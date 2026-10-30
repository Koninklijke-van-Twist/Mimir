<?php

declare(strict_types=1);

/**
 * Gebeurtenislog van één dag (Europe/Amsterdam), alleen voor sleutels met "Mag eventlog lezen".
 * GET ?date=&status=&caller=&category=&limit=&offset=&summary=1
 */

require_once dirname(__DIR__) . '/mimir_events_api.php';

mimir_events_api_main();
