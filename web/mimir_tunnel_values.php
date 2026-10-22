<?php

declare(strict_types=1);

/**
 * Tunnel-uitvoer in exact het OData-formaat.
 *
 * De bridge geeft cellen als tekst zoals de BC-webclient die toont: in de taal
 * en regio van de BC-gebruiker ("20-7-2026" in NL, "7/20/2026" in EN-US,
 * "08:00" of "8:00 AM"). OData geeft getypeerde JSON ("2026-07-20", "08:00:00",
 * "2026-07-20T08:00:00Z"). Mímir zet de tunnelwaarden om op basis van het
 * Edm-type uit $metadata, niet op het patroon van de tekst.
 *
 * Wat niet eenduidig kan:
 *  - datumvolgorde (D-M of M-D): geleerd uit de OData-steekproef (profiel), anders
 *    afgeleid uit het antwoord zelf (een deel > 12); lukt dat niet, OData-terugval;
 *  - Edm.String-velden die in OData tijden zijn (BC publiceert sommige Time-velden
 *    als Edm.String): herkend in de OData-steekproef;
 *  - velden die de webclient leeg laat (verborgen kolommen) of die de tunnel met
 *    minder precisie toont (DateTime in lokale tijd zonder seconden): in de
 *    steekproef gemarkeerd als onbetrouwbaar; vraagt een query zo'n veld, dan
 *    gaat die query naar OData.
 */

/** Verhoog bij elke wijziging aan de omzetting of het profiel: geleerde koppelingen worden opnieuw geleerd. */
const MIMIR_TUNNEL_OUTPUT_VERSION = 2;

const MIMIR_TUNNEL_ZERO_DATE = '0001-01-01';
const MIMIR_TUNNEL_ZERO_TIME = '00:00:00';
const MIMIR_TUNNEL_ZERO_DATETIME = '0001-01-01T00:00:00Z';

class MimirTunnelValueError extends RuntimeException
{
}

/** 'date', 'datetime', 'time' of null voor een Edm-type (plus de tijdvelden uit het profiel). */
function mimir_tunnel_temporal_kind(string $field, string $type, ?array $profile = null): ?string
{
    $edm = strtolower($type);
    if (str_contains($edm, 'datetime')) {
        return 'datetime';
    }
    if ($edm === 'edm.date' || str_ends_with($edm, '.date')) {
        return 'date';
    }
    if (str_contains($edm, 'timeofday') || $edm === 'edm.time' || str_contains($edm, 'duration')) {
        return 'time';
    }
    if (is_array($profile) && in_array($field, is_array($profile['time_fields'] ?? null) ? $profile['time_fields'] : [], true)) {
        return 'time';
    }

    return null;
}

/**
 * Delen van een webclientdatum: [p1, p2, p3, rest] of null.
 *
 * @return array{0: int, 1: int, 2: int, 3: string, 4: bool}|null 4 = eerste deel is het jaar (ISO)
 */
function mimir_tunnel_date_parts(string $text): ?array
{
    $text = trim($text);
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(.*)$/', $text, $m) === 1) {
        return [(int) $m[1], (int) $m[2], (int) $m[3], $m[4], true];
    }
    if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2}|\d{4})(?!\d)(.*)$/', $text, $m) === 1) {
        $year = (int) $m[3];
        if (strlen($m[3]) === 2) {
            $year += 2000;
        }

        return [(int) $m[1], (int) $m[2], $year, $m[4], false];
    }

    return null;
}

/**
 * Webclientdatum -> Y-m-d. $order: 'dmy', 'mdy' of null (alleen eenduidige datums).
 * Leeg = BC-nul (0001-01-01), zoals OData. null = niet eenduidig of geen datum.
 */
