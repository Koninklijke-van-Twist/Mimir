<?php

declare(strict_types=1);

/**
 * Taalonafhankelijke kolomkoppeling voor de Mimir-Tunnel.
 *
 * De bridge geeft paginakolommen als captions in de taal van de BC-gebruiker
 * ("Nr.", "Naam", soms gemengd met Engels). Mímir werkt met OData-veldnamen.
 * Geen vertaaltabel: de koppeling caption -> veld wordt per pagina uit BC zelf
 * afgeleid door een kleine tunnel-steekproef naast een OData-steekproef
 * ($top=N, dezelfde pagina, dezelfde sortering) te leggen en waarden te vergelijken.
 *
 * Volgorde:
 *  1. naamgelijkheid ("No." -> No), alleen als de waarden in de steekproef niet tegenspreken;
 *  2. waardevergelijking per rij (rij i tunnel ~ rij i OData);
 *  3. waardevergelijking zonder rijvolgorde (overlap van waardensets);
 *  gelijke scores (dubbele captions, identieke kolommen) beslist de kolomvolgorde.
 *
 * De koppeling staat in de page-810-cache bij de service (24 uur) en wordt
 * opnieuw geleerd zodra de tunnel captions geeft die de koppeling niet kent.
 */

const MIMIR_TUNNEL_COLUMNS_TTL = 86400;
const MIMIR_TUNNEL_COLUMNS_SAMPLE = 20;
const MIMIR_TUNNEL_COLUMNS_MIN_SCORE = 0.8;
/** Na een mislukte leerpoging (geen OData-steekproef) niet elke request opnieuw. */
const MIMIR_TUNNEL_COLUMNS_RETRY = 300;
/** Mímir vraagt de bridge om cellen op DesignName; een bridge zonder keyBy geeft captions. */
const MIMIR_TUNNEL_KEY_BY = 'designName';

/**
 * Antwoordt de bridge met DesignName-sleutels? Alleen als hij dat zelf meldt
 * (`keyBy: "designName"`); een oudere bridge stuurt geen keyBy en dus captions.
 *
 * @param array<string, mixed> $body
 */
function mimir_tunnel_body_is_design_keyed(array $body): bool
{
    return ($body['keyBy'] ?? null) === 'designName';
}

/** Onthoudt per request welke sleutels de tunnel gaf (voor meta.tunnel_key_by). */
function mimir_tunnel_note_key_by(array $body): void
{
    $GLOBALS['mimir_tunnel_key_by'] = mimir_tunnel_body_is_design_keyed($body) ? 'designName' : 'caption';
}

/**
 * DesignName of OData-naam vergelijkbaar maken: niet-alfanumeriek -> '_',
 * trailing '_' weg, kleine letters ("No." -> "no", "Balance (LCY)" -> "balance__lcy").
 */
function mimir_tunnel_design_norm(string $name): string
{
    return strtolower(rtrim((string) preg_replace('/[^A-Za-z0-9]/', '_', $name), '_'));
}

/**
 * DesignName -> OData-veld via $metadata (de types van de entity).
 * Eerst exact genormaliseerd, daarna met samengevoegde '_' (BC maakt van
 * "Balance (LCY)" Balance_LCY). Elk veld hoogstens één keer.
 *
 * @param list<string> $designNames
 * @param array<string, string> $types
 * @return array<string, string>
 */
function mimir_tunnel_design_columns(array $designNames, array $types): array
{
    $fields = array_values(array_filter(array_keys($types), 'is_string'));
    $collapse = static fn (string $norm): string => (string) preg_replace('/_+/', '_', $norm);
    $map = [];
    $used = [];
    foreach ([false, true] as $loose) {
        $index = [];
        foreach ($fields as $field) {
            $norm = mimir_tunnel_design_norm($field);
            $norm = $loose ? $collapse($norm) : $norm;
            $index[$norm] ??= $field;
        }
        foreach ($designNames as $name) {
            if (!is_string($name) || $name === '' || isset($map[$name]) || preg_match('/#\d+$/', $name) === 1) {
                continue;
            }
            $norm = mimir_tunnel_design_norm($name);
            $norm = $loose ? $collapse($norm) : $norm;
            $field = $index[$norm] ?? null;
            if ($field !== null && !isset($used[$field])) {
                $map[$name] = $field;
                $used[$field] = true;
            }
        }
    }

    return $map;
}

