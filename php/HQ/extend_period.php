<?php
require_once __DIR__ . '/hq_guard.php';
require_once __DIR__ . '/../billing_config.php';
require_once __DIR__ . '/../loyalty_points.php';
require_once __DIR__ . '/../payment_transfers.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
$hqId = map_pump_current_hq_id($pdo, $userId);
$accounts = billing_payment_receiving_accounts();
$returnPages = ['dashboard.php', 'brand.php', 'station.php', 'pricing.php', 'report_from_manager.php', 'report_to_admin.php', 'account.php', 'profile.php'];
$returnToSource = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (string) ($_POST['return_to'] ?? $_GET['return_to'] ?? '')
    : (string) ($_GET['return_to'] ?? '');
$returnTo = basename($returnToSource);
if (!in_array($returnTo, $returnPages, true)) {
    $refererPath = parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH);
    $refererPage = is_string($refererPath) ? basename($refererPath) : '';
    $returnTo = in_array($refererPage, $returnPages, true) ? $refererPage : 'dashboard.php';
}
$message = '';
$error = '';

$hqStmt = $pdo->prepare(
    'SELECT headquarter_id, company_name, headquarter_email, headquarters_owner_id, service_expires_at
     FROM headquarter WHERE headquarter_id = ? LIMIT 1'
);
$hqStmt->execute([$hqId]);
$hq = $hqStmt->fetch(PDO::FETCH_ASSOC);
if (!$hq) {
    http_response_code(403);
    exit('The signed-in account is not linked to an HQ.');
}

$stationCountStmt = $pdo->prepare(
    'SELECT COUNT(*)
     FROM station_business_scope
     WHERE headquarter_id = ?'
);
$stationCountStmt->execute([$hqId]);
$stationCount = (int) $stationCountStmt->fetchColumn();
$stationCountInput = max(1, $stationCount);
$hqPointsReady = loyalty_countermeasures_schema_ready($pdo);
$hqPointBalance = $hqPointsReady ? company_loyalty_get_balance($pdo, $hqId) : 0;
$extensionYearsInput = 0;
$extensionMonthsInput = 1;

