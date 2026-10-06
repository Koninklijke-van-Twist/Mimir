<?php

/**
 * Nachtelijke company-discovery voor Mímir.
 *
 * Productie: GET /mimir/nightly.php
 * Lokaal:    php web/nightly.php
 *
 * Schrijft de bedrijf→environment-kaart in SQLite. De UI-dropdown leest
 * die cache en doet geen live Company-discovery meer.
 */

declare(strict_types=1);

set_time_limit(600);
ini_set('max_execution_time', '600');
ignore_user_abort(true);

require_once __DIR__ . '/mimir_auth.php';
mimir_load_auth(PHP_SAPI !== 'cli');

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/logincheck.php';
}

require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/odata.php';
require_once __DIR__ . '/mimir_store.php';
require_once __DIR__ . '/mimir_filter.php';
require_once __DIR__ . '/mimir_service.php';

$nightlyOwner = '';
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
    $nightlyOwner = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
}
mimir_caller_reset();
mimir_caller_bind_named('nightly', $nightlyOwner);

$startedAt = hrtime(true);
$now = time();
$pdo = null;
register_shutdown_function(static function () use (&$pdo): void {
    if ($pdo instanceof PDO) {
        mimir_pdo_release($pdo);
        $pdo = null;
    }
    mimir_db_relax_perms(mimir_db_path());
});

try {
    $openError = null;
    for ($openAttempt = 1; $openAttempt <= 3; $openAttempt++) {
        try {
            $pdo = mimir_db(mimir_db_path());
            $openError = null;
            break;
        } catch (Throwable $error) {
            $openError = $error;
            if (!mimir_is_storage_failure($error) || $openAttempt >= 3) {
                throw $error;
            }
            mimir_db_self_repair(mimir_db_path());
            usleep(100000 * $openAttempt);
        }
    }
    if ($openError !== null || !$pdo instanceof PDO) {
        throw $openError instanceof Throwable ? $openError : new RuntimeException('SQLite openen mislukt.');
    }
    $companies = mimir_refresh_companies($pdo, $now);

    $metadata = [];
    $catalogEnvironments = mimir_catalog_environments();
    foreach (auth_get_active_environments() as $environment) {
        $envStarted = hrtime(true);
        try {
            // De metadata-catalogus (tabblad Metadata) wordt live ververst als
            // hij ontbreekt of ouder is dan MIMIR_CATALOG_REFRESH_AGE; anders
            // mag de gewone SQLite-cache van een uur antwoorden.
            $refreshCatalog = in_array($environment, $catalogEnvironments, true)
                && mimir_catalog_is_stale($environment, $now);
            $parsed = mimir_metadata_for_environment($pdo, $environment, $now, $refreshCatalog);
            $metadata[] = [
                'environment' => $environment,
                'ok' => true,
                'entity_sets' => count($parsed['entity_sets'] ?? []),
                'catalog' => $refreshCatalog ? 'refreshed' : 'fresh',
                'duration_ms' => (int) round((hrtime(true) - $envStarted) / 1_000_000),
            ];
        } catch (Throwable $error) {
            mimir_event_log(
                mimir_is_storage_failure($error) ? 'sqlite' : 'nightly',
                $error->getMessage(),
                $environment,
                '',
                'failed'
            );
            $metadata[] = [
                'environment' => $environment,
                'ok' => false,
                'error' => $error->getMessage(),
                'duration_ms' => (int) round((hrtime(true) - $envStarted) / 1_000_000),
            ];
        }
    }

    $payload = [
        'ok' => $companies['map'] !== [] && $companies['errors'] === [],
        'generated_at' => gmdate('c'),
        'companies' => $companies['companies'],
        'company_count' => count($companies['companies']),
        'errors' => $companies['errors'],
        'metadata' => $metadata,
        'total_duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
    ];

    if (PHP_SAPI === 'cli') {
        echo ($payload['ok'] ? 'OK' : 'PARTIAL')
            . ' companies=' . $payload['company_count']
            . ' duration=' . $payload['total_duration_ms'] . "ms\n";
        foreach ($payload['companies'] as $company) {
            echo '  ' . $company['name'] . ' → ' . $company['environment'] . "\n";
        }
        foreach ($payload['metadata'] as $row) {
            if (!empty($row['ok'])) {
                echo '  meta ' . $row['environment'] . ': sets=' . $row['entity_sets']
                    . ' catalog=' . $row['catalog']
                    . ' (' . $row['duration_ms'] . "ms)\n";
            } else {
                echo '  meta FAIL ' . $row['environment'] . ': ' . ($row['error'] ?? '') . "\n";
            }
        }
        foreach ($payload['errors'] as $error) {
            echo '  ERROR ' . $error . "\n";
        }
        exit($payload['ok'] ? 0 : 1);
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    http_response_code($payload['ok'] ? 200 : 207);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    if ($pdo instanceof PDO) {
        mimir_pdo_release($pdo);
        $pdo = null;
    }
    mimir_event_log(
        mimir_is_storage_failure($error) ? 'sqlite' : 'nightly',
        $error->getMessage(),
        '',
        '',
        'failed'
    );
    $payload = [
        'ok' => false,
        'generated_at' => gmdate('c'),
        'error' => $error->getMessage(),
        'total_duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
    ];
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'FAIL ' . $error->getMessage() . "\n");
        exit(1);
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    http_response_code(500);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
