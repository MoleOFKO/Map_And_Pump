<?php
require_once __DIR__ . '/moderator_common.php';

moderator_require_user();
$flash = hq_pull_flash();
$search = trim((string) ($_GET['search'] ?? ''));
$status = strtolower(trim((string) ($_GET['status'] ?? '')));
$allowedStatuses = ['active', 'approved', 'pending', 'suspended', 'removed', 'rejected'];
$status = in_array($status, $allowedStatuses, true) ? $status : '';
$userRows = customer_account_rows($search, $status);
$dbError = hq_db_available() ? null : hq_db_error_message();
$flashType = (string) ($flash['type'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Manage role 6 Customer accounts in the Map &amp; Pump moderator portal.">
    <title>Manage Users — Map &amp; Pump</title>
    <link rel="icon" type="image/png" href="/images/Logo.png"><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&amp;display=swap" rel="stylesheet"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"><link rel="stylesheet" href="/css/Admin.css"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>.mod-flash{border-radius:14px;background:rgba(10,22,44,.82);border:1px solid var(--glass-border)}.hq-status{display:inline-flex;align-items:center;gap:.45rem;color:rgba(255,255,255,.68);font-size:.8rem;white-space:nowrap}.hq-status:before{content:'';width:8px;height:8px;border-radius:50%;display:inline-block}.hq-status-active:before{background:#1be494;box-shadow:0 0 8px #1be494}.hq-status-pending:before{background:#ffc107;box-shadow:0 0 8px #ffc107}.hq-status-suspended:before{background:#ff9f43;box-shadow:0 0 8px #ff9f43}.hq-status-removed:before{background:#ff4d6a;box-shadow:0 0 8px #ff4d6a}</style>
</head>
<body class="background_color" id="background_color">
<?php include __DIR__ . '/includes/moderator_navbar.php'; ?>
<main class="container my-5">
    <?php if ($flash && $flashType): ?><div class="alert mod-flash alert-<?= moderator_escape($flashType) ?> alert-dismissible fade show mb-4" role="alert"><i class="bi <?= $flashType === 'success' ? 'bi-check-circle-fill text-success' : 'bi-exclamation-triangle-fill text-danger' ?> me-2"></i><?= moderator_escape((string) ($flash['message'] ?? '')) ?><button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button></div><?php endif; ?>
    <?php if ($dbError): ?><div class="alert mod-flash alert-danger mb-4"><i class="bi bi-database-exclamation me-2"></i><?= moderator_escape($dbError) ?></div><?php endif; ?>
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3"><div><span class="stat-chip mb-2"><i class="bi bi-person-gear txt-neon-cyan"></i> Customer Administration</span><h1 class="fw-bold m-0 h4 text-white mt-2">Manage Users</h1><p class="text-white-50 m-0 small mt-1">Edit only role 6 accounts whose role name is Customer.</p></div></div>
    <div class="dashboard-card py-3 px-4 mb-4"><form class="row g-3 align-items-center" method="GET" action="manage_users(M).php"><div class="col-12 col-md-7"><div class="input-group"><span class="input-group-text bg-transparent border-end-0" style="border-color:var(--glass-border);color:var(--neon-cyan);border-radius:12px 0 0 12px"><i class="bi bi-search"></i></span><input type="search" class="form-control glass-input border-start-0" placeholder="Search by user name, email, or HQ..." name="search" value="<?= moderator_escape($search) ?>"></div></div><div class="col-12 col-md-3"><select class="form-select glass-select" name="status"><option value="">All Status</option><?php foreach ($allowedStatuses as $option): ?><option value="<?= $option ?>" <?= $status === $option ? 'selected' : '' ?>><?= moderator_escape(hq_status_label($option)) ?></option><?php endforeach; ?></select></div><div class="col-12 col-md-2 d-grid"><button type="submit" class="btn btn-neon">Apply Filter</button></div></form></div>
    <div class="analytics-card"><div class="table-responsive"><table class="table table-dark-custom align-middle m-0"><thead><tr><th>User Account</th><th>Email</th><th>HQ</th><th>Role</th><th>Status</th><th class="text-end">Command</th></tr></thead><tbody>
    <?php if (!$userRows): ?><tr><td colspan="6" class="text-center text-white-50 py-5"><i class="bi bi-person-x fs-3 d-block mb-2"></i>No Customer accounts match the current filter.</td></tr><?php endif; ?>
    <?php foreach ($userRows as $row): $userStatus = (string) ($row['status'] ?? 'pending'); ?><tr><td><div class="d-flex align-items-center gap-3"><div class="rounded-3 d-flex align-items-center justify-content-center fw-bold" style="width:44px;height:44px;background:rgba(0,244,255,.1);border:1px solid rgba(0,244,255,.28);color:var(--neon-cyan)"><?= moderator_escape(hq_initials((string) ($row['user_name'] ?? 'User'))) ?></div><div><div class="fw-semibold text-white"><?= moderator_escape((string) ($row['user_name'] ?? 'Unnamed User')) ?></div><div class="text-white-50 small">User ID: <?= (int) $row['user_id'] ?></div></div></div></td><td class="text-white-50"><?= moderator_escape((string) ($row['email'] ?? 'No email')) ?></td><td class="text-white-50"><?= moderator_escape((string) ($row['headquarter_name'] ?? 'No HQ linked')) ?></td><td class="text-white-50">#<?= (int) $row['role_id'] ?> · <?= moderator_escape((string) $row['role_name']) ?></td><td><span class="hq-status <?= moderator_escape(hq_status_class($userStatus)) ?>"><?= moderator_escape(hq_status_label($userStatus)) ?></span></td><td class="text-end"><div class="d-flex gap-2 justify-content-end flex-wrap"><div class="dropdown"><button type="button" class="btn-action dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="View details"><span class="me-1"><i class="bi bi-eye-fill"></i></span>View details</button><ul class="dropdown-menu dropdown-menu-end"><?php $accountStatus = strtolower((string) ($row['status'] ?? 'pending')); if ($accountStatus === 'pending') { ?><li><form method="POST" action="user_account_action(M).php" class="d-inline"><input type="hidden" name="user_id" value="<?= (int) $row['user_id'] ?>"><input type="hidden" name="action_type" value="approve"><input type="hidden" name="reason" value="Approved after moderator review."><input type="hidden" name="return_to" value="manage_users(M).php"><button type="submit" class="dropdown-item text-start">Approve</button></form></li><li><form method="POST" action="user_account_action(M).php" class="d-inline"><input type="hidden" name="user_id" value="<?= (int) $row['user_id'] ?>"><input type="hidden" name="action_type" value="reject"><input type="hidden" name="reason" value="Rejected after moderator review."><input type="hidden" name="return_to" value="manage_users(M).php"><button type="submit" class="dropdown-item text-start">Reject</button></form></li><?php } else { ?><li><button type="button" class="dropdown-item js-edit-user" title="Edit User" data-bs-toggle="modal" data-bs-target="#editUserModal" data-user-id="<?= (int) $row['user_id'] ?>" data-user-name="<?= moderator_escape((string) $row['user_name']) ?>" data-user-email="<?= moderator_escape((string) $row['email']) ?>">Edit details</button></li><?php } ?></ul></div><button type="button" class="btn-action btn-action-danger js-user-action" title="Suspend / Remove User" data-user-id="<?= (int) $row['user_id'] ?>" data-user-name="<?= moderator_escape((string) $row['user_name']) ?>"><i class="bi bi-trash3-fill"></i> Remove / Suspend</button></div></td></tr><?php endforeach; ?>
    </tbody></table></div></div>
</main>
<div class="modal fade glass-modal" id="editUserModal" tabindex="-1" aria-labelledby="editUserLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title fw-bold" id="editUserLabel"><i class="bi bi-person-gear txt-neon-cyan me-2"></i> Edit Customer User</h5><p class="text-white-50 small mb-0 mt-1">Update role 6 Customer details in the live database.</p></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><form action="user_update_action(M).php" method="POST"><div class="modal-body d-flex flex-column gap-3"><input type="hidden" name="user_id" id="editUserId"><div><label class="form-label text-white-50 small" for="editUserName">User Name <span class="text-danger">*</span></label><input id="editUserName" type="text" name="user_name" class="form-control glass-input" maxlength="225" required></div><div><label class="form-label text-white-50 small" for="editUserEmail">Email <span class="text-danger">*</span></label><input id="editUserEmail" type="email" name="email" class="form-control glass-input" maxlength="255" required></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-3 text-white-50" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-neon">Save User Changes</button></div></form></div></div></div>
<div class="modal fade glass-modal" id="userActionModal" tabindex="-1" aria-labelledby="userActionLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title fw-bold" id="userActionLabel"><i class="bi bi-shield-exclamation text-danger me-2"></i> Suspend or Remove User</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><form action="user_account_action(M).php" method="POST"><div class="modal-body d-flex flex-column gap-3"><input type="hidden" name="user_id" id="userActionId"><input type="hidden" name="return_to" value="manage_users(M).php"><div class="small text-white-50">Target Customer: <span class="txt-neon-cyan fw-semibold" id="userActionName">Selected User</span></div><div><label class="form-label text-white-50 small" for="userActionType">Account action</label><select class="form-select glass-select" name="action_type" id="userActionType" required><option value="suspend">Suspend account</option><option value="remove">Remove account</option></select></div><div><label class="form-label text-white-50 small" for="userActionReason">Reason <span class="text-danger">*</span></label><textarea class="form-control glass-input" name="reason" id="userActionReason" rows="5" maxlength="2000" required></textarea></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-3 text-white-50" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger">Save Action</button></div></form></div></div></div>
<!-- User modals -->
<?php $moderatorActiveNav = 'manage_users'; include __DIR__ . '/includes/moderator_offcanvas.php'; ?>

<footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map &amp; Pump | All Rights Reserved</footer>
<?php include __DIR__ . '/includes/moderator_footer_scripts.php'; ?>

<!-- User details modal (loaded via AJAX) -->
<div class="modal fade glass-modal" id="userDetailsModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg"><div class="modal-content"><div class="modal-header"><h5 class="modal-title fw-bold"><i class="bi bi-person-check txt-neon-cyan me-2"></i> User Details</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body" id="userDetailsBody"><div class="text-center text-white-50 py-5"><span class="spinner-border spinner-border-sm me-2"></span>Loading user details...</div></div></div></div></div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const actionModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('userActionModal'));
    document.querySelectorAll('.js-edit-user').forEach(button => button.addEventListener('click', () => {
        document.getElementById('editUserId').value = button.dataset.userId || '';
        document.getElementById('editUserName').value = button.dataset.userName || '';
        document.getElementById('editUserEmail').value = button.dataset.userEmail || '';
    }));
    document.querySelectorAll('.js-user-action').forEach(button => button.addEventListener('click', () => {
        document.getElementById('userActionId').value = button.dataset.userId || '';
        document.getElementById('userActionName').textContent = button.dataset.userName || 'Selected User';
        document.getElementById('userActionReason').value = '';
        actionModal.show();
    }));

    // User details view (AJAX)
    const detailsModalEl = document.getElementById('userDetailsModal');
    const detailsModal = detailsModalEl ? new bootstrap.Modal(detailsModalEl) : null;
    const detailsBody = document.getElementById('userDetailsBody');
    const esc = value => String(value ?? '').replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character]));
    const date = value => value ? new Date(String(value).replace(' ', 'T')).toLocaleString() : 'Not available';

    document.querySelectorAll('.js-view-user').forEach(button => button.addEventListener('click', async event => {
        event.preventDefault();
        if (!detailsModal) return;
        detailsBody.innerHTML = '<div class="text-center text-white-50 py-5"><span class="spinner-border spinner-border-sm me-2"></span>Loading user details...</div>';
        detailsModal.show();
        try {
            const response = await fetch('user_details(M).php?id=' + encodeURIComponent(button.dataset.userId), { headers: { Accept: 'application/json' } });
            const payload = await response.json();
            if (!response.ok || !payload.success) throw new Error(payload.message || 'Unable to load user details.');
            const u = payload.user || {};
            const actions = payload.actions || [];
            detailsBody.innerHTML = '<div class="row g-4"><div class="col-12 col-md-6"><div class="mod-detail-label">Username</div><div class="mod-detail-value mb-3">'+esc(u.user_name)+'</div><div class="mod-detail-label">Email</div><div class="mod-detail-value mb-3">'+esc(u.email)+'</div><div class="mod-detail-label">Headquarter</div><div class="mod-detail-value mb-3">'+esc(u.headquarter_name || 'No HQ linked')+'</div></div><div class="col-12 col-md-6"><div class="mod-detail-label">Role</div><div class="mod-detail-value mb-3">#'+esc(u.role_id)+' · '+esc(u.role_name || '')+'</div><div class="mod-detail-label">Status</div><div class="mod-detail-value mb-3">'+esc(u.status)+'</div><div class="mod-detail-label">Created / Updated</div><div class="mod-detail-value">'+esc(date(u.created_at))+' / '+esc(date(u.updated_at))+'</div></div><div class="col-12"><div class="mod-detail-label mb-2">Account Action History</div>'+(actions.length ? actions.map(a=>'<div class="border-top border-white border-opacity-10 py-2"><span class="text-danger">'+esc(a.action_type)+'</span> · '+esc(date(a.created_at))+'<br><span class="text-white-50">'+esc(a.reason)+'</span></div>').join('') : '<div class="text-white-50">No action history.</div>')+'</div></div>';
        } catch (error) {
            detailsBody.innerHTML = '<div class="alert alert-danger">'+esc(error.message)+'</div>';
        }
    }));
});
</script>
</body>
</html>
