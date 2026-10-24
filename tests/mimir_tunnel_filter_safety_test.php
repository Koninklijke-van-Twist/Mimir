<?php

declare(strict_types=1);

define('MIMIR_DEFAULT_ROUTE', ''); // automatische route testen

// Tunnelfilters: alleen vertalen wat betrouwbaar is, anders OData; 0 rijen op een
// gefilterde query wordt één keer via OData gecontroleerd.

require_once dirname(__DIR__) . '/web/mimir_store.php';

function mimir_tfs_fail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function mimir_tfs_same(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) {
        mimir_tfs_fail($message . ' expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }
}

$root = sys_get_temp_dir() . '/mimir-tfs-' . getmypid();
if (!is_dir($root) && !mkdir($root, 0777, true) && !is_dir($root)) {
    mimir_tfs_fail('temp dir');
}
$GLOBALS['mimir_runtime_dir'] = $root;
$clock = 1_700_000_000;
$GLOBALS['mimir_tunnel_now'] = static function () use (&$clock): int {
    return $clock;
};

// --- Operatoren met lege string gaan niet naar de tunnel.
$plain = ['captions' => ['Contract_No' => 'Contract No.']];
mimir_tfs_same(mimir_tunnel_odata_to_filters("Contract_No ne ''", $plain), null, "ne '' is not a tunnel filter");
mimir_tfs_same(mimir_tunnel_odata_to_filters("Contract_No gt ''", $plain), null, "gt '' is not a tunnel filter");
mimir_tfs_same(mimir_tunnel_odata_to_filters("Contract_No eq ''", $plain), null, "eq '' is not a tunnel filter");
mimir_tfs_same(mimir_tunnel_odata_to_filters("Contract_No eq 'x'", $plain), ['Contract No.=x'], 'eq x still works');
mimir_tfs_same(mimir_tunnel_odata_to_filters("Contract_No eq 'A*'", $plain), null, 'wildcard inside a value is not passed on');
mimir_tfs_same(mimir_tunnel_odata_to_filters("Contract_No eq 'A..B'", $plain), null, 'range inside a value is not passed on');
mimir_tfs_same(mimir_tunnel_filters_for(['kind' => 'list', 'filter' => "Contract_No ne ''", 'predicate' => null, 'top' => 5], []), null, 'syntax check without entry');

// --- Met geleerde koppeling: alleen kolommen die de bridge nu teruggeeft.
$english = [
    'captions' => [
        // Nederlandse caption van vóór de taalwissel blijft in het cache staan.
        'Salesperson_Code' => 'Verkopercode',
        'Contract_No' => 'Contract No.',
        'Project_Manager' => 'Projectleider',
    ],
    'columns' => [
        'map' => ['Contract No.' => 'Contract_No', 'Salesperson Code' => 'Salesperson_Code', 'Name' => 'Name', 'Name#2' => 'Name_2'],
        'seen' => ['Contract No.', 'Salesperson Code', 'Name', 'Name#2', 'Description'],
        'complete' => true,
    ],
];
mimir_tfs_same(mimir_tunnel_caption_for('Salesperson_Code', $english), 'Salesperson Code', 'learned map wins over a stale dutch caption');
mimir_tfs_same(mimir_tunnel_odata_to_filters("Salesperson_Code eq 'NZ'", $english), ['Salesperson Code=NZ'], 'NZ goes to the english column');
mimir_tfs_same(mimir_tunnel_caption_for('Project_Manager', $english), null, 'stale caption not in seen is unknown');
mimir_tfs_same(mimir_tunnel_caption_for('Name_2', $english), null, 'duplicate caption is ambiguous');
mimir_tfs_same(mimir_tunnel_caption_for('Description', $english), 'Description', 'guess that the bridge shows is fine');
mimir_tfs_same(mimir_tunnel_caption_for('KVT_Sales_Person_Code', $english), null, 'unseen guess is unknown');
mimir_tfs_same(mimir_tunnel_odata_to_filters("KVT_Sales_Person_Code eq 'NZ'", $english), null, 'unknown column -> OData');
// --- DesignName-sleutels, KVT-velden alleen via $metadata gekoppeld (niet in de geleerde map).
$design = ['columns' => ['map' => ['No.' => 'No', 'Description' => 'Contract_No'], 'seen' => ['No.', 'Description', 'KVT_Contract_No', 'KVT_Sales_Person_Code'], 'key_by' => 'designName']];
mimir_tfs_same(mimir_tunnel_odata_to_filters("KVT_Contract_No eq 'CT26000230'", $design), ['KVT_Contract_No=CT26000230'], 'designName KVT column filters');
mimir_tfs_same(mimir_tunnel_caption_for('KVT_Sales_Person_Code', $design), 'KVT_Sales_Person_Code', 'designName KVT salesperson');
mimir_tfs_same(mimir_tunnel_caption_for('No', $design), 'No.', 'No still maps');
$wrongGuess = ['columns' => ['map' => ['Name' => 'Search_Name'], 'seen' => ['Name']]];
mimir_tfs_same(mimir_tunnel_caption_for('Name', $wrongGuess), null, 'guess that belongs to another field is refused');
// Zonder geleerde koppeling: oude gedrag (gok).
mimir_tfs_same(mimir_tunnel_caption_for('Vendor_No', []), 'Vendor No.', 'no learned columns keeps the guess');

// --- Integratie: 0 rijen op gefilterde query -> één keer OData, gelogd.
$GLOBALS['mimir_tunnel_url'] = 'http://127.0.0.1:8787';
$GLOBALS['mimir_tunnel_token'] = 'super-secret-token-value';
$GLOBALS['mimir_tunnel_cooldown'] = 120;

function mimir_tfs_job(PDO $pdo, array $overrides = []): array
{
    return array_merge([
        'environment' => 'kvtmdlive_aad',
        'company' => 'KVT',
        'entity' => 'ItemList',
        'service_prefix' => 'https://bc.test/env/ODataV4/',
        'select' => ['No', 'Description'],
        'filter' => null,
        'max_age' => 0,
        'top' => 5,
        'key_id' => 7,
        'meta_pdo' => $pdo,
        'schema' => ['keys' => ['No'], 'properties' => ['No' => 'Edm.String', 'Description' => 'Edm.String', 'Vendor_No' => 'Edm.String']],
    ], $overrides);
}

function mimir_tfs_pdo(int $clock): PDO
{
    $pdo = mimir_db(':memory:');
    mimir_meta_put($pdo, mimir_tunnel_cache_key('kvtmdlive_aad'), [
        'services' => ['ItemList' => [
            'page_id' => 31, 'object_type' => 'Page', 'published' => true,
            'captions' => ['No' => 'No.', 'Description' => 'Description', 'Vendor_No' => 'Vendor No.'],
            'columns' => ['map' => ['No.' => 'No', 'Description' => 'Description', 'Vendor No.' => 'Vendor_No'], 'seen' => ['No.', 'Description', 'Vendor No.'], 'learned_at' => $clock, 'complete' => true],
        ]],
        'service_caption' => 'Service Name',
        'stale_logged' => [],
    ], $clock);

    return $pdo;
}

$tunnelCalls = [];
$GLOBALS['mimir_tunnel_transport'] = static function (string $method, string $url, array $headers) use (&$tunnelCalls): array {
    unset($method, $headers);
    $tunnelCalls[] = rawurldecode($url);
    if (str_contains($url, 'filter=')) {
        return ['status' => 200, 'body' => json_encode(['rows' => []])];
    }

    return ['status' => 200, 'body' => json_encode(['rows' => [['cells' => ['No.' => 'A', 'Description' => 'x', 'Vendor No.' => 'V1']]]])];
};
$odataFetch = static function (string $url) use (&$odataCalls): array {
    $odataCalls[] = rawurldecode($url);

    return ['value' => [['No' => 'A', 'Description' => 'x', 'Vendor_No' => 'V1']]];
};

$odataCalls = [];
$pdo = mimir_tfs_pdo($clock);
$res = mimir_query_entity($pdo, mimir_tfs_job($pdo, ['filter' => ['field' => 'Vendor_No', 'op' => 'eq', 'value' => 'V1']]), $odataFetch, $clock);
mimir_tfs_same(count($res['value']), 1, '0 tunnel rows on a filter is rechecked via OData');
mimir_tfs_same(count($odataCalls), 1, 'exactly one OData call');
mimir_tfs_same(str_contains(mimir_event_log_path() !== '' && is_file(mimir_event_log_path()) ? (string) file_get_contents(mimir_event_log_path()) : '', 'controle via OData'), true, 'fallback is logged');

// Daarna uit de cache: niet leeg (het lege tunnelantwoord is niet opgeslagen).
$odataCalls = [];
$cached = mimir_query_entity($pdo, mimir_tfs_job($pdo, ['max_age' => 600, 'filter' => ['field' => 'Vendor_No', 'op' => 'eq', 'value' => 'V1']]), $odataFetch, $clock + 5);
mimir_tfs_same(count($cached['value']), 1, 'cached result after the recheck is not empty');

// Lege-string-operator: geen tunnelaanroep met filter, direct OData.
$odataCalls = [];
$tunnelCalls = [];
$pdo = mimir_tfs_pdo($clock);
$res = mimir_query_entity($pdo, mimir_tfs_job($pdo, ['filter' => ['field' => 'Vendor_No', 'op' => 'ne', 'value' => '']]), $odataFetch, $clock);
mimir_tfs_same(count($res['value']), 1, "ne '' comes from OData");
mimir_tfs_same(count(array_filter($tunnelCalls, static fn (string $u): bool => str_contains($u, '/page/'))), 0, "ne '' does not touch the tunnel page");

// Ongefilterd blijft tunnel.
$odataCalls = [];
$pdo = mimir_tfs_pdo($clock);
$res = mimir_query_entity($pdo, mimir_tfs_job($pdo), $odataFetch, $clock);
mimir_tfs_same($res['meta']['route'] ?? null, 'tunnel', 'unfiltered stays on the tunnel');
mimir_tfs_same(array_values(array_filter($odataCalls, static fn ($u): bool => !preg_match('/\?\$top=\d+$/', (string) $u))), [], 'unfiltered needs no OData (behalve eenmalige formaat-steekproef)');

// Gefilterd met rijen blijft tunnel.
$GLOBALS['mimir_tunnel_transport'] = static function (string $method, string $url, array $headers): array {
    unset($method, $headers);

    return ['status' => 200, 'body' => json_encode(['rows' => [['cells' => ['No.' => 'A', 'Description' => 'x', 'Vendor No.' => 'V1']]]])];
};
$odataCalls = [];
$pdo = mimir_tfs_pdo($clock);
$res = mimir_query_entity($pdo, mimir_tfs_job($pdo, ['filter' => ['field' => 'Vendor_No', 'op' => 'eq', 'value' => 'V1']]), $odataFetch, $clock);
mimir_tfs_same($res['meta']['route'] ?? null, 'tunnel', 'working filter stays on the tunnel');
mimir_tfs_same(array_values(array_filter($odataCalls, static fn ($u): bool => !preg_match('/\?\$top=\d+$/', (string) $u))), [], 'working filter needs no OData (behalve eenmalige formaat-steekproef)');

echo "mimir_tunnel_filter_safety_test: ok\n";
