# Mímir

Centrale OData-cache, verkenner en API voor Business Central op [sleutels.kvt.nl/mimir](https://sleutels.kvt.nl/mimir/). Andere sleutels-apps kunnen hier later hun BC-gegevens vandaan halen, in plaats van elk een eigen `odata.php` te onderhouden.

De pagina staat in `web/` en gaat via FTP naar `/var/www/html/mimir/`.

## Structuur

- `web/index.php` — verkenner (SSO). Tabel kiezen, filters, kolommen, resultaat, API-sleutels.
- `web/ui_api.php` — JSON voor die pagina. Zelfde login als de pagina. `max_age` staat hier vast op **600 seconden**.
- `web/api/` — API met sleutel (`tables.php`, `schema.php`, `query.php`, `companies.php`, of `index.php` met `PATH_INFO` / `?route=`).
- `web/openapi.yaml` / `web/openapi.json` — OpenAPI 3-specificatie (publiek, geen sleutel).
- `web/odata.php` — BC-client (basic of NTLM, paginering via `@odata.nextLink`, `$metadata`).
- `web/mimir_store.php` — SQLite: rijcache, dekking van een fetch, API-sleutels, usage.
- `web/mimir_bc_limit.php` — cross-process limiet op gelijktijdige live BC-requests per environment.
- `web/mimir_filter.php` — filterboom `and` / `or` / `xor`.
- `web/logincheck.php` + `web/auth_helper.php` — SSO-poort als Consus; company-discovery als Penates (meerdere environments).
- `web/data/mimir.sqlite` — runtime, niet in git.

## auth.php

Geen `auth.php` in deze repository. Lokaal wordt eerst `web/auth.php` geladen (dezelfde gedeelde file als de andere apps), tenzij `MIMIR_AUTH_FILE` naar een ander bestand wijst. Op de server blijft het `web/auth.php`. Die staat in `.gitignore` en de FTP-deploy overschrijft hem niet.

Verwachte variabelen, hetzelfde model als Penates. `$baseUrl` is alleen de host. Elke BC-database is een sleutel in `$auth_list`. `$environment` is de lijst die Mímir echt gebruikt (ook een string mag; leeg valt terug op de eerste sleutel van `$auth_list`). Een naam telt alleen mee als hij in beide staat. Fat-omgevingen mogen in die lijst staan zodra ze credentials hebben.

KVT en HVT delen `kvtmdlive_aad`. KVT Germany is een aparte database, `kvtgermanylive_aad`, met eigen credentials.

```php
<?php
$baseUrl = 'https://kvtmd365.kvt.nl:7148';
$environment = [
    'kvtmdlive_aad',
    'kvtgermanylive_aad',
    // optioneel, als de sleutel ook in $auth_list staat:
    // 'kvtmdlive_fat',
];
$auth_list = [
    'kvtmdlive_aad' => [
        'mode' => 'basic', // of 'ntlm'
        'user' => '...',
        'pass' => '...',
    ],
    'kvtgermanylive_aad' => [
        'mode' => 'basic',
        'user' => '...',
        'pass' => '...',
    ],
];
$allowedUsers = [
    'tim@kvt.nl',
];
```

Een aanroep noemt het **bedrijf**, niet het environment. `auth_get_environment_for_company` zoekt het bedrijf in alle actieve environments en kiest daarbij de credentials. De URL wordt:

`{baseUrl}/{environment}/ODataV4/Company('{company}')/{entity}`

Bijvoorbeeld `https://kvtmd365.kvt.nl:7148/kvtgermanylive_aad/ODataV4/Company('KVT%20Germany')/ItemList`.

`$metadata` (tabellen en velden) wordt per environment van het gekozen bedrijf opgehaald. Germany heeft een eigen lijst; die wordt niet gedeeld met KVT/HVT. Dezelfde bedrijfsnaam in twee actieve environments wordt geweigerd.

## Nightly

`web/nightly.php` ontdekt alle bedrijven over de actieve environments (Penates-stijl) en schrijft die kaart in SQLite. De UI-dropdown **Bedrijf** leest alleen die cache — geen live Company-discovery bij page load. Metadata (`$metadata`) per environment wordt tegelijk ververst.

Lokaal: `php web/nightly.php`  
Productie: `GET /mimir/nightly.php` (zelfde auth/logincheck als andere apps).

## Cache

Elke rij uit BC staat in SQLite met een eigen `fetched_at` (unix). De cachesleutel is **environment + bedrijf + entity set + rijsleutel**. Een rij uit `kvtgermanylive_aad` botst daardoor nooit met KVT of HVT op `kvtmdlive_aad`, ook als de bedrijfsnaam of het artikelnummer gelijk is. De rijsleutel komt uit de OData-key van de metadata van dát environment.

Een bestaande cache zonder `environment`-kolom wordt bij de eerste start omgezet. Die oude rijen krijgen een lege environment en worden niet meer uitgeserveerd.

Een rij is vers als `now - fetched_at <= max_age`. Daarnaast onthoudt Mímir of een fetch van die tabel (plus het `$filter` dat naar BC ging, of de hele tabel) binnen `max_age` compleet binnen was. Alleen dan komt het antwoord uit de cache.

### Brede dekking (minder BC-calls)

- Een **gefilterde** JSON-query mag uit een verse **lege-filter** (hele-tabel) dekking komen als de `select` past: Mímir filtert dan lokaal. Opaque OData-`$filter`-strings doen dat niet (die zijn niet lokaal toepasbaar).
- Exacte `filter_sig`-dekking wint van lege-filterdekking als beide vers genoeg zijn.
- **Warms / nightlies / batch-jobs** die veel UI-filters moeten voeden: haal **zonder filter** op (of met een zeer breed filter). Er is in Mímir zelf geen aparte entity-warm-endpoint; `nightly.php` doet alleen bedrijven + `$metadata`. Apps die warms doen moeten dus zelf `filter` weglaten.

### `$select` naar BC

- **Lege-filter / full-entity** fetch naar BC: **geen `$select`** — alle kolommen binnen, dekking `select_sig=*`. Het API-antwoord projecteert nog steeds op de gevraagde `select`.
- **Gefilterde** fetch met al kolommen op file voor dat company+entity: eveneens **geen `$select`** naar BC (niet krimpen; delen maximaliseren).
- **Gefilterde** cold cache: request-`select` mag nog naar BC.
- Ontbreekt een gevraagde kolom op een verder verse rij, dan haalt Mímir **de hele rij** opnieuw op (geen `$select` op die ene rij).

### Overlappende ranges (gap fill)

Voor aaneengesloten ranges op **één** vergelijkbaar veld (`eq` / `ge` / `gt` / `le` / `lt` en AND daarvan): als er verse overlappinge rangedekking is, haalt Mímir alleen de **ontbrekende subranges** uit BC, merge’t met gecachte rijen, en zet `meta.gap_fill=1`. Zonder veiligheidsbewijs (geen passende dekking, of onveilig filter zoals `contains` / cross-field OR) blijft het volledige BC-fetch voor dat filter.

`meta.shared` / `meta.bc_hit` / `from_cache` / `from_live` blijven de meetlat: shared = volledig uit andermans/legacy cache zonder BC; bc_hit zodra BC werd gebeld (ook bij partial gap fill).

### BC-concurrency

Business Central laat ongeveer vijf gelijktijdige requests per environment toe. Mímir beperkt live BC-fetches tot **3** tegelijk per environment (`kvtmdlive_aad` en `kvtgermanylive_aad` hebben aparte tellers), met een FIFO-wachtlijst in SQLite (`web/data/bc_limit.sqlite`). Antwoorden die volledig uit de cache komen nemen geen slot. Wie moest wachten krijgt `meta.queue_wait_ms` (en optioneel `bc_slots_used` / `bc_slots_max`). Na **120** seconden wachten volgt HTTP **503** in plaats van oneindig hangen. Constanten: `MIMIR_BC_MAX_CONCURRENT`, `MIMIR_BC_QUEUE_WAIT_SECONDS` in `web/mimir_bc_limit.php`.


De UI gebruikt altijd `max_age` 600. De API laat de aanroeper dat bepalen (default 3600, maximum 365 dagen).

Metadata blijft een uur staan, apart per environment. De bedrijvenlijst een dag, en alleen als elk actief environment antwoordde. `company` is verplicht bij tabellen, schema en query.

## Filtergrens naar Business Central

BC antwoordt vaak met **HTTP 501** op een OR over verschillende velden. XOR bestaat in OData niet.

| Filter | Naar BC `$filter` | Lokaal in PHP |
| --- | --- | --- |
| één voorwaarde (`eq`, `ne`, `gt`, `ge`, `lt`, `le`, `contains`, `startswith`, `endswith`) | ja | daarna nog eens, zelfde resultaat |
| `and` van zulke voorwaarden | ja | ja |
| `or` waarvan **alle** bladeren hetzelfde veld raken | ja | ja |
| `or` over verschillende velden | nee | ja |
| `xor` (n-air: oneven aantal ware kinderen; bij twee kinderen is dat precies één) | nee | ja |
| `and` met daarin een niet-stuwbare groep | alleen de wél stuwbare kinderen | de hele boom |

Komt er toch een 501 terug, dan haalt Mímir dezelfde set opnieuw op zonder `$filter` en past de boom alsnog lokaal toe.

Grote same-field OR-lijsten (JSON of eenvoudige `$filter`-string) batcht Mímir automatisch (40 per keer, korter bij te lange URL), zodat clients één query kunnen sturen.

Paginering volgt `@odata.nextLink` tot de set klaar is (plafond 100 pagina's van 2000). Alleen een afgeronde set wordt als dekking bewaard.

## API-sleutels

Aanmaken kan in de pagina, met een label. De volledige sleutel blijft daarna zichtbaar voor de eigenaar: hij staat leesbaar in `web/data/mimir.sqlite` (achter SSO, niet in git, niet via het web — `web/data/.htaccess` weigert alles). Opzoeken van een binnenkomende sleutel gaat via SHA-256 (`key_hash`), niet via een scan op de plaintext.

Per sleutel toont de pagina het gemiddelde aantal calls per dag over de laatste 30×24 uur: `aantal calls / 30`. Daarnaast een SVG-weekraster (maandag t/m zondag, vier weken, tijdzone Europe/Amsterdam) met het aantal aanroepen per dag. Hover toont datum en aantal. De intensiteit loopt tot 20 calls op een dag; daarboven wordt het vakje geel tot oranje. Intrekken zet `revoked_at`; de sleutel werkt dan niet meer.

Elke API-call schrijft `key_id`, endpoint en timestamp.

## API

Machine-readable specificatie: [OpenAPI 3 YAML](https://sleutels.kvt.nl/mimir/openapi.yaml) en [JSON](https://sleutels.kvt.nl/mimir/openapi.json) (ook gelinkt vanuit de UI-footer). Geen authenticatie voor die bestanden.

Authenticatie voor de data-API: `Authorization: Bearer <sleutel>` of `X-API-Key: <sleutel>`.

| Methode | Pad | Doel |
| --- | --- | --- |
| GET | `/mimir/api/tables.php?company=…` | entity sets van het environment van dat bedrijf. `q` filtert op naam |
| GET | `/mimir/api/schema.php?table=ItemList&company=…` | velden, types, sleutels van dat environment |
| GET | `/mimir/api/companies.php` | bedrijven uit de nightly-cache (`name` + `environment`). Geen live discovery per call |
| POST | `/mimir/api/query.php` | één tabel, of meerdere via `queries` |

Zonder `PATH_INFO` werken ook:

- `GET /mimir/api/index.php?route=tables`
- `GET /mimir/api/index.php?route=companies`
- `GET /mimir/api/index.php/tables/ItemList/schema`
- `POST /mimir/api/index.php/query`

Eén tabel:

```sh
curl -sS \
  -H "Authorization: Bearer mimir_…" \
  "https://sleutels.kvt.nl/mimir/api/tables.php?company=Koninklijke%20van%20Twist&q=item"

curl -sS \
  -H "X-API-Key: mimir_…" \
  "https://sleutels.kvt.nl/mimir/api/schema.php?table=ItemList&company=Koninklijke%20van%20Twist"

curl -sS \
  -H "Authorization: Bearer mimir_…" \
  -H "Content-Type: application/json" \
  -d '{"company":"Koninklijke van Twist","table":"ItemList","select":["No","Description"],"max_age":3600,"top":100,"filter":{"and":[{"field":"No","op":"eq","value":"123"}]}}' \
  "https://sleutels.kvt.nl/mimir/api/query.php"
```

Antwoord: `{ "value": [ … ], "meta": { "environment", "from_cache", "from_live", "max_age", "fetched_at_min", "fetched_at_max", "bc_filter", "filter_mode", "filter_note", "filter_batches?" } }`. `environment` is de BC-database die bij het bedrijf hoort.

### Query-opties

- **`filter`**: JSON-boom (`and` / `or` / `xor` + bladeren) zoals voorheen, **of** een niet-lege OData `$filter`-string. Een string gaat ongewijzigd naar BC (`filter_mode=bc`); lokaal matchen wordt overgeslagen. Maximale lengte 32 768 tekens.
- **`top`**: default **100**. Positief tot **10 000**. **`0` = ongelimiteerd** (geen `array_slice`; wel bestaande `@odata.nextLink`-paginering van 2000).
- **Automatische OR-batching**: een grote same-field `or` van `eq`-bladeren (JSON-boom of eenvoudige string `(Field eq 'a') or (Field eq 'b') or …`) wordt intern in chunks van **40** (of kleiner bij lange URL) naar BC gestuurd. Resultaten worden op rijsleutel samengevoegd. Clients hoeven zelf niet meer te chunken. Bij meer dan één batch staat `meta.filter_batches` op het aantal. Complexe strings die niet veilig te splitsen zijn, gaan als één `$filter` (nextLink blijft gelden).

```sh
curl -sS -H "Authorization: Bearer mimir_…"   "https://sleutels.kvt.nl/mimir/api/companies.php"

curl -sS   -H "Authorization: Bearer mimir_…"   -H "Content-Type: application/json"   -d '{"company":"Koninklijke van Twist","table":"ItemList","top":0,"filter":"(No eq 'A') or (No eq 'B')"}'   "https://sleutels.kvt.nl/mimir/api/query.php"
```

Meerdere tabellen in één verzoek, met een optionele equijoin (v1, één sleutel, na het ophalen — geen join in BC):

```sh
curl -sS \
  -H "Authorization: Bearer mimir_…" \
  -H "Content-Type: application/json" \
  -d '{
    "company": "Koninklijke van Twist",
    "max_age": 3600,
    "queries": [
      {"name": "items", "table": "ItemList", "select": ["No", "Vendor_No"], "top": 1000},
      {"name": "vendors", "table": "VendorList", "select": ["No", "Name"], "top": 1000}
    ],
    "combine": {"left": "items", "right": "vendors", "left_key": "Vendor_No", "right_key": "No", "as": "joined"}
  }' \
  "https://sleutels.kvt.nl/mimir/api/query.php"
```

Het antwoord is dan `{ "results": { "items": { "value", "meta" }, "vendors": { … }, "joined": { … } } }`.

## Deploy

Push naar `master` start `.github/workflows/deploy-ftp.yml` (zelfde patroon als Consus). Secrets op de GitHub-repo:

- `FTP_HOST`
- `FTP_USERNAME`
- `FTP_PASSWORD`
- `FTP_REMOTE_DIR` — het FTP-pad dat live `https://sleutels.kvt.nl/mimir/` is, doorgaans `/var/www/html/mimir`

De mirror zet `web/` daar neer met `--no-perms` (lokale git-modes overschrijven de server niet) en laat `auth.php` plus de runtime-mappen `data/**`, `cache/**` en `analytics/**` met rust. `data/.htaccess` gaat er daarna apart heen. `chmod 777` op die mappen en `chmod 666` op `data/*.sqlite*` is best-effort; een 550 maakt de job niet rood.

Zet `web/auth.php` eenmalig op de server. Die blijft bij volgende deploys staan.

## Tests

Zonder Business Central:

```sh
php tests/mimir_filter_test.php
php tests/mimir_cache_test.php
php tests/mimir_bc_reduce_test.php
php tests/mimir_keys_test.php
php tests/mimir_heatmap_test.php
php tests/mimir_auth_env_test.php
php tests/mimir_sqlite_test.php
```
