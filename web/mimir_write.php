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
 * @return array{method: string, company: string, table: string, key: array<string, mixed>, etag: string, data: array<string, mixed>}
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
 * Eén regel per write. Geen veldwaarden, alleen de namen.
 *
 * @param array<string, mixed> $entry
 */
function mimir_write_log(array $entry): void
{
    $caller = $GLOBALS['mimir_caller'] ?? [];
    $line = [
        'ts' => mimir_event_timestamp(),
        'key_id' => (int) ($caller['key_id'] ?? 0),
        'label' => (string) ($caller['label'] ?? ''),
        'owner' => (string) ($caller['owner'] ?? ''),
    ] + $entry;
    $json = json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }
    $path = mimir_write_log_path();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        return;
    }
    if (is_file($path) && (int) @filesize($path) > MIMIR_WRITE_LOG_MAX_BYTES) {
        $rotated = $path . '.1';
        @unlink($rotated);
        @rename($path, $rotated);
    }
    @file_put_contents($path, $json . "\n", FILE_APPEND | LOCK_EX);
    @chmod($path, 0666);
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
        'company' => $spec['company'],
        'table' => $spec['table'],
        'method' => $spec['method'],
        'fields' => array_values(array_map('strval', array_keys($spec['data']))),
    ];
    $environment = '';
    $status = 0;
    $error = '';
    $bcCalled = false;
    try {
        $metaPdo = $pdo instanceof PDO ? $pdo : mimir_db(':memory:');
        $environment = mimir_environment_for_company($metaPdo, $spec['company'], $now);
        $auth = auth_get_auth_for_environment($environment);
        $prefix = mimir_odata_prefix_for_environment($environment);
        $metadata = mimir_metadata_for_environment($metaPdo, $environment, $now);
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
        mimir_write_log($logBase + [
            'environment' => $environment,
            'status' => $status,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'bc_called' => $bcCalled,
            'error' => $error !== '' ? substr($error, 0, 300) : null,
        ]);
        if ($bcCalled && $pdo instanceof PDO) {
            try {
                mimir_usage_log($pdo, (int) $record['id'], 'write', $now, 0, 1, 0, 0, MIMIR_USAGE_KIND_WRITE);
            } catch (Throwable) {
            }
        }
    }
}

/**
 * Cache van die tabel in dat bedrijf ongeldig. Lukt SQLite niet, dan komt het
 * in een pending-bestand dat bij de volgende gezonde open wordt toegepast.
 */
function mimir_write_invalidate(?PDO $pdo, string $environment, string $company, string $entity, int $now): bool
{
    if ($pdo instanceof PDO) {
        try {
            mimir_cache_invalidate_entity($pdo, $environment, $company, $entity);

            return true;
        } catch (Throwable $error) {
            mimir_event_log('write', 'Cache-invalidatie mislukt: ' . $error->getMessage(), $environment, $entity, 'failed');
        }
    }
    mimir_cache_invalidation_pending_add($environment, $company, $entity, $now);

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
        if ($pdo instanceof PDO) {
            $record = mimir_key_lookup($pdo, $apiKey);
        } else {
            $record = mimir_authenticate_api_key($apiKey, $pdo);
            if ($record === null && !$pdo instanceof PDO) {
                throw new MimirWriteException('Database niet beschikbaar en de API-sleutel kan niet worden gecontroleerd.', 503, 'unavailable');
            }
        }
        if (is_array($record)) {
            mimir_caller_bind_api_key($record, $apiKey);
        }
        mimir_write_authorize($record);
        $spec = mimir_write_parse_request($method, $query, mimir_write_decode_body($rawBody), $ifMatch);
        if (!$pdo instanceof PDO) {
            $GLOBALS['mimir_live_bypass'] = true;
            mimir_prepare_live_globals();
        }

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