function mimir_tunnel_to_odata_date(mixed $value, ?string $order): ?string
{
    if ($value === null || (is_string($value) && trim($value) === '')) {
        return MIMIR_TUNNEL_ZERO_DATE;
    }
    if (!is_string($value)) {
        return null;
    }
    $parts = mimir_tunnel_date_parts($value);
    if ($parts === null) {
        return null;
    }
    [$a, $b, $year, , $iso] = $parts;
    if ($iso) {
        [$year, $month, $day] = [$a, $b, $year];
    } else {
        $resolved = mimir_tunnel_resolve_day_month($a, $b, $order);
        if ($resolved === null) {
            return null;
        }
        [$day, $month] = $resolved;
    }
    if (!checkdate($month, $day, $year)) {
        return null;
    }

    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

/** @return array{0: int, 1: int}|null [dag, maand] */
function mimir_tunnel_resolve_day_month(int $a, int $b, ?string $order): ?array
{
    if ($order === 'dmy') {
        return $b <= 12 ? [$a, $b] : null;
    }
    if ($order === 'mdy') {
        return $a <= 12 ? [$b, $a] : null;
    }
    if ($a === $b) {
        return [$a, $b];
    }
    if ($a > 12 && $b <= 12) {
        return [$a, $b];
    }
    if ($b > 12 && $a <= 12) {
        return [$b, $a];
    }

    return null;
}

/**
 * Webclienttijd -> HH:MM:SS (met fractie als de webclient die toont).
 * "8:00", "08.00", "8:00:05 PM", "15:20:55,297". Leeg = 00:00:00 (BC-nul).
 */
function mimir_tunnel_to_odata_time(mixed $value): ?string
{
    if ($value === null || (is_string($value) && trim($value) === '')) {
        return MIMIR_TUNNEL_ZERO_TIME;
    }
    if (!is_string($value)) {
        return null;
    }
    $text = trim(str_replace(["\u{00A0}", "\u{202F}"], ' ', $value));
    if (preg_match('/^(\d{1,2})[:.](\d{2})(?:[:.](\d{2})(?:[.,](\d{1,7}))?)?\s*([AaPp])\.?\s*[Mm]\.?$/', $text, $m) === 1
        || preg_match('/^(\d{1,2})[:.](\d{2})(?:[:.](\d{2})(?:[.,](\d{1,7}))?)?()$/', $text, $m) === 1) {
        $hour = (int) $m[1];
        $minute = (int) $m[2];
        $second = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : 0;
        $ampm = strtolower($m[5] ?? '');
        if ($ampm !== '') {
            if ($hour < 1 || $hour > 12) {
                return null;
            }
            $hour = $hour % 12 + ($ampm === 'p' ? 12 : 0);
        }
        if ($hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }
        $out = sprintf('%02d:%02d:%02d', $hour, $minute, $second);
        $fraction = rtrim((string) ($m[4] ?? ''), '0');

        return $fraction === '' ? $out : $out . '.' . $fraction;
    }

    return null;
}

function mimir_tunnel_timezone(): DateTimeZone
{
    $name = trim((string) ($GLOBALS['mimir_tunnel_timezone'] ?? 'Europe/Amsterdam'));
    try {
        return new DateTimeZone($name !== '' ? $name : 'Europe/Amsterdam');
    } catch (Throwable) {
        return new DateTimeZone('Europe/Amsterdam');
    }
}

/**
 * Webclient-datum/tijd (lokale tijd van de BC-gebruiker) -> ISO in UTC zoals OData
 * ("2026-07-20T06:00:00Z"). Al-ISO blijft ongemoeid. Leeg = 0001-01-01T00:00:00Z.
 */
function mimir_tunnel_to_odata_datetime(mixed $value, ?string $order): ?string
{
    if ($value === null || (is_string($value) && trim($value) === '')) {
        return MIMIR_TUNNEL_ZERO_DATETIME;
    }
    if (!is_string($value)) {
        return null;
    }
    $text = trim($value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2})$/', $text) === 1) {
        return $text;
    }
    $parts = mimir_tunnel_date_parts($text);
    if ($parts === null) {
        return null;
    }
    $date = mimir_tunnel_to_odata_date($text, $order);
    if ($date === null) {
        return null;
    }
    $rest = trim($parts[3]);
    $time = $rest === '' ? MIMIR_TUNNEL_ZERO_TIME : mimir_tunnel_to_odata_time($rest);
    if ($time === null) {
        return null;
    }
    if ($date === MIMIR_TUNNEL_ZERO_DATE) {
        return MIMIR_TUNNEL_ZERO_DATETIME;
    }
    $fraction = '';
    if (str_contains($time, '.')) {
        [$time, $fraction] = explode('.', $time, 2);
    }
    $local = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date . ' ' . $time, mimir_tunnel_timezone());
    if (!$local instanceof DateTimeImmutable) {
        return null;
    }

    return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s') . ($fraction !== '' ? '.' . $fraction : '') . 'Z';
}

/**
 * Datumvolgorde uit het antwoord zelf: een datumcel met een deel > 12 beslist.
 * Tegenstrijdig of niets eenduidigs: null.
 *
 * @param list<array<string, mixed>> $rawRecords veld => ruwe tunnelwaarde
 * @param array<string, string> $types
 */
