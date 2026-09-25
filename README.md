# Mímir

Centrale OData-cache, verkenner en API voor Business Central op [sleutels.kvt.nl/mimir](https://sleutels.kvt.nl/mimir/). Andere sleutels-apps kunnen hier later hun BC-gegevens vandaan halen, in plaats van elk een eigen `odata.php` te onderhouden.

De pagina staat in `web/` en gaat via FTP naar `/var/www/html/mimir/`.

## Structuur

- `web/index.php` — verkenner (SSO). Tabel kiezen, filters, kolommen, resultaat, API-sleutels.
- `web/ui_api.php` — JSON voor die pagina. Zelfde login als de pagina. `max_age` staat hier vast op **600 seconden**.
- `web/api/` — API met sleutel (`tables.php`, `schema.php`, `query.php`, of `index.php` met `PATH_INFO` / `?route=`).
- `web/odata.php` — BC-client (basic of NTLM, paginering via `@odata.nextLink`, `$metadata`).
- `web/mimir_store.php` — SQLite: rijcache, dekking van een fetch, API-sleutels, usage.
- `web/mimir_filter.php` — filterboom `and` / `or` / `xor`.
- `web/logincheck.php` + `web/auth_helper.php` — dezelfde SSO-poort als Consus.
- `web/data/mimir.sqlite` — runtime, niet in git.

## auth.php

Geen `auth.php` in deze repository. Lokaal wordt eerst `~/Repositories/auth.php` geladen (dezelfde gedeelde file als de andere apps), tenzij `MIMIR_AUTH_FILE` naar een ander bestand wijst. Op de server blijft het `web/auth.php`. Die staat in `.gitignore` en de FTP-deploy overschrijft hem niet.

Verwachte variabelen, hetzelfde als bij Consus:

```php
<?php
$baseUrl = 'https://kvtmd365.kvt.nl:7148';
$environment = 'kvtmdlive_aad';
$auth_list = [
    'kvtmdlive_aad' => [
        'mode' => 'basic', // of 'ntlm'
        'user' => '...',
        'pass' => '...',
    ],
];
$allowedUsers = [
    'tim@kvt.nl',
];
```

Het OData-pad wordt `$baseUrl/$environment/ODataV4/`, dus de live service is `https://kvtmd365.kvt.nl:7148/kvtmdlive_aad/ODataV4/`.

## Cache

Elke rij uit BC staat in SQLite met een eigen `fetched_at` (unix), per bedrijf en entity set. De sleutel komt uit de OData-key van de metadata.

Een rij is vers als `now - fetched_at <= max_age`. Daarnaast onthoudt Mímir of een fetch van die tabel (plus het `$filter` dat naar BC ging, of de hele tabel) binnen `max_age` compleet binnen was. Alleen dan komt het antwoord uit de cache.

Vraagt `select` een kolom die op een verder verse rij ontbreekt, dan haalt Mímir **de hele rij** opnieuw op (geen `$select` op die ene rij) en vervangt de cache. Een losse kolom bijplakken doen we niet.

De UI gebruikt altijd `max_age` 600. De API laat de aanroeper dat bepalen (default 3600, maximum 365 dagen).

Metadata (tabellen en velden) blijft een uur staan. De bedrijvenlijst een dag.

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

Paginering volgt `@odata.nextLink` tot de set klaar is (plafond 100 pagina's van 2000). Alleen een afgeronde set wordt als dekking bewaard.

## API-sleutels

Aanmaken kan in de pagina, met een label. De volledige sleutel blijft daarna zichtbaar voor de eigenaar: hij staat leesbaar in `web/data/mimir.sqlite` (achter SSO, niet in git, niet via het web — `web/data/.htaccess` weigert alles). Opzoeken van een binnenkomende sleutel gaat via SHA-256 (`key_hash`), niet via een scan op de plaintext.

Per sleutel toont de pagina het gemiddelde aantal calls per dag over de laatste 30×24 uur: `aantal calls / 30`. Intrekken zet `revoked_at`; de sleutel werkt dan niet meer.

Elke API-call schrijft `key_id`, endpoint en timestamp.

## API

Authenticatie: `Authorization: Bearer <sleutel>` of `X-API-Key: <sleutel>`.

| Methode | Pad | Doel |
| --- | --- | --- |
| GET | `/mimir/api/tables.php` | entity sets. `q` filtert op naam, `company` kiest de environment |
| GET | `/mimir/api/schema.php?table=ItemList` | velden, types, sleutels |
| POST | `/mimir/api/query.php` | één tabel, of meerdere via `queries` |

Zonder `PATH_INFO` werken ook:

- `GET /mimir/api/index.php?route=tables`
- `GET /mimir/api/index.php/tables/ItemList/schema`
- `POST /mimir/api/index.php/query`

Eén tabel:

```sh
curl -sS \
  -H "Authorization: Bearer mimir_…" \
  "https://sleutels.kvt.nl/mimir/api/tables.php?q=item"

curl -sS \
  -H "X-API-Key: mimir_…" \
  "https://sleutels.kvt.nl/mimir/api/schema.php?table=ItemList&company=Koninklijke%20van%20Twist"

curl -sS \
  -H "Authorization: Bearer mimir_…" \
  -H "Content-Type: application/json" \
  -d '{"company":"Koninklijke van Twist","table":"ItemList","select":["No","Description"],"max_age":3600,"top":100,"filter":{"and":[{"field":"No","op":"eq","value":"123"}]}}' \
  "https://sleutels.kvt.nl/mimir/api/query.php"
```

Antwoord: `{ "value": [ … ], "meta": { "from_cache", "from_live", "max_age", "fetched_at_min", "fetched_at_max", "bc_filter", "filter_mode", "filter_note" } }`.

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

De mirror zet `web/` daar neer en laat `auth.php`, `data/*.sqlite*` en `cache/**` met rust (die worden niet gewist). `chmod 777` op `analytics`, `data` en `cache` is best-effort; een 550 op cache maakt de job niet rood.

Zet `web/auth.php` eenmalig op de server. Die blijft bij volgende deploys staan.

## Tests

Zonder Business Central:

```sh
php tests/mimir_filter_test.php
php tests/mimir_cache_test.php
php tests/mimir_keys_test.php
```
