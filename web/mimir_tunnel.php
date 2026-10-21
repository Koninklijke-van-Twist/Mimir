<?php

declare(strict_types=1);

/**
 * Mimir-Tunnel: extra route naar de BC-webclient, naast OData.
 *
 * Zonder $mimir_tunnel_url én $mimir_tunnel_token in auth.php doet deze
 * module niets en blijft elke read op OData. Mét config gaan alle reads
 * (lijst, bulk, kaart) via de tunnel. `route: odata` dwingt OData af;
 * `route: tunnel` dwingt de tunnel af en valt niet terug op OData.
 *
 * Schrijven (etag, schrijflog) blijft OData: een mislukte tunnel-mutatie
 * mag niet daarna nog eens via OData, want de wijziging kan al in BC staan.
 */

const MIMIR_TUNNEL_PAGE_TTL = 86400;
/** top=0: de bridge leest in stukken van 50 en kapt zelf af (truncated). */
const MIMIR_TUNNEL_UNBOUNDED_TOP = 10000;
const MIMIR_TUNNEL_COOLDOWN = 120;
const MIMIR_TUNNEL_FAIL_LIMIT = 3;
const MIMIR_TUNNEL_WEB_SERVICES_PAGE = 810;
const MIMIR_TUNNEL_STALE_LOG_SECONDS = 3600;

class MimirTunnelFallback extends RuntimeException
{
}

/** Geforceerde tunnel (`route: tunnel`): de aanroeper mag niet op OData terugvallen. */
class MimirTunnelForced extends RuntimeException
{
}

class MimirTunnelOutage extends RuntimeException
{
}

class MimirTunnelWrongPage extends RuntimeException
{
}

class MimirTunnelUnknownColumn extends RuntimeException
{
}

/**
 * @return array{enabled: bool, url: string, token: string, cooldown: int}
 */
function mimir_tunnel_config(): array
{
    $url = trim((string) ($GLOBALS['mimir_tunnel_url'] ?? ''));
    $token = trim((string) ($GLOBALS['mimir_tunnel_token'] ?? ''));
    $cooldown = (int) ($GLOBALS['mimir_tunnel_cooldown'] ?? MIMIR_TUNNEL_COOLDOWN);
    if ($cooldown < 1) {
        $cooldown = 1;
    }

    return [
        'enabled' => $url !== '' && $token !== '',
        'url' => rtrim($url, '/'),
        'token' => $token,
        'cooldown' => $cooldown,
    ];
}

function mimir_tunnel_configured(): bool
{
    return mimir_tunnel_config()['enabled'];
}

function mimir_tunnel_now(): int
{
    if (isset($GLOBALS['mimir_tunnel_now']) && is_callable($GLOBALS['mimir_tunnel_now'])) {
        return (int) ($GLOBALS['mimir_tunnel_now'])();
    }

    return time();
}

function mimir_tunnel_cache_key(string $environment): string
{
    return 'tunnel-pages:' . trim($environment);
}

/**
 * Elke read (lijst, bulk, kaart) gaat via de tunnel zodra die geconfigureerd is.
 * `action` zit in de keuze zodat een latere aanroep dezelfde regel volgt;
 * write.php gebruikt hem niet (etag, geen dubbele mutatie).
 *
 * @param ?string $prefer null = automatisch, `odata` of `tunnel` uit het verzoek
 */
function mimir_tunnel_route_for(string $kind, ?string $prefer = null): string
{
    if ($prefer === 'odata' || !mimir_tunnel_configured()) {
        return 'odata';
    }
    if ($kind === 'list' || $kind === 'bulk' || $kind === 'card' || $kind === 'action') {
        return 'tunnel';
    }

    return 'odata';
}

/**
 * `route` op een query. null = automatisch (tunnel als die geconfigureerd is).
 */
function mimir_tunnel_normalize_route(mixed $route): ?string
{
    if ($route === null || $route === '') {
        return null;
    }
    if (!is_string($route)) {
        throw new MimirUserException('route moet "odata" of "tunnel" zijn.');
    }
    $value = strtolower(trim($route));
    if ($value !== 'odata' && $value !== 'tunnel') {
        throw new MimirUserException('route moet "odata" of "tunnel" zijn.');
    }
    if ($value === 'tunnel' && !mimir_tunnel_configured()) {
        throw new MimirUserException('route "tunnel" kan niet: de Mimir-Tunnel is niet geconfigureerd.');
    }

    return $value;
}

function mimir_bc_route_remember(string $route, ?int $page = null): void
{
    $current = $GLOBALS['mimir_bc_route'] ?? null;
    if (!is_string($current) || $current === '' || $current === 'cache') {
        $GLOBALS['mimir_bc_route'] = $route;
    } elseif ($current !== $route && $current !== 'mixed') {
        $GLOBALS['mimir_bc_route'] = 'mixed';
    }
    if ($page !== null && $page > 0) {
        $GLOBALS['mimir_bc_route_page'] = $page;
    }
}

/**
 * @return array{kind: string, top: int, filter: ?string, predicate: ?string}
 */
function mimir_tunnel_inspect_url(string $url, string $entity): array
{
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return ['kind' => 'bulk', 'top' => 0, 'filter' => null, 'predicate' => null];
    }
    $path = rawurldecode((string) ($parts['path'] ?? ''));
    $query = [];
    if (isset($parts['query']) && is_string($parts['query'])) {
        foreach (explode('&', $parts['query']) as $pair) {
            if ($pair === '') {
                continue;
            }
            $bits = explode('=', $pair, 2);
            $key = rawurldecode($bits[0]);
            $query[$key] = rawurldecode($bits[1] ?? '');
        }
    }
    $quoted = preg_quote($entity, '#');
    if (preg_match('#/' . $quoted . '\((.*)\)\s*$#', $path, $match) === 1) {
        return ['kind' => 'card', 'top' => 1, 'filter' => null, 'predicate' => $match[1]];
    }
    $top = 0;
    if (isset($query['$top']) && is_numeric($query['$top'])) {
        $top = (int) $query['$top'];
    }
    $filter = isset($query['$filter']) && $query['$filter'] !== '' ? $query['$filter'] : null;
    return [
        'kind' => $top >= 1 ? 'list' : 'bulk',
        'top' => $top,
        'filter' => $filter,
        'predicate' => null,
    ];
}

/**
 * @param array{environment: string, company: string, entity: string, types: array<string, string>, pdo: ?PDO, now: int, key_id: ?string, route: ?string} $ctx
 * @return array<string, mixed>|null null = deze URL hoort op OData
 */
