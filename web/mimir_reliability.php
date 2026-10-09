<?php

declare(strict_types=1);

/**
 * Betrouwbaarheid rond SQLite en Business Central: retries, circuit breaker,
 * zelfherstel en een gebeurtenislog buiten de database.
 *
 * Het log en de circuit-staat staan in JSON-bestanden in de datamap, niet in
 * mimir.sqlite, zodat ze leesbaar blijven als de database vastzit of corrupt is.
 * Foutacties (bc-failed, bypassed-to-BC, failed, fallback-one-slot) krijgen
 * een caller-veld: sleutel-id, label, eigenaar, prefix en hash-prefix — nooit
 * de volledige API-sleutel.
 */

const MIMIR_SQLITE_BUSY_TIMEOUT_MS = 3000;
const MIMIR_SQLITE_RETRY_ATTEMPTS = 4;
const MIMIR_SQLITE_RETRY_BUDGET_US = 2000000;
const MIMIR_SQLITE_RETRY_BASE_US = 40000;
const MIMIR_BC_RETRY_ATTEMPTS = 3;
const MIMIR_BC_RETRY_BUDGET_US = 8000000;
const MIMIR_BC_RETRY_BASE_US = 200000;
const MIMIR_CIRCUIT_PROBE_SECONDS = 15;
const MIMIR_EVENT_LOG_MAX_BYTES = 262144;
const MIMIR_EVENT_LOG_KEEP = 3;
const MIMIR_BC_TIMEOUT_NO_RETRY_SECONDS = 45.0;
const MIMIR_METADATA_SNAPSHOT_TTL = 86400;

class MimirBcTransientException extends RuntimeException
{
    public function __construct(string $message, public ?int $retryAfterSeconds = null, public int $httpStatus = 0)
    {
        parent::__construct($message);
    }
}

function mimir_runtime_dir(): string
{
    if (isset($GLOBALS['mimir_runtime_dir']) && is_string($GLOBALS['mimir_runtime_dir']) && $GLOBALS['mimir_runtime_dir'] !== '') {
        return $GLOBALS['mimir_runtime_dir'];
    }
    if (function_exists('mimir_db_path')) {
        return dirname(mimir_db_path());
    }

    return __DIR__ . '/data';
}

function mimir_reliability_db_path(): string
{
    if (isset($GLOBALS['mimir_reliability_db_path']) && is_string($GLOBALS['mimir_reliability_db_path']) && $GLOBALS['mimir_reliability_db_path'] !== '') {
        return $GLOBALS['mimir_reliability_db_path'];
    }
    if (function_exists('mimir_db_path')) {
        return mimir_db_path();
    }

    return __DIR__ . '/data/mimir.sqlite';
}

function mimir_reliability_now_us(): int
{
    if (isset($GLOBALS['mimir_reliability_now_us']) && is_callable($GLOBALS['mimir_reliability_now_us'])) {
        return (int) ($GLOBALS['mimir_reliability_now_us'])();
    }

    return (int) floor(microtime(true) * 1000000);
}

function mimir_reliability_sleep_us(int $microseconds): void
{
    if ($microseconds <= 0) {
        return;
    }
    if (isset($GLOBALS['mimir_reliability_sleep']) && is_callable($GLOBALS['mimir_reliability_sleep'])) {
        ($GLOBALS['mimir_reliability_sleep'])($microseconds);
        return;
    }
    usleep($microseconds);
}

function mimir_event_timestamp(?int $unix = null): string
{
    $moment = new DateTimeImmutable('@' . ($unix ?? time()));

    return $moment->setTimezone(new DateTimeZone('Europe/Amsterdam'))->format('Y-m-d\TH:i:sP');
}

function mimir_event_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mimir_event_redact(string $message): string
{
    $message = str_replace(["\r", "\n"], ' ', $message);
    $redacted = preg_replace('/mimir_[A-Za-z0-9]+/', 'mimir_[redacted]', $message);
    if (is_string($redacted)) {
        $message = $redacted;
    }
    $redacted = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $message);
    if (is_string($redacted)) {
        $message = $redacted;
    }
    $redacted = preg_replace('/(pass(word)?|userpwd|authorization|api[_-]?key)\s*[:=]\s*\S+/i', '$1=[redacted]', $message);
    if (is_string($redacted)) {
        $message = $redacted;
    }
    if (strlen($message) > 500) {
        return substr($message, 0, 500) . '…';
    }

    return $message;
}

function mimir_event_log_path(): string
{
    return mimir_runtime_dir() . '/mimir-events.jsonl';
}

function mimir_caller_reset(): void
{
    unset($GLOBALS['mimir_caller']);
}

/**
 * Zet de aanroeper voor dit verzoek. De volledige sleutel wordt niet bewaard.
 *
 * @param array{id?: int, label?: string, owner_email?: string}|null $record
 */
function mimir_caller_bind_api_key(?array $record, string $presentedKey = ''): void
{
    $presentedKey = trim($presentedKey);
    $id = 0;
    $label = '';
    $owner = '';
    if (is_array($record)) {
        $id = (int) ($record['id'] ?? 0);
        $label = trim((string) ($record['label'] ?? ''));
        $owner = trim((string) ($record['owner_email'] ?? ''));
    }
    $GLOBALS['mimir_caller'] = [
        'kind' => 'api-key',
        'key_id' => $id,
        'label' => $label,
        'owner' => $owner,
        'prefix' => mimir_key_public_prefix($presentedKey),
        'key_hash_prefix' => mimir_key_hash_prefix($presentedKey),
    ];
}

