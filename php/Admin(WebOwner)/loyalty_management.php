<?php
require_once __DIR__ . '/admin_common.php';
require_once __DIR__ . '/../loyalty_points.php';
require_once __DIR__ . '/../billing_config.php';
require_once __DIR__ . '/../booking_rules.php';

admin_require_admin();
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$error = '';
$message = '';
$companyAccounts = [];
$payouts = [];
$companyTotalBalance = 0.0;
$unallocatedBalance = 0.0;
$spentThisMonth = 0.0;
$reservedPointsValue = 0.0;
$committedThisMonth = 0.0;
$availableBudget = 0.0;
$selectedHeadquarterId = 0;
$selectedHeadquarterAvailable = 0.0;
$monthlyBudget = 0.0;
$fuelWeeklyQuotaLiters = (float) MAP_PUMP_FUEL_QUOTA_LITERS;
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
            $monthlyBudgetInput = filter_var($_POST['monthly_budget_mmk'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($pointsRate === false || $pointsRate < 0 || $pointsRate > 1000
                || $registrationFee === false || $registrationFee < 0
                || $packageRate === false || $packageRate < 0
                || $monthlyBudgetInput === false || !is_finite((float) $monthlyBudgetInput)
                || $monthlyBudgetInput < 0 || $monthlyBudgetInput > 999999999999.99) {
                throw new RuntimeException('Enter valid non-negative earning, billing, and monthly budget amounts.');
            }
            $monthlyBudgetInput = round((float) $monthlyBudgetInput, 2);

            $tierInputs = [];
            $redemptionInputs = [];
            foreach (array_keys(loyalty_tier_labels()) as $code) {
                $tierInputs[$code] = [
                    'points_cap' => filter_var($_POST['tier_caps'][$code] ?? null, FILTER_VALIDATE_INT),
                    'exp_threshold' => filter_var($_POST['tier_exp'][$code] ?? null, FILTER_VALIDATE_INT),
                    'monthly_budget_mmk' => 0.0,
                ];
                if ($tierInputs[$code]['points_cap'] === false || $tierInputs[$code]['points_cap'] <= 0
                    || $tierInputs[$code]['exp_threshold'] === false || $tierInputs[$code]['exp_threshold'] < 0) {
                    throw new RuntimeException('Enter a positive point cap and a non-negative EXP requirement for every tier.');
                }
            }
            $tierCodes = array_keys($tierInputs);
            if ($tierInputs['bronze']['exp_threshold'] !== 0) {
                throw new RuntimeException('Bronze must remain the starting tier at 0 EXP.');
            }
            for ($index = 1; $index < count($tierCodes); $index++) {
                $previous = $tierInputs[$tierCodes[$index - 1]];
                $current = $tierInputs[$tierCodes[$index]];
                if ($current['points_cap'] <= $previous['points_cap']
                    || $current['exp_threshold'] <= $previous['exp_threshold']) {
                    throw new RuntimeException('Each higher tier must have a larger point cap and EXP requirement.');
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
            $currentMonthlyBudget = loyalty_lock_monthly_pool_budget($pdo);
            foreach (array_keys($tierInputs) as $code) {
                $tierInputs[$code]['monthly_budget_mmk'] = (float) $monthlyBudgetInput;
            }
            $currentYearMonth = loyalty_current_year_month();
            $spentThisMonthStmt = $pdo->prepare(
                'SELECT spent_mmk
                 FROM loyalty_tier_pool
                 WHERE `year_month` = ?
                 FOR UPDATE'
            );
            $spentThisMonthStmt->execute([$currentYearMonth]);
            $spentThisMonth = 0.0;
            while (($spentValue = $spentThisMonthStmt->fetchColumn()) !== false) {
                $spentThisMonth += (float) $spentValue;
            }
            $committedThisMonth = $spentThisMonth + loyalty_unredeemed_points_value($pdo, $currentYearMonth);
            if ((float) $monthlyBudgetInput + 0.0001 < $committedThisMonth
                && (float) $monthlyBudgetInput + 0.0001 < $currentMonthlyBudget) {
                throw new RuntimeException(
                    'Budget is below ' . number_format($committedThisMonth, 2) . ' MMK already committed this month.'
                );
            }

            $saveSetting = $pdo->prepare(
                'INSERT INTO platform_settings (setting_key, setting_value)
                 VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
            );
            $saveSetting->execute(['loyalty_points_per_liter', number_format((float) $pointsRate, 4, '.', '')]);
            $saveSetting->execute(['loyalty_monthly_budget_mmk', number_format((float) $monthlyBudgetInput, 2, '.', '')]);
            $saveSetting->execute(['hq_registration_fee_mmk', (string) $registrationFee]);
            $saveSetting->execute(['hq_package_mmk_per_station_month', (string) $packageRate]);

            $saveTier = $pdo->prepare(
                'UPDATE loyalty_tier_rules
                 SET points_threshold = ?, exp_threshold = ?, monthly_budget_mmk = ?
                 WHERE tier_code = ?'
            );
            foreach ($tierInputs as $code => $rule) {
                $saveTier->execute([
                    $rule['points_cap'],
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

            $saveCurrentPool = $pdo->prepare(
                'INSERT INTO loyalty_tier_pool (tier_code, `year_month`, budget_mmk, spent_mmk)
                 VALUES (?, ?, ?, 0)
                 ON DUPLICATE KEY UPDATE budget_mmk = VALUES(budget_mmk)'
            );
            foreach ($tierInputs as $code => $rule) {
                $saveCurrentPool->execute([
                    $code,
                    $currentYearMonth,
                    $rule['monthly_budget_mmk'],
                ]);
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
                throw new RuntimeException('Enter a positive amount, valid month, and optional reference up to 500 characters.');
            }
            $amount = round((float) $amount, 2);
            $headquarterId = filter_var($_POST['headquarter_id'] ?? null, FILTER_VALIDATE_INT);
            $adminId = (int) ($_SESSION['user_id'] ?? 0);
            if ($headquarterId === false || $headquarterId <= 0 || $adminId <= 0) {
                throw new RuntimeException('Choose a company and verify the signed-in Admin account.');
            }

            loyalty_ensure_payout_verification_schema($pdo);

            $pdo->beginTransaction();
            $accountLock = $pdo->prepare(
                'SELECT balance_mmk
                 FROM loyalty_company_discount_accounts
                 WHERE headquarter_id = ?
                 LIMIT 1
                 FOR UPDATE'
            );
            $accountLock->execute([$headquarterId]);
            $balance = $accountLock->fetchColumn();
            if ($balance === false) {
                throw new RuntimeException('This company has no discounted amount to pay.');
            }
            $pendingStmt = $pdo->prepare(
                "SELECT COALESCE(SUM(amount_mmk), 0)
                 FROM loyalty_discount_payouts
                 WHERE headquarter_id = ? AND status = 'pending'
                 FOR UPDATE"
            );
            $pendingStmt->execute([$headquarterId]);
            $pendingTotal = (float) $pendingStmt->fetchColumn();
            $available = (float) $balance - $pendingTotal;
            if ($available + 0.0001 < $amount) {
                throw new RuntimeException(
                    'Amount exceeds what is still available to request ('
                    . number_format(max(0.0, $available), 2) . ' MMK after pending requests).'
                );
            }
            // Do not debit yet — company must confirm the payment first.
            $payout = $pdo->prepare(
                'INSERT INTO loyalty_discount_payouts
                    (headquarter_id, payout_month, amount_mmk, note, status, processed_by_user_id)
                 VALUES (?, ?, ?, ?, \'pending\', ?)'
            );
            $payout->execute([$headquarterId, $month, $amount, $note !== '' ? $note : null, $adminId]);
            $pdo->commit();
            $message = 'Payment verification sent to the company. The amount due decreases only after they confirm.';
        } elseif (($_POST['action'] ?? '') === 'save_fuel_quota') {
            $quotaInput = filter_var($_POST['fuel_weekly_quota_liters'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($quotaInput === false) {
                throw new RuntimeException('Enter a valid weekly fuel limit in liters.');
            }
            $savedQuota = map_pump_save_fuel_quota_liters($pdo, (float) $quotaInput);
            $fuelWeeklyQuotaLiters = $savedQuota;
            $message = 'Weekly fuel limit saved: ' . number_format($savedQuota, 2) . ' liters per vehicle.';
        } else {
            throw new RuntimeException('Choose a valid loyalty-management action.');
        }
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('loyalty_management save: ' . $e->getMessage());
    $error = $e instanceof RuntimeException
        ? $e->getMessage()
        : 'That change could not be saved. Please try again.';
}

try {
    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Database is unavailable.');
    }

    $pointsRateStmt = $pdo->prepare('SELECT setting_value FROM platform_settings WHERE setting_key = ?');
    $pointsRateStmt->execute(['loyalty_points_per_liter']);
    $pointsRate = (string) $pointsRateStmt->fetchColumn();
    $registrationFee = billing_admin_setting($pdo, 'hq_registration_fee_mmk', BILLING_HQ_REGISTRATION_FEE_MMK);
    $packageRate = billing_admin_setting($pdo, 'hq_package_mmk_per_station_month', BILLING_HQ_PACKAGE_MMK_PER_STATION_MONTHLY);
    $tierRules = loyalty_get_tier_rules($pdo);
    $monthlyBudget = loyalty_monthly_pool_budget($pdo);
    $fuelWeeklyQuotaLiters = map_pump_fuel_quota_liters($pdo);
    $spentThisMonthStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(spent_mmk), 0)
         FROM loyalty_tier_pool
         WHERE `year_month` = ?'
    );
    $spentThisMonthStmt->execute([loyalty_current_year_month()]);
    $spentThisMonth = (float) $spentThisMonthStmt->fetchColumn();
    $reservedPointsValue = loyalty_unredeemed_points_value($pdo);
    $committedThisMonth = $spentThisMonth + $reservedPointsValue;
    $availableBudget = max(0.0, $monthlyBudget - $committedThisMonth);
    $redemptions = loyalty_redemption_options($pdo);
    if (count($redemptions) !== 3) {
        throw new RuntimeException('Exactly three redemption options must be configured.');
    }
    loyalty_ensure_payout_verification_schema($pdo);
    $companyAccountsStmt = $pdo->query(
        "SELECT h.headquarter_id, h.company_name,
                COALESCE(a.balance_mmk, 0) AS balance_mmk,
                COALESCE(p.pending_mmk, 0) AS pending_mmk
         FROM headquarter h
         LEFT JOIN loyalty_company_discount_accounts a ON a.headquarter_id = h.headquarter_id
         LEFT JOIN (
             SELECT headquarter_id, SUM(amount_mmk) AS pending_mmk
             FROM loyalty_discount_payouts
             WHERE status = 'pending'
             GROUP BY headquarter_id
         ) p ON p.headquarter_id = h.headquarter_id
         UNION ALL
         SELECT a.headquarter_id, CONCAT('Removed company #', a.headquarter_id) AS company_name,
                a.balance_mmk, COALESCE(p.pending_mmk, 0) AS pending_mmk
         FROM loyalty_company_discount_accounts a
         LEFT JOIN headquarter h ON h.headquarter_id = a.headquarter_id
         LEFT JOIN (
             SELECT headquarter_id, SUM(amount_mmk) AS pending_mmk
             FROM loyalty_discount_payouts
             WHERE status = 'pending'
             GROUP BY headquarter_id
         ) p ON p.headquarter_id = a.headquarter_id
         WHERE h.headquarter_id IS NULL
         ORDER BY company_name, headquarter_id"
    );
    $companyAccounts = $companyAccountsStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($companyAccounts as &$accountRow) {
        $balance = (float) $accountRow['balance_mmk'];
        $pending = (float) $accountRow['pending_mmk'];
        $accountRow['available_mmk'] = max(0.0, $balance - $pending);
    }
    unset($accountRow);
    $companyTotalBalance = array_sum(array_map(
        static fn (array $account): float => (float) $account['balance_mmk'],
        $companyAccounts
    ));
    $unallocatedBalance = (float) $pdo->query(
        'SELECT balance_mmk FROM loyalty_discount_account WHERE account_id = 1'
    )->fetchColumn();
    $selectedHeadquarterId = (int) ($_POST['headquarter_id'] ?? ($companyAccounts[0]['headquarter_id'] ?? 0));
    if (!in_array($selectedHeadquarterId, array_map(
        static fn (array $account): int => (int) $account['headquarter_id'],
        $companyAccounts
    ), true)) {
        $selectedHeadquarterId = (int) ($companyAccounts[0]['headquarter_id'] ?? 0);
    }
    $selectedHeadquarterBalance = 0.0;
    $selectedHeadquarterAvailable = 0.0;
    foreach ($companyAccounts as $account) {
        if ((int) $account['headquarter_id'] === $selectedHeadquarterId) {
            $selectedHeadquarterBalance = (float) $account['balance_mmk'];
            $selectedHeadquarterAvailable = (float) $account['available_mmk'];
            break;
        }
    }
    $payoutStmt = $pdo->query(
        'SELECT p.payout_id, p.headquarter_id, h.company_name,
                p.payout_month, p.amount_mmk, p.note, p.status, p.processed_at, u.user_name
         FROM loyalty_discount_payouts p
         LEFT JOIN headquarter h ON h.headquarter_id = p.headquarter_id
         LEFT JOIN users u ON u.user_id = p.processed_by_user_id
         ORDER BY p.processed_at DESC, p.payout_id DESC
         LIMIT 50'
    );
    $payouts = $payoutStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('loyalty_management: ' . $e->getMessage());
    if ($error === '') {
        $error = 'Loyalty and billing information could not be loaded. Please try again later.';
    }
}

$tierLabels = loyalty_tier_labels();
$redemptionValues = array_values($redemptions ?? []);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Manage loyalty rewards, billing, company payouts, and fuel limits.">
    <title>Loyalty &amp; Billing — Map &amp; Pump</title>
    <link rel="icon" type="image/png" href="/images/Logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&amp;display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="/css/Admin.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        /* Matches the shared visual language used by the Users and Requests admin views. */
        body.background_color.admin-blue-page {
            --ab-bg: #0b1220; --ab-surface: #121d2d; --ab-raised: #182537;
            --ab-border: #27364a; --ab-text: #edf3fa; --ab-muted: #a4b3c6;
            --ab-accent: #79aaff; --ab-warning: #f7ca72; --ab-danger: #ff9aab;
            --neon-cyan: var(--ab-accent); --neon-blue: #89b5ff;
            --glass-border: var(--ab-border); --text-primary: var(--ab-text); --text-muted: var(--ab-muted);
            background: radial-gradient(ellipse at 90% 0%, rgba(45,80,155,.13), transparent 48%), var(--ab-bg);
            color: var(--ab-text); min-height: 100vh; background-repeat: no-repeat;
        }
        body.background_color.admin-blue-page:is(.light-mode,[data-theme="light"]) {
            --ab-bg: #f1f5f8; --ab-surface: #fff; --ab-raised: #f4f7fa; --ab-border: #d9e3ec;
            --ab-text: #172b40; --ab-muted: #53667b; --ab-accent: #285ec7;
            --ab-warning: #865b08; --ab-danger: #b0354c;
        }
        .admin-blue-page .admin-blue-shell { max-width: 1480px; padding-inline: clamp(16px,3.5vw,48px); margin: 36px auto 24px !important; color: var(--ab-text); font: 14px/1.65 'Poppins',system-ui,sans-serif; }
        .admin-blue-page .admin-blue-shell *, .admin-blue-page .admin-blue-shell *::before, .admin-blue-page .admin-blue-shell *::after { box-sizing: border-box; }
        .admin-blue-page .admin-blue-header { padding: 8px 0 28px; border-bottom: 1px solid var(--ab-border); }
        .admin-blue-page .admin-blue-header h1 { color: var(--ab-text); font-size: clamp(26px,3vw,38px); font-weight: 600; letter-spacing: -.03em; line-height: 1.3; }
        .admin-blue-page .companies-eyebrow { color: var(--ab-accent); font-size: 11px; font-weight: 600; letter-spacing: .13em; text-transform: uppercase; }
        .admin-blue-page .admin-blue-description, .admin-blue-page .loyalty-muted { color: var(--ab-muted) !important; font-size: 13px; }
        .admin-blue-page .admin-blue-shell :is(.analytics-card,.loyalty-metric-card) { padding: 24px; border: 1px solid var(--ab-border); border-radius: 14px; background: var(--ab-surface); color: var(--ab-text); box-shadow: 0 6px 24px rgba(0,0,0,.08); }
        .admin-blue-page .loyalty-metric-card { position: relative; display: flex; align-items: center; justify-content: space-between; gap: 18px; height: 100%; min-height: 148px; overflow: hidden; background: linear-gradient(135deg,rgba(121,170,255,.025),transparent 80%),var(--ab-surface) !important; }
        .admin-blue-page .loyalty-metric-card::before { content: ''; position: absolute; top: 24px; left: 0; width: 3px; height: 32px; border-radius: 0 4px 4px 0; background: var(--ab-accent); }
        .admin-blue-page .loyalty-metric-card span { display: block; margin-bottom: 10px; color: var(--ab-muted); font-size: 12px; font-weight: 500; }
        .admin-blue-page .loyalty-metric-card strong { display: block; font-size: clamp(22px,2.2vw,30px); font-weight: 600; line-height: 1.35; font-variant-numeric: tabular-nums; }
        .admin-blue-page .loyalty-metric-card strong small { display: block; margin-top: 6px; color: var(--ab-muted); font-size: 11px; font-weight: 500; letter-spacing: .1em; }
        .admin-blue-page :is(.loyalty-metric-card > i,.loyalty-section-icon) { display: grid; place-items: center; flex: 0 0 auto; width: 44px; height: 44px; border: 1px solid var(--ab-border); border-radius: 12px; background: var(--ab-raised); color: var(--ab-accent); font-size: 20px; }
        .admin-blue-page .loyalty-section-heading { display: flex; align-items: center; gap: 14px; margin-bottom: 24px; }
        .admin-blue-page .loyalty-section-heading h2 { color: var(--ab-text); font-size: 18px; font-weight: 600; letter-spacing: -.03em; }
        .admin-blue-page .loyalty-budget-control { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 24px; }
        .admin-blue-page .loyalty-budget-input { flex: 1 1 260px; min-width: 0; }
        .admin-blue-page .loyalty-budget-status { display: grid; grid-template-columns: auto auto; flex: 1 1 310px; gap: 8px 24px; padding-left: 24px; border-left: 1px solid var(--ab-border); font-size: 12px; }
        .admin-blue-page .loyalty-budget-status span { color: var(--ab-muted); }.admin-blue-page .loyalty-budget-status strong { color: var(--ab-text); font-weight: 500; text-align: right; font-variant-numeric: tabular-nums; }.admin-blue-page #loyalty-budget-remaining,.admin-blue-page .loyalty-company-balance { color: var(--ab-accent) !important; }
        .admin-blue-page .admin-blue-shell :is(.form-label,.form-control,.form-select) { font-family: 'Poppins',system-ui,sans-serif; }.admin-blue-page .admin-blue-shell .form-label { display:block; margin-bottom:8px; color:var(--ab-muted); font-size:12px; font-weight:500; }
        .admin-blue-page .admin-blue-shell :is(.form-control,.form-select) { min-height:44px; border:1px solid var(--ab-border); border-radius:8px; background:var(--ab-raised); color:var(--ab-text); font-size:13px; box-shadow:none; }.admin-blue-page:not(.light-mode):not([data-theme="light"]) .admin-blue-shell :is(.form-control,.form-select) { color-scheme:dark; }.admin-blue-page .admin-blue-shell :is(.form-control,.form-select):focus { border-color:var(--ab-accent); outline:2px solid var(--ab-accent); outline-offset:2px; box-shadow:none; }
        .admin-blue-page .admin-blue-shell .btn { display:inline-flex; align-items:center; justify-content:center; gap:6px; min-height:44px; border-radius:8px; padding:10px 16px; font:500 12px 'Poppins',system-ui,sans-serif; }.admin-blue-page .admin-blue-shell :is(.btn-primary,.btn-success) { border-color:#79aaff; background:#79aaff; color:#10284c; }.admin-blue-page .admin-blue-shell :is(.btn-primary,.btn-success):hover { border-color:#9cc1ff; background:#9cc1ff; color:#10284c; }
        .admin-blue-page .loyalty-advanced-settings { margin-top:28px; overflow:hidden; border:1px solid var(--ab-border); border-radius:10px; }.admin-blue-page .loyalty-advanced-settings summary { padding:16px 20px; cursor:pointer; background:var(--ab-raised); color:var(--ab-accent); font-size:13px; font-weight:500; }.admin-blue-page .loyalty-advanced-content { padding:24px; }.admin-blue-page .loyalty-form-actions { display:flex; justify-content:flex-end; margin-top:20px; }
        .admin-blue-page .admin-blue-shell .table-responsive { overflow:auto; border:1px solid var(--ab-border); border-radius:10px; scrollbar-color:var(--ab-border) var(--ab-raised); }.admin-blue-page .admin-blue-shell .table { --bs-table-bg:transparent; --bs-table-color:var(--ab-text); --bs-table-border-color:var(--ab-border); margin-bottom:0; color:var(--ab-text); font-size:13px; }.admin-blue-page .admin-blue-shell .table > :not(caption) > * > * { padding:16px; color:inherit; background:transparent; border-color:var(--ab-border); }.admin-blue-page .admin-blue-shell .table thead th { background:var(--ab-raised); color:var(--ab-muted); font-size:11px; font-weight:500; letter-spacing:.06em; text-transform:uppercase; white-space:nowrap; }.admin-blue-page .admin-blue-shell .table tbody tr:hover { background:var(--ab-raised); }.admin-blue-page .loyalty-table:has(input) { min-width:520px; }.admin-blue-page .loyalty-table:has(.loyalty-status) { min-width:840px; }
        .admin-blue-page .loyalty-payout-instructions { max-width:740px; }.admin-blue-page .loyalty-payout-balance { display:block; padding:14px 18px; border:1px solid var(--ab-border); border-radius:8px; background:var(--ab-raised); color:var(--ab-muted); font-size:12px; }.admin-blue-page .loyalty-payout-balance strong { color:var(--ab-text); font-weight:500; }.admin-blue-page .loyalty-optional { margin-left:6px; font-size:11px; font-weight:400; }
        .admin-blue-page .loyalty-status { display:inline-flex; align-items:center; gap:7px; padding:5px 9px; border:1px solid var(--ab-border); border-radius:6px; background:var(--ab-raised); color:var(--ab-muted); font-size:11px; font-weight:500; }.admin-blue-page .loyalty-status-pending { color:var(--ab-warning); }.admin-blue-page :is(.loyalty-status-confirmed,.loyalty-status-paid,.loyalty-status-approved) { color:var(--ab-accent); }.admin-blue-page :is(.loyalty-status-rejected,.loyalty-status-cancelled) { color:var(--ab-danger); }
        .admin-blue-page .admin-blue-shell .alert { padding:16px 20px; border:1px solid var(--ab-border); border-radius:10px; background:var(--ab-raised); color:var(--ab-text); font-size:13px; }.admin-blue-page .admin-blue-shell .alert-success { border-color:rgba(121,170,255,.4); }.admin-blue-page .admin-blue-shell .alert-danger { border-color:rgba(255,154,171,.4); }
        @media (max-width:767.98px) { .admin-blue-page .admin-blue-shell { margin-top:22px !important; }.admin-blue-page .admin-blue-shell :is(.analytics-card,.loyalty-metric-card) { padding:20px; }.admin-blue-page .loyalty-budget-control { gap:18px; }.admin-blue-page .loyalty-budget-status { flex-basis:100%; padding-top:18px; padding-left:0; border-top:1px solid var(--ab-border); border-left:0; }.admin-blue-page .loyalty-budget-save { width:100%; }.admin-blue-page .loyalty-advanced-content { padding:18px; } }
        @media (prefers-reduced-motion:reduce) { .admin-blue-page .admin-blue-shell * { transition:none !important; animation:none !important; } }
    </style>
</head>
<body class="background_color admin-blue-page" id="background_color">
<?php $adminSection = 'loyalty'; include __DIR__ . '/admin_section_nav.php'; ?>
<main class="container my-5 admin-blue-shell loyalty-billing-page">
    <header class="admin-blue-header mb-4">
        <div class="companies-eyebrow">Platform controls</div>
        <h1 class="mb-0">Loyalty &amp; Billing</h1>
        <p class="admin-blue-description">Configure rewards, monthly budget controls, company payouts, and vehicle fuel limits.</p>
    </header>
    <?php if ($message !== ''): ?><div class="alert alert-success"><?= hq_escape($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= hq_escape($error) ?></div><?php endif; ?>

    <?php if (isset($tierRules, $redemptions, $pointsRate)): ?>
    <section class="row g-3 loyalty-summary mb-4" aria-label="Loyalty and billing summary">
        <div class="col-md-4"><article class="loyalty-metric-card"><div><span>Budget this month</span><strong><?= number_format($monthlyBudget, 2) ?><small>MMK</small></strong></div><i class="bi bi-calendar2-check" aria-hidden="true"></i></article></div>
        <div class="col-md-4"><article class="loyalty-metric-card"><div><span>Discounts given</span><strong><?= number_format($spentThisMonth, 2) ?><small>MMK</small></strong></div><i class="bi bi-receipt" aria-hidden="true"></i></article></div>
        <div class="col-md-4"><article class="loyalty-metric-card"><div><span>Owed to companies</span><strong><?= number_format($companyTotalBalance, 2) ?><small>MMK</small></strong></div><i class="bi bi-wallet2" aria-hidden="true"></i></article></div>
    </section>

    <section class="analytics-card mb-4">
        <div class="loyalty-section-heading"><span class="loyalty-section-icon"><i class="bi bi-sliders" aria-hidden="true"></i></span><div><h2 class="h5 mb-0">Monthly budget</h2></div></div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= hq_escape((string) $_SESSION['admin_loyalty_csrf']) ?>">
            <input type="hidden" name="action" value="save_settings">
            <div class="loyalty-budget-control">
                <div class="loyalty-budget-input">
                    <label class="form-label" for="monthly-budget">Monthly limit (MMK)</label>
                    <input class="form-control" id="monthly-budget" name="monthly_budget_mmk" type="number" min="<?= number_format($committedThisMonth, 2, '.', '') ?>" max="999999999999.99" step="0.01" value="<?= number_format($monthlyBudget, 2, '.', '') ?>" required data-used="<?= number_format($spentThisMonth, 2, '.', '') ?>" data-held="<?= number_format($reservedPointsValue, 2, '.', '') ?>">
                </div>
                <div class="loyalty-budget-status" aria-live="polite">
                    <span>Used</span><strong id="loyalty-budget-used"><?= number_format($spentThisMonth, 2) ?> MMK</strong>
                    <span>Held for open points</span><strong id="loyalty-budget-held"><?= number_format($reservedPointsValue, 2) ?> MMK</strong>
                    <span>Available</span><strong id="loyalty-budget-remaining"><?= number_format($availableBudget, 2) ?> MMK</strong>
                </div>
                <button class="btn btn-primary loyalty-budget-save" type="submit"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save</button>
            </div>

            <details class="loyalty-advanced-settings">
                <summary>Rewards and tiers</summary>
                <div class="loyalty-advanced-content">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="points-per-liter">Points per liter</label>
                            <input class="form-control" id="points-per-liter" name="points_per_liter" type="number" min="0" max="1000" step="0.01" value="<?= hq_escape($pointsRate) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="registration-fee">Registration fee (MMK)</label>
                            <input class="form-control" id="registration-fee" name="registration_fee_mmk" type="number" min="0" step="1" value="<?= (int) $registrationFee ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="package-rate">Station fee (MMK)</label>
                            <input class="form-control" id="package-rate" name="package_mmk_per_station_month" type="number" min="0" step="1" value="<?= (int) $packageRate ?>" required>
                        </div>
                    </div>

                    <h3 class="h6 mt-4">Rewards</h3>
                    <div class="table-responsive">
                        <table class="table loyalty-table align-middle">
                            <thead><tr><th>Option</th><th>Points</th><th>Discount (MMK)</th></tr></thead>
                            <tbody>
                            <?php for ($index = 0; $index < 3; $index++): $rewardId = $index + 1; ?>
                                <tr>
                                    <th><?= $rewardId ?></th>
                                    <td><input class="form-control" name="reward_points[<?= $rewardId ?>]" type="number" min="1" step="1" value="<?= (int) array_keys($redemptions)[$index] ?>" required></td>
                                    <td><input class="form-control" name="reward_discount[<?= $rewardId ?>]" type="number" min="0.01" step="0.01" value="<?= number_format($redemptionValues[$index], 2, '.', '') ?>" required></td>
                                </tr>
                            <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>

                    <h3 class="h6 mt-4">Tiers</h3>
                    <div class="table-responsive">
                        <table class="table loyalty-table align-middle">
                            <thead><tr><th>Tier</th><th>Point cap</th><th>EXP required</th></tr></thead>
                            <tbody>
                            <?php foreach ($tierLabels as $code => $label): ?>
                                <tr>
                                    <th><?= hq_escape($label) ?></th>
                                    <td><input class="form-control" name="tier_caps[<?= hq_escape($code) ?>]" type="number" min="1" step="1" value="<?= $tierRules[$code]['points_cap'] ?>" required></td>
                                    <td><input class="form-control" name="tier_exp[<?= hq_escape($code) ?>]" type="number" min="0" step="1" value="<?= $tierRules[$code]['exp_threshold'] ?>" required></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="loyalty-form-actions"><button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save</button></div>
                </div>
            </details>
        </form>
    </section>

    <section class="analytics-card mb-4">
        <div class="loyalty-section-heading"><span class="loyalty-section-icon"><i class="bi bi-fuel-pump" aria-hidden="true"></i></span><div><h2 class="h5 mb-0">Weekly fuel limit</h2></div></div>
        <form method="post" class="loyalty-budget-control">
            <input type="hidden" name="csrf_token" value="<?= hq_escape((string) $_SESSION['admin_loyalty_csrf']) ?>">
            <input type="hidden" name="action" value="save_fuel_quota">
            <div class="loyalty-budget-input">
                <label class="form-label" for="fuel-weekly-quota">Liters per vehicle</label>
                <input class="form-control" id="fuel-weekly-quota" name="fuel_weekly_quota_liters" type="number" min="0.01" max="500" step="0.01" value="<?= number_format((float) $fuelWeeklyQuotaLiters, 2, '.', '') ?>" required>
            </div>
            <div class="loyalty-budget-status" aria-live="polite">
                <span>Period</span><strong>Weekly</strong>
                <span>Resets</span><strong>Sun 00:00 Yangon</strong>
            </div>
            <button class="btn btn-primary loyalty-budget-save" type="submit"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save</button>
        </form>
    </section>

    <section class="analytics-card mb-4">
        <div class="loyalty-section-heading"><span class="loyalty-section-icon"><i class="bi bi-wallet2" aria-hidden="true"></i></span><div><h2 class="h5 mb-0">Amounts due</h2></div></div>
        <div class="table-responsive mb-4">
            <table class="table loyalty-table align-middle">
                <thead><tr><th>Company</th><th class="text-end">Discounted</th></tr></thead>
                <tbody>
                <?php foreach ($companyAccounts as $account): ?>
                    <tr>
                        <th><?= hq_escape((string) ($account['company_name'] ?: 'Company')) ?></th>
                        <td class="text-end loyalty-company-balance"><?= number_format((float) $account['balance_mmk'], 2) ?> MMK</td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($companyAccounts === []): ?><tr><td colspan="2" class="loyalty-muted">None</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($unallocatedBalance > 0): ?>
            <p class="loyalty-unallocated-note">Unassigned: <strong><?= number_format($unallocatedBalance, 2) ?> MMK</strong></p>
        <?php endif; ?>
        <h3 class="h6 loyalty-subsection-title">Pay company</h3>
        <p class="loyalty-muted loyalty-payout-instructions">Sends a verification request to the company. Amount due drops only after they confirm payment.</p>
        <form method="post" class="row g-3 align-items-end loyalty-payout-form">
            <input type="hidden" name="csrf_token" value="<?= hq_escape((string) $_SESSION['admin_loyalty_csrf']) ?>">
            <input type="hidden" name="action" value="process_payout">
            <div class="col-md-4">
                <label class="form-label" for="payout-company">Company</label>
                <select class="form-select" id="payout-company" name="headquarter_id" required>
                    <?php foreach ($companyAccounts as $account): ?>
                        <option value="<?= (int) $account['headquarter_id'] ?>"
                            data-balance="<?= number_format((float) $account['balance_mmk'], 2, '.', '') ?>"
                            data-available="<?= number_format((float) $account['available_mmk'], 2, '.', '') ?>"
                            data-pending="<?= number_format((float) $account['pending_mmk'], 2, '.', '') ?>"
                            <?= (int) $account['headquarter_id'] === $selectedHeadquarterId ? 'selected' : '' ?>>
                            <?= hq_escape((string) ($account['company_name'] ?: 'Company')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4"><label class="form-label" for="payout-month">Month</label><input class="form-control" id="payout-month" name="payout_month" type="month" value="<?= hq_escape(loyalty_current_year_month()) ?>" required></div>
            <div class="col-md-4"><label class="form-label" for="payout-amount">Amount (MMK)</label><input class="form-control" id="payout-amount" name="payout_amount" type="number" min="0.01" step="0.01" value="<?= number_format($selectedHeadquarterAvailable, 2, '.', '') ?>" max="<?= number_format($selectedHeadquarterAvailable, 2, '.', '') ?>" required></div>
            <div class="col-md-8"><label class="form-label" for="payout-note">Reference <span class="loyalty-optional">Optional</span></label><input class="form-control" id="payout-note" name="payout_note" maxlength="500"></div>
            <div class="col-md-4 d-flex align-items-end"><button class="btn btn-success w-100" id="send-payout-request" type="submit" <?= $selectedHeadquarterAvailable <= 0 ? 'disabled' : '' ?>><i class="bi bi-send me-1" aria-hidden="true"></i>Send for confirm</button></div>
            <div class="col-md-12"><span class="loyalty-payout-balance">Due: <strong id="selected-company-balance"><?= number_format($selectedHeadquarterBalance, 2) ?> MMK</strong> · Available to request: <strong id="selected-company-available"><?= number_format($selectedHeadquarterAvailable, 2) ?> MMK</strong></span></div>
        </form>
        <div class="loyalty-history-heading mt-4"><h3 class="h6 mb-0">Payment requests</h3></div>
        <div class="table-responsive mt-3">
            <table class="table loyalty-table align-middle">
                <thead><tr><th>Company</th><th>Month</th><th>Amount</th><th>Status</th><th>Requested</th><th>Reference</th></tr></thead>
                <tbody>
                <?php foreach ($payouts as $payout): ?>
                    <?php $status = strtolower((string) ($payout['status'] ?? 'pending')); ?>
                    <tr>
                        <td><?= hq_escape((string) ($payout['company_name'] ?? ($payout['headquarter_id'] === null ? 'Unallocated legacy' : 'Removed company #' . (int) $payout['headquarter_id']))) ?></td>
                        <td><?= hq_escape((string) $payout['payout_month']) ?></td>
                        <td><?= number_format((float) $payout['amount_mmk'], 2) ?> MMK</td>
                        <td><span class="loyalty-status loyalty-status-<?= hq_escape($status) ?>"><?= hq_escape(ucfirst($status)) ?></span></td>
                        <td><?= hq_escape((string) $payout['processed_at']) ?></td>
                        <td><?= hq_escape((string) ($payout['note'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($payouts === []): ?><tr><td colspan="6" class="loyalty-muted">None</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="/js/Admin.js"></script>
<script>
(() => {
    const budget = document.getElementById('monthly-budget');
    const budgetRemaining = document.getElementById('loyalty-budget-remaining');
    if (budget && budgetRemaining) {
        const used = Number(budget.dataset.used || 0);
        const held = Number(budget.dataset.held || 0);
        const fmt = (n) => n.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        budget.addEventListener('input', () => {
            const remaining = Math.max(0, Number(budget.value || 0) - used - held);
            budgetRemaining.textContent = `${fmt(remaining)} MMK`;
        });
    }

    const company = document.getElementById('payout-company');
    const amount = document.getElementById('payout-amount');
    const balanceLabel = document.getElementById('selected-company-balance');
    const availableLabel = document.getElementById('selected-company-available');
    const sendButton = document.getElementById('send-payout-request');
    if (!company || !amount || !balanceLabel) return;
    const fmt = (n) => n.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    const updateCompanyBalance = () => {
        const opt = company.selectedOptions[0];
        const balance = Number(opt?.dataset.balance || 0);
        const available = Number(opt?.dataset.available || 0);
        amount.max = available.toFixed(2);
        amount.value = available > 0 ? available.toFixed(2) : '';
        if (sendButton) sendButton.disabled = available <= 0;
        balanceLabel.textContent = `${fmt(balance)} MMK`;
        if (availableLabel) availableLabel.textContent = `${fmt(available)} MMK`;
    };
    company.addEventListener('change', updateCompanyBalance);
    updateCompanyBalance();
})();
</script>
</body>
</html>
