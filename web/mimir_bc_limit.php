<?php

declare(strict_types=1);

/**
 * Cross-process limiet op gelijktijdige live Business Central-requests.
 * Per environment (bijv. kvtmdlive_aad) apart, FIFO-wachtlijst via SQLite.
 *
 * In-memory counters werken niet onder Apache mod_php (meerdere workers).
 * Sterft een worker midden in een request (PHP-timeout, OOM, deploy), dan
 * loopt `finally` soms niet. Ghost holders en vooral een dode FIFO-kop in
 * bc_waiters worden daarom op elke poll opgeruimd; een shutdown-handler
 * geeft slots van dit proces alsnog vrij.
 */

const MIMIR_BC_MAX_CONCURRENT = 3;
const MIMIR_BC_QUEUE_WAIT_SECONDS = 120;
/**
 * Ghost holders (gedode worker, rij blijft in bc_holders) vervallen na dit
 * aantal seconden. 360 ligt net boven CURLOPT_TIMEOUT 300 in odata.php:
 * één lopende BC-call wordt niet halverwege afgepakt, en een dode houder
 * houdt de capaciteit niet tien minuten (de oude 600s) bezet.
 */
const MIMIR_BC_SLOT_STALE_SECONDS = 360;
/**
 * Standaardleeftijd (seconden) waarna een bc_waiters-rij als dood geldt:
 * max(MIMIR_BC_QUEUE_WAIT_SECONDS, 30) + 30 = 150.
 * Een levende waiter stopt zelf bij het wachtbudget en wist zijn rij. Deze
 * grens is dat budget plus een kleine grace, zodat een worker die in de
 * wachtrij sterft niet voor altijd FIFO-kop blijft — anders ziet iedereen
 * "BC-concurrency limiet" terwijl er niets meer naar BC gaat.
 * Bij een afwijkend wachtbudget geldt dezelfde formule via
 * mimir_bc_limit_waiter_stale_seconds().
 */
const MIMIR_BC_WAITER_STALE_SECONDS = 150;
const MIMIR_BC_LIMIT_POLL_US = 50000;

/**
 * Override voor tests: pad naar de limiet-database, of null voor default.
 *
 * @param ?string $path
 */
function mimir_bc_limit_set_db_path(?string $path): void
{
    if ($path === null) {
        unset($GLOBALS['mimir_bc_limit_db_path']);
        return;
    }
    $GLOBALS['mimir_bc_limit_db_path'] = $path;
}

function mimir_bc_limit_db_path(): string
{
    if (isset($GLOBALS['mimir_bc_limit_db_path']) && is_string($GLOBALS['mimir_bc_limit_db_path']) && $GLOBALS['mimir_bc_limit_db_path'] !== '') {
        return $GLOBALS['mimir_bc_limit_db_path'];
    }
    if (function_exists('mimir_db_path')) {
        return dirname(mimir_db_path()) . '/bc_limit.sqlite';
    }

    return __DIR__ . '/data/bc_limit.sqlite';
}

function mimir_bc_limit_max_concurrent(): int
{
    if (isset($GLOBALS['mimir_bc_max_concurrent'])) {
        return max(1, (int) $GLOBALS['mimir_bc_max_concurrent']);
    }

    return MIMIR_BC_MAX_CONCURRENT;
}

function mimir_bc_limit_queue_wait_seconds(): int
{
    if (isset($GLOBALS['mimir_bc_queue_wait_seconds'])) {
        return max(0, (int) $GLOBALS['mimir_bc_queue_wait_seconds']);
    }

    return MIMIR_BC_QUEUE_WAIT_SECONDS;
}

/**
 * Leeftijd in seconden waarna een waiter-rij op de poll-pad wordt verwijderd.
 * max(queue-wait, 30) + 30. De vloer van 30s voorkomt dat een heel kort
 * wachtbudget een waiter die nog aan het pollen is meteen wist.
 * Standaard (wachtbudget 120) is dit MIMIR_BC_WAITER_STALE_SECONDS (150).
 */
function mimir_bc_limit_waiter_stale_seconds(): int
{
    return max(mimir_bc_limit_queue_wait_seconds(), 30) + 30;
}

function mimir_bc_limit_now(): float
{
    if (isset($GLOBALS['mimir_bc_limit_now']) && is_callable($GLOBALS['mimir_bc_limit_now'])) {
        return (float) ($GLOBALS['mimir_bc_limit_now'])();
    }

    return microtime(true);
}

