<?php

declare(strict_types=1);

require_once __DIR__ . '/mimir_filter.php';
require_once __DIR__ . '/odata.php';
require_once __DIR__ . '/mimir_heatmap.php';
require_once __DIR__ . '/mimir_bc_limit.php';

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
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException('Datamap kon niet worden aangemaakt: ' . $dir);
        }
        // Apache (user http) en CLI (tim) moeten beide kunnen schrijven, net als Consus data/.
        mimir_db_relax_perms($path);
    }
    $journal = 'wal';
    try {
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 30000');
        if ($path !== ':memory:') {
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA synchronous = NORMAL');
            $mode = $pdo->query('PRAGMA journal_mode');
            $journal = strtolower(trim((string) ($mode === false ? '' : $mode->fetchColumn())));
            mimir_db_relax_perms($path);
        }
    } catch (Throwable $error) {
        throw new RuntimeException(
            'SQLite openen mislukt (' . $path . '): ' . $error->getMessage(),
            0,
            $error
        );
    }
    if ($path !== ':memory:' && $journal !== 'wal') {
        throw new RuntimeException(
            'SQLite journal_mode is "' . $journal . '" in plaats van wal voor ' . $path
            . '. De datamap is niet schrijfbaar (directory write permissions; chmod 777 op de datamap).'
        );
    }
    mimir_db_retry(static function () use ($pdo): void {
        mimir_migrate($pdo);
    });
    if ($path !== ':memory:') {
        mimir_db_relax_perms($path);
    }
    return $pdo;
}

/**
 * Apache (http) en de FTP-eigenaar moeten dezelfde sqlite-bestanden kunnen schrijven.
 */
function mimir_db_relax_perms(string $path): void
{
    if ($path === ':memory:') {
        return;
    }
    $dir = dirname($path);
    if (is_dir($dir)) {
        @chmod($dir, 0777);
    }
    if (is_file($path)) {
        @chmod($path, 0666);
    }
    foreach ([$path . '-wal', $path . '-shm'] as $side) {
        if (is_file($side)) {
            @chmod($side, 0666);
        }
    }
}

function mimir_sqlite_is_busy(PDOException $error): bool
{
    $message = $error->getMessage();
    if (stripos($message, 'database is locked') !== false || stripos($message, 'SQLITE_BUSY') !== false) {
        return true;
    }
    $info = $error->errorInfo ?? null;
    if (is_array($info) && (string) ($info[0] ?? '') === 'HY000' && (int) ($info[1] ?? 0) === 5) {
        return true;
    }
    if (preg_match('/SQLSTATE\[HY000\][^\r\n]*\b5\b/', $message) === 1) {
        return true;
    }
    return false;
}

/**
 * Retry only SQLITE_BUSY / "database is locked". Other errors propagate immediately.
 */
function mimir_db_retry(callable $fn, int $attempts = 8): mixed
{
    if ($attempts < 1) {
        $attempts = 1;
    }
    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        try {
            return $fn();
        } catch (PDOException $error) {
            if (!mimir_sqlite_is_busy($error) || $attempt >= $attempts) {
                throw $error;
            }
            usleep(25000 * $attempt);
        }
    }
    throw new RuntimeException('SQLite-retry mislukt.');
}

function mimir_db_path(): string
{
    return __DIR__ . '/data/mimir.sqlite';
}

/**
 * @return list<string>
 */
function mimir_sqlite_columns(PDO $pdo, string $table): array
{
    if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
        throw new RuntimeException('Ongeldige tabelnaam.');
    }
    $columns = [];
    $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
    if ($stmt === false) {
        return [];
    }
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $columns[] = (string) ($row['name'] ?? '');
    }
    return $columns;
}