function mimir_tunnel_infer_date_order(array $rawRecords, array $types, ?array $profile = null): ?string
{
    $dmy = 0;
    $mdy = 0;
    foreach ($rawRecords as $record) {
        foreach ($record as $field => $value) {
            $kind = mimir_tunnel_temporal_kind((string) $field, (string) ($types[$field] ?? ''), $profile);
            if (($kind !== 'date' && $kind !== 'datetime') || !is_string($value)) {
                continue;
            }
            $parts = mimir_tunnel_date_parts($value);
            if ($parts === null || $parts[4]) {
                continue;
            }
            if ($parts[0] > 12 && $parts[1] <= 12) {
                $dmy++;
            } elseif ($parts[1] > 12 && $parts[0] <= 12) {
                $mdy++;
            }
        }
    }
    if ($dmy > 0 && $mdy === 0) {
        return 'dmy';
    }
    if ($mdy > 0 && $dmy === 0) {
        return 'mdy';
    }

    return null;
}

/**
 * Eén tunnelwaarde -> OData-waarde voor dit veld. Gooit MimirTunnelValueError
 * als een datum/tijd niet eenduidig om te zetten is.
 */
function mimir_tunnel_output_value(string $field, mixed $value, string $type, ?array $profile, ?string $order): mixed
{
    $kind = mimir_tunnel_temporal_kind($field, $type, $profile);
    if ($kind === null) {
        return mimir_tunnel_coerce($value, $type);
    }
    $out = match ($kind) {
        'date' => mimir_tunnel_to_odata_date($value, $order),
        'time' => mimir_tunnel_to_odata_time($value),
        default => mimir_tunnel_to_odata_datetime($value, $order),
    };
    if ($out === null) {
        throw new MimirTunnelValueError($field . ' = ' . substr(is_scalar($value) ? (string) $value : gettype($value), 0, 40)
            . ($order === null && $kind !== 'time' ? ' (datumvolgorde onbekend)' : ''));
    }

    return $out;
}

/** Waarde die in OData "niets" betekent: leeg, BC-nul, false of 0. */
function mimir_tunnel_odata_is_blank(mixed $value): bool
{
    if ($value === null || $value === '' || $value === false) {
        return true;
    }
    if (is_int($value) || is_float($value)) {
        return (float) $value == 0.0;
    }
    if (is_string($value)) {
        $t = trim($value);

        return $t === '' || $t === MIMIR_TUNNEL_ZERO_DATE || $t === MIMIR_TUNNEL_ZERO_TIME
            || preg_match('/^0001-01-01T00:00:00(\.0+)?(Z|[+-]00:00)$/', $t) === 1;
    }

    return false;
}

/**
 * Profiel van de tunnelkolommen, uit een tunnel- en OData-steekproef van dezelfde pagina:
 * datumvolgorde, Edm.String-tijdvelden en velden die de tunnel niet betrouwbaar geeft.
 *
 * @param array<string, string> $map caption/DesignName -> OData-veld
 * @param list<array<string, mixed>> $tunnelRows cellen
 * @param list<array<string, mixed>> $odataRows
 * @param array<string, string> $types
 * @param list<string> $keys
 * @return array{version: int, date_order: ?string, time_fields: list<string>, unreliable: array<string, string>, sampled: bool}
 */
