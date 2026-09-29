<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/web/mimir_store.php';

function mimir_bcl_fail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function mimir_bcl_same(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) {
        mimir_bcl_fail($message . ' expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }
}

function mimir_bcl_reset(int $max = 3, int $wait = 120): void
{
    unset(
        $GLOBALS['mimir_bc_limit_force_fallback'],
        $GLOBALS['mimir_bc_limit_fallback_path'],
        $GLOBALS['mimir_bc_limit_sleep'],
        $GLOBALS['mimir_bc_limit_now']
    );
    $GLOBALS['mimir_bc_max_concurrent'] = $max;
    $GLOBALS['mimir_bc_queue_wait_seconds'] = $wait;
    mimir_bc_limit_test_reset();
}

/**
 * @return array{proc: resource, pipes: array<int, resource>, worker: string}
 */
function mimir_bcl_child_hold(string $limitFile, string $dir, int $max, string $environment): array
{
    $worker = sys_get_temp_dir() . '/mimir_bc_limit_worker_' . getmypid() . '.php';
    $code = <<<'PHP'
<?php
declare(strict_types=1);
require $argv[1];
$GLOBALS['mimir_bc_limit_dir'] = $argv[2];
$GLOBALS['mimir_bc_max_concurrent'] = (int) $argv[3];
$GLOBALS['mimir_bc_queue_wait_seconds'] = 0;
mimir_bc_slot_acquire($argv[4]);
fwrite(STDOUT, "held\n");
fflush(STDOUT);
stream_set_blocking(STDIN, true);
fread(STDIN, 32);
PHP;
    if (file_put_contents($worker, $code) === false) {
        mimir_bcl_fail('could not write slot worker');
    }
    $proc = proc_open(
        [PHP_BINARY, $worker, $limitFile, $dir, (string) $max, $environment],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );
    if (!is_resource($proc)) {
        mimir_bcl_fail('could not start slot holder process');
    }
    stream_set_timeout($pipes[1], 10);
    $line = fgets($pipes[1]);
    if (!is_string($line) || trim($line) !== 'held') {
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        mimir_bcl_fail('child did not hold slot: ' . var_export($line, true) . ' stderr=' . $err);
    }

    return ['proc' => $proc, 'pipes' => $pipes, 'worker' => $worker];
}

/**
 * @param array{proc: resource, pipes: array<int, resource>, worker: string} $child
 */
function mimir_bcl_child_stop(array $child): void
{
    $pipes = $child['pipes'];
    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }
    if (isset($pipes[1]) && is_resource($pipes[1])) {
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
    }
    if (isset($pipes[2]) && is_resource($pipes[2])) {
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
    }
    if (is_resource($child['proc'])) {
        proc_close($child['proc']);
    }
    if (is_file($child['worker'])) {
        @unlink($child['worker']);
    }
}

$root = sys_get_temp_dir() . '/mimir_bc_limit_test_' . getmypid();
if (!is_dir($root) && !mkdir($root, 0777, true) && !is_dir($root)) {
    mimir_bcl_fail('could not create temp dir');
}
$slotDir = $root . '/slots';
$limitFile = dirname(__DIR__) . '/web/mimir_bc_limit.php';
$GLOBALS['mimir_runtime_dir'] = $root;
mimir_bc_limit_set_dir($slotDir);
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

mimir_bcl_reset();
mimir_bcl_same(MIMIR_BC_MAX_CONCURRENT, 3, 'default cap constant');
mimir_bcl_same(MIMIR_BC_QUEUE_WAIT_SECONDS, 120, 'default wait constant');
unset($GLOBALS['mimir_bc_max_concurrent'], $GLOBALS['mimir_bc_queue_wait_seconds']);
mimir_bcl_same(mimir_bc_limit_max_concurrent(), 3, 'default max from constant');
mimir_bcl_same(mimir_bc_limit_queue_wait_seconds(), 120, 'default wait from constant');
$GLOBALS['mimir_bc_max_concurrent'] = 0;
$GLOBALS['mimir_bc_queue_wait_seconds'] = -4;
mimir_bcl_same(mimir_bc_limit_max_concurrent(), 1, 'max floors at 1');
mimir_bcl_same(mimir_bc_limit_queue_wait_seconds(), 0, 'wait floors at 0');

$emptyEnv = false;
try {
    mimir_bc_slot_acquire('   ');
} catch (InvalidArgumentException) {
    $emptyEnv = true;
}
mimir_bcl_same($emptyEnv, true, 'empty environment is rejected');

