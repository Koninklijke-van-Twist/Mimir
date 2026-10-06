<?php

declare(strict_types=1);

/**
 * Webservice-metadata (tabellen, sleutels, velden) als JSON.
 * Met een API-sleutel (Authorization: Bearer / X-API-Key), of zonder sleutel
 * via de ingelogde sessie van de pagina.
 */

require_once dirname(__DIR__) . '/mimir_service.php';

if (mimir_request_api_key() === '') {
    // Zelfde poort als ui_api.php; top-level zodat logincheck $allowedUsers ziet.
    mimir_load_auth(true);
    require_once dirname(__DIR__) . '/logincheck.php';
}

mimir_metadata_api_main();
