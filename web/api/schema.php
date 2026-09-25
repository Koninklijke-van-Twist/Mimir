<?php

declare(strict_types=1);

$GLOBALS['MIMIR_ROUTE'] = 'tables/' . rawurlencode(trim((string) ($_GET['table'] ?? ''))) . '/schema';
require __DIR__ . '/index.php';