function mimir_caller_bind_named(string $kind, string $owner = ''): void
{
    $kind = trim($kind);
    if ($kind === '') {
        mimir_caller_reset();

        return;
    }
    $GLOBALS['mimir_caller'] = [
        'kind' => $kind,
        'key_id' => 0,
        'label' => '',
        'owner' => trim($owner),
        'prefix' => '',
        'key_hash_prefix' => '',
    ];
}

/**
 * Eerste 8 tekens na `mimir_`, alleen als de rest van het geheim niet meegaat.
 */
function mimir_key_public_prefix(string $plain): string
{
    $plain = trim($plain);
    if ($plain === '') {
        return '';
    }
    if (str_starts_with($plain, 'mimir_')) {
        $plain = substr($plain, 6);
    }
    $plain = preg_replace('/[^A-Za-z0-9]/', '', $plain);
    if (!is_string($plain) || strlen($plain) < 24) {
        return '';
    }

    return substr($plain, 0, 8);
}

/**
 * Eerste 12 hex-tekens van SHA-256, dezelfde hash als `api_keys.key_hash`.
 */
function mimir_key_hash_prefix(string $plain): string
{
    $plain = trim($plain);
    if ($plain === '') {
        return '';
    }

    return substr(hash('sha256', $plain), 0, 12);
}

function mimir_event_logs_caller(string $action): bool
{
    return in_array($action, ['bc-failed', 'bypassed-to-BC', 'failed', 'fallback-one-slot'], true);
}

function mimir_caller_log_value(): string
{
    $caller = $GLOBALS['mimir_caller'] ?? null;
    if (!is_array($caller)) {
        return '';
    }
    $kind = (string) ($caller['kind'] ?? '');
    if ($kind === 'api-key') {
        $parts = [];
        $id = (int) ($caller['key_id'] ?? 0);
        $parts[] = 'key_id=' . ($id > 0 ? (string) $id : '?');
        $label = trim((string) ($caller['label'] ?? ''));
        if ($label !== '') {
            $label = str_replace(['"', "\r", "\n"], ["'", ' ', ' '], $label);
            $parts[] = 'label="' . $label . '"';
        }
        $owner = trim((string) ($caller['owner'] ?? ''));
        if ($owner !== '') {
            $parts[] = 'owner=' . str_replace(["\r", "\n", ' '], '', $owner);
        }
        $prefix = trim((string) ($caller['prefix'] ?? ''));
        if ($prefix !== '') {
            $parts[] = 'prefix=' . $prefix;
        }
        $hashPrefix = trim((string) ($caller['key_hash_prefix'] ?? ''));
        if ($hashPrefix !== '') {
            $parts[] = 'hash=' . $hashPrefix;
        }

        return implode(' ', $parts);
    }
    $parts = [];
    if ($kind !== '') {
        $parts[] = $kind;
    }
    $owner = trim((string) ($caller['owner'] ?? ''));
    if ($owner !== '') {
        $parts[] = 'owner=' . str_replace(["\r", "\n", ' '], '', $owner);
    }

    return implode(' ', $parts);
}

function mimir_event_log(string $category, string $message, string $environment = '', string $entity = '', string $action = ''): void
{
    $dir = mimir_runtime_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        return;
    }
    @chmod($dir, 0777);
    $path = mimir_event_log_path();
    $entry = [
        'ts' => mimir_event_timestamp(),
        'category' => mimir_event_redact($category),
        'message' => mimir_event_redact($message),
        'environment' => mimir_event_redact($environment),
        'entity' => mimir_event_redact($entity),
        'action' => mimir_event_redact($action),
    ];
    if (mimir_event_logs_caller($action)) {
        $caller = mimir_event_redact(mimir_caller_log_value());
        if ($caller !== '') {
            $entry['caller'] = $caller;
        }
    }
    $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        return;
    }
    $handle = @fopen($path, 'ab');
    if ($handle === false) {
        return;
    }
    try {
        if (!@flock($handle, LOCK_EX)) {
            return;
        }
        clearstatcache(true, $path);
        $size = @filesize($path);
        if (is_int($size) && $size >= MIMIR_EVENT_LOG_MAX_BYTES) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
            $handle = null;
            mimir_event_log_rotate($path);
            $handle = @fopen($path, 'ab');
            if ($handle === false) {
                return;
            }
            if (!@flock($handle, LOCK_EX)) {
                return;
            }
        }
        @fwrite($handle, $line . "\n");
        @fflush($handle);
    } finally {
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }
    if (is_file($path)) {
        @chmod($path, 0666);
    }
}

function mimir_event_log_rotate(string $path): void
{
    $keep = MIMIR_EVENT_LOG_KEEP;
    $oldest = $path . '.' . $keep;
    if (is_file($oldest)) {
        @unlink($oldest);
    }
    for ($index = $keep - 1; $index >= 1; $index--) {
        $from = $path . '.' . $index;
        $to = $path . '.' . ($index + 1);
        if (is_file($from)) {
            @rename($from, $to);
        }
    }
    if (is_file($path)) {
        @rename($path, $path . '.1');
    }
}

/**
 * @return list<array{ts: string, category: string, message: string, environment: string, entity: string, action: string, caller: string}>
 */
