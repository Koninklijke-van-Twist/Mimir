<?php

declare(strict_types=1);

/**
 * Webservice-metadata als catalogus: welke entity sets (tabellen) een
 * environment heeft, met sleutels, velden en types.
 *
 * Bron is dezelfde $metadata-parser als de verkenner (odata_parse_metadata).
 * Per environment staat één JSON-bestand onder web/data (achter
 * web/data/.htaccess, buiten de FTP-mirror). De pagina en de API lezen alleen
 * dat bestand; $metadata gaat nooit per page-load naar Business Central.
 * Verversen gebeurt in nightly.php en met de knop Vernieuwen.
 */

require_once __DIR__ . '/mimir_reliability.php';

/** nightly.php ververst als de catalogus ouder is dan dit (net onder een dag, zodat een dagelijkse run nooit een dag overslaat). */
const MIMIR_CATALOG_REFRESH_AGE = 72000;
/** Vernieuwen-knop: binnen dit aantal seconden na de vorige fetch geen nieuwe BC-call. */
const MIMIR_CATALOG_REFRESH_MIN_INTERVAL = 60;
const MIMIR_CATALOG_PAGE_SIZE = 50;

/**
 * Environments voor de catalogus: alleen $environment uit auth.php, gefilterd
 * op namen met credentials in $auth_list. Geen terugval op de volgorde van
 * $auth_list.
 *
 * @return list<string>
 */
function mimir_catalog_environments(): array
{
    $configured = $GLOBALS['environment'] ?? null;
    $authList = $GLOBALS['auth_list'] ?? null;
    if (!is_array($authList)) {
        return [];
    }
    $items = is_array($configured) ? $configured : (preg_split('/[\s,;]+/', (string) ($configured ?? '')) ?: []);
    $list = [];
    foreach ($items as $item) {
        $name = trim((string) $item);
        if ($name === '' || in_array($name, $list, true) || !isset($authList[$name])) {
            continue;
        }
        $list[] = $name;
    }

    return $list;
}

function mimir_catalog_path(string $environment): string
{
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $environment);
    if (!is_string($safe) || $safe === '') {
        $safe = 'environment';
    }

    return mimir_runtime_dir() . '/mimir-catalog-' . $safe . '.json';
}

/**
 * @param array<string, mixed> $parsed uitvoer van odata_parse_metadata
 * @return array{environment: string, fetched_at: int, entity_sets: list<array<string, mixed>>}
 */
function mimir_catalog_from_parsed(string $environment, array $parsed, int $fetchedAt): array
{
    $types = is_array($parsed['types'] ?? null) ? $parsed['types'] : [];
    $sets = [];
    foreach (is_array($parsed['entity_sets'] ?? null) ? $parsed['entity_sets'] : [] as $set) {
        if (!is_array($set) || (string) ($set['name'] ?? '') === '') {
            continue;
        }
        $typeName = (string) ($set['entity_type'] ?? '');
        $short = $typeName;
        $dot = strrpos($typeName, '.');
        if ($dot !== false) {
            $short = substr($typeName, $dot + 1);
        }
        $type = $types[$short] ?? $types[$typeName] ?? [];
        $facets = is_array($type['facets'] ?? null) ? $type['facets'] : [];
        $properties = [];
        foreach (is_array($type['properties'] ?? null) ? $type['properties'] : [] as $propName => $propType) {
            $property = [
                'name' => (string) $propName,
                'type' => (string) $propType,
                'nullable' => ($facets[$propName]['nullable'] ?? true) !== false,
            ];
            if (isset($facets[$propName]['max_length'])) {
                $property['max_length'] = (int) $facets[$propName]['max_length'];
            }
            $properties[] = $property;
        }
        $navigation = [];
        foreach (is_array($type['navigation'] ?? null) ? $type['navigation'] : [] as $nav) {
            if (is_array($nav) && (string) ($nav['name'] ?? '') !== '') {
                $navigation[] = ['name' => (string) $nav['name'], 'type' => (string) ($nav['type'] ?? '')];
            }
        }
        $sets[] = [
            'name' => (string) $set['name'],
            'entity_type' => $typeName,
            'keys' => array_values(array_map('strval', is_array($type['keys'] ?? null) ? $type['keys'] : [])),
            'properties' => $properties,
            'navigation' => $navigation,
        ];
    }
    usort($sets, static function (array $a, array $b): int {
        return strcasecmp($a['name'], $b['name']);
    });

    return [
        'environment' => $environment,
        'fetched_at' => $fetchedAt,
        'entity_sets' => $sets,
    ];
}