function mimir_tunnel_try(string $url, array $ctx): ?array
{
    $prefer = isset($ctx['route']) && is_string($ctx['route']) ? $ctx['route'] : null;
    $forced = $prefer === 'tunnel';
    if (!mimir_tunnel_configured()) {
        if ($forced) {
            throw new MimirTunnelForced('de tunnel is niet geconfigureerd');
        }

        return null;
    }
    if ($prefer === 'odata') {
        return null;
    }
    if (!$forced && mimir_tunnel_circuit_open()) {
        return null;
    }
    $inspected = mimir_tunnel_inspect_url($url, (string) $ctx['entity']);
    if (mimir_tunnel_route_for($inspected['kind'], $prefer) !== 'tunnel') {
        if ($forced) {
            throw new MimirTunnelForced('deze aanroep is niet via de tunnel te lezen');
        }

        return null;
    }
    try {
        $result = mimir_tunnel_read($inspected, $ctx);
    } catch (MimirTunnelOutage $error) {
        mimir_tunnel_circuit_fail((string) $ctx['environment'], (string) $ctx['entity'], $error->getMessage());
        if ($forced) {
            throw new MimirTunnelForced($error->getMessage(), 0, $error);
        }
        throw new MimirTunnelFallback($error->getMessage(), 0, $error);
    } catch (MimirTunnelFallback $error) {
        if ($forced) {
            throw new MimirTunnelForced($error->getMessage(), 0, $error);
        }
        throw $error;
    }
    mimir_tunnel_circuit_success();
    mimir_bc_route_remember('tunnel', (int) $result['page_id']);

    return $result['odata'];
}

/**
 * @param array{kind: string, top: int, filter: ?string, predicate: ?string} $inspected
 * @param array{environment: string, company: string, entity: string, types: array<string, string>, pdo: ?PDO, now: int, key_id: ?string} $ctx
 * @return array{page_id: int, odata: array<string, mixed>}
 */
function mimir_tunnel_read(array $inspected, array $ctx): array
{
    $pdo = $ctx['pdo'] ?? null;
    if (!$pdo instanceof PDO) {
        throw new MimirTunnelFallback('geen meta-cache voor de paginakoppeling');
    }
    $page = mimir_tunnel_resolve_page($ctx);
    $triedWrong = false;
    $triedColumn = false;
    for ($attempt = 0; $attempt < 4; $attempt++) {
        try {
            $filters = mimir_tunnel_filters_for($inspected, $page);
            if ($filters === null) {
                if (!$triedColumn && mimir_tunnel_inspect_has_filter($inspected)) {
                    $triedColumn = true;
                    $page = mimir_tunnel_recover_page($page, $ctx, $triedWrong);
                    continue;
                }
                throw new MimirTunnelFallback('filter niet naar de tunnel te vertalen');
            }
            $top = $inspected['kind'] === 'card' ? 5 : (int) $inspected['top'];
            if ($top < 1) {
                $top = MIMIR_TUNNEL_UNBOUNDED_TOP;
            }
            $body = mimir_tunnel_get_page((int) $page['page_id'], (string) $ctx['company'], $filters, $top, $ctx['key_id'] ?? null);
            $page = mimir_tunnel_remember_captions($page, $body, $ctx);
            $odata = mimir_tunnel_odata_shape($inspected, $body, $ctx['types']);

            return ['page_id' => (int) $page['page_id'], 'odata' => $odata];
        } catch (MimirTunnelWrongPage $error) {
            if ($triedWrong) {
                throw new MimirTunnelFallback($error->getMessage(), 0, $error);
            }
            $triedWrong = true;
            $refreshed = mimir_tunnel_refresh_one($ctx);
            $newId = is_array($refreshed) ? (int) ($refreshed['page_id'] ?? 0) : 0;
            if ($refreshed === null || $newId < 1 || $newId === (int) $page['page_id']) {
                throw new MimirTunnelFallback($error->getMessage(), 0, $error);
            }
            $page = $refreshed;
        } catch (MimirTunnelUnknownColumn $error) {
            if ($triedColumn) {
                throw new MimirTunnelFallback($error->getMessage(), 0, $error);
            }
            $triedColumn = true;
            $page = mimir_tunnel_recover_page($page, $ctx, $triedWrong);
        }
    }

    throw new MimirTunnelFallback('tunnel gaf geen leesbaar antwoord');
}

/**
 * Eén caption-probe. Een verkeerde pagina daarbij ververst alleen díe koppeling.
 *
 * @param array<string, mixed> $entry
 * @param array{environment: string, company: string, entity: string, types: array<string, string>, pdo: ?PDO, now: int, key_id: ?string} $ctx
 * @return array<string, mixed>
 */
function mimir_tunnel_recover_page(array $entry, array $ctx, bool &$triedWrong): array
{
    try {
        return mimir_tunnel_probe_captions($entry, $ctx);
    } catch (MimirTunnelWrongPage $error) {
        if ($triedWrong) {
            throw new MimirTunnelFallback($error->getMessage(), 0, $error);
        }
        $triedWrong = true;
        $refreshed = mimir_tunnel_refresh_one($ctx);
        $newId = is_array($refreshed) ? (int) ($refreshed['page_id'] ?? 0) : 0;
        if ($refreshed === null || $newId < 1 || $newId === (int) ($entry['page_id'] ?? 0)) {
            throw new MimirTunnelFallback($error->getMessage(), 0, $error);
        }

        return $refreshed;
    }
}

/**
 * @param array{kind: string, top: int, filter: ?string, predicate: ?string} $inspected
 */
function mimir_tunnel_inspect_has_filter(array $inspected): bool
{
    if ($inspected['kind'] === 'card') {
        return trim((string) ($inspected['predicate'] ?? '')) !== '';
    }

    return trim((string) ($inspected['filter'] ?? '')) !== '';
}

/**
 * @param array{environment: string, company: string, entity: string, types: array<string, string>, pdo: ?PDO, now: int, key_id: ?string} $ctx
 * @return array<string, mixed>
 */
function mimir_tunnel_resolve_page(array $ctx): array
{
    $map = mimir_tunnel_page_map($ctx);
    $entity = (string) $ctx['entity'];
    $entry = $map['services'][$entity] ?? null;
    if (!is_array($entry)) {
        throw new MimirTunnelFallback('geen BC-pagina voor ' . $entity);
    }
    $pageId = (int) ($entry['page_id'] ?? 0);
    $type = trim((string) ($entry['object_type'] ?? ''));
    if ($pageId < 1 || ($type !== '' && !mimir_tunnel_is_page_type($type))) {
        throw new MimirTunnelFallback('geen BC-pagina voor ' . $entity);
    }

    return $entry;
}