function mimir_event_log_recent(int $limit = 40): array
{
    if ($limit < 1) {
        return [];
    }
    $path = mimir_event_log_path();
    $lines = mimir_event_log_tail($path, $limit);
    if (count($lines) < $limit && is_file($path . '.1')) {
        $older = mimir_event_log_tail($path . '.1', $limit - count($lines));
        $lines = array_merge($older, $lines);
    }
    $rows = [];
    foreach ($lines as $line) {
        $decoded = json_decode($line, true);
        if (!is_array($decoded)) {
            continue;
        }
        $rows[] = [
            'ts' => (string) ($decoded['ts'] ?? ''),
            'category' => (string) ($decoded['category'] ?? ''),
            'message' => (string) ($decoded['message'] ?? ''),
            'environment' => (string) ($decoded['environment'] ?? ''),
            'entity' => (string) ($decoded['entity'] ?? ''),
            'action' => (string) ($decoded['action'] ?? ''),
            'caller' => (string) ($decoded['caller'] ?? ''),
        ];
    }

    return $rows;
}

/**
 * @return list<string>
 */
function mimir_event_log_tail(string $path, int $limit): array
{
    if ($limit < 1 || !is_file($path)) {
        return [];
    }
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return [];
    }
    clearstatcache(true, $path);
    $chunk = '';
    $position = @filesize($path);
    if (!is_int($position) || $position < 0) {
        @fclose($handle);
        return [];
    }
    $need = $limit + 1;
    while ($position > 0 && substr_count($chunk, "\n") <= $need) {
        $read = (int) min(8192, $position);
        $position -= $read;
        if (@fseek($handle, $position) !== 0) {
            break;
        }
        $piece = @fread($handle, $read);
        if (!is_string($piece) || $piece === '') {
            break;
        }
        $chunk = $piece . $chunk;
        if ($position === 0) {
            break;
        }
    }
    @fclose($handle);
    $parts = preg_split("/\r\n|\n|\r/", trim($chunk));
    if (!is_array($parts)) {
        return [];
    }
    $parts = array_values(array_filter($parts, static function ($line): bool {
        return is_string($line) && trim($line) !== '';
    }));
    if (count($parts) > $limit) {
        $parts = array_slice($parts, -$limit);
    }

    return $parts;
}

function mimir_circuit_path(): string
{
    return mimir_runtime_dir() . '/mimir-circuit.json';
}

/**
 * @return array<string, mixed>
 */
function mimir_circuit_read(): array
{
    $raw = @file_get_contents(mimir_circuit_path());
    if (!is_string($raw) || trim($raw) === '') {
        return ['mode' => 'normal'];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['mode' => 'normal'];
    }

    return $decoded;
}

/**
 * @param array<string, mixed> $state
 */
function mimir_circuit_write(array $state): void
{
    $dir = mimir_runtime_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        return;
    }
    @chmod($dir, 0777);
    $path = mimir_circuit_path();
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }
    $tmp = $path . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        @unlink($tmp);
        return;
    }
    @chmod($tmp, 0666);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return;
    }
    @chmod($path, 0666);
}

function mimir_circuit_is_open(): bool
{
    $state = mimir_circuit_read();

    return (string) ($state['mode'] ?? 'normal') === 'bypass-to-bc';
}

function mimir_circuit_trip(string $reason): void
{
    $state = mimir_circuit_read();
    $now = time();
    if ((string) ($state['mode'] ?? 'normal') !== 'bypass-to-bc') {
        $state['since_unix'] = $now;
        $state['since'] = mimir_event_timestamp($now);
    }
    $state['mode'] = 'bypass-to-bc';
    $state['reason'] = mimir_event_redact($reason);
    $state['last_probe_unix'] = $now;
    $state['last_probe_ok'] = false;
    mimir_circuit_write($state);
}

function mimir_circuit_close(string $reason): void
{
    $previous = mimir_circuit_read();
    $wasOpen = (string) ($previous['mode'] ?? 'normal') === 'bypass-to-bc';
    mimir_circuit_write([
        'mode' => 'normal',
        'since' => '',
        'since_unix' => null,
        'reason' => '',
        'last_probe_unix' => time(),
        'last_probe_ok' => true,
        'last_probe_detail' => mimir_event_redact($reason),
    ]);
    if ($wasOpen) {
        mimir_event_log('sqlite', 'Database weer gezond: ' . $reason, '', '', 'recovered');
    }
}

/**
 * @return array{mode: string, label: string, since: string, open: bool}
 */
function mimir_circuit_public_state(): array
{
    $state = mimir_circuit_read();
    $open = (string) ($state['mode'] ?? 'normal') === 'bypass-to-bc';

    return [
        'mode' => $open ? 'bypass-to-bc' : 'normal',
        'label' => $open ? 'bypass-to-BC' : 'normal',
        'since' => $open ? (string) ($state['since'] ?? '') : '',
        'open' => $open,
    ];
}

function mimir_circuit_should_bypass(): bool
{
    if (!mimir_circuit_is_open()) {
        return false;
    }
    $state = mimir_circuit_read();
    $last = (int) ($state['last_probe_unix'] ?? 0);
    $interval = MIMIR_CIRCUIT_PROBE_SECONDS;
    if (isset($GLOBALS['mimir_probe_interval'])) {
        $interval = (int) $GLOBALS['mimir_probe_interval'];
    }
    if ($interval < 0) {
        $interval = 0;
    }
    if ((time() - $last) >= $interval) {
        if (mimir_db_health_probe()) {
            return false;
        }
    }

    return true;
}

