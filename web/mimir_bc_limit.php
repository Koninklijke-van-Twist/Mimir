<?php

declare(strict_types=1);

require_once __DIR__ . '/mimir_reliability.php';

/**
 * Cross-process limiet op gelijktijdige live Business Central-requests.
 * Per environment (bijv. kvtmdlive_aad) een counting semaphore van flock's.
 *
 * Het vorige limiet-SQLite (`bc_limit.sqlite`) liet elke wachtende
 * Apache/PHP-FPM-worker elke ~50ms `BEGIN IMMEDIATE` doen. Die exclusieve
 * lock op de coördinatiedatabase zelf werd onder belasting een lock-storm,
 * en bij een storage-fout ging de aanroep ongelimiteerd live naar BC.
 *
 * Nu houdt elk slot een exclusive `flock` op een eigen bestand
 * (`<datamap>/bc_slots/<environment>/slot-N`). Wachten is een korte
 * non-blocking poll op die bestanden, niet op SQLite. Sterft een worker
 * (timeout, OOM, deploy), dan laat de kernel de lock vallen zodra de
 * filedescriptor dichtgaat — er is geen rij in een wachttabel die kan
 * blijven hangen. Een shutdown-handler geeft slots van dit proces ook vrij
 * als `finally` niet liep.
 *
 * Lukt het openen van de slotbestanden niet, dan valt dit proces terug op
 * één exclusive flock (1 slot) voor dat environment. Lukt ook dat niet,
 * dan stopt acquire met HTTP 503. Live BC gaat nooit ongelimiteerd door.
 *
 * Geen strikte FIFO-ticketlijst: de eerste waiter die een vrij slot ziet
 * krijgt het. Een gecrashte waiter houdt geen lock en blokkeert niemand.
 */

const MIMIR_BC_MAX_CONCURRENT = 3;
const MIMIR_BC_QUEUE_WAIT_SECONDS = 120;
const MIMIR_BC_LIMIT_POLL_US = 50000;

/**
 * Override voor tests: map voor de slotbestanden, of null voor de default.
 */
function mimir_bc_limit_set_dir(?string $dir): void
{
    if ($dir === null || $dir === '') {
        unset($GLOBALS['mimir_bc_limit_dir']);
        return;
    }
    $GLOBALS['mimir_bc_limit_dir'] = $dir;
}