/**
 * @param array{environment: string, company: string, entity: string, types: array<string, string>, pdo: ?PDO, now: int, key_id: ?string} $ctx
 * @return array{services: array<string, array<string, mixed>>, service_caption: ?string, stale_logged?: array<string, int>}
 */
function mimir_tunnel_page_map(array $ctx): array
{
    $pdo = $ctx['pdo'] ?? null;
    if (!$pdo instanceof PDO) {
        throw new MimirTunnelFallback('geen meta-cache voor de paginakoppeling');
    }
    $key = mimir_tunnel_cache_key((string) $ctx['environment']);
    $cached = mimir_meta_get($pdo, $key, MIMIR_TUNNEL_PAGE_TTL, (int) $ctx['now']);
    if (is_array($cached) && isset($cached['services']) && is_array($cached['services'])) {
        return $cached;
    }
    try {
        $body = mimir_tunnel_get_page(
            MIMIR_TUNNEL_WEB_SERVICES_PAGE,
            (string) $ctx['company'],
            [],
            2000,
            $ctx['key_id'] ?? null
        );
    } catch (MimirTunnelOutage $error) {
        throw $error;
    } catch (Throwable $error) {
        throw new MimirTunnelOutage($error->getMessage(), 0, $error);
    }
    $parsed = mimir_tunnel_parse_services($body);
    if ($parsed === null) {
        throw new MimirTunnelOutage('pagina 810 onleesbaar');
    }
    if (!empty($body['truncated'])) {
        mimir_event_log('tunnel', 'Pagina 810 afgekapt; koppeling mogelijk incompleet.', (string) $ctx['environment'], 'Web Services', 'truncated');
    }
    $previous = mimir_meta_get_raw($pdo, $key);
    $oldServices = is_array($previous['services'] ?? null) ? $previous['services'] : [];
    foreach ($parsed['services'] as $name => $entry) {
        if (isset($oldServices[$name]['captions']) && is_array($oldServices[$name]['captions'])) {
            $entry['captions'] = array_merge($oldServices[$name]['captions'], $entry['captions'] ?? []);
        }
        $parsed['services'][$name] = $entry;
    }
    $payload = [
        'services' => $parsed['services'],
        'service_caption' => $parsed['service_caption'],
        'stale_logged' => is_array($previous['stale_logged'] ?? null) ? $previous['stale_logged'] : [],
    ];
    mimir_meta_put($pdo, $key, $payload, (int) $ctx['now']);

    return $payload;
}

/**
 * @param array{environment: string, company: string, entity: string, types: array<string, string>, pdo: ?PDO, now: int, key_id: ?string} $ctx
 * @return array<string, mixed>|null
 */
function mimir_tunnel_refresh_one(array $ctx): ?array
{
    $pdo = $ctx['pdo'] ?? null;
    if (!$pdo instanceof PDO) {
        return null;
    }
    $key = mimir_tunnel_cache_key((string) $ctx['environment']);
    $raw = mimir_meta_get_raw($pdo, $key);
    if (!is_array($raw)) {
        $raw = ['services' => []];
    }
    $services = is_array($raw['services'] ?? null) ? $raw['services'] : [];
    $entity = (string) $ctx['entity'];
    $preferred = trim((string) ($raw['service_caption'] ?? ''));
    $captions = [];
    foreach ([$preferred, 'Service Name', 'Servicenaam'] as $caption) {
        if ($caption !== '' && !in_array($caption, $captions, true)) {
            $captions[] = $caption;
        }
    }
    $body = null;
    $usedCaption = $preferred !== '' ? $preferred : 'Service Name';
    $last = null;
    foreach ($captions as $caption) {
        try {
            $body = mimir_tunnel_get_page(
                MIMIR_TUNNEL_WEB_SERVICES_PAGE,
                (string) $ctx['company'],
                [$caption . '=' . $entity],
                20,
                $ctx['key_id'] ?? null
            );
            $usedCaption = $caption;
            break;
        } catch (MimirTunnelUnknownColumn $error) {
            $last = $error;
        }
    }
    if (!is_array($body)) {
        if ($last instanceof Throwable) {
            throw new MimirTunnelFallback($last->getMessage(), 0, $last);
        }
        throw new MimirTunnelFallback('servicenaam-kolom onbekend');
    }
    $parsed = mimir_tunnel_parse_services($body);
    if ($parsed === null) {
        throw new MimirTunnelFallback('pagina 810 onleesbaar bij verversen');
    }
    $old = is_array($services[$entity] ?? null) ? $services[$entity] : [];
    $fresh = $parsed['services'][$entity] ?? [
        'page_id' => null,
        'object_type' => '',
        'captions' => [],
    ];
    if (!is_array($fresh['captions'] ?? null) || $fresh['captions'] === []) {
        $fresh['captions'] = is_array($old['captions'] ?? null) ? $old['captions'] : [];
    }
    $services[$entity] = $fresh;
    $fetched = (int) ($raw['_fetched_at'] ?? 0);
    unset($raw['_fetched_at']);
    $raw['services'] = $services;
    $raw['service_caption'] = $parsed['service_caption'] ?? $usedCaption;
    mimir_meta_put($pdo, $key, $raw, $fetched > 0 ? $fetched : (int) $ctx['now']);

    return $fresh;
}

/**
 * @param array<string, mixed> $entry
 * @param array{environment: string, company: string, entity: string, types: array<string, string>, pdo: ?PDO, now: int, key_id: ?string} $ctx
 * @return array<string, mixed>
 */
function mimir_tunnel_probe_captions(array $entry, array $ctx): array
{
    $body = mimir_tunnel_get_page((int) $entry['page_id'], (string) $ctx['company'], [], 1, $ctx['key_id'] ?? null);

    return mimir_tunnel_remember_captions($entry, $body, $ctx);
}

/**
 * @param array<string, mixed> $entry
 * @param array<string, mixed> $body
 * @param array{environment: string, company: string, entity: string, types: array<string, string>, pdo: ?PDO, now: int, key_id: ?string} $ctx
 * @return array<string, mixed>
 */
