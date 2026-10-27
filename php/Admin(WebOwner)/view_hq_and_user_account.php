<?php
require_once __DIR__ . '/admin_common.php';

admin_require_admin();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$flash = hq_pull_flash();
$search = trim((string) ($_GET['search'] ?? ''));
$region = strtolower(trim((string) ($_GET['region'] ?? '')));
$status = strtolower(trim((string) ($_GET['status'] ?? '')));
$expiryFilter = strtolower(trim((string) ($_GET['expiry'] ?? '')));
if (!in_array($expiryFilter, ['warning', 'expired', 'any'], true)) {
    $expiryFilter = '';
}

$allowedRegions = admin_filter_hq_regions();
$allowedStatuses = admin_filter_user_statuses(
    "u.headquarter_id IS NOT NULL OR EXISTS (
        SELECT 1 FROM headquarter h WHERE h.headquarter_id = u.headquarter_id
     )"
);
// Keep actionable statuses available even if not yet present in rows.
foreach (['active', 'approved', 'pending', 'rejected', 'suspended', 'removed'] as $fallbackStatus) {
    if (!in_array($fallbackStatus, $allowedStatuses, true)) {
        $allowedStatuses[] = $fallbackStatus;
    }
}
$allowedStatuses = hq_status_filter_options($allowedStatuses);

if ($region !== '' && !in_array($region, $allowedRegions, true)) {
    // Still allow free-text region from the query string (LIKE filter below).
}
if ($status !== '' && !in_array($status, $allowedStatuses, true)) {
    $status = '';
}

$hqRows = [];
$dbError = null;

if (hq_db_available() && hq_registry_schema_ready()) {
   $hqRoleId = hq_hq_role_id();
   if ($hqRoleId > 0) {
       // Keep unlinked HQ records visible and classify them as pending below.
       $hqStateSql = "COALESCE(NULLIF(h.status, ''), COALESCE(owner.status, 'pending'))";
       $where = ['(owner.user_id IS NULL OR ' . hq_manageable_status_sql($hqStateSql) . ')'];
       $params = [];
       $types = '';

       if ($search !== '') {
           $where[] = '(LOWER(h.company_name) LIKE ? OR LOWER(owner.user_name) LIKE ?)';
           $searchLike = '%' . strtolower($search) . '%';
           array_push($params, $searchLike, $searchLike);
           $types .= 'ss';
       }

       if ($region !== '') {
           $where[] = 'LOWER(h.location) LIKE ?';
           $params[] = '%' . $region . '%';
           $types .= 's';
       }

       if ($status !== '') {
           if (in_array($status, ['approved', 'active'], true)) {
               $where[] = "LOWER($hqStateSql) IN ('approved', 'active')";
           } elseif (in_array($status, ['pending', 'pending_verification'], true)) {
               $where[] = "LOWER($hqStateSql) IN ('pending', 'pending_verification')";
           } else {
               $where[] = "LOWER($hqStateSql) = ?";
               $params[] = $status;
               $types .= 's';
           }
       }

       if ($expiryFilter !== '' && function_exists('expiry_hq_filter_sql')) {
           $expirySql = expiry_hq_filter_sql($expiryFilter, 'h.expire_at');
           if ($expirySql !== '') {
               $where[] = $expirySql;
           }
       }

       // Station totals come from `branches` via station_business_scope (never the dummy `stations` view).
       $sql = "SELECT
            h.headquarter_id,
            h.company_name,
            h.location,
            h.headquarter_email,
            h.created_at,
            h.expire_at,
            owner.user_name AS owner_name,
            owner.email AS owner_email,
            {$hqStateSql} AS account_status,
            COALESCE(user_counts.user_count, 0) AS user_count,
            COALESCE(station_counts.station_count, 0) AS station_count
        FROM headquarter h
        LEFT JOIN (
            SELECT u.headquarter_id, MIN(u.user_id) AS owner_user_id, COUNT(*) AS user_count
            FROM users u
            WHERE (u.role_id = {$hqRoleId} OR u.business_role_id = {$hqRoleId} OR u.current_role_id = {$hqRoleId})
            GROUP BY headquarter_id
        ) user_counts ON user_counts.headquarter_id = h.headquarter_id
        LEFT JOIN users owner ON owner.user_id = user_counts.owner_user_id
        LEFT JOIN (
            SELECT scope.headquarter_id, COUNT(DISTINCT scope.station_id) AS station_count
            FROM station_business_scope scope
            INNER JOIN branches b ON b.branch_id = scope.station_id
            GROUP BY scope.headquarter_id
        ) station_counts ON station_counts.headquarter_id = h.headquarter_id";

       if ($where) {
           $sql .= ' WHERE ' . implode(' AND ', $where);
       }
       $sql .= ' ORDER BY h.created_at DESC, h.headquarter_id DESC';

       $statement = $conn->prepare($sql);
       if (!$statement) {
           $dbError = hq_db_error_message();
       } else {
           if ($types !== '') {
               $bound = array_values($params);
               if (!hq_bind($statement, $types, $bound)) {
                   $dbError = hq_db_error_message();
               }
           }

           if (!$dbError && !$statement->execute()) {
               $dbError = hq_db_error_message();
           } else {
               $result = $statement->get_result();
               while ($result && ($row = $result->fetch_assoc())) {
                   $hqRows[] = $row;
               }
           }

           $statement->close();
       }
   }
} else {
    $dbError = hq_db_error_message();
}