function mimir_bc_limit_dir(): string
{
    if (isset($GLOBALS['mimir_bc_limit_dir']) && is_string($GLOBALS['mimir_bc_limit_dir']) && $GLOBALS['mimir_bc_limit_dir'] !== '') {
        return $GLOBALS['mimir_bc_limit_dir'];
    }

    return mimir_runtime_dir() . '/bc_slots';
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

/**
 * Eén padcomponent. Geen slashes, geen `.` / `..`.
 */
function mimir_bc_limit_env_key(string $environment): string
{
    $key = preg_replace('/[^A-Za-z0-9._-]+/', '_', $environment);
    if (!is_string($key) || $key === '' || $key === '.' || $key === '..') {
        $key = 'env';
    }
    if (strlen($key) > 80) {
        $key = substr($key, 0, 48) . '-' . substr(hash('sha256', $environment), 0, 16);
    }

    return $key;
}

function mimir_bc_limit_env_dir(string $environment): string
{
    return rtrim(mimir_bc_limit_dir(), '/') . '/' . mimir_bc_limit_env_key($environment);
}

/**
 * Eén exclusive lock gedeeld door iedereen die de slotbestanden niet kan
 * openen. Per environment, zodat NL en Germany elkaar niet afknijpen.
 */
function mimir_bc_limit_fallback_path(string $environment): string
{
    if (
        isset($GLOBALS['mimir_bc_limit_fallback_path'])
        && is_string($GLOBALS['mimir_bc_limit_fallback_path'])
        && $GLOBALS['mimir_bc_limit_fallback_path'] !== ''
    ) {
        return $GLOBALS['mimir_bc_limit_fallback_path'];
    }

    return rtrim(sys_get_temp_dir(), '/') . '/mimir-bc-slot-' . mimir_bc_limit_env_key($environment) . '.lock';
}

function mimir_bc_limit_ensure_dir(string $dir): bool
{
    if (is_dir($dir)) {
        return true;
    }
    $previous = umask(0);
    $created = @mkdir($dir, 0777, true);
    umask($previous);

    return $created || is_dir($dir);
}

/**
 * @return resource|null
 */
function mimir_bc_limit_open_lock_file(string $path)
{
    $previous = umask(0);
    $handle = @fopen($path, 'c');
    umask($previous);
    if ($handle === false) {
        return null;
    }
    // Apache (www-data) en CLI moeten dezelfde lockbestanden kunnen openen.
    @chmod($path, 0666);

    return $handle;
}

/**
 * @param resource|null $handle
 */
function mimir_bc_limit_close_handle($handle): void
{
    if (!is_resource($handle)) {
        return;
    }
    @flock($handle, LOCK_UN);
    @fclose($handle);
}

function mimir_bc_limit_throw_timeout(string $environment, int $waitSeconds, int $max): void
{
    $message = 'BC-concurrency limiet bereikt voor environment ' . $environment
        . ': wachttijd van ' . $waitSeconds . 's overschreden (max '
        . $max . ' gelijktijdige live-requests). Probeer het later opnieuw.';
    if (class_exists('MimirUserException', false)) {
        throw new MimirUserException($message, 503);
    }
    throw new RuntimeException($message, 503);
}

function mimir_bc_limit_throw_unavailable(string $environment): void
{
    $message = 'BC-slotcoördinatie niet beschikbaar voor environment ' . $environment
        . '; live BC wordt niet ongelimiteerd doorgelaten.';
    if (class_exists('MimirUserException', false)) {
        throw new MimirUserException($message, 503);
    }
    throw new RuntimeException($message, 503);
}

function mimir_bc_limit_log_fallback(string $environment): void
{
    if (!isset($GLOBALS['mimir_bc_limit_fallback_logged']) || !is_array($GLOBALS['mimir_bc_limit_fallback_logged'])) {
        $GLOBALS['mimir_bc_limit_fallback_logged'] = [];
    }
    if (isset($GLOBALS['mimir_bc_limit_fallback_logged'][$environment])) {
        return;
    }
    $GLOBALS['mimir_bc_limit_fallback_logged'][$environment] = true;
    if (!function_exists('mimir_event_log')) {
        return;
    }
    mimir_event_log(
        'bc-limit',
        'BC-slotbestanden niet bruikbaar; exclusieve flock met 1 slot zodat live BC niet ongelimiteerd doorgaat.',
        $environment,
        '',
        'fallback-one-slot'
    );
}

/**
 * @return array{handles: list<resource>, fallback: bool}|null
 */
function mimir_bc_limit_open_slot_handles(string $environment, int $max): ?array
{
    $dir = mimir_bc_limit_env_dir($environment);
    if (!mimir_bc_limit_ensure_dir($dir)) {
        return null;
    }
    $handles = [];
    for ($i = 0; $i < $max; $i++) {
        $handle = mimir_bc_limit_open_lock_file($dir . '/slot-' . $i);
        if ($handle === null) {
            foreach ($handles as $opened) {
                mimir_bc_limit_close_handle($opened);
            }

            return null;
        }
        $handles[] = $handle;
    }

    return ['handles' => $handles, 'fallback' => false];
}

/**
 * @return array{handles: list<resource>, fallback: bool}
 */
function mimir_bc_limit_open_candidates(string $environment, int $max): array
{
    $forceFallback = !empty($GLOBALS['mimir_bc_limit_force_fallback']);
    if (!$forceFallback) {
        $slots = mimir_bc_limit_open_slot_handles($environment, $max);
        if ($slots !== null) {
            return $slots;
        }
    }

    $handle = mimir_bc_limit_open_lock_file(mimir_bc_limit_fallback_path($environment));
    if ($handle === null) {
        mimir_bc_limit_throw_unavailable($environment);
        throw new RuntimeException('BC-slotcoördinatie niet beschikbaar.');
    }
    mimir_bc_limit_log_fallback($environment);

    return ['handles' => [$handle], 'fallback' => true];
}

/**
 * Eerste vrije exclusive lock, of timeout. Niet-winnaars worden gesloten
 * zodat dit proces niet per ongeluk extra slots vasthoudt.
 *
 * @param list<resource> $handles
 * @return array{handle: resource, slot: int}
 */
function mimir_bc_limit_wait_lock(array $handles, float $deadline, string $environment, int $max, int $waitSeconds): array
{
    $count = count($handles);
    if ($count < 1) {
        mimir_bc_limit_throw_unavailable($environment);
    }
    try {
        $start = random_int(0, $count - 1);
    } catch (Throwable) {
        $start = 0;
    }
    $winner = null;
    try {
        while ($winner === null) {
            for ($step = 0; $step < $count; $step++) {
                $index = ($start + $step) % $count;
                $handle = $handles[$index];
                if (is_resource($handle) && @flock($handle, LOCK_EX | LOCK_NB)) {
                    $winner = ['handle' => $handle, 'slot' => $index];
                    break;
                }
            }
            if ($winner !== null) {
                break;
            }
            if (mimir_bc_limit_now() >= $deadline) {
                mimir_bc_limit_throw_timeout($environment, $waitSeconds, $max);
            }
            $start = ($start + 1) % $count;
            mimir_bc_limit_sleep(MIMIR_BC_LIMIT_POLL_US);
        }
    } finally {
        foreach ($handles as $handle) {
            if ($winner !== null && $handle === $winner['handle']) {
                continue;
            }
            if (is_resource($handle)) {
                @fclose($handle);
            }
        }
    }
    if ($winner === null) {
        mimir_bc_limit_throw_unavailable($environment);
    }

    return $winner;
}

function mimir_bc_limit_file_locked(string $path): bool
{
    if ($path === '' || !is_file($path)) {
        return false;
    }
    $handle = @fopen($path, 'c');
    if ($handle === false) {
        return true;
    }
    $got = @flock($handle, LOCK_EX | LOCK_NB);
    if ($got) {
        @flock($handle, LOCK_UN);
        @fclose($handle);

        return false;
    }
    @fclose($handle);

    return true;
}

/**
 * Neemt één BC-slot voor $environment. Herhaaldelijk binnen hetzelfde
 * proces is reentrant (refcount); alleen de eerste acquire wacht.
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
    $started = mimir_bc_limit_now();
    $deadline = $started + $waitSeconds;
    $candidates = mimir_bc_limit_open_candidates($environment, $max);
    $effectiveMax = $candidates['fallback'] ? 1 : $max;
    $winner = mimir_bc_limit_wait_lock(
        $candidates['handles'],
        $deadline,
        $environment,
        $effectiveMax,
        $waitSeconds
    );
    $waitMs = (int) max(0, (int) round((mimir_bc_limit_now() - $started) * 1000));
    $GLOBALS['mimir_bc_held'][$environment] = [
        'refs' => 1,
        'handle' => $winner['handle'],
        'slot' => $winner['slot'],
        'wait_ms' => $waitMs,
        'fallback' => $candidates['fallback'],
    ];
    mimir_bc_limit_register_shutdown_release();

    return $waitMs;
}

function mimir_bc_slot_release(string $environment): void
{
    $environment = trim($environment);
    if ($environment === '' || !isset($GLOBALS['mimir_bc_held']) || !is_array($GLOBALS['mimir_bc_held'])) {
        return;
    }
    if (!isset($GLOBALS['mimir_bc_held'][$environment]) || !is_array($GLOBALS['mimir_bc_held'][$environment])) {
        return;
    }

    $GLOBALS['mimir_bc_held'][$environment]['refs']--;
    if ($GLOBALS['mimir_bc_held'][$environment]['refs'] > 0) {
        return;
    }

    $handle = $GLOBALS['mimir_bc_held'][$environment]['handle'] ?? null;
    unset($GLOBALS['mimir_bc_held'][$environment]);
    mimir_bc_limit_close_handle($handle);
}

/**
 * Aantal bezette slots voor een environment (andere workers inbegrepen).
 * Telt locks, niet een tabel: een gestorven proces telt niet meer mee.
 */
function mimir_bc_slots_used(string $environment): int
{
    $environment = trim($environment);
    if ($environment === '') {
        return 0;
    }

    $fallback = !empty($GLOBALS['mimir_bc_limit_force_fallback']);
    if (!$fallback && isset($GLOBALS['mimir_bc_held'][$environment]['fallback'])) {
        $fallback = !empty($GLOBALS['mimir_bc_held'][$environment]['fallback']);
    }
    if (!$fallback && isset($GLOBALS['mimir_bc_foreign']) && is_array($GLOBALS['mimir_bc_foreign'])) {
        foreach ($GLOBALS['mimir_bc_foreign'] as $info) {
            if (!is_array($info)) {
                continue;
            }
            if (($info['environment'] ?? '') === $environment && !empty($info['fallback'])) {
                $fallback = true;
                break;
            }
        }
    }
    if (!$fallback && !is_dir(mimir_bc_limit_env_dir($environment))) {
        $fallback = is_file(mimir_bc_limit_fallback_path($environment));
    }
    if ($fallback) {
        return mimir_bc_limit_file_locked(mimir_bc_limit_fallback_path($environment)) ? 1 : 0;
    }

    $dir = mimir_bc_limit_env_dir($environment);
    $max = mimir_bc_limit_max_concurrent();
    $used = 0;
    for ($i = 0; $i < $max; $i++) {
        if (mimir_bc_limit_file_locked($dir . '/slot-' . $i)) {
            $used++;
        }
    }

    return $used;
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
 * De kernel laat de flock ook vallen als dit proces sterft vóór shutdown.
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
function mimir_bc_slot_force_hold(string $environment, string $holderId): void
{
    $environment = trim($environment);
    $holderId = trim($holderId);
    if ($environment === '' || $holderId === '') {
        throw new InvalidArgumentException('environment en holderId zijn verplicht.');
    }
    if (!isset($GLOBALS['mimir_bc_foreign']) || !is_array($GLOBALS['mimir_bc_foreign'])) {
        $GLOBALS['mimir_bc_foreign'] = [];
    }
    $key = $environment . "\0" . $holderId;
    if (isset($GLOBALS['mimir_bc_foreign'][$key])) {
        return;
    }

    $max = mimir_bc_limit_max_concurrent();
    $candidates = mimir_bc_limit_open_candidates($environment, $max);
    $winner = null;
    foreach ($candidates['handles'] as $index => $handle) {
        if (is_resource($handle) && @flock($handle, LOCK_EX | LOCK_NB)) {
            $winner = ['handle' => $handle, 'slot' => $index];
            break;
        }
    }
    foreach ($candidates['handles'] as $handle) {
        if ($winner !== null && $handle === $winner['handle']) {
            continue;
        }
        if (is_resource($handle)) {
            @fclose($handle);
        }
    }
    if ($winner === null) {
        throw new RuntimeException('Geen vrij BC-slot voor force_hold.');
    }
    $GLOBALS['mimir_bc_foreign'][$key] = [
        'environment' => $environment,
        'handle' => $winner['handle'],
        'fallback' => $candidates['fallback'],
    ];
}

function mimir_bc_slot_force_release(string $environment, string $holderId): void
{
    $key = trim($environment) . "\0" . trim($holderId);
    if (!isset($GLOBALS['mimir_bc_foreign']) || !is_array($GLOBALS['mimir_bc_foreign'])) {
        return;
    }
    if (!isset($GLOBALS['mimir_bc_foreign'][$key]) || !is_array($GLOBALS['mimir_bc_foreign'][$key])) {
        return;
    }
    $handle = $GLOBALS['mimir_bc_foreign'][$key]['handle'] ?? null;
    unset($GLOBALS['mimir_bc_foreign'][$key]);
    mimir_bc_limit_close_handle($handle);
}

/**
 * Sluit locks en wist process-state (alleen voor tests).
 */
function mimir_bc_limit_test_reset(): void
{
    if (isset($GLOBALS['mimir_bc_held']) && is_array($GLOBALS['mimir_bc_held'])) {
        foreach ($GLOBALS['mimir_bc_held'] as $info) {
            if (is_array($info)) {
                mimir_bc_limit_close_handle($info['handle'] ?? null);
            }
        }
    }
    $GLOBALS['mimir_bc_held'] = [];
    if (isset($GLOBALS['mimir_bc_foreign']) && is_array($GLOBALS['mimir_bc_foreign'])) {
        foreach ($GLOBALS['mimir_bc_foreign'] as $info) {
            if (is_array($info)) {
                mimir_bc_limit_close_handle($info['handle'] ?? null);
            }
        }
    }
    $GLOBALS['mimir_bc_foreign'] = [];
}
