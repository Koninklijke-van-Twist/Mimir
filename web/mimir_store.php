<?php

declare(strict_types=1);

require_once __DIR__ . '/mimir_filter.php';
require_once __DIR__ . '/odata.php';

const MIMIR_UI_MAX_AGE = 600;
const MIMIR_ODATA_PAGE_SIZE = 2000;
const MIMIR_DEFAULT_MAX_AGE = 3600;
const MIMIR_MAX_MAX_AGE = 31536000;
const MIMIR_DEFAULT_TOP = 100;
const MIMIR_MAX_TOP = 10000;
const MIMIR_METADATA_TTL = 3600;
const MIMIR_COMPANY_TTL = 86400;

class MimirUserException extends RuntimeException
{
    public function __construct(string $message, public int $status = 400)
    {
        parent::__construct($message);
    }
}

function mimir_db(string $path): PDO
{
    if ($path !== ':memory:') {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Datamap kon niet worden aangemaakt.');
        }
    }
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    if ($path !== ':memory:') {
        $pdo->exec('PRAGMA journal_mode = WAL');
    }
    mimir_migrate($pdo);
    return $pdo;
}

function mimir_db_path(): string
{
    return __DIR__ . '/data/mimir.sqlite';
}

function mimir_migrate(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS cache_rows (
            company TEXT NOT NULL,
            entity TEXT NOT NULL,
            row_key TEXT NOT NULL,
            payload TEXT NOT NULL,
            fetched_at INTEGER NOT NULL,
            PRIMARY KEY (company, entity, row_key)
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cache_rows_entity ON cache_rows(company, entity, fetched_at)');
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS cache_coverage (
            company TEXT NOT NULL,
            entity TEXT NOT NULL,
            filter_sig TEXT NOT NULL,
            select_sig TEXT NOT NULL,
            fetched_at INTEGER NOT NULL,
            row_count INTEGER NOT NULL,
            PRIMARY KEY (company, entity, filter_sig, select_sig)
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS meta_cache (
            cache_key TEXT PRIMARY KEY,
            payload TEXT NOT NULL,
            fetched_at INTEGER NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS api_keys (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_email TEXT NOT NULL,
            label TEXT NOT NULL,
            key_plain TEXT NOT NULL,
            key_hash TEXT NOT NULL UNIQUE,
            created_at INTEGER NOT NULL,
            revoked_at INTEGER
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS api_usage (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            key_id INTEGER NOT NULL,
            endpoint TEXT NOT NULL,
            called_at INTEGER NOT NULL
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_api_usage_key_time ON api_usage(key_id, called_at)');
}

function mimir_key_hash(string $key): string
{
    return hash('sha256', $key);
}

/**
 * @return array{id: int, label: string, key: string, created_at: int}
 */
function mimir_key_create(PDO $pdo, string $ownerEmail, string $label, int $now): array
{
    $ownerEmail = strtolower(trim($ownerEmail));
    $label = trim($label);
    if ($ownerEmail === '' || filter_var($ownerEmail, FILTER_VALIDATE_EMAIL) === false) {
        throw new MimirUserException('Eigenaar van de sleutel ontbreekt.');
    }
    if ($label === '' || strlen($label) > 80) {
        throw new MimirUserException('Label is verplicht en maximaal 80 tekens.');
    }
    $plain = 'mimir_' . bin2hex(random_bytes(24));
    $stmt = $pdo->prepare(
        'INSERT INTO api_keys (owner_email, label, key_plain, key_hash, created_at) VALUES (:owner, :label, :plain, :hash, :created)'
    );
    $stmt->execute([
        ':owner' => $ownerEmail,
        ':label' => $label,
        ':plain' => $plain,
        ':hash' => mimir_key_hash($plain),
        ':created' => $now,
    ]);

    return [
        'id' => (int) $pdo->lastInsertId(),
        'label' => $label,
        'key' => $plain,
        'created_at' => $now,
    ];
}

/**
 * Actieve sleutel opzoeken via SHA-256. De plaintext blijft in de tabel zodat
 * de eigenaar hem in de UI altijd terugziet.
 *
 * @return array{id: int, owner_email: string, label: string, key_plain: string}|null
 */
function mimir_key_lookup(PDO $pdo, string $plain): ?array
{
    $plain = trim($plain);
    if ($plain === '') {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT id, owner_email, label, key_plain FROM api_keys WHERE key_hash = :hash AND revoked_at IS NULL'
    );
    $stmt->execute([':hash' => mimir_key_hash($plain)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }
    if (!hash_equals((string) $row['key_plain'], $plain)) {
        return null;
    }

    return [
        'id' => (int) $row['id'],
        'owner_email' => (string) $row['owner_email'],
        'label' => (string) $row['label'],
        'key_plain' => (string) $row['key_plain'],
    ];
}

function mimir_key_revoke(PDO $pdo, int $id, string $ownerEmail, int $now): bool
{
    $stmt = $pdo->prepare(
        'UPDATE api_keys SET revoked_at = :now WHERE id = :id AND owner_email = :owner AND revoked_at IS NULL'
    );
    $stmt->execute([
        ':now' => $now,
        ':id' => $id,
        ':owner' => strtolower(trim($ownerEmail)),
    ]);
    return $stmt->rowCount() > 0;
}

/**
 * @return list<array{id: int, label: string, key: string, created_at: int, revoked_at: ?int, avg_per_day: float}>
 */
function mimir_key_list(PDO $pdo, string $ownerEmail, int $now): array
{
    $stmt = $pdo->prepare(
        'SELECT id, label, key_plain, created_at, revoked_at FROM api_keys WHERE owner_email = :owner ORDER BY id DESC'
    );
    $stmt->execute([':owner' => strtolower(trim($ownerEmail))]);
    $rows = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $id = (int) $row['id'];
        $rows[] = [
            'id' => $id,
            'label' => (string) $row['label'],
            'key' => (string) $row['key_plain'],
            'created_at' => (int) $row['created_at'],
            'revoked_at' => $row['revoked_at'] === null ? null : (int) $row['revoked_at'],
            'avg_per_day' => mimir_key_avg_per_day($pdo, $id, $now),
        ];
    }
    return $rows;
}

function mimir_key_avg_per_day(PDO $pdo, int $keyId, int $now): float
{
    $since = $now - (30 * 86400);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM api_usage WHERE key_id = :id AND called_at >= :since');
    $stmt->execute([':id' => $keyId, ':since' => $since]);
    $count = (int) $stmt->fetchColumn();
    return $count / 30;
}

function mimir_usage_log(PDO $pdo, int $keyId, string $endpoint, int $now): void
{
    $stmt = $pdo->prepare('INSERT INTO api_usage (key_id, endpoint, called_at) VALUES (:id, :endpoint, :at)');
    $stmt->execute([
        ':id' => $keyId,
        ':endpoint' => $endpoint,
        ':at' => $now,
    ]);
}

function mimir_meta_get(PDO $pdo, string $key, int $ttl, int $now): ?array
{
    $stmt = $pdo->prepare('SELECT payload, fetched_at FROM meta_cache WHERE cache_key = :key');
    $stmt->execute([':key' => $key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }
    if (($now - (int) $row['fetched_at']) > $ttl) {
        return null;
    }
    $decoded = json_decode((string) $row['payload'], true);
    return is_array($decoded) ? $decoded : null;
}

function mimir_meta_put(PDO $pdo, string $key, array $payload, int $now): void
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Metadata kon niet worden opgeslagen.');
    }
    $stmt = $pdo->prepare(
        'INSERT INTO meta_cache (cache_key, payload, fetched_at) VALUES (:key, :payload, :at)
         ON CONFLICT(cache_key) DO UPDATE SET payload = excluded.payload, fetched_at = excluded.fetched_at'
    );
    $stmt->execute([':key' => $key, ':payload' => $json, ':at' => $now]);
}

/**
 * @param array<string, mixed> $payload
 */
function mimir_cache_upsert(PDO $pdo, string $company, string $entity, string $rowKey, array $payload, int $fetchedAt): void
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Rij kon niet worden gecachet.');
    }
    $stmt = $pdo->prepare(
        'INSERT INTO cache_rows (company, entity, row_key, payload, fetched_at) VALUES (:company, :entity, :row_key, :payload, :at)
         ON CONFLICT(company, entity, row_key) DO UPDATE SET payload = excluded.payload, fetched_at = excluded.fetched_at'
    );
    $stmt->execute([
        ':company' => $company,
        ':entity' => $entity,
        ':row_key' => $rowKey,
        ':payload' => $json,
        ':at' => $fetchedAt,
    ]);
}

function mimir_cache_delete(PDO $pdo, string $company, string $entity, string $rowKey): void
{
    $stmt = $pdo->prepare('DELETE FROM cache_rows WHERE company = :company AND entity = :entity AND row_key = :row_key');
    $stmt->execute([':company' => $company, ':entity' => $entity, ':row_key' => $rowKey]);
}

/**
 * @return list<array{row_key: string, payload: array<string, mixed>, fetched_at: int}>
 */
function mimir_cache_all(PDO $pdo, string $company, string $entity): array
{
    $stmt = $pdo->prepare('SELECT row_key, payload, fetched_at FROM cache_rows WHERE company = :company AND entity = :entity');
    $stmt->execute([':company' => $company, ':entity' => $entity]);
    $rows = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $payload = json_decode((string) $row['payload'], true);
        $rows[] = [
            'row_key' => (string) $row['row_key'],
            'payload' => is_array($payload) ? $payload : [],
            'fetched_at' => (int) $row['fetched_at'],
        ];
    }
    return $rows;
}

function mimir_coverage_put(PDO $pdo, string $company, string $entity, string $filterSig, string $selectSig, int $fetchedAt, int $rowCount): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO cache_coverage (company, entity, filter_sig, select_sig, fetched_at, row_count)
         VALUES (:company, :entity, :filter_sig, :select_sig, :at, :count)
         ON CONFLICT(company, entity, filter_sig, select_sig) DO UPDATE SET fetched_at = excluded.fetched_at, row_count = excluded.row_count'
    );
    $stmt->execute([
        ':company' => $company,
        ':entity' => $entity,
        ':filter_sig' => $filterSig,
        ':select_sig' => $selectSig,
        ':at' => $fetchedAt,
        ':count' => $rowCount,
    ]);
}

