<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/account_deletion.php';

$deleted = map_pump_cleanup_due_account_deletions($pdo);
printf("Purged %d due HQ account(s).\n", $deleted);