/**
 * Koppeling sleutel -> OData-veld voor dit antwoord: bij DesignName-sleutels
 * eerst de $metadata-koppeling, daarna de geleerde caption/waarde-koppeling
 * voor wat overblijft. Bij captions alleen de geleerde koppeling (oude gedrag).
 *
 * @param array<string, mixed> $entry
 * @param array<string, mixed> $body
 * @param array<string, string> $types
 * @return array<string, string>
 */
function mimir_tunnel_effective_columns(array $entry, array $body, array $types): array
{
    $learned = mimir_tunnel_columns_map($entry);
    if (!mimir_tunnel_body_is_design_keyed($body)) {
        return $learned;
    }
    $design = mimir_tunnel_design_columns(mimir_tunnel_body_captions($body), $types);
    $used = array_flip(array_values($design));
    foreach ($learned as $key => $field) {
        if (!isset($design[$key]) && !isset($used[$field])) {
            $design[$key] = $field;
            $used[$field] = true;
        }
    }

    return $design;
}

/**
 * Captions in een tunnel-antwoord, in kolomvolgorde.
 *
 * @param array<string, mixed> $body
 * @return list<string>
 */
function mimir_tunnel_body_captions(array $body): array
{
    $out = [];
    foreach ($body['rows'] ?? [] as $row) {
        if (is_array($row) && is_array($row['cells'] ?? null)) {
            foreach (array_keys($row['cells']) as $caption) {
                if (is_string($caption) && $caption !== '' && !in_array($caption, $out, true)) {
                    $out[] = $caption;
                }
            }
        }
    }
    foreach ($body['fields'] ?? [] as $field) {
        if (is_array($field) && isset($field['name']) && is_string($field['name']) && $field['name'] !== ''
            && !in_array($field['name'], $out, true)) {
            $out[] = $field['name'];
        }
    }

    return $out;
}

/**
 * @param array<string, mixed> $body
 * @return list<array<string, mixed>>
 */
function mimir_tunnel_body_cell_rows(array $body): array
{
    $rows = [];
    foreach ($body['rows'] ?? [] as $row) {
        if (is_array($row) && is_array($row['cells'] ?? null) && $row['cells'] !== []) {
            $rows[] = $row['cells'];
        }
    }
    if ($rows === [] && is_array($body['fields'] ?? null)) {
        $cells = [];
        foreach ($body['fields'] as $field) {
            if (is_array($field) && isset($field['name']) && is_string($field['name'])) {
                $cells[$field['name']] = $field['value'] ?? null;
            }
        }
        if ($cells !== []) {
            $rows[] = $cells;
        }
    }

    return $rows;
}

/**
 * Moet de koppeling (opnieuw) geleerd worden voor dit antwoord?
 *
 * @param array<string, mixed> $entry
 * @param list<string> $captions
 */
function mimir_tunnel_columns_stale(array $entry, array $captions, int $now): bool
{
    $columns = is_array($entry['columns'] ?? null) ? $entry['columns'] : null;
    if ($columns === null) {
        return true;
    }
    $learnedAt = (int) ($columns['learned_at'] ?? 0);
    $ok = !empty($columns['complete']);
    $ttl = $ok ? MIMIR_TUNNEL_COLUMNS_TTL : MIMIR_TUNNEL_COLUMNS_RETRY;
    if ($now - $learnedAt > $ttl) {
        return true;
    }
    $known = is_array($columns['seen'] ?? null) ? $columns['seen'] : [];
    foreach ($captions as $caption) {
        if (!in_array($caption, $known, true)) {
            // Onbekende caption: andere taal of gewijzigde pagina. Opnieuw leren,
            // maar hoogstens eens per MIMIR_TUNNEL_COLUMNS_RETRY na een vorige poging.
            return $ok || $now - $learnedAt > MIMIR_TUNNEL_COLUMNS_RETRY;
        }
    }

    return false;
}

/**
 * caption -> OData-veld uit de geleerde koppeling.
 *
 * @param array<string, mixed> $entry
 * @return array<string, string>
 */
