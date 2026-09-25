<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/mimir_auth.php';
mimir_load_auth();
require_once __DIR__ . '/logincheck.php';

$email = (string) ($_SESSION['user']['email'] ?? '');
function mimir_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0099cc">
    <title>Mímir · OData-cache</title>
    <link rel="stylesheet" href="brand.css">
    <link rel="stylesheet" href="mimir.css">
    <link rel="manifest" href="site.webmanifest">
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
</head>
<body>
    <div class="page">
        <header class="hero">
            <div class="hero-main">
                <div class="hero-logo">
                    <img src="logo-website.png" alt="Koninklijke van Twist">
                </div>
                <div>
                    <h1>Mímir</h1>
                    <p>OData-verkenner en cache voor Business Central. Andere sleutels-apps halen hun gegevens hier op, in plaats van zelf BC te bellen.</p>
                </div>
            </div>
            <p class="who"><?= mimir_h($email) ?></p>
        </header>

        <section class="panel">
            <div class="panel-head">
                <h2>Tabel</h2>
                <p>Maximale cacheleeftijd in deze pagina is 10 minuten. Ouder dan dat haalt Mímir de rij opnieuw op uit Business Central.</p>
            </div>
            <div class="toolbar">
                <label class="field">
                    <span>Bedrijf</span>
                    <select id="company"></select>
                </label>
                <div class="field combo-field">
                    <label for="table-search">OData-tabel</label>
                    <div class="combo">
                        <input id="table-search" type="text" placeholder="Zoek een tabel" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="table-list">
                        <ul id="table-list" class="combo-list" hidden></ul>
                    </div>
                </div>
                <div class="field actions">
                    <span>Query</span>
                    <button type="button" id="run" class="primary" disabled>Ophalen</button>
                </div>
            </div>
            <p id="status" class="status" role="status"></p>
        </section>

        <section class="panel" id="schema-panel" hidden>
            <div class="panel-head">
                <h2>Filters en kolommen</h2>
                <p>OR over verschillende velden en XOR gaan niet naar Business Central (dat geeft daar vaak HTTP 501). Die blijven in Mímir. Een verse rij die een gevraagde kolom mist, wordt in zijn geheel ververst.</p>
            </div>
            <div class="split">
                <div>
                    <div id="filters"></div>
                    <div class="row-actions">
                        <button type="button" id="add-condition">Voorwaarde</button>
                        <button type="button" id="add-group">Groep</button>
                    </div>
                </div>
                <div>
                    <p class="hint">Geen aangevinkte kolom betekent: alle kolommen.</p>
                    <div id="columns" class="columns"></div>
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <h2>Resultaat</h2>
                <p id="meta-line">Nog geen query.</p>
            </div>
            <div class="table-wrap">
                <table id="results">
                    <thead></thead>
                    <tbody></tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <h2>API-sleutels</h2>
                <p>De volledige sleutel blijft zichtbaar voor jou. Hij staat in leesbare vorm in de SQLite-database achter deze login, en de lookup gebruikt SHA-256. De database wordt niet via het web geserveerd en niet meegenomen in git of de FTP-deploy.</p>
            </div>
            <form id="key-form" class="key-form">
                <label class="field grow">
                    <span>Label</span>
                    <input id="key-label" name="label" type="text" maxlength="80" required placeholder="Bijvoorbeeld Consus">
                </label>
                <button type="submit" class="primary">Sleutel maken</button>
            </form>
            <p id="key-status" class="status" role="status"></p>
            <div class="table-wrap">
                <table id="keys">
                    <thead>
                        <tr>
                            <th>Label</th>
                            <th>Sleutel</th>
                            <th>Gem. calls / dag</th>
                            <th>Aangemaakt</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </section>
    </div>
    <script src="mimir.js"></script>
</body>
</html>
