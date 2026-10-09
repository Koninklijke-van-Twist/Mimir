<?php

declare(strict_types=1);

/**
 * Schrijven naar Business Central via OData (api/write.php).
 *
 * - Alleen API-sleutels met can_write=1; anders 403 write_not_allowed. De
 *   UI-sessie schrijft niet: de verkenner is alleen-lezen.
 * - Het environment komt uit de bedrijfskaart (zoals bij lezen), nooit uit de
 *   volgorde van $auth_list.
 * - Altijd live naar BC, binnen een BC-slot. Geen cache, geen stille fallback,
 *   geen retry (een time-out kan een insert al hebben gedaan).
 * - Na een geslaagde write: cache van die tabel in dat bedrijf ongeldig.
 * - Log per write in mimir-writes.jsonl: caller, tabel, bedrijf, methode,
 *   status, duur en alleen de veldnamen van de body.
 */

require_once __DIR__ . '/mimir_service.php';

const MIMIR_WRITE_MAX_BODY_BYTES = 262144;
const MIMIR_WRITE_METHODS = ['POST', 'PATCH', 'DELETE'];
const MIMIR_WRITE_LOG_MAX_BYTES = 1048576;

class MimirWriteException extends MimirUserException
{
    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(string $message, int $status, public string $errorCode, public array $extra = [])
    {
        parent::__construct($message, $status);
    }
}

/**
 * Leest de request-body met een plafond. Leeg is toegestaan (DELETE).
 *
 * @return array<string, mixed>
 */
function mimir_write_decode_body(?string $raw): array
{
    if ($raw === null || trim($raw) === '') {
        return [];
    }
    if (strlen($raw) > MIMIR_WRITE_MAX_BODY_BYTES) {
        throw new MimirWriteException('JSON-body is groter dan ' . MIMIR_WRITE_MAX_BODY_BYTES . ' bytes.', 413, 'body_too_large');
    }
    $decoded = json_decode($raw, true, 32);
    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new MimirWriteException('JSON-body moet een object zijn.', 400, 'invalid_body');
    }

    return $decoded;
}

/**
 * Normaliseert een schrijfverzoek. company/table/key/etag mogen in de body of
 * in de query string staan; de velden voor BC staan in `data`.
 *
 * @param array<string, mixed> $query
 * @param array<string, mixed> $body
 * @return array{method: string, company: string, table: string, key: array<string, mixed>, etag: string, data: array<string, mixed>, forced: bool}
 */
