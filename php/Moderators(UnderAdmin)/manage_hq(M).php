<?php
require_once __DIR__ . '/moderator_common.php';

$moderatorUser = moderator_require_user();
$flash = hq_pull_flash();
$search = trim((string) ($_GET['search'] ?? ''));
$region = strtolower(trim((string) ($_GET['region'] ?? '')));
$status = strtolower(trim((string) ($_GET['status'] ?? '')));
$allowedRegions = ['yangon', 'mandalay', 'naypyidaw'];
$allowedStatuses = ['active', 'pending', 'suspended', 'removed'];
$region = in_array($region, $allowedRegions, true) ? $region : '';
$status = in_array($status, $allowedStatuses, true) ? $status : '';
$hqRows = moderator_hq_rows($search, $region, $status);
$dbError = hq_db_available() ? null : hq_db_error_message();
$regionLabel = static fn (string $value): string => match ($value) {
    'yangon' => 'Yangon Region',
    'mandalay' => 'Mandalay Region',
    'naypyidaw' => 'Naypyidaw',
    default => 'All Regions',
};
$flashType = (string) ($flash['type'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Manage Map &amp; Pump headquarters.">
    <title>Manage HQ — Map &amp; Pump</title>
    <link rel="icon" type="image/png" href="/images/Logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&amp;display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="/css/Admin.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .mod-flash{border-radius:14px;background:rgba(10,22,44,.82);border:1px solid var(--glass-border)}
        .hq-status{display:inline-flex;align-items:center;gap:.45rem;color:rgba(255,255,255,.68);font-size:.8rem;white-space:nowrap}
        .hq-status:before{content:'';width:8px;height:8px;border-radius:50%;display:inline-block}
        .hq-status-active:before{background:#1be494;box-shadow:0 0 8px #1be494}.hq-status-pending:before{background:#ffc107;box-shadow:0 0 8px #ffc107}.hq-status-suspended:before{background:#ff9f43;box-shadow:0 0 8px #ff9f43}.hq-status-removed:before{background:#ff4d6a;box-shadow:0 0 8px #ff4d6a}
        .manage-hq-note{color:var(--text-muted);font-size:.78rem;line-height:1.5}
    </style>
</head>
<body class="background_color" id="background_color">
<?php include __DIR__ . '/includes/moderator_navbar.php'; ?>
<main class="container my-5">
    <?php if ($flash && $flashType): ?><div class="alert mod-flash alert-<?= moderator_escape($flashType) ?> alert-dismissible fade show mb-4" role="alert"><i class="bi <?= $flashType === 'success' ? 'bi-check-circle-fill text-success' : 'bi-exclamation-triangle-fill text-danger' ?> me-2"></i><?= moderator_escape((string) ($flash['message'] ?? '')) ?><?php if (!empty($flash['context']['temporary_password'])): ?><div class="mt-2 small">Username: <code><?= moderator_escape((string) $flash['context']['username']) ?></code> · Temporary password: <code><?= moderator_escape((string) $flash['context']['temporary_password']) ?></code></div><?php endif; ?><button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button></div><?php endif; ?>
    <?php if ($dbError): ?><div class="alert mod-flash alert-danger mb-4"><i class="bi bi-database-exclamation me-2"></i><?= moderator_escape($dbError) ?></div><?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div><span class="stat-chip mb-2"><i class="bi bi-buildings-fill txt-neon-cyan"></i> HQ Administration</span><h1 class="fw-bold m-0 h4 text-white mt-2">Manage HQ</h1><p class="text-white-50 m-0 small mt-1">Register and edit only role 3 HQ accounts in the live database.</p></div>
        <button type="button" class="btn btn-neon d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#registerHQModal"><i class="bi bi-building-add"></i> Register New HQ</button>
    </div>

    <div class="dashboard-card py-3 px-4 mb-4"><form class="row g-3 align-items-center" method="GET" action="manage_hq(M).php"><div class="col-12 col-md-5"><div class="input-group"><span class="input-group-text bg-transparent border-end-0" style="border-color:var(--glass-border);color:var(--neon-cyan);border-radius:12px 0 0 12px"><i class="bi bi-search"></i></span><input type="search" class="form-control glass-input border-start-0" placeholder="Search by HQ or owner name..." name="search" value="<?= moderator_escape($search) ?>"></div></div><div class="col-12 col-md-3"><select class="form-select glass-select" name="region"><option value="">All Regions</option><?php foreach ($allowedRegions as $option): ?><option value="<?= $option ?>" <?= $region === $option ? 'selected' : '' ?>><?= moderator_escape($regionLabel($option)) ?></option><?php endforeach; ?></select></div><div class="col-12 col-md-2"><select class="form-select glass-select" name="status"><option value="">All Status</option><?php foreach ($allowedStatuses as $option): ?><option value="<?= $option ?>" <?= $status === $option ? 'selected' : '' ?>><?= moderator_escape(hq_status_label($option)) ?></option><?php endforeach; ?></select></div><div class="col-12 col-md-2 d-grid"><button type="submit" class="btn btn-neon">Apply Filter</button></div></form></div>

    <div class="analytics-card"><div class="table-responsive"><table class="table table-dark-custom align-middle m-0"><thead><tr><th>HQ Entity &amp; Owner</th><th>Role</th><th>Contact</th><th>Location</th><th>License Expiry</th><th>Status</th><th class="text-end">Command</th></tr></thead><tbody>
    <?php if (!$hqRows): ?><tr><td colspan="7" class="text-center text-white-50 py-5"><i class="bi bi-building-x fs-3 d-block mb-2"></i>No HQ accounts match the current filter.</td></tr><?php endif; ?>
    <?php foreach ($hqRows as $row): ?>
        <?php
        $company = (string) ($row['company_name'] ?? 'Unnamed HQ');
        $locationParts = preg_split('/\s+\x{2014}\s+/u', (string) ($row['location'] ?? ''), 2) ?: [];
        $rowRegion = strtolower(trim((string) ($locationParts[0] ?? '')));
        $rowRegion = str_replace(' region', '', $rowRegion);
        $rowRegion = $rowRegion === 'naypyidaw' ? 'naypyidaw' : $rowRegion;
        $rowAddress = (string) ($locationParts[1] ?? ($locationParts[0] ?? ''));
        $expiry = !empty($row['expire_at']) ? substr((string) $row['expire_at'], 0, 10) : '';
        $rowStatus = (string) ($row['account_status'] ?? 'pending');
        ?>
        <tr>
            <td><div class="d-flex align-items-center gap-3"><div class="rounded-3 d-flex align-items-center justify-content-center fw-bold" style="width:44px;height:44px;background:rgba(0,244,255,.1);border:1px solid rgba(0,244,255,.28);color:var(--neon-cyan)"><?= moderator_escape(hq_initials($company)) ?></div><div><div class="fw-semibold text-white"><?= moderator_escape($company) ?></div><div class="text-white-50 small"><?= moderator_escape((string) ($row['owner_name'] ?? 'No owner account')) ?></div></div></div></td>
            <td class="text-white-50">#3 &middot; HQ</td><td><div class="text-white"><?= moderator_escape((string) ($row['contact_number'] ?? 'Not provided')) ?></div><div class="text-white-50 small"><?= moderator_escape((string) ($row['owner_email'] ?? $row['headquarter_email'] ?? 'No email')) ?></div></td>
            <td><div class="text-white"><?= moderator_escape((string) ($locationParts[0] ?? 'Location not available')) ?></div><div class="text-white-50 small"><?= moderator_escape($rowAddress) ?></div></td>
            <td class="text-white-50"><?= moderator_escape($expiry !== '' ? $expiry : 'Not available') ?></td>
            <td><span class="hq-status <?= moderator_escape(hq_status_class($rowStatus)) ?>"><?= moderator_escape(hq_status_label($rowStatus)) ?></span></td>
            <td class="text-end"><button type="button" class="btn-action js-edit-hq" title="Edit HQ" data-bs-toggle="modal" data-bs-target="#editHQModal" data-hq-id="<?= (int) $row['headquarter_id'] ?>" data-hq-name="<?= moderator_escape($company) ?>" data-logo="<?= moderator_escape((string) ($row['company_logo'] ?? '')) ?>" data-contact="<?= moderator_escape((string) ($row['contact_number'] ?? '')) ?>" data-owner-name="<?= moderator_escape((string) ($row['owner_name'] ?? '')) ?>" data-owner-email="<?= moderator_escape((string) ($row['owner_email'] ?? $row['headquarter_email'] ?? '')) ?>" data-region="<?= moderator_escape($rowRegion) ?>" data-address="<?= moderator_escape($rowAddress) ?>" data-license="<?= moderator_escape((string) ($row['business_license'] ?? '')) ?>" data-expire-at="<?= moderator_escape($expiry) ?>"><i class="bi bi-pencil-square"></i></button></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
</main>

<div class="modal fade glass-modal" id="registerHQModal" tabindex="-1" aria-labelledby="registerHQLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title fw-bold" id="registerHQLabel"><i class="bi bi-building-add txt-neon-cyan me-2"></i> Register New Headquarter</h5><p class="text-white-50 small mb-0 mt-1">Submit a pending HQ registration request. The HQ and owner account are created only after approval.</p></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><form action="hq_register_action(M).php" method="POST"><div class="modal-body d-flex flex-column gap-3"><div class="manage-hq-note"><span class="text-danger">*</span> All required fields must be completed. No HQ or owner account is created until the request is approved.</div><div><label class="form-label text-white-50 small" for="registerHqName">Company / HQ Name <span class="text-danger">*</span></label><input id="registerHqName" type="text" name="hq_name" class="form-control glass-input" maxlength="255" required></div><div><label class="form-label text-white-50 small" for="registerLogo">Company Logo URL / Storage Path <span class="text-danger">*</span></label><input id="registerLogo" type="text" name="company_logo" class="form-control glass-input" maxlength="255" placeholder="/uploads/company-logo.png" required></div><div><label class="form-label text-white-50 small" for="registerCompanyEmail">Company Email <span class="text-danger">*</span></label><input id="registerCompanyEmail" type="email" name="headquarter_email" class="form-control glass-input" maxlength="225" required></div><div class="row g-3"><div class="col-12 col-md-6"><label class="form-label text-white-50 small" for="registerOwnerName">Owner Name <span class="text-danger">*</span></label><input id="registerOwnerName" type="text" name="owner_name" class="form-control glass-input" maxlength="225" required></div><div class="col-12 col-md-6"><label class="form-label text-white-50 small" for="registerOwnerEmail">Owner Email <span class="text-danger">*</span></label><input id="registerOwnerEmail" type="email" name="owner_email" class="form-control glass-input" maxlength="225" required></div></div><div><label class="form-label text-white-50 small" for="registerContact">Contact Number <span class="text-danger">*</span></label><input id="registerContact" type="text" name="contact_number" class="form-control glass-input" maxlength="50" required></div><div><label class="form-label text-white-50 small" for="registerRegion">Regional Headquarters Office <span class="text-danger">*</span></label><select id="registerRegion" name="region" class="form-select glass-select" required><option value="">Select region</option><option value="yangon">Yangon Region</option><option value="mandalay">Mandalay Region</option><option value="naypyidaw">Naypyidaw</option></select></div><div><label class="form-label text-white-50 small" for="registerAddress">Physical Address Details <span class="text-danger">*</span></label><textarea id="registerAddress" name="address" rows="3" maxlength="220" class="form-control glass-input" required></textarea></div><div><label class="form-label text-white-50 small" for="registerLicense">Business License <span class="text-danger">*</span></label><input id="registerLicense" type="text" name="business_license" class="form-control glass-input" maxlength="255" required></div><div><label class="form-label text-white-50 small" for="registerExpire">License Expiry Date <span class="text-danger">*</span></label><input id="registerExpire" type="date" name="expire_at" class="form-control glass-input" min="<?= date('Y-m-d') ?>" required></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-3 text-white-50" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-neon">Register Account</button></div></form></div></div></div>

<div class="modal fade glass-modal" id="editHQModal" tabindex="-1" aria-labelledby="editHQLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title fw-bold" id="editHQLabel"><i class="bi bi-pencil-square txt-neon-cyan me-2"></i> Edit HQ</h5><p class="text-white-50 small mb-0 mt-1">Update HQ and owner details in the live database.</p></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><form action="hq_update_action(M).php" method="POST"><input type="hidden" name="headquarter_id" id="editHQId"><div class="modal-body d-flex flex-column gap-3"><div class="manage-hq-note"><span class="text-danger">*</span> Changing the owner email also updates the linked HQ account email.</div><div><label class="form-label text-white-50 small" for="editHqName">Company / HQ Name <span class="text-danger">*</span></label><input id="editHqName" type="text" name="hq_name" class="form-control glass-input" maxlength="255" required></div><div><label class="form-label text-white-50 small" for="editLogo">Company Logo URL / Storage Path <span class="text-danger">*</span></label><input id="editLogo" type="text" name="company_logo" class="form-control glass-input" maxlength="255" required></div><div class="row g-3"><div class="col-12 col-md-6"><label class="form-label text-white-50 small" for="editOwnerName">Owner Name <span class="text-danger">*</span></label><input id="editOwnerName" type="text" name="owner_name" class="form-control glass-input" maxlength="225" required></div><div class="col-12 col-md-6"><label class="form-label text-white-50 small" for="editOwnerEmail">Owner Email <span class="text-danger">*</span></label><input id="editOwnerEmail" type="email" name="owner_email" class="form-control glass-input" maxlength="225" required></div></div><div><label class="form-label text-white-50 small" for="editContact">Contact Number <span class="text-danger">*</span></label><input id="editContact" type="text" name="contact_number" class="form-control glass-input" maxlength="50" required></div><div><label class="form-label text-white-50 small" for="editRegion">Regional Headquarters Office <span class="text-danger">*</span></label><select id="editRegion" name="region" class="form-select glass-select" required><option value="">Select region</option><option value="yangon">Yangon Region</option><option value="mandalay">Mandalay Region</option><option value="naypyidaw">Naypyidaw</option></select></div><div><label class="form-label text-white-50 small" for="editAddress">Physical Address Details <span class="text-danger">*</span></label><textarea id="editAddress" name="address" rows="3" maxlength="220" class="form-control glass-input" required></textarea></div><div><label class="form-label text-white-50 small" for="editLicense">Business License <span class="text-danger">*</span></label><input id="editLicense" type="text" name="business_license" class="form-control glass-input" maxlength="255" required></div><div><label class="form-label text-white-50 small" for="editExpire">License Expiry Date <span class="text-danger">*</span></label><input id="editExpire" type="date" name="expire_at" min="<?= date('Y-m-d') ?>" class="form-control glass-input" required></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-3 text-white-50" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-neon">Save HQ Changes</button></div></form></div></div></div>

<?php if (false): // User controls are rendered only on manage_users(M).php and view_users(M).php. ?>
<div class="modal fade glass-modal" id="userAccountsModal" tabindex="-1" aria-labelledby="userAccountsLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-xl"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title fw-bold" id="userAccountsLabel"><i class="bi bi-people-fill txt-neon-cyan me-2"></i> Linked User Accounts</h5><p class="text-white-50 small mb-0 mt-1" id="userAccountsSubtitle">Loading accounts...</p></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body" id="manageUsersBody"><div class="text-center text-white-50 py-5"><span class="spinner-border spinner-border-sm me-2"></span>Loading user accounts...</div></div><div class="modal-footer"><div class="small text-white-50 me-auto">User controls are available in View Users and Manage Users.</div><button type="button" class="btn btn-outline-secondary rounded-3 text-white-50" data-bs-dismiss="modal">Close</button></div></div></div></div>

<div class="modal fade glass-modal" id="editUserModal" tabindex="-1" aria-labelledby="editUserLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title fw-bold" id="editUserLabel"><i class="bi bi-person-gear txt-neon-cyan me-2"></i> Edit User Account</h5><p class="text-white-50 small mb-0 mt-1" id="editUserSubtitle">Update user details in the live database.</p></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><form action="hq_user_update_action(M).php" method="POST"><div class="modal-body d-flex flex-column gap-3"><input type="hidden" name="user_id" id="editUserId"><input type="hidden" name="headquarter_id" id="editUserHQId"><div><label class="form-label text-white-50 small" for="editUserName">User Name <span class="text-danger">*</span></label><input id="editUserName" type="text" name="user_name" class="form-control glass-input" maxlength="225" required></div><div><label class="form-label text-white-50 small" for="editUserEmail">Email <span class="text-danger">*</span></label><input id="editUserEmail" type="email" name="email" class="form-control glass-input" maxlength="255" required></div><div class="small text-white-50"><i class="bi bi-info-circle me-1 txt-neon-cyan"></i>Status and suspend/remove history are controlled from View HQ &amp; User Accounts.</div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-3 text-white-50" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-neon">Save User Changes</button></div></form></div></div></div>

<?php endif; ?>
<?php $moderatorActiveNav = 'manage_hq'; include __DIR__ . '/includes/moderator_offcanvas.php'; ?><footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map &amp; Pump | All Rights Reserved</footer><?php include __DIR__ . '/includes/moderator_footer_scripts.php'; ?>
<?php if (false): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const userAccountsModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('userAccountsModal'));
    const editUserModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('editUserModal'));
    const usersBody = document.getElementById('manageUsersBody');
    const usersSubtitle = document.getElementById('userAccountsSubtitle');
    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character]));
    const formatDate = value => value ? new Date(String(value).replace(' ', 'T')).toLocaleString() : 'Not available';

    document.querySelectorAll('.js-edit-hq').forEach(button => button.addEventListener('click', () => {
        const fields = {
            editHQId: 'hqId', editHqName: 'hqName', editLogo: 'logo', editContact: 'contact', editOwnerName: 'ownerName', editOwnerEmail: 'ownerEmail', editRegion: 'region', editAddress: 'address', editLicense: 'license', editExpire: 'expireAt'
        };
        Object.entries(fields).forEach(([id, key]) => { const field = document.getElementById(id); if (field) field.value = button.dataset[key] || ''; });
    }));

    document.querySelectorAll('.js-manage-users').forEach(button => button.addEventListener('click', async () => {
        const hqId = button.dataset.hqId || '';
        const hqName = button.dataset.hqName || 'Selected HQ';
        usersSubtitle.textContent = hqName + ' — loading accounts...';
        usersBody.innerHTML = '<div class="text-center text-white-50 py-5"><span class="spinner-border spinner-border-sm me-2"></span>Loading user accounts...</div>';
        userAccountsModal.show();
        try {
            const response = await fetch('hq_details(M).php?id=' + encodeURIComponent(hqId), { headers: { Accept: 'application/json' } });
            const payload = await response.json();
            if (!response.ok || !payload.success) throw new Error(payload.message || 'Unable to load user accounts.');
            const accounts = payload.accounts || [];
            usersSubtitle.textContent = hqName + ' — ' + accounts.length + ' linked account' + (accounts.length === 1 ? '' : 's');
            usersBody.innerHTML = accounts.length ? '<div class="table-responsive"><table class="table table-dark-custom align-middle m-0"><thead><tr><th>User Name</th><th>Email</th><th>Role</th><th>Status</th><th>Updated</th><th class="text-end">Edit</th></tr></thead><tbody>' + accounts.map(account => '<tr><td class="text-white">' + escapeHtml(account.username) + '</td><td class="text-white-50">' + escapeHtml(account.email) + '</td><td class="text-white-50">' + escapeHtml(account.role_name || 'HQ User') + '</td><td><span class="hq-status ' + escapeHtml(account.status === 'active' ? 'hq-status-active' : account.status === 'suspended' ? 'hq-status-suspended' : account.status === 'removed' ? 'hq-status-removed' : 'hq-status-pending') + '">' + escapeHtml(account.status || 'pending') + '</span></td><td class="text-white-50">' + escapeHtml(formatDate(account.updated_at || account.created_at)) + '</td><td class="text-end"><button type="button" class="btn-action js-edit-user" title="Edit User" data-user-id="' + escapeHtml(account.user_id) + '" data-hq-id="' + escapeHtml(hqId) + '" data-user-name="' + escapeHtml(account.username) + '" data-user-email="' + escapeHtml(account.email) + '" data-hq-name="' + escapeHtml(hqName) + '"><i class="bi bi-pencil-square"></i></button></td></tr>').join('') + '</tbody></table></div>' : '<div class="text-center text-white-50 py-5"><i class="bi bi-person-x fs-3 d-block mb-2"></i>No linked user accounts were found.</div>';
        } catch (error) {
            usersSubtitle.textContent = hqName;
            usersBody.innerHTML = '<div class="alert alert-danger">' + escapeHtml(error.message) + '</div>';
        }
    }));

    usersBody.addEventListener('click', event => {
        const button = event.target.closest('.js-edit-user');
        if (!button) return;
        document.getElementById('editUserId').value = button.dataset.userId || '';
        document.getElementById('editUserHQId').value = button.dataset.hqId || '';
        document.getElementById('editUserName').value = button.dataset.userName || '';
        document.getElementById('editUserEmail').value = button.dataset.userEmail || '';
        document.getElementById('editUserSubtitle').textContent = (button.dataset.hqName || 'Selected HQ') + ' — update account details';
        userAccountsModal.hide();
        editUserModal.show();
    });
});
</script>
<?php endif; ?>
</body>
</html>