if (empty($_SESSION['hq_extension_csrf'])) {
    $_SESSION['hq_extension_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string) ($_POST['action'] ?? 'submit_payment');
        $usePoints = $action === 'use_points';
        if (!$usePoints && $action !== 'submit_payment') {
            throw new RuntimeException('Choose a valid extension payment method.');
        }
        if (!hash_equals((string) $_SESSION['hq_extension_csrf'], (string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Your session expired. Refresh the page and try again.');
        }
        if (!payment_transfer_schema_ready($pdo) || !payment_transfer_ensure_hq_service_expiry_schema($pdo)) {
            throw new RuntimeException('Payment and service-period storage is unavailable. Please try again later.');
        }

        $email = strtolower(trim((string) ($_POST['company_email'] ?? '')));
        $expectedEmail = strtolower(trim((string) $hq['headquarter_email']));
        $yearsValue = trim((string) ($_POST['years'] ?? ''));
        $monthsValue = trim((string) ($_POST['months'] ?? ''));
        $extensionYears = filter_var($yearsValue === '' ? '0' : $yearsValue, FILTER_VALIDATE_INT);
        $extensionMonths = filter_var($monthsValue === '' ? '0' : $monthsValue, FILTER_VALIDATE_INT);
        if ($extensionYears !== false) {
            $extensionYearsInput = $extensionYears;
        }
        if ($extensionMonths !== false) {
            $extensionMonthsInput = $extensionMonths;
        }
        $months = ($extensionYears !== false && $extensionMonths !== false)
            ? $extensionYears * 12 + $extensionMonths
            : false;
        $requestedStationCount = filter_var($_POST['station_count'] ?? null, FILTER_VALIDATE_INT);
        if ($requestedStationCount !== false) {
            $stationCountInput = $requestedStationCount;
        }
        $method = trim((string) ($_POST['payment_method'] ?? ''));
        if ($email === '' || $email !== $expectedEmail
            || $extensionYears === false || $extensionYears < 0 || $extensionYears > intdiv(BILLING_HQ_EXTENSION_MAX_MONTHS, 12)
            || $extensionMonths === false || $extensionMonths < 0 || $extensionMonths > 11
            || $months === false || $months < 1 || $months > BILLING_HQ_EXTENSION_MAX_MONTHS
            || $requestedStationCount === false || $requestedStationCount < 1 || $requestedStationCount > 1000000
            || (!$usePoints && !array_key_exists($method, $accounts))) {
            throw new RuntimeException('Use this HQ company email, enter 1 to 1,000,000 stations, choose a valid year/month period up to ' . BILLING_HQ_EXTENSION_MAX_MONTHS . ' months' . ($usePoints ? '.' : ', and select a payment method.'));
        }

        if (!$usePoints) {
            $file = $_FILES['receipt'] ?? null;
            if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || (int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > 5 * 1024 * 1024
                || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
                throw new RuntimeException('Upload a payment screenshot no larger than 5 MB.');
            }
            $receiptPath = (string) $file['tmp_name'];
            $mime = null;
            if (class_exists('finfo')) {
                try {
                    $detector = new finfo(FILEINFO_MIME_TYPE);
                    $detectedMime = $detector->file($receiptPath);
                    $mime = is_string($detectedMime) ? $detectedMime : null;
                } catch (Throwable) {
                    $mime = null;
                }
            }
            if ($mime === null && function_exists('mime_content_type')) {
                $detectedMime = @mime_content_type($receiptPath);
                $mime = is_string($detectedMime) ? $detectedMime : null;
            }
            if ($mime === null) {
                $signature = file_get_contents($receiptPath, false, null, 0, 16);
                if (is_string($signature)) {
                    if (str_starts_with($signature, "\xFF\xD8\xFF")) {
                        $mime = 'image/jpeg';
                    } elseif (str_starts_with($signature, "\x89PNG\x0D\x0A\x1A\x0A")) {
                        $mime = 'image/png';
                    } elseif (strlen($signature) >= 12 && substr($signature, 0, 4) === 'RIFF' && substr($signature, 8, 4) === 'WEBP') {
                        $mime = 'image/webp';
                    }
                }
            }
            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                throw new RuntimeException('The payment screenshot must be a JPG, PNG, or WEBP image.');
            }
        }

        $pointsRequired = BILLING_HQ_EXTENSION_POINTS_PER_STATION_MONTHLY * $requestedStationCount * $months;
        $termDiscount = 0;
        if (!$usePoints) {
            foreach (BILLING_HQ_EXTENSION_TERM_DISCOUNTS as $term) {
                if ($months >= (int) $term['min_months']) {
                    $termDiscount = (int) $term['percent'];
                    break;
                }
            }
        }
        $amount = round(BILLING_HQ_EXTENSION_MMK_PER_STATION_MONTHLY * $requestedStationCount * $months * (1 - $termDiscount / 100), 2);

        $pdo->beginTransaction();
        $lock = $pdo->prepare('SELECT service_expires_at FROM headquarter WHERE headquarter_id = ? FOR UPDATE');
        $lock->execute([$hqId]);
        $currentExpiry = $lock->fetchColumn();
        if ($currentExpiry === false) {
            throw new RuntimeException('The company account could not be verified.');
        }
        $pending = $pdo->prepare(
            "SELECT 1 FROM payment_transfers
             WHERE payer_headquarter_id = ? AND payment_type = 'hq_period_extension'
               AND status IN ('pending_payment', 'payer_reported_paid') LIMIT 1"
        );
        $pending->execute([$hqId]);
        if ($pending->fetchColumn()) {
            throw new RuntimeException('An extension payment is already awaiting review for this HQ.');
        }

        if ($usePoints) {
            if (!$hqPointsReady) {
                throw new RuntimeException('HQ points are temporarily unavailable. Please try again later.');
            }
            if (!company_loyalty_redeem_credit(
                $pdo,
                $hqId,
                $pointsRequired,
                sprintf('HQ service-period extension (%d stations, %d months)', $requestedStationCount, $months)
            )) {
                throw new RuntimeException('There are not enough HQ points for this extension.');
            }
            $now = new DateTimeImmutable();
            $start = $currentExpiry && new DateTimeImmutable((string) $currentExpiry) > $now
                ? new DateTimeImmutable((string) $currentExpiry)
                : $now;
            $newExpiry = payment_transfer_add_months_clamped($start, $months)->format('Y-m-d H:i:s');
            $expiryUpdate = $pdo->prepare('UPDATE headquarter SET service_expires_at = ? WHERE headquarter_id = ?');
            $expiryUpdate->execute([$newExpiry, $hqId]);
            $ownerStatus = $pdo->prepare(
                "UPDATE users u
                 INNER JOIN headquarter h ON h.headquarters_owner_id = u.user_id
                 SET u.status = 'active', u.updated_at = NOW()
                 WHERE h.headquarter_id = ? AND LOWER(COALESCE(u.status, '')) = 'expired'
                   AND (h.business_license_expire_date IS NULL OR h.business_license_expire_date = '' OR h.business_license_expire_date >= CURDATE())"
            );
            $ownerStatus->execute([$hqId]);
            $pdo->commit();
            $hq['service_expires_at'] = $newExpiry;
            $hqPointBalance = max(0, $hqPointBalance - $pointsRequired);
            $message = 'Service period extended through ' . $newExpiry . ' using ' . number_format($pointsRequired) . ' HQ points. Points payments do not receive term discounts.';
        } else {
            $transferId = payment_transfer_create($pdo, [
                'payment_type' => 'hq_period_extension',
                'amount_mmk' => $amount,
                'description' => sprintf('HQ service-period extension for %d station(s), %d month(s)', $requestedStationCount, $months),
                'quantity' => $requestedStationCount,
                'term_months' => $months,
                'payer_user_id' => $userId,
                'payer_email' => (string) $hq['headquarter_email'],
                'payer_headquarter_id' => $hqId,
                'recipient_role' => BILLING_PLATFORM_RECEIVER_ROLE,
                'payment_method' => $method,
            ]);
            $paidUpdate = $pdo->prepare("UPDATE payment_transfers SET status = 'payer_reported_paid', reported_paid_at = NOW(), paid_declared_at = NOW() WHERE payment_transfer_id = ?");
            $paidUpdate->execute([$transferId]);
            $receipt = $pdo->prepare(
                'INSERT INTO payment_transfer_receipts
                (payment_transfer_id, file_name, mime_type, file_size, file_data, uploaded_by_user_id, uploaded_by_email)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $receipt->bindValue(1, $transferId, PDO::PARAM_INT);
            $receipt->bindValue(2, substr(basename((string) ($file['name'] ?? 'payment-screenshot')), 0, 255));
            $receipt->bindValue(3, (string) $mime);
            $receipt->bindValue(4, (int) $file['size'], PDO::PARAM_INT);
            $receipt->bindValue(5, file_get_contents((string) $file['tmp_name']), PDO::PARAM_LOB);
            $receipt->bindValue(6, $userId, PDO::PARAM_INT);
            $receipt->bindValue(7, (string) $hq['headquarter_email']);
            $receipt->execute();
            payment_transfer_record_event($pdo, $transferId, 'pending_payment', 'payer_reported_paid', $userId, (string) $hq['headquarter_email'], 'HQ owner submitted an extension payment method and screenshot for staff review.');
            $pdo->commit();
            payment_transfer_send_notices($pdo, $transferId, 'recipient', 'payer_reported_paid');
            $message = 'Payment submitted. Your HQ service period will update after Admin or Moderator review.';
        }
        $_SESSION['hq_extension_flash'] = [
            'message' => $message,
            'show_payment_status' => !$usePoints,
        ];
        $returnUrl = '/php/HQ/index.php?extension_payment=submitted&return_to=' . rawurlencode($returnTo);
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Payment submitted</title></head><body>';
        echo '<p>Payment submitted. Returning to your previous page… <a href="' . htmlspecialchars($returnUrl, ENT_QUOTES, 'UTF-8') . '">Continue</a></p>';
        echo '<script>window.top.location.replace(' . json_encode($returnUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ');</script>';
        echo '</body></html>';
        exit;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('HQ service extension submission failed: ' . $exception->getMessage());
        $error = $exception->getMessage();
    }
}

$pendingStmt = $pdo->prepare(
    "SELECT amount_mmk, term_months, payment_method, status, created_at
     FROM payment_transfers
     WHERE payer_headquarter_id = ? AND payment_type = 'hq_period_extension'
       AND status IN ('pending_payment', 'payer_reported_paid')
     ORDER BY payment_transfer_id DESC LIMIT 1"
);
$pendingStmt->execute([$hqId]);
$pendingExtension = $pendingStmt->fetch(PDO::FETCH_ASSOC) ?: null;
$showPaymentForm = ($_GET['show_payment_form'] ?? '') === '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Extend service period | HQ</title>
    <link rel="stylesheet" href="../../css/HQstyle.css">
    <style>
        .extend-shell { max-width: 960px; margin: 0 auto; }
        .extend-header { display:flex; align-items:flex-start; justify-content:space-between; gap:24px; margin-bottom:24px; }
        .extend-title { margin:0 0 6px; font-size:1.55rem; }
        .extend-subtitle { margin:0; color:var(--muted); max-width:620px; }
        .extend-expiry { min-width:190px; padding:14px 16px; border:1px solid var(--border); border-radius:10px; background:var(--card-bg); }
        .extend-expiry span { display:block; color:var(--muted); font-size:.75rem; }
        .extend-expiry strong { display:block; margin-top:3px; font-variant-numeric:tabular-nums; }
        .extend-grid { display:grid; grid-template-columns:minmax(0,1.25fr) minmax(260px,.75fr); gap:18px; align-items:start; }
        .extend-card { padding:22px; border:1px solid var(--border); border-radius:12px; background:var(--card-bg); }
        .extend-card h2 { font-size:1rem; margin-bottom:16px; }
        .extend-card-header { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:16px; }
        .extend-card-header h2 { margin:0; }
        .hq-points-balance { padding:6px 10px; border:1px solid var(--border); border-radius:999px; color:var(--muted); font-size:.8rem; white-space:nowrap; }
        .hq-points-balance strong { color:var(--slate-50); font-variant-numeric:tabular-nums; }
        .extend-fields { display:grid; grid-template-columns:repeat(6,minmax(0,1fr)); gap:16px; }
        .extend-field-wide { grid-column:1/-1; }
        .extension-period-fieldset { grid-column:span 4; min-width:0; margin:0; padding:8px 12px 12px; border:1px solid var(--border); border-radius:8px; }
        .extension-period-fieldset legend { float:none; display:table; width:auto; margin:0; padding:0 6px; background:var(--card-bg); color:var(--slate-300); font-size:.9rem; font-weight:600; }
        .extension-period-inputs { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        .extension-period-legend { display:block; margin-top:8px; color:var(--muted); font-size:.8rem; }
        .payment-method-field { grid-column:span 2; }
        .payment-alternative { display:grid; gap:6px; margin-top:10px; text-align:center; }
        .payment-or { color:var(--muted); font-weight:600; }
        .extension-points-note { color:var(--muted); font-size:.75rem; }
        .transfer-number { display:flex; gap:10px; align-items:center; margin:8px 0; }
        .transfer-number strong { overflow-wrap:anywhere; font-size:1.05rem; }
        .transfer-copy { flex:0 0 auto; }
        .extend-total { display:flex; justify-content:space-between; gap:16px; padding:15px 0; border-top:1px solid var(--border); border-bottom:1px solid var(--border); font-variant-numeric:tabular-nums; }
        .extend-note { color:var(--muted); font-size:.8rem; }
        .extend-submit { width:100%; margin-top:16px; }
        .extend-status { margin-bottom:18px; }
        .extend-return-payment { margin-top:12px; }
        @media(max-width:760px) { body { padding:22px 16px; } .extend-header { display:block; } .extend-expiry { margin-top:16px; } .extend-grid { grid-template-columns:1fr; } }
        @media(max-width:480px) { .extend-fields { grid-template-columns:1fr; } .extend-field-wide,.extension-period-fieldset,.payment-method-field { grid-column:auto; } }
    </style>
</head>
<body>
<main class="extend-shell">
    <header class="extend-header">
        <div>
            <p class="hq-eyebrow">HQ billing</p>
            <h1 class="extend-title">Extend service period</h1>
            <p class="extend-subtitle">Choose how many months to add, transfer the displayed amount, and submit a screenshot for review.</p>
        </div>
        <div class="extend-expiry"><span>Current service expiry</span><strong><?= htmlspecialchars((string) ($hq['service_expires_at'] ?: 'No expiry for included stations'), ENT_QUOTES, 'UTF-8') ?></strong></div>
    </header>

    <?php if ($message !== ''): ?><div class="alert alert-success extend-status" role="status"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-danger extend-status" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($pendingExtension && !$showPaymentForm): ?>
        <div class="alert extend-status" role="status">
            An extension payment for <?= (int) $pendingExtension['term_months'] ?> month(s) is awaiting review: <?= number_format((float) $pendingExtension['amount_mmk'], 2) ?> MMK via <?= htmlspecialchars((string) $pendingExtension['payment_method'], ENT_QUOTES, 'UTF-8') ?>.
            <div><a class="btn btn-outline-secondary extend-return-payment" href="extend_period.php?show_payment_form=1">Return to payment</a></div>
        </div>
    <?php else: ?>
        <?php if ($pendingExtension): ?>
            <div class="alert extend-status" role="status">
                Your previous payment for <?= (int) $pendingExtension['term_months'] ?> month(s) is still awaiting review. You may review the payment form, but cannot submit another request until the pending payment is resolved.
                <a href="/php/payment_center.php">View payment status</a>.
            </div>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data" class="extend-grid" data-accounts="<?= htmlspecialchars(json_encode($accounts), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['hq_extension_csrf'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') ?>">
            <section class="extend-card">
                <div class="extend-card-header">
                    <h2>Payment details</h2>
                    <div class="hq-points-balance">Points balance: <strong><?= number_format($hqPointBalance) ?></strong></div>
                </div>
                <div class="extend-fields">
                    <div class="extend-field-wide">
                        <label for="company-email">Company email</label>
                        <input id="company-email" type="email" name="company_email" value="<?= htmlspecialchars((string) $hq['headquarter_email'], ENT_QUOTES, 'UTF-8') ?>" readonly required>
                    </div>
                    <div class="extend-field-wide">
                        <label for="station-count">Number of stations (<?= number_format(BILLING_HQ_EXTENSION_MMK_PER_STATION_MONTHLY) ?> MMK per station/month)</label>
                        <input id="station-count" type="number" name="station_count" min="1" max="1000000" step="1" value="<?= $stationCountInput ?>" required>
                    </div>
                    <fieldset class="extension-period-fieldset">
                        <legend>Extension period</legend>
                        <div class="extension-period-inputs">
                            <div>
                                <label for="extension-years">Years</label>
                                <input id="extension-years" type="number" name="years" min="0" max="<?= intdiv(BILLING_HQ_EXTENSION_MAX_MONTHS, 12) ?>" step="1" value="<?= $extensionYearsInput ?>" placeholder="e.g. 1" required>
                            </div>
                            <div>
                                <label for="extension-months">Months</label>
                                <input id="extension-months" type="number" name="months" min="0" max="11" step="1" value="<?= $extensionMonthsInput ?>" placeholder="0–11" required>
                            </div>
                        </div>
                        <small class="extension-period-legend">Enter years and months (for example, 1 year and 6 months). The total period must be 1–<?= BILLING_HQ_EXTENSION_MAX_MONTHS ?> months. 12+ months: 2% off; 60+ months: 15% off.</small>
                    </fieldset>
                    <div class="payment-method-field">
                        <label for="payment-method">Payment method</label>
                        <select id="payment-method" name="payment_method" required><option value="">Choose a method</option><?php foreach (array_keys($accounts) as $methodName): ?><option value="<?= htmlspecialchars($methodName, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($methodName, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select>
                        <div class="payment-alternative">
                            <span class="payment-or">or</span>
                            <button class="btn btn-outline-secondary extension-points-button" id="use-points" type="submit" name="action" value="use_points" formnovalidate data-balance="<?= $hqPointBalance ?>" data-ready="<?= $hqPointsReady ? '1' : '0' ?>" data-pending="<?= $pendingExtension ? '1' : '0' ?>" disabled>Use points</button>
                            <small class="extension-points-note" id="extension-points-cost"><?php if ($hqPointsReady): ?>Balance: <?= number_format($hqPointBalance) ?> points. Cost: <?= number_format(BILLING_HQ_EXTENSION_POINTS_PER_STATION_MONTHLY) ?> points per station/month; no term discount.<?php else: ?>HQ points are temporarily unavailable.<?php endif; ?></small>
                        </div>
                    </div>
                    <div class="extend-field-wide">
                        <label for="payment-screenshot">Payment screenshot (JPG, PNG, or WEBP; max 5 MB)</label>
                        <input id="payment-screenshot" type="file" name="receipt" accept="image/jpeg,image/png,image/webp" required>
                    </div>
                </div>
            </section>
            <aside class="extend-card">
                <h2>Transfer and total</h2>
                <p class="extend-note">Transfer to the account for your selected method.</p>
                <div class="transfer-number"><strong id="transfer-number">Choose a payment method</strong><button class="btn btn-outline-secondary transfer-copy" id="copy-number" type="button" disabled aria-label="Copy transfer account"><svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3"/></svg></button></div>
                <p class="extend-note">Account name: <?= htmlspecialchars(BILLING_PLATFORM_RECIPIENT_NAME, ENT_QUOTES, 'UTF-8') ?></p>
                <div class="extend-total"><span>Actual value</span><strong id="extension-actual"><?= number_format(BILLING_HQ_EXTENSION_MMK_PER_STATION_MONTHLY * $stationCountInput) ?> MMK</strong></div>
                <div class="extend-total"><span>Discount <span id="extension-discount-rate">(0%)</span></span><strong id="extension-discount">0 MMK</strong></div>
                <div class="extend-total"><span>Total due</span><strong id="extension-total"><?= number_format(BILLING_HQ_EXTENSION_MMK_PER_STATION_MONTHLY * $stationCountInput) ?> MMK</strong></div>
                <p class="extend-note" style="margin:14px 0 0">Longer terms receive configured discounts. Access updates after payment receipt is confirmed by an Admin or Moderator.</p>
                <button class="btn extend-submit" type="submit" name="action" value="submit_payment" <?= $pendingExtension ? 'disabled' : '' ?>>Submit payment for review</button>
            </aside>
        </form>
    <?php endif; ?>
</main>
<script>
(() => {
    const form = document.querySelector('form[data-accounts]');
    if (!form) return;
    const accounts = JSON.parse(form.dataset.accounts || '{}');
    const method = document.getElementById('payment-method');
    const years = document.getElementById('extension-years');
    const months = document.getElementById('extension-months');
    const stationCount = document.getElementById('station-count');
    const number = document.getElementById('transfer-number');
    const copy = document.getElementById('copy-number');
    const usePoints = document.getElementById('use-points');
    const pointsCost = document.getElementById('extension-points-cost');
    const actual = document.getElementById('extension-actual');
    const discountValue = document.getElementById('extension-discount');
    const discountRate = document.getElementById('extension-discount-rate');
    const total = document.getElementById('extension-total');
    const termDiscounts = <?= json_encode(BILLING_HQ_EXTENSION_TERM_DISCOUNTS) ?>;
    const moneyFormat = new Intl.NumberFormat();
    const pointsBalance = Number(usePoints.dataset.balance || 0);
    const pointsWalletReady = usePoints.dataset.ready === '1';
    const hasPendingPayment = usePoints.dataset.pending === '1';
    const validatePeriod = () => {
        const totalMonths = Number(years.value) * 12 + Number(months.value);
        const valid = Number.isInteger(totalMonths) && totalMonths >= 1 && totalMonths <= <?= (int) BILLING_HQ_EXTENSION_MAX_MONTHS ?>;
        months.setCustomValidity(valid ? '' : 'The combined extension period must be between 1 and <?= (int) BILLING_HQ_EXTENSION_MAX_MONTHS ?> months.');
        return totalMonths;
    };
    const update = () => {
        const account = accounts[method.value] || {};
        number.textContent = account.number || 'Choose a payment method';
        copy.disabled = !account.number;
        const totalMonths = validatePeriod();
        const stationCountValue = Number(stationCount.value);
        const pointsRequired = <?= (int) BILLING_HQ_EXTENSION_POINTS_PER_STATION_MONTHLY ?> * stationCountValue * totalMonths;
        const pointsInputsValid = Number.isInteger(totalMonths) && totalMonths >= 1 && totalMonths <= <?= (int) BILLING_HQ_EXTENSION_MAX_MONTHS ?>
            && Number.isInteger(stationCountValue) && stationCountValue >= 1 && stationCountValue <= 1000000;
        pointsCost.textContent = pointsWalletReady
            ? `Balance: ${moneyFormat.format(pointsBalance)} points. Cost: ${moneyFormat.format(pointsRequired)} points; no term discount.`
            : 'HQ points are temporarily unavailable.';
        usePoints.disabled = hasPendingPayment || !pointsWalletReady || !pointsInputsValid || pointsRequired > pointsBalance;
        const discount = termDiscounts.find((term) => totalMonths >= Number(term.min_months));
        const actualAmount = <?= (int) BILLING_HQ_EXTENSION_MMK_PER_STATION_MONTHLY ?> * stationCountValue * totalMonths;
        const percent = Number(discount?.percent || 0);
        const savings = actualAmount * percent / 100;
        const amount = actualAmount - savings;
        actual.textContent = `${moneyFormat.format(actualAmount)} MMK`;
        discountRate.textContent = `(${percent}% off)`;
        discountValue.textContent = `${moneyFormat.format(savings)} MMK`;
        total.textContent = `${moneyFormat.format(amount)} MMK`;
    };
    method.addEventListener('change', update);
    years.addEventListener('input', update);
    months.addEventListener('change', update);
    months.addEventListener('input', update);
    form.addEventListener('submit', event => {
        if (event.submitter === usePoints) {
            const totalMonths = Number(years.value) * 12 + Number(months.value);
            const pointsRequired = <?= (int) BILLING_HQ_EXTENSION_POINTS_PER_STATION_MONTHLY ?> * Number(stationCount.value) * totalMonths;
            if (!window.confirm(`Use ${moneyFormat.format(pointsRequired)} HQ points to extend service for ${totalMonths} month(s)? Points payments do not receive term discounts.`)) {
                event.preventDefault();
            }
            return;
        }
        if (!form.checkValidity()) {
            event.preventDefault();
            form.reportValidity();
        }
    });
    update();
    stationCount.addEventListener('input', update);
    copy.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(number.textContent);
            copy.setAttribute('aria-label', 'Transfer account copied');
        } catch (error) {
            copy.setAttribute('aria-label', 'Unable to copy transfer account');
        }
    });
})();
</script>
</body>
</html>