function mimir_bc_limit_sleep(int $microseconds): void
{
    if (isset($GLOBALS['mimir_bc_limit_sleep']) && is_callable($GLOBALS['mimir_bc_limit_sleep'])) {
        ($GLOBALS['mimir_bc_limit_sleep'])($microseconds);
        return;
    }
    usleep($microseconds);
}

function mimir_bc_limit_new_id(): string
{
    return bin2hex(random_bytes(8)) . '-' . getmypid();
}

function mimir_bc_limit_db(): PDO
{
    $path = mimir_bc_limit_db_path();
    if ($path !== ':memory:') {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException('BC-limit datamap kon niet worden aangemaakt: ' . $dir);
        }
        @chmod($dir, 0777);
    }

    if (!isset($GLOBALS['mimir_bc_limit_pdo_cache']) || !is_array($GLOBALS['mimir_bc_limit_pdo_cache'])) {
        $GLOBALS['mimir_bc_limit_pdo_cache'] = [];
    }
    $cache = &$GLOBALS['mimir_bc_limit_pdo_cache'];
    if (isset($cache[$path]) && $cache[$path] instanceof PDO) {
        return $cache[$path];
    }

    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    if ($path !== ':memory:') {
        $pdo->exec('PRAGMA journal_mode = WAL');
        @chmod($path, 0666);
        foreach ([$path . '-wal', $path . '-shm'] as $side) {
            if (is_file($side)) {
                @chmod($side, 0666);
            }
        }
    }
    mimir_bc_limit_migrate($pdo);
    $cache[$path] = $pdo;

    return $pdo;
}

/**
 * Test helper: drop cached PDO so a new path/overrides take effect.
 */
function mimir_bc_limit_reset_db_cache(): void
{
    $GLOBALS['mimir_bc_limit_pdo_cache'] = [];
}

