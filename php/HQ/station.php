<?php
require_once __DIR__ . '/hq_guard.php';
/**
 * Shared database connection.
 */
include_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../business_scope.php';
require_once __DIR__ . '/../loyalty_points.php';
require_once __DIR__ . '/../billing_config.php';
require_once __DIR__ . '/../payment_transfers.php';
map_pump_ensure_station_scope_manager_nullable($pdo);
$message = '';
$error = '';
$hqId = map_pump_current_hq_id($pdo);
$billingSchemaReady = loyalty_countermeasures_schema_ready($pdo);
$paymentTransferSchemaReady = payment_transfer_schema_ready($pdo);
$hqPointBalance = company_loyalty_get_balance($pdo, $hqId);
$billingMonth = loyalty_current_year_month();
$stationMonthlyFee = (int) LOYALTY_HQ_STATION_MONTHLY_FEE;
$extraStationMmkMonthly = (int) BILLING_HQ_EXTRA_STATION_MMK_MONTHLY;
$stationBillingCsrf = $_SESSION['hq_station_billing_csrf'] ?? '';
if (!is_string($stationBillingCsrf) || strlen($stationBillingCsrf) < 32) {
    $stationBillingCsrf = bin2hex(random_bytes(32));
    $_SESSION['hq_station_billing_csrf'] = $stationBillingCsrf;
}
$stationLimit = null;
$baseStationLimit = null;
$monthlySlots = 0;
$stationCount = 0;
$stationLimitReached = false;
$showStationLimitModal = false;

function map_pump_normalize_station_location(string $location): ?string
{
    if (!preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/', $location, $matches)) {
        return null;
    }

    $latitude = (float) $matches[1];
    $longitude = (float) $matches[2];
    if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
        return null;
    }

    return number_format($latitude, 6, '.', '') . ', ' . number_format($longitude, 6, '.', '');
}

