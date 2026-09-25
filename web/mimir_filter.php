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
