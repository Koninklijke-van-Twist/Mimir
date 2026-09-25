<?php

declare(strict_types=1);

const MIMIR_FILTER_OPS = ['eq', 'ne', 'gt', 'ge', 'lt', 'le', 'contains', 'startswith', 'endswith'];
const MIMIR_FILTER_MAX_DEPTH = 8;
const MIMIR_FILTER_MAX_STRING = 32768;
const MIMIR_FILTER_OR_BATCH = 40;
/** Max length of one batched $filter string (URL safety). */
const MIMIR_FILTER_BATCH_MAX_CHARS = 1500;

function mimir_filter_field_ok(string $field): bool
{
    return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $field);
}

/**
 * @return string|null fouttekst, of null als het filter geldig is
 */
function mimir_filter_validate(mixed $filter, int $depth = 0): ?string
{
    if ($filter === null) {
        return null;
    }
    if (is_string($filter)) {
        if (trim($filter) === '') {
            return 'Filter-string mag niet leeg zijn.';
        }
        if (strlen($filter) > MIMIR_FILTER_MAX_STRING) {
            return 'Filter-string is te lang (max ' . MIMIR_FILTER_MAX_STRING . ' tekens).';
        }
        return null;
    }
    if (!is_array($filter)) {
        return 'Filter moet een object of OData $filter-string zijn.';
    }
    if ($depth > MIMIR_FILTER_MAX_DEPTH) {
        return 'Filter is te diep genest.';
    }

    $groups = [];
    foreach (['and', 'or', 'xor'] as $name) {
        if (array_key_exists($name, $filter)) {
            $groups[] = $name;
        }
    }
    $looksLikeLeaf = array_key_exists('field', $filter) || array_key_exists('op', $filter) || array_key_exists('value', $filter);
    if (count($groups) > 1 || ($groups !== [] && $looksLikeLeaf)) {
        return 'Een filterknoop is een groep (and/or/xor) of een voorwaarde, niet allebei.';
    }
    if ($groups !== []) {
        $name = $groups[0];
        $children = $filter[$name];
        if (!is_array($children) || array_is_list($children) === false) {
            return 'Groep ' . $name . ' moet een lijst zijn.';
        }
        foreach ($children as $child) {
            $error = mimir_filter_validate($child, $depth + 1);
            if ($error !== null) {
                return $error;
            }
        }
        return null;
    }

    if (!isset($filter['field'], $filter['op']) || !array_key_exists('value', $filter)) {
        return 'Voorwaarde mist field, op of value.';
    }
    $field = $filter['field'];
    $op = $filter['op'];
    if (!is_string($field) || !mimir_filter_field_ok($field)) {
        return 'Veldnaam is ongeldig.';
    }
    if (!is_string($op) || !in_array(strtolower($op), MIMIR_FILTER_OPS, true)) {
        return 'Operator is ongeldig.';
    }
    if (is_array($filter['value'])) {
        return 'Filterwaarde moet een scalar of null zijn.';
    }

    return null;
}

/**
 * @param array<string, string> $types veld => Edm-type
 * @return array{mode: string, odata: ?string}
 */
function mimir_filter_plan(mixed $filter, array $types = []): array
{
    if ($filter === null) {
        return ['mode' => 'none', 'odata' => null];
    }
    if (is_string($filter)) {
        return ['mode' => 'bc', 'odata' => $filter];
    }
    if (!is_array($filter)) {
        return ['mode' => 'local', 'odata' => null];
    }
    if (isset($filter['and']) && $filter['and'] === []) {
        return ['mode' => 'none', 'odata' => null];
    }
    $full = mimir_filter_odata_tree($filter, $types);
    if ($full !== null) {
        return ['mode' => 'bc', 'odata' => $full];
    }
    if (isset($filter['and']) && is_array($filter['and'])) {
        $parts = [];
        foreach ($filter['and'] as $child) {
            $part = mimir_filter_odata_tree($child, $types);
            if ($part !== null) {
                $parts[] = $part;
            }
        }
        if ($parts !== []) {
            return ['mode' => 'mixed', 'odata' => '(' . implode(') and (', $parts) . ')'];
        }
    }

    return ['mode' => 'local', 'odata' => null];
}

/**
 * Hele boom naar $filter, of null als BC dit niet veilig kan (XOR, of OR over verschillende velden).
 *
 * @param array<string, string> $types
 */
