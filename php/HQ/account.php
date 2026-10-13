<?php
require_once __DIR__ . '/hq_guard.php';
require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../business_scope.php';
require_once __DIR__ . '/../invitations_schema.php';

try {
    map_pump_ensure_invitation_schema($pdo);
} catch (Throwable $e) {
    error_log('Failed to prepare HQ invitation schema: ' . $e->getMessage());
}

$managers = [];
$stations = [];
$branchManagers = [];
$dbError = '';
$statusMessage = '';
$hqId = map_pump_current_hq_id($pdo);
$sessionMessage = $_SESSION['hq_account_message'] ?? null;
unset($_SESSION['hq_account_message']);
if (is_array($sessionMessage)) {
    if (($sessionMessage['type'] ?? 'success') === 'danger') {
        $dbError = (string)($sessionMessage['text'] ?? '');
    } else {
        $statusMessage = (string)($sessionMessage['text'] ?? '');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_manager_status'])) {
    $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $newStatus = filter_input(INPUT_POST, 'new_status', FILTER_SANITIZE_STRING);
    $accountRole = filter_input(INPUT_POST, 'account_role', FILTER_VALIDATE_INT);

    if ($userId && $accountRole === 4 && in_array($newStatus, ['active', 'disabled'], true)) {
        try {
            if (!map_pump_manager_in_hq($pdo, (int)$userId, $hqId)) {
                $statusMessage = 'That manager is not under your HQ.';
            } else {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('UPDATE users SET status = ? WHERE user_id = ? AND COALESCE(business_role_id, role_id) = ?');
                $stmt->execute([$newStatus, $userId, $accountRole]);

                $stationStmt = $pdo->prepare('SELECT station_id FROM station_business_scope WHERE headquarter_id = ? AND branch_manager_user_id = ? ORDER BY station_id LIMIT 1');
                $stationStmt->execute([$hqId, $userId]);
                $stationId = $stationStmt->fetchColumn() ?: null;

                $scopeStmt = $pdo->prepare(
                    "INSERT INTO business_account_scope (user_id, business_role_id, headquarter_id, branch_manager_user_id, station_id, status)
                     VALUES (?, ?, ?, NULL, ?, ?)
                     ON DUPLICATE KEY UPDATE headquarter_id = VALUES(headquarter_id), business_role_id = VALUES(business_role_id), station_id = VALUES(station_id), status = VALUES(status)"
                );
                 $scopeStmt->execute([$userId, $accountRole, $hqId, $stationId, $newStatus]);
                $pdo->commit();
                $statusMessage = 'Manager status updated successfully.';
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $dbError = $e->getMessage();
        }
    } else {
        $statusMessage = 'Invalid manager status update request.';
    }
}

try {
    $sql = "SELECT u.user_id, u.user_name, u.email, u.status, 4 AS account_role,
                   s.branch_id AS station_id, s.branch_name AS station_name,
                   CASE WHEN s.latitude IS NOT NULL AND s.longitude IS NOT NULL
                        THEN CONCAT(s.latitude, ', ', s.longitude) ELSE s.address END AS location
            FROM users u
            LEFT JOIN business_account_scope bas ON bas.user_id = u.user_id
            LEFT JOIN station_business_scope sbs ON sbs.branch_manager_user_id = u.user_id AND sbs.headquarter_id = :hq_id_scope
                        LEFT JOIN branches s ON s.branch_id = COALESCE(bas.station_id, sbs.station_id)
                            WHERE COALESCE(u.business_role_id, u.role_id) = 4
              AND (
                  bas.headquarter_id = :hq_id
                  OR (
                      bas.headquarter_id IS NULL
                      AND (u.headquarter_id = :hq_id_user OR sbs.headquarter_id = :hq_id_scope_filter)
                  )
              )
            ORDER BY u.user_id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':hq_id_scope' => $hqId,
        ':hq_id' => $hqId,
        ':hq_id_user' => $hqId,
        ':hq_id_scope_filter' => $hqId,
    ]);
    $managers = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

try {
    $stationStmt = $pdo->prepare("SELECT s.branch_id AS station_id, s.branch_name AS station_name,
                                         CASE WHEN s.latitude IS NOT NULL AND s.longitude IS NOT NULL
                                              THEN CONCAT(s.latitude, ', ', s.longitude) ELSE s.address END AS location,
                                         s.address AS station_address
                                  FROM branches s
                                  INNER JOIN station_business_scope sbs ON sbs.station_id = s.branch_id
                                  WHERE sbs.headquarter_id = ?
                                  ORDER BY s.branch_name");
    $stationStmt->execute([$hqId]);
    $stations = $stationStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

try {
    $branchManagerStmt = $pdo->prepare(
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
    $branchManagerStmt->execute([$hqId, $hqId]);
    $branchManagers = $branchManagerStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $dbError = $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <script src="../../js/hq-theme.js"></script>
<link rel="stylesheet" href="../../css/HQstyle.css">
    <style>
        /* Account roster search bar styling (matches pricing/station search bars) */
        .account-search {
            position: relative;
            flex: 0 0 auto;
            width: 260px;
            max-width: 100%;
        }

        .account-search .account-search-icon {
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

        .account-search #accountSearch {
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

        .account-search #accountSearch::placeholder {
            color: var(--slate-300);
            opacity: 0.7;
        }

        .account-search #accountSearch:focus {
            background: rgba(255, 255, 255, 0.08);
            border-color: rgba(56, 189, 248, 0.45);
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.16);
        }

        .account-search #accountSearch::-webkit-search-cancel-button,
        .account-search #accountSearch::-webkit-search-decoration {
            -webkit-appearance: none;
            appearance: none;
        }

        .account-search .account-search-clear {
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

        .account-search .account-search-clear:hover {
            color: var(--slate-50);
            background: rgba(255, 255, 255, 0.08);
        }

        .account-search.has-value .account-search-clear {
            display: flex;
            align-items: center;
            justify-content: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2 class="title">Manage Branch Managers</h2>
        <p class="subtitle">Invite and control branch managers assigned to this HQ. Staff are managed by their branch manager.</p>

        <?php
if (!empty($dbError)): ?>
            <div class="alert alert-danger" role="alert">
                Database error: <?= htmlspecialchars($dbError) ?>
            </div>
        <?php
endif; ?>

        <?php
if (!empty($statusMessage)): ?>
            <div class="alert alert-success" role="alert">
                <?= htmlspecialchars($statusMessage) ?>
            </div>
        <?php
endif; ?>

        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap;">
                <h3 style="margin: 0;">Manager &amp; Staff Roster</h3>
                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                    <div class="account-search" id="accountSearchWrap">
                        <i class="fa-solid fa-magnifying-glass account-search-icon" aria-hidden="true"></i>
                        <input type="search" id="accountSearch" class="form-control" placeholder="Search accounts..." autocomplete="off" aria-label="Search accounts by name, email, station or status">
                    </div>
                    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#inviteAccountModal">+ Add Manager or Staff</button>
                </div>
            </div>
            <ul class="nav nav-tabs mt-3" id="accountRoleTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" type="button" role="tab" data-account-role-tab="4" aria-selected="true">Branch Managers</button>
                </li>
            </ul>
            <table id="accountRosterTable" style="width: 100%; border-collapse: collapse; margin-top: 10px;">
                <thead>
                    <tr style="border-bottom: 2px solid var(--border); text-align: left;">
                        <th style="padding: 12px 8px;">Account Name</th>
                        <th style="padding: 12px 8px;">Account Email</th>
                        <th style="padding: 12px 8px;">Station Assignment</th>
                        <th style="padding: 12px 8px;">Status</th>
                        <th style="padding: 12px 8px; text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
if (!empty($managers)): ?>
                        <?php
foreach ($managers as $manager): ?>
                            <?php
$status = strtolower((string)($manager['status'] ?? ''));
                                $isActive = in_array($status, ['active', 'approved'], true);
                                $statusLabel = $isActive ? 'Active' : ucfirst($status ?: 'Inactive');
                                $statusColor = $isActive ? 'var(--success)' : 'var(--danger)';
                                $stationName = trim((string)($manager['station_name'] ?? ''));
                                $stationLocation = trim((string)($manager['location'] ?? ''));
                                $stationText = $stationName !== '' ? ($stationLocation !== '' ? $stationName . ' — ' . $stationLocation : $stationName) : 'Unassigned';
                            ?>
                            <tr style="border-bottom: 1px solid var(--border);" data-account-role="<?= (int)($manager['account_role'] ?? 4) ?>" data-search="<?= htmlspecialchars(strtolower(trim(
                                    ($manager['user_name'] ?? '') . ' ' .
                                    ($manager['email'] ?? '') . ' ' .
                                    $stationText . ' ' .
                                    $statusLabel
                                )), ENT_QUOTES) ?>">
                                <td style="padding: 12px 8px;"><?= htmlspecialchars($manager['user_name'] ?? 'N/A') ?></td>
                                <td style="padding: 12px 8px;"><?= htmlspecialchars($manager['email'] ?? 'N/A') ?></td>
                                <td style="padding: 12px 8px;"><?= htmlspecialchars($stationText) ?></td>
                                <td style="padding: 12px 8px;"><span style="color: <?= htmlspecialchars($statusColor) ?>; font-weight: bold;"><?= htmlspecialchars($statusLabel) ?></span></td>
                                <td style="padding: 12px 8px; text-align: right;">
                                    <form method="POST" action="" style="display: inline;" class="manager-status-form">
                                        <input type="hidden" name="toggle_manager_status" value="1">
                                        <input type="hidden" name="user_id" value="<?= (int)($manager['user_id'] ?? 0) ?>">
                                        <input type="hidden" name="account_role" value="<?= (int)($manager['account_role'] ?? 4) ?>">
                                        <input type="hidden" name="new_status" value="<?= $isActive ? 'disabled' : 'active' ?>">
                                        <button type="button" class="btn <?= $isActive ? 'btn-danger' : 'btn-success' ?> status-toggle-btn" style="padding: 6px 12px; font-size: 0.8rem;" data-action-text="<?= $isActive ? 'disable' : 'activate' ?>" data-user-name="<?= htmlspecialchars($manager['user_name'] ?? 'this manager', ENT_QUOTES) ?>">
                                            <?= $isActive ? 'Disable' : 'Activate' ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php
endforeach; ?>
                    <?php
else: ?>
                        <tr data-account-empty="true">
                            <td colspan="5" style="padding: 16px 8px; text-align: center; color: var(--muted);">No branch manager accounts found.</td>
                        </tr>
                    <?php
endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="modal fade" id="inviteAccountModal" tabindex="-1" aria-labelledby="inviteAccountModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="background: var(--bg, #111827); color: var(--slate-50, #f8fafc); border: 1px solid rgba(255,255,255,0.08); border-radius: 18px;">
                <form method="POST" action="./invite_account.php" id="inviteAccountForm">
                    <div class="modal-header border-0"><h5 class="modal-title" id="inviteAccountModalLabel">Add Branch Manager</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>
                    <div class="modal-body">
                        <p class="small text-white-50">The email must belong to an existing customer account. That customer must Accept or Decline the invitation before continuing.</p>
                        <label class="form-label" for="inviteEmail">Customer email</label>
                        <input class="form-control mb-3" type="email" id="inviteEmail" name="email" required>
                        <label class="form-label" for="inviteRole">Role</label>
                        <select class="form-select mb-3" id="inviteRole" name="role" required><option value="manager">Branch Manager</option><option value="staff">Staff</option></select>
                        <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                            <label class="form-label mb-0" for="inviteStation">Station</label>
                            <button type="button" class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#quickStationModal">+ Add Station</button>
                        </div>
                        <select class="form-select" id="inviteStation" name="station_id" required><option value="">Select an HQ station</option><?php foreach ($stations as $station): ?><option value="<?= (int)$station['station_id'] ?>"><?= htmlspecialchars((string)$station['station_name'] . ' — ' . (string)$station['location']) ?></option><?php endforeach; ?></select>
                        <?php if (empty($stations)): ?>
                            <div class="alert alert-warning mt-2 mb-0" role="alert">No stations are assigned to this HQ. Add a station before sending an invitation.</div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer border-0"><button type="button" class="btn btn-outline-secondary text-white" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Send Invitation</button></div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="quickStationModal" tabindex="-1" aria-labelledby="quickStationModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content" style="background: var(--bg, #111827); color: var(--slate-50, #f8fafc); border: 1px solid rgba(255,255,255,0.08); border-radius: 18px;">
                <form method="POST" action="./station.php">
                    <input type="hidden" name="action" value="register">
                    <input type="hidden" name="return_to" value="account.php">
                    <div class="modal-header border-0">
                        <h5 class="modal-title" id="quickStationModalLabel">Add Station</h5>
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
                                        <option value="<?= htmlspecialchars($manager['email'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($manager['email']) ?></option>
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
                                    <button type="button" class="btn btn-sm btn-outline-info" id="quickAddTrackLocBtn">
                                        <i class="bi bi-geo-alt-fill"></i> Track My Location
                                    </button>
                                </div>
                                <input type="text" id="quickStationLocation" name="location" class="form-control bg-dark text-light border-secondary" placeholder="Click the map or track location" required readonly>
                            </div>
                            <div class="col-12">
                                <div id="quickStationMap" aria-label="Choose station location on map" style="height: 220px; border-radius: 8px; border: 1px solid #373b3e;"></div>
                                <small class="text-secondary">Click the map or use the button above to select coordinates.</small>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0">
                        <button type="button" class="btn btn-outline-secondary text-white" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Save Station</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="statusConfirmModal" tabindex="-1" aria-labelledby="statusConfirmModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="background: var(--bg, #111827); color: var(--slate-50, #f8fafc); border: 1px solid rgba(255,255,255,0.08); border-radius: 18px;">
                <div class="modal-header border-0">
                    <h5 class="modal-title" id="statusConfirmModalLabel">Confirm Action</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p id="statusConfirmText" class="mb-0">Are you sure you want to proceed?</p>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-outline-secondary text-white" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmStatusAction">Confirm</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        const statusModal = new bootstrap.Modal(document.getElementById('statusConfirmModal'));
        let quickStationMap;
        let quickStationMarker;
        let pendingForm = null;

        function parseQuickStationCoordinates(value) {
            const parts = (value || '').split(',');
            if (parts.length !== 2) return null;
            const latitude = Number.parseFloat(parts[0]);
            const longitude = Number.parseFloat(parts[1]);
            if (!Number.isFinite(latitude) || !Number.isFinite(longitude) || latitude < -90 || latitude > 90 || longitude < -180 || longitude > 180) {
                return null;
            }
            return L.latLng(latitude, longitude);
        }

        function setQuickStationLocation(input, latLng) {
            if (quickStationMarker) {
                quickStationMarker.setLatLng(latLng);
            } else {
                quickStationMarker = L.marker(latLng).addTo(quickStationMap);
            }
            input.value = latLng.lat.toFixed(6) + ', ' + latLng.lng.toFixed(6);
        }

        function initializeQuickStationMap() {
            const locationInput = document.getElementById('quickStationLocation');
            const initialLocation = parseQuickStationCoordinates(locationInput.value);
            if (!quickStationMap) {
                quickStationMap = L.map('quickStationMap').setView(initialLocation || [21.9588, 96.0891], initialLocation ? 14 : 6);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                }).addTo(quickStationMap);
                quickStationMap.on('click', event => setQuickStationLocation(locationInput, event.latlng));
            } else if (initialLocation) {
                quickStationMap.setView(initialLocation, 14);
            }

            if (initialLocation) {
                setQuickStationLocation(locationInput, initialLocation);
            }
            window.setTimeout(() => quickStationMap.invalidateSize(), 100);
        }

        document.getElementById('quickStationModal').addEventListener('shown.bs.modal', initializeQuickStationMap);
        document.getElementById('quickAddTrackLocBtn').addEventListener('click', () => {
            if (!quickStationMap) {
                initializeQuickStationMap();
            }
            if (!navigator.geolocation) {
                alert('Location services are not available in this browser.');
                return;
            }
            navigator.geolocation.getCurrentPosition(position => {
                const location = L.latLng(position.coords.latitude, position.coords.longitude);
                setQuickStationLocation(document.getElementById('quickStationLocation'), location);
                quickStationMap.setView(location, 14);
            }, () => alert('Could not get your location. Check browser location permissions and try again.'), { enableHighAccuracy: true, timeout: 10000 });
        });

        document.querySelectorAll('.status-toggle-btn').forEach(function (button) {
            button.addEventListener('click', function () {
                const actionText = button.dataset.actionText || 'proceed';
                const userName = button.dataset.userName || 'this manager';
                const form = button.closest('.manager-status-form');

                pendingForm = form;
                const confirmText = document.getElementById('statusConfirmText');
                const confirmButton = document.getElementById('confirmStatusAction');

                confirmText.textContent = 'Are you sure you want to ' + actionText + ' manager "' + userName + '"?';
                confirmButton.classList.toggle('btn-danger', actionText === 'disable');
                confirmButton.classList.toggle('btn-success', actionText === 'activate');
                confirmButton.textContent = 'Confirm ' + actionText.charAt(0).toUpperCase() + actionText.slice(1);

                statusModal.show();
            });
        });

        document.getElementById('confirmStatusAction').addEventListener('click', function () {
            if (pendingForm) {
                pendingForm.submit();
            }
        });

        const accountRoleTabs = document.querySelectorAll('[data-account-role-tab]');
        const accountRows = document.querySelectorAll('[data-account-role]');
        let accountEmptyRow = document.querySelector('[data-account-empty]');
        const accountTableBody = accountEmptyRow ? accountEmptyRow.parentElement : document.querySelector('table tbody');
        let currentAccountRole = '4';

        function showAccountRole(role) {
            currentAccountRole = role;
            let visibleRows = 0;
            const term = (document.getElementById('accountSearch') || {}).value ? document.getElementById('accountSearch').value.trim().toLowerCase() : '';
            accountRows.forEach(function (row) {
                const matchesRole = row.dataset.accountRole === role;
                const haystack = row.dataset.search || '';
                const matchesSearch = term === '' || haystack.indexOf(term) !== -1;
                const isVisible = matchesRole && matchesSearch;
                row.style.display = isVisible ? '' : 'none';
                if (isVisible) {
                    visibleRows += 1;
                }
            });

            const emptyText = term !== ''
                ? 'No accounts match your search.'
                : (role === '5' ? 'No staff accounts found.' : 'No branch manager accounts found.');

            if (accountEmptyRow) {
                accountEmptyRow.style.display = visibleRows === 0 ? '' : 'none';
                accountEmptyRow.querySelector('td').textContent = emptyText;
            } else if (visibleRows === 0) {
                const emptyRow = document.createElement('tr');
                emptyRow.dataset.accountEmpty = 'true';
                emptyRow.innerHTML = '<td colspan="5" style="padding: 16px 8px; text-align: center; color: var(--muted);"></td>';
                accountTableBody.appendChild(emptyRow);
                accountEmptyRow = emptyRow;
                accountEmptyRow.querySelector('td').textContent = emptyText;
            }
        }

        accountRoleTabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                accountRoleTabs.forEach(function (item) {
                    const active = item === tab;
                    item.classList.toggle('active', active);
                    item.setAttribute('aria-selected', active ? 'true' : 'false');
                });
                showAccountRole(tab.dataset.accountRoleTab);
            });
        });

        showAccountRole('4');

        // Live search filter for the account roster table
        (function () {
            const input = document.getElementById('accountSearch');
            const clearBtn = document.getElementById('accountSearchClear');
            const searchWrap = document.getElementById('accountSearchWrap');
            if (!input) return;

            function updateClearButton() {
                if (input.value.trim() !== '') {
                    searchWrap.classList.add('has-value');
                } else {
                    searchWrap.classList.remove('has-value');
                }
            }

            function applyFilter() {
                updateClearButton();
                showAccountRole(currentAccountRole);
            }

            input.addEventListener('input', applyFilter);
            input.addEventListener('search', applyFilter);
            input.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    input.value = '';
                    applyFilter();
                }
            });
            if (clearBtn) {
                clearBtn.addEventListener('click', function () {
                    input.value = '';
                    applyFilter();
                    input.focus();
                });
            }
        })();

        const inviteAccountForm = document.getElementById('inviteAccountForm');
        if (inviteAccountForm) {
            inviteAccountForm.addEventListener('submit', function (event) {
                if (!inviteAccountForm.checkValidity()) {
                    event.preventDefault();
                    inviteAccountForm.reportValidity();
                }
            });
        }
    </script>

</body>
<footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map & Pump | All Rights Reserved</footer>
</html>