/**
 * @return array{filter_sig: string, select_sig: string, fetched_at: int}|null
 */
function mimir_coverage_find(PDO $pdo, string $company, string $entity, ?string $pushedFilter, string $selectSig, int $maxAge, int $now): ?array
{
    $sql = 'SELECT filter_sig, select_sig, fetched_at FROM cache_coverage
            WHERE company = :company AND entity = :entity AND fetched_at >= :min_at';
    $params = [
        ':company' => $company,
        ':entity' => $entity,
        ':min_at' => $now - $maxAge,
    ];
    if ($pushedFilter === null) {
        $sql .= ' AND filter_sig = \'\'';
    } else {
        $sql .= ' AND (filter_sig = :filter OR filter_sig = \'\')';
        $params[':filter'] = $pushedFilter;
    }
    $sql .= ' ORDER BY fetched_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (mimir_select_sig_covers((string) $row['select_sig'], $selectSig)) {
            return [
                'filter_sig' => (string) $row['filter_sig'],
                'select_sig' => (string) $row['select_sig'],
                'fetched_at' => (int) $row['fetched_at'],
            ];
        }
    }
    return null;
}

/**
 * @param list<string> $select
 */
function mimir_select_sig(array $select): string
{
    if ($select === []) {
        return '*';
    }
    $cols = array_values(array_unique($select));
    sort($cols, SORT_STRING);
    return implode(',', $cols);
}