function mimir_filter_odata_tree(mixed $filter, array $types): ?string
{
    if (!is_array($filter)) {
        return null;
    }
    if (isset($filter['xor'])) {
        return null;
    }
    if (isset($filter['and']) && is_array($filter['and'])) {
        if ($filter['and'] === []) {
            return null;
        }
        $parts = [];
        foreach ($filter['and'] as $child) {
            $part = mimir_filter_odata_tree($child, $types);
            if ($part === null) {
                return null;
            }
            $parts[] = $part;
        }
        return '(' . implode(') and (', $parts) . ')';
    }
    if (isset($filter['or']) && is_array($filter['or'])) {
        if ($filter['or'] === []) {
            return null;
        }
        $fields = mimir_filter_fields($filter);
        if (count($fields) !== 1) {
            return null;
        }
        $parts = [];
        foreach ($filter['or'] as $child) {
            $part = mimir_filter_odata_tree($child, $types);
            if ($part === null) {
                return null;
            }
            $parts[] = $part;
        }
        return '(' . implode(') or (', $parts) . ')';
    }
    if (!isset($filter['field'], $filter['op']) || !array_key_exists('value', $filter)) {
        return null;
    }
    $field = (string) $filter['field'];
    if (!mimir_filter_field_ok($field)) {
        return null;
    }
    $op = strtolower((string) $filter['op']);
    if (!in_array($op, MIMIR_FILTER_OPS, true)) {
        return null;
    }
    $type = (string) ($types[$field] ?? 'Edm.String');
    try {
        $literal = mimir_odata_literal($filter['value'], $type);
    } catch (InvalidArgumentException) {
        return null;
    }
    if (in_array($op, ['contains', 'startswith', 'endswith'], true)) {
        if ($filter['value'] === null) {
            return null;
        }
        return $op . '(' . $field . ',' . $literal . ')';
    }

    return $field . ' ' . $op . ' ' . $literal;
}

/**
 * @return list<string>
 */
function mimir_filter_fields(mixed $filter): array
{
    if (is_string($filter) || !is_array($filter)) {
        return [];
    }
    foreach (['and', 'or', 'xor'] as $name) {
        if (isset($filter[$name]) && is_array($filter[$name])) {
            $fields = [];
            foreach ($filter[$name] as $child) {
                foreach (mimir_filter_fields($child) as $field) {
                    $fields[$field] = true;
                }
            }
            return array_keys($fields);
        }
    }
    if (isset($filter['field']) && is_string($filter['field'])) {
        return [$filter['field']];
    }
    return [];
}

function mimir_odata_literal(mixed $value, string $type): string
{
    if ($value === null) {
        return 'null';
    }
    $edm = strtolower($type);
    if (str_contains($edm, 'boolean')) {
        $bool = mimir_bool_value($value);
        if ($bool === null) {
            throw new InvalidArgumentException('Booleaanse filterwaarde is ongeldig.');
        }
        return $bool ? 'true' : 'false';
    }
    if (preg_match('/int|decimal|double|single|float|byte/', $edm) === 1) {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            throw new InvalidArgumentException('Numerieke filterwaarde is ongeldig.');
        }
        if (preg_match('/decimal|double|single|float/', $edm) === 1) {
            $formatted = rtrim(rtrim(sprintf('%.10F', (float) $value), '0'), '.');
            return $formatted === '' ? '0' : $formatted;
        }
        if (is_float($value) || (is_string($value) && str_contains($value, '.'))) {
            $formatted = rtrim(rtrim(sprintf('%.10F', (float) $value), '0'), '.');
            return $formatted === '' ? '0' : $formatted;
        }
        return (string) (int) $value;
    }
    if (str_contains($edm, 'guid')) {
        $guid = (string) $value;
        if (!preg_match('/^[0-9a-fA-F-]{32,36}$/', $guid)) {
            throw new InvalidArgumentException('GUID is ongeldig.');
        }
        return "guid'" . $guid . "'";
    }
    if (str_contains($edm, 'date') || str_contains($edm, 'time')) {
        $text = trim((string) $value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:\d{2})?)?$/', $text)) {
            throw new InvalidArgumentException('Datumfilter is ongeldig.');
        }
        return str_replace(' ', 'T', $text);
    }

    return "'" . str_replace("'", "''", (string) $value) . "'";
}

function mimir_bool_value(mixed $value): ?bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) && ($value === 0 || $value === 1)) {
        return $value === 1;
    }
    if (is_string($value)) {
        $text = strtolower(trim($value));
        if (in_array($text, ['true', '1'], true)) {
            return true;
        }
        if (in_array($text, ['false', '0'], true)) {
            return false;
        }
    }
    return null;
}

