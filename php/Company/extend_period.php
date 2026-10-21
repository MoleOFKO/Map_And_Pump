<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../billing_config.php';
require_once __DIR__ . '/../payment_transfers.php';

if (empty($_SESSION['hq_extension_csrf'])) {
    $_SESSION['hq_extension_csrf'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';
$accounts = billing_payment_receiving_accounts();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals((string) $_SESSION['hq_extension_csrf'], (string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Your session expired. Refresh the page and try again.');
        }
        if (!payment_transfer_schema_ready($pdo) || !payment_transfer_ensure_hq_service_expiry_schema($pdo)) {
            throw new RuntimeException('Payment and service-period storage is unavailable. Please try again later.');
        }

        $email = strtolower(trim((string) ($_POST['company_email'] ?? '')));
        $months = filter_var($_POST['months'] ?? null, FILTER_VALIDATE_INT);
        $method = trim((string) ($_POST['payment_method'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $months === false || $months < 1 || $months > 24 || !array_key_exists($method, $accounts)) {
            throw new RuntimeException('Enter the company email, choose 1 to 24 months, and select a payment method.');
        }

        $file = $_FILES['receipt'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || (int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > 5 * 1024 * 1024
            || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new RuntimeException('Upload a payment screenshot no larger than 5 MB.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new RuntimeException('The payment screenshot must be a JPG, PNG, or WEBP image.');
        }

        $hqStmt = $pdo->prepare(
            'SELECT headquarter_id, company_name, headquarter_email, headquarters_owner_id
             FROM headquarter WHERE LOWER(headquarter_email) = ? LIMIT 1'
        );
        $hqStmt->execute([$email]);
        $hq = $hqStmt->fetch(PDO::FETCH_ASSOC);
        if (!$hq) {
            throw new RuntimeException('No company account was found for that email address.');
        }

        $termDiscount = 0;
        foreach (BILLING_HQ_TERM_DISCOUNTS as $term) {
            if ((int) $term['months'] === $months) {
                $termDiscount = (int) $term['percent'];
                break;
            }
        }
        $amount = round(BILLING_HQ_EXTENSION_MMK_MONTHLY * $months * (1 - $termDiscount / 100), 2);
        $headquarterId = (int) $hq['headquarter_id'];
        $pdo->beginTransaction();
        $lock = $pdo->prepare('SELECT headquarter_id FROM headquarter WHERE headquarter_id = ? FOR UPDATE');
        $lock->execute([$headquarterId]);
        if (!$lock->fetchColumn()) {
            throw new RuntimeException('The company account could not be verified.');
        }
        $pending = $pdo->prepare(
            "SELECT 1 FROM payment_transfers
             WHERE payer_headquarter_id = ? AND payment_type = 'hq_period_extension'
               AND status IN ('pending_payment', 'payer_reported_paid') LIMIT 1"
        );
        $pending->execute([$headquarterId]);
        if ($pending->fetchColumn()) {
            throw new RuntimeException('A service-period extension is already awaiting payment review for this company.');
        }

        $transferId = payment_transfer_create($pdo, [
            'payment_type' => 'hq_period_extension',
            'amount_mmk' => $amount,
            'description' => sprintf('HQ service-period extension for %d month(s)', $months),
            'term_months' => $months,
            'payer_user_id' => (int) $hq['headquarters_owner_id'],
            'payer_email' => (string) $hq['headquarter_email'],
            'payer_headquarter_id' => $headquarterId,
            'recipient_role' => BILLING_PLATFORM_RECEIVER_ROLE,
            'payment_method' => $method,
        ]);
        $update = $pdo->prepare("UPDATE payment_transfers SET status = 'payer_reported_paid', reported_paid_at = NOW(), paid_declared_at = NOW() WHERE payment_transfer_id = ?");
        $update->execute([$transferId]);
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
        $receipt->bindValue(6, (int) ($hq['headquarters_owner_id'] ?? 0) ?: null, !empty($hq['headquarters_owner_id']) ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $receipt->bindValue(7, (string) $hq['headquarter_email']);
        $receipt->execute();
        payment_transfer_record_event($pdo, $transferId, 'pending_payment', 'payer_reported_paid', null, $email, 'Company submitted an extension payment method and screenshot for staff review.');
        $pdo->commit();
        payment_transfer_send_notices($pdo, $transferId, 'recipient', 'payer_reported_paid');
        $message = 'Extension payment submitted. The company service period will update after Admin or Moderator review.';
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('HQ service extension submission failed: ' . $exception->getMessage());
        $error = $exception->getMessage();
    }
}

$page_title = 'Extend HQ service | MAP & PUMP';
$page_description = 'Submit a payment for an HQ service-period extension.';
require __DIR__ . '/../partials/head.php';
require __DIR__ . '/../partials/nav.php';
?>
<main class="container py-5" style="min-height:65vh;max-width:760px">
    <h1 class="h2 mb-2">Extend company service period</h1>
    <p class="text-secondary mb-4">Enter the company email, choose the extension length, transfer the displayed amount, and submit a screenshot for review.</p>
    <?php if ($message !== ''): ?><div class="alert alert-success" role="status"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="row g-3" data-accounts="<?= htmlspecialchars(json_encode($accounts), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['hq_extension_csrf'], ENT_QUOTES, 'UTF-8') ?>">
        <div class="col-12"><label class="form-label" for="company-email">Company email</label><input class="form-control" type="email" id="company-email" name="company_email" autocomplete="email" required></div>
        <div class="col-md-6"><label class="form-label" for="extension-months">Extension period</label><select class="form-select" id="extension-months" name="months" required><?php for ($monthCount = 1; $monthCount <= 24; $monthCount++): ?><option value="<?= $monthCount ?>"><?= $monthCount ?> month<?= $monthCount === 1 ? '' : 's' ?></option><?php endfor; ?></select></div>
        <div class="col-md-6"><label class="form-label" for="payment-method">Payment method</label><select class="form-select" id="payment-method" name="payment_method" required><option value="">Choose a payment method</option><?php foreach (array_keys($accounts) as $methodName): ?><option value="<?= htmlspecialchars($methodName, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($methodName, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><div class="border rounded p-3"><div>Transfer to <strong id="transfer-number">Select a payment method</strong> <button class="btn btn-sm btn-outline-secondary ms-2" type="button" id="copy-number" disabled>Copy</button></div><small class="text-secondary">Account name: <?= htmlspecialchars(BILLING_PLATFORM_RECIPIENT_NAME, ENT_QUOTES, 'UTF-8') ?></small></div></div>
        <div class="col-12"><label class="form-label" for="payment-screenshot">Payment screenshot (JPG, PNG, or WEBP; max 5 MB)</label><input class="form-control" type="file" id="payment-screenshot" name="receipt" accept="image/jpeg,image/png,image/webp" required></div>
        <div class="col-12"><p class="mb-1">Total: <strong id="extension-total"><?= number_format(BILLING_HQ_EXTENSION_MMK_MONTHLY) ?> MMK</strong></p><small class="text-secondary">Longer terms use the configured HQ term discounts. Service expiry changes only after review confirms receipt.</small></div>
        <div class="col-12"><button class="btn btn-primary" type="submit">Submit extension payment</button></div>
    </form>
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
        number.textContent = account.number || 'Select a payment method';
        copy.disabled = !account.number;
        const discount = termDiscounts.find((term) => Number(term.months) === Number(months.value));
        const amount = <?= (int) BILLING_HQ_EXTENSION_MMK_MONTHLY ?> * Number(months.value) * (1 - Number(discount?.percent || 0) / 100);
        total.textContent = `${new Intl.NumberFormat().format(amount)} MMK`;
    };
    method.addEventListener('change', update);
    months.addEventListener('change', update);
    copy.addEventListener('click', async () => {
        await navigator.clipboard.writeText(number.textContent);
        copy.textContent = 'Copied';
    });
})();
</script>
<?php require __DIR__ . '/../partials/footer.php'; ?>