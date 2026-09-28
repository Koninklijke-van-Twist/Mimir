<?php

declare(strict_types=1);

require_once __DIR__ . '/mimir_auth.php';
require_once __DIR__ . '/odata.php';
require_once __DIR__ . '/mimir_store.php';
require_once __DIR__ . '/mimir_bc_limit.php';
require_once __DIR__ . '/auth_helper.php';

function mimir_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mimir_request_api_key(): string
{
    $header = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strtolower((string) $name) === 'authorization') {
                $header = trim((string) $value);
                break;
            }
        }
    }
    if (preg_match('/^Bearer\s+(\S+)/i', $header, $match) === 1) {
        return $match[1];
    }

    $apiKey = trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
    if ($apiKey === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strtolower((string) $name) === 'x-api-key') {
                return trim((string) $value);
            }
        }
    }

    return $apiKey;
}

function mimir_route_from_request(): string
{
    $path = trim((string) ($_SERVER['PATH_INFO'] ?? ''), '/');
    if ($path === '') {
        $path = trim((string) ($_GET['route'] ?? ''), '/');
    }
    return $path;
}

/**
 * @return array{action: string, table?: string}
 */
function mimir_parse_route(string $route): array
{
    $route = trim($route, '/');
    if ($route === 'tables') {
        return ['action' => 'tables'];
    }
    if (preg_match('#^tables/([^/]+)/schema$#', $route, $match) === 1) {
        return ['action' => 'schema', 'table' => rawurldecode($match[1])];
    }
    if ($route === 'query') {
        return ['action' => 'query'];
    }
    if ($route === 'companies') {
        return ['action' => 'companies'];
    }
    return ['action' => 'unknown'];
}

function mimir_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        throw new MimirUserException('JSON-body ontbreekt.');
    }
    if (strlen($raw) > 1000000) {
        throw new MimirUserException('JSON-body is te groot.', 413);
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new MimirUserException('JSON-body is ongeldig.');
    }
    return $decoded;
}

function mimir_public_error(Throwable $error): string
{
    $message = $error->getMessage();
    if (strlen($message) > 400) {
        return substr($message, 0, 400) . '…';
    }
    return $message;
}

/**
 * @param array<string, string> $map
 * @return list<array{name: string, environment: string}>
 */
function mimir_companies_from_map(array $map): array
{
    $companies = [];
    foreach ($map as $name => $environment) {
        $companyName = trim((string) $name);
        $environmentName = trim((string) $environment);
        if ($companyName === '' || $environmentName === '') {
            continue;
        }
        $companies[] = [
            'name' => $companyName,
            'environment' => $environmentName,
        ];
    }
    usort($companies, static function (array $left, array $right): int {
        return strcasecmp($left['name'], $right['name']);
    });
    return $companies;
}

/**
 * Bedrijven over alle actieve environments. De map wordt alleen gecachet
 * als elk environment antwoordde, zodat een tijdelijke fout Germany niet een dag verstopt.
 *
 * @return array{companies: list<array{name: string, environment: string}>, errors: list<string>}
 */
function mimir_company_cache_key(): string
{
    return 'company-map:' . auth_get_environment_key_fragment();
}

/**
 * Haalt bedrijven op uit BC en schrijft de nightly-cache.
 * Geroepen vanuit nightly.php (niet vanuit de UI).
 *
 * @return array{companies: list<array{name: string, environment: string}>, map: array<string, string>, errors: list<string>, fetched_at: int}
 */