function mimir_tunnel_remember_captions(array $entry, array $body, array $ctx): array
{
    $learned = mimir_tunnel_captions_in_body($body, $ctx['types']);
    if ($learned === []) {
        return $entry;
    }
    $current = is_array($entry['captions'] ?? null) ? $entry['captions'] : [];
    $entry['captions'] = array_merge($current, $learned);
    $pdo = $ctx['pdo'] ?? null;
    if ($pdo instanceof PDO) {
        mimir_tunnel_save_entry($pdo, (string) $ctx['environment'], (string) $ctx['entity'], $entry, (int) $ctx['now']);
    }

    return $entry;
}

/**
 * @param array<string, mixed> $entry
 */
function mimir_tunnel_save_entry(PDO $pdo, string $environment, string $entity, array $entry, int $now): void
{
    $key = mimir_tunnel_cache_key($environment);
    $raw = mimir_meta_get_raw($pdo, $key);
    $fetched = $now;
    if (!is_array($raw)) {
        $raw = ['services' => []];
    } else {
        $fetched = (int) ($raw['_fetched_at'] ?? $now);
        unset($raw['_fetched_at']);
    }
    if (!isset($raw['services']) || !is_array($raw['services'])) {
        $raw['services'] = [];
    }
    $raw['services'][$entity] = $entry;
    mimir_meta_put($pdo, $key, $raw, $fetched > 0 ? $fetched : $now);
}

/**
 * @param array<string, mixed> $body
 * @return array{services: array<string, array<string, mixed>>, service_caption: ?string}|null
 */
function mimir_tunnel_parse_services(array $body): ?array
{
    $rows = [];
    foreach ($body['rows'] ?? [] as $row) {
        if (is_array($row) && is_array($row['cells'] ?? null)) {
            $rows[] = $row['cells'];
        }
    }
    if ($rows === []) {
        return ['services' => [], 'service_caption' => null];
    }
    $captions = [];
    foreach ($rows as $cells) {
        foreach (array_keys($cells) as $caption) {
            if (is_string($caption) && !in_array($caption, $captions, true)) {
                $captions[] = $caption;
            }
        }
    }
    $serviceCol = mimir_tunnel_match_column($captions, 'service');
    $idCol = mimir_tunnel_match_column($captions, 'object_id');
    $typeCol = mimir_tunnel_match_column($captions, 'object_type');
    $publishedCol = mimir_tunnel_match_column($captions, 'published');
    if ($serviceCol === null || $idCol === null) {
        return null;
    }
    $services = [];
    foreach ($rows as $cells) {
        $name = trim((string) ($cells[$serviceCol] ?? ''));
        if ($name === '') {
            continue;
        }
        if (preg_match('/\d+/', (string) ($cells[$idCol] ?? ''), $idMatch) !== 1) {
            continue;
        }
        $id = (int) $idMatch[0];
        $type = $typeCol !== null ? trim((string) ($cells[$typeCol] ?? '')) : '';
        $published = $publishedCol === null || mimir_tunnel_is_published($cells[$publishedCol] ?? null);
        $isPage = $type === '' || mimir_tunnel_is_page_type($type);
        $candidate = [
            'page_id' => ($isPage && $id > 0) ? $id : null,
            'object_type' => $type,
            'captions' => [],
            'published' => $published,
        ];
        if (!isset($services[$name]) || !is_array($services[$name])) {
            $services[$name] = $candidate;
            continue;
        }
        $current = $services[$name];
        $currentPublished = !empty($current['published']);
        $replace = ($published && !$currentPublished)
            || ($published === $currentPublished && $candidate['page_id'] !== null && empty($current['page_id']));
        if ($replace) {
            $services[$name] = $candidate;
        }
    }

    return ['services' => $services, 'service_caption' => $serviceCol];
}

/**
 * @param list<string> $captions
 */
function mimir_tunnel_match_column(array $captions, string $role): ?string
{
    $aliases = [
        'service' => ['service_name', 'servicename', 'servicenaam'],
        'object_id' => ['object_id', 'objectid', 'object_nr', 'objectnr'],
        'object_type' => ['object_type', 'objecttype', 'objectsoort'],
        'published' => ['published', 'gepubliceerd'],
    ];
    $normalized = [];
    foreach ($captions as $caption) {
        $normalized[$caption] = strtolower(mimir_tunnel_caption_to_odata($caption));
    }
    foreach ($aliases[$role] ?? [] as $alias) {
        foreach ($normalized as $caption => $name) {
            if ($name === $alias) {
                return $caption;
            }
        }
    }
    foreach ($normalized as $caption => $name) {
        if ($role === 'service' && (str_contains($name, 'service') || $name === 'servicenaam')) {
            return $caption;
        }
        if ($role === 'object_id' && str_contains($name, 'object') && (str_contains($name, 'id') || str_contains($name, 'nr'))) {
            return $caption;
        }
        if ($role === 'object_type' && str_contains($name, 'object') && (str_contains($name, 'type') || str_contains($name, 'soort'))) {
            return $caption;
        }
        if ($role === 'published' && (str_contains($name, 'publish') || str_contains($name, 'gepubliceerd'))) {
            return $caption;
        }
    }

    return null;
}

function mimir_tunnel_is_page_type(string $type): bool
{
    $text = strtolower(trim($type));

    return $text === 'page' || $text === 'pagina';
}

function mimir_tunnel_is_published(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value)) {
        return $value === 1;
    }
    $text = strtolower(trim((string) $value));

    return in_array($text, ['1', 'true', 'yes', 'ja', 'published', 'gepubliceerd'], true);
}

function mimir_tunnel_caption_to_odata(string $caption): string
{
    $out = '';
    $length = strlen($caption);
    for ($i = 0; $i < $length; $i++) {
        $char = $caption[$i];
        if ($char === '.') {
            continue;
        }
        $out .= ctype_alnum($char) ? $char : '_';
    }

    return $out;
}

function mimir_tunnel_guess_caption(string $field): string
{
    if (!str_contains($field, '_')) {
        return strcasecmp($field, 'No') === 0 ? 'No.' : $field;
    }
    $spaced = str_replace('_', ' ', $field);
    if (str_ends_with($spaced, ' No')) {
        return substr($spaced, 0, -3) . ' No.';
    }

    return $spaced;
}

/**
 * @param array<string, mixed> $entry
 */
function mimir_tunnel_caption_for(string $field, array $entry): string
{
    $known = $entry['captions'] ?? null;
    if (is_array($known) && isset($known[$field]) && is_string($known[$field]) && $known[$field] !== '') {
        return $known[$field];
    }

    return mimir_tunnel_guess_caption($field);
}

