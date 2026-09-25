<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/web/mimir_store.php';

function mimir_sqlite_fail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function mimir_sqlite_same(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) {
        mimir_sqlite_fail($message . ' expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }
}

function mimir_sqlite_pragma(PDO $pdo, string $name): string
{
    $stmt = $pdo->query('PRAGMA ' . $name);
    if ($stmt === false) {
        mimir_sqlite_fail('PRAGMA ' . $name . ' failed');
    }
    return (string) $stmt->fetchColumn();
}

$memory = mimir_db(':memory:');
mimir_sqlite_same((int) mimir_sqlite_pragma($memory, 'busy_timeout'), 30000, 'in-memory busy_timeout is 30s');

$root = sys_get_temp_dir() . '/mimir-sqlite-' . bin2hex(random_bytes(4));
register_shutdown_function(static function () use ($root): void {
    if (!is_dir($root)) {
        return;
    }
    foreach (glob($root . '/*') ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    @rmdir($root);
});

$path = $root . '/mimir.sqlite';
$pdo = mimir_db($path);
mimir_sqlite_same((int) mimir_sqlite_pragma($pdo, 'busy_timeout'), 30000, 'file db busy_timeout is 30s');
mimir_sqlite_same(strtolower(mimir_sqlite_pragma($pdo, 'journal_mode')), 'wal', 'file db uses WAL');
mimir_sqlite_same((int) mimir_sqlite_pragma($pdo, 'synchronous'), 1, 'WAL uses synchronous NORMAL');
mimir_sqlite_same(fileperms($root) & 0777, 0777, 'data directory is 0777');
mimir_sqlite_same(fileperms($path) & 0777, 0666, 'sqlite file is 0666');
foreach ([$path . '-wal', $path . '-shm'] as $side) {
    if (is_file($side)) {
        mimir_sqlite_same(fileperms($side) & 0777, 0666, basename($side) . ' is 0666');
    }
}

mimir_meta_put($pdo, 'probe', ['ok' => true], 1_700_000_000);
$stored = mimir_meta_get($pdo, 'probe', 60, 1_700_000_000);
if (!is_array($stored) || ($stored['ok'] ?? null) !== true) {
    mimir_sqlite_fail('meta put/get on WAL file db failed');
}
foreach ([$path . '-wal', $path . '-shm'] as $side) {
    if (is_file($side)) {
        mimir_sqlite_same(fileperms($side) & 0777, 0666, basename($side) . ' stays 0666 after a write');
    }
}

$calls = 0;
$retried = mimir_db_retry(static function () use (&$calls): string {
    $calls++;
    if ($calls < 3) {
        throw new PDOException('SQLSTATE[HY000]: General error: 5 database is locked');
    }
    return 'ok';
}, 4);
mimir_sqlite_same($retried, 'ok', 'retry returns the successful result');
mimir_sqlite_same($calls, 3, 'retry runs again after database is locked');

$calls = 0;
try {
    mimir_db_retry(static function () use (&$calls): void {
        $calls++;
        $error = new PDOException('driver busy');
        $error->errorInfo = ['HY000', 5, 'database is locked'];
        throw $error;
    }, 2);
    mimir_sqlite_fail('HY000/5 should still fail after the last attempt');
} catch (PDOException $error) {
    mimir_sqlite_same($calls, 2, 'HY000 code 5 is retried then rethrown');
    if (stripos($error->getMessage(), 'driver busy') === false) {
        mimir_sqlite_fail('original busy exception was replaced');
    }
}

$calls = 0;
try {
    mimir_db_retry(static function () use (&$calls): void {
        $calls++;
        throw new PDOException('SQLITE_BUSY: database is locked');
    }, 2);
    mimir_sqlite_fail('SQLITE_BUSY should still fail after the last attempt');
} catch (PDOException) {
    mimir_sqlite_same($calls, 2, 'SQLITE_BUSY is retried');
}

$calls = 0;
try {
    mimir_db_retry(static function () use (&$calls): void {
        $calls++;
        throw new PDOException('SQLSTATE[HY000]: General error: 8 attempt to write a readonly database');
    }, 4);
    mimir_sqlite_fail('readonly errors must not be swallowed');
} catch (PDOException) {
    mimir_sqlite_same($calls, 1, 'non-busy PDO errors are not retried');
}

$calls = 0;
try {
    mimir_db_retry(static function () use (&$calls): void {
        $calls++;
        throw new RuntimeException('metadata kapot');
    }, 4);
    mimir_sqlite_fail('runtime errors must not be swallowed');
} catch (RuntimeException $error) {
    mimir_sqlite_same($calls, 1, 'non-PDO errors are not retried');
    mimir_sqlite_same($error->getMessage(), 'metadata kapot', 'original runtime exception is preserved');
}

$blocked = $root . '/not-a-database';
if (!mkdir($blocked) && !is_dir($blocked)) {
    mimir_sqlite_fail('could not create a directory to stand in for a bad sqlite path');
}
try {
    mimir_db($blocked);
    mimir_sqlite_fail('opening a directory as sqlite should fail');
} catch (RuntimeException $error) {
    if (!str_contains($error->getMessage(), 'SQLite openen mislukt')) {
        mimir_sqlite_fail('open failures stay wrapped, got: ' . $error->getMessage());
    }
    if ($error instanceof PDOException) {
        mimir_sqlite_fail('open failures must not leak PDOException');
    }
}
@rmdir($blocked);

fwrite(STDOUT, "ok\n");
