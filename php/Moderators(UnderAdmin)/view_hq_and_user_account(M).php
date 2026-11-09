<?php
require_once __DIR__ . '/moderator_common.php';

$moderatorUser = moderator_require_user();
$flash = hq_pull_flash();
$search = trim((string) ($_GET['search'] ?? ''));
$region = strtolower(trim((string) ($_GET['region'] ?? '')));
$status = strtolower(trim((string) ($_GET['status'] ?? '')));
$expiryFilter = strtolower(trim((string) ($_GET['expiry'] ?? '')));
if (!in_array($expiryFilter, ['warning', 'expired', 'any'], true)) {
    $expiryFilter = '';
}

$allowedRegions = function_exists('admin_filter_hq_regions') ? admin_filter_hq_regions() : [];
$allowedStatuses = function_exists('admin_filter_user_statuses')
    ? admin_filter_user_statuses('EXISTS (SELECT 1 FROM business_account_scope bas WHERE bas.user_id = u.user_id AND bas.headquarter_id IS NOT NULL)')
    : hq_manageable_statuses();
foreach (['active', 'approved', 'pending', 'rejected', 'suspended', 'removed'] as $fallbackStatus) {
    if (!in_array($fallbackStatus, $allowedStatuses, true)) {
        $allowedStatuses[] = $fallbackStatus;
    }
}
$allowedStatuses = hq_status_filter_options($allowedStatuses);