function mimir_select_sig_covers(string $stored, string $needed): bool
{
    if ($stored === '*') {
        return true;
    }
    if ($needed === '*') {
        return false;
    }
    $have = $stored === '' ? [] : explode(',', $stored);
    $need = $needed === '' ? [] : explode(',', $needed);
    return array_diff($need, $have) === [];
}

/**
 * @param list<string> $requiredColumns
 * @param array{payload: array<string, mixed>, fetched_at: int}|null $stored
 */
function mimir_row_refresh_reason(?array $stored, array $requiredColumns, int $maxAge, int $now): string
{
    if ($stored === null) {
        return 'missing';
    }
    if (($now - (int) $stored['fetched_at']) > $maxAge) {
        return 'stale';
    }
    foreach ($requiredColumns as $column) {
        if (!array_key_exists($column, $stored['payload'])) {
            return 'columns';
        }
    }
    return 'ok';
}

/**
 * @param list<string> $keys
 * @param array<string, mixed> $payload
 */
function mimir_row_key(array $payload, array $keys): string
{
    if ($keys === []) {
        $copy = $payload;
        unset($copy['@odata.etag']);
        ksort($copy);
        $json = json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return 'hash:' . hash('sha256', $json === false ? '' : $json);
    }
    $parts = [];
    foreach ($keys as $key) {
        $encoded = json_encode($payload[$key] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $parts[] = $key . '=' . ($encoded === false ? 'null' : $encoded);
    }
    return implode('|', $parts);
}

/**
 * @param list<string> $select
 * @return list<string>
 */
function mimir_normalize_select(array $select): array
{
    $out = [];
    foreach ($select as $column) {
        if (!is_string($column)) {
            throw new MimirUserException('select moet een lijst veldnamen zijn.');
        }
        $column = trim($column);
        if ($column === '') {
            continue;
        }
        if (!mimir_filter_field_ok($column)) {
            throw new MimirUserException('Kolomnaam is ongeldig: ' . $column);
        }
        $out[$column] = $column;
    }
    return array_values($out);
}

function mimir_entity_name_ok(string $entity): bool
{
    return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $entity);
}

