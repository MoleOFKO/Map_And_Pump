<?php
require_once __DIR__ . '/hq_guard.php';
require_once __DIR__ . '/../conn.php';

$managers = [];
$dbError = '';
$statusMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_manager_status'])) {
    $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $newStatus = filter_input(INPUT_POST, 'new_status', FILTER_SANITIZE_STRING);

    if ($userId && in_array($newStatus, ['active', 'disabled'], true)) {
        try {
            $stmt = $pdo->prepare('UPDATE users SET status = ? WHERE user_id = ? AND COALESCE(business_role_id, role_id) = 4');
            $stmt->execute([$newStatus, $userId]);
            $statusMessage = 'Manager status updated successfully.';
        } catch (PDOException $e) {
            $dbError = $e->getMessage();
        }
    } else {
        $statusMessage = 'Invalid manager status update request.';
    }
}

try {
    $sql = "SELECT u.user_id, u.user_name, u.email, u.status, s.station_id, s.station_name, s.location
            FROM users u
            LEFT JOIN stations s ON s.business_user_id = u.user_id
            WHERE COALESCE(u.business_role_id, u.role_id) = 4
            ORDER BY u.user_id ASC";

    $stmt = $pdo->query($sql);
    $managers = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (PDOException $e) {
    $dbError = $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../../css/HQstyle.css">
</head>
<body>
    <div class="container">
        <h2 class="title">Manage Manager Accounts</h2>
        <p class="subtitle">Control status permissions and access clearance for on-site personnel.</p>

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
            <h3>Manager Roster</h3>
            <table style="width: 100%; border-collapse: collapse; margin-top: 10px;">
                <thead>
                    <tr style="border-bottom: 2px solid var(--border); text-align: left;">
                        <th style="padding: 12px 8px;">Manager Name</th>
                        <th style="padding: 12px 8px;">Manager Email</th>
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
                            <tr style="border-bottom: 1px solid var(--border);">
                                <td style="padding: 12px 8px;"><?= htmlspecialchars($manager['user_name'] ?? 'N/A') ?></td>
                                <td style="padding: 12px 8px;"><?= htmlspecialchars($manager['email'] ?? 'N/A') ?></td>
                                <td style="padding: 12px 8px;"><?= htmlspecialchars($stationText) ?></td>
                                <td style="padding: 12px 8px;"><span style="color: <?= htmlspecialchars($statusColor) ?>; font-weight: bold;"><?= htmlspecialchars($statusLabel) ?></span></td>
                                <td style="padding: 12px 8px; text-align: right;">
                                    <form method="POST" action="" style="display: inline;" class="manager-status-form">
                                        <input type="hidden" name="toggle_manager_status" value="1">
                                        <input type="hidden" name="user_id" value="<?= (int)($manager['user_id'] ?? 0) ?>">
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
                        <tr>
                            <td colspan="5" style="padding: 16px 8px; text-align: center; color: var(--muted);">No manager accounts found.</td>
                        </tr>
                    <?php
endif; ?>
                </tbody>
            </table>
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
    </script>

</body>
<footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map & Pump | All Rights Reserved</footer>
</html>
