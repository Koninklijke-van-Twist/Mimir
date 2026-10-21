<?php

declare(strict_types=1);

/**
 * Rijcache en dekking per environment + OData-tabel in een eigen SQLite:
 * data/cache/<environment>/<tabel>.sqlite. De meta-database (sleutels,
 * heatmap, schrijflog, metadata) blijft klein en los daarvan.
 *
 * - Een trage of kapotte tabel-database blokkeert de rest niet: bij een
 *   storage-fout krijgt alleen die database een eigen circuit
 *   (<pad>.circuit, MIMIR_CACHE_DB_CIRCUIT_SECONDS) en gaat die query live
 *   naar BC zonder cache. Het globale circuit (meta-database) blijft dicht.
 * - Dat geldt ook voor fouten ná het openen (SQLITE_BUSY, I/O, schijf vol
 *   tijdens lezen of schrijven): mimir_cache_storage_failure() zet dan het
 *   circuit van die ene tabel, nooit het globale circuit, en repareert de
 *   meta-database niet. Sleutelpagina en andere tabellen merken er niets van.
 * - Open verbindingen worden per request hergebruikt.
 */

const MIMIR_CACHE_DB_CIRCUIT_SECONDS = 30;

function mimir_cache_root(): string
{
    if (isset($GLOBALS['mimir_cache_root']) && is_string($GLOBALS['mimir_cache_root']) && $GLOBALS['mimir_cache_root'] !== '') {
        return $GLOBALS['mimir_cache_root'];
    }

    return mimir_runtime_dir() . '/cache';
}

function mimir_cache_safe_segment(string $value): string
{
    $clean = preg_replace('/[^A-Za-z0-9_.-]/', '_', trim($value));
    $clean = is_string($clean) ? trim($clean, '.') : '';
    if ($clean === '') {
        throw new RuntimeException('Ongeldige naam voor een cache-database.');
    }

    return substr($clean, 0, 120);
}

function mimir_cache_db_path(string $environment, string $entity): string
{
    // Tabelnamen zijn hoofdletterongevoelig in de cache (COLLATE NOCASE).
    return mimir_cache_root() . '/' . mimir_cache_safe_segment($environment) . '/' . mimir_cache_safe_segment(strtolower($entity)) . '.sqlite';
}

function mimir_pdo_is_memory(PDO $pdo): bool
{
    try {
        $stmt = $pdo->query('PRAGMA database_list');
        if ($stmt === false) {
            return true;
        }
        $file = '';
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (($row['name'] ?? '') === 'main') {
                $file = (string) ($row['file'] ?? '');
            }
        }
        $stmt->closeCursor();

        return $file === '';
    } catch (Throwable) {
        return true;
    }
}

function mimir_cache_db_circuit_open(string $path): bool
{
    $raw = @file_get_contents($path . '.circuit');
    if (!is_string($raw)) {
        return false;
    }
    $state = json_decode($raw, true);

    return is_array($state) && (int) ($state['until'] ?? 0) > time();
}

function mimir_cache_db_circuit_trip(string $path, string $reason): void
{
    @file_put_contents($path . '.circuit', json_encode(['until' => time() + MIMIR_CACHE_DB_CIRCUIT_SECONDS, 'reason' => mimir_event_redact($reason)]), LOCK_EX);
    @chmod($path . '.circuit', 0666);
}

/**
 * De cache-database voor environment + tabel, of null als die nu niet
 * bruikbaar is (eigen circuit open of openen faalt).
 */
function mimir_cache_db(string $environment, string $entity): ?PDO
{
    $path = mimir_cache_db_path($environment, $entity);
    $pool = $GLOBALS['mimir_cache_db_pool'] ?? [];
    if (isset($pool[$path]) && $pool[$path] instanceof PDO) {
        return $pool[$path];
    }
    if (mimir_cache_db_circuit_open($path)) {
        return null;
    }
    try {
        $pdo = mimir_db($path);
    } catch (Throwable $error) {
        mimir_cache_db_circuit_trip($path, $error->getMessage());
        mimir_event_log('sqlite', 'Cache-database niet bruikbaar, deze tabel gaat live: ' . $error->getMessage(), $environment, $entity, 'bypassed-to-BC');

        return null;
    }
    $GLOBALS['mimir_cache_db_pool'][$path] = $pdo;
    mimir_cache_db_identities()[$pdo] = $path;

    return $pdo;
}

/**
 * Welke PDO de rijcache voor deze tabel gebruikt. Een in-memory meta-PDO
 * (live bypass, tests) blijft zichzelf; anders de eigen tabel-database, of
 * bij een probleem een lege in-memory database (live, zonder cache).
 */