function mimir_tunnel_columns_map(array $entry): array
{
    $map = $entry['columns']['map'] ?? null;
    if (!is_array($map)) {
        return [];
    }
    $out = [];
    foreach ($map as $caption => $field) {
        if (is_string($caption) && is_string($field) && $field !== '') {
            $out[$caption] = $field;
        }
    }

    return $out;
}

/**
 * Leert de koppeling als dat nodig is en geeft de bijgewerkte service-entry terug.
 * Zonder `odata_sample` in de context blijft alleen naamgelijkheid over (oude gedrag).
 *
 * @param array<string, mixed> $entry
 * @param array<string, mixed> $body het tunnel-antwoord dat nu gelezen is
 * @param array<string, mixed> $ctx
 * @return array<string, mixed>
 */
function mimir_tunnel_ensure_columns(array $entry, array $body, array $ctx, bool $force = false): array
{
    $sampler = $ctx['odata_sample'] ?? null;
    if (!is_callable($sampler)) {
        return $entry;
    }
    $now = (int) ($ctx['now'] ?? time());
    $captions = mimir_tunnel_body_captions($body);
    if (!$force && ($captions === [] || !mimir_tunnel_columns_stale($entry, $captions, $now))) {
        return $entry;
    }
    if (!empty($GLOBALS['mimir_tunnel_columns_learned'][(string) ($ctx['entity'] ?? '')])) {
        // Eén leerpoging per entity per request.
        return $entry;
    }
    $GLOBALS['mimir_tunnel_columns_learned'][(string) ($ctx['entity'] ?? '')] = true;

    $types = is_array($ctx['types'] ?? null) ? $ctx['types'] : [];
    $keys = is_array($ctx['keys'] ?? null) ? array_values(array_filter($ctx['keys'], 'is_string')) : [];
    $tunnelRows = mimir_tunnel_body_cell_rows($body);
    $designKeyed = mimir_tunnel_body_is_design_keyed($body);
    if ($designKeyed) {
        // DesignName-sleutels: koppeling direct uit $metadata. Alleen als dat niet
        // alles dekt (of de sleutelvelden mist) valt het terug op de waardevergelijking.
        $design = mimir_tunnel_design_columns($captions, $types);
        $unmapped = array_values(array_diff($captions, array_keys($design)));
        if ($unmapped === [] && array_diff($keys, array_values($design)) === []) {
            $entry['columns'] = [
                'map' => $design,
                'seen' => $captions,
                'learned_at' => $now,
                'complete' => true,
                'method' => array_fill_keys(array_keys($design), 'designName'),
                'key_by' => 'designName',
            ];
            // Filters gaan ook op DesignName (de bridge accepteert DesignName of caption).
            $fieldToKey = is_array($entry['captions'] ?? null) ? $entry['captions'] : [];
            foreach ($design as $name => $field) {
                $fieldToKey[$field] = $name;
            }
            $entry['captions'] = $fieldToKey;
            $pdo = $ctx['pdo'] ?? null;
            if ($pdo instanceof PDO) {
                mimir_tunnel_save_entry($pdo, (string) ($ctx['environment'] ?? ''), (string) ($ctx['entity'] ?? ''), $entry, $now);
            }

            return $entry;
        }
    }
    // Alles al op naam te koppelen (Engelse captions): geen OData-steekproef nodig.
    $byName = mimir_tunnel_learn_columns($captions, $tunnelRows, [], $types, $keys);
    $keysByName = array_diff($keys, array_values($byName['map'])) === [];
    if ($byName['unmapped'] === [] && $keysByName) {
        $entry['columns'] = [
            'map' => $byName['map'],
            'seen' => $captions,
            'learned_at' => $now,
            'complete' => true,
            'method' => $byName['method'],
        ];
        $pdo = $ctx['pdo'] ?? null;
        if ($pdo instanceof PDO) {
            mimir_tunnel_save_entry($pdo, (string) ($ctx['environment'] ?? ''), (string) ($ctx['entity'] ?? ''), $entry, $now);
        }

        return $entry;
    }
    $sampleFromBody = !$force && count($tunnelRows) >= 1 && !empty($ctx['_unfiltered_first_page']);
    $odataRows = [];
    $error = null;
    try {
        if (!$sampleFromBody) {
            $probe = mimir_tunnel_get_page((int) $entry['page_id'], (string) $ctx['company'], [], MIMIR_TUNNEL_COLUMNS_SAMPLE, $ctx['key_id'] ?? null, 0, $designKeyed ? MIMIR_TUNNEL_KEY_BY : null);
            $tunnelRows = mimir_tunnel_body_cell_rows($probe);
            $captions = array_values(array_unique(array_merge($captions, mimir_tunnel_body_captions($probe))));
        }
        $count = max(1, min(MIMIR_TUNNEL_COLUMNS_SAMPLE, count($tunnelRows)));
        $sample = $sampler($count);
        foreach (is_array($sample) ? $sample : [] as $row) {
            if (is_array($row)) {
                $odataRows[] = $row;
            }
        }
    } catch (MimirTunnelOutage $outage) {
        throw $outage;
    } catch (Throwable $caught) {
        $error = $caught->getMessage();
    }

    $result = mimir_tunnel_learn_columns($captions, $tunnelRows, $odataRows, $types, $keys);
    $keyMapped = true;
    foreach ($keys as $key) {
        if (!in_array($key, $result['map'], true)) {
            $keyMapped = false;
        }
    }
    $entry['columns'] = [
        'map' => $result['map'],
        'seen' => $captions,
        'learned_at' => $now,
        'complete' => $error === null && $odataRows !== [] && $keyMapped,
        'method' => $result['method'],
    ];
    $fieldToCaption = is_array($entry['captions'] ?? null) ? $entry['captions'] : [];
    foreach ($result['map'] as $caption => $field) {
        $fieldToCaption[$field] = (string) preg_replace('/#\d+$/', '', $caption);
    }
    $entry['captions'] = $fieldToCaption;

    $environment = (string) ($ctx['environment'] ?? '');
    $entity = (string) ($ctx['entity'] ?? '');
    $parts = [count($result['map']) . ' kolommen gekoppeld'];
    if ($result['unmapped'] !== []) {
        $parts[] = 'niet gekoppeld: ' . implode(', ', array_slice($result['unmapped'], 0, 15));
    }
    if (!$keyMapped) {
        $parts[] = 'sleutel ontbreekt (' . implode(', ', $keys) . '), OData-terugval';
    }
    if ($error !== null) {
        $parts[] = 'OData-steekproef mislukt: ' . substr($error, 0, 160);
    }
    if ($result['unmapped'] !== [] || !$keyMapped || $error !== null) {
        mimir_event_log('tunnel', 'Kolomkoppeling ' . $entity . ': ' . implode('; ', $parts) . '.', $environment, $entity, 'tunnel-columns');
    }
    $pdo = $ctx['pdo'] ?? null;
    if ($pdo instanceof PDO) {
        mimir_tunnel_save_entry($pdo, $environment, $entity, $entry, $now);
    }

    return $entry;
}