function mimir_write_parse_request(string $method, array $query, array $body, string $ifMatch = ''): array
{
    $method = strtoupper(trim($method));
    if (!in_array($method, MIMIR_WRITE_METHODS, true)) {
        throw new MimirWriteException('Gebruik POST (insert), PATCH (update) of DELETE.', 405, 'method_not_allowed');
    }
    $company = trim((string) ($body['company'] ?? $query['company'] ?? ''));
    $table = trim((string) ($body['table'] ?? $query['table'] ?? ''));
    if ($company === '' || $table === '') {
        throw new MimirWriteException('company en table zijn verplicht.', 400, 'invalid_request');
    }
    if (strlen($company) > 120 || preg_match('/[\x00-\x1F\x7F]/', $company) === 1) {
        throw new MimirWriteException('Bedrijfsnaam is ongeldig.', 400, 'invalid_request');
    }
    // Alleen een kale entity-setnaam: geen /, (, ?, %, punten of andere padtekens.
    if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,127}$/', $table) !== 1) {
        throw new MimirWriteException('Tabelnaam is ongeldig.', 400, 'invalid_table');
    }
    $key = $body['key'] ?? null;
    if ($key === null && isset($query['key']) && is_string($query['key'])) {
        $key = json_decode($query['key'], true);
    }
    if ($key !== null && (!is_array($key) || ($key !== [] && array_is_list($key)))) {
        throw new MimirWriteException('key moet een object zijn: {"Veld": waarde}.', 400, 'invalid_key');
    }
    $data = $body['data'] ?? [];
    if (!is_array($data) || ($data !== [] && array_is_list($data))) {
        throw new MimirWriteException('data moet een object zijn.', 400, 'invalid_body');
    }
    $etag = trim($ifMatch);
    if ($etag === '') {
        $etag = trim((string) ($body['etag'] ?? $data['@odata.etag'] ?? ''));
    }
    unset($data['@odata.etag']);
    // Overschrijven zonder versiecheck alleen als de aanroeper het expliciet
    // vraagt: etag "*" (body of If-Match) of "force": true.
    $force = $body['force'] ?? false;
    if (!is_bool($force)) {
        throw new MimirWriteException('force moet true of false zijn.', 400, 'invalid_request');
    }
    if ($force) {
        if ($etag !== '' && $etag !== '*') {
            throw new MimirWriteException('force: true en een etag sluiten elkaar uit. Stuur de etag óf force.', 400, 'invalid_request');
        }
        $etag = '*';
    }

    if ($method === 'POST' && $data === []) {
        throw new MimirWriteException('data is verplicht bij POST.', 400, 'invalid_body');
    }
    if ($method === 'PATCH' && $data === []) {
        throw new MimirWriteException('data is verplicht bij PATCH.', 400, 'invalid_body');
    }
    if ($method !== 'POST') {
        if (!is_array($key) || $key === []) {
            throw new MimirWriteException('key is verplicht bij ' . $method . '.', 400, 'invalid_key');
        }
        if ($etag === '') {
            throw new MimirWriteException('etag is verplicht bij ' . $method . ' (header If-Match, veld etag of data.@odata.etag).', 428, 'etag_required');
        }
    }
    if (strlen($etag) > 512 || preg_match('/[\x00-\x1F\x7F]/', $etag) === 1) {
        throw new MimirWriteException('etag is ongeldig.', 400, 'invalid_request');
    }

    return [
        'method' => $method,
        'company' => $company,
        'table' => $table,
        'key' => is_array($key) ? $key : [],
        'etag' => $etag,
        'data' => $data,
        'forced' => $method !== 'POST' && $etag === '*',
    ];
}

/**
 * Alleen een API-sleutel met schrijfrecht.
 *
 * @param array<string, mixed>|null $record
 */
function mimir_write_authorize(?array $record): void
{
    if (!is_array($record) || (int) ($record['id'] ?? 0) < 1) {
        throw new MimirWriteException('API-sleutel is ongeldig of ingetrokken.', 401, 'unauthorized');
    }
    if (($record['can_write'] ?? false) !== true) {
        throw new MimirWriteException('Deze API-sleutel mag niet schrijven naar Business Central.', 403, 'write_not_allowed');
    }
}

/**
 * OData-sleutelpredicaat voor een write. Elke sleutel uit de metadata is
 * verplicht; strings worden volledig URL-gecodeerd (geen padinjectie).
 *
 * @param array<string, mixed> $key
 * @param array{keys: list<string>, properties: array<string, string>} $schema
 */
