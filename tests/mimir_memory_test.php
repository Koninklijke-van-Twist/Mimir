<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/web/mimir_service.php';

function mimir_mem_fail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function mimir_mem_same(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) {
        mimir_mem_fail($message . ' expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }
}

$root = sys_get_temp_dir() . '/mimir-memory-' . bin2hex(random_bytes(4));
if (!mkdir($root) && !is_dir($root)) {
    mimir_mem_fail('could not create temp dir');
}
$GLOBALS['mimir_runtime_dir'] = $root;
register_shutdown_function(static function () use ($root): void {
    if (!is_dir($root)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($root);
});

$now = 1_700_000_000;
$prefix = 'https://bc.test/env/ODataV4/';
$schema = [
    'keys' => ['No'],
    'properties' => [
        'No' => 'Edm.String',
        'Description' => 'Edm.String',
        'Starting_Date' => 'Edm.Date',
        'Ending_Date' => 'Edm.Date',
        'Fat' => 'Edm.String',
    ],
];

mimir_mem_same(mimir_fetch_retain_keys([], null, ['No']), null, 'empty select keeps the whole row');
$dateKeys = mimir_fetch_retain_keys(
    ['No', 'Description'],
    'Starting_Date ge 2026-01-01 and Ending_Date le 2026-12-31',
    ['No']
);
sort($dateKeys);
mimir_mem_same($dateKeys, ['Description', 'Ending_Date', 'No', 'Starting_Date'], 'date filter stays available for the local recheck');
$opaque = mimir_fetch_retain_keys(['Description'], "No eq '1' or Description eq 'x'", ['No']);
sort($opaque);
mimir_mem_same($opaque, ['Description', 'No'], 'unparsed filter string adds no columns');
$structured = mimir_fetch_retain_keys(['Description'], ['field' => 'Blocked', 'op' => 'eq', 'value' => true], ['No']);
sort($structured);
mimir_mem_same($structured, ['Blocked', 'Description', 'No'], 'structured filter field is retained');

$pdo = mimir_db(':memory:');
$width = 150_000;
$fatBytes = 0;
$fetch = static function (string $url) use ($prefix, $width, &$fatBytes): array {
    $page = static function (array $spec) use ($width, &$fatBytes): array {
        $rows = [];
        foreach ($spec as [$no, $ending]) {
            $fat = str_repeat('x', $width) . $no;
            $fatBytes += strlen($fat);
            $rows[] = [
                'No' => $no,
                'Description' => 'rij ' . $no,
                'Starting_Date' => '2026-06-01',
                'Ending_Date' => $ending,
                '@odata.etag' => 'W/"' . $no . '"',
                'Fat' => $fat,
            ];
        }

        return $rows;
    };
    if (str_contains($url, 'skiptoken')) {
        return ['value' => $page([['3', '2026-08-01'], ['4', '2026-09-01']])];
    }

    return [
        'value' => $page([['1', '2026-07-01'], ['2', '2027-01-15']]),
        '@odata.nextLink' => $prefix . 'Company(\'KVT\')/Prijslijstregels?skiptoken=2',
    ];
};

// Kolom op file: de volgende gefilterde fetch laat $select weg en BC levert alle kolommen.
mimir_cache_upsert($pdo, 'kvtmdlive_aad', 'KVT', 'Prijslijstregels', mimir_row_key(['No' => 'seed'], ['No']), [
    'No' => 'seed',
    'Description' => 'oud',
], $now - 10);

ini_set('memory_limit', '128M');
$before = memory_get_usage();
$job = [
    'environment' => 'kvtmdlive_aad',
    'company' => 'KVT',
    'entity' => 'Prijslijstregels',
    'service_prefix' => $prefix,
    'select' => ['No', 'Description'],
    'filter' => 'Starting_Date ge 2026-01-01 and Ending_Date le 2026-12-31',
    'max_age' => 600,
    'top' => 0,
    'schema' => $schema,
];
$calls = [];
$result = mimir_query_entity($pdo, $job, static function (string $url) use (&$calls, $fetch): array {
    $calls[] = $url;

    return $fetch($url);
}, $now);
unset($fetch);
gc_collect_cycles();
$growth = memory_get_usage() - $before;

mimir_mem_same(ini_get('memory_limit'), MIMIR_MEMORY_LIMIT, 'full fetch raises the memory limit');
mimir_mem_same(count($calls), 2, 'both pages are fetched');
if (str_contains(rawurldecode($calls[0]), '$select')) {
    mimir_mem_fail('warm filtered fetch must omit $select: ' . $calls[0]);
}
if (!str_contains(rawurldecode($calls[0]), 'Starting_Date ge 2026-01-01')) {
    mimir_mem_fail('date filter is still pushed: ' . $calls[0]);
}
mimir_mem_same(array_column($result['value'], 'No'), ['1', '3', '4'], 'local date recheck drops the row outside the range, order kept');
mimir_mem_same(array_keys($result['value'][0]), ['No', 'Description'], 'response is projected to the select');
mimir_mem_same($result['meta']['from_live'], 3, 'only matching rows count as live');
if (!str_contains((string) $result['meta']['delta_mode'], 'niet in config')) {
    mimir_mem_fail('Prijslijstregels stays a full fetch: ' . var_export($result['meta']['delta_mode'], true));
}
$cached = [];
foreach (mimir_cache_all($pdo, 'kvtmdlive_aad', 'KVT', 'Prijslijstregels') as $row) {
    $cached[$row['payload']['No']] = $row['payload'];
}
mimir_mem_same(isset($cached['2']['Fat']), true, 'out-of-range row is still cached');
mimir_mem_same(strlen((string) $cached['1']['Fat']) > $width, true, 'cache keeps the wide column');
mimir_mem_same((int) $pdo->query('SELECT row_count FROM cache_coverage')->fetchColumn(), 4, 'coverage counts every fetched row');
if ($growth > $fatBytes / 2) {
    mimir_mem_fail('wide BC columns stayed in PHP after the response (' . $growth . ' bytes, payload ' . $fatBytes . ')');
}

$fresh = mimir_db(':memory:');
$full = mimir_query_entity($fresh, [
    'environment' => 'kvtmdlive_aad',
    'company' => 'KVT',
    'entity' => 'Prijslijstregels',
    'service_prefix' => $prefix,
    'select' => [],
    'filter' => null,
    'max_age' => 0,
    'top' => 0,
    'schema' => $schema,
], static fn (): array => ['value' => [[
    'No' => '9',
    'Description' => 'alles',
    'Fat' => 'WIDE',
    '@odata.etag' => 'W/"9"',
]]], $now);
mimir_mem_same($full['value'][0]['Fat'] ?? null, 'WIDE', 'empty select still returns every column');

$GLOBALS['mimir_request_entity'] = 'Prijslijstregels';
$GLOBALS['mimir_bc_route'] = 'odata';
$GLOBALS['mimir_request_route'] = 'query';
mimir_log_fatal_error([
    'type' => E_ERROR,
    'message' => 'Allowed memory size of 536870912 bytes exhausted (tried to allocate 45088768 bytes)',
    'file' => '/var/www/html/mimir/web/mimir_store.php',
    'line' => 2384,
]);
unset($GLOBALS['mimir_bc_route']);
mimir_log_fatal_error([
    'type' => E_ERROR,
    'message' => 'boom',
    'file' => '/var/www/html/mimir/web/mimir_service.php',
    'line' => 12,
]);

$lines = array_values(array_filter(array_map('trim', file(mimir_event_log_path()) ?: [])));
if (count($lines) < 2) {
    mimir_mem_fail('fatal events were not written');
}
$withRoute = json_decode($lines[count($lines) - 2], true);
$fallback = json_decode($lines[count($lines) - 1], true);
if (!is_array($withRoute) || !is_array($fallback)) {
    mimir_mem_fail('fatal event is not json');
}
mimir_mem_same($withRoute['entity'] ?? null, 'Prijslijstregels', 'fatal event entity field');
mimir_mem_same($withRoute['action'] ?? null, 'odata', 'fatal event action is the BC route');
foreach (['route=odata', 'entity=Prijslijstregels', '/var/www/html/mimir/web/mimir_store.php:2384', 'tried to allocate 45088768'] as $part) {
    if (!str_contains((string) ($withRoute['message'] ?? ''), $part)) {
        mimir_mem_fail('fatal message missing ' . $part . ': ' . ($withRoute['message'] ?? ''));
    }
}
mimir_mem_same($fallback['action'] ?? null, 'query', 'without a BC route the API route is logged');
if (!str_contains((string) ($fallback['message'] ?? ''), 'mimir_service.php:12')) {
    mimir_mem_fail('fallback fatal message missing file:line: ' . ($fallback['message'] ?? ''));
}

fwrite(STDOUT, "ok\n");
