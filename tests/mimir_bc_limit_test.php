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

$tmp = sys_get_temp_dir() . '/mimir_bc_limit_test_' . getmypid() . '.sqlite';
@unlink($tmp);
mimir_bc_limit_set_db_path($tmp);
mimir_bc_limit_reset_db_cache();
$GLOBALS['mimir_bc_max_concurrent'] = 3;
$GLOBALS['mimir_bc_queue_wait_seconds'] = 120;
unset($GLOBALS['mimir_bc_limit_sleep'], $GLOBALS['mimir_bc_limit_now']);
mimir_bc_limit_test_reset();

mimir_bcl_same(MIMIR_BC_SLOT_STALE_SECONDS, 360, 'holder stale cutoff is 360s');
mimir_bcl_same(MIMIR_BC_WAITER_STALE_SECONDS, max(MIMIR_BC_QUEUE_WAIT_SECONDS, 30) + 30, 'waiter stale constant matches default formula');
mimir_bcl_same(mimir_bc_limit_waiter_stale_seconds(), MIMIR_BC_WAITER_STALE_SECONDS, 'default waiter stale age');

// --- (1) Direct acquire / release ---
$wait = mimir_bc_slot_acquire('kvtmdlive_aad');
mimir_bcl_same($wait >= 0, true, 'acquire returns non-negative wait_ms');
mimir_bcl_same(mimir_bc_slots_used('kvtmdlive_aad'), 1, 'one holder after acquire');
mimir_bc_slot_release('kvtmdlive_aad');
mimir_bcl_same(mimir_bc_slots_used('kvtmdlive_aad'), 0, 'released');

// --- (2) Reentrancy ---
mimir_bc_slot_acquire('kvtmdlive_aad');
mimir_bcl_same(mimir_bc_slot_acquire('kvtmdlive_aad'), 0, 'nested acquire wait 0');
mimir_bcl_same(mimir_bc_slots_used('kvtmdlive_aad'), 1, 'still one holder when nested');
mimir_bc_slot_release('kvtmdlive_aad');
mimir_bcl_same(mimir_bc_slots_used('kvtmdlive_aad'), 1, 'refcount still holding');
mimir_bc_slot_release('kvtmdlive_aad');
mimir_bcl_same(mimir_bc_slots_used('kvtmdlive_aad'), 0, 'fully released');

// --- (3) Separate environments ---
mimir_bc_limit_test_reset();
$GLOBALS['mimir_bc_max_concurrent'] = 1;
mimir_bc_slot_force_hold('kvtmdlive_aad', 'foreign-nl');
$waitDe = mimir_bc_slot_acquire('kvtgermanylive_aad');
mimir_bcl_same($waitDe >= 0, true, 'other environment not blocked');
mimir_bcl_same(mimir_bc_slots_used('kvtgermanylive_aad'), 1, 'germany holder');
mimir_bcl_same(mimir_bc_slots_used('kvtmdlive_aad'), 1, 'nl still held by foreign');
mimir_bc_slot_release('kvtgermanylive_aad');
mimir_bc_slot_force_release('kvtmdlive_aad', 'foreign-nl');

// --- (4) Max concurrent: 4th times out ---
mimir_bc_limit_test_reset();
$GLOBALS['mimir_bc_max_concurrent'] = 3;
$GLOBALS['mimir_bc_queue_wait_seconds'] = 0;
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
}
mimir_bcl_same($timedOut, true, 'fourth waiter times out');
mimir_bcl_same(mimir_bc_slots_used('kvtmdlive_aad'), 3, 'still three after timeout');