function mimir_migrate(PDO $pdo): void
{
    $rowColumns = mimir_sqlite_columns($pdo, 'cache_rows');
    if ($rowColumns !== [] && !in_array('environment', $rowColumns, true)) {
        $pdo->exec('ALTER TABLE cache_rows RENAME TO cache_rows_legacy');
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS cache_rows (
            environment TEXT NOT NULL,
            company TEXT NOT NULL,
            entity TEXT NOT NULL,
            row_key TEXT NOT NULL,
            payload TEXT NOT NULL,
            fetched_at INTEGER NOT NULL,
            PRIMARY KEY (environment, company, entity, row_key)
        )'
    );
    if (mimir_sqlite_columns($pdo, 'cache_rows_legacy') !== []) {
        $pdo->exec(
            "INSERT OR IGNORE INTO cache_rows (environment, company, entity, row_key, payload, fetched_at)
             SELECT '', company, entity, row_key, payload, fetched_at FROM cache_rows_legacy"
        );
        $pdo->exec('DROP TABLE cache_rows_legacy');
    }
    $pdo->exec('DROP INDEX IF EXISTS idx_cache_rows_entity');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cache_rows_lookup ON cache_rows(environment, company, entity, fetched_at)');

    $coverageColumns = mimir_sqlite_columns($pdo, 'cache_coverage');
    if ($coverageColumns !== [] && !in_array('environment', $coverageColumns, true)) {
        $pdo->exec('ALTER TABLE cache_coverage RENAME TO cache_coverage_legacy');
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS cache_coverage (
            environment TEXT NOT NULL,
            company TEXT NOT NULL,
            entity TEXT NOT NULL,
            filter_sig TEXT NOT NULL,
            select_sig TEXT NOT NULL,
            fetched_at INTEGER NOT NULL,
            row_count INTEGER NOT NULL,
            key_id INTEGER,
            PRIMARY KEY (environment, company, entity, filter_sig, select_sig)
        )'
    );
    if (mimir_sqlite_columns($pdo, 'cache_coverage_legacy') !== []) {
        $pdo->exec(
            "INSERT OR IGNORE INTO cache_coverage (environment, company, entity, filter_sig, select_sig, fetched_at, row_count)
             SELECT '', company, entity, filter_sig, select_sig, fetched_at, row_count FROM cache_coverage_legacy"
        );
        $pdo->exec('DROP TABLE cache_coverage_legacy');
    }
    $coverageColumns = mimir_sqlite_columns($pdo, 'cache_coverage');
    if ($coverageColumns !== [] && !in_array('key_id', $coverageColumns, true)) {
        $pdo->exec('ALTER TABLE cache_coverage ADD COLUMN key_id INTEGER');
    }
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
            called_at INTEGER NOT NULL,
            shared INTEGER NOT NULL DEFAULT 0,
            bc_hit INTEGER NOT NULL DEFAULT 0,
            from_cache INTEGER NOT NULL DEFAULT 0,
            from_live INTEGER NOT NULL DEFAULT 0
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_api_usage_key_time ON api_usage(key_id, called_at)');
    $usageColumns = mimir_sqlite_columns($pdo, 'api_usage');
    if ($usageColumns !== []) {
        if (!in_array('shared', $usageColumns, true)) {
            $pdo->exec('ALTER TABLE api_usage ADD COLUMN shared INTEGER NOT NULL DEFAULT 0');
        }
        if (!in_array('bc_hit', $usageColumns, true)) {
            $pdo->exec('ALTER TABLE api_usage ADD COLUMN bc_hit INTEGER NOT NULL DEFAULT 0');
        }
        if (!in_array('from_cache', $usageColumns, true)) {
            $pdo->exec('ALTER TABLE api_usage ADD COLUMN from_cache INTEGER NOT NULL DEFAULT 0');
        }
        if (!in_array('from_live', $usageColumns, true)) {
            $pdo->exec('ALTER TABLE api_usage ADD COLUMN from_live INTEGER NOT NULL DEFAULT 0');
        }
    }
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
 * @return list<array{id: int, label: string, key: string, created_at: int, revoked_at: ?int, avg_per_day: float, shared_pct: ?int, days: list<array{date: string, count: int, future: bool}>}>
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
            'shared_pct' => mimir_key_shared_pct($pdo, $id, $now),
            'days' => mimir_key_usage_days($pdo, $id, $now),
        ];
    }
    return $rows;
}

/**
 * Dagtotalen voor het Mithra-weekraster (maandag als eerste kolom, vier weken).
 *
 * @return list<array{date: string, count: int, future: bool}>
 */
function mimir_key_usage_days(PDO $pdo, int $keyId, int $now): array
{
    $today = mimir_heatmap_today($now);
    $dates = mimir_heatmap_grid_dates($today);
    if ($dates === []) {
        return [];
    }
    $zone = mimir_heatmap_timezone();
    $from = (new DateTimeImmutable($dates[0] . ' 00:00:00', $zone))->getTimestamp();
    $to = (new DateTimeImmutable($today . ' 23:59:59', $zone))->getTimestamp();
    return mimir_heatmap_build_grid_days(mimir_usage_counts_by_date($pdo, $keyId, $from, $to), $today);
}

/**
 * @return array<string, int>
 */
function mimir_usage_counts_by_date(PDO $pdo, int $keyId, int $fromUnix, int $toUnix): array
{
    $stmt = $pdo->prepare(
        'SELECT called_at FROM api_usage WHERE key_id = :id AND called_at >= :from_at AND called_at <= :to_at'
    );
    $stmt->execute([
        ':id' => $keyId,
        ':from_at' => $fromUnix,
        ':to_at' => $toUnix,
    ]);
    $zone = mimir_heatmap_timezone();
    $counts = [];
    while ($calledAt = $stmt->fetchColumn()) {
        $date = (new DateTimeImmutable('@' . (int) $calledAt))->setTimezone($zone)->format('Y-m-d');
        $counts[$date] = (int) ($counts[$date] ?? 0) + 1;
    }
    return $counts;
}