// --- (1) Direct acquire / release, no poll when a slot is free ---
mimir_bcl_reset();
$sleeps = 0;
$GLOBALS['mimir_bc_limit_sleep'] = static function (int $us) use (&$sleeps): void {
    $sleeps++;
};
$wait = mimir_bc_slot_acquire('kvtmdlive_aad');
mimir_bcl_same($wait, 0, 'immediate acquire waits 0ms');
mimir_bcl_same($sleeps, 0, 'free slot does not poll');
mimir_bcl_same(mimir_bc_slots_used('kvtmdlive_aad'), 1, 'one holder after acquire');
mimir_bcl_same(is_file($slotDir . '/kvtmdlive_aad/slot-0'), true, 'slot file created');
mimir_bcl_same(is_file($root . '/bc_limit.sqlite'), false, 'limiter does not create sqlite');
mimir_bc_slot_release('kvtmdlive_aad');
mimir_bcl_same(mimir_bc_slots_used('kvtmdlive_aad'), 0, 'released');
mimir_bc_slot_release('kvtmdlive_aad');
mimir_bcl_same(mimir_bc_slots_used('kvtmdlive_aad'), 0, 'release of unknown hold is a no-op');
unset($GLOBALS['mimir_bc_limit_sleep']);

// --- (2) Reentrancy ---
mimir_bcl_reset();
mimir_bc_slot_acquire('kvtmdlive_aad');
mimir_bcl_same(mimir_bc_slot_acquire('kvtmdlive_aad'), 0, 'nested acquire wait 0');
mimir_bcl_same(mimir_bc_slots_used('kvtmdlive_aad'), 1, 'still one holder when nested');
mimir_bc_slot_release('kvtmdlive_aad');
mimir_bcl_same(mimir_bc_slots_used('kvtmdlive_aad'), 1, 'refcount still holding');
mimir_bc_slot_release('kvtmdlive_aad');
mimir_bcl_same(mimir_bc_slots_used('kvtmdlive_aad'), 0, 'fully released');

// --- (3) Separate environments ---
mimir_bcl_reset(1, 120);
mimir_bc_slot_force_hold('kvtmdlive_aad', 'foreign-nl');
$waitDe = mimir_bc_slot_acquire('kvtgermanylive_aad');
mimir_bcl_same($waitDe, 0, 'other environment not blocked');
mimir_bcl_same(mimir_bc_slots_used('kvtgermanylive_aad'), 1, 'germany holder');
mimir_bcl_same(mimir_bc_slots_used('kvtmdlive_aad'), 1, 'nl still held by foreign');
mimir_bc_slot_release('kvtgermanylive_aad');
mimir_bc_slot_force_release('kvtmdlive_aad', 'foreign-nl');
mimir_bcl_same(mimir_bc_slots_used('kvtmdlive_aad'), 0, 'foreign nl released');
mimir_bcl_same(mimir_bc_slots_used('kvtgermanylive_aad'), 0, 'germany released');

// --- (4) Max concurrent: 4th times out without taking a slot or failing over ---
mimir_bcl_reset(3, 0);
foreach (['a', 'b', 'c'] as $id) {
    mimir_bc_slot_force_hold('kvtmdlive_aad', 'foreign-' . $id);
}
mimir_bcl_same(mimir_bc_slots_used('kvtmdlive_aad'), 3, 'three foreign holders');
$timedOut = false;
try {
    mimir_bc_slot_acquire('kvtmdlive_aad');
} catch (MimirUserException $error) {
    $timedOut = true;
    mimir_bcl_same($error->status, 503, 'timeout HTTP 503');
    if (!str_contains($error->getMessage(), 'BC-concurrency')) {
        mimir_bcl_fail('timeout message should mention BC-concurrency');
    }
    if (!str_contains($error->getMessage(), 'max 3')) {
        mimir_bcl_fail('timeout message should mention max 3');
    }
    mimir_bcl_same(mimir_should_failover_to_bc($error), false, 'slot timeout does not fail over to BC');
    mimir_bcl_same(mimir_is_storage_failure($error), false, 'slot timeout is not a storage bypass');
}
mimir_bcl_same($timedOut, true, 'fourth waiter times out');
mimir_bcl_same(mimir_bc_slots_used('kvtmdlive_aad'), 3, 'still three after timeout');
mimir_bcl_same($GLOBALS['mimir_bc_held'], [], 'timed-out acquire holds nothing');
mimir_bc_slot_force_release('kvtmdlive_aad', 'foreign-a');
$afterFree = mimir_bc_slot_acquire('kvtmdlive_aad');
mimir_bcl_same($afterFree, 0, 'acquire proceeds once a slot is free');
mimir_bcl_same(mimir_bc_slots_used('kvtmdlive_aad'), 3, 'two foreign plus self');
mimir_bc_slot_release('kvtmdlive_aad');