function mimir_tunnel_build_profile(array $map, array $tunnelRows, array $odataRows, array $types, array $keys): array
{
    $profile = [
        'version' => MIMIR_TUNNEL_OUTPUT_VERSION,
        'date_order' => null,
        'time_fields' => [],
        'unreliable' => [],
        'sampled' => $tunnelRows !== [] && $odataRows !== [],
    ];
    if (!$profile['sampled']) {
        return $profile;
    }
    // Ruwe tunnelrecords op OData-veldnaam.
    $raw = [];
    foreach ($tunnelRows as $cells) {
        $record = [];
        foreach ($map as $key => $field) {
            if (is_string($field) && array_key_exists($key, $cells)) {
                $record[$field] = $cells[$key];
            }
        }
        $raw[] = $record;
    }
    $pairs = mimir_tunnel_profile_pairs($raw, $odataRows, $types, $keys);

    // Edm.String-velden met tijden in OData (BC publiceert Time soms zo).
    foreach ($types as $field => $type) {
        if (!is_string($field) || mimir_tunnel_temporal_kind($field, (string) $type) !== null || !str_contains(strtolower((string) $type), 'string')) {
            continue;
        }
        $seen = 0;
        $ok = true;
        foreach ($odataRows as $row) {
            if (!array_key_exists($field, $row)) {
                continue;
            }
            $v = $row[$field];
            if (!is_string($v) || preg_match('/^\d{2}:\d{2}:\d{2}(\.\d+)?$/', $v) !== 1) {
                $ok = false;
                break;
            }
            $seen++;
        }
        if ($ok && $seen > 0) {
            $profile['time_fields'][] = $field;
        }
    }

    // Datumvolgorde uit paren (tunnel "20-7-2026" naast OData "2026-07-20").
    $dmy = 0;
    $mdy = 0;
    foreach ($pairs as [$t, $o]) {
        foreach ($t as $field => $value) {
            $kind = mimir_tunnel_temporal_kind((string) $field, (string) ($types[$field] ?? ''), $profile);
            if (($kind !== 'date' && $kind !== 'datetime') || !is_string($value) || !is_string($o[$field] ?? null)) {
                continue;
            }
            $parts = mimir_tunnel_date_parts($value);
            if ($parts === null || $parts[4] || preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string) $o[$field], $m) !== 1) {
                continue;
            }
            // Bij DateTime kan UTC een dag verschuiven: dan past geen van beide volgordes en telt het paar niet.
            if ($parts[2] !== (int) $m[1]) {
                continue;
            }
            [$mo, $d] = [(int) $m[2], (int) $m[3]];
            if ($d === $mo) {
                continue;
            }
            if ($parts[0] === $d && $parts[1] === $mo) {
                $dmy++;
            } elseif ($parts[0] === $mo && $parts[1] === $d) {
                $mdy++;
            }
        }
    }
    if ($dmy > $mdy) {
        $profile['date_order'] = 'dmy';
    } elseif ($mdy > $dmy) {
        $profile['date_order'] = 'mdy';
    } else {
        $profile['date_order'] = mimir_tunnel_infer_date_order($raw, $types, $profile);
    }

    // Onbetrouwbare velden.
    $mapped = array_flip(array_values(array_filter($map, 'is_string')));
    foreach ($types as $field => $type) {
        if (!is_string($field)) {
            continue;
        }
        $inOdata = false;
        $odataFilled = false;
        foreach ($odataRows as $row) {
            if (array_key_exists($field, $row)) {
                $inOdata = true;
                if (!mimir_tunnel_odata_is_blank($row[$field])) {
                    $odataFilled = true;
                }
            }
        }
        if (!$inOdata) {
            continue;
        }
        if (!isset($mapped[$field])) {
            $profile['unreliable'][$field] = 'geen tunnelkolom';
            continue;
        }
        $tunnelFilled = false;
        foreach ($raw as $record) {
            $v = $record[$field] ?? null;
            if ($v !== null && !(is_string($v) && trim($v) === '')) {
                $tunnelFilled = true;
                break;
            }
        }
        if (!$tunnelFilled && $odataFilled) {
            $profile['unreliable'][$field] = 'leeg in de tunnel (verborgen kolom)';
            continue;
        }
        $kind = mimir_tunnel_temporal_kind($field, (string) $type, $profile);
        if ($kind === null) {
            continue;
        }
        foreach ($pairs as [$t, $o]) {
            if (!array_key_exists($field, $t) || !array_key_exists($field, $o)) {
                continue;
            }
            try {
                $converted = mimir_tunnel_output_value($field, $t[$field], (string) $type, $profile, $profile['date_order']);
            } catch (MimirTunnelValueError) {
                $profile['unreliable'][$field] = 'niet eenduidig om te zetten';
                break;
            }
            $expected = $o[$field];
            if ($expected === null) {
                $expected = match ($kind) {
                    'date' => MIMIR_TUNNEL_ZERO_DATE,
                    'time' => MIMIR_TUNNEL_ZERO_TIME,
                    default => MIMIR_TUNNEL_ZERO_DATETIME,
                };
            }
            if (!mimir_tunnel_temporal_equal($kind, $converted, $expected)) {
                $profile['unreliable'][$field] = 'wijkt af van OData (' . substr((string) $converted, 0, 30) . ' vs ' . substr((string) $expected, 0, 30) . ')';
                break;
            }
        }
    }
    sort($profile['time_fields']);
    ksort($profile['unreliable']);

    return $profile;
}

function mimir_tunnel_temporal_equal(string $kind, mixed $a, mixed $b): bool
{
    if (!is_string($a) || !is_string($b)) {
        return $a === $b;
    }
    if ($kind === 'datetime') {
        $na = preg_replace('/\.0+(?=Z|[+-])/', '', $a);
        $nb = preg_replace('/\.0+(?=Z|[+-])/', '', $b);

        return $na === $nb;
    }
    if ($kind === 'time') {
        return preg_replace('/\.0+$/', '', $a) === preg_replace('/\.0+$/', '', $b);
    }

    return $a === $b;
}