function mimir_bc_credentials_available(): bool
{
    if (array_key_exists('mimir_bc_credentials_available', $GLOBALS)) {
        return (bool) $GLOBALS['mimir_bc_credentials_available'];
    }
    global $baseUrl, $auth_list;
    if (!is_string($baseUrl ?? null) || trim((string) $baseUrl) === '') {
        return false;
    }

    return is_array($auth_list ?? null) && $auth_list !== [];
}

function mimir_sqlite_is_transient(PDOException $error): bool
{
    $message = $error->getMessage();
    if (stripos($message, 'database is locked') !== false
        || stripos($message, 'SQLITE_BUSY') !== false
        || stripos($message, 'SQLITE_LOCKED') !== false
        || stripos($message, 'disk I/O error') !== false
    ) {
        return true;
    }
    $info = $error->errorInfo ?? null;
    if (is_array($info)) {
        $code = (int) ($info[1] ?? 0);
        if ((string) ($info[0] ?? '') === 'HY000' && ($code === 5 || $code === 6 || $code === 10)) {
            return true;
        }
    }
    if (preg_match('/SQLSTATE\[HY000\][^\r\n]*\b(5|6|10)\b/', $message) === 1) {
        return true;
    }

    return false;
}

function mimir_sqlite_is_busy(PDOException $error): bool
{
    return mimir_sqlite_is_transient($error);
}

function mimir_is_storage_failure(Throwable $error): bool
{
    $current = $error;
    while ($current !== null) {
        if ($current instanceof PDOException) {
            return true;
        }
        $message = $current->getMessage();
        if (stripos($message, 'SQLite') !== false
            || stripos($message, 'database is locked') !== false
            || stripos($message, 'readonly') !== false
            || stripos($message, 'malformed') !== false
            || stripos($message, 'disk is full') !== false
            || stripos($message, 'disk I/O') !== false
            || stripos($message, 'Datamap') !== false
            || stripos($message, 'journal_mode') !== false
            || stripos($message, 'unable to open') !== false
            || stripos($message, 'not a database') !== false
        ) {
            return true;
        }
        $current = $current->getPrevious();
    }

    return false;
}

function mimir_retry_sleep_us(int $failedAttempt, int $budgetRemainingUs, int $baseUs): int
{
    if ($budgetRemainingUs <= 0 || $baseUs < 0) {
        return 0;
    }
    $exponent = max(0, $failedAttempt - 1);
    $delay = $baseUs;
    for ($i = 0; $i < $exponent; $i++) {
        if ($delay > 8000000) {
            break;
        }
        $delay *= 2;
    }
    $jitter = 0;
    if (array_key_exists('mimir_reliability_jitter_us', $GLOBALS)) {
        $jitter = (int) $GLOBALS['mimir_reliability_jitter_us'];
    } else {
        $jitterMax = (int) max(0, (int) floor($delay / 4));
        if ($jitterMax > 0) {
            try {
                $jitter = random_int(0, $jitterMax);
            } catch (Throwable) {
                $jitter = 0;
            }
        }
    }
    if ($jitter < 0) {
        $jitter = 0;
    }
    $sleep = $delay + $jitter;
    if ($sleep > $budgetRemainingUs) {
        $sleep = $budgetRemainingUs;
    }
    mimir_reliability_sleep_us($sleep);

    return $sleep;
}

/**
 * Opnieuw proberen bij SQLITE_BUSY, SQLITE_LOCKED en disk I/O error.
 * Exponentiële backoff met jitter, begrensde totale wachttijd.
 */
function mimir_db_retry(callable $fn, int $attempts = MIMIR_SQLITE_RETRY_ATTEMPTS): mixed
{
    if ($attempts < 1) {
        $attempts = 1;
    }
    $budget = MIMIR_SQLITE_RETRY_BUDGET_US;
    if (array_key_exists('mimir_sqlite_retry_budget_us', $GLOBALS)) {
        $budget = (int) $GLOBALS['mimir_sqlite_retry_budget_us'];
    }
    if ($budget < 0) {
        $budget = 0;
    }
    $spent = 0;
    $retried = false;
    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        try {
            $result = $fn();
            mimir_db_relax_tracked();
            if ($retried) {
                mimir_event_log('sqlite', 'Tijdelijke SQLite-fout hersteld na opnieuw proberen.', '', '', 'retried');
            }

            return $result;
        } catch (PDOException $error) {
            $transient = mimir_sqlite_is_transient($error);
            if (!$transient || $attempt >= $attempts || ($budget - $spent) <= 0) {
                throw $error;
            }
            $spent += mimir_retry_sleep_us($attempt, $budget - $spent, MIMIR_SQLITE_RETRY_BASE_US);
            $retried = true;
        }
    }

    throw new RuntimeException('SQLite-retry mislukt.');
}

function mimir_bc_is_transient(Throwable $error): bool
{
    if ($error instanceof MimirBcTransientException) {
        return true;
    }
    $message = $error->getMessage();
    if (preg_match('/HTTP (\d{3})/', $message, $match) === 1) {
        $code = (int) $match[1];
        if ($code === 429 || ($code >= 500 && $code <= 599)) {
            return true;
        }
    }
    if (stripos($message, 'timed out') !== false
        || stripos($message, 'Operation timeout') !== false
        || stripos($message, 'Connection reset') !== false
        || stripos($message, 'connection reset') !== false
        || stripos($message, 'Recv failure') !== false
        || stripos($message, 'Could not connect') !== false
        || stripos($message, 'Connection refused') !== false
    ) {
        return true;
    }

    return false;
}

