<?php
require_once __DIR__ . '/moderator_common.php';

$moderatorUser = moderator_require_user();
$flash = hq_pull_flash();
$search = trim((string) ($_GET['search'] ?? ''));
$region = strtolower(trim((string) ($_GET['region'] ?? '')));
$status = strtolower(trim((string) ($_GET['status'] ?? '')));

// Filter keywords from live data (not fixed placeholders).
$allowedRegions = function_exists('admin_filter_hq_regions') ? admin_filter_hq_regions() : [];
$allowedStatuses = function_exists('admin_filter_user_statuses')
    ? admin_filter_user_statuses('u.headquarter_id IS NOT NULL')
    : hq_manageable_statuses();
foreach (['approved', 'active', 'pending', 'suspended', 'removed'] as $fallbackStatus) {
    if (!in_array($fallbackStatus, $allowedStatuses, true)) {
        $allowedStatuses[] = $fallbackStatus;
    }
}
sort($allowedStatuses);

if ($status !== '' && !in_array($status, $allowedStatuses, true)) {
    $status = '';
}
// Region may be free-text LIKE even if not in the distinct list.
$hqRows = moderator_hq_rows($search, $region, $status);
$dbError = hq_db_available() ? null : hq_db_error_message();
$regionLabel = static function (string $value): string {
    return function_exists('admin_filter_label') ? admin_filter_label($value) : ucwords($value);
};
$flashType = (string) ($flash['type'] ?? '');
$filtersActive = ($search !== '' || $region !== '' || $status !== '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View/Manage HQ — Map &amp; Pump</title>
    <link rel="icon" type="image/png" href="/images/Logo.png"><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&amp;display=swap" rel="stylesheet"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"><link rel="stylesheet" href="/css/Admin.css"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .mod-flash{border-radius:14px;background:rgba(10,22,44,.82);border:1px solid var(--glass-border);color:var(--text-primary)}
        body.background_color.light-mode .mod-flash{background:rgba(255,255,255,.92)}
        .mod-detail-label{color:var(--text-muted);font-size:.72rem;text-transform:uppercase;letter-spacing:.07em}
        .mod-detail-value{color:var(--text-primary);word-break:break-word}
        .mod-modal-table{--bs-table-bg:transparent;--bs-table-color:var(--text-primary);--bs-table-border-color:var(--glass-border)}
        .mod-modal-table th{color:var(--text-muted);font-size:.72rem;text-transform:uppercase}
        .hq-table-scroll{max-height:min(60vh,520px);overflow:auto}
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
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3"><div><span class="stat-chip mb-2"><i class="bi bi-building-fill txt-neon-cyan"></i> HQ Administration</span><h1 class="fw-bold m-0 h4 text-white mt-2">View/Manage HQ</h1><p class="text-white-50 m-0 small mt-1">View HQ records. The status filter reflects the linked owner's account state.</p></div></div>

            <div class="dashboard-card py-3 px-4 mb-4"><form class="row g-3 align-items-center" method="GET" action="view_hq_and_user_account(M).php"><div class="col-12 col-md-4"><div class="input-group"><span class="input-group-text bg-transparent border-end-0" style="border-color:var(--glass-border);color:var(--neon-cyan);border-radius:12px 0 0 12px"><i class="bi bi-search"></i></span><input type="search" class="form-control glass-input border-start-0" placeholder="Search by HQ or owner name..." name="search" value="<?= moderator_escape($search) ?>"></div></div><div class="col-12 col-md-3"><select class="form-select glass-select" name="region"><option value="">All Regions</option><?php foreach ($allowedRegions as $option): ?><option value="<?= moderator_escape($option) ?>" <?= $region === $option ? 'selected' : '' ?>><?= moderator_escape($regionLabel($option)) ?></option><?php endforeach; ?></select></div><div class="col-12 col-md-2"><select class="form-select glass-select" name="status" aria-label="Owner account status"><option value="">All Owner Account Statuses</option><?php foreach ($allowedStatuses as $option): ?><option value="<?= moderator_escape($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= moderator_escape(function_exists('admin_filter_label') ? admin_filter_label($option) : hq_status_label($option)) ?></option><?php endforeach; ?></select></div><div class="col-12 col-md-3 d-flex gap-2"><button type="submit" class="btn btn-neon flex-grow-1">Apply Filter</button><?php if ($filtersActive): ?><a href="view_hq_and_user_account(M).php" class="btn btn-outline-secondary text-white-50 border-secondary border-opacity-25" title="Clear filters">Clear</a><?php endif; ?></div></form></div>

    <div class="analytics-card"><div class="table-responsive hq-table-scroll"><table class="table table-dark-custom align-middle m-0"><thead><tr><th>HQ Entity &amp; Owner</th><th>Role</th><th>Stations</th><th>Headquarters Location</th><th>Owner Account Status</th><th class="text-end">Command</th></tr></thead><tbody>
    <?php if (!$hqRows): ?><tr><td colspan="6" class="text-center text-white-50 py-5"><i class="bi bi-building-x fs-3 d-block mb-2"></i>No HQ accounts match the current filter.</td></tr><?php endif; ?>
    <?php foreach ($hqRows as $row): ?><?php $company = (string) ($row['company_name'] ?? 'Unnamed HQ'); $location = preg_split('/\s+[—-]\s+/u', (string) ($row['location'] ?? ''), 2) ?: []; $rowStatus = (string) ($row['account_status'] ?? 'pending'); $stationCount = (int) ($row['station_count'] ?? 0); ?><tr><td><div class="d-flex align-items-center gap-3"><div class="rounded-3 d-flex align-items-center justify-content-center fw-bold" style="width:44px;height:44px;background:rgba(0,244,255,.1);border:1px solid rgba(0,244,255,.28);color:var(--neon-cyan)"><?= moderator_escape(hq_initials($company)) ?></div><div><div class="fw-semibold text-white"><?= moderator_escape($company) ?></div><div class="text-white-50 small">Owner: <?= moderator_escape((string) ($row['owner_name'] ?? 'No owner account')) ?> (<?= moderator_escape((string) ($row['owner_email'] ?? 'No email available')) ?>)</div></div></div></td><td class="text-white-50">#3 &middot; HQ</td><td class="text-white-50"><?= $stationCount ?></td><td><div class="text-white"><?= moderator_escape((string) ($location[0] ?? 'Location not available')) ?></div><div class="text-white-50 small"><?= moderator_escape((string) ($location[1] ?? ($location[0] ?? 'Location not available'))) ?></div></td><td><span class="hq-status <?= moderator_escape(hq_status_class($rowStatus)) ?>"><?= moderator_escape(hq_status_label($rowStatus)) ?></span></td><td class="text-end"><div class="d-flex gap-2 justify-content-end"><a href="#" class="btn-action js-view-hq" title="View HQ Details" data-hq-id="<?= (int) $row['headquarter_id'] ?>"><i class="bi bi-eye-fill"></i></a><a href="#" class="btn-action btn-action-danger js-account-action" title="Suspend / Remove / Restore" data-hq-id="<?= (int) $row['headquarter_id'] ?>" data-hq-name="<?= moderator_escape($company) ?>"><i class="bi bi-shield-exclamation"></i></a></div></td></tr><?php endforeach; ?>
    </tbody></table></div></div>
</main>

<div class="modal fade glass-modal" id="hqDetailsModal" tabindex="-1" aria-labelledby="hqDetailsModalLabel" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title fw-bold" id="hqDetailsModalLabel"><i class="bi bi-building txt-neon-cyan me-2"></i> HQ Details</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body" id="hqDetailsBody"><div class="text-center text-white-50 py-5"><div class="spinner-border spinner-border-sm txt-neon-cyan mb-2" role="status"></div><div>Loading HQ details…</div></div></div><div class="modal-footer"><button type="button" class="btn btn-neon js-edit-hq-details" data-bs-target="#editHQModal" data-bs-toggle="modal">Edit details</button></div></div></div></div>
<div class="modal fade glass-modal" id="accountActionModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title fw-bold"><i class="bi bi-shield-exclamation text-danger me-2"></i> Suspend / Remove / Restore HQ</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><form action="hq_account_action(M).php" method="POST"><div class="modal-body"><input type="hidden" name="headquarter_id" id="actionHQId"><div class="small text-white-50 mb-3">Target HQ: <span class="txt-neon-cyan fw-semibold" id="actionHQName">—</span></div><label class="form-label text-white-50 small" for="actionType">Action</label><select id="actionType" name="action_type" class="form-select glass-select mb-3" required><option value="suspend">Suspend account</option><option value="remove">Remove account</option><option value="restore">Restore (approve again)</option></select><label class="form-label text-white-50 small" for="actionReason">Reason <span class="text-danger">*</span></label><textarea id="actionReason" name="reason" class="form-control glass-input" rows="5" maxlength="2000" required></textarea><div class="small text-white-50 mt-3">The reason is recorded and email delivery uses the configured placeholder until SMTP is added.</div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-3 text-white-50" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger">Save Action</button></div></form></div></div></div>
<div class="modal fade glass-modal" id="editHQModal" tabindex="-1" aria-labelledby="editHQLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title fw-bold" id="editHQLabel"><i class="bi bi-pencil-square txt-neon-cyan me-2"></i> Edit HQ</h5><p class="text-white-50 small mb-0 mt-1">Update HQ and owner details in the live database.</p></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><form action="hq_update_action(M).php" method="POST"><input type="hidden" name="headquarter_id" id="editHQId"><input type="hidden" name="return_to" value="view_hq_and_user_account(M).php"><div class="modal-body d-flex flex-column gap-3"><div class="manage-hq-note text-white-50 small"><span class="text-danger">*</span> Changing the owner email also updates the linked HQ account email.</div><div><label class="form-label text-white-50 small" for="editHqName">Company / HQ Name <span class="text-danger">*</span></label><input id="editHqName" type="text" name="hq_name" class="form-control glass-input" maxlength="255" required></div><div><label class="form-label text-white-50 small" for="editLogo">Company Logo URL / Storage Path <span class="text-danger">*</span></label><input id="editLogo" type="text" name="company_logo" class="form-control glass-input" maxlength="255" required></div><div class="row g-3"><div class="col-12 col-md-6"><label class="form-label text-white-50 small" for="editOwnerName">Owner Name <span class="text-danger">*</span></label><input id="editOwnerName" type="text" name="owner_name" class="form-control glass-input" maxlength="225" required></div><div class="col-12 col-md-6"><label class="form-label text-white-50 small" for="editOwnerEmail">Owner Email <span class="text-danger">*</span></label><input id="editOwnerEmail" type="email" name="owner_email" class="form-control glass-input" maxlength="225" required></div></div><div><label class="form-label text-white-50 small" for="editContact">Contact Number <span class="text-danger">*</span></label><input id="editContact" type="text" name="contact_number" class="form-control glass-input" maxlength="50" required></div><div><label class="form-label text-white-50 small" for="editRegion">Regional Headquarters Office <span class="text-danger">*</span></label><select id="editRegion" name="region" class="form-select glass-select" required><option value="">Select region</option><?php
$editRegions = $allowedRegions ?: ['yangon', 'mandalay', 'naypyidaw'];
foreach ($editRegions as $option): ?><option value="<?= moderator_escape($option) ?>"><?= moderator_escape($regionLabel($option)) ?></option><?php endforeach; ?></select></div><div><label class="form-label text-white-50 small" for="editAddress">Physical Address Details <span class="text-danger">*</span></label><textarea id="editAddress" name="address" rows="3" maxlength="220" class="form-control glass-input" required></textarea></div><div><label class="form-label text-white-50 small" for="editLicense">Business License <span class="text-danger">*</span></label><input id="editLicense" type="text" name="business_license" class="form-control glass-input" maxlength="255" required></div><div><label class="form-label text-white-50 small" for="editExpire">License Expiry Date <span class="text-danger">*</span></label><input id="editExpire" type="date" name="expire_at" min="<?= date('Y-m-d') ?>" class="form-control glass-input" required></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-3 text-white-50" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-neon">Save HQ Changes</button></div></form></div></div></div>

<?php $moderatorActiveNav = 'view_hq'; include __DIR__ . '/includes/moderator_offcanvas.php'; ?><footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map &amp; Pump | All Rights Reserved</footer><?php include __DIR__ . '/includes/moderator_footer_scripts.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const detailModal = new bootstrap.Modal(document.getElementById('hqDetailsModal'));
    const actionModal = new bootstrap.Modal(document.getElementById('accountActionModal'));
    const detailsBody = document.getElementById('hqDetailsBody');
    const detailsTitle = document.getElementById('hqDetailsModalLabel');
    let currentHQDetails = null;
    const esc = value => String(value ?? '').replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character]));
    const date = value => value ? new Date(String(value).replace(' ', 'T')).toLocaleString() : 'Not available';
    const statusClass = status => ({active:'hq-status-active', approved:'hq-status-active', suspended:'hq-status-suspended', removed:'hq-status-removed'}[String(status || '').toLowerCase()] || 'hq-status-pending');
    const statusLabel = status => ({active:'Approved', approved:'Approved', suspended:'Suspended', removed:'Removed', pending:'Pending Verification', pending_verification:'Pending Verification'}[String(status || '').toLowerCase()] || (status ? String(status) : 'Unknown'));
    const stationStatusLabel = status => ({active:'Active', approved:'Active', decommissioned:'Decommissioned', suspended:'Suspended', removed:'Removed', offline:'Offline'}[String(status || '').toLowerCase()] || (status ? String(status) : 'Unknown'));
    const emptyRow = (cols, message) => '<tr><td colspan="' + cols + '" class="text-center text-white-50 py-4"><i class="bi bi-inbox me-1"></i>' + esc(message) + '</td></tr>';
    const sectionTitle = (icon, title, count) => '<div class="d-flex align-items-center justify-content-between mb-2 mt-1"><h6 class="text-white fw-semibold mb-0"><i class="bi ' + icon + ' txt-neon-cyan me-2"></i>' + title + '</h6><span class="badge rounded-pill" style="background:rgba(0,244,255,.12);color:#7eefff;border:1px solid rgba(0,244,255,.25);font-weight:600;">' + count + '</span></div>';
    const renderDetails = payload => {
        const hq = payload.hq || {};
        currentHQDetails = hq;
        const accounts = payload.accounts || [];
        const stations = payload.stations || [];
        const actions = payload.actions || [];
        detailsTitle.innerHTML = '<i class="bi bi-building txt-neon-cyan me-2"></i>' + esc(hq.company_name || 'HQ Details');
        const registeredDay = String(hq.created_at || '').slice(0, 10);
        const expiryDay = String(hq.expire_at || '').slice(0, 10);
        const currentDate = new Date();
        const today = [currentDate.getFullYear(), String(currentDate.getMonth() + 1).padStart(2, '0'), String(currentDate.getDate()).padStart(2, '0')].join('-');
        const licenseDateWarning = registeredDay && expiryDay && expiryDay <= registeredDay
            ? '<div class="alert alert-warning py-2 mb-3" role="status"><i class="bi bi-exclamation-triangle-fill me-2"></i>License expiry must be after registration. Correct this date from Edit details using the current business license.</div>'
            : (expiryDay && expiryDay < today ? '<div class="alert alert-warning py-2 mb-3" role="status"><i class="bi bi-exclamation-triangle-fill me-2"></i>This business license has expired. Review the current license date.</div>' : '');
        const metaCards = '<div class="row g-3 mb-4">' +
            '<div class="col-12 col-md-6"><div class="p-3 rounded-3" style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);"><div class="mod-detail-label">HQ location</div><div class="mod-detail-value mt-1">' + esc(hq.location || 'Not available') + '</div></div></div>' +
            '<div class="col-6 col-md-3"><div class="p-3 rounded-3 h-100" style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);"><div class="mod-detail-label">Contact</div><div class="mod-detail-value mt-1">' + esc(hq.contact_number || '—') + '</div></div></div>' +
            '<div class="col-6 col-md-3"><div class="p-3 rounded-3 h-100" style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);"><div class="mod-detail-label">Email</div><div class="mod-detail-value mt-1 text-break">' + esc(hq.headquarter_email || '—') + '</div></div></div>' +
            '<div class="col-6 col-md-3"><div class="p-3 rounded-3 h-100" style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);"><div class="mod-detail-label">Owner</div><div class="mod-detail-value mt-1">' + esc(hq.owner_name || '—') + '</div></div></div>' +
            '<div class="col-6 col-md-3"><div class="p-3 rounded-3 h-100" style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);"><div class="mod-detail-label">Registered</div><div class="mod-detail-value mt-1">' + esc(date(hq.created_at)) + '</div></div></div>' +
            '<div class="col-6 col-md-3"><div class="p-3 rounded-3 h-100" style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);"><div class="mod-detail-label">License expiry</div><div class="mod-detail-value mt-1">' + esc(date(hq.expire_at)) + '</div></div></div>' +
            '<div class="col-6 col-md-3"><div class="p-3 rounded-3 h-100" style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);"><div class="mod-detail-label">Business license</div><div class="mod-detail-value mt-1 text-break">' + esc(hq.business_license || '—') + '</div></div></div></div>';
        const accountRows = accounts.length ? accounts.map(account => '<tr><td><div class="fw-semibold text-white">' + esc(account.username || '—') + '</div><div class="text-white-50 small">ID ' + esc(account.user_id || '') + '</div></td><td class="text-white-50 text-break">' + esc(account.email || '—') + '</td><td class="text-white-50"><div>' + esc(account.role_name || '—') + '</div><div class="small text-white-50">Base #' + esc(account.role_id ?? '—') + ' · Business #' + esc(account.business_role_id ?? '—') + '</div></td><td><span class="hq-status ' + statusClass(account.status) + '">' + esc(statusLabel(account.status)) + '</span></td><td class="text-white-50 small">' + esc(date(account.created_at)) + '</td></tr>').join('') : emptyRow(5, 'No user accounts are linked to this HQ.');
        const stationRows = stations.length ? stations.map(station => '<tr><td><div class="fw-semibold text-white">' + esc(station.station_name || '—') + '</div><div class="text-white-50 small">ID ' + esc(station.station_id || '') + '</div></td><td class="text-white-50">' + esc(station.location || '—') + '</td><td class="text-white-50">' + esc(station.manager_name || '—') + '</td><td><span class="hq-status ' + statusClass(station.status) + '">' + esc(stationStatusLabel(station.status)) + '</span></td><td class="text-white-50 small">' + esc(date(station.created_at)) + '</td></tr>').join('') : emptyRow(5, 'No stations are linked to this HQ.');
        const actionRows = actions.length ? actions.map(action => '<tr><td><span class="badge text-bg-dark border border-secondary border-opacity-25">' + esc(String(action.action_type || '').toUpperCase()) + '</span></td><td class="text-white-50">' + esc(action.reason || '—') + '</td><td class="text-white-50 small">' + esc(date(action.created_at)) + '</td></tr>').join('') : emptyRow(3, 'No suspend/remove history.');
        detailsBody.innerHTML = licenseDateWarning + metaCards + sectionTitle('bi-people-fill', 'Linked user accounts', accounts.length) + '<div class="table-responsive mb-4"><table class="table mod-modal-table align-middle mb-0"><thead><tr><th>User</th><th>Email</th><th>Role</th><th>Status</th><th>Created</th></tr></thead><tbody>' + accountRows + '</tbody></table></div>' + sectionTitle('bi-fuel-pump-fill', 'Linked stations', stations.length) + '<div class="table-responsive mb-4"><table class="table mod-modal-table align-middle mb-0"><thead><tr><th>Station</th><th>Location</th><th>Manager</th><th>Status</th><th>Created</th></tr></thead><tbody>' + stationRows + '</tbody></table></div>' + sectionTitle('bi-clock-history', 'Account action history', actions.length) + '<div class="table-responsive"><table class="table mod-modal-table align-middle mb-0"><thead><tr><th>Action</th><th>Reason</th><th>Recorded</th></tr></thead><tbody>' + actionRows + '</tbody></table></div>';
    };
    document.querySelectorAll('.js-view-hq').forEach(button => button.addEventListener('click', async event => {
        event.preventDefault(); detailsTitle.innerHTML = '<i class="bi bi-building txt-neon-cyan me-2"></i> Stations &amp; Users'; detailsBody.innerHTML = '<div class="text-center text-white-50 py-5"><div class="spinner-border spinner-border-sm txt-neon-cyan mb-2" role="status"></div><div>Loading stations &amp; users…</div></div>'; detailModal.show();
        try { const response = await fetch('hq_details(M).php?id=' + encodeURIComponent(button.dataset.hqId), {headers: {Accept: 'application/json'}, cache: 'no-store'}); const payload = await response.json(); if (!response.ok || !payload.success) throw new Error(payload.message || 'Unable to load HQ details.'); renderDetails(payload); } catch (error) { detailsBody.innerHTML = '<div class="alert mod-flash alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>' + esc(error.message) + '</div>'; }
    }));
    document.querySelectorAll('.js-account-action').forEach(button => button.addEventListener('click', event => { event.preventDefault(); document.getElementById('actionHQId').value = button.dataset.hqId || ''; document.getElementById('actionHQName').textContent = button.dataset.hqName || 'Selected HQ'; document.getElementById('actionReason').value = ''; actionModal.show(); }));
    document.querySelectorAll('.js-edit-hq-details').forEach(button => button.addEventListener('click', () => {
        const h = currentHQDetails || {};
        const location = String(h.location || '');
        const parts = location.split(/\s+(?:—|-)\s+/);
        const regionRaw = String(parts[0] || '').toLowerCase().replace(/\s+region$/, '').trim();
        const address = parts.length > 1 ? parts.slice(1).join(' - ') : location;
        document.getElementById('editHQId').value = h.headquarter_id || '';
        document.getElementById('editHqName').value = h.company_name || '';
        document.getElementById('editLogo').value = h.company_logo || '';
        document.getElementById('editContact').value = h.contact_number || '';
        document.getElementById('editOwnerName').value = h.owner_name || '';
        document.getElementById('editOwnerEmail').value = h.owner_email || h.headquarter_email || '';
        document.getElementById('editRegion').value = regionRaw;
        document.getElementById('editAddress').value = address;
        document.getElementById('editLicense').value = h.business_license || '';
        document.getElementById('editExpire').value = h.expire_at ? String(h.expire_at).slice(0, 10) : '';
        const expiryInput = document.getElementById('editExpire');
        const now = new Date();
        const localToday = [now.getFullYear(), String(now.getMonth() + 1).padStart(2, '0'), String(now.getDate()).padStart(2, '0')].join('-');
        let minExpiry = localToday;
        const createdDay = String(h.created_at || '').slice(0, 10);
        if (/^\d{4}-\d{2}-\d{2}$/.test(createdDay)) {
            const dayAfterRegistration = new Date(createdDay + 'T00:00:00Z');
            dayAfterRegistration.setUTCDate(dayAfterRegistration.getUTCDate() + 1);
            minExpiry = [minExpiry, dayAfterRegistration.toISOString().slice(0, 10)].sort().pop();
        }
        expiryInput.min = minExpiry;
    }));
});
</script>
</body>
</html>