// --- (5) Waiting acquires when a holder releases during the poll ---
mimir_bcl_reset(3, 5);
foreach (['a', 'b', 'c'] as $id) {
    mimir_bc_slot_force_hold('kvtmdlive_aad', 'foreign-' . $id);
}
$now = 1000.0;
$polls = 0;
$GLOBALS['mimir_bc_limit_now'] = static function () use (&$now): float {
    return $now;
};
$GLOBALS['mimir_bc_limit_sleep'] = static function (int $us) use (&$polls, &$now): void {
    $polls++;
    $now += $us / 1000000;
    if ($polls === 1) {
        mimir_bc_slot_force_release('kvtmdlive_aad', 'foreign-a');
    }
};
$waited = mimir_bc_slot_acquire('kvtmdlive_aad');
mimir_bcl_same($polls, 1, 'waiter polled once');
mimir_bcl_same($waited, 50, 'queue_wait_ms matches one poll');
mimir_bcl_same(mimir_bc_slots_used('kvtmdlive_aad'), 3, 'back to three holders (2 foreign + self)');
mimir_bc_slot_release('kvtmdlive_aad');
unset($GLOBALS['mimir_bc_limit_sleep'], $GLOBALS['mimir_bc_limit_now']);
mimir_bc_slot_force_release('kvtmdlive_aad', 'foreign-b');
mimir_bc_slot_force_release('kvtmdlive_aad', 'foreign-c');
mimir_bcl_same(mimir_bc_slots_used('kvtmdlive_aad'), 0, 'foreign holders released');

// --- (6) query_entity: cache hit takes no slot ---
mimir_bcl_reset(3, 120);
$nowTs = 1_700_000_000;
$schema = [
    'keys' => ['No'],
    'properties' => [
        'No' => 'Edm.String',
        'Description' => 'Edm.String',
    ],
];
$prefix = 'https://bc.test/env/ODataV4/';
$pdo = mimir_db(':memory:');
mimir_cache_upsert($pdo, 'kvtmdlive_aad', 'KVT', 'WorkOrders', mimir_row_key(['No' => 'WO1'], ['No']), [
    'No' => 'WO1',
    'Description' => 'cached',
], $nowTs - 10);
mimir_coverage_put($pdo, 'kvtmdlive_aad', 'KVT', 'WorkOrders', '', '*', $nowTs - 10, 1);
$calls = 0;
$cached = mimir_query_entity($pdo, [
    'environment' => 'kvtmdlive_aad',
    'company' => 'KVT',
    'entity' => 'WorkOrders',
    'service_prefix' => $prefix,
    'select' => ['No', 'Description'],
    'filter' => null,
    'max_age' => 600,
    'top' => 0,
    'schema' => $schema,
], static function (string $url) use (&$calls): array {
    $calls++;
    return ['value' => []];
}, $nowTs);
mimir_bcl_same($calls, 0, 'cache path does not call fetch');
mimir_bcl_same($cached['meta']['bc_hit'], 0, 'cache bc_hit 0');
mimir_bcl_same($cached['meta']['queue_wait_ms'], 0, 'cache queue_wait_ms 0');
mimir_bcl_same($cached['meta']['bc_slots_used'], 0, 'cache no slot used');
mimir_bcl_same(mimir_bc_slots_used('kvtmdlive_aad'), 0, 'no leftover holders after cache query');

// --- (7) query_entity: live fetch takes one slot for whole logical call (multi-page) ---
mimir_bcl_reset();
$pdo = mimir_db(':memory:');
$page = 0;
$maxUsedDuring = 0;
$live = mimir_query_entity($pdo, [
    'environment' => 'kvtmdlive_aad',
    'company' => 'KVT',
    'entity' => 'WorkOrders',
    'service_prefix' => $prefix,
    'select' => ['No', 'Description'],
    'filter' => null,
    'max_age' => 600,
    'top' => 0,
    'schema' => $schema,
], static function (string $url) use (&$page, &$maxUsedDuring, $prefix): array {
    $page++;
    $used = mimir_bc_slots_used('kvtmdlive_aad');
    $maxUsedDuring = max($maxUsedDuring, $used);
    if ($page === 1) {
        return [
            'value' => [['No' => 'WO1', 'Description' => 'one']],
            '@odata.nextLink' => rtrim($prefix, '/') . '/Company(\'KVT\')/WorkOrders?$skiptoken=2',
        ];
    }
    return ['value' => [['No' => 'WO2', 'Description' => 'two']]];
}, $nowTs);
mimir_bcl_same($page, 2, 'two pages fetched');
mimir_bcl_same($maxUsedDuring, 1, 'one slot held across pages');
mimir_bcl_same($live['meta']['bc_hit'], 1, 'live bc_hit');
mimir_bcl_same($live['meta']['queue_wait_ms'] >= 0, true, 'live queue_wait_ms present');
mimir_bcl_same($live['meta']['bc_slots_max'], 3, 'bc_slots_max in meta');
mimir_bcl_same(mimir_bc_slots_used('kvtmdlive_aad'), 0, 'slot released after query');
mimir_bcl_same(count($live['value']), 2, 'both pages in result');

