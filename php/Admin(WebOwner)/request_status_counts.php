<?php
require_once __DIR__ . '/admin_common.php';
require_once __DIR__ . '/request_common.php';
require_once __DIR__ . '/user_account_common.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$user = admin_current_user(false);
$role = strtolower(trim((string) ($user['role_name'] ?? '')));
if (!$user || !in_array($role, ['admin', 'moderator'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Not authorized to view request totals.']);
    exit;
}

$summary = request_live_status_summary();
if ($summary['error'] !== null) {
    http_response_code(503);
    echo json_encode(['error' => $summary['error']]);
    exit;
}

echo json_encode([
    'pending' => $summary['pending'] + pending_account_count(),
    'approved' => $summary['approved'],
    'approved_today' => $summary['approved_today'],
    'declined' => $summary['declined_flagged'],
    'declined_flagged' => $summary['declined_flagged'],
]);
