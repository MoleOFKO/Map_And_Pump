<?php
require_once __DIR__ . '/hq_guard.php';
require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../business_scope.php';
require_once __DIR__ . '/../loyalty_points.php';

$hqId = map_pump_current_hq_id($pdo);
$message = '';
$error = '';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['hq_loyalty_payout_csrf'])) {
    $_SESSION['hq_loyalty_payout_csrf'] = bin2hex(random_bytes(32));
}

try {
    if ($hqId <= 0) {
        throw new RuntimeException('Your headquarters account is not linked.');
    }
    loyalty_ensure_payout_verification_schema($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals((string) $_SESSION['hq_loyalty_payout_csrf'], (string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Your session expired. Refresh and try again.');
        }
        $action = (string) ($_POST['action'] ?? '');
        $payoutId = filter_var($_POST['payout_id'] ?? null, FILTER_VALIDATE_INT);
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($payoutId === false || $payoutId <= 0 || ($userId <= 0 && empty($_SESSION['hq_auth']))) {
            throw new RuntimeException('Choose a valid payment request.');
        }

        $pdo->beginTransaction();
        $lock = $pdo->prepare(
            "SELECT payout_id, headquarter_id, amount_mmk, status
             FROM loyalty_discount_payouts
             WHERE payout_id = ? AND headquarter_id = ?
             LIMIT 1
             FOR UPDATE"
        );
        $lock->execute([$payoutId, $hqId]);
        $row = $lock->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('That payment request was not found for your company.');
        }
        if (strtolower((string) $row['status']) !== 'pending') {
            throw new RuntimeException('This request was already handled.');
        }

        if ($action === 'confirm_payout') {
            $amount = (float) $row['amount_mmk'];
            $accountLock = $pdo->prepare(
                'SELECT balance_mmk
                 FROM loyalty_company_discount_accounts
                 WHERE headquarter_id = ?
                 LIMIT 1
                 FOR UPDATE'
            );
            $accountLock->execute([$hqId]);
            $balance = $accountLock->fetchColumn();
            if ($balance === false || (float) $balance + 0.0001 < $amount) {
                throw new RuntimeException('Company balance is lower than this payment amount.');
            }
            $debit = $pdo->prepare(
                'UPDATE loyalty_company_discount_accounts
                 SET balance_mmk = balance_mmk - ?
                 WHERE headquarter_id = ? AND balance_mmk >= ?'
            );
            $debit->execute([$amount, $hqId, $amount]);
            if ($debit->rowCount() !== 1) {
                throw new RuntimeException('Balance changed before confirmation could complete.');
            }
            $upd = $pdo->prepare(
                "UPDATE loyalty_discount_payouts
                 SET status = 'confirmed', confirmed_by_user_id = ?, confirmed_at = CURRENT_TIMESTAMP
                 WHERE payout_id = ? AND status = 'pending'"
            );
            $upd->execute([$userId > 0 ? $userId : null, $payoutId]);
            if ($upd->rowCount() !== 1) {
                throw new RuntimeException('Could not confirm this payment request.');
            }
            $pdo->commit();
            $message = 'Payment confirmed. The discounted amount due was reduced.';
        } elseif ($action === 'reject_payout') {
            $upd = $pdo->prepare(
                "UPDATE loyalty_discount_payouts
                 SET status = 'rejected', confirmed_by_user_id = ?, confirmed_at = CURRENT_TIMESTAMP
                 WHERE payout_id = ? AND status = 'pending'"
            );
            $upd->execute([$userId > 0 ? $userId : null, $payoutId]);
            if ($upd->rowCount() !== 1) {
                throw new RuntimeException('Could not reject this payment request.');
            }
            $pdo->commit();
            $message = 'Payment request rejected. The amount remains due until a new request is confirmed.';
        } else {
            throw new RuntimeException('Choose confirm or reject.');
        }
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('HQ loyalty_payouts: ' . $e->getMessage());
    $error = $e instanceof RuntimeException
        ? $e->getMessage()
        : 'Payment requests could not be updated. Please try again.';
}

$pending = [];
$history = [];
$balanceDue = 0.0;
try {
    if ($hqId > 0) {
        loyalty_ensure_payout_verification_schema($pdo);
        $balStmt = $pdo->prepare(
            'SELECT COALESCE(balance_mmk, 0) FROM loyalty_company_discount_accounts WHERE headquarter_id = ? LIMIT 1'
        );
        $balStmt->execute([$hqId]);
        $balanceDue = (float) $balStmt->fetchColumn();

        $pendingStmt = $pdo->prepare(
            "SELECT payout_id, payout_month, amount_mmk, note, processed_at, status
             FROM loyalty_discount_payouts
             WHERE headquarter_id = ? AND status = 'pending'
             ORDER BY processed_at DESC, payout_id DESC"
        );
        $pendingStmt->execute([$hqId]);
        $pending = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

        $histStmt = $pdo->prepare(
            "SELECT payout_id, payout_month, amount_mmk, note, processed_at, status, confirmed_at
             FROM loyalty_discount_payouts
             WHERE headquarter_id = ? AND status <> 'pending'
             ORDER BY COALESCE(confirmed_at, processed_at) DESC, payout_id DESC
             LIMIT 30"
        );
        $histStmt->execute([$hqId]);
        $history = $histStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    error_log('HQ loyalty_payouts load: ' . $e->getMessage());
    if ($error === '') {
        $error = 'Could not load payment requests.';
    }
}

function hq_payout_escape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Discount payment verification</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="../../css/HQstyle.css">
    <style>
        .payout-wrap { padding: 1.25rem 1.5rem 2rem; color: var(--slate-100, #e2e8f0); }
        .payout-wrap h1 { font-size: 1.35rem; margin-bottom: .35rem; }
        .payout-meta { color: var(--slate-400, #94a3b8); margin-bottom: 1.25rem; }
        .payout-card {
            background: rgba(15, 23, 42, .72);
            border: 1px solid rgba(148, 163, 184, .22);
            border-radius: 14px;
            padding: 1.1rem 1.2rem;
            margin-bottom: 1.25rem;
        }
        .payout-card h2 { font-size: 1rem; margin-bottom: .9rem; }
        .payout-table { width: 100%; border-collapse: collapse; }
        .payout-table th, .payout-table td {
            padding: .65rem .5rem;
            border-bottom: 1px solid rgba(148, 163, 184, .16);
            text-align: left;
            vertical-align: middle;
        }
        .payout-table th { color: var(--slate-400, #94a3b8); font-size: .78rem; text-transform: uppercase; letter-spacing: .04em; }
        .payout-actions { display: flex; gap: .5rem; flex-wrap: wrap; }
        .badge-soft {
            display: inline-block; padding: .15rem .55rem; border-radius: 999px;
            font-size: .75rem; font-weight: 700;
        }
        .badge-pending { background: rgba(255, 193, 7, .18); color: #ffd666; }
        .badge-confirmed { background: rgba(40, 167, 69, .18); color: #7ddea0; }
        .badge-rejected { background: rgba(220, 53, 69, .18); color: #f5a3ad; }
        .due-pill { font-size: 1.15rem; font-weight: 700; }
    </style>
</head>
<body>
<div class="payout-wrap">
    <h1>Discount payment verification</h1>
    <p class="payout-meta">Confirm that Map&amp;Pump paid the driver-discount amount your stations absorbed. Confirming reduces the amount still due.</p>

    <?php if ($message !== ''): ?><div class="alert alert-success"><?= hq_payout_escape($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-danger"><?= hq_payout_escape($error) ?></div><?php endif; ?>

    <div class="payout-card">
        <h2>Amount still due</h2>
        <div class="due-pill"><?= number_format($balanceDue, 2) ?> MMK</div>
    </div>

    <div class="payout-card">
        <h2>Waiting for your confirmation</h2>
        <?php if ($pending === []): ?>
            <p class="payout-meta mb-0">No pending payment requests.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="payout-table">
                    <thead>
                    <tr><th>Month</th><th>Amount</th><th>Requested</th><th>Reference</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pending as $row): ?>
                        <tr>
                            <td><?= hq_payout_escape((string) $row['payout_month']) ?></td>
                            <td><?= number_format((float) $row['amount_mmk'], 2) ?> MMK</td>
                            <td><?= hq_payout_escape((string) $row['processed_at']) ?></td>
                            <td><?= hq_payout_escape((string) ($row['note'] ?? '—')) ?></td>
                            <td>
                                <div class="payout-actions">
                                    <form method="post" onsubmit="return confirm('Confirm this payment was received?');">
                                        <input type="hidden" name="csrf_token" value="<?= hq_payout_escape((string) $_SESSION['hq_loyalty_payout_csrf']) ?>">
                                        <input type="hidden" name="payout_id" value="<?= (int) $row['payout_id'] ?>">
                                        <input type="hidden" name="action" value="confirm_payout">
                                        <button class="btn btn-success btn-sm" type="submit">Confirm</button>
                                    </form>
                                    <form method="post" onsubmit="return confirm('Reject this payment request?');">
                                        <input type="hidden" name="csrf_token" value="<?= hq_payout_escape((string) $_SESSION['hq_loyalty_payout_csrf']) ?>">
                                        <input type="hidden" name="payout_id" value="<?= (int) $row['payout_id'] ?>">
                                        <input type="hidden" name="action" value="reject_payout">
                                        <button class="btn btn-outline-danger btn-sm" type="submit">Reject</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="payout-card">
        <h2>Recent history</h2>
        <?php if ($history === []): ?>
            <p class="payout-meta mb-0">No confirmed or rejected requests yet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="payout-table">
                    <thead>
                    <tr><th>Month</th><th>Amount</th><th>Status</th><th>When</th><th>Reference</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($history as $row): ?>
                        <?php $status = strtolower((string) ($row['status'] ?? '')); ?>
                        <tr>
                            <td><?= hq_payout_escape((string) $row['payout_month']) ?></td>
                            <td><?= number_format((float) $row['amount_mmk'], 2) ?> MMK</td>
                            <td><span class="badge-soft badge-<?= hq_payout_escape($status) ?>"><?= hq_payout_escape(ucfirst($status)) ?></span></td>
                            <td><?= hq_payout_escape((string) ($row['confirmed_at'] ?? $row['processed_at'])) ?></td>
                            <td><?= hq_payout_escape((string) ($row['note'] ?? '—')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