function mimir_write_key_predicate(array $key, array $schema): string
{
    $keys = $schema['keys'];
    if ($keys === []) {
        throw new MimirWriteException('Deze tabel heeft geen sleutel in de metadata.', 400, 'invalid_key');
    }
    $known = [];
    foreach ($keys as $name) {
        $known[strtolower($name)] = $name;
    }
    $normalized = [];
    foreach ($key as $name => $value) {
        $canonical = $known[strtolower((string) $name)] ?? null;
        if ($canonical === null) {
            throw new MimirWriteException('Onbekend sleutelveld: ' . (string) $name, 400, 'invalid_key');
        }
        if (!is_scalar($value) && $value !== null) {
            throw new MimirWriteException('Sleutelwaarde moet een scalar zijn: ' . $canonical, 400, 'invalid_key');
        }
        $normalized[$canonical] = $value;
    }
    $parts = [];
    foreach ($keys as $name) {
        if (!array_key_exists($name, $normalized)) {
            throw new MimirWriteException('Sleutelveld ontbreekt: ' . $name, 400, 'invalid_key');
        }
        $value = $normalized[$name];
        if (is_string($value) && preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new MimirWriteException('Sleutelwaarde bevat ongeldige tekens: ' . $name, 400, 'invalid_key');
        }
        try {
            $literal = mimir_odata_literal($value, (string) ($schema['properties'][$name] ?? 'Edm.String'));
        } catch (InvalidArgumentException $error) {
            throw new MimirWriteException($name . ': ' . $error->getMessage(), 400, 'invalid_key');
        }
        if (str_starts_with($literal, "'") && str_ends_with($literal, "'") && strlen($literal) >= 2) {
            $literal = "'" . rawurlencode(substr($literal, 1, -1)) . "'";
        }
        $parts[] = $name . '=' . $literal;
    }

    return implode(',', $parts);
}

/**
 * @param array<string, mixed> $data
 * @param array{properties: array<string, string>} $schema
 * @return array<string, mixed> met de veldnamen zoals in de metadata
 */
function mimir_write_validate_fields(array $data, array $schema): array
{
    $known = [];
    foreach (array_keys($schema['properties']) as $name) {
        $known[strtolower((string) $name)] = (string) $name;
    }
    $out = [];
    foreach ($data as $name => $value) {
        $name = (string) $name;
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,127}$/', $name) !== 1) {
            throw new MimirWriteException('Veldnaam is ongeldig: ' . $name, 400, 'invalid_field');
        }
        $canonical = $known[strtolower($name)] ?? null;
        if ($canonical === null) {
            throw new MimirWriteException('Onbekend veld voor deze tabel: ' . $name, 400, 'invalid_field');
        }
        $out[$canonical] = $value;
    }

    return $out;
}

/**
 * Haalt code en message uit een OData-foutantwoord van BC.
 *
 * @return array{code: string, message: string}
 */
function mimir_write_bc_error(string $raw): array
{
    $decoded = json_decode($raw, true);
    if (is_array($decoded) && is_array($decoded['error'] ?? null)) {
        return [
            'code' => (string) ($decoded['error']['code'] ?? ''),
            'message' => mimir_event_redact(substr((string) ($decoded['error']['message'] ?? ''), 0, 800)),
        ];
    }

    return ['code' => '', 'message' => mimir_event_redact(substr(trim(strip_tags($raw)), 0, 800))];
}

function mimir_write_log_path(): string
{
    return mimir_runtime_dir() . '/mimir-writes.jsonl';
}

/**
 * Eén regel per write. Geen veldwaarden, alleen de namen. Gaat altijd naar
 * het jsonl-bestand (audit/fallback) en daarnaast naar SQLite (UI en API).
 * Lukt SQLite niet, dan komt de regel in de pending-wachtrij. Gooit nooit.
 *
 * @param array<string, mixed> $entry
 */
function mimir_write_log(?PDO $pdo, array $entry): void
{
    try {
        $caller = $GLOBALS['mimir_caller'] ?? [];
        $line = [
            'ts' => mimir_event_timestamp((int) ($entry['logged_at'] ?? time())),
            'entry_id' => bin2hex(random_bytes(8)),
            'key_id' => (int) ($caller['key_id'] ?? 0),
            'label' => (string) ($caller['label'] ?? ''),
            'owner' => (string) ($caller['owner'] ?? ''),
        ] + $entry;
    } catch (Throwable) {
        return;
    }
    try {
        $json = json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $path = mimir_write_log_path();
        $dir = dirname($path);
        if ($json !== false && (is_dir($dir) || @mkdir($dir, 0777, true))) {
            if (is_file($path) && (int) @filesize($path) > MIMIR_WRITE_LOG_MAX_BYTES) {
                @unlink($path . '.1');
                @rename($path, $path . '.1');
            }
            @file_put_contents($path, $json . "\n", FILE_APPEND | LOCK_EX);
            @chmod($path, 0666);
        }
    } catch (Throwable) {
    }
    $stored = false;
    if ($pdo instanceof PDO && !mimir_write_side_task_failing('log')) {
        try {
            mimir_write_log_insert($pdo, $line);
            $stored = true;
        } catch (Throwable) {
        }
    }
    if (!$stored) {
        mimir_pending_add('log', $line);
    }
}