/**
 * @param array<string, mixed> $row
 * @param array<string, string> $types
 */
function mimir_filter_match(array $row, mixed $filter, array $types = []): bool
{
    if ($filter === null) {
        return true;
    }
    // Opaque OData $filter-string: BC heeft al gefilterd.
    if (is_string($filter)) {
        return true;
    }
    if (!is_array($filter)) {
        return false;
    }
    if (isset($filter['and']) && is_array($filter['and'])) {
        foreach ($filter['and'] as $child) {
            if (!mimir_filter_match($row, $child, $types)) {
                return false;
            }
        }
        return true;
    }
    if (isset($filter['or']) && is_array($filter['or'])) {
        foreach ($filter['or'] as $child) {
            if (mimir_filter_match($row, $child, $types)) {
                return true;
            }
        }
        return false;
    }
    if (isset($filter['xor']) && is_array($filter['xor'])) {
        $trues = 0;
        foreach ($filter['xor'] as $child) {
            if (mimir_filter_match($row, $child, $types)) {
                $trues++;
            }
        }
        return ($trues % 2) === 1;
    }
    if (!isset($filter['field'], $filter['op']) || !array_key_exists('value', $filter)) {
        return false;
    }
    $field = (string) $filter['field'];
    $actual = array_key_exists($field, $row) ? $row[$field] : null;
    $type = (string) ($types[$field] ?? 'Edm.String');
    return mimir_filter_compare($actual, strtolower((string) $filter['op']), $filter['value'], $type);
}

function mimir_filter_compare(mixed $actual, string $op, mixed $expected, string $type): bool
{
    if (in_array($op, ['contains', 'startswith', 'endswith'], true)) {
        if ($actual === null || $expected === null) {
            return false;
        }
        $haystack = (string) $actual;
        $needle = (string) $expected;
        return match ($op) {
            'contains' => str_contains($haystack, $needle),
            'startswith' => str_starts_with($haystack, $needle),
            'endswith' => str_ends_with($haystack, $needle),
            default => false,
        };
    }

    $edm = strtolower($type);
    $numeric = preg_match('/int|decimal|double|single|float|byte/', $edm) === 1;
    $boolean = str_contains($edm, 'boolean');

    if ($actual === null || $expected === null) {
        $bothNull = $actual === null && $expected === null;
        return match ($op) {
            'eq' => $bothNull,
            'ne' => !$bothNull,
            default => false,
        };
    }

    if ($boolean) {
        $left = mimir_bool_value($actual);
        $right = mimir_bool_value($expected);
        if ($left === null || $right === null) {
            return false;
        }
        return match ($op) {
            'eq' => $left === $right,
            'ne' => $left !== $right,
            default => false,
        };
    }

    if ($numeric && is_numeric($actual) && is_numeric($expected)) {
        $left = (float) $actual;
        $right = (float) $expected;
        return match ($op) {
            'eq' => $left == $right,
            'ne' => $left != $right,
            'gt' => $left > $right,
            'ge' => $left >= $right,
            'lt' => $left < $right,
            'le' => $left <= $right,
            default => false,
        };
    }

    $left = (string) $actual;
    $right = (string) $expected;
    return match ($op) {
        'eq' => $left === $right,
        'ne' => $left !== $right,
        'gt' => $left > $right,
        'ge' => $left >= $right,
        'lt' => $left < $right,
        'le' => $left <= $right,
        default => false,
    };
}

/**
 * Splits a list of OData leaf clauses into OR-batches (count and URL length).
 *
 * @param list<string> $parts
 * @return list<string>
 */
function mimir_filter_chunk_or_parts(array $parts): array
{
    if ($parts === []) {
        return [];
    }
    $batches = [];
    $current = [];
    foreach ($parts as $part) {
        $part = (string) $part;
        $candidate = array_merge($current, [$part]);
        $wrapped = '(' . implode(') or (', $candidate) . ')';
        if (
            $current !== []
            && (
                count($current) >= MIMIR_FILTER_OR_BATCH
                || strlen($wrapped) > MIMIR_FILTER_BATCH_MAX_CHARS
            )
        ) {
            $batches[] = '(' . implode(') or (', $current) . ')';
            $current = [$part];
            continue;
        }
        $current = $candidate;
    }
    if ($current !== []) {
        $batches[] = '(' . implode(') or (', $current) . ')';
    }
    return $batches;
}

