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
mimir_tn_same(mimir_tunnel_route_for('list'), 'odata', 'unconfigured list stays odata');
mimir_tn_same(mimir_tunnel_route_for('bulk'), 'odata', 'unconfigured bulk stays odata');
mimir_tn_same(mimir_tunnel_route_for('card'), 'odata', 'unconfigured card stays odata');
mimir_tn_same(mimir_tunnel_route_for('action'), 'odata', 'unconfigured action stays odata');
mimir_tn_same(mimir_tunnel_normalize_route(null), null, 'missing route is automatic');
mimir_tn_same(mimir_tunnel_normalize_route('odata'), 'odata', 'odata is allowed without a tunnel');
$rejected = false;
try {
    mimir_tunnel_normalize_route('tunnel');
} catch (MimirUserException $error) {
    $rejected = $error->status === 400;
}
mimir_tn_same($rejected, true, 'forcing the tunnel without config is an error');

mimir_tn_enable();
mimir_tn_same(mimir_tunnel_route_for('list'), 'tunnel', 'a list uses the tunnel');
mimir_tn_same(mimir_tunnel_route_for('bulk'), 'tunnel', 'bulk uses the tunnel');
mimir_tn_same(mimir_tunnel_route_for('card'), 'tunnel', 'card uses the tunnel');
mimir_tn_same(mimir_tunnel_route_for('action'), 'tunnel', 'action uses the tunnel when configured');
mimir_tn_same(mimir_tunnel_route_for('list', 'odata'), 'odata', 'odata preference wins');
mimir_tn_same(mimir_tunnel_normalize_route(' Tunnel '), 'tunnel', 'route is trimmed and lowercased');
$badRoute = false;
try {
    mimir_tunnel_normalize_route('bc');
} catch (MimirUserException $error) {
    $badRoute = true;
}
mimir_tn_same($badRoute, true, 'an unknown route is rejected');

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
mimir_tn_same(array_values(array_filter($odataCalls, static fn ($u): bool => !preg_match('/\?\$top=\d+$/', (string) $u))), [], 'fresh cache does not call OData (behalve eenmalige formaat-steekproef)');
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
mimir_tn_same(array_values(array_filter($odataCalls, static fn ($u): bool => !preg_match('/\?\$top=\d+$/', (string) $u))), [], 'small list does not call OData (behalve eenmalige formaat-steekproef)');
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
mimir_tn_same(array_values(array_filter($odataCalls, static fn ($u): bool => !preg_match('/\?\$top=\d+$/', (string) $u))), [], 'stale-name list still uses the tunnel (behalve eenmalige formaat-steekproef)');
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

// Bulk (top 0) gaat ook via de tunnel. De koppeling is al warm.
$tunnelBefore = count($tunnelCalls);
$odataCalls = [];
$bulk = mimir_query_entity($pdo, mimir_tn_job($pdo, ['top' => 0, 'max_age' => 0]), static function (string $url) use (&$odataCalls): array {
    $odataCalls[] = $url;

    return ['value' => [['No' => 'B', 'Description' => 'bulk']]];
}, $now + 10);
mimir_tn_same(array_values(array_filter($odataCalls, static fn ($u): bool => !preg_match('/\?\$top=\d+$/', (string) $u))), [], 'unlimited read does not call OData (behalve eenmalige formaat-steekproef)');
mimir_tn_same(count($tunnelCalls) > $tunnelBefore, true, 'unlimited read calls the tunnel');
$bulkUrl = $tunnelCalls[count($tunnelCalls) - 1];
mimir_tn_same(str_contains($bulkUrl, '/page/31'), true, 'unlimited read uses the mapped page');
mimir_tn_same(str_contains($bulkUrl, 'top=' . MIMIR_TUNNEL_PAGE_ROWS), true, 'unlimited read asks the bridge for its window');
mimir_tn_same($bulk['meta']['route'] ?? null, 'tunnel', 'bulk meta says tunnel');
mimir_tn_same($bulk['value'][0]['Description'] ?? null, 'Pomp', 'bulk row came from the tunnel');

