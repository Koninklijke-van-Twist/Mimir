<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/mimir_service.php';

mimir_api_main(isset($GLOBALS['MIMIR_ROUTE']) ? (string) $GLOBALS['MIMIR_ROUTE'] : null);