// --- (8) A dead worker drops its flock; it does not need a stale-row reaper ---
mimir_bcl_reset(1, 0);
$child = mimir_bcl_child_hold($limitFile, $slotDir, 1, 'childenv');
try {
    mimir_bcl_same(mimir_bc_slots_used('childenv'), 1, 'parent sees child hold');
    $blocked = false;
    try {
        mimir_bc_slot_acquire('childenv');
    } catch (MimirUserException $error) {
        $blocked = true;
        mimir_bcl_same($error->status, 503, 'blocked by live child is 503');
    }
    mimir_bcl_same($blocked, true, 'parent cannot take the only slot while child holds it');
    mimir_bcl_same(mimir_bc_slots_used('childenv'), 1, 'failed acquire did not steal the child slot');
} finally {
    mimir_bcl_child_stop($child);
}
mimir_bcl_same(mimir_bc_slots_used('childenv'), 0, 'child exit released flock');
$afterChild = mimir_bc_slot_acquire('childenv');
mimir_bcl_same($afterChild, 0, 'acquire works after child death');
mimir_bc_slot_release('childenv');
mimir_bcl_same(mimir_bc_slots_used('childenv'), 0, 'parent release after child');

// --- (9) with_slot releases on success and on exception ---
mimir_bcl_reset();
$inside = mimir_bc_with_slot('kvtmdlive_aad', static function (int $waitMs): int {
    if ($waitMs !== 0) {
        throw new RuntimeException('expected no wait');
    }
    return mimir_bc_slots_used('kvtmdlive_aad');
});
mimir_bcl_same($inside, 1, 'with_slot holds one slot');
mimir_bcl_same(mimir_bc_slots_used('kvtmdlive_aad'), 0, 'with_slot released');
$threw = false;
try {
    mimir_bc_with_slot('kvtmdlive_aad', static function (): void {
        throw new RuntimeException('boom');
    });
} catch (RuntimeException $error) {
    $threw = $error->getMessage() === 'boom';
}
mimir_bcl_same($threw, true, 'with_slot propagates');
mimir_bcl_same(mimir_bc_slots_used('kvtmdlive_aad'), 0, 'with_slot released after throw');

// --- (10) Shutdown release drops remaining holds (including nested refs) ---
mimir_bcl_reset(3, 5);
mimir_bc_slot_acquire('kvtmdlive_aad');
mimir_bc_slot_acquire('kvtmdlive_aad');
mimir_bc_slot_acquire('kvtgermanylive_aad');
mimir_bcl_same(!empty($GLOBALS['mimir_bc_shutdown_registered']), true, 'shutdown release registered on acquire');
mimir_bcl_same(mimir_bc_slots_used('kvtmdlive_aad'), 1, 'nl held before shutdown');
mimir_bcl_same(mimir_bc_slots_used('kvtgermanylive_aad'), 1, 'de held before shutdown');
mimir_bc_limit_shutdown_release();
mimir_bcl_same(mimir_bc_slots_used('kvtmdlive_aad'), 0, 'shutdown released nl including nested refs');
mimir_bcl_same(mimir_bc_slots_used('kvtgermanylive_aad'), 0, 'shutdown released de');
mimir_bcl_same($GLOBALS['mimir_bc_held'], [], 'process hold map cleared');
mimir_bc_limit_shutdown_release();
mimir_bcl_same(mimir_bc_slots_used('kvtmdlive_aad'), 0, 'second shutdown is a no-op');
$afterShutdown = mimir_bc_slot_acquire('kvtmdlive_aad');
mimir_bcl_same($afterShutdown, 0, 'acquire works after shutdown release');
mimir_bc_slot_release('kvtmdlive_aad');
mimir_bcl_same(mimir_bc_slots_used('kvtmdlive_aad'), 0, 'normal release still works after shutdown');

