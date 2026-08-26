<?php
require_once __DIR__ . '/moderator_common.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$flash = moderator_pull_flash();
$search = trim((string) ($_GET['search'] ?? ''));
$roleFilter = (int) ($_GET['role_id'] ?? 0);
$statusFilter = strtolower(trim((string) ($_GET['status'] ?? '')));
$allowedStatuses = ['approved', 'active', 'pending', 'suspended', 'removed', 'rejected'];
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

$roles = [];
$moderatorRows = [];
$metrics = [
    'total' => 0,
    'active' => 0,
    'suspended' => 0,
    'last_sync' => null,
];
$dbError = null;

if (moderator_schema_ready()) {
    $roles = moderator_role_options();
    $where = [
        'u.headquarter_id IS NULL',
        "LOWER(TRIM(r.role_name)) IN ('admin', 'moderator')",
    ];
    $params = [];
    $types = '';

    if ($search !== '') {
        $where[] = 'LOWER(u.user_name) LIKE ?';
        $searchLike = '%' . strtolower($search) . '%';
        $params[] = $searchLike;
        $types .= 's';
    }

    if ($roleFilter > 0) {
        $where[] = 'u.role_id = ?';
        $params[] = $roleFilter;
        $types .= 'i';
    }

    if ($statusFilter === 'active') {
        $where[] = "LOWER(u.status) IN ('approved', 'active')";
    } elseif ($statusFilter !== '') {
        $where[] = 'LOWER(u.status) = ?';
        $params[] = $statusFilter;
        $types .= 's';
    }

    $whereSql = implode(' AND ', $where);
    $metricQuery = $conn->query(
        "SELECT
            COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN LOWER(u.status) IN ('approved', 'active') THEN 1 ELSE 0 END), 0) AS active,
            COALESCE(SUM(CASE WHEN LOWER(u.status) = 'suspended' THEN 1 ELSE 0 END), 0) AS suspended,
            MAX(u.updated_at) AS last_sync
         FROM users u
         JOIN roles r ON r.role_id = u.role_id
         WHERE u.headquarter_id IS NULL
           AND LOWER(TRIM(r.role_name)) IN ('admin', 'moderator')"
    );
    if ($metricQuery) {
        $metrics = array_merge($metrics, $metricQuery->fetch_assoc() ?: []);
    } else {
        $dbError = 'The moderator summary could not be loaded.';
    }

    $listStatement = hq_prepare(
        "SELECT
            u.user_id,
            u.user_name,
            u.email,
            u.role_id,
            r.role_name,
            u.status,
            u.created_at,
            u.updated_at
         FROM users u
         JOIN roles r ON r.role_id = u.role_id
         WHERE $whereSql
         ORDER BY u.updated_at DESC, u.user_id DESC",
        $types,
        $params
    );
    if (!$listStatement || !$listStatement->execute()) {
        $dbError = 'The moderator accounts could not be loaded.';
        $listStatement?->close();
    } else {
        $result = $listStatement->get_result();
        while ($result && ($row = $result->fetch_assoc())) {
            $moderatorRows[] = $row;
        }
        $listStatement->close();
    }
} else {
    $dbError = 'The moderator database is temporarily unavailable or its required schema is incomplete.';
}