/**
 * Detect a simple same-field eq OR string: (Field eq 'a') or (Field eq 'b') …
 *
 * @return list<string>|null leaf clauses (Field eq '…'), or null if too complex
 */
function mimir_filter_parse_simple_eq_or_string(string $filter): ?array
{
    $rest = trim($filter);
    if ($rest === '') {
        return null;
    }
    $field = null;
    $parts = [];
    while ($rest !== '') {
        if (
            preg_match(
                "/^\(?\s*([A-Za-z_][A-Za-z0-9_]*)\s+eq\s+'((?:[^']|'')*)'\s*\)?\s*(?:or\b|$)/i",
                $rest,
                $match
            ) !== 1
        ) {
            return null;
        }
        $name = $match[1];
        if ($field === null) {
            $field = $name;
        } elseif (strcasecmp($field, $name) !== 0) {
            return null;
        }
        $parts[] = $name . " eq '" . $match[2] . "'";
        $rest = ltrim(substr($rest, strlen($match[0])));
    }
    return $parts === [] ? null : $parts;
}

/**
 * Same-field OR of pushable leaves from a JSON tree (top-level `or` only).
 *
 * @param array<string, string> $types
 * @return list<string>|null
 */
function mimir_filter_same_field_or_parts(mixed $filter, array $types): ?array
{
    if (!is_array($filter) || !isset($filter['or']) || !is_array($filter['or']) || !array_is_list($filter['or'])) {
        return null;
    }
    if (count(mimir_filter_fields($filter)) !== 1) {
        return null;
    }
    $parts = [];
    foreach ($filter['or'] as $child) {
        $part = mimir_filter_odata_tree($child, $types);
        if ($part === null) {
            return null;
        }
        $parts[] = $part;
    }
    return $parts === [] ? null : $parts;
}

/**
 * OData $filter strings for BC batches. Null = no batching (use the normal single plan).
 *
 * @param array<string, string> $types
 * @return list<string>|null
 */
function mimir_filter_odata_batches(mixed $filter, array $types = []): ?array
{
    $parts = null;
    if (is_string($filter)) {
        $parts = mimir_filter_parse_simple_eq_or_string($filter);
    } elseif (is_array($filter)) {
        $parts = mimir_filter_same_field_or_parts($filter, $types);
    }
    if ($parts === null) {
        return null;
    }
    $batches = mimir_filter_chunk_or_parts($parts);
    if (count($batches) <= 1) {
        return null;
    }
    return $batches;
}

function mimir_filter_note(string $mode): ?string
{
    return match ($mode) {
        'bc' => 'Het filter is volledig als OData $filter naar Business Central gestuurd.',
        'mixed' => 'Een deel van het filter (de verplichte AND-voorwaarden) ging naar Business Central. OR over verschillende velden en XOR zijn lokaal toegepast.',
        'local' => 'Het filter is lokaal toegepast. Business Central weigert OR over verschillende velden vaak met HTTP 501, en XOR kent OData niet.',
        default => null,
    };
}

/**
 * Opaque OData $filter strings cannot be applied locally.
 */
function mimir_filter_allows_local(mixed $filter): bool
{
    return !is_string($filter);
}

/**
 * Contiguous range on one field: eq / ge / gt / le / lt, or AND of those on the same field.
 *
 * @param array<string, string> $types
 * @return array{
 *   field: string,
 *   lower: mixed,
 *   lower_inclusive: bool,
 *   upper: mixed,
 *   upper_inclusive: bool
 * }|null
 */
