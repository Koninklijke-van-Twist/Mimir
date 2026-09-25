<?php

/**
 * Laadt auth.php zonder geheimen in de repository.
 *
 * Alleen web/auth.php (page root op productie). Geen paden buiten web/.
 * Optioneel: MIMIR_AUTH_FILE, maar alleen als die onder web/ ligt.
 */

function mimir_auth_candidates(): array
{
    $webDir = __DIR__;
    $paths = [];

    $override = getenv('MIMIR_AUTH_FILE');
    if (is_string($override) && trim($override) !== '') {
        $override = trim($override);
        $realOverride = realpath($override) ?: $override;
        $realWeb = realpath($webDir) ?: $webDir;
        $realOverrideNorm = str_replace('\\', '/', (string) $realOverride);
        $realWebNorm = rtrim(str_replace('\\', '/', (string) $realWeb), '/') . '/';
        if (str_starts_with($realOverrideNorm, $realWebNorm) || dirname($realOverrideNorm) === rtrim($realWebNorm, '/')) {
            $paths[] = $override;
        }
    }

    $paths[] = $webDir . DIRECTORY_SEPARATOR . 'auth.php';

    $unique = [];
    foreach ($paths as $path) {
        $key = strtolower(str_replace('\\', '/', $path));
        if (!isset($unique[$key])) {
            $unique[$key] = $path;
        }
    }

    return array_values($unique);
}

function mimir_load_auth(bool $jsonErrors = false): void
{
    global $baseUrl, $allowedUsers, $auth_list, $environment, $auth, $primaryEnvironment;

    if (isset($baseUrl) && is_string($baseUrl) && $baseUrl !== '') {
        return;
    }

    foreach (mimir_auth_candidates() as $path) {
        if (is_file($path)) {
            require_once $path;
            // require binnen een functie houdt losse assignments lokaal tenzij
            // we ze terugzetten op $GLOBALS (en de global-aliassen).
            foreach (['baseUrl', 'allowedUsers', 'auth_list', 'environment', 'auth', 'primaryEnvironment'] as $name) {
                if (array_key_exists($name, get_defined_vars())) {
                    $GLOBALS[$name] = $$name;
                }
            }
            return;
        }
    }

    $message = 'auth.php niet gevonden. Zet web/auth.php op de server (niet in git); lokaal ook onder web/.';
    if (PHP_SAPI === 'cli' && !$jsonErrors) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    if ($jsonErrors) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
        exit(1);
    }

    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit(1);
}
