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

$ctx = current_context($conn);
$bid = (int)$ctx['branch']['branch_id'];

$body = json_decode(file_get_contents('php://input'), true) ?: $_POST;

try {
    if (!empty($body['all'])) {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE branch_id = :b AND is_read = 0");
        $stmt->execute([':b' => $bid]);
        json_out(['ok' => true, 'updated' => $stmt->rowCount()]);
    }
    $nid = (int)($body['notif_id'] ?? 0);
    if ($nid <= 0) json_out(['ok' => false, 'error' => 'notif_id required'], 400);

    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE notif_id = :n AND branch_id = :b");
    $stmt->execute([':n' => $nid, ':b' => $bid]);
    json_out(['ok' => true]);
} catch (PDOException $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()], 500);
}
