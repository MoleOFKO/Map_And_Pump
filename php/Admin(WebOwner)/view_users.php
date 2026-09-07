<?php
require_once __DIR__ . '/user_account_common.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$flash = hq_pull_flash();
$search = trim((string) ($_GET['search'] ?? ''));
$status = strtolower(trim((string) ($_GET['status'] ?? '')));
$allowedStatuses = hq_manageable_statuses();
$status = in_array($status, $allowedStatuses, true) ? $status : '';
$userRows = customer_account_rows($search, $status);
$dbError = hq_db_available() ? null : hq_db_error_message();
$flashType = (string) ($flash['type'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="View and manage non-HQ accounts in the Map &amp; Pump admin portal.">
    <title>View/Manage Users — Map &amp; Pump</title>
    <link rel="icon" type="image/png" href="/images/Logo.png"><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&amp;display=swap" rel="stylesheet"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"><link rel="stylesheet" href="/css/Admin.css"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body class="background_color" id="background_color">
<?php $adminSection = 'view_users'; include __DIR__ . '/admin_section_nav.php'; ?>
<main class="container my-5">
    <?php if ($flash && $flashType): ?><div class="alert hq-flash alert-<?= hq_escape($flashType) ?> alert-dismissible fade show mb-4" role="alert"><i class="bi <?= $flashType === 'success' ? 'bi-check-circle-fill text-success' : 'bi-exclamation-triangle-fill text-danger' ?> me-2"></i><?= hq_escape((string) ($flash['message'] ?? '')) ?><button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button></div><?php endif; ?>
    <?php if ($dbError): ?><div class="alert hq-flash alert-danger mb-4"><i class="bi bi-database-exclamation me-2"></i><?= hq_escape($dbError) ?></div><?php endif; ?>
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3"><div><span class="stat-chip mb-2"><i class="bi bi-person-vcard-fill txt-neon-cyan"></i> User Administration</span><h1 class="fw-bold m-0 h4 text-white mt-2">View/Manage Users</h1><p class="text-white-50 m-0 small mt-1">View and manage approved, active, suspended, or removed non-HQ accounts.</p></div></div>
    <div class="dashboard-card py-3 px-4 mb-4"><form class="row g-3 align-items-center" method="GET" action="view_users.php"><div class="col-12 col-md-7"><div class="input-group"><span class="input-group-text bg-transparent border-end-0" style="border-color:var(--glass-border);color:var(--neon-cyan);border-radius:12px 0 0 12px"><i class="bi bi-search"></i></span><input type="search" class="form-control glass-input border-start-0" placeholder="Search by user name, email, or HQ..." name="search" value="<?= hq_escape($search) ?>"></div></div><div class="col-12 col-md-3"><select class="form-select glass-select" name="status"><option value="">All Status</option><?php foreach ($allowedStatuses as $option): ?><option value="<?= $option ?>" <?= $status === $option ? 'selected' : '' ?>><?= hq_escape(hq_status_label($option)) ?></option><?php endforeach; ?></select></div><div class="col-12 col-md-2 d-grid"><button type="submit" class="btn btn-neon">Apply Filter</button></div></form></div>
    <div class="analytics-card"><div class="table-responsive"><table class="table table-dark-custom align-middle m-0"><thead><tr><th>User Account</th><th>Email</th><th>HQ</th><th>Role</th><th>Status</th><th class="text-end">Command</th></tr></thead><tbody>
    <?php if (!$userRows): ?><tr><td colspan="6" class="text-center text-white-50 py-5"><i class="bi bi-person-x fs-3 d-block mb-2"></i>No user accounts match the current filter.</td></tr><?php endif; ?>
    <?php foreach ($userRows as $row): $userStatus = (string) ($row['status'] ?? 'pending'); ?><tr><td><div class="d-flex align-items-center gap-3"><div class="rounded-3 d-flex align-items-center justify-content-center fw-bold" style="width:44px;height:44px;background:rgba(0,244,255,.1);border:1px solid rgba(0,244,255,.28);color:var(--neon-cyan)"><?= hq_escape(hq_initials((string) ($row['user_name'] ?? 'User'))) ?></div><div><div class="fw-semibold text-white"><?= hq_escape((string) ($row['user_name'] ?? 'Unnamed User')) ?></div><div class="text-white-50 small">User ID: <?= (int) $row['user_id'] ?></div></div></div></td><td class="text-white-50"><?= hq_escape((string) ($row['email'] ?? 'No email')) ?></td><td class="text-white-50"><?= hq_escape((string) ($row['headquarter_name'] ?? 'No HQ linked')) ?></td><td class="text-white-50">#<?= (int) $row['role_id'] ?> · <?= hq_escape((string) $row['role_name']) ?></td><td><span class="hq-status <?= hq_escape(hq_status_class($userStatus)) ?>"><?= hq_escape(hq_status_label($userStatus)) ?></span></td><td class="text-end"><div class="d-flex gap-2 justify-content-end"><a href="#" class="btn-action js-view-user" title="View User Details" data-user-id="<?= (int) $row['user_id'] ?>"><i class="bi bi-eye-fill"></i></a><button type="button" class="btn-action btn-action-danger js-user-action" title="Suspend/Remove User" data-user-id="<?= (int) $row['user_id'] ?>" data-user-name="<?= hq_escape((string) $row['user_name']) ?>"><i class="bi bi-trash3-fill"></i></button></div></td></tr><?php endforeach; ?>
    </tbody></table></div></div>
</main>
<div class="modal fade glass-modal" id="userActionModal" tabindex="-1" aria-labelledby="userActionLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title fw-bold" id="userActionLabel"><i class="bi bi-shield-exclamation text-danger me-2"></i> Suspend or Remove User</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><form action="user_account_action.php" method="POST"><div class="modal-body d-flex flex-column gap-3"><input type="hidden" name="user_id" id="userActionId"><input type="hidden" name="return_to" value="view_users.php"><div class="small text-white-50">Target user: <span class="txt-neon-cyan fw-semibold" id="userActionName">Selected User</span></div><div><label class="form-label text-white-50 small" for="userActionType">Account action</label><select class="form-select glass-select" name="action_type" id="userActionType" required><option value="suspend">Suspend account</option><option value="remove">Remove account</option></select></div><div><label class="form-label text-white-50 small" for="userActionReason">Reason <span class="text-danger">*</span></label><textarea class="form-control glass-input" name="reason" id="userActionReason" rows="5" maxlength="2000" required></textarea></div><div class="small text-white-50">The reason is saved to user action history. Email delivery is currently a placeholder until SMTP is configured.</div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-3 text-white-50" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger">Save Action</button></div></form></div></div></div>

<!-- User details modal (loaded via AJAX) -->
<div class="modal fade glass-modal" id="userDetailsModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg"><div class="modal-content"><div class="modal-header"><h5 class="modal-title fw-bold"><i class="bi bi-person-check txt-neon-cyan me-2"></i> User Details</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body" id="userDetailsBody"><div class="text-center text-white-50 py-5"><span class="spinner-border spinner-border-sm me-2"></span>Loading user details...</div></div><div class="modal-footer"><button type="button" class="btn btn-neon js-edit-details" data-bs-target="#editUserModal" data-bs-toggle="modal">Edit details</button></div></div></div></div>

<div class="modal fade glass-modal" id="editUserModal" tabindex="-1" aria-labelledby="editUserLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title fw-bold" id="editUserLabel"><i class="bi bi-person-gear txt-neon-cyan me-2"></i> Edit User</h5><p class="text-white-50 small mb-0 mt-1">Update this account's basic details.</p></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><form action="user_update_action.php" method="POST"><div class="modal-body d-flex flex-column gap-3"><input type="hidden" name="user_id" id="editUserId"><input type="hidden" name="return_to" value="view_users.php"><div><label class="form-label text-white-50 small" for="editUserName">User Name <span class="text-danger">*</span></label><input id="editUserName" type="text" name="user_name" class="form-control glass-input" maxlength="225" required></div><div><label class="form-label text-white-50 small" for="editUserEmail">Email <span class="text-danger">*</span></label><input id="editUserEmail" type="email" name="email" class="form-control glass-input" maxlength="255" required></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-3 text-white-50" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-neon">Save User Changes</button></div></form></div></div></div>

<footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map &amp; Pump | All Rights Reserved</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="/js/Admin.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('userActionModal'));
    document.querySelectorAll('.js-user-action').forEach(button => button.addEventListener('click', () => {
        document.getElementById('userActionId').value = button.dataset.userId || '';
        document.getElementById('userActionName').textContent = button.dataset.userName || 'Selected User';
        document.getElementById('userActionReason').value = '';
        modal.show();
    }));

    // User details view (AJAX)
    const detailsModalEl = document.getElementById('userDetailsModal');
    const detailsModal = detailsModalEl ? new bootstrap.Modal(detailsModalEl) : null;
    const detailsBody = document.getElementById('userDetailsBody');
    let currentUserDetails = null;
    const esc = value => String(value ?? '').replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character]));
    const date = value => value ? new Date(String(value).replace(' ', 'T')).toLocaleString() : 'Not available';

    const statusClass = (status) => ({active:'hq-status-active', approved:'hq-status-active', suspended:'hq-status-suspended', removed:'hq-status-removed'}[String(status || '').toLowerCase()] || 'hq-status-pending');
    const statusLabel = (status) => ({active:'Active', approved:'Approved', suspended:'Suspended', removed:'Removed', pending:'Pending', pending_verification:'Pending Verification'}[String(status || '').toLowerCase()] || (status ? String(status) : 'Unknown'));
    const card = (label, value) =>
        '<div class="p-3 rounded-3 h-100" style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);">' +
        '<div class="mod-detail-label">' + label + '</div>' +
        '<div class="mod-detail-value mt-1 text-break">' + value + '</div></div>';

    document.querySelectorAll('.js-view-user').forEach(button => button.addEventListener('click', async event => {
        event.preventDefault();
        if (!detailsModal) return;
        detailsBody.innerHTML = '<div class="text-center text-white-50 py-5"><span class="spinner-border spinner-border-sm me-2"></span>Loading user details...</div>';
        detailsModal.show();
        try {
            const response = await fetch('user_details.php?id=' + encodeURIComponent(button.dataset.userId), { headers: { Accept: 'application/json' }, cache: 'no-store' });
            const payload = await response.json();
            if (!response.ok || !payload.success) throw new Error(payload.message || 'Unable to load user details.');
            const u = payload.user || {};
            const license = payload.license || null;
            const hq = payload.headquarter || null;
            const actions = payload.actions || [];
            currentUserDetails = u;

            const profile =
                '<div class="d-flex align-items-center gap-3 mb-4 pb-3 border-bottom border-white border-opacity-10">' +
                '<div class="rounded-3 d-flex align-items-center justify-content-center fw-bold" style="width:52px;height:52px;background:rgba(0,244,255,.1);border:1px solid rgba(0,244,255,.28);color:var(--neon-cyan);font-size:1.1rem;">' +
                esc(String(u.user_name || 'U').slice(0, 2).toUpperCase()) +
                '</div><div><div class="fw-semibold text-white fs-5">' + esc(u.user_name || 'Unnamed user') + '</div>' +
                '<div class="text-white-50 small">User ID ' + esc(u.user_id || '') + ' · <span class="hq-status ' + statusClass(u.status) + '">' + esc(statusLabel(u.status)) + '</span></div></div></div>';

            const accountGrid =
                '<div class="row g-3 mb-4">' +
                '<div class="col-12 col-md-6">' + card('Email', esc(u.email || '—')) + '</div>' +
                '<div class="col-12 col-md-6">' + card('Role', '#' + esc(u.role_id || '—') + ' · ' + esc(u.role_name || 'Unassigned')) + '</div>' +
                '<div class="col-12 col-md-6">' + card('Headquarter', esc(u.headquarter_name || 'No HQ linked')) + '</div>' +
                '<div class="col-6 col-md-3">' + card('Created', esc(date(u.created_at))) + '</div>' +
                '<div class="col-6 col-md-3">' + card('Updated', esc(date(u.updated_at))) + '</div>' +
                '<div class="col-12 col-md-6">' + card('Last login', esc(date(u.last_login_at))) + '</div>' +
                '</div>';

            let licenseBlock = '';
            if (license) {
                licenseBlock =
                    '<div class="d-flex align-items-center justify-content-between mb-2"><h6 class="text-white fw-semibold mb-0"><i class="bi bi-card-heading txt-neon-cyan me-2"></i>License</h6></div>' +
                    '<div class="row g-3 mb-4">' +
                    '<div class="col-12 col-md-6">' + card('License name', esc(license.license_name || '—')) + '</div>' +
                    '<div class="col-12 col-md-6">' + card('License number', esc(license.license_number || '—')) + '</div>' +
                    '<div class="col-6 col-md-4">' + card('Phone', esc(license.phone_number || '—')) + '</div>' +
                    '<div class="col-6 col-md-4">' + card('NRC', esc(license.nrc_number || '—')) + '</div>' +
                    '<div class="col-6 col-md-4">' + card('Status', esc(license.status || '—')) + '</div>' +
                    '<div class="col-6 col-md-4">' + card('Date of birth', esc(license.date_of_birth || '—')) + '</div>' +
                    '<div class="col-6 col-md-4">' + card('Blood type', esc(license.blood_type || '—')) + '</div>' +
                    '<div class="col-6 col-md-4">' + card('Valid until', esc(license.license_last_valid_date || '—')) + '</div>' +
                    '</div>';
            }

            let hqBlock = '';
            if (hq) {
                hqBlock =
                    '<div class="d-flex align-items-center justify-content-between mb-2"><h6 class="text-white fw-semibold mb-0"><i class="bi bi-building txt-neon-cyan me-2"></i>Linked HQ</h6></div>' +
                    '<div class="row g-3 mb-4">' +
                    '<div class="col-12 col-md-6">' + card('Company', esc(hq.company_name || '—')) + '</div>' +
                    '<div class="col-12 col-md-6">' + card('Location', esc(hq.location || '—')) + '</div>' +
                    '<div class="col-12 col-md-6">' + card('HQ email', esc(hq.headquarter_email || '—')) + '</div>' +
                    '<div class="col-12 col-md-6">' + card('Contact', esc(hq.contact_number || '—')) + '</div>' +
                    '</div>';
            }

            const history =
                '<div class="d-flex align-items-center justify-content-between mb-2"><h6 class="text-white fw-semibold mb-0"><i class="bi bi-clock-history txt-neon-cyan me-2"></i>Account action history</h6>' +
                '<span class="badge rounded-pill" style="background:rgba(0,244,255,.12);color:#7eefff;border:1px solid rgba(0,244,255,.25);">' + actions.length + '</span></div>' +
                (actions.length
                    ? '<div class="table-responsive"><table class="table hq-modal-table align-middle mb-0"><thead><tr><th>Action</th><th>Reason</th><th>When</th></tr></thead><tbody>' +
                      actions.map(a =>
                          '<tr><td><span class="badge text-bg-dark border border-secondary border-opacity-25">' + esc(String(a.action_type || '').toUpperCase()) + '</span></td>' +
                          '<td class="text-white-50">' + esc(a.reason || '—') + '</td>' +
                          '<td class="text-white-50 small">' + esc(date(a.created_at)) + '</td></tr>'
                      ).join('') +
                      '</tbody></table></div>'
                    : '<div class="text-white-50 py-3"><i class="bi bi-inbox me-1"></i>No action history.</div>');

            detailsBody.innerHTML = profile + accountGrid + licenseBlock + hqBlock + history;
        } catch (error) {
            detailsBody.innerHTML = '<div class="alert alert-danger">'+esc(error.message)+'</div>';
        }
    }));

    document.querySelectorAll('.js-edit-details').forEach(button => button.addEventListener('click', () => {
        const u = currentUserDetails || {};
        document.getElementById('editUserId').value = u.user_id || '';
        document.getElementById('editUserName').value = u.user_name || '';
        document.getElementById('editUserEmail').value = u.email || '';
    }));
});
</script>
</body>
</html>

