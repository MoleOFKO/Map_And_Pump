<?php
require_once __DIR__ . '/hq_guard.php';
require_once __DIR__ . '/../billing_config.php';
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
    "SELECT COUNT(*)
     FROM station_business_scope sbs
     INNER JOIN branches b ON b.branch_id = sbs.station_id
     WHERE sbs.headquarter_id = ? AND LOWER(COALESCE(b.status, 'active')) <> 'closed'"
);
$stationCountStmt->execute([$hqId]);
$stationCount = (int) $stationCountStmt->fetchColumn();
$stationCountInput = max(1, $stationCount);
$packageRate = billing_admin_setting($pdo, 'hq_package_mmk_per_station_month', BILLING_HQ_PACKAGE_MMK_PER_STATION_MONTHLY);
$extensionYearsInput = 0;
$extensionMonthsInput = 1;

if (empty($_SESSION['hq_extension_csrf'])) {
    $_SESSION['hq_extension_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string) ($_POST['action'] ?? 'submit_payment');
        $cancelUnreportedPayment = $action === 'cancel_unreported_payment';
        if (!$cancelUnreportedPayment && $action !== 'submit_payment') {
            throw new RuntimeException('Choose a valid MMK service-package payment action.');
        }
        if (!hash_equals((string) $_SESSION['hq_extension_csrf'], (string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Your session expired. Refresh the page and try again.');
        }
        if (!payment_transfer_schema_ready($pdo) || !payment_transfer_ensure_hq_service_expiry_schema($pdo)) {
            throw new RuntimeException('Payment and service-period storage is unavailable. Please try again later.');
        }

        if ($cancelUnreportedPayment) {
            $pdo->beginTransaction();
            $cancelStmt = $pdo->prepare(
                "SELECT payment_transfer_id
                 FROM payment_transfers
                 WHERE payer_headquarter_id = ? AND payment_type IN ('hq_station_package', 'hq_period_extension')
                   AND status = 'pending_payment'
                 ORDER BY payment_transfer_id DESC
                 LIMIT 1 FOR UPDATE"
            );
            $cancelStmt->execute([$hqId]);
            $transferId = $cancelStmt->fetchColumn();
            if ($transferId === false) {
                throw new RuntimeException('This payment has already been reported or is no longer available to cancel.');
            }
            $cancelUpdate = $pdo->prepare(
                "UPDATE payment_transfers SET status = 'cancelled'
                 WHERE payment_transfer_id = ? AND payer_headquarter_id = ?
                   AND payment_type IN ('hq_station_package', 'hq_period_extension') AND status = 'pending_payment'"
            );
            $cancelUpdate->execute([(int) $transferId, $hqId]);
            if ($cancelUpdate->rowCount() !== 1) {
                throw new RuntimeException('This payment request has already changed.');
            }
            payment_transfer_record_event(
                $pdo,
                (int) $transferId,
                'pending_payment',
                'cancelled',
                $userId > 0 ? $userId : null,
                strtolower((string) $hq['headquarter_email']),
                'HQ payer cancelled the unreported service extension payment request.'
            );
            $pdo->commit();
            payment_transfer_send_notices($pdo, (int) $transferId, 'recipient', 'cancelled');

            $_SESSION['hq_extension_flash'] = [
                'message' => 'The unreported extension payment request was cancelled.',
                'show_payment_status' => false,
            ];
            $returnUrl = '/php/HQ/index.php?extension_payment=cancelled&return_to=' . rawurlencode($returnTo);
            header('Content-Type: text/html; charset=UTF-8');
            header('Cache-Control: no-store');
            echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Payment request cancelled</title></head><body>';
            echo '<p>Payment request cancelled. Returning to your previous page… <a href="' . htmlspecialchars($returnUrl, ENT_QUOTES, 'UTF-8') . '">Continue</a></p>';
            echo '<script>window.top.location.replace(' . json_encode($returnUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ');</script>';
            echo '</body></html>';
            exit;
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
            || $stationCount < 1 || $requestedStationCount === false || $requestedStationCount !== $stationCount
            || !array_key_exists($method, $accounts)) {
            throw new RuntimeException('Use this HQ company email, include all current stations, choose a valid period up to ' . BILLING_HQ_EXTENSION_MAX_MONTHS . ' months, and select a payment method.');
        }

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

        $termDiscount = 0;
        foreach (BILLING_HQ_EXTENSION_TERM_DISCOUNTS as $term) {
            if ($months >= (int) $term['min_months']) {
                $termDiscount = (int) $term['percent'];
                break;
            }
        }
        $amount = round($packageRate * $requestedStationCount * $months * (1 - $termDiscount / 100), 2);

        $pdo->beginTransaction();
        $lock = $pdo->prepare('SELECT service_expires_at FROM headquarter WHERE headquarter_id = ? FOR UPDATE');
        $lock->execute([$hqId]);
        $currentExpiry = $lock->fetchColumn();
        if ($currentExpiry === false) {
            throw new RuntimeException('The company account could not be verified.');
        }
        $lockedStationCountStmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM station_business_scope sbs
             INNER JOIN branches b ON b.branch_id = sbs.station_id
             WHERE sbs.headquarter_id = ? AND LOWER(COALESCE(b.status, 'active')) <> 'closed'"
        );
        $lockedStationCountStmt->execute([$hqId]);
        if ((int) $lockedStationCountStmt->fetchColumn() !== $requestedStationCount) {
            throw new RuntimeException('The station count changed while you were preparing payment. Refresh and submit the package again.');
        }
        $pending = $pdo->prepare(
            "SELECT 1 FROM payment_transfers
             WHERE payer_headquarter_id = ? AND payment_type IN ('hq_station_package', 'hq_period_extension')
               AND status IN ('pending_payment', 'payer_reported_paid') LIMIT 1"
        );
        $pending->execute([$hqId]);
        if ($pending->fetchColumn()) {
            throw new RuntimeException('An extension payment is already awaiting review for this HQ.');
        }

        $transferId = payment_transfer_create($pdo, [
                'payment_type' => 'hq_station_package',
                'amount_mmk' => $amount,
                'description' => sprintf('All-in-one HQ station package for %d station(s), %d month(s)', $requestedStationCount, $months),
                'quantity' => $requestedStationCount,
                'term_months' => $months,
                'service_start_month' => date('Y-m'),
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
            $receipt->bindValue(6, $userId > 0 ? $userId : null, $userId > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $receipt->bindValue(7, (string) $hq['headquarter_email']);
            $receipt->execute();
            payment_transfer_record_event($pdo, $transferId, 'pending_payment', 'payer_reported_paid', $userId > 0 ? $userId : null, (string) $hq['headquarter_email'], 'HQ owner submitted an extension payment method and screenshot for staff review.');
            $pdo->commit();
            payment_transfer_send_notices($pdo, $transferId, 'recipient', 'payer_reported_paid');
            $message = 'Payment submitted. Your station package and service period update after Admin or Moderator review.';
        $_SESSION['hq_extension_flash'] = [
            'message' => $message,
            'show_payment_status' => true,
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
    "SELECT payment_transfer_id, amount_mmk, term_months, payment_method, status, created_at
     FROM payment_transfers
     WHERE payer_headquarter_id = ?
       AND (
           payment_type = 'hq_period_extension'
           OR (
               payment_type = 'hq_station_package'
               AND description NOT LIKE '%additional capacity slot(s)%'
           )
       )
       AND status IN ('pending_payment', 'payer_reported_paid')
     ORDER BY payment_transfer_id DESC LIMIT 1"
);
$pendingStmt->execute([$hqId]);
$pendingExtension = $pendingStmt->fetch(PDO::FETCH_ASSOC) ?: null;
$latestPaymentStmt = $pdo->prepare(
    "SELECT payment_transfer_id, reference_code, status, decision_note
     FROM payment_transfers
     WHERE payer_headquarter_id = ?
      AND (
          payment_type = 'hq_period_extension'
          OR (
              payment_type = 'hq_station_package'
              AND description NOT LIKE '%additional capacity slot(s)%'
          )
      )
    ORDER BY payment_transfer_id DESC
    LIMIT 1"
);
$latestPaymentStmt->execute([$hqId]);
$latestPayment = $latestPaymentStmt->fetch(PDO::FETCH_ASSOC) ?: null;
$latestPaymentId = (int) ($latestPayment['payment_transfer_id'] ?? 0);
$latestPaymentRejection = null;
$latestPaymentSuccess = false;
if ($latestPaymentId > 0
    && (int) ($_SESSION['hq_service_extension_final_payment_notice_seen'] ?? 0) !== $latestPaymentId) {
    if (($latestPayment['status'] ?? '') === 'rejected') {
        $latestPaymentRejection = $latestPayment;
        $_SESSION['hq_service_extension_final_payment_notice_seen'] = $latestPaymentId;
    } elseif (($latestPayment['status'] ?? '') === 'received') {
        $latestPaymentSuccess = true;
        $_SESSION['hq_service_extension_final_payment_notice_seen'] = $latestPaymentId;
    }
}
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
        .extension-points-error { margin:8px 0 0; color:#dc3545; font-size:.85rem; }
        .transfer-number { display:flex; gap:10px; align-items:center; margin:8px 0; }
        .transfer-number strong { overflow-wrap:anywhere; font-size:1.05rem; }
        .transfer-copy { flex:0 0 auto; }
        .extend-total { display:flex; justify-content:space-between; gap:16px; padding:15px 0; border-top:1px solid var(--border); border-bottom:1px solid var(--border); font-variant-numeric:tabular-nums; }
        .extend-note { color:var(--muted); font-size:.8rem; }
        .extend-submit { width:100%; margin-top:16px; }
        .extend-status { margin-bottom:18px; }
        .extend-return-payment { margin-top:12px; }
        .receipt-dialog { width:min(720px, calc(100% - 32px)); max-width:none; max-height:calc(100% - 32px); padding:0; border:1px solid var(--border); border-radius:14px; color:var(--slate-50); background:var(--card-bg); box-shadow:0 24px 80px rgba(0,0,0,.45); }
        .receipt-dialog::backdrop { background:rgba(5,10,20,.72); backdrop-filter:blur(3px); }
        .receipt-dialog-header,.receipt-dialog-footer { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:18px 22px; }
        .receipt-dialog-header { border-bottom:1px solid var(--border); }
        .receipt-dialog-header h2 { margin:0; font-size:1.15rem; }
        .receipt-dialog-close { padding:0 4px; border:0; color:var(--muted); background:transparent; font-size:1.6rem; line-height:1; }
        .cancel-payment-button { border-color:#dc3545 !important; color:#dc3545 !important; }
        .cancel-payment-button:hover { background:#dc3545 !important; color:#fff !important; }
        .receipt-dialog-body { display:grid; gap:14px; overflow:auto; padding:20px 22px; }
        .receipt-summary-card { padding:22px; }
        .receipt-summary-card .extend-total { padding:10px 0; }
        .receipt-company-details { margin:0 0 18px; color:var(--muted); font-size:.85rem; line-height:1.6; }
        .receipt-transfer-method { margin:0 0 6px; color:var(--muted); font-size:.85rem; }
        .receipt-dialog-footer { justify-content:flex-end; flex-wrap:nowrap; border-top:1px solid var(--border); }
        .receipt-dialog-footer .btn { flex:1 1 0; min-width:0; padding:.55rem .3rem; font-size:clamp(.62rem, 1.5vw, .82rem); white-space:nowrap; }
        @media(max-width:480px) { .receipt-dialog-header,.receipt-dialog-body,.receipt-dialog-footer { padding-left:16px; padding-right:16px; } .receipt-dialog-footer { gap:6px; } }
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
    <?php if ($latestPaymentRejection !== null): ?>
        <div id="final-payment-status-alert" class="alert alert-danger extend-status" role="alert">
            Your HQ payment request was rejected.
            Reason: <?= htmlspecialchars((string) ($latestPaymentRejection['decision_note'] ?: 'No reason was provided by the reviewer.'), ENT_QUOTES, 'UTF-8') ?>
            <a href="/php/payment_center.php" target="_top">View payment status</a>.
        </div>
    <?php elseif ($latestPaymentSuccess): ?>
        <div id="final-payment-status-alert" class="alert alert-success extend-status" role="status">
            Your HQ payment was approved and the service package has been applied.
            <a href="/php/payment_center.php" target="_top">View payment status</a>.
        </div>
    <?php endif; ?>
    <?php if ($latestPaymentRejection !== null || $latestPaymentSuccess): ?>
        <script>
            window.setTimeout(() => {
                document.getElementById('final-payment-status-alert')?.remove();
            }, 10000);
        </script>
    <?php endif; ?>
    <?php if ($pendingExtension && !$showPaymentForm): ?>
        <div class="alert extend-status" role="status">
            A service-package payment for <?= (int) $pendingExtension['term_months'] ?> month(s) is <?= $pendingExtension['status'] === 'pending_payment' ? 'awaiting payment' : 'awaiting review' ?>: <?= number_format((float) $pendingExtension['amount_mmk'], 2) ?> MMK via <?= htmlspecialchars((string) $pendingExtension['payment_method'], ENT_QUOTES, 'UTF-8') ?>.
            <?php if ($pendingExtension['status'] === 'pending_payment'): ?>
                <p class="extend-note mt-2 mb-0">This request has not been reported as paid yet, so it can still be cancelled.</p>
                <form method="post" class="extend-return-payment" onsubmit="return confirm('Cancel this unreported service-package payment request?');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['hq_extension_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') ?>">
                    <button class="btn btn-outline-secondary cancel-payment-button" type="submit" name="action" value="cancel_unreported_payment">Cancel payment request</button>
                </form>
            <?php else: ?>
                <p class="extend-note mt-2 mb-0">Payment has already been reported and is awaiting review, so it cannot be cancelled here.</p>
                <div><a class="btn btn-outline-secondary extend-return-payment" href="extend_period.php?show_payment_form=1">Return to payment</a></div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <?php if ($pendingExtension): ?>
            <div class="alert extend-status" role="status">
                <?php if ($pendingExtension['status'] === 'pending_payment'): ?>
                    Your previous service-package payment request for <?= (int) $pendingExtension['term_months'] ?> month(s) is awaiting payment. You cannot submit another request until it is resolved.
                    <form method="post" class="extend-return-payment" onsubmit="return confirm('Cancel this unreported service-package payment request?');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['hq_extension_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') ?>">
                        <button class="btn btn-outline-secondary cancel-payment-button" type="submit" name="action" value="cancel_unreported_payment">Cancel payment request</button>
                    </form>
                <?php else: ?>
                    Your previous payment for <?= (int) $pendingExtension['term_months'] ?> month(s) has been reported and is awaiting review. You may review the payment form, but cannot submit another request until the payment is resolved.
                    <a href="/php/payment_center.php">View payment status</a>.
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data" class="extend-grid" data-accounts="<?= htmlspecialchars(json_encode($accounts), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['hq_extension_csrf'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') ?>">
            <section class="extend-card">
                <div class="extend-card-header">
                    <h2>Service-package details</h2>
                </div>
                <div class="extend-fields">
                    <div class="extend-field-wide">
                        <label for="company-email">Company email</label>
                        <input id="company-email" type="email" name="company_email" value="<?= htmlspecialchars((string) $hq['headquarter_email'], ENT_QUOTES, 'UTF-8') ?>" readonly required>
                    </div>
                    <div class="extend-field-wide">
                        <label for="station-count">All current stations (<?= number_format($packageRate) ?> MMK per station/month)</label>
                        <input id="station-count" type="number" name="station_count" min="1" max="<?= max(1, $stationCount) ?>" step="1" value="<?= $stationCountInput ?>" readonly required>
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
                        <small class="extension-period-legend">This package includes service extension, station operation, and extra capacity. Enter years and months (for example, 1 year and 6 months). The total period must be 1–<?= BILLING_HQ_EXTENSION_MAX_MONTHS ?> months. 1+ years: 2% off; 5+ years: 15% off.</small>
                    </fieldset>
                    <div class="payment-method-field">
                        <label for="payment-method">Payment method</label>
                        <select id="payment-method" name="payment_method" required><option value="">Choose a method</option><?php foreach (array_keys($accounts) as $methodName): ?><option value="<?= htmlspecialchars($methodName, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($methodName, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select>
                    </div>
                    <div class="extend-field-wide">
                        <label for="payment-screenshot">Payment screenshot (JPG, PNG, or WEBP; max 5 MB)</label>
                        <input id="payment-screenshot" type="file" name="receipt" accept="image/jpeg,image/png,image/webp" required>
                    </div>
                </div>
            </section>
            <aside class="extend-card">
                <h2>Transfer and package total</h2>
                <p class="extend-note">Transfer the package amount to the account for your selected method.</p>
                <div class="transfer-number"><strong id="transfer-number">Choose a payment method</strong><button class="btn btn-outline-secondary transfer-copy" id="copy-number" type="button" disabled aria-label="Copy transfer account"><svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3"/></svg></button></div>
                <p class="extend-note">Account name: <?= htmlspecialchars(BILLING_PLATFORM_RECIPIENT_NAME, ENT_QUOTES, 'UTF-8') ?></p>
                <div class="extend-total"><span>Actual value</span><strong id="extension-actual"><?= number_format($packageRate * $stationCountInput) ?> MMK</strong></div>
                <div class="extend-total"><span>Discount <span id="extension-discount-rate">(0%)</span></span><strong id="extension-discount">0 MMK</strong></div>
                <div class="extend-total"><span>Total due</span><strong id="extension-total"><?= number_format($packageRate * $stationCountInput) ?> MMK</strong></div>
                <p class="extend-note" style="margin:14px 0 0">Longer terms receive configured discounts. Access updates after payment receipt is confirmed by an Admin or Moderator.</p>
                <button class="btn extend-submit" id="submit-payment" type="submit" name="action" value="submit_payment" <?= $pendingExtension ? 'disabled' : '' ?>>Submit payment for review</button>
            </aside>
        </form>
        <dialog class="receipt-dialog" id="payment-confirmation" aria-labelledby="payment-confirmation-title">
            <div class="receipt-dialog-header">
                <h2 id="payment-confirmation-title">Review payment details</h2>
                <button type="button" class="receipt-dialog-close" id="close-payment-confirmation" aria-label="Close">×</button>
            </div>
            <div class="receipt-dialog-body">
                <section class="extend-card receipt-summary-card">
                    <h2>Transfer and total</h2>
                    <p class="receipt-company-details">
                        <strong id="receipt-company"></strong><br>
                        <span id="receipt-email"></span><br>
                        <span><span id="receipt-stations"></span> stations · <span id="receipt-period"></span></span>
                    </p>
                    <div id="receipt-transfer-details">
                        <p class="receipt-transfer-method">Payment method: <strong id="receipt-method"></strong></p>
                        <div class="transfer-number"><strong id="receipt-account"></strong></div>
                        <p class="extend-note">Account name: <?= htmlspecialchars(BILLING_PLATFORM_RECIPIENT_NAME, ENT_QUOTES, 'UTF-8') ?></p>
                        <div class="extend-total"><span>Actual value</span><strong id="receipt-actual"></strong></div>
                        <div class="extend-total"><span>Discount <span id="receipt-discount-rate"></span></span><strong id="receipt-discount"></strong></div>
                        <div class="extend-total"><span>Total due</span><strong id="receipt-total"></strong></div>
                    </div>
                </section>
                <p class="extend-note mb-0">Download or print this receipt for your records. Confirm to submit the payment screenshot and details for review.</p>
            </div>
            <div class="receipt-dialog-footer">
                <button type="button" class="btn btn-outline-secondary" id="download-payment-receipt" title="Download receipt as HTML" aria-label="Download receipt as HTML">Download</button>
                <button type="button" class="btn btn-outline-secondary" id="print-payment-receipt" title="Print or save receipt as PDF" aria-label="Print or save receipt as PDF">Print PDF</button>
                <button type="button" class="btn btn-outline-secondary" id="cancel-payment-confirmation">Back</button>
                <button type="button" class="btn btn-success" id="confirm-payment-submission" title="Confirm and submit payment for review" aria-label="Confirm and submit payment for review">Submit</button>
            </div>
        </dialog>
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
    const actual = document.getElementById('extension-actual');
    const discountValue = document.getElementById('extension-discount');
    const discountRate = document.getElementById('extension-discount-rate');
    const total = document.getElementById('extension-total');
    const submitPayment = document.getElementById('submit-payment');
    const confirmation = document.getElementById('payment-confirmation');
    const confirmSubmission = document.getElementById('confirm-payment-submission');
    const confirmationTitle = document.getElementById('payment-confirmation-title');
    const transferDetails = document.getElementById('receipt-transfer-details');
    const receiptNote = confirmation.querySelector('.receipt-dialog-body > .extend-note');
    const confirmationValues = {
        company: <?= json_encode((string) $hq['company_name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        email: <?= json_encode((string) $hq['headquarter_email'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        recipient: <?= json_encode(BILLING_PLATFORM_RECIPIENT_NAME, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
    };
    const termDiscounts = <?= json_encode(BILLING_HQ_EXTENSION_TERM_DISCOUNTS) ?>;
    const moneyFormat = new Intl.NumberFormat();
    let confirmedAction = null;
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
        const discount = termDiscounts.find((term) => totalMonths >= Number(term.min_months));
        const actualAmount = <?= (int) $packageRate ?> * stationCountValue * totalMonths;
        const percent = Number(discount?.percent || 0);
        const savings = actualAmount * percent / 100;
        const amount = actualAmount - savings;
        actual.textContent = `${moneyFormat.format(actualAmount)} MMK`;
        discountRate.textContent = `(${percent}% off)`;
        discountValue.textContent = `${moneyFormat.format(savings)} MMK`;
        total.textContent = `${moneyFormat.format(amount)} MMK`;
    };
    const receiptData = () => {
        const account = accounts[method.value] || {};
        const totalMonths = Number(years.value) * 12 + Number(months.value);
        const discount = termDiscounts.find((term) => totalMonths >= Number(term.min_months));
        const yearCount = Math.floor(totalMonths / 12);
        const monthCount = totalMonths % 12;
        const period = [
            yearCount ? `${yearCount} year${yearCount === 1 ? '' : 's'}` : '',
            monthCount ? `${monthCount} month${monthCount === 1 ? '' : 's'}` : ''
        ].filter(Boolean).join(' ');
        return {
            company: confirmationValues.company,
            email: confirmationValues.email,
            stations: moneyFormat.format(Number(stationCount.value)),
            period: period || `${totalMonths} months`,
            method: method.options[method.selectedIndex]?.text || '',
            accountNumber: account.number || '',
            recipient: confirmationValues.recipient,
            actual: actual.textContent,
            discountRate: `${Number(discount?.percent || 0)}%`,
            discount: discountValue.textContent,
            total: total.textContent,
            createdAt: new Date().toLocaleString()
        };
    };
    const escapeHtml = value => String(value).replace(/[&<>"']/g, character => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
    })[character]);
    const receiptDocument = data => {
        const paymentDetails = `<p class="method">Payment method: <strong>${escapeHtml(data.method)}</strong></p>
<div class="account"><strong>${escapeHtml(data.accountNumber)}</strong></div>
<p class="note">Account name: ${escapeHtml(data.recipient)}</p>
<div class="total"><span>Actual value</span><strong>${escapeHtml(data.actual)}</strong></div>
<div class="total"><span>Discount (${escapeHtml(data.discountRate)})</span><strong>${escapeHtml(data.discount)}</strong></div>
<div class="total"><span>Total due</span><strong>${escapeHtml(data.total)}</strong></div>`;
        return `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>HQ extension summary</title><style>
:root{color-scheme:dark}
*{box-sizing:border-box}
@page{size:A4;margin:18mm}
body{margin:0;padding:24px;background:#0c1422;color:#edf3fb;font:14px/1.5 Arial,sans-serif;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.receipt{width:100%;max-width:620px;margin:24px auto}
.header{display:flex;align-items:center;min-height:54px;padding:16px 22px;border:1px solid #29394e;border-radius:14px 14px 0 0;background:#142033}
.header h1{margin:0;font-size:18px;font-weight:600}
.card{padding:22px;border:1px solid #29394e;border-top:0;border-radius:0 0 14px 14px;background:#142033;break-inside:avoid}
.card h2{margin:0 0 16px;font-size:16px;font-weight:600}
.company{margin:0 0 18px;color:#a1b1c6;font-size:12px;line-height:1.6}
.company strong{color:#edf3fb;font-size:14px}
.method{margin:0 0 6px;color:#a1b1c6;font-size:12px}
.account{display:flex;align-items:center;margin:8px 0;font-size:17px;overflow-wrap:anywhere}
.note{margin:0 0 14px;color:#a1b1c6;font-size:12px}
.total{display:flex;justify-content:space-between;gap:16px;padding:10px 0;border-top:1px solid #29394e;border-bottom:1px solid #29394e;font-variant-numeric:tabular-nums}
.total span{color:#a1b1c6}.total strong{text-align:right;overflow-wrap:anywhere}
.disclaimer{margin:18px 0 0;color:#a1b1c6;font-size:11px}
@media print{body{padding:0;background:#0c1422}.receipt{max-width:none;margin:0}.header,.card{background:#142033!important;color:#edf3fb!important;box-shadow:none}}
</style></head><body><main class="receipt">
<header class="header"><h1>Review payment details</h1></header>
<section class="card"><h2>Transfer and total</h2>
<p class="company"><strong>${escapeHtml(data.company)}</strong><br>
${escapeHtml(data.email)}<br>
${escapeHtml(data.stations)} stations · ${escapeHtml(data.period)}</p>
${paymentDetails}
<p class="disclaimer">Prepared ${escapeHtml(data.createdAt)}. This summary records the extension details and is not proof of payment confirmation.</p>
</section></main></body></html>`;
    };
    const updateConfirmation = () => {
        const data = receiptData();
        confirmationTitle.textContent = 'Review package payment';
        confirmation.querySelector('.receipt-summary-card h2').textContent = 'Transfer and package total';
        transferDetails.hidden = false;
        receiptNote.textContent = 'Download or print this receipt for your records. Confirm to submit the payment screenshot and package details for review.';
        confirmSubmission.textContent = 'Submit';
        confirmSubmission.setAttribute('aria-label', 'Confirm and submit package payment for review');
        document.getElementById('receipt-company').textContent = data.company;
        document.getElementById('receipt-email').textContent = data.email;
        document.getElementById('receipt-stations').textContent = data.stations;
        document.getElementById('receipt-period').textContent = data.period;
        document.getElementById('receipt-method').textContent = data.method;
        document.getElementById('receipt-account').textContent = data.accountNumber;
        document.getElementById('receipt-actual').textContent = data.actual;
        document.getElementById('receipt-discount-rate').textContent = `(${data.discountRate})`;
        document.getElementById('receipt-discount').textContent = data.discount;
        document.getElementById('receipt-total').textContent = data.total;
    };
    method.addEventListener('change', update);
    years.addEventListener('input', update);
    months.addEventListener('change', update);
    months.addEventListener('input', update);
    form.addEventListener('submit', event => {
        if (confirmedAction === 'submit_payment') {
            confirmedAction = null;
            return;
        }
        if (!form.checkValidity()) {
            event.preventDefault();
            form.reportValidity();
            return;
        }
        event.preventDefault();
        updateConfirmation();
        confirmation.showModal();
    });
    document.getElementById('close-payment-confirmation').addEventListener('click', () => confirmation.close());
    document.getElementById('cancel-payment-confirmation').addEventListener('click', () => confirmation.close());
    document.getElementById('confirm-payment-submission').addEventListener('click', () => {
        if (!form.reportValidity()) return;
        confirmedAction = 'submit_payment';
        confirmation.close();
        form.requestSubmit(submitPayment);
    });
    document.getElementById('download-payment-receipt').addEventListener('click', () => {
        const blob = new Blob([receiptDocument(receiptData())], {type: 'text/html;charset=utf-8'});
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        const filename = confirmationValues.company.trim().replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '') || 'hq';
        link.href = url;
        link.download = `${filename}-extension-payment-receipt.html`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    });
    document.getElementById('print-payment-receipt').addEventListener('click', () => {
        const printWindow = window.open('', '_blank');
        if (!printWindow) {
            window.alert('Allow pop-ups to print or save this receipt as a PDF.');
            return;
        }
        printWindow.document.open();
        printWindow.document.write(receiptDocument(receiptData()));
        printWindow.document.close();
        printWindow.focus();
        printWindow.print();
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