function mimir_refresh_companies(PDO $pdo, int $now): array
{
    $discovered = auth_discover_companies_across_active_environments();
    $map = is_array($discovered['map'] ?? null) ? $discovered['map'] : [];
    $errors = is_array($discovered['errors'] ?? null) ? array_values($discovered['errors']) : [];
    $byEnvironment = is_array($discovered['by_environment'] ?? null) ? $discovered['by_environment'] : [];
    $GLOBALS['demeter_company_environment_map'] = $map;
    $GLOBALS['demeter_company_environment_errors'] = $errors;
    $GLOBALS['demeter_companies_by_environment'] = $byEnvironment;

    $payload = [
        'map' => $map,
        'errors' => $errors,
        'by_environment' => $byEnvironment,
        'active_environments' => auth_get_active_environments(),
        'source' => 'nightly',
    ];
    // Ook partial resultaat bewaren: UI moet een dropdown houden.
    if ($map !== []) {
        mimir_meta_put($pdo, mimir_company_cache_key(), $payload, $now);
        mimir_company_map_remember($map);
    }

    return [
        'companies' => mimir_companies_from_map($map),
        'map' => $map,
        'errors' => $errors,
        'fetched_at' => $now,
    ];
}

/**
 * Leest de company-dropdown uit de nightly-cache.
 * Live BC alleen als $allowLive true is (nightly / expliciete refresh).
 *
 * @return array{companies: list<array{name: string, environment: string}>, errors: list<string>, fetched_at: int|null, source: string}
 */
function mimir_company_catalog(PDO $pdo, int $now, bool $allowLive = false): array
{
    $current = $GLOBALS['demeter_company_environment_map'] ?? null;
    if (is_array($current) && $current !== []) {
        mimir_company_map_remember($current);
        $errors = $GLOBALS['demeter_company_environment_errors'] ?? [];
        return [
            'companies' => mimir_companies_from_map($current),
            'errors' => is_array($errors) ? array_values($errors) : [],
            'fetched_at' => isset($GLOBALS['demeter_company_fetched_at']) ? (int) $GLOBALS['demeter_company_fetched_at'] : null,
            'source' => 'memory',
        ];
    }

    $cacheKey = mimir_company_cache_key();
    // Stale nightly-lijst blijft bruikbaar tot de volgende nightly.
    $cached = mimir_meta_get_raw($pdo, $cacheKey);
    if (is_array($cached) && isset($cached['map']) && is_array($cached['map']) && $cached['map'] !== []) {
        $map = $cached['map'];
        $errors = is_array($cached['errors'] ?? null) ? array_values($cached['errors']) : [];
        $fetchedAt = (int) ($cached['_fetched_at'] ?? 0);
        $GLOBALS['demeter_company_environment_map'] = $map;
        $GLOBALS['demeter_company_environment_errors'] = $errors;
        $GLOBALS['demeter_company_fetched_at'] = $fetchedAt;
        if (isset($cached['by_environment']) && is_array($cached['by_environment'])) {
            $GLOBALS['demeter_companies_by_environment'] = $cached['by_environment'];
        }
        mimir_company_map_remember($map);
        return [
            'companies' => mimir_companies_from_map($map),
            'errors' => $errors,
            'fetched_at' => $fetchedAt > 0 ? $fetchedAt : null,
            'source' => 'nightly',
        ];
    }

    if (!$allowLive) {
        return [
            'companies' => [],
            'errors' => ['Geen bedrijfscache. Draai eerst nightly.php om bedrijven te ontdekken.'],
            'fetched_at' => null,
            'source' => 'empty',
        ];
    }

    $refreshed = mimir_refresh_companies($pdo, $now);
    return [
        'companies' => $refreshed['companies'],
        'errors' => $refreshed['errors'],
        'fetched_at' => $refreshed['fetched_at'],
        'source' => 'live',
    ];
}

function mimir_environment_for_company(PDO $pdo, string $company, int $now): string
{
    mimir_company_catalog($pdo, $now);
    try {
        return auth_get_environment_for_company($company);
    } catch (RuntimeException $error) {
        throw new MimirUserException($error->getMessage(), 404);
    }
}

/**
 * @return array{entity_sets: list<array{name: string, entity_type: string}>, types: array<string, array{keys: list<string>, properties: array<string, string>}>}
 */
