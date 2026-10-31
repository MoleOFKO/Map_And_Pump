<?php
require_once __DIR__ . '/Login/session_helper.php';
map_pump_configure_session(!empty($_COOKIE[MAP_PUMP_REMEMBER_EMAIL_COOKIE]));
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/payment_transfers.php';

$paymentReady = payment_transfer_schema_ready($pdo);
$accessToken = trim((string) ($_GET['access'] ?? ''));
if ($paymentReady && $accessToken !== '') {
    header('Referrer-Policy: no-referrer');
    $tokenStmt = $pdo->prepare("SELECT payment_transfer_id, UNIX_TIMESTAMP(payer_access_expires_at) AS access_expires_ts FROM payment_transfers WHERE payer_access_token_hash = ? AND payer_access_expires_at > NOW() AND payment_type = 'hq_registration' LIMIT 1");
    $tokenStmt->execute([hash('sha256', $accessToken)]);
    $tokenAccess = $tokenStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $tokenTransferId = (int) ($tokenAccess['payment_transfer_id'] ?? 0);
    if ($tokenTransferId > 0) {
        session_regenerate_id(true);
        $_SESSION['payment_transfer_access_id'] = $tokenTransferId;
        $_SESSION['payment_transfer_access_expires'] = (int) $tokenAccess['access_expires_ts'];
        header('Location: /php/payment_center.php');
        exit;
    }
    http_response_code(410);
    exit('This secure payment link has expired or is invalid. Contact MAP & PUMP support.');
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ((empty($_SESSION['is_logged_in']) || $userId <= 0)
    && ((int) ($_SESSION['payment_transfer_access_id'] ?? 0) <= 0 || (int) ($_SESSION['payment_transfer_access_expires'] ?? 0) < time())) {
    header('Location: /php/Login/login.php');
    exit;
}

$isAdmin = payment_transfer_user_is_admin($pdo, $userId);
$isModeratorReviewer = !$isAdmin && payment_transfer_user_is_admin_or_moderator($pdo, $userId);
$userStmt = $pdo->prepare('SELECT email, headquarter_id FROM users WHERE user_id = ? LIMIT 1');
$userStmt->execute([$userId]);
$currentUser = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$userEmail = strtolower(trim((string) ($currentUser['email'] ?? '')));
$hqId = (int) ($currentUser['headquarter_id'] ?? 0);
$isHqRecipient = $hqId > 0 && payment_transfer_user_owns_hq($pdo, $userId, $hqId);

$transferRows = [];
$paymentFlash = $_SESSION['payment_transfer_flash'] ?? null;
unset($_SESSION['payment_transfer_flash']);
if (empty($_SESSION['payment_transfer_csrf'])) {
    $_SESSION['payment_transfer_csrf'] = bin2hex(random_bytes(32));
}

if ($paymentReady) {
    $conditions = [];
    $params = [];
    if ($userId > 0) {
        $conditions = ['pt.payer_user_id = ?', 'LOWER(COALESCE(pt.payer_email, \'\')) = ?'];
        $params = [$userId, $userEmail];
    }
    if ((int) ($_SESSION['payment_transfer_access_id'] ?? 0) > 0 && (int) ($_SESSION['payment_transfer_access_expires'] ?? 0) >= time()) {
        $conditions[] = 'pt.payment_transfer_id = ?';
        $params[] = (int) $_SESSION['payment_transfer_access_id'];
    }
    if ($isAdmin) {
        $conditions[] = "pt.recipient_role = 'admin'";
    } elseif ($isModeratorReviewer) {
        $conditions[] = "(pt.recipient_role = 'admin' AND pt.payment_type IN ('hq_period_extension', 'hq_station_package'))";
    }
    if ($isHqRecipient) {
        $conditions[] = "(pt.recipient_role = 'station_hq' AND pt.recipient_headquarter_id = ?)";
        $params[] = $hqId;
    }
    $stmt = $pdo->prepare(
        'SELECT pt.*, h.company_name AS recipient_hq_name,
            COALESCE(payer_hq.company_name, registration.sender_entity) AS payer_hq_name,
            COALESCE(payer_hq.headquarter_email, registration.headquarter_email, pt.payer_email) AS payer_hq_email,
            b.branch_name AS station_name
         FROM payment_transfers pt
         LEFT JOIN headquarter h ON h.headquarter_id = pt.recipient_headquarter_id
         LEFT JOIN headquarter payer_hq ON payer_hq.headquarter_id = pt.payer_headquarter_id
         LEFT JOIN system_requests registration ON registration.request_id = pt.request_id AND pt.payment_type = \'hq_registration\'
         LEFT JOIN branches b ON b.branch_id = pt.station_id
         WHERE (' . implode(' OR ', $conditions) . ')
         ORDER BY (pt.status IN (\'pending_payment\', \'payer_reported_paid\')) DESC, pt.created_at DESC, pt.payment_transfer_id DESC
         LIMIT 200'
    );
    $stmt->execute($params);
    $transferRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$receiptByTransfer = [];
$eventsByTransfer = [];
if ($transferRows) {
    $transferIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['payment_transfer_id'], $transferRows)));
    $in = implode(',', array_fill(0, count($transferIds), '?'));
    $receiptStmt = $pdo->prepare("SELECT payment_transfer_id, file_name, mime_type FROM payment_transfer_receipts WHERE payment_transfer_id IN ({$in})");
    $receiptStmt->execute($transferIds);
    foreach ($receiptStmt->fetchAll(PDO::FETCH_ASSOC) as $receiptRow) {
        $receiptByTransfer[(int) $receiptRow['payment_transfer_id']] = [
            'file_name' => (string) $receiptRow['file_name'],
            'mime_type' => (string) $receiptRow['mime_type'],
        ];
    }
    $eventStmt = $pdo->prepare("SELECT payment_transfer_id, previous_status, new_status, actor_email, note, created_at FROM payment_transfer_events WHERE payment_transfer_id IN ({$in}) ORDER BY created_at ASC, event_id ASC");
    $eventStmt->execute($transferIds);
    foreach ($eventStmt->fetchAll(PDO::FETCH_ASSOC) as $eventRow) {
        $eventsByTransfer[(int) $eventRow['payment_transfer_id']][] = $eventRow;
    }
}
$page_title = 'Payment status | MAP & PUMP';
$page_description = 'Review external payment status notices.';
require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/nav.php';

$statusLabels = [
    'pending_payment' => 'Awaiting payer payment',
    'payer_reported_paid' => 'Payer reported paid · awaiting recipient',
    'received' => 'Recipient recorded receipt',
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
            <a class="btn btn-outline-light" href="<?= $isAdmin ? '/php/Admin(WebOwner)/view_requests.php' : ($isModeratorReviewer ? '/php/Moderators(UnderAdmin)/view_requests(M).php' : '/php/HomePage.php') ?>"><?= ($isAdmin || $isModeratorReviewer) ? 'Return to View Requests' : 'Return to MAP &amp; PUMP' ?></a>
        </div>

        <?php if (is_array($paymentFlash)): ?>
            <div class="alert alert-<?= htmlspecialchars((string) ($paymentFlash['type'] ?? 'info'), ENT_QUOTES, 'UTF-8') ?>" role="status">
                <?= htmlspecialchars((string) ($paymentFlash['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>
        <?php if ($isAdmin && $paymentReady): ?>
            <section class="rounded-4 border border-info p-4 mb-4" style="background:rgba(13,20,34,.82);">
                <h2 class="h5 text-white">HQ station billing</h2>
                <p class="small text-secondary mb-0">Standalone station-fee quotes are retired. Set package prices in Loyalty &amp; Billing Management; HQ owners submit one all-in-one MMK package for extension, station operation, and capacity.</p>
            </section>
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
                        || ($userEmail !== '' && strtolower((string) ($transfer['payer_email'] ?? '')) === $userEmail)
                        || (int) ($_SESSION['payment_transfer_access_id'] ?? 0) === (int) $transfer['payment_transfer_id'];
                    $isRecipient = $isAdmin
                        ? $transfer['recipient_role'] === 'admin'
                        : ($isModeratorReviewer
                            ? $transfer['recipient_role'] === 'admin' && in_array($transfer['payment_type'], ['hq_period_extension', 'hq_station_package'], true)
                            : ($isHqRecipient && $transfer['recipient_role'] === 'station_hq'
                                && (int) $transfer['recipient_headquarter_id'] === $hqId));
                    $canDecide = $isRecipient && !$isPayer && $transfer['status'] === 'payer_reported_paid';
                    $canCancel = $isPayer && in_array($transfer['status'], ['pending_payment', 'payer_reported_paid'], true);
                    $canReportPaid = $isPayer && $transfer['status'] === 'pending_payment';
                    $canRecreateRegistration = $isPayer && $transfer['payment_type'] === 'hq_registration'
                        && in_array($transfer['status'], ['rejected', 'cancelled'], true);
                    $typeLabel = match ((string) $transfer['payment_type']) {
                        'hq_registration' => 'HQ registration fee',
                        'hq_extra_station' => 'Monthly extra-station capacity and operation',
                        'hq_station_operating_quote' => 'Negotiated monthly station operating fee',
                        'hq_period_extension' => 'HQ service-period extension',
                        'hq_station_package' => 'All-in-one HQ station package',
                        'fuel_purchase' => 'Customer fuel payment',
                        default => 'External payment',
                    };
                    $recipientLabel = $transfer['recipient_role'] === 'station_hq'
                        ? (trim((string) ($transfer['recipient_hq_name'] ?? '')) ?: 'Station HQ #' . (int) $transfer['recipient_headquarter_id'])
                        : BILLING_PLATFORM_RECIPIENT_NAME . ' (Admin)';
                    $statusClass = match ((string) $transfer['status']) {
                        'received' => 'success',
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
                            <div class="col-md-3"><span class="text-secondary">Selected method</span><div class="fw-semibold"><?= htmlspecialchars((string) ($transfer['payment_method'] ?: 'Not specified'), ENT_QUOTES, 'UTF-8') ?></div></div>
                             <div class="col-md-3"><span class="text-secondary">Requested</span><div class="fw-semibold"><?= htmlspecialchars((string) $transfer['created_at'], ENT_QUOTES, 'UTF-8') ?></div></div>
                            <div class="col-12"><span class="text-secondary">Details</span><div><?= htmlspecialchars((string) $transfer['description'], ENT_QUOTES, 'UTF-8') ?><?php if (!empty($transfer['station_name'])): ?> · <?= htmlspecialchars((string) $transfer['station_name'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?></div></div>
                            <?php if (in_array($transfer['payment_type'], ['hq_registration', 'hq_period_extension', 'hq_station_package'], true)): ?><div class="col-md-6"><span class="text-secondary">Company</span><div><?= htmlspecialchars((string) ($transfer['payer_hq_name'] ?? 'Unknown company'), ENT_QUOTES, 'UTF-8') ?></div></div><div class="col-md-6"><span class="text-secondary">Company email</span><div><?= htmlspecialchars((string) ($transfer['payer_hq_email'] ?? $transfer['payer_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div></div><?php endif; ?>
                            <?php if (!empty($transfer['request_id'])): ?><div class="col-12"><span class="text-secondary">Registration request</span><div>#<?= (int) $transfer['request_id'] ?></div></div><?php endif; ?>
                            <?php if (!empty($transfer['decision_note'])): ?><div class="col-12"><span class="text-secondary">Recipient note</span><div><?= htmlspecialchars((string) $transfer['decision_note'], ENT_QUOTES, 'UTF-8') ?></div></div><?php endif; ?>
                            <?php $receiptName = $receiptByTransfer[(int) $transfer['payment_transfer_id']] ?? null; $transferEvents = $eventsByTransfer[(int) $transfer['payment_transfer_id']] ?? []; ?>
                            <?php if ($receiptName): ?><div class="col-12"><span class="text-secondary"><?= in_array($transfer['payment_type'], ['hq_registration', 'hq_period_extension', 'hq_station_package'], true) ? 'Payment screenshot' : 'Optional receipt evidence' ?></span> · <a href="/php/Payment/receipt.php?id=<?= (int) $transfer['payment_transfer_id'] ?>" class="link-info"><?= htmlspecialchars((string) $receiptName['file_name'], ENT_QUOTES, 'UTF-8') ?></a><?php if (in_array($transfer['payment_type'], ['hq_registration', 'hq_period_extension', 'hq_station_package'], true) && str_starts_with($receiptName['mime_type'], 'image/')): ?><div class="mt-2"><a href="/php/Payment/receipt.php?id=<?= (int) $transfer['payment_transfer_id'] ?>&amp;view=1" target="_blank" rel="noopener"><img src="/php/Payment/receipt.php?id=<?= (int) $transfer['payment_transfer_id'] ?>&amp;view=1" alt="Payment screenshot" style="display:block;max-width:320px;max-height:220px;object-fit:contain;border:1px solid rgba(255,255,255,.18);border-radius:6px"></a></div><?php endif; ?><div class="small text-secondary">The attachment is evidence for review; it does not independently confirm payment.</div></div><?php endif; ?>
                            <?php if ($transferEvents): ?><div class="col-12"><span class="text-secondary">Status history</span><ol class="mb-0 mt-1 ps-3"><?php foreach ($transferEvents as $event): ?><li><?= htmlspecialchars((string) $event['created_at'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($statusLabels[(string) $event['new_status']] ?? ucfirst((string) $event['new_status']), ENT_QUOTES, 'UTF-8') ?><?php if (!empty($event['actor_email'])): ?> by <?= htmlspecialchars((string) $event['actor_email'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?><?php if (!empty($event['note'])): ?> — <?= htmlspecialchars((string) $event['note'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?></li><?php endforeach; ?></ol></div><?php endif; ?>
                        </div>
                        <?php if ($canReportPaid || $canDecide || $canCancel || $canRecreateRegistration): ?>
                            <div class="d-flex flex-wrap gap-2 mt-3">
                                <?php if ($canReportPaid): ?>
                                    <form method="post" enctype="multipart/form-data" action="/php/Payment/action.php" class="d-flex flex-wrap align-items-end gap-2">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['payment_transfer_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="transfer_id" value="<?= (int) $transfer['payment_transfer_id'] ?>">
                                        <input type="hidden" name="action" value="report_paid">
                                        <label class="small text-secondary">Optional receipt (JPG, PNG, WEBP, PDF; max 5 MB)<input class="form-control form-control-sm mt-1" type="file" name="receipt" accept="image/jpeg,image/png,image/webp,application/pdf"></label>
                                        <button class="btn btn-warning" type="submit" onclick="return confirm('Report that you sent this external payment? This will notify the recipient but will not confirm receipt or unlock anything.')">I paid</button>
                                    </form>
                                <?php endif; ?>
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
                                        <button class="btn btn-outline-secondary" type="submit">Cancel payment request</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($canRecreateRegistration): ?>
                                    <form method="post" action="/php/Payment/action.php" onsubmit="return confirm('Create a replacement payment request for this still-pending HQ registration?')">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['payment_transfer_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="transfer_id" value="<?= (int) $transfer['payment_transfer_id'] ?>">
                                        <input type="hidden" name="action" value="recreate_registration_request">
                                        <button class="btn btn-outline-info" type="submit">Create replacement request</button>
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
