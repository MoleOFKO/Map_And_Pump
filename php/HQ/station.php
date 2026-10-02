<?php
require_once __DIR__ . '/hq_guard.php';
/**
 * Shared database connection.
 */
include_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../business_scope.php';
$message = '';
$error = '';
$hqId = map_pump_current_hq_id($pdo);
$stationLimit = 3;
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

    // Register Station
    if ($action === 'register') {
        $station_name    = trim($_POST['station_name'] ?? '');
        $location        = trim($_POST['location'] ?? '');
        $station_address = trim($_POST['station_address'] ?? '');
        $manager_email   = trim($_POST['manager_email'] ?? '');

        $location = map_pump_normalize_station_location($location);

        if (!empty($station_name) && $location !== null && !empty($manager_email)) {
            try {
                $pdo->beginTransaction();
                $hqLockStmt = $pdo->prepare("SELECT headquarter_id FROM headquarter WHERE headquarter_id = ? FOR UPDATE");
                $hqLockStmt->execute([$hqId]);
                $stationCountStmt = $pdo->prepare(
                    "SELECT COUNT(*)
                     FROM branches b
                     INNER JOIN station_business_scope sbs ON sbs.station_id = b.branch_id
                     WHERE sbs.headquarter_id = ? AND b.status <> 'closed'"
                );
                $stationCountStmt->execute([$hqId]);
                if ((int) $stationCountStmt->fetchColumn() >= $stationLimit) {
                    throw new RuntimeException('station_limit_reached');
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

                $branchCodeStmt = $pdo->prepare("SELECT CONCAT('BR-', LPAD(COALESCE(MAX(branch_id), 0) + 1, 5, '0')) FROM branches");
                $branchCodeStmt->execute();
                $branchCode = (string)$branchCodeStmt->fetchColumn();
                [$latitude, $longitude] = array_map('trim', explode(',', $location, 2));
                $branchStmt = $pdo->prepare(
                    "INSERT INTO branches (branch_code, branch_name, city, township, address, latitude, longitude, manager_user_id, opening_time, closing_time, status, hq_region)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, '06:00:00', '22:00:00', 'active', ? )"
                );
                $branchStmt->execute([$branchCode, $station_name, 'Unknown', null, $station_address, $latitude, $longitude, $manager_id, null]);
                $branchId = (int)$pdo->lastInsertId();
                $stationId = $branchId;

                $pdo->prepare("DELETE FROM station_business_scope WHERE branch_manager_user_id = ?")->execute([$manager_id]);
                $scopeStmt = $pdo->prepare(
                    "INSERT INTO station_business_scope (station_id, headquarter_id, branch_manager_user_id)
                     VALUES (?, ?, ?)"
                );
                $scopeStmt->execute([$stationId, $hqId, $manager_id]);

                $accountScopeStmt = $pdo->prepare(
                    "INSERT INTO business_account_scope
                        (user_id, business_role_id, headquarter_id, branch_manager_user_id, station_id, status)
                     VALUES (?, 4, ?, NULL, ?, 'active')
                     ON DUPLICATE KEY UPDATE headquarter_id = VALUES(headquarter_id), station_id = VALUES(station_id), status = 'active'"
                );
                $accountScopeStmt->execute([$manager_id, $hqId, $stationId]);

                $userScopeStmt = $pdo->prepare("UPDATE users SET headquarter_id = ?, branch_id = ?, business_role_id = 4 WHERE user_id = ?");
                $userScopeStmt->execute([$hqId, $branchId, $manager_id]);

                $fuelStmt = $pdo->query("SELECT oil_type_id FROM oil_types ORDER BY oil_type_id LIMIT 4");
                $inventoryStmt = $pdo->prepare(
                    "INSERT INTO station_oil_inventory
                        (station_id, oil_type_id, price_per_litre, is_active, current_amount_liters, updated_at)
                     VALUES (?, ?, 0, 1, 0, NOW())"
                );
                foreach ($fuelStmt->fetchAll(PDO::FETCH_COLUMN) as $oilTypeId) {
                    $inventoryStmt->execute([$stationId, (int)$oilTypeId]);
                }

                $tankSeedStmt = $pdo->prepare(
                    "INSERT INTO tanks (branch_id, fuel_code, fuel_name, capacity_l, current_l, alert_pct, is_enabled)
                     VALUES (?, ?, ?, ?, 0, 25, 1)"
                );
                $tankSeeds = [
                    ['p92', 'Petrol 92', 15000],
                    ['p95', 'Petrol 95', 10000],
                    ['dsl', 'Diesel', 12000],
                    ['pdsl', 'Premium Diesel', 12000],
                ];
                foreach ($tankSeeds as $tankSeed) {
                    $tankSeedStmt->execute([$stationId, $tankSeed[0], $tankSeed[1], $tankSeed[2]]);
                }
                $pdo->commit();
                $message = "Station node created successfully.";
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
                } else {
                    $error = $e->getMessage();
                }
            }
        } else {
            $error = "Station name, branch manager email, and a valid map location are required.";
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
            $userScopeStmt = $pdo->prepare("UPDATE users SET headquarter_id = ?, branch_id = ?, business_role_id = 4 WHERE user_id = ?");
            $userScopeStmt->execute([$hqId, $station_id, $manager_id]);
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
    $stmt = $pdo->prepare(
        "SELECT b.branch_id AS station_id, b.branch_name AS station_name, b.address AS station_address,
                b.manager_user_id AS business_user_id,
                CASE WHEN b.latitude IS NOT NULL AND b.longitude IS NOT NULL THEN CONCAT(b.latitude, ', ', b.longitude) ELSE '' END AS location,
                CASE b.status WHEN 'active' THEN 'Active' WHEN 'maintenance' THEN 'Maintenance' ELSE 'Decommissioned' END AS status,
                u.user_name AS manager_name, u.email AS manager_email
         FROM branches b
         INNER JOIN station_business_scope sbs ON sbs.station_id = b.branch_id
         LEFT JOIN users u ON u.user_id = b.manager_user_id
         WHERE sbs.headquarter_id = ?
           AND b.status <> 'closed'
         ORDER BY b.branch_id DESC"
    );
    $stmt->execute([$hqId]);
    $stations = $stmt->fetchAll();
    $stationCount = count($stations);
    $stationLimitReached = $stationCount >= $stationLimit;

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
            position: absolute;
            right: 0;
            top: 100%;
            margin-top: 4px;
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 8px;
            min-width: 190px;
            z-index: 1000;
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

        <div class="card p-4 bg-dark text-light border-secondary">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h3>Manage Deployed Stations</h3>
                    <p class="subtitle mb-0">Monitor active nodes, update operational status, or remove configurations.</p>
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
                                    $badgeClass = $isMaintenance ? 'badge bg-warning text-dark' : 'badge bg-success';
                                    $statusText = $isMaintenance ? 'Maintenance' : 'Active';
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
                                            <button type="button" data-bs-toggle="modal" data-bs-target="#configureModal"
                                                onclick="closeStationMenus(); setConfigureData('<?= htmlspecialchars($row['station_id'], ENT_QUOTES) ?>', '<?= htmlspecialchars($row['station_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($row['station_address'], ENT_QUOTES) ?>', '<?= htmlspecialchars($row['manager_email'], ENT_QUOTES) ?>', '<?= htmlspecialchars($row['location'], ENT_QUOTES) ?>')">
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
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-light">Station Name</label>
                                <input type="text" name="station_name" class="form-control bg-dark text-light border-secondary" placeholder="e.g. Downtown Metro" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-light">Branch Manager Email</label>
                                <select name="manager_email" class="form-select bg-dark text-light border-secondary" required>
                                    <option value="">Choose a branch manager</option>
                                    <?php foreach ($branchManagers as $manager): ?>
                                        <option value="<?= htmlspecialchars($manager['email'], ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars($manager['email']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 mb-3">
                                <label class="form-label text-light">Station Address</label>
                                <input type="text" name="station_address" class="form-control bg-dark text-light border-secondary" placeholder="e.g. No. 45, Main Road, Township">
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
                    <p class="mb-2">Your HQ has reached its limit of <?= (int) $stationLimit ?> included stations.</p>
                    <p class="text-secondary mb-0">Review the station estimate to see pricing for additional stations.</p>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Close</button>
                    <a href="/HomePage.php#calc" class="btn btn-warning"><i class="bi bi-calculator me-1"></i>View station estimate</a>
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

        function toggleStationActions(button, stationId) {
            const menu = document.getElementById('station-menu-' + stationId);
            const isVisible = menu.style.display === 'block';

            // Hide all other menus
            document.querySelectorAll('.station-action-menu').forEach(m => {
                if (m.id !== 'station-menu-' + stationId) {
                    m.style.display = 'none';
                }
            });

            menu.style.display = isVisible ? 'none' : 'block';

            if (!isVisible) {
                document.addEventListener('click', function closeMenu(e) {
                    if (!menu.contains(e.target) && e.target !== button) {
                        menu.style.display = 'none';
                        document.removeEventListener('click', closeMenu);
                    }
                });
            }
        }

        function closeStationMenus() {
            document.querySelectorAll('.station-action-menu').forEach(m => {
                m.style.display = 'none';
            });
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