function mimir_key_avg_per_day(PDO $pdo, int $keyId, int $now): float
{
    $since = $now - (30 * 86400);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM api_usage WHERE key_id = :id AND called_at >= :since');
    $stmt->execute([':id' => $keyId, ':since' => $since]);
    $count = (int) $stmt->fetchColumn();
    return $count / 30;
}

function mimir_key_shared_pct(PDO $pdo, int $keyId, int $now): ?int
{
    return mimir_usage_shared_pct($pdo, $now, $keyId);
}

/**
 * Global shared % across all API keys (same 7-day query window). Null when no query calls.
 */
function mimir_usage_shared_pct_global(PDO $pdo, int $now): ?int
{
    return mimir_usage_shared_pct($pdo, $now, null);
}

/**
 * Percentage of query calls in the past 7 days counted as shared cache hits.
 * Null when there were no query calls in the window (UI shows —).
 */
function mimir_usage_shared_pct(PDO $pdo, int $now, ?int $keyId): ?int
{
    $since = $now - (7 * 86400);
    if ($keyId === null) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(CASE WHEN shared = 1 THEN 1 ELSE 0 END), 0) AS shared_count
             FROM api_usage
             WHERE endpoint = 'query' AND called_at >= :since"
        );
        $stmt->execute([':since' => $since]);
    } else {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(CASE WHEN shared = 1 THEN 1 ELSE 0 END), 0) AS shared_count
             FROM api_usage
             WHERE key_id = :id AND endpoint = 'query' AND called_at >= :since"
        );
        $stmt->execute([':id' => $keyId, ':since' => $since]);
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }
    $total = (int) ($row['total'] ?? 0);
    if ($total === 0) {
        return null;
    }
    return (int) round(100 * ((int) ($row['shared_count'] ?? 0)) / $total);
}

function mimir_usage_log(
    PDO $pdo,
    int $keyId,
    string $endpoint,
    int $now,
    int $shared = 0,
    int $bcHit = 0,
    int $fromCache = 0,
    int $fromLive = 0
): void {
    mimir_db_retry(static function () use ($pdo, $keyId, $endpoint, $now, $shared, $bcHit, $fromCache, $fromLive): void {
        $stmt = $pdo->prepare(
            'INSERT INTO api_usage (key_id, endpoint, called_at, shared, bc_hit, from_cache, from_live)
             VALUES (:id, :endpoint, :at, :shared, :bc_hit, :from_cache, :from_live)'
        );
        $stmt->execute([
            ':id' => $keyId,
            ':endpoint' => $endpoint,
            ':at' => $now,
            ':shared' => $shared ? 1 : 0,
            ':bc_hit' => $bcHit ? 1 : 0,
            ':from_cache' => max(0, $fromCache),
            ':from_live' => max(0, $fromLive),
        ]);
    });
}

/**
 * Derive usage flags from a query response (single table or multi-query results).
 *
 * @param array<string, mixed> $response
 * @return array{shared: int, bc_hit: int, from_cache: int, from_live: int}
 */
function mimir_usage_flags_from_response(array $response): array
{
    if (isset($response['results']) && is_array($response['results'])) {
        $anyShared = false;
        $anyBc = false;
        $sumCache = 0;
        $sumLive = 0;
        $childCount = 0;
        foreach ($response['results'] as $child) {
            if (!is_array($child)) {
                continue;
            }
            $childCount++;
            $meta = is_array($child['meta'] ?? null) ? $child['meta'] : [];
            $fromCache = (int) ($meta['from_cache'] ?? 0);
            $fromLive = (int) ($meta['from_live'] ?? 0);
            $sumCache += $fromCache;
            $sumLive += $fromLive;
            if ($fromLive > 0 || (int) ($meta['bc_hit'] ?? 0) === 1) {
                $anyBc = true;
            }
            if ((int) ($meta['shared'] ?? 0) === 1) {
                $anyShared = true;
            }
        }
        if ($childCount === 0) {
            return ['shared' => 0, 'bc_hit' => 0, 'from_cache' => 0, 'from_live' => 0];
        }
        return [
            'shared' => (!$anyBc && $anyShared) ? 1 : 0,
            'bc_hit' => $anyBc ? 1 : 0,
            'from_cache' => $sumCache,
            'from_live' => $sumLive,
        ];
    }

    $meta = is_array($response['meta'] ?? null) ? $response['meta'] : [];
    return [
        'shared' => (int) ($meta['shared'] ?? 0) ? 1 : 0,
        'bc_hit' => (int) ($meta['bc_hit'] ?? 0) ? 1 : 0,
        'from_cache' => (int) ($meta['from_cache'] ?? 0),
        'from_live' => (int) ($meta['from_live'] ?? 0),
    ];
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

function mimir_meta_get_raw(PDO $pdo, string $key): ?array
{
    $stmt = $pdo->prepare('SELECT payload, fetched_at FROM meta_cache WHERE cache_key = :key');
    $stmt->execute([':key' => $key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }
    $decoded = json_decode((string) $row['payload'], true);
    if (!is_array($decoded)) {
        return null;
    }
    $decoded['_fetched_at'] = (int) ($row['fetched_at'] ?? 0);
    return $decoded;
}

function mimir_meta_put(PDO $pdo, string $key, array $payload, int $now): void
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Metadata kon niet worden opgeslagen.');
    }
    mimir_db_retry(static function () use ($pdo, $key, $json, $now): void {
        $stmt = $pdo->prepare(
            'INSERT INTO meta_cache (cache_key, payload, fetched_at) VALUES (:key, :payload, :at)
             ON CONFLICT(cache_key) DO UPDATE SET payload = excluded.payload, fetched_at = excluded.fetched_at'
        );
        $stmt->execute([':key' => $key, ':payload' => $json, ':at' => $now]);
    });
}

