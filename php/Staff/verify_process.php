<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require __DIR__ . '/../conn.php';
require __DIR__ . '/../usage_history_schema.php';
require_once __DIR__ . '/../Customer/car_access.php';
require_once __DIR__ . '/../Customer/license_access.php';
require_once __DIR__ . '/../business_scope.php';
require_once __DIR__ . '/../booking_rules.php';
require_once __DIR__ . '/../booking_reservations.php';
require_once __DIR__ . '/../activity_log.php';
require_once __DIR__ . '/../loyalty_points.php';
map_pump_booking_configure_timezone($pdo);
customer_license_status_column_ready($pdo);

$staffCanAccessBranch = static function (int $staffUserId, int $branchId) use ($pdo): bool {
    return function_exists('map_pump_staff_can_access_branch')
        && (bool) call_user_func('map_pump_staff_can_access_branch', $pdo, $staffUserId, $branchId);
};

$bookingCode = '';
$ajax = false;

if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    $ajax = true;
}


if (empty($_SESSION['is_logged_in']) || empty($_SESSION['user_id'])) {
    respond(['success' => false, 'message' => 'Please sign in to verify this booking.'], $ajax, 401, '../Login/login.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_SESSION['staff_verify_csrf_token'] ?? '');
    if ($csrf === '' || !hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        respond(['success' => false, 'message' => 'Your session expired. Reload the scanner or booking details and try again.'], $ajax, 403);
    }
    $action = isset($_POST['action']) ? trim($_POST['action']) : 'verify';
    if ($action === 'offer_partial') {
        $bookingId = (int)($_POST['booking_id'] ?? 0);
        $offered = round((float)($_POST['amount_liters'] ?? 0), 2);
        if ($bookingId <= 0 || $offered <= 0 || !map_pump_booking_schema_ready($pdo)) {
            respond(['success' => false, 'message' => 'Invalid partial-fill offer.'], $ajax, 422, 'verify_detail.php?booking_id=' . $bookingId);
        }
        try {
            $pdo->beginTransaction();
            $offerBooking = $pdo->prepare("SELECT b.station_id, b.status, b.expire_at, r.requested_liters
                FROM booking b INNER JOIN booking_reservations r ON r.booking_id = b.booking_id
                WHERE b.booking_id = ? LIMIT 1 FOR UPDATE");
            $offerBooking->execute([$bookingId]);
            $offerRow = $offerBooking->fetch(PDO::FETCH_ASSOC);
            if (!$offerRow || !$staffCanAccessBranch((int)$_SESSION['user_id'], (int)$offerRow['station_id'])
                || !in_array((string)$offerRow['status'], ['notfilled', 'waiting', 'serving'], true)
                || strtotime((string)$offerRow['expire_at']) <= time()
                || $offered >= (float)$offerRow['requested_liters']) {
                throw new RuntimeException('This booking cannot accept that partial-fill offer.');
            }
            $saveOffer = $pdo->prepare("INSERT INTO booking_partial_offers (booking_id, offered_liters, status, offered_by, offered_at, answered_at)
                VALUES (?, ?, 'pending', ?, NOW(), NULL)
                ON DUPLICATE KEY UPDATE offered_liters = VALUES(offered_liters), status = 'pending', offered_by = VALUES(offered_by), offered_at = NOW(), answered_at = NULL");
            $saveOffer->execute([$bookingId, round($offered, 2), (int)$_SESSION['user_id']]);
            $pdo->commit();
            respond(['success' => true, 'message' => 'Partial quantity sent to the driver for acceptance.'], $ajax, 200, 'verify_detail.php?booking_id=' . $bookingId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            respond(['success' => false, 'message' => $e->getMessage()], $ajax, 409, 'verify_detail.php?booking_id=' . $bookingId);
        }
    }
    if ($action === 'unable_to_fulfill') {
        $bookingId = (int)($_POST['booking_id'] ?? 0);
        if ($bookingId <= 0 || !map_pump_booking_schema_ready($pdo)) respond(['success' => false, 'message' => 'Invalid booking.'], $ajax, 422);
        try {
            $pdo->beginTransaction();
            $unableStmt = $pdo->prepare("SELECT b.station_id, b.qr_refrence_code, b.status, b.expire_at FROM booking b WHERE b.booking_id = ? LIMIT 1 FOR UPDATE");
            $unableStmt->execute([$bookingId]);
            $unable = $unableStmt->fetch(PDO::FETCH_ASSOC);
            if (!$unable || !$staffCanAccessBranch((int)$_SESSION['user_id'], (int)$unable['station_id'])
                || !in_array((string)$unable['status'], ['notfilled','waiting','serving'], true)
                || strtotime((string)$unable['expire_at']) <= time()) throw new RuntimeException('This booking can no longer be closed as unfulfillable.');
            $close = $pdo->prepare("UPDATE booking SET status = 'station_unable_to_fulfill' WHERE booking_id = ? AND status IN ('notfilled','waiting','serving')");
            $close->execute([$bookingId]);
            $freePump = $pdo->prepare("UPDATE pumps SET status = 'idle', current_token = NULL WHERE branch_id = ? AND current_token = ? AND status = 'active'");
            $freePump->execute([(int)$unable['station_id'], (string)$unable['qr_refrence_code']]);
            map_pump_issue_shortfall_coupon($pdo, $bookingId, 0.0);
            $pdo->commit();
            respond(['success' => true, 'message' => 'Booking closed. A full-value station coupon was issued to the driver.'], $ajax, 200, 'verify_qr.php');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            respond(['success' => false, 'message' => $e->getMessage()], $ajax, 409, 'verify_detail.php?booking_id=' . $bookingId);
        }
    }
    if ($action === 'confirm') {
        $bookingId = isset($_POST['booking_id']) ? (int)$_POST['booking_id'] : 0;
        $amountLiters = isset($_POST['amount_liters']) ? trim($_POST['amount_liters']) : '';

        if ($bookingId <= 0 || $amountLiters === '' || !is_numeric($amountLiters) || (float)$amountLiters <= 0) {
            respond(['success' => false, 'message' => 'Please enter a valid amount in liters.'], $ajax, 422, 'verify_detail.php?booking_id=' . urlencode((string)$bookingId));
        }

        // Ensure optional loyalty columns exist (best-effort; no ledger table).
        loyalty_schema_ready($pdo);
        // Create loyalty tables before opening the fill transaction. MySQL
        // DDL may implicitly commit, so schema setup must stay outside it.
        loyalty_countermeasures_schema_ready($pdo);

        $stmt = $pdo->prepare(
            'SELECT b.booking_id, b.user_id, b.station_id, b.oil_type_id, o.oil_name, b.qr_code, b.qr_refrence_code,
                    r.requested_liters, r.price_per_litre AS booking_price,
                    b.status, b.filled_gas, b.time_of_filled, b.expire_at, b.created_at,
                    b.car_license_id
             FROM booking b
             LEFT JOIN booking_reservations r ON r.booking_id = b.booking_id
             LEFT JOIN users u ON u.user_id = b.user_id
             LEFT JOIN oil_types o ON o.oil_type_id = b.oil_type_id
             WHERE b.booking_id = :booking_id
             LIMIT 1'
        );
        $stmt->execute([':booking_id' => $bookingId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            if (map_pump_usage_history_schema_ready($pdo)) {
                $historyRow = find_usage_history_by_booking_id($pdo, $bookingId);
                if ($historyRow && $staffCanAccessBranch((int) $_SESSION['user_id'], (int) $historyRow['station_id'])) {
                    respond([
                        'success' => true,
                        'message' => 'Fuel fill completed successfully.',
                        'booking' => $historyRow,
                    ], $ajax, 200, 'verify_qr.php');
                }
            }
            respond(['success' => false, 'message' => 'Booking not found.'], $ajax, 404, 'verify_detail.php?booking_id=' . urlencode((string)$bookingId));
        }

            if (!$staffCanAccessBranch((int) $_SESSION['user_id'], (int) $row['station_id'])) {
            respond(['success' => false, 'message' => 'This booking belongs to another station.'], $ajax, 403, 'verify_qr.php');
        }

        if (!hq_billing_station_is_paid($pdo, (int) $row['station_id'])) {
            respond(['success' => false, 'message' => 'This station is paused until its HQ pays the MMK service package.'], $ajax, 403, 'verify_qr.php');
        }

        $closedStatuses = ['filled', 'completed', 'cancelled', 'expired', 'station_unable_to_fulfill'];
        if (in_array(strtolower((string)$row['status']), $closedStatuses, true)) {
            respond(['success' => false, 'message' => 'This booking has already been used or is no longer valid.', 'booking' => $row], $ajax, 200, 'verify_qr.php');
        }

        $requestedLiters = (float)($row['requested_liters'] ?? 0);
        if ($requestedLiters <= 0 || (float)$amountLiters > $requestedLiters) {
            respond(['success' => false, 'message' => 'The fill must not exceed the quantity reserved for this booking.'], $ajax, 422, 'verify_detail.php?booking_id=' . urlencode((string)$bookingId));
        }
        if ((float)$amountLiters + 0.0001 < $requestedLiters) {
            $offerStmt = $pdo->prepare("SELECT offered_liters, status FROM booking_partial_offers WHERE booking_id = ? LIMIT 1");
            $offerStmt->execute([$bookingId]);
            $offer = $offerStmt->fetch(PDO::FETCH_ASSOC);
            if (!$offer || $offer['status'] !== 'accepted' || abs((float)$offer['offered_liters'] - (float)$amountLiters) > 0.01) {
                respond(['success' => false, 'message' => 'The driver must accept this exact partial-fill amount in their booking page before the fill can be confirmed.'], $ajax, 409, 'verify_detail.php?booking_id=' . urlencode((string)$bookingId));
            }
        }

        if (!empty($row['expire_at']) && strtotime((string)$row['expire_at']) < time()) {
            $expireUpdate = $pdo->prepare("UPDATE booking SET status = 'expired' WHERE booking_id = :booking_id AND status IN ('notfilled','waiting','serving') AND expire_at <= NOW()");
            $expireUpdate->execute([':booking_id' => $row['booking_id']]);
            respond(['success' => false, 'message' => 'This booking pass has expired.', 'booking' => $row], $ajax, 200, 'verify_qr.php');
        }

        if (!map_pump_usage_history_schema_ready($pdo)) {
            respond(['success' => false, 'message' => 'Usage history table is not ready.'], $ajax, 500, 'verify_detail.php?booking_id=' . urlencode((string)$bookingId));
        }

        $carLicenseId = isset($row['car_license_id']) ? (int)$row['car_license_id'] : 0;
        if ($carLicenseId <= 0) {
            respond(['success' => false, 'message' => 'This booking does not have a car license linked. Ask the customer to add/select a car and book again.'], $ajax, 422, 'verify_detail.php?booking_id=' . urlencode((string)$bookingId));
        }

        $quota = customer_fuel_quota_for_car($pdo, (int)$row['user_id'], $carLicenseId);
        $remainingLiters = (float)$quota['remaining'];
        if ((float)$amountLiters > $remainingLiters) {
            respond(['success' => false, 'message' => 'This fill is over the ' . map_pump_fuel_quota_period_label() . ' fuel limit. Remaining allowance: ' . rtrim(rtrim(number_format($remainingLiters, 1, '.', ''), '0'), '.') . ' liters.'], $ajax, 422, 'verify_detail.php?booking_id=' . urlencode((string)$bookingId));
        }

        $pdo->beginTransaction();
        try {
            $userLock = $pdo->prepare('SELECT user_id FROM users WHERE user_id = ? LIMIT 1 FOR UPDATE');
            $userLock->execute([(int)$row['user_id']]);
            if (!$userLock->fetchColumn()) throw new RuntimeException('BOOKING_USER_MISSING');
            if (!customer_license_valid_for_booking($pdo, (int)$row['user_id'], true)) throw new RuntimeException('DRIVER_LICENSE_INVALID');
            $lockedBooking = $pdo->prepare("SELECT status, expire_at FROM booking WHERE booking_id = ? FOR UPDATE");
            $lockedBooking->execute([(int)$row['booking_id']]);
            $lockedState = $lockedBooking->fetch(PDO::FETCH_ASSOC);
            if (!$lockedState || !in_array((string)$lockedState['status'], ['notfilled','waiting','serving'], true)
                || strtotime((string)$lockedState['expire_at']) <= time()) throw new RuntimeException('BOOKING_NOT_ACTIVE');
            if ((float)$amountLiters + 0.0001 < $requestedLiters) {
                $lockedOffer = $pdo->prepare("SELECT offered_liters, status FROM booking_partial_offers WHERE booking_id = ? LIMIT 1 FOR UPDATE");
                $lockedOffer->execute([(int)$row['booking_id']]);
                $offerState = $lockedOffer->fetch(PDO::FETCH_ASSOC);
                if (!$offerState || $offerState['status'] !== 'accepted' || abs((float)$offerState['offered_liters'] - (float)$amountLiters) > 0.01) throw new RuntimeException('BOOKING_NOT_ACTIVE');
            }
            $quota = customer_fuel_quota_for_car($pdo, (int)$row['user_id'], $carLicenseId);
            if ((float)$amountLiters > (float)$quota['remaining']) throw new RuntimeException('QUOTA_EXCEEDED');
            $carOwnership = $pdo->prepare('SELECT 1 FROM user_car WHERE user_id = ? AND car_id = ? LIMIT 1 FOR UPDATE');
            $carOwnership->execute([(int)$row['user_id'], $carLicenseId]);
            if (!$carOwnership->fetchColumn()) throw new RuntimeException('CAR_NOT_OWNED');
            $carExpiry = $pdo->prepare("SELECT status, license_expire_date FROM car_license WHERE car_license_id = ? LIMIT 1 FOR UPDATE");
            $carExpiry->execute([$carLicenseId]);
            $carState = $carExpiry->fetch(PDO::FETCH_ASSOC);
            if (!$carState || strtolower((string)$carState['status']) === 'expired'
                || (!empty($carState['license_expire_date']) && $carState['license_expire_date'] < date('Y-m-d'))) throw new RuntimeException('CAR_EXPIRED');
            $fuelName = strtolower(str_replace(' ', '', (string)($row['oil_name'] ?? '')));
            $tankFuelCode = match ($fuelName) {
                'octane92' => 'p92',
                'octane95' => 'p95',
                'disel', 'diesel' => 'dsl',
                'premiumdisel', 'premiumdiesel' => 'pdsl',
                default => '',
            };
            if ($tankFuelCode === '') {
                throw new RuntimeException('The booking fuel type is not mapped to a station tank.');
            }

            // Deduct stock atomically. A fill cannot be confirmed once the
            // selected station has insufficient live stock for it.
            $deductTank = $pdo->prepare(
                'UPDATE tanks
                 SET current_l = current_l - :amount
                 WHERE branch_id = :station_id
                   AND fuel_code = :fuel_code
                   AND is_enabled = 1
                   AND current_l >= :amount'
            );
            $deductTank->execute([
                ':amount' => (float)$amountLiters,
                ':station_id' => (int)$row['station_id'],
                ':fuel_code' => $tankFuelCode,
            ]);
            if ($deductTank->rowCount() !== 1) {
                throw new RuntimeException('INSUFFICIENT_LIVE_STOCK');
            }

            $tankLevelStmt = $pdo->prepare(
                'SELECT current_l FROM tanks WHERE branch_id = :station_id AND fuel_code = :fuel_code LIMIT 1'
            );
            $tankLevelStmt->execute([
                ':station_id' => (int)$row['station_id'],
                ':fuel_code' => $tankFuelCode,
            ]);
            $remainingTankLiters = (float)($tankLevelStmt->fetchColumn() ?: 0);
            map_pump_append_station_inventory_snapshot($pdo, (int)$row['station_id'], (int)$row['oil_type_id'], $remainingTankLiters);

            $updateCar = $pdo->prepare('UPDATE car_license SET filled_gas = filled_gas + :amount, updated_at = NOW() WHERE car_license_id = :car_license_id');
            $updateCar->execute([
                ':amount' => (float)$amountLiters,
                ':car_license_id' => $carLicenseId,
            ]);

            $latestInventorySql = map_pump_latest_station_inventory_sql();
            $priceStmt = $pdo->prepare(
                "SELECT price_per_litre
                 FROM ({$latestInventorySql}) li
                 WHERE li.station_id = :station_id AND li.oil_type_id = :oil_type_id
                 LIMIT 1"
            );
            $priceStmt->execute([
                ':station_id' => (int)$row['station_id'],
                ':oil_type_id' => (int)$row['oil_type_id'],
            ]);
            $pricePerLitre = $priceStmt->fetchColumn();
            // A reservation's quote is stable even if the station later changes its public price.
            $pricePerLitre = isset($row['booking_price']) ? (float)$row['booking_price'] : ($pricePerLitre !== false ? (float)$pricePerLitre : null);
            $totalCost = $pricePerLitre !== null ? round($pricePerLitre * (float)$amountLiters, 2) : null;
            $filledAt = date('Y-m-d H:i:s');
            $staffUserId = (int)($_SESSION['user_id'] ?? 0);

            $historyInsert = $pdo->prepare(
                'INSERT INTO usage_history
                    (booking_id, user_id, car_license_id, station_id, oil_type_id, qr_code, qr_refrence_code,
                     filled_liters, price_per_litre, total_cost, status, booked_at, expire_at, filled_at, staff_user_id, created_at)
                 VALUES
                    (:booking_id, :user_id, :car_license_id, :station_id, :oil_type_id, :qr_code, :qr_refrence_code,
                     :filled_liters, :price_per_litre, :total_cost, :status, :booked_at, :expire_at, :filled_at, :staff_user_id, NOW())'
            );
            $historyInsert->execute([
                ':booking_id' => (int)$row['booking_id'],
                ':user_id' => (int)$row['user_id'],
                ':car_license_id' => $carLicenseId > 0 ? $carLicenseId : null,
                ':station_id' => (int)$row['station_id'],
                ':oil_type_id' => (int)$row['oil_type_id'],
                ':qr_code' => (string)$row['qr_code'],
                ':qr_refrence_code' => (string)$row['qr_refrence_code'],
                ':filled_liters' => (float)$amountLiters,
                ':price_per_litre' => $pricePerLitre,
                ':total_cost' => $totalCost,
                ':status' => 'filled',
                ':booked_at' => $row['created_at'] ?? null,
                ':expire_at' => $row['expire_at'] ?? null,
                ':filled_at' => $filledAt,
                ':staff_user_id' => $staffUserId > 0 ? $staffUserId : null,
            ]);
            $usageId = (int) $pdo->lastInsertId();

            // Loyalty: read the booking reward intent, then settle redemption and earn on fill.
            $pointsIntent = 0;
            try {
                $intentStmt = $pdo->prepare(
                    'SELECT COALESCE(points_to_redeem, 0) FROM booking WHERE booking_id = ? LIMIT 1'
                );
                $intentStmt->execute([(int) $row['booking_id']]);
                $pointsIntent = (int) $intentStmt->fetchColumn();
            } catch (Throwable $intentEx) {
                $pointsIntent = 0;
            }

            // Countermeasures: detect internal (own station / business-role same HQ)
            $isInternalFill = false;
            $isInternalFill = loyalty_is_internal_fill(
                $pdo,
                (int) $row['user_id'],
                (int) $row['station_id']
            );

            $loyaltyResult = loyalty_apply_on_fill(
                $pdo,
                (int) $row['user_id'],
                (float) $amountLiters,
                $totalCost !== null ? (float) $totalCost : null,
                $pointsIntent,
                (int) $row['booking_id'],
                $staffUserId > 0 ? $staffUserId : null,
                $isInternalFill,
                (int) $row['station_id'],
                $usageId > 0 ? $usageId : null
            );
            $netTotalCost = $totalCost !== null
                ? max(0.0, round($totalCost - (float) ($loyaltyResult['discount_mmk'] ?? 0), 2))
                : null;
            $couponRedemption = $netTotalCost !== null
                ? map_pump_redeem_company_coupon($pdo, (int)$row['user_id'], (int)$row['station_id'], $usageId, $netTotalCost)
                : ['coupon_id' => null, 'discount_mmk' => 0.0];
            if ($netTotalCost !== null) $netTotalCost = max(0.0, round($netTotalCost - (float)$couponRedemption['discount_mmk'], 2));

            // Persist loyalty figures on usage_history when columns exist (fail-soft).
            if ($usageId > 0) {
                try {
                    $loyaltyHist = $pdo->prepare(
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
                    $loyaltyHist->execute([
                        ':net_total' => $netTotalCost,
                        ':earned' => (int) ($loyaltyResult['points_earned'] ?? 0),
                        ':redeemed' => (int) ($loyaltyResult['points_redeemed'] ?? 0),
                        ':discount' => (float) ($loyaltyResult['discount_mmk'] ?? 0),
                        ':is_internal' => !empty($loyaltyResult['is_internal']) ? 1 : 0,
                        ':admin_share' => isset($loyaltyResult['admin_share_mmk'])
                            ? (float) $loyaltyResult['admin_share_mmk']
                            : null,
                        ':company_share' => isset($loyaltyResult['company_share_mmk'])
                            ? (float) $loyaltyResult['company_share_mmk']
                            : null,
                        ':usage_id' => $usageId,
                    ]);
                } catch (Throwable $histEx) {
                    // New columns may not exist yet — fall back to v1 columns only.
                    try {
                        $loyaltyHist = $pdo->prepare(
                            'UPDATE usage_history
                             SET total_cost = :net_total,
                                 points_earned = :earned,
                                 points_redeemed = :redeemed,
                                 discount_mmk = :discount
                             WHERE usage_id = :usage_id'
                        );
                        $loyaltyHist->execute([
                            ':net_total' => $netTotalCost,
                            ':earned' => (int) ($loyaltyResult['points_earned'] ?? 0),
                            ':redeemed' => (int) ($loyaltyResult['points_redeemed'] ?? 0),
                            ':discount' => (float) ($loyaltyResult['discount_mmk'] ?? 0),
                            ':usage_id' => $usageId,
                        ]);
                    } catch (Throwable $histEx2) {
                        // Columns may not exist yet; non-fatal.
                    }
                }
            }

            $completeBooking = $pdo->prepare("UPDATE booking SET status = 'completed', filled_gas = :liters, time_of_filled = NOW() WHERE booking_id = :booking_id AND status IN ('notfilled','waiting','serving')");
            $completeBooking->execute([':liters' => (float)$amountLiters, ':booking_id' => (int)$row['booking_id']]);
            if ($completeBooking->rowCount() !== 1) throw new RuntimeException('BOOKING_NOT_ACTIVE');
            $coupon = null;
            if ((float)$amountLiters + 0.0001 < $requestedLiters) {
                $coupon = map_pump_issue_shortfall_coupon($pdo, (int)$row['booking_id'], (float)$amountLiters);
            }

            $pdo->commit();
            $row['status'] = 'filled';
            $row['filled_gas'] = (float)$amountLiters;
            $row['time_of_filled'] = $filledAt;
            $row['loyalty'] = $loyaltyResult;
            $row['coupon'] = $coupon;
            $row['coupon_redemption'] = $couponRedemption;
            map_pump_log_action($pdo, $staffUserId, 'Confirmed fuel fill for booking #' . (int) $row['booking_id'] . ' (' . rtrim(rtrim(number_format((float) $amountLiters, 2, '.', ''), '0'), '.') . ' L)', 5);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = $e->getMessage() === 'INSUFFICIENT_LIVE_STOCK'
                ? 'The station no longer has enough live stock for this fill. Ask the branch manager to update stock before confirming.'
                : (in_array($e->getMessage(), ['BOOKING_NOT_ACTIVE','CAR_NOT_OWNED','CAR_EXPIRED','QUOTA_EXCEEDED','DRIVER_LICENSE_INVALID'], true)
                    ? 'The booking, vehicle, or quota is no longer valid. Refresh the booking before continuing.'
                : 'Failed to save fill amount. Please try again.');
            $status = $e->getMessage() === 'INSUFFICIENT_LIVE_STOCK' ? 422
                : ($e->getMessage() === 'DRIVER_LICENSE_INVALID' ? 403
                : (in_array($e->getMessage(), ['BOOKING_NOT_ACTIVE','CAR_NOT_OWNED','CAR_EXPIRED','QUOTA_EXCEEDED'], true) ? 409 : 500));
            respond(['success' => false, 'message' => $message], $ajax, $status, 'verify_detail.php?booking_id=' . urlencode((string)$bookingId));
        }

        $loyaltyMsg = '';
        if (!empty($row['loyalty']) && is_array($row['loyalty'])) {
            $le = (int) ($row['loyalty']['points_earned'] ?? 0);
            $ee = (int) ($row['loyalty']['exp_earned'] ?? 0);
            $lr = (int) ($row['loyalty']['points_redeemed'] ?? 0);
            $ld = (float) ($row['loyalty']['discount_mmk'] ?? 0);
            $isInternalMsg = !empty($row['loyalty']['is_internal']);
            $poolLimited = !empty($row['loyalty']['pool_limited']);
            if ($isInternalMsg) {
                $loyaltyMsg = ' Loyalty: 0 pts (internal fill — own station/network; points not awarded).';
            } elseif ($le > 0 || $ee > 0 || $lr > 0 || $poolLimited) {
                $loyaltyParts = [];
                if ($le > 0) {
                    $loyaltyParts[] = '+' . $le . ' pt' . ($le === 1 ? '' : 's');
                }
                if ($ee > 0) {
                    $loyaltyParts[] = '+' . $ee . ' EXP';
                }
                if ($lr > 0) {
                    $loyaltyParts[] = '−' . $lr . ' redeemed';
                    if ($ld > 0) {
                        $loyaltyParts[] = '(' . number_format($ld, 0) . ' MMK off)';
                    }
                    if ($poolLimited) {
                        $loyaltyParts[] = '(tier pool limited)';
                    }
                } elseif ($poolLimited) {
                    $loyaltyParts[] = 'redeem skipped (tier pool empty)';
                }
                $loyaltyMsg = ' Loyalty: ' . implode(', ', $loyaltyParts) . '.';
            }
        }
        if (!empty($row['coupon']['face_value_mmk'])) {
            $loyaltyMsg .= ' Station shortfall coupon issued: ' . number_format((float)$row['coupon']['face_value_mmk'], 0) . ' MMK, usable once at this company within 30 days.';
        }
        if (!empty($row['coupon_redemption']['discount_mmk'])) {
            $loyaltyMsg .= ' Applied station coupon: ' . number_format((float)$row['coupon_redemption']['discount_mmk'], 0) . ' MMK (one-use coupon).';
        }

        respond([
            'success' => true,
            'message' => 'Booking confirmed. The fill was saved to usage history and removed from active bookings.' . $loyaltyMsg,
            'booking' => $row,
            'loyalty' => $row['loyalty'] ?? null,
        ], $ajax, 200, 'verify_qr.php');
    }

    $rawBookingCode = isset($_POST['booking_code']) ? trim((string)$_POST['booking_code']) : '';
    $qrPayload = isset($_POST['qr_payload']) ? trim((string)$_POST['qr_payload']) : '';
    $bookingCode = normalize_booking_reference($qrPayload !== '' ? $qrPayload : $rawBookingCode);
}

function respond(array $response, bool $ajax, int $status = 200, string $redirect = 'verify_qr.php'): void
{
    http_response_code($status);
    if ($ajax) {
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['verify_flash'] = $response;
    header('Location: ' . $redirect);
    exit;
}


function normalize_booking_reference(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $decoded = json_decode($value, true);
    if (is_array($decoded)) {
        foreach (['booking_code', 'qr_refrence_code', 'qr_reference_code', 'code'] as $key) {
            if (!empty($decoded[$key])) {
                return normalize_booking_reference((string)$decoded[$key]);
            }
        }
    }

    $parts = parse_url($value);
    if (is_array($parts) && !empty($parts['query'])) {
        parse_str($parts['query'], $query);
        foreach (['booking_code', 'qr_refrence_code', 'qr_reference_code', 'code', 'qr'] as $key) {
            if (!empty($query[$key])) {
                return normalize_booking_reference((string)$query[$key]);
            }
        }
    }

    if (strpos($value, '|') !== false) {
        $value = explode('|', $value, 2)[0];
    }

    return strtoupper(preg_replace('/\s+/', '', trim($value)));
}

function find_usage_history_by_booking_id(PDO $pdo, int $bookingId): ?array
{
    if ($bookingId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT uh.usage_id, uh.booking_id, uh.user_id, uh.station_id, uh.oil_type_id, uh.qr_code, uh.qr_refrence_code,
                uh.status, uh.filled_liters, uh.filled_at, uh.expire_at, uh.booked_at AS created_at,
                s.branch_name AS station_name, o.oil_name
         FROM usage_history uh
         LEFT JOIN branches s ON s.branch_id = uh.station_id
         LEFT JOIN oil_types o ON o.oil_type_id = uh.oil_type_id
         WHERE uh.booking_id = :booking_id
         LIMIT 1'
    );
    $stmt->execute([':booking_id' => $bookingId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ? normalize_usage_history_booking($row) : null;
}

function find_usage_history_by_code(PDO $pdo, string $bookingCode, string $normalized): ?array
{
    $stmt = $pdo->prepare(
        'SELECT uh.usage_id, uh.booking_id, uh.user_id, uh.station_id, uh.oil_type_id, uh.qr_code, uh.qr_refrence_code,
                uh.status, uh.filled_liters, uh.filled_at, uh.expire_at, uh.booked_at AS created_at,
                s.branch_name AS station_name, o.oil_name
         FROM usage_history uh
         LEFT JOIN branches s ON s.branch_id = uh.station_id
         LEFT JOIN oil_types o ON o.oil_type_id = uh.oil_type_id
         WHERE uh.qr_refrence_code = :code_exact
            OR uh.qr_refrence_code LIKE :code_prefix
            OR uh.qr_refrence_code = :payload_a
            OR uh.qr_code = :code_b
            OR uh.qr_code = :payload_b
         ORDER BY uh.filled_at DESC, uh.usage_id DESC
         LIMIT 1'
    );
    $stmt->execute([
        ':code_exact' => $bookingCode,
        ':code_prefix' => $bookingCode . '|%',
        ':payload_a' => $normalized,
        ':code_b' => $bookingCode,
        ':payload_b' => $normalized,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ? normalize_usage_history_booking($row) : null;
}

function normalize_usage_history_booking(array $row): array
{
    $row['filled_gas'] = (float)($row['filled_liters'] ?? 0);
    $row['time_of_filled'] = $row['filled_at'] ?? null;
    $row['status'] = $row['status'] ?? 'filled';
    return $row;
}

if ($bookingCode === '') {
    respond(['success' => false, 'message' => 'No booking code was supplied.'], $ajax, 422);
}

$normalized = isset($_POST['qr_payload']) ? trim((string)$_POST['qr_payload']) : $bookingCode;

try {
    $stmt = $pdo->prepare(
        'SELECT b.booking_id, b.user_id, b.station_id, b.oil_type_id, b.qr_code, b.qr_refrence_code,
                b.status, b.filled_gas, b.time_of_filled, b.expire_at, b.created_at,
                s.branch_name AS station_name, o.oil_name
         FROM booking b
         LEFT JOIN branches s ON s.branch_id = b.station_id
         LEFT JOIN oil_types o ON o.oil_type_id = b.oil_type_id
         WHERE b.qr_refrence_code = :code_exact
            OR b.qr_refrence_code LIKE :code_prefix
            OR b.qr_refrence_code = :payload_a
            OR b.qr_code = :code_b
            OR b.qr_code = :payload_b
         ORDER BY CASE
            WHEN b.status = :active_status AND b.filled_gas = 0 AND (b.expire_at IS NULL OR b.expire_at > NOW()) THEN 1
            ELSE 0
         END DESC, b.booking_id DESC
         LIMIT 1'
    );
    $stmt->execute([
        ':code_exact' => $bookingCode,
        ':code_prefix' => $bookingCode . '|%',
        ':payload_a' => $normalized,
        ':code_b' => $bookingCode,
        ':payload_b' => $normalized,
        ':active_status' => 'notfilled',
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        if (map_pump_usage_history_schema_ready($pdo)) {
            $historyRow = find_usage_history_by_code($pdo, $bookingCode, $normalized);
            if ($historyRow && $staffCanAccessBranch((int) $_SESSION['user_id'], (int) $historyRow['station_id'])) {
                respond([
                    'success' => false,
                    'message' => 'This QR code was already filled and saved in usage history.',
                    'booking' => $historyRow,
                ], $ajax, 200);
            }
        }
        respond(['success' => false, 'message' => 'Booking code not found in the database.'], $ajax, 404);
    }

        if (!$staffCanAccessBranch((int) $_SESSION['user_id'], (int) $row['station_id'])) {
        respond([
            'success' => false,
            'message' => 'This booking belongs to another station.',
            'booking' => $row,
        ], $ajax, 403);
    }

    $closedStatuses = ['filled', 'completed', 'cancelled', 'expired'];
    if (in_array(strtolower((string)$row['status']), $closedStatuses, true)) {
        respond([
            'success' => false,
            'message' => 'This booking has already been used or is no longer valid.',
            'booking' => $row,
        ], $ajax);
    }

    if (!empty($row['expire_at']) && strtotime((string)$row['expire_at']) < time()) {
        $expireUpdate = $pdo->prepare("UPDATE booking SET status = 'expired' WHERE booking_id = :booking_id AND status IN ('notfilled','waiting','serving') AND expire_at <= NOW()");
        $expireUpdate->execute([':booking_id' => $row['booking_id']]);
        $row['status'] = 'expired';
        respond([
            'success' => false,
            'message' => 'This booking pass has expired.',
            'booking' => $row,
        ], $ajax);
    }

    $row['status'] = $row['status'];
    $row['filled_gas'] = (float)($row['filled_gas'] ?? 0);
    $row['time_of_filled'] = $row['time_of_filled'];

    respond([
        'success' => true,
        'message' => 'Booking found. Enter the liters and confirm fill.',
        'booking' => $row,
    ], $ajax);
} catch (Throwable $e) {
    if ($ajax) {
        respond(['success' => false, 'message' => 'Server error while verifying the booking. Please try again.'], true, 500);
    }
    respond(['success' => false, 'message' => 'Server error while verifying the booking. Please try again.'], false, 500, 'verify_qr.php');
}
