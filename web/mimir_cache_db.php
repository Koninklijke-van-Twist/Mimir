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

function mimir_cache_db_reset_pool(): void
{
    $GLOBALS['mimir_cache_db_pool'] = [];
}