$defaultRoleId = '';
foreach ($roles as $role) {
    if (strtolower(trim((string) $role['role_name'])) === 'moderator') {
        $defaultRoleId = (string) $role['role_id'];
        break;
    }
}
$flashType = (string) ($flash['type'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Map &amp; Pump Admin — Manage system administrators and moderator accounts.">
    <title>Manage Moderators — Map &amp; Pump</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="icon" type="image/png" href="/images/Logo.png">
    <link rel="stylesheet" href="/css/Admin.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .moderator-role { display:inline-flex; align-items:center; border-radius:999px; padding:.35rem .7rem; font-size:.72rem; font-weight:700; border:1px solid; }
        .moderator-role-admin { color:#00f4ff; background:rgba(0,244,255,.1); border-color:rgba(0,244,255,.25); }
        .moderator-role-standard { color:#a78bfa; background:rgba(122,92,255,.12); border-color:rgba(167,139,250,.28); }
        .moderator-status { display:inline-flex; align-items:center; gap:.45rem; color:rgba(255,255,255,.72); font-size:.8rem; white-space:nowrap; }
        .moderator-status::before { content:''; width:8px; height:8px; border-radius:50%; display:inline-block; }
        .moderator-status-active::before { background:#1be494; box-shadow:0 0 8px #1be494; }
        .moderator-status-pending::before { background:#ffc107; box-shadow:0 0 8px #ffc107; }
        .moderator-status-suspended::before { background:#ff9f43; box-shadow:0 0 8px #ff9f43; }
        .moderator-status-removed::before { background:#ff4d6a; box-shadow:0 0 8px #ff4d6a; }
        .moderator-modal-note { color:rgba(255,255,255,.58); font-size:.78rem; line-height:1.5; }
        body.background_color.light-mode .moderator-status { color:var(--text-muted); }
        body.background_color.light-mode .moderator-modal-note { color:var(--text-muted); }
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
                        <div class="slider"><span class="theme-icon theme-icon-dark"><i class="bi bi-moon-stars-fill"></i></span><span class="theme-icon theme-icon-light"><i class="bi bi-sun-fill"></i></span><div class="cosmos"></div><div class="energy-line"></div><div class="energy-line"></div><div class="energy-line"></div><div class="toggle-orb"><div class="inner-orb"></div><div class="ring"></div></div><div class="particles"><div style="--angle: 30deg" class="particle"></div><div style="--angle: 60deg" class="particle"></div><div style="--angle: 90deg" class="particle"></div><div style="--angle: 120deg" class="particle"></div><div style="--angle: 150deg" class="particle"></div><div style="--angle: 180deg" class="particle"></div></div></div>
                    </label>
                    <form action="../Login/logout.php" method="post" class="m-0 d-inline-block">
                        <button class="btn btn-sm menu-pill-btn logout-btn-nav" type="submit"><i class="bi bi-box-arrow-right me-1 txt-neon-cyan"></i> Logout</button>
                    </form>
                    <button class="btn btn-sm menu-pill-btn" type="button" data-bs-toggle="offcanvas" data-bs-target="#mainMenuOffcanvas"><i class="bi bi-grid-fill me-1 txt-neon-cyan"></i> Menu</button>
                </div>
            </div>
        </nav>
    </div>

    <div class="container my-5">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <span class="stat-chip mb-2"><i class="bi bi-shield-lock-fill txt-neon-cyan"></i> Security Division</span>
                <h1 class="fw-bold m-0 h4 text-white mt-2">Manage System Administrators</h1>
                <p class="text-white-50 m-0 small mt-1">Create, edit, suspend, and revoke system-level moderator access.</p>
            </div>
            <button class="btn btn-neon d-flex align-items-center gap-2" type="button" data-bs-toggle="modal" data-bs-target="#addAdminModal"><i class="bi bi-person-plus-fill"></i> Add New Moderator</button>
        </div>

        <?php if ($flash && $flashType !== ''): ?>
            <div class="alert alert-<?= hq_escape($flashType) ?> border-0 shadow-sm" role="alert">
                <?= hq_escape((string) ($flash['message'] ?? '')) ?>
            </div>
        <?php endif; ?>
        <?php if ($dbError): ?>
            <div class="alert alert-danger border-0 shadow-sm" role="alert"><?= hq_escape($dbError) ?></div>
        <?php endif; ?>

        <div class="row g-4 mb-4">
            <div class="col-12 col-sm-4"><div class="dashboard-card metric-card"><div class="d-flex align-items-center justify-content-between gap-3"><div><p class="mb-0 metric-label">Total Admins</p><h4 class="fw-bold text-white mb-0 metric-value" style="font-size:1.8rem;"><?= (int) $metrics['total'] ?></h4></div><span class="fs-3 txt-neon-cyan"><i class="bi bi-people-fill"></i></span></div><p class="text-success small mb-0 mt-2"><i class="bi bi-circle-fill me-1" style="font-size:0.45rem;"></i><?= (int) $metrics['active'] ?> active accounts</p></div></div>
            <div class="col-12 col-sm-4"><div class="dashboard-card metric-card"><div class="d-flex align-items-center justify-content-between gap-3"><div><p class="mb-0 metric-label">Active Privileges</p><h4 class="fw-bold text-white mb-0" style="font-size:1.2rem; letter-spacing:-0.02em;">Admin, Moderator</h4></div><span class="fs-3 txt-neon-blue"><i class="bi bi-key-fill"></i></span></div><p class="text-white-50 small mb-0 mt-2"><?= count($roles) ?> database role tiers available</p></div></div>
            <div class="col-12 col-sm-4"><div class="dashboard-card metric-card"><div class="d-flex align-items-center justify-content-between gap-3"><div><p class="mb-0 metric-label">Last Account Update</p><h4 class="fw-bold text-white mb-0" style="font-size:1.25rem; letter-spacing:-0.03em;"><?= hq_escape($metrics['last_sync'] ? hq_date((string) $metrics['last_sync']) : 'No accounts') ?></h4></div><span class="fs-3 text-success"><i class="bi bi-shield-check"></i></span></div><p class="text-success small mb-0 mt-2"><i class="bi bi-database-check me-1"></i>Live database values</p></div></div>
        </div>

        <div class="row g-4">
            <div class="col-12"><div class="dashboard-card py-3 px-4"><form class="row g-3 align-items-center" method="GET" action="manage_moderators.php">
                <div class="col-12 col-md-5"><div class="input-group"><span class="input-group-text bg-transparent border-end-0" style="border-color:var(--glass-border);color:var(--neon-cyan);border-radius:12px 0 0 12px;"><i class="bi bi-search"></i></span><input type="text" class="form-control glass-input border-start-0" placeholder="Search username or display name..." name="search" value="<?= hq_escape($search) ?>"></div></div>
                <div class="col-12 col-md-3"><select class="form-select glass-select" name="role_id"><option value="0">All Roles</option><?php foreach ($roles as $role): ?><option value="<?= (int) $role['role_id'] ?>" <?= $roleFilter === (int) $role['role_id'] ? 'selected' : '' ?>><?= hq_escape(moderator_role_label($role['role_name'])) ?></option><?php endforeach; ?></select></div>
                <div class="col-12 col-md-2"><select class="form-select glass-select" name="status"><option value="">All Statuses</option><?php foreach ($allowedStatuses as $option): ?><option value="<?= hq_escape($option) ?>" <?= $statusFilter === $option ? 'selected' : '' ?>><?= hq_escape(moderator_status_label($option)) ?></option><?php endforeach; ?></select></div>
                <div class="col-12 col-md-2 d-grid"><button type="submit" class="btn btn-neon">Apply Filter</button></div>
            </form></div></div>

            <div class="col-12"><div class="analytics-card"><div class="table-responsive"><table class="table table-dark-custom align-middle m-0"><thead><tr><th>Admin Identity</th><th>Email Address</th><th>Designation Role</th><th>Last Account Update</th><th>Account State</th><th class="text-end">Actions</th></tr></thead><tbody>
            <?php if (!$moderatorRows): ?>
                <tr><td colspan="6" class="text-center text-white-50 py-5">No system administrator or moderator accounts match the current filters.</td></tr>
            <?php else: ?>
                <?php foreach ($moderatorRows as $row): ?>
                    <?php $status = (string) ($row['status'] ?? ''); $roleName = (string) ($row['role_name'] ?? ''); ?>
                    <tr>
                        <td><div class="d-flex align-items-center gap-3"><div class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width:42px;height:42px;background:rgba(0,244,255,.1);border:1px solid rgba(0,244,255,.3);flex-shrink:0;"><i class="bi bi-person-badge txt-neon-cyan"></i></div><div><div class="fw-semibold text-white"><?= hq_escape((string) $row['user_name']) ?></div><div class="text-white-50 small">User ID <?= (int) $row['user_id'] ?></div></div></div></td>
                        <td class="text-white-50"><?= hq_escape((string) $row['email']) ?></td>
                        <td><span class="moderator-role <?= hq_escape(moderator_role_class($roleName)) ?>"><?= hq_escape(moderator_role_label($roleName)) ?></span></td>
                        <td class="text-white-50"><?= hq_escape(hq_date((string) ($row['updated_at'] ?? ''))) ?></td>
                        <td><span class="moderator-status <?= hq_escape(moderator_status_class($status)) ?>"><?= hq_escape(moderator_status_label($status)) ?></span></td>
                        <td class="text-end"><div class="d-flex gap-2 justify-content-end">
                            <button type="button" class="btn-action js-edit-moderator" title="Modify Moderator" aria-label="Modify <?= hq_escape((string) $row['user_name']) ?>" data-bs-toggle="modal" data-bs-target="#editAdminModal" data-user-id="<?= (int) $row['user_id'] ?>" data-user-name="<?= hq_escape((string) $row['user_name']) ?>" data-email="<?= hq_escape((string) $row['email']) ?>" data-role-id="<?= (int) $row['role_id'] ?>"><i class="bi bi-pencil-fill"></i></button>
                            <button type="button" class="btn-action btn-action-danger js-status-moderator" title="Change Account Status" aria-label="Change status for <?= hq_escape((string) $row['user_name']) ?>" data-bs-toggle="modal" data-bs-target="#statusAdminModal" data-user-id="<?= (int) $row['user_id'] ?>" data-user-name="<?= hq_escape((string) $row['user_name']) ?>" data-status="<?= hq_escape($status) ?>"><i class="bi bi-person-x-fill"></i></button>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody></table></div></div></div>
        </div>
    </div>

    <div class="modal fade glass-modal" id="addAdminModal" tabindex="-1" aria-labelledby="addAdminModalLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title fw-bold" id="addAdminModalLabel"><i class="bi bi-person-lock txt-neon-cyan me-2"></i> Add New Moderator</h5><p class="moderator-modal-note mb-0 mt-1">These fields map directly to the live <code>users</code> and <code>roles</code> tables.</p></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div><form action="moderator_action.php" method="POST"><input type="hidden" name="action" value="create"><div class="modal-body d-flex flex-column gap-3"><div><label class="form-label text-white-50 small" for="newModeratorName">Username / Display Name <span class="text-danger">*</span></label><input id="newModeratorName" type="text" name="user_name" class="form-control glass-input" placeholder="e.g. ei_thinzar" maxlength="225" autocomplete="username" required><div class="moderator-modal-note mt-1">The database has one identity field, <code>users.user_name</code>; it is used for the moderator’s username/display name.</div></div><div><label class="form-label text-white-50 small" for="newModeratorEmail">Email Address <span class="text-danger">*</span></label><input id="newModeratorEmail" type="email" name="email" class="form-control glass-input" placeholder="moderator@mapandpump.com" maxlength="255" autocomplete="email" required></div><div><label class="form-label text-white-50 small" for="newModeratorRole">Database Role <span class="text-danger">*</span></label><select id="newModeratorRole" class="form-select glass-select" name="role_id" required <?= !$roles ? 'disabled' : '' ?>><option value="">Select a role</option><?php foreach ($roles as $role): ?><option value="<?= (int) $role['role_id'] ?>" <?= (string) $role['role_id'] === $defaultRoleId ? 'selected' : '' ?>><?= hq_escape(moderator_role_label($role['role_name'])) ?></option><?php endforeach; ?></select><div class="moderator-modal-note mt-1">Only Admin and Moderator roles are offered here; HQ and operational roles are managed elsewhere.</div></div><div class="row g-3"><div class="col-12 col-md-6"><label class="form-label text-white-50 small" for="newModeratorPassword">Password <span class="text-danger">*</span></label><input id="newModeratorPassword" type="password" name="password" class="form-control glass-input" minlength="8" maxlength="255" autocomplete="new-password" required></div><div class="col-12 col-md-6"><label class="form-label text-white-50 small" for="newModeratorConfirm">Confirm Password <span class="text-danger">*</span></label><input id="newModeratorConfirm" type="password" name="confirm_password" class="form-control glass-input" minlength="8" maxlength="255" autocomplete="new-password" required></div></div><div class="moderator-modal-note"><i class="bi bi-info-circle txt-neon-cyan me-1"></i><code>status</code> is set to <strong>approved</strong> automatically when an administrator provisions the account. The nullable HQ and license fields are intentionally left empty.</div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-3 text-white-50 border-secondary border-opacity-20" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-neon" <?= !$roles ? 'disabled' : '' ?>>Provision Access</button></div></form></div></div></div>

    <div class="modal fade glass-modal" id="editAdminModal" tabindex="-1" aria-labelledby="editAdminModalLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg"><div class="modal-content"><div class="modal-header"><h5 class="modal-title fw-bold" id="editAdminModalLabel"><i class="bi bi-pencil-square txt-neon-cyan me-2"></i> Edit Moderator</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div><form action="moderator_action.php" method="POST"><input type="hidden" name="action" value="update_profile"><input type="hidden" name="user_id" id="editModeratorId"><div class="modal-body d-flex flex-column gap-3"><div><label class="form-label text-white-50 small" for="editModeratorName">Username / Display Name <span class="text-danger">*</span></label><input id="editModeratorName" type="text" name="user_name" class="form-control glass-input" maxlength="225" required></div><div><label class="form-label text-white-50 small" for="editModeratorEmail">Email Address <span class="text-danger">*</span></label><input id="editModeratorEmail" type="email" name="email" class="form-control glass-input" maxlength="255" required></div><div><label class="form-label text-white-50 small" for="editModeratorRole">Database Role <span class="text-danger">*</span></label><select id="editModeratorRole" class="form-select glass-select" name="role_id" required><?php foreach ($roles as $role): ?><option value="<?= (int) $role['role_id'] ?>"><?= hq_escape(moderator_role_label($role['role_name'])) ?></option><?php endforeach; ?></select></div><div class="row g-3"><div class="col-12 col-md-6"><label class="form-label text-white-50 small" for="editModeratorPassword">New Password</label><input id="editModeratorPassword" type="password" name="password" class="form-control glass-input" minlength="8" maxlength="255" autocomplete="new-password"><div class="moderator-modal-note mt-1">Leave blank to keep the current password.</div></div><div class="col-12 col-md-6"><label class="form-label text-white-50 small" for="editModeratorConfirm">Confirm New Password</label><input id="editModeratorConfirm" type="password" name="confirm_password" class="form-control glass-input" minlength="8" maxlength="255" autocomplete="new-password"></div></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-3 text-white-50 border-secondary border-opacity-20" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-neon">Save Changes</button></div></form></div></div></div>

    <div class="modal fade glass-modal" id="statusAdminModal" tabindex="-1" aria-labelledby="statusAdminModalLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title fw-bold" id="statusAdminModalLabel"><i class="bi bi-person-x txt-neon-cyan me-2"></i> Change Moderator Status</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div><form action="moderator_action.php" method="POST"><input type="hidden" name="action" value="update_status"><input type="hidden" name="user_id" id="statusModeratorId"><div class="modal-body"><p class="text-white-50 mb-3">Choose the new database status for <strong class="text-white" id="statusModeratorName">this account</strong>.</p><label class="form-label text-white-50 small" for="statusModeratorValue">Account Status</label><select id="statusModeratorValue" class="form-select glass-select" name="status" required><option value="approved">Active / Approved</option><option value="suspended">Suspended</option><option value="removed">Revoked / Removed</option></select><div class="moderator-modal-note mt-3"><i class="bi bi-database-check txt-neon-cyan me-1"></i>This updates <code>users.status</code> in the <code>map_and_pump</code> database.</div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-3 text-white-50 border-secondary border-opacity-20" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-neon">Update Status</button></div></form></div></div></div>

    <div class="offcanvas offcanvas-end" tabindex="-1" id="mainMenuOffcanvas" style="width:320px;background:rgba(6,12,24,.98);backdrop-filter:blur(28px);border-left:1px solid rgba(0,180,255,.18);"><div class="offcanvas-header pb-2 border-bottom border-white border-opacity-10"><div><h5 class="offcanvas-title text-white fw-bold mb-0">Main Menu</h5><p class="text-white-50 small mb-0" style="font-size:.78rem;">Admin Portal</p></div><label class="cosmic-toggle float-end" title="Toggle light mode"><input class="toggle js-theme-toggle" type="checkbox" aria-label="Toggle light mode"><div class="slider"><span class="theme-icon theme-icon-dark"><i class="bi bi-moon-stars-fill"></i></span><span class="theme-icon theme-icon-light"><i class="bi bi-sun-fill"></i></span><div class="cosmos"></div><div class="energy-line"></div><div class="energy-line"></div><div class="energy-line"></div><div class="toggle-orb"><div class="inner-orb"></div><div class="ring"></div></div><div class="particles"><div style="--angle:30deg" class="particle"></div><div style="--angle:60deg" class="particle"></div><div style="--angle:90deg" class="particle"></div><div style="--angle:120deg" class="particle"></div><div style="--angle:150deg" class="particle"></div><div style="--angle:180deg" class="particle"></div></div></div></label><button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button></div><div class="offcanvas-body pt-3"><ul class="list-unstyled d-flex flex-column gap-2"><li><a href="admin_dashboard.php" class="menu-btn-nav"><i class="bi bi-house me-3 txt-neon-cyan"></i> Home</a></li><li><a href="view_hq_and_user_account.php" class="menu-btn-nav"><i class="bi bi-building-gear me-3 txt-neon-cyan"></i> View HQ &amp; User Accounts</a></li><li><a href="view_requests.php" class="menu-btn-nav"><i class="bi bi-file-earmark-check me-3 txt-neon-cyan"></i> View Requests</a></li><li><a href="view_report.php" class="menu-btn-nav"><i class="bi bi-graph-up-arrow me-3 txt-neon-cyan"></i> View Report</a></li><li><a href="edit_profile.php" class="menu-btn-nav"><i class="bi bi-person-bounding-box me-3 txt-neon-blue"></i> Edit Profile</a></li><li><a href="manage_moderators.php" class="menu-btn-nav active-nav"><i class="bi bi-people-fill me-3 txt-neon-blue"></i> Manage Moderators</a></li><li><a href="view_logs.php" class="menu-btn-nav"><i class="bi bi-terminal-split me-3 text-danger"></i> View Log Files</a></li><hr style="border-color:rgba(255,255,255,.08);margin:4px 0;"><li><form action="../Login/logout.php" method="post" class="m-0"><button type="submit" class="logout-btn-nav btn border-0 w-100"><i class="bi bi-box-arrow-right me-2"></i> Logout</button></form></li></ul></div></div>

    <footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map &amp; Pump | All Rights Reserved</footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/js/Admin.js"></script>
    <script>
        document.addEventListener('pointermove', (event) => {
            document.body.style.setProperty('--bg-x', `${(event.clientX / window.innerWidth) * 100}%`);
            document.body.style.setProperty('--bg-y', `${(event.clientY / window.innerHeight) * 100}%`);
        });

        const editModal = document.getElementById('editAdminModal');
        editModal?.addEventListener('show.bs.modal', (event) => {
            const button = event.relatedTarget;
            if (!button) return;
            document.getElementById('editModeratorId').value = button.dataset.userId || '';
            document.getElementById('editModeratorName').value = button.dataset.userName || '';
            document.getElementById('editModeratorEmail').value = button.dataset.email || '';
            document.getElementById('editModeratorRole').value = button.dataset.roleId || '';
            document.getElementById('editModeratorPassword').value = '';
            document.getElementById('editModeratorConfirm').value = '';
        });

        const statusModal = document.getElementById('statusAdminModal');
        statusModal?.addEventListener('show.bs.modal', (event) => {
            const button = event.relatedTarget;
            if (!button) return;
            document.getElementById('statusModeratorId').value = button.dataset.userId || '';
            document.getElementById('statusModeratorName').textContent = button.dataset.userName || 'this account';
            const currentStatus = String(button.dataset.status || '').toLowerCase();
            document.getElementById('statusModeratorValue').value = ['approved', 'suspended', 'removed'].includes(currentStatus) ? currentStatus : 'approved';
        });
    </script>
</body>
</html>