// --- (5) FIFO: release one slot during poll → waiter proceeds ---
mimir_bc_limit_test_reset();
$GLOBALS['mimir_bc_max_concurrent'] = 3;
$GLOBALS['mimir_bc_queue_wait_seconds'] = 5;
foreach (['a', 'b', 'c'] as $id) {
    mimir_bc_slot_force_hold('kvtmdlive_aad', 'foreign-' . $id);
}
$polls = 0;
$GLOBALS['mimir_bc_limit_sleep'] = static function (int $us) use (&$polls): void {
    $polls++;
    if ($polls === 1) {
        mimir_bc_slot_force_release('kvtmdlive_aad', 'foreign-a');
    }
};
$waited = mimir_bc_slot_acquire('kvtmdlive_aad');
mimir_bcl_same($polls >= 1, true, 'waiter polled at least once');
mimir_bcl_same($waited >= 0, true, 'waiter acquired after release');
mimir_bcl_same(mimir_bc_slots_used('kvtmdlive_aad'), 3, 'back to three holders (2 foreign + self)');
mimir_bc_slot_release('kvtmdlive_aad');
unset($GLOBALS['mimir_bc_limit_sleep']);
mimir_bc_slot_force_release('kvtmdlive_aad', 'foreign-b');
mimir_bc_slot_force_release('kvtmdlive_aad', 'foreign-c');

// --- (6) query_entity: cache hit takes no slot ---
mimir_bc_limit_test_reset();
$GLOBALS['mimir_bc_max_concurrent'] = 3;
$GLOBALS['mimir_bc_queue_wait_seconds'] = 120;
$now = 1_700_000_000;
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
], $now - 10);
mimir_coverage_put($pdo, 'kvtmdlive_aad', 'KVT', 'WorkOrders', '', '*', $now - 10, 1);
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
}, $now);
mimir_bcl_same($calls, 0, 'cache path does not call fetch');
mimir_bcl_same($cached['meta']['bc_hit'], 0, 'cache bc_hit 0');
mimir_bcl_same($cached['meta']['queue_wait_ms'], 0, 'cache queue_wait_ms 0');
mimir_bcl_same($cached['meta']['bc_slots_used'], 0, 'cache no slot used');
mimir_bcl_same(mimir_bc_slots_used('kvtmdlive_aad'), 0, 'no leftover holders after cache query');

// --- (7) query_entity: live fetch takes one slot for whole logical call (multi-page) ---
mimir_bc_limit_test_reset();
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
}, $now);
mimir_bcl_same($page, 2, 'two pages fetched');
mimir_bcl_same($maxUsedDuring, 1, 'one slot held across pages');
mimir_bcl_same($live['meta']['bc_hit'], 1, 'live bc_hit');
mimir_bcl_same($live['meta']['queue_wait_ms'] >= 0, true, 'live queue_wait_ms present');
mimir_bcl_same($live['meta']['bc_slots_max'], 3, 'bc_slots_max in meta');
mimir_bcl_same(mimir_bc_slots_used('kvtmdlive_aad'), 0, 'slot released after query');
mimir_bcl_same(count($live['value']), 2, 'both pages in result');

// --- (8) Stale holder past cutoff is removed and does not block ---
mimir_bc_limit_test_reset();
$GLOBALS['mimir_bc_max_concurrent'] = 1;
$GLOBALS['mimir_bc_queue_wait_seconds'] = 0;
unset($GLOBALS['mimir_bc_limit_sleep'], $GLOBALS['mimir_bc_limit_now']);
mimir_bc_slot_force_hold('kvtmdlive_aad', 'ghost-holder', time() - MIMIR_BC_SLOT_STALE_SECONDS - 5);
mimir_bcl_same(mimir_bc_slots_used('kvtmdlive_aad'), 0, 'stale holder cleaned on count');
$pdoLimit = mimir_bc_limit_db();
$ghostLeft = (int) $pdoLimit->query("SELECT COUNT(*) FROM bc_holders WHERE holder_id = 'ghost-holder'")->fetchColumn();
mimir_bcl_same($ghostLeft, 0, 'stale holder row deleted');
$afterGhost = mimir_bc_slot_acquire('kvtmdlive_aad');
mimir_bcl_same($afterGhost >= 0, true, 'acquire proceeds after stale holder cleanup');
mimir_bcl_same(mimir_bc_slots_used('kvtmdlive_aad'), 1, 'only the new holder remains');
mimir_bc_slot_release('kvtmdlive_aad');