function mimir_retry_after_seconds(?string $header): ?int
{
    if ($header === null) {
        return null;
    }
    $header = trim($header);
    if ($header === '') {
        return null;
    }
    if (preg_match('/^\d+$/', $header) === 1) {
        return (int) $header;
    }
    $parsed = strtotime($header);
    if ($parsed === false) {
        return null;
    }
    $delta = $parsed - time();
    if ($delta < 0) {
        return 0;
    }

    return $delta;
}

/**
 * Opnieuw proberen bij timeouts, HTTP 429, 5xx en connection resets.
 * Retry-After wordt gerespecteerd, maar de totale wachttijd blijft begrensd.
 */
function mimir_bc_retry(callable $fn, int $attempts = MIMIR_BC_RETRY_ATTEMPTS): mixed
{
    if ($attempts < 1) {
        $attempts = 1;
    }
    $budget = MIMIR_BC_RETRY_BUDGET_US;
    if (array_key_exists('mimir_bc_retry_budget_us', $GLOBALS)) {
        $budget = (int) $GLOBALS['mimir_bc_retry_budget_us'];
    }
    if ($budget < 0) {
        $budget = 0;
    }
    $spent = 0;
    $retried = false;
    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        try {
            $result = $fn();
            if ($retried) {
                mimir_event_log('bc', 'Tijdelijke Business Central-fout hersteld na opnieuw proberen.', '', '', 'retried');
            }

            return $result;
        } catch (Throwable $error) {
            if (!mimir_bc_is_transient($error) || $attempt >= $attempts) {
                throw $error;
            }
            $remaining = $budget - $spent;
            if ($remaining <= 0) {
                throw $error;
            }
            $sleepUs = 0;
            if ($error instanceof MimirBcTransientException && $error->retryAfterSeconds !== null) {
                $sleepUs = $error->retryAfterSeconds * 1000000;
                if ($sleepUs > $remaining) {
                    $sleepUs = $remaining;
                }
                if ($sleepUs < 0) {
                    $sleepUs = 0;
                }
                mimir_reliability_sleep_us($sleepUs);
            } else {
                $sleepUs = mimir_retry_sleep_us($attempt, $remaining, MIMIR_BC_RETRY_BASE_US);
            }
            $spent += $sleepUs;
            $retried = true;
        }
    }

    throw new RuntimeException('Business Central-retry mislukt.');
}

/**
 * TODO: tijdelijk 0777 op de datamap en op db, -wal, -shm en -journal, tot de
 * echte oorzaak (cron-user versus php-fpm/Apache www-data, FTP-umask) vaststaat.
 * Daarna terug naar een strakkere mode. Best-effort: fouten worden onderdrukt.
 */
function mimir_db_relax_perms(string $path): void
{
    if ($path === '' || $path === ':memory:') {
        return;
    }
    $previous = umask(0);
    try {
        $dir = dirname($path);
        $temp = rtrim(sys_get_temp_dir(), '/');
        // Niet /tmp zelf: 0777 zou de sticky bit wissen. De datamap van Mímir wel.
        if (is_dir($dir) && $dir !== '/' && $dir !== $temp) {
            @chmod($dir, 0777);
        }
        foreach ([$path, $path . '-wal', $path . '-shm', $path . '-journal'] as $file) {
            if (is_file($file)) {
                @chmod($file, 0777);
            }
        }
    } finally {
        umask($previous);
    }
}

function mimir_db_relax_tracked(): void
{
    $paths = $GLOBALS['mimir_db_perm_paths'] ?? [];
    if (!is_array($paths)) {
        return;
    }
    foreach ($paths as $tracked) {
        if (is_string($tracked)) {
            mimir_db_relax_perms($tracked);
        }
    }
}

function mimir_db_track_path(string $path): void
{
    if ($path === '' || $path === ':memory:') {
        return;
    }
    if (!isset($GLOBALS['mimir_db_perm_paths']) || !is_array($GLOBALS['mimir_db_perm_paths'])) {
        $GLOBALS['mimir_db_perm_paths'] = [];
        register_shutdown_function(static function (): void {
            $paths = $GLOBALS['mimir_db_perm_paths'] ?? [];
            if (!is_array($paths)) {
                return;
            }
            foreach ($paths as $tracked) {
                if (is_string($tracked)) {
                    mimir_db_relax_perms($tracked);
                }
            }
        });
    }
    $GLOBALS['mimir_db_perm_paths'][$path] = $path;
}

/**
 * Verwijdert een leeg -journal alleen als dat aantoonbaar veilig is:
 * exclusieve lock, bestand blijft 0 bytes, en de database staat in WAL.
 * Een niet-leeg journal kan een onafgeronde transactie zijn; dat laat SQLite
 * zelf terugrollen bij openen. -wal, -shm en het databasebestand worden nooit
 * verwijderd.
 */
