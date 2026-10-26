<?php
require_once __DIR__ . '/hq_guard.php';
require_once __DIR__ . '/../billing_config.php';
require_once __DIR__ . '/../payment_transfers.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
$hqId = map_pump_current_hq_id($pdo, $userId);
$accounts = billing_payment_receiving_accounts();
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

if (empty($_SESSION['hq_extension_csrf'])) {
    $_SESSION['hq_extension_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals((string) $_SESSION['hq_extension_csrf'], (string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Your session expired. Refresh the page and try again.');
        }
        if (!payment_transfer_schema_ready($pdo) || !payment_transfer_ensure_hq_service_expiry_schema($pdo)) {
            throw new RuntimeException('Payment and service-period storage is unavailable. Please try again later.');
        }

        $email = strtolower(trim((string) ($_POST['company_email'] ?? '')));
        $expectedEmail = strtolower(trim((string) $hq['headquarter_email']));
        $months = filter_var($_POST['months'] ?? null, FILTER_VALIDATE_INT);
        $method = trim((string) ($_POST['payment_method'] ?? ''));
        if ($email === '' || $email !== $expectedEmail || $months === false || $months < 1 || $months > 24 || !array_key_exists($method, $accounts)) {
            throw new RuntimeException('Use this HQ company email, choose 1 to 24 months, and select a payment method.');
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
        foreach (BILLING_HQ_TERM_DISCOUNTS as $term) {
            if ((int) $term['months'] === $months) {
                $termDiscount = (int) $term['percent'];
                break;
            }
        }
        $amount = round(BILLING_HQ_EXTENSION_MMK_MONTHLY * $months * (1 - $termDiscount / 100), 2);

        $pdo->beginTransaction();
        $lock = $pdo->prepare('SELECT headquarter_id FROM headquarter WHERE headquarter_id = ? FOR UPDATE');
        $lock->execute([$hqId]);
        if (!$lock->fetchColumn()) {
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

        $transferId = payment_transfer_create($pdo, [
            'payment_type' => 'hq_period_extension',
            'amount_mmk' => $amount,
            'description' => sprintf('HQ service-period extension for %d month(s)', $months),
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
        .extend-fields { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
        .extend-field-wide { grid-column:1/-1; }
        .transfer-number { display:flex; gap:10px; align-items:center; margin:8px 0; }
        .transfer-number strong { overflow-wrap:anywhere; font-size:1.05rem; }
        .transfer-copy { flex:0 0 auto; }
        .extend-total { display:flex; justify-content:space-between; gap:16px; padding:15px 0; border-top:1px solid var(--border); border-bottom:1px solid var(--border); font-variant-numeric:tabular-nums; }
        .extend-note { color:var(--muted); font-size:.8rem; }
        .extend-submit { width:100%; margin-top:16px; }
        .extend-status { margin-bottom:18px; }
        @media(max-width:760px) { body { padding:22px 16px; } .extend-header { display:block; } .extend-expiry { margin-top:16px; } .extend-grid { grid-template-columns:1fr; } }
        @media(max-width:480px) { .extend-fields { grid-template-columns:1fr; } .extend-field-wide { grid-column:auto; } }
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
    <?php if ($pendingExtension): ?>
        <div class="alert extend-status" role="status">
            An extension payment for <?= (int) $pendingExtension['term_months'] ?> month(s) is awaiting review: <?= number_format((float) $pendingExtension['amount_mmk'], 2) ?> MMK via <?= htmlspecialchars((string) $pendingExtension['payment_method'], ENT_QUOTES, 'UTF-8') ?>.
        </div>
    <?php else: ?>
        <form method="post" enctype="multipart/form-data" class="extend-grid" data-accounts="<?= htmlspecialchars(json_encode($accounts), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['hq_extension_csrf'], ENT_QUOTES, 'UTF-8') ?>">
            <section class="extend-card">
                <h2>Payment details</h2>
                <div class="extend-fields">
                    <div class="extend-field-wide">
                        <label for="company-email">Company email</label>
                        <input id="company-email" type="email" name="company_email" value="<?= htmlspecialchars((string) $hq['headquarter_email'], ENT_QUOTES, 'UTF-8') ?>" readonly required>
                    </div>
                    <div>
                        <label for="extension-months">Extension period</label>
                        <select id="extension-months" name="months" required><?php for ($monthCount = 1; $monthCount <= 24; $monthCount++): ?><option value="<?= $monthCount ?>"><?= $monthCount ?> month<?= $monthCount === 1 ? '' : 's' ?></option><?php endfor; ?></select>
                    </div>
                    <div>
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
                <h2>Transfer and total</h2>
                <p class="extend-note">Transfer to the account for your selected method.</p>
                <div class="transfer-number"><strong id="transfer-number">Choose a payment method</strong><button class="btn btn-outline-secondary transfer-copy" id="copy-number" type="button" disabled aria-label="Copy transfer account"><svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3"/></svg></button></div>
                <p class="extend-note">Account name: <?= htmlspecialchars(BILLING_PLATFORM_RECIPIENT_NAME, ENT_QUOTES, 'UTF-8') ?></p>
                <div class="extend-total"><span>Total due</span><strong id="extension-total"><?= number_format(BILLING_HQ_EXTENSION_MMK_MONTHLY) ?> MMK</strong></div>
                <p class="extend-note" style="margin:14px 0 0">Longer terms receive configured discounts. Access updates after payment receipt is confirmed by an Admin or Moderator.</p>
                <button class="btn extend-submit" type="submit">Submit payment for review</button>
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
    const months = document.getElementById('extension-months');
    const number = document.getElementById('transfer-number');
    const copy = document.getElementById('copy-number');
    const total = document.getElementById('extension-total');
    const termDiscounts = <?= json_encode(BILLING_HQ_TERM_DISCOUNTS) ?>;
    const update = () => {
        const account = accounts[method.value] || {};
        number.textContent = account.number || 'Choose a payment method';
        copy.disabled = !account.number;
        const discount = termDiscounts.find((term) => Number(term.months) === Number(months.value));
        const amount = <?= (int) BILLING_HQ_EXTENSION_MMK_MONTHLY ?> * Number(months.value) * (1 - Number(discount?.percent || 0) / 100);
        total.textContent = `${new Intl.NumberFormat().format(amount)} MMK`;
    };
    method.addEventListener('change', update);
    months.addEventListener('change', update);
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