function mimir_metadata_for_environment(PDO $pdo, string $environment, int $now): array
{
    $prefix = mimir_odata_prefix_for_environment($environment);
    $cacheKey = 'metadata:' . $prefix;
    $cached = mimir_meta_get($pdo, $cacheKey, MIMIR_METADATA_TTL, $now);
    if (is_array($cached) && isset($cached['entity_sets'], $cached['types']) && is_array($cached['entity_sets']) && is_array($cached['types'])) {
        return $cached;
    }
    if (!empty($GLOBALS['mimir_live_bypass'])) {
        $snapshot = mimir_metadata_snapshot_read($environment, MIMIR_METADATA_SNAPSHOT_TTL);
        if (is_array($snapshot)) {
            return $snapshot;
        }
    }

    $auth = auth_get_auth_for_environment($environment);
    return mimir_bc_with_slot($environment, static function () use ($pdo, $prefix, $auth, $cacheKey, $now, $environment): array {
        $xml = odata_get_text(rtrim($prefix, '/') . '/$metadata', $auth);
        $parsed = odata_parse_metadata($xml);
        mimir_metadata_snapshot_write($environment, $parsed, $now);
        try {
            mimir_meta_put($pdo, $cacheKey, $parsed, $now);
        } catch (Throwable $error) {
            if (!mimir_is_storage_failure($error)) {
                throw $error;
            }
            $GLOBALS['mimir_stamp_bc_live'] = true;
            mimir_circuit_trip($error->getMessage());
            mimir_event_log('sqlite', $error->getMessage(), $environment, '', 'bypassed-to-BC');
        }
        return $parsed;
    });
}

/**
 * @param array<string, mixed> $spec
 * @return array{value: list<array<string, mixed>>, meta: array<string, mixed>}
 */
function mimir_run_table_query(PDO $pdo, array $spec, int $now, ?int $forceMaxAge = null, ?int $keyId = null): array
{
    $company = trim((string) ($spec['company'] ?? ''));
    $table = trim((string) ($spec['table'] ?? $spec['entity'] ?? ''));
    if ($company === '' || $table === '') {
        throw new MimirUserException('company en table zijn verplicht.');
    }
    $environment = mimir_environment_for_company($pdo, $company, $now);
    $auth = auth_get_auth_for_environment($environment);
    $prefix = mimir_odata_prefix_for_environment($environment);
    $metadata = mimir_metadata_for_environment($pdo, $environment, $now);
    try {
        $schema = mimir_schema_for_set($metadata, $table);
    } catch (RuntimeException $error) {
        throw new MimirUserException($error->getMessage(), 404);
    }
    $fetch = static function (string $url) use ($auth): array {
        return odata_get_json($url, $auth);
    };
    $maxAge = $forceMaxAge ?? ($spec['max_age'] ?? MIMIR_DEFAULT_MAX_AGE);

    return mimir_query_entity($pdo, [
        'environment' => $environment,
        'company' => $company,
        'entity' => $schema['name'],
        'service_prefix' => $prefix,
        'select' => is_array($spec['select'] ?? null) ? $spec['select'] : [],
        'filter' => $spec['filter'] ?? null,
        'max_age' => $maxAge,
        'top' => $spec['top'] ?? MIMIR_DEFAULT_TOP,
        'key_id' => $keyId,
        'schema' => [
            'keys' => $schema['keys'],
            'properties' => $schema['properties'],
        ],
    ], $fetch, $now);
}

/**
 * @param array<string, mixed> $body
 * @return array<string, mixed>
 */
function mimir_run_request_body(PDO $pdo, array $body, int $now, ?int $forceMaxAge = null, ?int $keyId = null): array
{
    if (array_key_exists('queries', $body)) {
        $queries = $body['queries'];
        if (!is_array($queries) || !array_is_list($queries)) {
            throw new MimirUserException('queries moet een lijst zijn.');
        }
        if ($queries === []) {
            throw new MimirUserException('queries is leeg.');
        }
        if (count($queries) > 8) {
            throw new MimirUserException('Maximaal 8 tabellen per verzoek.');
        }
        $results = [];
        foreach ($queries as $index => $query) {
            if (!is_array($query)) {
                throw new MimirUserException('Elke query moet een object zijn.');
            }
            $name = trim((string) ($query['name'] ?? ('q' . $index)));
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
                throw new MimirUserException('Querynaam is ongeldig: ' . $name);
            }
            if (isset($results[$name])) {
                throw new MimirUserException('Querynaam komt dubbel voor: ' . $name);
            }
            if (!isset($query['company']) && isset($body['company'])) {
                $query['company'] = $body['company'];
            }
            if (!array_key_exists('max_age', $query) && array_key_exists('max_age', $body)) {
                $query['max_age'] = $body['max_age'];
            }
            $results[$name] = mimir_run_table_query($pdo, $query, $now, $forceMaxAge, $keyId);
        }
        if (isset($body['combine'])) {
            $results = mimir_apply_combine($results, $body['combine']);
        }
        return ['results' => $results];
    }

    return mimir_run_table_query($pdo, $body, $now, $forceMaxAge, $keyId);
}

