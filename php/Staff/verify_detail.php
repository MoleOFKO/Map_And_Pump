<?php
session_start();
require_once __DIR__ . '/../Customer/car_access.php';
require __DIR__ . '/../conn.php';
require_once __DIR__ . '/../business_scope.php';
require_once __DIR__ . '/../loyalty_points.php';
require_once __DIR__ . '/../booking_reservations.php';
require_once __DIR__ . '/staff_access_guard.php';

map_pump_require_active_staff_session($pdo, false);

$staffCanAccessBranch = static function (int $staffUserId, int $branchId) use ($pdo): bool {
    return function_exists('map_pump_staff_can_access_branch')
        && (bool) call_user_func('map_pump_staff_can_access_branch', $pdo, $staffUserId, $branchId);
};

function redirect_to_login(string $message = 'Please sign in to continue.')
{
    $_SESSION['login_error'] = $message;
    header('Location: ../Login/login.php');
    exit;
}

if (empty($_SESSION['is_logged_in']) || empty($_SESSION['user_id'])) {
    redirect_to_login();
}
if (empty($_SESSION['staff_verify_csrf_token'])) $_SESSION['staff_verify_csrf_token'] = bin2hex(random_bytes(32));
$staffVerifyCsrfToken = (string)$_SESSION['staff_verify_csrf_token'];

$flash = $_SESSION['verify_flash'] ?? null;
unset($_SESSION['verify_flash']);

$bookingId = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;
$booking = null;
$weeklyLimitLiters = function_exists('map_pump_fuel_quota_liters')
    ? map_pump_fuel_quota_liters($pdo)
    : (float) MAP_PUMP_FUEL_QUOTA_LITERS;
