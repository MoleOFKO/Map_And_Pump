<?php
session_start();
define('MAP_PUMP_JSON_RESPONSE', true);
require __DIR__ . '/../conn.php';
require_once __DIR__ . '/../booking_reservations.php';
require_once __DIR__ . '/license_access.php';
header('Content-Type: application/json');
function partial_offer_reply(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') partial_offer_reply(405, 'Method not allowed.');
$userId = (int)($_SESSION['user_id'] ?? 0);
$bookingId = (int)($_POST['booking_id'] ?? 0);
$decision = (string)($_POST['decision'] ?? '');
$sessionToken = (string)($_SESSION['booking_csrf_token'] ?? '');
if ($userId <= 0) partial_offer_reply(401, 'Please sign in again.');
if (!customer_license_access($pdo, $userId)['is_approved']) partial_offer_reply(403, 'Your driver license must be confirmed before using booking features.');
if (!in_array($decision, ['accept', 'decline'], true) || $bookingId <= 0 || $sessionToken === ''
    || !hash_equals($sessionToken, (string)($_POST['csrf_token'] ?? ''))) partial_offer_reply(403, 'Refresh the page and try again.');
map_pump_booking_configure_timezone($pdo);
if (!map_pump_booking_schema_ready($pdo)) partial_offer_reply(503, 'Booking service is temporarily unavailable.');
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT b.status, b.expire_at, b.station_id, b.qr_refrence_code, r.requested_liters, o.offered_liters, o.status AS offer_status
        FROM booking b INNER JOIN booking_reservations r ON r.booking_id = b.booking_id
        INNER JOIN booking_partial_offers o ON o.booking_id = b.booking_id
        WHERE b.booking_id = ? AND b.user_id = ? AND b.expire_at > NOW() LIMIT 1 FOR UPDATE");
    $stmt->execute([$bookingId, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !in_array((string)$row['status'], ['notfilled','waiting','serving'], true)
        || $row['offer_status'] !== 'pending') {
        throw new RuntimeException('This partial-fill offer is no longer available.');
    }
    if ($decision === 'accept') {
        $save = $pdo->prepare("UPDATE booking_partial_offers SET status = 'accepted', answered_at = NOW() WHERE booking_id = ? AND status = 'pending'");
        $save->execute([$bookingId]);
    } else {
        $save = $pdo->prepare("UPDATE booking_partial_offers SET status = 'declined', answered_at = NOW() WHERE booking_id = ? AND status = 'pending'");
        $save->execute([$bookingId]);
        $close = $pdo->prepare("UPDATE booking SET status = 'station_unable_to_fulfill' WHERE booking_id = ? AND status IN ('notfilled','waiting','serving')");
        $close->execute([$bookingId]);
        $freePump = $pdo->prepare("UPDATE pumps SET status = 'idle', current_token = NULL WHERE branch_id = ? AND current_token = ? AND status = 'active'");
        $freePump->execute([(int)$row['station_id'], (string)$row['qr_refrence_code']]);
        map_pump_issue_shortfall_coupon($pdo, $bookingId, 0.0);
    }
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => $decision === 'accept' ? 'Partial amount accepted.' : 'Reservation closed and a station coupon was issued.']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    partial_offer_reply(409, $e->getMessage());
}