/**
 * Testhaak: $GLOBALS['mimir_write_fail_side_tasks'] = ['log', 'usage', 'invalidate'].
 */
function mimir_write_side_task_failing(string $task): bool
{
    $failing = $GLOBALS['mimir_write_fail_side_tasks'] ?? [];

    return is_array($failing) && in_array($task, $failing, true);
}

/**
 * Heatmapregistratie, best-effort; anders pending.
 */
function mimir_write_record_usage(?PDO $pdo, int $keyId, int $now): void
{
    if ($keyId < 1) {
        return;
    }
    if ($pdo instanceof PDO && !mimir_write_side_task_failing('usage')) {
        try {
            mimir_usage_log($pdo, $keyId, 'write', $now, 0, 1, 0, 0, MIMIR_USAGE_KIND_WRITE);

            return;
        } catch (Throwable) {
        }
    }
    mimir_pending_add('usage', ['key_id' => $keyId, 'endpoint' => 'write', 'at' => $now]);
}

/**
 * Voert de write uit. $send is injecteerbaar voor tests; standaard
 * odata_write_request. Er is precies één aanroep van $send per verzoek.
 *
 * @param array{method: string, company: string, table: string, key: array<string, mixed>, etag: string, data: array<string, mixed>} $spec
 * @param array<string, mixed> $record
 * @param callable(string, string, array, ?string, list<string>): array{code: int, raw: string, headers?: array<string, string>}|null $send
 * @return array{status: int, payload: array<string, mixed>}
 */