if ($status !== '' && !in_array($status, $allowedStatuses, true)) {
    $status = '';
}
// Region may be free-text LIKE even if not in the distinct list.
$hqRows = moderator_hq_rows($search, $region, $status, $expiryFilter);
$dbError = hq_db_available() ? null : hq_db_error_message();
$regionLabel = static function (string $value): string {
    return function_exists('admin_filter_label') ? admin_filter_label($value) : ucwords($value);
};
$flashType = (string) ($flash['type'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View/Manage Companies — Map &amp; Pump</title>
    <link rel="icon" type="image/png" href="/images/Logo.png"><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&amp;display=swap" rel="stylesheet"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"><link rel="stylesheet" href="/css/Admin.css"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .mod-flash{border-radius:14px;background:rgba(10,22,44,.82);border:1px solid var(--glass-border);color:var(--text-primary)}
        body.background_color.light-mode .mod-flash{background:rgba(255,255,255,.92)}
        .hq-flash{border-radius:14px;background:rgba(10,22,44,.82);border:1px solid var(--glass-border);color:var(--text-primary)}
        .hq-flash.alert-danger{border-color:rgba(255,77,106,.45)}
        .hq-flash.alert-warning{border-color:rgba(255,193,7,.35)}
        body.background_color.light-mode .hq-flash{background:rgba(255,255,255,.9)}
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
    <?php if (function_exists('expiry_render_page_banners')) { expiry_render_page_banners('moderator', true); } ?>
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3"><div><span class="stat-chip mb-2"><i class="bi bi-building-fill txt-neon-cyan"></i> Company Administration</span><h1 class="fw-bold m-0 h4 text-white mt-2">View/Manage Companies</h1></div></div>

            <div class="dashboard-card py-3 px-4 mb-4"><form class="row g-3 align-items-end" method="GET" action="view_hq_and_user_account(M).php"><div class="col-12 col-md-3"><div class="input-group"><span class="input-group-text bg-transparent border-end-0" style="border-color:var(--glass-border);color:var(--neon-cyan);border-radius:12px 0 0 12px"><i class="bi bi-search"></i></span><input type="search" class="form-control glass-input border-start-0" placeholder="Search by company or owner name..." name="search" value="<?= moderator_escape($search) ?>"></div></div><div class="col-6 col-md-2"><select class="form-select glass-select" name="region" aria-label="Region"><option value="">All Regions</option><?php foreach ($allowedRegions as $option): ?><option value="<?= moderator_escape($option) ?>" <?= $region === $option ? 'selected' : '' ?>><?= moderator_escape($regionLabel($option)) ?></option><?php endforeach; ?></select></div><div class="col-6 col-md-2"><select class="form-select glass-select" name="status" aria-label="Company status"><option value="">All Status</option><?php foreach ($allowedStatuses as $option): ?><option value="<?= moderator_escape($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= moderator_escape(hq_status_label($option)) ?></option><?php endforeach; ?></select></div><div class="col-12 col-md-3"><?= function_exists('expiry_filter_select_html') ? expiry_filter_select_html($expiryFilter, 'modHqExpiryFilter', 'moderator_escape') : '' ?></div><div class="col-12 col-md-2 d-flex gap-2"><button type="button" class="btn btn-neon flex-grow-1 js-clear-filters" title="Clear filters">Clear</button></div></form></div>

    <div class="analytics-card"><div class="table-responsive hq-table-scroll"><table class="table table-dark-custom align-middle m-0"><thead><tr><th>Company &amp; Owner</th><th>Role</th><th>Stations</th><th>Company Location</th><th>Owner Account Status</th><th class="text-end">Command</th></tr></thead><tbody>
    <?php if (!$hqRows): ?><tr><td colspan="6" class="text-center text-white-50 py-5"><i class="bi bi-building-x fs-3 d-block mb-2"></i>No companies match the current filter.</td></tr><?php endif; ?>
    <?php foreach ($hqRows as $row): ?><?php $company = (string) ($row['company_name'] ?? 'Unnamed Company'); $location = preg_split('/\s+[—-]\s+/u', (string) ($row['location'] ?? ''), 2) ?: []; $rowStatus = (string) ($row['account_status'] ?? 'pending'); $stationCount = (int) ($row['station_count'] ?? 0); ?><tr><td><div class="d-flex align-items-center gap-3"><div class="rounded-3 d-flex align-items-center justify-content-center fw-bold" style="width:44px;height:44px;background:rgba(0,244,255,.1);border:1px solid rgba(0,244,255,.28);color:var(--neon-cyan)"><?= moderator_escape(hq_initials($company)) ?></div><div><div class="fw-semibold text-white"><?= moderator_escape($company) ?></div><div class="text-white-50 small">Owner: <?= moderator_escape((string) ($row['owner_name'] ?? 'No owner account')) ?> (<?= moderator_escape((string) ($row['owner_email'] ?? 'No email available')) ?>)</div></div></div></td><td class="text-white-50">#3 &middot; Company</td><td class="text-white-50"><?= $stationCount ?></td><td><div class="text-white"><?= moderator_escape((string) ($location[0] ?? 'Location not available')) ?></div><?php if (!empty($location[1])): ?><div class="text-white-50 small"><?= moderator_escape((string) $location[1]) ?></div><?php endif; ?></td><td><span class="hq-status <?= moderator_escape(hq_status_class($rowStatus)) ?>"><?= moderator_escape(hq_status_label($rowStatus)) ?></span><?php if (function_exists("expiry_badge_html")): ?><div class="mt-1"><?= expiry_badge_html(isset($row["expire_at"]) ? (string) $row["expire_at"] : null) ?></div><?php endif; ?></td><td class="text-end"><div class="d-flex gap-2 justify-content-end"><a href="#" class="btn-action js-view-hq" title="View Company Details" data-hq-id="<?= (int) $row['headquarter_id'] ?>"><i class="bi bi-eye-fill"></i></a><a href="#" class="btn-action btn-action-danger js-account-action" title="Activate / Suspend / Remove Company" data-hq-id="<?= (int) $row['headquarter_id'] ?>" data-hq-name="<?= moderator_escape($company) ?>" data-hq-status="<?= moderator_escape($rowStatus) ?>"><i class="bi bi-gear-fill"></i></a></div></td></tr><?php endforeach; ?>
    </tbody></table></div></div>
</main>

<div class="modal fade glass-modal" id="hqDetailsModal" tabindex="-1" aria-labelledby="hqDetailsModalLabel" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title fw-bold" id="hqDetailsModalLabel"><i class="bi bi-building txt-neon-cyan me-2"></i> Company Details</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body" id="hqDetailsBody"><div class="text-center text-white-50 py-5"><div class="spinner-border spinner-border-sm txt-neon-cyan mb-2" role="status"></div><div>Loading company details…</div></div></div></div></div></div>
<div class="modal fade glass-modal" id="accountActionModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title fw-bold"><i class="bi bi-shield-exclamation text-danger me-2"></i> Company Account Review</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><form action="hq_account_action(M).php" method="POST"><div class="modal-body"><input type="hidden" name="headquarter_id" id="actionHQId"><div class="small text-white-50 mb-3">Target Company: <span class="txt-neon-cyan fw-semibold" id="actionHQName">—</span></div><label class="form-label text-white-50 small" for="actionType">Action</label><select id="actionType" name="action_type" class="form-select glass-select mb-3" required><option value="activate">Activate pending company</option><option value="suspend">Suspend account</option><option value="remove">Remove account</option><option value="restore">Restore (approve again)</option></select><label class="form-label text-white-50 small" for="actionReason">Reason <span class="text-danger">*</span></label><textarea id="actionReason" name="reason" class="form-control glass-input" rows="5" maxlength="2000" required></textarea></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-3 text-white-50" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-neon">Confirm Activation</button></div></form></div></div></div>
<?php $moderatorActiveNav = 'view_hq'; include __DIR__ . '/includes/moderator_offcanvas.php'; ?><footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map &amp; Pump | All Rights Reserved</footer><?php include __DIR__ . '/includes/moderator_footer_scripts.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const detailModal = new bootstrap.Modal(document.getElementById('hqDetailsModal'));
    const actionModal = new bootstrap.Modal(document.getElementById('accountActionModal'));
    const detailsBody = document.getElementById('hqDetailsBody');
    const detailsTitle = document.getElementById('hqDetailsModalLabel');
    const esc = value => String(value ?? '').replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character]));
    const date = value => value ? new Date(String(value).replace(' ', 'T')).toLocaleString() : 'Not available';
    const statusClass = status => ({active:'hq-status-active', approved:'hq-status-active', suspended:'hq-status-suspended', removed:'hq-status-removed'}[String(status || '').toLowerCase()] || 'hq-status-pending');
    const statusLabel = status => ({active:'Active', approved:'Approved', suspended:'Suspended', removed:'Removed', pending:'Pending Verification', pending_verification:'Pending Verification'}[String(status || '').toLowerCase()] || (status ? String(status) : 'Unknown'));
    const stationStatusLabel = status => ({active:'Active', approved:'Active', decommissioned:'Decommissioned', suspended:'Suspended', removed:'Removed', offline:'Offline'}[String(status || '').toLowerCase()] || (status ? String(status) : 'Unknown'));
    const emptyRow = (cols, message) => '<tr><td colspan="' + cols + '" class="text-center text-white-50 py-4"><i class="bi bi-inbox me-1"></i>' + esc(message) + '</td></tr>';
    const sectionTitle = (icon, title, count) => '<div class="d-flex align-items-center justify-content-between mb-2 mt-1"><h6 class="text-white fw-semibold mb-0"><i class="bi ' + icon + ' txt-neon-cyan me-2"></i>' + title + '</h6><span class="badge rounded-pill" style="background:rgba(0,244,255,.12);color:#7eefff;border:1px solid rgba(0,244,255,.25);font-weight:600;">' + count + '</span></div>';
    
    <?= function_exists('expiry_js_detail_helper') ? expiry_js_detail_helper() : 'function expiryDetailAlert(){return "";}' ?>
    const renderDetails = payload => {
        const hq = payload.hq || {};
        const accounts = payload.accounts || [];
        const stations = payload.stations || [];
        const actions = payload.actions || [];
        detailsTitle.innerHTML = '<i class="bi bi-building txt-neon-cyan me-2"></i>' + esc(hq.company_name || 'Company Details');
        const registeredDay = String(hq.created_at || '').slice(0, 10);
        const expiryDay = String(hq.expire_at || '').slice(0, 10);
        const currentDate = new Date();
        const today = [currentDate.getFullYear(), String(currentDate.getMonth() + 1).padStart(2, '0'), String(currentDate.getDate()).padStart(2, '0')].join('-');
        const licenseDateWarning = registeredDay && expiryDay && expiryDay <= registeredDay
            ? '<div class="alert alert-warning py-2 mb-3" role="status"><i class="bi bi-exclamation-triangle-fill me-2"></i>License expiry must be after registration. Verify the current business license before changing account status.</div>'
            : (expiryDay && expiryDay < today ? '<div class="alert alert-warning py-2 mb-3" role="status"><i class="bi bi-exclamation-triangle-fill me-2"></i>This business license has expired. Company access is limited to the profile until the business license is renewed.</div>' : '');
        const metaCards = '<div class="row g-3 mb-4">' +
            '<div class="col-12 col-md-6"><div class="p-3 rounded-3" style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);"><div class="mod-detail-label">Company location</div><div class="mod-detail-value mt-1">' + esc(hq.location || 'Not available') + '</div></div></div>' +
            '<div class="col-6 col-md-3"><div class="p-3 rounded-3 h-100" style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);"><div class="mod-detail-label">Contact</div><div class="mod-detail-value mt-1">' + esc(hq.contact_number || '—') + '</div></div></div>' +
            '<div class="col-6 col-md-3"><div class="p-3 rounded-3 h-100" style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);"><div class="mod-detail-label">Email</div><div class="mod-detail-value mt-1 text-break">' + esc(hq.headquarter_email || '—') + '</div></div></div>' +
            '<div class="col-6 col-md-3"><div class="p-3 rounded-3 h-100" style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);"><div class="mod-detail-label">Owner</div><div class="mod-detail-value mt-1">' + esc(hq.owner_name || '—') + '</div></div></div>' +
            '<div class="col-6 col-md-3"><div class="p-3 rounded-3 h-100" style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);"><div class="mod-detail-label">Registered</div><div class="mod-detail-value mt-1">' + esc(date(hq.created_at)) + '</div></div></div>' +
            '<div class="col-6 col-md-3"><div class="p-3 rounded-3 h-100" style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);"><div class="mod-detail-label">License expiry</div><div class="mod-detail-value mt-1">' + esc(date(hq.expire_at)) + '</div></div></div>' +
            '<div class="col-6 col-md-3"><div class="p-3 rounded-3 h-100" style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);"><div class="mod-detail-label">Business license</div><div class="mod-detail-value mt-1 text-break">' + esc(hq.business_license || '—') + '</div></div></div></div>';
        const roleLabel = role => ({hq:'Company',headquarter:'Company',headquarters:'Company',customer:'Driver'}[String(role || '').trim().toLowerCase()] || (role || '—'));
        const accountRows = accounts.length ? accounts.map(account => '<tr><td><div class="fw-semibold text-white">' + esc(account.username || '—') + '</div><div class="text-white-50 small">ID ' + esc(account.user_id || '') + '</div></td><td class="text-white-50 text-break">' + esc(account.email || '—') + '</td><td class="text-white-50"><div>' + esc(roleLabel(account.role_name)) + '</div><div class="small text-white-50">Base #' + esc(account.role_id ?? '—') + ' · Business #' + esc(account.business_role_id ?? '—') + '</div></td><td><span class="hq-status ' + statusClass(account.status) + '">' + esc(statusLabel(account.status)) + '</span></td><td class="text-white-50 small">' + esc(date(account.created_at)) + '</td></tr>').join('') : emptyRow(5, 'No accounts are linked to this company.');
        const stationRows = stations.length ? stations.map(station => '<tr><td><div class="fw-semibold text-white">' + esc(station.station_name || '—') + '</div><div class="text-white-50 small">ID ' + esc(station.station_id || '') + '</div></td><td class="text-white-50">' + esc(station.location || '—') + '</td><td class="text-white-50">' + esc(station.manager_name || '—') + '</td><td><span class="hq-status ' + statusClass(station.status) + '">' + esc(stationStatusLabel(station.status)) + '</span></td><td class="text-white-50 small">' + esc(date(station.created_at)) + '</td></tr>').join('') : emptyRow(5, 'No stations are linked to this company.');
        const actionRows = actions.length ? actions.map(action => '<tr><td><span class="badge text-bg-dark border border-secondary border-opacity-25">' + esc(String(action.action_type || '').toUpperCase()) + '</span></td><td class="text-white-50">' + esc(action.reason || '—') + '</td><td class="text-white-50 small">' + esc(date(action.created_at)) + '</td></tr>').join('') : emptyRow(3, 'No suspend/remove history.');
        detailsBody.innerHTML = licenseDateWarning + metaCards + sectionTitle('bi-people-fill', 'Linked accounts', accounts.length) + '<div class="table-responsive mb-4"><table class="table mod-modal-table align-middle mb-0"><thead><tr><th>Account</th><th>Email</th><th>Role</th><th>Status</th><th>Created</th></tr></thead><tbody>' + accountRows + '</tbody></table></div>' + sectionTitle('bi-fuel-pump-fill', 'Linked stations', stations.length) + '<div class="table-responsive mb-4"><table class="table mod-modal-table align-middle mb-0"><thead><tr><th>Station</th><th>Location</th><th>Manager</th><th>Status</th><th>Created</th></tr></thead><tbody>' + stationRows + '</tbody></table></div>' + sectionTitle('bi-clock-history', 'Account action history', actions.length) + '<div class="table-responsive"><table class="table mod-modal-table align-middle mb-0"><thead><tr><th>Action</th><th>Reason</th><th>Recorded</th></tr></thead><tbody>' + actionRows + '</tbody></table></div>';
    };
    document.addEventListener('click', async event => {
        const viewBtn = event.target.closest('.js-view-hq');
        if (viewBtn) {
            event.preventDefault(); detailsTitle.innerHTML = '<i class="bi bi-building txt-neon-cyan me-2"></i> Stations &amp; Accounts'; detailsBody.innerHTML = '<div class="text-center text-white-50 py-5"><div class="spinner-border spinner-border-sm txt-neon-cyan mb-2" role="status"></div><div>Loading stations &amp; accounts…</div></div>'; detailModal.show();
            try { const response = await fetch('hq_details(M).php?id=' + encodeURIComponent(viewBtn.dataset.hqId), {headers: {Accept: 'application/json'}, cache: 'no-store'}); const payload = await response.json(); if (!response.ok || !payload.success) throw new Error(payload.message || 'Unable to load company details.'); renderDetails(payload); } catch (error) { detailsBody.innerHTML = '<div class="alert mod-flash alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>' + esc(error.message) + '</div>'; }
            return;
        }
        const actionBtn = event.target.closest('.js-account-action');
        if (actionBtn) {
            event.preventDefault(); document.getElementById('actionHQId').value = actionBtn.dataset.hqId || ''; document.getElementById('actionHQName').textContent = actionBtn.dataset.hqName || 'Selected Company'; document.getElementById('actionType').value = actionBtn.dataset.hqStatus === 'pending' ? 'activate' : 'suspend'; document.getElementById('actionReason').value = ''; actionModal.show();
            return;
        }
    });
});
</script>
</body>
</html>
