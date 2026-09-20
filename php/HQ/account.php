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

$stations = [];
try {
    $stationStmt = $pdo->prepare("SELECT s.branch_id AS station_id, s.branch_name AS station_name,
                                         CASE WHEN s.latitude IS NOT NULL AND s.longitude IS NOT NULL
                                              THEN CONCAT(s.latitude, ', ', s.longitude) ELSE s.address END AS location
                                  FROM branches s
                                  INNER JOIN station_business_scope sbs ON sbs.station_id = s.branch_id
                                  WHERE sbs.headquarter_id = ?
                                  ORDER BY s.branch_name");
    $stationStmt->execute([$hqId]);
    $stations = $stationStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $dbError = $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <script src="../../js/hq-theme.js"></script>
<link rel="stylesheet" href="../../css/HQstyle.css">

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
                <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#inviteAccountModal">+ Add Branch Manager</button>
            </div>
            <ul class="nav nav-tabs mt-3" id="accountRoleTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" type="button" role="tab" data-account-role-tab="4" aria-selected="true">Branch Managers</button>
                </li>
            </ul>
            <table style="width: 100%; border-collapse: collapse; margin-top: 10px;">
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
                            <tr style="border-bottom: 1px solid var(--border);" data-account-role="<?= (int)($manager['account_role'] ?? 4) ?>">
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
                        <label class="form-label" for="inviteStation">Station</label>
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
    <script>
        const statusModal = new bootstrap.Modal(document.getElementById('statusConfirmModal'));
        let pendingForm = null;

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

        function showAccountRole(role) {
            let visibleRows = 0;
            accountRows.forEach(function (row) {
                const isVisible = row.dataset.accountRole === role;
                row.style.display = isVisible ? '' : 'none';
                if (isVisible) {
                    visibleRows += 1;
                }
            });

            if (accountEmptyRow) {
                accountEmptyRow.style.display = visibleRows === 0 ? '' : 'none';
                accountEmptyRow.querySelector('td').textContent = role === '5' ? 'No staff accounts found.' : 'No branch manager accounts found.';
            } else if (visibleRows === 0) {
                const emptyRow = document.createElement('tr');
                emptyRow.dataset.accountEmpty = 'true';
                emptyRow.innerHTML = '<td colspan="5" style="padding: 16px 8px; text-align: center; color: var(--muted);"></td>';
                accountTableBody.appendChild(emptyRow);
                accountEmptyRow = emptyRow;
                accountEmptyRow.querySelector('td').textContent = role === '5' ? 'No staff accounts found.' : 'No branch manager accounts found.';
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
