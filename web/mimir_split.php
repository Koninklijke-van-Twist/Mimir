<?php

declare(strict_types=1);

/**
 * Eenmalige migratie van de administratie uit het oude mimir.sqlite naar
 * data/mimir-meta.sqlite: api_keys, api_usage (heatmap, incl. kind),
 * write_log en meta_cache. De rijcache (cache_rows/cache_coverage) gaat NIET
 * mee; die loopt opnieuw vol in data/cache/<env>/<tabel>.sqlite.
 *
 * Idempotent (INSERT OR IGNORE op dezelfde id's), onder een exclusieve lock,
 * met verificatie: elke id uit de oude tabel moet in de meta-database staan.
 * Volgorde: kopiëren -> verifiëren -> marker (requests schakelen om) ->
 * wachten tot lopende requests klaar zijn -> inhaalronde -> opnieuw verifiëren.
 */

require_once __DIR__ . '/mimir_store.php';

const MIMIR_SPLIT_TABLES = ['api_keys', 'api_usage', 'write_log', 'meta_cache'];

/**
 * @return array{tables: array<string, array{old: int, copied: int, present: int}>, ok: bool}
 */
function mimir_split_copy(PDO $meta, string $oldPath): array
{
    $quoted = str_replace("'", "''", $oldPath);
    // Gewoon pad (geen URI: PDO staat URI-namen niet altijd toe). Er wordt
    // alleen uit "old" gelezen.
    $meta->exec("ATTACH DATABASE '" . $quoted . "' AS old");
    try {
        $report = ['tables' => [], 'ok' => true];
        $meta->exec('BEGIN IMMEDIATE');
        try {
            foreach (MIMIR_SPLIT_TABLES as $table) {
                $oldColumns = mimir_split_columns($meta, 'old', $table);
                if ($oldColumns === []) {
                    $report['tables'][$table] = ['old' => 0, 'copied' => 0, 'present' => 0];
                    continue;
                }
                $newColumns = mimir_split_columns($meta, 'main', $table);
                $common = array_values(array_intersect($oldColumns, $newColumns));
                $list = implode(', ', array_map(static fn (string $c): string => '"' . $c . '"', $common));
                $before = (int) $meta->query('SELECT COUNT(*) FROM main.' . $table)->fetchColumn();
                $meta->exec('INSERT OR IGNORE INTO main.' . $table . ' (' . $list . ') SELECT ' . $list . ' FROM old.' . $table);
                $after = (int) $meta->query('SELECT COUNT(*) FROM main.' . $table)->fetchColumn();
                $report['tables'][$table] = ['old' => 0, 'copied' => $after - $before, 'present' => 0];
            }
            $meta->exec('COMMIT');
        } catch (Throwable $error) {
            $meta->exec('ROLLBACK');
            throw $error;
        }
        foreach (MIMIR_SPLIT_TABLES as $table) {
            if (mimir_split_columns($meta, 'old', $table) === []) {
                continue;
            }
            $keyCol = $table === 'meta_cache' ? 'cache_key' : 'id';
            $old = (int) $meta->query('SELECT COUNT(*) FROM old.' . $table)->fetchColumn();
            $present = (int) $meta->query('SELECT COUNT(*) FROM old.' . $table . ' o JOIN main.' . $table . ' m ON m.' . $keyCol . ' = o.' . $keyCol)->fetchColumn();
            $report['tables'][$table]['old'] = $old;
            $report['tables'][$table]['present'] = $present;
            if ($present !== $old) {
                $report['ok'] = false;
            }
        }

        return $report;
    } finally {
        $meta->exec('DETACH DATABASE old');
    }
}

/**
 * @return list<string>
 */
function mimir_split_columns(PDO $pdo, string $schema, string $table): array
{
    $stmt = $pdo->query('PRAGMA ' . $schema . '.table_info(' . $table . ')');
    $columns = [];
    if ($stmt !== false) {
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $columns[] = (string) $row['name'];
        }
        $stmt->closeCursor();
    }

    return $columns;
}

/**
 * Volledige migratie. $settleSeconds: wachttijd na de marker voor lopende
 * requests die nog in het oude bestand schrijven.
 *
 * @return array{ok: bool, already: bool, first: array<string, mixed>, catchup: ?array<string, mixed>, meta: string}
 */
function mimir_split_migrate(string $dataDir, int $settleSeconds = 10, ?callable $out = null): array
{
    $out ??= static function (string $line): void {
    };
    $oldPath = $dataDir . '/mimir.sqlite';
    $metaPath = $dataDir . '/mimir-meta.sqlite';
    $marker = $dataDir . '/' . MIMIR_SPLIT_MARKER;
    if (!is_file($oldPath)) {
        throw new RuntimeException('Oud bestand niet gevonden: ' . $oldPath);
    }
    $lock = fopen($dataDir . '/mimir-split.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Er loopt al een migratie (mimir-split.lock).');
    }
    try {
        $meta = mimir_db($metaPath, 30000);
        $out('Kopiëren naar ' . $metaPath . ' …');
        $first = mimir_split_copy($meta, $oldPath);
        mimir_split_print($first, $out);
        if (!$first['ok']) {
            throw new RuntimeException('Verificatie mislukt: niet alle rijen staan in de meta-database. Marker NIET gezet.');
        }
        $already = is_file($marker);
        if (!$already) {
            file_put_contents($marker, json_encode(['migrated_at' => date('c'), 'tables' => $first['tables']]) . "\n");
            @chmod($marker, 0666);
            $out('Marker gezet; requests gebruiken nu ' . basename($metaPath) . '.');
        }
        if ($settleSeconds > 0) {
            $out('Wachten ' . $settleSeconds . ' s op lopende requests …');
            sleep($settleSeconds);
        }
        $catchup = mimir_split_copy($meta, $oldPath);
        $out('Inhaalronde:');
        mimir_split_print($catchup, $out);
        mimir_key_mirror_sync($meta);

        return ['ok' => $catchup['ok'], 'already' => $already, 'first' => $first, 'catchup' => $catchup, 'meta' => $metaPath];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * @param array{tables: array<string, array{old: int, copied: int, present: int}>, ok: bool} $report
 */
function mimir_split_print(array $report, callable $out): void
{
    foreach ($report['tables'] as $table => $row) {
        $out(sprintf('  %-11s oud %8d  gekopieerd %8d  aanwezig %8d  %s', $table, $row['old'], $row['copied'], $row['present'], $row['present'] === $row['old'] ? 'OK' : 'VERSCHIL'));
    }
}
