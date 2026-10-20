# Analytics — vaste sqlite-structuur en gebruik

Dit bestand is de enige bron van waarheid voor de Elpis-analyticsmodule.
**Van deze structuur en dit gebruik mag onder geen voorwaarde worden afgeweken.**

Andere implementaties (andere pagina’s, portals, cronjobs) **mogen `analytics.php` niet wijzigen, includen of kopiëren**. Ze gebruiken alleen de HTTP-call hieronder.

## Bestanden

| Pad | Rol |
|-----|-----|
| `web/analytics/analytics.php` | Enige writer. Alleen HTTP-endpoint. Niet includen. |
| `web/analytics/analytics.sqlite` | SQLite 3-database. Wordt automatisch aangemaakt. |
| `web/analytics/rules.md` | Dit contract. |

## Schema (exact)

```sql
CREATE TABLE visits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    visited_at INTEGER NOT NULL,
    user_email TEXT NOT NULL
);
```

| Kolom        | Type    | Betekenis                                      |
|--------------|---------|------------------------------------------------|
| `id`         | INTEGER | Auto-increment primaire sleutel                |
| `visited_at` | INTEGER | Unix-timestamp (seconden sinds 1970-01-01 UTC) |
| `user_email` | TEXT    | E-mailadres van de ingelogde gebruiker         |

Geen extra tabellen, geen extra kolommen, geen hernoemen, geen andere types.
Geen updates, geen deletes, geen aggregatietabellen in dit bestand.

## Wanneer vastleggen

Direct nadat `logincheck.php` heeft vastgesteld dat de gebruiker toegang heeft.
In Elpis is dat na de `allowedUsers`-check:

if (!is_trusted_requester()) {
    require __DIR__ . "/../login/lib.php";

    if (
        !array_any($allowedUsers, function ($email) {
            return strtolower((string) $email) === strtolower((string) ($_SESSION['user']['email'] ?? ''));
        })
    ) {
        require __DIR__ . "/../login/403.php";
        die();
    }

    <---- ANALYTICS CALL HIER
}

Niet eerder (niet bij 403). Niet door `analytics.php` zelf te laten includen vanuit de pagina.

## Hoe aanroepen

HTTP `GET` of `POST` naar `analytics/analytics.php` (relatief t.o.v. de app-root `web/`).

Verplichte gegevens uit de login-userdata (`$_SESSION['user']`, gezet door `C:\xampp\htdocs\login`):

| Veld | Bron | Transport |
|------|------|-----------|
| `user_email` | `$_SESSION['user']['email']` | query/body |
| `api_key` | `$_SESSION['user']['api_key']` | header `X-API-Key` of query/body `api_key` |
| `oid` | `$_SESSION['user']['oid']` | query/body `oid` of header `X-OID` |

Voorbeeld:

```
GET analytics/analytics.php?user_email=naam@kvt.nl&api_key=…&oid=…
```

of:

```
GET analytics/analytics.php?user_email=naam@kvt.nl&oid=…
X-API-Key: …
```

De API-key is de roterende key uit login-userdata (`hash('sha256', oid|dd-mm-YYYY)`). Zonder geldige key weigert het endpoint (401).

De call moet de paginaload niet blokkeren (korte timeout, fouten negeren).

## Wat analytics.php doet

1. API-key + oid verifiëren via login (`verify_rotating_api_key`).
2. Exact één rij invoegen:

```sql
INSERT INTO visits (visited_at, user_email)
VALUES (:visited_at, :user_email);
```

- `:visited_at` = `time()` (unix-timestamp, INTEGER)
- `:user_email` = lowercase, getrimd e-mailadres

3. JSON teruggeven: `{"ok":true}` of `{"ok":false,"error":"…"}`.

## Verboden

- `analytics.php` aanpassen vanuit een andere app of feature.
- `require`/`include` van `analytics.php`.
- `logincheck.php` vanuit `analytics.php` laden.
- Het sqlite-schema wijzigen.

# Voorbeeld analytics.php call

```php
$analyticsEmail = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
$analyticsApiKey = trim((string) ($_SESSION['user']['api_key'] ?? ''));
$analyticsOid = strtolower(trim((string) ($_SESSION['user']['oid'] ?? '')));
if ($analyticsEmail !== '' && $analyticsApiKey !== '' && $analyticsOid !== '') {
    $analyticsScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $analyticsHost = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $analyticsBase = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
    $analyticsUrl = $analyticsScheme . '://' . $analyticsHost . $analyticsBase . '/analytics/analytics.php?' . http_build_query([
        'user_email' => $analyticsEmail,
        'api_key' => $analyticsApiKey,
        'oid' => $analyticsOid,
    ], '', '&', PHP_QUERY_RFC3986);

    if (function_exists('curl_init')) {
        $analyticsCurl = curl_init($analyticsUrl);
        curl_setopt_array($analyticsCurl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_TIMEOUT => 1,
            CURLOPT_HTTPHEADER => ['X-API-Key: ' . $analyticsApiKey],
        ]);
        curl_exec($analyticsCurl);
        curl_close($analyticsCurl);
    }
}
```