function mimir_filter_as_range(mixed $filter, array $types = []): ?array
{
    if ($filter === null || is_string($filter) || !is_array($filter)) {
        return null;
    }
    $leaves = [];
    if (isset($filter['and']) && is_array($filter['and']) && array_is_list($filter['and'])) {
        foreach ($filter['and'] as $child) {
            if (!is_array($child) || isset($child['and']) || isset($child['or']) || isset($child['xor'])) {
                return null;
            }
            $leaves[] = $child;
        }
    } elseif (isset($filter['field'], $filter['op']) && array_key_exists('value', $filter)) {
        $leaves[] = $filter;
    } else {
        return null;
    }
    if ($leaves === []) {
        return null;
    }

    $field = null;
    $lower = null;
    $lowerInc = true;
    $upper = null;
    $upperInc = true;
    $hasLower = false;
    $hasUpper = false;

    foreach ($leaves as $leaf) {
        if (!isset($leaf['field'], $leaf['op']) || !array_key_exists('value', $leaf)) {
            return null;
        }
        $name = (string) $leaf['field'];
        if ($field === null) {
            $field = $name;
        } elseif ($field !== $name) {
            return null;
        }
        $op = strtolower((string) $leaf['op']);
        $value = $leaf['value'];
        if (!in_array($op, ['eq', 'ge', 'gt', 'le', 'lt'], true)) {
            return null;
        }
        if ($op === 'eq') {
            if ($hasLower || $hasUpper) {
                return null;
            }
            $lower = $value;
            $upper = $value;
            $lowerInc = true;
            $upperInc = true;
            $hasLower = true;
            $hasUpper = true;
            continue;
        }
        if ($op === 'ge' || $op === 'gt') {
            if ($hasLower) {
                return null;
            }
            $lower = $value;
            $lowerInc = ($op === 'ge');
            $hasLower = true;
            continue;
        }
        if ($hasUpper) {
            return null;
        }
        $upper = $value;
        $upperInc = ($op === 'le');
        $hasUpper = true;
    }

    if ($field === null || (!$hasLower && !$hasUpper)) {
        return null;
    }
    if ($hasLower && $hasUpper) {
        $type = (string) ($types[$field] ?? 'Edm.String');
        $cmp = mimir_filter_range_cmp($lower, $upper, $type);
        if ($cmp > 0) {
            return null;
        }
        if ($cmp === 0 && (!$lowerInc || !$upperInc)) {
            return null;
        }
    }

    return [
        'field' => $field,
        'lower' => $hasLower ? $lower : null,
        'lower_inclusive' => $hasLower ? $lowerInc : true,
        'upper' => $hasUpper ? $upper : null,
        'upper_inclusive' => $hasUpper ? $upperInc : true,
    ];
}

/**
 * Parse a simple OData range filter_sig (eq / ge / gt / le / lt, optional AND).
 *
 * @return array{
 *   field: string,
 *   lower: mixed,
 *   lower_inclusive: bool,
 *   upper: mixed,
 *   upper_inclusive: bool
 * }|null
 */
function mimir_filter_range_parse_odata(string $sig): ?array
{
    $sig = trim($sig);
    if ($sig === '') {
        return null;
    }
    $parts = preg_split('/\s+and\s+/i', $sig);
    if ($parts === false || $parts === []) {
        return null;
    }
    $leaves = [];
    foreach ($parts as $part) {
        $part = trim($part);
        $part = preg_replace('/^\(|\)$/', '', $part) ?? $part;
        $part = trim($part);
        if (
            preg_match(
                "/^([A-Za-z_][A-Za-z0-9_]*)\s+(eq|ne|gt|ge|lt|le)\s+(.+)$/i",
                $part,
                $match
            ) !== 1
        ) {
            return null;
        }
        $literal = trim($match[3]);
        try {
            $value = mimir_filter_parse_odata_literal($literal);
        } catch (InvalidArgumentException) {
            return null;
        }
        $leaves[] = [
            'field' => $match[1],
            'op' => strtolower($match[2]),
            'value' => $value,
        ];
    }
    if (count($leaves) === 1) {
        return mimir_filter_as_range($leaves[0]);
    }
    return mimir_filter_as_range(['and' => $leaves]);
}

/**
 * @throws InvalidArgumentException
 */
function mimir_filter_parse_odata_literal(string $literal): mixed
{
    $literal = trim($literal);
    if (strcasecmp($literal, 'null') === 0) {
        return null;
    }
    if (strcasecmp($literal, 'true') === 0) {
        return true;
    }
    if (strcasecmp($literal, 'false') === 0) {
        return false;
    }
    if (preg_match("/^'(.*)'$/s", $literal, $match) === 1) {
        return str_replace("''", "'", $match[1]);
    }
    if (preg_match("/^guid'([^']+)'$/i", $literal, $match) === 1) {
        return $match[1];
    }
    if (is_numeric($literal)) {
        return str_contains($literal, '.') ? (float) $literal : (int) $literal;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $literal) === 1) {
        return $literal;
    }
    throw new InvalidArgumentException('Unsupported OData literal.');
}

/**
 * @param array{
 *   field: string,
 *   lower: mixed,
 *   lower_inclusive: bool,
 *   upper: mixed,
 *   upper_inclusive: bool
 * } $range
 * @param array<string, string> $types
 */
