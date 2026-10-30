<?php
require_once __DIR__ . '/moderator_common.php';

moderator_require_user();
$flash = hq_pull_flash();
$search = trim((string) ($_GET['search'] ?? ''));
$status = strtolower(trim((string) ($_GET['status'] ?? '')));
$expiryFilter = strtolower(trim((string) ($_GET['expiry'] ?? '')));
if (!in_array($expiryFilter, ['warning', 'expired', 'any'], true)) {
    $expiryFilter = '';
}
$allowedStatuses = customer_account_status_options();
$status = ($status !== '' && in_array($status, $allowedStatuses, true)) ? $status : '';
$userRows = customer_account_rows($search, $status, $expiryFilter);
$dbError = hq_db_available() ? null : hq_db_error_message();
$flashType = (string) ($flash['type'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="View and manage non-HQ accounts in the Map &amp; Pump moderator portal.">
    <title>View/Manage Users — Map &amp; Pump</title>
    <link rel="icon" type="image/png" href="/images/Logo.png"><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&amp;display=swap" rel="stylesheet"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"><link rel="stylesheet" href="/css/Admin.css"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .mod-flash{border-radius:14px;background:rgba(10,22,44,.82);border:1px solid var(--glass-border);color:var(--text-primary)}
        body.background_color.light-mode .mod-flash{background:rgba(255,255,255,.92)}
        .hq-flash{border-radius:14px;background:rgba(10,22,44,.82);border:1px solid var(--glass-border);color:var(--text-primary)}
        .hq-flash.alert-danger{border-color:rgba(255,77,106,.45)}
        .hq-flash.alert-warning{border-color:rgba(255,193,7,.35)}
        body.background_color.light-mode .hq-flash{background:rgba(255,255,255,.9)}
        .user-table-scroll{max-height:min(60vh,520px);overflow:auto}
        .hq-status{display:inline-flex;align-items:center;gap:.45rem;color:rgba(255,255,255,.68);font-size:.8rem;white-space:nowrap}
        body.background_color.light-mode .hq-status{color:var(--text-muted)}
        .hq-status:before{content:'';width:8px;height:8px;border-radius:50%;display:inline-block}
        .hq-status-active:before,.hq-status-approved:before{background:#1be494;box-shadow:0 0 8px #1be494}
        .hq-status-pending:before{background:#ffc107;box-shadow:0 0 8px #ffc107}
        .hq-status-suspended:before{background:#ff9f43;box-shadow:0 0 8px #ff9f43}
        .hq-status-removed:before{background:#ff4d6a;box-shadow:0 0 8px #ff4d6a}
    </style>
</head>
<body class="background_color" id="background_color">
<?php include __DIR__ . '/includes/moderator_navbar.php'; ?>
<main class="container my-5">
    <?php if ($flash && $flashType): ?><div class="alert mod-flash alert-<?= moderator_escape($flashType) ?> alert-dismissible fade show mb-4" role="alert"><i class="bi <?= $flashType === 'success' ? 'bi-check-circle-fill text-success' : 'bi-exclamation-triangle-fill text-danger' ?> me-2"></i><?= moderator_escape((string) ($flash['message'] ?? '')) ?><button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button></div><?php endif; ?>
    <?php if ($dbError): ?><div class="alert mod-flash alert-danger mb-4"><i class="bi bi-database-exclamation me-2"></i><?= moderator_escape($dbError) ?></div><?php endif; ?>
    <?php if (function_exists('expiry_render_page_banners')) { expiry_render_page_banners('moderator', true); } ?>
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3"><div><span class="stat-chip mb-2"><i class="bi bi-person-vcard-fill txt-neon-cyan"></i> User Administration</span><h1 class="fw-bold m-0 h4 text-white mt-2">View/Manage Users</h1></div></div>
    <div class="dashboard-card py-3 px-4 mb-4"><form class="row g-3 align-items-end" method="GET" action="view_users(M).php"><div class="col-12 col-md-4"><div class="input-group"><span class="input-group-text bg-transparent border-end-0" style="border-color:var(--glass-border);color:var(--neon-cyan);border-radius:12px 0 0 12px"><i class="bi bi-search"></i></span><input type="search" class="form-control glass-input border-start-0" placeholder="Search by name or email..." name="search" value="<?= moderator_escape($search) ?>"></div></div><div class="col-6 col-md-2"><select class="form-select glass-select" name="status" aria-label="Status"><option value="">All Status</option><?php foreach ($allowedStatuses as $option): ?><option value="<?= moderator_escape($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= moderator_escape(function_exists('admin_filter_label') ? admin_filter_label($option) : hq_status_label($option)) ?></option><?php endforeach; ?></select></div><div class="col-6 col-md-3"><?= function_exists('expiry_filter_select_html') ? expiry_filter_select_html($expiryFilter, 'modUserExpiryFilter', 'moderator_escape') : '' ?></div><div class="col-12 col-md-3 d-flex gap-2"><button type="button" class="btn btn-neon flex-grow-1 js-clear-filters" title="Clear filters">Clear</button></div></form></div>
    <div class="analytics-card"><div class="table-responsive user-table-scroll"><table class="table table-dark-custom align-middle m-0"><thead><tr><th>User Account</th><th>Email</th><th>HQ</th><th>Role</th><th>Status</th><th class="text-end">Command</th></tr></thead><tbody>
    <?php if (!$userRows): ?><tr><td colspan="6" class="text-center text-white-50 py-5"><i class="bi bi-person-x fs-3 d-block mb-2"></i>No user accounts match the current filter.</td></tr><?php endif; ?>
    <?php foreach ($userRows as $row): $userStatus = (string) ($row['status'] ?? 'pending'); ?><tr><td><div class="d-flex align-items-center gap-3"><div class="rounded-3 d-flex align-items-center justify-content-center fw-bold" style="width:44px;height:44px;background:rgba(0,244,255,.1);border:1px solid rgba(0,244,255,.28);color:var(--neon-cyan)"><?= moderator_escape(hq_initials((string) ($row['user_name'] ?? 'User'))) ?></div><div><div class="fw-semibold text-white"><?= moderator_escape((string) ($row['user_name'] ?? 'Unnamed User')) ?></div><div class="text-white-50 small">User ID: <?= moderator_escape(trim((string) ($row['user_public_id'] ?? '')) ?: 'N/A') ?></div></div></div></td><td class="text-white-50"><?= moderator_escape((string) ($row['email'] ?? 'No email')) ?></td><td class="text-white-50"><?= moderator_escape((string) ($row['headquarter_name'] ?? 'No HQ linked')) ?></td><td class="text-white-50">#<?= (int) ($row['role_id'] ?? 0) ?> · <?= moderator_escape(ucwords(str_replace('_', ' ', (string) ($row['role_name'] ?? 'Customer')))) ?></td><td><span class="hq-status <?= moderator_escape(hq_status_class($userStatus)) ?>"><?= moderator_escape(hq_status_label($userStatus)) ?></span><?php if (function_exists("expiry_badge_html")): ?><div class="mt-1"><?= expiry_badge_html(isset($row["license_last_valid_date"]) ? (string) $row["license_last_valid_date"] : null) ?></div><?php endif; ?></td><td class="text-end"><div class="d-flex gap-2 justify-content-end"><a href="#" class="btn-action js-view-user" title="View User Details" data-user-id="<?= (int) $row['user_id'] ?>"><i class="bi bi-eye-fill"></i></a><?php if ($userStatus === 'pending_verification'): ?><a href="view_requests(M).php?search=<?= rawurlencode((string) ($row['email'] ?? '')) ?>" class="btn-action" title="Open account verification request"><i class="bi bi-arrow-up-right-circle-fill"></i></a><?php else: ?><button type="button" class="btn-action btn-action-danger js-user-action" title="Suspend / Remove / Restore User" data-user-id="<?= (int) $row['user_id'] ?>" data-user-name="<?= moderator_escape((string) $row['user_name']) ?>"><i class="bi bi-gear-fill"></i></button><?php endif; ?></div></td></tr><?php endforeach; ?>
    </tbody></table></div></div>
</main>
<div class="modal fade glass-modal" id="userActionModal" tabindex="-1" aria-labelledby="userActionLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title fw-bold" id="userActionLabel"><i class="bi bi-shield-exclamation text-danger me-2"></i> Suspend / Remove / Restore User</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><form action="user_account_action(M).php" method="POST"><div class="modal-body d-flex flex-column gap-3"><input type="hidden" name="user_id" id="userActionId"><input type="hidden" name="return_to" value="view_users(M).php"><div class="small text-white-50">Target user: <span class="txt-neon-cyan fw-semibold" id="userActionName">Selected User</span></div><div><label class="form-label text-white-50 small" for="userActionType">Account action</label><select class="form-select glass-select" name="action_type" id="userActionType" required><option value="suspend">Suspend account</option><option value="remove">Remove account</option><option value="restore">Restore (approve again)</option></select></div><div><label class="form-label text-white-50 small" for="userActionReason">Reason <span class="text-danger">*</span></label><textarea class="form-control glass-input" name="reason" id="userActionReason" rows="5" maxlength="2000" required></textarea></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-3 text-white-50" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger">Save Action</button></div></form></div></div></div>

<!-- User details modal (loaded via AJAX) -->
<div class="modal fade glass-modal" id="userDetailsModal" tabindex="-1" aria-labelledby="userDetailsLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg"><div class="modal-content"><div class="modal-header"><h5 class="modal-title fw-bold" id="userDetailsLabel"><i class="bi bi-person-check txt-neon-cyan me-2"></i> User Details</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body" id="userDetailsBody"><div class="text-center text-white-50 py-5"><div class="spinner-border spinner-border-sm txt-neon-cyan mb-2" role="status"></div><div>Loading user details…</div></div></div><div class="modal-footer"><button type="button" class="btn btn-neon js-edit-details" data-bs-target="#editUserModal" data-bs-toggle="modal">Edit details</button></div></div></div></div>

<div class="modal fade glass-modal" id="editUserModal" tabindex="-1" aria-labelledby="editUserLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title fw-bold" id="editUserLabel"><i class="bi bi-person-gear txt-neon-cyan me-2"></i> Edit User</h5></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><form action="user_update_action(M).php" method="POST"><div class="modal-body d-flex flex-column gap-3"><input type="hidden" name="user_id" id="editUserId"><input type="hidden" name="return_to" value="view_users(M).php"><div><label class="form-label text-white-50 small" for="editUserName">User Name <span class="text-danger">*</span></label><input id="editUserName" type="text" name="user_name" class="form-control glass-input" maxlength="225" required></div><div><label class="form-label text-white-50 small" for="editUserEmail">Email <span class="text-danger">*</span></label><input id="editUserEmail" type="email" name="email" class="form-control glass-input" maxlength="255" required></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-3 text-white-50" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-neon">Save User Changes</button></div></form></div></div></div>

<?php $moderatorActiveNav = 'view_users'; include __DIR__ . '/includes/moderator_offcanvas.php'; ?><footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map &amp; Pump | All Rights Reserved</footer><?php include __DIR__ . '/includes/moderator_footer_scripts.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('userActionModal'));
    document.addEventListener('click', (event) => {
        const button = event.target.closest('.js-user-action');
        if (!button) return;
        document.getElementById('userActionId').value = button.dataset.userId || '';
        document.getElementById('userActionName').textContent = button.dataset.userName || 'Selected User';
        document.getElementById('userActionReason').value = '';
        modal.show();
    });

    // User details view (AJAX)
    const detailsModalEl = document.getElementById('userDetailsModal');
    const detailsModal = detailsModalEl ? new bootstrap.Modal(detailsModalEl) : null;
    const detailsBody = document.getElementById('userDetailsBody');
    let currentUserDetails = null;
    const esc = value => String(value ?? '').replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character]));
    const date = value => value ? new Date(String(value).replace(' ', 'T')).toLocaleString() : 'Not available';
    const statusClass = status => ({active:'hq-status-active', approved:'hq-status-active', suspended:'hq-status-suspended', removed:'hq-status-removed'}[String(status || '').toLowerCase()] || 'hq-status-pending');
    const statusLabel = status => ({active:'Approved', approved:'Approved', suspended:'Suspended', removed:'Removed', pending:'Pending', pending_verification:'Pending Verification'}[String(status || '').toLowerCase()] || (status ? String(status) : 'Unknown'));
    
    <?= function_exists('expiry_js_detail_helper') ? expiry_js_detail_helper() : 'function expiryDetailAlert(){return "";}' ?>
    const card = (label, value) => '<div class="p-3 rounded-3 h-100" style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);"><div class="mod-detail-label">' + label + '</div><div class="mod-detail-value mt-1 text-break">' + value + '</div></div>';

    document.addEventListener('click', async event => {
        const button = event.target.closest('.js-view-user');
        if (!button) return;
        event.preventDefault();
        if (!detailsModal) return;
        detailsBody.innerHTML = '<div class="text-center text-white-50 py-5"><span class="spinner-border spinner-border-sm me-2"></span>Loading user details...</div>';
        detailsModal.show();
        try {
            const response = await fetch('user_details(M).php?id=' + encodeURIComponent(button.dataset.userId), { headers: { Accept: 'application/json' } });
            const payload = await response.json();
            if (!response.ok || !payload.success) throw new Error(payload.message || 'Unable to load user details.');
            const u = payload.user || {};
            const license = payload.license || null;
            const hq = payload.headquarter || null;
            const actions = payload.actions || [];
            currentUserDetails = u;

            const profile = '<div class="d-flex align-items-center gap-3 mb-4 pb-3 border-bottom border-white border-opacity-10"><div class="rounded-3 d-flex align-items-center justify-content-center fw-bold" style="width:52px;height:52px;background:rgba(0,244,255,.1);border:1px solid rgba(0,244,255,.28);color:var(--neon-cyan);font-size:1.1rem;">' + esc(String(u.user_name || 'U').slice(0, 2).toUpperCase()) + '</div><div><div class="fw-semibold text-white fs-5">' + esc(u.user_name || 'Unnamed user') + '</div><div class="text-white-50 small">User ID ' + esc(u.user_public_id || 'N/A') + ' · <span class="hq-status ' + statusClass(u.status) + '">' + esc(statusLabel(u.status)) + '</span></div></div></div>';
            const accountGrid = '<div class="row g-3 mb-4"><div class="col-12 col-sm-6 col-lg-4">' + card('Email', esc(u.email || '—')) + '</div><div class="col-12 col-sm-6 col-lg-4">' + card('Role', '#' + esc(u.role_id || '—') + ' · ' + esc(u.role_name || 'Unassigned')) + '</div><div class="col-12 col-sm-6 col-lg-4">' + card('Headquarter', esc(u.headquarter_name || 'No HQ linked')) + '</div><div class="col-6 col-sm-4 col-lg-4">' + card('Created', esc(date(u.created_at))) + '</div><div class="col-6 col-sm-4 col-lg-4">' + card('Updated', esc(date(u.updated_at))) + '</div><div class="col-12 col-sm-4 col-lg-4">' + card('Last login', esc(date(u.last_login_at))) + '</div></div>';
            let licenseBlock = '';
            if (license) {
                licenseBlock = '<div class="d-flex align-items-center justify-content-between mb-2"><h6 class="text-white fw-semibold mb-0"><i class="bi bi-card-heading txt-neon-cyan me-2"></i>License</h6></div><div class="row g-3 mb-4"><div class="col-12 col-sm-6 col-lg-4">' + card('License name', esc(license.license_name || '—')) + '</div><div class="col-12 col-sm-6 col-lg-4">' + card('License number', esc(license.license_number || '—')) + '</div><div class="col-6 col-sm-4 col-lg-4">' + card('Phone', esc(license.phone_number || '—')) + '</div><div class="col-6 col-sm-4 col-lg-4">' + card('NRC', esc(license.nrc_number || '—')) + '</div><div class="col-6 col-sm-4 col-lg-4">' + card('Status', esc(license.status || '—')) + '</div><div class="col-6 col-sm-4 col-lg-4">' + card('Date of birth', esc(license.date_of_birth || '—')) + '</div><div class="col-6 col-sm-4 col-lg-4">' + card('Blood type', esc(license.blood_type || '—')) + '</div><div class="col-6 col-sm-4 col-lg-4">' + card('Valid until', esc(license.license_last_valid_date || '—')) + '</div></div>';
            }
            let hqBlock = '';
            if (hq) {
                hqBlock = '<div class="d-flex align-items-center justify-content-between mb-2"><h6 class="text-white fw-semibold mb-0"><i class="bi bi-building txt-neon-cyan me-2"></i>Linked HQ</h6></div><div class="row g-3 mb-4"><div class="col-12 col-sm-6 col-lg-3">' + card('Company', esc(hq.company_name || '—')) + '</div><div class="col-12 col-sm-6 col-lg-3">' + card('Location', esc(hq.location || '—')) + '</div><div class="col-12 col-sm-6 col-lg-3">' + card('HQ email', esc(hq.headquarter_email || '—')) + '</div><div class="col-12 col-sm-6 col-lg-3">' + card('Contact', esc(hq.contact_number || '—')) + '</div></div>';
            }
            const history = '<div class="d-flex align-items-center justify-content-between mb-2"><h6 class="text-white fw-semibold mb-0"><i class="bi bi-clock-history txt-neon-cyan me-2"></i>Account action history</h6><span class="badge rounded-pill" style="background:rgba(0,244,255,.12);color:#7eefff;border:1px solid rgba(0,244,255,.25);">' + actions.length + '</span></div>' + (actions.length ? '<div class="table-responsive"><table class="table mod-modal-table align-middle mb-0"><thead><tr><th>Action</th><th>Reason</th><th>When</th></tr></thead><tbody>' + actions.map(a => '<tr><td><span class="badge text-bg-dark border border-secondary border-opacity-25">' + esc(String(a.action_type || '').toUpperCase()) + '</span></td><td class="text-white-50">' + esc(a.reason || '—') + '</td><td class="text-white-50 small">' + esc(date(a.created_at)) + '</td></tr>').join('') + '</tbody></table></div>' : '<div class="text-white-50 py-3"><i class="bi bi-inbox me-1"></i>No action history.</div>');
            detailsBody.innerHTML = expiryDetailAlert((license && license.license_last_valid_date) || '', 'license') + profile + accountGrid + licenseBlock + hqBlock + history;
        } catch (error) {
            detailsBody.innerHTML = '<div class="alert alert-danger">'+esc(error.message)+'</div>';
        }
    });
    document.addEventListener('click', (event) => {
        const button = event.target.closest('.js-edit-details');
        if (!button) return;
        const u = currentUserDetails || {};
        document.getElementById('editUserId').value = u.user_id || '';
        document.getElementById('editUserName').value = u.user_name || '';
        document.getElementById('editUserEmail').value = u.email || '';
    });
});
</script>
</body>
</html>
