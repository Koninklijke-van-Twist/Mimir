<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/web/mimir_store.php';

function mimir_tn_fail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function mimir_tn_same(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) {
        mimir_tn_fail($message . ' expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }
}

$root = sys_get_temp_dir() . '/mimir-tunnel-' . getmypid();
if (!is_dir($root) && !mkdir($root, 0777, true) && !is_dir($root)) {
    mimir_tn_fail('temp dir');
}
$GLOBALS['mimir_runtime_dir'] = $root;
$clock = 1_700_000_000;
$GLOBALS['mimir_tunnel_now'] = static function () use (&$clock): int {
    return $clock;
};

function mimir_tn_reset_config(): void
{
    unset(
        $GLOBALS['mimir_tunnel_url'],
        $GLOBALS['mimir_tunnel_token'],
        $GLOBALS['mimir_tunnel_top_max'],
        $GLOBALS['mimir_tunnel_cooldown'],
        $GLOBALS['mimir_tunnel_transport'],
        $GLOBALS['mimir_bc_route'],
        $GLOBALS['mimir_bc_route_page']
    );
}

function mimir_tn_enable(): void
{
    $GLOBALS['mimir_tunnel_url'] = 'http://127.0.0.1:8787';
    $GLOBALS['mimir_tunnel_token'] = 'super-secret-token-value';
    $GLOBALS['mimir_tunnel_top_max'] = 200;
    $GLOBALS['mimir_tunnel_cooldown'] = 120;
}

function mimir_tn_clear_circuit(): void
{
    $path = mimir_tunnel_circuit_path();
    if (is_file($path)) {
        unlink($path);
    }
}

/** @return array{keys: list<string>, properties: array<string, string>} */
function mimir_tn_schema(): array
{
    return [
        'keys' => ['No'],
        'properties' => [
            'No' => 'Edm.String',
            'Description' => 'Edm.String',
            'Inventory' => 'Edm.Decimal',
            'Vendor_No' => 'Edm.String',
        ],
    ];
}

/** @param array<string, mixed> $overrides */
function mimir_tn_job(PDO $pdo, array $overrides = []): array
{
    return array_merge([
        'environment' => 'kvtmdlive_aad',
        'company' => 'KVT',
        'entity' => 'ItemList',
        'service_prefix' => 'https://bc.test/env/ODataV4/',
        'select' => ['No', 'Description'],
        'filter' => null,
        'max_age' => 600,
        'top' => 50,
        'key_id' => 7,
        'meta_pdo' => $pdo,
        'schema' => mimir_tn_schema(),
    ], $overrides);
}

/** @return array<string, mixed> */
function mimir_tn_services_body(): array
{
    return ['rows' => [
        ['cells' => ['Service Name' => 'ItemList', 'Object ID' => '31', 'Object Type' => 'Page', 'Published' => 'Yes']],
        ['cells' => ['Service Name' => 'LVS_Contract_Total_Price', 'Object ID' => '50100', 'Object Type' => 'Page', 'Published' => 'Yes']],
        ['cells' => ['Service Name' => 'LVS_Contract_Total_Price_2', 'Object ID' => '50101', 'Object Type' => 'Page', 'Published' => 'Yes']],
        ['cells' => ['Service Name' => 'OtherList', 'Object ID' => '99', 'Object Type' => 'Page', 'Published' => 'Yes']],
    ]];
}

function mimir_tn_log(): string
{
    $path = mimir_event_log_path();

    return is_file($path) ? (string) file_get_contents($path) : '';
}

mimir_tn_reset_config();
mimir_tn_same(mimir_tunnel_configured(), false, 'tunnel off without config');
mimir_tn_same(mimir_tunnel_route_for('list', 50), 'odata', 'unconfigured list stays odata');
mimir_tn_same(mimir_tunnel_route_for('card', 1), 'odata', 'unconfigured card stays odata');
mimir_tn_same(mimir_tunnel_route_for('action', 0), 'odata', 'unconfigured action stays odata');

mimir_tn_enable();
mimir_tn_same(mimir_tunnel_route_for('list', 1), 'tunnel', 'top 1 is a small list');
mimir_tn_same(mimir_tunnel_route_for('list', 200), 'tunnel', 'top at the threshold uses the tunnel');
mimir_tn_same(mimir_tunnel_route_for('list', 201), 'odata', 'top above the threshold stays odata');
mimir_tn_same(mimir_tunnel_route_for('bulk', 0), 'odata', 'bulk stays odata');
mimir_tn_same(mimir_tunnel_route_for('card', 1), 'tunnel', 'card uses the tunnel');
mimir_tn_same(mimir_tunnel_route_for('action', 0), 'tunnel', 'action uses the tunnel when configured');

mimir_tn_same(mimir_tunnel_caption_to_odata('No.'), 'No', 'No. becomes No');
mimir_tn_same(mimir_tunnel_caption_to_odata('Vendor No.'), 'Vendor_No', 'caption with space and dot');
mimir_tn_same(mimir_tunnel_guess_caption('No'), 'No.', 'No guesses the BC caption');
mimir_tn_same(mimir_tunnel_guess_caption('Vendor_No'), 'Vendor No.', 'Vendor_No guesses Vendor No.');
mimir_tn_same(
    mimir_tunnel_stale_successor('LVS_Contract_Total_Price', ['LVS_Contract_Total_Price', 'LVS_Contract_Total_Price_2']),
    'LVS_Contract_Total_Price_2',
    'plain name is stale when _2 exists'
);
mimir_tn_same(
    mimir_tunnel_stale_successor('LVS_Contract_Total_Price_2', ['LVS_Contract_Total_Price', 'LVS_Contract_Total_Price_2']),
    null,
    'the newest suffix is not stale'
);
mimir_tn_same(
    mimir_tunnel_stale_successor('LVS_Contract_Total_Price_2', ['LVS_Contract_Total_Price_2', 'LVS_Contract_Total_Price_3']),
    'LVS_Contract_Total_Price_3',
    'a lower suffix is stale'
);

$captions = ['captions' => ['No' => 'No.', 'City' => 'City', 'Description' => 'Description']];
mimir_tn_same(
    mimir_tunnel_odata_to_filters("(No eq 'A') or (No eq 'B')", $captions),
    ['No.=A|B'],
    'same-field or becomes one tunnel filter'
);
mimir_tn_same(
    mimir_tunnel_odata_to_filters("(No eq 'A') and (City eq 'London')", $captions),
    ['No.=A', 'City=London'],
    'and becomes separate tunnel filters'
);
mimir_tn_same(
    mimir_tunnel_odata_to_filters("contains(Description,'pomp')", $captions),
    ['Description=*pomp*'],
    'contains becomes a wildcard'
);
mimir_tn_same(mimir_tunnel_odata_to_filters("No gt 10", $captions), ['No.=>10'], 'gt keeps the comparison');
mimir_tn_same(mimir_tunnel_odata_to_filters("Description eq null", $captions), null, 'null is not a tunnel filter');
mimir_tn_same(
    mimir_tunnel_predicate_to_filters("No='A',Vendor_No='V1'", ['captions' => ['No' => 'No.', 'Vendor_No' => 'Vendor No.']]),
    ['No.=A', 'Vendor No.=V1'],
    'key predicate becomes eq filters'
);

$dutch = mimir_tunnel_parse_services(['rows' => [[
    'cells' => ['Servicenaam' => 'ItemList', 'Object-id' => '31', 'Objectsoort' => 'Pagina', 'Gepubliceerd' => 'Ja'],
]]]);
if (!is_array($dutch)) {
    mimir_tn_fail('dutch web-service row should parse');
}
mimir_tn_same($dutch['services']['ItemList']['page_id'] ?? null, 31, 'dutch captions map to page 31');
mimir_tn_same($dutch['service_caption'] ?? null, 'Servicenaam', 'service caption is remembered');

$listUrl = mimir_tunnel_inspect_url('https://bc.test/env/ODataV4/Company(\'KVT\')/ItemList?$top=50&$filter=No%20eq%20%27A%27', 'ItemList');
mimir_tn_same($listUrl['kind'], 'list', 'collection with $top is a list');
mimir_tn_same($listUrl['top'], 50, 'top is read from the query');
mimir_tn_same($listUrl['filter'], "No eq 'A'", 'filter is decoded');
$cardUrl = mimir_tunnel_inspect_url('https://bc.test/env/ODataV4/Company(\'KVT\')/ItemList(No=\'A\')', 'ItemList');
mimir_tn_same($cardUrl['kind'], 'card', 'key url is a card');
mimir_tn_same($cardUrl['predicate'], "No='A'", 'key predicate is kept');
$bulkUrl = mimir_tunnel_inspect_url('https://bc.test/env/ODataV4/Company(\'KVT\')/ItemList', 'ItemList');
mimir_tn_same($bulkUrl['kind'], 'bulk', 'url without $top is bulk');

$shaped = mimir_tunnel_odata_shape(
    ['kind' => 'list', 'top' => 10, 'filter' => null, 'predicate' => null],
    ['truncated' => true, 'rows' => [], 'fields' => []],
    []
);
mimir_tn_same($shaped['@mimir.truncated'] ?? null, true, 'truncated flag is passed through');

// Verse cache blijft cache, ook als de tunnel aan staat.
$now = $clock;
$pdo = mimir_db(':memory:');
mimir_cache_upsert($pdo, 'kvtmdlive_aad', 'KVT', 'ItemList', mimir_row_key(['No' => 'A'], ['No']), [
    'No' => 'A',
    'Description' => 'Pomp',
], $now - 30);
mimir_coverage_put($pdo, 'kvtmdlive_aad', 'KVT', 'ItemList', '', '*', $now - 30, 1);
$odataCalls = [];
$tunnelCalls = [];
$GLOBALS['mimir_tunnel_transport'] = static function (string $method, string $url, array $headers) use (&$tunnelCalls): array {
    unset($method, $headers);
    $tunnelCalls[] = $url;

    return ['status' => 500, 'body' => '{"ok":false}'];
};
$cached = mimir_query_entity($pdo, mimir_tn_job($pdo), static function (string $url) use (&$odataCalls): array {
    $odataCalls[] = $url;

    return ['value' => []];
}, $now);
mimir_tn_same($odataCalls, [], 'fresh cache does not call OData');
mimir_tn_same($tunnelCalls, [], 'fresh cache does not call the tunnel');
mimir_tn_same($cached['meta']['route'] ?? null, 'cache', 'cache hit reports route cache');
mimir_tn_same($cached['meta']['webservice'] ?? null, 'ItemList', 'cache hit still names the webservice');
mimir_tn_same($cached['value'][0]['Description'] ?? null, 'Pomp', 'cached row is unchanged');

// Kleine lijst via de tunnel.
$pdo = mimir_db(':memory:');
$tunnelCalls = [];
$odataCalls = [];
$sawBearer = false;
$sawKey = false;
$GLOBALS['mimir_tunnel_transport'] = static function (string $method, string $url, array $headers) use (&$tunnelCalls, &$sawBearer, &$sawKey): array {
    unset($method);
    $tunnelCalls[] = $url;
    foreach ($headers as $header) {
        if ($header === 'Authorization: Bearer super-secret-token-value') {
            $sawBearer = true;
        }
        if ($header === 'X-Mimir-Key-Id: 7') {
            $sawKey = true;
        }
    }
    if (str_contains($url, '/page/810')) {
        return ['status' => 200, 'body' => json_encode(mimir_tn_services_body())];
    }

    return ['status' => 200, 'body' => json_encode(['rows' => [[
        'bookmark' => 'b1',
        'cells' => ['No.' => 'A', 'Description' => 'Pomp', 'Inventory' => '3'],
    ]]])];
};
$live = mimir_query_entity($pdo, mimir_tn_job($pdo, [
    'top' => 50,
    'select' => ['No', 'Description', 'Inventory'],
]), static function (string $url) use (&$odataCalls): array {
    $odataCalls[] = $url;

    return ['value' => []];
}, $now);
mimir_tn_same($odataCalls, [], 'small list does not call OData');
mimir_tn_same(count($tunnelCalls) >= 2, true, 'page 810 and the list page are read');
mimir_tn_same(str_contains($tunnelCalls[0], '/page/810'), true, 'mapping is loaded first');
mimir_tn_same(str_contains($tunnelCalls[1], '/page/31'), true, 'list uses the mapped page');
mimir_tn_same($sawBearer, true, 'bearer token is sent');
mimir_tn_same($sawKey, true, 'key id header is sent');
mimir_tn_same($live['meta']['route'] ?? null, 'tunnel', 'meta names the tunnel');
mimir_tn_same($live['meta']['page'] ?? null, 31, 'meta names the page');
mimir_tn_same($live['meta']['webservice'] ?? null, 'ItemList', 'meta names the webservice');
mimir_tn_same($live['value'][0]['Description'] ?? null, 'Pomp', 'tunnel row is returned');
mimir_tn_same($live['value'][0]['Inventory'] ?? null, 3.0, 'decimal cell is coerced');
if (str_contains(mimir_tn_log(), 'super-secret-token-value')) {
    mimir_tn_fail('tunnel token leaked into the event log');
}
if (!str_contains(mimir_tn_log(), 'route=tunnel webservice=ItemList page=31')) {
    mimir_tn_fail('route was not logged: ' . mimir_tn_log());
}

// Verouderde webservicenaam.
$staleBefore = substr_count(mimir_tn_log(), 'Verouderde webservicenaam LVS_Contract_Total_Price;');
$odataCalls = [];
$stale = mimir_query_entity($pdo, mimir_tn_job($pdo, [
    'entity' => 'LVS_Contract_Total_Price',
    'top' => 10,
    'select' => ['No'],
]), static function (string $url) use (&$odataCalls): array {
    $odataCalls[] = $url;

    return ['value' => []];
}, $now);
mimir_tn_same($odataCalls, [], 'stale-name list still uses the tunnel');
mimir_tn_same(
    $stale['meta']['webservice_warning'] ?? null,
    'Verouderde webservicenaam LVS_Contract_Total_Price; nieuwere variant LVS_Contract_Total_Price_2.',
    'response warns about the newer webservice'
);
mimir_tn_same($stale['meta']['page'] ?? null, 50100, 'stale name still has its own page');
mimir_tn_same(substr_count(mimir_tn_log(), 'Verouderde webservicenaam LVS_Contract_Total_Price;'), $staleBefore + 1, 'stale name is logged once');
mimir_query_entity($pdo, mimir_tn_job($pdo, [
    'entity' => 'LVS_Contract_Total_Price',
    'top' => 10,
    'select' => ['No'],
    'max_age' => 0,
]), static function (string $url): array {
    unset($url);

    return ['value' => [['No' => '1']]];
}, $now + 5);
mimir_tn_same(
    substr_count(mimir_tn_log(), 'Verouderde webservicenaam LVS_Contract_Total_Price;'),
    $staleBefore + 1,
    'the same stale warning is not logged again within the hour'
);

// Bulk blijft OData. De koppeling is al warm, dus de tunnel wordt niet gebeld.
$tunnelBefore = count($tunnelCalls);
$odataCalls = [];
$bulk = mimir_query_entity($pdo, mimir_tn_job($pdo, ['top' => 0, 'max_age' => 0]), static function (string $url) use (&$odataCalls): array {
    $odataCalls[] = $url;

    return ['value' => [['No' => 'B', 'Description' => 'bulk']]];
}, $now + 10);
mimir_tn_same(count($odataCalls) >= 1, true, 'unlimited read calls OData');
mimir_tn_same(count($tunnelCalls), $tunnelBefore, 'unlimited read does not call the tunnel when the map is warm');
mimir_tn_same($bulk['meta']['route'] ?? null, 'odata', 'bulk meta says odata');
mimir_tn_same($bulk['value'][0]['Description'] ?? null, 'bulk', 'bulk row came from OData');

// Circuit: 3 storingen, daarna OData tot de cooldown voorbij is.
mimir_tn_clear_circuit();
$fail = true;
$tunnelCalls = [];
$GLOBALS['mimir_tunnel_transport'] = static function (string $method, string $url, array $headers) use (&$tunnelCalls, &$fail): array {
    unset($method, $headers);
    $tunnelCalls[] = $url;
    if (str_contains($url, '/page/810')) {
        return ['status' => 200, 'body' => json_encode(mimir_tn_services_body())];
    }
    if ($fail) {
        return ['status' => 503, 'body' => '{"ok":false,"error":"Geen vrije BC-sessie","code":"QUEUE_TIMEOUT"}'];
    }

    return ['status' => 200, 'body' => json_encode(['rows' => [[
        'cells' => ['No.' => 'C', 'Description' => 'weer tunnel'],
    ]]])];
};
$circuitPdo = mimir_db(':memory:');
for ($i = 0; $i < 3; $i++) {
    $clock = 1_700_000_000 + ($i * 10);
    $answered = mimir_query_entity($circuitPdo, mimir_tn_job($circuitPdo, ['top' => 20, 'max_age' => 0]), static function (string $url): array {
        unset($url);

        return ['value' => [['No' => 'Z', 'Description' => 'uit odata']]];
    }, $clock);
    mimir_tn_same($answered['meta']['route'] ?? null, 'odata', 'failed tunnel falls back to OData');
    mimir_tn_same($answered['value'][0]['Description'] ?? null, 'uit odata', 'fallback body is the OData row');
}
if (!str_contains(mimir_tn_log(), 'Tunnel uit na 3 mislukte pogingen')) {
    mimir_tn_fail('circuit open was not logged');
}
$callsAtOpen = count($tunnelCalls);
$clock = 1_700_000_000 + 30;
$skipped = mimir_query_entity($circuitPdo, mimir_tn_job($circuitPdo, ['top' => 20, 'max_age' => 0]), static function (string $url): array {
    unset($url);

    return ['value' => [['No' => 'Z', 'Description' => 'uit odata']]];
}, $clock);
mimir_tn_same(count($tunnelCalls), $callsAtOpen, 'open circuit does not call the tunnel');
mimir_tn_same($skipped['meta']['route'] ?? null, 'odata', 'open circuit uses OData');
$fail = false;
$clock = 1_700_000_000 + 20 + 120 + 1;
$again = mimir_query_entity($circuitPdo, mimir_tn_job($circuitPdo, ['top' => 20, 'max_age' => 0]), static function (string $url): array {
    unset($url);

    return ['value' => []];
}, $clock);
mimir_tn_same($again['meta']['route'] ?? null, 'tunnel', 'tunnel is tried again after the cooldown');
mimir_tn_same($again['value'][0]['Description'] ?? null, 'weer tunnel', 'row after cooldown comes from the tunnel');

// Verkeerde pagina: alleen die ene koppeling opnieuw ophalen.
mimir_tn_clear_circuit();
$clock = 1_700_000_000;
$pagePdo = mimir_db(':memory:');
mimir_meta_put($pagePdo, mimir_tunnel_cache_key('kvtmdlive_aad'), [
    'services' => [
        'ItemList' => ['page_id' => 31, 'object_type' => 'Page', 'captions' => [], 'published' => true],
        'OtherList' => ['page_id' => 99, 'object_type' => 'Page', 'captions' => [], 'published' => true],
    ],
    'service_caption' => 'Service Name',
    'stale_logged' => [],
], $clock);
$tunnelCalls = [];
$odataCalls = [];
$GLOBALS['mimir_tunnel_transport'] = static function (string $method, string $url, array $headers) use (&$tunnelCalls): array {
    unset($method, $headers);
    $tunnelCalls[] = $url;
    if (str_contains($url, '/page/31')) {
        return ['status' => 400, 'body' => '{"ok":false,"error":"Page 31 does not exist","code":"NOT_FOUND"}'];
    }
    if (str_contains($url, '/page/810')) {
        return ['status' => 200, 'body' => json_encode(['rows' => [[
            'cells' => ['Service Name' => 'ItemList', 'Object ID' => '32', 'Object Type' => 'Page', 'Published' => 'Yes'],
        ]]])];
    }
    if (str_contains($url, '/page/32')) {
        return ['status' => 200, 'body' => json_encode(['rows' => [[
            'cells' => ['No.' => 'A', 'Description' => 'nieuwe pagina'],
        ]]])];
    }

    return ['status' => 500, 'body' => '{"ok":false}'];
};
$fixed = mimir_query_entity($pagePdo, mimir_tn_job($pagePdo, ['top' => 5, 'max_age' => 0]), static function (string $url) use (&$odataCalls): array {
    $odataCalls[] = $url;

    return ['value' => []];
}, $clock);
mimir_tn_same($odataCalls, [], 'a refreshed page does not fall back to OData');
mimir_tn_same($fixed['meta']['page'] ?? null, 32, 'meta uses the refreshed page');
mimir_tn_same($fixed['value'][0]['Description'] ?? null, 'nieuwe pagina', 'row comes from the new page');
$stored = mimir_meta_get($pagePdo, mimir_tunnel_cache_key('kvtmdlive_aad'), MIMIR_TUNNEL_PAGE_TTL, $clock);
mimir_tn_same($stored['services']['ItemList']['page_id'] ?? null, 32, 'only ItemList was refreshed');
mimir_tn_same($stored['services']['OtherList']['page_id'] ?? null, 99, 'OtherList stayed in the cache');
$refreshed810 = false;
foreach ($tunnelCalls as $called) {
    if (str_contains($called, '/page/810') && str_contains($called, 'ItemList')) {
        $refreshed810 = true;
    }
}
mimir_tn_same($refreshed810, true, 'the single mapping was re-read from page 810');

// Onbekende filterkolom: één probe, daarna de geleerde caption.
mimir_tn_clear_circuit();
$colPdo = mimir_db(':memory:');
mimir_meta_put($colPdo, mimir_tunnel_cache_key('kvtmdlive_aad'), [
    'services' => [
        'ItemList' => ['page_id' => 31, 'object_type' => 'Page', 'captions' => [], 'published' => true],
    ],
    'service_caption' => 'Service Name',
    'stale_logged' => [],
], $clock);
$tunnelCalls = [];
$odataCalls = [];
$GLOBALS['mimir_tunnel_transport'] = static function (string $method, string $url, array $headers) use (&$tunnelCalls): array {
    unset($method, $headers);
    $tunnelCalls[] = $url;
    if (str_contains($url, 'Vendor%20No.%3D')) {
        return ['status' => 502, 'body' => '{"ok":false,"error":"Filter column not found: Vendor No.","code":"BC_ERROR"}'];
    }
    if (str_contains($url, '/page/31') && !str_contains($url, 'filter=')) {
        return ['status' => 200, 'body' => json_encode(['rows' => [[
            'cells' => ['No.' => 'A', 'Vendor No' => 'V9'],
        ]]])];
    }
    if (str_contains($url, 'Vendor%20No%3D')) {
        return ['status' => 200, 'body' => json_encode(['rows' => [[
            'cells' => ['No.' => 'A', 'Vendor No' => 'V1', 'Description' => 'leverancier'],
        ]]])];
    }

    return ['status' => 500, 'body' => '{"ok":false,"error":"unexpected ' . $url . '"}'];
};
$filtered = mimir_query_entity($colPdo, mimir_tn_job($colPdo, [
    'top' => 10,
    'max_age' => 0,
    'filter' => ['field' => 'Vendor_No', 'op' => 'eq', 'value' => 'V1'],
    'select' => ['No', 'Vendor_No'],
]), static function (string $url) use (&$odataCalls): array {
    $odataCalls[] = $url;

    return ['value' => []];
}, $clock);
mimir_tn_same($odataCalls, [], 'learned caption stays on the tunnel');
mimir_tn_same($filtered['meta']['route'] ?? null, 'tunnel', 'column retry is still the tunnel');
mimir_tn_same($filtered['value'][0]['Vendor_No'] ?? null, 'V1', 'vendor number came from the retried read');
$learned = mimir_meta_get($colPdo, mimir_tunnel_cache_key('kvtmdlive_aad'), MIMIR_TUNNEL_PAGE_TTL, $clock);
mimir_tn_same($learned['services']['ItemList']['captions']['Vendor_No'] ?? null, 'Vendor No', 'caption was cached on the one service');

// Kaart: ontbrekende kolom ververst één sleutel via de tunnel.
mimir_tn_clear_circuit();
$cardPdo = mimir_db(':memory:');
mimir_cache_upsert($cardPdo, 'kvtmdlive_aad', 'KVT', 'ItemList', mimir_row_key(['No' => 'A'], ['No']), [
    'No' => 'A',
    'Description' => 'Pomp',
], $clock - 20);
mimir_coverage_put($cardPdo, 'kvtmdlive_aad', 'KVT', 'ItemList', '', '*', $clock - 20, 1);
mimir_meta_put($cardPdo, mimir_tunnel_cache_key('kvtmdlive_aad'), [
    'services' => [
        'ItemList' => ['page_id' => 31, 'object_type' => 'Page', 'captions' => ['No' => 'No.'], 'published' => true],
    ],
    'service_caption' => 'Service Name',
    'stale_logged' => [],
], $clock);
$odataCalls = [];
$tunnelCalls = [];
$GLOBALS['mimir_tunnel_transport'] = static function (string $method, string $url, array $headers) use (&$tunnelCalls): array {
    unset($method, $headers);
    $tunnelCalls[] = $url;

    return ['status' => 200, 'body' => json_encode([
        'rows' => [],
        'fields' => [
            ['name' => 'No.', 'value' => 'A'],
            ['name' => 'Description', 'value' => 'Pomp'],
            ['name' => 'Inventory', 'value' => '9'],
        ],
    ])];
};
$card = mimir_query_entity($cardPdo, mimir_tn_job($cardPdo, [
    'select' => ['No', 'Description', 'Inventory'],
]), static function (string $url) use (&$odataCalls): array {
    $odataCalls[] = $url;

    return ['No' => 'A', 'Description' => 'van odata', 'Inventory' => 1];
}, $clock);
mimir_tn_same($odataCalls, [], 'card refresh does not call OData');
mimir_tn_same($tunnelCalls !== [] && str_contains($tunnelCalls[0], '/page/31'), true, 'card opens the mapped page');
mimir_tn_same(str_contains(rawurldecode($tunnelCalls[0]), 'No.=A'), true, 'card filter uses the key');
mimir_tn_same($card['meta']['route'] ?? null, 'tunnel', 'card meta says tunnel');
mimir_tn_same($card['value'][0]['Inventory'] ?? null, 9.0, 'missing column came from the card');
mimir_tn_same($card['value'][0]['Description'] ?? null, 'Pomp', 'card keeps the description');

// Koppelcache legen laat andere meta staan.
mimir_meta_put($cardPdo, 'metadata:keep', ['entity_sets' => []], $clock);
$cleared = mimir_tunnel_clear_page_cache($cardPdo);
mimir_tn_same($cleared, 1, 'one page map was deleted');
mimir_tn_same(mimir_meta_get($cardPdo, mimir_tunnel_cache_key('kvtmdlive_aad'), MIMIR_TUNNEL_PAGE_TTL, $clock), null, 'page map is gone');
mimir_tn_same(mimir_meta_get($cardPdo, 'metadata:keep', 3600, $clock) !== null, true, 'other meta stays');

// Zonder config is het antwoord zoals voorheen: geen route-veld.
mimir_tn_reset_config();
mimir_tn_clear_circuit();
$plain = mimir_db(':memory:');
$plainResult = mimir_query_entity($plain, mimir_tn_job($plain, ['meta_pdo' => $plain, 'top' => 10, 'max_age' => 0]), static function (string $url): array {
    unset($url);

    return ['value' => [['No' => 'A', 'Description' => 'odata']]];
}, $clock);
mimir_tn_same(array_key_exists('route', $plainResult['meta']), false, 'unconfigured response has no route');
mimir_tn_same(array_key_exists('webservice', $plainResult['meta']), false, 'unconfigured response has no webservice');
mimir_tn_same(array_key_exists('page', $plainResult['meta']), false, 'unconfigured response has no page');
mimir_tn_same($plainResult['value'][0]['Description'] ?? null, 'odata', 'unconfigured read is OData');

fwrite(STDOUT, "ok\n");