/**
 * @param array<string, mixed> $parsed
 */
function mimir_catalog_write(string $environment, array $parsed, int $now): void
{
    if ($environment === '' || !isset($parsed['entity_sets'], $parsed['types'])) {
        return;
    }
    mimir_json_file_write(mimir_catalog_path($environment), mimir_catalog_from_parsed($environment, $parsed, $now));
}

/**
 * Leest de catalogus. Ontbreekt het bestand nog (eerste deploy), dan bouwt
 * hij er een uit de bestaande metadata-snapshot, zonder BC te bellen.
 *
 * @return array{environment: string, fetched_at: int, entity_sets: list<array<string, mixed>>}|null
 */
function mimir_catalog_read(string $environment): ?array
{
    if ($environment === '') {
        return null;
    }
    $data = mimir_json_file_read(mimir_catalog_path($environment));
    if (isset($data['entity_sets']) && is_array($data['entity_sets']) && (int) ($data['fetched_at'] ?? 0) > 0) {
        return [
            'environment' => $environment,
            'fetched_at' => (int) $data['fetched_at'],
            'entity_sets' => array_values($data['entity_sets']),
        ];
    }
    if (!function_exists('mimir_metadata_snapshot_path')) {
        return null;
    }
    $snapshot = mimir_json_file_read(mimir_metadata_snapshot_path($environment));
    $parsed = $snapshot['parsed'] ?? null;
    $fetchedAt = (int) ($snapshot['fetched_at'] ?? 0);
    if (!is_array($parsed) || $fetchedAt < 1 || !isset($parsed['entity_sets'], $parsed['types'])) {
        return null;
    }

    return mimir_catalog_from_parsed($environment, $parsed, $fetchedAt);
}

function mimir_catalog_is_stale(string $environment, int $now, int $maxAge = MIMIR_CATALOG_REFRESH_AGE): bool
{
    $catalog = mimir_catalog_read($environment);
    if ($catalog === null) {
        return true;
    }

    return ($now - $catalog['fetched_at']) > $maxAge;
}

function mimir_catalog_lower(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function mimir_catalog_needle(string $query): string
{
    return mimir_catalog_lower(trim($query));
}

/**
 * Zoekfilter, gelijk aan de pagina (mimir.js): één zoekterm, hoofdletterongevoelig,
 * substring in de tabelnaam of in een veldnaam. Tabellen met een naammatch
 * komen eerst, daarna tabellen die alleen op een veld matchen; binnen elke
 * groep op naam. Elke treffer krijgt match.name en match.fields.
 *
 * @param list<array<string, mixed>> $sets
 * @return list<array<string, mixed>>
 */
function mimir_catalog_search(array $sets, string $query): array
{
    $needle = mimir_catalog_needle($query);
    if ($needle === '') {
        return array_values($sets);
    }
    $byName = [];
    $byField = [];
    foreach ($sets as $set) {
        $nameMatch = str_contains(mimir_catalog_lower((string) ($set['name'] ?? '')), $needle);
        $fields = [];
        foreach (is_array($set['properties'] ?? null) ? $set['properties'] : [] as $property) {
            $propName = (string) ($property['name'] ?? '');
            if ($propName !== '' && str_contains(mimir_catalog_lower($propName), $needle)) {
                $fields[] = $propName;
            }
        }
        if (!$nameMatch && $fields === []) {
            continue;
        }
        $set['match'] = ['name' => $nameMatch, 'fields' => $fields];
        if ($nameMatch) {
            $byName[] = $set;
        } else {
            $byField[] = $set;
        }
    }

    return array_merge($byName, $byField);
}

/**
 * @param list<array<string, mixed>> $sets
 * @return array<string, mixed>|null
 */
function mimir_catalog_find(array $sets, string $table): ?array
{
    $table = trim($table);
    if ($table === '') {
        return null;
    }
    foreach ($sets as $set) {
        if (strcasecmp((string) ($set['name'] ?? ''), $table) === 0) {
            return $set;
        }
    }

    return null;
}

/**
 * Paginering (1-based, maximaal 50 per pagina). Een te hoge pagina valt terug op de laatste.
 *
 * @return array{page: int, pages: int, per_page: int, offset: int, total: int}
 */
function mimir_catalog_page(int $total, int $page, int $perPage = MIMIR_CATALOG_PAGE_SIZE): array
{
    $perPage = max(1, min(MIMIR_CATALOG_PAGE_SIZE, $perPage));
    $total = max(0, $total);
    $pages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($pages, $page));

    return [
        'page' => $page,
        'pages' => $pages,
        'per_page' => $perPage,
        'offset' => ($page - 1) * $perPage,
        'total' => $total,
    ];
}

