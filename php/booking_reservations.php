<?php
require_once __DIR__ . '/booking_rules.php';

function map_pump_booking_schema_ready(PDO $pdo): bool
{
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'booking_reservations'");
        return (int)$stmt->fetchColumn() === 1;
    } catch (Throwable $e) {
        error_log('Booking reservation migration is unavailable: ' . $e->getMessage());
        return false;
    }
}

function map_pump_booking_reserved_liters(PDO $pdo, int $stationId, int $oilTypeId, ?int $exceptBookingId = null, bool $forUpdate = false): float
{
    $statuses = map_pump_booking_active_statuses();
    $params = array_merge([$stationId, $oilTypeId], $statuses);
    $exceptSql = '';
    if ($exceptBookingId !== null && $exceptBookingId > 0) {
        $exceptSql = ' AND b.booking_id <> ?';
        $params[] = $exceptBookingId;
    }
    // Use a current locking read when called after locking the tank. An
    // aggregate SELECT could still read from an older RR snapshot on MySQL.
    $lockSql = $forUpdate ? ' FOR UPDATE' : '';
    $stmt = $pdo->prepare("SELECT r.requested_liters
        FROM booking_reservations r INNER JOIN booking b ON b.booking_id = r.booking_id
        WHERE r.station_id = ? AND r.oil_type_id = ? AND b.status IN (" . implode(',', array_fill(0, count($statuses), '?')) . ")
          AND b.expire_at > NOW(){$exceptSql}{$lockSql}");
    $stmt->execute($params);
    $reservedLiters = 0.0;
    while (($liters = $stmt->fetchColumn()) !== false) {
        $reservedLiters += (float)$liters;
    }
    return $reservedLiters;
}

function map_pump_booking_available_liters(PDO $pdo, int $stationId, int $oilTypeId, float $physicalLiters): float
{
    return max(0.0, $physicalLiters - map_pump_booking_reserved_liters($pdo, $stationId, $oilTypeId));
}

function map_pump_requested_liters_from_legacy_payload(string $payload): float
{
    if (preg_match('/\|([0-9]+(?:\.[0-9]+)?)L$/i', $payload, $m) !== 1) {
        return 0.0;
    }
    return (float)$m[1];
}

function map_pump_issue_shortfall_coupon(PDO $pdo, int $bookingId, float $actualLiters): ?array
{
    $stmt = $pdo->prepare("SELECT b.user_id, b.station_id, r.requested_liters, r.price_per_litre,
                                  sbs.headquarter_id
        FROM booking b
        INNER JOIN booking_reservations r ON r.booking_id = b.booking_id
        INNER JOIN station_business_scope sbs ON sbs.station_id = b.station_id
        WHERE b.booking_id = ? LIMIT 1");
    $stmt->execute([$bookingId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || (float)$row['price_per_litre'] <= 0 || (int)$row['headquarter_id'] <= 0) {
        throw new RuntimeException('Cannot issue a station coupon without its booking-time price and company owner.');
    }
    $requested = (float)$row['requested_liters'];
    $shortfall = max(0.0, $requested - max(0.0, $actualLiters));
    if ($shortfall <= 0) return null;
    $amount = min($requested * (float)$row['price_per_litre'], $shortfall * (float)$row['price_per_litre']);
    $insert = $pdo->prepare("INSERT INTO station_shortfall_coupons
        (booking_id, user_id, issuing_station_id, headquarter_id, face_value_mmk, issued_at, expires_at)
        VALUES (?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY))");
    $insert->execute([$bookingId, (int)$row['user_id'], (int)$row['station_id'], (int)$row['headquarter_id'], round($amount, 2)]);
    return ['coupon_id' => (int)$pdo->lastInsertId(), 'face_value_mmk' => round($amount, 2)];
}

/** Apply the earliest same-company coupon once; any unused remainder is forfeited. */
function map_pump_redeem_company_coupon(PDO $pdo, int $userId, int $stationId, int $usageId, float $purchaseBalance): array
{
    $result = ['coupon_id' => null, 'discount_mmk' => 0.0];
    if ($userId <= 0 || $stationId <= 0 || $usageId <= 0 || $purchaseBalance <= 0) return $result;
    $companyStmt = $pdo->prepare('SELECT headquarter_id FROM station_business_scope WHERE station_id = ? LIMIT 1');
    $companyStmt->execute([$stationId]);
    $companyId = (int)($companyStmt->fetchColumn() ?: 0);
    if ($companyId <= 0) return $result;
    $couponStmt = $pdo->prepare("SELECT coupon_id, face_value_mmk FROM station_shortfall_coupons
        WHERE user_id = ? AND headquarter_id = ? AND status = 'available' AND expires_at > NOW()
        ORDER BY expires_at ASC, coupon_id ASC LIMIT 1 FOR UPDATE");
    $couponStmt->execute([$userId, $companyId]);
    $coupon = $couponStmt->fetch(PDO::FETCH_ASSOC);
    if (!$coupon) return $result;
    $discount = min((float)$coupon['face_value_mmk'], $purchaseBalance);
    $mark = $pdo->prepare("UPDATE station_shortfall_coupons SET status = 'redeemed', redeemed_at = NOW(), redeemed_station_id = ?, redemption_value_mmk = ? WHERE coupon_id = ? AND status = 'available'");
    $mark->execute([$stationId, round($discount, 2), (int)$coupon['coupon_id']]);
    if ($mark->rowCount() !== 1) return $result;
    $insert = $pdo->prepare('INSERT INTO station_coupon_redemptions (coupon_id, usage_id, station_id, applied_mmk) VALUES (?, ?, ?, ?)');
    $insert->execute([(int)$coupon['coupon_id'], $usageId, $stationId, round($discount, 2)]);
    return ['coupon_id' => (int)$coupon['coupon_id'], 'discount_mmk' => round($discount, 2)];
}