/**
 * v1: inner equijoin op één sleutel. Geen geneste BC-joins.
 *
 * @param array<string, array{value: list<array<string, mixed>>, meta: array<string, mixed>}> $results
 * @return array<string, array{value: list<array<string, mixed>>, meta: array<string, mixed>}>
 */
function mimir_apply_combine(array $results, mixed $combine): array
{
    if (!is_array($combine)) {
        throw new MimirUserException('combine moet een object zijn.');
    }
    $left = (string) ($combine['left'] ?? '');
    $right = (string) ($combine['right'] ?? '');
    $leftKey = (string) ($combine['left_key'] ?? '');
    $rightKey = (string) ($combine['right_key'] ?? '');
    $as = (string) ($combine['as'] ?? 'joined');
    if (!isset($results[$left], $results[$right])) {
        throw new MimirUserException('combine verwijst naar een onbekende query.');
    }
    if (!mimir_filter_field_ok($leftKey) || !mimir_filter_field_ok($rightKey)) {
        throw new MimirUserException('combine-sleutel is ongeldig.');
    }
    if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $as) !== 1) {
        throw new MimirUserException('Naam van het join-resultaat is ongeldig.');
    }
    $index = [];
    foreach ($results[$right]['value'] as $row) {
        if (!array_key_exists($rightKey, $row) || $row[$rightKey] === null) {
            continue;
        }
        $index[(string) $row[$rightKey]][] = $row;
    }
    $joined = [];
    foreach ($results[$left]['value'] as $row) {
        if (!array_key_exists($leftKey, $row) || $row[$leftKey] === null) {
            continue;
        }
        foreach ($index[(string) $row[$leftKey]] ?? [] as $other) {
            $merged = [];
            foreach ($row as $column => $value) {
                $merged[$left . '.' . $column] = $value;
            }
            foreach ($other as $column => $value) {
                $merged[$right . '.' . $column] = $value;
            }
            $joined[] = $merged;
        }
    }
    $results[$as] = [
        'value' => $joined,
        'meta' => [
            'from_cache' => 0,
            'from_live' => 0,
            'max_age' => null,
            'fetched_at_min' => null,
            'fetched_at_max' => null,
            'joined' => true,
            'join' => 'inner',
            'filter_note' => 'v1 equijoin op één sleutel, na het ophalen van beide tabellen. Geen join in Business Central.',
        ],
    ];
    return $results;
}

/**
 * @return list<array{name: string, entity_type: string}>
 */
/**
 * @return array{environment: string, tables: list<array{name: string, entity_type: string}>}
 */
function mimir_list_tables(PDO $pdo, string $company, string $query, int $now): array
{
    if (trim($company) === '') {
        throw new MimirUserException('company is verplicht. De tabellenlijst komt uit het environment van dat bedrijf.');
    }
    $environment = mimir_environment_for_company($pdo, $company, $now);
    $metadata = mimir_metadata_for_environment($pdo, $environment, $now);
    $needle = strtolower($query);
    $tables = [];
    foreach ($metadata['entity_sets'] as $set) {
        $name = (string) $set['name'];
        if ($needle !== '' && !str_contains(strtolower($name), $needle)) {
            continue;
        }
        $tables[] = [
            'name' => $name,
            'entity_type' => (string) ($set['entity_type'] ?? ''),
        ];
    }
    return ['environment' => $environment, 'tables' => $tables];
}