function mimir_write_execute(?PDO $pdo, array $record, array $spec, int $now, ?callable $send = null): array
{
    mimir_write_authorize($record);
    $send ??= 'odata_write_request';
    $started = microtime(true);
    $logBase = [
        'logged_at' => $now,
        'company' => $spec['company'],
        'table' => $spec['table'],
        'method' => $spec['method'],
        'fields' => array_values(array_map('strval', array_keys($spec['data']))),
        'forced' => !empty($spec['forced']),
    ];
    $environment = '';
    $status = 0;
    $error = '';
    $bcCalled = false;
    try {
        [$environment, $metadata] = mimir_write_resolve($pdo, $spec['company'], $now);
        $auth = auth_get_auth_for_environment($environment);
        $prefix = mimir_odata_prefix_for_environment($environment);
        try {
            $schema = mimir_schema_for_set($metadata, $spec['table']);
        } catch (RuntimeException) {
            throw new MimirWriteException('Onbekende tabel in ' . $environment . ': ' . $spec['table'], 404, 'unknown_table');
        }
        $data = mimir_write_validate_fields($spec['data'], $schema);
        $headers = [];
        if ($spec['method'] === 'POST') {
            $url = mimir_collection_url($prefix, $spec['company'], $schema['name']);
        } else {
            $url = mimir_entity_key_url($prefix, $spec['company'], $schema['name'], mimir_write_key_predicate($spec['key'], $schema));
            $headers[] = 'If-Match: ' . $spec['etag'];
        }
        if ($spec['method'] !== 'DELETE') {
            $headers[] = 'Prefer: return=representation';
        }
        $json = null;
        if ($spec['method'] !== 'DELETE') {
            $json = json_encode((object) $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            if ($json === false) {
                throw new MimirWriteException('data kon niet als JSON worden verstuurd.', 400, 'invalid_body');
            }
        }

        $response = mimir_bc_with_slot($environment, static function (int $waitMs) use ($send, $spec, $url, $auth, $json, $headers, &$bcCalled): array {
            $bcCalled = true;
            $result = $send($spec['method'], $url, $auth, $json, $headers);
            $result['queue_wait_ms'] = $waitMs;

            return $result;
        });
        $status = (int) ($response['code'] ?? 0);
        $raw = (string) ($response['raw'] ?? '');
        if ($status < 200 || $status >= 300) {
            $bcError = mimir_write_bc_error($raw);
            $error = $bcError['message'];
            throw new MimirWriteException(
                'Business Central weigerde de write (HTTP ' . $status . ')' . ($bcError['message'] !== '' ? ': ' . $bcError['message'] : '.'),
                $status >= 400 && $status < 500 ? $status : 502,
                'bc_error',
                ['bc_status' => $status, 'bc_error' => $bcError, 'environment' => $environment]
            );
        }

        $invalidated = mimir_write_invalidate($pdo, $environment, $spec['company'], $schema['name'], $now);
        $value = null;
        if (trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            $value = is_array($decoded) ? mimir_strip_odata_noise($decoded) : null;
        }
        $etag = '';
        if (is_array($decoded ?? null) && isset($decoded['@odata.etag'])) {
            $etag = (string) $decoded['@odata.etag'];
        } elseif (isset($response['headers']['etag'])) {
            $etag = (string) $response['headers']['etag'];
        }

        return [
            'status' => $status === 204 ? 200 : $status,
            'payload' => [
                'ok' => true,
                'method' => $spec['method'],
                'company' => $spec['company'],
                'table' => $schema['name'],
                'environment' => $environment,
                'bc_status' => $status,
                'etag' => $etag !== '' ? $etag : null,
                'value' => $value,
                'forced' => !empty($spec['forced']),
                'meta' => [
                    'source' => 'bc-live',
                    'cache_invalidated' => $invalidated,
                    'queue_wait_ms' => (int) ($response['queue_wait_ms'] ?? 0),
                ],
            ],
        ];
    } catch (MimirWriteTimeoutException $timeout) {
        $status = 504;
        $error = 'timeout';
        mimir_event_log('write', 'Time-out bij ' . $spec['method'] . ' (niet opnieuw geprobeerd)', $environment, $spec['table'], 'failed');
        throw new MimirWriteException(
            'Business Central antwoordde niet op tijd. De write is NIET opnieuw geprobeerd; controleer in BC of hij toch is uitgevoerd voordat je hem opnieuw stuurt.',
            504,
            'bc_timeout',
            ['environment' => $environment]
        );
    } catch (MimirWriteException $known) {
        if ($status === 0) {
            $status = $known->status;
        }
        $error = $error !== '' ? $error : $known->errorCode;
        throw $known;
    } catch (MimirUserException $user) {
        $status = $user->status;
        $error = $user->getMessage();
        throw new MimirWriteException($user->getMessage(), $user->status, $user->status === 503 ? 'bc_busy' : ($user->status === 404 ? 'unknown_company' : 'invalid_request'));
    } catch (Throwable $other) {
        $status = 502;
        $error = mimir_public_error($other);
        mimir_event_log('write', $other->getMessage(), $environment, $spec['table'], 'bc-failed');
        throw new MimirWriteException('Write naar Business Central mislukt: ' . mimir_event_redact(mimir_public_error($other)), 502, 'bc_unreachable', ['environment' => $environment]);
    } finally {
        // Bijtaken: best-effort, nooit blokkerend; mislukt -> pending-wachtrij.
        try {
            mimir_write_log($pdo, $logBase + [
                'environment' => $environment,
                'status' => $status,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'bc_called' => $bcCalled,
                'error' => $error !== '' ? substr($error, 0, 300) : null,
            ]);
        } catch (Throwable) {
        }
        if ($bcCalled) {
            try {
                mimir_write_record_usage($pdo, (int) $record['id'], $now);
            } catch (Throwable) {
            }
        }
    }
}

/**
 * Environment en metadata voor het bedrijf. Faalt SQLite hier, dan dezelfde
 * route als bij een open circuit (bedrijfskaart- en metadata-snapshot, of live
 * $metadata), zodat de write niet op de cache strandt.
 *
 * @return array{0: string, 1: array<string, mixed>}
 */
function mimir_write_resolve(?PDO $pdo, string $company, int $now): array
{
    if ($pdo instanceof PDO) {
        try {
            $environment = mimir_environment_for_company($pdo, $company, $now);

            return [$environment, mimir_metadata_for_environment($pdo, $environment, $now)];
        } catch (MimirUserException $error) {
            throw $error;
        } catch (Throwable $error) {
            mimir_event_log('write', 'SQLite bij write niet bruikbaar, verder via snapshot/live: ' . $error->getMessage(), '', '', 'bypassed-to-BC');
        }
    }
    $GLOBALS['mimir_live_bypass'] = true;
    try {
        mimir_prepare_live_globals();
    } catch (Throwable) {
    }
    $memory = mimir_db(':memory:');
    $environment = mimir_environment_for_company($memory, $company, $now);

    return [$environment, mimir_metadata_for_environment($memory, $environment, $now)];
}

/**
 * Cache van die tabel in dat bedrijf ongeldig. Lukt SQLite niet, dan komt het
 * in een pending-bestand dat bij de volgende gezonde open wordt toegepast.
 */
function mimir_write_invalidate(?PDO $pdo, string $environment, string $company, string $entity, int $now): bool
{
    try {
        if ($pdo instanceof PDO && !mimir_write_side_task_failing('invalidate')) {
            try {
                mimir_cache_invalidate_entity($pdo, $environment, $company, $entity);

                return true;
            } catch (Throwable $error) {
                mimir_event_log('write', 'Cache-invalidatie mislukt (pending): ' . $error->getMessage(), $environment, $entity, 'failed');
            }
        }
        mimir_pending_add('invalidate', ['environment' => $environment, 'company' => $company, 'entity' => $entity, 'at' => $now]);
    } catch (Throwable) {
    }

    return false;
}

/**
 * Volledige afhandeling zonder exit, zodat de tests hem kunnen aanroepen.
 *
 * @param array<string, mixed> $query
 * @return array{status: int, payload: array<string, mixed>}
 */
function mimir_write_handle(string $method, string $apiKey, array $query, ?string $rawBody, string $ifMatch, ?callable $send = null, ?PDO $pdo = null): array
{
    try {
        if ($apiKey === '') {
            throw new MimirWriteException('API-sleutel ontbreekt. Gebruik Authorization: Bearer of de header X-API-Key. Schrijven kan niet met de ingelogde sessie.', 401, 'unauthorized');
        }
        mimir_caller_reset();
        mimir_caller_bind_api_key(null, $apiKey);
        $record = null;
        try {
            if ($pdo instanceof PDO) {
                $record = mimir_key_lookup($pdo, $apiKey);
            } else {
                $record = mimir_authenticate_api_key($apiKey, $pdo);
            }
        } catch (Throwable) {
            // SQLite weg: schrijfrecht via de sleutelspiegel, zoals bij een open circuit.
            $pdo = null;
            $record = mimir_key_mirror_lookup($apiKey);
        }
        if ($record === null && !$pdo instanceof PDO) {
            throw new MimirWriteException('Database niet beschikbaar en de API-sleutel staat niet in de sleutelspiegel.', 503, 'unavailable');
        }
        if (is_array($record)) {
            mimir_caller_bind_api_key($record, $apiKey);
        }
        mimir_write_authorize($record);
        $spec = mimir_write_parse_request($method, $query, mimir_write_decode_body($rawBody), $ifMatch);

        return mimir_write_execute($pdo, $record, $spec, time(), $send);
    } catch (MimirWriteException $error) {
        return ['status' => $error->status, 'payload' => ['error' => $error->getMessage(), 'code' => $error->errorCode] + $error->extra];
    } catch (Throwable $error) {
        return ['status' => 500, 'payload' => ['error' => mimir_public_error($error), 'code' => 'internal_error']];
    } finally {
        $GLOBALS['mimir_live_bypass'] = false;
    }
}

function mimir_write_api_main(): void
{
    @set_time_limit(180);
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, MIMIR_WRITE_METHODS, true)) {
        header('Allow: POST, PATCH, DELETE');
        mimir_json(['error' => 'Gebruik POST (insert), PATCH (update) of DELETE.', 'code' => 'method_not_allowed'], 405);
    }
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > MIMIR_WRITE_MAX_BODY_BYTES) {
        mimir_json(['error' => 'JSON-body is groter dan ' . MIMIR_WRITE_MAX_BODY_BYTES . ' bytes.', 'code' => 'body_too_large'], 413);
    }
    $raw = file_get_contents('php://input', false, null, 0, MIMIR_WRITE_MAX_BODY_BYTES + 1);
    $apiKey = mimir_request_api_key();
    if ($apiKey !== '') {
        mimir_load_auth(true);
    }
    $result = mimir_write_handle(
        $method,
        $apiKey,
        $_GET,
        $raw === false ? null : $raw,
        trim((string) ($_SERVER['HTTP_IF_MATCH'] ?? ''))
    );
    mimir_json($result['payload'], $result['status']);
}