function mimir_filter_range_to_odata(array $range, array $types = []): string
{
    $field = $range['field'];
    $type = (string) ($types[$field] ?? 'Edm.String');
    $parts = [];
    if ($range['lower'] !== null) {
        $op = !empty($range['lower_inclusive']) ? 'ge' : 'gt';
        $parts[] = $field . ' ' . $op . ' ' . mimir_odata_literal($range['lower'], $type);
    }
    if ($range['upper'] !== null) {
        $op = !empty($range['upper_inclusive']) ? 'le' : 'lt';
        $parts[] = $field . ' ' . $op . ' ' . mimir_odata_literal($range['upper'], $type);
    }
    if ($parts === []) {
        throw new InvalidArgumentException('Range has no bounds.');
    }
    if (count($parts) === 1) {
        return $parts[0];
    }
    return '(' . implode(') and (', $parts) . ')';
}

/**
 * @param array{
 *   field: string,
 *   lower: mixed,
 *   lower_inclusive: bool,
 *   upper: mixed,
 *   upper_inclusive: bool
 * } $range
 * @return array<string, mixed>
 */
function mimir_filter_range_to_json(array $range): array
{
    $leaves = [];
    if ($range['lower'] !== null) {
        $leaves[] = [
            'field' => $range['field'],
            'op' => !empty($range['lower_inclusive']) ? 'ge' : 'gt',
            'value' => $range['lower'],
        ];
    }
    if ($range['upper'] !== null) {
        $leaves[] = [
            'field' => $range['field'],
            'op' => !empty($range['upper_inclusive']) ? 'le' : 'lt',
            'value' => $range['upper'],
        ];
    }
    if ($leaves === []) {
        throw new InvalidArgumentException('Range has no bounds.');
    }
    if (
        count($leaves) === 2
        && $range['lower'] !== null
        && $range['upper'] !== null
        && $range['lower'] === $range['upper']
        && !empty($range['lower_inclusive'])
        && !empty($range['upper_inclusive'])
    ) {
        return ['field' => $range['field'], 'op' => 'eq', 'value' => $range['lower']];
    }
    if (count($leaves) === 1) {
        return $leaves[0];
    }
    return ['and' => $leaves];
}

/**
 * @return int negative if $a < $b, 0 if equal, positive if $a > $b
 */
function mimir_filter_range_cmp(mixed $a, mixed $b, string $type): int
{
    $edm = strtolower($type);
    $numeric = preg_match('/int|decimal|double|single|float|byte/', $edm) === 1;
    if ($numeric && is_numeric($a) && is_numeric($b)) {
        return ((float) $a) <=> ((float) $b);
    }
    return ((string) $a) <=> ((string) $b);
}

/**
 * True when $outer fully contains $inner (same field).
 *
 * @param array{field: string, lower: mixed, lower_inclusive: bool, upper: mixed, upper_inclusive: bool} $outer
 * @param array{field: string, lower: mixed, lower_inclusive: bool, upper: mixed, upper_inclusive: bool} $inner
 */
function mimir_filter_range_contains(array $outer, array $inner, string $type): bool
{
    if ($outer['field'] !== $inner['field']) {
        return false;
    }
    if ($inner['lower'] !== null) {
        if ($outer['lower'] !== null) {
            $cmp = mimir_filter_range_cmp($outer['lower'], $inner['lower'], $type);
            if ($cmp > 0) {
                return false;
            }
            if ($cmp === 0 && !$outer['lower_inclusive'] && $inner['lower_inclusive']) {
                return false;
            }
        }
    } elseif ($outer['lower'] !== null) {
        return false;
    }
    if ($inner['upper'] !== null) {
        if ($outer['upper'] !== null) {
            $cmp = mimir_filter_range_cmp($outer['upper'], $inner['upper'], $type);
            if ($cmp < 0) {
                return false;
            }
            if ($cmp === 0 && !$outer['upper_inclusive'] && $inner['upper_inclusive']) {
                return false;
            }
        }
    } elseif ($outer['upper'] !== null) {
        return false;
    }
    return true;
}

/**
 * Subtract covered ranges from request; return contiguous gaps (same field).
 *
 * @param array{field: string, lower: mixed, lower_inclusive: bool, upper: mixed, upper_inclusive: bool} $request
 * @param list<array{field: string, lower: mixed, lower_inclusive: bool, upper: mixed, upper_inclusive: bool}> $covered
 * @return list<array{field: string, lower: mixed, lower_inclusive: bool, upper: mixed, upper_inclusive: bool}>
 */