/**
 * @return array{name: string, entity_type: string, keys: list<string>, properties: list<array{name: string, type: string}>}
 */
function mimir_table_schema(PDO $pdo, string $company, string $table, int $now): array
{
    if (trim($company) === '') {
        throw new MimirUserException('company is verplicht. Het schema komt uit het environment van dat bedrijf.');
    }
    $environment = mimir_environment_for_company($pdo, $company, $now);
    $metadata = mimir_metadata_for_environment($pdo, $environment, $now);
    try {
        $schema = mimir_schema_for_set($metadata, $table);
    } catch (RuntimeException $error) {
        throw new MimirUserException($error->getMessage(), 404);
    }
    $properties = [];
    foreach ($schema['properties'] as $name => $type) {
        $properties[] = ['name' => (string) $name, 'type' => (string) $type];
    }
    return [
        'name' => $schema['name'],
        'entity_type' => $schema['entity_type'],
        'environment' => $environment,
        'keys' => $schema['keys'],
        'properties' => $properties,
    ];
}

function mimir_prepare_live_globals(): void
{
    if (mimir_company_map_restore()) {
        return;
    }
    if (!function_exists('auth_discover_companies_across_active_environments')) {
        return;
    }
    $discovered = auth_discover_companies_across_active_environments();
    $map = is_array($discovered['map'] ?? null) ? $discovered['map'] : [];
    $errors = is_array($discovered['errors'] ?? null) ? array_values($discovered['errors']) : [];
    $GLOBALS['demeter_company_environment_map'] = $map;
    $GLOBALS['demeter_company_environment_errors'] = $errors;
    if (isset($discovered['by_environment']) && is_array($discovered['by_environment'])) {
        $GLOBALS['demeter_companies_by_environment'] = $discovered['by_environment'];
    }
    mimir_company_map_remember($map);
}

/**
 * @param array<string, mixed> $record
 * @param array{action: string, table?: string} $parsed
 * @param array<string, mixed>|null $body
 * @return array<string, mixed>
 */
function mimir_api_cached_payload(PDO $pdo, array $record, array $parsed, int $now, ?array $body): array
{
    $keyId = (int) ($record['id'] ?? 0);
    if ($parsed['action'] === 'tables') {
        mimir_usage_log_best_effort($pdo, $keyId, $parsed['action'], $now);
        $listed = mimir_list_tables($pdo, trim((string) ($_GET['company'] ?? '')), trim((string) ($_GET['q'] ?? '')), $now);

        return ['value' => $listed['tables'], 'environment' => $listed['environment']];
    }
    if ($parsed['action'] === 'companies') {
        mimir_usage_log_best_effort($pdo, $keyId, $parsed['action'], $now);
        $catalog = mimir_company_catalog($pdo, $now, false);

        return [
            'value' => $catalog['companies'],
            'fetched_at' => $catalog['fetched_at'],
            'source' => $catalog['source'],
        ];
    }
    if ($parsed['action'] === 'schema') {
        mimir_usage_log_best_effort($pdo, $keyId, $parsed['action'], $now);

        return mimir_table_schema($pdo, trim((string) ($_GET['company'] ?? '')), (string) ($parsed['table'] ?? ''), $now);
    }
    if (!is_array($body)) {
        throw new MimirUserException('JSON-body ontbreekt.');
    }
    $result = mimir_run_request_body($pdo, $body, $now, null, $keyId);
    $flags = mimir_usage_flags_from_response($result);
    mimir_usage_log_best_effort(
        $pdo,
        $keyId,
        'query',
        $now,
        $flags['shared'],
        $flags['bc_hit'],
        $flags['from_cache'],
        $flags['from_live']
    );

    return $result;
}

/**
 * @param array{action: string, table?: string} $parsed
 * @param array<string, mixed>|null $body
 * @return array<string, mixed>
 */
