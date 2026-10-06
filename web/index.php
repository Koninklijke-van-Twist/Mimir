<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/mimir_auth.php';
mimir_load_auth();
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/mimir_reliability.php';

$email = (string) ($_SESSION['user']['email'] ?? '');
$mimirCircuit = mimir_circuit_public_state();
$mimirEvents = mimir_event_log_recent(40);
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
    <link rel="service-desc" type="application/openapi+yaml" href="openapi.yaml" title="Mímir OpenAPI">
    <link rel="alternate" type="application/openapi+json" href="openapi.json" title="Mímir OpenAPI JSON">
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
            <div class="hero-aside">
                <p class="who"><?= mimir_h($email) ?></p>
                <p class="circuit <?= $mimirCircuit['open'] ? 'circuit-bypass' : 'circuit-normal' ?>">
                    Circuit: <strong><?= mimir_event_escape($mimirCircuit['label']) ?></strong><?php if ($mimirCircuit['since'] !== ''): ?> sinds <?= mimir_event_escape($mimirCircuit['since']) ?><?php endif; ?>
                </p>
                <p class="shared-global" id="shared-global" title="Percentage van alle query-API-aanroepen (alle sleutels) in de afgelopen 7 dagen dat volledig uit cache kwam die door een andere sleutel is gevuld — zonder Business Central te bellen.">Gedeeld <strong>—</strong></p>
            </div>
        </header>

        <nav class="tabs" role="tablist" aria-label="Weergave">
            <button type="button" role="tab" id="tab-explorer" aria-controls="view-explorer" aria-selected="true" data-tab="explorer">OData-verkenner</button>
            <button type="button" role="tab" id="tab-metadata" aria-controls="view-metadata" aria-selected="false" data-tab="metadata" tabindex="-1">Webservice-metadata</button>
        </nav>

        <div id="view-explorer" role="tabpanel" aria-labelledby="tab-explorer">
        <section class="panel">
            <div class="panel-head">
                <h2>Tabel</h2>
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
            </div>
            <p id="status" class="status" role="status"></p>
        </section>

        <section class="panel" id="schema-panel" hidden>
            <div class="panel-head">
                <h2>Filters en kolommen</h2>
            </div>
            <div class="split">
                <div class="filters-pane">
                    <div id="filters"></div>
                    <div class="row-actions">
                        <button type="button" id="add-condition">Voorwaarde</button>
                        <button type="button" id="add-group">Groep</button>
                    </div>
                    <div class="run-bar">
                        <button type="button" id="run" class="primary" disabled>Ophalen</button>
                    </div>
                </div>
                <div class="columns-pane">
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
                <p>De volledige sleutel blijft zichtbaar voor jou. Hij staat in leesbare vorm in de SQLite-database achter deze login, en de lookup gebruikt SHA-256. Per sleutel zie je het daggemiddelde van de laatste maand, welk aandeel van de query-aanroepen afgelopen week geen Business Central nodig had dankzij cache van een andere sleutel/app («Waarvan gedeeld»), en een weekraster van de aanroepen (maandag tot zondag).</p>
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
                            <th>Waarvan gedeeld</th>
                            <th>Laatste weken</th>
                            <th>Aangemaakt</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </section>

        <section class="panel" id="reliability">
            <div class="panel-head">
                <h2>Gebeurtenissen</h2>
                <p>Circuit <strong><?= mimir_event_escape($mimirCircuit['label']) ?></strong><?php if ($mimirCircuit['since'] !== ''): ?> sinds <?= mimir_event_escape($mimirCircuit['since']) ?><?php endif; ?>. Tijden in Europe/Amsterdam. Het log staat buiten de SQLite-database.</p>
            </div>
            <div class="table-wrap">
                <table id="events">
                    <thead>
                        <tr>
                            <th>Tijd</th>
                            <th>Categorie</th>
                            <th>Omgeving</th>
                            <th>Entity</th>
                            <th>Actie</th>
                            <th>Aanroeper</th>
                            <th>Bericht</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($mimirEvents === []): ?>
                            <tr><td colspan="7" class="empty">Nog geen gebeurtenissen.</td></tr>
                        <?php else: ?>
                            <?php foreach (array_reverse($mimirEvents) as $event): ?>
                                <tr>
                                    <td><?= mimir_event_escape((string) $event['ts']) ?></td>
                                    <td><?= mimir_event_escape((string) $event['category']) ?></td>
                                    <td><?= mimir_event_escape((string) $event['environment']) ?></td>
                                    <td><?= mimir_event_escape((string) $event['entity']) ?></td>
                                    <td><?= mimir_event_escape((string) $event['action']) ?></td>
                                    <td><?= mimir_event_escape((string) ($event['caller'] ?? '')) ?></td>
                                    <td><?= mimir_event_escape((string) $event['message']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
        </div>

        <div id="view-metadata" role="tabpanel" aria-labelledby="tab-metadata" hidden>
            <section class="panel">
                <div class="panel-head">
                    <h2>Webservice-metadata</h2>
                    <p>Alle OData-tabellen (entity sets) van Business Central met sleutels, velden en types, uit <code>$metadata</code>. De lijst komt uit de cache in Mímir: nightly.php ververst hem dagelijks, of gebruik Vernieuwen. Machine-readable: <a href="api/metadata.php">api/metadata.php</a> (zie <a href="openapi.yaml">OpenAPI</a>).</p>
                </div>
                <div class="toolbar metadata-toolbar">
                    <label class="field" id="md-environment-field" hidden>
                        <span>Environment</span>
                        <select id="md-environment"></select>
                    </label>
                    <label class="field grow">
                        <span>Zoek op tabel of veld</span>
                        <input id="md-search" type="search" placeholder="Bijvoorbeeld ItemList of Vendor_No" autocomplete="off">
                    </label>
                    <button type="button" id="md-refresh">Vernieuwen</button>
                </div>
                <div class="metadata-summary">
                    <p id="md-count" class="md-count" role="status">Metadata laden…</p>
                    <p id="md-updated" class="md-updated"></p>
                </div>
                <p id="md-status" class="status" role="status"></p>
                <div class="pager" id="md-pager-top"></div>
                <div id="md-list" class="md-list"></div>
                <div class="pager" id="md-pager-bottom"></div>
            </section>
        </div>
    </div>
    <footer class="site-footer">
        <p>API-documentatie: <a href="openapi.yaml">OpenAPI (YAML)</a> · <a href="openapi.json">JSON</a> · Metadata: <a href="api/metadata.php">api/metadata.php</a></p>
    </footer>
    <script src="mimir.js"></script>
</body>
</html>