function mimir_db_remove_stale_journal(string $path): bool
{
    if ($path === '' || $path === ':memory:') {
        return false;
    }
    $journal = $path . '-journal';
    if (!is_file($journal)) {
        return false;
    }
    $handle = @fopen($journal, 'rb');
    if ($handle === false) {
        return false;
    }
    if (!@flock($handle, LOCK_EX | LOCK_NB)) {
        @fclose($handle);
        return false;
    }
    $size = @filesize($journal);
    if ($size !== 0) {
        @flock($handle, LOCK_UN);
        @fclose($handle);
        return false;
    }
    $mode = '';
    try {
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 200');
        $queried = $pdo->query('PRAGMA journal_mode');
        $mode = strtolower(trim((string) ($queried === false ? '' : $queried->fetchColumn())));
        $pdo = null;
    } catch (Throwable) {
        @flock($handle, LOCK_UN);
        @fclose($handle);
        return false;
    }
    if ($mode !== 'wal') {
        @flock($handle, LOCK_UN);
        @fclose($handle);
        return false;
    }
    clearstatcache(true, $journal);
    if (@filesize($journal) !== 0) {
        @flock($handle, LOCK_UN);
        @fclose($handle);
        return false;
    }
    $removed = @unlink($journal);
    @flock($handle, LOCK_UN);
    @fclose($handle);
    if ($removed) {
        mimir_event_log(
            'sqlite',
            'Leeg rollback-journal verwijderd; database stond in WAL en het bestand was 0 bytes onder een exclusieve lock.',
            '',
            '',
            'self-repair'
        );
    }

    return (bool) $removed;
}

/**
 * @return list<string>
 */
function mimir_db_self_repair(string $path): array
{
    $actions = [];
    if ($path === '' || $path === ':memory:') {
        return $actions;
    }
    mimir_db_relax_perms($path);
    $actions[] = 'chmod-777';
    if (mimir_db_remove_stale_journal($path)) {
        $actions[] = 'removed-empty-journal';
    }

    return $actions;
}

function mimir_pdo_release(?PDO $pdo): void
{
    if (!$pdo instanceof PDO) {
        return;
    }
    try {
        if ($pdo->inTransaction()) {
            $pdo->exec('ROLLBACK');
        }
    } catch (Throwable) {
    }
}

function mimir_db_health_probe(?string $path = null): bool
{
    $path = $path ?? mimir_reliability_db_path();
    mimir_db_self_repair($path);
    if (!function_exists('mimir_db')) {
        mimir_circuit_trip('health probe zonder database-open');

        return false;
    }
    try {
        $pdo = mimir_db($path);
        $check = $pdo->query('PRAGMA quick_check');
        $value = strtolower(trim((string) ($check === false ? '' : $check->fetchColumn())));
        if ($value !== 'ok') {
            mimir_pdo_release($pdo);
            mimir_circuit_trip('quick_check: ' . $value);

            return false;
        }
        $one = $pdo->query('SELECT 1');
        $selected = $one === false ? null : $one->fetchColumn();
        mimir_pdo_release($pdo);
        $pdo = null;
        if ((int) $selected !== 1) {
            mimir_circuit_trip('SELECT 1 faalde');

            return false;
        }
    } catch (Throwable $error) {
        mimir_circuit_trip($error->getMessage());

        return false;
    }
    mimir_db_relax_perms($path);
    mimir_circuit_close('quick_check ok');

    return true;
}

function mimir_should_failover_to_bc(Throwable $error): bool
{
    if (class_exists('MimirUserException', false) && $error instanceof MimirUserException) {
        return false;
    }

    return mimir_bc_credentials_available();
}

/**
 * @param array{environment?: string, entity?: string, category?: string} $context
 */
function mimir_with_cache_or_live(callable $cached, callable $live, array $context = []): mixed
{
    $environment = (string) ($context['environment'] ?? '');
    $entity = (string) ($context['entity'] ?? '');
    $category = (string) ($context['category'] ?? 'sqlite');

    if (mimir_circuit_should_bypass()) {
        mimir_event_log($category, 'Circuit open: verzoek gaat live naar Business Central.', $environment, $entity, 'bypassed-to-BC');
        try {
            return mimir_stamp_bc_live($live());
        } catch (Throwable $liveError) {
            if (class_exists('MimirUserException', false) && $liveError instanceof MimirUserException) {
                throw $liveError;
            }
            mimir_event_log('bc', mimir_event_redact($liveError->getMessage()), $environment, $entity, 'bc-failed');
            throw $liveError;
        }
    }

    try {
        $result = $cached();
        if (!empty($GLOBALS['mimir_stamp_bc_live'])) {
            return mimir_stamp_bc_live($result);
        }

        return $result;
    } catch (Throwable $error) {
        if (class_exists('MimirUserException', false) && $error instanceof MimirUserException) {
            throw $error;
        }
        if (!mimir_should_failover_to_bc($error)) {
            throw $error;
        }
        if (mimir_is_storage_failure($error)) {
            mimir_db_self_repair(mimir_reliability_db_path());
            mimir_circuit_trip($error->getMessage());
            mimir_event_log($category, mimir_event_redact($error->getMessage()), $environment, $entity, 'bypassed-to-BC');
        } else {
            mimir_event_log('internal', mimir_event_redact($error->getMessage()), $environment, $entity, 'bypassed-to-BC');
        }
        try {
            return mimir_stamp_bc_live($live());
        } catch (Throwable $liveError) {
            if (class_exists('MimirUserException', false) && $liveError instanceof MimirUserException) {
                throw $liveError;
            }
            mimir_event_log('bc', mimir_event_redact($liveError->getMessage()), $environment, $entity, 'bc-failed');
            throw $liveError;
        }
    }
}

