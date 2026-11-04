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
if (isset($_SESSION['payment_transfer_flash']) && is_array($_SESSION['payment_transfer_flash'])) {
    $paymentFlash = $_SESSION['payment_transfer_flash'];
    unset($_SESSION['payment_transfer_flash']);
    if (($paymentFlash['type'] ?? '') !== 'success') {
        $error = (string) ($paymentFlash['message'] ?? '');
    } elseif (!in_array($_GET['payment'] ?? '', ['pending', 'station_limit_pending'], true)) {
        $message = (string) ($paymentFlash['message'] ?? '');
    }
}
$hqId = map_pump_current_hq_id($pdo);
$hqDetailsStmt = $pdo->prepare('SELECT company_name, headquarter_email FROM headquarter WHERE headquarter_id = ? LIMIT 1');
$hqDetailsStmt->execute([$hqId]);
$hqDetails = $hqDetailsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$billingSchemaReady = loyalty_countermeasures_schema_ready($pdo);
$paymentTransferSchemaReady = payment_transfer_schema_ready($pdo);
$billingMonth = loyalty_current_year_month();
$packageRate = billing_admin_setting($pdo, 'hq_package_mmk_per_station_month', BILLING_HQ_PACKAGE_MMK_PER_STATION_MONTHLY);
$stationLimit = null;
$baseStationLimit = null;
$monthlySlots = 0;
$stationCount = 0;
$stationLimitReached = false;
$showStationLimitModal = false;
$stationLimitPaymentAccounts = billing_payment_receiving_accounts();
$stationLimitTermOptions = [];
foreach (BILLING_HQ_STATION_LIMIT_TERMS as $termMonths) {
    $stationLimitTermOptions[$termMonths] = $termMonths < 12
        ? $termMonths . ' month' . ($termMonths === 1 ? '' : 's')
        : (int) ($termMonths / 12) . ' year' . ($termMonths === 12 ? '' : 's');
}
if (empty($_SESSION['payment_transfer_csrf'])) {
    $_SESSION['payment_transfer_csrf'] = bin2hex(random_bytes(32));
}

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

    if ($action === 'pay_station_fee') {
        $error = 'Station fees, capacity, and service extensions are paid together through the MMK service package.';
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
                    throw new RuntimeException('Station billing is temporarily unavailable. Please try again later.');
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
                    $monthlySlots = hq_billing_current_month_slots($pdo, $hqId);
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
                if ($manager_email === '') {
                    throw new RuntimeException('A branch manager is required to create a station.');
                }

                $managerStmt = $pdo->prepare(
                    "SELECT u.user_id
                     FROM users u
                     LEFT JOIN business_account_scope bas ON bas.user_id = u.user_id
                     WHERE u.email = ?
                       AND COALESCE(u.business_role_id, u.role_id) = 4
                       AND (
                           bas.headquarter_id = ?
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

                $assignedManagerStmt = $pdo->prepare(
                    "SELECT 1
                     FROM branches b
                     LEFT JOIN station_business_scope sbs ON sbs.station_id = b.branch_id
                     WHERE b.status <> 'closed'
                       AND (b.manager_user_id = ? OR sbs.branch_manager_user_id = ?)
                     LIMIT 1"
                );
                $assignedManagerStmt->execute([$manager_id, $manager_id]);
                if ($assignedManagerStmt->fetchColumn()) {
                    throw new RuntimeException('This branch manager is already assigned to another station.');
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

                $scopeStmt = $pdo->prepare(
                    "INSERT INTO station_business_scope (station_id, headquarter_id, branch_manager_user_id)
                     VALUES (?, ?, ?)"
                );
                $scopeStmt->execute([$stationId, $hqId, $manager_id ?: null]);
                if ($paymentTransferSchemaReady) {
                    payment_transfer_assign_package_fees($pdo, $hqId, $stationId);
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

                    $userScopeStmt = $pdo->prepare("UPDATE users SET business_role_id = 4 WHERE user_id = ?");
                    $userScopeStmt->execute([$manager_id]);
                }

                $tankSeedStmt = $pdo->prepare(
                    "INSERT INTO tanks
                        (branch_id, fuel_code, fuel_name, oil_type_id, capacity_l, current_l, alert_pct, is_enabled)
                     VALUES (?, ?, ?, ?, ?, 0, 25, 1)"
                );
                $oilTypeStmt = $pdo->prepare('SELECT oil_type_id FROM oil_types WHERE oil_name = ? LIMIT 1');
                $tankSeeds = [
                    ['p92', 'Octane 92', 15000],
                    ['p95', 'Octane 95', 10000],
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
                    $error = 'Station fees, capacity, and service extensions are paid together through the MMK service package.';
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
                       bas.headquarter_id = ?
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
            $userScopeStmt = $pdo->prepare("UPDATE users SET business_role_id = 4 WHERE user_id = ?");
            $userScopeStmt->execute([$manager_id]);
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
                if ($new_status === 'Active' && !hq_billing_station_is_paid($pdo, (int) $station_id)) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'This station is paused until the MMK service package is paid for the current month.'
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
$availableBranchManagers = [];
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
            $stationRow['monthly_fee_paid'] = hq_billing_station_is_paid($pdo, (int) $stationRow['station_id']) ? 1 : 0;
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
    $monthlySlots = hq_billing_current_month_slots($pdo, $hqId);
    $stationLimit = $baseStationLimit === null ? null : $baseStationLimit + $monthlySlots;
    $paidExtraSlots = $stationLimit === null
        ? $monthlySlots
        : max(0, $stationLimit - BILLING_HQ_INCLUDED_STATION_SLOTS);
    $stationLimitReached = $stationLimit !== null && $stationCount >= $stationLimit;

    $managerStmt = $pdo->prepare(
        "SELECT u.user_id, u.email
         FROM users u
         LEFT JOIN business_account_scope bas ON bas.user_id = u.user_id
         WHERE COALESCE(u.business_role_id, u.role_id) = 4
           AND (
               bas.headquarter_id = ?
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

    $availableManagerStmt = $pdo->prepare(
        "SELECT u.user_id, u.email
         FROM users u
         LEFT JOIN business_account_scope bas ON bas.user_id = u.user_id
         WHERE COALESCE(u.business_role_id, u.role_id) = 4
           AND (
               bas.headquarter_id = ?
               OR EXISTS (
                   SELECT 1 FROM station_business_scope sbs
                   WHERE sbs.branch_manager_user_id = u.user_id
                     AND sbs.headquarter_id = ?
               )
           )
           AND LOWER(COALESCE(u.status, 'active')) NOT IN ('suspended', 'removed')
           AND NOT EXISTS (
               SELECT 1
               FROM branches b
               LEFT JOIN station_business_scope sbs ON sbs.station_id = b.branch_id
               WHERE b.status <> 'closed'
                 AND (b.manager_user_id = u.user_id OR sbs.branch_manager_user_id = u.user_id)
           )
         ORDER BY u.email"
    );
    $availableManagerStmt->execute([$hqId, $hqId]);
    $availableBranchManagers = $availableManagerStmt->fetchAll();
} catch (PDOException $e) {
    $error = "Could not retrieve stations: " . $e->getMessage();
}
$latestPaymentRejection = null;
$latestPaymentSuccess = false;
$latestPaymentPending = false;
if ($paymentTransferSchemaReady) {
    $latestPaymentStmt = $pdo->prepare(
        "SELECT payment_transfer_id, reference_code, status, decision_note
         FROM payment_transfers
         WHERE payer_headquarter_id = ?
          AND (
              payment_type = 'hq_extra_station'
              OR (
                  payment_type = 'hq_station_package'
                  AND description LIKE '%additional capacity slot(s)%'
              )
          )
        ORDER BY payment_transfer_id DESC
        LIMIT 1"
    );
    $latestPaymentStmt->execute([$hqId]);
    $latestPayment = $latestPaymentStmt->fetch(PDO::FETCH_ASSOC);
    $latestPaymentId = (int) ($latestPayment['payment_transfer_id'] ?? 0);
    if (($latestPayment['status'] ?? '') === 'payer_reported_paid') {
        $latestPaymentPending = true;
    }
    if ($latestPaymentId > 0
        && (int) ($_SESSION['hq_station_limit_final_payment_notice_seen'] ?? 0) !== $latestPaymentId) {
        if (($latestPayment['status'] ?? '') === 'rejected') {
            $latestPaymentRejection = $latestPayment;
            $_SESSION['hq_station_limit_final_payment_notice_seen'] = $latestPaymentId;
        } elseif (($latestPayment['status'] ?? '') === 'received') {
            $latestPaymentSuccess = true;
            $_SESSION['hq_station_limit_final_payment_notice_seen'] = $latestPaymentId;
        }
    }
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
        .station-limit-receipt-dialog { width: min(720px, calc(100% - 32px)); max-width: none; max-height: calc(100% - 32px); padding: 0; border: 1px solid var(--border); border-radius: 14px; color: var(--slate-50); background: var(--card-bg); box-shadow: 0 24px 80px rgba(0,0,0,.45); }
        .station-limit-receipt-dialog::backdrop { background: rgba(5,10,20,.72); backdrop-filter: blur(3px); }
        .station-limit-receipt-header, .station-limit-receipt-footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 18px 22px; }
        .station-limit-receipt-header { border-bottom: 1px solid var(--border); }
        .station-limit-receipt-header h2 { margin: 0; font-size: 1.15rem; }
        .station-limit-receipt-close { padding: 0 4px; border: 0; color: var(--slate-300); background: transparent; font-size: 1.6rem; line-height: 1; }
        .station-limit-receipt-body { display: grid; gap: 14px; overflow: auto; padding: 20px 22px; }
        .station-limit-receipt-card { padding: 22px; border: 1px solid var(--border); border-radius: 12px; background: rgba(255,255,255,.03); }
        .station-limit-receipt-card h3 { margin: 0 0 16px; font-size: 1rem; }
        .station-limit-receipt-company { margin: 0 0 18px; color: var(--slate-300); font-size: .85rem; line-height: 1.6; }
        .station-limit-receipt-company strong { color: var(--slate-50); font-size: .95rem; }
        .station-limit-receipt-method, .station-limit-receipt-note, .station-limit-receipt-disclaimer { color: var(--slate-300); font-size: .82rem; }
        .station-limit-receipt-method { margin: 0 0 6px; }
        .station-limit-receipt-account { margin: 8px 0; font-size: 1.05rem; overflow-wrap: anywhere; }
        .station-limit-receipt-note { margin: 0 0 14px; }
        .station-limit-receipt-total { display: flex; justify-content: space-between; gap: 16px; padding: 10px 0; border-top: 1px solid var(--border); font-variant-numeric: tabular-nums; }
        .station-limit-receipt-total span { color: var(--slate-300); }
        .station-limit-receipt-total strong { text-align: right; overflow-wrap: anywhere; }
        .station-limit-receipt-disclaimer { margin: 0; }
        .station-limit-receipt-footer { justify-content: flex-end; flex-wrap: nowrap; border-top: 1px solid var(--border); }
        .station-limit-receipt-footer .btn { flex: 1 1 0; min-width: 0; padding: .55rem .3rem; font-size: clamp(.62rem, 1.5vw, .82rem); white-space: nowrap; }
        @media(max-width:480px) { .station-limit-receipt-header, .station-limit-receipt-body, .station-limit-receipt-footer { padding-left: 16px; padding-right: 16px; } .station-limit-receipt-footer { gap: 6px; } }
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

        <?php if ($latestPaymentRejection !== null): ?>
            <div id="final-payment-status-alert" class="alert alert-danger" role="alert">
                Your HQ payment request was rejected.
                Reason: <?= htmlspecialchars((string) ($latestPaymentRejection['decision_note'] ?: 'No reason was provided by the reviewer.'), ENT_QUOTES, 'UTF-8') ?>
                <a href="../payment_center.php" target="_top" class="alert-link">View payment status</a>.
            </div>
        <?php elseif ($latestPaymentSuccess): ?>
            <div id="final-payment-status-alert" class="alert alert-success" role="status">
                Your HQ payment was approved and the service package has been applied.
                <a href="../payment_center.php" target="_top" class="alert-link">View payment status</a>.
            </div>
        <?php elseif ($latestPaymentPending): ?>
            <div class="alert alert-warning" role="status">
                Your payment has been submitted and is waiting for Admin or Moderator approval.
                <a href="../payment_center.php" target="_top" class="alert-link">View payment status</a>.
            </div>
        <?php endif; ?>
        <?php if ($latestPaymentRejection !== null || $latestPaymentSuccess): ?>
            <script>
                window.setTimeout(() => {
                    document.getElementById('final-payment-status-alert')?.remove();
                }, 10000);
            </script>
        <?php endif; ?>

        <div class="card p-4 bg-dark text-light border-secondary">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h3>Manage Deployed Stations</h3>
                    <p class="subtitle mb-0">Monitor stations and manage the combined monthly MMK service package.</p>
                    <p class="text-secondary small mb-0"><?= number_format($packageRate) ?> MMK per station/month for the service extension, station operation, and capacity package. <a href="../payment_center.php" target="_top" class="link-info">View payment status</a></p>
                    <?php if (isset($_GET['payment']) && $_GET['payment'] === 'pending'): ?>
                    <div class="alert alert-info mt-3 mb-0">Your payment details and screenshot were submitted for Admin or Moderator review. <a href="../payment_center.php" target="_top" class="alert-link">View payment status</a>.</div>
                    <?php endif; ?>
                    <?php if ($stationLimit !== null): ?>
                    <p class="text-secondary small mb-0">
                        Station capacity: <?= BILLING_HQ_INCLUDED_STATION_SLOTS ?> included + <?= (int) $paidExtraSlots ?> paid extra = <?= (int) $stationLimit ?> stations
                        (<?= (int) $stationCount ?> active this month).
                    </p>
                    <?php else: ?>
                    <p class="text-secondary small mb-0">
                        <?= BILLING_HQ_INCLUDED_STATION_SLOTS ?> stations included + <?= (int) $paidExtraSlots ?> paid extra slots this month; registration capacity is unlimited for this HQ.
                    </p>
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
                    <button type="button" class="btn btn-outline-info" data-bs-toggle="modal" data-bs-target="#extendStationLimitModal">
                        <i class="fa-solid fa-up-right-and-down-left-from-center me-1" aria-hidden="true"></i>Extend Station Limit
                    </button>
                    <?php endif; ?>
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
                                            <span class="small <?= $feePaid ? 'text-success' : 'text-warning' ?> px-3 py-2 d-block">
                                                <?= $feePaid ? 'Monthly package paid' : 'MMK service package required' ?>
                                            </span>
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
                                <label class="form-label text-light">Branch Manager Email <span class="text-danger">*</span></label>
                                <select name="manager_email" class="form-select bg-dark text-light border-secondary" required>
                                    <option value="" selected disabled>Select a branch manager</option>
                                    <?php foreach ($availableBranchManagers as $manager): ?>
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
                    <p class="text-secondary">The combined MMK service package is <?= number_format($packageRate) ?> per station per month and includes service extension, operation, and capacity.</p>
                    <p class="text-secondary small mt-3 mb-0">A recipient-recorded MMK receipt adds monthly capacity and covers monthly operation for the same number of extra stations.</p>
                </div>
                <div class="modal-footer border-secondary">
                    <?php if ($baseStationLimit !== null): ?>
                    <button type="button" class="btn btn-info" id="openStationLimitExtension">Extend Station Limit</button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="extendStationLimitModal" tabindex="-1" aria-labelledby="extendStationLimitTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content modal-content-custom text-light">
                <form method="POST" action="../Payment/action.php" enctype="multipart/form-data"
                    id="extendStationLimitForm"
                    data-base-capacity="<?= (int) ($baseStationLimit ?? 0) + (int) $monthlySlots ?>"
                    data-current-stations="<?= (int) $stationCount ?>"
                    data-package-rate="<?= (int) $packageRate ?>"
                    data-term-discounts="<?= htmlspecialchars(json_encode(BILLING_HQ_STATION_LIMIT_TERM_DISCOUNTS), ENT_QUOTES, 'UTF-8') ?>"
                    data-accounts="<?= htmlspecialchars(json_encode($stationLimitPaymentAccounts), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="create_extra_station">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['payment_transfer_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                    <div class="modal-header border-secondary">
                        <div>
                            <h5 class="modal-title" id="extendStationLimitTitle">Extend Station Limit</h5>
                            <p class="text-secondary small mb-0">Add station capacity for a selected service term.</p>
                        </div>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-lg-7">
                                <div class="border border-secondary rounded p-3 h-100">
                                    <h6 class="mb-3">Station capacity details</h6>
                                    <p class="text-secondary small">This all-in-one package extends HQ service and covers operating fees for all package stations during the selected term.</p>
                                    <div class="mb-3">
                                        <label for="extraStationQuantity" class="form-label">Additional station slots</label>
                                        <input type="number" class="form-control bg-dark text-light border-secondary"
                                            id="extraStationQuantity" name="quantity" min="1" max="60" step="1" value="1" required>
                                        <small class="text-secondary">Current capacity: <?= (int) $baseStationLimit + (int) $monthlySlots ?> stations. Up to 60 additional slots per request.</small>
                                    </div>
                                    <div class="mb-3">
                                        <label for="stationLimitTerm" class="form-label">Service term</label>
                                        <select class="form-select bg-dark text-light border-secondary" id="stationLimitTerm" name="term_months" required>
                                            <?php foreach ($stationLimitTermOptions as $termMonths => $termLabel): ?>
                                            <option value="<?= (int) $termMonths ?>"><?= htmlspecialchars($termLabel, ENT_QUOTES, 'UTF-8') ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label for="stationLimitPaymentMethod" class="form-label">Payment method</label>
                                        <select class="form-select bg-dark text-light border-secondary" id="stationLimitPaymentMethod" name="payment_method" required>
                                            <option value="">Choose a method</option>
                                            <?php foreach ($stationLimitPaymentAccounts as $method => $account): ?>
                                            <option value="<?= htmlspecialchars($method, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($method, ENT_QUOTES, 'UTF-8') ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="mt-3">
                                        <label for="stationLimitReceipt" class="form-label">Payment screenshot (JPG, PNG, or WEBP; max 5 MB)</label>
                                        <input class="form-control bg-dark text-light border-secondary" type="file" id="stationLimitReceipt" name="receipt" accept="image/jpeg,image/png,image/webp" required>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-5">
                                <div class="border border-secondary rounded p-3 h-100">
                                    <h6 class="mb-3">Transfer and package total</h6>
                                    <p class="text-secondary small mb-1">Transfer account</p>
                                    <div class="d-flex align-items-center gap-2 mb-3">
                                        <strong id="stationLimitTransferAccount">Choose a payment method</strong>
                                        <span id="stationLimitTransferLabel" class="text-secondary small"></span>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="copyStationLimitAccount" disabled aria-label="Copy transfer account number" title="Copy account number">
                                            <i class="bi bi-clipboard" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                    <div id="stationLimitCopyStatus" class="small mb-2" role="status" aria-live="polite"></div>
                                    <p class="text-secondary small mb-1">Account name</p>
                                    <p class="mb-3"><?= htmlspecialchars(BILLING_PLATFORM_RECIPIENT_NAME, ENT_QUOTES, 'UTF-8') ?></p>
                                    <div class="d-flex justify-content-between border-top border-secondary pt-2">
                                        <span>Package stations</span><strong id="stationLimitPackageCount"><?= max((int) $stationCount, (int) $baseStationLimit + (int) $monthlySlots) + 1 ?></strong>
                                    </div>
                                    <div class="d-flex justify-content-between mt-2">
                                        <span>Actual value</span><strong id="stationLimitActual"><?= number_format($packageRate * (max((int) $stationCount, (int) $baseStationLimit + (int) $monthlySlots) + 1)) ?> MMK</strong>
                                    </div>
                                    <div class="d-flex justify-content-between mt-2">
                                        <span>Discount <span id="stationLimitDiscountRate">(0%)</span></span><strong id="stationLimitDiscount">0 MMK</strong>
                                    </div>
                                    <div class="d-flex justify-content-between border-top border-secondary pt-2 mt-2">
                                        <span>Total due</span><strong id="stationLimitTotal"><?= number_format($packageRate * (max((int) $stationCount, (int) $baseStationLimit + (int) $monthlySlots) + 1)) ?> MMK</strong>
                                    </div>
                                    <p class="text-secondary small mt-3 mb-0">Transfer the displayed amount before submitting your screenshot for Admin or Moderator review. Capacity updates after the payment is confirmed.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-secondary">
                        <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-info">Review Payment Details</button>
                    </div>
                </form>
                <dialog class="station-limit-receipt-dialog" id="stationLimitPaymentConfirmation" aria-labelledby="stationLimitReceiptTitle">
                    <div class="station-limit-receipt-header">
                        <h2 id="stationLimitReceiptTitle">Review payment details</h2>
                        <button type="button" class="station-limit-receipt-close" id="closeStationLimitReceipt" aria-label="Close">×</button>
                    </div>
                    <div class="station-limit-receipt-body">
                        <section class="station-limit-receipt-card">
                            <h3>Transfer and package total</h3>
                            <p class="station-limit-receipt-company">
                                <strong><?= htmlspecialchars((string) ($hqDetails['company_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong><br>
                                <?= htmlspecialchars((string) ($hqDetails['headquarter_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?><br>
                                <span id="stationLimitReceiptPackageCount"></span> stations · <span id="stationLimitReceiptTerm"></span>
                            </p>
                            <p class="station-limit-receipt-method">Payment method: <strong id="stationLimitReceiptMethod"></strong></p>
                            <div class="station-limit-receipt-account"><strong id="stationLimitReceiptAccount"></strong></div>
                            <p class="station-limit-receipt-note">Account name: <?= htmlspecialchars(BILLING_PLATFORM_RECIPIENT_NAME, ENT_QUOTES, 'UTF-8') ?></p>
                            <div class="station-limit-receipt-total"><span>Actual value</span><strong id="stationLimitReceiptActual"></strong></div>
                            <div class="station-limit-receipt-total"><span>Discount <span id="stationLimitReceiptDiscountRate"></span></span><strong id="stationLimitReceiptDiscount"></strong></div>
                            <div class="station-limit-receipt-total"><span>Total due</span><strong id="stationLimitReceiptTotal"></strong></div>
                        </section>
                        <p class="station-limit-receipt-disclaimer">Download or print this receipt for your records. Confirm to submit the payment screenshot and package details for review; this summary is not proof of payment confirmation.</p>
                    </div>
                    <div class="station-limit-receipt-footer">
                        <button type="button" class="btn btn-outline-secondary" id="downloadStationLimitReceipt">Download</button>
                        <button type="button" class="btn btn-outline-secondary" id="printStationLimitReceipt">Print PDF</button>
                        <button type="button" class="btn btn-outline-secondary" id="cancelStationLimitReceipt">Back</button>
                        <button type="button" class="btn btn-success" id="confirmStationLimitPayment">Submit</button>
                    </div>
                </dialog>
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

        const extendStationLimitForm = document.getElementById('extendStationLimitForm');
        if (extendStationLimitForm) {
            const quantityInput = document.getElementById('extraStationQuantity');
            const termSelect = document.getElementById('stationLimitTerm');
            const methodSelect = document.getElementById('stationLimitPaymentMethod');
            const transferAccount = document.getElementById('stationLimitTransferAccount');
            const transferLabel = document.getElementById('stationLimitTransferLabel');
            const copyAccountButton = document.getElementById('copyStationLimitAccount');
            const copyStatus = document.getElementById('stationLimitCopyStatus');
            const baseCapacity = Number(extendStationLimitForm.dataset.baseCapacity);
            const currentStations = Number(extendStationLimitForm.dataset.currentStations);
            const packageRate = Number(extendStationLimitForm.dataset.packageRate);
            const termDiscounts = JSON.parse(extendStationLimitForm.dataset.termDiscounts);
            const paymentAccounts = JSON.parse(extendStationLimitForm.dataset.accounts);
            const submitPaymentButton = extendStationLimitForm.querySelector('button[type="submit"]');
            const paymentConfirmation = document.getElementById('stationLimitPaymentConfirmation');
            let confirmedPaymentSubmission = false;
            const formatMmk = amount => `${new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 }).format(amount)} MMK`;

            function updateStationLimitQuote() {
                const extraSlots = Number(quantityInput.value) || 0;
                const termMonths = Number(termSelect.value) || 1;
                const packageStations = Math.max(currentStations, baseCapacity) + extraSlots;
                const selectedDiscount = termDiscounts.find(tier => termMonths >= Number(tier.min_months));
                const termPercent = selectedDiscount ? Number(selectedDiscount.percent) : 0;
                const actual = packageRate * packageStations * termMonths;
                const total = Math.round(actual * (1 - termPercent / 100) * 100) / 100;
                const discount = actual - total;

                document.getElementById('stationLimitPackageCount').textContent = packageStations;
                document.getElementById('stationLimitActual').textContent = formatMmk(actual);
                document.getElementById('stationLimitDiscountRate').textContent = `(${termPercent}% off)`;
                document.getElementById('stationLimitDiscount').textContent = formatMmk(discount);
                document.getElementById('stationLimitTotal').textContent = formatMmk(total);

                const selectedAccount = paymentAccounts[methodSelect.value];
                transferAccount.textContent = selectedAccount?.number || 'Choose a payment method';
                transferLabel.textContent = selectedAccount ? `(${selectedAccount.label})` : '';
                copyAccountButton.disabled = !selectedAccount?.number;
                copyStatus.textContent = '';
            }

            quantityInput.addEventListener('input', updateStationLimitQuote);
            termSelect.addEventListener('change', updateStationLimitQuote);
            methodSelect.addEventListener('change', updateStationLimitQuote);
            copyAccountButton.addEventListener('click', async () => {
                const accountNumber = transferAccount.textContent.trim();
                if (!accountNumber || copyAccountButton.disabled) {
                    return;
                }

                try {
                    await navigator.clipboard.writeText(accountNumber);
                    copyStatus.className = 'small mb-2 text-success';
                    copyStatus.textContent = 'Account number copied.';
                } catch {
                    const fallbackInput = document.createElement('textarea');
                    fallbackInput.value = accountNumber;
                    fallbackInput.setAttribute('readonly', '');
                    fallbackInput.style.position = 'fixed';
                    fallbackInput.style.opacity = '0';
                    document.body.appendChild(fallbackInput);
                    fallbackInput.select();
                    const copied = document.execCommand('copy');
                    fallbackInput.remove();
                    copyStatus.className = copied ? 'small mb-2 text-success' : 'small mb-2 text-danger';
                    copyStatus.textContent = copied
                        ? 'Account number copied.'
                        : 'Could not copy the account number. Please copy it manually.';
                }
            });
            updateStationLimitQuote();

            const paymentReceiptData = () => {
                const account = paymentAccounts[methodSelect.value] || {};
                return {
                    company: <?= json_encode((string) ($hqDetails['company_name'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                    email: <?= json_encode((string) ($hqDetails['headquarter_email'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                    stations: document.getElementById('stationLimitPackageCount').textContent,
                    term: termSelect.options[termSelect.selectedIndex]?.text || '',
                    method: methodSelect.options[methodSelect.selectedIndex]?.text || '',
                    accountNumber: account.number || '',
                    actual: document.getElementById('stationLimitActual').textContent,
                    discountRate: document.getElementById('stationLimitDiscountRate').textContent,
                    discount: document.getElementById('stationLimitDiscount').textContent,
                    total: document.getElementById('stationLimitTotal').textContent,
                    createdAt: new Date().toLocaleString()
                };
            };
            const escapeReceiptHtml = value => String(value).replace(/[&<>"']/g, character => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            })[character]);
            const receiptDocument = data => `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Station package payment summary</title><style>
*{box-sizing:border-box}
@page{size:A4;margin:18mm}
body{margin:0;padding:24px;background:#0c1422;color:#edf3fb;font:14px/1.5 Arial,sans-serif;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.receipt{width:100%;max-width:620px;margin:24px auto}
.header{padding:16px 22px;border:1px solid #29394e;border-radius:14px 14px 0 0;background:#142033}
.header h1{margin:0;font-size:18px;font-weight:600}
.card{padding:22px;border:1px solid #29394e;border-top:0;border-radius:0 0 14px 14px;background:#142033;break-inside:avoid}
.card h2{margin:0 0 16px;font-size:16px;font-weight:600}
.company{margin:0 0 18px;color:#a1b1c6;font-size:12px;line-height:1.6}
.company strong{color:#edf3fb;font-size:14px}
.method{margin:0 0 6px;color:#a1b1c6;font-size:12px}
.account{margin:8px 0;font-size:17px;overflow-wrap:anywhere}
.note{margin:0 0 14px;color:#a1b1c6;font-size:12px}
.total{display:flex;justify-content:space-between;gap:16px;padding:10px 0;border-top:1px solid #29394e;font-variant-numeric:tabular-nums}
.total span{color:#a1b1c6}.total strong{text-align:right;overflow-wrap:anywhere}
.disclaimer{margin:18px 0 0;color:#a1b1c6;font-size:11px}
@media print{body{padding:0;background:#0c1422}.receipt{max-width:none;margin:0}.header,.card{background:#142033!important;color:#edf3fb!important;box-shadow:none}}
</style></head><body><main class="receipt">
<header class="header"><h1>Review payment details</h1></header>
<section class="card"><h2>Transfer and package total</h2>
<p class="company"><strong>${escapeReceiptHtml(data.company)}</strong><br>${escapeReceiptHtml(data.email)}<br>${escapeReceiptHtml(data.stations)} stations · ${escapeReceiptHtml(data.term)}</p>
<p class="method">Payment method: <strong>${escapeReceiptHtml(data.method)}</strong></p>
<div class="account"><strong>${escapeReceiptHtml(data.accountNumber)}</strong></div>
<p class="note">Account name: <?= htmlspecialchars(BILLING_PLATFORM_RECIPIENT_NAME, ENT_QUOTES, 'UTF-8') ?></p>
<div class="total"><span>Actual value</span><strong>${escapeReceiptHtml(data.actual)}</strong></div>
<div class="total"><span>Discount ${escapeReceiptHtml(data.discountRate)}</span><strong>${escapeReceiptHtml(data.discount)}</strong></div>
<div class="total"><span>Total due</span><strong>${escapeReceiptHtml(data.total)}</strong></div>
<p class="disclaimer">Prepared ${escapeReceiptHtml(data.createdAt)}. This summary records the package details and is not proof of payment confirmation.</p>
</section></main></body></html>`;
            const updatePaymentReceipt = () => {
                const data = paymentReceiptData();
                document.getElementById('stationLimitReceiptPackageCount').textContent = data.stations;
                document.getElementById('stationLimitReceiptTerm').textContent = data.term;
                document.getElementById('stationLimitReceiptMethod').textContent = data.method;
                document.getElementById('stationLimitReceiptAccount').textContent = data.accountNumber;
                document.getElementById('stationLimitReceiptActual').textContent = data.actual;
                document.getElementById('stationLimitReceiptDiscountRate').textContent = data.discountRate;
                document.getElementById('stationLimitReceiptDiscount').textContent = data.discount;
                document.getElementById('stationLimitReceiptTotal').textContent = data.total;
            };
            extendStationLimitForm.addEventListener('submit', event => {
                if (confirmedPaymentSubmission) {
                    confirmedPaymentSubmission = false;
                    return;
                }
                if (!extendStationLimitForm.checkValidity()) {
                    event.preventDefault();
                    extendStationLimitForm.reportValidity();
                    return;
                }
                event.preventDefault();
                updatePaymentReceipt();
                paymentConfirmation.showModal();
            });
            document.getElementById('closeStationLimitReceipt').addEventListener('click', () => paymentConfirmation.close());
            document.getElementById('cancelStationLimitReceipt').addEventListener('click', () => paymentConfirmation.close());
            document.getElementById('confirmStationLimitPayment').addEventListener('click', () => {
                if (!extendStationLimitForm.reportValidity()) return;
                confirmedPaymentSubmission = true;
                paymentConfirmation.close();
                extendStationLimitForm.requestSubmit(submitPaymentButton);
            });
            document.getElementById('downloadStationLimitReceipt').addEventListener('click', () => {
                const blob = new Blob([receiptDocument(paymentReceiptData())], { type: 'text/html;charset=utf-8' });
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                const company = <?= json_encode((string) ($hqDetails['company_name'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                const filename = company.trim().replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '') || 'hq';
                link.href = url;
                link.download = `${filename}-station-package-payment-receipt.html`;
                document.body.appendChild(link);
                link.click();
                link.remove();
                window.setTimeout(() => URL.revokeObjectURL(url), 1000);
            });
            document.getElementById('printStationLimitReceipt').addEventListener('click', () => {
                const printWindow = window.open('', '_blank');
                if (!printWindow) {
                    window.alert('Allow pop-ups to print or save this receipt as a PDF.');
                    return;
                }
                printWindow.document.open();
                printWindow.document.write(receiptDocument(paymentReceiptData()));
                printWindow.document.close();
                printWindow.focus();
                printWindow.print();
            });
        }

        const openStationLimitExtension = document.getElementById('openStationLimitExtension');
        if (openStationLimitExtension) {
            openStationLimitExtension.addEventListener('click', () => {
                const warningModal = document.getElementById('stationLimitModal');
                warningModal.addEventListener('hidden.bs.modal', () => {
                    bootstrap.Modal.getOrCreateInstance(document.getElementById('extendStationLimitModal')).show();
                }, { once: true });
                bootstrap.Modal.getOrCreateInstance(warningModal).hide();
            });
        }

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