/**
 * @param array<string, mixed> $payload
 */
function mimir_cache_upsert(PDO $pdo, string $environment, string $company, string $entity, string $rowKey, array $payload, int $fetchedAt): void
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Rij kon niet worden gecachet.');
    }
    mimir_db_retry(static function () use ($pdo, $environment, $company, $entity, $rowKey, $json, $fetchedAt): void {
        $stmt = $pdo->prepare(
            'INSERT INTO cache_rows (environment, company, entity, row_key, payload, fetched_at)
             VALUES (:environment, :company, :entity, :row_key, :payload, :at)
             ON CONFLICT(environment, company, entity, row_key) DO UPDATE SET payload = excluded.payload, fetched_at = excluded.fetched_at'
        );
        $stmt->execute([
            ':environment' => $environment,
            ':company' => $company,
            ':entity' => $entity,
            ':row_key' => $rowKey,
            ':payload' => $json,
            ':at' => $fetchedAt,
        ]);
    });
}

function mimir_cache_delete(PDO $pdo, string $environment, string $company, string $entity, string $rowKey): void
{
    mimir_db_retry(static function () use ($pdo, $environment, $company, $entity, $rowKey): void {
        $stmt = $pdo->prepare(
            'DELETE FROM cache_rows WHERE environment = :environment AND company = :company AND entity = :entity AND row_key = :row_key'
        );
        $stmt->execute([
            ':environment' => $environment,
            ':company' => $company,
            ':entity' => $entity,
            ':row_key' => $rowKey,
        ]);
    });
}

/**
 * @return list<array{row_key: string, payload: array<string, mixed>, fetched_at: int}>
 */
function mimir_cache_all(PDO $pdo, string $environment, string $company, string $entity): array
{
    $stmt = $pdo->prepare(
        'SELECT row_key, payload, fetched_at FROM cache_rows WHERE environment = :environment AND company = :company AND entity = :entity'
    );
    $stmt->execute([':environment' => $environment, ':company' => $company, ':entity' => $entity]);
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

function mimir_coverage_put(
    PDO $pdo,
    string $environment,
    string $company,
    string $entity,
    string $filterSig,
    string $selectSig,
    int $fetchedAt,
    int $rowCount,
    ?int $keyId = null
): void {
    mimir_db_retry(static function () use ($pdo, $environment, $company, $entity, $filterSig, $selectSig, $fetchedAt, $rowCount, $keyId): void {
        $stmt = $pdo->prepare(
            'INSERT INTO cache_coverage (environment, company, entity, filter_sig, select_sig, fetched_at, row_count, key_id)
             VALUES (:environment, :company, :entity, :filter_sig, :select_sig, :at, :count, :key_id)
             ON CONFLICT(environment, company, entity, filter_sig, select_sig) DO UPDATE SET
                fetched_at = excluded.fetched_at,
                row_count = excluded.row_count,
                key_id = COALESCE(excluded.key_id, cache_coverage.key_id)'
        );
        $stmt->execute([
            ':environment' => $environment,
            ':company' => $company,
            ':entity' => $entity,
            ':filter_sig' => $filterSig,
            ':select_sig' => $selectSig,
            ':at' => $fetchedAt,
            ':count' => $rowCount,
            ':key_id' => $keyId,
        ]);
    });
}

/**
 * Find fresh coverage that can satisfy a request.
 * Prefer an exact filter_sig match; if none (and local filtering is safe), fall back
 * to empty-filter (full-entity) coverage so filtered UI calls hit local filter.
 *
 * @return array{filter_sig: string, select_sig: string, fetched_at: int, key_id: ?int}|null
 */
function mimir_coverage_find(
    PDO $pdo,
    string $environment,
    string $company,
    string $entity,
    ?string $pushedFilter,
    string $selectSig,
    int $maxAge,
    int $now,
    bool $allowEmptyFallback = true
): ?array {
    $sql = 'SELECT filter_sig, select_sig, fetched_at, key_id FROM cache_coverage
            WHERE environment = :environment AND company = :company AND entity = :entity AND fetched_at >= :min_at';
    $params = [
        ':environment' => $environment,
        ':company' => $company,
        ':entity' => $entity,
        ':min_at' => $now - $maxAge,
    ];
    if ($pushedFilter === null) {
        $sql .= " AND filter_sig = ''";
    } else {
        $sql .= " AND (filter_sig = :filter OR filter_sig = '')";
        $params[':filter'] = $pushedFilter;
    }
    $sql .= ' ORDER BY fetched_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $exact = null;
    $empty = null;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!mimir_select_sig_covers((string) $row['select_sig'], $selectSig)) {
            continue;
        }
        $covKey = $row['key_id'] ?? null;
        $candidate = [
            'filter_sig' => (string) $row['filter_sig'],
            'select_sig' => (string) $row['select_sig'],
            'fetched_at' => (int) $row['fetched_at'],
            'key_id' => $covKey === null || $covKey === '' ? null : (int) $covKey,
        ];
        if ($candidate['filter_sig'] === '') {
            if ($empty === null) {
                $empty = $candidate;
            }
            continue;
        }
        if ($pushedFilter !== null && $candidate['filter_sig'] === $pushedFilter && $exact === null) {
            $exact = $candidate;
        }
    }

    if ($pushedFilter === null) {
        return $empty;
    }
    if ($exact !== null) {
        return $exact;
    }
    if ($allowEmptyFallback) {
        return $empty;
    }
    return null;
}

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
 * Business-column names currently stored for an entity (union across cached rows).
 *
 * @return list<string>
 */
