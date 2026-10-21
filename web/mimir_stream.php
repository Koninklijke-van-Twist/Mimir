<?php

declare(strict_types=1);

/**
 * Streaming-antwoord voor volledige fetches (top = 0).
 *
 * Een volledige entiteit (bijv. AppItemCard) past niet als één PHP-array in het
 * geheugen. In streaming-modus schrijft mimir_query_entity elke BC-pagina direct
 * in de store en stuurt daarna de rijen één voor één uit de store naar de client:
 *
 *   {"value":[ {...},{...}, ... ],"meta":{...}}
 *
 * Tijdens het vullen gaan na MIMIR_STREAM_KEEPALIVE_AFTER seconden de headers
 * (200) en spaties uit (geldige JSON-whitespace), zodat proxy en client de
 * verbinding niet als idle afbreken. Een fout ná de headers komt als
 * `"error"`/`"status"` in de body; een fout ná het openen van `value` sluit de
 * array af met `"complete":false`.
 */

const MIMIR_STREAM_KEEPALIVE_AFTER = 10.0;
const MIMIR_STREAM_FLUSH_ROWS = 200;
const MIMIR_STREAM_JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

function mimir_stream_enable(): void
{
    $GLOBALS['mimir_stream'] = [
        'enabled' => true,
        'headers' => false,
        'open' => false,
        'done' => false,
        'rows' => 0,
        'started' => microtime(true),
        'last_out' => microtime(true),
    ];
}

function mimir_stream_reset(): void
{
    unset($GLOBALS['mimir_stream']);
}

function mimir_stream_active(): bool
{
    return !empty($GLOBALS['mimir_stream']['enabled']) && empty($GLOBALS['mimir_stream']['done']);
}

/** True zodra er iets naar de client is gestuurd (status ligt dan vast op 200). */
function mimir_stream_started(): bool
{
    return !empty($GLOBALS['mimir_stream']['headers']);
}

function mimir_stream_opened(): bool
{
    return !empty($GLOBALS['mimir_stream']['open']) || !empty($GLOBALS['mimir_stream']['done']);
}

function mimir_stream_out(string $chunk): void
{
    $writer = $GLOBALS['mimir_stream_writer'] ?? null;
    if (is_callable($writer)) {
        // Tests vangen de stream op zonder output buffers te flushen.
        $writer($chunk);
        $GLOBALS['mimir_stream']['last_out'] = microtime(true);
        return;
    }
    echo $chunk;
    while (ob_get_level() > 0 && @ob_end_flush()) {
    }
    @flush();
    $GLOBALS['mimir_stream']['last_out'] = microtime(true);
}

function mimir_stream_headers(): void
{
    if (!empty($GLOBALS['mimir_stream']['headers'])) {
        return;
    }
    $GLOBALS['mimir_stream']['headers'] = true;
    if (!headers_sent() && !is_callable($GLOBALS['mimir_stream_writer'] ?? null)) {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Accel-Buffering: no');
        header('X-Mimir-Stream: 1');
    }
}

/**
 * Per BC-pagina aanroepen tijdens het vullen: verlengt de PHP-tijdslimiet en
 * stuurt na een tijdje een spatie zodat proxy/client niet afhaken.
 */
function mimir_stream_keepalive(): void
{
    if (!mimir_stream_active()) {
        return;
    }
    @set_time_limit(300);
    $state = $GLOBALS['mimir_stream'];
    $now = microtime(true);
    if (empty($state['headers']) && ($now - (float) $state['started']) < MIMIR_STREAM_KEEPALIVE_AFTER) {
        return;
    }
    if (!empty($state['open'])) {
        return;
    }
    mimir_stream_headers();
    mimir_stream_out(' ');
}

function mimir_stream_begin(): void
{
    if (mimir_stream_opened()) {
        throw new RuntimeException('Streaming-antwoord was al gestart en kan niet opnieuw beginnen.');
    }
    mimir_stream_headers();
    $GLOBALS['mimir_stream']['open'] = true;
    $GLOBALS['mimir_stream']['rows'] = 0;
    mimir_stream_out('{"value":[');
}

/** @param array<string, mixed> $row */
function mimir_stream_row(array $row): void
{
    $json = json_encode($row, MIMIR_STREAM_JSON_FLAGS);
    if (!is_string($json)) {
        $json = '{}';
    }
    $rows = (int) $GLOBALS['mimir_stream']['rows'];
    $chunk = ($rows > 0 ? ',' : '') . $json;
    $GLOBALS['mimir_stream']['rows'] = $rows + 1;
    if (($rows + 1) % MIMIR_STREAM_FLUSH_ROWS === 0) {
        @set_time_limit(300);
        mimir_stream_out($chunk);
    } elseif (is_callable($GLOBALS['mimir_stream_writer'] ?? null)) {
        ($GLOBALS['mimir_stream_writer'])($chunk);
    } else {
        echo $chunk;
    }
}

/**
 * Sluit `value` af en schrijft de overige velden van het resultaat (meta e.d.).
 *
 * @param array<string, mixed> $result
 */
function mimir_stream_finish(array $result): void
{
    unset($result['value'], $result['@mimir_streamed']);
    $tail = '';
    if ($result !== []) {
        $json = json_encode($result, MIMIR_STREAM_JSON_FLAGS);
        if (is_string($json) && strlen($json) > 2) {
            $tail = ',' . substr($json, 1, -1);
        }
    }
    $GLOBALS['mimir_stream']['open'] = false;
    $GLOBALS['mimir_stream']['done'] = true;
    mimir_stream_out(']' . $tail . '}');
}

/**
 * Fout nadat er al output is: status kan niet meer veranderen, dus in de body.
 */
function mimir_stream_abort(string $message, int $status): void
{
    $error = json_encode(['error' => $message, 'status' => $status], MIMIR_STREAM_JSON_FLAGS);
    if (!is_string($error)) {
        $error = '{"error":"Onbekende fout.","status":' . $status . '}';
    }
    if (!empty($GLOBALS['mimir_stream']['open'])) {
        $chunk = '],' . substr($error, 1, -1) . ',"meta":{"complete":false}}';
    } else {
        $chunk = $error;
    }
    $GLOBALS['mimir_stream']['open'] = false;
    $GLOBALS['mimir_stream']['done'] = true;
    mimir_stream_out($chunk);
}