$regionLabel = static function (string $value) {
    return admin_filter_label($value);
};

$flashType = $flash['type'] ?? '';
$flashContext = $flash['context'] ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Map &amp; Pump Admin — View and manage role 3 HQ accounts and their HQ details.">
    <title>View/Manage HQ — Map &amp; Pump</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&amp;display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="icon" type="image/png" href="/images/Logo.png">
    <link rel="stylesheet" href="/css/Admin.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .hq-status {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            color: rgba(255, 255, 255, .68);
            font-size: .8rem;
            white-space: nowrap;
        }
        .hq-status::before {
            content: '';
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
        }
        .hq-status-active::before { background: #1be494; box-shadow: 0 0 8px #1be494; }
        .hq-status-pending::before { background: #ffc107; box-shadow: 0 0 8px #ffc107; }
        .hq-status-suspended::before { background: #ff9f43; box-shadow: 0 0 8px #ff9f43; }
        .hq-status-removed::before { background: #ff4d6a; box-shadow: 0 0 8px #ff4d6a; }
        .hq-flash { border-radius: 14px; background: rgba(10, 22, 44, .82); border: 1px solid var(--glass-border); color: var(--text-primary); }
        .hq-flash.alert-success { border-color: rgba(27, 228, 148, .4); }
        .hq-flash.alert-danger { border-color: rgba(255, 77, 106, .45); }
        .hq-credential-box { border: 1px solid rgba(0, 244, 255, .2); border-radius: 12px; background: rgba(0, 244, 255, .06); }
        .hq-detail-label { color: var(--text-muted); font-size: .72rem; text-transform: uppercase; letter-spacing: .07em; }
        .hq-detail-value { color: var(--text-primary); word-break: break-word; }
        .hq-modal-table { --bs-table-bg: transparent; --bs-table-color: var(--text-primary); --bs-table-border-color: var(--glass-border); }
        .hq-modal-table th { color: var(--text-muted); font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; }
        body.background_color.light-mode .hq-flash { background: rgba(255,255,255,.9); }
        body.background_color.light-mode .hq-status { color: var(--text-muted); }
        .hq-table-scroll {
            max-height: min(60vh, 520px);
            overflow: auto;
        }
    </style>
</head>
<body class="background_color" id="background_color">
<?php $adminSection = 'view_hq'; include __DIR__ . '/admin_section_nav.php'; ?>

    <main class="container my-5">
        <?php if ($flash && $flashType): ?>
            <div class="alert hq-flash alert-<?= hq_escape($flashType) ?> alert-dismissible fade show mb-4" role="alert">
                <div class="d-flex gap-2 align-items-start">
                    <i class="bi <?= $flashType === 'success' ? 'bi-check-circle-fill text-success' : 'bi-exclamation-triangle-fill text-danger' ?> mt-1"></i>
                    <div class="flex-grow-1">
                        <div><?= hq_escape($flash['message'] ?? '') ?></div>
                        <?php if ($flashType === 'success' && !empty($flashContext['temporary_password'])): ?>
                            <div class="hq-credential-box p-3 mt-3 small">
                                <div class="fw-semibold txt-neon-cyan mb-1">Temporary login credentials — save these now</div>
                                <div>Username: <code><?= hq_escape($flashContext['username'] ?? '') ?></code></div>
                                <div>Temporary password: <code><?= hq_escape($flashContext['temporary_password']) ?></code></div>
                                <div class="text-white-50 mt-2">Email: <?= hq_escape($flashContext['email'] ?? '') ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (function_exists('expiry_render_page_banners')) { expiry_render_page_banners('admin', true); } ?>
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <span class="stat-chip mb-2"><i class="bi bi-building-fill txt-neon-cyan"></i> HQ Administration</span>
                <h1 class="fw-bold m-0 h4 text-white mt-2">View/Manage HQ</h1>
                <p class="text-white-50 m-0 small mt-1">Pending verification decisions are handled in Requests.</p>
            </div>
        </div>

        <?php if ($dbError): ?>
            <div class="alert hq-flash alert-danger mb-4" role="alert">
                <i class="bi bi-database-exclamation me-2"></i><?= hq_escape($dbError) ?>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-12">
                <div class="dashboard-card py-3 px-4">
                    <form class="row g-3 align-items-end" method="GET" action="view_hq_and_user_account.php">
                        <div class="col-12 col-md-4 col-xl-3">
                            <label class="form-label text-white-50 small mb-1" for="hqSearch">Search</label>
                            <div class="input-group">
                                <span class="input-group-text bg-transparent border-end-0" style="border-color: var(--glass-border); color: var(--neon-cyan); border-radius: 12px 0 0 12px;"><i class="bi bi-search"></i></span>
                                <input id="hqSearch" type="search" class="form-control glass-input border-start-0" placeholder="Search by HQ or owner name..." name="search" value="<?= hq_escape($search) ?>">
                            </div>
                        </div>
                        <div class="col-6 col-md-4 col-xl-2">
                            <label class="form-label text-white-50 small mb-1" for="hqRegion">Region</label>
                            <select id="hqRegion" class="form-select glass-select" name="region">
                                <option value="" <?= $region === '' ? 'selected' : '' ?>>All Regions</option>
                                <?php foreach ($allowedRegions as $option): ?>
                                    <option value="<?= $option ?>" <?= $region === $option ? 'selected' : '' ?>><?= hq_escape($regionLabel($option)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-md-4 col-xl-2">
                            <label class="form-label text-white-50 small mb-1" for="hqStatus">Owner status</label>
                            <select id="hqStatus" class="form-select glass-select" name="status">
                                <option value="" <?= $status === '' ? 'selected' : '' ?>>All Status</option>
                                <?php foreach ($allowedStatuses as $option): ?>
                                    <option value="<?= hq_escape($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= hq_escape(hq_status_label($option)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6 col-xl-3">
                            <?= function_exists('expiry_filter_select_html') ? expiry_filter_select_html($expiryFilter, 'hqExpiryFilter', 'hq_escape') : '' ?>
                        </div>
                        <div class="col-12 col-md-6 col-xl-2 d-flex gap-2">
                            <button type="button" class="btn btn-neon flex-grow-1 js-clear-filters" title="Clear filters">Clear</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="col-12">
                <div class="analytics-card">
                    <div class="table-responsive hq-table-scroll">
                        <table class="table table-dark-custom align-middle m-0">
                            <thead>
                                <tr><th>HQ Entity &amp; Owner</th><th>Role</th><th>Headquarters Location</th><th>Stations</th><th>Owner Account Status</th><th class="text-end">Command</th></tr>
                            </thead>
                            <tbody>
                                <?php if (!$hqRows): ?>
                                    <tr><td colspan="6" class="text-center text-white-50 py-5"><i class="bi bi-building-x fs-3 d-block mb-2"></i>No HQ accounts match the current filter.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($hqRows as $row): ?>
                                    <?php
                                    $companyName = (string) ($row['company_name'] ?? 'Unnamed HQ');
                                    $ownerName = (string) ($row['owner_name'] ?? 'No owner account');
                                    $ownerEmail = (string) ($row['owner_email'] ?? 'No email available');
                                    $rowStatus = (string) ($row['account_status'] ?? 'pending');
                                    $stationCount = (int) ($row['station_count'] ?? 0);
                                    $locationParts = preg_split('/\s+[—-]\s+/u', (string) ($row['location'] ?? ''), 2) ?: [];
                                    $locationRegion = $locationParts[0] ?? 'Location not available';
                                    $locationAddress = $locationParts[1] ?? '';
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center gap-3">
                                                <div class="rounded-3 d-flex align-items-center justify-content-center text-white fw-bold" style="width:44px;height:44px;background:rgba(0,244,255,.1);border:1px solid rgba(0,244,255,.28);flex-shrink:0;font-size:.85rem;color:var(--neon-cyan)!important;">
                                                    <?= hq_escape(hq_initials($companyName)) ?>
                                                </div>
                                                <div>
                                                    <div class="fw-semibold text-white"><?= hq_escape($companyName) ?></div>
                                                    <div class="text-white-50 small" style="font-size:.8rem;">Owner: <?= hq_escape($ownerName) ?> (<?= hq_escape($ownerEmail) ?>)</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-white-50">#3 &middot; HQ</td>
                                        <td><div class="text-white"><?= hq_escape($locationRegion) ?></div><?php if ($locationAddress !== ''): ?><div class="text-white-50 small" style="font-size:.8rem;"><?= hq_escape($locationAddress) ?></div><?php endif; ?></td>
                                        <td class="text-white-50"><span class="stat-chip" style="font-size:.75rem;"><i class="bi bi-fuel-pump me-1 txt-neon-cyan"></i><?= $stationCount ?></span></td>
                                        <td>
                                            <span class="hq-status <?= hq_status_class($rowStatus) ?>"><?= hq_escape(hq_status_label($rowStatus)) ?></span>
                                            <?php if (function_exists('expiry_badge_html')): ?>
                                                <div class="mt-1"><?= expiry_badge_html(isset($row['expire_at']) ? (string) $row['expire_at'] : null) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-flex gap-2 justify-content-end">
                                                <a href="#" class="btn-action js-view-hq" title="View Stations &amp; Users" aria-label="View <?= hq_escape($companyName) ?> details" data-hq-id="<?= (int) $row['headquarter_id'] ?>"><i class="bi bi-eye-fill"></i></a>
                                                <a href="#" class="btn-action btn-action-danger js-account-action" title="Activate / Suspend / Remove HQ" aria-label="Change status for <?= hq_escape($companyName) ?>" data-hq-id="<?= (int) $row['headquarter_id'] ?>" data-hq-name="<?= hq_escape($companyName) ?>" data-hq-status="<?= hq_escape($rowStatus) ?>"><i class="bi bi-gear-fill"></i></a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <div class="modal fade glass-modal" id="accountActionModal" tabindex="-1" aria-labelledby="accountActionModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title fw-bold" id="accountActionModalLabel"><i class="bi bi-shield-exclamation text-danger me-2"></i> HQ Account Review</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <form action="hq_account_action.php" method="POST">
                    <div class="modal-body d-flex flex-column gap-3">
                        <input type="hidden" name="headquarter_id" id="actionHQId"><input type="hidden" name="return_to" value="view_hq_and_user_account.php">
                        <div class="small text-white-50">Target HQ: <span class="txt-neon-cyan fw-semibold" id="actionHQName">—</span></div>
                        <div><label class="form-label text-white-50 small" for="actionType">Account action</label><select class="form-select glass-select account-action-select" name="action_type" id="actionType" required><option value="activate">Activate pending HQ</option><option value="suspend">Suspend account</option><option value="remove">Remove account</option><option value="restore">Restore (approve again)</option></select></div>
                        <div><label class="form-label text-white-50 small" for="actionReason">Reason to record and notify</label><textarea class="form-control glass-input" name="reason" id="actionReason" rows="5" maxlength="2000" placeholder="Explain why this HQ account is being suspended, removed, or restored..." required style="resize:none;"></textarea></div>
                        <div class="small text-white-50"><i class="bi bi-envelope me-1 txt-neon-cyan"></i>The reason is saved to the HQ action history. An email notice is sent after the account action succeeds, using the configured mail provider.</div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-3 text-white-50 border-secondary border-opacity-20" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger" id="actionSubmit">Confirm Suspend</button></div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade glass-modal" id="hqDetailsModal" tabindex="-1" aria-labelledby="hqDetailsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title fw-bold" id="hqDetailsModalLabel"><i class="bi bi-building txt-neon-cyan me-2"></i> HQ Details</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <div class="modal-body" id="hqDetailsBody"><div class="text-center text-white-50 py-5"><div class="spinner-border spinner-border-sm txt-neon-cyan mb-2" role="status"></div><div>Loading HQ details…</div></div></div>
                <div class="modal-footer"><button type="button" class="btn btn-neon js-edit-hq-details" data-bs-target="#editHQModal" data-bs-toggle="modal">Edit details</button></div>
            </div>
        </div>
    </div>

    <div class="modal fade glass-modal" id="editHQModal" tabindex="-1" aria-labelledby="editHQLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title fw-bold" id="editHQLabel"><i class="bi bi-pencil-square txt-neon-cyan me-2"></i> Edit HQ</h5><p class="text-white-50 small mb-0 mt-1">Update HQ and owner details in the live database.</p></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><form action="hq_update_action.php" method="POST"><input type="hidden" name="headquarter_id" id="editHQId"><input type="hidden" name="return_to" value="view_hq_and_user_account.php"><div class="modal-body d-flex flex-column gap-3"><div class="manage-hq-note text-white-50 small"><span class="text-danger">*</span> Changing the owner email also updates the linked HQ account email.</div><div><label class="form-label text-white-50 small" for="editHqName">Company / HQ Name <span class="text-danger">*</span></label><input id="editHqName" type="text" name="hq_name" class="form-control glass-input" maxlength="255" required></div><div><label class="form-label text-white-50 small" for="editLogo">Company Logo URL / Storage Path <span class="text-danger">*</span></label><input id="editLogo" type="text" name="company_logo" class="form-control glass-input" maxlength="255" required></div><div class="row g-3"><div class="col-12 col-md-6"><label class="form-label text-white-50 small" for="editOwnerName">Owner Name <span class="text-danger">*</span></label><input id="editOwnerName" type="text" name="owner_name" class="form-control glass-input" maxlength="225" required></div><div class="col-12 col-md-6"><label class="form-label text-white-50 small" for="editOwnerEmail">Owner Email <span class="text-danger">*</span></label><input id="editOwnerEmail" type="email" name="owner_email" class="form-control glass-input" maxlength="225" required></div></div><div><label class="form-label text-white-50 small" for="editContact">Contact Number <span class="text-danger">*</span></label><input id="editContact" type="text" name="contact_number" class="form-control glass-input" maxlength="50" required></div><div><label class="form-label text-white-50 small" for="editRegion">Regional Headquarters Office <span class="text-danger">*</span></label><select id="editRegion" name="region" class="form-select glass-select" required><option value="">Select region</option><option value="yangon">Yangon Region</option><option value="mandalay">Mandalay Region</option><option value="naypyidaw">Naypyidaw</option></select></div><div><label class="form-label text-white-50 small" for="editAddress">Physical Address Details <span class="text-danger">*</span></label><textarea id="editAddress" name="address" rows="3" maxlength="220" class="form-control glass-input" required></textarea></div><div><label class="form-label text-white-50 small" for="editLicense">Business License <span class="text-danger">*</span></label><input id="editLicense" type="text" name="business_license" class="form-control glass-input" maxlength="255" required></div><div><label class="form-label text-white-50 small" for="editExpire">License Expiry Date <span class="text-danger">*</span></label><input id="editExpire" type="date" name="expire_at" min="<?= date('Y-m-d') ?>" class="form-control glass-input" required></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-3 text-white-50" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-neon">Save HQ Changes</button></div></form></div></div></div>

    

    <footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map &amp; Pump | All Rights Reserved</footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/js/Admin.js"></script>
    <script>
        (() => {
            const actionModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('accountActionModal'));
            const detailsModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('hqDetailsModal'));
            const actionId = document.getElementById('actionHQId');
            const actionName = document.getElementById('actionHQName');
            const actionType = document.getElementById('actionType');
            const actionSubmit = document.getElementById('actionSubmit');
            const detailsTitle = document.getElementById('hqDetailsModalLabel');
            const detailsBody = document.getElementById('hqDetailsBody');
            let currentHQDetails = null;

            const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (character) => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[character]));
            const formatDate = (value) => value ? new Date(String(value).replace(' ', 'T')).toLocaleString() : 'Not available';
            const statusClass = (status) => ({active:'hq-status-active', approved:'hq-status-active', suspended:'hq-status-suspended', removed:'hq-status-removed'}[String(status || '').toLowerCase()] || 'hq-status-pending');
            const statusLabel = (status) => ({active:'Active', approved:'Approved', suspended:'Suspended', removed:'Removed', pending:'Pending Verification', pending_verification:'Pending Verification'}[String(status || '').toLowerCase()] || (status ? String(status) : 'Unknown'));
            const stationStatusLabel = (status) => ({active:'Active', approved:'Active', decommissioned:'Decommissioned', suspended:'Suspended', removed:'Removed', offline:'Offline'}[String(status || '').toLowerCase()] || (status ? String(status) : 'Unknown'));

            const updateActionButton = () => {
                const kind = actionType.value;
                actionSubmit.textContent = ({activate:'Confirm Activation', remove:'Confirm Remove', restore:'Confirm Restore', suspend:'Confirm Suspend'})[kind];
                actionSubmit.classList.toggle('btn-danger', !['activate', 'restore'].includes(kind));
                actionSubmit.classList.toggle('btn-neon', ['activate', 'restore'].includes(kind));
            };
            actionType.addEventListener('change', updateActionButton);

            document.addEventListener('click', (event) => {
                const button = event.target.closest('.js-account-action');
                if (!button) return;
                event.preventDefault();
                actionId.value = button.dataset.hqId || '';
                actionName.textContent = button.dataset.hqName || 'Selected HQ';
                actionType.value = button.dataset.hqStatus === 'pending' ? 'activate' : 'suspend';
                updateActionButton();
                document.getElementById('actionReason').value = '';
                actionModal.show();
            });

            const sectionTitle = (icon, title, count) =>
                '<div class="d-flex align-items-center justify-content-between mb-2 mt-1">' +
                '<h6 class="text-white fw-semibold mb-0"><i class="bi ' + icon + ' txt-neon-cyan me-2"></i>' + title + '</h6>' +
                '<span class="badge rounded-pill" style="background:rgba(0,244,255,.12);color:#7eefff;border:1px solid rgba(0,244,255,.25);font-weight:600;">' + count + '</span></div>';

            const emptyRow = (cols, message) =>
                '<tr><td colspan="' + cols + '" class="text-center text-white-50 py-4"><i class="bi bi-inbox me-1"></i>' + escapeHtml(message) + '</td></tr>';

            
            <?= function_exists('expiry_js_detail_helper') ? expiry_js_detail_helper() : 'function expiryDetailAlert(){return "";}' ?>
            const renderDetails = (payload) => {
                const hq = payload.hq || {};
                currentHQDetails = hq;
                const accounts = payload.accounts || [];
                const stations = payload.stations || [];
                const actions = payload.actions || [];
                detailsTitle.innerHTML = '<i class="bi bi-building txt-neon-cyan me-2"></i>' + escapeHtml(hq.company_name || 'HQ Details');

                const registeredDay = String(hq.created_at || '').slice(0, 10);
                const expiryDay = String(hq.expire_at || '').slice(0, 10);
                const currentDate = new Date();
                const today = [currentDate.getFullYear(), String(currentDate.getMonth() + 1).padStart(2, '0'), String(currentDate.getDate()).padStart(2, '0')].join('-');
                const licenseDateWarning = registeredDay && expiryDay && expiryDay <= registeredDay
                    ? '<div class="alert alert-warning py-2 mb-3" role="status"><i class="bi bi-exclamation-triangle-fill me-2"></i>License expiry must be after registration. Correct this date from Edit details using the current business license.</div>'
                    : (typeof expiryDetailAlert === 'function' ? expiryDetailAlert(expiryDay, 'hq') : (expiryDay && expiryDay < today ? '<div class="alert alert-danger py-2 mb-3" role="alert"><i class="bi bi-x-circle-fill me-2"></i>This business license has expired. HQ access is limited to the profile until the business license is renewed.</div>' : ''));

                const metaCards =
                    '<div class="row g-3 mb-4">' +
                    '<div class="col-12 col-md-6"><div class="p-3 rounded-3" style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);"><div class="hq-detail-label">HQ location</div><div class="hq-detail-value mt-1">' + escapeHtml(hq.location || 'Not available') + '</div></div></div>' +
                    '<div class="col-6 col-md-3"><div class="p-3 rounded-3 h-100" style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);"><div class="hq-detail-label">Contact</div><div class="hq-detail-value mt-1">' + escapeHtml(hq.contact_number || '—') + '</div></div></div>' +
                    '<div class="col-6 col-md-3"><div class="p-3 rounded-3 h-100" style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);"><div class="hq-detail-label">Email</div><div class="hq-detail-value mt-1 text-break">' + escapeHtml(hq.headquarter_email || '—') + '</div></div></div>' +
                    '<div class="col-6 col-md-3"><div class="p-3 rounded-3 h-100" style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);"><div class="hq-detail-label">Owner</div><div class="hq-detail-value mt-1">' + escapeHtml(hq.owner_name || '—') + '</div></div></div>' +
                    '<div class="col-6 col-md-3"><div class="p-3 rounded-3 h-100" style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);"><div class="hq-detail-label">Registered</div><div class="hq-detail-value mt-1">' + escapeHtml(formatDate(hq.created_at)) + '</div></div></div>' +
                    '<div class="col-6 col-md-3"><div class="p-3 rounded-3 h-100" style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);"><div class="hq-detail-label">License expiry</div><div class="hq-detail-value mt-1">' + escapeHtml(formatDate(hq.expire_at)) + '</div></div></div>' +
                    '<div class="col-6 col-md-3"><div class="p-3 rounded-3 h-100" style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);"><div class="hq-detail-label">Business license</div><div class="hq-detail-value mt-1 text-break">' + escapeHtml(hq.business_license || '—') + '</div></div></div>' +
                    '</div>';

                const accountRows = accounts.length
                    ? accounts.map((account) =>
                        '<tr>' +
                        '<td><div class="fw-semibold text-white">' + escapeHtml(account.username || '—') + '</div><div class="text-white-50 small">ID ' + escapeHtml(account.user_id || '') + '</div></td>' +
                        '<td class="text-white-50 text-break">' + escapeHtml(account.email || '—') + '</td>' +
                        '<td class="text-white-50"><div>' + escapeHtml(account.role_name || '—') + '</div><div class="small text-white-50">Base #' + escapeHtml(account.role_id ?? '—') + ' · Business #' + escapeHtml(account.business_role_id ?? '—') + '</div></td>' +
                        '<td><span class="hq-status ' + statusClass(account.status) + '">' + escapeHtml(statusLabel(account.status)) + '</span></td>' +
                        '<td class="text-white-50 small">' + escapeHtml(formatDate(account.created_at)) + '</td>' +
                        '</tr>').join('')
                    : emptyRow(5, 'No user accounts are linked to this HQ.');

                const stationRows = stations.length
                    ? stations.map((station) =>
                        '<tr>' +
                        '<td><div class="fw-semibold text-white">' + escapeHtml(station.station_name || '—') + '</div><div class="text-white-50 small">ID ' + escapeHtml(station.station_id || '') + '</div></td>' +
                        '<td class="text-white-50">' + escapeHtml(station.location || '—') + '</td>' +
                        '<td class="text-white-50">' + escapeHtml(station.manager_name || '—') + '</td>' +
                        '<td><span class="hq-status ' + statusClass(station.status) + '">' + escapeHtml(stationStatusLabel(station.status)) + '</span></td>' +
                        '<td class="text-white-50 small">' + escapeHtml(formatDate(station.created_at)) + '</td>' +
                        '</tr>').join('')
                    : emptyRow(5, 'No stations are linked to this HQ.');

                const actionRows = actions.length
                    ? actions.map((action) =>
                        '<tr>' +
                        '<td><span class="badge text-bg-dark border border-secondary border-opacity-25">' + escapeHtml(String(action.action_type || '').toUpperCase()) + '</span></td>' +
                        '<td class="text-white-50">' + escapeHtml(action.reason || '—') + '</td>' +
                        '<td class="text-white-50 small">' + escapeHtml(formatDate(action.created_at)) + '</td>' +
                        '</tr>').join('')
                    : emptyRow(3, 'No suspend/remove history.');

                detailsBody.innerHTML =
                    licenseDateWarning + metaCards +
                    sectionTitle('bi-people-fill', 'Linked user accounts', accounts.length) +
                    '<div class="table-responsive mb-4"><table class="table hq-modal-table align-middle mb-0"><thead><tr><th>User</th><th>Email</th><th>Role</th><th>Status</th><th>Created</th></tr></thead><tbody>' + accountRows + '</tbody></table></div>' +
                    sectionTitle('bi-fuel-pump-fill', 'Linked stations', stations.length) +
                    '<div class="table-responsive mb-4"><table class="table hq-modal-table align-middle mb-0"><thead><tr><th>Station</th><th>Location</th><th>Manager</th><th>Status</th><th>Created</th></tr></thead><tbody>' + stationRows + '</tbody></table></div>' +
                    sectionTitle('bi-clock-history', 'Account action history', actions.length) +
                    '<div class="table-responsive"><table class="table hq-modal-table align-middle mb-0"><thead><tr><th>Action</th><th>Reason</th><th>Recorded</th></tr></thead><tbody>' + actionRows + '</tbody></table></div>';
            };

            document.addEventListener('click', async (event) => {
                const button = event.target.closest('.js-view-hq');
                if (!button) return;
                event.preventDefault();
                detailsTitle.innerHTML = '<i class="bi bi-building txt-neon-cyan me-2"></i> Stations &amp; Users';
                detailsBody.innerHTML = '<div class="text-center text-white-50 py-5"><div class="spinner-border spinner-border-sm txt-neon-cyan mb-2" role="status"></div><div>Loading stations &amp; users…</div></div>';
                detailsModal.show();
                try {
                    const response = await fetch('hq_details.php?id=' + encodeURIComponent(button.dataset.hqId), {headers: {Accept: 'application/json'}, cache: 'no-store'});
                    const payload = await response.json();
                    if (!response.ok || !payload.success) throw new Error(payload.message || 'Could not load HQ details.');
                    renderDetails(payload);
                } catch (error) {
                    detailsBody.innerHTML = '<div class="alert hq-flash alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>' + escapeHtml(error.message) + '</div>';
                }
            });

            document.addEventListener('click', (event) => {
                const button = event.target.closest('.js-edit-hq-details');
                if (!button) return;
                    const hq = currentHQDetails || {};
                    const location = String(hq.location || '');
                    const parts = location.split(/\s+(?:—|-)\s+/);
                    const regionRaw = String(parts[0] || '').toLowerCase().replace(/\s+region$/, '').trim();
                    const address = parts.length > 1 ? parts.slice(1).join(' - ') : location;
                    document.getElementById('editHQId').value = hq.headquarter_id || '';
                    document.getElementById('editHqName').value = hq.company_name || '';
                    document.getElementById('editLogo').value = hq.company_logo || '';
                    document.getElementById('editContact').value = hq.contact_number || '';
                    document.getElementById('editOwnerName').value = hq.owner_name || '';
                    document.getElementById('editOwnerEmail').value = hq.owner_email || hq.headquarter_email || '';
                    document.getElementById('editRegion').value = regionRaw;
                    document.getElementById('editAddress').value = address;
                    document.getElementById('editLicense').value = hq.business_license || '';
                    document.getElementById('editExpire').value = hq.expire_at ? String(hq.expire_at).slice(0, 10) : '';
                    const expiryInput = document.getElementById('editExpire');
                    const now = new Date();
                    const localToday = [now.getFullYear(), String(now.getMonth() + 1).padStart(2, '0'), String(now.getDate()).padStart(2, '0')].join('-');
                    let minExpiry = localToday;
                    const createdDay = String(hq.created_at || '').slice(0, 10);
                    if (/^\d{4}-\d{2}-\d{2}$/.test(createdDay)) {
                        const dayAfterRegistration = new Date(createdDay + 'T00:00:00Z');
                        dayAfterRegistration.setUTCDate(dayAfterRegistration.getUTCDate() + 1);
                        minExpiry = [minExpiry, dayAfterRegistration.toISOString().slice(0, 10)].sort().pop();
                    }
                    expiryInput.min = minExpiry;
            });
        })();
    </script>
    <script>document.addEventListener('pointermove', (event) => { document.body.style.setProperty('--bg-x', `${(event.clientX / window.innerWidth) * 100}%`); document.body.style.setProperty('--bg-y', `${(event.clientY / window.innerHeight) * 100}%`); });</script>
</body>
</html>
