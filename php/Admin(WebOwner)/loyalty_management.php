<?php
require_once __DIR__ . '/admin_common.php';
require_once __DIR__ . '/../loyalty_points.php';
require_once __DIR__ . '/../billing_config.php';

admin_require_admin();
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$error = '';
$message = '';
if (empty($_SESSION['admin_loyalty_csrf'])) {
    $_SESSION['admin_loyalty_csrf'] = bin2hex(random_bytes(32));
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals((string) $_SESSION['admin_loyalty_csrf'], (string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Your session expired. Refresh the page and try again.');
        }

        if (($_POST['action'] ?? '') === 'save_settings') {
            $pointsRate = filter_var($_POST['points_per_liter'] ?? null, FILTER_VALIDATE_FLOAT);
            $registrationFee = filter_var($_POST['registration_fee_mmk'] ?? null, FILTER_VALIDATE_INT);
            $packageRate = filter_var($_POST['package_mmk_per_station_month'] ?? null, FILTER_VALIDATE_INT);
            if ($pointsRate === false || $pointsRate < 0 || $pointsRate > 1000
                || $registrationFee === false || $registrationFee < 0
                || $packageRate === false || $packageRate < 0) {
                throw new RuntimeException('Enter valid non-negative earning and billing amounts.');
            }

            $tierInputs = [];
            $redemptionInputs = [];
            foreach (array_keys(loyalty_tier_labels()) as $code) {
                $tierInputs[$code] = [
                    'points_threshold' => filter_var($_POST['tier_points'][$code] ?? null, FILTER_VALIDATE_INT),
                    'exp_threshold' => filter_var($_POST['tier_exp'][$code] ?? null, FILTER_VALIDATE_INT),
                    'monthly_budget_mmk' => filter_var($_POST['tier_budget'][$code] ?? null, FILTER_VALIDATE_FLOAT),
                ];
                if ($tierInputs[$code]['points_threshold'] === false || $tierInputs[$code]['points_threshold'] < 0
                    || $tierInputs[$code]['exp_threshold'] === false || $tierInputs[$code]['exp_threshold'] < 0
                    || $tierInputs[$code]['monthly_budget_mmk'] === false || $tierInputs[$code]['monthly_budget_mmk'] < 0) {
                    throw new RuntimeException('Enter valid non-negative point, EXP, and budget thresholds for every tier.');
                }
            }
            $tierCodes = array_keys($tierInputs);
            if ($tierInputs['bronze']['points_threshold'] !== 0 || $tierInputs['bronze']['exp_threshold'] !== 0) {
                throw new RuntimeException('Bronze must remain the starting tier at 0 points and 0 EXP.');
            }
            for ($index = 1; $index < count($tierCodes); $index++) {
                $previous = $tierInputs[$tierCodes[$index - 1]];
                $current = $tierInputs[$tierCodes[$index]];
                if ($current['points_threshold'] <= $previous['points_threshold']
                    || $current['exp_threshold'] <= $previous['exp_threshold']
                    || $current['monthly_budget_mmk'] <= $previous['monthly_budget_mmk']) {
                    throw new RuntimeException('Each higher tier must require more points and EXP and have a larger monthly pool budget.');
                }
            }

            for ($rewardId = 1; $rewardId <= 3; $rewardId++) {
                $redemptionInputs[$rewardId] = [
                    'points_required' => filter_var($_POST['reward_points'][$rewardId] ?? null, FILTER_VALIDATE_INT),
                    'discount_mmk' => filter_var($_POST['reward_discount'][$rewardId] ?? null, FILTER_VALIDATE_FLOAT),
                ];
                if ($redemptionInputs[$rewardId]['points_required'] === false || $redemptionInputs[$rewardId]['points_required'] <= 0
                    || $redemptionInputs[$rewardId]['discount_mmk'] === false || $redemptionInputs[$rewardId]['discount_mmk'] <= 0) {
                    throw new RuntimeException('Each redemption option needs positive points and MMK amounts.');
                }
                if ($rewardId > 1
                    && ($redemptionInputs[$rewardId]['points_required'] <= $redemptionInputs[$rewardId - 1]['points_required']
                        || $redemptionInputs[$rewardId]['discount_mmk'] <= $redemptionInputs[$rewardId - 1]['discount_mmk'])) {
                    throw new RuntimeException('Redemption point and discount amounts must increase with each option.');
                }
            }

            $pdo->beginTransaction();
            $saveSetting = $pdo->prepare(
                'INSERT INTO platform_settings (setting_key, setting_value)
                 VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
            );
            $saveSetting->execute(['loyalty_points_per_liter', number_format((float) $pointsRate, 4, '.', '')]);
            $saveSetting->execute(['loyalty_pool_enforced', isset($_POST['pool_enforced']) ? '1' : '0']);
            $saveSetting->execute(['hq_registration_fee_mmk', (string) $registrationFee]);
            $saveSetting->execute(['hq_package_mmk_per_station_month', (string) $packageRate]);

            $saveTier = $pdo->prepare(
                'UPDATE loyalty_tier_rules
                 SET points_threshold = ?, exp_threshold = ?, monthly_budget_mmk = ?
                 WHERE tier_code = ?'
            );
            foreach ($tierInputs as $code => $rule) {
                $saveTier->execute([
                    $rule['points_threshold'],
                    $rule['exp_threshold'],
                    $rule['monthly_budget_mmk'],
                    $code,
                ]);
                if ($saveTier->rowCount() !== 1) {
                    $exists = $pdo->prepare('SELECT 1 FROM loyalty_tier_rules WHERE tier_code = ? LIMIT 1');
                    $exists->execute([$code]);
                    if (!$exists->fetchColumn()) {
                        throw new RuntimeException('A tier rule could not be saved.');
                    }
                }
            }

            $saveReward = $pdo->prepare(
                'UPDATE loyalty_redemption_rules
                 SET points_required = ?, discount_mmk = ?
                 WHERE reward_id = ?'
            );
            foreach ($redemptionInputs as $rewardId => $reward) {
                $saveReward->execute([$reward['points_required'], $reward['discount_mmk'], $rewardId]);
                if ($saveReward->rowCount() !== 1) {
                    $exists = $pdo->prepare('SELECT 1 FROM loyalty_redemption_rules WHERE reward_id = ? LIMIT 1');
                    $exists->execute([$rewardId]);
                    if (!$exists->fetchColumn()) {
                        throw new RuntimeException('A redemption option could not be saved.');
                    }
                }
            }
            $pdo->commit();
            $message = 'Loyalty and billing settings saved.';
        } elseif (($_POST['action'] ?? '') === 'process_payout') {
            $amount = filter_var($_POST['payout_amount'] ?? null, FILTER_VALIDATE_FLOAT);
            $month = trim((string) ($_POST['payout_month'] ?? ''));
            $note = trim((string) ($_POST['payout_note'] ?? ''));
            if ($amount === false || $amount <= 0 || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) || strlen($note) > 500) {
                throw new RuntimeException('Enter a positive payout amount, valid month, and note up to 500 characters.');
            }

            $pdo->beginTransaction();
            $accountLock = $pdo->query('SELECT balance_mmk FROM loyalty_discount_account WHERE account_id = 1 FOR UPDATE');
            $balance = $accountLock->fetchColumn();
            if ($balance === false || (float) $balance < (float) $amount) {
                throw new RuntimeException('The payout exceeds the available Total Discounted Amount balance.');
            }
            $debit = $pdo->prepare(
                'UPDATE loyalty_discount_account
                 SET balance_mmk = balance_mmk - ?
                 WHERE account_id = 1 AND balance_mmk >= ?'
            );
            $debit->execute([$amount, $amount]);
            if ($debit->rowCount() !== 1) {
                throw new RuntimeException('The balance changed before the payout could be recorded.');
            }
            $payout = $pdo->prepare(
                'INSERT INTO loyalty_discount_payouts
                    (payout_month, amount_mmk, note, processed_by_user_id)
                 VALUES (?, ?, ?, ?)'
            );
            $adminId = (int) ($_SESSION['user_id'] ?? 0);
            if ($adminId <= 0) {
                throw new RuntimeException('Could not identify the signed-in Admin.');
            }
            $payout->execute([$month, $amount, $note !== '' ? $note : null, $adminId]);
            $pdo->commit();
            $message = 'Monthly admin payout recorded and deducted from the available balance.';
        } else {
            throw new RuntimeException('Choose a valid loyalty-management action.');
        }
    }

    $pointsRateStmt = $pdo->prepare('SELECT setting_value FROM platform_settings WHERE setting_key = ?');
    $pointsRateStmt->execute(['loyalty_points_per_liter']);
    $pointsRate = (string) $pointsRateStmt->fetchColumn();
    $poolStmt = $pdo->prepare('SELECT setting_value FROM platform_settings WHERE setting_key = ?');
    $poolStmt->execute(['loyalty_pool_enforced']);
    $poolEnforced = (string) $poolStmt->fetchColumn() === '1';
    $registrationFee = billing_admin_setting($pdo, 'hq_registration_fee_mmk', BILLING_HQ_REGISTRATION_FEE_MMK);
    $packageRate = billing_admin_setting($pdo, 'hq_package_mmk_per_station_month', BILLING_HQ_PACKAGE_MMK_PER_STATION_MONTHLY);
    $tierRules = loyalty_get_tier_rules($pdo);
    $redemptions = loyalty_redemption_options($pdo);
    if (count($redemptions) !== 3) {
        throw new RuntimeException('Exactly three redemption options must be configured.');
    }
    $balance = (float) $pdo->query('SELECT balance_mmk FROM loyalty_discount_account WHERE account_id = 1')->fetchColumn();
    $payoutStmt = $pdo->query(
        'SELECT p.payout_id, p.payout_month, p.amount_mmk, p.note, p.processed_at, u.user_name
         FROM loyalty_discount_payouts p
         LEFT JOIN users u ON u.user_id = p.processed_by_user_id
         ORDER BY p.processed_at DESC, p.payout_id DESC
         LIMIT 50'
    );
    $payouts = $payoutStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('loyalty_management: ' . $e->getMessage());
    $error = $e->getMessage();
}

