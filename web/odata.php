<?php

declare(strict_types=1);

/**
 * OData-client voor Business Central, afgeleid van Consus.
 * Paginering volgt @odata.nextLink. De bestandscache van Consus zit hier niet
 * in: Mímir bewaart rijen in SQLite met een fetched_at per rij.
 */

const MIMIR_ODATA_PAGE_GUARD = 100;

function odata_init_curl(array $auth, string $accept = 'application/json')
{
    $ch = curl_init();
    if ($ch === false) {
        throw new RuntimeException('cURL kon niet worden gestart.');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'Mimir-ODataClient/1.0 (nl-NL)',
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => [
            'Accept: ' . $accept,
            'Accept-Language: nl-NL,nl;q=0.9,en;q=0.8',
        ],
    ]);

    if (($auth['mode'] ?? '') === 'basic') {
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, (string) ($auth['user'] ?? '') . ':' . (string) ($auth['pass'] ?? ''));
    } elseif (($auth['mode'] ?? '') === 'ntlm') {
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_NTLM);
        curl_setopt($ch, CURLOPT_USERPWD, (string) ($auth['user'] ?? '') . ':' . (string) ($auth['pass'] ?? ''));
    }

    return $ch;
}

function odata_get_json(string $url, array $auth): array
{
    $ch = odata_init_curl($auth, 'application/json');
    try {
        curl_setopt($ch, CURLOPT_URL, $url);
        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new RuntimeException('cURL error: ' . curl_error($ch));
        }
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code < 200 || $code >= 300) {
            $body = substr((string) $raw, 0, 800);
            throw new RuntimeException('HTTP ' . $code . ' from OData: ' . $body);
        }
        $json = json_decode((string) $raw, true);
        if (!is_array($json)) {
            throw new RuntimeException('Invalid JSON from OData');
        }
        return $json;
    } finally {
        curl_close($ch);
    }
}

function odata_get_text(string $url, array $auth): string
{
    $ch = odata_init_curl($auth, 'application/xml, application/json;q=0.5');
    try {
        curl_setopt($ch, CURLOPT_URL, $url);
        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new RuntimeException('cURL error: ' . curl_error($ch));
        }
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code < 200 || $code >= 300) {
            $body = substr((string) $raw, 0, 800);
            throw new RuntimeException('HTTP ' . $code . ' from OData: ' . $body);
        }
        return (string) $raw;
    } finally {
        curl_close($ch);
    }
}

/**
 * Zelfde handtekening als Consus, zodat auth_helper company-discovery kan doen.
 * $ttlSeconds blijft in de signature; de rijcache van Mímir bewaart de leeftijd.
 */
function odata_get_all(string $url, array $auth, $ttlSeconds = 300): array
{
    unset($ttlSeconds);
    $all = [];
    $next = $url;
    $guard = 0;
    while (is_string($next) && $next !== '') {
        $resp = odata_get_json($next, $auth);
        if (!isset($resp['value']) || !is_array($resp['value'])) {
            throw new RuntimeException("OData response missing 'value' array");
        }
        foreach ($resp['value'] as $row) {
            if (is_array($row)) {
                $all[] = $row;
            }
        }
        $next = $resp['@odata.nextLink'] ?? null;
        $guard++;
        if ($guard > 500) {
            throw new RuntimeException('OData-paginering gestopt na 500 pagina\'s.');
        }
    }

    return $all;
}

function mimir_odata_prefix_for_environment(string $environment): string
{
    global $baseUrl;
    $base = rtrim(trim((string) ($baseUrl ?? '')), '/');
    $environment = trim($environment);
    if ($base === '' || $environment === '') {
        throw new RuntimeException('baseUrl of environment ontbreekt in auth.php.');
    }

    return $base . '/' . rawurlencode($environment) . '/ODataV4/';
}

function mimir_company_segment(string $company): string
{
    $escaped = str_replace("'", "''", $company);
    return "Company('" . rawurlencode($escaped) . "')";
}

/**
 * @param array<string, scalar|null> $query
 */
function mimir_collection_url(string $prefix, string $company, string $entity, array $query = []): string
{
    $url = rtrim($prefix, '/') . '/' . mimir_company_segment($company) . '/' . rawurlencode($entity);
    if ($query !== []) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
    return $url;
}

function mimir_entity_key_url(string $prefix, string $company, string $entity, string $predicate): string
{
    return rtrim($prefix, '/') . '/' . mimir_company_segment($company) . '/' . rawurlencode($entity) . '(' . $predicate . ')';
}