$weeklyFilledLiters = 0.0;
$fillableLiters = $weeklyLimitLiters;
if ($bookingId > 0) {
    $stmt = $pdo->prepare(
        'SELECT b.booking_id, b.user_id, b.station_id, b.oil_type_id, b.qr_code, b.qr_refrence_code,
            r.requested_liters,
                b.status, b.filled_gas, b.time_of_filled, b.expire_at, b.created_at,
            s.branch_name AS station_name, o.oil_name, u.user_name, u.email, b.car_license_id,
            c.car_plate_number, c.vehicle_name, c.vehicle_type, c.model_year,
            c.filled_gas AS weekly_filled_gas, c.updated_at AS car_license_updated_at
         FROM booking b
         LEFT JOIN booking_reservations r ON r.booking_id = b.booking_id
         LEFT JOIN branches s ON s.branch_id = b.station_id
         LEFT JOIN oil_types o ON o.oil_type_id = b.oil_type_id
         LEFT JOIN users u ON u.user_id = b.user_id
         LEFT JOIN car_license c ON c.car_license_id = b.car_license_id
         WHERE b.booking_id = :booking_id
         LIMIT 1'
    );
    $stmt->execute([':booking_id' => $bookingId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($booking && !$staffCanAccessBranch((int) $_SESSION['user_id'], (int) $booking['station_id'])) {
        $booking = null;
        $errorMessage = 'This booking belongs to another branch.';
    }
}

if (!$booking) {
    $errorMessage = 'Booking detail not found. Please return to the scanner and try again.';
} else {
    $carLicenseId = isset($booking['car_license_id']) ? (int)$booking['car_license_id'] : 0;
    if ($carLicenseId > 0) {
        $quota = customer_fuel_quota_for_car($pdo, (int)$booking['user_id'], $carLicenseId);
        $weeklyLimitLiters = (float)$quota['limit'];
        $weeklyFilledLiters = max(0.0, (float)$quota['used']);
        $fillableLiters = max(0.0, (float)$quota['remaining']);
    }
}

$customerUid = $booking['user_id'] ?? '';
$oilType = $booking['oil_name'] ?? 'Unknown';
$stationName = $booking['station_name'] ?? 'Unknown station';
$bookingCode = $booking['qr_refrence_code'] ?? '';
$isFilled = isset($booking['status']) && in_array(strtolower((string)$booking['status']), ['filled', 'completed', 'cancelled', 'expired', 'station_unable_to_fulfill'], true);
$maxFillLiters = $booking ? min($fillableLiters, (float)($booking['requested_liters'] ?? 0)) : 0.0;
$customerName = $booking['user_name'] ?? 'Customer';
$customerInitial = strtoupper(substr(trim((string)$customerName), 0, 1)) ?: 'C';
$customerEmail = $booking['email'] ?? '';
$customerPlate = $booking['car_plate_number'] ?? '';
$customerVehicle = trim((string)($booking['vehicle_name'] ?? '') . ' ' . (string)($booking['vehicle_type'] ?? ''));
$customerModelYear = $booking['model_year'] ?? '';
$weeklyFilledDisplay = rtrim(rtrim(number_format($weeklyFilledLiters, 1, '.', ''), '0'), '.');
$fillableDisplay = rtrim(rtrim(number_format($fillableLiters, 1, '.', ''), '0'), '.');

// Loyalty preview for staff (balance + redeem intent + internal flag)
$loyaltyBalance = 0;
$pointsToRedeemIntent = 0;
$loyaltyWillBeInternal = false;
if (!empty($booking['user_id'])) {
    loyalty_schema_ready($pdo);
    $loyaltyBalance = loyalty_get_balance($pdo, (int) $booking['user_id']);
    try {
        $intentStmt = $pdo->prepare('SELECT COALESCE(points_to_redeem, 0) FROM booking WHERE booking_id = ? LIMIT 1');
        $intentStmt->execute([(int) ($booking['booking_id'] ?? 0)]);
        $pointsToRedeemIntent = (int) $intentStmt->fetchColumn();
    } catch (Throwable $e) {
        $pointsToRedeemIntent = 0;
    }
    try {
        $loyaltyWillBeInternal = loyalty_is_internal_fill(
            $pdo,
            (int) $booking['user_id'],
            (int) ($booking['station_id'] ?? 0)
        );
    } catch (Throwable $e) {
        $loyaltyWillBeInternal = false;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Fill Details - Map & Pump</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="icon" type="image/png" href="/images/Logo.png">
    <link rel="stylesheet" href="/css/Admin.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .detail-label { color: rgba(255,255,255,0.72); }
        .detail-value { color: #ffffff; font-weight: 700; }
        .dashboard-card { background: rgba(5, 11, 20, 0.92); border: 1px solid rgba(255,255,255,0.08); }
        .glass-input { background: rgba(255,255,255,0.05); color: #fff !important; }
        .glass-input::placeholder { color: rgba(255,255,255,0.65) !important; }
        .booked-customer { display:flex; align-items:center; gap:16px; padding:18px; border:1px solid rgba(56,189,248,.2); border-radius:14px; background:linear-gradient(135deg,rgba(56,189,248,.1),rgba(255,255,255,.025)); }
        .booked-customer { width:100%; min-height:92px; }
        .booking-summary-grid > [class*="col-"] { min-width:0; }
        .booking-summary-grid > [class*="col-"] > .booking-info-tile { width:100%; height:100%; min-height:116px; display:flex; flex-direction:column; justify-content:center; padding:18px !important; border:1px solid rgba(255,255,255,.08); border-radius:14px !important; background:rgba(255,255,255,.04); }
        .booking-info-tile .detail-value { overflow-wrap:anywhere; }
        .booked-customer-avatar { display:grid; place-items:center; flex:0 0 52px; width:52px; height:52px; border-radius:50%; color:#06111b; background:#38bdf8; font-size:21px; font-weight:800; }
        .booked-customer-label { color:#7dd3fc; font-size:10px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; }
        .booked-customer-name { margin:2px 0 3px; color:#fff; font-size:19px; font-weight:800; }
        .booked-customer-contact { color:rgba(255,255,255,.68); font-size:12px; overflow-wrap:anywhere; }
        .booked-vehicle { margin-left:auto; min-width:190px; padding:10px 12px; border-radius:10px; background:rgba(0,0,0,.2); }
        .booked-vehicle-label { color:rgba(255,255,255,.58); font-size:10px; font-weight:700; text-transform:uppercase; }
        .booked-vehicle-value { color:#fff; font-size:13px; font-weight:700; }
        @media(max-width:575px) { .booked-customer { align-items:flex-start; flex-wrap:wrap; } .booked-vehicle { width:100%; margin-left:68px; } }
    </style>
</head>
<body class="background_color" id="background_color">
    <div class="page-load-bar"></div>
    <div class="container sticky-nav-wrapper">
        <nav class="navbar navbar-dark text-white navigation_style">
            <div class="container-fluid d-flex justify-content-between align-items-center p-0 gap-3">
                <a class="navbar-brand d-flex align-items-center gap-2 fw-semibold txt-neon-cyan m-0" href="staff_dashboard.php" style="font-size: 0.95rem;">
                    <img src="/images/Logo.png" alt="Logo" class="nav-logo">
                    <span class="d-none d-sm-inline">Staff Portal</span>
                </a>

                <div class="admin-nav-actions">
                    <label class="cosmic-toggle nav-theme-toggle" title="Toggle light mode">
                        <input class="toggle js-theme-toggle" type="checkbox" aria-label="Toggle light mode" />
                        <div class="slider">
                            <span class="theme-icon theme-icon-dark"><i class="bi bi-moon-stars-fill"></i></span>
                            <span class="theme-icon theme-icon-light"><i class="bi bi-sun-fill"></i></span>
                            <div class="cosmos"></div>
                            <div class="energy-line"></div>
                            <div class="energy-line"></div>
                            <div class="energy-line"></div>
                            <div class="toggle-orb">
                                <div class="inner-orb"></div>
                                <div class="ring"></div>
                            </div>
                            <div class="particles">
                                <div style="--angle: 30deg" class="particle"></div>
                                <div style="--angle: 60deg" class="particle"></div>
                                <div style="--angle: 90deg" class="particle"></div>
                                <div style="--angle: 120deg" class="particle"></div>
                                <div style="--angle: 150deg" class="particle"></div>
                                <div style="--angle: 180deg" class="particle"></div>
                            </div>
                        </div>
                    </label>
                    <button class="btn btn-sm menu-pill-btn" type="button" data-bs-toggle="offcanvas" data-bs-target="#mainMenuOffcanvas">
                        <i class="bi bi-grid-fill me-1 txt-neon-cyan"></i> Menu
                    </button>
                </div>
            </div>
        </nav>
    </div>

    <div class="container my-5 text_color">
        <div class="row justify-content-center">
            <div class="col-12 col-sm-10 col-md-8 col-lg-7 px-3">
                <div class="mb-3 ms-1">
                    <a href="verify_qr.php" class="stat-chip text-decoration-none">
                        <i class="bi bi-arrow-left"></i> Back to Scanner
                    </a>
                </div>

                <div class="dashboard-card p-4 p-sm-5 mt-2">
                    <h2 class="fw-bold mb-2 text-white">Booking Fill Details</h2>
                    <p class="text-white-50 mb-4 small">Enter the fuel amount and confirm the customer fill.</p>

                    <?php if (!empty($flash)): ?>
                        <div class="alert alert-<?php echo $flash['success'] ? 'success' : 'danger'; ?> border-0 mb-4">
                            <?php echo htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($errorMessage)): ?>
                        <div class="alert alert-warning border-0"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php else: ?>
                        <div class="row g-3 mb-4 booking-summary-grid">
                            <div class="col-12">
                                <section class="booked-customer" aria-label="Booked customer details">
                                    <div class="booked-customer-avatar" aria-hidden="true"><?php echo htmlspecialchars($customerInitial, ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="min-w-0">
                                        <div class="booked-customer-label">Booked customer</div>
                                        <h3 class="booked-customer-name"><?php echo htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8'); ?></h3>
                                        <div class="booked-customer-contact"><?php echo htmlspecialchars($customerEmail, ENT_QUOTES, 'UTF-8'); ?> · Customer UID <?php echo htmlspecialchars((string)$customerUid, ENT_QUOTES, 'UTF-8'); ?></div>
                                    </div>
                                    <div class="booked-vehicle">
                                        <div class="booked-vehicle-label"><i class="bi bi-car-front-fill me-1"></i>Booked vehicle</div>
                                        <div class="booked-vehicle-value"><?php echo htmlspecialchars($customerPlate !== '' ? $customerPlate : 'Plate not available', ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div class="booked-customer-contact"><?php echo htmlspecialchars(trim($customerVehicle . ($customerModelYear !== '' ? ' · ' . $customerModelYear : '')) ?: 'Vehicle details not available', ENT_QUOTES, 'UTF-8'); ?></div>
                                    </div>
                                </section>
                            </div>
                            <div class="col-12 col-md-6">
                                <div class="booking-info-tile">
                                    <div class="detail-label mb-2">Oil Type</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($oilType, ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <div class="booking-info-tile">
                                    <div class="detail-label mb-2">Station</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($stationName, ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <div class="booking-info-tile">
                                    <div class="detail-label mb-2">Booking Code</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($bookingCode, ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="text-white-50 small mt-2">Reserved: <?php echo htmlspecialchars(rtrim(rtrim(number_format((float)($booking['requested_liters'] ?? 0), 2, '.', ''), '0'), '.') . ' L', ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <div class="booking-info-tile">
                                    <div class="detail-label mb-2">Filled This Week</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($weeklyFilledDisplay, ENT_QUOTES, 'UTF-8'); ?> / <?php echo htmlspecialchars(rtrim(rtrim(number_format($weeklyLimitLiters, 1, '.', ''), '0'), '.'), ENT_QUOTES, 'UTF-8'); ?> L</div>
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <div class="booking-info-tile">
                                    <div class="detail-label mb-2">Can Fill</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($fillableDisplay, ENT_QUOTES, 'UTF-8'); ?> L left</div>
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <div class="booking-info-tile">
                                    <div class="detail-label mb-2">Loyalty Balance</div>
                                    <div class="detail-value text-warning"><?php echo (int) $loyaltyBalance; ?> pts</div>
                                    <?php if (!empty($loyaltyWillBeInternal)): ?>
                                        <div class="text-warning small mt-2">Internal fill (own station/network) — 0 points will be awarded.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <div class="booking-info-tile">
                                    <div class="detail-label mb-2">Points to Redeem (this booking)</div>
                                    <div class="detail-value">
                                        <?php if (!empty($loyaltyWillBeInternal)): ?>
                                            0 pts
                                            <span class="text-white-50 small"> · internal fill — loyalty skipped</span>
                                        <?php else: ?>
                                            <?php echo (int) $pointsToRedeemIntent; ?> pts
                                            <?php if ((int) $pointsToRedeemIntent > 0): ?>
                                                <span class="text-white-50 small"> · will apply on confirm (earn points up to your tier cap, then EXP)</span>
                                            <?php else: ?>
                                                <span class="text-white-50 small"> · earn points up to your tier cap, then EXP</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <form action="verify_process.php" method="POST" class="text-start">
                            <input type="hidden" name="action" value="confirm">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($staffVerifyCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="booking_id" value="<?php echo htmlspecialchars($bookingId, ENT_QUOTES, 'UTF-8'); ?>">

                            <div class="mb-4">
                                <label for="amountLiters" class="form-label text-white-50">Amount (Liters)</label>
                                <input type="number" step="0.1" min="0.1" max="<?php echo htmlspecialchars((string)$maxFillLiters, ENT_QUOTES, 'UTF-8'); ?>" class="form-control glass-input border-0 px-3 py-3 text-white" id="amountLiters" name="amount_liters" placeholder="Enter actual dispensed liters" <?php echo ($isFilled || $maxFillLiters <= 0) ? 'disabled' : 'required'; ?> <?php echo $isFilled ? 'value="0"' : ''; ?> style="color:#fff;" />
                                <div class="form-text text-white-50">Enter the actual amount dispensed. Maximum: <?php echo htmlspecialchars(rtrim(rtrim(number_format($maxFillLiters, 2, '.', ''), '0'), '.'), ENT_QUOTES, 'UTF-8'); ?> L.</div>
                            </div>

                            <button type="submit" class="btn btn-neon w-100 py-2 rounded-pill" <?php echo ($isFilled || $fillableLiters <= 0) ? 'disabled' : ''; ?> >
                                <i class="bi bi-check-circle-fill me-2"></i> Confirm Fill
                            </button>

                            <?php if ($isFilled): ?>
                                <p class="text-success small mt-3">This booking is already marked as filled.</p>
                            <?php elseif ($fillableLiters <= 0): ?>
                                <p class="text-warning small mt-3">This customer has reached the weekly fuel limit.</p>
                            <?php endif; ?>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="offcanvas offcanvas-end" tabindex="-1" id="mainMenuOffcanvas" style="width: 320px; background: rgba(6, 12, 24, 0.98); backdrop-filter: blur(28px); border-left: 1px solid rgba(0, 180, 255, 0.18);">
        <div class="offcanvas-header pb-2 border-bottom border-white border-opacity-10">
            <div>
                <h5 class="offcanvas-title text-white fw-bold mb-0">Main Menu</h5>
                <p class="text-white-50 small mb-0" style="font-size:0.78rem;">Staff Portal</p>
            </div>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
        </div>
        <div class="offcanvas-body pt-3">
            <ul class="list-unstyled d-flex flex-column gap-2">
                <li><a href="staff_dashboard.php" class="menu-btn-nav"><i class="bi bi-house me-3 txt-neon-cyan"></i> Dashboard</a></li>
                <li><a href="verify_qr.php" class="menu-btn-nav"><i class="bi bi-qr-code-scan me-3 txt-neon-cyan"></i> Verify QR</a></li>
                <li><a href="feedback_manage.php" class="menu-btn-nav"><i class="bi bi-chat-left-heart me-3 txt-neon-cyan"></i> Feedback</a></li>
                <li><a href="staff_account.php" class="menu-btn-nav"><i class="bi bi-person-gear me-3 txt-neon-blue"></i> Account</a></li>
                <hr style="border-color: rgba(255, 255, 255, 0.08); margin: 4px 0;">
                <li>
                    <form action="../Login/logout.php" method="post" class="m-0">
                        <button type="submit" class="logout-btn-nav btn border-0 w-100">
                            <i class="bi bi-box-arrow-right me-2"></i> Logout
                        </button>
                    </form>
                </li>
            </ul>
        </div>
    </div>

    <footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map & Pump | All Rights Reserved</footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/js/Admin.js"></script>
</body>
</html>