function mimir_filter_range_gaps(array $request, array $covered, string $type): array
{
    $field = $request['field'];
    $relevant = [];
    foreach ($covered as $range) {
        if (($range['field'] ?? '') !== $field) {
            continue;
        }
        $clipped = mimir_filter_range_intersect($request, $range, $type);
        if ($clipped !== null) {
            $relevant[] = $clipped;
        }
    }
    if ($relevant === []) {
        return [$request];
    }
    usort($relevant, static function (array $a, array $b) use ($type): int {
        if ($a['lower'] === null) {
            return $b['lower'] === null ? 0 : -1;
        }
        if ($b['lower'] === null) {
            return 1;
        }
        $cmp = mimir_filter_range_cmp($a['lower'], $b['lower'], $type);
        if ($cmp !== 0) {
            return $cmp;
        }
        return ($b['lower_inclusive'] <=> $a['lower_inclusive']);
    });

    $merged = [];
    foreach ($relevant as $range) {
        if ($merged === []) {
            $merged[] = $range;
            continue;
        }
        $lastIdx = count($merged) - 1;
        if (mimir_filter_range_can_merge($merged[$lastIdx], $range, $type)) {
            $merged[$lastIdx] = mimir_filter_range_union_pair($merged[$lastIdx], $range, $type);
        } else {
            $merged[] = $range;
        }
    }

    $gaps = [];
    $cursorLower = $request['lower'];
    $cursorLowerInc = $request['lower_inclusive'];
    foreach ($merged as $piece) {
        $gap = mimir_filter_range_before($cursorLower, $cursorLowerInc, $piece, $field, $type);
        if ($gap !== null) {
            $gaps[] = $gap;
        }
        if ($piece['upper'] === null) {
            return $gaps;
        }
        $cursorLower = $piece['upper'];
        $cursorLowerInc = !$piece['upper_inclusive'];
    }
    $tail = [
        'field' => $field,
        'lower' => $cursorLower,
        'lower_inclusive' => $cursorLowerInc,
        'upper' => $request['upper'],
        'upper_inclusive' => $request['upper_inclusive'],
    ];
    if (mimir_filter_range_nonempty($tail, $type)) {
        $gaps[] = $tail;
    }
    return $gaps;
}

/**
 * @param array{field: string, lower: mixed, lower_inclusive: bool, upper: mixed, upper_inclusive: bool} $a
 * @param array{field: string, lower: mixed, lower_inclusive: bool, upper: mixed, upper_inclusive: bool} $b
 * @return array{field: string, lower: mixed, lower_inclusive: bool, upper: mixed, upper_inclusive: bool}|null
 */
function mimir_filter_range_intersect(array $a, array $b, string $type): ?array
{
    if ($a['field'] !== $b['field']) {
        return null;
    }
    $lower = null;
    $lowerInc = true;
    if ($a['lower'] === null && $b['lower'] === null) {
        $lower = null;
        $lowerInc = true;
    } elseif ($a['lower'] === null) {
        $lower = $b['lower'];
        $lowerInc = $b['lower_inclusive'];
    } elseif ($b['lower'] === null) {
        $lower = $a['lower'];
        $lowerInc = $a['lower_inclusive'];
    } else {
        $cmp = mimir_filter_range_cmp($a['lower'], $b['lower'], $type);
        if ($cmp > 0) {
            $lower = $a['lower'];
            $lowerInc = $a['lower_inclusive'];
        } elseif ($cmp < 0) {
            $lower = $b['lower'];
            $lowerInc = $b['lower_inclusive'];
        } else {
            $lower = $a['lower'];
            $lowerInc = $a['lower_inclusive'] && $b['lower_inclusive'];
        }
    }
    $upper = null;
    $upperInc = true;
    if ($a['upper'] === null && $b['upper'] === null) {
        $upper = null;
        $upperInc = true;
    } elseif ($a['upper'] === null) {
        $upper = $b['upper'];
        $upperInc = $b['upper_inclusive'];
    } elseif ($b['upper'] === null) {
        $upper = $a['upper'];
        $upperInc = $a['upper_inclusive'];
    } else {
        $cmp = mimir_filter_range_cmp($a['upper'], $b['upper'], $type);
        if ($cmp < 0) {
            $upper = $a['upper'];
            $upperInc = $a['upper_inclusive'];
        } elseif ($cmp > 0) {
            $upper = $b['upper'];
            $upperInc = $b['upper_inclusive'];
        } else {
            $upper = $a['upper'];
            $upperInc = $a['upper_inclusive'] && $b['upper_inclusive'];
        }
    }
    $out = [
        'field' => $a['field'],
        'lower' => $lower,
        'lower_inclusive' => $lowerInc,
        'upper' => $upper,
        'upper_inclusive' => $upperInc,
    ];
    return mimir_filter_range_nonempty($out, $type) ? $out : null;
}