function mimir_api_live_payload(array $parsed, int $now, ?array $body, ?int $forceMaxAge = null): array
{
    $GLOBALS['mimir_live_bypass'] = true;
    try {
        mimir_prepare_live_globals();
        $pdo = mimir_db(':memory:');
        if ($parsed['action'] === 'tables') {
            $listed = mimir_list_tables($pdo, trim((string) ($_GET['company'] ?? '')), trim((string) ($_GET['q'] ?? '')), $now);

            return mimir_stamp_bc_live(['value' => $listed['tables'], 'environment' => $listed['environment']]);
        }
        if ($parsed['action'] === 'companies') {
            $map = $GLOBALS['demeter_company_environment_map'] ?? [];
            if (!is_array($map)) {
                $map = [];
            }

            return [
                'value' => mimir_companies_from_map($map),
                'fetched_at' => $now,
                'source' => 'bc-live',
            ];
        }
        if ($parsed['action'] === 'schema') {
            $schema = mimir_table_schema($pdo, trim((string) ($_GET['company'] ?? '')), (string) ($parsed['table'] ?? ''), $now);
            $schema['source'] = 'bc-live';

            return $schema;
        }
        if (!is_array($body)) {
            throw new MimirUserException('JSON-body ontbreekt.');
        }

        return mimir_stamp_bc_live(mimir_run_request_body($pdo, $body, $now, $forceMaxAge, null));
    } finally {
        $GLOBALS['mimir_live_bypass'] = false;
    }
}

function mimir_usage_log_best_effort(
    PDO $pdo,
    int $keyId,
    string $endpoint,
    int $now,
    int $shared = 0,
    int $bcHit = 0,
    int $fromCache = 0,
    int $fromLive = 0
): void {
    if ($keyId < 1) {
        return;
    }
    try {
        mimir_usage_log($pdo, $keyId, $endpoint, $now, $shared, $bcHit, $fromCache, $fromLive);
    } catch (Throwable $error) {
        if (!mimir_is_storage_failure($error)) {
            throw $error;
        }
        mimir_circuit_trip($error->getMessage());
        mimir_event_log('sqlite', $error->getMessage(), '', $endpoint, 'bypassed-to-BC');
    }
}

/**
 * @return array{id: int, owner_email: string, label: string, key_plain: string}|null
 */
function mimir_authenticate_api_key(string $apiKey, ?PDO &$pdo): ?array
{
    $pdo = null;
    if (!mimir_circuit_should_bypass()) {
        try {
            $pdo = mimir_db(mimir_db_path());
        } catch (Throwable $error) {
            if (!mimir_is_storage_failure($error)) {
                throw $error;
            }
            mimir_db_self_repair(mimir_db_path());
            mimir_circuit_trip($error->getMessage());
            $pdo = null;
        }
    }
    if ($pdo instanceof PDO) {
        return mimir_key_lookup($pdo, $apiKey);
    }

    return mimir_key_mirror_lookup($apiKey);
}