// Handle Form Submissions & AJAX requests via PDO
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Pay one station's operating fee for the current month.
    if ($action === 'pay_station_fee') {
        $stationIdToPay = (int) ($_POST['station_id'] ?? 0);
        try {
            if (!$billingSchemaReady) {
                throw new RuntimeException('HQ point billing is temporarily unavailable. Please try again later.');
            }
            if (!hash_equals($stationBillingCsrf, (string) ($_POST['billing_csrf'] ?? ''))) {
                throw new RuntimeException('Your session expired. Refresh the page and try again.');
            }
            if ($stationIdToPay <= 0) {
                throw new RuntimeException('Select a valid station.');
            }

            $pdo->beginTransaction();
            $hqLockStmt = $pdo->prepare('SELECT headquarter_id FROM headquarter WHERE headquarter_id = ? FOR UPDATE');
            $hqLockStmt->execute([$hqId]);
            if ($hqLockStmt->fetchColumn() === false) {
                throw new RuntimeException('Could not verify this HQ account.');
            }
            $stationLockStmt = $pdo->prepare(
                'SELECT b.branch_id
                 FROM branches b
                 INNER JOIN station_business_scope sbs ON sbs.station_id = b.branch_id
                 WHERE b.branch_id = ? AND sbs.headquarter_id = ? AND b.status <> \'closed\'
                 FOR UPDATE'
            );
            $stationLockStmt->execute([$stationIdToPay, $hqId]);
            if ($stationLockStmt->fetchColumn() === false) {
                throw new RuntimeException('You can only pay fees for stations under your HQ.');
            }
            if ($paymentTransferSchemaReady) {
                $quoteStmt = $pdo->prepare("SELECT 1 FROM payment_transfers WHERE payment_type = 'hq_station_operating_quote' AND payer_headquarter_id = ? AND station_id = ? AND service_start_month = ? AND status IN ('pending_payment', 'payer_reported_paid') LIMIT 1");
                $quoteStmt->execute([$hqId, $stationIdToPay, $billingMonth]);
                if ($quoteStmt->fetchColumn()) {
                    throw new RuntimeException('An Admin-created MMK quote is pending for this station and month. Cancel the quote before paying this month with points.');
                }
            }
            $paidCheck = $pdo->prepare(
                'SELECT 1 FROM company_loyalty_station_fees
                 WHERE headquarter_id = ? AND station_id = ? AND `year_month` = ? LIMIT 1'
            );
            $paidCheck->execute([$hqId, $stationIdToPay, $billingMonth]);
            if ($paidCheck->fetchColumn()) {
                throw new RuntimeException('This station is already paid for the current month.');
            }
            if (!company_loyalty_redeem_credit($pdo, $hqId, $stationMonthlyFee, 'station monthly operating fee')) {
                throw new RuntimeException('INSUFFICIENT_HQ_POINTS');
            }
            $feeStmt = $pdo->prepare(
                'INSERT INTO company_loyalty_station_fees (headquarter_id, station_id, `year_month`, points_paid)
                 VALUES (?, ?, ?, ?)'
            );
            $feeStmt->execute([$hqId, $stationIdToPay, $billingMonth, $stationMonthlyFee]);
            $pdo->commit();
            $message = 'Station monthly fee paid. The station is eligible to operate for this month.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage() === 'INSUFFICIENT_HQ_POINTS'
                ? 'Insufficient HQ points. Pay by MMK is available for extra stations; ask an Admin about arrangements for included-station point fees.'
                : $e->getMessage();
        }
    }

    // Register Station
    if ($action === 'register') {
        $station_name    = trim($_POST['station_name'] ?? '');
        $location        = trim($_POST['location'] ?? '');
        $station_address = trim($_POST['station_address'] ?? '');
        $manager_email   = trim($_POST['manager_email'] ?? '');

        $location = map_pump_normalize_station_location($location);

        if (!empty($station_name) && $location !== null) {
            try {
                if (!$billingSchemaReady) {
                    throw new RuntimeException('HQ point billing is temporarily unavailable. Please try again later.');
                }
                $pdo->beginTransaction();
                $hqLockStmt = $pdo->prepare("SELECT station_count FROM headquarter WHERE headquarter_id = ? FOR UPDATE");
                $hqLockStmt->execute([$hqId]);
                $stationLimitValue = $hqLockStmt->fetchColumn();
                if ($stationLimitValue === false) {
                    throw new RuntimeException('Could not verify this HQ station limit.');
                }
                $duplicateStationStmt = $pdo->prepare(
                    'SELECT 1
                     FROM branches b
                     INNER JOIN station_business_scope sbs ON sbs.station_id = b.branch_id
                     WHERE sbs.headquarter_id = ?
                       AND LOWER(TRIM(b.branch_name)) = LOWER(?)
                     LIMIT 1'
                );
                $duplicateStationStmt->execute([$hqId, $station_name]);
                if ($duplicateStationStmt->fetchColumn()) {
                    throw new RuntimeException('A station with this name already exists under your HQ.');
                }

                $stationLimit = (int) $stationLimitValue;
                if ($stationLimit < 1) {
                    $stationLimit = null;
                } else {
                    $monthlySlots = company_loyalty_current_month_slots($pdo, $hqId);
                    $baseStationLimit = $stationLimit;
                    $stationLimit += $monthlySlots;
                }
                $stationCountStmt = $pdo->prepare(
                    "SELECT COUNT(*)
                     FROM branches b
                     INNER JOIN station_business_scope sbs ON sbs.station_id = b.branch_id
                     WHERE sbs.headquarter_id = ? AND b.status <> 'closed'"
                );
                $stationCountStmt->execute([$hqId]);
                if ($stationLimit !== null && (int) $stationCountStmt->fetchColumn() >= $stationLimit) {
                    throw new RuntimeException('station_limit_reached');
                }

                $manager_id = null;
                if (!empty($manager_email)) {
                    $managerStmt = $pdo->prepare(
                        "SELECT u.user_id
                         FROM users u
                         LEFT JOIN business_account_scope bas ON bas.user_id = u.user_id
                         WHERE u.email = ?
                           AND COALESCE(u.business_role_id, u.role_id) = 4
                           AND (
                               COALESCE(bas.headquarter_id, u.headquarter_id) = ?
                               OR EXISTS (
                                   SELECT 1 FROM station_business_scope sbs
                                   WHERE sbs.branch_manager_user_id = u.user_id
                                     AND sbs.headquarter_id = ?
                               )
                           )
                         LIMIT 1"
                    );
                    $managerStmt->execute([$manager_email, $hqId, $hqId]);
                    $manager_id = $managerStmt->fetchColumn();
                    if (!$manager_id) {
                        throw new RuntimeException('Please select a valid branch manager or leave the assignment empty.');
                    }
                }

                $temporaryBranchCode = 'TMP-' . bin2hex(random_bytes(8));
                [$latitude, $longitude] = array_map('trim', explode(',', $location, 2));
                $branchStmt = $pdo->prepare(
                    "INSERT INTO branches (branch_code, branch_name, city, township, address, latitude, longitude, manager_user_id, opening_time, closing_time, status, hq_region)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, '06:00:00', '22:00:00', 'active', ? )"
                );
                $branchStmt->execute([$temporaryBranchCode, $station_name, 'Unknown', null, $station_address, $latitude, $longitude, $manager_id, null]);
                $branchId = (int)$pdo->lastInsertId();
                $stationId = $branchId;

                $branchCode = 'BR-' . str_pad((string) $branchId, 5, '0', STR_PAD_LEFT);
                $branchCodeCheck = $pdo->prepare('SELECT 1 FROM branches WHERE branch_code = ? AND branch_id <> ? LIMIT 1');
                $branchCodeCheck->execute([$branchCode, $branchId]);
                while ($branchCodeCheck->fetchColumn()) {
                    $branchCode = 'BR-' . bin2hex(random_bytes(8));
                    $branchCodeCheck->execute([$branchCode, $branchId]);
                }
                $branchCodeUpdate = $pdo->prepare('UPDATE branches SET branch_code = ? WHERE branch_id = ?');
                $branchCodeUpdate->execute([$branchCode, $branchId]);

                $pdo->prepare("DELETE FROM station_business_scope WHERE branch_manager_user_id = ?")->execute([$manager_id]);
                $scopeStmt = $pdo->prepare(
                    "INSERT INTO station_business_scope (station_id, headquarter_id, branch_manager_user_id)
                     VALUES (?, ?, ?)"
                );
                $scopeStmt->execute([$stationId, $hqId, $manager_id ?: null]);
                if ($paymentTransferSchemaReady) {
                    payment_transfer_assign_available_entitlements($pdo, $hqId);
                }

                if ($manager_id) {
                    $accountScopeStmt = $pdo->prepare(
                        "INSERT INTO business_account_scope
                            (user_id, business_role_id, headquarter_id, branch_manager_user_id, station_id, status)
                         VALUES (?, 4, ?, NULL, ?, 'active')
                         ON DUPLICATE KEY UPDATE headquarter_id = VALUES(headquarter_id), station_id = VALUES(station_id), status = 'active'"
                    );
                    $accountScopeStmt->execute([$manager_id, $hqId, $stationId]);

                    $userScopeStmt = $pdo->prepare("UPDATE users SET headquarter_id = ?, business_role_id = 4 WHERE user_id = ?");
                    $userScopeStmt->execute([$hqId, $manager_id]);
                }

                $tankSeedStmt = $pdo->prepare(
                    "INSERT INTO tanks
                        (branch_id, fuel_code, fuel_name, oil_type_id, capacity_l, current_l, alert_pct, is_enabled)
                     VALUES (?, ?, ?, ?, ?, 0, 25, 1)"
                );
                $oilTypeStmt = $pdo->prepare('SELECT oil_type_id FROM oil_types WHERE oil_name = ? LIMIT 1');
                $tankSeeds = [
                    ['p92', 'Petrol 92', 15000],
                    ['p95', 'Petrol 95', 10000],
                    ['dsl', 'Diesel', 12000],
                    ['pdsl', 'Premium Diesel', 12000],
                ];
                foreach ($tankSeeds as $tankSeed) {
                    $oilTypeStmt->execute([$tankSeed[1]]);
                    $oilTypeId = $oilTypeStmt->fetchColumn();
                    if ($oilTypeId === false) {
                        throw new RuntimeException('Required fuel type "' . $tankSeed[1] . '" is missing from oil_types.');
                    }
                    $tankSeedStmt->execute([
                        $stationId,
                        $tankSeed[0],
                        $tankSeed[1],
                        (int) $oilTypeId,
                        $tankSeed[2],
                    ]);
                }
                $pdo->commit();
                $message = "Station node created successfully.";
                $returnTo = trim((string)($_POST['return_to'] ?? ''));
                if ($returnTo !== '') {
                    $_SESSION['hq_account_message'] = ['type' => 'success', 'text' => $message];
                    header('Location: ' . $returnTo);
                    exit;
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = "Error registering station: " . $e->getMessage();
            } catch (RuntimeException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($e->getMessage() === 'station_limit_reached') {
                    $showStationLimitModal = true;
                    $returnTo = trim((string)($_POST['return_to'] ?? ''));
                    if ($returnTo !== '') {
                        $_SESSION['hq_account_message'] = ['type' => 'danger', 'text' => 'Station limit reached for this HQ.'];
                        header('Location: ' . $returnTo);
                        exit;
                    }
                } elseif ($e->getMessage() === 'INSUFFICIENT_HQ_POINTS') {
                    $error = 'Insufficient HQ points. Use Pay by MMK for monthly extra-station capacity and operation; included-station operating fees remain points-based.';
                } else {
                    $error = $e->getMessage();
                }
            }
        } else {
            $error = "Station name and a valid map location are required.";
        }

        $returnTo = trim((string)($_POST['return_to'] ?? ''));
        if ($returnTo !== '' && $error !== '') {
            $_SESSION['hq_account_message'] = ['type' => 'danger', 'text' => $error];
            header('Location: ' . $returnTo);
            exit;
        }
    }

    // Update Configuration
    if ($action === 'configure') {
        $station_id      = trim($_POST['station_id'] ?? '');
        $station_name    = trim($_POST['station_name'] ?? '');
        $station_address = trim($_POST['station_address'] ?? '');
        $location        = trim($_POST['location'] ?? '');
        $manager_email   = trim($_POST['manager_email'] ?? '');

        try {
            $location = map_pump_normalize_station_location($location);
            if ($location === null) {
                throw new RuntimeException('Choose a valid station location from the map.');
            }

            if (!map_pump_station_in_hq($pdo, (int)$station_id, $hqId)) {
                throw new RuntimeException('You can only configure stations under your own HQ.');
            }

            $managerStmt = $pdo->prepare(
                "SELECT u.user_id
                 FROM users u
                 LEFT JOIN business_account_scope bas ON bas.user_id = u.user_id
                 WHERE u.email = ?
                   AND COALESCE(u.business_role_id, u.role_id) = 4
                   AND (
                       COALESCE(bas.headquarter_id, u.headquarter_id) = ?
                       OR EXISTS (
                           SELECT 1 FROM station_business_scope sbs
                           WHERE sbs.branch_manager_user_id = u.user_id
                             AND sbs.headquarter_id = ?
                       )
                   )
                 LIMIT 1"
            );
            $managerStmt->execute([$manager_email, $hqId, $hqId]);
            $manager_id = $managerStmt->fetchColumn();

            if (!$manager_id) {
                throw new RuntimeException('Please select a valid branch manager.');
            }

            $pdo->beginTransaction();
            [$latitude, $longitude] = array_map('trim', explode(',', $location, 2));
            $stmt = $pdo->prepare("UPDATE branches SET branch_name = ?, address = ?, latitude = ?, longitude = ?, manager_user_id = ? WHERE branch_id = ?");
            $stmt->execute([$station_name, $station_address, $latitude, $longitude, $manager_id, $station_id]);
            $scopeStmt = $pdo->prepare("UPDATE station_business_scope SET branch_manager_user_id = ? WHERE station_id = ? AND headquarter_id = ?");
            $scopeStmt->execute([$manager_id, $station_id, $hqId]);
            $accountScopeStmt = $pdo->prepare(
                "INSERT INTO business_account_scope
                    (user_id, business_role_id, headquarter_id, branch_manager_user_id, station_id, status)
                 VALUES (?, 4, ?, NULL, ?, 'active')
                 ON DUPLICATE KEY UPDATE headquarter_id = VALUES(headquarter_id), station_id = VALUES(station_id), status = 'active'"
            );
            $accountScopeStmt->execute([$manager_id, $hqId, $station_id]);
            $userScopeStmt = $pdo->prepare("UPDATE users SET headquarter_id = ?, business_role_id = 4 WHERE user_id = ?");
            $userScopeStmt->execute([$hqId, $manager_id]);
            $pdo->commit();
            $message = "Configuration saved successfully!";
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Error updating configuration: " . $e->getMessage();
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
    }

    // Toggle Status (AJAX Handler)
    if ($action === 'toggle_status') {
        $station_id = $_POST['station_id'] ?? '';
        $new_status = $_POST['status'] ?? '';

        if (!empty($station_id) && in_array($new_status, ['Active', 'Maintenance'])) {
            try {
                if ($new_status === 'Active' && !company_loyalty_station_is_paid($pdo, (int) $station_id)) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'This station is paused until its ' . number_format($stationMonthlyFee) . '-point monthly fee is paid.'
                    ]);
                    exit;
                }
                $stmt = $pdo->prepare(
                    "UPDATE branches b
                     INNER JOIN station_business_scope sbs ON sbs.station_id = b.branch_id
                     SET b.status = LOWER(?)
                     WHERE b.branch_id = ? AND sbs.headquarter_id = ?"
                );
                $stmt->execute([$new_status, $station_id, $hqId]);
                echo json_encode(['success' => true]);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
        }
        exit;
    }

    // Decommission Station
    if ($action === 'decommission') {
        $station_id = $_POST['station_id'] ?? '';

        try {
            $stmt = $pdo->prepare(
                "UPDATE branches b
                 INNER JOIN station_business_scope sbs ON sbs.station_id = b.branch_id
                 SET b.status = 'closed'
                 WHERE b.branch_id = ? AND sbs.headquarter_id = ?"
            );
            $stmt->execute([$station_id, $hqId]);
            $message = "Station successfully decommissioned and hidden.";
        } catch (PDOException $e) {
            $error = "Error decommissioning station: " . $e->getMessage();
        }
    }
}