/**
 * @param array<string, mixed> $row
 * @param list<string> $select
 * @return array<string, mixed>
 */
function mimir_project_row(array $row, array $select): array
{
    if ($select === []) {
        return $row;
    }
    $projected = [];
    foreach ($select as $column) {
        if (array_key_exists($column, $row)) {
            $projected[$column] = $row[$column];
        }
    }
    return $projected;
}

/**
 * @param array{
 *   company: string,
 *   entity: string,
 *   service_prefix: string,
 *   select?: list<string>,
 *   filter?: mixed,
 *   max_age?: int,
 *   top?: int,
 *   schema: array{keys?: list<string>, properties?: array<string, string>}
 * } $job
 * @param callable(string): array $fetch
 * @return array{value: list<array<string, mixed>>, meta: array<string, mixed>}
 */
function mimir_query_entity(PDO $pdo, array $job, callable $fetch, int $now): array
{
    $company = trim((string) ($job['company'] ?? ''));
    $entity = trim((string) ($job['entity'] ?? ''));
    $prefix = (string) ($job['service_prefix'] ?? '');
    if ($company === '') {
        throw new MimirUserException('company is verplicht.');
    }
    if (!mimir_entity_name_ok($entity)) {
        throw new MimirUserException('Tabelnaam is ongeldig.');
    }
    if ($prefix === '') {
        throw new MimirUserException('OData-service ontbreekt.');
    }
    $schema = $job['schema'] ?? [];
    $keys = array_values(array_filter(
        is_array($schema['keys'] ?? null) ? $schema['keys'] : [],
        static fn ($key): bool => is_string($key) && $key !== ''
    ));
    $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
    $types = [];
    foreach ($properties as $name => $type) {
        if (is_string($name)) {
            $types[$name] = is_string($type) ? $type : 'Edm.String';
        }
    }
    $select = mimir_normalize_select(is_array($job['select'] ?? null) ? $job['select'] : []);
    foreach ($select as $column) {
        if ($types !== [] && !isset($types[$column])) {
            throw new MimirUserException('Onbekende kolom: ' . $column);
        }
    }
    $filter = $job['filter'] ?? null;
    $filterError = mimir_filter_validate($filter);
    if ($filterError !== null) {
        throw new MimirUserException($filterError);
    }
    if ($types !== []) {
        foreach (mimir_filter_fields($filter) as $field) {
            if (!isset($types[$field])) {
                throw new MimirUserException('Onbekend veld: ' . $field);
            }
        }
    }
    $maxAge = (int) ($job['max_age'] ?? MIMIR_DEFAULT_MAX_AGE);
    if ($maxAge < 0 || $maxAge > MIMIR_MAX_MAX_AGE) {
        throw new MimirUserException('max_age moet tussen 0 en 365 dagen liggen.');
    }
    $top = (int) ($job['top'] ?? MIMIR_DEFAULT_TOP);
    if ($top < 1 || $top > MIMIR_MAX_TOP) {
        throw new MimirUserException('top moet tussen 1 en ' . MIMIR_MAX_TOP . ' liggen.');
    }

    $plan = mimir_filter_plan($filter, $types);
    $pushed = $plan['odata'];
    $mode = (string) $plan['mode'];
    if ($mode === 'none') {
        $pushed = null;
    }
    $selectSig = mimir_select_sig($select);
    $required = array_values(array_unique(array_merge($select, mimir_filter_fields($filter))));
    $coverage = mimir_coverage_find($pdo, $company, $entity, $pushed, $selectSig, $maxAge, $now);

    $fromCache = 0;
    $fromLive = 0;
    $kept = [];
    $usedCoverage = false;

    if ($coverage !== null) {
        $served = mimir_serve_from_coverage(
            $pdo,
            $company,
            $entity,
            $prefix,
            $filter,
            $types,
            $keys,
            $required,
            $maxAge,
            $top,
            $coverage['fetched_at'],
            $fetch,
            $now
        );
        if (!$served['fallback']) {
            $usedCoverage = true;
            $kept = $served['rows'];
            $fromCache = $served['from_cache'];
            $fromLive = $served['from_live'];
        }
    }
    if (!$usedCoverage) {
        $live = mimir_fetch_collection(
            $pdo,
            $company,
            $entity,
            $prefix,
            $filter,
            $types,
            $keys,
            $select,
            $pushed,
            $mode,
            $fetch,
            $now
        );
        $mode = $live['mode'];
        $pushed = $live['pushed'];
        foreach ($live['rows'] as $row) {
            if (!mimir_filter_match($row['payload'], $filter, $types)) {
                continue;
            }
            $kept[] = $row;
        }
        if (count($kept) > $top) {
            $kept = array_slice($kept, 0, $top);
        }
        $fromLive = count($kept);
    }

    $value = [];
    $min = null;
    $max = null;
    foreach ($kept as $row) {
        $value[] = mimir_project_row($row['payload'], $select);
        $at = (int) $row['fetched_at'];
        $min = $min === null ? $at : min($min, $at);
        $max = $max === null ? $at : max($max, $at);
    }

    return [
        'value' => $value,
        'meta' => [
            'from_cache' => $fromCache,
            'from_live' => $fromLive,
            'max_age' => $maxAge,
            'fetched_at_min' => $min,
            'fetched_at_max' => $max,
            'bc_filter' => $pushed,
            'filter_mode' => $mode,
            'filter_note' => mimir_filter_note($mode),
        ],
    ];
}

