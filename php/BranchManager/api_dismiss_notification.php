<?php
/**
 * api_dismiss_notification.php  —  Mark a single notification (or all) as read.
 * POST JSON body: { "notif_id": <int> }   or   { "all": true }
 * Response: { ok: true, updated: <int>, unread: <int> }
 */
define('MAP_PUMP_JSON_RESPONSE', true);
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../conn.php';
require_branch_manager();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'POST only'], 405);

$ctx  = current_context($pdo);
$user = $ctx['user'];
if (!$user) json_out(['ok' => false, 'error' => 'No active session'], 401);
$uid = (int)$user['user_id'];

$body = json_decode(file_get_contents('php://input'), true) ?: $_POST;

try {
    // ---- Clear all ----
    if (!empty($body['all'])) {
        $updated = notif_mark_all_read($pdo, $uid);
        json_out(['ok' => true, 'updated' => $updated, 'unread' => notif_unread_count($pdo, $uid)]);
    }

    // ---- Dismiss one ----
    $nid = (int)($body['notif_id'] ?? 0);
    if ($nid <= 0) json_out(['ok' => false, 'error' => 'notif_id required'], 400);

    // Ownership check — a manager may only dismiss their own notifications.
    $stmt = $pdo->prepare(
        "SELECT notification_id FROM notifications
         WHERE notification_id = :n AND recipient_user_id = :u LIMIT 1"
    );
    $stmt->execute([':n' => $nid, ':u' => $uid]);
    if (!$stmt->fetchColumn()) {
        json_out(['ok' => false, 'error' => 'Notification not found'], 404);
    }

    $updated = notif_mark_read($pdo, $uid, $nid);
    json_out(['ok' => true, 'updated' => $updated, 'unread' => notif_unread_count($pdo, $uid)]);
} catch (PDOException $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()], 500);
}