// Fetch Stations from database with Manager Info
$stations = [];
$branchManagers = [];
try {
    $monthlyFeePaidSql = $billingSchemaReady
        ? "EXISTS (
                SELECT 1 FROM company_loyalty_station_fees fee
                WHERE fee.headquarter_id = sbs.headquarter_id
                  AND fee.station_id = b.branch_id
                  AND fee.`year_month` = ?
            )"
        : '0';
    $stmt = $pdo->prepare(
        "SELECT b.branch_id AS station_id, b.branch_name AS station_name, b.address AS station_address,
                b.manager_user_id AS business_user_id,
                CASE WHEN b.latitude IS NOT NULL AND b.longitude IS NOT NULL THEN CONCAT(b.latitude, ', ', b.longitude) ELSE '' END AS location,
                CASE b.status WHEN 'active' THEN 'Active' WHEN 'maintenance' THEN 'Maintenance' ELSE 'Decommissioned' END AS status,
                {$monthlyFeePaidSql} AS monthly_fee_paid,
                u.user_name AS manager_name, u.email AS manager_email
         FROM branches b
         INNER JOIN station_business_scope sbs ON sbs.station_id = b.branch_id
         LEFT JOIN users u ON u.user_id = b.manager_user_id
         WHERE sbs.headquarter_id = ?
           AND b.status <> 'closed'
         ORDER BY b.branch_id DESC"
    );
    $stmt->execute($billingSchemaReady ? [$billingMonth, $hqId] : [$hqId]);
    $stations = $stmt->fetchAll();
    if ($billingSchemaReady) {
        foreach ($stations as &$stationRow) {
            $stationRow['monthly_fee_paid'] = company_loyalty_station_is_paid($pdo, (int) $stationRow['station_id']) ? 1 : 0;
        }
        unset($stationRow);
    }
    $stationCount = count($stations);
    $limitStmt = $pdo->prepare("SELECT station_count FROM headquarter WHERE headquarter_id = ?");
    $limitStmt->execute([$hqId]);
    $limitValue = $limitStmt->fetchColumn();
    $baseStationLimit = $limitValue === false ? null : (int) $limitValue;
    if ($baseStationLimit !== null && $baseStationLimit < 1) {
        $baseStationLimit = null;
    }
    $monthlySlots = company_loyalty_current_month_slots($pdo, $hqId);
    $stationLimit = $baseStationLimit === null ? null : $baseStationLimit + $monthlySlots;
    $stationLimitReached = $stationLimit !== null && $stationCount >= $stationLimit;

    $managerStmt = $pdo->prepare(
        "SELECT u.user_id, u.email
         FROM users u
         LEFT JOIN business_account_scope bas ON bas.user_id = u.user_id
         WHERE COALESCE(u.business_role_id, u.role_id) = 4
           AND (
               COALESCE(bas.headquarter_id, u.headquarter_id) = ?
               OR EXISTS (
                   SELECT 1 FROM station_business_scope sbs
                   WHERE sbs.branch_manager_user_id = u.user_id
                     AND sbs.headquarter_id = ?
               )
           )
           AND LOWER(COALESCE(u.status, 'active')) NOT IN ('suspended', 'removed')
         ORDER BY u.email"
    );
    $managerStmt->execute([$hqId, $hqId]);
    $branchManagers = $managerStmt->fetchAll();
} catch (PDOException $e) {
    $error = "Could not retrieve stations: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <script src="../../js/hq-theme.js"></script>
    <link rel="stylesheet" href="../../css/HQstyle.css">
    <style>
        .table-custom { background-color: transparent !important; color: var(--slate-50); }
        .table-custom th { text-transform: uppercase; font-size: 0.74rem; letter-spacing: 0.05em; color: var(--slate-300); padding: 12px 16px; background: rgba(255, 255, 255, 0.05); border-bottom: 1px solid var(--border); }
        .table-custom td { background-color: transparent !important; color: var(--slate-50) !important; border-color: var(--border); vertical-align: middle; }
        .table-actions { white-space: nowrap; }
        .modal-content-custom { background-color: var(--card-bg) !important; border: 1px solid var(--border) !important; box-shadow: 0 1rem 3rem rgba(0, 0, 0, 0.5); }
        .modal-content-decommission { background-color: var(--card-bg) !important; border: 1px solid #842029 !important; box-shadow: 0 1rem 3rem rgba(220, 53, 69, 0.15); }
        .station-toolbar { flex-wrap: wrap; justify-content: flex-end; }
        .station-search {
            position: relative;
            flex: 0 0 auto;
            width: 260px;
            max-width: 100%;
        }

        .station-search .station-search-icon {
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--slate-300);
            font-size: 0.82rem;
            line-height: 1;
            opacity: 0.85;
            pointer-events: none;
        }

        .station-search #stationSearch {
            display: block;
            width: 100%;
            height: 36px;
            padding: 0 66px 0 34px;
            font-size: 0.85rem;
            color: var(--slate-50);
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: 999px;
            box-shadow: none;
            outline: none;
            appearance: none;
            -webkit-appearance: none;
            box-sizing: border-box;
        }

        .station-search #stationSearch::placeholder {
            color: var(--slate-300);
            opacity: 0.7;
        }

        .station-search #stationSearch:focus {
            background: rgba(255, 255, 255, 0.08);
            border-color: rgba(56, 189, 248, 0.45);
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.16);
        }

        .station-search #stationSearch::-webkit-search-cancel-button,
        .station-search #stationSearch::-webkit-search-decoration {
            -webkit-appearance: none;
            appearance: none;
        }

        .station-search .station-search-clear {
            position: absolute;
            right: 34px;
            top: 50%;
            transform: translateY(-50%);
            display: none;
            width: 22px;
            height: 22px;
            padding: 0;
            border: none;
            border-radius: 50%;
            background: transparent;
            color: var(--slate-300);
            font-size: 0.7rem;
            line-height: 1;
            cursor: pointer;
        }

        .station-search .station-search-clear:hover {
            color: var(--slate-50);
            background: rgba(255, 255, 255, 0.08);
        }

        .station-search.has-value .station-search-clear {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Kebab action menu (matches report manager view) */
        .station-actions-cell { position: relative; }
        .station-action-toggle {
            border: none;
            background: none;
            color: var(--slate-300);
            cursor: pointer;
            font-size: 1.3rem;
            box-shadow: none;
            padding: 5px 8px;
            border-radius: 6px;
            transition: background 0.15s ease, color 0.15s ease;
        }
        .station-action-toggle:hover {
            background: rgba(255, 255, 255, 0.08);
            color: var(--slate-50);
        }
        .station-action-menu {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            margin: 0;
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 8px;
            min-width: 190px;
            z-index: 1090;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.35);
        }
        .station-action-menu button {
            border: none;
            background: none;
            cursor: pointer;
            width: 100%;
            text-align: left;
            padding: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
            border-radius: 6px;
            font-size: 0.85rem;
            color: #0d6efd;
        }
        .station-action-menu button:hover {
            background: rgba(255, 255, 255, 0.06);
        }
        .station-action-menu button.danger {
            color: #dc3545;
        }
        .station-action-menu button i {
            font-size: 1rem;
        }
        .station-billing-form { margin: 0; }
        #stationMap, #configMap { height: 220px; border-radius: 8px; border: 1px solid #373b3e; }
    </style>