function mimir_on_file_columns(PDO $pdo, string $environment, string $company, string $entity): array
{
    $cols = [];
    foreach (mimir_cache_all($pdo, $environment, $company, $entity) as $stored) {
        foreach (array_keys($stored['payload']) as $key) {
            if (!is_string($key) || $key === '' || str_starts_with($key, '@')) {
                continue;
            }
            $cols[$key] = $key;
        }
    }
    $out = array_values($cols);
    sort($out, SORT_STRING);
    return $out;
}

/**
 * Age-refresh: if request $select is a subset of columns already on file, fetch the
 * full on-file set from BC so the cache does not shrink. Otherwise keep $select.
 *
 * @param list<string> $select
 * @param list<string> $onFile
 * @return list<string>
 */
function mimir_select_for_bc_refresh(array $select, array $onFile): array
{
    if ($select === [] || $onFile === []) {
        return $select;
    }
    if (array_diff($select, $onFile) !== []) {
        // Request asks for columns not on file — existing widen/refresh path.
        return $select;
    }
    return $onFile;
}

/**
 * Columns to send as BC $select. Empty list = omit $select (fetch all columns).
 *
 * - Full-entity / empty $filter toward BC: always omit — warms maximize sharing;
 *   API still projects the caller's select.
 * - Filtered with any on-file columns for company+entity: omit (do not shrink;
 *   stronger than union age-refresh).
 * - Filtered cold cache: pass request select.
 *
 * @param list<string> $select
 * @param list<string> $onFile
 * @return list<string>
 */
function mimir_select_for_bc(array $select, array $onFile, bool $emptyFilter): array
{
    if ($emptyFilter) {
        return [];
    }
    if ($onFile !== []) {
        return [];
    }
    return $select;
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
 *   environment: string,
 *   company: string,
 *   entity: string,
 *   service_prefix: string,
 *   select?: list<string>,
 *   filter?: mixed,
 *   max_age?: int,
 *   top?: int,
 *   key_id?: ?int,
 *   schema: array{keys?: list<string>, properties?: array<string, string>}
 * } $job
 * @param callable(string): array $fetch
 * @return array{value: list<array<string, mixed>>, meta: array<string, mixed>}
 */
