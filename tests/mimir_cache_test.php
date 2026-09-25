<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/web/mimir_store.php';

function mimir_cache_fail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function mimir_cache_same(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) {
        mimir_cache_fail($message . ' expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }
}

$now = 1_700_000_000;
mimir_cache_same(mimir_row_refresh_reason(['payload' => ['No' => 'A'], 'fetched_at' => $now - 600], ['No'], 600, $now), 'ok', 'age equal to max_age is fresh');
mimir_cache_same(mimir_row_refresh_reason(['payload' => ['No' => 'A'], 'fetched_at' => $now - 601], ['No'], 600, $now), 'stale', 'age above max_age is stale');
mimir_cache_same(mimir_row_refresh_reason(['payload' => ['No' => 'A'], 'fetched_at' => $now - 10], ['No', 'Description'], 600, $now), 'columns', 'missing column refreshes the row');
mimir_cache_same(mimir_row_refresh_reason(null, ['No'], 600, $now), 'missing', 'absent row');

$schema = [
    'keys' => ['No'],
    'properties' => [
        'No' => 'Edm.String',
        'Description' => 'Edm.String',
        'Inventory' => 'Edm.Decimal',
    ],
];
$prefix = 'https://bc.test/env/ODataV4/';

function mimir_job(array $overrides = []): array
{
    global $schema, $prefix;
    return array_merge([
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
mimir_cache_upsert($pdo, 'KVT', 'ItemList', mimir_row_key(['No' => 'A'], ['No']), [
    'No' => 'A',
    'Description' => 'Pomp',
    'Inventory' => 3,
], $now - 30);
mimir_coverage_put($pdo, 'KVT', 'ItemList', '', '*', $now - 30, 1);
$calls = [];
$cached = mimir_query_entity($pdo, mimir_job(), function (string $url) use (&$calls): array {
    $calls[] = $url;
    return ['value' => []];
}, $now);
mimir_cache_same($calls, [], 'fresh complete row does not call BC');
mimir_cache_same($cached['meta']['from_cache'], 1, 'served from cache');
mimir_cache_same($cached['meta']['from_live'], 0, 'nothing live');
mimir_cache_same($cached['value'][0]['Description'], 'Pomp', 'cached description');

$pdo = mimir_db(':memory:');
mimir_cache_upsert($pdo, 'KVT', 'ItemList', mimir_row_key(['No' => 'A'], ['No']), [
    'No' => 'A',
    'Description' => 'Pomp',
], $now - 20);
mimir_coverage_put($pdo, 'KVT', 'ItemList', '', '*', $now - 20, 1);
$calls = [];
$refreshed = mimir_query_entity($pdo, mimir_job([
    'select' => ['No', 'Description', 'Inventory'],
]), function (string $url) use (&$calls): array {
    $calls[] = $url;
    return ['No' => 'A', 'Description' => 'Pomp', 'Inventory' => 9, '@odata.etag' => 'W/"1"'];
}, $now);
mimir_cache_same(count($calls), 1, 'one whole-row refresh');
if (str_contains($calls[0], '%24select') || str_contains($calls[0], '$select')) {
    mimir_cache_fail('whole-row refresh must not send $select: ' . $calls[0]);
}
if (!str_contains($calls[0], "ItemList(No='A')")) {
    mimir_cache_fail('whole-row refresh uses the entity key: ' . $calls[0]);
}
mimir_cache_same($refreshed['meta']['from_live'], 1, 'refreshed row counts as live');
mimir_cache_same($refreshed['value'][0]['Inventory'], 9, 'inventory came from BC');

$pdo = mimir_db(':memory:');
mimir_cache_upsert($pdo, 'KVT', 'ItemList', mimir_row_key(['No' => 'A'], ['No']), [
    'No' => 'A',
    'Description' => 'oud',
], $now - 5000);
$calls = [];
$live = mimir_query_entity($pdo, mimir_job(), function (string $url) use (&$calls): array {
    $calls[] = $url;
    if (str_contains($url, '@odata.nextLink')) {
        return ['value' => []];
    }
    if (str_contains($url, 'skiptoken')) {
        return ['value' => [['No' => 'B', 'Description' => 'twee']]];
    }
    return [
        'value' => [['No' => 'A', 'Description' => 'nieuw']],
        '@odata.nextLink' => 'https://bc.test/env/ODataV4/Company(\'KVT\')/ItemList?skiptoken=2',
    ];
}, $now);
mimir_cache_same(count($calls) >= 2, true, 'stale cache follows nextLink');
mimir_cache_same($live['meta']['from_live'], 2, 'both pages are live');
mimir_cache_same($live['value'][0]['Description'], 'nieuw', 'stale row replaced from BC');

$pdo = mimir_db(':memory:');
$calls = [];
$mixed = mimir_query_entity($pdo, mimir_job([
    'select' => ['No', 'Description', 'Inventory'],
    'filter' => [
        'and' => [
            ['field' => 'No', 'op' => 'eq', 'value' => 'A'],
            ['or' => [
                ['field' => 'Description', 'op' => 'contains', 'value' => 'x'],
                ['field' => 'Inventory', 'op' => 'gt', 'value' => 5],
            ]],
        ],
    ],
]), function (string $url) use (&$calls): array {
    $calls[] = $url;
    return ['value' => [
        ['No' => 'A', 'Description' => 'x', 'Inventory' => 1],
        ['No' => 'A', 'Description' => 'nee', 'Inventory' => 1],
        ['No' => 'B', 'Description' => 'x', 'Inventory' => 9],
    ]];
}, $now);
if (!str_contains(rawurldecode($calls[0]), "No eq 'A'")) {
    mimir_cache_fail('mixed filter should push No eq: ' . $calls[0]);
}
mimir_cache_same($mixed['meta']['filter_mode'], 'mixed', 'filter mode mixed');
mimir_cache_same(count($mixed['value']), 1, 'local or drops the non-matching sibling');
mimir_cache_same($mixed['value'][0]['Description'], 'x', 'kept the contains match');

$pdo = mimir_db(':memory:');
$calls = [];
mimir_query_entity($pdo, mimir_job([
    'filter' => ['or' => [
        ['field' => 'No', 'op' => 'eq', 'value' => 'A'],
        ['field' => 'Description', 'op' => 'eq', 'value' => 'Pomp'],
    ]],
]), function (string $url) use (&$calls): array {
    $calls[] = $url;
    return ['value' => []];
}, $now);
if (str_contains($calls[0], '%24filter') || str_contains($calls[0], '$filter')) {
    mimir_cache_fail('cross-field or must not be sent as $filter: ' . $calls[0]);
}

$pdo = mimir_db(':memory:');
$calls = [];
$retry = mimir_query_entity($pdo, mimir_job([
    'filter' => ['field' => 'No', 'op' => 'eq', 'value' => 'A'],
]), function (string $url) use (&$calls): array {
    $calls[] = $url;
    if (str_contains($url, '%24filter') || str_contains($url, '$filter')) {
        throw new RuntimeException('HTTP 501 from OData: filter rejected');
    }
    return ['value' => [
        ['No' => 'A', 'Description' => 'ja'],
        ['No' => 'B', 'Description' => 'nee'],
    ]];
}, $now);
mimir_cache_same(count($calls), 2, '501 retries without $filter');
mimir_cache_same($retry['meta']['filter_mode'], 'local', 'retry is local');
mimir_cache_same(count($retry['value']), 1, 'local eq still applied after 501');

fwrite(STDOUT, "ok\n");
