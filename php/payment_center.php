<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/payment_transfers.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
if (empty($_SESSION['is_logged_in']) || $userId <= 0) {
    header('Location: /php/Login/login.php');
    exit;
}

$isAdmin = payment_transfer_user_is_admin($pdo, $userId);
$userStmt = $pdo->prepare('SELECT email, headquarter_id FROM users WHERE user_id = ? LIMIT 1');
$userStmt->execute([$userId]);
$currentUser = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$userEmail = strtolower(trim((string) ($currentUser['email'] ?? '')));
$hqId = (int) ($currentUser['headquarter_id'] ?? 0);
$isHqRecipient = $hqId > 0 && payment_transfer_user_owns_hq($pdo, $userId, $hqId);

$paymentReady = payment_transfer_schema_ready($pdo);
$transferRows = [];
$paymentFlash = $_SESSION['payment_transfer_flash'] ?? null;
unset($_SESSION['payment_transfer_flash']);
if (empty($_SESSION['payment_transfer_csrf'])) {
    $_SESSION['payment_transfer_csrf'] = bin2hex(random_bytes(32));
}

if ($paymentReady) {
    $conditions = ['pt.payer_user_id = ?', 'LOWER(COALESCE(pt.payer_email, \'\')) = ?'];
    $params = [$userId, $userEmail];
    if ($isAdmin) {
        $conditions[] = "pt.recipient_role = 'admin'";
    }
    if ($isHqRecipient) {
        $conditions[] = "(pt.recipient_role = 'station_hq' AND pt.recipient_headquarter_id = ?)";
        $params[] = $hqId;
    }
    $stmt = $pdo->prepare(
        'SELECT pt.*, h.company_name AS recipient_hq_name, b.branch_name AS station_name
         FROM payment_transfers pt
         LEFT JOIN headquarter h ON h.headquarter_id = pt.recipient_headquarter_id
         LEFT JOIN branches b ON b.branch_id = pt.station_id
         WHERE (' . implode(' OR ', $conditions) . ')
         ORDER BY (pt.status = \'pending_transfer\') DESC, pt.created_at DESC, pt.payment_transfer_id DESC
         LIMIT 200'
    );
    $stmt->execute($params);
    $transferRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$page_title = 'Payment status | MAP & PUMP';
$page_description = 'Review external payment status notices.';
require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/nav.php';

$statusLabels = [
    'pending_transfer' => 'Awaiting recipient',
    'confirmed' => 'Recipient confirmed',
    'rejected' => 'Recipient did not confirm',
    'cancelled' => 'Cancelled by payer',
];
?>
<main class="container py-5" style="min-height:65vh;">
    <div class="mx-auto" style="max-width:1050px;">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
            <div>
                <p class="text-uppercase text-info small fw-bold mb-2">External payment records</p>
                <h1 class="h2 text-white mb-2">Payment status</h1>
                <p class="text-secondary mb-0">The website records the payer’s declaration and the recipient’s response. It does not send, hold, or independently verify money.</p>
            </div>
            <a class="btn btn-outline-light" href="/php/HomePage.php">Return to MAP &amp; PUMP</a>
        </div>

        <?php if (is_array($paymentFlash)): ?>
            <div class="alert alert-<?= htmlspecialchars((string) ($paymentFlash['type'] ?? 'info'), ENT_QUOTES, 'UTF-8') ?>" role="status">
                <?= htmlspecialchars((string) ($paymentFlash['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>
        <?php if (!$paymentReady): ?>
            <div class="alert alert-warning">Payment status storage is not available right now.</div>
        <?php elseif (!$transferRows): ?>
            <div class="rounded-4 border border-secondary p-4 text-secondary">There are no payment records for this account.</div>
        <?php else: ?>
            <div class="d-grid gap-3">
                <?php foreach ($transferRows as $transfer): ?>
                    <?php
                    $isPayer = (int) ($transfer['payer_user_id'] ?? 0) === $userId
                        || ($userEmail !== '' && strtolower((string) ($transfer['payer_email'] ?? '')) === $userEmail);
                    $isRecipient = $isAdmin
                        ? $transfer['recipient_role'] === 'admin'
                        : ($isHqRecipient && $transfer['recipient_role'] === 'station_hq'
                            && (int) $transfer['recipient_headquarter_id'] === $hqId);
                    $canDecide = $isRecipient && !$isPayer && $transfer['status'] === 'pending_transfer';
                    $canCancel = $isPayer && $transfer['status'] === 'pending_transfer';
                    $typeLabel = match ((string) $transfer['payment_type']) {
                        'hq_registration' => 'HQ registration fee',
                        'hq_extra_station' => 'Monthly extra-station capacity and operation',
                        'fuel_purchase' => 'Customer fuel payment',
                        default => 'External payment',
                    };
                    $recipientLabel = $transfer['recipient_role'] === 'station_hq'
                        ? (trim((string) ($transfer['recipient_hq_name'] ?? '')) ?: 'Station HQ #' . (int) $transfer['recipient_headquarter_id'])
                        : BILLING_PLATFORM_RECIPIENT_NAME . ' (Admin)';
                    $statusClass = match ((string) $transfer['status']) {
                        'confirmed' => 'success',
                        'rejected', 'cancelled' => 'secondary',
                        default => 'warning',
                    };
                    ?>
                    <article class="rounded-4 border border-secondary p-4 text-white" style="background:rgba(13,20,34,.82);">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                            <div>
                                <h2 class="h5 mb-1"><?= htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') ?></h2>
                                <span class="small text-secondary">Reference <?= htmlspecialchars((string) $transfer['reference_code'], ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <span class="badge text-bg-<?= $statusClass ?>"><?= htmlspecialchars($statusLabels[(string) $transfer['status']] ?? ucfirst((string) $transfer['status']), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div class="row g-3 small">
                            <div class="col-md-3"><span class="text-secondary">Amount</span><div class="fw-semibold"><?= number_format((float) $transfer['amount_mmk'], 2) ?> MMK</div></div>
                            <div class="col-md-3"><span class="text-secondary">Recipient</span><div class="fw-semibold"><?= htmlspecialchars($recipientLabel, ENT_QUOTES, 'UTF-8') ?></div></div>
                            <div class="col-md-3"><span class="text-secondary">Declared method</span><div class="fw-semibold"><?= htmlspecialchars((string) ($transfer['payment_method'] ?: 'Not specified'), ENT_QUOTES, 'UTF-8') ?></div></div>
                            <div class="col-md-3"><span class="text-secondary">Reported</span><div class="fw-semibold"><?= htmlspecialchars((string) $transfer['paid_declared_at'], ENT_QUOTES, 'UTF-8') ?></div></div>
                            <div class="col-12"><span class="text-secondary">Details</span><div><?= htmlspecialchars((string) $transfer['description'], ENT_QUOTES, 'UTF-8') ?><?php if (!empty($transfer['station_name'])): ?> · <?= htmlspecialchars((string) $transfer['station_name'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?></div></div>
                            <?php if (!empty($transfer['request_id'])): ?><div class="col-12"><span class="text-secondary">Registration request</span><div>#<?= (int) $transfer['request_id'] ?></div></div><?php endif; ?>
                            <?php if (!empty($transfer['decision_note'])): ?><div class="col-12"><span class="text-secondary">Recipient note</span><div><?= htmlspecialchars((string) $transfer['decision_note'], ENT_QUOTES, 'UTF-8') ?></div></div><?php endif; ?>
                        </div>
                        <?php if ($canDecide || $canCancel): ?>
                            <div class="d-flex flex-wrap gap-2 mt-3">
                                <?php if ($canDecide): ?>
                                    <form method="post" action="/php/Payment/action.php">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['payment_transfer_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="transfer_id" value="<?= (int) $transfer['payment_transfer_id'] ?>">
                                        <input type="hidden" name="action" value="confirm_received">
                                        <button class="btn btn-success" type="submit" onclick="return confirm('Confirm that this external payment was received?')">I received it</button>
                                    </form>
                                    <form method="post" action="/php/Payment/action.php" class="d-flex gap-2">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['payment_transfer_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="transfer_id" value="<?= (int) $transfer['payment_transfer_id'] ?>">
                                        <input type="hidden" name="action" value="mark_not_received">
                                        <input class="form-control" style="max-width:260px;" name="decision_note" maxlength="500" placeholder="Optional note for payer">
                                        <button class="btn btn-outline-warning" type="submit">I did not receive it</button>
                                    </form>
                                <?php elseif ($canCancel): ?>
                                    <form method="post" action="/php/Payment/action.php">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['payment_transfer_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="transfer_id" value="<?= (int) $transfer['payment_transfer_id'] ?>">
                                        <input type="hidden" name="action" value="cancel_transfer">
                                        <button class="btn btn-outline-secondary" type="submit">Cancel notice</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>
