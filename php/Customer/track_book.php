<?php
include 'share_header.php';
require_once __DIR__ . '/../business_scope.php';
require_once __DIR__ . '/../booking_rules.php';
require_once __DIR__ . '/../booking_reservations.php';
require_once __DIR__ . '/../loyalty_points.php';
require_once __DIR__ . '/../feedback_schema.php';
require_once __DIR__ . '/../usage_history_schema.php';
$billingReady = loyalty_countermeasures_schema_ready($pdo);
$billingMonth = loyalty_current_year_month();
$availableOilQuota = max(0.0, (float) ($_SESSION['oil_remaining'] ?? (((float) ($_SESSION['oil_limit'] ?? 35.0)) - ((float) ($_SESSION['oil_used'] ?? 0.0)))));
$loyaltyBalance = loyalty_get_available_balance($pdo, (int) ($_SESSION['user_id'] ?? 0));
$loyaltyTierStatus = loyalty_customer_tier_status($pdo, (int) ($_SESSION['user_id'] ?? 0), $loyaltyBalance);
$loyaltyTierCode = $loyaltyTierStatus['tier_code'];
$loyaltyTierLabel = loyalty_tier_label($loyaltyTierCode);
$loyaltyRedemptionOptions = loyalty_redemption_options($pdo);

function map_pump_station_coordinates(string $location): ?array
{
    if (!preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/', $location, $matches)) {
        return null;
    }

    $latitude = (float) $matches[1];
    $longitude = (float) $matches[2];

    if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
        return null;
    }

    return [$latitude, $longitude];
}

$customerStationsData = [];
try {
    $monthlyFeePaidSql = $billingReady
        ? "EXISTS (
                SELECT 1 FROM company_loyalty_station_fees fee
                INNER JOIN station_business_scope sbs ON sbs.headquarter_id = fee.headquarter_id
                WHERE sbs.station_id = b.branch_id AND fee.station_id = b.branch_id AND fee.year_month = ?
            )"
        : '0';
    $stationStmt = $pdo->prepare(
        "SELECT b.branch_id AS station_id, b.branch_name AS station_name,
                CASE WHEN b.latitude IS NOT NULL AND b.longitude IS NOT NULL
                    THEN CONCAT(b.latitude, ', ', b.longitude) ELSE '' END AS location,
                b.address AS station_address, b.status, {$monthlyFeePaidSql} AS monthly_fee_paid,
                CASE WHEN b.is_24hr = 1
                           OR b.opening_time IS NULL
                           OR b.closing_time IS NULL
                           OR (b.opening_time <= b.closing_time AND CURRENT_TIME() BETWEEN b.opening_time AND b.closing_time)
                           OR (b.opening_time > b.closing_time AND (CURRENT_TIME() >= b.opening_time OR CURRENT_TIME() <= b.closing_time))
                     THEN 1 ELSE 0 END AS is_open,
                     GREATEST(0, t.current_l - COALESCE((
                         SELECT SUM(r.requested_liters)
                         FROM booking_reservations r
                         INNER JOIN booking active_b ON active_b.booking_id = r.booking_id
                         WHERE r.station_id = b.branch_id AND r.oil_type_id = ot.oil_type_id
                           AND active_b.status IN ('notfilled','waiting','serving')
                           AND active_b.expire_at > NOW()
                     ), 0)) AS fuel_stock_liters,
                            ot.oil_type_id, ot.oil_name, t.fuel_price AS price_per_litre,
                            t.is_enabled AS price_is_active
         FROM branches b
         LEFT JOIN tanks t ON t.branch_id = b.branch_id
            LEFT JOIN oil_types ot ON ot.oil_type_id = t.oil_type_id
         ORDER BY b.branch_id ASC, ot.oil_name ASC"
    );
    $stationStmt->execute($billingReady ? [$billingMonth] : []);
    $stationsById = [];
    foreach ($stationStmt->fetchAll(PDO::FETCH_ASSOC) as $stationRow) {
        $stationId = (int)($stationRow['station_id'] ?? 0);
        $location = trim((string)($stationRow['location'] ?? ''));
        $coords = map_pump_station_coordinates($location);
        if ($coords === null) {
            continue;
        }

        if (!isset($stationsById[$stationId])) {
            $stationStatus = trim((string)($stationRow['status'] ?? 'Active'));
            if ($stationStatus === '') {
                $stationStatus = 'Active';
            }
            $feePaid = $billingReady
                ? hq_billing_station_is_paid($pdo, $stationId)
                : ((int) ($stationRow['monthly_fee_paid'] ?? 0) === 1);
            $isStationActive = strcasecmp($stationStatus, 'Active') === 0 && $feePaid;
            if (!$feePaid && strcasecmp($stationStatus, 'Active') === 0) {
                $stationStatus = 'Paused — monthly fee unpaid';
            }
            $isOpen = (int)($stationRow['is_open'] ?? 0) === 1;
            $stationsById[$stationId] = [
                'id' => $stationId,
                'name' => (string)($stationRow['station_name'] ?? ('Station #' . $stationId)),
                'address' => (string)($stationRow['station_address'] ?: $location ?: 'Address not set'),
                'lat' => $coords[0],
                'lng' => $coords[1],
                'status' => $stationStatus,
                'isActive' => $isStationActive,
                'monthlyFeePaid' => $feePaid,
                'isOpen' => $isOpen,
                'fuels' => [],
                'isBookable' => false,
                'logo' => '../../images/Logo.png',
            ];
        }

        $oilTypeId = (int)($stationRow['oil_type_id'] ?? 0);
        if ($oilTypeId > 0) {
            $fuelAlreadyAdded = false;
            foreach ($stationsById[$stationId]['fuels'] as $existingFuel) {
                if ((int)($existingFuel['id'] ?? 0) === $oilTypeId) {
                    $fuelAlreadyAdded = true;
                    break;
                }
            }
            if (!$fuelAlreadyAdded) {
                $stationsById[$stationId]['fuels'][] = [
                    'id' => $oilTypeId,
                    'name' => (string)($stationRow['oil_name'] ?: 'Fuel'),
                    'price' => ((int)($stationRow['price_is_active'] ?? 0) === 1 && (float)($stationRow['price_per_litre'] ?? 0) > 0) ? (float)$stationRow['price_per_litre'] : null,
                    'stock' => (float)($stationRow['fuel_stock_liters'] ?? 0),
                    'available' => ((int)($stationRow['price_is_active'] ?? 0) === 1 && (float)($stationRow['price_per_litre'] ?? 0) > 0 && (float)($stationRow['fuel_stock_liters'] ?? 0) > 0),
                ];
            }
        }
    }
    foreach ($stationsById as &$station) {
        $hasBookableFuel = false;
        foreach ($station['fuels'] as $fuel) {
            if (!empty($fuel['available'])) {
                $hasBookableFuel = true;
                break;
            }
        }
        $station['isBookable'] = $station['isActive'] && $station['isOpen'] && $hasBookableFuel;
    }
    unset($station);

    try {
        if ($stationsById && map_pump_feedback_schema_ready($pdo)) {
            $reviewPlaceholders = implode(',', array_fill(0, count($stationsById), '?'));
            $reviewStmt = $pdo->prepare(
                "SELECT f.station_id, f.rating,
                        CASE WHEN LOCATE(CHAR(10), f.feedback) > 0
                             THEN TRIM(SUBSTRING(f.feedback, LOCATE(CHAR(10), f.feedback) + 1))
                             ELSE TRIM(f.feedback) END AS review_text,
                        u.user_name AS customer_name, f.created_at
                                 FROM feedback f
                                 LEFT JOIN users u ON u.user_id = f.user_id
                 WHERE f.station_id IN ({$reviewPlaceholders})
                 ORDER BY f.station_id ASC, f.created_at DESC, f.feedback_id DESC"
            );
            $reviewStmt->execute(array_keys($stationsById));
            foreach ($reviewStmt->fetchAll(PDO::FETCH_ASSOC) as $reviewRow) {
                $stationId = (int) $reviewRow['station_id'];
                if (count($stationsById[$stationId]['reviews'] ?? []) >= 12) {
                    continue;
                }

                $reviewText = trim((string) ($reviewRow['review_text'] ?? ''));
                if ($reviewText === 'Customer submitted a rating without a written comment.') {
                    $reviewText = '';
                }
                if ($reviewText === '' && empty($reviewRow['rating'])) {
                    continue;
                }
                $stationsById[$stationId]['reviews'][] = [
                    'customer' => (string) ($reviewRow['customer_name'] ?? 'Customer'),
                    'rating' => (int) ($reviewRow['rating'] ?? 0),
                    'text' => $reviewText,
                    'createdAt' => (string) ($reviewRow['created_at'] ?? ''),
                ];
            }
        }
    } catch (Throwable $e) {
        error_log('Failed to load customer station reviews: ' . $e->getMessage());
    }

    $customerStationsData = array_values($stationsById);
} catch (Throwable $e) {
    error_log('Failed to load customer stations: ' . $e->getMessage());
}

$customerCarsData = [];
try {
    if (customer_car_schema_ready($pdo)) {
        $selectedCarId = customer_active_car_id($pdo, (int)$_SESSION['user_id']);
        $carStmt = $pdo->prepare(
            "SELECT c.car_license_id, c.car_plate_number, c.vehicle_name, c.vehicle_type,
                    c.license_expire_date, uc.is_active
             FROM user_car uc
             INNER JOIN car_license c ON c.car_license_id = uc.car_id
             WHERE uc.user_id = ?
               AND LOWER(COALESCE(c.status, 'pending')) <> 'expired'
               AND (c.license_expire_date IS NULL OR c.license_expire_date >= CURDATE())
             ORDER BY (c.car_license_id = ?) DESC, uc.is_active DESC, c.updated_at DESC"
        );
        $carStmt->execute([(int)$_SESSION['user_id'], $selectedCarId]);
        foreach ($carStmt->fetchAll(PDO::FETCH_ASSOC) as $carRow) {
            $carId = (int)$carRow['car_license_id'];
            $carQuota = customer_fuel_quota_for_car($pdo, (int)$_SESSION['user_id'], $carId);
            $customerCarsData[] = [
                'id' => $carId,
                'plate' => (string)$carRow['car_plate_number'],
                'name' => trim((string)($carRow['vehicle_name'] ?? '') . ' ' . (string)($carRow['vehicle_type'] ?? '')),
                'quota' => (float)$carQuota['remaining'],
                'selected' => $carId === $selectedCarId,
            ];
        }
    }
} catch (Throwable $e) {
    error_log('Failed to load customer booking cars: ' . $e->getMessage());
}

