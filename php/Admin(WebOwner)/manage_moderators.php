<?php
require_once __DIR__ . '/moderator_common.php';
require_once __DIR__ . '/admin_common.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Manage Moderators (create/hire/suspend staff) is admin-only.
admin_require_admin();

$flash = moderator_pull_flash();
$search = trim((string) ($_GET['search'] ?? ''));
$roleFilter = (int) ($_GET['role_id'] ?? 0);
$statusFilter = strtolower(trim((string) ($_GET['status'] ?? '')));
$allowedStatuses = ['approved', 'pending', 'suspended', 'removed', 'rejected'];
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

// Moderator accounts may be stored directly on role_id or as an existing
// account's business role. Admin accounts are deliberately out of scope.
$moderatorRolePredicate = "(
    (
        LOWER(TRIM(COALESCE(r.role_name, ''))) = 'moderator'
        OR LOWER(TRIM(COALESCE(br.role_name, ''))) = 'moderator'
        OR LOWER(TRIM(COALESCE(cr.role_name, ''))) = 'moderator'
    )
    AND LOWER(TRIM(COALESCE(r.role_name, ''))) <> 'admin'
    AND LOWER(TRIM(COALESCE(br.role_name, ''))) <> 'admin'
    AND LOWER(TRIM(COALESCE(cr.role_name, ''))) <> 'admin'
)";