function mimir_api_main(?string $forcedRoute = null): void
{
    @set_time_limit(300);
    $GLOBALS['mimir_stamp_bc_live'] = false;
    $apiKey = mimir_request_api_key();
    if ($apiKey === '') {
        mimir_json(['error' => 'API-sleutel ontbreekt. Gebruik Authorization: Bearer of de header X-API-Key.'], 401);
    }

    mimir_load_auth(true);

    $route = $forcedRoute ?? mimir_route_from_request();
    $parsed = mimir_parse_route($route);
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($parsed['action'] === 'unknown') {
        mimir_json(['error' => 'Onbekend endpoint. Gebruik tables, tables/{naam}/schema, query of companies.'], 404);
    }
    if ($parsed['action'] === 'tables' || $parsed['action'] === 'companies' || $parsed['action'] === 'schema') {
        if ($method !== 'GET') {
            mimir_json(['error' => 'GET verwacht.'], 405);
        }
    } elseif ($method !== 'POST') {
        mimir_json(['error' => 'POST verwacht.'], 405);
    }

    $body = null;
    if ($parsed['action'] === 'query') {
        try {
            $body = mimir_read_json_body();
        } catch (MimirUserException $error) {
            mimir_json(['error' => $error->getMessage()], $error->status);
        }
    }

    try {
        $pdo = null;
        $record = mimir_authenticate_api_key($apiKey, $pdo);
    } catch (Throwable $error) {
        mimir_event_log('request', $error->getMessage(), '', (string) ($parsed['table'] ?? ''), 'bc-failed');
        mimir_json(['error' => 'Database niet beschikbaar.'], 500);
    }
    if ($record === null) {
        if (!$pdo instanceof PDO) {
            mimir_json(['error' => 'Database niet beschikbaar en de API-sleutel kan niet worden gecontroleerd.'], 503);
        }
        mimir_json(['error' => 'API-sleutel is ongeldig of ingetrokken.'], 401);
    }

    $entity = '';
    if (is_array($body)) {
        $entity = trim((string) ($body['table'] ?? $body['entity'] ?? ''));
    }
    if ($entity === '' && isset($parsed['table'])) {
        $entity = (string) $parsed['table'];
    }
    $now = time();

    try {
        $payload = mimir_with_cache_or_live(
            static function () use ($pdo, $record, $parsed, $now, $body): array {
                if (!$pdo instanceof PDO) {
                    throw new RuntimeException('SQLite niet beschikbaar.');
                }

                return mimir_api_cached_payload($pdo, $record, $parsed, $now, $body);
            },
            static function () use ($parsed, $now, $body): array {
                return mimir_api_live_payload($parsed, $now, $body);
            },
            ['entity' => $entity, 'category' => 'request']
        );
        mimir_json($payload);
    } catch (MimirUserException $error) {
        mimir_json(['error' => $error->getMessage()], $error->status);
    } catch (Throwable $error) {
        mimir_json(['error' => mimir_public_error($error)], 502);
    }
}

function mimir_session_email(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $email = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new MimirUserException('Geen gebruiker in de sessie.', 401);
    }
    return $email;
}

function mimir_ui_open_db(): ?PDO
{
    if (mimir_circuit_should_bypass()) {
        return null;
    }
    try {
        return mimir_db(mimir_db_path());
    } catch (Throwable $error) {
        if (!mimir_is_storage_failure($error)) {
            throw $error;
        }
        mimir_db_self_repair(mimir_db_path());
        mimir_circuit_trip($error->getMessage());

        return null;
    }
}

/**
 * @param array<string, mixed>|null $body
 * @return array<string, mixed>
 */
function mimir_ui_cached_payload(PDO $pdo, string $action, string $email, int $now, ?array $body): array
{
    if ($action === 'keys') {
        return [
            'value' => mimir_key_list($pdo, $email, $now),
            'heatmap' => mimir_heatmap_options(),
            'shared_pct_global' => mimir_usage_shared_pct_global($pdo, $now),
        ];
    }
    if ($action === 'keys_create') {
        if (!is_array($body)) {
            throw new MimirUserException('JSON-body ontbreekt.');
        }

        return mimir_key_create($pdo, $email, (string) ($body['label'] ?? ''), $now);
    }
    if ($action === 'keys_revoke') {
        if (!is_array($body)) {
            throw new MimirUserException('JSON-body ontbreekt.');
        }
        $ok = mimir_key_revoke($pdo, (int) ($body['id'] ?? 0), $email, $now);
        if (!$ok) {
            throw new MimirUserException('Sleutel niet gevonden.', 404);
        }

        return ['ok' => true];
    }
    if ($action === 'companies') {
        $allowLive = in_array(strtolower(trim((string) ($_GET['live'] ?? ''))), ['1', 'true', 'yes'], true);
        $catalog = mimir_company_catalog($pdo, $now, $allowLive);

        return [
            'value' => $catalog['companies'],
            'errors' => $catalog['errors'],
            'fetched_at' => $catalog['fetched_at'],
            'source' => $catalog['source'],
        ];
    }
    if ($action === 'tables') {
        $listed = mimir_list_tables($pdo, trim((string) ($_GET['company'] ?? '')), trim((string) ($_GET['q'] ?? '')), $now);

        return ['value' => $listed['tables'], 'environment' => $listed['environment']];
    }
    if ($action === 'schema') {
        return mimir_table_schema($pdo, trim((string) ($_GET['company'] ?? '')), trim((string) ($_GET['table'] ?? '')), $now);
    }
    if ($action === 'query') {
        if (!is_array($body)) {
            throw new MimirUserException('JSON-body ontbreekt.');
        }

        return mimir_run_request_body($pdo, $body, $now, MIMIR_UI_MAX_AGE);
    }

    throw new MimirUserException('Onbekende actie.', 404);
}