// route=odata forceert OData, ook voor een kleine lijst.
$tunnelBefore = count($tunnelCalls);
$odataCalls = [];
$forcedOdata = mimir_query_entity($pdo, mimir_tn_job($pdo, [
    'top' => 20,
    'max_age' => 0,
    'route' => 'odata',
]), static function (string $url) use (&$odataCalls): array {
    $odataCalls[] = $url;

    return ['value' => [['No' => 'O', 'Description' => 'gedwongen odata']]];
}, $now + 11);
mimir_tn_same(count($tunnelCalls), $tunnelBefore, 'route odata does not call the tunnel');
mimir_tn_same(count($odataCalls) >= 1, true, 'route odata calls OData');
mimir_tn_same($forcedOdata['meta']['route'] ?? null, 'odata', 'forced OData is named in meta');
mimir_tn_same($forcedOdata['value'][0]['Description'] ?? null, 'gedwongen odata', 'forced OData row is returned');

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
mimir_tn_same(array_values(array_filter($odataCalls, static fn ($u): bool => !preg_match('/\?\$top=\d+$/', (string) $u))), [], 'a refreshed page does not fall back to OData (behalve eenmalige formaat-steekproef)');
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
mimir_tn_same(array_values(array_filter($odataCalls, static fn ($u): bool => !preg_match('/\?\$top=\d+$/', (string) $u))), [], 'learned caption stays on the tunnel (behalve eenmalige formaat-steekproef)');
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
mimir_tn_same(array_values(array_filter($odataCalls, static fn ($u): bool => !preg_match('/\?\$top=\d+$/', (string) $u))), [], 'card refresh does not call OData (behalve eenmalige formaat-steekproef)');
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

