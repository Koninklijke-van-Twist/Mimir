<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/web/mimir_store.php';

function mimir_bc_fail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function mimir_bc_same(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) {
        mimir_bc_fail($message . ' expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }
}

$now = 1_700_000_000;
$schema = [
    'keys' => ['No'],
    'properties' => [
        'No' => 'Edm.String',
        'Description' => 'Edm.String',
    ],
];
$prefix = 'https://bc.test/env/ODataV4/';

function mimir_bc_job(array $overrides = []): array
{
    global $schema, $prefix;
    return array_merge([
        'environment' => 'kvtmdlive_aad',
        'company' => 'KVT',
        'entity' => 'WorkOrders',
        'service_prefix' => $prefix,
        'select' => ['No', 'Description'],
        'filter' => null,
        'max_age' => 600,
        'top' => 0,
        'schema' => $schema,
    ], $overrides);
}

// --- (1) Filtered request served from fresh empty-filter coverage (local filter) ---
$pdo = mimir_db(':memory:');
foreach ([['WO0005', 'a'], ['WO0015', 'b'], ['WO0022', 'c'], ['WO0030', 'd']] as [$no, $d]) {
    mimir_cache_upsert($pdo, 'kvtmdlive_aad', 'KVT', 'WorkOrders', mimir_row_key(['No' => $no], ['No']), [
        'No' => $no,
        'Description' => $d,
    ], $now - 10);
}
mimir_coverage_put($pdo, 'kvtmdlive_aad', 'KVT', 'WorkOrders', '', '*', $now - 10, 4, 1);
$calls = [];
$fromEmpty = mimir_query_entity($pdo, mimir_bc_job([
    'filter' => [
        'and' => [
            ['field' => 'No', 'op' => 'ge', 'value' => 'WO0015'],
            ['field' => 'No', 'op' => 'le', 'value' => 'WO0022'],
        ],
    ],
    'key_id' => 2,
]), function (string $url) use (&$calls): array {
    $calls[] = $url;
    return ['value' => []];
}, $now);
mimir_bc_same($calls, [], 'filtered request uses empty-filter coverage without BC');
mimir_bc_same($fromEmpty['meta']['bc_hit'], 0, 'no bc_hit from empty coverage');
mimir_bc_same($fromEmpty['meta']['shared'], 1, 'foreign empty coverage is shared');
mimir_bc_same(count($fromEmpty['value']), 2, 'local filter keeps WO0015 and WO0022');
$nos = array_column($fromEmpty['value'], 'No');
sort($nos);
mimir_bc_same($nos, ['WO0015', 'WO0022'], 'expected Nos from local filter');

// Opaque string $filter must NOT fall back to empty coverage (cannot local-filter).
$pdo = mimir_db(':memory:');
mimir_cache_upsert($pdo, 'kvtmdlive_aad', 'KVT', 'WorkOrders', mimir_row_key(['No' => 'WO0015'], ['No']), [
    'No' => 'WO0015',
    'Description' => 'x',
], $now - 10);
mimir_coverage_put($pdo, 'kvtmdlive_aad', 'KVT', 'WorkOrders', '', '*', $now - 10, 1);
$calls = [];
$stringHit = mimir_query_entity($pdo, mimir_bc_job([
    'filter' => "No eq 'WO0015'",
]), function (string $url) use (&$calls): array {
    $calls[] = $url;
    return ['value' => [['No' => 'WO0015', 'Description' => 'live']]];
}, $now);
mimir_bc_same(count($calls), 1, 'opaque string filter still hits BC');
mimir_bc_same($stringHit['meta']['bc_hit'], 1, 'opaque string bc_hit');
mimir_bc_same($stringHit['value'][0]['Description'], 'live', 'opaque string used BC row');

// --- (2) Empty-filter warm omits $select; response still projected ---
$pdo = mimir_db(':memory:');
$calls = [];
$warm = mimir_query_entity($pdo, mimir_bc_job([
    'select' => ['No', 'Description'],
    'filter' => null,
]), function (string $url) use (&$calls): array {
    $calls[] = $url;
    return ['value' => [
        ['No' => 'WO0001', 'Description' => 'one', 'Secret' => 'nope'],
    ]];
}, $now);
mimir_bc_same(count($calls), 1, 'warm hits BC once');
if (str_contains($calls[0], '%24select') || str_contains($calls[0], '$select')) {
    mimir_bc_fail('warm must omit $select: ' . $calls[0]);
}
mimir_bc_same(array_keys($warm['value'][0]), ['No', 'Description'], 'warm response projected');
$cov = mimir_coverage_find($pdo, 'kvtmdlive_aad', 'KVT', 'WorkOrders', null, 'Description,No', 600, $now);
mimir_bc_same($cov['select_sig'] ?? null, '*', 'empty-filter coverage stored as *');

// --- (3) Overlapping ranges: share middle rows, BC only for gap ---
$pdo = mimir_db(':memory:');
// App A previously fetched WO0005–WO0022
foreach ([['WO0005', 'a'], ['WO0010', 'b'], ['WO0015', 'c'], ['WO0022', 'd']] as [$no, $d]) {
    mimir_cache_upsert($pdo, 'kvtmdlive_aad', 'KVT', 'WorkOrders', mimir_row_key(['No' => $no], ['No']), [
        'No' => $no,
        'Description' => $d,
    ], $now - 20);
}
$filterA = "(No ge 'WO0005') and (No le 'WO0022')";
mimir_coverage_put($pdo, 'kvtmdlive_aad', 'KVT', 'WorkOrders', $filterA, '*', $now - 20, 4, 1);

$calls = [];
$gap = mimir_query_entity($pdo, mimir_bc_job([
    'filter' => [
        'and' => [
            ['field' => 'No', 'op' => 'ge', 'value' => 'WO0015'],
            ['field' => 'No', 'op' => 'le', 'value' => 'WO0030'],
        ],
    ],
    'key_id' => 2,
]), function (string $url) use (&$calls): array {
    $calls[] = $url;
    $decoded = rawurldecode($url);
    // Only gap should be requested: gt WO0022 and le WO0030
    if (!str_contains($decoded, "No gt 'WO0022'") || !str_contains($decoded, "No le 'WO0030'")) {
        mimir_bc_fail('gap BC filter unexpected: ' . $decoded);
    }
    if (str_contains($decoded, "No ge 'WO0015'")) {
        mimir_bc_fail('must not re-fetch covered lower bound: ' . $decoded);
    }
    return ['value' => [
        ['No' => 'WO0023', 'Description' => 'gap1'],
        ['No' => 'WO0030', 'Description' => 'gap2'],
    ]];
}, $now);
mimir_bc_same(count($calls), 1, 'only one BC call for the gap');
mimir_bc_same($gap['meta']['bc_hit'], 1, 'partial gap fill is bc_hit');
mimir_bc_same($gap['meta']['gap_fill'] ?? null, 1, 'gap_fill meta set');
mimir_bc_same($gap['meta']['from_cache'] >= 2, true, 'shared middle from cache');
mimir_bc_same($gap['meta']['from_live'], 2, 'two gap rows from BC');
$nos = array_column($gap['value'], 'No');
sort($nos);
mimir_bc_same($nos, ['WO0015', 'WO0022', 'WO0023', 'WO0030'], 'merged overlap + gap');

// Unsafe filter (OR / contains): fall back to full BC for the filter
$pdo = mimir_db(':memory:');
mimir_cache_upsert($pdo, 'kvtmdlive_aad', 'KVT', 'WorkOrders', mimir_row_key(['No' => 'WO0015'], ['No']), [
    'No' => 'WO0015',
    'Description' => 'cached',
], $now - 10);
mimir_coverage_put($pdo, 'kvtmdlive_aad', 'KVT', 'WorkOrders', "(No ge 'WO0005') and (No le 'WO0022')", '*', $now - 10, 1);
$calls = [];
$unsafe = mimir_query_entity($pdo, mimir_bc_job([
    'filter' => ['field' => 'Description', 'op' => 'contains', 'value' => 'x'],
]), function (string $url) use (&$calls): array {
    $calls[] = $url;
    return ['value' => [['No' => 'WO0099', 'Description' => 'xyz']]];
}, $now);
mimir_bc_same(count($calls), 1, 'unsafe filter full BC');
mimir_bc_same($unsafe['meta']['gap_fill'] ?? null, null, 'no gap_fill for unsafe');
mimir_bc_same($unsafe['meta']['bc_hit'], 1, 'unsafe bc_hit');
mimir_bc_same(count($unsafe['value']), 1, 'unsafe returned BC row');

// Prefer exact filter coverage over empty when both exist
$pdo = mimir_db(':memory:');
mimir_cache_upsert($pdo, 'kvtmdlive_aad', 'KVT', 'WorkOrders', mimir_row_key(['No' => 'WO0015'], ['No']), [
    'No' => 'WO0015',
    'Description' => 'exact',
], $now - 5);
$exactSig = "No eq 'WO0015'";
mimir_coverage_put($pdo, 'kvtmdlive_aad', 'KVT', 'WorkOrders', '', '*', $now - 2, 100); // fresher empty
mimir_coverage_put($pdo, 'kvtmdlive_aad', 'KVT', 'WorkOrders', $exactSig, '*', $now - 5, 1, 9);
$found = mimir_coverage_find($pdo, 'kvtmdlive_aad', 'KVT', 'WorkOrders', $exactSig, '*', 600, $now, true);
mimir_bc_same($found['filter_sig'] ?? null, $exactSig, 'prefer exact over fresher empty');
mimir_bc_same($found['key_id'] ?? null, 9, 'exact coverage key_id');

fwrite(STDOUT, "ok\n");
