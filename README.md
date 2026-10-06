# Mímir

Centrale OData-cache, verkenner en API voor Business Central op [sleutels.kvt.nl/mimir](https://sleutels.kvt.nl/mimir/). Andere sleutels-apps kunnen hier later hun BC-gegevens vandaan halen, in plaats van elk een eigen `odata.php` te onderhouden.

De pagina staat in `web/` en gaat via FTP naar `/var/www/html/mimir/`.

## Structuur

- `web/index.php` — verkenner (SSO), twee tabbladen: **OData-verkenner** (tabel kiezen, filters, kolommen, resultaat, API-sleutels) en **Webservice-metadata** (alle tabellen met sleutels en velden).
- `web/ui_api.php` — JSON voor die pagina. Zelfde login als de pagina. `max_age` staat hier vast op **600 seconden**.
- `web/api/` — API met sleutel (`tables.php`, `schema.php`, `query.php`, `companies.php`, of `index.php` met `PATH_INFO` / `?route=`). `metadata.php` werkt met sleutel of met de ingelogde sessie.
- `web/mimir_metadata.php` — metadata-catalogus per environment (`web/data/mimir-catalog-<environment>.json`), zoekfilter en paginering.
- `web/openapi.yaml` / `web/openapi.json` — OpenAPI 3-specificatie (publiek, geen sleutel).
- `web/odata.php` — BC-client (basic of NTLM, paginering via `@odata.nextLink`, `$metadata`).
- `web/mimir_store.php` — SQLite: rijcache, dekking van een fetch, API-sleutels, usage.
- `web/mimir_bc_limit.php` — cross-process limiet (`flock`-semaphore) op gelijktijdige live BC-requests per environment.
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

De metadata-catalogus voor het tabblad **Webservice-metadata** ververst nightly live als hij ontbreekt of ouder is dan 20 uur (`MIMIR_CATALOG_REFRESH_AGE`; net onder een dag zodat een dagelijkse run nooit een dag overslaat).

Lokaal: `php web/nightly.php`  
Productie: `GET /mimir/nightly.php` (zelfde auth/logincheck als andere apps).

## Webservice-metadata

Tweede tabblad op de pagina: een overzicht van alle OData-tabellen (entity sets) per environment, met sleutels en velden (type, verplicht, maximale lengte) en navigatie. Een soort API-spec-aanvulling: welke tabellen er zijn en wat voor data eruit kan komen.

- Bron: `$metadata` van `{baseUrl}/{environment}/ODataV4/`, geparsed door `odata_parse_metadata` (dezelfde parser als de verkenner). Environments komen alleen uit `$environment` in `auth.php` (met credentials in `$auth_list`); de volgorde van `$auth_list` telt niet.
- Opslag: `web/data/mimir-catalog-<environment>.json` (`{environment, fetched_at, entity_sets:[{name, entity_type, keys, properties:[{name, type, nullable, max_length?}], navigation}]}`), achter `web/data/.htaccess` en buiten de FTP-mirror. Ontbreekt het bestand nog, dan bouwt Mímir het uit de bestaande metadata-snapshot zonder BC te bellen.
- Verversen: `nightly.php` (zie boven), elke live `$metadata`-fetch van de verkenner, en de knop **Vernieuwen** (ingelogd; hooguit één BC-call per minuut per environment). De pagina en de API lezen alleen het bestand: nooit `$metadata` per page-load.
- Pagina: tabellen standaard ingevouwen, 50 per pagina, zoekbalk die hoofdletterongevoelig filtert op tabelnaam én veldnamen (treffers gemarkeerd; bij een veldmatch staat in de regel welke velden), environment-keuze, telling «X tabellen, Y gevonden» en «Laatst bijgewerkt» in Europe/Amsterdam. Deeplink: `index.php#metadata`.
- Machine-readable: `GET /mimir/api/metadata.php` (zie [API](#api)).

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

Business Central laat ongeveer vijf gelijktijdige requests per environment toe. Mímir beperkt live BC-fetches tot **3** tegelijk per environment (`kvtmdlive_aad` en `kvtgermanylive_aad` hebben aparte tellers). Dat is een counting semaphore van exclusive `flock`s op `web/data/bc_slots/<environment>/slot-N`, niet een SQLite-wachtlijst. Elke wachtende worker pollt alleen die slotbestanden; er is geen `BEGIN IMMEDIATE` meer op een gedeelde limiet-database. Antwoorden die volledig uit de cache komen nemen geen slot. Wie moest wachten krijgt `meta.queue_wait_ms` (en optioneel `bc_slots_used` / `bc_slots_max`). Na **120** seconden wachten volgt HTTP **503** in plaats van oneindig hangen. Constanten: `MIMIR_BC_MAX_CONCURRENT`, `MIMIR_BC_QUEUE_WAIT_SECONDS` in `web/mimir_bc_limit.php`.

Een worker die midden in een request sterft (PHP-timeout, OOM, deploy) laat geen slot achter: de kernel geeft `flock` vrij zodra het proces de filedescriptor sluit. Er is geen houder- of wachtrijtabel die een dode worker kan laten staan. Een shutdown-handler geeft slots van dit proces ook vrij als `finally` niet liep. Lukt het aanmaken van de slotbestanden niet, dan valt Mímir terug op één exclusive lock per environment. Lukt ook die lock niet (rechten, I/O, directory niet te maken), dan gaat het verzoek **live naar Business Central** zonder slot, met een regel `bypassed-to-BC` in het gebeurtenislog. Een volle wachtrij blijft HTTP **503**; bezet is geen kapotte coördinatie. Het oude `web/data/bc_limit.sqlite` wordt niet meer gebruikt en mag weg.

## Als de database vastzit

SQLite krijgt `busy_timeout` van 3 seconden. Tijdelijke fouten (`SQLITE_BUSY` / `SQLITE_LOCKED`, "database is locked", disk I/O error) worden een paar keer opnieuw geprobeerd met exponentiële backoff en jitter, binnen een begrensde wachttijd. Timeouts, HTTP 429, 5xx en connection resets naar Business Central ook, met respect voor `Retry-After`.

Blijft de database locked, onleesbaar, readonly of corrupt, of zijn de retries op, dan gaat het verzoek **live naar Business Central** in hetzelfde JSON-formaat. `meta.source` is dan `bc-live`. Een circuit houdt dat vol tot een health probe (`PRAGMA quick_check`) de database weer gezond vindt. Andere interne fouten vallen ook terug op BC zolang de credentials er zijn. Clientfouten (ontbrekend veld, ongeldig filter) blijven een foutantwoord.

Het gebeurtenislog (`web/data/mimir-events.jsonl`, tijdzone Europe/Amsterdam) staat buiten SQLite en is zichtbaar op de Mímir-pagina, samen met de circuit-staat (normal of bypass-to-BC) en sinds wanneer. Er komen geen secrets in dat log.

Bij `bc-failed`, `bypassed-to-BC`, `failed` en `fallback-one-slot` staat ook het veld `caller`, zodat zichtbaar is welke app of sessie het verzoek deed. Voor een API-sleutel is dat `key_id` (het id uit `api_keys`), `label` (de naam uit de sleutelpagina), `owner` (e-mail van de eigenaar), `prefix` (de eerste 8 tekens ná `mimir_`, alleen als de rest van de sleutel lang genoeg is om niet mee te loggen) en `hash` (de eerste 12 hex-tekens van SHA-256, hetzelfde `key_hash` waarmee een sleutel wordt opgezocht). De volledige sleutel staat er niet in. De UI logt `ui` plus het sessie-e-mailadres; `nightly.php` logt `nightly` (plus e-mail als de nachtrun via de browser liep). Het label en de eigenaar zitten ook in de sleutelspiegel (`mimir-key-mirror.json`), zodat ze bij een open circuit nog bekend zijn zonder de plaintext.

`nightly.php` probeert de database opnieuw te openen, draait een open transactie bij afbreken terug (shutdown, want `exit` slaat `finally` over) en laat geen lock achter. Een leeg `-journal` wordt alleen verwijderd als de database in WAL staat, het bestand 0 bytes is en er een exclusieve lock op zit. `-wal`, `-shm` en het databasebestand zelf worden niet gewist.


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

Paginering volgt `@odata.nextLink` tot de set klaar is (plafond 100 pagina's). Een ongelimiteerde fetch (`top: 0`) stuurt **geen** `$top` naar BC: `$top` is daar een totaalplafond en onderdrukt `nextLink`, waardoor grote sets (debiteuren, crediteuren) op 2000 rijen bleven steken. De pagina-grootte is `Prefer: odata.maxpagesize=2000` op de HTTP-client. Wijst BC die header af, dan dezelfde URL zonder Prefer; zonder `$top` pagineert BC alsnog via `nextLink` op de eigen Max Page Size. `$skip` gebruiken we niet. Een eindige `top` gaat wél als `$top` mee en telt niet als volledige dekking wanneer dat plafond vol is. Alleen een afgeronde set wordt als dekking bewaard.

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
| GET | `/mimir/api/metadata.php` | webservice-metadata: alle tabellen met sleutels en velden. Optioneel `environment`, `q` (zoekfilter op tabel- en veldnamen), `table` (één tabel), `page`/`per_page` (max 50). Ook met de ingelogde sessie, zonder sleutel. Belt BC niet |

Zonder `PATH_INFO` werken ook:

- `GET /mimir/api/index.php?route=tables`
- `GET /mimir/api/index.php?route=companies`
- `GET /mimir/api/index.php/tables/ItemList/schema`
- `POST /mimir/api/index.php/query`

Metadata:

```sh
curl -sS -H "Authorization: Bearer mimir_…" \
  "https://sleutels.kvt.nl/mimir/api/metadata.php?environment=kvtmdlive_aad&q=vendor_no"

curl -sS -H "X-API-Key: mimir_…" \
  "https://sleutels.kvt.nl/mimir/api/metadata.php?table=AppItems"
```

Antwoord: `{ "environment", "environments", "fetched_at", "fetched_at_label", "total", "matched", "q", "entity_sets": [ { "name", "entity_type", "keys", "properties": [ { "name", "type", "nullable", "max_length?" } ], "navigation", "match?" } ] }`. Met `q` krijgt elke tabel `match: { "name": bool, "fields": [ … ] }`; tabellen met een naammatch staan vooraan.

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

- **`filter`**: JSON-boom (`and` / `or` / `xor` + bladeren) zoals voorheen, **of** een niet-lege OData `$filter`-string. Een string gaat ongewijzigd naar BC (`filter_mode=bc`). Eenvoudige vormen `Field eq true|false` en `Field eq 'literal'` worden daarnaast lokaal toegepast, zodat een gedeelde cache geen gesloten rijen in `Open eq true` lekt. Complexere strings zijn niet lokaal te bewijzen en komen niet uit coverage; die gaan live naar BC. Maximale lengte 32 768 tekens.
- **`top`**: default **100**. Positief tot **10 000**. **`0` = ongelimiteerd** (geen `array_slice` en geen `$top` naar BC; paginering via `Prefer: odata.maxpagesize` en `@odata.nextLink`).
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

Voor de mirror zet de runner `./web` op 755 (mappen) en 644 (bestanden). De mirror gaat zonder `--no-perms`, zodat lftp die modi meestuurt: PHP moet voor Apache minstens 644 zijn. Mode 600 kwam van `--no-perms` plus de umask van de FTP-server. `data` en `data/**`, `cache` en `cache/**` blijven buiten de mirror, samen met `auth.php`, `.htaccess` en de sqlite/json/lock-globs, zodat een deploy de database, `-wal`, `-shm`, `-journal` en het gebeurtenislog niet overschrijft of verwijdert. `analytics/**` wordt niet uitgesloten zodat `analytics.php` weer meegaat; alleen `analytics/*.sqlite` en `analytics/*.sqlite-*` blijven op de server. Geen put naar `data/`. `web/data/.htaccess` staat in git voor nieuwe installs en blijft op de server staan.

Na de mirror, in een aparte lftp-sessie: `chmod 777` op de mappen `data`, `cache` en `analytics`, en (tijdelijk) ook op de sqlite-bestanden plus `-wal`/`-shm`/`-journal`. Een mapmode 777 maakt een bestand van een andere gebruiker niet schrijfbaar; de bestandschmod wel. Best-effort: een 550 maakt de job niet rood. PHP zet bij openen dezelfde 0777 (umask 0, fouten onderdrukt) tot de eigenaar-oorzaak vaststaat.

Zet `web/auth.php` eenmalig op de server. Die blijft bij volgende deploys staan.

## Tests

Zonder Business Central:

```sh
php tests/mimir_filter_test.php
php tests/mimir_cache_test.php
php tests/mimir_bc_reduce_test.php
php tests/mimir_bc_limit_test.php
php tests/mimir_keys_test.php
php tests/mimir_heatmap_test.php
php tests/mimir_auth_env_test.php
php tests/mimir_sqlite_test.php
php tests/mimir_reliability_test.php
php tests/mimir_metadata_test.php
```