/**
 * @param array<string, mixed>|null $body
 * @return array<string, mixed>
 */
function mimir_ui_live_payload(string $action, int $now, ?array $body): array
{
    $parsed = ['action' => $action];
    if ($action === 'schema') {
        $parsed['table'] = trim((string) ($_GET['table'] ?? ''));
    }
    if ($action === 'companies') {
        $payload = mimir_api_live_payload($parsed, $now, null);

        return [
            'value' => $payload['value'] ?? [],
            'errors' => [],
            'fetched_at' => $payload['fetched_at'] ?? $now,
            'source' => 'bc-live',
        ];
    }
    if ($action === 'tables' || $action === 'schema') {
        return mimir_api_live_payload($parsed, $now, null);
    }
    if ($action === 'query') {
        return mimir_api_live_payload($parsed, $now, $body, MIMIR_UI_MAX_AGE);
    }

    throw new MimirUserException('Onbekende actie.', 404);
}

function mimir_ui_main(): void
{
    @set_time_limit(300);
    $GLOBALS['mimir_stamp_bc_live'] = false;
    $action = trim((string) ($_GET['action'] ?? ''));
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $now = time();
    $keyActions = ['keys' => 'GET', 'keys_create' => 'POST', 'keys_revoke' => 'POST'];
    $dataActions = ['companies' => 'GET', 'tables' => 'GET', 'schema' => 'GET', 'query' => 'POST'];

    try {
        $email = mimir_session_email();
    } catch (MimirUserException $error) {
        mimir_json(['error' => $error->getMessage()], $error->status);
    }

    if (!isset($keyActions[$action]) && !isset($dataActions[$action])) {
        mimir_json(['error' => 'Onbekende actie.'], 404);
    }
    $expected = $keyActions[$action] ?? $dataActions[$action];
    if ($method !== $expected) {
        mimir_json(['error' => $expected . ' verwacht.'], 405);
    }

    $body = null;
    if ($method === 'POST') {
        try {
            $body = mimir_read_json_body();
        } catch (MimirUserException $error) {
            mimir_json(['error' => $error->getMessage()], $error->status);
        }
    }

    if (isset($dataActions[$action])) {
        mimir_load_auth(true);
    }

    try {
        $pdo = mimir_ui_open_db();
        if (isset($keyActions[$action])) {
            if (!$pdo instanceof PDO) {
                mimir_json(['error' => 'Database niet beschikbaar.'], 503);
            }
            $payload = mimir_ui_cached_payload($pdo, $action, $email, $now, $body);
            mimir_json($payload, $action === 'keys_create' ? 201 : 200);
        }
        $entity = trim((string) ($_GET['table'] ?? ''));
        if ($entity === '' && is_array($body)) {
            $entity = trim((string) ($body['table'] ?? $body['entity'] ?? ''));
        }
        $payload = mimir_with_cache_or_live(
            static function () use ($pdo, $action, $email, $now, $body): array {
                if (!$pdo instanceof PDO) {
                    throw new RuntimeException('SQLite niet beschikbaar.');
                }

                return mimir_ui_cached_payload($pdo, $action, $email, $now, $body);
            },
            static function () use ($action, $now, $body): array {
                return mimir_ui_live_payload($action, $now, $body);
            },
            ['entity' => $entity, 'category' => 'request']
        );
        mimir_json($payload);
    } catch (MimirUserException $error) {
        mimir_json(['error' => $error->getMessage()], $error->status);
    } catch (Throwable $error) {
        mimir_json(['error' => mimir_public_error($error)], 502);
    }
}