/**
 * @param list<string> $keys
 * @param list<string> $required
 * @param array<string, string> $types
 * @param callable(string): array $fetch
 * @return array{rows: list<array{payload: array<string, mixed>, fetched_at: int}>, from_cache: int, from_live: int, fallback: bool}
 */
function mimir_serve_from_coverage(
    PDO $pdo,
    string $company,
    string $entity,
    string $prefix,
    mixed $filter,
    array $types,
    array $keys,
    array $required,
    int $maxAge,
    int $top,
    int $coverageAt,
    callable $fetch,
    int $now
): array {
    $window = [];
    $needsRefresh = 0;
    foreach (mimir_cache_all($pdo, $company, $entity) as $stored) {
        if ($stored['fetched_at'] + 2 < $coverageAt) {
            continue;
        }
        $reason = mimir_row_refresh_reason($stored, $required, $maxAge, $now);
        if ($reason !== 'ok') {
            $needsRefresh++;
        }
        $window[] = ['stored' => $stored, 'reason' => $reason];
    }
    if ($needsRefresh > 25) {
        return ['rows' => [], 'from_cache' => 0, 'from_live' => 0, 'fallback' => true];
    }

    $rows = [];
    $fromCache = 0;
    $fromLive = 0;
    foreach ($window as $item) {
        $stored = $item['stored'];
        if ($item['reason'] === 'ok') {
            $current = ['payload' => $stored['payload'], 'fetched_at' => $stored['fetched_at'], 'live' => false];
        } else {
            $refreshed = mimir_refresh_whole_row($pdo, $company, $entity, $prefix, $stored['payload'], $keys, $types, $required, $fetch, $now);
            if ($refreshed === null) {
                continue;
            }
            $current = ['payload' => $refreshed['payload'], 'fetched_at' => $refreshed['fetched_at'], 'live' => true];
        }
        if (!mimir_filter_match($current['payload'], $filter, $types)) {
            continue;
        }
        $rows[] = $current;
    }
    if (count($rows) > $top) {
        $rows = array_slice($rows, 0, $top);
    }
    $out = [];
    foreach ($rows as $row) {
        if ($row['live']) {
            $fromLive++;
        } else {
            $fromCache++;
        }
        $out[] = ['payload' => $row['payload'], 'fetched_at' => $row['fetched_at']];
    }
    return ['rows' => $out, 'from_cache' => $fromCache, 'from_live' => $fromLive, 'fallback' => false];
}

