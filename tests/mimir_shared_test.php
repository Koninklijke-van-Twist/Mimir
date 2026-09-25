<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/web/mimir_store.php';

function mimir_shared_fail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function mimir_shared_same(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) {
        mimir_shared_fail($message . ' expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
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

function mimir_shared_job(array $overrides = []): array
{
    global $schema, $prefix;
    return array_merge([
        'environment' => 'kvtmdlive_aad',
        'company' => 'KVT',
        'entity' => 'ItemList',
        'service_prefix' => $prefix,
        'select' => ['No', 'Description'],
        'filter' => null,
        'max_age' => 600,
        'top' => 50,
        'schema' => $schema,
    ], $overrides);
}

$pdo = mimir_db(':memory:');
$cols = mimir_sqlite_columns($pdo, 'cache_coverage');
if (!in_array('key_id', $cols, true)) {
    mimir_shared_fail('cache_coverage must have key_id after migrate');
}
$usageCols = mimir_sqlite_columns($pdo, 'api_usage');
foreach (['shared', 'bc_hit', 'from_cache', 'from_live'] as $col) {
    if (!in_array($col, $usageCols, true)) {
        mimir_shared_fail('api_usage missing ' . $col);
    }
}

$keyA = mimir_key_create($pdo, 'a@kvt.nl', 'App A', $now);
$keyB = mimir_key_create($pdo, 'b@kvt.nl', 'App B', $now);

mimir_cache_upsert($pdo, 'kvtmdlive_aad', 'KVT', 'ItemList', mimir_row_key(['No' => 'A'], ['No']), [
    'No' => 'A',
    'Description' => 'Pomp',
], $now - 30);
mimir_coverage_put($pdo, 'kvtmdlive_aad', 'KVT', 'ItemList', '', '*', $now - 30, 1, $keyA['id']);

$found = mimir_coverage_find($pdo, 'kvtmdlive_aad', 'KVT', 'ItemList', null, '*', 600, $now);
mimir_shared_same($found['key_id'] ?? null, $keyA['id'], 'coverage_find returns owner key_id');

$own = mimir_query_entity($pdo, mimir_shared_job(['key_id' => $keyA['id']]), static fn (): array => ['value' => []], $now);
mimir_shared_same($own['meta']['from_live'], 0, 'own cache has no live');
mimir_shared_same($own['meta']['shared'], 0, 'own earlier fetch is not shared');
mimir_shared_same($own['meta']['bc_hit'], 0, 'own cache is not a BC hit');

$foreign = mimir_query_entity($pdo, mimir_shared_job(['key_id' => $keyB['id']]), static fn (): array => ['value' => []], $now);
mimir_shared_same($foreign['meta']['shared'], 1, 'foreign coverage counts as shared');
mimir_shared_same($foreign['meta']['bc_hit'], 0, 'foreign full cache is not a BC hit');

// Legacy null ownership counts as shared when fully cached
mimir_coverage_put($pdo, 'kvtmdlive_aad', 'KVT', 'ItemList', '', '*', $now - 20, 1, null);
// COALESCE keeps previous key_id when putting null — force null with SQL for this case
$pdo->exec('UPDATE cache_coverage SET key_id = NULL');
$legacy = mimir_query_entity($pdo, mimir_shared_job(['key_id' => $keyB['id']]), static fn (): array => ['value' => []], $now);
mimir_shared_same($legacy['meta']['shared'], 1, 'null coverage key_id counts as shared');

// Live BC path: shared=0, bc_hit=1
$pdo = mimir_db(':memory:');
$keyA = mimir_key_create($pdo, 'a@kvt.nl', 'App A', $now);
$live = mimir_query_entity($pdo, mimir_shared_job(['key_id' => $keyA['id']]), static function (): array {
    return ['value' => [['No' => 'A', 'Description' => 'Live']]];
}, $now);
mimir_shared_same($live['meta']['from_live'] > 0, true, 'live fetch populated from_live');
mimir_shared_same($live['meta']['shared'], 0, 'live is not shared');
mimir_shared_same($live['meta']['bc_hit'], 1, 'live is a BC hit');
$stamped = mimir_coverage_find($pdo, 'kvtmdlive_aad', 'KVT', 'ItemList', null, 'Description,No', 600, $now);
mimir_shared_same($stamped['key_id'] ?? null, $keyA['id'], 'live fetch stamps coverage key_id');

// Usage flags + shared_pct
$pdo = mimir_db(':memory:');
$keyA = mimir_key_create($pdo, 'a@kvt.nl', 'App A', $now);
$keyB = mimir_key_create($pdo, 'b@kvt.nl', 'App B', $now);
mimir_usage_log($pdo, $keyA['id'], 'query', $now - 100, 1, 0, 5, 0);
mimir_usage_log($pdo, $keyA['id'], 'query', $now - 50, 0, 1, 0, 3);
mimir_usage_log($pdo, $keyA['id'], 'tables', $now - 10, 0, 0, 0, 0);
mimir_usage_log($pdo, $keyB['id'], 'query', $now - 20, 1, 0, 2, 0);
mimir_shared_same(mimir_key_shared_pct($pdo, $keyA['id'], $now), 50, 'one of two queries shared');
mimir_shared_same(mimir_usage_shared_pct_global($pdo, $now), 67, 'global 2 of 3 queries shared');
$keyC = mimir_key_create($pdo, 'c@kvt.nl', 'App C', $now);
mimir_shared_same(mimir_key_shared_pct($pdo, $keyC['id'], $now), null, 'empty window is null');

$flags = mimir_usage_flags_from_response([
    'results' => [
        'a' => ['meta' => ['from_cache' => 1, 'from_live' => 0, 'shared' => 1, 'bc_hit' => 0]],
        'b' => ['meta' => ['from_cache' => 2, 'from_live' => 0, 'shared' => 0, 'bc_hit' => 0]],
    ],
]);
mimir_shared_same($flags['shared'], 1, 'multi-query shared when all cache and any foreign');
mimir_shared_same($flags['bc_hit'], 0, 'multi-query no BC');

$flagsBc = mimir_usage_flags_from_response([
    'results' => [
        'a' => ['meta' => ['from_cache' => 1, 'from_live' => 0, 'shared' => 1, 'bc_hit' => 0]],
        'b' => ['meta' => ['from_cache' => 0, 'from_live' => 1, 'shared' => 0, 'bc_hit' => 1]],
    ],
]);
mimir_shared_same($flagsBc['shared'], 0, 'any BC child clears shared');
mimir_shared_same($flagsBc['bc_hit'], 1, 'any BC child sets bc_hit');

fwrite(STDOUT, "ok\n");