if (moderator_schema_ready()) {
    $roles = moderator_role_options();
    $where = [
        'u.headquarter_id IS NULL',
        $moderatorRolePredicate,
    ];
    $params = [];
    $types = '';

    if ($search !== '') {
        $where[] = '(LOWER(u.user_name) LIKE ? OR LOWER(u.email) LIKE ?)';
        $searchLike = '%' . strtolower($search) . '%';
        $params[] = $searchLike;
        $params[] = $searchLike;
        $types .= 'ss';
    }

    if ($roleFilter > 0) {
        $where[] = '(u.role_id = ? OR u.business_role_id = ?)';
        $params[] = $roleFilter;
        $params[] = $roleFilter;
        $types .= 'ii';
    }

    if ($statusFilter === 'approved') {
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
         LEFT JOIN roles r ON r.role_id = u.role_id
         LEFT JOIN roles br ON br.role_id = u.business_role_id
         LEFT JOIN roles cr ON cr.role_id = u.current_role_id
         WHERE u.headquarter_id IS NULL
           AND $moderatorRolePredicate"
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
            u.business_role_id,
            COALESCE(br.role_name, r.role_name, cr.role_name) AS role_name,
            COALESCE(u.business_role_id, u.role_id) AS display_role_id,
            u.status,
            u.created_at,
            u.updated_at
         FROM users u
         LEFT JOIN roles r ON r.role_id = u.role_id
         LEFT JOIN roles br ON br.role_id = u.business_role_id
         LEFT JOIN roles cr ON cr.role_id = u.current_role_id
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
    <meta name="description" content="Map &amp; Pump Admin — Manage moderator accounts.">
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
        .moderator-mode-tabs { display:flex; gap:.5rem; flex-wrap:wrap; }
        .moderator-mode-tabs .btn { border-radius:999px; font-size:.8rem; font-weight:600; }
        .moderator-search-wrap { position:relative; z-index:5; }
        .moderator-search-results {
            position:absolute; left:0; right:0; top:calc(100% + 4px); z-index:30;
            max-height:min(240px, 40vh); overflow-x:hidden; overflow-y:auto;
            border-radius:12px;
            background:rgba(8,14,28,.98); border:1px solid rgba(0,244,255,.22);
            box-shadow:0 12px 32px rgba(0,0,0,.45); display:none;
            -webkit-overflow-scrolling:touch;
            scrollbar-width:thin;
            scrollbar-color:rgba(0,244,255,.45) rgba(4,12,28,.95);
        }
        .moderator-search-results.show { display:block; }
        .moderator-search-results::-webkit-scrollbar { width:8px; height:8px; }
        .moderator-search-results::-webkit-scrollbar-track {
            background:rgba(4,12,28,.95); border-radius:999px;
        }
        .moderator-search-results::-webkit-scrollbar-thumb {
            background:rgba(0,244,255,.4); border-radius:999px;
            border:2px solid rgba(4,12,28,.95);
        }
        .moderator-search-results::-webkit-scrollbar-thumb:hover {
            background:rgba(0,244,255,.6);
        }
        .moderator-search-item {
            width:100%; text-align:left; border:0; background:transparent; color:#fff;
            padding:.7rem .9rem; border-bottom:1px solid rgba(255,255,255,.06);
        }
        .moderator-search-item:hover, .moderator-search-item:focus { background:rgba(0,244,255,.08); }
        .moderator-search-item small { color:rgba(255,255,255,.55); }
        .moderator-search-item.is-loading, .moderator-search-item.is-error { pointer-events:none; }
        .moderator-selected-chip {
            display:none; align-items:center; gap:.6rem; margin-top:.65rem;
            padding:.55rem .8rem; border-radius:12px;
            background:rgba(0,244,255,.08); border:1px solid rgba(0,244,255,.22);
            color:rgba(255,255,255,.9); font-size:.85rem;
        }
        .moderator-selected-chip.show { display:flex; }
        body.background_color.light-mode .moderator-status { color:var(--text-muted); }
        body.background_color.light-mode .moderator-modal-note { color:var(--text-muted); }
        body.background_color.light-mode .moderator-search-results {
            background:#fff; border-color:rgba(0,0,0,.12); color:#111;
            scrollbar-color:rgba(11,94,215,.5) rgba(226,232,240,.95);
        }
        body.background_color.light-mode .moderator-search-results::-webkit-scrollbar-track {
            background:rgba(226,232,240,.95);
        }
        body.background_color.light-mode .moderator-search-results::-webkit-scrollbar-thumb {
            background:rgba(11,94,215,.4); border-color:rgba(226,232,240,.95);
        }
        body.background_color.light-mode .moderator-search-item { color:#111; }
        body.background_color.light-mode .moderator-search-item small { color:#666; }
        .moderator-flash {
            border-radius:14px;
            background:rgba(10,22,44,.82);
            border:1px solid var(--glass-border);
            color:var(--text-primary);
        }
        body.background_color.light-mode .moderator-flash {
            background:rgba(255,255,255,.92);
        }
    </style>
</head>
<body class="background_color" id="background_color">
<?php $adminSection = 'manage_moderators'; include __DIR__ . '/admin_section_nav.php'; ?>

<?php
$filtersActive = ($search !== '' || $roleFilter > 0 || $statusFilter !== '');
?>
<main class="container my-5">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <span class="stat-chip mb-2"><i class="bi bi-shield-lock-fill txt-neon-cyan"></i> Security Division</span>
                <h1 class="fw-bold m-0 h4 text-white mt-2">Manage Moderators</h1>
                <p class="text-white-50 m-0 small mt-1">Create, edit, suspend, and revoke Moderator access. Admin privileges are not managed here.</p>
            </div>
            <button class="btn btn-neon d-flex align-items-center gap-2" type="button" data-bs-toggle="modal" data-bs-target="#addAdminModal"><i class="bi bi-person-plus-fill"></i> Add New Moderator</button>
        </div>

        <?php if ($flash && $flashType !== ''): ?>
            <div class="alert moderator-flash alert-<?= hq_escape($flashType) ?> alert-dismissible fade show mb-4" role="alert">
                <i class="bi <?= $flashType === 'success' ? 'bi-check-circle-fill text-success' : 'bi-exclamation-triangle-fill text-danger' ?> me-2"></i><?= hq_escape((string) ($flash['message'] ?? '')) ?>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if ($dbError): ?>
            <div class="alert moderator-flash alert-danger mb-4" role="alert"><i class="bi bi-database-exclamation me-2"></i><?= hq_escape($dbError) ?></div>
        <?php endif; ?>

        <div class="row g-3 mb-4">
            <div class="col-12 col-sm-4">
                <div class="dashboard-card metric-card">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div>
                            <p class="mb-0 metric-label">Total Moderators</p>
                            <h4 class="fw-bold text-white mb-0 metric-value" style="font-size:1.8rem;"><?= (int) $metrics['total'] ?></h4>
                        </div>
                        <span class="fs-3 txt-neon-cyan"><i class="bi bi-people-fill"></i></span>
                    </div>
                    <p class="text-white-50 small mb-0 mt-2">Non-admin accounts with Moderator role</p>
                </div>
            </div>
            <div class="col-12 col-sm-4">
                <div class="dashboard-card metric-card">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div>
                            <p class="mb-0 metric-label">Active</p>
                            <h4 class="fw-bold text-white mb-0 metric-value" style="font-size:1.8rem;"><?= (int) $metrics['active'] ?></h4>
                        </div>
                        <span class="fs-3 text-success"><i class="bi bi-shield-check"></i></span>
                    </div>
                    <p class="text-success small mb-0 mt-2"><i class="bi bi-circle-fill me-1" style="font-size:0.45rem;"></i>Approved / active status</p>
                </div>
            </div>
            <div class="col-12 col-sm-4">
                <div class="dashboard-card metric-card">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div>
                            <p class="mb-0 metric-label">Suspended</p>
                            <h4 class="fw-bold text-white mb-0 metric-value" style="font-size:1.8rem;"><?= (int) $metrics['suspended'] ?></h4>
                        </div>
                        <span class="fs-3" style="color:#ff9f43;"><i class="bi bi-pause-circle-fill"></i></span>
                    </div>
                    <p class="text-white-50 small mb-0 mt-2">
                        <?php if (!empty($metrics['last_sync'])): ?>
                            Last update <?= hq_escape(hq_date((string) $metrics['last_sync'])) ?>
                        <?php else: ?>
                            No moderator accounts yet
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-12">
                <div class="dashboard-card py-3 px-4">
                    <form class="row g-3 align-items-end" method="GET" action="manage_moderators.php">
                        <div class="col-12 col-md-4">
                            <label class="form-label text-white-50 small mb-1" for="moderatorSearch">Search</label>
                            <div class="input-group">
                                <span class="input-group-text bg-transparent border-end-0" style="border-color:var(--glass-border);color:var(--neon-cyan);border-radius:12px 0 0 12px;"><i class="bi bi-search"></i></span>
                                <input id="moderatorSearch" type="search" class="form-control glass-input border-start-0" placeholder="Username or email…" name="search" value="<?= hq_escape($search) ?>">
                            </div>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label text-white-50 small mb-1" for="moderatorRoleFilter">Role</label>
                            <select id="moderatorRoleFilter" class="form-select glass-select" name="role_id">
                                <option value="0">All Roles</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?= (int) $role['role_id'] ?>" <?= $roleFilter === (int) $role['role_id'] ? 'selected' : '' ?>><?= hq_escape(moderator_role_label($role['role_name'])) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-2">
                            <label class="form-label text-white-50 small mb-1" for="moderatorStatusFilter">Status</label>
                            <select id="moderatorStatusFilter" class="form-select glass-select" name="status">
                                <option value="">All Statuses</option>
                                <?php foreach ($allowedStatuses as $option): ?>
                                    <option value="<?= hq_escape($option) ?>" <?= $statusFilter === $option ? 'selected' : '' ?>><?= hq_escape(moderator_status_label($option)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-3 d-flex gap-2">
                            <button type="submit" class="btn btn-neon flex-grow-1">Apply Filter</button>
                            <?php if ($filtersActive): ?>
                                <a href="manage_moderators.php" class="btn btn-outline-secondary text-white-50 border-secondary border-opacity-25" title="Clear filters">Clear</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <div class="col-12"><div class="analytics-card"><div class="table-responsive"><table class="table table-dark-custom align-middle m-0"><thead><tr><th>Moderator Identity</th><th>Email Address</th><th>Designation Role</th><th>Last Account Update</th><th>Account State</th><th class="text-end">Actions</th></tr></thead><tbody>
            <?php if (!$moderatorRows): ?>
                <tr><td colspan="6" class="text-center text-white-50 py-5"><i class="bi bi-person-x fs-3 d-block mb-2"></i>No moderator accounts match the current filters.</td></tr>
            <?php else: ?>
                <?php foreach ($moderatorRows as $row): ?>
                    <?php
                    $status = (string) ($row['status'] ?? '');
                    $roleName = (string) ($row['role_name'] ?? '');
                    $displayRoleId = (int) ($row['display_role_id'] ?? $row['role_id'] ?? 0);
                    ?>
                    <tr>
                        <td><div class="d-flex align-items-center gap-3"><div class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width:42px;height:42px;background:rgba(0,244,255,.1);border:1px solid rgba(0,244,255,.3);flex-shrink:0;"><i class="bi bi-person-badge txt-neon-cyan"></i></div><div><div class="fw-semibold text-white"><?= hq_escape((string) $row['user_name']) ?></div><div class="text-white-50 small">User ID <?= (int) $row['user_id'] ?></div></div></div></td>
                        <td class="text-white-50"><?= hq_escape((string) $row['email']) ?></td>
                        <td><span class="moderator-role <?= hq_escape(moderator_role_class($roleName)) ?>"><?= hq_escape(moderator_role_label($roleName)) ?></span></td>
                        <td class="text-white-50"><?= hq_escape(hq_date((string) ($row['updated_at'] ?? ''))) ?></td>
                        <td><span class="moderator-status <?= hq_escape(moderator_status_class($status)) ?>"><?= hq_escape(moderator_status_label($status)) ?></span></td>
                        <td class="text-end"><div class="d-flex gap-2 justify-content-end">
                            <button type="button" class="btn-action js-edit-moderator" title="Modify Moderator" aria-label="Modify <?= hq_escape((string) $row['user_name']) ?>" data-bs-toggle="modal" data-bs-target="#editAdminModal" data-user-id="<?= (int) $row['user_id'] ?>" data-user-name="<?= hq_escape((string) $row['user_name']) ?>" data-email="<?= hq_escape((string) $row['email']) ?>" data-role-id="<?= $displayRoleId ?>"><i class="bi bi-pencil-fill"></i></button>
                            <button type="button" class="btn-action btn-action-danger js-status-moderator" title="Change Account Status" aria-label="Change status for <?= hq_escape((string) $row['user_name']) ?>" data-bs-toggle="modal" data-bs-target="#statusAdminModal" data-user-id="<?= (int) $row['user_id'] ?>" data-user-name="<?= hq_escape((string) $row['user_name']) ?>" data-status="<?= hq_escape($status) ?>"><i class="bi bi-person-x-fill"></i></button>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody></table></div></div></div>
        </div>
    </div>
</main>

    <div class="modal fade glass-modal" id="addAdminModal" tabindex="-1" aria-labelledby="addAdminModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title fw-bold" id="addAdminModalLabel"><i class="bi bi-person-lock txt-neon-cyan me-2"></i> Add New Moderator</h5>
                        <p class="moderator-modal-note mb-0 mt-1">Create a Moderator account, or grant Moderator access to an eligible existing account. This workflow cannot create Admin accounts.</p>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body d-flex flex-column gap-3">
                    <div class="moderator-mode-tabs" role="tablist" aria-label="Provision mode">
                        <button type="button" class="btn btn-neon js-moderator-mode" data-mode="create" aria-pressed="true">Create new account</button>
                        <button type="button" class="btn btn-outline-secondary text-white-50 border-secondary border-opacity-25 js-moderator-mode" data-mode="hire" aria-pressed="false">Hire existing account</button>
                    </div>

                    <form id="createModeratorForm" action="moderator_action.php" method="POST">
                        <input type="hidden" name="action" value="create">
                        <div class="d-flex flex-column gap-3">
                            <div>
                                <label class="form-label text-white-50 small" for="newModeratorName">Username / Display Name <span class="text-danger">*</span></label>
                                <input id="newModeratorName" type="text" name="user_name" class="form-control glass-input" placeholder="e.g. ei_thinzar" maxlength="225" autocomplete="username" required>
                                <div class="moderator-modal-note mt-1">Maps to <code>users.user_name</code>.</div>
                            </div>
                            <div>
                                <label class="form-label text-white-50 small" for="newModeratorEmail">Email Address <span class="text-danger">*</span></label>
                                <input id="newModeratorEmail" type="email" name="email" class="form-control glass-input" placeholder="moderator@mapandpump.com" maxlength="255" autocomplete="email" required>
                            </div>
                            <div>
                                <label class="form-label text-white-50 small" for="newModeratorRole">Database Role <span class="text-danger">*</span></label>
                                <select id="newModeratorRole" class="form-select glass-select" name="role_id" required <?= !$roles ? 'disabled' : '' ?>>
                                    <option value="">Select a role</option>
                                    <?php foreach ($roles as $role): ?>
                                        <option value="<?= (int) $role['role_id'] ?>" <?= (string) $role['role_id'] === $defaultRoleId ? 'selected' : '' ?>><?= hq_escape(moderator_role_label($role['role_name'])) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="moderator-modal-note mt-1">Only the Moderator role is offered here. Admin privileges cannot be granted from this panel.</div>
                            </div>
                            <div class="row g-3">
                                <div class="col-12 col-md-6">
                                    <label class="form-label text-white-50 small" for="newModeratorPassword">Password <span class="text-danger">*</span></label>
                                    <input id="newModeratorPassword" type="password" name="password" class="form-control glass-input" minlength="8" maxlength="255" autocomplete="new-password" required>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label text-white-50 small" for="newModeratorConfirm">Confirm Password <span class="text-danger">*</span></label>
                                    <input id="newModeratorConfirm" type="password" name="confirm_password" class="form-control glass-input" minlength="8" maxlength="255" autocomplete="new-password" required>
                                </div>
                            </div>
                            <div class="moderator-modal-note"><i class="bi bi-info-circle txt-neon-cyan me-1"></i><code>status</code> is set to <strong>approved</strong> when the account is created.</div>
                        </div>
                        <div class="d-flex justify-content-end gap-2 mt-4">
                            <button type="button" class="btn btn-outline-secondary rounded-3 text-white-50 border-secondary border-opacity-20" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-neon" <?= !$roles ? 'disabled' : '' ?>>Provision Access</button>
                        </div>
                    </form>

                    <form id="hireModeratorForm" action="moderator_action.php" method="POST" class="d-none" novalidate>
                        <input type="hidden" name="action" value="hire_existing">
                        <input type="hidden" name="target_user_id" id="hireTargetUserId" value="">
                        <div class="d-flex flex-column gap-3">
                            <div class="moderator-search-wrap">
                                <label class="form-label text-white-50 small" for="hireAccountSearch">Search by name or email <span class="text-danger">*</span></label>
                                <input id="hireAccountSearch" type="search" class="form-control glass-input" placeholder="Type at least 2 characters…" autocomplete="off" aria-autocomplete="list" aria-controls="hireSearchResults">
                                <div id="hireSearchResults" class="moderator-search-results" role="listbox"></div>
                                <div id="hireSelectedChip" class="moderator-selected-chip" aria-live="polite">
                                    <i class="bi bi-person-check txt-neon-cyan"></i>
                                    <span id="hireSelectedLabel">No account selected</span>
                                    <button type="button" class="btn btn-sm btn-link text-white-50 ms-auto p-0 js-clear-hire-target" aria-label="Clear selected account">Clear</button>
                                </div>
                                <div class="moderator-modal-note mt-1">Existing Admin and Moderator accounts are excluded. Matching eligible users appear in the dropdown as you type.</div>
                            </div>
                            <div>
                                <label class="form-label text-white-50 small" for="hireModeratorRole">Offered Moderator role <span class="text-danger">*</span></label>
                                <select id="hireModeratorRole" class="form-select glass-select" name="role_id" required <?= !$roles ? 'disabled' : '' ?>>
                                    <option value="">Select a role</option>
                                    <?php foreach ($roles as $role): ?>
                                        <option value="<?= (int) $role['role_id'] ?>" <?= (string) $role['role_id'] === $defaultRoleId ? 'selected' : '' ?>><?= hq_escape(moderator_role_label($role['role_name'])) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="form-label text-white-50 small" for="hireMessage">Hire message <span class="text-danger">*</span></label>
                                <textarea id="hireMessage" name="hire_message" class="form-control glass-input" rows="4" maxlength="2000" placeholder="Explain why they are being invited and what access they will receive." required></textarea>
                                <div class="moderator-modal-note mt-1">Saved in the database and shown as a popup the next time that account signs in.</div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-end gap-2 mt-4">
                            <button type="button" class="btn btn-outline-secondary rounded-3 text-white-50 border-secondary border-opacity-20" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-neon" id="hireSubmitBtn" <?= !$roles ? 'disabled' : '' ?>>Provision Access</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade glass-modal" id="editAdminModal" tabindex="-1" aria-labelledby="editAdminModalLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg"><div class="modal-content"><div class="modal-header"><h5 class="modal-title fw-bold" id="editAdminModalLabel"><i class="bi bi-pencil-square txt-neon-cyan me-2"></i> Edit Moderator</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div><form action="moderator_action.php" method="POST"><input type="hidden" name="action" value="update_profile"><input type="hidden" name="user_id" id="editModeratorId"><div class="modal-body d-flex flex-column gap-3"><div><label class="form-label text-white-50 small" for="editModeratorName">Username / Display Name <span class="text-danger">*</span></label><input id="editModeratorName" type="text" name="user_name" class="form-control glass-input" maxlength="225" required></div><div><label class="form-label text-white-50 small" for="editModeratorEmail">Email Address <span class="text-danger">*</span></label><input id="editModeratorEmail" type="email" name="email" class="form-control glass-input" maxlength="255" required></div><div><label class="form-label text-white-50 small" for="editModeratorRole">Database Role <span class="text-danger">*</span></label><select id="editModeratorRole" class="form-select glass-select" name="role_id" required><?php foreach ($roles as $role): ?><option value="<?= (int) $role['role_id'] ?>"><?= hq_escape(moderator_role_label($role['role_name'])) ?></option><?php endforeach; ?></select><div class="moderator-modal-note mt-1">Password changes are available only to the account holder through Edit Profile.</div></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-3 text-white-50 border-secondary border-opacity-20" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-neon">Save Changes</button></div></form></div></div></div>

    <div class="modal fade glass-modal" id="statusAdminModal" tabindex="-1" aria-labelledby="statusAdminModalLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title fw-bold" id="statusAdminModalLabel"><i class="bi bi-person-x txt-neon-cyan me-2"></i> Change Moderator Status</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div><form action="moderator_action.php" method="POST"><input type="hidden" name="action" value="update_status"><input type="hidden" name="user_id" id="statusModeratorId"><div class="modal-body"><p class="text-white-50 mb-3">Choose the new database status for <strong class="text-white" id="statusModeratorName">this account</strong>.</p><label class="form-label text-white-50 small" for="statusModeratorValue">Account Status</label><select id="statusModeratorValue" class="form-select glass-select" name="status" required><option value="approved">Approved</option><option value="suspended">Suspended</option><option value="removed">Revoked / Removed</option></select><div class="moderator-modal-note mt-3"><i class="bi bi-database-check txt-neon-cyan me-1"></i>This updates <code>users.status</code> in the <code>map_and_pump</code> database.</div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-3 text-white-50 border-secondary border-opacity-20" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-neon">Update Status</button></div></form></div></div></div>

    

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

        (function initModeratorProvisionModes() {
            const createForm = document.getElementById('createModeratorForm');
            const hireForm = document.getElementById('hireModeratorForm');
            const modeButtons = document.querySelectorAll('.js-moderator-mode');
            const searchInput = document.getElementById('hireAccountSearch');
            const resultsBox = document.getElementById('hireSearchResults');
            const targetInput = document.getElementById('hireTargetUserId');
            const selectedChip = document.getElementById('hireSelectedChip');
            const selectedLabel = document.getElementById('hireSelectedLabel');
            let searchTimer = null;
            let lastQuery = '';
            let searchAbort = null;
            let searchSeq = 0;

            function escapeHtml(value) {
                return String(value ?? '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            }

            function setMode(mode) {
                const isHire = mode === 'hire';
                createForm?.classList.toggle('d-none', isHire);
                hireForm?.classList.toggle('d-none', !isHire);
                modeButtons.forEach((btn) => {
                    const active = btn.dataset.mode === mode;
                    btn.classList.toggle('btn-neon', active);
                    btn.classList.toggle('btn-outline-secondary', !active);
                    btn.classList.toggle('text-white-50', !active);
                    btn.setAttribute('aria-pressed', active ? 'true' : 'false');
                });
            }

            function clearHireTarget() {
                if (targetInput) targetInput.value = '';
                if (selectedLabel) selectedLabel.textContent = 'No account selected';
                selectedChip?.classList.remove('show');
            }

            function selectHireTarget(user) {
                if (!user || !targetInput) return;
                targetInput.value = String(user.user_id || '');
                if (selectedLabel) {
                    selectedLabel.textContent = `${user.user_name || 'User'} · ${user.email || ''} (${user.role_name || 'role'})`;
                }
                selectedChip?.classList.add('show');
                if (searchInput) searchInput.value = user.email || user.user_name || '';
                resultsBox?.classList.remove('show');
                if (resultsBox) resultsBox.innerHTML = '';
            }

            function renderMessage(text, kind) {
                if (!resultsBox) return;
                resultsBox.innerHTML = `<div class="moderator-search-item ${kind || ''}"><small>${escapeHtml(text)}</small></div>`;
                resultsBox.classList.add('show');
            }

            function renderResults(results) {
                if (!resultsBox) return;
                resultsBox.innerHTML = '';
                if (!Array.isArray(results) || results.length === 0) {
                    renderMessage('No matching accounts', 'is-error');
                    return;
                }
                results.forEach((row) => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'moderator-search-item';
                    button.setAttribute('role', 'option');
                    button.innerHTML = `<div class="fw-semibold">${escapeHtml(row.user_name || '')}</div><small>${escapeHtml(row.email || '')} · ${escapeHtml(row.role_name || '')}</small>`;
                    button.addEventListener('click', () => selectHireTarget(row));
                    resultsBox.appendChild(button);
                });
                resultsBox.classList.add('show');
            }

            async function runSearch(query) {
                if (query.length < 2) {
                    if (searchAbort) {
                        searchAbort.abort();
                        searchAbort = null;
                    }
                    resultsBox?.classList.remove('show');
                    if (resultsBox) resultsBox.innerHTML = '';
                    return;
                }

                if (searchAbort) {
                    searchAbort.abort();
                }
                const controller = new AbortController();
                searchAbort = controller;
                const seq = ++searchSeq;

                renderMessage('Searching…', 'is-loading');

                try {
                    const response = await fetch(
                        `moderator_user_search.php?q=${encodeURIComponent(query)}`,
                        {
                            headers: { 'Accept': 'application/json' },
                            credentials: 'same-origin',
                            signal: controller.signal,
                            cache: 'no-store',
                        }
                    );
                    if (seq !== searchSeq) return;

                    let payload = null;
                    try {
                        payload = await response.json();
                    } catch (parseError) {
                        renderMessage('Search response was invalid. Try again.', 'is-error');
                        return;
                    }

                    if (!response.ok || !payload || !payload.ok) {
                        const msg = (payload && payload.message) ? payload.message : 'Search failed. Try again.';
                        renderMessage(msg, 'is-error');
                        return;
                    }
                    renderResults(payload.results || []);
                } catch (error) {
                    if (error && error.name === 'AbortError') {
                        return;
                    }
                    if (seq !== searchSeq) return;
                    renderMessage('Network error while searching. Try again.', 'is-error');
                }
            }

            modeButtons.forEach((btn) => {
                btn.addEventListener('click', () => setMode(btn.dataset.mode || 'create'));
            });

            document.querySelector('.js-clear-hire-target')?.addEventListener('click', () => {
                clearHireTarget();
                if (searchInput) searchInput.value = '';
                resultsBox?.classList.remove('show');
                if (resultsBox) resultsBox.innerHTML = '';
            });

            searchInput?.addEventListener('input', () => {
                const query = searchInput.value.trim();
                if (query !== lastQuery) {
                    clearHireTarget();
                }
                lastQuery = query;
                window.clearTimeout(searchTimer);
                searchTimer = window.setTimeout(() => runSearch(query), 280);
            });

            searchInput?.addEventListener('focus', () => {
                if ((resultsBox?.children?.length || 0) > 0) {
                    resultsBox.classList.add('show');
                }
            });

            document.addEventListener('click', (event) => {
                if (!resultsBox || !searchInput) return;
                if (event.target === searchInput || resultsBox.contains(event.target)) return;
                resultsBox.classList.remove('show');
            });

            hireForm?.addEventListener('submit', (event) => {
                if (!targetInput?.value) {
                    event.preventDefault();
                    alert('Select an existing account from the search dropdown before provisioning access.');
                    searchInput?.focus();
                }
            });

            setMode('create');
        })();
    </script>
</body>
</html>