function mimir_stamp_bc_live(mixed $result): mixed
{
    if (!is_array($result)) {
        return $result;
    }
    if (isset($result['meta']) && is_array($result['meta'])) {
        $result['meta']['source'] = 'bc-live';
        $result['meta']['bc_hit'] = 1;
        $result['meta']['shared'] = 0;
    }
    if (isset($result['results']) && is_array($result['results'])) {
        foreach ($result['results'] as $name => $child) {
            if (is_string($name) && is_array($child)) {
                $result['results'][$name] = mimir_stamp_bc_live($child);
            }
        }
    }
    if (isset($result['value']) && !isset($result['meta'])) {
        $result['source'] = 'bc-live';
    }

    return $result;
}

/**
 * @return array<string, mixed>
 */
function mimir_json_file_read(string $path): array
{
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * @param array<string, mixed> $payload
 */
function mimir_json_file_write(string $path, array $payload): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        return;
    }
    @chmod($dir, 0777);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }
    $tmp = $path . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        @unlink($tmp);
        return;
    }
    @chmod($tmp, 0666);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return;
    }
    @chmod($path, 0666);
}

function mimir_key_mirror_path(): string
{
    return mimir_runtime_dir() . '/mimir-key-mirror.json';
}

function mimir_key_mirror_sync(PDO $pdo): void
{
    try {
        $columns = mimir_sqlite_columns($pdo, 'api_keys');
        $writeColumn = in_array('can_write', $columns, true) ? 'can_write' : '0 AS can_write';
        $stmt = $pdo->query('SELECT id, key_hash, revoked_at, label, owner_email, ' . $writeColumn . ' FROM api_keys');
        if ($stmt === false) {
            return;
        }
        $keys = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $hash = (string) ($row['key_hash'] ?? '');
            if ($hash === '') {
                continue;
            }
            $revokedAt = $row['revoked_at'] ?? null;
            $keys[$hash] = [
                'id' => (int) ($row['id'] ?? 0),
                'revoked' => $revokedAt !== null && $revokedAt !== '',
                'label' => (string) ($row['label'] ?? ''),
                'owner_email' => (string) ($row['owner_email'] ?? ''),
                'can_write' => (int) ($row['can_write'] ?? 0) === 1,
            ];
        }
        mimir_json_file_write(mimir_key_mirror_path(), ['keys' => $keys]);
    } catch (Throwable) {
    }
}

/**
 * Bij een open circuit bepaalt de spiegel wie er binnenkomt; `can_write`
 * gaat daarom mee (default false, dus een oude spiegel geeft geen schrijfrecht).
 */
function mimir_key_mirror_remember(int $id, string $plain, string $label = '', string $ownerEmail = '', bool $canWrite = false): void
{
    $plain = trim($plain);
    if ($id < 1 || $plain === '') {
        return;
    }
    $hash = hash('sha256', $plain);
    $data = mimir_json_file_read(mimir_key_mirror_path());
    $keys = $data['keys'] ?? [];
    if (!is_array($keys)) {
        $keys = [];
    }
    $existing = isset($keys[$hash]) && is_array($keys[$hash]) ? $keys[$hash] : [];
    $label = trim($label);
    $ownerEmail = trim($ownerEmail);
    if ($label === '') {
        $label = trim((string) ($existing['label'] ?? ''));
    }
    if ($ownerEmail === '') {
        $ownerEmail = trim((string) ($existing['owner_email'] ?? ''));
    }
    $keys[$hash] = [
        'id' => $id,
        'revoked' => false,
        'label' => $label,
        'owner_email' => $ownerEmail,
        'can_write' => $canWrite,
    ];
    mimir_json_file_write(mimir_key_mirror_path(), ['keys' => $keys]);
}

function mimir_key_mirror_set_write(int $id, bool $canWrite): void
{
    if ($id < 1) {
        return;
    }
    $data = mimir_json_file_read(mimir_key_mirror_path());
    $keys = $data['keys'] ?? [];
    if (!is_array($keys)) {
        return;
    }
    $changed = false;
    foreach ($keys as $hash => $row) {
        if (is_array($row) && (int) ($row['id'] ?? 0) === $id) {
            $keys[$hash]['can_write'] = $canWrite;
            $changed = true;
        }
    }
    if ($changed) {
        mimir_json_file_write(mimir_key_mirror_path(), ['keys' => $keys]);
    }
}

function mimir_cache_invalidation_pending_path(): string
{
    return mimir_runtime_dir() . '/mimir-invalidate-pending.json';
}

/**
 * Een write die slaagde terwijl SQLite niet bereikbaar was: onthoud welke
 * tabel ongeldig moet, zodat de cache na herstel niet de oude rijen serveert.
 */
function mimir_cache_invalidation_pending_add(string $environment, string $company, string $entity, int $now): void
{
    $data = mimir_json_file_read(mimir_cache_invalidation_pending_path());
    $items = is_array($data['items'] ?? null) ? $data['items'] : [];
    $items[strtolower($environment . '|' . $company . '|' . $entity)] = [
        'environment' => $environment,
        'company' => $company,
        'entity' => $entity,
        'at' => $now,
    ];
    mimir_json_file_write(mimir_cache_invalidation_pending_path(), ['items' => $items]);
}

function mimir_cache_invalidation_apply_pending(PDO $pdo): void
{
    $path = mimir_cache_invalidation_pending_path();
    if (!is_file($path) || !function_exists('mimir_cache_invalidate_entity')) {
        return;
    }
    try {
        $data = mimir_json_file_read($path);
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            mimir_cache_invalidate_entity(
                $pdo,
                (string) ($item['environment'] ?? ''),
                (string) ($item['company'] ?? ''),
                (string) ($item['entity'] ?? '')
            );
        }
        @unlink($path);
    } catch (Throwable) {
    }
}

