<?php

/**
 * Laadt auth.php zonder geheimen in de repository.
 *
 * Zelfde volgorde als Consus: MIMIR_AUTH_FILE, daarna ~/Repositories/auth.php
 * (gedeeld met de andere sleutels-apps), daarna web/auth.php op de server.
 * require binnen een functie erft de lokale scope; de global-regel houdt
 * $allowedUsers en $auth_list zichtbaar voor logincheck.php.
 */

function mimir_auth_candidates(): array
{
    $paths = [];
    $override = getenv('MIMIR_AUTH_FILE');
    if (is_string($override) && trim($override) !== '') {
        $paths[] = trim($override);
    }

    foreach (['HOME', 'USERPROFILE'] as $key) {
        $base = getenv($key);
        if (!is_string($base) || $base === '') {
            continue;
        }
        $paths[] = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'Repositories' . DIRECTORY_SEPARATOR . 'auth.php';
    }

    $paths[] = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'auth.php';
    $paths[] = __DIR__ . DIRECTORY_SEPARATOR . 'auth.php';

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
            return;
        }
    }

    $message = 'auth.php niet gevonden. Lokaal: ~/Repositories/auth.php naast de repo. Op de server: web/auth.php (niet in git).';
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