/**
 * Paren [tunnelrecord, OData-rij] op sleutel; zonder sleutelmatch op rijvolgorde.
 *
 * @param list<array<string, mixed>> $raw
 * @param list<array<string, mixed>> $odataRows
 * @param array<string, string> $types
 * @param list<string> $keys
 * @return list<array{0: array<string, mixed>, 1: array<string, mixed>}>
 */
function mimir_tunnel_profile_pairs(array $raw, array $odataRows, array $types, array $keys): array
{
    $pairs = [];
    if ($keys !== []) {
        $index = [];
        foreach ($odataRows as $row) {
            $sig = mimir_tunnel_profile_key($row, $keys, $types);
            if ($sig !== null) {
                $index[$sig] = $row;
            }
        }
        foreach ($raw as $record) {
            $sig = mimir_tunnel_profile_key($record, $keys, $types);
            if ($sig !== null && isset($index[$sig])) {
                $pairs[] = [$record, $index[$sig]];
            }
        }
        // Met sleutels alleen echte paren: rijvolgorde kan verschillen.
        return $pairs;
    }
    $n = min(count($raw), count($odataRows));
    for ($i = 0; $i < $n; $i++) {
        $pairs[] = [$raw[$i], $odataRows[$i]];
    }

    return $pairs;
}

/**
 * @param array<string, mixed> $row
 * @param list<string> $keys
 * @param array<string, string> $types
 */
function mimir_tunnel_profile_key(array $row, array $keys, array $types): ?string
{
    $parts = [];
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row)) {
            return null;
        }
        $parts[] = mimir_tunnel_norm_value($row[$key], (string) ($types[$key] ?? ''));
    }

    return implode("\x1f", $parts);
}

/** Geldig profiel van de huidige versie in een service-entry, of null. */
function mimir_tunnel_entry_profile(array $entry): ?array
{
    $profile = $entry['columns']['profile'] ?? null;
    if (!is_array($profile) || (int) ($profile['version'] ?? 0) !== MIMIR_TUNNEL_OUTPUT_VERSION) {
        return null;
    }

    return $profile;
}

/**
 * Gevraagde velden die de tunnel volgens het profiel niet betrouwbaar geeft.
 *
 * @param list<string> $select leeg = alle velden
 * @param array<string, string> $types
 * @return array<string, string> veld => reden
 */
function mimir_tunnel_unreliable_requested(?array $profile, array $select, array $types): array
{
    if ($profile === null) {
        return [];
    }
    $unreliable = is_array($profile['unreliable'] ?? null) ? $profile['unreliable'] : [];
    $requested = $select !== [] ? $select : array_keys($types);
    $out = [];
    foreach ($requested as $field) {
        if (is_string($field) && isset($unreliable[$field])) {
            $out[$field] = (string) $unreliable[$field];
        }
    }

    return $out;
}

/**
 * Profiel voor een entry waarvan de koppeling zonder OData-steekproef geleerd is
 * (DesignName of naamgelijkheid): haalt alsnog een kleine steekproef.
 *
 * @param array<string, mixed> $entry
 * @param array<string, string> $map
 * @param list<array<string, mixed>> $tunnelRows
 * @param array<string, mixed> $ctx
 * @return array<string, mixed>|null
 */
function mimir_tunnel_sample_profile(array $entry, array $map, array $tunnelRows, array $ctx, bool $designKeyed, bool $fromBody): ?array
{
    $sampler = $ctx['odata_sample'] ?? null;
    if (!is_callable($sampler)) {
        return null;
    }
    $types = is_array($ctx['types'] ?? null) ? $ctx['types'] : [];
    $keys = is_array($ctx['keys'] ?? null) ? array_values(array_filter($ctx['keys'], 'is_string')) : [];
    try {
        if (!$fromBody || $tunnelRows === []) {
            $probe = mimir_tunnel_get_page((int) $entry['page_id'], (string) $ctx['company'], [], MIMIR_TUNNEL_COLUMNS_SAMPLE, $ctx['key_id'] ?? null, 0, $designKeyed ? MIMIR_TUNNEL_KEY_BY : null);
            $tunnelRows = mimir_tunnel_body_cell_rows($probe);
        }
        $count = max(1, min(MIMIR_TUNNEL_COLUMNS_SAMPLE, count($tunnelRows)));
        $odataRows = [];
        foreach ((array) $sampler($count) as $row) {
            if (is_array($row)) {
                $odataRows[] = $row;
            }
        }
    } catch (MimirTunnelOutage $outage) {
        throw $outage;
    } catch (Throwable) {
        return null;
    }

    return mimir_tunnel_build_profile($map, $tunnelRows, $odataRows, $types, $keys);
}
