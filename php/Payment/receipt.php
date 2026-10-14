<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../payment_transfers.php';

$transferId = (int) ($_GET['id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);
$accessId = (int) ($_SESSION['payment_transfer_access_id'] ?? 0);
$accessValid = $accessId > 0 && $accessId === $transferId && (int) ($_SESSION['payment_transfer_access_expires'] ?? 0) >= time();
if ($transferId <= 0 || !payment_transfer_schema_ready($pdo)) {
    http_response_code(404);
    exit('Receipt not found.');
}

$stmt = $pdo->prepare('SELECT pt.*, u.email AS account_email FROM payment_transfers pt LEFT JOIN users u ON u.user_id = ? WHERE pt.payment_transfer_id = ? LIMIT 1');
$stmt->execute([$userId > 0 ? $userId : null, $transferId]);
$transfer = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$transfer) {
    http_response_code(404);
    exit('Receipt not found.');
}
$email = strtolower(trim((string) ($transfer['account_email'] ?? '')));
$isPayer = ($userId > 0 && (int) ($transfer['payer_user_id'] ?? 0) === $userId)
    || ($email !== '' && $email === strtolower((string) ($transfer['payer_email'] ?? '')))
    || $accessValid;
$isRecipient = $userId > 0 && ($transfer['recipient_role'] === 'admin'
    ? payment_transfer_user_is_admin($pdo, $userId)
    : ($transfer['recipient_role'] === 'station_hq' && payment_transfer_user_owns_hq($pdo, $userId, (int) $transfer['recipient_headquarter_id'])));
if ((!$isPayer && !$isRecipient) || ((empty($_SESSION['is_logged_in']) || $userId <= 0) && !$accessValid)) {
    http_response_code(403);
    exit('You are not authorized to view this receipt.');
}

$receiptStmt = $pdo->prepare('SELECT file_name, mime_type, file_size, file_data FROM payment_transfer_receipts WHERE payment_transfer_id = ? LIMIT 1');
$receiptStmt->execute([$transferId]);
$receipt = $receiptStmt->fetch(PDO::FETCH_ASSOC);
if (!$receipt) {
    http_response_code(404);
    exit('Receipt not found.');
}
$fileName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename((string) $receipt['file_name'])) ?: 'receipt';
header('Content-Type: ' . (string) $receipt['mime_type']);
header('Content-Length: ' . (int) $receipt['file_size']);
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('Referrer-Policy: no-referrer');
echo $receipt['file_data'];