function mimir_key_mirror_revoke(int $id): void
{
    if ($id < 1) {
        return;
    }
    $data = mimir_json_file_read(mimir_key_mirror_path());
    $keys = $data['keys'] ?? [];
    if (!is_array($keys)) {
        return;
    }
    $changed = false;
    foreach ($keys as $hash => $row) {
        if (!is_array($row)) {
            continue;
        }
        if ((int) ($row['id'] ?? 0) === $id) {
            $keys[$hash]['revoked'] = true;
            $changed = true;
        }
    }
    if ($changed) {
        mimir_json_file_write(mimir_key_mirror_path(), ['keys' => $keys]);
    }
}

/**
 * @return array{id: int, owner_email: string, label: string, key_plain: string}|null
 */
function mimir_key_mirror_lookup(string $plain): ?array
{
    $plain = trim($plain);
    if ($plain === '') {
        return null;
    }
    $hash = hash('sha256', $plain);
    $data = mimir_json_file_read(mimir_key_mirror_path());
    $keys = $data['keys'] ?? [];
    if (!is_array($keys) || !isset($keys[$hash]) || !is_array($keys[$hash])) {
        return null;
    }
    if (!empty($keys[$hash]['revoked'])) {
        return null;
    }
    $id = (int) ($keys[$hash]['id'] ?? 0);
    if ($id < 1) {
        return null;
    }

    return [
        'id' => $id,
        'owner_email' => trim((string) ($keys[$hash]['owner_email'] ?? '')),
        'label' => trim((string) ($keys[$hash]['label'] ?? '')),
        'key_plain' => '',
        'can_write' => ($keys[$hash]['can_write'] ?? false) === true,
    ];
}

/**
 * @param array<string, string> $map
 */
function mimir_company_map_remember(array $map): void
{
    $clean = [];
    foreach ($map as $name => $environment) {
        $companyName = trim((string) $name);
        $environmentName = trim((string) $environment);
        if ($companyName === '' || $environmentName === '') {
            continue;
        }
        $clean[$companyName] = $environmentName;
    }
    if ($clean === []) {
        return;
    }
    mimir_json_file_write(mimir_runtime_dir() . '/mimir-company-map.json', [
        'map' => $clean,
        'saved_at' => time(),
    ]);
}

function mimir_company_map_restore(int $maxAge = 3600): bool
{
    $current = $GLOBALS['demeter_company_environment_map'] ?? null;
    if (is_array($current) && $current !== []) {
        return true;
    }
    $data = mimir_json_file_read(mimir_runtime_dir() . '/mimir-company-map.json');
    $map = $data['map'] ?? null;
    if (!is_array($map) || $map === []) {
        return false;
    }
    $savedAt = (int) ($data['saved_at'] ?? 0);
    if ($maxAge > 0 && $savedAt > 0 && (time() - $savedAt) > $maxAge) {
        return false;
    }
    $clean = [];
    foreach ($map as $name => $environment) {
        $clean[(string) $name] = (string) $environment;
    }
    $GLOBALS['demeter_company_environment_map'] = $clean;

    return true;
}

function mimir_metadata_snapshot_path(string $environment): string
{
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $environment);
    if (!is_string($safe) || $safe === '') {
        $safe = 'environment';
    }

    return mimir_runtime_dir() . '/mimir-metadata-' . $safe . '.json';
}

/**
 * @param array<string, mixed> $parsed
 */
function mimir_metadata_snapshot_write(string $environment, array $parsed, int $now): void
{
    if ($environment === '' || !isset($parsed['entity_sets'], $parsed['types'])) {
        return;
    }
    mimir_json_file_write(mimir_metadata_snapshot_path($environment), [
        'fetched_at' => $now,
        'parsed' => $parsed,
    ]);
}

/**
 * @return array<string, mixed>|null
 */
function mimir_metadata_snapshot_read(string $environment, int $ttl): ?array
{
    if ($environment === '' || $ttl < 1) {
        return null;
    }
    $data = mimir_json_file_read(mimir_metadata_snapshot_path($environment));
    $fetchedAt = (int) ($data['fetched_at'] ?? 0);
    if ($fetchedAt < 1 || (time() - $fetchedAt) > $ttl) {
        return null;
    }
    $parsed = $data['parsed'] ?? null;
    if (!is_array($parsed) || !isset($parsed['entity_sets'], $parsed['types']) || !is_array($parsed['entity_sets']) || !is_array($parsed['types'])) {
        return null;
    }

    return $parsed;
}

/**
 * CSRF-token voor sleutelbeheer (aanmaken, intrekken, schrijfrecht). Staat in
 * de sessie en als <meta name="mimir-csrf"> op de pagina; mimir.js stuurt hem
 * mee als header X-Mimir-CSRF.
 */
function mimir_csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent() && PHP_SAPI !== 'cli') {
        session_start();
    }
    $token = $_SESSION['mimir_csrf'] ?? '';
    if (!is_string($token) || strlen($token) < 32) {
        $token = bin2hex(random_bytes(32));
        $_SESSION['mimir_csrf'] = $token;
    }

    return $token;
}

function mimir_csrf_valid(string $presented): bool
{
    $expected = $_SESSION['mimir_csrf'] ?? '';
    if (!is_string($expected) || strlen($expected) < 32 || $presented === '') {
        return false;
    }

    return hash_equals($expected, $presented);
}
