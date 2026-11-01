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

    if (in_array($action, ['call_next', 'complete'], true) && !company_loyalty_station_is_paid($pdo, $bid)) {
        json_out(['ok' => false, 'error' => 'This station is paused until its HQ pays the monthly operating fee.'], 403);
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
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT bk.booking_id, bk.station_id, bk.status, bk.expire_at, p.pump_id
                FROM booking bk INNER JOIN station_business_scope sbs ON sbs.station_id = bk.station_id
                LEFT JOIN pumps p ON p.current_token = bk.qr_refrence_code AND p.branch_id = ?
                WHERE bk.booking_id = ? AND sbs.branch_manager_user_id = ? LIMIT 1 FOR UPDATE");
            $stmt->execute([$bid, $token_id, $user['user_id']]);
            $unable = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$unable || !in_array((string)$unable['status'], ['notfilled','waiting','serving'], true)
                || strtotime((string)$unable['expire_at']) <= time()) { $pdo->rollBack(); json_out(['ok' => false, 'error' => 'Booking is no longer active.'], 409); }
            $stmt = $pdo->prepare("UPDATE booking SET status = 'station_unable_to_fulfill' WHERE booking_id = ? AND status IN ('notfilled','waiting','serving')");
            $stmt->execute([$token_id]);
            map_pump_issue_shortfall_coupon($pdo, (int)$unable['booking_id'], 0.0);
            if (!empty($unable['pump_id'])) {
                $stmt = $pdo->prepare("UPDATE pumps SET status = 'idle', current_token = NULL WHERE pump_id = ?");
                $stmt->execute([(int)$unable['pump_id']]);
            }
            $pdo->commit();
            json_out(['ok' => true, 'message' => 'Closed and issued a full-value station coupon.']);
        }

        if ($action === 'complete') {
            $token_id      = (int)($_POST['token_id'] ?? 0);
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
            $tok = $stmt->fetch();
            if (!$tok) { $pdo->rollBack(); json_out(['ok' => false, 'error' => 'Token not found']); }

            if ($filled_liters > (float)$tok['requested_liters']) { $pdo->rollBack(); json_out(['ok' => false, 'error' => 'The fill cannot exceed the reserved quantity.'], 422); }
            if ($filled_liters + 0.0001 < (float)$tok['requested_liters']) {
                $offerCheck = $pdo->prepare("SELECT offered_liters FROM booking_partial_offers WHERE booking_id = ? AND status = 'accepted' LIMIT 1");
                $offerCheck->execute([(int)$tok['booking_id']]);
                $acceptedAmount = $offerCheck->fetchColumn();
                if ($acceptedAmount === false || abs((float)$acceptedAmount - $filled_liters) > 0.01) { $pdo->rollBack(); json_out(['ok' => false, 'error' => 'The driver must accept this exact partial amount in their booking page.'], 409); }
            }
            $userLock = $pdo->prepare('SELECT user_id FROM users WHERE user_id = ? LIMIT 1 FOR UPDATE');
            $userLock->execute([(int)$tok['user_id']]);
            if (!$userLock->fetchColumn()) { $pdo->rollBack(); json_out(['ok' => false, 'error' => 'Customer account is unavailable.'], 409); }
            if (!customer_license_valid_for_booking($pdo, (int)$tok['user_id'], true)) { $pdo->rollBack(); json_out(['ok' => false, 'error' => 'Driver license is expired or no longer approved.'], 403); }
            $carLink = $pdo->prepare('SELECT 1 FROM user_car WHERE user_id = ? AND car_id = ? LIMIT 1 FOR UPDATE');
            $carLink->execute([(int)$tok['user_id'], (int)$tok['car_license_id']]);
            if (!$carLink->fetchColumn()) { $pdo->rollBack(); json_out(['ok' => false, 'error' => 'Vehicle is not linked to this customer.'], 403); }
            $carStatus = $pdo->prepare('SELECT status, license_expire_date FROM car_license WHERE car_license_id = ? LIMIT 1 FOR UPDATE');
            $carStatus->execute([(int)$tok['car_license_id']]);
            $carData = $carStatus->fetch(PDO::FETCH_ASSOC);
            if (!$carData || strtolower((string)$carData['status']) === 'expired' || (!empty($carData['license_expire_date']) && $carData['license_expire_date'] < date('Y-m-d'))) { $pdo->rollBack(); json_out(['ok' => false, 'error' => 'Vehicle registration is expired or invalid.'], 403); }
            $quota = customer_fuel_quota_for_car($pdo, (int)$tok['user_id'], (int)$tok['car_license_id']);
            if ($filled_liters > (float)$quota['remaining']) { $pdo->rollBack(); json_out(['ok' => false, 'error' => 'This fill exceeds the customer weekly quota.'], 422); }

            $priceStmt = $pdo->prepare("
                SELECT price_per_litre
                FROM station_oil_inventory
                WHERE station_id = :s AND oil_type_id = :o
                ORDER BY is_active DESC, updated_at DESC LIMIT 1
            ");
            $priceStmt->execute([':s' => $tok['station_id'], ':o' => $tok['oil_type_id']]);
            $invRow = $priceStmt->fetch();
            $price  = (float)$tok['booking_price'];
            $total  = ($price !== null) ? round($price * $filled_liters, 2) : null;

            $fg = $filled_liters;
            $stmt = $pdo->prepare("UPDATE booking SET status = 'completed', filled_gas = :fg, time_of_filled = NOW() WHERE booking_id = :t AND status = 'serving'");
            $stmt->execute([':fg' => $fg, ':t' => $token_id]);

            if ($tok['pump_id']) {
                $stmt = $pdo->prepare("UPDATE pumps SET status = 'idle', current_token = NULL WHERE pump_id = :p");
                $stmt->execute([':p' => $tok['pump_id']]);
            }

            $stmt = $pdo->prepare("
                INSERT INTO usage_history
                    (booking_id, user_id, car_license_id, station_id, oil_type_id,
                     qr_code, qr_refrence_code, filled_liters, price_per_litre, total_cost,
                     status, booked_at, expire_at, filled_at, staff_user_id)
                VALUES
                    (:bid, :uid, :clid, :sid, :oid,
                     :qr, :qref, :liters, :price, :total,
                     'filled', :booked_at, :expire_at, NOW(), :staff)
            ");
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

            $isInternal = loyalty_is_internal_fill(
                $pdo,
                (int) $tok['user_id'],
                (int) $tok['station_id']
            );
            $loyaltyResult = loyalty_apply_on_fill(
                $pdo,
                (int) $tok['user_id'],
                $filled_liters,
                $total,
                (int) $tok['points_to_redeem'],
                (int) $tok['booking_id'],
                (int) $user['user_id'],
                $isInternal,
                (int) $tok['station_id'],
                $usageId > 0 ? $usageId : null
            );
            $discount = (float) ($loyaltyResult['discount_mmk'] ?? 0);
            $netTotal = $total !== null ? max(0.0, round($total - $discount, 2)) : null;
            $couponRedemption = $netTotal !== null
                ? map_pump_redeem_company_coupon($pdo, (int)$tok['user_id'], (int)$tok['station_id'], $usageId, $netTotal)
                : ['coupon_id' => null, 'discount_mmk' => 0.0];
            if ($netTotal !== null) $netTotal = max(0.0, round($netTotal - (float)$couponRedemption['discount_mmk'], 2));
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
                ':earned' => (int) ($loyaltyResult['points_earned'] ?? 0),
                ':redeemed' => (int) ($loyaltyResult['points_redeemed'] ?? 0),
                ':discount' => $discount,
                ':is_internal' => !empty($loyaltyResult['is_internal']) ? 1 : 0,
                ':admin_share' => (float) ($loyaltyResult['admin_share_mmk'] ?? 0),
                ':company_share' => (float) ($loyaltyResult['company_share_mmk'] ?? 0),
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
            if ($fuelCode === '') throw new RuntimeException('Unknown booked fuel tank.');
            $deduct = $pdo->prepare('UPDATE tanks SET current_l = current_l - ? WHERE branch_id = ? AND fuel_code = ? AND is_enabled = 1 AND current_l >= ?');
            $deduct->execute([$filled_liters, (int)$tok['station_id'], $fuelCode, $filled_liters]);
            if ($deduct->rowCount() !== 1) throw new RuntimeException('Insufficient live tank stock.');
            $tankNow = $pdo->prepare('SELECT current_l FROM tanks WHERE branch_id = ? AND fuel_code = ? LIMIT 1');
            $tankNow->execute([(int)$tok['station_id'], $fuelCode]);
            map_pump_append_station_inventory_snapshot($pdo, (int)$tok['station_id'], (int)$tok['oil_type_id'], (float)$tankNow->fetchColumn());
            if ($filled_liters + 0.0001 < (float)$tok['requested_liters']) map_pump_issue_shortfall_coupon($pdo, (int)$tok['booking_id'], $filled_liters);

            $pdo->commit();
            log_activity($pdo, $bid, 'user', $user['user_id'], $user['full_name'], "Completed token {$tok['token_code']}");
            json_out([
                'ok' => true,
                'filled_liters' => $filled_liters,
                'total_cost' => $netTotal,
                'discount_mmk' => $discount,
                'station_coupon_discount_mmk' => (float)($couponRedemption['discount_mmk'] ?? 0),
                'points_earned' => (int) ($loyaltyResult['points_earned'] ?? 0),
                'exp_earned' => (int) ($loyaltyResult['exp_earned'] ?? 0),
                'points_redeemed' => (int) ($loyaltyResult['points_redeemed'] ?? 0),
            ]);
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
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM booking bk
    INNER JOIN station_business_scope sbs ON sbs.station_id = bk.station_id
    WHERE sbs.branch_manager_user_id = :manager_id
      AND bk.status IN ('notfilled','waiting','serving')
");
$stmt->execute([':manager_id' => $user['user_id']]);
$waiting_count = (int)$stmt->fetchColumn();

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

// live queue (all non-completed, plus a few recently completed for context)
$stmt = $pdo->prepare("
    SELECT bk.booking_id AS token_id,
           bk.qr_refrence_code AS token_code,
           bk.created_at AS requested_at,
           bk.expire_at AS estimated_at,
           bk.time_of_filled AS served_at,
           CASE WHEN bk.status = 'notfilled' THEN 'waiting' ELSE bk.status END AS status,
           u.user_name AS full_name,
           NULL AS vehicle_no,
           ot.oil_name AS fuel_code,
           NULL AS pump_no
    FROM booking bk
    INNER JOIN station_business_scope sbs ON sbs.station_id = bk.station_id
    LEFT JOIN users u ON u.user_id = bk.user_id
    LEFT JOIN oil_types ot ON ot.oil_type_id = bk.oil_type_id
    WHERE sbs.branch_manager_user_id = :manager_id
      AND (bk.status IN ('notfilled','waiting','serving')
           OR (bk.status = 'completed' AND bk.time_of_filled >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)))
    ORDER BY FIELD(bk.status, 'serving','notfilled','waiting','completed'), bk.created_at ASC
    LIMIT 30
");
$stmt->execute([':manager_id' => $user['user_id']]);
$queue = $stmt->fetchAll();

// booking history (everything that is no longer in the live queue: completed, cancelled, unable to fulfill, etc.)
$history = [];
try {
    $stmt = $pdo->prepare("
        SELECT bk.booking_id,
               bk.qr_refrence_code AS token_code,
               bk.status,
               bk.filled_gas,
               COALESCE(bk.time_of_filled, bk.created_at) AS logged_at,
               u.user_name AS customer_name,
               ot.oil_name AS fuel_name
        FROM booking bk
        INNER JOIN station_business_scope sbs ON sbs.station_id = bk.station_id
        LEFT JOIN users u ON u.user_id = bk.user_id
        LEFT JOIN oil_types ot ON ot.oil_type_id = bk.oil_type_id
        WHERE sbs.branch_manager_user_id = :manager_id
          AND bk.status NOT IN ('notfilled','waiting','serving')
        ORDER BY COALESCE(bk.time_of_filled, bk.created_at) DESC, bk.booking_id DESC
        LIMIT 100
    ");
    $stmt->execute([':manager_id' => $user['user_id']]);
    $history = $stmt->fetchAll();
} catch (Throwable $e) {
    $history = [];
}

$alerts       = notif_recent($pdo, (int)$user['user_id'], 5);
$unread_count = notif_unread_count($pdo, (int)$user['user_id']);
$has_unread   = $unread_count > 0;
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
  .table-custom th:nth-child(4),
  .table-custom td:nth-child(4) {
    width: 42%;
    min-width: 360px;
  }
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
  .activity-scroll {
    overflow-x: hidden; overflow-y: hidden; max-height: none;
    scrollbar-width: thin; scrollbar-color: rgba(148, 163, 184, 0.45) transparent;
  }
  .activity-scroll.has-scroll { overflow-y: auto; }
  .activity-scroll::-webkit-scrollbar { width: 8px; }
  .activity-scroll::-webkit-scrollbar-track { background: transparent; }
  .activity-scroll::-webkit-scrollbar-thumb {
    background: rgba(148, 163, 184, 0.38); border-radius: 999px; border: 2px solid transparent; background-clip: content-box;
  }
  .activity-scroll::-webkit-scrollbar-thumb:hover { background: rgba(148, 163, 184, 0.62); }
  .activity-row .t{font-size:11.5px; color:var(--slate-400); min-width:90px;}
  .activity-row b{color:var(--slate-50);}

  .act-link{font-size:12px; color:var(--sky-400); font-weight:600; margin-right:0; transition: color 0.15s ease, opacity 0.2s; cursor: pointer; text-decoration: none; white-space: nowrap; display:inline-flex; align-items:center; justify-content:center;}
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
            <div class="n" id="stat-waiting"><?= $waiting_count ?></div>
            <div class="l">Token Issue</div>
          </div>
        </div>
        <div class="col-6 col-lg-3">
          <div class="stat-card">
            <div class="n"><?= $avg_wait ?: '—' ?> min</div>
            <div class="l">Token completed</div>
          </div>
        </div>
        <div class="col-6 col-lg-3">
          <div class="stat-card">
            <div class="n" id="stat-issued"><?= $issued_today ?></div>
            <div class="l">Cancel by Customer</div>
          </div>
        </div>
        <div class="col-6 col-lg-3">
          <div class="stat-card">
            <div class="n" style="color:var(--red-400)"><?= $pump_maint ?> / <?= $pump_total ?></div>
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
        <div class="table-responsive">
          <table class="table table-custom" id="queue-table">
            <thead>
              <tr>
                <th style="width:20%">Token</th>
                <th style="width:20%">Requested Time</th>
                <th style="width:20%">Expire Time</th>
                <th style="width:40%">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $stat_map = ['waiting' => 'waiting', 'serving' => 'serving', 'completed' => 'done', 'cancelled' => 'done'];
              $stat_label = ['waiting' => 'Waiting', 'serving' => 'Serving', 'completed' => 'Done', 'cancelled' => 'Cancelled'];
              // find first idle pump for "Call Next" default target
              $default_pump = null;
              foreach ($pumps as $p) { if ($p['status'] === 'idle') { $default_pump = $p; break; } }
              if (!$queue): ?>
                <tr><td colspan="4" style="text-align:center; color:var(--slate-400); padding:22px;">Queue is empty.</td></tr>
              <?php else: foreach ($queue as $q): ?>
                <tr id="row-<?= h($q['token_code']) ?>" data-token-id="<?= (int)$q['token_id'] ?>">
                  <td class="tok-cell"><?= h($q['token_code']) ?></td>
                  <td><?= h(date('h:i A', strtotime($q['requested_at']))) ?></td>
                  <td><?= $q['estimated_at'] ? h(date('h:i A', strtotime($q['estimated_at']))) : '—' ?></td>
                  <td>
                    <?php if ($q['status'] === 'serving'): ?>
                      <a class="act-link btn-complete" data-token-id="<?= (int)$q['token_id'] ?>">Complete</a>
                      <a class="act-link btn-partial" data-token-id="<?= (int)$q['token_id'] ?>">Offer partial</a>
                      <a class="act-link danger btn-cancel" data-token-id="<?= (int)$q['token_id'] ?>">Cancel</a>
                      <a class="act-link danger btn-unable" data-token-id="<?= (int)$q['token_id'] ?>">Unable to fulfill</a>
                    <?php elseif ($q['status'] === 'waiting'): ?>
                      <?php if ($default_pump): ?>
                        <a class="act-link btn-next" data-token-id="<?= (int)$q['token_id'] ?>" data-pump-id="<?= (int)$default_pump['pump_id'] ?>">Call Next</a>
                      <?php endif; ?>
                      <a class="act-link btn-partial" data-token-id="<?= (int)$q['token_id'] ?>">Offer partial</a>
                      <a class="act-link danger btn-cancel" data-token-id="<?= (int)$q['token_id'] ?>">Cancel</a>
                      <a class="act-link danger btn-unable" data-token-id="<?= (int)$q['token_id'] ?>">Unable to fulfill</a>
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
          <div class="activity-scroll" id="history-scroll">
          <?php if (!$history): ?>
            <div style="text-align:center; padding:22px; color:var(--slate-400); font-size: 12.5px;">No Booking History yet.</div>
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
              <div class="activity-row">
                <span class="t"><?= h(date('M j · h:i A', strtotime($hrow['logged_at']))) ?></span>
                <span>
                  <i class="fa-solid <?= h($meta[0]) ?>" style="color:<?= $meta[1] ?>; margin-right:6px;"></i>
                  <b><?= h($hrow['customer_name'] ?: 'Customer') ?></b>
                  <span style="color:var(--slate-300);"> — Token <?= h($hrow['token_code']) ?> <?= h($meta[2]) ?><?= h($extra) ?></span>
                </span>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
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

    // Booking History: show 5 rows by default, scroll when there are more
    const historyScroll = document.getElementById('history-scroll');
    function updateHistoryScrollState() {
      if (!historyScroll) return;
      const rows = historyScroll.querySelectorAll('.activity-row');
      if (rows.length <= 5) {
        historyScroll.classList.remove('has-scroll');
        historyScroll.style.maxHeight = 'none';
        historyScroll.style.overflowY = 'hidden';
        return;
      }
      let height = 0;
      for (let i = 0; i < 5; i++) height += rows[i].offsetHeight;
      if (!height) return; // tab hidden
      historyScroll.classList.add('has-scroll');
      historyScroll.style.maxHeight = height + 'px';
      historyScroll.style.overflowY = 'auto';
    }
    updateHistoryScrollState();
    window.addEventListener('resize', updateHistoryScrollState);
    if (historyScroll && window.ResizeObserver) {
      new ResizeObserver(updateHistoryScrollState).observe(historyScroll);
    }

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

      if (target.classList.contains('btn-unable')) {
        const tokenId = target.dataset.tokenId;
        if (!confirm('Close this reservation as unfulfillable and issue a full-value station coupon?')) return;
        try {
          const data = await serverAction('unable_to_fulfill', { token_id: tokenId });
          if (!data.ok) { alert('Failed: ' + (data.error || 'unknown')); return; }
          window.location.reload();
        } catch (err) { alert('Network error: ' + err.message); }
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
</body>
</html>
