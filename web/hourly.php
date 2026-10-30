<?php

/**
 * Elk uur draaien (cron). Doet alleen iets in het weekend (Europe/Amsterdam):
 * weekendverversing van grote delta-tabellen in 48 blokken, zaterdag 00:00 t/m
 * zondag 23:00 (zie mimir_rolling_refresh). Gemiste uren worden ingehaald.
 *
 * Productie: GET /mimir/hourly.php
 * Lokaal:    php web/hourly.php
 */

declare(strict_types=1);

set_time_limit(900);
ignore_user_abort(true);

require_once __DIR__ . '/mimir_auth.php';
mimir_load_auth(PHP_SAPI !== 'cli');
if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/logincheck.php';
}
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/odata.php';
require_once __DIR__ . '/mimir_store.php';
require_once __DIR__ . '/mimir_service.php';

mimir_caller_reset();
mimir_caller_bind_named('hourly', '');
mimir_event_log_prune();

$now = time();
$local = new DateTimeImmutable('@' . $now);
$local = $local->setTimezone(new DateTimeZone('Europe/Amsterdam'));
$day = (int) $local->format('N'); // 6 = zaterdag, 7 = zondag
$lines = [];
if ($day >= 6) {
    $slot = ($day === 7 ? 24 : 0) + (int) $local->format('G');
    $weekendStart = $local->modify($day === 7 ? 'yesterday' : 'today')->setTime(0, 0)->getTimestamp();
    $pdo = mimir_db(mimir_db_path());
    foreach (auth_get_active_environments() as $environment) {
        try {
            $metadata = mimir_metadata_for_environment($pdo, $environment, $now);
        } catch (Throwable $error) {
            $lines[] = $environment . ': metadata mislukt: ' . $error->getMessage();
            continue;
        }
        foreach (MIMIR_ROLLING_REFRESH_TABLES as $table) {
            try {
                $schema = mimir_schema_for_set($metadata, $table);
            } catch (Throwable $error) {
                continue;
            }
            $cachePdo = mimir_cache_pdo_for($pdo, $environment, $schema['name']);
            // Alleen bedrijven die voor deze tabel al delta gebruiken.
            $stmt = $cachePdo->prepare('SELECT DISTINCT cache_key FROM meta_cache WHERE cache_key LIKE :p');
            $stmt->execute([':p' => 'delta:%|' . $schema['name'] . '|%']);
            $companies = [];
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $cacheKey) {
                $companies[explode('|', substr((string) $cacheKey, 6))[0]] = true;
            }
            $auth = auth_get_auth_for_environment($environment);
            $fetch = static fn (string $url): array => mimir_bc_with_slot($environment, static fn (): array => odata_get_json($url, $auth));
            foreach (array_keys($companies) as $company) {
                $job = [
                    'environment' => $environment, 'company' => (string) $company, 'entity' => $schema['name'],
                    'prefix' => mimir_odata_prefix_for_environment($environment),
                    'keys' => $schema['keys'], 'types' => $schema['properties'],
                ];
                try {
                    $status = mimir_rolling_refresh($cachePdo, $job, $fetch, $slot, $weekendStart, $now);
                } catch (Throwable $error) {
                    $status = 'mislukt: ' . $error->getMessage();
                }
                mimir_event_log('delta', 'weekendverversing ' . $status, $environment, $schema['name'], 'rolling');
                $lines[] = $environment . ' ' . $company . ' ' . $schema['name'] . ': ' . $status;
            }
        }
    }
}

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}
echo ($lines === [] ? 'niets te doen' : implode("\n", $lines)) . "\n";