// --- (11) Coordination failure falls back to one exclusive flock, never unlimited ---
mimir_bcl_reset(3, 0);
$GLOBALS['mimir_bc_limit_force_fallback'] = true;
@unlink(mimir_event_log_path());
mimir_bcl_same(mimir_bc_limit_max_concurrent(), 3, 'configured max stays 3 in fallback');
mimir_bc_slot_force_hold('kvtmdlive_aad', 'fallback-foreign');
mimir_bcl_same(mimir_bc_slots_used('kvtmdlive_aad'), 1, 'fallback is a single slot');
$fallbackBlocked = false;
try {
    mimir_bc_slot_acquire('kvtmdlive_aad');
} catch (MimirUserException $error) {
    $fallbackBlocked = true;
    mimir_bcl_same($error->status, 503, 'fallback cap is 503');
    if (!str_contains($error->getMessage(), 'max 1')) {
        mimir_bcl_fail('fallback timeout should say max 1');
    }
    mimir_bcl_same(mimir_should_failover_to_bc($error), false, 'fallback timeout does not uncapped-failover');
}
mimir_bcl_same($fallbackBlocked, true, 'second holder blocked on the one fallback slot');
$deFallback = mimir_bc_slot_acquire('kvtgermanylive_aad');
mimir_bcl_same($deFallback, 0, 'fallback is per environment');
mimir_bcl_same(mimir_bc_slots_used('kvtgermanylive_aad'), 1, 'germany fallback held');
mimir_bc_slot_release('kvtgermanylive_aad');
mimir_bc_slot_force_release('kvtmdlive_aad', 'fallback-foreign');
$nlFallback = mimir_bc_slot_acquire('kvtmdlive_aad');
mimir_bcl_same($nlFallback, 0, 'fallback acquire after release');
mimir_bcl_same(mimir_bc_slots_used('kvtmdlive_aad'), 1, 'one fallback holder');
mimir_bc_slot_release('kvtmdlive_aad');
$loggedFallback = false;
$loggedBypass = false;
foreach (mimir_event_log_recent(20) as $row) {
    if (($row['action'] ?? '') === 'fallback-one-slot') {
        $loggedFallback = true;
    }
    if (($row['action'] ?? '') === 'bypassed-to-BC') {
        $loggedBypass = true;
    }
}
mimir_bcl_same($loggedFallback, true, 'fallback is logged');
mimir_bcl_same($loggedBypass, false, 'fallback does not log an unlimited BC bypass');

// --- (12) If the fallback lock cannot be opened either, fail closed ---
mimir_bcl_reset(3, 0);
$savedDir = mimir_bc_limit_dir();
mimir_bc_limit_set_dir('/proc/mimir-bc-slots-denied');
$GLOBALS['mimir_bc_limit_fallback_path'] = '/proc/mimir-bc-fallback-denied';
@unlink(mimir_event_log_path());
$closed = false;
try {
    mimir_bc_slot_acquire('kvtmdlive_aad');
} catch (MimirUserException $error) {
    $closed = true;
    mimir_bcl_same($error->status, 503, 'fail closed is 503');
    if (!str_contains($error->getMessage(), 'niet ongelimiteerd')) {
        mimir_bcl_fail('fail-closed message should refuse unlimited BC');
    }
    mimir_bcl_same(mimir_should_failover_to_bc($error), false, 'coordination failure does not fail over to BC');
    mimir_bcl_same(mimir_is_storage_failure($error), false, 'coordination failure is not classified as storage bypass');
}
mimir_bcl_same($closed, true, 'acquire fails closed when no lock file can be opened');
mimir_bcl_same($GLOBALS['mimir_bc_held'], [], 'fail closed does not record an untracked hold');
$closedBypass = false;
foreach (mimir_event_log_recent(20) as $row) {
    if (($row['action'] ?? '') === 'bypassed-to-BC') {
        $closedBypass = true;
    }
}
mimir_bcl_same($closedBypass, false, 'fail closed does not log bypassed-to-BC');
mimir_bc_limit_set_dir($savedDir);
unset($GLOBALS['mimir_bc_limit_fallback_path']);

// cleanup
mimir_bcl_reset();
foreach (['kvtmdlive_aad', 'kvtgermanylive_aad', 'childenv'] as $envName) {
    @unlink(sys_get_temp_dir() . '/mimir-bc-slot-' . $envName . '.lock');
}

fwrite(STDOUT, "ok\n");