// route=tunnel valt niet terug op OData, ook niet als het circuit open is.
mimir_tn_enable();
mimir_tn_clear_circuit();
$clock = 1_700_000_000;
$forcePdo = mimir_db(':memory:');
mimir_meta_put($forcePdo, mimir_tunnel_cache_key('kvtmdlive_aad'), [
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

    return ['status' => 503, 'body' => '{"ok":false,"error":"Geen vrije BC-sessie","code":"QUEUE_TIMEOUT"}'];
};
$forcedError = null;
try {
    mimir_query_entity($forcePdo, mimir_tn_job($forcePdo, [
        'top' => 5,
        'max_age' => 0,
        'route' => 'tunnel',
    ]), static function (string $url) use (&$odataCalls): array {
        $odataCalls[] = $url;

        return ['value' => [['No' => 'Z', 'Description' => 'uit odata']]];
    }, $clock);
} catch (MimirUserException $error) {
    $forcedError = $error;
}
mimir_tn_same($forcedError instanceof MimirUserException, true, 'forced tunnel failure is an error');
mimir_tn_same($forcedError?->status, 502, 'forced tunnel failure is HTTP 502');
mimir_tn_same(array_values(array_filter($odataCalls, static fn ($u): bool => !preg_match('/\?\$top=\d+$/', (string) $u))), [], 'forced tunnel failure does not call OData (behalve eenmalige formaat-steekproef)');
mimir_tn_same(str_contains($forcedError?->getMessage() ?? '', 'geen terugval'), true, 'the error says there is no OData fallback');
for ($i = 0; $i < 2; $i++) {
    try {
        mimir_query_entity($forcePdo, mimir_tn_job($forcePdo, [
            'top' => 5,
            'max_age' => 0,
            'route' => 'tunnel',
        ]), static function (string $url): array {
            unset($url);

            return ['value' => []];
        }, $clock + 10 + $i);
    } catch (MimirUserException) {
    }
}
mimir_tn_same(mimir_tunnel_circuit_open(), true, 'forced outages still open the circuit');
$callsBeforeBypass = count($tunnelCalls);
$bypassed = null;
try {
    $bypassed = mimir_query_entity($forcePdo, mimir_tn_job($forcePdo, [
        'top' => 5,
        'max_age' => 0,
        'route' => 'tunnel',
    ]), static function (string $url) use (&$odataCalls): array {
        $odataCalls[] = $url;

        return ['value' => [['No' => 'Z', 'Description' => 'uit odata']]];
    }, $clock + 40);
} catch (MimirUserException) {
}
mimir_tn_same(count($tunnelCalls) > $callsBeforeBypass, true, 'forced tunnel still calls the bridge while the circuit is open');
mimir_tn_same(array_values(array_filter($odataCalls, static fn ($u): bool => !preg_match('/\?\$top=\d+$/', (string) $u))), [], 'an open circuit does not add an OData call when the tunnel is forced (behalve eenmalige formaat-steekproef)');
mimir_tn_same($bypassed, null, 'the forced call while the circuit is open still fails');

// Top 0 via de tunnel: pagina's van MIMIR_TUNNEL_PAGE_ROWS met skip, tot een korte pagina.
mimir_tn_reset_config();
mimir_tn_clear_circuit();
mimir_tn_enable();
$pagePdo = mimir_db(':memory:');
$pageCalls = [];
$GLOBALS['mimir_tunnel_transport'] = static function (string $method, string $url, array $headers) use (&$pageCalls): array {
    unset($method, $headers);
    if (str_contains($url, '/page/810')) {
        return ['status' => 200, 'body' => json_encode(mimir_tn_services_body())];
    }
    $pageCalls[] = $url;
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    $skip = (int) ($q['skip'] ?? 0);
    $top = (int) ($q['top'] ?? 0);
    $total = 2 * MIMIR_TUNNEL_PAGE_ROWS + 7;
    $rows = [];
    for ($i = $skip; $i < min($total, $skip + $top); $i++) {
        $rows[] = ['bookmark' => 'b' . $i, 'cells' => ['No.' => 'N' . $i, 'Description' => 'r' . $i]];
    }

    return ['status' => 200, 'body' => json_encode(['rows' => $rows, 'truncated' => count($rows) === $top])];
};
$odataCalls = [];
$paged = mimir_query_entity($pagePdo, mimir_tn_job($pagePdo, ['top' => 0, 'max_age' => 0]), static function (string $url) use (&$odataCalls): array {
    $odataCalls[] = $url;

    return ['value' => []];
}, $clock);
mimir_tn_same(array_values(array_filter($odataCalls, static fn ($u): bool => !preg_match('/\?\$top=\d+$/', (string) $u))), [], 'paged top 0 does not call OData (behalve eenmalige formaat-steekproef)');
mimir_tn_same(count($pageCalls), 3, 'paged top 0 reads three bridge pages');
mimir_tn_same(str_contains($pageCalls[1], 'skip=' . MIMIR_TUNNEL_PAGE_ROWS), true, 'second page skips one page');
mimir_tn_same(count($paged['value']), 2 * MIMIR_TUNNEL_PAGE_ROWS + 7, 'paged top 0 returns every row');
mimir_tn_same(mimir_tunnel_page_top(['kind' => 'list', 'top' => 50]), 50, 'small list asks for its top');
mimir_tn_same(mimir_tunnel_page_top(['kind' => 'list', 'top' => 5000]), MIMIR_TUNNEL_PAGE_ROWS, 'large list is paged');

// Tunnel valt weg na de eerste pagina: nette fout, geen OData met tunnel-skip.
$pageCalls = [];
$GLOBALS['mimir_tunnel_transport'] = static function (string $method, string $url, array $headers) use (&$pageCalls): array {
    unset($method, $headers);
    if (str_contains($url, '/page/810')) {
        return ['status' => 200, 'body' => json_encode(mimir_tn_services_body())];
    }
    $pageCalls[] = $url;
    if (str_contains($url, 'skip=')) {
        return ['status' => 503, 'body' => json_encode(['ok' => false, 'error' => 'dood', 'code' => 'SESSION_DEAD'])];
    }
    $rows = [];
    for ($i = 0; $i < MIMIR_TUNNEL_PAGE_ROWS; $i++) {
        $rows[] = ['bookmark' => 'b' . $i, 'cells' => ['No.' => 'M' . $i, 'Description' => 'm']];
    }

    return ['status' => 200, 'body' => json_encode(['rows' => $rows])];
};
$midPdo = mimir_db(':memory:');
$odataCalls = [];
$midStatus = null;
try {
    mimir_query_entity($midPdo, mimir_tn_job($midPdo, ['top' => 0, 'max_age' => 0]), static function (string $url) use (&$odataCalls): array {
        $odataCalls[] = $url;

        return ['value' => []];
    }, $clock);
} catch (MimirUserException $error) {
    $midStatus = $error->status;
}
mimir_tn_same($midStatus, 502, 'tunnel loss mid-pagination is a 502 user error');
mimir_tn_same(array_values(array_filter($odataCalls, static fn ($u): bool => !preg_match('/\?\$top=\d+$/', (string) $u))), [], 'no OData call with a tunnel skip (behalve eenmalige formaat-steekproef)');
mimir_tn_clear_circuit();

// Deadline: na de eerste pagina verlopen = 504.
$GLOBALS['mimir_deadline_at'] = microtime(true) - 1;
$dlPdo = mimir_db(':memory:');
$dlStatus = null;
try {
    mimir_query_entity($dlPdo, mimir_tn_job($dlPdo, ['top' => 0, 'max_age' => 0, 'route' => 'odata']), static function (string $url): array {
        return ['value' => [['No' => 'D' . strlen($url), 'Description' => 'd']], '@odata.nextLink' => 'https://bc.test/env/ODataV4/Company(\'KVT\')/ItemList?$skiptoken=x' . strlen($url)];
    }, $clock);
} catch (MimirUserException $error) {
    $dlStatus = $error->status;
}
unset($GLOBALS['mimir_deadline_at']);
mimir_tn_same($dlStatus, 504, 'deadline after the first page gives 504');

// Nederlandse captions in page 810.
$nl = mimir_tunnel_parse_services(['rows' => [
    ['cells' => ['Objecttype' => 'Pagina', 'Object-id' => '26', 'Servicenaam' => 'AppVendors', 'Gepubliceerd' => 'Ja']],
]]);
mimir_tn_same($nl['services']['AppVendors']['page_id'] ?? null, 26, 'Dutch captions map AppVendors to page 26');
mimir_tn_same($nl['service_caption'] ?? null, 'Servicenaam', 'Dutch service caption is remembered');

// Lege koppeling: gericht zoeken op de servicenaam, korte TTL, diagnose in de fout.
mimir_tn_reset_config();
mimir_tn_clear_circuit();
mimir_tn_enable();
$emptyPdo = mimir_db(':memory:');
$lookups = [];
$GLOBALS['mimir_tunnel_transport'] = static function (string $method, string $url, array $headers) use (&$lookups): array {
    unset($method, $headers);
    if (str_contains($url, '/page/810')) {
        $lookups[] = $url;
        if (str_contains($url, 'filter=')) {
            return ['status' => 200, 'body' => json_encode(['rows' => [['cells' => ['Servicenaam' => 'ItemList', 'Object-id' => '31', 'Objecttype' => 'Pagina']]]])];
        }

        return ['status' => 200, 'body' => json_encode(['rows' => [], 'totalRowCount' => 0])];
    }

    return ['status' => 200, 'body' => json_encode(['rows' => [['bookmark' => 'x', 'cells' => ['No.' => 'A', 'Description' => 'gevonden']]]])];
};
$found = mimir_query_entity($emptyPdo, mimir_tn_job($emptyPdo, ['top' => 5, 'max_age' => 0, 'route' => 'tunnel']), static function (string $url): array {
    unset($url);

    return ['value' => []];
}, $clock);
mimir_tn_same($found['meta']['route'] ?? null, 'tunnel', 'missing name is found via a filtered lookup');
mimir_tn_same(count($lookups), 2, 'full read then one filtered lookup');
mimir_tn_same(str_contains($lookups[1], 'Servicenaam') || str_contains($lookups[1], 'Service'), true, 'lookup filters on the service name');
$cachedMap = mimir_meta_get_raw($emptyPdo, mimir_tunnel_cache_key('kvtmdlive_aad'));
mimir_tn_same(mimir_tunnel_map_incomplete(['services' => [], 'diag' => ['truncated' => false]]), true, 'empty map is incomplete');
mimir_tn_same(is_array($cachedMap['diag'] ?? null), true, 'diag is stored with the map');
$diagError = null;
try {
    mimir_query_entity($emptyPdo, mimir_tn_job($emptyPdo, ['entity' => 'Nope', 'top' => 5, 'max_age' => 0, 'route' => 'tunnel']), static function (string $url): array {
        unset($url);

        return ['value' => []];
    }, $clock);
} catch (MimirUserException $error) {
    $diagError = $error->getMessage();
}
mimir_tn_same(is_string($diagError) && str_contains($diagError, 'page 810: 0 rijen'), true, 'error shows the page 810 diagnosis: ' . var_export($diagError, true));
mimir_tn_clear_circuit();

// Captions die niet op OData-velden passen (bijv. "Nr."): terugval op OData, geen 200 met 0 rijen.
mimir_tn_reset_config();
mimir_tn_clear_circuit();
mimir_tn_enable();
$capPdo = mimir_db(':memory:');
$GLOBALS['mimir_tunnel_transport'] = static function (string $method, string $url, array $headers): array {
    unset($method, $headers);
    if (str_contains($url, '/page/810')) {
        return ['status' => 200, 'body' => json_encode(mimir_tn_services_body())];
    }

    // Waarden die ook niet op de OData-steekproef passen: niets te koppelen.
    return ['status' => 200, 'body' => json_encode(['rows' => [['bookmark' => 'x', 'cells' => ['Nr.' => 'Q', 'Omschrijving' => 'Pomp']]]])];
};
$capOdata = [];
$capResult = mimir_query_entity($capPdo, mimir_tn_job($capPdo, ['top' => 0, 'max_age' => 0]), static function (string $url) use (&$capOdata): array {
    $capOdata[] = $url;

    return ['value' => [['No' => 'A', 'Description' => 'uit odata']]];
}, $clock);
mimir_tn_same(count($capOdata) >= 1, true, 'unmappable captions fall back to OData');
mimir_tn_same($capResult['value'][0]['Description'] ?? null, 'uit odata', 'OData rows are returned');
mimir_tn_same(str_contains(mimir_tn_log(), 'Nr.'), true, 'fallback log names the captions');
mimir_tn_clear_circuit();

// Query/Codeunit in page 810 of geen rij: direct OData, geen tunnel-leespoging, één keer loggen.
mimir_tn_reset_config();
mimir_tn_clear_circuit();
mimir_tn_enable();
$qPdo = mimir_db(':memory:');
$qCalls = [];
$GLOBALS['mimir_tunnel_transport'] = static function (string $method, string $url, array $headers) use (&$qCalls): array {
    unset($method, $headers);
    $qCalls[] = $url;
    if (str_contains($url, '/page/810')) {
        return ['status' => 200, 'body' => json_encode(['rows' => [
            ['cells' => ['Object Type' => 'Query', 'Object ID' => '50123', 'Service Name' => 'WerkordersLines', 'Published' => 'Yes']],
            ['cells' => ['Object Type' => 'Page', 'Object ID' => '31', 'Service Name' => 'itemlist', 'Published' => 'Yes']],
        ]])];
    }

    return ['status' => 200, 'body' => json_encode(['rows' => [['bookmark' => 'x', 'cells' => ['No.' => 'A', 'Description' => 'tunnel']]]])];
};
$qLogBefore = substr_count(mimir_tn_log(), 'terugval op OData: geen BC-pagina voor WerkordersLines');
for ($i = 0; $i < 3; $i++) {
    $qResult = mimir_query_entity($qPdo, mimir_tn_job($qPdo, ['entity' => 'WerkordersLines', 'top' => 5, 'max_age' => 0]), static function (string $url): array {
        unset($url);

        return ['value' => [['No' => 'Q', 'Description' => 'odata']]];
    }, $clock);
    mimir_tn_same($qResult['value'][0]['Description'] ?? null, 'odata', 'query service is read via OData');
}
$qPageCalls = array_values(array_filter($qCalls, static fn (string $u): bool => !str_contains($u, '/page/810')));
mimir_tn_same($qPageCalls, [], 'no tunnel page read for a query service');
$qLog = mimir_tn_log();
mimir_tn_same(substr_count($qLog, 'terugval op OData: geen BC-pagina voor WerkordersLines') - $qLogBefore, 1, 'no-page fallback is logged once');
mimir_tn_same(str_contains($qLog, 'objecttype Query 50123 is geen pagina'), true, 'log names the object type and id');
mimir_tn_same(mimir_tunnel_find_service_ci(['itemlist' => ['page_id' => 31]], 'ItemList'), ['page_id' => 31], 'service name match ignores case');
mimir_tn_clear_circuit();

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