/**
 * Kern: koppelt captions aan OData-velden op basis van een tunnel- en een OData-steekproef.
 *
 * @param list<string> $captions kolomvolgorde van de tunnel
 * @param list<array<string, mixed>> $tunnelRows
 * @param list<array<string, mixed>> $odataRows
 * @param array<string, string> $types OData-veld => Edm-type, in $metadata-volgorde
 * @param list<string> $keys
 * @return array{map: array<string, string>, unmapped: list<string>, method: array<string, string>}
 */
function mimir_tunnel_learn_columns(array $captions, array $tunnelRows, array $odataRows, array $types, array $keys = []): array
{
    $fields = array_values(array_filter(array_keys($types), 'is_string'));
    $fieldPos = array_flip($fields);
    $captionPos = array_flip($captions);
    $map = [];
    $method = [];
    $usedFields = [];
    $hasSample = $odataRows !== [] && $tunnelRows !== [];
    $n = min(count($tunnelRows), count($odataRows));

    // 1. Naamgelijkheid, tenzij de steekproef het tegenspreekt.
    foreach ($captions as $caption) {
        $base = (string) preg_replace('/#\d+$/', '', $caption);
        $norm = strtolower(mimir_tunnel_caption_to_odata($base));
        foreach ($fields as $field) {
            if (isset($usedFields[$field])) {
                continue;
            }
            $fnorm = strtolower(mimir_tunnel_caption_to_odata($field));
            $gnorm = strtolower(mimir_tunnel_caption_to_odata(mimir_tunnel_guess_caption($field)));
            $loose = mimir_tunnel_loose_name($base);
            if ($norm !== $fnorm && $norm !== $gnorm && $loose !== mimir_tunnel_loose_name($field)) {
                continue;
            }
            if ($base !== $caption) {
                // "Naam#2" hoort niet bij "Naam"/Name: dat beslist de waardevergelijking.
                continue;
            }
            if ($hasSample) {
                $score = mimir_tunnel_column_score($tunnelRows, $odataRows, $caption, $field, (string) $types[$field], $n);
                if ($score['contradicts']) {
                    continue;
                }
            }
            $map[$caption] = $field;
            $method[$caption] = 'naam';
            $usedFields[$field] = true;
            break;
        }
    }

    if ($hasSample) {
        // 2. Per rij (zelfde paginasortering), 3. zonder rijvolgorde.
        foreach (['rij' => true, 'set' => false] as $label => $aligned) {
            $candidates = [];
            foreach ($captions as $caption) {
                if (isset($map[$caption])) {
                    continue;
                }
                foreach ($fields as $field) {
                    if (isset($usedFields[$field])) {
                        continue;
                    }
                    $type = (string) $types[$field];
                    $score = $aligned
                        ? mimir_tunnel_column_score($tunnelRows, $odataRows, $caption, $field, $type, $n)
                        : mimir_tunnel_column_set_score($tunnelRows, $odataRows, $caption, $field, $type);
                    if ($score['score'] < MIMIR_TUNNEL_COLUMNS_MIN_SCORE || $score['support'] < 1) {
                        continue;
                    }
                    $cp = count($captions) > 1 ? ($captionPos[$caption] ?? 0) / (count($captions) - 1) : 0.0;
                    $fp = count($fields) > 1 ? ($fieldPos[$field] ?? 0) / (count($fields) - 1) : 0.0;
                    $candidates[] = [
                        'caption' => $caption,
                        'field' => $field,
                        'score' => $score['score'],
                        'support' => $score['support'],
                        'distance' => abs($cp - $fp),
                        'cpos' => $captionPos[$caption] ?? 0,
                        'fpos' => $fieldPos[$field] ?? 0,
                    ];
                }
            }
            usort($candidates, static function (array $a, array $b): int {
                return [$b['score'], $b['support'], $a['distance'], $a['cpos'], $a['fpos']]
                    <=> [$a['score'], $a['support'], $b['distance'], $b['cpos'], $b['fpos']];
            });
            foreach ($candidates as $candidate) {
                if (isset($map[$candidate['caption']]) || isset($usedFields[$candidate['field']])) {
                    continue;
                }
                $map[$candidate['caption']] = $candidate['field'];
                $method[$candidate['caption']] = $label;
                $usedFields[$candidate['field']] = true;
            }
        }
        // Dubbele captions met dezelfde waarden: volgorde van kolommen = volgorde van velden.
        $map = mimir_tunnel_columns_order_duplicates($map, $captions, $fieldPos, $tunnelRows, $types);
    }

    $unmapped = [];
    foreach ($captions as $caption) {
        if (!isset($map[$caption])) {
            $unmapped[] = $caption;
        }
    }

    return ['map' => $map, 'unmapped' => $unmapped, 'method' => $method];
}