/**
 * "6 oktober 2026, 13:40" in Europe/Amsterdam, zonder intl-extensie.
 */
function mimir_dutch_datetime(int $unix): string
{
    if ($unix < 1) {
        return '';
    }
    $months = ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];
    $date = (new DateTimeImmutable('@' . $unix))->setTimezone(new DateTimeZone('Europe/Amsterdam'));

    return (int) $date->format('j') . ' ' . $months[(int) $date->format('n') - 1] . ' ' . $date->format('Y') . ', ' . $date->format('H:i');
}

function mimir_catalog_resolve_environment(string $requested): string
{
    $environments = mimir_catalog_environments();
    if ($environments === []) {
        throw new MimirUserException('Geen environments in $environment van auth.php.', 500);
    }
    $requested = trim($requested);
    if ($requested === '') {
        return $environments[0];
    }
    foreach ($environments as $environment) {
        if (strcasecmp($environment, $requested) === 0) {
            return $environment;
        }
    }

    throw new MimirUserException('Onbekend environment: ' . $requested . '. Kies uit ' . implode(', ', $environments) . '.', 404);
}

/**
 * JSON voor de pagina en api/metadata.php.
 *
 * @return array<string, mixed>
 */
function mimir_metadata_payload(string $environment, string $query = '', string $table = '', ?int $page = null, ?int $perPage = null): array
{
    $environment = mimir_catalog_resolve_environment($environment);
    $catalog = mimir_catalog_read($environment);
    $base = [
        'environment' => $environment,
        'environments' => mimir_catalog_environments(),
        'fetched_at' => $catalog['fetched_at'] ?? null,
        'fetched_at_label' => $catalog !== null ? mimir_dutch_datetime($catalog['fetched_at']) : '',
    ];
    if ($catalog === null) {
        if (trim($table) !== '') {
            throw new MimirUserException('Nog geen metadata voor ' . $environment . '. Draai nightly.php of gebruik Vernieuwen.', 404);
        }

        return $base + [
            'total' => 0,
            'matched' => 0,
            'q' => trim($query),
            'entity_sets' => [],
            'note' => 'Nog geen metadata voor ' . $environment . '. Draai nightly.php of gebruik Vernieuwen.',
        ];
    }
    $sets = $catalog['entity_sets'];
    if (trim($table) !== '') {
        $found = mimir_catalog_find($sets, $table);
        if ($found === null) {
            throw new MimirUserException('Onbekende tabel in ' . $environment . ': ' . trim($table), 404);
        }
        $sets = [$found];
    }
    $matched = mimir_catalog_search($sets, $query);
    $payload = $base + [
        'total' => count($catalog['entity_sets']),
        'matched' => count($matched),
        'q' => trim($query),
    ];
    if ($page !== null) {
        $paging = mimir_catalog_page(count($matched), $page, $perPage ?? MIMIR_CATALOG_PAGE_SIZE);
        $payload['page'] = $paging['page'];
        $payload['pages'] = $paging['pages'];
        $payload['per_page'] = $paging['per_page'];
        $matched = array_slice($matched, $paging['offset'], $paging['per_page']);
    }
    $payload['entity_sets'] = array_values($matched);

    return $payload;
}

/**
 * Leest environment, q, table, page en per_page uit de querystring.
 *
 * @return array<string, mixed>
 */
function mimir_metadata_payload_from_get(): array
{
    $page = null;
    if (isset($_GET['page']) && trim((string) $_GET['page']) !== '') {
        $page = (int) $_GET['page'];
    }
    $perPage = null;
    if (isset($_GET['per_page']) && trim((string) $_GET['per_page']) !== '') {
        $perPage = (int) $_GET['per_page'];
        $page ??= 1;
    }

    return mimir_metadata_payload(
        (string) ($_GET['environment'] ?? ''),
        (string) ($_GET['q'] ?? ''),
        (string) ($_GET['table'] ?? ''),
        $page,
        $perPage
    );
}

/**
 * De volledige catalogus is ongeveer 1 MB JSON (±80 kB gzip). Comprimeer als
 * de client dat accepteert en PHP of Apache het niet al doet.
 */
function mimir_metadata_compress_output(): void
{
    if (PHP_SAPI === 'cli' || headers_sent() || !function_exists('ob_gzhandler')) {
        return;
    }
    if (filter_var(ini_get('zlib.output_compression'), FILTER_VALIDATE_BOOLEAN)) {
        return;
    }
    ob_start('ob_gzhandler');
}