/**
 * @param array{field: string, lower: mixed, lower_inclusive: bool, upper: mixed, upper_inclusive: bool} $range
 */
function mimir_filter_range_nonempty(array $range, string $type): bool
{
    if ($range['lower'] === null || $range['upper'] === null) {
        return true;
    }
    $cmp = mimir_filter_range_cmp($range['lower'], $range['upper'], $type);
    if ($cmp < 0) {
        return true;
    }
    if ($cmp > 0) {
        return false;
    }
    return $range['lower_inclusive'] && $range['upper_inclusive'];
}

/**
 * @param array{field: string, lower: mixed, lower_inclusive: bool, upper: mixed, upper_inclusive: bool} $a
 * @param array{field: string, lower: mixed, lower_inclusive: bool, upper: mixed, upper_inclusive: bool} $b
 */
function mimir_filter_range_can_merge(array $a, array $b, string $type): bool
{
    if ($a['field'] !== $b['field']) {
        return false;
    }
    if ($a['upper'] === null || $b['lower'] === null) {
        return true;
    }
    $cmp = mimir_filter_range_cmp($a['upper'], $b['lower'], $type);
    if ($cmp > 0) {
        return true;
    }
    if ($cmp < 0) {
        return false;
    }
    // Same boundary: gap only when both exclude the point (lt X and gt X).
    return $a['upper_inclusive'] || $b['lower_inclusive'];
}

/**
 * @param array{field: string, lower: mixed, lower_inclusive: bool, upper: mixed, upper_inclusive: bool} $a
 * @param array{field: string, lower: mixed, lower_inclusive: bool, upper: mixed, upper_inclusive: bool} $b
 * @return array{field: string, lower: mixed, lower_inclusive: bool, upper: mixed, upper_inclusive: bool}
 */
function mimir_filter_range_union_pair(array $a, array $b, string $type): array
{
    $lower = $a['lower'];
    $lowerInc = $a['lower_inclusive'];
    if ($a['lower'] === null || $b['lower'] === null) {
        $lower = null;
        $lowerInc = true;
    } else {
        $cmp = mimir_filter_range_cmp($a['lower'], $b['lower'], $type);
        if ($cmp > 0) {
            $lower = $b['lower'];
            $lowerInc = $b['lower_inclusive'];
        } elseif ($cmp === 0) {
            $lowerInc = $a['lower_inclusive'] || $b['lower_inclusive'];
        }
    }
    $upper = $a['upper'];
    $upperInc = $a['upper_inclusive'];
    if ($a['upper'] === null || $b['upper'] === null) {
        $upper = null;
        $upperInc = true;
    } else {
        $cmp = mimir_filter_range_cmp($a['upper'], $b['upper'], $type);
        if ($cmp < 0) {
            $upper = $b['upper'];
            $upperInc = $b['upper_inclusive'];
        } elseif ($cmp === 0) {
            $upperInc = $a['upper_inclusive'] || $b['upper_inclusive'];
        }
    }
    return [
        'field' => $a['field'],
        'lower' => $lower,
        'lower_inclusive' => $lowerInc,
        'upper' => $upper,
        'upper_inclusive' => $upperInc,
    ];
}

/**
 * @param array{field: string, lower: mixed, lower_inclusive: bool, upper: mixed, upper_inclusive: bool} $piece
 * @return array{field: string, lower: mixed, lower_inclusive: bool, upper: mixed, upper_inclusive: bool}|null
 */
function mimir_filter_range_before(
    mixed $cursorLower,
    bool $cursorLowerInc,
    array $piece,
    string $field,
    string $type
): ?array {
    if ($piece['lower'] === null) {
        return null;
    }
    $gap = [
        'field' => $field,
        'lower' => $cursorLower,
        'lower_inclusive' => $cursorLowerInc,
        'upper' => $piece['lower'],
        'upper_inclusive' => !$piece['lower_inclusive'],
    ];
    return mimir_filter_range_nonempty($gap, $type) ? $gap : null;
}