/**
 * @param list<string> $keys
 * @param array<string, string> $types
 * @param array<string, mixed> $payload
 * @param callable(string): array $fetch
 * @return array{payload: array<string, mixed>, fetched_at: int}|null
 */
function mimir_refresh_whole_row(
    PDO $pdo,
    string $company,
    string $entity,
    string $prefix,
    array $payload,
    array $keys,
    array $types,
    array $required,
    callable $fetch,
    int $now
): ?array {
    if ($keys === []) {
        throw new RuntimeException('Hele rij verversen kan niet zonder sleutelvelden.');
    }
    $rowKey = mimir_row_key($payload, $keys);
    try {
        $predicate = mimir_key_predicate($payload, $keys, $types);
    } catch (RuntimeException) {
        throw new RuntimeException('Hele rij verversen kan niet: sleutel ontbreekt in de cache.');
    }
    $url = mimir_entity_key_url($prefix, $company, $entity, $predicate);
    try {
        $decoded = $fetch($url);
    } catch (Throwable $error) {
        if (mimir_exception_status($error) === 404) {
            mimir_cache_delete($pdo, $company, $entity, $rowKey);
            return null;
        }
        throw $error;
    }
    if (isset($decoded['error'])) {
        $message = is_array($decoded['error']) ? (string) ($decoded['error']['message'] ?? 'OData-fout') : 'OData-fout';
        throw new RuntimeException($message);
    }
    if (isset($decoded['value']) && is_array($decoded['value']) && array_is_list($decoded['value'])) {
        throw new RuntimeException('Sleutel-verzoek gaf een lijst terug in plaats van één rij.');
    }
    $fresh = mimir_strip_odata_noise($decoded);
    foreach ($required as $column) {
        if (!array_key_exists($column, $fresh)) {
            $fresh[$column] = null;
        }
    }
    $newKey = mimir_row_key($fresh, $keys);
    if ($newKey !== $rowKey) {
        mimir_cache_delete($pdo, $company, $entity, $rowKey);
    }
    mimir_cache_upsert($pdo, $company, $entity, $newKey, $fresh, $now);
    return ['payload' => $fresh, 'fetched_at' => $now];
}