/**
 * Groepen captions met dezelfde basis ("Naam", "Naam#2") die aan velden met
 * dezelfde waarden hangen: herverdeel op volgorde, zodat de eerste kolom bij
 * het eerste veld komt.
 *
 * @param array<string, string> $map
 * @param list<string> $captions
 * @param array<string, int> $fieldPos
 * @param list<array<string, mixed>> $tunnelRows
 * @param array<string, string> $types
 * @return array<string, string>
 */
function mimir_tunnel_columns_order_duplicates(array $map, array $captions, array $fieldPos, array $tunnelRows, array $types): array
{
    $groups = [];
    foreach ($captions as $caption) {
        if (isset($map[$caption])) {
            $groups[(string) preg_replace('/#\d+$/', '', $caption)][] = $caption;
        }
    }
    foreach ($groups as $members) {
        if (count($members) < 2) {
            continue;
        }
        // Alleen als de kolommen in de steekproef niet van elkaar te onderscheiden zijn.
        $first = $members[0];
        $same = true;
        foreach ($tunnelRows as $row) {
            foreach ($members as $member) {
                if (mimir_tunnel_norm_value($row[$member] ?? null, (string) ($types[$map[$member]] ?? ''))
                    !== mimir_tunnel_norm_value($row[$first] ?? null, (string) ($types[$map[$first]] ?? ''))) {
                    $same = false;
                    break 2;
                }
            }
        }
        if (!$same) {
            continue;
        }
        $assigned = [];
        foreach ($members as $member) {
            $assigned[] = $map[$member];
        }
        usort($assigned, static fn (string $a, string $b): int => ($fieldPos[$a] ?? 0) <=> ($fieldPos[$b] ?? 0));
        foreach ($members as $i => $member) {
            $map[$member] = $assigned[$i];
        }
    }

    return $map;
}