function mimir_query_entity(PDO $pdo, array $job, callable $fetch, int $now): array
{
    $environment = trim((string) ($job['environment'] ?? ''));
    $company = trim((string) ($job['company'] ?? ''));
    $entity = trim((string) ($job['entity'] ?? ''));
    $prefix = (string) ($job['service_prefix'] ?? '');
    if ($environment === '') {
        throw new MimirUserException('environment is verplicht.');
    }
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
    // 0 = unlimited (geen array_slice). Positief blijft begrensd op MIMIR_MAX_TOP.
    if ($top < 0 || $top > MIMIR_MAX_TOP) {
        throw new MimirUserException('top moet 0 (ongelimiteerd) of tussen 1 en ' . MIMIR_MAX_TOP . ' liggen.');
    }

    $plan = mimir_filter_plan($filter, $types);
    $pushed = $plan['odata'];
    $mode = (string) $plan['mode'];
    if ($mode === 'none') {
        $pushed = null;
    }
    $filterBatches = mimir_filter_odata_batches($filter, $types);
    $batchCount = $filterBatches === null ? 1 : count($filterBatches);
    $selectSig = mimir_select_sig($select);
    $required = array_values(array_unique(array_merge($select, mimir_filter_fields($filter))));
    $onFile = mimir_on_file_columns($pdo, $environment, $company, $entity);
    $emptyFilterFetch = ($pushed === null);
    // Full-entity warms omit $select toward BC; filtered cold may still pass select.
    // Age-refresh non-shrink is preserved by omitting (or by mimir_select_for_bc_refresh when used).
    $fetchSelect = mimir_select_for_bc($select, $onFile, $emptyFilterFetch);
    $fetchSelectSig = mimir_select_sig($fetchSelect);
    $keyId = array_key_exists('key_id', $job) && $job['key_id'] !== null && $job['key_id'] !== ''
        ? (int) $job['key_id']
        : null;
    $allowBroad = mimir_filter_allows_local($filter);

    $slotHeld = false;
    $queueWaitMs = 0;
    $innerFetch = $fetch;
    $fetch = static function (string $url) use ($innerFetch, $environment, &$slotHeld, &$queueWaitMs): array {
        if (!$slotHeld) {
            $queueWaitMs = mimir_bc_slot_acquire($environment);
            $slotHeld = true;
        }

        return $innerFetch($url);
    };

    try {
    $coverage = mimir_coverage_find(
        $pdo,
        $environment,
        $company,
        $entity,
        $pushed,
        $selectSig,
        $maxAge,
        $now,
        $allowBroad
    );

    $fromCache = 0;
    $fromLive = 0;
    $kept = [];
    $usedCoverage = false;
    $gapFilled = false;
    $gapSharedKeyId = null;

    if ($coverage !== null) {
        $served = mimir_serve_from_coverage(
            $pdo,
            $environment,
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
        $gap = null;
        if ($filterBatches === null && $allowBroad) {
            $gap = mimir_try_range_gap_fill(
                $pdo,
                $environment,
                $company,
                $entity,
                $prefix,
                $filter,
                $types,
                $keys,
                $required,
                $select,
                $fetchSelect,
                $fetchSelectSig,
                $pushed,
                $mode,
                $maxAge,
                $top,
                $fetch,
                $now,
                $keyId
            );
        }
        if ($gap !== null) {
            $gapFilled = true;
            $kept = $gap['rows'];
            $fromCache = $gap['from_cache'];
            $fromLive = $gap['from_live'];
            $mode = $gap['mode'];
            $pushed = $gap['pushed'];
            $gapSharedKeyId = $gap['shared_key_id'];
            if ($top > 0 && count($kept) > $top) {
                $kept = array_slice($kept, 0, $top);
            }
        } elseif ($filterBatches !== null) {
            $merged = [];
            $seen = [];
            $anyTruncated = false;
            $mode = 'bc';
            foreach ($filterBatches as $batchFilter) {
                $live = mimir_fetch_collection(
                    $pdo,
                    $environment,
                    $company,
                    $entity,
                    $prefix,
                    $filter,
                    $types,
                    $keys,
                    $fetchSelect,
                    $batchFilter,
                    'bc',
                    $fetch,
                    $now,
                    false,
                    $keyId
                );
                if ($live['mode'] === 'local') {
                    $mode = 'local';
                }
                if (!empty($live['truncated'])) {
                    $anyTruncated = true;
                }
                foreach ($live['rows'] as $row) {
                    $rowKey = mimir_row_key($row['payload'], $keys);
                    if (isset($seen[$rowKey])) {
                        continue;
                    }
                    $seen[$rowKey] = true;
                    $merged[] = $row;
                }
            }
            if (!$anyTruncated) {
                $filterSig = $pushed ?? '';
                mimir_coverage_put($pdo, $environment, $company, $entity, $filterSig, $fetchSelectSig, $now, count($merged), $keyId);
            }
            foreach ($merged as $row) {
                if (!mimir_filter_match($row['payload'], $filter, $types)) {
                    continue;
                }
                $kept[] = $row;
            }
            if ($top > 0 && count($kept) > $top) {
                $kept = array_slice($kept, 0, $top);
            }
            $fromLive = count($kept);
        } else {
            $live = mimir_fetch_collection(
                $pdo,
                $environment,
                $company,
                $entity,
                $prefix,
                $filter,
                $types,
                $keys,
                $fetchSelect,
                $pushed,
                $mode,
                $fetch,
                $now,
                true,
                $keyId
            );
            $mode = $live['mode'];
            $pushed = $live['pushed'];
            foreach ($live['rows'] as $row) {
                if (!mimir_filter_match($row['payload'], $filter, $types)) {
                    continue;
                }
                $kept[] = $row;
            }
            if ($top > 0 && count($kept) > $top) {
                $kept = array_slice($kept, 0, $top);
            }
            $fromLive = count($kept);
        }
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

    $bcHit = 0;
    $shared = 0;
    if ($usedCoverage && $fromLive === 0) {
        $coverageKeyId = $coverage['key_id'] ?? null;
        $bcHit = 0;
        if ($coverageKeyId !== null && $keyId !== null && (int) $coverageKeyId === $keyId) {
            $shared = 0;
        } else {
            // null ownership (legacy/UI) or another key populated the coverage
            $shared = 1;
        }
    } elseif ($gapFilled && $fromLive === 0) {
        $bcHit = 0;
        if ($gapSharedKeyId !== null && $keyId !== null && (int) $gapSharedKeyId === $keyId) {
            $shared = 0;
        } else {
            $shared = 1;
        }
    } else {
        $shared = 0;
        $bcHit = 1;
    }

    $meta = [
        'environment' => $environment,
        'from_cache' => $fromCache,
        'from_live' => $fromLive,
        'shared' => $shared,
        'bc_hit' => $bcHit,
        'max_age' => $maxAge,
        'fetched_at_min' => $min,
        'fetched_at_max' => $max,
        'bc_filter' => $pushed,
        'filter_mode' => $mode,
        'filter_note' => mimir_filter_note($mode),
    ];
    if ($gapFilled) {
        $meta['gap_fill'] = 1;
    }
    if ($batchCount > 1) {
        $meta['filter_batches'] = $batchCount;
    }
    $meta['queue_wait_ms'] = $queueWaitMs;
    $meta['bc_slots_max'] = mimir_bc_limit_max_concurrent();
    $meta['bc_slots_used'] = $slotHeld ? mimir_bc_slots_used($environment) : 0;

    return [
        'value' => $value,
        'meta' => $meta,
    ];
    } finally {
        if ($slotHeld) {
            mimir_bc_slot_release($environment);
        }
    }
}


/**
 * Fresh coverage rows for an entity (any filter_sig).
 *
 * @return list<array{filter_sig: string, select_sig: string, fetched_at: int, key_id: ?int, row_count: int}>
 */
function mimir_coverage_list_fresh(
    PDO $pdo,
    string $environment,
    string $company,
    string $entity,
    int $maxAge,
    int $now
): array {
    $stmt = $pdo->prepare(
        'SELECT filter_sig, select_sig, fetched_at, key_id, row_count FROM cache_coverage
         WHERE environment = :environment AND company = :company AND entity = :entity AND fetched_at >= :min_at
         ORDER BY fetched_at DESC'
    );
    $stmt->execute([
        ':environment' => $environment,
        ':company' => $company,
        ':entity' => $entity,
        ':min_at' => $now - $maxAge,
    ]);
    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $covKey = $row['key_id'] ?? null;
        $out[] = [
            'filter_sig' => (string) $row['filter_sig'],
            'select_sig' => (string) $row['select_sig'],
            'fetched_at' => (int) $row['fetched_at'],
            'key_id' => $covKey === null || $covKey === '' ? null : (int) $covKey,
            'row_count' => (int) ($row['row_count'] ?? 0),
        ];
    }
    return $out;
}

/**
 * Row-level gap fill for overlapping contiguous ranges on one comparable field.
 * Returns null when gap analysis is unsafe — caller falls back to full BC fetch.
 *
 * @param list<string> $keys
 * @param list<string> $required
 * @param list<string> $select
 * @param list<string> $fetchSelect
 * @param array<string, string> $types
 * @param callable(string): array $fetch
 * @return array{
 *   rows: list<array{payload: array<string, mixed>, fetched_at: int}>,
 *   from_cache: int,
 *   from_live: int,
 *   mode: string,
 *   pushed: ?string,
 *   shared_key_id: ?int
 * }|null
 */
function mimir_try_range_gap_fill(
    PDO $pdo,
    string $environment,
    string $company,
    string $entity,
    string $prefix,
    mixed $filter,
    array $types,
    array $keys,
    array $required,
    array $select,
    array $fetchSelect,
    string $fetchSelectSig,
    ?string $pushed,
    string $mode,
    int $maxAge,
    int $top,
    callable $fetch,
    int $now,
    ?int $keyId
): ?array {
    $requestRange = mimir_filter_as_range($filter, $types);
    if ($requestRange === null) {
        return null;
    }
    $field = $requestRange['field'];
    $type = (string) ($types[$field] ?? 'Edm.String');
    $selectSig = mimir_select_sig($select);

    $coveredRanges = [];
    $sharedKeyId = null;
    $newestAt = null;
    foreach (mimir_coverage_list_fresh($pdo, $environment, $company, $entity, $maxAge, $now) as $cov) {
        if (!mimir_select_sig_covers($cov['select_sig'], $selectSig)) {
            continue;
        }
        if ($cov['filter_sig'] === '') {
            // Full-entity coverage should have been handled by coverage_find; treat as total cover.
            $coveredRanges = [$requestRange];
            $sharedKeyId = $cov['key_id'];
            $newestAt = $cov['fetched_at'];
            break;
        }
        $range = mimir_filter_range_parse_odata($cov['filter_sig']);
        if ($range === null || $range['field'] !== $field) {
            continue;
        }
        if (mimir_filter_range_intersect($requestRange, $range, $type) === null) {
            continue;
        }
        $coveredRanges[] = $range;
        if ($newestAt === null || $cov['fetched_at'] > $newestAt) {
            $newestAt = $cov['fetched_at'];
            $sharedKeyId = $cov['key_id'];
        }
    }
    if ($coveredRanges === []) {
        return null;
    }

    $gaps = mimir_filter_range_gaps($requestRange, $coveredRanges, $type);

    // Collect fresh cached rows that match the request and have required columns.
    $cached = [];
    $seen = [];
    foreach (mimir_cache_all($pdo, $environment, $company, $entity) as $stored) {
        if (($now - (int) $stored['fetched_at']) > $maxAge) {
            continue;
        }
        $reason = mimir_row_refresh_reason($stored, $required, $maxAge, $now);
        if ($reason !== 'ok') {
            continue;
        }
        if (!mimir_filter_match($stored['payload'], $filter, $types)) {
            continue;
        }
        $rowKey = $stored['row_key'];
        $seen[$rowKey] = true;
        $cached[] = ['payload' => $stored['payload'], 'fetched_at' => $stored['fetched_at']];
    }

    if ($gaps === []) {
        // Completeness proof: union of coverages contains the request.
        $rows = $cached;
        if ($top > 0 && count($rows) > $top) {
            $rows = array_slice($rows, 0, $top);
        }
        return [
            'rows' => $rows,
            'from_cache' => count($rows),
            'from_live' => 0,
            'mode' => $mode,
            'pushed' => $pushed,
            'shared_key_id' => $sharedKeyId,
        ];
    }

    // Partial: fetch only gap sub-ranges from BC, merge with cached overlap.
    $liveRows = [];
    $anyTruncated = false;
    $liveMode = $mode;
    $lastPushed = $pushed;
    foreach ($gaps as $gapRange) {
        try {
            $gapOdata = mimir_filter_range_to_odata($gapRange, $types);
        } catch (InvalidArgumentException) {
            return null;
        }
        $live = mimir_fetch_collection(
            $pdo,
            $environment,
            $company,
            $entity,
            $prefix,
            $filter,
            $types,
            $keys,
            $fetchSelect,
            $gapOdata,
            'bc',
            $fetch,
            $now,
            true,
            $keyId
        );
        $liveMode = $live['mode'];
        $lastPushed = $live['pushed'];
        if (!empty($live['truncated'])) {
            $anyTruncated = true;
        }
        foreach ($live['rows'] as $row) {
            $rowKey = mimir_row_key($row['payload'], $keys);
            if (isset($seen[$rowKey])) {
                continue;
            }
            $seen[$rowKey] = true;
            $liveRows[] = $row;
        }
    }

    $merged = [];
    foreach ($cached as $row) {
        $merged[] = $row;
    }
    foreach ($liveRows as $row) {
        if (!mimir_filter_match($row['payload'], $filter, $types)) {
            continue;
        }
        $merged[] = $row;
    }

    if (!$anyTruncated && $pushed !== null && $pushed !== '') {
        mimir_coverage_put(
            $pdo,
            $environment,
            $company,
            $entity,
            $pushed,
            $fetchSelectSig,
            $now,
            count($merged),
            $keyId
        );
    }

    return [
        'rows' => $merged,
        'from_cache' => count($cached),
        'from_live' => count($liveRows),
        'mode' => $liveMode,
        'pushed' => $lastPushed,
        'shared_key_id' => $sharedKeyId,
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
    string $environment,
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
    foreach (mimir_cache_all($pdo, $environment, $company, $entity) as $stored) {
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
            $refreshed = mimir_refresh_whole_row($pdo, $environment, $company, $entity, $prefix, $stored['payload'], $keys, $types, $required, $fetch, $now);
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
    if ($top > 0 && count($rows) > $top) {
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
    string $environment,
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
            mimir_cache_delete($pdo, $environment, $company, $entity, $rowKey);
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
        mimir_cache_delete($pdo, $environment, $company, $entity, $rowKey);
    }
    mimir_cache_upsert($pdo, $environment, $company, $entity, $newKey, $fresh, $now);
    return ['payload' => $fresh, 'fetched_at' => $now];
}

/**
 * @param list<string> $keys
 * @param list<string> $select
 * @param array<string, string> $types
 * @param callable(string): array $fetch
 * @return array{rows: list<array{payload: array<string, mixed>, fetched_at: int}>, mode: string, pushed: ?string, truncated: bool}
 */
function mimir_fetch_collection(
    PDO $pdo,
    string $environment,
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
    int $now,
    bool $writeCoverage = true,
    ?int $keyId = null
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
        mimir_cache_upsert($pdo, $environment, $company, $entity, $rowKey, $clean, $now);
        $stored[] = ['payload' => $clean, 'fetched_at' => $now];
    }

    if ($writeCoverage && !$pages['truncated']) {
        $filterSig = $pushed ?? '';
        $selectSig = mimir_select_sig($select);
        mimir_coverage_put($pdo, $environment, $company, $entity, $filterSig, $selectSig, $now, count($stored), $keyId);
    }

    return ['rows' => $stored, 'mode' => $mode, 'pushed' => $pushed, 'truncated' => $pages['truncated']];
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