function mimir_cache_pdo_for(PDO $metaPdo, string $environment, string $entity): PDO
{
    if (!empty($GLOBALS['mimir_cache_split_disabled']) || mimir_pdo_is_memory($metaPdo)) {
        return $metaPdo;
    }
    $pdo = mimir_cache_db($environment, $entity);
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    return mimir_db(':memory:');
}

/**
 * Welke tabel-database achter een PDO zit, los van de pool. Een PDO die na
 * een fout uit de pool is gehaald, blijft zo herkenbaar: een tweede fout op
 * dezelfde PDO (nog een upsert, coverage, refresh) blijft per tabel en raakt
 * het globale circuit niet. WeakMap: verdwijnt de PDO, dan ook de entry.
 *
 * @return WeakMap<PDO, string>
 */
function mimir_cache_db_identities(): WeakMap
{
    if (!isset($GLOBALS['mimir_cache_db_identity']) || !$GLOBALS['mimir_cache_db_identity'] instanceof WeakMap) {
        $GLOBALS['mimir_cache_db_identity'] = new WeakMap();
    }

    return $GLOBALS['mimir_cache_db_identity'];
}

/**
 * Pad van de tabel-database achter deze PDO, of null als het geen
 * tabel-database is (meta-PDO, in-memory).
 */
function mimir_cache_db_path_of(PDO $pdo): ?string
{
    $identities = mimir_cache_db_identities();

    return isset($identities[$pdo]) ? (string) $identities[$pdo] : null;
}

/**
 * Storage-fout tijdens werk op een tabel-database: alleen die database krijgt
 * een circuit en valt uit de pool (volgende query gaat live naar BC). Het
 * globale circuit en de meta-database blijven ongemoeid. Geeft true als de
 * fout zo is afgehandeld; false als de PDO geen tabel-database is (dan
 * beslist de aanroeper, zoals voorheen, over het globale circuit).
 */
function mimir_cache_db_fail(PDO $pdo, Throwable $error, string $environment = '', string $entity = ''): bool
{
    $path = mimir_cache_db_path_of($pdo);
    if ($path === null) {
        return false;
    }
    mimir_cache_db_circuit_trip($path, $error->getMessage());
    if (($GLOBALS['mimir_cache_db_pool'][$path] ?? null) === $pdo) {
        unset($GLOBALS['mimir_cache_db_pool'][$path]);
    }
    mimir_event_log('sqlite', 'Cache-database van deze tabel faalt, tabel gaat live: ' . $error->getMessage(), $environment, $entity, 'bypassed-to-BC');

    return true;
}

/**
 * Vervanger van mimir_circuit_trip() voor fouten op de rijcache: per tabel
 * als het een tabel-database is, anders (meta-PDO zonder splitsing) globaal.
 */
function mimir_cache_storage_failure(PDO $pdo, Throwable $error, string $environment, string $entity): void
{
    $GLOBALS['mimir_stamp_bc_live'] = true;
    if (mimir_cache_db_fail($pdo, $error, $environment, $entity)) {
        return;
    }
    mimir_circuit_trip($error->getMessage());
    mimir_event_log('sqlite', $error->getMessage(), $environment, $entity, 'bypassed-to-BC');
}

/**
 * mimir_query_entity op de rijcache van één tabel. Een storage-fout in die
 * tabel-database zet alleen het circuit van die tabel en draait de query
 * opnieuw live (in-memory, zonder cache). Andere fouten, en fouten op een
 * PDO die geen tabel-database is, gaan ongewijzigd door.
 *
 * @param array<string, mixed> $job
 * @param callable(string): array $fetch
 * @return array{value: list<array<string, mixed>>, meta: array<string, mixed>}
 */
function mimir_query_entity_isolated(PDO $cachePdo, array $job, callable $fetch, int $now): array
{
    try {
        return mimir_query_entity($cachePdo, $job, $fetch, $now);
    } catch (Throwable $error) {
        if (!mimir_is_storage_failure($error)
            || !mimir_cache_db_fail($cachePdo, $error, (string) ($job['environment'] ?? ''), (string) ($job['entity'] ?? ''))) {
            throw $error;
        }
    }
    $GLOBALS['mimir_stamp_bc_live'] = true;

    return mimir_stamp_bc_live(mimir_query_entity(mimir_db(':memory:'), $job, $fetch, $now));
}

function mimir_cache_db_reset_pool(): void
{
    $GLOBALS['mimir_cache_db_pool'] = [];
}