mimir_bc_slot_force_hold('kvtmdlive_aad', 'fresh-holder', time() - 30);
mimir_bcl_same(mimir_bc_slots_used('kvtmdlive_aad'), 1, 'holder younger than cutoff stays');
$freshBlocked = false;
try {
    mimir_bc_slot_acquire('kvtmdlive_aad');
} catch (MimirUserException $error) {
    $freshBlocked = true;
    mimir_bcl_same($error->status, 503, 'fresh holder still yields 503');
}
mimir_bcl_same($freshBlocked, true, 'non-stale holder still blocks');
mimir_bc_slot_force_release('kvtmdlive_aad', 'fresh-holder');

// --- (9) Dead waiter at head does not block a new acquire ---
mimir_bc_limit_test_reset();
$GLOBALS['mimir_bc_max_concurrent'] = 1;
$GLOBALS['mimir_bc_queue_wait_seconds'] = 0;
unset($GLOBALS['mimir_bc_limit_sleep'], $GLOBALS['mimir_bc_limit_now']);
mimir_bcl_same(mimir_bc_limit_waiter_stale_seconds(), 60, 'short queue budget still waits 60s before reaping waiters');
$staleWaiterAt = microtime(true) - (mimir_bc_limit_waiter_stale_seconds() + 5);
mimir_bc_slot_force_wait('kvtmdlive_aad', 'dead-head', $staleWaiterAt);
$afterDeadWaiter = mimir_bc_slot_acquire('kvtmdlive_aad');
mimir_bcl_same($afterDeadWaiter >= 0, true, 'stale head waiter does not block acquire');
mimir_bcl_same(mimir_bc_slots_used('kvtmdlive_aad'), 1, 'slot taken despite dead waiter');
$pdoLimit = mimir_bc_limit_db();
$deadLeft = (int) $pdoLimit->query("SELECT COUNT(*) FROM bc_waiters WHERE waiter_id = 'dead-head'")->fetchColumn();
mimir_bcl_same($deadLeft, 0, 'dead head waiter row removed');
mimir_bc_slot_release('kvtmdlive_aad');

mimir_bc_slot_force_wait('kvtmdlive_aad', 'live-head', microtime(true));
$liveBlocked = false;
try {
    mimir_bc_slot_acquire('kvtmdlive_aad');
} catch (MimirUserException) {
    $liveBlocked = true;
}
mimir_bcl_same($liveBlocked, true, 'fresh head waiter still blocks');
$liveLeft = (int) $pdoLimit->query("SELECT COUNT(*) FROM bc_waiters WHERE waiter_id = 'live-head'")->fetchColumn();
mimir_bcl_same($liveLeft, 1, 'fresh waiter kept');
$selfLeft = (int) $pdoLimit->query("SELECT COUNT(*) FROM bc_waiters WHERE waiter_id != 'live-head'")->fetchColumn();
mimir_bcl_same($selfLeft, 0, 'timed-out acquire removed its own waiter');

// --- (10) Shutdown release drops remaining holds (including nested refs) ---
mimir_bc_limit_test_reset();
$GLOBALS['mimir_bc_max_concurrent'] = 3;
$GLOBALS['mimir_bc_queue_wait_seconds'] = 5;
unset($GLOBALS['mimir_bc_limit_sleep'], $GLOBALS['mimir_bc_limit_now']);
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
mimir_bcl_same($afterShutdown >= 0, true, 'acquire works after shutdown release');
mimir_bc_slot_release('kvtmdlive_aad');
mimir_bcl_same(mimir_bc_slots_used('kvtmdlive_aad'), 0, 'normal release still works after shutdown');

// cleanup
mimir_bc_limit_test_reset();
@unlink($tmp);
foreach ([$tmp . '-wal', $tmp . '-shm'] as $side) {
    @unlink($side);
}

fwrite(STDOUT, "ok\n");