/**
 * @param array{kind: string, top: int, filter: ?string, predicate: ?string} $inspected
 * @param array<string, mixed> $entry
 * @return list<string>|null
 */
function mimir_tunnel_filters_for(array $inspected, array $entry): ?array
{
    if ($inspected['kind'] === 'card') {
        $predicate = trim((string) ($inspected['predicate'] ?? ''));
        if ($predicate === '') {
            return [];
        }

        return mimir_tunnel_predicate_to_filters($predicate, $entry);
    }
    $filter = trim((string) ($inspected['filter'] ?? ''));
    if ($filter === '') {
        return [];
    }

    return mimir_tunnel_odata_to_filters($filter, $entry);
}

/**
 * @param array<string, mixed> $entry
 * @return list<string>|null
 */
function mimir_tunnel_odata_to_filters(string $odata, array $entry): ?array
{
    $odata = trim($odata);
    if ($odata === '') {
        return [];
    }
    $out = [];
    foreach (mimir_tunnel_split_on($odata, ' and ') as $part) {
        $part = mimir_tunnel_unwrap(trim($part));
        if ($part === '') {
            return null;
        }
        $leaves = [];
        foreach (mimir_tunnel_split_on($part, ' or ') as $orPart) {
            $leaf = mimir_tunnel_parse_leaf(mimir_tunnel_unwrap(trim($orPart)));
            if ($leaf === null) {
                return null;
            }
            $leaves[] = $leaf;
        }
        if (count($leaves) > 1) {
            $field = (string) $leaves[0]['field'];
            $values = [];
            foreach ($leaves as $leaf) {
                if ($leaf['op'] !== 'eq' || $leaf['field'] !== $field || $leaf['value'] === null) {
                    return null;
                }
                $text = (string) $leaf['value'];
                if (str_contains($text, '|')) {
                    return null;
                }
                $values[] = $text;
            }
            $out[] = mimir_tunnel_caption_for($field, $entry) . '=' . implode('|', $values);
            continue;
        }
        $leaf = $leaves[0];
        if ($leaf['value'] === null) {
            return null;
        }
        $encoded = mimir_tunnel_filter_value((string) $leaf['op'], (string) $leaf['value']);
        if ($encoded === null) {
            return null;
        }
        $out[] = mimir_tunnel_caption_for((string) $leaf['field'], $entry) . '=' . $encoded;
    }

    return $out;
}

/**
 * @param array<string, mixed> $entry
 * @return list<string>|null
 */
function mimir_tunnel_predicate_to_filters(string $predicate, array $entry): ?array
{
    $out = [];
    foreach (mimir_tunnel_split_on($predicate, ',') as $part) {
        $part = trim($part);
        if ($part === '') {
            return null;
        }
        $eq = strpos($part, '=');
        if ($eq === false || $eq < 1) {
            return null;
        }
        $field = trim(substr($part, 0, $eq));
        if (!mimir_filter_field_ok($field)) {
            return null;
        }
        $raw = trim(substr($part, $eq + 1));
        if (strcasecmp($raw, 'null') === 0) {
            return null;
        }
        $value = mimir_tunnel_unquote($raw);
        if ($value === null || $value === '' || str_contains($value, '|')) {
            return null;
        }
        $out[] = mimir_tunnel_caption_for($field, $entry) . '=' . $value;
    }

    return $out === [] ? null : $out;
}

/**
 * @return array{field: string, op: string, value: ?string}|null
 */
function mimir_tunnel_parse_leaf(string $text): ?array
{
    $text = trim($text);
    if (preg_match('/^(contains|startswith|endswith)\(\s*([A-Za-z_][A-Za-z0-9_.]*)\s*,\s*(.+)\)$/i', $text, $match) === 1) {
        $raw = trim($match[3]);
        if (strcasecmp($raw, 'null') === 0) {
            return ['field' => $match[2], 'op' => strtolower($match[1]), 'value' => null];
        }
        $value = mimir_tunnel_unquote($raw);

        return $value === null ? null : ['field' => $match[2], 'op' => strtolower($match[1]), 'value' => $value];
    }
    if (preg_match('/^([A-Za-z_][A-Za-z0-9_.]*)\s+(eq|ne|gt|ge|lt|le)\s+(.+)$/i', $text, $match) === 1) {
        $raw = trim($match[3]);
        if (strcasecmp($raw, 'null') === 0) {
            return ['field' => $match[1], 'op' => strtolower($match[2]), 'value' => null];
        }
        $value = mimir_tunnel_unquote($raw);

        return $value === null ? null : ['field' => $match[1], 'op' => strtolower($match[2]), 'value' => $value];
    }

    return null;
}

function mimir_tunnel_unquote(string $literal): ?string
{
    $literal = trim($literal);
    if (strcasecmp($literal, 'null') === 0) {
        return null;
    }
    if (preg_match("/^guid'([^']*)'$/i", $literal, $match) === 1) {
        return $match[1];
    }
    if (str_starts_with($literal, "'")) {
        if (!str_ends_with($literal, "'") || strlen($literal) < 2) {
            return null;
        }

        return str_replace("''", "'", substr($literal, 1, -1));
    }

    return $literal;
}

function mimir_tunnel_filter_value(string $op, string $value): ?string
{
    if (str_contains($value, '|')) {
        return null;
    }
    if ($op === 'eq' && ($value === '' || preg_match('/^(<>|>=|<=|>|<)/', $value) === 1)) {
        return null;
    }

    return match ($op) {
        'eq' => $value,
        'ne' => '<>' . $value,
        'gt' => '>' . $value,
        'ge' => '>=' . $value,
        'lt' => '<' . $value,
        'le' => '<=' . $value,
        'contains' => '*' . $value . '*',
        'startswith' => $value . '*',
        'endswith' => '*' . $value,
        default => null,
    };
}

/**
 * @return list<string>
 */
function mimir_tunnel_split_on(string $text, string $separator): array
{
    $parts = [];
    $buffer = '';
    $depth = 0;
    $inQuote = false;
    $length = strlen($text);
    $separatorLength = strlen($separator);
    for ($i = 0; $i < $length; $i++) {
        $char = $text[$i];
        if ($inQuote) {
            $buffer .= $char;
            if ($char === "'") {
                if ($i + 1 < $length && $text[$i + 1] === "'") {
                    $buffer .= "'";
                    $i++;
                    continue;
                }
                $inQuote = false;
            }
            continue;
        }
        if ($char === "'") {
            $inQuote = true;
            $buffer .= $char;
            continue;
        }
        if ($char === '(') {
            $depth++;
            $buffer .= $char;
            continue;
        }
        if ($char === ')') {
            if ($depth > 0) {
                $depth--;
            }
            $buffer .= $char;
            continue;
        }
        if ($depth === 0 && $separatorLength > 0 && substr($text, $i, $separatorLength) === $separator) {
            $parts[] = $buffer;
            $buffer = '';
            $i += $separatorLength - 1;
            continue;
        }
        $buffer .= $char;
    }
    $parts[] = $buffer;

    return $parts;
}