$tierLabels = loyalty_tier_labels();
$redemptionValues = array_values($redemptions ?? []);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Loyalty &amp; Billing Management</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="/css/Admin.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body class="background_color" id="background_color">
<?php $adminSection = 'loyalty'; include __DIR__ . '/admin_section_nav.php'; ?>
<main class="container my-5">
    <h1 class="h3 text-white mb-4">Loyalty &amp; Billing Management</h1>
    <?php if ($message !== ''): ?><div class="alert alert-success"><?= hq_escape($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= hq_escape($error) ?></div><?php endif; ?>

    <?php if (isset($tierRules, $redemptions, $pointsRate)): ?>
    <section class="analytics-card mb-4">
        <h2 class="h5 text-white">Customer rewards, tiers, and billing</h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= hq_escape((string) $_SESSION['admin_loyalty_csrf']) ?>">
            <input type="hidden" name="action" value="save_settings">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label text-white" for="points-per-liter">Points earned per external-fill liter</label>
                    <input class="form-control" id="points-per-liter" name="points_per_liter" type="number" min="0" max="1000" step="0.01" value="<?= hq_escape($pointsRate) ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label text-white" for="registration-fee">HQ registration fee (MMK)</label>
                    <input class="form-control" id="registration-fee" name="registration_fee_mmk" type="number" min="0" step="1" value="<?= (int) $registrationFee ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label text-white" for="package-rate">All-in-one station package (MMK / station / month)</label>
                    <input class="form-control" id="package-rate" name="package_mmk_per_station_month" type="number" min="0" step="1" value="<?= (int) $packageRate ?>" required>
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" id="pool-enforced" name="pool_enforced" type="checkbox" value="1" <?= $poolEnforced ? 'checked' : '' ?>>
                        <label class="form-check-label text-white" for="pool-enforced">Enforce monthly shared tier-pool budgets</label>
                    </div>
                </div>
            </div>

            <h3 class="h6 text-white mt-4">Fixed redemption options</h3>
            <div class="table-responsive">
                <table class="table table-dark align-middle">
                    <thead><tr><th>Option</th><th>Points</th><th>Discount (MMK)</th></tr></thead>
                    <tbody>
                    <?php for ($index = 0; $index < 3; $index++): $rewardId = $index + 1; ?>
                        <tr>
                            <th>Option <?= $rewardId ?></th>
                            <td><input class="form-control" name="reward_points[<?= $rewardId ?>]" type="number" min="1" step="1" value="<?= (int) array_keys($redemptions)[$index] ?>" required></td>
                            <td><input class="form-control" name="reward_discount[<?= $rewardId ?>]" type="number" min="0.01" step="0.01" value="<?= number_format($redemptionValues[$index], 2, '.', '') ?>" required></td>
                        </tr>
                    <?php endfor; ?>
                    </tbody>
                </table>
            </div>

            <h3 class="h6 text-white mt-4">Tier thresholds and shared monthly MMK pools</h3>
            <p class="text-white-50 small">A driver must meet both the point threshold and the EXP threshold. Meeting the 35 L weekly quota adds 1 EXP; a missed week removes 1 EXP. Pool usage is shared by all drivers in the same tier each calendar month.</p>
            <div class="table-responsive">
                <table class="table table-dark align-middle">
                    <thead><tr><th>Tier</th><th>Points</th><th>EXP weeks</th><th>Pool budget (MMK / month)</th></tr></thead>
                    <tbody>
                    <?php foreach ($tierLabels as $code => $label): ?>
                        <tr>
                            <th><?= hq_escape($label) ?></th>
                            <td><input class="form-control" name="tier_points[<?= hq_escape($code) ?>]" type="number" min="0" step="1" value="<?= $tierRules[$code]['points_threshold'] ?>" required></td>
                            <td><input class="form-control" name="tier_exp[<?= hq_escape($code) ?>]" type="number" min="0" step="1" value="<?= $tierRules[$code]['exp_threshold'] ?>" required></td>
                            <td><input class="form-control" name="tier_budget[<?= hq_escape($code) ?>]" type="number" min="0" step="1000" value="<?= number_format($tierRules[$code]['monthly_budget_mmk'], 2, '.', '') ?>" required></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <button class="btn btn-primary" type="submit">Save settings</button>
        </form>
    </section>

    <section class="analytics-card mb-4">
        <h2 class="h5 text-white">Total Discounted Amount and monthly payout</h2>
        <p class="text-white-50">Available balance: <strong class="text-white"><?= number_format($balance, 2) ?> MMK</strong></p>
        <form method="post" class="row g-3 align-items-end">
            <input type="hidden" name="csrf_token" value="<?= hq_escape((string) $_SESSION['admin_loyalty_csrf']) ?>">
            <input type="hidden" name="action" value="process_payout">
            <div class="col-md-3"><label class="form-label text-white" for="payout-month">Payout month</label><input class="form-control" id="payout-month" name="payout_month" type="month" value="<?= date('Y-m') ?>" required></div>
            <div class="col-md-3"><label class="form-label text-white" for="payout-amount">Amount (MMK)</label><input class="form-control" id="payout-amount" name="payout_amount" type="number" min="0.01" step="0.01" max="<?= number_format($balance, 2, '.', '') ?>" required></div>
            <div class="col-md-4"><label class="form-label text-white" for="payout-note">Note</label><input class="form-control" id="payout-note" name="payout_note" maxlength="500"></div>
            <div class="col-md-2"><button class="btn btn-success w-100" type="submit" <?= $balance <= 0 ? 'disabled' : '' ?>>Record payout</button></div>
        </form>
        <div class="table-responsive mt-4">
            <table class="table table-dark align-middle">
                <thead><tr><th>Month</th><th>Amount</th><th>Processed by</th><th>Processed at</th><th>Note</th></tr></thead>
                <tbody>
                <?php foreach ($payouts as $payout): ?>
                    <tr><td><?= hq_escape((string) $payout['payout_month']) ?></td><td><?= number_format((float) $payout['amount_mmk'], 2) ?> MMK</td><td><?= hq_escape((string) ($payout['user_name'] ?? 'Admin')) ?></td><td><?= hq_escape((string) $payout['processed_at']) ?></td><td><?= hq_escape((string) ($payout['note'] ?? '')) ?></td></tr>
                <?php endforeach; ?>
                <?php if ($payouts === []): ?><tr><td colspan="5" class="text-white-50">No payouts recorded yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