/**
 * @param list<string> $keys
 * @param list<string> $select
 * @param array<string, string> $types
 * @param callable(string): array $fetch
 * @return array{rows: list<array{payload: array<string, mixed>, fetched_at: int}>, mode: string, pushed: ?string}
 */
function mimir_fetch_collection(
    PDO $pdo,
    string $company,
    string $entity,
    string $prefix,
    mixed $filter,
    array $types,
    array $keys,
    array $select,
    ?string $pushed,
    string $mode,
    callable $fetch,
    int $now
): array {
    $attempt = static function (?string $filterString) use ($prefix, $company, $entity, $select, $keys, $fetch): array {
        $query = ['$top' => MIMIR_ODATA_PAGE_SIZE];
        if ($filterString !== null && $filterString !== '') {
            $query['$filter'] = $filterString;
        }
        if ($select !== []) {
            $columns = array_values(array_unique(array_merge($select, $keys)));
            sort($columns, SORT_STRING);
            $query['$select'] = implode(',', $columns);
        }
        $url = mimir_collection_url($prefix, $company, $entity, $query);
        return mimir_follow_pages($url, $prefix, $fetch);
    };

    try {
        $pages = $attempt($pushed);
    } catch (Throwable $error) {
        if ($pushed !== null && mimir_exception_status($error) === 501) {
            $pushed = null;
            $mode = 'local';
            $pages = $attempt(null);
        } else {
            throw $error;
        }
    }

    $stored = [];
    foreach ($pages['rows'] as $payload) {
        $clean = mimir_strip_odata_noise($payload);
        if ($keys !== []) {
            $missingKey = false;
            foreach ($keys as $key) {
                if (!array_key_exists($key, $clean)) {
                    $missingKey = true;
                    break;
                }
            }
            if ($missingKey) {
                continue;
            }
        }
        $rowKey = mimir_row_key($clean, $keys);
        mimir_cache_upsert($pdo, $company, $entity, $rowKey, $clean, $now);
        $stored[] = ['payload' => $clean, 'fetched_at' => $now];
    }

    if (!$pages['truncated']) {
        $filterSig = $pushed ?? '';
        $selectSig = mimir_select_sig($select);
        mimir_coverage_put($pdo, $company, $entity, $filterSig, $selectSig, $now, count($stored));
    }

    return ['rows' => $stored, 'mode' => $mode, 'pushed' => $pushed];
}

/**
 * @param callable(string): array $fetch
 * @return array{rows: list<array<string, mixed>>, truncated: bool}
 */
function mimir_follow_pages(string $url, string $prefix, callable $fetch): array
{
    $rows = [];
    $next = $url;
    $guard = 0;
    $truncated = false;
    while ($next !== '') {
        if (!mimir_same_odata_origin($next, $prefix)) {
            throw new RuntimeException('OData nextLink wijst naar een andere host.');
        }
        $decoded = $fetch($next);
        if (!isset($decoded['value']) || !is_array($decoded['value'])) {
            throw new RuntimeException("OData response missing 'value' array");
        }
        foreach ($decoded['value'] as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        $link = $decoded['@odata.nextLink'] ?? null;
        $next = is_string($link) ? $link : '';
        $guard++;
        if ($next !== '' && $guard >= MIMIR_ODATA_PAGE_GUARD) {
            $truncated = true;
            break;
        }
    }
    return ['rows' => $rows, 'truncated' => $truncated];
}

function mimir_exception_status(Throwable $error): ?int
{
    if (preg_match('/HTTP (\d{3})/', $error->getMessage(), $match) === 1) {
        return (int) $match[1];
    }
    return null;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function mimir_strip_odata_noise(array $row): array
{
    $clean = [];
    foreach ($row as $key => $value) {
        if (!is_string($key)) {
            continue;
        }
        if (str_starts_with($key, '@odata.') && $key !== '@odata.etag') {
            continue;
        }
        $clean[$key] = $value;
    }
    return $clean;
}