/**
 * Query-parameters voor het schrijflog: limit (max 200), since (unix of
 * ISO-datum/tijd, Europe/Amsterdam), before_id (paginering), table, company.
 *
 * @param array<string, mixed> $get
 * @return array{limit: int, since: int, before_id: int, table: string, company: string}
 */
function mimir_write_log_options(array $get): array
{
    $since = trim((string) ($get['since'] ?? ''));
    $sinceUnix = 0;
    if ($since !== '') {
        if (ctype_digit($since)) {
            $sinceUnix = (int) $since;
        } else {
            try {
                $sinceUnix = (new DateTimeImmutable($since, new DateTimeZone('Europe/Amsterdam')))->getTimestamp();
            } catch (Throwable) {
                throw new MimirWriteException('since is ongeldig (unix-tijd of ISO-datum).', 400, 'invalid_request');
            }
        }
    }
    $table = trim((string) ($get['table'] ?? ''));
    if ($table !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,127}$/', $table) !== 1) {
        throw new MimirWriteException('Tabelnaam is ongeldig.', 400, 'invalid_table');
    }

    return [
        'limit' => max(1, min(MIMIR_WRITE_LOG_MAX_LIMIT, (int) ($get['limit'] ?? MIMIR_WRITE_LOG_DEFAULT_LIMIT) ?: MIMIR_WRITE_LOG_DEFAULT_LIMIT)),
        'since' => $sinceUnix,
        'before_id' => max(0, (int) ($get['before_id'] ?? 0)),
        'table' => $table,
        'company' => substr(trim((string) ($get['company'] ?? '')), 0, 120),
    ];
}

