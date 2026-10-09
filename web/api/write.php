<?php

declare(strict_types=1);

/**
 * Schrijven naar Business Central (POST insert, PATCH update, DELETE).
 * Alleen met een API-sleutel waarvoor "Mag schrijven naar BC" aanstaat.
 */

require_once dirname(__DIR__) . '/mimir_write.php';

mimir_write_api_main();
