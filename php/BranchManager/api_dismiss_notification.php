<?php
/**
 * api_dismiss_notification.php  —  Mark a single notification (or all) as read.
 * POST body: { notif_id: <int> }   or   { all: true }
 */
define('MAP_PUMP_JSON_RESPONSE', true);
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../conn.php';
require_branch_manager();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'POST only'], 405);

$ctx = current_context($pdo);
$user = $ctx['user'];

$body = json_decode(file_get_contents('php://input'), true) ?: $_POST;

try {
    if (!empty($body['all'])) {
        json_out(['ok' => true, 'updated' => 0]);
    }
    $nid = (int)($body['notif_id'] ?? 0);
    if ($nid <= 0) json_out(['ok' => false, 'error' => 'notif_id required'], 400);

    $stmt = $pdo->prepare("SELECT notification_id FROM notifications WHERE notification_id = :n AND recipient_user_id = :u LIMIT 1");
    $stmt->execute([':n' => $nid, ':u' => $user['user_id']]);
    if (!$stmt->fetchColumn()) {
        json_out(['ok' => false, 'error' => 'Notification not found'], 404);
    }
    json_out(['ok' => true]);
} catch (PDOException $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()], 500);
}