function mimir_tunnel_unwrap(string $text): string
{
    $text = trim($text);
    while (str_starts_with($text, '(') && str_ends_with($text, ')')) {
        $depth = 0;
        $wrapsAll = true;
        $inQuote = false;
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            if ($inQuote) {
                if ($char === "'") {
                    if ($i + 1 < $length && $text[$i + 1] === "'") {
                        $i++;
                        continue;
                    }
                    $inQuote = false;
                }
                continue;
            }
            if ($char === "'") {
                $inQuote = true;
                continue;
            }
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth === 0 && $i !== $length - 1) {
                    $wrapsAll = false;
                    break;
                }
            }
        }
        if (!$wrapsAll || $depth !== 0) {
            break;
        }
        $text = trim(substr($text, 1, -1));
    }

    return $text;
}

/**
 * @param list<string> $filters
 * @return array<string, mixed>
 */
function mimir_tunnel_get_page(int $pageId, string $company, array $filters, int $top, ?string $keyId): array
{
    $query = ['company' => $company, 'top' => (string) max(1, $top)];
    if ($filters !== []) {
        $query['filter'] = $filters;
    }
    $response = mimir_tunnel_http('GET', '/page/' . $pageId, $query, $keyId);
    if ($response['status'] >= 200 && $response['status'] < 300) {
        if (!is_array($response['json'])) {
            throw new MimirTunnelOutage('ongeldige JSON van de tunnel');
        }

        return $response['json'];
    }
    $kind = mimir_tunnel_failure_kind($response);
    $message = mimir_tunnel_error_message($response);
    if ($kind === 'column') {
        throw new MimirTunnelUnknownColumn($message);
    }
    if ($kind === 'page') {
        throw new MimirTunnelWrongPage($message);
    }
    if ($kind === 'outage') {
        throw new MimirTunnelOutage($message);
    }

    throw new MimirTunnelFallback($message);
}

/**
 * @return 'column'|'page'|'outage'|'fallback'
 */
function mimir_tunnel_failure_kind(array $response): string
{
    if (mimir_tunnel_is_unknown_column($response)) {
        return 'column';
    }
    if (mimir_tunnel_is_wrong_page($response)) {
        return 'page';
    }
    $status = (int) ($response['status'] ?? 0);
    if ($status === 0 || $status === 401 || $status === 404 || $status >= 500) {
        return 'outage';
    }

    return 'fallback';
}

function mimir_tunnel_is_unknown_column(array $response): bool
{
    $text = strtolower(mimir_tunnel_error_message($response));

    return str_contains($text, 'filter column not found')
        || str_contains($text, 'unknown column')
        || str_contains($text, 'onbekende kolom')
        || str_contains($text, 'columnbinderpath');
}

function mimir_tunnel_is_wrong_page(array $response): bool
{
    if ((int) ($response['status'] ?? 0) === 404) {
        return false;
    }
    $json = is_array($response['json'] ?? null) ? $response['json'] : [];
    $code = strtoupper((string) ($json['code'] ?? ''));
    if ($code === '' && isset($json['error']) && is_string($json['error'])) {
        $code = strtoupper($json['error']);
    }
    if ($code === 'NOT_FOUND') {
        return true;
    }
    $text = strtolower(mimir_tunnel_error_message($response));

    return preg_match('/page.*(not found|does not exist|bestaat niet|unknown|onbekend|ongeldig)|unknown page|ongeldige pagina/', $text) === 1;
}

function mimir_tunnel_error_message(array $response): string
{
    $json = is_array($response['json'] ?? null) ? $response['json'] : [];
    $code = trim((string) ($json['code'] ?? ''));
    $message = trim((string) ($json['message'] ?? ''));
    if ($message === '' && isset($json['error']) && is_string($json['error'])) {
        $message = trim($json['error']);
    }
    if ($message === '') {
        $message = trim(substr((string) ($response['body'] ?? ''), 0, 180));
    }
    $text = trim('HTTP ' . (int) ($response['status'] ?? 0) . ' ' . $code . ' ' . $message);

    return strlen($text) > 240 ? substr($text, 0, 240) : $text;
}

/**
 * @param array<string, string|list<string>> $query
 * @return array{status: int, json: ?array, body: string}
 */
function mimir_tunnel_http(string $method, string $path, array $query, ?string $keyId): array
{
    $config = mimir_tunnel_config();
    $url = $config['url'] . $path;
    $qs = mimir_tunnel_query_string($query);
    if ($qs !== '') {
        $url .= '?' . $qs;
    }
    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . $config['token'],
    ];
    $safeKey = mimir_tunnel_key_header($keyId);
    if ($safeKey !== '') {
        $headers[] = 'X-Mimir-Key-Id: ' . $safeKey;
    }
    if (isset($GLOBALS['mimir_tunnel_transport']) && is_callable($GLOBALS['mimir_tunnel_transport'])) {
        $result = ($GLOBALS['mimir_tunnel_transport'])($method, $url, $headers);
        if (!is_array($result)) {
            return ['status' => 0, 'json' => null, 'body' => 'transport gaf geen antwoord'];
        }
        $body = (string) ($result['body'] ?? '');
        $decoded = json_decode($body, true);

        return [
            'status' => (int) ($result['status'] ?? 0),
            'json' => is_array($decoded) ? $decoded : null,
            'body' => $body,
        ];
    }
    if (!function_exists('curl_init')) {
        return ['status' => 0, 'json' => null, 'body' => 'cURL ontbreekt'];
    }
    $ch = curl_init($url);
    if ($ch === false) {
        return ['status' => 0, 'json' => null, 'body' => 'cURL kon niet worden gestart'];
    }
    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'Mimir-TunnelClient/1.0',
    ];
    $protocols = 0;
    if (defined('CURLPROTO_HTTP')) {
        $protocols |= CURLPROTO_HTTP;
    }
    if (defined('CURLPROTO_HTTPS')) {
        $protocols |= CURLPROTO_HTTPS;
    }
    if ($protocols !== 0) {
        $options[CURLOPT_PROTOCOLS] = $protocols;
    }
    curl_setopt_array($ch, $options);
    try {
        $raw = curl_exec($ch);
        if ($raw === false) {
            return ['status' => 0, 'json' => null, 'body' => curl_error($ch)];
        }
        $body = (string) $raw;
        $decoded = json_decode($body, true);

        return [
            'status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'json' => is_array($decoded) ? $decoded : null,
            'body' => $body,
        ];
    } finally {
        curl_close($ch);
    }
}