/**
 * Fallback zonder SQLite: het jsonl-bestand (en .1) filteren op key_id.
 * Alleen bij een open circuit; normaal gaat alles via de index in SQLite.
 *
 * @param array{limit: int, since: int, before_id: int, table: string, company: string} $options
 * @return array{value: list<array<string, mixed>>, next_before_id: ?int, source: string}
 */
function mimir_write_log_from_file(int $keyId, array $options): array
{
    $rows = [];
    $path = mimir_write_log_path();
    foreach ([$path . '.1', $path] as $file) {
        $raw = is_file($file) ? @file_get_contents($file) : false;
        if (!is_string($raw)) {
            continue;
        }
        foreach (explode("\n", $raw) as $line) {
            $entry = json_decode(trim($line), true);
            if (!is_array($entry) || (int) ($entry['key_id'] ?? 0) !== $keyId) {
                continue;
            }
            $at = (int) ($entry['logged_at'] ?? 0);
            if ($options['since'] > 0 && $at < $options['since']) {
                continue;
            }
            if ($options['table'] !== '' && strcasecmp((string) ($entry['table'] ?? ''), $options['table']) !== 0) {
                continue;
            }
            if ($options['company'] !== '' && strcasecmp((string) ($entry['company'] ?? ''), $options['company']) !== 0) {
                continue;
            }
            $rows[] = mimir_write_log_public_row([
                'id' => 0,
                'logged_at' => $at,
                'method' => $entry['method'] ?? '',
                'company' => $entry['company'] ?? '',
                'environment' => $entry['environment'] ?? '',
                'table_name' => $entry['table'] ?? '',
                'status' => $entry['status'] ?? 0,
                'duration_ms' => $entry['duration_ms'] ?? 0,
                'fields' => json_encode($entry['fields'] ?? []),
                'forced' => !empty($entry['forced']) ? 1 : 0,
                'bc_called' => !empty($entry['bc_called']) ? 1 : 0,
                'error' => $entry['error'] ?? null,
            ]);
        }
    }
    $rows = array_reverse($rows);

    return ['value' => array_slice($rows, 0, $options['limit']), 'next_before_id' => null, 'source' => 'file'];
}

