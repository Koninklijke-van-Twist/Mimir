<?php

declare(strict_types=1);

$web = dirname(__DIR__) . '/web';

// Load store only; redefine catalog helpers inline like the service.
require_once $web . '/mimir_filter.php';
require_once $web . '/odata.php';
require_once $web . '/mimir_heatmap.php';
require_once $web . '/mimir_store.php';

function auth_get_environment_key_fragment(): string
{
    return 'test-envs';
}

function mimir_companies_from_map(array $map): array
{
    $companies = [];
    foreach ($map as $name => $environment) {
        $companies[] = ['name' => (string) $name, 'environment' => (string) $environment];
    }
    usort($companies, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return $companies;
}

function mimir_company_cache_key(): string
{
    return 'company-map:' . auth_get_environment_key_fragment();
}

function mimir_refresh_companies(PDO $pdo, int $now): array
{
    $map = [
        'Koninklijke van Twist' => 'kvtmdlive_aad',
        'KVT Germany GmbH' => 'kvtgermanylive_aad',
    ];
    mimir_meta_put($pdo, mimir_company_cache_key(), [
        'map' => $map,
        'errors' => [],
        'source' => 'nightly',
    ], $now);
    $GLOBALS['demeter_company_environment_map'] = $map;
    return [
        'companies' => mimir_companies_from_map($map),
        'map' => $map,
        'errors' => [],
        'fetched_at' => $now,
    ];
}

function mimir_company_catalog(PDO $pdo, int $now, bool $allowLive = false): array
{
    $cached = mimir_meta_get_raw($pdo, mimir_company_cache_key());
    if (is_array($cached) && isset($cached['map']) && is_array($cached['map']) && $cached['map'] !== []) {
        return [
            'companies' => mimir_companies_from_map($cached['map']),
            'errors' => [],
            'fetched_at' => (int) ($cached['_fetched_at'] ?? 0),
            'source' => 'nightly',
        ];
    }
    if (!$allowLive) {
        return ['companies' => [], 'errors' => ['empty'], 'fetched_at' => null, 'source' => 'empty'];
    }
    return mimir_refresh_companies($pdo, $now) + ['source' => 'live'];
}

$pdo = mimir_db(':memory:');
$now = time();
$empty = mimir_company_catalog($pdo, $now, false);
if ($empty['source'] !== 'empty') {
    fwrite(STDERR, "expected empty\n");
    exit(1);
}
mimir_refresh_companies($pdo, $now);
$cached = mimir_company_catalog($pdo, $now + 99, false);
if (count($cached['companies']) !== 2 || $cached['source'] !== 'nightly') {
    fwrite(STDERR, "cache failed\n");
    exit(1);
}
echo "ok\n";