$activeBooking = null;
try {
    $activeStatuses = map_pump_booking_active_statuses();
    $statusPlaceholders = [];
    $statusParams = [':user_id' => (int) $_SESSION['user_id']];
    foreach ($activeStatuses as $index => $status) {
        $placeholder = ':status_' . $index;
        $statusPlaceholders[] = $placeholder;
        $statusParams[$placeholder] = $status;
    }

    if ($statusPlaceholders) {
        $bookingStmt = $pdo->prepare(
            'SELECT bk.booking_id, bk.station_id, bk.oil_type_id, bk.qr_code, bk.qr_refrence_code,
                    bk.status, bk.expire_at, bk.created_at, r.requested_liters,
                    po.offered_liters, po.status AS partial_offer_status,
                    b.branch_name AS station_name, b.address AS station_address,
                    ot.oil_name
             FROM booking bk
             LEFT JOIN booking_reservations r ON r.booking_id = bk.booking_id
             LEFT JOIN booking_partial_offers po ON po.booking_id = bk.booking_id
             LEFT JOIN branches b ON b.branch_id = bk.station_id
             LEFT JOIN oil_types ot ON ot.oil_type_id = bk.oil_type_id
             WHERE bk.user_id = :user_id
               AND bk.status IN (' . implode(', ', $statusPlaceholders) . ')
               AND (bk.expire_at IS NULL OR bk.expire_at > NOW())
             ORDER BY bk.created_at DESC, bk.booking_id DESC
             LIMIT 1'
        );
        $bookingStmt->execute($statusParams);
        $activeBooking = $bookingStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($activeBooking) {
            $activeBooking['quantity_liters'] = (float)($activeBooking['requested_liters'] ?? 0);
        }
    }
} catch (Throwable $e) {
    error_log('Failed to load customer active booking: ' . $e->getMessage());
}
$hasActiveBooking = $activeBooking !== null;
$availableStationCoupons = [];
try {
    $couponStmt = $pdo->prepare("SELECT c.coupon_id, c.face_value_mmk, c.expires_at, h.company_name
        FROM station_shortfall_coupons c
        LEFT JOIN headquarter h ON h.headquarter_id = c.headquarter_id
        WHERE c.user_id = ? AND c.status = 'available' AND c.expires_at > NOW()
        ORDER BY c.expires_at ASC, c.coupon_id ASC");
    $couponStmt->execute([(int)($_SESSION['user_id'] ?? 0)]);
    $availableStationCoupons = $couponStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Could not load station coupons: ' . $e->getMessage());
}
if (empty($_SESSION['booking_csrf_token'])) {
    $_SESSION['booking_csrf_token'] = bin2hex(random_bytes(32));
}
$bookingCsrfToken = (string) $_SESSION['booking_csrf_token'];
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">

<section id="current-booking-card" class="dashboard-panel-card customer-map-launch-card customer-booking-status-card mt-3<?php echo $hasActiveBooking ? '' : ' d-none'; ?>">
    <div class="row align-items-center g-4">
        <div class="col-12 col-md-auto text-center">
            <div class="customer-qr-wrap customer-map-qr mx-auto mb-2">
                <img id="current-booking-qr" src="<?php echo $hasActiveBooking ? htmlspecialchars('https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=' . rawurlencode((string) $activeBooking['qr_code']), ENT_QUOTES, 'UTF-8') : ''; ?>" alt="Current booking QR code">
            </div>
            <div id="current-booking-code" class="token-no text-sky-400" style="font-size:16px;"><?php echo $hasActiveBooking ? htmlspecialchars((string) $activeBooking['qr_refrence_code']) : ''; ?></div>
        </div>
        <div class="col">
            <span class="status-pill active mb-2">Booking active</span>
            <h3 class="text-white mb-3">Your current booking</h3>
            <div class="row g-3 small">
                <div class="col-12 col-sm-6"><span class="text-secondary d-block">Station</span><strong id="current-booking-station" class="text-white"><?php echo $hasActiveBooking ? htmlspecialchars((string) ($activeBooking['station_name'] ?? 'Station')) : ''; ?></strong></div>
                <div class="col-12 col-sm-6"><span class="text-secondary d-block">Address</span><strong id="current-booking-address" class="text-white"><?php echo $hasActiveBooking ? htmlspecialchars((string) ($activeBooking['station_address'] ?: 'Address not set')) : ''; ?></strong></div>
                <div class="col-12 col-sm-6"><span class="text-secondary d-block">Fuel</span><strong id="current-booking-fuel" class="text-white"><?php echo $hasActiveBooking ? htmlspecialchars((string) ($activeBooking['oil_name'] ?? '')) : ''; ?></strong></div>
                <div class="col-12 col-sm-6"><span class="text-secondary d-block">Amount</span><strong id="current-booking-amount" class="text-white"><?php echo $hasActiveBooking && isset($activeBooking['quantity_liters']) ? htmlspecialchars(rtrim(rtrim(number_format((float) $activeBooking['quantity_liters'], 1, '.', ''), '0'), '.') . ' L') : ''; ?></strong></div>
                <div class="col-12"><span class="text-secondary d-block">Expires</span><strong id="current-booking-expiry" class="text-white"><?php echo $hasActiveBooking && !empty($activeBooking['expire_at']) ? htmlspecialchars((string) $activeBooking['expire_at']) : 'When confirmed by station staff'; ?></strong></div>
            </div>
            <p class="text-secondary small mt-3 mb-0">Keep this booking QR available and show it to staff at the selected station.</p>
            <?php if ($hasActiveBooking && ($activeBooking['partial_offer_status'] ?? '') === 'pending'): ?>
                <div class="alert alert-warning mt-3 mb-0">
                    <strong>Station offered a partial fill:</strong> <?= htmlspecialchars(rtrim(rtrim(number_format((float)$activeBooking['offered_liters'], 2, '.', ''), '0'), '.') . ' L', ENT_QUOTES, 'UTF-8') ?>.
                    Accept only if you agree to this amount. Accepting records this amount and issues the station-funded coupon for the unfilled liters.
                    <div class="d-flex gap-2 mt-2">
                        <button type="button" class="btn btn-success btn-sm" onclick="respondToPartialOffer('accept')">Accept partial fill</button>
                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="respondToPartialOffer('decline')">Decline and close booking</button>
                    </div>
                    <div id="partial-offer-message" class="small mt-2" role="status"></div>
                </div>
            <?php endif; ?>
            <div class="d-flex flex-wrap gap-2 mt-3">
                <button type="button" class="btn btn-outline-info btn-sm px-3 py-2 fw-bold" onclick="downloadBookingDetails()" title="Save a JPEG receipt with booking details and QR code"><i class="bi bi-download me-1"></i>Download receipt</button>
                <button type="button" class="btn btn-outline-danger btn-sm px-3 py-2 fw-bold" onclick="openCancellationDialog()"><i class="bi bi-x-circle me-1"></i>Cancel booking</button>
            </div>
        </div>
    </div>
</section>

<?php if ($availableStationCoupons): ?>
<section class="dashboard-panel-card mt-3" aria-live="polite">
    <h3 class="text-white mb-3">Available station coupons</h3>
    <div class="d-grid gap-2">
        <?php foreach ($availableStationCoupons as $stationCoupon): ?>
            <div class="alert alert-success mb-0">
                <strong><?= htmlspecialchars(number_format((float)$stationCoupon['face_value_mmk'], 0) . ' MMK', ENT_QUOTES, 'UTF-8') ?></strong>
                for <?= htmlspecialchars((string)($stationCoupon['company_name'] ?? 'the issuing fuel company'), ENT_QUOTES, 'UTF-8') ?>,
                until <?= htmlspecialchars((string)$stationCoupon['expires_at'], ENT_QUOTES, 'UTF-8') ?>.
                Redeem once on a later fill at that company; any unused amount is forfeited.
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<dialog id="booking-cancellation-dialog" class="booking-cancellation-dialog" aria-labelledby="booking-cancellation-title">
    <form id="booking-cancellation-form">
        <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
            <div>
                <h3 id="booking-cancellation-title" class="text-white mb-1">Why are you cancelling?</h3>
                <p class="text-secondary small mb-0">Choose 1 to 3 reasons so we can improve the booking experience.</p>
            </div>
            <button type="button" class="btn-close btn-close-white" onclick="closeCancellationDialog()" aria-label="Close"></button>
        </div>
        <div id="cancellation-reasons" class="cancellation-reasons">
            <label><input type="checkbox" name="cancellation_reason[]" value="Change of plans"><span>Change of plans</span></label>
            <label><input type="checkbox" name="cancellation_reason[]" value="Found a closer station"><span>Found a closer station</span></label>
            <label><input type="checkbox" name="cancellation_reason[]" value="Long wait or queue"><span>Long wait or queue</span></label>
            <label><input type="checkbox" name="cancellation_reason[]" value="Station closed or unavailable"><span>Station closed or unavailable</span></label>
            <label><input type="checkbox" name="cancellation_reason[]" value="Fuel type or stock unavailable"><span>Fuel type or stock unavailable</span></label>
            <label><input type="checkbox" name="cancellation_reason[]" value="Booking details were incorrect"><span>Booking details were incorrect</span></label>
            <label><input type="checkbox" name="cancellation_reason[]" value="Vehicle problem"><span>Vehicle problem</span></label>
            <label><input type="checkbox" name="cancellation_reason[]" value="Payment or budget issue"><span>Payment or budget issue</span></label>
            <label><input type="checkbox" name="cancellation_reason[]" value="Booked by mistake"><span>Booked by mistake</span></label>
            <label><input type="checkbox" name="cancellation_reason[]" value="Emergency"><span>Emergency</span></label>
            <label><input id="cancellation-other-checkbox" type="checkbox" name="cancellation_reason[]" value="Other"><span>Other</span></label>
        </div>
        <label class="form-label text-secondary small mt-3" for="cancellation-details">Tell us what happened <span id="cancellation-details-required" class="d-none">(required for Other)</span></label>
        <textarea id="cancellation-details" class="form-control custom-form-field" rows="3" maxlength="1000" placeholder="Optional details; required when Other is selected."></textarea>
        <div class="d-flex justify-content-between align-items-center gap-2 mt-3">
            <small id="cancellation-selection-count" class="text-secondary">0 of 3 selected</small>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="closeCancellationDialog()">Keep booking</button>
                <button id="submit-cancellation-btn" type="submit" class="btn btn-danger btn-sm fw-bold">Cancel booking</button>
            </div>
        </div>
        <p id="cancellation-error" class="text-danger small mb-0 mt-2" role="alert"></p>
    </form>
</dialog>

<section id="station-launch-card" class="dashboard-panel-card customer-map-launch-card<?php echo $hasActiveBooking ? ' d-none' : ''; ?>">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div>
            <h3 class="text-white mb-1">Find a station and book your fuel</h3>
            <p class="text-secondary mb-0" style="font-size:13px;">Choose a nearby station to create your booking pass.</p>
        </div>
        <button id="locate-btn" class="btn btn-info px-4 py-3 fw-bold rounded-4" type="button" onclick="openMapExperience()" style="font-size:13px; font-family:'Space Grotesk';">
            Choose a station on the map <i class="bi bi-arrow-right ms-2"></i>
        </button>
    </div>
</section>

<section id="quick-mapping-card" class="dashboard-panel-card customer-map-launch-card mt-3<?php echo $hasActiveBooking ? ' d-none' : ''; ?>">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div>
            <h3 class="text-white mb-1">Quick Mapping</h3>
            <p class="text-secondary mb-0" style="font-size:13px;">Automatically choose nearest station on the map</p>
        </div>
        <button id="auto-connect-btn" class="btn btn-info px-4 py-3 fw-bold rounded-4" type="button" onclick="autoConnectNearestStation()" style="font-size:13px; font-family:'Space Grotesk';">
            Auto connect <i class="bi bi-geo-alt-fill ms-2"></i>
        </button>
    </div>
</section>

<section id="top5-stations-card" class="dashboard-panel-card customer-map-launch-card mt-3 top5-stations-panel<?php echo $hasActiveBooking ? ' d-none' : ''; ?>">
    <h3 class="text-white mb-2">Top 5 nearest stations</h3>
    <div id="top5-stations-list" class="top5-stations-list">
        <div class="text-secondary" style="font-size:13px;">Locating nearest stations…</div>
    </div>
</section>

<style>
.top5-stations-panel {
    overflow: hidden;
}
.customer-map-card-back {
    color: var(--customer-muted);
    font-size: 12px;
    font-weight: 700;
    text-decoration: none;
}
.customer-map-card-back:hover,
.customer-map-card-back:focus-visible {
    color: var(--customer-neon-cyan);
}
.top5-stations-list {
    display: flex;
    flex-direction: column;
    gap: 0;
}
.top5-station-row {
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
    padding: 10px 4px;
    border: 0;
    border-bottom: 1px solid rgba(255,255,255,.08);
    background: transparent;
    color: inherit;
    text-align: left;
    cursor: pointer;
    transition: background .15s;
}
.top5-station-row:last-child {
    border-bottom: 0;
    padding-bottom: 2px;
}
.top5-station-row:first-of-type {
    padding-top: 4px;
}
.top5-station-row:hover {
    background: rgba(0, 244, 255, .07);
}
.top5-station-row .row-rank {
    flex: 0 0 22px;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 700;
    color: #00a8ff;
    background: rgba(0, 168, 255, .12);
}
.top5-station-row img {
    flex: 0 0 28px;
    width: 28px;
    height: 28px;
    object-fit: contain;
    border-radius: 8px;
    background: rgba(255,255,255,.06);
    padding: 3px;
}
.top5-station-row .row-body {
    flex: 1 1 auto;
    min-width: 0;
}
.top5-station-row .row-name {
    display: block;
    color: #fff;
    font-size: 13px;
    font-weight: 600;
    line-height: 1.3;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.top5-station-row .row-address {
    display: block;
    color: rgba(255,255,255,.45);
    font-size: 11px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.top5-station-row .row-dist {
    flex: 0 0 auto;
    color: #00a8ff;
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
}
.booking-cancellation-dialog {
    width: min(520px, calc(100% - 28px));
    max-height: min(88vh, 720px);
    padding: 22px;
    border: 1px solid rgba(255,255,255,.14);
    border-radius: 8px;
    color: #fff;
    background: #101a27;
}
.booking-cancellation-dialog::backdrop { background: rgba(0,0,0,.72); }
.cancellation-reasons { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
.cancellation-reasons label {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    min-width: 0;
    padding: 9px;
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 6px;
    color: #dce5ef;
    font-size: 12px;
    cursor: pointer;
}
.cancellation-reasons input { flex: 0 0 auto; margin-top: 2px; accent-color: #0dcaf0; }
.cancellation-reasons label:has(input:checked) { border-color: #0dcaf0; background: rgba(13,202,240,.09); }
@media (max-width: 480px) {
    .booking-cancellation-dialog { padding: 16px; }
    .cancellation-reasons { grid-template-columns: 1fr; }
}

/* Light mode: station name / address must stay readable on pale glass cards */
body.customer-light-mode .top5-station-row {
    border-bottom-color: rgba(11, 94, 215, 0.12);
    color: #102033;
}
body.customer-light-mode .top5-station-row:hover {
    background: rgba(11, 94, 215, 0.07);
}
body.customer-light-mode .top5-station-row .row-rank {
    color: #0b5ed7;
    background: rgba(11, 94, 215, 0.1);
}
body.customer-light-mode .top5-station-row img {
    background: rgba(11, 94, 215, 0.06);
}
body.customer-light-mode .top5-station-row .row-name {
    color: #102033;
}
body.customer-light-mode .top5-station-row .row-address {
    color: rgba(51, 65, 85, 0.72);
}
body.customer-light-mode .top5-station-row .row-dist {
    color: #0b5ed7;
}

.customer-map-back-btn {
    position: absolute;
    z-index: 900;
    top: 16px;
    right: 16px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    border-radius: 999px;
    font-weight: 700;
    font-size: 13px;
    padding: 8px 14px;
    color: #e8f3ff;
    background: rgba(4, 10, 20, 0.82);
    border: 1px solid rgba(13, 110, 253, 0.28);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.28);
    transition: background 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
}
.customer-map-back-btn:hover {
    background: rgba(13, 110, 253, 0.18);
    border-color: rgba(13, 110, 253, 0.45);
    color: #fff;
    box-shadow: 0 10px 24px rgba(13, 110, 253, 0.15);
}
body.customer-light-mode .customer-map-back-btn {
    color: #102033;
    background: rgba(255, 255, 255, 0.92);
    border-color: rgba(11, 94, 215, 0.2);
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.12);
}
body.customer-light-mode .customer-map-back-btn:hover {
    background: rgba(11, 94, 215, 0.1);
    border-color: rgba(11, 94, 215, 0.35);
    color: #0b5ed7;
}
.customer-map-recenter-btn {
    position: absolute;
    z-index: 900;
    right: 16px;
    bottom: 16px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    border-radius: 999px;
    font-weight: 700;
    font-size: 13px;
    padding: 8px 14px;
    color: #e8f3ff;
    background: rgba(4, 10, 20, 0.82);
    border: 1px solid rgba(13, 110, 253, 0.28);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.28);
    transition: background 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
}
.customer-map-recenter-btn:hover {
    background: rgba(13, 110, 253, 0.18);
    border-color: rgba(13, 110, 253, 0.45);
    color: #fff;
    box-shadow: 0 10px 24px rgba(13, 110, 253, 0.15);
}
.customer-station-marker {
    display: block;
    width: 18px;
    height: 18px;
    border: 3px solid #fff;
    border-radius: 50%;
    box-shadow: 0 1px 6px rgba(0, 0, 0, 0.45);
}
.station-review-summary {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 12px;
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 8px;
    background: rgba(255,255,255,.035);
}
.station-review-average { flex: 0 0 68px; text-align: center; }
.station-review-average strong { display: block; color: #fff; font-size: 24px; line-height: 1.1; }
.station-review-stars { color: #fbbf24; letter-spacing: 1px; white-space: nowrap; }
.station-review-caption { color: #9aa8b8; font-size: 10px; }
.station-review-distribution { display: grid; flex: 1; gap: 5px; }
.station-review-row { display: flex; align-items: center; gap: 7px; color: #dce5ef; font-size: 10px; }
.station-review-row-label { flex: 0 0 30px; }
.station-review-track { height: 5px; flex: 1; overflow: hidden; border-radius: 999px; background: rgba(255,255,255,.12); }
.station-review-fill { display: block; height: 100%; border-radius: inherit; background: #fbbf24; }
.station-review-percent { flex: 0 0 28px; color: #9aa8b8; text-align: right; }
.station-review-filters { display: flex; flex-wrap: wrap; gap: 6px; margin: 10px 0; }
.station-review-filter { padding: 5px 8px; border: 1px solid rgba(255,255,255,.12); border-radius: 6px; color: #dce5ef; background: rgba(255,255,255,.04); font-size: 10px; }
.station-review-filter.active { color: #0b1b2b; border-color: #fbbf24; background: #fbbf24; }
.station-review-filter:focus-visible { outline: 2px solid #0dcaf0; outline-offset: 2px; }
.customer-review-card { padding: 12px; border: 1px solid rgba(255,255,255,.12); border-radius: 8px; background: rgba(255,255,255,.025); }
.customer-review-card[hidden] { display: none !important; }
.customer-review-card p { color: #dce5ef; font-size: 12px; line-height: 1.5; white-space: pre-wrap; overflow-wrap: anywhere; }
body.customer-light-mode .station-review-summary,
body.customer-light-mode .customer-review-card { border-color: rgba(15,23,42,.12); background: rgba(255,255,255,.65); }
body.customer-light-mode .station-review-average strong,
body.customer-light-mode .station-review-row,
body.customer-light-mode .customer-review-card p { color: #102033; }
body.customer-light-mode .station-review-caption,
body.customer-light-mode .station-review-percent { color: #526174; }
.customer-station-marker.active { background: #198754; }
.customer-station-marker.inactive { background: #6c757d; }
.customer-station-tooltip { min-width: 160px; }
.customer-station-tooltip .station-tooltip-name { font-weight: 700; }
.customer-station-tooltip .station-tooltip-status { margin-top: 2px; font-weight: 600; }
.customer-station-tooltip .station-tooltip-fuels { margin-top: 4px; white-space: normal; }
body.customer-light-mode .customer-map-recenter-btn {
    color: #102033;
    background: rgba(255, 255, 255, 0.92);
    border-color: rgba(11, 94, 215, 0.2);
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.12);
}
body.customer-light-mode .customer-map-recenter-btn:hover {
    background: rgba(11, 94, 215, 0.1);
    border-color: rgba(11, 94, 215, 0.35);
    color: #0b5ed7;
}
@media (max-width: 575.98px) {
    .customer-map-back-btn,
    .customer-map-recenter-btn {
        padding: 7px 12px;
        font-size: 12px;
    }
    .customer-map-back-btn { top: 12px; right: 12px; }
    .customer-map-recenter-btn { right: 12px; bottom: 12px; }
}
</style>


<section id="map-experience" class="customer-map-experience d-none" aria-live="polite">

    <button id="map-recenter-btn" type="button" class="btn btn-sm customer-map-recenter-btn" onclick="recenterCustomerLocation()" aria-label="Recenter map on your location" title="Recenter on your location">
        <i class="bi bi-crosshair me-1"></i> My location
    </button>

    <button id="map-back-btn" type="button" class="btn btn-sm customer-map-back-btn" onclick="closeMapExperience()" aria-label="Back to station cards">
        <i class="bi bi-arrow-left me-1"></i> Back
    </button>

    <div id="station-map" class="customer-station-map"></div>

    <button id="show-station-card" type="button" class="btn btn-info customer-map-reopen d-none" onclick="showStationCard()">
        <i class="bi bi-card-text me-2"></i> View selected station
    </button>

    <section id="station-booking-card" class="dashboard-panel-card customer-map-station-card d-none">
        <button type="button" class="btn btn-outline-info btn-sm customer-card-hide-btn" onclick="hideStationCard()" aria-label="Hide station card">
            <i class="bi bi-chevron-down"></i><span>Hide card</span>
        </button>
        <button type="button" class="btn btn-link customer-map-card-back p-0 mb-2" onclick="closeMapExperience()">
            <i class="bi bi-arrow-left me-1"></i>Back to stations
        </button>

        <div id="station-detail-card">
            <div class="d-flex justify-content-between align-items-start gap-2 border-bottom border-secondary border-opacity-10 pb-3 mb-3">
                <div>
                    <span class="text-sky-400 text-uppercase" style="font-size:10px; font-weight:700; letter-spacing:.08em;">Selected station</span>
                    <h4 id="selected-station-name" class="text-white mt-1 mb-1">Station</h4>
                    <p id="selected-station-address" class="text-secondary mb-0" style="font-size:12px;"></p>
                </div>
                <div class="rounded-3 overflow-hidden border border-secondary border-opacity-25" style="width:96px; height:96px; background:#0c1724; flex-shrink:0;">
                    <img id="selected-station-image" src="../../images/Logo.png" alt="Station image" style="width:100%; height:100%; object-fit:cover;">
                </div>
            </div>

            <div id="station-detail-fuels" class="d-flex flex-column gap-2"></div>

            <button id="station-book-trigger" type="button" class="btn btn-info w-100 py-3 fw-bold rounded-4 mt-3" onclick="showBookingForm()">Book this station <i class="bi bi-qr-code ms-1"></i></button>
            <small id="station-booking-status" class="text-secondary d-block mt-2" role="status"></small>
            <button id="station-reviews-toggle" type="button" class="btn btn-outline-info w-100 mt-3" aria-expanded="false" onclick="toggleStationReviews()">
                <i class="bi bi-chat-square-text me-1"></i> Customer reviews (0)
            </button>
            <section id="station-reviews" class="d-none mt-3" aria-live="polite" aria-label="Customer reviews for this station">
                <h5 class="text-white mb-2">Customer reviews</h5>
                <div id="station-review-summary" class="station-review-summary mb-2"></div>
                <div id="station-review-filters" class="station-review-filters" role="group" aria-label="Filter reviews by rating"></div>
                <div id="station-reviews-list" class="d-flex flex-column gap-2"></div>
                <p id="station-review-empty" class="text-secondary small mb-0" hidden>No reviews match this rating.</p>
            </section>
        </div>

        <div id="station-booking-form" class="d-none">
            <div class="d-flex justify-content-between align-items-start gap-2 border-bottom border-secondary border-opacity-10 pb-3 mb-3">
                <div>
                    <span class="text-sky-400 text-uppercase" style="font-size:10px; font-weight:700; letter-spacing:.08em;">Selected station</span>
                    <h4 id="selected-station-name-booking" class="text-white mt-1 mb-1">Station</h4>
                    <p id="selected-station-address-booking" class="text-secondary mb-0" style="font-size:12px;"></p>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label text-secondary" for="booking-car-select" style="font-size:10px; font-weight:600;">CAR FOR THIS BOOKING</label>
                <select id="booking-car-select" class="form-select custom-form-field" <?php echo empty($customerCarsData) ? 'disabled' : ''; ?>>
                    <?php foreach ($customerCarsData as $car): ?>
                        <option value="<?php echo (int)$car['id']; ?>" data-quota="<?php echo htmlspecialchars((string)$car['quota'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo $car['selected'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($car['plate'] . ($car['name'] !== '' ? ' · ' . $car['name'] : ''), ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                    <?php if (empty($customerCarsData)): ?>
                        <option value="">Add a valid car license before booking</option>
                    <?php endif; ?>
                </select>
                <small id="booking-car-quota" class="text-secondary d-block mt-2">Allowance for selected car: <strong class="text-sky-400"></strong></small>
            </div>

            <div class="mb-3">
                <label class="form-label text-secondary" for="station-fuel-select" style="font-size:10px; font-weight:600;">FUEL TYPE</label>
                <select id="station-fuel-select" class="form-select custom-form-field"></select>
            </div>
            <div class="row g-2 mb-3" aria-live="polite">
                <div class="col-6"><div class="p-2 rounded-3 h-100 border border-secondary border-opacity-25"><small class="text-secondary d-block">Price per liter</small><strong id="station-fuel-price" class="text-white">Choose fuel</strong></div></div>
                <div class="col-6"><div class="p-2 rounded-3 h-100 border border-secondary border-opacity-25"><small class="text-secondary d-block">Fuel left</small><strong id="station-fuel-stock" class="text-white">Choose fuel</strong></div></div>
            </div>
            <div class="mb-3">
                <label class="form-label text-secondary" for="station-volume-input" style="font-size:10px; font-weight:600;">AMOUNT (LITERS)</label>
                <input id="station-volume-input" type="number" min="0.1" step="0.1" value="20" class="form-control custom-form-field">
                <small class="text-secondary d-block mt-2">Maximum for this fuel and car: <strong id="station-quota" class="text-sky-400"></strong></small>
            </div>
            <div class="mb-3">
                <label class="form-label text-secondary" for="loyalty-points-select" style="font-size:10px; font-weight:600;">LOYALTY POINTS TO USE (PER FILL)</label>
                <select id="loyalty-points-select" class="form-select custom-form-field">
                    <option value="0" selected>Don't use points — no discount</option>
                    <?php foreach ($loyaltyRedemptionOptions as $points => $discount): ?>
                        <option value="<?php echo (int) $points; ?>" <?php echo $loyaltyBalance < (int) $points ? 'disabled' : ''; ?>>
                            <?php echo (int) $points; ?> points — <?php echo number_format((int) $discount); ?> MMK off
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-secondary d-block mt-2">
                    Balance: <strong class="text-warning"><?php echo (int) $loyaltyBalance; ?></strong> pts
                    · <?php echo htmlspecialchars($loyaltyTierLabel, ENT_QUOTES, 'UTF-8'); ?> tier
                    · cap: <strong><?php echo number_format($loyaltyTierStatus['points_cap']); ?></strong> pts
                    · <?php echo (int) $loyaltyTierStatus['exp']; ?> EXP.
                    At your cap, successful external fills earn EXP instead of points.
                    Points are held while this booking is active and released if it is cancelled or expires. A completed redemption credits the Admin-managed Total Discounted Amount.
                </small>
                <small id="loyalty-discount-estimate" class="text-sky-400 d-block mt-1"></small>
            </div>
            <button id="confirm-booking-btn" type="button" class="btn btn-info w-100 py-3 fw-bold rounded-4" onclick="confirmBooking()">Book this station <i class="bi bi-qr-code ms-1"></i></button>
            <button type="button" class="btn btn-outline-secondary w-100 mt-2" onclick="showStationDetails()">Back to station details</button>
        </div>

        <div id="booking-pass" class="d-none text-center py-1">
            <div class="customer-qr-icon mx-auto mb-2"><i class="bi bi-qr-code-scan"></i></div>
            <span class="status-pill active mb-2">Booking confirmed</span>
            <h4 class="text-white mb-1">Booking pass</h4>
            <p id="booking-summary" class="text-secondary mb-2" style="font-size:12px;"></p>
            <div class="customer-qr-wrap customer-map-qr mx-auto mb-2"><img id="qr-image" src="" alt="Booking QR code"></div>
            <div id="qr-token-code" class="token-no text-sky-400 mb-3" style="font-size:16px; letter-spacing:.08em;"></div>
            <button type="button" class="btn btn-outline-danger btn-sm px-3 py-2 fw-bold rounded-3" onclick="openCancellationDialog()"><i class="bi bi-x-circle me-1"></i>Cancel booking</button>
        </div>
    </section>
</section>

<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.4/build/qrcode.min.js"></script>
<script>
    let mapInstance, customerMarker, routeLine, selectedStation;
    let customerLocation = [16.8409, 96.1735]; // Yangon fallback when location permission is unavailable.
    const customerCarsData = <?php echo json_encode($customerCarsData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    let availableOilQuota = Number(customerCarsData.find(car => car.selected)?.quota ?? <?php echo json_encode($availableOilQuota); ?>);
    const stationLogoUrl = '../../images/Logo.png';
    const stationsData = <?php echo json_encode($customerStationsData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    const savedActiveBooking = <?php echo json_encode($activeBooking, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    const bookingCsrfToken = <?php echo json_encode($bookingCsrfToken); ?>;
    window.respondToPartialOffer = async function(decision) {
        const message = document.getElementById('partial-offer-message');
        const formData = new FormData();
        formData.append('booking_id', String((savedActiveBooking || {}).booking_id || ''));
        formData.append('decision', decision);
        formData.append('csrf_token', bookingCsrfToken);
        try {
            const response = await fetch('respond_partial_offer.php', { method: 'POST', body: formData });
            const result = await response.json();
            if (!response.ok || !result.success) {
                if (message) message.textContent = result.message || 'Could not save your decision.';
                return;
            }
            window.location.reload();
        } catch (error) {
            if (message) message.textContent = 'Could not reach the booking service. Try again.';
        }
    };
    let currentBooking = null;

    async function createBookingQrCanvas(payload, width, margin) {
        const canvas = document.createElement('canvas');
        if (window.QRCode && typeof window.QRCode.toCanvas === 'function') {
            try {
                await window.QRCode.toCanvas(canvas, payload, { width, margin, errorCorrectionLevel: 'M' });
                return canvas;
            } catch (error) {
                console.warn('Local QR generation failed; using the QR image service.', error);
            }
        }

        const image = new Image();
        image.crossOrigin = 'anonymous';
        const loaded = new Promise((resolve, reject) => {
            image.onload = resolve;
            image.onerror = () => reject(new Error('QR image service is unavailable.'));
        });
        image.src = `https://api.qrserver.com/v1/create-qr-code/?size=${width}x${width}&qzone=${margin}&data=${encodeURIComponent(payload)}`;
        await loaded;
        canvas.width = width;
        canvas.height = width;
        const context = canvas.getContext('2d');
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, width, width);
        context.drawImage(image, 0, 0, width, width);
        return canvas;
    }

    function showCurrentBooking(booking) {
        const workflowIds = ['station-launch-card', 'quick-mapping-card', 'top5-stations-card', 'map-experience'];
        workflowIds.forEach(id => document.getElementById(id)?.classList.add('d-none'));
        currentBooking = { ...booking };

        document.getElementById('current-booking-code').textContent = booking.booking_code || booking.qr_refrence_code || '';
        document.getElementById('current-booking-station').textContent = booking.station_name || 'Station';
        document.getElementById('current-booking-address').textContent = booking.station_address || 'Address not set';
        document.getElementById('current-booking-fuel').textContent = booking.oil_name || booking.fuel_type || '';
        document.getElementById('current-booking-amount').textContent = `${booking.quantity_liters || booking.amount_liters || ''} L`;
        document.getElementById('current-booking-expiry').textContent = booking.expire_at || 'When confirmed by station staff';
        const payload = booking.qr_code || booking.payload || booking.qr_refrence_code || booking.booking_code || '';
        if (payload) {
            createBookingQrCanvas(payload, 240, 1)
                .then(canvas => { document.getElementById('current-booking-qr').src = canvas.toDataURL('image/png'); })
                .catch(error => console.error('Could not generate booking QR code.', error));
        }
        document.getElementById('current-booking-card').classList.remove('d-none');
    }

    async function downloadBookingDetails() {
        if (!currentBooking) return;
        const booking = currentBooking;
        const payload = booking.qr_code || booking.payload || booking.qr_refrence_code || booking.booking_code || '';
        if (!payload) {
            alert('This booking is missing its QR payload. Refresh the page and try again.');
            return;
        }

        try {
            const qrCanvas = await createBookingQrCanvas(payload, 320, 2);
            const width = 800;
            const padding = 48;
            const rows = [
                ['Station', booking.station_name || 'N/A'],
                ['Address', booking.station_address || 'Address not set'],
                ['Fuel', booking.oil_name || booking.fuel_type || 'N/A'],
                ['Amount', `${booking.quantity_liters ?? booking.amount_liters ?? 'N/A'} L`],
                ['Status', booking.status || 'Active'],
                ['Booked', booking.created_at || 'Not available'],
                ['Expires', booking.expire_at || 'When confirmed by station staff']
            ];
            const valueX = 205;
            const valueWidth = width - padding - valueX;
            const lineHeight = 25;
            const measureContext = document.createElement('canvas').getContext('2d');
            measureContext.font = '16px Arial, sans-serif';
            const wrapText = (text, maxWidth) => {
                const words = String(text).split(/\s+/);
                const lines = [];
                let line = '';
                words.forEach(word => {
                    const candidate = line ? `${line} ${word}` : word;
                    if (line && measureContext.measureText(candidate).width > maxWidth) {
                        lines.push(line);
                        line = word;
                    } else {
                        line = candidate;
                    }
                });
                if (line) lines.push(line);
                return lines;
            };
            const wrappedRows = rows.map(([label, value]) => ({ label, lines: wrapText(value, valueWidth) }));
            const rowsHeight = wrappedRows.reduce((height, row) => height + Math.max(lineHeight, row.lines.length * lineHeight) + 12, 0);
            const canvas = document.createElement('canvas');
            canvas.width = width;
            canvas.height = 590 + rowsHeight;
            const context = canvas.getContext('2d');
            context.fillStyle = '#ffffff';
            context.fillRect(0, 0, canvas.width, canvas.height);
            context.fillStyle = '#102033';
            context.font = '700 30px Arial, sans-serif';
            context.fillText('MAP & PUMP BOOKING', padding, 58);
            context.fillStyle = '#536273';
            context.font = '18px Arial, sans-serif';
            context.fillText('OFFLINE BOOKING RECEIPT', padding, 88);
            context.drawImage(qrCanvas, (width - 320) / 2, 112, 320, 320);
            context.fillStyle = '#102033';
            context.font = '700 22px Arial, sans-serif';
            context.textAlign = 'center';
            context.fillText(booking.booking_code || booking.qr_refrence_code || 'Booking reference', width / 2, 466);
            context.textAlign = 'left';
            let y = 530;
            wrappedRows.forEach(row => {
                context.fillStyle = '#536273';
                context.font = '700 16px Arial, sans-serif';
                context.fillText(`${row.label}:`, padding, y);
                context.fillStyle = '#102033';
                context.font = '16px Arial, sans-serif';
                row.lines.forEach((line, index) => context.fillText(line, valueX, y + index * lineHeight));
                y += Math.max(lineHeight, row.lines.length * lineHeight) + 12;
            });
            context.fillStyle = '#536273';
            context.font = '14px Arial, sans-serif';
            context.fillText('Present the QR code or booking reference to station staff.', padding, y + 8);

            const imageBlob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.82));
            if (!imageBlob) throw new Error('Could not encode booking image.');
            const link = document.createElement('a');
            link.href = URL.createObjectURL(imageBlob);
            const safeReference = String(booking.booking_code || booking.qr_refrence_code || 'receipt').replace(/[^a-z0-9_-]/gi, '-');
            link.download = `map-and-pump-booking-${safeReference}.jpg`;
            link.click();
            setTimeout(() => URL.revokeObjectURL(link.href), 1000);
        } catch (error) {
            console.error('Could not create booking image.', error);
            alert('The booking image could not be created. Please try again.');
        }
    }

    const cancellationDialog = document.getElementById('booking-cancellation-dialog');
    const cancellationChecks = Array.from(document.querySelectorAll('#cancellation-reasons input[type="checkbox"]'));

    function openCancellationDialog() {
        if (!currentBooking) return;
        document.getElementById('cancellation-error').textContent = '';
        cancellationDialog.showModal();
    }

    function closeCancellationDialog() {
        cancellationDialog.close();
    }

    cancellationChecks.forEach(checkbox => checkbox.addEventListener('change', () => {
        const selected = cancellationChecks.filter(item => item.checked);
        if (selected.length > 3) {
            checkbox.checked = false;
            alert('Select no more than 3 reasons.');
        }
        const count = cancellationChecks.filter(item => item.checked).length;
        document.getElementById('cancellation-selection-count').textContent = `${count} of 3 selected`;
        const otherSelected = document.getElementById('cancellation-other-checkbox').checked;
        document.getElementById('cancellation-details-required').classList.toggle('d-none', !otherSelected);
        document.getElementById('cancellation-details').placeholder = otherSelected
            ? 'Please describe the problem.'
            : 'Optional details; required when Other is selected.';
    }));

    document.getElementById('booking-cancellation-form').addEventListener('submit', async event => {
        event.preventDefault();
        const reasons = cancellationChecks.filter(item => item.checked).map(item => item.value);
        const details = document.getElementById('cancellation-details').value.trim();
        const error = document.getElementById('cancellation-error');
        if (reasons.length < 1 || reasons.length > 3) {
            error.textContent = 'Select at least 1 and no more than 3 reasons.';
            return;
        }
        if (reasons.includes('Other') && !details) {
            error.textContent = 'Please describe the problem when selecting Other.';
            return;
        }

        const submitButton = document.getElementById('submit-cancellation-btn');
        submitButton.disabled = true;
        error.textContent = '';
        const formData = new FormData();
        formData.append('booking_id', String(currentBooking.booking_id || ''));
        formData.append('csrf_token', bookingCsrfToken);
        reasons.forEach(reason => formData.append('reasons[]', reason));
        formData.append('details', details);
        try {
            const response = await fetch('cancel_booking.php', { method: 'POST', body: formData });
            const result = await response.json();
            if (!response.ok || !result.success) {
                error.textContent = result.message || 'Could not cancel this booking. Please try again.';
                submitButton.disabled = false;
                return;
            }
            window.location.reload();
        } catch (requestError) {
            error.textContent = 'Could not reach the booking service. Check your connection and try again.';
            submitButton.disabled = false;
        }
    });

    if (savedActiveBooking) {
        showCurrentBooking(savedActiveBooking);
    }

    function openMapExperience(autoSelectNearest, preselectStationId) {
        document.getElementById('station-launch-card').classList.add('d-none');
        document.getElementById('quick-mapping-card').classList.add('d-none');
        document.getElementById('top5-stations-card').classList.add('d-none');
        document.getElementById('map-experience').classList.remove('d-none');
        if (!mapInstance) {
            mapInstance = L.map('station-map', { scrollWheelZoom: false }).setView(customerLocation, 12);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                maxZoom: 19
            }).addTo(mapInstance);
            stationsData.forEach(station => {
                const marker = L.marker([station.lat, station.lng], { icon: createStationIcon(station) }).addTo(mapInstance);
                marker.bindTooltip(createStationTooltip(station), {
                    className: 'customer-station-tooltip',
                    direction: 'top',
                    offset: [0, -16]
                });
                marker.on('click', () => selectStation(station.id));
            });
            if (stationsData.length && !autoSelectNearest && !preselectStationId) {
                mapInstance.fitBounds(stationsData.map(station => [station.lat, station.lng]), { padding: [40, 40], maxZoom: 13 });
            }
            locateCustomer(autoSelectNearest, preselectStationId);
        } else {
            if (preselectStationId) {
                selectStation(preselectStationId);
            } else if (autoSelectNearest) {
                locateCustomer(true);
            }
        }
        setTimeout(() => mapInstance.invalidateSize(), 50);
    }

    function closeMapExperience() {
        document.getElementById('map-experience').classList.add('d-none');
        document.getElementById('station-booking-card').classList.add('d-none');
        document.getElementById('show-station-card').classList.add('d-none');
        document.getElementById('station-launch-card').classList.remove('d-none');
        document.getElementById('quick-mapping-card').classList.remove('d-none');
        document.getElementById('top5-stations-card').classList.remove('d-none');
        document.getElementById('booking-pass').classList.add('d-none');
        document.getElementById('station-booking-form').classList.remove('d-none');
        selectedStation = null;
    }

    function distanceKm(lat1, lng1, lat2, lng2) {
        const toRad = deg => deg * Math.PI / 180;
        const R = 6371;
        const dLat = toRad(lat2 - lat1);
        const dLng = toRad(lng2 - lng1);
        const a = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLng / 2) ** 2;
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    function findNearestStation() {
        let nearest = null;
        let minDist = Infinity;
        stationsData.filter(station => station.isBookable).forEach(station => {
            const d = distanceKm(customerLocation[0], customerLocation[1], station.lat, station.lng);
            if (d < minDist) {
                minDist = d;
                nearest = station;
            }
        });
        return nearest;
    }

    function getTopNearestStations(limit) {
        return stationsData
            .map(station => ({
                ...station,
                distance: distanceKm(customerLocation[0], customerLocation[1], station.lat, station.lng)
            }))
            .sort((a, b) => a.distance - b.distance)
            .slice(0, limit);
    }

    function renderTop5Stations() {
        const list = document.getElementById('top5-stations-list');
        if (!list) return;
        const top5 = getTopNearestStations(5);
        if (!top5.length) {
            list.innerHTML = '<div class="text-secondary" style="font-size:13px;">No stations with map locations are available.</div>';
            return;
        }
        list.innerHTML = top5.map((station, index) => `
            <button type="button" class="top5-station-row" onclick="bookFromTop5(${station.id})" title="${station.name}">
                <span class="row-rank">${index + 1}</span>
                <img src="${station.logo}" alt="${station.name} logo" onerror="this.src='../../images/Logo.png'">
                <span class="row-body">
                    <span class="row-name">${station.name}</span>
                    <span class="row-address">${station.address}</span>
                </span>
                <span class="row-dist">${station.distance.toFixed(1)} km</span>
            </button>
        `).join('');
    }


    function bookFromTop5(stationId) {
        openMapExperience(false, stationId);
    }

    function autoConnectNearestStation() {
        openMapExperience(true);
    }

    function locateCustomer(autoSelectNearest, preselectStationId) {
        const afterLocate = (focusCustomerLocation) => {
            drawCustomerLocation(autoSelectNearest, preselectStationId, focusCustomerLocation);
            renderTop5Stations();
        };
        if (!navigator.geolocation) {
            afterLocate(false);
            return;
        }
        navigator.geolocation.getCurrentPosition(position => {
            customerLocation = [position.coords.latitude, position.coords.longitude];
            afterLocate(true);
        }, () => afterLocate(false), { enableHighAccuracy: true, timeout: 8000 });
    }

    function recenterCustomerLocation() {
        if (!mapInstance) return;
        const recenter = () => {
            drawCustomerLocation(false);
            mapInstance.setView(customerLocation, 15, { animate: true });
            renderTop5Stations();
        };
        if (!navigator.geolocation) {
            recenter();
            return;
        }
        navigator.geolocation.getCurrentPosition(position => {
            customerLocation = [position.coords.latitude, position.coords.longitude];
            recenter();
        }, recenter, { enableHighAccuracy: true, timeout: 8000 });
    }

    function drawCustomerLocation(autoSelectNearest, preselectStationId, focusCustomerLocation) {
        if (!mapInstance) return;
        if (customerMarker) mapInstance.removeLayer(customerMarker);
        customerMarker = L.circleMarker(customerLocation, { radius: 9, color: '#fff', weight: 2, fillColor: '#00a8ff', fillOpacity: 1 }).addTo(mapInstance).bindTooltip('Your location');
        if (preselectStationId) {
            selectStation(preselectStationId);
        } else if (autoSelectNearest) {
            const nearest = findNearestStation();
            if (nearest) selectStation(nearest.id);
        } else {
            if (!selectedStation && focusCustomerLocation) mapInstance.setView(customerLocation, 12);
            if (selectedStation) showRoute();
        }
    }

    // Populate Top 5 list as soon as the page loads (uses geolocation when allowed).
    (function initTop5List() {
        if (!navigator.geolocation) {
            renderTop5Stations();
            return;
        }
        navigator.geolocation.getCurrentPosition(position => {
            customerLocation = [position.coords.latitude, position.coords.longitude];
            renderTop5Stations();
        }, () => renderTop5Stations(), { enableHighAccuracy: true, timeout: 8000 });
    })();



    function renderStationDetailCard(station) {
        const stationImage = document.getElementById('selected-station-image');
        const name = document.getElementById('selected-station-name');
        const address = document.getElementById('selected-station-address');
        const nameBooking = document.getElementById('selected-station-name-booking');
        const addressBooking = document.getElementById('selected-station-address-booking');
        const detailList = document.getElementById('station-detail-fuels');
        const bookingTrigger = document.getElementById('station-book-trigger');
        const bookingStatus = document.getElementById('station-booking-status');
        const reviewsToggle = document.getElementById('station-reviews-toggle');
        const reviewsPanel = document.getElementById('station-reviews');
        const reviewsList = document.getElementById('station-reviews-list');
        const reviewSummary = document.getElementById('station-review-summary');
        const reviewFilters = document.getElementById('station-review-filters');
        const reviewEmpty = document.getElementById('station-review-empty');
        if (!station) return;

        name.textContent = station.name;
        address.textContent = station.address;
        nameBooking.textContent = station.name;
        addressBooking.textContent = station.address;
        stationImage.src = station.logo || '../../images/Logo.png';
        stationImage.onerror = () => { stationImage.src = '../../images/Logo.png'; };

        bookingTrigger.disabled = !station.isBookable;
        bookingStatus.textContent = station.isBookable
            ? ''
            : String(station.status).toLowerCase() === 'maintenance'
                ? 'This station is under maintenance. Booking is unavailable.'
                : !station.isOpen
                    ? 'This station is outside opening hours. Booking is unavailable.'
                    : !station.isActive
                        ? 'This station is not currently available for booking.'
                        : 'Fuel is currently unavailable for booking.';

        const reviewCount = (station.reviews || []).length;
        const reviewsIcon = document.createElement('i');
        reviewsIcon.className = 'bi bi-chat-square-text me-1';
        reviewsToggle.replaceChildren(reviewsIcon, document.createTextNode(`Customer reviews (${reviewCount})`));
        reviewsPanel.classList.add('d-none');
        reviewsToggle.setAttribute('aria-expanded', 'false');
        reviewsList.replaceChildren();
        reviewSummary.replaceChildren();
        reviewFilters.replaceChildren();
        reviewEmpty.hidden = true;
        if (station.reviews?.length) {
            const ratedReviews = station.reviews.filter(review => Number(review.rating) >= 1 && Number(review.rating) <= 5);
            const ratingAverage = ratedReviews.length
                ? ratedReviews.reduce((sum, review) => sum + Number(review.rating), 0) / ratedReviews.length
                : 0;
            const average = document.createElement('div');
            average.className = 'station-review-average';
            const averageValue = document.createElement('strong');
            averageValue.textContent = ratingAverage.toFixed(1);
            const averageStars = document.createElement('span');
            averageStars.className = 'station-review-stars';
            averageStars.textContent = '\u2605'.repeat(Math.round(ratingAverage)) + '\u2606'.repeat(5 - Math.round(ratingAverage));
            averageStars.setAttribute('aria-label', `${ratingAverage.toFixed(1)} out of 5 stars`);
            const averageCaption = document.createElement('span');
            averageCaption.className = 'station-review-caption';
            averageCaption.textContent = `${ratedReviews.length} ${ratedReviews.length === 1 ? 'rating' : 'ratings'}`;
            average.append(averageValue, averageStars, averageCaption);

            const distribution = document.createElement('div');
            distribution.className = 'station-review-distribution';
            for (let level = 5; level >= 1; level--) {
                const count = ratedReviews.filter(review => Number(review.rating) === level).length;
                const percentage = ratedReviews.length ? Math.round(count / ratedReviews.length * 100) : 0;
                const row = document.createElement('div');
                row.className = 'station-review-row';
                const label = document.createElement('span');
                label.className = 'station-review-row-label';
                label.textContent = `${level} star`;
                const track = document.createElement('span');
                track.className = 'station-review-track';
                track.setAttribute('aria-label', `${count} ${level}-star reviews`);
                const fill = document.createElement('span');
                fill.className = 'station-review-fill';
                fill.style.width = `${percentage}%`;
                track.append(fill);
                const percent = document.createElement('span');
                percent.className = 'station-review-percent';
                percent.textContent = `${percentage}%`;
                row.append(label, track, percent);
                distribution.append(row);
            }
            reviewSummary.append(average, distribution);

            const allFilter = document.createElement('button');
            allFilter.type = 'button';
            allFilter.className = 'station-review-filter active';
            allFilter.dataset.ratingFilter = 'all';
            allFilter.setAttribute('aria-pressed', 'true');
            allFilter.textContent = 'All reviews';
            reviewFilters.append(allFilter);
            for (let level = 5; level >= 1; level--) {
                const filter = document.createElement('button');
                filter.type = 'button';
                filter.className = 'station-review-filter';
                filter.dataset.ratingFilter = String(level);
                filter.setAttribute('aria-pressed', 'false');
                filter.textContent = `${level} star${level === 1 ? '' : 's'}`;
                reviewFilters.append(filter);
            }
            reviewFilters.querySelectorAll('.station-review-filter').forEach(filter => {
                filter.addEventListener('click', () => {
                    reviewFilters.querySelectorAll('.station-review-filter').forEach(button => {
                        const active = button === filter;
                        button.classList.toggle('active', active);
                        button.setAttribute('aria-pressed', String(active));
                    });
                    const selectedRating = filter.dataset.ratingFilter;
                    let visibleCount = 0;
                    reviewsList.querySelectorAll('.customer-review-card').forEach(card => {
                        const visible = selectedRating === 'all' || card.dataset.rating === selectedRating;
                        card.hidden = !visible;
                        if (visible) visibleCount++;
                    });
                    reviewEmpty.hidden = visibleCount > 0;
                });
            });

            station.reviews.forEach(review => {
                const reviewCard = document.createElement('article');
                reviewCard.className = 'customer-review-card';
                const ratingValue = Math.max(0, Math.min(5, Number(review.rating) || 0));
                reviewCard.dataset.rating = String(ratingValue);
                const heading = document.createElement('div');
                heading.className = 'd-flex justify-content-between gap-2';
                const customer = document.createElement('strong');
                customer.className = 'text-white';
                customer.textContent = review.customer || 'Customer';
                const rating = document.createElement('span');
                rating.className = 'station-review-stars small';
                rating.textContent = ratingValue > 0
                    ? '\u2605'.repeat(ratingValue) + '\u2606'.repeat(5 - ratingValue)
                    : 'No rating';
                rating.setAttribute('aria-label', ratingValue > 0 ? `${ratingValue} out of 5 stars` : 'No rating');
                heading.append(customer, rating);
                const date = document.createElement('small');
                date.className = 'text-secondary d-block mt-1';
                date.textContent = review.createdAt ? new Date(review.createdAt.replace(' ', 'T')).toLocaleDateString() : '';
                const text = document.createElement('p');
                text.className = 'mb-0 mt-2';
                text.textContent = review.text || 'No written comment.';
                reviewCard.append(heading, date, text);
                reviewsList.append(reviewCard);
            });
        } else {
            reviewSummary.classList.add('d-none');
            reviewFilters.classList.add('d-none');
            const emptyState = document.createElement('p');
            emptyState.className = 'text-secondary small mb-0';
            emptyState.textContent = 'No customer reviews for this station yet.';
            reviewsList.append(emptyState);
        }
        if (station.reviews?.length) {
            reviewSummary.classList.remove('d-none');
            reviewFilters.classList.remove('d-none');
        }

        const fuels = station.fuels && station.fuels.length
            ? station.fuels
            : [{ id: 0, name: 'No fuel available', price: null, stock: 0, available: false }];
        detailList.replaceChildren();
        fuels.forEach(fuel => {
            const isAvailable = station.isBookable && fuel.available === true && Number(fuel.price) > 0 && Number(fuel.stock) > 0;
            const item = document.createElement('button');
            item.type = 'button';
            item.className = `customer-detail-fuel-item ${isAvailable ? 'customer-detail-fuel-item--active' : 'customer-detail-fuel-item--inactive'}`;
            item.dataset.fuelId = String(fuel.id);
            item.disabled = !isAvailable;
            item.setAttribute('aria-pressed', 'false');

            const heading = document.createElement('div');
            heading.className = 'd-flex justify-content-between align-items-center gap-2';
            const name = document.createElement('span');
            name.className = 'text-white fw-bold';
            name.textContent = String(fuel.name || 'Fuel');
            const status = document.createElement('span');
            status.className = `customer-fuel-status ${isAvailable ? 'customer-fuel-status--active' : 'customer-fuel-status--inactive'}`;
            status.textContent = isAvailable ? 'Active' : 'Unavailable';
            heading.append(name, status);
            item.append(heading);

            if (station.isOpen) {
                const priceRow = document.createElement('div');
                priceRow.className = 'd-flex justify-content-between align-items-center mt-2 text-secondary small';
                const priceLabel = document.createElement('span');
                priceLabel.textContent = 'Fuel price';
                const priceValue = document.createElement('strong');
                priceValue.className = 'text-white';
                priceValue.textContent = fuel.price !== null && Number(fuel.price) > 0
                    ? `${Number(fuel.price).toLocaleString()} MMK / L`
                    : 'Not available';
                priceRow.append(priceLabel, priceValue);
                item.append(priceRow);
            }

            const stockRow = document.createElement('div');
            stockRow.className = 'd-flex justify-content-between align-items-center mt-1 text-secondary small';
            const stockLabel = document.createElement('span');
            stockLabel.textContent = 'Liters left';
            const stockValue = document.createElement('strong');
            stockValue.className = Number(fuel.stock) > 0 ? 'text-sky-400' : 'text-white';
            stockValue.textContent = Number(fuel.stock) > 0 ? `${Number(fuel.stock).toLocaleString()} L left` : 'Unavailable';
            stockRow.append(stockLabel, stockValue);
            item.append(stockRow);

            item.addEventListener('click', () => {
                document.getElementById('station-fuel-select').value = String(fuel.id);
                updateSelectedFuelDetails();
            });
            detailList.append(item);
        });
    }

    function selectStation(stationId) {
        selectedStation = stationsData.find(station => Number(station.id) === Number(stationId));
        if (!selectedStation) {
            return;
        }
        renderStationDetailCard(selectedStation);
        const fuelSelect = document.getElementById('station-fuel-select');
        const bookingButton = document.getElementById('confirm-booking-btn');
        const availableFuels = selectedStation.fuels.filter(fuel => selectedStation.isBookable && fuel.available === true && Number(fuel.price) > 0 && Number(fuel.stock) > 0);
        if (availableFuels.length) {
            fuelSelect.replaceChildren(...selectedStation.fuels.map(fuel => {
                const option = document.createElement('option');
                option.value = String(fuel.id);
                option.textContent = fuel.name;
                option.disabled = !availableFuels.includes(fuel);
                return option;
            }));
            fuelSelect.disabled = false;
            fuelSelect.value = String(availableFuels[0].id);
        } else {
            fuelSelect.replaceChildren(new Option(selectedStation.isOpen ? 'No fuel currently available' : 'Station is currently closed', ''));
            fuelSelect.disabled = true;
            bookingButton.disabled = true;
        }
        updateBookingCarQuota();
        document.getElementById('station-volume-input').value = Math.min(20, availableOilQuota);
        updateSelectedFuelDetails();
        document.getElementById('booking-pass').classList.add('d-none');
        showStationDetails();
        showStationCard();
        showRoute();
    }

    function updateBookingCarQuota() {
        const selectedCarId = Number(document.getElementById('booking-car-select').value);
        const selectedCar = customerCarsData.find(car => Number(car.id) === selectedCarId);
        availableOilQuota = selectedCar ? Number(selectedCar.quota) : 0;
        document.querySelector('#booking-car-quota strong').textContent = `${availableOilQuota.toLocaleString()} L`;
        if (selectedStation) updateSelectedFuelDetails();
    }

    function updateSelectedFuelDetails() {
        const selectedFuelId = Number(document.getElementById('station-fuel-select').value);
        const fuel = selectedStation?.fuels.find(item => Number(item.id) === selectedFuelId);
        const price = document.getElementById('station-fuel-price');
        const stock = document.getElementById('station-fuel-stock');
        const volumeInput = document.getElementById('station-volume-input');
        const bookingButton = document.getElementById('confirm-booking-btn');

        document.querySelectorAll('#station-detail-fuels .customer-detail-fuel-item').forEach(item => {
            const isSelected = item.dataset.fuelId === String(selectedFuelId);
            item.classList.toggle('customer-detail-fuel-item--selected', isSelected);
            item.setAttribute('aria-pressed', String(isSelected));
        });

        if (!fuel) {
            price.textContent = 'Not available';
            stock.textContent = 'Not available';
            volumeInput.max = '0';
            bookingButton.disabled = true;
            document.getElementById('station-quota').textContent = '0 L';
            return;
        }

        const availableLiters = Math.max(0, Number(fuel.stock));
        const hasValidFuel = selectedStation?.isBookable && Number(fuel.price) > 0 && availableLiters > 0 && fuel.available;
        const bookingLimit = hasValidFuel ? Math.min(availableOilQuota, availableLiters) : 0;
        price.textContent = hasValidFuel ? `${Number(fuel.price).toLocaleString()} MMK / L` : 'Not available';
        stock.textContent = hasValidFuel ? `${availableLiters.toLocaleString()} L` : 'Unavailable';
        volumeInput.max = String(bookingLimit);
        if (Number(volumeInput.value) > bookingLimit) {
            volumeInput.value = bookingLimit > 0 ? bookingLimit : '';
        }
        document.getElementById('station-quota').textContent = hasValidFuel ? `${bookingLimit.toLocaleString()} L` : '0 L';
        bookingButton.disabled = !selectedStation?.isBookable || !document.getElementById('booking-car-select').value || bookingLimit < 0.1;
    }

    function showBookingForm() {
        if (!selectedStation?.isBookable) return;
        document.getElementById('station-detail-card').classList.add('d-none');
        document.getElementById('station-booking-form').classList.remove('d-none');
    }

    function toggleStationReviews() {
        const panel = document.getElementById('station-reviews');
        const button = document.getElementById('station-reviews-toggle');
        const expanded = button.getAttribute('aria-expanded') === 'true';
        panel.classList.toggle('d-none', expanded);
        button.setAttribute('aria-expanded', String(!expanded));
    }

    function showStationDetails() {
        document.getElementById('station-detail-card').classList.remove('d-none');
        document.getElementById('station-booking-form').classList.add('d-none');
    }

    document.getElementById('station-fuel-select').addEventListener('change', updateSelectedFuelDetails);
    document.getElementById('booking-car-select').addEventListener('change', updateBookingCarQuota);

    function createStationIcon(station) {
        return L.divIcon({
            className: '',
            html: `<span class="customer-station-marker ${station.isActive ? 'active' : 'inactive'}" aria-label="Station marker"></span>`,
            iconSize: [18, 18],
            iconAnchor: [9, 9],
            tooltipAnchor: [0, -12]
        });
    }

    function createStationTooltip(station) {
        const tooltip = document.createElement('div');
        const name = document.createElement('div');
        const status = document.createElement('div');
        const fuels = document.createElement('div');

        name.className = 'station-tooltip-name';
        status.className = 'station-tooltip-status';
        fuels.className = 'station-tooltip-fuels';
        name.textContent = station.name;
        status.textContent = `Status: ${station.status}`;
        fuels.textContent = station.fuels.length
            ? `Fuels: ${station.fuels.map(fuel => station.isOpen
                ? `${fuel.name} (${Number(fuel.price).toLocaleString()} MMK/L, ${Number(fuel.stock).toLocaleString()} L left)`
                : `${fuel.name} (${Number(fuel.stock).toLocaleString()} L left)`).join(', ')}`
            : 'Active fuels: None';
        tooltip.append(name, status, fuels);
        return tooltip;
    }

    function showStationCard() {
        if (!selectedStation) return;
        document.getElementById('station-booking-card').classList.remove('d-none');
        document.getElementById('show-station-card').classList.add('d-none');
    }

    function hideStationCard() {
        document.getElementById('station-booking-card').classList.add('d-none');
        document.getElementById('show-station-card').innerHTML = '<i class="bi bi-card-text me-2"></i> Show station card';
        document.getElementById('show-station-card').classList.remove('d-none');
    }

    async function showRoute() {
        if (!mapInstance || !selectedStation) return;
        if (routeLine) mapInstance.removeLayer(routeLine);
        const destination = [selectedStation.lat, selectedStation.lng];
        routeLine = L.polyline([customerLocation, destination], { color: '#00f4ff', weight: 4, opacity: .8, dashArray: '8 8' }).addTo(mapInstance);
        mapInstance.fitBounds(routeLine.getBounds(), { padding: [55, 55] });
        try {
            const points = `${customerLocation[1]},${customerLocation[0]};${selectedStation.lng},${selectedStation.lat}`;
            const response = await fetch(`https://router.project-osrm.org/route/v1/driving/${points}?overview=full&geometries=geojson`);
            const route = (await response.json()).routes?.[0];
            if (!route) return;
            mapInstance.removeLayer(routeLine);
            routeLine = L.geoJSON(route.geometry, { style: { color: '#00f4ff', weight: 5, opacity: .9 } }).addTo(mapInstance);
        } catch (error) { /* The cyan fallback line remains visible. */ }
    }

    const loyaltyBalanceClient = <?php echo (int) $loyaltyBalance; ?>;
    const loyaltyRedemptionOptions = <?php echo json_encode($loyaltyRedemptionOptions, JSON_THROW_ON_ERROR); ?>;

    function updateLoyaltyEstimate() {
        const estimateEl = document.getElementById('loyalty-discount-estimate');
        if (!estimateEl) return;
        const points = Number(document.getElementById('loyalty-points-select')?.value || 0);
        const quantity = Number(document.getElementById('station-volume-input')?.value || 0);
        const selectedFuelId = Number(document.getElementById('station-fuel-select')?.value || 0);
        const fuel = selectedStation?.fuels?.find(item => Number(item.id) === selectedFuelId);
        if (!points || points <= 0 || !quantity || quantity <= 0 || !fuel || !(Number(fuel.price) > 0)) {
            estimateEl.textContent = '';
            return;
        }
        const totalCost = quantity * Number(fuel.price);
        const affordableOption = Object.entries(loyaltyRedemptionOptions)
            .map(([optionPoints, optionDiscount]) => [Number(optionPoints), Number(optionDiscount)])
            .filter(([optionPoints, optionDiscount]) => optionPoints <= points && optionPoints <= loyaltyBalanceClient && optionDiscount <= totalCost)
            .sort((left, right) => right[0] - left[0])[0];
        if (!affordableOption) {
            const minimumDiscount = Math.min(...Object.values(loyaltyRedemptionOptions).map(Number));
            estimateEl.textContent = `Estimated bill must be at least ${minimumDiscount.toLocaleString()} MMK for this reward. Points not applied to the final fill are released.`;
            return;
        }
        const [pointsToUse, discountMmk] = affordableOption;
        estimateEl.textContent = `Estimated ${discountMmk.toLocaleString()} MMK off using ${pointsToUse} points. A lower reward tier may apply if staff fills fewer liters.`;
    }

    document.getElementById('loyalty-points-select')?.addEventListener('change', updateLoyaltyEstimate);
    document.getElementById('station-volume-input')?.addEventListener('input', updateLoyaltyEstimate);
    document.getElementById('station-fuel-select')?.addEventListener('change', updateLoyaltyEstimate);

    async function confirmBooking() {
        const quantity = Number(document.getElementById('station-volume-input').value);
        const selectedFuelId = Number(document.getElementById('station-fuel-select').value);
        const fuel = selectedStation?.fuels.find(item => Number(item.id) === selectedFuelId);
        const selectedCarId = Number(document.getElementById('booking-car-select').value);
        const pointsToRedeem = Number(document.getElementById('loyalty-points-select')?.value || 0);
        const bookingLimit = fuel ? Math.min(availableOilQuota, Number(fuel.stock)) : 0;
        if (!selectedStation || !selectedStation.isBookable || !fuel || selectedCarId <= 0 || !quantity || quantity <= 0 || quantity > bookingLimit) {
            alert(`Choose an available car and fuel, then enter an amount between 0.1 and ${bookingLimit} liters.`);
            return;
        }
        if (pointsToRedeem > 0 && pointsToRedeem > loyaltyBalanceClient) {
            alert('Not enough loyalty points for that option.');
            return;
        }
        const code = `MP-${Math.floor(100 + Math.random() * 900)}-${Math.random().toString(36).substring(2, 6).toUpperCase()}`;
        const displayPayload = `${code}|${selectedStation.name}|${fuel.name}|${quantity}L`;

        const formData = new FormData();
        formData.append('csrf_token', bookingCsrfToken);
        formData.append('booking_code', code);
        formData.append('station_id', String(selectedStation.id));
        formData.append('fuel_type', fuel.name);
        formData.append('quantity_liters', String(quantity));
        formData.append('car_license_id', String(selectedCarId));
        formData.append('display_payload', displayPayload);
        formData.append('points_to_redeem', String(pointsToRedeem));
        let result;
        try {
            const response = await fetch('save_booking.php', {
                method: 'POST',
                body: formData
            });
            result = await response.json();
            if (!response.ok || !result.success) {
                alert(result.message || 'Could not save this booking. Please try again.');
                return;
            }
        } catch (error) {
            console.error('Booking API unavailable.', error);
            alert('Could not save this booking to the database. Please check your connection and try again.');
            return;
        }

        const qrPayload = result.payload || displayPayload;
        showCurrentBooking({
            booking_id: result.booking_id,
            booking_code: result.booking_code || code,
            qr_code: qrPayload,
            station_name: selectedStation.name,
            station_address: selectedStation.address,
            oil_name: fuel.name,
            quantity_liters: quantity,
            expire_at: result.expire_at || 'When confirmed by station staff'
        });
    }
</script>

<?php include 'share_footer.php'; ?>