/**
 * Score per rij: aandeel rijen (met een waarde aan minstens één kant) waar de waarden gelijk zijn.
 *
 * @param list<array<string, mixed>> $tunnelRows
 * @param list<array<string, mixed>> $odataRows
 * @return array{score: float, support: int, contradicts: bool}
 */
function mimir_tunnel_column_score(array $tunnelRows, array $odataRows, string $caption, string $field, string $type, int $n): array
{
    $equal = 0;
    $differ = 0;
    for ($i = 0; $i < $n; $i++) {
        if (!array_key_exists($caption, $tunnelRows[$i]) || !array_key_exists($field, $odataRows[$i])) {
            continue;
        }
        $a = mimir_tunnel_norm_value($tunnelRows[$i][$caption], $type);
        $b = mimir_tunnel_norm_value($odataRows[$i][$field], $type);
        if ($a === '' && $b === '') {
            continue;
        }
        if ($a === $b) {
            $equal++;
        } else {
            $differ++;
        }
    }
    $total = $equal + $differ;

    return [
        'score' => $total > 0 ? $equal / $total : 0.0,
        'support' => $equal,
        // Naamgelijkheid geldt tenzij de waarden overwegend verschillen.
        'contradicts' => $total > 0 && $equal / $total < 0.5,
    ];
}

/**
 * Score zonder rijvolgorde: overlap van de verschillende niet-lege waarden.
 *
 * @param list<array<string, mixed>> $tunnelRows
 * @param list<array<string, mixed>> $odataRows
 * @return array{score: float, support: int}
 */
function mimir_tunnel_column_set_score(array $tunnelRows, array $odataRows, string $caption, string $field, string $type): array
{
    $a = [];
    foreach ($tunnelRows as $row) {
        $v = mimir_tunnel_norm_value($row[$caption] ?? null, $type);
        if ($v !== '') {
            $a[$v] = true;
        }
    }
    $b = [];
    foreach ($odataRows as $row) {
        $v = mimir_tunnel_norm_value($row[$field] ?? null, $type);
        if ($v !== '') {
            $b[$v] = true;
        }
    }
    if ($a === [] || $b === []) {
        return ['score' => 0.0, 'support' => 0];
    }
    $common = count(array_intersect_key($a, $b));
    // Eén gedeelde waarde (bv. "0" of "Ja") zegt te weinig zonder rijvolgorde.
    if ($common < 2) {
        return ['score' => 0.0, 'support' => 0];
    }

    return ['score' => $common / count($a + $b), 'support' => $common];
}

/**
 * Vergelijkbare vorm van een waarde: tunnel geeft tekst zoals de webclient die
 * toont (NL/EN getal- en datumnotatie), OData getypeerde JSON.
 */