/**
 * @param array<string, string|list<string>> $query
 */
function mimir_tunnel_query_string(array $query): string
{
    $parts = [];
    foreach ($query as $key => $value) {
        if ($key === 'filter' && is_array($value)) {
            foreach ($value as $filter) {
                $parts[] = 'filter=' . rawurlencode((string) $filter);
            }
            continue;
        }
        if ($value === null || $value === '' || is_array($value)) {
            continue;
        }
        $parts[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
    }

    return implode('&', $parts);
}

function mimir_tunnel_key_header(?string $keyId): string
{
    $keyId = trim((string) $keyId);
    if ($keyId === '') {
        return '';
    }
    $safe = preg_replace('/[^A-Za-z0-9._:@-]/', '', $keyId);
    if (!is_string($safe) || $safe === '') {
        return '';
    }

    return substr($safe, 0, 80);
}

/**
 * @param array{kind: string, top: int, filter: ?string, predicate: ?string} $inspected
 * @param array<string, mixed> $body
 * @param array<string, string> $types
 * @return array<string, mixed>
 */
function mimir_tunnel_odata_shape(array $inspected, array $body, array $types): array
{
    $records = mimir_tunnel_records($body, $types);
    if ($inspected['kind'] === 'card') {
        if ($records === []) {
            throw new MimirTunnelFallback('lege kaart');
        }

        return $records[0];
    }
    $payload = ['value' => $records];
    if (!empty($body['truncated'])) {
        $payload['@mimir.truncated'] = true;
    }

    return $payload;
}

/**
 * @param array<string, mixed> $body
 * @param array<string, string> $types
 * @return list<array<string, mixed>>
 */
function mimir_tunnel_records(array $body, array $types): array
{
    $records = [];
    $rows = $body['rows'] ?? null;
    if (is_array($rows)) {
        foreach ($rows as $row) {
            if (!is_array($row) || !is_array($row['cells'] ?? null) || $row['cells'] === []) {
                continue;
            }
            $record = mimir_tunnel_cells_to_record($row['cells'], $types);
            if ($record !== []) {
                $records[] = $record;
            }
        }
    }
    if ($records === [] && is_array($body['fields'] ?? null)) {
        $cells = [];
        foreach ($body['fields'] as $field) {
            if (!is_array($field) || !isset($field['name']) || !is_string($field['name'])) {
                continue;
            }
            $cells[$field['name']] = $field['value'] ?? null;
        }
        if ($cells !== []) {
            $records[] = mimir_tunnel_cells_to_record($cells, $types);
        }
    }

    return $records;
}

/**
 * @param array<string, mixed> $cells
 * @param array<string, string> $types
 * @return array<string, mixed>
 */
function mimir_tunnel_cells_to_record(array $cells, array $types): array
{
    $byNorm = [];
    foreach ($cells as $caption => $value) {
        if (!is_string($caption) || $caption === '') {
            continue;
        }
        $byNorm[strtolower(mimir_tunnel_caption_to_odata($caption))] = $value;
    }
    $record = [];
    foreach ($types as $name => $type) {
        if (!is_string($name)) {
            continue;
        }
        $norm = strtolower(mimir_tunnel_caption_to_odata($name));
        if (!array_key_exists($norm, $byNorm)) {
            $norm = strtolower(mimir_tunnel_caption_to_odata(mimir_tunnel_guess_caption($name)));
        }
        if (!array_key_exists($norm, $byNorm)) {
            continue;
        }
        $record[$name] = mimir_tunnel_coerce($byNorm[$norm], (string) $type);
    }

    return $record;
}

/**
 * @param array<string, mixed> $body
 * @param array<string, string> $types
 * @return array<string, string>
 */
function mimir_tunnel_captions_in_body(array $body, array $types): array
{
    $cells = [];
    foreach ($body['rows'] ?? [] as $row) {
        if (is_array($row) && is_array($row['cells'] ?? null)) {
            foreach ($row['cells'] as $caption => $value) {
                if (is_string($caption)) {
                    $cells[$caption] = $value;
                }
            }
        }
    }
    foreach ($body['fields'] ?? [] as $field) {
        if (is_array($field) && isset($field['name']) && is_string($field['name'])) {
            $cells[$field['name']] = $field['value'] ?? null;
        }
    }
    $map = [];
    $byNorm = [];
    foreach ($cells as $caption => $value) {
        unset($value);
        $byNorm[strtolower(mimir_tunnel_caption_to_odata($caption))] = $caption;
    }
    foreach ($types as $name => $type) {
        unset($type);
        if (!is_string($name)) {
            continue;
        }
        $norm = strtolower(mimir_tunnel_caption_to_odata($name));
        if (!isset($byNorm[$norm])) {
            $norm = strtolower(mimir_tunnel_caption_to_odata(mimir_tunnel_guess_caption($name)));
        }
        if (isset($byNorm[$norm])) {
            $map[$name] = $byNorm[$norm];
        }
    }

    return $map;
}

function mimir_tunnel_coerce(mixed $value, string $type): mixed
{
    if ($value === null) {
        return null;
    }
    $edm = strtolower($type);
    if (is_string($value) && trim($value) === '') {
        return str_contains($edm, 'string') ? '' : null;
    }
    if (str_contains($edm, 'boolean')) {
        if (is_bool($value)) {
            return $value;
        }
        $bool = function_exists('mimir_bool_value') ? mimir_bool_value($value) : null;
        if ($bool !== null) {
            return $bool;
        }
        $text = strtolower(trim((string) $value));
        if (in_array($text, ['ja', 'yes'], true)) {
            return true;
        }
        if (in_array($text, ['nee', 'no'], true)) {
            return false;
        }

        return $value;
    }
    $numeric = preg_match('/int|decimal|double|single|float|byte/', $edm) === 1;
    if (!$numeric) {
        return $value;
    }
    $integer = str_contains($edm, 'int') && preg_match('/decimal|double|single|float/', $edm) !== 1;
    if (is_int($value)) {
        return $integer ? $value : (float) $value;
    }
    if (is_float($value)) {
        return $integer ? (int) $value : $value;
    }
    if (!is_string($value)) {
        return $value;
    }
    $text = trim($value);
    if (preg_match('/^-?\d+$/', $text) === 1) {
        return $integer ? (int) $text : (float) $text;
    }
    if (preg_match('/^-?\d+,\d+$/', $text) === 1 || preg_match('/^-?\d+\.\d+$/', $text) === 1) {
        return (float) str_replace(',', '.', $text);
    }

    return $value;
}

/**
 * @param list<string> $names
 */
function mimir_tunnel_stale_successor(string $name, array $names): ?string
{
    if (preg_match('/^(.*)_(\d+)$/', $name, $match) === 1) {
        $base = $match[1];
        $current = (int) $match[2];
    } else {
        $base = $name;
        $current = 0;
    }
    if ($base === '') {
        return null;
    }
    $best = null;
    $bestNumber = $current;
    $pattern = '/^' . preg_quote($base, '/') . '_(\d+)$/';
    foreach ($names as $other) {
        if (!is_string($other) || preg_match($pattern, $other, $number) !== 1) {
            continue;
        }
        $value = (int) $number[1];
        if ($value > $bestNumber) {
            $bestNumber = $value;
            $best = $other;
        }
    }

    return $best;
}

function mimir_tunnel_stale_warning(PDO $pdo, string $environment, string $name, int $now): ?string
{
    $raw = mimir_meta_get_raw($pdo, mimir_tunnel_cache_key($environment));
    if (!is_array($raw)) {
        return null;
    }
    $fetched = (int) ($raw['_fetched_at'] ?? 0);
    if ($fetched < 1 || ($now - $fetched) > MIMIR_TUNNEL_PAGE_TTL) {
        return null;
    }
    $services = is_array($raw['services'] ?? null) ? $raw['services'] : [];
    $successor = mimir_tunnel_stale_successor($name, array_keys($services));
    if ($successor === null) {
        return null;
    }
    $message = 'Verouderde webservicenaam ' . $name . '; nieuwere variant ' . $successor . '.';
    $logged = is_array($raw['stale_logged'] ?? null) ? $raw['stale_logged'] : [];
    $last = (int) ($logged[$name] ?? 0);
    if ($now - $last >= MIMIR_TUNNEL_STALE_LOG_SECONDS) {
        mimir_event_log('tunnel', $message, $environment, $name, 'stale-webservice');
        $logged[$name] = $now;
        unset($raw['_fetched_at']);
        $raw['stale_logged'] = $logged;
        mimir_meta_put($pdo, mimir_tunnel_cache_key($environment), $raw, $fetched);
    }

    return $message;
}

function mimir_tunnel_cached_page_id(PDO $pdo, string $environment, string $entity, int $now): ?int
{
    $cached = mimir_meta_get($pdo, mimir_tunnel_cache_key($environment), MIMIR_TUNNEL_PAGE_TTL, $now);
    if (!is_array($cached)) {
        return null;
    }
    $entry = $cached['services'][$entity] ?? null;
    if (!is_array($entry) || empty($entry['page_id'])) {
        return null;
    }

    return (int) $entry['page_id'];
}

function mimir_tunnel_clear_page_cache(PDO $pdo): int
{
    $stmt = $pdo->prepare("DELETE FROM meta_cache WHERE cache_key LIKE 'tunnel-pages:%'");
    $stmt->execute();

    return $stmt->rowCount();
}

function mimir_tunnel_circuit_path(): string
{
    return mimir_runtime_dir() . '/mimir-tunnel-circuit.json';
}

/**
 * @param callable(array{failures: int, open_until: int}): array{failures: int, open_until: int} $change
 * @return array{failures: int, open_until: int}
 */
function mimir_tunnel_circuit_mutate(callable $change): array
{
    $empty = ['failures' => 0, 'open_until' => 0];
    $dir = mimir_runtime_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        return $change($empty);
    }
    $path = mimir_tunnel_circuit_path();
    $handle = @fopen($path, 'c+');
    if ($handle === false) {
        return $change($empty);
    }
    try {
        if (!flock($handle, LOCK_EX)) {
            return $change($empty);
        }
        $raw = stream_get_contents($handle);
        $decoded = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
        $state = [
            'failures' => is_array($decoded) ? (int) ($decoded['failures'] ?? 0) : 0,
            'open_until' => is_array($decoded) ? (int) ($decoded['open_until'] ?? 0) : 0,
        ];
        $next = $change($state);
        $encoded = json_encode([
            'failures' => (int) ($next['failures'] ?? 0),
            'open_until' => (int) ($next['open_until'] ?? 0),
        ]);
        if ($encoded !== false) {
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, $encoded);
            fflush($handle);
        }

        return [
            'failures' => (int) ($next['failures'] ?? 0),
            'open_until' => (int) ($next['open_until'] ?? 0),
        ];
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
        if (is_file($path)) {
            @chmod($path, 0666);
        }
    }
}