/**
 * @param array<string, string> $types
 * @param list<string> $keys
 */
function mimir_key_predicate(array $row, array $keys, array $types): string
{
    require_once __DIR__ . '/mimir_filter.php';
    $parts = [];
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row)) {
            throw new RuntimeException('Sleutelveld ontbreekt op de rij: ' . $key);
        }
        $literal = mimir_odata_literal($row[$key], (string) ($types[$key] ?? 'Edm.String'));
        if (str_starts_with($literal, "'")) {
            $literal = str_replace(' ', '%20', $literal);
        }
        $parts[] = $key . '=' . $literal;
    }
    return implode(',', $parts);
}

function mimir_same_odata_origin(string $url, string $prefix): bool
{
    $left = parse_url($url);
    $right = parse_url($prefix);
    if (!is_array($left) || !is_array($right)) {
        return false;
    }
    $leftPort = (int) ($left['port'] ?? (($left['scheme'] ?? '') === 'https' ? 443 : 80));
    $rightPort = (int) ($right['port'] ?? (($right['scheme'] ?? '') === 'https' ? 443 : 80));
    return strcasecmp((string) ($left['scheme'] ?? ''), (string) ($right['scheme'] ?? '')) === 0
        && strcasecmp((string) ($left['host'] ?? ''), (string) ($right['host'] ?? '')) === 0
        && $leftPort === $rightPort;
}

/**
 * @return array{entity_sets: list<array{name: string, entity_type: string}>, types: array<string, array{keys: list<string>, properties: array<string, string>}>}
 */
function odata_parse_metadata(string $xml): array
{
    $previous = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadXML($xml);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if ($loaded === false) {
        throw new RuntimeException('Ongeldige OData-metadata.');
    }

    $types = [];
    $sets = [];
    foreach ($dom->getElementsByTagName('*') as $element) {
        if (!$element instanceof DOMElement) {
            continue;
        }
        if ($element->localName === 'EntityType') {
            $name = $element->getAttribute('Name');
            if ($name === '') {
                continue;
            }
            $keys = [];
            $properties = [];
            foreach ($element->childNodes as $child) {
                if (!$child instanceof DOMElement) {
                    continue;
                }
                if ($child->localName === 'Key') {
                    foreach ($child->childNodes as $ref) {
                        if ($ref instanceof DOMElement && $ref->localName === 'PropertyRef') {
                            $refName = $ref->getAttribute('Name');
                            if ($refName !== '') {
                                $keys[] = $refName;
                            }
                        }
                    }
                }
                if ($child->localName === 'Property') {
                    $propName = $child->getAttribute('Name');
                    if ($propName === '') {
                        continue;
                    }
                    $properties[$propName] = $child->getAttribute('Type') ?: 'Edm.String';
                }
            }
            $types[$name] = ['keys' => $keys, 'properties' => $properties];
        }
        if ($element->localName === 'EntitySet' && $element->parentNode instanceof DOMElement && $element->parentNode->localName === 'EntityContainer') {
            $setName = $element->getAttribute('Name');
            if ($setName === '') {
                continue;
            }
            $sets[] = [
                'name' => $setName,
                'entity_type' => $element->getAttribute('EntityType'),
            ];
        }
    }

    usort($sets, static function (array $a, array $b): int {
        return strcasecmp($a['name'], $b['name']);
    });

    return ['entity_sets' => $sets, 'types' => $types];
}

/**
 * @param array{entity_sets: list<array{name: string, entity_type: string}>, types: array<string, array{keys: list<string>, properties: array<string, string>}>} $metadata
 * @return array{name: string, entity_type: string, keys: list<string>, properties: array<string, string>}
 */
function mimir_schema_for_set(array $metadata, string $setName): array
{
    foreach ($metadata['entity_sets'] as $set) {
        if (strcasecmp($set['name'], $setName) !== 0) {
            continue;
        }
        $typeName = (string) $set['entity_type'];
        $short = $typeName;
        $dot = strrpos($typeName, '.');
        if ($dot !== false) {
            $short = substr($typeName, $dot + 1);
        }
        $type = $metadata['types'][$short] ?? $metadata['types'][$typeName] ?? ['keys' => [], 'properties' => []];
        return [
            'name' => $set['name'],
            'entity_type' => $typeName,
            'keys' => array_values($type['keys'] ?? []),
            'properties' => is_array($type['properties'] ?? null) ? $type['properties'] : [],
        ];
    }

    throw new RuntimeException('Onbekende tabel: ' . $setName);
}