function mimir_tunnel_norm_value(mixed $value, string $type): string
{
    if ($value === null) {
        return '';
    }
    $edm = strtolower($type);
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_int($value) || is_float($value)) {
        if (str_contains($edm, 'boolean')) {
            return $value ? '1' : '0';
        }
        return mimir_tunnel_norm_number((float) $value);
    }
    if (!is_string($value)) {
        return '';
    }
    $text = trim($value);
    if ($text === '') {
        return '';
    }
    if (str_contains($edm, 'boolean')) {
        $low = strtolower($text);
        if (in_array($low, ['1', 'true', 'ja', 'yes', 'waar', 'on'], true)) {
            return '1';
        }
        if (in_array($low, ['0', 'false', 'nee', 'no', 'onwaar', 'off'], true)) {
            return '0';
        }
    }
    if (preg_match('/int|decimal|double|single|float|byte/', $edm) === 1) {
        $number = mimir_tunnel_parse_number($text);
        if ($number !== null) {
            return mimir_tunnel_norm_number($number);
        }
    }
    if (str_contains($edm, 'date')) {
        $date = mimir_tunnel_parse_date($text);
        if ($date !== null) {
            return $date;
        }
    }

    return mb_strtolower($text);
}

function mimir_tunnel_norm_number(float $number): string
{
    $rounded = round($number, 5);
    if ($rounded == 0.0) {
        $rounded = 0.0;
    }

    return rtrim(rtrim(sprintf('%.5F', $rounded), '0'), '.');
}

/** "1.234,50", "1,234.50", "1234,5", "-12" -> float; null als het geen getal is. */
function mimir_tunnel_parse_number(string $text): ?float
{
    $t = str_replace([' ', "\u{00A0}", "\u{202F}"], '', trim($text));
    if (preg_match('/^[-+]?\d{1,3}(\.\d{3})+(,\d+)?$/', $t) === 1) {
        return (float) str_replace(['.', ','], ['', '.'], $t);
    }
    if (preg_match('/^[-+]?\d{1,3}(,\d{3})+(\.\d+)?$/', $t) === 1) {
        return (float) str_replace(',', '', $t);
    }
    if (preg_match('/^[-+]?\d+,\d+$/', $t) === 1) {
        return (float) str_replace(',', '.', $t);
    }
    if (preg_match('/^[-+]?\d+(\.\d+)?$/', $t) === 1) {
        return (float) $t;
    }

    return null;
}

/** ISO, d-m-Y, d/m/Y, d.m.Y (BC-webclient) -> Y-m-d; datum/tijd: alleen het datumdeel. */
function mimir_tunnel_parse_date(string $text): ?string
{
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $text, $m) === 1) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
    if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2,4})/', $text, $m) === 1) {
        $year = strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];
        $day = (int) $m[1];
        $month = (int) $m[2];
        if ($month > 12 && $day <= 12) {
            [$day, $month] = [$month, $day];
        }
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    return null;
}

/**
 * Geforceerd opnieuw leren (koppeling klopt niet meer). null = niet mogelijk
 * (geen OData-steekproef, of al geleerd in dit request).
 *
 * @param array<string, mixed> $entry
 * @param array<string, mixed> $body
 * @param array<string, mixed> $ctx
 * @return array<string, mixed>|null
 */
function mimir_tunnel_relearn_columns(array $entry, array $body, array $ctx): ?array
{
    if (!is_callable($ctx['odata_sample'] ?? null)) {
        return null;
    }
    if (!empty($GLOBALS['mimir_tunnel_columns_learned'][(string) ($ctx['entity'] ?? '')])) {
        return null;
    }

    return mimir_tunnel_ensure_columns($entry, $body, $ctx, true);
}

/**
 * OData-URL voor de steekproef: de collectie van deze entity, ongefilterd, $top=N.
 * Een kaart-URL (Entity(No='A')) wordt de collectie.
 */
function mimir_tunnel_sample_url(string $url, string $entity, int $top): ?string
{
    $hash = strpos($url, '#');
    if ($hash !== false) {
        $url = substr($url, 0, $hash);
    }
    $q = strpos($url, '?');
    $base = $q === false ? $url : substr($url, 0, $q);
    $quoted = preg_quote(rawurlencode($entity), '#') . '|' . preg_quote($entity, '#');
    if (preg_match('#^(.*/(?:' . $quoted . '))(?:\(.*\))?/?$#', $base, $m) !== 1) {
        return null;
    }

    return $m[1] . '?$top=' . max(1, min(MIMIR_TUNNEL_COLUMNS_SAMPLE, $top));
}

/** Alleen letters/cijfers, kleine letters: "Balance (LCY)" en Balance_LCY worden allebei "balancelcy". */
function mimir_tunnel_loose_name(string $name): string
{
    return strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $name));
}
