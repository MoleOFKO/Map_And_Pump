<?php
/**
 * booking-slot.php — Live queue + pump status
 * POST actions
 *   action=call_next  -> token_id, pump_id  (move waiting → serving)
 *   action=complete   -> token_id           (serving → completed, free pump)
 *   action=cancel     -> token_id           (any → cancelled)
 *   action=pump_state -> pump_id, state     (active|idle|maintenance|offline)
 */
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../conn.php';
require_branch_manager();
require_once __DIR__ . '/../usage_history_schema.php';
require_once __DIR__ . '/../booking_rules.php';
require_once __DIR__ . '/../booking_reservations.php';
require_once __DIR__ . '/../Customer/car_access.php';
require_once __DIR__ . '/../Customer/license_access.php';
require_once __DIR__ . '/../business_scope.php';
require_once __DIR__ . '/../loyalty_points.php';

function map_pump_table_exists(PDO $pdo, string $tableName): bool {
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
        );
        $stmt->execute([$tableName]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

$pumpsTableExists = map_pump_table_exists($pdo, 'pumps');

$ctx      = current_context($pdo);
$user     = $ctx['user'];
$branch   = $ctx['branch'];
$initials = initials($user['full_name']);
$bid      = (int)$branch['branch_id'];
map_pump_booking_configure_timezone($pdo);
customer_license_status_column_ready($pdo);

// Prepare loyalty tables before any fill transaction (MySQL DDL can commit).
map_pump_usage_history_schema_ready($pdo);
loyalty_schema_ready($pdo);
loyalty_countermeasures_schema_ready($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) json_out(['ok' => false, 'error' => 'CSRF failed'], 403);
    $action = $_POST['action'] ?? '';
    $pumpSpecificActions = ['call_next', 'unable_to_fulfill', 'complete', 'cancel', 'pump_state'];

    if (!$pumpsTableExists && in_array($action, $pumpSpecificActions, true)) {
        json_out(['ok' => false, 'error' => 'Pump tracking is unavailable for this station right now.'], 503);
    }

    if (in_array($action, ['call_next', 'complete'], true) && !hq_billing_station_is_paid($pdo, $bid)) {
        json_out(['ok' => false, 'error' => 'This station is paused until its HQ pays the monthly operating fee.'], 403);
    }

    if (!booking_unfulfillment_reason_schema_ready($pdo) && $action === 'unable_to_fulfill') {
        json_out(['ok' => false, 'error' => 'Unable to record the unfulfillment reason right now.'], 500);
    }

    try {
        if ($action === 'call_next') {
            $token_id = (int)($_POST['token_id'] ?? 0);
            $pump_id  = (int)($_POST['pump_id'] ?? 0);

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                SELECT bk.booking_id, bk.qr_refrence_code AS token_code
                FROM booking bk
                INNER JOIN station_business_scope sbs ON sbs.station_id = bk.station_id
                WHERE bk.booking_id = :t
                  AND bk.status IN ('notfilled', 'waiting')
                  AND bk.expire_at > NOW()
                  AND sbs.branch_manager_user_id = :manager_id
                LIMIT 1 FOR UPDATE
            ");
            $stmt->execute([':t' => $token_id, ':manager_id' => $user['user_id']]);
            $tok = $stmt->fetch();
            if (!$tok) { $pdo->rollBack(); json_out(['ok' => false, 'error' => 'Token not found']); }

            $stmt = $pdo->prepare("
                UPDATE booking SET status = 'serving'
                WHERE booking_id = :t AND status IN ('notfilled', 'waiting')
            ");
            $stmt->execute([':t' => $token_id]);
            if ($stmt->rowCount() !== 1) { $pdo->rollBack(); json_out(['ok' => false, 'error' => 'Booking state changed. Refresh the queue.'], 409); }

            $stmt = $pdo->prepare("
              UPDATE pumps SET status = 'active', current_token = :tc
              WHERE pump_id = :p AND branch_id = :b AND status = 'idle' AND current_token IS NULL
            ");
            $stmt->execute([':tc' => $tok['token_code'], ':p' => $pump_id, ':b' => $bid]);
            if ($stmt->rowCount() !== 1) {
              $pdo->rollBack();
              json_out(['ok' => false, 'error' => 'That pump is unavailable. Refresh and choose another pump.'], 409);
            }
            $pdo->commit();

            log_activity($pdo, $bid, 'user', $user['user_id'], $user['full_name'], "Called next token {$tok['token_code']} to pump #{$pump_id}");
            json_out(['ok' => true]);
        }

        if ($action === 'offer_partial') {
            $token_id = (int)($_POST['token_id'] ?? 0);
            $offered = round((float)($_POST['offered_liters'] ?? 0), 2);
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT bk.booking_id, bk.station_id, bk.status, bk.expire_at, br.requested_liters
                FROM booking bk INNER JOIN booking_reservations br ON br.booking_id = bk.booking_id
                INNER JOIN station_business_scope sbs ON sbs.station_id = bk.station_id
                WHERE bk.booking_id = ? AND sbs.branch_manager_user_id = ? LIMIT 1 FOR UPDATE");
            $stmt->execute([$token_id, $user['user_id']]);
            $bookingOffer = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$bookingOffer || !in_array((string)$bookingOffer['status'], ['notfilled','waiting','serving'], true)
                || strtotime((string)$bookingOffer['expire_at']) <= time() || $offered <= 0 || $offered >= (float)$bookingOffer['requested_liters']) {
                $pdo->rollBack(); json_out(['ok' => false, 'error' => 'Invalid partial-fill offer.'], 409);
            }
            $stmt = $pdo->prepare("INSERT INTO booking_partial_offers (booking_id, offered_liters, status, offered_by, offered_at, answered_at)
                VALUES (?, ?, 'pending', ?, NOW(), NULL)
                ON DUPLICATE KEY UPDATE offered_liters = VALUES(offered_liters), status = 'pending', offered_by = VALUES(offered_by), offered_at = NOW(), answered_at = NULL");
            $stmt->execute([$token_id, $offered, $user['user_id']]);
            $pdo->commit();
            json_out(['ok' => true]);
        }

        if ($action === 'unable_to_fulfill') {
            $token_id = (int)($_POST['token_id'] ?? 0);
            $reason = trim((string)($_POST['reason'] ?? ''));
            $reason_note = trim((string)($_POST['reason_note'] ?? ''));
            $allowedReasons = ['Insufficient Resources', 'Service Unavailable', 'Staff Unavailable', 'Other'];
            if ($token_id <= 0 || !in_array($reason, $allowedReasons, true)) {
                json_out(['ok' => false, 'error' => 'Please select a valid reason.'], 422);
            }
            if ($reason === 'Other' && $reason_note === '') {
                json_out(['ok' => false, 'error' => 'Please add a brief description for the selected reason.'], 422);
            }
            $details = $reason === 'Other' ? $reason_note : null;

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT bk.booking_id, bk.station_id, bk.status, bk.expire_at, p.pump_id, bk.qr_refrence_code
                FROM booking bk INNER JOIN station_business_scope sbs ON sbs.station_id = bk.station_id
                LEFT JOIN pumps p ON p.current_token = bk.qr_refrence_code AND p.branch_id = ?
                WHERE bk.booking_id = ? AND sbs.branch_manager_user_id = ? LIMIT 1 FOR UPDATE");
            $stmt->execute([$bid, $token_id, $user['user_id']]);
            $unable = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$unable || !in_array((string)$unable['status'], ['notfilled','waiting','serving'], true)
                || strtotime((string)$unable['expire_at']) <= time()) { $pdo->rollBack(); json_out(['ok' => false, 'error' => 'Booking is no longer active.'], 409); }

            $saveReason = $pdo->prepare(
                "INSERT INTO booking_unfulfillment_reason (booking_id, user_id, reason, details)
                 VALUES (:booking_id, :user_id, :reason, :details)
                 ON DUPLICATE KEY UPDATE reason = VALUES(reason), details = VALUES(details), user_id = VALUES(user_id), created_at = CURRENT_TIMESTAMP"
            );
            $saveReason->execute([
                ':booking_id' => (int)$token_id,
                ':user_id' => (int)$user['user_id'],
                ':reason' => $reason,
                ':details' => $details,
            ]);

            $cancelTable = booking_cancellation_table_name($pdo);
            try {
                $cancelInsert = $pdo->prepare(
                    "INSERT INTO {$cancelTable} (booking_id, user_id, reason_tags, details, created_at)
                     VALUES (:booking_id, :user_id, :reason_tags, :details, NOW())
                     ON DUPLICATE KEY UPDATE reason_tags = VALUES(reason_tags), details = VALUES(details), user_id = VALUES(user_id), created_at = CURRENT_TIMESTAMP"
                );
                $cancelInsert->execute([
                    ':booking_id' => (int)$token_id,
                    ':user_id' => (int)$user['user_id'],
                    ':reason_tags' => json_encode(['Unable To Fulfill'], JSON_UNESCAPED_UNICODE),
                    ':details' => $details,
                ]);
            } catch (Throwable $cancelInsertError) {
                error_log('Unable to record branch-manager cancellation reason: ' . $cancelInsertError->getMessage());
            }

            $stmt = $pdo->prepare("UPDATE booking SET status = 'station_unable_to_fulfill' WHERE booking_id = ? AND status IN ('notfilled','waiting','serving')");
            $stmt->execute([$token_id]);
            if ($stmt->rowCount() !== 1) { $pdo->rollBack(); json_out(['ok' => false, 'error' => 'Booking state changed while submitting the reason.'], 409); }
            map_pump_issue_shortfall_coupon($pdo, (int)$unable['booking_id'], 0.0);
            if (!empty($unable['pump_id'])) {
                $stmt = $pdo->prepare("UPDATE pumps SET status = 'idle', current_token = NULL WHERE pump_id = ?");
                $stmt->execute([(int)$unable['pump_id']]);
            }
            $pdo->commit();
            log_activity($pdo, $bid, 'user', $user['user_id'], $user['full_name'], "Marked token {$unable['qr_refrence_code']} as unable to fulfill: {$reason}");
            json_out(['ok' => true, 'message' => 'Closed and issued a full-value station coupon.']);
        }

        if ($action === 'cancel') {
            $token_id = (int)($_POST['token_id'] ?? 0);
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                SELECT bk.booking_id, p.pump_id, bk.qr_refrence_code AS token_code
                FROM booking bk
                INNER JOIN station_business_scope sbs ON sbs.station_id = bk.station_id
                LEFT JOIN pumps p ON p.current_token = bk.qr_refrence_code AND p.branch_id = :branch_id
                WHERE bk.booking_id = :t
                  AND bk.status IN ('notfilled', 'waiting', 'serving')
                  AND bk.expire_at > NOW()
                  AND sbs.branch_manager_user_id = :manager_id
                LIMIT 1 FOR UPDATE
            ");
            $stmt->execute([':t' => $token_id, ':manager_id' => $user['user_id'], ':branch_id' => $bid]);
            $tok = $stmt->fetch();
            if (!$tok) { $pdo->rollBack(); json_out(['ok' => false, 'error' => 'Token not found']); }

            $stmt = $pdo->prepare("UPDATE booking SET status = 'cancelled' WHERE booking_id = :t AND status IN ('notfilled', 'waiting', 'serving') AND expire_at > NOW()");
            $stmt->execute([':t' => $token_id]);
            if ($stmt->rowCount() !== 1) { $pdo->rollBack(); json_out(['ok' => false, 'error' => 'Booking state changed. Refresh the queue.'], 409); }
            if ($tok['pump_id']) {
                $stmt = $pdo->prepare("UPDATE pumps SET status = 'idle', current_token = NULL WHERE pump_id = :p");
                $stmt->execute([':p' => $tok['pump_id']]);
            }
            $pdo->commit();
            log_activity($pdo, $bid, 'user', $user['user_id'], $user['full_name'], "Cancelled token {$tok['token_code']}");
            json_out(['ok' => true]);
        }

        if ($action === 'complete') {
            $token_id = (int)($_POST['token_id'] ?? 0);
            $filled_liters = round(max(0, (float)($_POST['filled_liters'] ?? 0)), 2);

            if ($filled_liters <= 0) {
                json_out(['ok' => false, 'error' => 'Enter the actual liters dispensed before completing this fill.'], 422);
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                SELECT bk.booking_id, bk.user_id, bk.car_license_id, bk.station_id, bk.oil_type_id,
                       br.requested_liters, br.price_per_litre AS booking_price,
                       bk.qr_code, bk.qr_refrence_code AS token_code,
                       bk.created_at AS booked_at, bk.expire_at, p.pump_id,
                       COALESCE(bk.points_to_redeem, 0) AS points_to_redeem
                FROM booking bk
                INNER JOIN booking_reservations br ON br.booking_id = bk.booking_id
                INNER JOIN station_business_scope sbs ON sbs.station_id = bk.station_id
                LEFT JOIN pumps p ON p.current_token = bk.qr_refrence_code AND p.branch_id = :branch_id
                WHERE bk.booking_id = :t
                  AND bk.status = 'serving'
                  AND sbs.branch_manager_user_id = :manager_id
                  AND bk.expire_at > NOW()
                LIMIT 1 FOR UPDATE
            ");
            $stmt->execute([':t' => $token_id, ':manager_id' => $user['user_id'], ':branch_id' => $bid]);
            $tok = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$tok) {
                $pdo->rollBack();
                json_out(['ok' => false, 'error' => 'Token not found']);
            }

            if ($filled_liters > (float)$tok['requested_liters']) {
                $pdo->rollBack();
                json_out(['ok' => false, 'error' => 'The fill cannot exceed the reserved quantity.'], 422);
            }

            if ($filled_liters + 0.0001 < (float)$tok['requested_liters']) {
                $offerCheck = $pdo->prepare("SELECT offered_liters FROM booking_partial_offers WHERE booking_id = ? AND status = 'accepted' LIMIT 1");
                $offerCheck->execute([(int)$tok['booking_id']]);
                $acceptedAmount = $offerCheck->fetchColumn();
                if ($acceptedAmount === false || abs((float)$acceptedAmount - $filled_liters) > 0.01) {
                    $pdo->rollBack();
                    json_out(['ok' => false, 'error' => 'The driver must accept this exact partial amount in their booking page.'], 409);
                }
            }

            $userLock = $pdo->prepare('SELECT user_id FROM users WHERE user_id = ? LIMIT 1 FOR UPDATE');
            $userLock->execute([(int)$tok['user_id']]);
            if (!$userLock->fetchColumn()) {
                $pdo->rollBack();
                json_out(['ok' => false, 'error' => 'Customer account is unavailable.'], 409);
            }

            if (!customer_license_valid_for_booking($pdo, (int)$tok['user_id'], true)) {
                $pdo->rollBack();
                json_out(['ok' => false, 'error' => 'Driver license is expired or no longer approved.'], 403);
            }

            $carLink = $pdo->prepare('SELECT 1 FROM user_car WHERE user_id = ? AND car_id = ? LIMIT 1 FOR UPDATE');
            $carLink->execute([(int)$tok['user_id'], (int)$tok['car_license_id']]);
            if (!$carLink->fetchColumn()) {
                $pdo->rollBack();
                json_out(['ok' => false, 'error' => 'Vehicle is not linked to this customer.'], 403);
            }

            $carStatus = $pdo->prepare('SELECT status, license_expire_date FROM car_license WHERE car_license_id = ? LIMIT 1 FOR UPDATE');
            $carStatus->execute([(int)$tok['car_license_id']]);
            $carData = $carStatus->fetch(PDO::FETCH_ASSOC);
            if (!$carData || strtolower((string)$carData['status']) === 'expired' || (!empty($carData['license_expire_date']) && $carData['license_expire_date'] < date('Y-m-d'))) {
                $pdo->rollBack();
                json_out(['ok' => false, 'error' => 'Vehicle registration is expired or invalid.'], 403);
            }

            $quota = customer_fuel_quota_for_car($pdo, (int)$tok['user_id'], (int)$tok['car_license_id']);
            if ($filled_liters > (float)$quota['remaining']) {
                $pdo->rollBack();
                json_out(['ok' => false, 'error' => 'This fill exceeds the customer weekly quota.'], 422);
            }

            $price = (float)$tok['booking_price'];
            $total = round($price * $filled_liters, 2);

            $stmt = $pdo->prepare("UPDATE booking SET status = 'completed', filled_gas = :fg, time_of_filled = NOW() WHERE booking_id = :t AND status = 'serving'");
            $stmt->execute([':fg' => $filled_liters, ':t' => $token_id]);

            if ($tok['pump_id']) {
                $stmt = $pdo->prepare("UPDATE pumps SET status = 'idle', current_token = NULL WHERE pump_id = :p");
                $stmt->execute([':p' => $tok['pump_id']]);
            }

            $stmt = $pdo->prepare(
                "INSERT INTO usage_history
                    (booking_id, user_id, car_license_id, station_id, oil_type_id,
                     qr_code, qr_refrence_code, filled_liters, price_per_litre, total_cost,
                     status, booked_at, expire_at, filled_at, staff_user_id)
                VALUES
                    (:bid, :uid, :clid, :sid, :oid,
                     :qr, :qref, :liters, :price, :total,
                     'filled', :booked_at, :expire_at, NOW(), :staff)"
            );
            $stmt->execute([
                ':bid'       => $tok['booking_id'],
                ':uid'       => $tok['user_id'],
                ':clid'      => $tok['car_license_id'],
                ':sid'       => $tok['station_id'],
                ':oid'       => $tok['oil_type_id'],
                ':qr'        => $tok['qr_code'],
                ':qref'      => $tok['token_code'],
                ':liters'    => $filled_liters,
                ':price'     => $price,
                ':total'     => $total,
                ':booked_at' => $tok['booked_at'],
                ':expire_at' => $tok['expire_at'],
                ':staff'     => $user['user_id'],
            ]);
            $usageId = (int) $pdo->lastInsertId();

            $isInternal = loyalty_is_internal_fill($pdo, (int)$tok['user_id'], (int)$tok['station_id']);
            $loyaltyResult = loyalty_apply_on_fill(
                $pdo,
                (int)$tok['user_id'],
                $filled_liters,
                $total,
                (int)$tok['points_to_redeem'],
                (int)$tok['booking_id'],
                (int)$user['user_id'],
                $isInternal,
                (int)$tok['station_id'],
                $usageId > 0 ? $usageId : null
            );

            $discount = (float)($loyaltyResult['discount_mmk'] ?? 0);
            $netTotal = max(0.0, round($total - $discount, 2));
            $couponRedemption = map_pump_redeem_company_coupon($pdo, (int)$tok['user_id'], (int)$tok['station_id'], $usageId, $netTotal);
            $netTotal = max(0.0, round($netTotal - (float)($couponRedemption['discount_mmk'] ?? 0), 2));

            $loyaltyUpdate = $pdo->prepare(
                'UPDATE usage_history
                 SET total_cost = :net_total,
                     points_earned = :earned,
                     points_redeemed = :redeemed,
                     discount_mmk = :discount,
                     is_internal = :is_internal,
                     admin_share_mmk = :admin_share,
                     company_share_mmk = :company_share
                 WHERE usage_id = :usage_id'
            );
            $loyaltyUpdate->execute([
                ':net_total' => $netTotal,
                ':earned' => (int)($loyaltyResult['points_earned'] ?? 0),
                ':redeemed' => (int)($loyaltyResult['points_redeemed'] ?? 0),
                ':discount' => $discount,
                ':is_internal' => !empty($loyaltyResult['is_internal']) ? 1 : 0,
                ':admin_share' => (float)($loyaltyResult['admin_share_mmk'] ?? 0),
                ':company_share' => (float)($loyaltyResult['company_share_mmk'] ?? 0),
                ':usage_id' => $usageId,
            ]);

            $oilStmt = $pdo->prepare('SELECT oil_name FROM oil_types WHERE oil_type_id = ?');
            $oilStmt->execute([(int)$tok['oil_type_id']]);
            $oilName = strtolower(preg_replace('/\s+/', '', (string)$oilStmt->fetchColumn()));
            $fuelCode = match ($oilName) {
                'octane92' => 'p92',
                'octane95' => 'p95',
                'diesel', 'disel' => 'dsl',
                'premiumdiesel', 'premiumdisel' => 'pdsl',
                default => ''
            };
            if ($fuelCode === '') {
                throw new RuntimeException('Unknown booked fuel tank.');
            }

            $deduct = $pdo->prepare('UPDATE tanks SET current_l = current_l - ? WHERE branch_id = ? AND fuel_code = ? AND is_enabled = 1 AND current_l >= ?');
            $deduct->execute([$filled_liters, (int)$tok['station_id'], $fuelCode, $filled_liters]);
            if ($deduct->rowCount() !== 1) {
                throw new RuntimeException('Insufficient live tank stock.');
            }

            $tankNow = $pdo->prepare('SELECT current_l FROM tanks WHERE branch_id = ? AND fuel_code = ? LIMIT 1');
            $tankNow->execute([(int)$tok['station_id'], $fuelCode]);
            map_pump_append_station_inventory_snapshot($pdo, (int)$tok['station_id'], (int)$tok['oil_type_id'], (float)$tankNow->fetchColumn());

            if ($filled_liters + 0.0001 < (float)$tok['requested_liters']) {
                map_pump_issue_shortfall_coupon($pdo, (int)$tok['booking_id'], $filled_liters);
            }

            $pdo->commit();
            log_activity($pdo, $bid, 'user', $user['user_id'], $user['full_name'], "Completed token {$tok['token_code']}");
            json_out([
                'ok' => true,
                'filled_liters' => $filled_liters,
                'total_cost' => $netTotal,
                'discount_mmk' => $discount,
                'station_coupon_discount_mmk' => (float)($couponRedemption['discount_mmk'] ?? 0),
                'points_earned' => (int)($loyaltyResult['points_earned'] ?? 0),
                'exp_earned' => (int)($loyaltyResult['exp_earned'] ?? 0),
                'points_redeemed' => (int)($loyaltyResult['points_redeemed'] ?? 0),
            ]);
        }

        if ($action === 'pump_state') {
            $pump_id = (int)($_POST['pump_id'] ?? 0);
            $state = $_POST['state'] ?? 'idle';
            if (!in_array($state, ['active','idle','maintenance','offline'], true)) $state = 'idle';
            $stmt = $pdo->prepare("UPDATE pumps SET status = :s WHERE pump_id = :p AND branch_id = :b");
            $stmt->execute([':s' => $state, ':p' => $pump_id, ':b' => $bid]);
            log_activity($pdo, $bid, 'user', $user['user_id'], $user['full_name'], "Set pump #{$pump_id} to {$state}");
            json_out(['ok' => true]);
        }

        json_out(['ok' => false, 'error' => 'Unknown action'], 400);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $message = 'Could not complete this action. Please try again.';
        json_out(['ok' => false, 'error' => $message], 500);
    }
}

// -------- Load data --------
if ($pumpsTableExists) {
    $stmt = $pdo->prepare("SELECT * FROM pumps WHERE branch_id = :b ORDER BY pump_no ASC");
    $stmt->execute([':b' => $bid]);
    $pumps = $stmt->fetchAll();
} else {
    $pumps = [];
}

// KPI stats
$summary = booking_manager_summary_counts($pdo, (int)$user['user_id']);
$waiting_count = (int)$summary['token_issue'];
$completed_count = (int)$summary['token_completed'];
$customer_cancel_count = (int)$summary['cancel_by_customer'];
$branch_manager_cancel_count = (int)$summary['cancel_by_manager'];

$stmt = $pdo->prepare("
    SELECT AVG(TIMESTAMPDIFF(MINUTE, booked_at, filled_at))
    FROM usage_history uh
    INNER JOIN station_business_scope sbs ON sbs.station_id = uh.station_id
    WHERE sbs.branch_manager_user_id = :manager_id
      AND DATE(uh.filled_at) = CURDATE()
");
$stmt->execute([':manager_id' => $user['user_id']]);
$avg_wait = round((float)$stmt->fetchColumn());

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM booking bk
    INNER JOIN station_business_scope sbs ON sbs.station_id = bk.station_id
    WHERE sbs.branch_manager_user_id = :manager_id
      AND DATE(bk.created_at) = CURDATE()
");
$stmt->execute([':manager_id' => $user['user_id']]);
$issued_today = (int)$stmt->fetchColumn();

if ($pumpsTableExists) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM pumps WHERE branch_id = :b AND status = 'maintenance'");
    $stmt->execute([':b' => $bid]);
    $pump_maint = (int)$stmt->fetchColumn();
} else {
    $pump_maint = 0;
}
$pump_total = count($pumps);

// Live queue used by the queue table.
$queue = [];
try {
    $queue = booking_manager_live_queue($pdo, (int)$user['user_id']);
} catch (Throwable $e) {
    $queue = [];
}

// Notifications used by the existing topbar template.
$alerts = [];
$has_unread = false;
if (function_exists('notif_recent') && function_exists('notif_unread_count')) {
    try {
        $alerts = notif_recent($pdo, (int)$user['user_id'], 5);
        $unread_count = notif_unread_count($pdo, (int)$user['user_id']);
        $has_unread = $unread_count > 0;
    } catch (Throwable $e) {
        $alerts = [];
        $has_unread = false;
    }
}

// Booking history for the History tab.
$history = [];
$branchCreatedDate = '';
$historyStart = '';
$historyEnd = '';
$todayDate = (new DateTimeImmutable('now'))->format('Y-m-d');

$branchCreatedStmt = $pdo->prepare('SELECT DATE(created_at) AS created_date FROM branches WHERE branch_id = :branch_id LIMIT 1');
$branchCreatedStmt->execute([':branch_id' => $bid]);
$rawBranchCreated = $branchCreatedStmt->fetchColumn();
if (is_string($rawBranchCreated) && trim($rawBranchCreated) !== '') {
    $branchCreatedDate = date('Y-m-d', strtotime($rawBranchCreated));
}

$validHistoryDate = static function (string $date): bool {
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return $dt && $dt->format('Y-m-d') === $date;
};

$historyStart = trim((string) ($_GET['history_from'] ?? ''));
$historyEnd = trim((string) ($_GET['history_to'] ?? ''));
if (!$validHistoryDate($historyStart)) {
    $historyStart = $branchCreatedDate !== '' ? $branchCreatedDate : '';
}
if (!$validHistoryDate($historyEnd)) {
    $historyEnd = $todayDate;
}
if ($branchCreatedDate !== '' && $historyStart < $branchCreatedDate) {
    $historyStart = $branchCreatedDate;
}
if ($historyEnd > $todayDate) {
    $historyEnd = $todayDate;
}
if ($historyStart > $historyEnd) {
    $historyStart = $branchCreatedDate !== '' ? $branchCreatedDate : $historyEnd;
}

try {
    $historySql = "
        SELECT l.log_id, l.user_id, l.action, l.created_at
        FROM log l
        WHERE l.user_id = :manager_id
          AND (
                l.action LIKE 'Completed token %'
             OR l.action LIKE 'Cancelled token %'
             OR l.action LIKE 'Marked token % as unable to fulfill:%'
          )
    ";
    $historyParams = [':manager_id' => (int)$user['user_id']];
    if ($historyStart !== '') {
        $historySql .= ' AND DATE(l.created_at) >= :start_date';
        $historyParams[':start_date'] = $historyStart;
    }
    if ($historyEnd !== '') {
        $historySql .= ' AND DATE(l.created_at) <= :end_date';
        $historyParams[':end_date'] = $historyEnd;
    }
    $historySql .= ' ORDER BY l.created_at DESC, l.log_id DESC';

    $stmt = $pdo->prepare($historySql);
    $stmt->execute($historyParams);
    $rawHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $history = [];
    foreach ($rawHistory as $row) {
        $action = (string)($row['action'] ?? '');
        $status = 'cancelled';
        if (stripos($action, 'Completed token ') === 0) {
            $status = 'completed';
        } elseif (stripos($action, 'Marked token ') === 0 && stripos($action, 'unable to fulfill') !== false) {
            $status = 'station_unable_to_fulfill';
        }

        $token = '';
        if (preg_match('/Token\s+([^\s]+)\b/i', $action, $m)) {
            $token = $m[1];
        } elseif (preg_match('/token\s+([^\s]+)\b/i', $action, $m)) {
            $token = $m[1];
        }

        $history[] = [
            'status' => $status,
            'logged_at' => $row['created_at'],
            'token_code' => $token,
            'customer_name' => 'Customer',
            'fuel_name' => '',
            'filled_gas' => null,
        ];
    }
} catch (Throwable $e) {
    $history = [];
    error_log('Unable to load booking history log: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MAP & PUMP — Monitor Booking Slot</title>

<!-- Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Noto+Sans+Myanmar:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="icon" type="image/png" href="/images/Logo.png">
<!-- Bootstrap 5.3.3 CSS -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<!-- Bootstrap Icons -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

<!-- Font Awesome 6.5.1 CSS -->
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">

<style>
  :root{
    --navy-950:#070b14; --navy-900:#0b1220; --navy-800:#101a30; --navy-700:#16213f;
    --blue-700:#1d4ed8; --blue-500:#2f6feb; --sky-400:#38bdf8;
    --amber-400:#fbbf24; --amber-300:#fcd34d; --mint-400:#34d399;
    --red-400:#f87171;
    --slate-50:#f8fafc; --slate-300:#c9d2e3; --slate-400:#8b96ad;
  }
  
  *{box-sizing:border-box; margin:0; padding:0;}
  body{background:var(--navy-950); color:var(--slate-50); font-family:'Inter','Space Grotesk',sans-serif; overflow-x:hidden;}
  .disp{font-family:'Space Grotesk','Noto Sans Myanmar',sans-serif;}
  a{color:inherit; text-decoration:none;}
  button{font-family:inherit;}
  @media (prefers-reduced-motion: reduce){ *{animation-duration:0.001ms !important; animation-iteration-count:1 !important; transition: none !important;} }
  @keyframes pulse{0%,100%{opacity:1;} 50%{opacity:0.35;}}

  .app-shell{display:flex; min-height:100vh;}
  
  /* Sidebar Custom Styling */
  .sidebar{
    width:252px; 
    flex-shrink:0; 
    background:var(--navy-900); 
    border-right:1px solid rgba(255,255,255,0.06); 
    display:flex; 
    flex-direction:column; 
    padding:22px 16px; 
    position:sticky; 
    top:0; 
    height:100vh; 
    z-index:60;
  }
  
  .nav-logo {
    width: 32px;        
    height: auto;
    object-fit: contain;
  }
  .side-brand{display:flex; align-items:center; gap:10px; font-weight:700; font-size:17px; padding:6px 8px 0;}
  .side-brand svg{width:28px; height:28px; transition: transform 0.2s ease;}
  .side-brand:hover svg{transform: rotate(-10deg) scale(1.1);}
  .side-role{font-size:11px; color:var(--slate-400); font-weight:500; margin:2px 0 0 46px;}

  .side-branch{
    margin:20px 8px 0; padding:10px 12px; background:var(--navy-800); border-radius:12px;
    border:1px solid rgba(255,255,255,0.06); font-size:12.5px;
    transition: background 0.3s, border-color 0.3s;
    display:flex; align-items:center; gap:10px;
  }
  .side-branch:hover{
    background: var(--navy-700);
    border-color: rgba(56,189,248,0.2);
  }
  .side-branch .hq-logo{
    width:36px; height:36px; border-radius:8px; object-fit:cover; flex-shrink:0;
    background:var(--navy-950); border:1px solid rgba(255,255,255,0.08);
  }
  .side-branch .txt{min-width:0; flex:1;}
  .side-branch b{display:block; font-size:14px; font-family:'Space Grotesk'; margin-bottom:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;}
  .side-branch span{color:var(--slate-400);}

  .side-nav{margin-top:22px; display:flex; flex-direction:column; gap:4px; flex:1;}
  .side-link{
    display:flex; align-items:center; gap:12px; padding:11px 12px; border-radius:10px; font-size:14px;
    color:var(--slate-300); font-weight:500;
    transition: background 0.25s ease, color 0.25s ease, transform 0.2s ease;
  }
  .side-link:hover{
    background:rgba(255,255,255,0.045); color:var(--slate-50);
    transform: translateX(4px);
  }
  .side-link.active{
    background:linear-gradient(135deg, rgba(56,189,248,0.16), rgba(29,78,216,0.12));
    color:var(--slate-50);
    box-shadow:inset 0 0 0 1px rgba(56,189,248,0.32);
  }
  .side-link.active:hover{transform: none;}
  .side-link .ic{font-size:16px; width:18px; text-align:center; transition: transform 0.2s ease;}
  .side-link:hover .ic{transform: scale(1.15);}
  .side-logout{
    display:flex; align-items:center; gap:12px; padding:12px; border-radius:10px; font-size:14px;
    color:var(--red-400); font-weight:600; margin-top:14px; border-top:1px solid rgba(255,255,255,0.06);
    padding-top:18px;
    transition: background 0.25s, color 0.25s, padding-left 0.2s;
  }
  .side-logout:hover{background:rgba(248,113,113,0.08); padding-left: 16px;}

  .main{flex:1; min-width:0;}
  
  .topbar{
    position:sticky; 
    top:0; 
    z-index:50; 
    display:flex; 
    align-items:center; 
    justify-content:space-between; 
    padding:16px 32px; 
    background:rgba(7,11,20,0.72); 
    backdrop-filter:blur(14px); 
    border-bottom:1px solid rgba(255,255,255,0.06);
  }
  .topbar h1{font-size:18px; font-weight:700; display:flex; align-items:center; gap:10px;}

  .live-tag{
    display:inline-flex; align-items:center; gap:6px; font-size:11px; color:var(--mint-400);
    background:rgba(52,211,153,0.12); padding:4px 10px; border-radius: 999px; font-weight:700;
  }
  .live-tag .dot{width:6px; height:6px; border-radius:50%; background:var(--mint-400); animation:pulse 1.6s infinite;}
  .topbar .date{font-size:12.5px; color:var(--slate-400); margin-top:2px; font-variant-numeric: tabular-nums;}
  .topbar-right{display:flex; align-items:center; gap:16px; position: relative;}

  .bell{
    width:36px; height:36px; border-radius:50%; background:var(--navy-800); display:flex; align-items:center;
    justify-content:center; border:1px solid rgba(255,255,255,0.08); position:relative; font-size:15px; color:var(--slate-300);
    cursor:pointer; transition: transform 0.2s, background-color 0.2s;
  }
  .bell:hover{
    background: var(--navy-700);
    transform: scale(1.05);
  }
  .bell .ping{position:absolute; top:6px; right:7px; width:7px; height:7px; border-radius:50%; background:var(--amber-400); box-shadow:0 0 0 2px var(--navy-800); transition: transform 0.2s;}

  /* ---------- Notifications Dropdown Popup ---------- */
  .notif-popup {
    position: absolute;
    top: 48px;
    right: 60px;
    width: 320px;
    max-height: 420px;
    overflow-y: auto;
    background: var(--navy-800);
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 14px;
    box-shadow: 0 16px 32px rgba(0, 0, 0, 0.4);
    padding: 14px 12px;
    z-index: 100;
    display: none;
    animation: fadeInDown 0.25s cubic-bezier(0.16, 1, 0.3, 1);
  }
  .notif-popup.show { display: block; }
  .notif-header { display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid rgba(255, 255, 255, 0.08); padding-bottom: 10px; margin-bottom: 10px; }
  .notif-header h4 { font-size: 14px; font-weight: 700; margin: 0; }
  .notif-header button { background: transparent; border: none; color: var(--sky-400); font-size: 11px; cursor: pointer; }
  .notif-item {
    padding: 9px 8px; border-radius: 10px; display: flex; gap: 10px; align-items: flex-start; transition: background-color 0.2s; border: 1px solid transparent;
  }
  .notif-item:hover { background-color: rgba(255,255,255,0.04); }
  .notif-item .ic {
    width: 32px; height: 32px; border-radius: 10px; display:flex; align-items:center; justify-content:center; font-size:12px; flex-shrink:0;
    background: rgba(148,163,184,0.12); color: var(--slate-200);
  }
  .notif-item[data-kind="warn"] .ic, .notif-item.warn .ic { background: rgba(251,191,36,0.16); color: var(--amber-300); }
  .notif-item[data-kind="err"] .ic, .notif-item.err .ic { background: rgba(248,113,113,0.16); color: var(--red-400); }
  .notif-item[data-kind="ok"] .ic, .notif-item.ok .ic { background: rgba(52,211,153,0.14); color: var(--mint-400); }
  .notif-item[data-kind="info"] .ic, .notif-item.info .ic { background: rgba(56,189,248,0.14); color: var(--sky-400); }
  .notif-item-body b { display:block; font-size:12.5px; color:var(--slate-50); margin-bottom:2px; }
  .notif-item-body span { color:var(--slate-400); font-size:11.5px; display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .notif-time { font-size:10px; color:var(--slate-400); margin-top:4px; }
  @keyframes fadeInDown { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }

  .avatar-chip{display:flex; align-items:center; gap:10px; font-size:13px; cursor: pointer; padding: 4px 8px; border-radius: 99px; transition: background 0.2s;}
  .avatar-chip:hover{background: rgba(255,255,255,0.05);}
  .avatar-circle{
    width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,var(--sky-400),var(--blue-700));
    display:flex; align-items:center; justify-content:center; font-weight:700; font-size:13px; font-family:'Space Grotesk';
  }

  /* Content Entrance Fade */
  .content{
    padding:30px 32px 60px;
    opacity: 0;
    transform: translateY(10px);
    animation: layoutFadeIn 0.5s ease forwards;
  }

  .page-foot{
    text-align:center; margin-top:34px; padding-top:18px;
    border-top:1px solid rgba(255,255,255,0.06);
    font-size:12px; color:var(--slate-400);
  }
  @keyframes layoutFadeIn {
    to { opacity: 1; transform: translateY(0); }
  }
  .page-head p{font-size:13px; color:var(--slate-400); margin-bottom:24px;}

  /* Cards and Panels with Custom Gradients */
  .stat-card{
    background:linear-gradient(160deg, var(--navy-800), var(--navy-900)); 
    border:1px solid rgba(255,255,255,0.07); 
    border-radius:14px; 
    padding:16px 18px;
    transition: transform 0.3s ease, border-color 0.3s ease;
  }
  .stat-card:hover {
    transform: translateY(-2px);
    border-color: rgba(56,189,248,0.25);
  }
  .stat-card .n{font-family:'Space Grotesk'; font-size:22px; font-weight:700; transition: color 0.3s;}
  .stat-card .l{font-size:11.5px; color:var(--slate-400); margin-top:4px;}

  .section-title{font-size:15.5px; font-weight:700; margin-bottom:16px;}

  .pump-card{
    background:linear-gradient(160deg, var(--navy-800), var(--navy-900)); 
    border:1px solid rgba(255,255,255,0.07); 
    border-radius:16px; 
    padding:18px; 
    text-align:center;
    transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
  }
  .pump-card:hover:not(.maint) {
    transform: translateY(-4px);
    border-color: rgba(251,191,36,0.2);
    box-shadow: 0 10px 25px -10px rgba(0,0,0,0.5);
  }
  .pump-card .pnum{font-family:'Space Grotesk'; font-size:14px; color:var(--slate-400); margin-bottom:10px;}
  
  .pump-card .tok{
    font-family:'Space Grotesk'; font-size:28px; font-weight:700; color:var(--amber-300); margin-bottom:8px;
    transition: color 0.3s ease, font-size 0.3s ease;
  }
  .pump-card.idle .tok{color:var(--slate-400); font-size:16px;}
  .pump-card.maint .tok{color:var(--red-400); font-size:16px;}
  
  .pstat{font-size:10.5px; font-weight:700; padding:4px 10px; border-radius:999px; display:inline-flex; align-items:center; gap:6px; transition: all 0.3s;}
  .pstat.active{background:rgba(52,211,153,0.14); color:var(--mint-400);}
  .pstat.idle{background:rgba(139,150,173,0.14); color:var(--slate-400);}
  .pstat.maint{background:rgba(248,113,113,0.14); color:var(--red-400);}
  .pstat .d{width:6px; height:6px; border-radius:50%; background:currentColor;}

  .panel{
    background:linear-gradient(160deg, var(--navy-800), var(--navy-900)); 
    border:1px solid rgba(255,255,255,0.07); 
    border-radius:18px; 
    overflow:hidden;
  }
  
  /* Table Styling */
  .table-custom {
    margin-bottom: 0;
    border-collapse: separate;
    border-spacing: 0 8px;
  }
  .table-custom thead th {
    background: transparent;
    color: var(--slate-400);
    font-size: 11.5px;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    padding: 16px 18px;
    border-bottom: 1px solid rgba(255,255,255,0.07);
    font-weight: 600;
    text-align: center;
    vertical-align: middle;
  }
  .table-custom tbody tr {
    transition: opacity 0.3s ease, transform 0.3s ease;
  }
  .table-custom tbody td {
    background: transparent;
    color: var(--slate-50);
    padding: 16px 18px;
    font-size: 13.5px;
    border-bottom: 1px solid rgba(255,255,255,0.05);
    vertical-align: middle;
    transition: all 0.2s;
    text-align: center;
  }
  .table-custom tbody tr:last-child td {
    border-bottom: none;
  }
  .table-custom tbody tr:hover td {
    background: rgba(255,255,255,0.015);
  }
  #queue-table {
    table-layout: fixed;
    width: 100%;
  }
  #queue-table th,
  #queue-table td {
    width: 20%;
  }
  #queue-scroll { overflow-y: hidden; scrollbar-width: thin; scrollbar-color: rgba(148, 163, 184, 0.45) transparent; }
  #queue-scroll.has-scroll { overflow-y: auto; }
  #queue-scroll::-webkit-scrollbar { width: 8px; height: 8px; }
  #queue-scroll::-webkit-scrollbar-track { background: transparent; }
  #queue-scroll::-webkit-scrollbar-thumb {
    background: rgba(148, 163, 184, 0.38); border-radius: 999px; border: 2px solid transparent; background-clip: content-box;
  }
  #queue-scroll::-webkit-scrollbar-thumb:hover { background: rgba(148, 163, 184, 0.62); }
  #queue-scroll.has-scroll #queue-table thead th { position: sticky; top: 0; z-index: 2; background: #0f1a30; }
  .status-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    line-height: 1.4;
    white-space: nowrap;
    background: rgba(139,150,173,0.14);
    color: var(--slate-400);
  }
  .status-pill .d { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
  .status-pill.st-complete { color: #34d399; background: rgba(52,211,153,0.14); }
  .status-pill.st-waiting { color: #60a5fa; background: rgba(96,165,250,0.14); }
  .status-pill.st-unable { color: #fb923c; background: rgba(251,146,60,0.14); }
  .status-pill.st-canceled { color: #f87171; background: rgba(248,113,113,0.14); }
  .status-pill.st-neutral { color: var(--slate-400); background: rgba(139,150,173,0.14); }
  .act-link.unable-btn { color: #ef4444; gap: 6px; padding: 4px 12px; border: 1px solid #ef4444; border-radius: 999px; background: transparent; }
  .act-link.unable-btn:hover { color: #fff; background: #ef4444; }
  .ufm-backdrop {
    position: fixed; inset: 0; background: rgba(3,6,12,0.7);
    display: none; align-items: center; justify-content: center;
    z-index: 1100; padding: 20px;
  }
  .ufm-backdrop.show { display: flex; }
  .ufm-dialog {
    width: 100%; max-width: 440px;
    background: linear-gradient(160deg, var(--navy-800), var(--navy-900));
    border: 1px solid rgba(255,255,255,0.1); border-radius: 16px;
    box-shadow: 0 20px 40px rgba(0,0,0,0.5); overflow: visible;
  }
  .ufm-head {
    display: flex; justify-content: space-between; align-items: center;
    padding: 16px 20px; border-bottom: 1px solid rgba(255,255,255,0.07);
    font-weight: 700; font-size: 15px;
  }
  .ufm-x { background: none; border: none; color: var(--slate-400); font-size: 20px; line-height: 1; cursor: pointer; }
  .ufm-x:hover { color: var(--slate-50); }
  .ufm-body { padding: 18px 20px; }
  .ufm-body > p { font-size: 13px; color: var(--slate-300); margin-bottom: 16px; }
  .ufm-label { display: block; font-size: 12px; font-weight: 600; color: var(--slate-300); margin-bottom: 6px; }
  .ufm-label .req { color: var(--red-400); }
  .ufm-select { position: relative; margin-bottom: 16px; }
  .ufm-trigger {
    width: 100%; display: flex; justify-content: space-between; align-items: center;
    padding: 10px 12px; background: var(--navy-950);
    border: 1px solid rgba(255,255,255,0.12); border-radius: 10px;
    color: var(--slate-50); font-size: 13.5px; text-align: left; cursor: pointer;
  }
  .ufm-trigger[aria-expanded="true"] { border-color: rgba(56,189,248,0.5); }
  .ufm-trigger i { color: var(--slate-400); transition: transform 0.2s; }
  .ufm-trigger[aria-expanded="true"] i { transform: rotate(180deg); }
  .ufm-options {
    list-style: none; margin: 0; padding: 4px;
    position: absolute; left: 0; right: 0; top: calc(100% + 6px); z-index: 5;
    background: var(--navy-950); border: 1px solid rgba(255,255,255,0.12); border-radius: 10px;
    display: none;
  }
  .ufm-options.show { display: block; }
  .ufm-options li { padding: 9px 10px; border-radius: 7px; font-size: 13.5px; color: var(--slate-300); cursor: pointer; }
  .ufm-options li:hover { background: rgba(255,255,255,0.05); color: var(--slate-50); }
  .ufm-options li.sel { color: var(--sky-400); }
  .ufm-comment-wrap { margin-top: 4px; }
  .ufm-comment {
    width: 100%; min-height: 84px; resize: vertical;
    background: var(--navy-950); border: 1px solid rgba(255,255,255,0.12); border-radius: 10px;
    padding: 10px 12px; color: var(--slate-50); font-family: inherit; font-size: 13.5px;
  }
  .ufm-comment:focus { outline: none; border-color: rgba(56,189,248,0.5); }
  .ufm-error { color: var(--red-400); font-size: 12px; margin-top: 6px; display: none; }
  .ufm-error.show { display: block; }
  .ufm-foot {
    display: flex; justify-content: flex-end; gap: 10px;
    padding: 14px 20px; border-top: 1px solid rgba(255,255,255,0.07);
  }
  .ufm-btn {
    padding: 9px 18px; border-radius: 10px; font-size: 13px; font-weight: 600; cursor: pointer;
    border: 1px solid rgba(255,255,255,0.12); background: transparent; color: var(--slate-300);
  }
  .ufm-btn:hover { color: var(--slate-50); }
  .ufm-btn.primary { background: linear-gradient(135deg, var(--sky-400), var(--blue-700)); border-color: transparent; color: #fff; }
  .ufm-btn:disabled { opacity: 0.6; cursor: wait; }
  .queue-actions {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-wrap: wrap;
    gap: 12px 16px;
    width: 100%;
    min-height: 32px;
    padding: 2px 0;
  }
  /* ---------- Tabs (same pattern as manage-staff.php) ---------- */
  .tabs{display:flex; gap:8px; margin-bottom:20px; border-bottom:1px solid rgba(255,255,255,0.08); flex-wrap:wrap;}
  .tab-btn{
    background:none; border:none; color:var(--slate-400); font-family:inherit; font-size:13.5px; font-weight:600;
    padding:11px 4px; cursor:pointer; position:relative; margin-right:22px; transition: color 0.25s ease;
  }
  .tab-btn:hover{color: var(--slate-50);}
  .tab-btn.active{color:var(--slate-50);}
  .tab-btn.active::after{
    content:""; position:absolute; left:0; right:0; bottom:-1px; height:2px; background:var(--amber-400); border-radius:2px;
    animation: slideLine 0.25s ease-out;
  }
  @keyframes slideLine { from { transform: scaleX(0); } to { transform: scaleX(1); } }
  .tab-panel{display:none;}
  .tab-panel.active{ display:block; animation: panelFadeIn 0.3s ease forwards; }
  @keyframes panelFadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }

  /* ---------- Booking history log ---------- */
  .activity-row{
    display:flex; gap: 14px; padding:13px 18px; border-bottom:1px solid rgba(255,255,255,0.05); font-size:13px;
    transition: background 0.2s;
  }
  .activity-row:hover{background: rgba(255,255,255,0.01);}
  .activity-row:last-child{border-bottom:none;}
  .activity-row{white-space:nowrap; min-width:max-content; align-items:center;}
  .activity-scroll {
    overflow-x: auto; overflow-y: hidden; max-height: none;
    scrollbar-width: thin; scrollbar-color: rgba(148, 163, 184, 0.45) transparent;
  }
  .activity-scroll.has-scroll { overflow-y: auto; }
  .activity-scroll::-webkit-scrollbar { width: 8px; height: 8px; }
  .activity-scroll::-webkit-scrollbar-track { background: transparent; }
  .activity-scroll::-webkit-scrollbar-thumb {
    background: rgba(148, 163, 184, 0.38); border-radius: 999px; border: 2px solid transparent; background-clip: content-box;
  }
  .activity-scroll::-webkit-scrollbar-thumb:hover { background: rgba(148, 163, 184, 0.62); }
  .activity-row .t{font-size:11.5px; color:var(--slate-400); min-width:90px;}
  .activity-row b{color:var(--slate-50);}

  .history-head{display:flex; align-items:center; justify-content:space-between; gap:16px 24px; flex-wrap:nowrap; padding:20px 22px 0;}
  .history-head > div:first-child{flex:1 1 0; min-width:0;}
  .history-head h3{font-family:'Space Grotesk',sans-serif; font-size:17px; font-weight:700; margin:0 0 6px; color:var(--slate-50);}
  .history-head p{font-size:13px; color:var(--slate-400); margin:0;}
  .history-filter{display:flex; align-items:center; gap:10px; flex-wrap:nowrap; flex-shrink:0;}
  @media (max-width: 900px){
    .history-head{flex-wrap:wrap;}
    .history-filter{flex-wrap:wrap;}
  }
  .history-filter label{font-size:12.5px; color:var(--slate-400); display:flex; align-items:center; gap:8px; margin:0;}
  .history-filter input[type="date"]{
    background: rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.1); border-radius:10px;
    padding:8px 10px; color:var(--slate-50); font-family:inherit; font-size:13px; color-scheme: dark;
  }
  .history-filter input[type="date"]:focus{outline:none; border-color:var(--sky-400);}
  .history-btn{border:none; border-radius:10px; padding:9px 18px; font-size:13px; font-weight:700; color:#fff; background:var(--blue-500); cursor:pointer; transition: background 0.2s;}
  .history-btn:hover{background:var(--blue-700);}
  .history-box{margin:18px 22px 22px; border:1px solid rgba(255,255,255,0.08); border-radius:14px; background:rgba(255,255,255,0.02); overflow:hidden;}
  .history-empty{text-align:center; padding:22px; color:var(--slate-400); font-size:12.5px;}

  .act-link{font-size:12px; color:var(--sky-400); font-weight:600; margin-right:0; transition: color 0.15s ease, opacity 0.2s; cursor: pointer; text-decoration: none; white-space: nowrap; display:inline-flex; align-items:center; justify-content:center;}

  body.branch-manager-theme {
    --navy-950:#070b14; --navy-900:#0b1220; --navy-800:#101a30; --navy-700:#16213f; --navy-600:#1a2438;
    --blue-700:#1d4ed8; --blue-500:#2f6feb; --sky-400:#38bdf8;
    --amber-400:#fbbf24; --amber-300:#fcd34d; --mint-400:#34d399; --red-400:#f87171;
    --slate-50:#f8fafc; --slate-300:#c9d2e3; --slate-400:#8b96ad;
    background: var(--navy-950); color: var(--slate-50);
  }
  body.branch-manager-theme.light-mode {
    --navy-950:#f3f6fb; --navy-900:#ffffff; --navy-800:#f7f9fc; --navy-700:#edf3fb; --navy-600:#e5edf8;
    --blue-700:#286be0; --blue-500:#3c82ff; --sky-400:#3f8cf5; --amber-400:#d98400; --amber-300:#e2a72d; --mint-400:#128e6b; --red-400:#cf3d3d;
    --slate-50:#172337; --slate-300:#3c4d68; --slate-400:#58677e; background: var(--navy-950); color: var(--slate-50);
  }
  body.branch-manager-theme.light-mode .sidebar,
  body.branch-manager-theme.light-mode .topbar,
  body.branch-manager-theme.light-mode .panel,
  body.branch-manager-theme.light-mode .queue-box,
  body.branch-manager-theme.light-mode .history-box,
  body.branch-manager-theme.light-mode .notif-popup,
  body.branch-manager-theme.light-mode .bell,
  body.branch-manager-theme.light-mode .side-branch,
  body.branch-manager-theme.light-mode .avatar-chip,
  body.branch-manager-theme.light-mode .stat-card,
  body.branch-manager-theme.light-mode .modal-card {
    background: var(--navy-900); border-color: rgba(17,24,39,0.12); color: var(--slate-50);
  }
  body.branch-manager-theme.light-mode .side-link,
  body.branch-manager-theme.light-mode .side-branch span,
  body.branch-manager-theme.light-mode .topbar .date,
  body.branch-manager-theme.light-mode .notif-item-body span,
  body.branch-manager-theme.light-mode .status-pill,
  body.branch-manager-theme.light-mode .history-empty,
  body.branch-manager-theme.light-mode .queue-row .pump,
  body.branch-manager-theme.light-mode .history-filter label,
  body.branch-manager-theme.light-mode .panel p,
  body.branch-manager-theme.light-mode .act-link {
    color: var(--slate-300);
  }
  body.branch-manager-theme.light-mode .side-link.active,
  body.branch-manager-theme.light-mode .side-link:hover,
  body.branch-manager-theme.light-mode .avatar-chip:hover,
  body.branch-manager-theme.light-mode .bell:hover {
    background: rgba(63, 140, 245, 0.10);
    color: var(--slate-50);
  }
  .staff-theme-control {
    position: relative; display: grid; width: 28px; height: 32px; place-items: center; margin: 0; color: var(--slate-300); cursor: pointer;
  }
  .staff-theme-control input { position: absolute; width: 1px; height: 1px; opacity: 0; }
  .staff-theme-control span { font-size: 1.05rem; }
  .staff-theme-control:focus-within span { outline: 2px solid var(--sky-400); outline-offset: 4px; border-radius: 3px; }
  body.branch-manager-theme.light-mode .staff-theme-control { color: var(--slate-400); }
</style>
</head>
<body>

<div class="app-shell">
  <!-- Sidebar -->
  <aside class="sidebar">
    <a href="dashboard.php" class="text-decoration-none d-flex align-items-center gap-2 text-white fw-bold padding" style="padding: 6px 8px 0;">
      <img src="/images/Logo.png" alt="Logo" class="nav-logo">
      <span class="disp" style="font-size: 17px; letter-spacing: 0.5px; color: #ffffff; font-weight: 700;">Map & Pump</span>
    </a>
    <div class="side-role">Branch Manager Console</div>

    <?php $hqLogoSrc = function_exists('hq_logo_src') ? hq_logo_src($branch['hq_logo'] ?? null) : null; ?>
    <div class="side-branch">
      <?php if ($hqLogoSrc): ?>
        <img src="<?= h($hqLogoSrc) ?>" alt="HQ Logo" class="hq-logo" onerror="this.style.display='none'">
      <?php else: ?>
        <div class="hq-logo" style="display:flex; align-items:center; justify-content:center; color:var(--slate-400);"><i class="fa-solid fa-building"></i></div>
      <?php endif; ?>
      <div class="txt">
        <b><?= h($branch['branch_name']) ?></b>
        <span>Branch ID: <?= h($branch['branch_code']) ?></span>
      </div>
    </div>

    <nav class="side-nav">
      <a href="dashboard.php" class="side-link"><span class="ic"><i class="fa-solid fa-house"></i></span> Dashboard</a>
      <a href="manage-staff.php" class="side-link"><span class="ic"><i class="fa-solid fa-users"></i></span> Manage Staff</a>
      <a href="manage-oil.php" class="side-link"><span class="ic"><i class="fa-solid fa-gas-pump"></i></span> Manage Oil Stock</a>
      <a href="booking-slot.php" class="side-link active"><span class="ic"><i class="fa-solid fa-ticket"></i></span> Monitor Booking Slot</a>
      <a href="view-customers.php" class="side-link"><span class="ic"><i class="fa-solid fa-address-book"></i></span> View Customers</a>
      <a href="report-hq.php" class="side-link"><span class="ic"><i class="fa-solid fa-paper-plane"></i></span> Report to HQ</a>
      <a href="branchprofile.php" class="side-link"><span class="ic"><i class="fa-solid fa-building"></i></span> Branch Profile</a>
      <a href="edit-profile.php" class="side-link"><span class="ic"><i class="fa-solid fa-gear"></i></span> Edit Profile</a>
    </nav>

    <a href="../Login/logout.php" class="side-logout"><span class="ic"><i class="fa-solid fa-right-from-bracket"></i></span> Log Out</a>
  </aside>

  <!-- Main Content Panel -->
  <div class="main">
    <!-- Topbar -->
    <div class="topbar">
      <div>
        <h1>Monitor Booking Slot <span class="live-tag ms-2"><span class="dot"></span> Live</span></h1>
        <div class="date" id="live-clock">Wednesday · July 15, 2026 · 11:38 AM</div>
      </div>
      <div class="topbar-right">
        <label class="staff-theme-control" title="Toggle light mode">
          <input class="js-theme-toggle" type="checkbox" aria-label="Toggle light mode">
          <span><i class="fa-solid fa-sun"></i></span>
        </label>

        <div class="bell" id="notif-bell"><i class="fa-regular fa-bell"></i><?php if ($has_unread): ?><span class="ping" id="notif-ping"></span><?php endif; ?></div>
        
        <div class="notif-popup" id="notif-popup">
          <div class="notif-header">
            <h4>Notifications</h4>
            <button id="clear-notifs-btn" type="button">Mark all read</button>
          </div>
          <div class="notif-list" id="notif-list">
            <?php if (!$alerts): ?>
              <div class="notif-empty" style="text-align:center; padding:12px 0; color:var(--slate-400); font-size:12px;">No new notifications</div>
            <?php else:
              foreach ($alerts as $a):
                $meta = notif_ui_meta($a['type'], $a['title']);
                $icon = $meta['icon'];
                $tone = $meta['tone'];
            ?>
              <div class="notif-item <?= h($tone) ?>" data-notif-id="<?= (int)$a['notif_id'] ?>" data-link="<?= h($a['link'] ?? 'dashboard.php') ?>" data-kind="<?= h($tone) ?>">
                <div class="ic"><i class="fa-solid <?= h($icon) ?>"></i></div>
                <div class="notif-item-body">
                  <b><?= h($a['title']) ?></b>
                  <span><?= h($a['body']) ?></span>
                  <div class="notif-time"><?= h($a['time_ago']) ?></div>
                </div>
              </div>
            <?php endforeach; endif; ?>
          </div>
        </div>
        
        <div class="avatar-chip">
          <div class="avatar-circle"><a href="edit-profile.php"><?= h(mb_substr($initials,0,1)) ?></a></div>
          <div><b><a href="edit-profile.php"><?= h(explode(' ', $user['full_name'])[0]) ?></a></b></div>
        </div>
      </div>
    </div>

    <!-- Content Workspace -->
    <div class="content">
      <div class="page-head">
      </div>

      <!-- Stats Grid -->
      <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
          <div class="stat-card">
            <div class="n" id="stat-waiting"><?= (int)$waiting_count ?></div>
            <div class="l">Token Issue</div>
          </div>
        </div>
        <div class="col-6 col-lg-3">
          <div class="stat-card">
            <div class="n"><?= (int)$completed_count ?></div>
            <div class="l">Token completed</div>
          </div>
        </div>
        <div class="col-6 col-lg-3">
          <div class="stat-card">
            <div class="n" id="stat-issued"><?= (int)$customer_cancel_count ?></div>
            <div class="l">Cancel by Customer</div>
          </div>
        </div>
        <div class="col-6 col-lg-3">
          <div class="stat-card">
            <div class="n" style="color:var(--red-400)"><?= (int)$branch_manager_cancel_count ?></div>
            <div class="l">Cancel by Branch Manager</div>
          </div>
        </div>
      </div>

      <div class="tabs">
        <button class="tab-btn active" id="tabbtn-queue" onclick="showTab('queue')">Live Booking Queue</button>
        <button class="tab-btn" id="tabbtn-history" onclick="showTab('history')">Booking History</button>
      </div>

      <!-- TAB 1: LIVE BOOKING QUEUE -->
      <div class="tab-panel active" id="tab-queue">
      <div class="panel">
        <div class="table-responsive" id="queue-scroll">
          <table class="table table-custom" id="queue-table">
            <thead>
              <tr>
                <th style="width:20%">Token</th>
                <th style="width:20%">Requested Time</th>
                <th style="width:20%">Expire Time</th>
                <th style="width:20%">Status</th>
                <th style="width:20%">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $stat_map = ['waiting' => 'waiting', 'serving' => 'serving', 'completed' => 'done', 'cancelled' => 'done'];
              $stat_label = ['waiting' => 'Waiting', 'serving' => 'Serving', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'station_unable_to_fulfill' => 'Unable to Fulfill'];
              $stat_class = ['waiting' => 'st-waiting', 'completed' => 'st-complete', 'cancelled' => 'st-canceled', 'station_unable_to_fulfill' => 'st-unable'];
              // find first idle pump for "Call Next" default target
              $default_pump = null;
              foreach ($pumps as $p) { if ($p['status'] === 'idle') { $default_pump = $p; break; } }
              if (!$queue): ?>
                <tr><td colspan="5" style="text-align:center; color:var(--slate-400); padding:22px;">Queue is empty.</td></tr>
              <?php else: foreach ($queue as $q): ?>
                <tr id="row-<?= h($q['token_code']) ?>" data-token-id="<?= (int)$q['token_id'] ?>">
                  <td class="tok-cell"><?= h($q['token_code']) ?></td>
                  <td><?= h(date('h:i A', strtotime($q['requested_at']))) ?></td>
                  <td><?= $q['estimated_at'] ? h(date('h:i A', strtotime($q['estimated_at']))) : '—' ?></td>
                  <td><span class="status-pill <?= h($stat_class[$q['status']] ?? 'st-neutral') ?>"><span class="d"></span><?= h($stat_label[$q['status']] ?? ucfirst((string)$q['status'])) ?></span></td>
                  <td>
                    <?php if ($q['status'] === 'serving'): ?>
                      <a class="act-link danger unable-btn btn-unable" data-token-id="<?= (int)$q['token_id'] ?>"><i class="fa-solid fa-triangle-exclamation"></i>Unable to fulfill</a>
                    <?php elseif ($q['status'] === 'waiting'): ?>
                      <a class="act-link danger unable-btn btn-unable" data-token-id="<?= (int)$q['token_id'] ?>"><i class="fa-solid fa-triangle-exclamation"></i>Unable to fulfill</a>
                    <?php else: ?>
                      —
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      </div>

      <!-- TAB 2: BOOKING HISTORY LOG -->
      <div class="tab-panel" id="tab-history">
        <div class="panel">
          <div class="history-head">
            <div>
              <h3>Booking Records</h3>
              <p>View completed, canceled, and unfulfilled bookings.</p>
            </div>
            <div class="history-filter">
              <label for="history-from">From <input type="date" id="history-from" name="history_from" value="<?= h($branchCreatedDate !== '' ? $branchCreatedDate : $todayDate) ?>" min="<?= h($branchCreatedDate !== '' ? $branchCreatedDate : '') ?>" max="<?= h($todayDate) ?>"></label>
              <label for="history-to">To <input type="date" id="history-to" name="history_to" value="<?= h($todayDate) ?>" min="<?= h($branchCreatedDate !== '' ? $branchCreatedDate : '') ?>" max="<?= h($todayDate) ?>"></label>
              <button type="button" class="history-btn" id="history-apply">Apply</button>
              <button type="button" class="history-btn" id="history-clear">Clear</button>
            </div>
          </div>
          <div class="history-box">
          <div class="activity-scroll" id="history-scroll">
          <?php if (!$history): ?>
            <div class="history-empty">No Booking History yet.</div>
          <?php else: ?>
            <?php
            $hist_meta = [
                'completed' => ['fa-circle-check',        'var(--mint-400)',  'completed'],
                'cancelled' => ['fa-circle-xmark',        'var(--red-400)',   'was cancelled'],
                'station_unable_to_fulfill' => ['fa-triangle-exclamation', 'var(--amber-400)', 'could not be fulfilled by the station'],
            ];
            foreach ($history as $hrow):
                $st   = (string)$hrow['status'];
                $meta = $hist_meta[$st] ?? ['fa-clock-rotate-left', 'var(--sky-400)', 'closed (' . str_replace('_', ' ', $st) . ')'];
                $extra = '';
                if ($st === 'completed' && $hrow['filled_gas'] !== null) {
                    $extra = ' · ' . rtrim(rtrim(number_format((float)$hrow['filled_gas'], 2, '.', ''), '0'), '.') . ' L';
                }
                if (!empty($hrow['fuel_name'])) {
                    $extra = ' · ' . $hrow['fuel_name'] . $extra;
                }
            ?>
              <div class="activity-row" data-date="<?= h(date('Y-m-d', strtotime($hrow['logged_at']))) ?>">
                <span class="t"><?= h(date('M j · h:i A', strtotime($hrow['logged_at']))) ?></span>
                  <i class="fa-solid <?= h($meta[0]) ?>" style="color:<?= $meta[1] ?>; margin-right:6px;"></i>
                  <b><?= h($hrow['customer_name'] ?: 'Customer') ?></b>
                  <span style="color:var(--slate-300);"> — Token <?= h($hrow['token_code']) ?> <?= h($meta[2]) ?><?= h($extra) ?></span>
                </span>
              </div>
            <?php endforeach; ?>
            <div class="history-empty" id="history-filter-empty" style="display:none;">No booking records for the selected date range.</div>
          <?php endif; ?>
          </div>
          </div>
        </div>
      </div>

      <div class="page-foot">© 2026 MAP & PUMP | All Right Reserved</div>
    </div>
  </div>
</div>

<!-- Bootstrap 5.3.3 JavaScript Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
  // Tab Switching Controller
  function showTab(name){
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    document.getElementById('tabbtn-' + name).classList.add('active');
  }

  document.addEventListener('DOMContentLoaded', () => {

    // Booking History: all records shown by default; scrollbar appears when more than 10 rows are visible
    const HISTORY_VISIBLE_ROWS = 10;
    const historyScroll = document.getElementById('history-scroll');
    const historyFrom = document.getElementById('history-from');
    const historyTo = document.getElementById('history-to');
    const historyEmpty = document.getElementById('history-filter-empty');

    function updateHistoryScrollState() {
      if (!historyScroll) return;
      const rows = Array.from(historyScroll.querySelectorAll('.activity-row')).filter(r => r.style.display !== 'none');
      if (rows.length <= HISTORY_VISIBLE_ROWS) {
        historyScroll.classList.remove('has-scroll');
        historyScroll.style.maxHeight = 'none';
        historyScroll.style.overflowY = 'hidden';
        return;
      }
      let height = 0;
      for (let i = 0; i < HISTORY_VISIBLE_ROWS; i++) height += rows[i].offsetHeight;
      if (!height) return; // tab hidden
      // account for the horizontal scrollbar, if shown
      height += historyScroll.offsetHeight - historyScroll.clientHeight;
      historyScroll.classList.add('has-scroll');
      historyScroll.style.maxHeight = height + 'px';
      historyScroll.style.overflowY = 'auto';
    }

    function applyHistoryFilter() {
      if (!historyScroll) return;
      const from = historyFrom.value;
      const to = historyTo.value;
      const invalid = from && to && from > to;
      let shown = 0;
      historyScroll.querySelectorAll('.activity-row').forEach(row => {
        const d = row.dataset.date;
        const match = !invalid && (!from || d >= from) && (!to || d <= to);
        row.style.display = match ? '' : 'none';
        if (match) shown++;
      });
      if (historyEmpty) {
        historyEmpty.textContent = invalid
          ? 'The From date cannot be later than the To date.'
          : 'No booking records for the selected date range.';
        historyEmpty.style.display = shown === 0 ? '' : 'none';
      }
      historyScroll.scrollTop = 0;
      updateHistoryScrollState();
    }

    document.getElementById('history-apply')?.addEventListener('click', applyHistoryFilter);
    document.getElementById('history-clear')?.addEventListener('click', () => {
      historyFrom.value = '';
      historyTo.value = '';
      applyHistoryFilter();
    });

    updateHistoryScrollState();
    window.addEventListener('resize', updateHistoryScrollState);
    document.getElementById('tabbtn-history')?.addEventListener('click', () => setTimeout(updateHistoryScrollState, 0));
    if (historyScroll && window.ResizeObserver) {
      new ResizeObserver(updateHistoryScrollState).observe(historyScroll);
    }

    // Live Booking Queue: show 6 rows by default, scroll when there are more (no row limit)
    const QUEUE_VISIBLE_ROWS = 6;
    const queueScroll = document.getElementById('queue-scroll');
    function updateQueueScrollState() {
      if (!queueScroll) return;
      const rows = queueScroll.querySelectorAll('#queue-table tbody tr');
      if (rows.length <= QUEUE_VISIBLE_ROWS) {
        queueScroll.classList.remove('has-scroll');
        queueScroll.style.maxHeight = 'none';
        return;
      }
      const lastVisible = rows[QUEUE_VISIBLE_ROWS - 1];
      const rowBottom = lastVisible.getBoundingClientRect().bottom - queueScroll.getBoundingClientRect().top + queueScroll.scrollTop;
      if (rowBottom <= 0) return; // tab hidden
      queueScroll.classList.add('has-scroll');
      queueScroll.style.maxHeight = Math.ceil(rowBottom + 8) + 'px';
    }
    updateQueueScrollState();
    window.addEventListener('resize', updateQueueScrollState);
    document.getElementById('tabbtn-queue')?.addEventListener('click', () => setTimeout(updateQueueScrollState, 0));
    const queueBody = document.querySelector('#queue-table tbody');
    if (queueBody) new MutationObserver(updateQueueScrollState).observe(queueBody, { childList: true });
    if (queueScroll && window.ResizeObserver) new ResizeObserver(updateQueueScrollState).observe(document.getElementById('queue-table'));

    // 1. Live Dynamic Clock
    function updateClock() {
      const now = new Date();
      const options = { weekday: 'long', month: 'short', day: 'numeric', year: 'numeric' };
      const dateString = now.toLocaleDateString('en-US', options);
      const timeString = now.toLocaleTimeString('en-US', { hour12: true, hour: '2-digit', minute: '2-digit', second: '2-digit' });
      document.getElementById('live-clock').textContent = `${dateString} · ${timeString}`;
    }
    setInterval(updateClock, 1000);
    updateClock();

    // 2. Notification Dropdown Toggle & Interactions
    const bellBtn = document.getElementById('notif-bell');
    const notifPopup = document.getElementById('notif-popup');
    const notifPing = document.getElementById('notif-ping');
    const clearNotifsBtn = document.getElementById('clear-notifs-btn');
    const notifList = document.getElementById('notif-list');

    function postDismiss(payload) {
      return fetch('api_dismiss_notification.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      }).then(r => r.json()).catch(() => ({ ok: false }));
    }

    function renderNotifEmpty() {
      if (!notifList) return;
      notifList.innerHTML = '<div class="notif-empty" style="text-align:center; padding:12px 0; color:var(--slate-400); font-size:12px;">No new notifications</div>';
      if (notifPing) notifPing.remove();
    }

    if (bellBtn && notifPopup) {
      bellBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const ping = bellBtn.querySelector('.ping');
        if (ping) {
          ping.style.transform = 'scale(0)';
          setTimeout(() => ping.remove(), 200);
        }
        notifPopup.classList.toggle('show');
      });

      document.addEventListener('click', (e) => {
        if (!notifPopup.contains(e.target) && !bellBtn.contains(e.target)) {
          notifPopup.classList.remove('show');
        }
      });
    }

    if (notifList) {
      notifList.addEventListener('click', (e) => {
        const item = e.target.closest('.notif-item');
        if (!item) return;
        const id = Number(item.dataset.notifId || 0);
        const link = item.dataset.link || '';
        if (id > 0) {
          postDismiss({ notif_id: id }).then((res) => {
            if (res.ok && notifPing && Number(res.unread || 0) <= 0) {
              notifPing.remove();
            }
          });
        }
        if (link) window.location.href = link;
      });
    }

    if (clearNotifsBtn) {
      clearNotifsBtn.addEventListener('click', () => {
        postDismiss({ all: true }).then((res) => {
          if (res.ok) renderNotifEmpty();
        });
      });
    }

    // 3. Queue Action Handlers → server persist
    const csrfToken = '<?= h(csrf_token()) ?>';
    const queueTable = document.getElementById('queue-table');

    async function serverAction(action, extra = {}) {
      const fd = new FormData();
      fd.append('csrf', csrfToken);
      fd.append('action', action);
      for (const k in extra) fd.append(k, extra[k]);
      const res = await fetch('booking-slot.php', { method: 'POST', body: fd });
      return res.json();
    }

    // UNABLE TO FULFILL MODAL
    const ufm = {
      modal: document.getElementById('ufm-modal'),
      trigger: document.getElementById('ufm-trigger'),
      options: document.getElementById('ufm-options'),
      current: document.getElementById('ufm-current'),
      otherWrap: document.getElementById('ufm-other-wrap'),
      comment: document.getElementById('ufm-comment'),
      error: document.getElementById('ufm-error'),
      submit: document.getElementById('ufm-submit'),
      cancel: document.getElementById('ufm-cancel'),
      close: document.getElementById('ufm-close'),
      tokenId: null,
    };

    function setUfmOpen(open) {
      ufm.options.classList.remove('show');
      ufm.trigger.setAttribute('aria-expanded', 'false');
      ufm.modal.classList.toggle('show', open);
    }

    function selectUfmReason(value) {
      ufm.current.textContent = value;
      ufm.options.querySelectorAll('li').forEach((li) => {
        li.classList.toggle('sel', li.dataset.value === value);
        li.setAttribute('aria-selected', li.dataset.value === value ? 'true' : 'false');
      });
      const isOther = value === 'Other';
      ufm.otherWrap.hidden = !isOther;
      if (!isOther) { ufm.comment.value = ''; ufm.error.classList.remove('show'); }
    }

    function openUnableModal(tokenId) {
      ufm.tokenId = tokenId;
      selectUfmReason('Service Unavailable');
      ufm.comment.value = '';
      ufm.error.classList.remove('show');
      ufm.submit.disabled = false;
      setUfmOpen(true);
    }

    ufm.trigger.addEventListener('click', () => {
      const open = ufm.trigger.getAttribute('aria-expanded') !== 'true';
      ufm.trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
      ufm.options.classList.toggle('show', open);
    });

    ufm.options.addEventListener('click', (e) => {
      const li = e.target.closest('li[data-value]');
      if (!li) return;
      selectUfmReason(li.dataset.value);
      ufm.trigger.setAttribute('aria-expanded', 'false');
      ufm.options.classList.remove('show');
    });

    ufm.comment.addEventListener('input', () => {
      if (ufm.comment.value.trim()) ufm.error.classList.remove('show');
    });

    [ufm.cancel, ufm.close].forEach((btn) => btn.addEventListener('click', () => setUfmOpen(false)));
    ufm.modal.addEventListener('click', (e) => { if (e.target === ufm.modal) setUfmOpen(false); });

    ufm.submit.addEventListener('click', async () => {
      const reason = ufm.current.textContent.trim();
      const note = ufm.comment.value.trim();
      if (reason === 'Other' && !note) {
        ufm.error.classList.add('show');
        ufm.comment.focus();
        return;
      }
      ufm.submit.disabled = true;
      try {
        const data = await serverAction('unable_to_fulfill', { token_id: ufm.tokenId, reason: reason, reason_note: note });
        if (!data.ok) { alert('Failed: ' + (data.error || 'unknown')); ufm.submit.disabled = false; return; }
        window.location.reload();
      } catch (err) {
        alert('Network error: ' + err.message);
        ufm.submit.disabled = false;
      }
    });

    queueTable.addEventListener('click', async (e) => {
      const target = e.target;

      // CALL NEXT ACTION
      if (target.classList.contains('btn-next')) {
        const tokenId = target.dataset.tokenId;
        const pumpId  = target.dataset.pumpId;
        if (!pumpId) { alert('No idle pump available.'); return; }
        try {
          const data = await serverAction('call_next', { token_id: tokenId, pump_id: pumpId });
          if (!data.ok) { alert('Failed: ' + (data.error || 'unknown')); return; }
          window.location.reload();
        } catch (err) { alert('Network error: ' + err.message); }
      }

      // COMPLETE ACTION
      if (target.classList.contains('btn-complete')) {
        const tokenId = target.dataset.tokenId;
        const litersText = prompt('Enter the actual liters dispensed:');
        if (litersText === null) return;
        const liters = Number(litersText);
        if (!Number.isFinite(liters) || liters <= 0) {
          alert('Enter a valid amount greater than 0 liters.');
          return;
        }
        try {
          const data = await serverAction('complete', { token_id: tokenId, filled_liters: String(liters) });
          if (!data.ok) { alert('Failed: ' + (data.error || 'unknown')); return; }
          window.location.reload();
        } catch (err) { alert('Network error: ' + err.message); }
      }

      if (target.classList.contains('btn-partial')) {
        const tokenId = target.dataset.tokenId;
        const litersText = prompt('Offer how many liters? The driver must accept in their booking page.');
        if (litersText === null) return;
        const liters = Number(litersText);
        if (!Number.isFinite(liters) || liters <= 0) { alert('Enter a valid positive amount.'); return; }
        try {
          const data = await serverAction('offer_partial', { token_id: tokenId, offered_liters: String(liters) });
          if (!data.ok) { alert('Failed: ' + (data.error || 'unknown')); return; }
          alert('Offer sent. Wait for the driver to accept before completing the fill.');
          window.location.reload();
        } catch (err) { alert('Network error: ' + err.message); }
      }

      const unableBtn = target.closest('.btn-unable');
      if (unableBtn) {
        openUnableModal(unableBtn.dataset.tokenId);
        return;
      }

      // CANCEL ACTION
      if (target.classList.contains('btn-cancel')) {
        const tokenId = target.dataset.tokenId;
        const row = target.closest('tr');
        if (!confirm('Cancel this token?')) return;
        try {
          const data = await serverAction('cancel', { token_id: tokenId });
          if (!data.ok) { alert('Failed: ' + (data.error || 'unknown')); return; }
          if (row) {
            row.style.opacity = '0';
            row.style.transform = 'translateX(20px)';
                       setTimeout(() => row.remove(), 300);
          }
          setTimeout(() => window.location.reload(), 350);
        } catch (err) { alert('Network error: ' + err.message); }
      }
    });
  });
</script>
<div class="ufm-backdrop" id="ufm-modal">
  <div class="ufm-dialog" role="dialog" aria-modal="true" aria-labelledby="ufm-title">
    <div class="ufm-head">
      <span id="ufm-title">Mark as Unable to Fulfill</span>
      <button type="button" class="ufm-x" id="ufm-close" aria-label="Close">&times;</button>
    </div>
    <div class="ufm-body">
      <p>Select a reason for being unable to fulfill this booking:</p>
      <span class="ufm-label">Reason for Unfulfillment <span class="req">*</span></span>
      <div class="ufm-select">
        <button type="button" class="ufm-trigger" id="ufm-trigger" aria-haspopup="listbox" aria-expanded="false">
          <span id="ufm-current">Service Unavailable</span><i class="fa-solid fa-chevron-down"></i>
        </button>
        <ul class="ufm-options" id="ufm-options" role="listbox">
          <li role="option" data-value="Insufficient Resources">Insufficient Resources</li>
          <li role="option" data-value="Service Unavailable" class="sel">Service Unavailable</li>
          <li role="option" data-value="Staff Unavailable">Staff Unavailable</li>
          <li role="option" data-value="Other">Other</li>
        </ul>
      </div>
      <div class="ufm-comment-wrap" id="ufm-other-wrap" hidden>
        <span class="ufm-label">Please specify the reason <span class="req">*</span></span>
        <textarea class="ufm-comment" id="ufm-comment" maxlength="500" placeholder="Describe the reason"></textarea>
        <div class="ufm-error" id="ufm-error">Please specify the reason.</div>
      </div>
    </div>
    <div class="ufm-foot">
      <button type="button" class="ufm-btn" id="ufm-cancel">Cancel</button>
      <button type="button" class="ufm-btn primary" id="ufm-submit">Submit</button>
    </div>
  </div>
</div>
<script>
  (function () {
    const root = document.body;
    const storedTheme = localStorage.getItem('map_pump_branch_manager_theme');
    const toggle = document.querySelector('.js-theme-toggle');
    const icon = document.querySelector('.staff-theme-control span i');

    function applyTheme(isLight) {
      root.classList.toggle('light-mode', isLight);
      if (toggle) toggle.checked = isLight;
      if (icon) {
        icon.className = isLight ? 'fa-solid fa-moon' : 'fa-solid fa-sun';
      }
      const control = document.querySelector('.staff-theme-control');
      if (control) control.title = isLight ? 'Toggle dark mode' : 'Toggle light mode';
      localStorage.setItem('map_pump_branch_manager_theme', isLight ? 'light' : 'dark');
    }

    root.classList.add('branch-manager-theme');
    applyTheme(storedTheme === 'light');

    if (toggle) {
      toggle.addEventListener('change', function () {
        applyTheme(this.checked);
      });
    }
  })();
</script>
</body>
</html>

<?php
function booking_cancellation_table_name(PDO $pdo): string {
    static $name = null;
    if ($name !== null) {
        return $name;
    }

    foreach (['booking_cancellation', 'booking_cancelation'] as $candidate) {
        try {
            $stmt = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1");
            $stmt->execute([':table' => $candidate]);
            if ($stmt->fetchColumn()) {
                $name = $candidate;
                return $name;
            }
        } catch (Throwable $e) {
            // Ignore unsupported schema checks and keep probing the next table name.
        }
    }

    $name = 'booking_cancellation';
    return $name;
}

function booking_unfulfillment_reason_schema_ready(PDO $pdo): bool {
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS booking_unfulfillment_reason (
                reason_id INT NOT NULL AUTO_INCREMENT,
                booking_id INT NOT NULL,
                user_id INT NOT NULL,
                reason VARCHAR(120) NOT NULL,
                details VARCHAR(1000) DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (reason_id),
                UNIQUE KEY uq_booking_unfulfillment_reason_booking (booking_id),
                KEY idx_booking_unfulfillment_reason_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        return true;
    } catch (Throwable $e) {
        error_log('Could not prepare booking_unfulfillment_reason table: ' . $e->getMessage());
        return false;
    }
}

function booking_manager_summary_counts(PDO $pdo, int $managerId): array {
    $stmt = $pdo->prepare(
        "SELECT
            COUNT(bk.booking_id) AS token_issue,
            SUM(CASE WHEN LOWER(COALESCE(bk.status, '')) IN ('completed', 'filled') THEN 1 ELSE 0 END) AS token_completed,
            SUM(CASE WHEN LOWER(COALESCE(bk.status, '')) IN ('canceled', 'cancelled') THEN 1 ELSE 0 END) AS cancel_by_customer,
            SUM(CASE WHEN LOWER(COALESCE(bk.status, '')) IN ('unable_to_fulfill', 'station_unable_to_fulfill', 'unable to fulfill') THEN 1 ELSE 0 END) AS cancel_by_manager
         FROM booking bk
         INNER JOIN station_business_scope sbs ON sbs.station_id = bk.station_id
         WHERE sbs.branch_manager_user_id = :manager_id"
    );
    $stmt->execute([':manager_id' => $managerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'token_issue' => (int) ($row['token_issue'] ?? 0),
        'token_completed' => (int) ($row['token_completed'] ?? 0),
        'cancel_by_customer' => (int) ($row['cancel_by_customer'] ?? 0),
        'cancel_by_manager' => (int) ($row['cancel_by_manager'] ?? 0),
    ];
}

function booking_manager_live_queue(PDO $pdo, int $managerId): array {
    $stmt = $pdo->prepare(
        "SELECT bk.booking_id AS token_id,
                bk.qr_refrence_code AS token_code,
                bk.created_at AS requested_at,
                bk.expire_at AS estimated_at,
                bk.status,
                bk.time_of_filled AS served_at
         FROM booking bk
         INNER JOIN station_business_scope sbs ON sbs.station_id = bk.station_id
         WHERE sbs.branch_manager_user_id = :manager_id
           AND bk.status IN ('notfilled', 'waiting', 'serving')
         ORDER BY FIELD(bk.status, 'serving', 'notfilled', 'waiting'), bk.created_at ASC"
    );
    $stmt->execute([':manager_id' => $managerId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
