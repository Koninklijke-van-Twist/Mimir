<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/web/mimir_store.php';

function mimir_stream_fail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function mimir_stream_same(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) {
        mimir_stream_fail($message . ' expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }
}

$GLOBALS['mimir_stream_buffer'] = '';
$GLOBALS['mimir_stream_writer'] = static function (string $chunk): void {
    $GLOBALS['mimir_stream_buffer'] .= $chunk;
};
function mimir_stream_take(): string
{
    $out = $GLOBALS['mimir_stream_buffer'];
    $GLOBALS['mimir_stream_buffer'] = '';
    return $out;
}

$now = 1_700_000_000;
$prefix = 'https://bc.test/env/ODataV4/';
$job = [
    'environment' => 'kvtmdlive_aad',
    'company' => 'KVT',
    'entity' => 'AppItemCard',
    'service_prefix' => $prefix,
    'select' => ['No', 'Item_Category_Code'],
    'filter' => null,
    'max_age' => 0,
    'top' => 0,
    'schema' => ['keys' => ['No'], 'properties' => ['No' => 'Edm.String', 'Item_Category_Code' => 'Edm.String', 'Description' => 'Edm.String']],
];

/** @return callable(string): array */
function mimir_stream_pages(int $pages, int $perPage, array &$calls, bool $truncateLast = false): callable
{
    return static function (string $url) use ($pages, $perPage, &$calls, $truncateLast, &$i): array {
        $calls[] = $url;
        $i = ($i ?? 0) + 1;
        $value = [];
        for ($r = 0; $r < $perPage; $r++) {
            $value[] = ['@odata.etag' => 'x', 'No' => sprintf('I%03d-%03d', $i, $r), 'Item_Category_Code' => 'C' . $i];
        }
        $page = ['value' => $value];
        if ($i < $pages) {
            $page['@odata.nextLink'] = 'https://bc.test/env/ODataV4/Company(\'KVT\')/AppItemCard?$skiptoken=' . $i;
        }
        if ($truncateLast && $i === $pages) {
            $page['@mimir.truncated'] = true;
        }
        return $page;
    };
}

function mimir_stream_run(PDO $pdo, array $job, callable $fetch, int $now): array
{
    mimir_stream_enable();
    $result = mimir_query_entity($pdo, $job, $fetch, $now);
    mimir_stream_same(!empty($result['@mimir_streamed']), true, 'result marked streamed');
    mimir_stream_finish($result);
    $out = mimir_stream_take();
    mimir_stream_reset();
    $decoded = json_decode($out, true);
    if (!is_array($decoded)) {
        mimir_stream_fail('stream output is not valid JSON: ' . substr($out, 0, 200));
    }
    return $decoded;
}

// 1. Cold full fetch over 3 pages: everything streamed and projected, coverage written.
$pdo = mimir_db(':memory:');
$calls = [];
$out = mimir_stream_run($pdo, $job, mimir_stream_pages(3, 4, $calls), $now);
mimir_stream_same(count($out['value']), 12, 'all rows streamed');
mimir_stream_same(array_keys($out['value'][0]), ['No', 'Item_Category_Code'], 'projected columns');
mimir_stream_same($out['meta']['complete'], true, 'complete');
mimir_stream_same($out['meta']['from_live'], 12, 'from_live counts streamed rows');
mimir_stream_same(count($calls), 3, 'three BC pages');
mimir_stream_same(str_contains(urldecode($calls[0]), '$select'), false, 'full warm omits $select (shared warm policy)');
mimir_stream_same(str_contains(urldecode($calls[0]), '$top'), false, 'no $top on full fetch');
$cov = $pdo->query("SELECT COUNT(*) FROM cache_coverage WHERE entity = 'AppItemCard'")->fetchColumn();
mimir_stream_same((int) $cov, 1, 'coverage written for complete fetch');

// 2. Warm: served from store without BC.
$calls2 = [];
$warm = $job;
$warm['max_age'] = 600;
$out = mimir_stream_run($pdo, $warm, mimir_stream_pages(1, 1, $calls2), $now + 5);
mimir_stream_same($calls2, [], 'warm stream does not call BC');
mimir_stream_same(count($out['value']), 12, 'warm rows streamed from store');
mimir_stream_same($out['meta']['from_cache'], 12, 'from_cache');

// 3. Truncated fetch: no coverage, complete=false.
$pdo = mimir_db(':memory:');
$calls3 = [];
$out = mimir_stream_run($pdo, $job, mimir_stream_pages(2, 3, $calls3, true), $now);
mimir_stream_same($out['meta']['complete'], false, 'truncated is incomplete');
$cov = $pdo->query("SELECT COUNT(*) FROM cache_coverage")->fetchColumn();
mimir_stream_same((int) $cov, 0, 'no coverage for truncated fetch');

// 4. Rows not refetched (deleted in BC) are not streamed on a fresh cold fetch.
$pdo = mimir_db(':memory:');
mimir_cache_upsert($pdo, 'kvtmdlive_aad', 'KVT', 'AppItemCard', mimir_row_key(['No' => 'OLD'], ['No']), ['No' => 'OLD', 'Item_Category_Code' => 'X'], $now - 100);
$calls4 = [];
$out = mimir_stream_run($pdo, $job, mimir_stream_pages(1, 2, $calls4), $now);
mimir_stream_same(count($out['value']), 2, 'stale deleted row not streamed');

// 5. Abort after opening value keeps JSON valid.
mimir_stream_enable();
mimir_stream_begin();
mimir_stream_row(['No' => 'A']);
mimir_stream_abort('boem', 502);
$decoded = json_decode(mimir_stream_take(), true);
mimir_stream_reset();
mimir_stream_same($decoded['error'] ?? null, 'boem', 'abort error in body');
mimir_stream_same($decoded['meta']['complete'] ?? null, false, 'abort marks incomplete');

// 6. Not streaming when top > 0.
$pdo = mimir_db(':memory:');
$calls6 = [];
$bounded = $job;
$bounded['top'] = 5;
mimir_stream_enable();
$res = mimir_query_entity($pdo, $bounded, mimir_stream_pages(1, 5, $calls6), $now);
mimir_stream_reset();
mimir_stream_same(isset($res['@mimir_streamed']), false, 'bounded query not streamed');
mimir_stream_same(count($res['value']), 5, 'bounded rows returned normally');

echo "ok\n";
