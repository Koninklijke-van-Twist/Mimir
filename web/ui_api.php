<?php

declare(strict_types=1);

require_once __DIR__ . '/mimir_auth.php';
mimir_load_auth(true);
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/mimir_service.php';

mimir_ui_main();