/**
 * api/write_log.php: een API-sleutel leest alleen zijn eigen writes.
 *
 * @param array<string, mixed> $get
 * @return array{status: int, payload: array<string, mixed>}
 */
function mimir_write_log_api_handle(string $method, string $apiKey, array $get, ?PDO $pdo = null): array
{
    try {
        if (strtoupper($method) !== 'GET') {
            throw new MimirWriteException('GET verwacht.', 405, 'method_not_allowed');
        }
        if ($apiKey === '') {
            throw new MimirWriteException('API-sleutel ontbreekt. Gebruik Authorization: Bearer of de header X-API-Key.', 401, 'unauthorized');
        }
        mimir_caller_reset();
        mimir_caller_bind_api_key(null, $apiKey);
        try {
            $record = $pdo instanceof PDO ? mimir_key_lookup($pdo, $apiKey) : mimir_authenticate_api_key($apiKey, $pdo);
        } catch (Throwable) {
            $pdo = null;
            $record = mimir_key_mirror_lookup($apiKey);
        }
        if (!is_array($record)) {
            throw new MimirWriteException('API-sleutel is ongeldig of ingetrokken.', 401, 'unauthorized');
        }
        mimir_caller_bind_api_key($record, $apiKey);
        $options = mimir_write_log_options($get);
        $keyId = (int) $record['id'];
        $result = null;
        if ($pdo instanceof PDO) {
            try {
                $result = mimir_write_log_list($pdo, $keyId, $options) + ['source' => 'sqlite'];
                mimir_usage_log_best_effort($pdo, $keyId, 'write_log', time());
            } catch (Throwable) {
                $result = null;
            }
        }
        $result ??= mimir_write_log_from_file($keyId, $options);

        return ['status' => 200, 'payload' => $result + ['key_id' => $keyId, 'limit' => $options['limit']]];
    } catch (MimirWriteException $error) {
        return ['status' => $error->status, 'payload' => ['error' => $error->getMessage(), 'code' => $error->errorCode]];
    } catch (Throwable $error) {
        return ['status' => 500, 'payload' => ['error' => mimir_public_error($error), 'code' => 'internal_error']];
    }
}

function mimir_write_log_api_main(): void
{
    $apiKey = mimir_request_api_key();
    if ($apiKey !== '') {
        mimir_load_auth(true);
    }
    $result = mimir_write_log_api_handle((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), $apiKey, $_GET);
    mimir_json($result['payload'], $result['status']);
}
