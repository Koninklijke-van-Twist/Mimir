<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/web/mimir_store.php';

function mimir_rel_fail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function mimir_rel_same(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) {
        mimir_rel_fail($message . ' expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }
}

$root = sys_get_temp_dir() . '/mimir-reliability-' . bin2hex(random_bytes(4));
if (!mkdir($root) && !is_dir($root)) {
    mimir_rel_fail('could not create temp dir');
}
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

$GLOBALS['mimir_runtime_dir'] = $root;
$GLOBALS['mimir_reliability_db_path'] = $root . '/mimir.sqlite';
$GLOBALS['mimir_bc_credentials_available'] = true;
$GLOBALS['mimir_reliability_jitter_us'] = 0;
$GLOBALS['mimir_probe_interval'] = 3600;
$sleeps = [];
$GLOBALS['mimir_reliability_sleep'] = static function (int $microseconds) use (&$sleeps): void {
    $sleeps[] = $microseconds;
};

mimir_rel_same(mimir_event_timestamp(1700000000), '2023-11-14T23:13:20+01:00', 'timestamp is Europe/Amsterdam');

$calls = 0;
$sleeps = [];
$retried = mimir_db_retry(static function () use (&$calls): string {
    $calls++;
    if ($calls < 3) {
        throw new PDOException('disk I/O error');
    }

    return 'ok';
}, 4);
mimir_rel_same($retried, 'ok', 'disk I/O error is retried');
mimir_rel_same($calls, 3, 'disk I/O error succeeds on the third attempt');
mimir_rel_same($sleeps, [40000, 80000], 'sqlite backoff is exponential without jitter');

$calls = 0;
$sleeps = [];
$GLOBALS['mimir_sqlite_retry_budget_us'] = 50000;
try {
    mimir_db_retry(static function () use (&$calls): void {
        $calls++;
        throw new PDOException('SQLITE_LOCKED: database is locked');
    }, 6);
    mimir_rel_fail('budget should stop SQLITE_LOCKED retries');
} catch (PDOException) {
    mimir_rel_same($calls, 3, 'retry stops when the sleep budget is spent');
}
unset($GLOBALS['mimir_sqlite_retry_budget_us']);

$calls = 0;
try {
    mimir_db_retry(static function () use (&$calls): void {
        $calls++;
        throw new PDOException('database or disk is full');
    }, 4);
    mimir_rel_fail('disk full must not be retried as transient');
} catch (PDOException) {
    mimir_rel_same($calls, 1, 'disk full is not a transient retry');
}

$calls = 0;
$sleeps = [];
$GLOBALS['mimir_bc_retry_budget_us'] = 3000000;
$bc = mimir_bc_retry(static function () use (&$calls): string {
    $calls++;
    if ($calls < 2) {
        throw new MimirBcTransientException('HTTP 429 from OData: slow down', 30, 429);
    }

    return 'ok';
}, 3);
mimir_rel_same($bc, 'ok', 'BC 429 is retried');
mimir_rel_same($calls, 2, 'BC 429 succeeds on the second attempt');
mimir_rel_same($sleeps, [3000000], 'Retry-After is honored but clamped to the budget');
unset($GLOBALS['mimir_bc_retry_budget_us']);

$calls = 0;
try {
    mimir_bc_retry(static function () use (&$calls): void {
        $calls++;
        throw new RuntimeException('HTTP 400 from OData: bad request');
    }, 3);
    mimir_rel_fail('HTTP 400 must not be retried');
} catch (RuntimeException $error) {
    mimir_rel_same($calls, 1, 'HTTP 400 is not transient');
    mimir_rel_same(str_contains($error->getMessage(), 'HTTP 400'), true, 'original BC error is preserved');
}

$calls = 0;
try {
    mimir_bc_retry(static function () use (&$calls): void {
        $calls++;
        throw new RuntimeException('HTTP 503 from OData: down');
    }, 2);
    mimir_rel_fail('HTTP 503 should exhaust retries');
} catch (RuntimeException) {
    mimir_rel_same($calls, 2, 'HTTP 503 is retried then rethrown');
}

$path = $root . '/mimir.sqlite';
$pdo = mimir_db($path);
$journal = $path . '-journal';
file_put_contents($journal, '');
mimir_db_relax_perms($path);
mimir_rel_same(fileperms($root) & 0777, 0777, 'runtime directory is 0777');
mimir_rel_same(fileperms($path) & 0777, 0777, 'sqlite file is 0777');
mimir_rel_same(fileperms($journal) & 0777, 0777, 'journal file is 0777');
$removed = mimir_db_remove_stale_journal($path);
if (is_file($journal)) {
    mimir_rel_fail('empty journal should be removed when WAL is active, removed=' . var_export($removed, true));
}
file_put_contents($journal, 'hot-journal');
mimir_db_relax_perms($path);
mimir_rel_same(mimir_db_remove_stale_journal($path), false, 'non-empty journal is kept');
mimir_rel_same(is_file($journal), true, 'non-empty journal file still exists');
@unlink($journal);

mimir_key_mirror_remember(7, 'mimir_plaintextkey123456', 'Consus', 'tim@kvt.nl');
$mirrorRaw = (string) file_get_contents(mimir_key_mirror_path());
mimir_rel_same(str_contains($mirrorRaw, 'mimir_plaintextkey123456'), false, 'key mirror does not store the plaintext key');
mimir_rel_same(str_contains($mirrorRaw, 'Consus'), true, 'key mirror stores the label');
mimir_rel_same(str_contains($mirrorRaw, 'tim@kvt.nl'), true, 'key mirror stores the owner');
$mirrored = mimir_key_mirror_lookup('mimir_plaintextkey123456');
if (!is_array($mirrored) || (int) $mirrored['id'] !== 7) {
    mimir_rel_fail('key mirror lookup failed');
}
mimir_rel_same($mirrored['label'], 'Consus', 'mirror lookup returns the label');
mimir_rel_same($mirrored['owner_email'], 'tim@kvt.nl', 'mirror lookup returns the owner');
mimir_rel_same($mirrored['key_plain'], '', 'mirror lookup does not return the plaintext key');
mimir_key_mirror_revoke(7);
mimir_rel_same(mimir_key_mirror_lookup('mimir_plaintextkey123456'), null, 'revoked key is not served from the mirror');

mimir_event_log('sqlite', 'key mimir_abcdef0123456789 password=hunter2 <script>', 'kvtmdlive_aad', 'ItemList', 'retried');
$logRaw = (string) file_get_contents(mimir_event_log_path());
mimir_rel_same(str_contains($logRaw, 'mimir_abcdef0123456789'), false, 'event log redacts API keys');
mimir_rel_same(str_contains($logRaw, 'hunter2'), false, 'event log redacts passwords');
mimir_rel_same(str_contains($logRaw, 'mimir_[redacted]'), true, 'redacted key marker is stored');
$recent = mimir_event_log_recent(10);
$last = null;
foreach ($recent as $row) {
    if (($row['message'] ?? '') !== '' && str_contains((string) $row['message'], 'mimir_[redacted]')) {
        $last = $row;
    }
}
if (!is_array($last)) {
    mimir_rel_fail('event log recent is missing the redacted row: ' . var_export($recent, true) . "\nRAW:\n" . $logRaw);
}
$escaped = mimir_event_escape((string) $last['message'] . '<script>');
mimir_rel_same(str_contains($escaped, '<script>'), false, 'log output is escaped');
mimir_rel_same(str_contains($escaped, '&lt;script&gt;'), true, 'escaped log keeps the text');
mimir_rel_same($last['action'], 'retried', 'log keeps the action');
mimir_rel_same($last['environment'], 'kvtmdlive_aad', 'log keeps the environment');
mimir_rel_same($last['caller'] ?? '', '', 'a recovered retry does not invent a caller');

$callerKey = 'mimir_' . str_repeat('ab', 24);
$callerHash = substr(hash('sha256', $callerKey), 0, 12);
mimir_caller_bind_api_key([
    'id' => 12,
    'label' => 'Consus',
    'owner_email' => 'tim@kvt.nl',
], $callerKey);
mimir_event_log('bc', 'HTTP 500 from OData for ' . $callerKey, 'kvtmdlive_aad', 'ItemList', 'bc-failed');
mimir_event_log('sqlite', 'database is locked', 'kvtmdlive_aad', 'ItemList', 'bypassed-to-BC');
mimir_event_log('bc', 'Tijdelijke Business Central-fout hersteld na opnieuw proberen.', 'kvtmdlive_aad', 'ItemList', 'retried');
$callerRaw = (string) file_get_contents(mimir_event_log_path());
mimir_rel_same(str_contains($callerRaw, $callerKey), false, 'failure log does not contain the full API key');
mimir_rel_same(str_contains($callerRaw, 'mimir_' . substr($callerKey, 6, 8)), false, 'failure log does not contain the mimir_ prefix of the key');
$failedCaller = '';
$bypassCaller = '';
$retryCaller = '';
foreach (mimir_event_log_recent(20) as $row) {
    if (($row['action'] ?? '') === 'bc-failed' && ($row['entity'] ?? '') === 'ItemList') {
        $failedCaller = (string) ($row['caller'] ?? '');
    }
    if (($row['action'] ?? '') === 'bypassed-to-BC' && str_contains((string) ($row['message'] ?? ''), 'database is locked')) {
        $bypassCaller = (string) ($row['caller'] ?? '');
    }
    if (($row['action'] ?? '') === 'retried' && ($row['entity'] ?? '') === 'ItemList' && str_contains((string) ($row['message'] ?? ''), 'Tijdelijke')) {
        $retryCaller = (string) ($row['caller'] ?? '');
    }
}
$expectedCaller = 'key_id=12 label="Consus" owner=tim@kvt.nl prefix=' . substr($callerKey, 6, 8) . ' hash=' . $callerHash;
mimir_rel_same($failedCaller, $expectedCaller, 'bc-failed names the API key without the secret');
mimir_rel_same($bypassCaller, $expectedCaller, 'bypassed-to-BC names the same caller');
mimir_rel_same($retryCaller, '', 'retried does not add a caller field');
mimir_caller_bind_named('ui', 'tim@kvt.nl');
mimir_event_log('request', 'slot files unusable', 'kvtmdlive_aad', '', 'fallback-one-slot');
mimir_caller_bind_named('nightly', '');
mimir_event_log('nightly', 'metadata timeout', 'kvtmdlive_aad', '', 'failed');
$named = ['fallback-one-slot' => '', 'failed' => ''];
foreach (mimir_event_log_recent(10) as $row) {
    $action = (string) ($row['action'] ?? '');
    if (array_key_exists($action, $named) && $named[$action] === '') {
        $named[$action] = (string) ($row['caller'] ?? '');
    }
}
mimir_rel_same($named['fallback-one-slot'], 'ui owner=tim@kvt.nl', 'UI session is the caller on a slot fallback');
mimir_rel_same($named['failed'], 'nightly', 'nightly is the caller when the night run fails');
mimir_caller_reset();
mimir_event_log('bc', 'no caller bound', '', 'ItemList', 'bc-failed');
$unbound = '';
foreach (mimir_event_log_recent(5) as $row) {
    if (($row['message'] ?? '') === 'no caller bound') {
        $unbound = (string) ($row['caller'] ?? '');
    }
}
mimir_rel_same($unbound, '', 'bc-failed without a bound caller omits the field');

$index = (string) file_get_contents(dirname(__DIR__) . '/web/index.php');
if (!str_contains($index, "\$event['caller']")) {
    mimir_rel_fail('status page does not render the caller column');
}

$index = (string) file_get_contents(dirname(__DIR__) . '/web/index.php');
if (!str_contains($index, 'mimir_event_log_recent') || !str_contains($index, 'mimir_event_escape') || !str_contains($index, 'mimir_circuit_public_state')) {
    mimir_rel_fail('status page does not render the circuit and escaped event log');
}

$cacheCalls = 0;
$liveCalls = 0;
$phase = 'lock';
$cached = static function () use (&$cacheCalls, &$phase): array {
    $cacheCalls++;
    if ($phase === 'lock') {
        throw new PDOException('SQLSTATE[HY000]: General error: 5 database is locked');
    }

    return [
        'value' => [['No' => 'cache']],
        'meta' => ['from_cache' => 1, 'from_live' => 0, 'bc_hit' => 0, 'shared' => 1, 'environment' => 'kvtmdlive_aad'],
    ];
};
$live = static function () use (&$liveCalls): array {
    $liveCalls++;

    return [
        'value' => [['No' => 'live']],
        'meta' => ['from_cache' => 0, 'from_live' => 1, 'bc_hit' => 1, 'shared' => 0, 'environment' => 'kvtmdlive_aad'],
    ];
};

$first = mimir_with_cache_or_live($cached, $live, ['environment' => 'kvtmdlive_aad', 'entity' => 'ItemList']);
mimir_rel_same($cacheCalls, 1, 'locked database tries the cache once');
mimir_rel_same($liveCalls, 1, 'locked database falls through to a live BC call');
mimir_rel_same($first['meta']['source'] ?? null, 'bc-live', 'live bypass sets meta.source');
mimir_rel_same(mimir_circuit_is_open(), true, 'circuit opens after a locked database');

$cacheCalls = 0;
$liveCalls = 0;
$second = mimir_with_cache_or_live($cached, $live, ['environment' => 'kvtmdlive_aad', 'entity' => 'ItemList']);
mimir_rel_same($cacheCalls, 0, 'open circuit skips the database');
mimir_rel_same($liveCalls, 1, 'open circuit keeps serving live BC');
mimir_rel_same($second['meta']['source'] ?? null, 'bc-live', 'second bypass still marks bc-live');

$GLOBALS['mimir_probe_interval'] = 0;
$phase = 'ok';
$cacheCalls = 0;
$liveCalls = 0;
$third = mimir_with_cache_or_live($cached, $live, ['environment' => 'kvtmdlive_aad', 'entity' => 'ItemList']);
mimir_rel_same($cacheCalls, 1, 'healthy probe resumes the cache');
mimir_rel_same($liveCalls, 0, 'healthy probe does not call BC');
mimir_rel_same(isset($third['meta']['source']), false, 'normal cache response has no source flag');
mimir_rel_same(mimir_circuit_is_open(), false, 'circuit closes after a healthy probe');

$recovered = false;
$bypassed = false;
foreach (mimir_event_log_recent(40) as $row) {
    if (($row['action'] ?? '') === 'recovered') {
        $recovered = true;
    }
    if (($row['action'] ?? '') === 'bypassed-to-BC' && ($row['entity'] ?? '') === 'ItemList') {
        $bypassed = true;
    }
}
if (empty($bypassed)) {
    mimir_rel_fail('event log is missing the bypass action');
}
if (!$recovered) {
    mimir_rel_fail('event log is missing recovery');
}

$holderPath = $root . '/locked.sqlite';
$holder = new PDO('sqlite:' . $holderPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$holder->exec('PRAGMA journal_mode = WAL');
$holder->exec('CREATE TABLE IF NOT EXISTS t (x INTEGER)');
$holder->exec('BEGIN EXCLUSIVE');
$lockedLive = 0;
$locked = mimir_with_cache_or_live(
    static function () use ($holderPath): string {
        $other = new PDO('sqlite:' . $holderPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $other->exec('PRAGMA busy_timeout = 200');
        $other->exec('BEGIN IMMEDIATE');

        return 'cached';
    },
    static function () use (&$lockedLive): array {
        $lockedLive++;

        return ['value' => [], 'meta' => ['from_cache' => 0, 'from_live' => 0, 'bc_hit' => 1, 'shared' => 0]];
    },
    ['environment' => 'kvtmdlive_aad', 'entity' => 'ItemList']
);
$holder->exec('ROLLBACK');
$holder = null;
mimir_rel_same($lockedLive, 1, 'a real SQLite lock leads to one live call');
mimir_rel_same($locked['meta']['source'] ?? null, 'bc-live', 'real lock response is marked bc-live');

$GLOBALS['mimir_bc_credentials_available'] = false;
mimir_circuit_write(['mode' => 'normal']);
try {
    mimir_with_cache_or_live(
        static function (): void {
            throw new PDOException('database is locked');
        },
        static function (): void {
            throw new RuntimeException('live should not run without credentials');
        }
    );
    mimir_rel_fail('missing credentials must not bypass');
} catch (PDOException) {
    mimir_rel_same(true, true, 'original lock error is returned without credentials');
}
$GLOBALS['mimir_bc_credentials_available'] = true;

try {
    mimir_with_cache_or_live(
        static function (): void {
            throw new MimirUserException('Filter is ongeldig.', 400);
        },
        static function (): void {
            throw new RuntimeException('client errors must not hit BC');
        }
    );
    mimir_rel_fail('user errors must not fail over');
} catch (MimirUserException $error) {
    mimir_rel_same($error->getMessage(), 'Filter is ongeldig.', 'client error is preserved');
}

fwrite(STDOUT, "ok\n");