function mimir_tunnel_circuit_open(): bool
{
    $state = mimir_tunnel_circuit_mutate(static fn (array $current): array => $current);

    return $state['open_until'] > mimir_tunnel_now();
}

function mimir_tunnel_circuit_fail(string $environment, string $entity, string $reason): void
{
    $now = mimir_tunnel_now();
    $opened = false;
    $until = 0;
    mimir_tunnel_circuit_mutate(static function (array $state) use ($now, &$opened, &$until): array {
        $wasOpen = $state['open_until'] > $now;
        $state['failures']++;
        if ($state['failures'] >= MIMIR_TUNNEL_FAIL_LIMIT) {
            $state['open_until'] = $now + mimir_tunnel_config()['cooldown'];
            $opened = !$wasOpen;
            $until = $state['open_until'];
        }

        return $state;
    });
    if ($opened) {
        mimir_event_log(
            'tunnel',
            'Tunnel uit na ' . MIMIR_TUNNEL_FAIL_LIMIT . ' mislukte pogingen; OData tot ' . $until . '. Laatste: ' . $reason,
            $environment,
            $entity,
            'tunnel-fallback'
        );
    }
}

function mimir_tunnel_circuit_success(): void
{
    mimir_tunnel_circuit_mutate(static function (array $state): array {
        if ($state['failures'] === 0 && $state['open_until'] === 0) {
            return $state;
        }

        return ['failures' => 0, 'open_until' => 0];
    });
}
