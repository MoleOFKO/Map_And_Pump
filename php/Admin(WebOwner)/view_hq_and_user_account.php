<?php
require_once __DIR__ . '/hq_account_common.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$flash = hq_pull_flash();
$search = trim((string) ($_GET['search'] ?? ''));
$region = strtolower(trim((string) ($_GET['region'] ?? '')));
$status = strtolower(trim((string) ($_GET['status'] ?? '')));
$allowedRegions = ['yangon', 'mandalay', 'naypyidaw'];
$allowedStatuses = hq_manageable_statuses();

if (!in_array($region, $allowedRegions, true)) {
    $region = '';
}
if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}

$hqRows = [];
$dbError = null;

if (hq_db_available() && hq_registry_schema_ready()) {
    // The audit table is created once if the database user has DDL permission.
    // Its failure does not prevent the HQ list itself from being displayed.
    hq_action_table_ready();

   $hqRoleId = hq_hq_role_id();
   if ($hqRoleId > 0) {
       $where = [hq_manageable_status_sql("COALESCE(owner.status, 'pending')")];
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
           if ($status === 'approved') {
               $where[] = "LOWER(COALESCE(owner.status, 'pending')) IN ('approved', 'active')";
           } else {
               $where[] = 'LOWER(COALESCE(owner.status, \'pending\')) = ?';
               $params[] = $status;
               $types .= 's';
           }
       }

       $sql = "SELECT
            h.headquarter_id,
            h.company_name,
            h.location,
            h.headquarter_email,
            h.created_at,
            owner.user_name AS owner_name,
            owner.email AS owner_email,
            COALESCE(owner.status, 'pending') AS account_status,
            COALESCE(user_counts.user_count, 0) AS user_count,
            0 AS station_count
        FROM headquarter h
        INNER JOIN (
            SELECT u.headquarter_id, MIN(u.user_id) AS owner_user_id, COUNT(*) AS user_count
            FROM users u
            WHERE (u.role_id = 3 OR u.business_role_id = 3)
            GROUP BY headquarter_id
        ) user_counts ON user_counts.headquarter_id = h.headquarter_id
        INNER JOIN users owner ON owner.user_id = user_counts.owner_user_id";

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

$regionLabel = static function (string $value): string {
    return match ($value) {
        'yangon' => 'Yangon Region',
        'mandalay' => 'Mandalay Region',
        'naypyidaw' => 'Naypyidaw',
        default => 'All Regions',
    };
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
    </style>
</head>
<body class="background_color" id="background_color">
    <div class="page-load-bar"></div>

    <div class="container sticky-nav-wrapper">
        <nav class="navbar navbar-dark text-white navigation_style">
            <div class="container-fluid d-flex justify-content-between align-items-center p-0 gap-3">
                <a class="navbar-brand d-flex align-items-center gap-2 fw-semibold txt-neon-cyan m-0" href="admin_dashboard.php" style="font-size: 0.95rem;">
                    <img src="/images/Logo.png" alt="Logo" class="nav-logo">
                    <span class="d-none d-sm-inline">Admin Portal</span>
                </a>
                <div class="admin-nav-actions">
                    <label class="cosmic-toggle nav-theme-toggle" title="Toggle light mode">
                        <input class="toggle js-theme-toggle" type="checkbox" aria-label="Toggle light mode">
                        <div class="slider">
                            <span class="theme-icon theme-icon-dark"><i class="bi bi-moon-stars-fill"></i></span>
                            <span class="theme-icon theme-icon-light"><i class="bi bi-sun-fill"></i></span>
                            <div class="cosmos"></div>
                            <div class="toggle-orb"><div class="inner-orb"></div><div class="ring"></div></div>
                        </div>
                    </label>
                    <button class="btn btn-sm menu-pill-btn" type="button" data-bs-toggle="offcanvas" data-bs-target="#mainMenuOffcanvas">
                        <i class="bi bi-grid-fill me-1 txt-neon-cyan"></i> Menu
                    </button>
                </div>
            </div>
        </nav>
    </div>

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

        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <span class="stat-chip mb-2"><i class="bi bi-building-fill txt-neon-cyan"></i> HQ Administration</span>
                <h1 class="fw-bold m-0 h4 text-white mt-2">View/Manage HQ</h1>
                <p class="text-white-50 m-0 small mt-1">View and manage approved, active, suspended, or removed role 3 HQ accounts.</p>
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
                    <form class="row g-3 align-items-center" method="GET" action="view_hq_and_user_account.php">
                        <div class="col-12 col-md-5">
                            <div class="input-group">
                                <span class="input-group-text bg-transparent border-end-0" style="border-color: var(--glass-border); color: var(--neon-cyan); border-radius: 12px 0 0 12px;"><i class="bi bi-search"></i></span>
                                <input type="text" class="form-control glass-input border-start-0" placeholder="Search by HQ or owner name..." name="search" value="<?= hq_escape($search) ?>">
                            </div>
                        </div>
                        <div class="col-12 col-md-3">
                            <select class="form-select glass-select" name="region">
                                <option value="" <?= $region === '' ? 'selected' : '' ?>>All Regions</option>
                                <?php foreach ($allowedRegions as $option): ?>
                                    <option value="<?= $option ?>" <?= $region === $option ? 'selected' : '' ?>><?= hq_escape($regionLabel($option)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-2">
                            <select class="form-select glass-select" name="status">
                                <option value="" <?= $status === '' ? 'selected' : '' ?>>All Status</option>
                                <?php foreach ($allowedStatuses as $option): ?>
                                    <option value="<?= $option ?>" <?= $status === $option ? 'selected' : '' ?>><?= hq_escape(hq_status_label($option)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-2 d-grid"><button type="submit" class="btn btn-neon">Apply Filter</button></div>
                    </form>
                </div>
            </div>

            <div class="col-12">
                <div class="analytics-card">
                    <div class="table-responsive">
                        <table class="table table-dark-custom align-middle m-0">
                            <thead>
                                <tr><th>HQ Entity &amp; Owner</th><th>Role</th><th>Headquarters Location</th><th>Status</th><th class="text-end">Command</th></tr>
                            </thead>
                            <tbody>
                                <?php if (!$hqRows): ?>
                                    <tr><td colspan="5" class="text-center text-white-50 py-5"><i class="bi bi-building-x fs-3 d-block mb-2"></i>No HQ accounts match the current filter.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($hqRows as $row): ?>
                                    <?php
                                    $companyName = (string) ($row['company_name'] ?? 'Unnamed HQ');
                                    $ownerName = (string) ($row['owner_name'] ?? 'No owner account');
                                    $ownerEmail = (string) ($row['owner_email'] ?? 'No email available');
                                    $rowStatus = (string) ($row['account_status'] ?? 'pending');
                                    $locationParts = explode(' — ', (string) ($row['location'] ?? ''), 2);
                                    $locationRegion = $locationParts[0] ?? 'Location not available';
                                    $locationAddress = $locationParts[1] ?? ($locationParts[0] ?? 'Location not available');
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
                                        <td><div class="text-white"><?= hq_escape($locationRegion) ?></div><div class="text-white-50 small" style="font-size:.8rem;"><?= hq_escape($locationAddress) ?></div></td>
                                        <td><span class="hq-status <?= hq_status_class($rowStatus) ?>"><?= hq_escape(hq_status_label($rowStatus)) ?></span></td>
                                        <td class="text-end">
                                            <div class="d-flex gap-2 justify-content-end">
                                                <a href="#" class="btn-action js-view-hq" title="View Stations &amp; Users" aria-label="View <?= hq_escape($companyName) ?> details" data-hq-id="<?= (int) $row['headquarter_id'] ?>"><i class="bi bi-eye-fill"></i></a>
                                                <a href="#" class="btn-action btn-action-danger js-account-action" title="Suspend/Remove" aria-label="Suspend or remove <?= hq_escape($companyName) ?>" data-hq-id="<?= (int) $row['headquarter_id'] ?>" data-hq-name="<?= hq_escape($companyName) ?>"><i class="bi bi-trash3-fill"></i></a>
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
                <div class="modal-header"><h5 class="modal-title fw-bold" id="accountActionModalLabel"><i class="bi bi-shield-exclamation text-danger me-2"></i> Suspend or Remove HQ</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <form action="hq_account_action.php" method="POST">
                    <div class="modal-body d-flex flex-column gap-3">
                        <input type="hidden" name="headquarter_id" id="actionHQId">
                        <div class="small text-white-50">Target HQ: <span class="txt-neon-cyan fw-semibold" id="actionHQName">—</span></div>
                        <div><label class="form-label text-white-50 small" for="actionType">Account action</label><select class="form-select glass-select" name="action_type" id="actionType" required><option value="suspend">Suspend account</option><option value="remove">Remove account</option></select></div>
                        <div><label class="form-label text-white-50 small" for="actionReason">Reason to record and notify</label><textarea class="form-control glass-input" name="reason" id="actionReason" rows="5" maxlength="2000" placeholder="Explain why this HQ account is being suspended or removed..." required style="resize:none;"></textarea></div>
                        <div class="small text-white-50"><i class="bi bi-envelope me-1 txt-neon-cyan"></i>The reason is saved to the HQ action history. Email delivery is currently a clearly marked placeholder until SMTP/provider settings are added.</div>
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

    <div class="offcanvas offcanvas-end" tabindex="-1" id="mainMenuOffcanvas" style="width:320px;background:rgba(6,12,24,.98);backdrop-filter:blur(28px);border-left:1px solid rgba(0,180,255,.18);">
        <div class="offcanvas-header pb-2 border-bottom border-white border-opacity-10"><div><h5 class="offcanvas-title text-white fw-bold mb-0">Main Menu</h5><p class="text-white-50 small mb-0" style="font-size:.78rem;">Admin Portal</p></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button></div>
        <div class="offcanvas-body pt-3"><ul class="list-unstyled d-flex flex-column gap-2">
            <li><a href="admin_dashboard.php" class="menu-btn-nav"><i class="bi bi-house me-3 txt-neon-cyan"></i> Home</a></li>
            <li><a href="view_hq_and_user_account.php" class="menu-btn-nav active-nav"><i class="bi bi-building-gear me-3 txt-neon-cyan"></i> View/Manage HQ</a></li>
            <li><a href="view_users.php" class="menu-btn-nav"><i class="bi bi-people me-3 txt-neon-cyan"></i> View/Manage Users</a></li>
            <li><a href="view_requests.php" class="menu-btn-nav"><i class="bi bi-file-earmark-check me-3 txt-neon-cyan"></i> View Requests</a></li>
            <li><a href="view_report.php" class="menu-btn-nav"><i class="bi bi-graph-up-arrow me-3 txt-neon-cyan"></i> View Report</a></li>
            <li><a href="edit_profile.php" class="menu-btn-nav"><i class="bi bi-person-bounding-box me-3 txt-neon-blue"></i> Edit Profile</a></li>
            <li><a href="manage_moderators.php" class="menu-btn-nav"><i class="bi bi-people-fill me-3 txt-neon-blue"></i> Manage Moderators</a></li>
            <li><a href="view_logs.php" class="menu-btn-nav"><i class="bi bi-terminal-split me-3 text-danger"></i> View Log Files</a></li>
            <hr style="border-color:rgba(255,255,255,.08);margin:4px 0;">
            <li><form action="../Login/logout.php" method="post" class="m-0"><button type="submit" class="logout-btn-nav btn border-0 w-100"><i class="bi bi-box-arrow-right me-2"></i> Logout</button></form></li>
        </ul></div>
    </div>

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
            const statusLabel = (status) => ({active:'Approved', approved:'Approved', suspended:'Suspended', removed:'Removed', pending:'Pending Verification', pending_verification:'Pending Verification'}[String(status || '').toLowerCase()] || (status ? String(status) : 'Unknown'));
            const stationStatusLabel = (status) => ({active:'Active', approved:'Active', decommissioned:'Decommissioned', suspended:'Suspended', removed:'Removed', offline:'Offline'}[String(status || '').toLowerCase()] || (status ? String(status) : 'Unknown'));

            actionType.addEventListener('change', () => {
                const remove = actionType.value === 'remove';
                actionSubmit.textContent = remove ? 'Confirm Remove' : 'Confirm Suspend';
                actionSubmit.classList.toggle('btn-danger', true);
            });

            document.querySelectorAll('.js-account-action').forEach((button) => {
                button.addEventListener('click', (event) => {
                    event.preventDefault();
                    actionId.value = button.dataset.hqId || '';
                    actionName.textContent = button.dataset.hqName || 'Selected HQ';
                    actionType.value = 'suspend';
                    actionSubmit.textContent = 'Confirm Suspend';
                    document.getElementById('actionReason').value = '';
                    actionModal.show();
                });
            });

            const sectionTitle = (icon, title, count) =>
                '<div class="d-flex align-items-center justify-content-between mb-2 mt-1">' +
                '<h6 class="text-white fw-semibold mb-0"><i class="bi ' + icon + ' txt-neon-cyan me-2"></i>' + title + '</h6>' +
                '<span class="badge rounded-pill" style="background:rgba(0,244,255,.12);color:#7eefff;border:1px solid rgba(0,244,255,.25);font-weight:600;">' + count + '</span></div>';

            const emptyRow = (cols, message) =>
                '<tr><td colspan="' + cols + '" class="text-center text-white-50 py-4"><i class="bi bi-inbox me-1"></i>' + escapeHtml(message) + '</td></tr>';

            const renderDetails = (payload) => {
                const hq = payload.hq || {};
                currentHQDetails = hq;
                const accounts = payload.accounts || [];
                const stations = payload.stations || [];
                const actions = payload.actions || [];
                detailsTitle.innerHTML = '<i class="bi bi-building txt-neon-cyan me-2"></i>' + escapeHtml(hq.company_name || 'HQ Details');

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
                        '<td class="text-white-50">' + escapeHtml(account.role_name || '—') + '</td>' +
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
                    metaCards +
                    sectionTitle('bi-people-fill', 'Linked user accounts', accounts.length) +
                    '<div class="table-responsive mb-4"><table class="table hq-modal-table align-middle mb-0"><thead><tr><th>User</th><th>Email</th><th>Role</th><th>Status</th><th>Created</th></tr></thead><tbody>' + accountRows + '</tbody></table></div>' +
                    sectionTitle('bi-fuel-pump-fill', 'Linked stations', stations.length) +
                    '<div class="table-responsive mb-4"><table class="table hq-modal-table align-middle mb-0"><thead><tr><th>Station</th><th>Location</th><th>Manager</th><th>Status</th><th>Created</th></tr></thead><tbody>' + stationRows + '</tbody></table></div>' +
                    sectionTitle('bi-clock-history', 'Account action history', actions.length) +
                    '<div class="table-responsive"><table class="table hq-modal-table align-middle mb-0"><thead><tr><th>Action</th><th>Reason</th><th>Recorded</th></tr></thead><tbody>' + actionRows + '</tbody></table></div>';
            };

            document.querySelectorAll('.js-view-hq').forEach((button) => {
                button.addEventListener('click', async (event) => {
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
            });

            document.querySelectorAll('.js-edit-hq-details').forEach((button) => {
                button.addEventListener('click', () => {
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
                });
            });
        })();
    </script>
    <script>document.addEventListener('pointermove', (event) => { document.body.style.setProperty('--bg-x', `${(event.clientX / window.innerWidth) * 100}%`); document.body.style.setProperty('--bg-y', `${(event.clientY / window.innerHeight) * 100}%`); });</script>
</body>
</html>