</head>
<body>
    <div class="container py-4">
        <h2 class="title">Station Network</h2>
        <p class="subtitle">Onboard physical station nodes and set up terminal operating profiles.</p>

        <?php if (!empty($message)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

<?php
$hqPointBalance = company_loyalty_get_balance($pdo, $hqId);
?>

        <div class="card p-4 bg-dark text-light border-secondary">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h3>Manage Deployed Stations</h3>
                    <p class="subtitle mb-0">Monitor stations, pay monthly operating fees, or manage registration capacity.</p>
                    <p class="text-info small mb-0 mt-2"><i class="fa-solid fa-star me-1" aria-hidden="true"></i>HQ points: <strong><?= number_format($hqPointBalance) ?></strong></p>
                    <p class="text-secondary small mb-0">Points option: <?= number_format($stationMonthlyFee) ?> points/month for station operation. MMK option: <?= number_format($extraStationMmkMonthly) ?> MMK/month per station beyond the included <?= (int) BILLING_HQ_INCLUDED_STATION_SLOTS ?>; this covers both slot and operation. <a href="/php/payment_center.php" class="link-info">View payment status</a></p>
                    <?php if (isset($_GET['payment']) && $_GET['payment'] === 'pending'): ?>
                    <div class="alert alert-info mt-3 mb-0">Your payment request was created. Its fixed amount and recipient status are available in <a href="/php/payment_center.php">Payment status</a>.</div>
                    <?php endif; ?>
                    <?php if ($stationLimit !== null): ?>
                    <p class="text-secondary small mb-0">Capacity this month: <?= (int) $stationCount ?> / <?= (int) $stationLimit ?> stations (<?= (int) $monthlySlots ?> paid extra slots).</p>
                    <?php else: ?>
                    <p class="text-secondary small mb-0">Station registration capacity is unlimited for this HQ.</p>
                    <?php endif; ?>
                </div>
                <div class="d-flex align-items-center gap-2 station-toolbar">
                    <div class="station-search" id="stationSearchWrap">
                        <i class="fa-solid fa-magnifying-glass station-search-icon" aria-hidden="true"></i>
                        <input type="search" id="stationSearch" placeholder="Search stations..."
                            autocomplete="off" aria-label="Search stations by ID, name, address, manager or status">
                        <button type="button" id="stationSearchClear" class="station-search-clear" title="Clear search" aria-label="Clear search">
                            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                        </button>
                    </div>
                    <?php if ($baseStationLimit !== null): ?>
                    <button type="button" class="btn btn-outline-success" onclick="window.top.routeTo('extend_period.php')">
                        <i class="fa-solid fa-money-bill-transfer me-1" aria-hidden="true"></i>Extend Service
                    </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#inviteManagerModal">+Add Manager</button>
                    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="<?= $stationLimitReached ? '#stationLimitModal' : '#registerModal' ?>">+Add Station</button>
                </div>
            </div>
            <div class="table-responsive">
                <table id="stationsTable" class="table table-custom align-middle">
                    <thead>
                        <tr>
                            <th>Station ID</th>
                            <th>Station Name</th>
                            <th>Address</th>
                            <th>Branch Manager</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="stationsTableBody">
                        <?php if (empty($stations)): ?>
                            <tr class="station-empty-row"><td colspan="6" class="text-center text-muted">No station nodes found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($stations as $row): ?>
                                <?php
                                    $isMaintenance = ($row['status'] === 'Maintenance');
                                    $feePaid = (int) ($row['monthly_fee_paid'] ?? 0) === 1;
                                    $isBillingPaused = !$feePaid;
                                    $badgeClass = $isBillingPaused ? 'badge bg-danger' : ($isMaintenance ? 'badge bg-warning text-dark' : 'badge bg-success');
                                    $statusText = $isBillingPaused ? 'Paused · monthly fee unpaid' : ($isMaintenance ? 'Maintenance' : 'Active');
                                    $btnClass = $isMaintenance ? 'btn btn-sm me-1 btn-outline-warning' : 'btn btn-sm me-1 btn-outline-info';
                                    $btnText = $isMaintenance ? 'Set Active' : 'Set Maintenance';
                                    $targetStatus = $isMaintenance ? 'Active' : 'Maintenance';
                                ?>
                                <tr class="station-row" data-search="<?= htmlspecialchars(strtolower(trim(
                                        '#' . $row['station_id'] . ' ' .
                                        ($row['station_name'] ?? '') . ' ' .
                                        ($row['station_address'] ?? '') . ' ' .
                                        ($row['manager_name'] ?? 'Not assigned') . ' ' .
                                        ($row['manager_email'] ?? '') . ' ' .
                                        $statusText
                                    )), ENT_QUOTES) ?>">
                                    <td>#<?= htmlspecialchars($row['station_id']) ?></td>
                                    <td><?= htmlspecialchars($row['station_name']) ?></td>
                                    <td><?= htmlspecialchars($row['station_address']) ?></td>
                                    <td><?= htmlspecialchars($row['manager_name'] ?? 'Not assigned') ?></td>
                                    <td>
                                        <span id="status-badge-<?= $row['station_id'] ?>" class="<?= $badgeClass ?>">
                                            <?= $statusText ?>
                                        </span>
                                    </td>
                                    <td class="text-end table-actions station-actions-cell" onclick="event.stopPropagation();">
                                        <button type="button" class="station-action-toggle" onclick="toggleStationActions(this, <?= $row['station_id'] ?>)" title="More Actions" aria-label="More actions">
                                            <i class="bi bi-three-dots-vertical"></i>
                                        </button>
                                        <div class="station-action-menu" id="station-menu-<?= $row['station_id'] ?>">
                                            <button type="button" id="toggle-btn-<?= $row['station_id'] ?>" onclick="closeStationMenus(); toggleStatus(<?= $row['station_id'] ?>, '<?= $targetStatus ?>')">
                                                <i class="bi <?= $isMaintenance ? 'bi-toggle2-on' : 'bi-tools' ?>"></i> <?= $btnText ?>
                                            </button>
                                            <?php if (!$feePaid): ?>
                                            <form method="POST" class="station-billing-form" onsubmit="return confirm('Pay <?= $stationMonthlyFee ?> HQ points for this station this month?')">
                                                <input type="hidden" name="action" value="pay_station_fee">
                                                <input type="hidden" name="station_id" value="<?= (int) $row['station_id'] ?>">
                                                <input type="hidden" name="billing_csrf" value="<?= htmlspecialchars($stationBillingCsrf, ENT_QUOTES, 'UTF-8') ?>">
                                                <button type="submit" title="Pay the monthly station fee">
                                                    <i class="bi bi-credit-card"></i> Pay monthly fee · <?= number_format($stationMonthlyFee) ?> pts
                                                </button>
                                            </form>
                                            <?php else: ?>
                                            <span class="small text-success px-3 py-2 d-block">Monthly fee paid</span>
                                            <?php endif; ?>
                                            <button type="button" class="configure-station-btn" data-bs-toggle="modal" data-bs-target="#configureModal"
                                                data-station-id="<?= htmlspecialchars((string) $row['station_id'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-station-name="<?= htmlspecialchars((string) $row['station_name'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-station-address="<?= htmlspecialchars((string) ($row['station_address'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                data-manager-email="<?= htmlspecialchars((string) ($row['manager_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                data-location="<?= htmlspecialchars((string) $row['location'], ENT_QUOTES, 'UTF-8') ?>">
                                                <i class="bi bi-gear"></i> Configure
                                            </button>
                                            <button type="button" class="danger" data-bs-toggle="modal" data-bs-target="#decommissionModal"
                                                onclick="closeStationMenus(); setDecommissionData('<?= htmlspecialchars($row['station_id']) ?>', 'Station #<?= htmlspecialchars($row['station_id']) ?> - <?= htmlspecialchars($row['station_name']) ?>')">
                                                <i class="bi bi-trash"></i> Decommission
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <tr id="stationsNoResults" class="d-none">
                            <td colspan="6" class="text-center text-muted">No stations match your search.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Register Modal -->
    <div class="modal fade" id="registerModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content modal-content-custom text-light">
                <form method="POST">
                    <input type="hidden" name="action" value="register">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title">Register Station Node</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-12 mb-3">
                                <label class="form-label text-light">Station Name</label>
                                <input type="text" name="station_name" class="form-control bg-dark text-light border-secondary" placeholder="e.g. Downtown Metro" required>
                            </div>
                            <div class="col-12 mb-3">
                                <label class="form-label text-light">Station Address</label>
                                <input type="text" name="station_address" class="form-control bg-dark text-light border-secondary" placeholder="e.g. No. 45, Main Road, Township">
                            </div>
                            <div class="col-12 mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label class="form-label text-light mb-0">Branch Manager Email (Optional)</label>
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#inviteManagerModal" data-reopen-source="registerModal">
                                        <i class="bi bi-person-plus"></i> Add Manager
                                    </button>
                                </div>
                                <select name="manager_email" class="form-select bg-dark text-light border-secondary">
                                    <option value="">No manager assigned</option>
                                    <?php foreach ($branchManagers as $manager): ?>
                                        <option value="<?= htmlspecialchars($manager['email'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($manager['email']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label class="form-label text-light mb-0">Location (Coordinates)</label>
                                    <button type="button" class="btn btn-sm btn-outline-info" id="trackLocBtn">
                                        <i class="bi bi-geo-alt-fill"></i> Track My Location
                                    </button>
                                </div>
                                <input type="text" id="stationLocation" name="location" class="form-control bg-dark text-light border-secondary" placeholder="Click the map or track location" required readonly>
                            </div>
                            <div class="col-12">
                                <div id="stationMap" aria-label="Choose station location on map"></div>
                                <small class="text-secondary">Click the map or use the button above to select coordinates.</small>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-secondary">
                        <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-success">Register Station</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="inviteManagerModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content modal-content-custom text-light">
                <form method="POST" action="./invite_account.php">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title">Add Manager</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <label class="form-label text-light">Customer Email</label>
                        <input type="email" name="email" class="form-control bg-dark text-light border-secondary mb-3" placeholder="customer@example.com" required>
                        <label class="form-label text-light">Role</label>
                        <select name="role" class="form-select bg-dark text-light border-secondary" required>
                            <option value="manager">Branch Manager</option>
                        </select>
                        <?php if (empty($stations)): ?>
                            <div class="alert alert-warning mt-3 mb-0" role="alert">Create a station first so the manager can be assigned to it automatically.</div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer border-secondary">
                        <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Send Invitation</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <!-- Station Limit Modal -->
    <div class="modal fade" id="stationLimitModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content modal-content-custom text-light border-warning">
                <div class="modal-header border-warning">
                    <h5 class="modal-title text-warning"><i class="bi bi-exclamation-triangle-fill me-2"></i>Station limit reached</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">Your HQ has reached its configured limit of <?= (int) $stationLimit ?> stations.</p>
                    <p class="text-secondary">Extra station capacity is available through the MMK option at <?= number_format($extraStationMmkMonthly) ?> per extra station per month, covering both capacity and station operation.</p>
                    <p class="text-secondary small mt-3 mb-0">A recipient-recorded MMK receipt adds monthly capacity and covers monthly operation for the same number of extra stations.</p>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Configure Modal -->
    <div class="modal fade" id="configureModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content modal-content-custom text-light">
                <form method="POST">
                    <input type="hidden" name="action" value="configure">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title">Configure Station Node</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-light">Station ID</label>
                                <input type="text" id="configStationId" name="station_id" class="form-control bg-dark text-light border-secondary" readonly>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-light">Station Name</label>
                                <input type="text" id="configStationName" name="station_name" class="form-control bg-dark text-light border-secondary" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-light">Branch Manager Email</label>
                                <select id="configManagerEmail" name="manager_email" class="form-select bg-dark text-light border-secondary" required>
                                    <option value="">Choose a branch manager</option>
                                    <?php foreach ($branchManagers as $manager): ?>
                                        <option value="<?= htmlspecialchars($manager['email'], ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars($manager['email']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-light">Station Address</label>
                                <input type="text" id="configStationAddress" name="station_address" class="form-control bg-dark text-light border-secondary" placeholder="e.g. No. 45, Main Road">
                            </div>
                            <div class="col-12 mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label class="form-label text-light mb-0">Location (Coordinates)</label>
                                    <button type="button" class="btn btn-sm btn-outline-info" id="configTrackLocBtn">
                                        <i class="bi bi-geo-alt-fill"></i> Track My Location
                                    </button>
                                </div>
                                <input type="text" id="configLocation" name="location" class="form-control bg-dark text-light border-secondary" required readonly>
                            </div>
                            <div class="col-12">
                                <div id="configMap" aria-label="Choose station location on map"></div>
                                <small class="text-secondary">Click the map or use the button above to update station coordinates.</small>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-secondary">
                        <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Decommission Modal -->
    <div class="modal fade" id="decommissionModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content modal-content-decommission text-light">
                <form method="POST">
                    <input type="hidden" name="action" value="decommission">
                    <input type="hidden" id="decommissionStationId" name="station_id">
                    <div class="modal-header border-danger">
                        <h5 class="modal-title text-danger">Decommission Station Node</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to decommission <strong id="decommissionTarget" class="text-warning"></strong>?</p>
                        <p class="small text-muted mb-0">This action will hide the station from the active table without deleting its historical records from the system.</p>
                    </div>
                    <div class="modal-footer border-danger">
                        <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Decommission</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        let stationMap;
        let stationMarker;
        let configMap;
        let configMarker;
        const defaultMapCenter = [21.9588, 96.0891];

        function parseMapCoordinates(value) {
            const parts = (value || '').split(',');
            if (parts.length !== 2) {
                return null;
            }

            const latitude = Number.parseFloat(parts[0]);
            const longitude = Number.parseFloat(parts[1]);
            if (!Number.isFinite(latitude) || !Number.isFinite(longitude)
                || latitude < -90 || latitude > 90 || longitude < -180 || longitude > 180) {
                return null;
            }

            return L.latLng(latitude, longitude);
        }

        function setMapLocation(map, marker, locationInput, latLng) {
            if (marker) {
                marker.setLatLng(latLng);
            } else {
                marker = L.marker(latLng).addTo(map);
            }
            locationInput.value = latLng.lat.toFixed(6) + ', ' + latLng.lng.toFixed(6);
            return marker;
        }

        function initializeStationMap() {
            const locationInput = document.getElementById('stationLocation');
            const initialLocation = parseMapCoordinates(locationInput.value);
            if (!stationMap) {
                stationMap = L.map('stationMap').setView(initialLocation || defaultMapCenter, initialLocation ? 14 : 6);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                }).addTo(stationMap);
                stationMap.on('click', event => {
                    stationMarker = setMapLocation(stationMap, stationMarker, locationInput, event.latlng);
                });
            } else if (initialLocation) {
                stationMap.setView(initialLocation, 14);
            }

            if (initialLocation) {
                stationMarker = setMapLocation(stationMap, stationMarker, locationInput, initialLocation);
            }
            window.setTimeout(() => stationMap.invalidateSize(), 100);
        }

        function initializeConfigMap() {
            const locationInput = document.getElementById('configLocation');
            const initialLocation = parseMapCoordinates(locationInput.value);
            if (!configMap) {
                configMap = L.map('configMap').setView(initialLocation || defaultMapCenter, initialLocation ? 14 : 6);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                }).addTo(configMap);
                configMap.on('click', event => {
                    configMarker = setMapLocation(configMap, configMarker, locationInput, event.latlng);
                });
            } else {
                configMap.setView(initialLocation || defaultMapCenter, initialLocation ? 14 : 6);
            }

            if (configMarker) {
                configMap.removeLayer(configMarker);
                configMarker = null;
            }
            if (initialLocation) {
                configMarker = setMapLocation(configMap, configMarker, locationInput, initialLocation);
            }
            window.setTimeout(() => configMap.invalidateSize(), 100);
        }

        function trackCurrentLocation(map, locationInput, updateMarker) {
            if (!navigator.geolocation) {
                alert('Location services are not available in this browser.');
                return;
            }

            navigator.geolocation.getCurrentPosition(position => {
                const location = L.latLng(position.coords.latitude, position.coords.longitude);
                updateMarker(location);
                map.setView(location, 14);
            }, () => {
                alert('Could not get your location. Check browser location permissions and try again.');
            }, { enableHighAccuracy: true, timeout: 10000 });
        }

        document.querySelectorAll('[data-reopen-source]').forEach(button => {
            button.addEventListener('click', function () {
                const targetId = this.getAttribute('data-bs-target');
                const targetModal = targetId ? document.querySelector(targetId) : null;
                const sourceId = this.getAttribute('data-reopen-source');
                if (targetModal && sourceId) {
                    targetModal.setAttribute('data-reopen-source', sourceId);
                }
            });
        });

        document.querySelectorAll('.modal').forEach(modalEl => {
            modalEl.addEventListener('hidden.bs.modal', function () {
                const reopenSource = this.getAttribute('data-reopen-source');
                if (!reopenSource) {
                    return;
                }

                const sourceModal = document.getElementById(reopenSource);
                if (sourceModal) {
                    window.setTimeout(() => {
                        const modalInstance = bootstrap.Modal.getOrCreateInstance(sourceModal);
                        modalInstance.show();
                    }, 120);
                }

                this.removeAttribute('data-reopen-source');
            });
        });

        document.getElementById('registerModal').addEventListener('shown.bs.modal', initializeStationMap);
        document.getElementById('configureModal').addEventListener('shown.bs.modal', initializeConfigMap);
        document.querySelectorAll('.configure-station-btn').forEach(button => {
            button.addEventListener('click', function () {
                closeStationMenus();
                setConfigureData(
                    this.dataset.stationId,
                    this.dataset.stationName,
                    this.dataset.stationAddress,
                    this.dataset.managerEmail,
                    this.dataset.location
                );
            });
        });
        document.getElementById('trackLocBtn').addEventListener('click', () => {
            trackCurrentLocation(stationMap, document.getElementById('stationLocation'), location => {
                stationMarker = setMapLocation(stationMap, stationMarker, document.getElementById('stationLocation'), location);
            });
        });
        document.getElementById('configTrackLocBtn').addEventListener('click', () => {
            trackCurrentLocation(configMap, document.getElementById('configLocation'), location => {
                configMarker = setMapLocation(configMap, configMarker, document.getElementById('configLocation'), location);
            });
        });

        document.addEventListener('DOMContentLoaded', () => {
            const searchWrap = document.getElementById('stationSearchWrap');
            const searchInput = document.getElementById('stationSearch');
            const clearBtn = document.getElementById('stationSearchClear');
            const rows = document.querySelectorAll('.station-row');
            const noResultsRow = document.getElementById('stationsNoResults');

            <?php if ($showStationLimitModal): ?>
            new bootstrap.Modal(document.getElementById('stationLimitModal')).show();
            <?php endif; ?>

            function filterStations() {
                const query = searchInput.value.toLowerCase().trim();
                let visibleCount = 0;

                if (query.length > 0) {
                    searchWrap.classList.add('has-value');
                } else {
                    searchWrap.classList.remove('has-value');
                }

                rows.forEach(row => {
                    const searchData = row.getAttribute('data-search') || '';
                    if (searchData.includes(query)) {
                        row.classList.remove('d-none');
                        visibleCount++;
                    } else {
                        row.classList.add('d-none');
                    }
                });

                if (noResultsRow) {
                    if (visibleCount === 0 && rows.length > 0) {
                        noResultsRow.classList.remove('d-none');
                    } else {
                        noResultsRow.classList.add('d-none');
                    }
                }
            }

            searchInput.addEventListener('input', filterStations);

            clearBtn.addEventListener('click', () => {
                searchInput.value = '';
                filterStations();
                searchInput.focus();
            });
        });

        let openStationMenu = null;
        let stationMenuOutsideHandler = null;

        function toggleStationActions(button, stationId) {
            const menu = document.getElementById('station-menu-' + stationId);
            if (openStationMenu === menu) {
                closeStationMenus();
                return;
            }

            closeStationMenus();
            menu._stationActionsCell = menu.parentElement;
            menu._stationActionsToggle = button;
            document.body.appendChild(menu);
            menu.style.display = 'block';

            const buttonRect = button.getBoundingClientRect();
            const menuRect = menu.getBoundingClientRect();
            const left = Math.max(8, Math.min(
                buttonRect.right - menuRect.width,
                window.innerWidth - menuRect.width - 8
            ));
            const top = buttonRect.bottom + menuRect.height <= window.innerHeight - 8
                ? buttonRect.bottom + 4
                : Math.max(8, buttonRect.top - menuRect.height - 4);
            menu.style.left = `${left}px`;
            menu.style.top = `${top}px`;
            openStationMenu = menu;

            stationMenuOutsideHandler = event => {
                if (!menu.contains(event.target) && event.target !== button) {
                    closeStationMenus();
                }
            };
            document.addEventListener('click', stationMenuOutsideHandler);
            document.addEventListener('scroll', closeStationMenus, true);
            window.addEventListener('resize', closeStationMenus);
        }

        function closeStationMenus() {
            document.querySelectorAll('.station-action-menu').forEach(m => {
                m.style.display = 'none';
                if (m._stationActionsCell && m._stationActionsToggle) {
                    m._stationActionsCell.insertBefore(m, m._stationActionsToggle.nextSibling);
                    delete m._stationActionsCell;
                    delete m._stationActionsToggle;
                }
                m.style.left = '';
                m.style.top = '';
            });
            if (stationMenuOutsideHandler) {
                document.removeEventListener('click', stationMenuOutsideHandler);
                stationMenuOutsideHandler = null;
            }
            document.removeEventListener('scroll', closeStationMenus, true);
            window.removeEventListener('resize', closeStationMenus);
            openStationMenu = null;
        }

        function toggleStatus(stationId, targetStatus) {
            const formData = new FormData();
            formData.append('action', 'toggle_status');
            formData.append('station_id', stationId);
            formData.append('status', targetStatus);

            fetch('station.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert(data.message || 'Failed to update station status.');
                    }
                })
                .catch(() => alert('Network error. Failed to execute action.'));
        }

        function setConfigureData(id, name, address, managerEmail, locationStr) {
            document.getElementById('configStationId').value = id;
            document.getElementById('configStationName').value = name;
            document.getElementById('configStationAddress').value = address;
            document.getElementById('configManagerEmail').value = managerEmail;
            document.getElementById('configLocation').value = locationStr;
        }

        function setDecommissionData(id, targetName) {
            document.getElementById('decommissionStationId').value = id;
            document.getElementById('decommissionTarget').innerText = targetName;
        }
    </script>
</body>
</html>
