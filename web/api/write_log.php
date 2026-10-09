<?php

declare(strict_types=1);

/**
 * Schrijflog van de aanroepende API-sleutel (alleen de eigen writes).
 * GET ?limit=&since=&before_id=&table=&company=
 */

require_once dirname(__DIR__) . '/mimir_write.php';

mimir_write_log_api_main();