function mimir_bc_limit_migrate(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS bc_holders (
            environment TEXT NOT NULL,
            holder_id TEXT NOT NULL,
            acquired_at INTEGER NOT NULL,
            PRIMARY KEY (environment, holder_id)
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS bc_waiters (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            environment TEXT NOT NULL,
            waiter_id TEXT NOT NULL,
            enqueued_at REAL NOT NULL
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_bc_waiters_env ON bc_waiters(environment, id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_bc_holders_env ON bc_holders(environment)');
}

function mimir_bc_limit_cleanup_stale(PDO $pdo, string $environment): void
{
    $holderCutoff = time() - MIMIR_BC_SLOT_STALE_SECONDS;
    $pdo->prepare('DELETE FROM bc_holders WHERE environment = :e AND acquired_at < :c')
        ->execute([':e' => $environment, ':c' => $holderCutoff]);

    // Zelfde klok als enqueued_at (mimir_bc_limit_now). Een dode kop anders
    // blokkeert elke latere acquire, ook als er geen holders meer zijn.
    $waiterCutoff = sprintf('%.6F', mimir_bc_limit_now() - mimir_bc_limit_waiter_stale_seconds());
    $pdo->prepare('DELETE FROM bc_waiters WHERE environment = :e AND enqueued_at < :c')
        ->execute([':e' => $environment, ':c' => $waiterCutoff]);
}

function mimir_bc_limit_throw_timeout(string $environment, int $waitSeconds, int $max): never
{
    $message = 'BC-concurrency limiet bereikt voor environment ' . $environment
        . ': wachttijd van ' . $waitSeconds . 's overschreden (max '
        . $max . ' gelijktijdige live-requests). Probeer het later opnieuw.';
    if (class_exists('MimirUserException')) {
        throw new MimirUserException($message, 503);
    }
    throw new RuntimeException($message, 503);
}

/**
 * Neemt één BC-slot voor $environment (FIFO). Herhaaldelijk binnen dezelfde
 * request/process is reentrant (refcount); alleen de eerste acquire wacht.
 *
 * @return int milliseconden gewacht vóór het slot (0 bij directe toekenning of nest)
 */
function mimir_bc_slot_acquire(string $environment): int
{
    $environment = trim($environment);
    if ($environment === '') {
        throw new InvalidArgumentException('environment is verplicht voor BC-slot.');
    }

    if (!isset($GLOBALS['mimir_bc_held']) || !is_array($GLOBALS['mimir_bc_held'])) {
        $GLOBALS['mimir_bc_held'] = [];
    }
    if (isset($GLOBALS['mimir_bc_held'][$environment])) {
        $GLOBALS['mimir_bc_held'][$environment]['refs']++;

        return 0;
    }

    $max = mimir_bc_limit_max_concurrent();
    $waitSeconds = mimir_bc_limit_queue_wait_seconds();
    $pdo = mimir_bc_limit_db();
    $holderId = mimir_bc_limit_new_id();
    $waiterId = mimir_bc_limit_new_id();
    $started = mimir_bc_limit_now();
    $deadline = $started + $waitSeconds;

    $ins = $pdo->prepare(
        'INSERT INTO bc_waiters (environment, waiter_id, enqueued_at) VALUES (:e, :w, :t)'
    );
    $ins->execute([':e' => $environment, ':w' => $waiterId, ':t' => $started]);
    $waiterRowId = (int) $pdo->lastInsertId();
    $acquired = false;

    try {
        while (true) {
            mimir_bc_limit_cleanup_stale($pdo, $environment);

            $pdo->exec('BEGIN IMMEDIATE');
            try {
                $countStmt = $pdo->prepare('SELECT COUNT(*) FROM bc_holders WHERE environment = :e');
                $countStmt->execute([':e' => $environment]);
                $held = (int) $countStmt->fetchColumn();

                $headStmt = $pdo->prepare(
                    'SELECT id FROM bc_waiters WHERE environment = :e ORDER BY id ASC LIMIT 1'
                );
                $headStmt->execute([':e' => $environment]);
                $headId = $headStmt->fetchColumn();

                if ($held < $max && (int) $headId === $waiterRowId) {
                    $pdo->prepare('DELETE FROM bc_waiters WHERE id = :id')->execute([':id' => $waiterRowId]);
                    $pdo->prepare(
                        'INSERT INTO bc_holders (environment, holder_id, acquired_at)
                         VALUES (:e, :h, :t)'
                    )->execute([
                        ':e' => $environment,
                        ':h' => $holderId,
                        ':t' => time(),
                    ]);
                    $pdo->exec('COMMIT');
                    $acquired = true;
                    $waitMs = (int) max(0, (int) round((mimir_bc_limit_now() - $started) * 1000));
                    $GLOBALS['mimir_bc_held'][$environment] = [
                        'refs' => 1,
                        'holder_id' => $holderId,
                        'wait_ms' => $waitMs,
                    ];
                    mimir_bc_limit_register_shutdown_release();

                    return $waitMs;
                }
                $pdo->exec('COMMIT');
            } catch (Throwable $error) {
                try {
                    $pdo->exec('ROLLBACK');
                } catch (Throwable) {
                }
                throw $error;
            }

            if (mimir_bc_limit_now() >= $deadline) {
                $pdo->prepare('DELETE FROM bc_waiters WHERE id = :id')->execute([':id' => $waiterRowId]);
                mimir_bc_limit_throw_timeout($environment, $waitSeconds, $max);
            }

            mimir_bc_limit_sleep(MIMIR_BC_LIMIT_POLL_US);
        }
    } catch (Throwable $error) {
        if (!$acquired) {
            try {
                $pdo->prepare('DELETE FROM bc_waiters WHERE id = :id')->execute([':id' => $waiterRowId]);
            } catch (Throwable) {
            }
        }
        throw $error;
    }
}

function mimir_bc_slot_release(string $environment): void
{
    $environment = trim($environment);
    if ($environment === '' || !isset($GLOBALS['mimir_bc_held'][$environment])) {
        return;
    }

    $GLOBALS['mimir_bc_held'][$environment]['refs']--;
    if ($GLOBALS['mimir_bc_held'][$environment]['refs'] > 0) {
        return;
    }

    $holderId = (string) $GLOBALS['mimir_bc_held'][$environment]['holder_id'];
    unset($GLOBALS['mimir_bc_held'][$environment]);

    try {
        $pdo = mimir_bc_limit_db();
        $stmt = $pdo->prepare(
            'DELETE FROM bc_holders WHERE environment = :e AND holder_id = :h'
        );
        $stmt->execute([':e' => $environment, ':h' => $holderId]);
    } catch (Throwable) {
        // Slot vrijgeven mag een response niet breken; stale cleanup ruimt op.
    }
}

/**
 * Aantal actieve houders voor een environment (andere workers inbegrepen).
 */
function mimir_bc_slots_used(string $environment): int
{
    $environment = trim($environment);
    if ($environment === '') {
        return 0;
    }
    try {
        $pdo = mimir_bc_limit_db();
        mimir_bc_limit_cleanup_stale($pdo, $environment);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM bc_holders WHERE environment = :e');
        $stmt->execute([':e' => $environment]);

        return (int) $stmt->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

/**
 * @template T
 * @param callable(int): T $fn krijgt queue_wait_ms
 * @return T
 */
function mimir_bc_with_slot(string $environment, callable $fn): mixed
{
    $waitMs = mimir_bc_slot_acquire($environment);
    try {
        return $fn($waitMs);
    } finally {
        mimir_bc_slot_release($environment);
    }
}

/**
 * Eenmalig per proces: als finally niet liep (timeout/OOM/deploy), geef
 * wat dit proces nog vasthoudt alsnog vrij. Normale release blijft primair.
 * Idempotent en slikt fouten — een response mag hier niet op stuklopen.
 */
function mimir_bc_limit_register_shutdown_release(): void
{
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;
    $GLOBALS['mimir_bc_shutdown_registered'] = true;
    register_shutdown_function('mimir_bc_limit_shutdown_release');
}

function mimir_bc_limit_shutdown_release(): void
{
    try {
        if (!isset($GLOBALS['mimir_bc_held']) || !is_array($GLOBALS['mimir_bc_held'])) {
            return;
        }
        foreach (array_keys($GLOBALS['mimir_bc_held']) as $environment) {
            if (!is_string($environment)) {
                unset($GLOBALS['mimir_bc_held'][$environment]);
                continue;
            }
            $refs = (int) ($GLOBALS['mimir_bc_held'][$environment]['refs'] ?? 1);
            if ($refs < 1) {
                $refs = 1;
            }
            for ($i = 0; $i < $refs; $i++) {
                if (!isset($GLOBALS['mimir_bc_held'][$environment])) {
                    break;
                }
                mimir_bc_slot_release($environment);
            }
            unset($GLOBALS['mimir_bc_held'][$environment]);
        }
    } catch (Throwable) {
    }
}

/**
 * Test/sim: zet een "vreemde" houder zonder process-lokale refcount
 * (alsof een andere Apache-worker het slot heeft).
 */
function mimir_bc_slot_force_hold(string $environment, string $holderId, ?int $acquiredAt = null): void
{
    $pdo = mimir_bc_limit_db();
    $pdo->prepare(
        'INSERT OR REPLACE INTO bc_holders (environment, holder_id, acquired_at)
         VALUES (:e, :h, :t)'
    )->execute([
        ':e' => trim($environment),
        ':h' => $holderId,
        ':t' => $acquiredAt ?? time(),
    ]);
}

/**
 * Test/sim: zet een vreemde waiter (alsof een andere worker in de wachtrij staat).
 */
function mimir_bc_slot_force_wait(string $environment, string $waiterId, ?float $enqueuedAt = null): void
{
    $pdo = mimir_bc_limit_db();
    $pdo->prepare(
        'INSERT INTO bc_waiters (environment, waiter_id, enqueued_at) VALUES (:e, :w, :t)'
    )->execute([
        ':e' => trim($environment),
        ':w' => $waiterId,
        ':t' => $enqueuedAt ?? mimir_bc_limit_now(),
    ]);
}

function mimir_bc_slot_force_release(string $environment, string $holderId): void
{
    $pdo = mimir_bc_limit_db();
    $pdo->prepare(
        'DELETE FROM bc_holders WHERE environment = :e AND holder_id = :h'
    )->execute([
        ':e' => trim($environment),
        ':h' => $holderId,
    ]);
}

/**
 * Leegt limiet-tabellen en process-state (alleen voor tests).
 */
function mimir_bc_limit_test_reset(): void
{
    $GLOBALS['mimir_bc_held'] = [];
    try {
        $pdo = mimir_bc_limit_db();
        $pdo->exec('DELETE FROM bc_holders');
        $pdo->exec('DELETE FROM bc_waiters');
    } catch (Throwable) {
    }
}
