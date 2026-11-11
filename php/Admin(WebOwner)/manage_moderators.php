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
// Status keywords from moderator-scoped accounts in the database.
$allowedStatuses = admin_filter_user_statuses(
    "(
        LOWER(TRIM(COALESCE((SELECT role_name FROM roles WHERE role_id = u.role_id), ''))) = 'moderator'
        OR LOWER(TRIM(COALESCE((SELECT role_name FROM roles WHERE role_id = u.business_role_id), ''))) = 'moderator'
        OR LOWER(TRIM(COALESCE((SELECT role_name FROM roles WHERE role_id = u.current_role_id), ''))) = 'moderator'
    )"
);
foreach (['approved', 'pending', 'suspended', 'removed', 'rejected', 'active'] as $fallbackStatus) {
    if (!in_array($fallbackStatus, $allowedStatuses, true)) {
        $allowedStatuses[] = $fallbackStatus;
    }
}
sort($allowedStatuses);
if ($statusFilter !== '' && !in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}
if ($statusFilter === 'active') {
    $statusFilter = 'approved';
}
$statusOptions = array_values(array_filter(
    $allowedStatuses,
    static fn(string $status): bool => $status !== 'active'
));

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
        'NOT EXISTS (SELECT 1 FROM business_account_scope bas WHERE bas.user_id = u.user_id AND bas.headquarter_id IS NOT NULL)',
        'NOT EXISTS (SELECT 1 FROM headquarter owned_hq WHERE owned_hq.headquarters_owner_id = u.user_id)',
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
                 WHERE NOT EXISTS (SELECT 1 FROM business_account_scope bas WHERE bas.user_id = u.user_id AND bas.headquarter_id IS NOT NULL)
                     AND NOT EXISTS (SELECT 1 FROM headquarter owned_hq WHERE owned_hq.headquarters_owner_id = u.user_id)
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
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&amp;display=swap" rel="stylesheet">
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
        .moderator-mode-tabs { display:flex; gap:.5rem; flex-wrap:wrap; }
        .moderator-mode-tabs .btn { border-radius:999px; font-size:.8rem; font-weight:600; }
        #addAdminModal .modal-dialog { height:calc(100dvh - 1rem); max-height:calc(100dvh - 1rem); margin:.5rem auto; }
        #addAdminModal .modal-content { height:100%; max-height:100%; min-height:0; }
        #addAdminModal .modal-body { min-height:0; overflow-y:auto; }
        #addAdminModal form { flex:0 0 auto; overflow:visible; }
        .moderator-search-results { max-height:34vh; overflow:auto; border-radius:12px; }
        .moderator-search-results table { --bs-table-bg:transparent; --bs-table-color:#fff; --bs-table-border-color:rgba(255,255,255,.08); }
        .moderator-search-results th { color:rgba(255,255,255,.55); font-size:.72rem; text-transform:uppercase; letter-spacing:.05em; white-space:nowrap; }
        .moderator-search-results td { vertical-align:middle; }
        .moderator-search-results tbody tr { cursor:pointer; }
        .moderator-search-results tbody tr.is-selected { --bs-table-bg:rgba(0,244,255,.1); }
        .moderator-account-select { width:100%; text-align:left; border:0; background:transparent; color:inherit; padding:0; }
        .moderator-account-select:hover { color:var(--neon-cyan); }
        .moderator-account-select.is-selected { color:var(--neon-cyan); }
        .moderator-status-select {
            appearance:none;
            -webkit-appearance:none;
            padding-right:2.8rem !important;
            cursor:pointer;
            background-image:none !important;
        }
        .moderator-status-select:focus-visible { outline:2px solid var(--neon-cyan); outline-offset:2px; }
        .moderator-status-select-wrap { position:relative; }
        .moderator-status-select-caret {
            position:absolute;
            top:50%;
            right:1.15rem;
            width:9px;
            height:9px;
            border-right:2px solid #70b4ff;
            border-bottom:2px solid #70b4ff;
            transform:translateY(-70%) rotate(45deg);
            pointer-events:none;
        }
        #statusModeratorHint { color:#bdcbe0; font-size:.8rem; }
        body.background_color.light-mode .moderator-status-select-caret { border-color:#0b5ed7; }
        body.background_color.light-mode #statusModeratorHint { color:#46576b; }
        .moderator-selected-chip {
            display:none; align-items:center; gap:.6rem; margin-top:.65rem;
            padding:.55rem .8rem; border-radius:12px;
            background:rgba(0,244,255,.08); border:1px solid rgba(0,244,255,.22);
            color:rgba(255,255,255,.9); font-size:.85rem;
        }
        .moderator-selected-chip.show { display:flex; }
        body.background_color.light-mode .moderator-status { color:var(--text-muted); }
        body.background_color.light-mode .moderator-search-results table { --bs-table-color:#111; --bs-table-border-color:rgba(0,0,0,.08); }
        .moderator-flash {
            border-radius:14px;
            background:rgba(10,22,44,.82);
            border:1px solid var(--glass-border);
            color:var(--text-primary);
        }
        body.background_color.light-mode .moderator-flash {
            background:rgba(255,255,255,.92);
        }
        @media (max-width:575.98px) {
            #addAdminModal .modal-dialog { height:calc(100dvh - .75rem); max-height:calc(100dvh - .75rem); margin:.375rem auto; }
        }

        /* Keep this page aligned with the Users and Requests admin screens. */
        body.background_color.admin-blue-page { --ab-bg:#0b1220; --ab-surface:#121d2d; --ab-raised:#182537; --ab-border:#27364a; --ab-text:#edf3fa; --ab-muted:#a4b3c6; --ab-accent:#79aaff; --ab-warning:#f7ca72; --ab-danger:#ff9aab; --neon-cyan:var(--ab-accent); --neon-blue:#89b5ff; --glass-border:var(--ab-border); --text-primary:var(--ab-text); --text-muted:var(--ab-muted); min-height:100vh; background:radial-gradient(ellipse at 90% 0%,rgba(45,80,155,.13),transparent 48%),var(--ab-bg); background-repeat:no-repeat; color:var(--ab-text); }
        body.background_color.admin-blue-page:is(.light-mode,[data-theme="light"]) { --ab-bg:#f1f5f8; --ab-surface:#fff; --ab-raised:#f4f7fa; --ab-border:#d9e3ec; --ab-text:#172b40; --ab-muted:#53667b; --ab-accent:#285ec7; --ab-warning:#865b08; --ab-danger:#b0354c; }
        .admin-blue-page :is(.admin-blue-shell,.modal) { color:var(--ab-text); font:14px/1.65 'Poppins',system-ui,sans-serif; }.admin-blue-page .admin-blue-shell { max-width:1480px; padding-inline:clamp(16px,3.5vw,48px); margin:36px auto 24px !important; }.admin-blue-page .admin-blue-header { padding:8px 0 28px; border-bottom:1px solid var(--ab-border); }.admin-blue-page .admin-blue-header h1 { color:var(--ab-text); font-size:clamp(26px,3vw,38px); font-weight:600; letter-spacing:-.03em; }.admin-blue-page .companies-eyebrow { color:var(--ab-accent); font-size:11px; font-weight:600; letter-spacing:.13em; text-transform:uppercase; }.admin-blue-page .admin-blue-description { margin:12px 0 0; color:var(--ab-muted); font-size:13px; }
        .admin-blue-page :is(.admin-blue-shell,.modal) :is(.text-white,.modal-body h6) { color:var(--ab-text) !important; }.admin-blue-page :is(.admin-blue-shell,.modal) :is(.text-white-50,.loyalty-muted) { color:var(--ab-muted) !important; }.admin-blue-page :is(.admin-blue-shell,.modal) :is(.txt-neon-cyan,.text-success) { color:var(--ab-accent) !important; }
        .admin-blue-page .admin-blue-shell :is(.analytics-card,.dashboard-card) { padding:24px; border:1px solid var(--ab-border); border-radius:14px; background:var(--ab-surface); color:var(--ab-text); box-shadow:0 6px 24px rgba(0,0,0,.08); }.admin-blue-page .admin-blue-shell .metric-card { position:relative; min-height:148px; overflow:hidden; background:linear-gradient(135deg,rgba(121,170,255,.025),transparent 80%),var(--ab-surface); }.admin-blue-page .admin-blue-shell .metric-card::before { content:''; position:absolute; top:24px; left:0; width:3px; height:32px; border-radius:0 4px 4px 0; background:var(--ab-accent); }.admin-blue-page .admin-blue-shell .metric-label { color:var(--ab-muted); font-size:12px; font-weight:500; }.admin-blue-page .admin-blue-shell .metric-card h4 { font-size:34px !important; font-weight:600 !important; font-variant-numeric:tabular-nums; }.admin-blue-page .admin-blue-shell .metric-card .fs-3 { display:grid; place-items:center; width:44px; height:44px; border:1px solid var(--ab-border); border-radius:12px; background:var(--ab-raised); font-size:20px !important; }
        .admin-blue-page .admin-blue-shell .admin-filter-card { padding:22px 24px; }.admin-blue-page .admin-blue-shell .admin-filter-form { display:flex; flex-wrap:wrap; align-items:flex-end; gap:16px; }.admin-blue-page .admin-blue-shell .filter-group { flex:1 1 170px; min-width:0; }.admin-blue-page .admin-blue-shell .filter-group--search { flex:2 1 300px; }.admin-blue-page .admin-blue-shell .filter-actions { display:flex; flex:0 0 auto; gap:8px; }.admin-blue-page .admin-blue-shell :is(.filter-label,.filter-group label),.admin-blue-page .modal .form-label { display:block; margin-bottom:8px; color:var(--ab-muted); font-size:12px; font-weight:500; }.admin-blue-page .admin-blue-shell .filter-search { position:relative; }.admin-blue-page .admin-blue-shell .filter-search-icon { position:absolute; top:50%; left:13px; z-index:1; transform:translateY(-50%); color:var(--ab-muted); pointer-events:none; }.admin-blue-page .admin-blue-shell .filter-input { padding-left:38px !important; }
        .admin-blue-page :is(.admin-blue-shell,.modal) :is(.form-control,.form-select) { min-height:44px; border:1px solid var(--ab-border); border-radius:8px; background:var(--ab-raised); color:var(--ab-text); font:13px/1.5 'Poppins',system-ui,sans-serif; box-shadow:none; }.admin-blue-page:not(.light-mode):not([data-theme="light"]) :is(.admin-blue-shell,.modal) :is(.form-control,.form-select) { color-scheme:dark; }.admin-blue-page :is(.admin-blue-shell,.modal) :is(.form-control,.form-select):focus { border-color:var(--ab-accent); outline:2px solid var(--ab-accent); outline-offset:2px; box-shadow:none; }
        .admin-blue-page :is(.admin-blue-shell,.modal) :is(.btn,.btn-clear-filters) { display:inline-flex; align-items:center; justify-content:center; gap:6px; min-height:44px; border-radius:8px; padding:10px 16px; font:500 12px 'Poppins',system-ui,sans-serif; }.admin-blue-page :is(.admin-blue-shell,.modal) :is(.btn-neon,.btn-primary,.btn-success) { border-color:#79aaff; background:#79aaff; color:#10284c; }.admin-blue-page :is(.admin-blue-shell,.modal) :is(.btn-neon,.btn-primary,.btn-success):hover { border-color:#9cc1ff; background:#9cc1ff; color:#10284c; }.admin-blue-page .admin-blue-shell .btn-clear-filters { border:1px solid var(--ab-border); background:var(--ab-raised); color:var(--ab-text); }
        .admin-blue-page :is(.admin-blue-shell,.modal) .table-responsive { overflow:auto; border:1px solid var(--ab-border); border-radius:10px; scrollbar-color:var(--ab-border) var(--ab-raised); }.admin-blue-page :is(.admin-blue-shell,.modal) .table { --bs-table-bg:transparent; --bs-table-color:var(--ab-text); --bs-table-border-color:var(--ab-border); margin-bottom:0; color:var(--ab-text); font-size:13px; }.admin-blue-page :is(.admin-blue-shell,.modal) .table > :not(caption) > * > * { padding:16px; color:inherit; background:transparent; border-color:var(--ab-border); }.admin-blue-page :is(.admin-blue-shell,.modal) .table thead th { background:var(--ab-raised); color:var(--ab-muted); font-size:11px; font-weight:500; letter-spacing:.06em; text-transform:uppercase; white-space:nowrap; }.admin-blue-page :is(.admin-blue-shell,.modal) .table tbody tr:hover { background:var(--ab-raised); }.admin-blue-page .admin-blue-shell .table-dark-custom { min-width:960px; }
        .admin-blue-page .moderator-role,.admin-blue-page .moderator-status { border-color:var(--ab-border); background:var(--ab-raised); color:var(--ab-muted); font-size:11px; font-weight:500; }.admin-blue-page .moderator-role-admin { color:var(--ab-accent); }.admin-blue-page .moderator-role-standard { color:#b7a3ff; }.admin-blue-page .moderator-status::before { width:6px; height:6px; box-shadow:none; background:var(--ab-warning); }.admin-blue-page .moderator-status-active::before { background:var(--ab-accent); }.admin-blue-page .moderator-status-suspended::before,.admin-blue-page .moderator-status-removed::before { background:var(--ab-danger); }
        .admin-blue-page :is(.admin-blue-shell,.modal) .alert { padding:16px 20px; border:1px solid var(--ab-border); border-radius:10px; background:var(--ab-raised); color:var(--ab-text); font-size:13px; }.admin-blue-page :is(.admin-blue-shell,.modal) .alert-success { border-color:rgba(121,170,255,.4); }.admin-blue-page :is(.admin-blue-shell,.modal) .alert-danger { border-color:rgba(255,154,171,.4); }.admin-blue-page .modal .modal-content { border:1px solid var(--ab-border); border-radius:14px; background:var(--ab-surface) !important; color:var(--ab-text); }.admin-blue-page .modal :is(.modal-header,.modal-footer) { border-color:var(--ab-border); background:var(--ab-surface); }.admin-blue-page .modal .modal-body { background:var(--ab-surface); color:var(--ab-text); }.admin-blue-page:is(.light-mode,[data-theme="light"]) .btn-close-white { filter:none; }
        @media (max-width:767.98px) { .admin-blue-page .admin-blue-shell { margin-top:22px !important; }.admin-blue-page .admin-blue-shell :is(.analytics-card,.dashboard-card) { padding:20px; }.admin-blue-page .admin-blue-shell .filter-group--search,.admin-blue-page .admin-blue-shell .filter-actions { flex-basis:100%; }.admin-blue-page .admin-blue-shell .filter-actions { justify-content:flex-end; } }
    </style>
</head>
<body class="background_color admin-blue-page" id="background_color">
<?php $adminSection = 'manage_moderators'; include __DIR__ . '/admin_section_nav.php'; ?>

<main class="container my-5 admin-blue-shell">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div class="admin-blue-header flex-grow-1">
                <div class="companies-eyebrow">Security division</div>
                <h1 class="mb-0">Manage Moderators</h1>
                <p class="admin-blue-description">Add, find, and manage moderator access across the platform.</p>
            </div>
            <button class="btn btn-neon d-flex align-items-center gap-2" type="button" data-bs-toggle="modal" data-bs-target="#addAdminModal"><i class="bi bi-person-plus-fill"></i> Add moderator</button>
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
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-12">
                <div class="dashboard-card admin-filter-card">
                    <form class="admin-filter-form" method="GET" action="manage_moderators.php">
                        <div class="filter-group filter-group--search">
                            <label class="filter-label" for="moderatorSearch">Search</label>
                            <div class="filter-search">
                                <i class="bi bi-search filter-search-icon" aria-hidden="true"></i>
                                <input id="moderatorSearch" type="search" class="form-control filter-control filter-input" placeholder="Username or email…" name="search" value="<?= hq_escape($search) ?>">
                            </div>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label" for="moderatorRoleFilter">Role</label>
                            <div class="filter-select-wrap"><select id="moderatorRoleFilter" class="form-select filter-control filter-select" name="role_id">
                                <option value="0">All Roles</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?= (int) $role['role_id'] ?>" <?= $roleFilter === (int) $role['role_id'] ? 'selected' : '' ?>><?= hq_escape(moderator_role_label($role['role_name'])) ?></option>
                                <?php endforeach; ?>
                            </select></div>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label" for="moderatorStatusFilter">Status</label>
                            <div class="filter-select-wrap"><select id="moderatorStatusFilter" class="form-select filter-control filter-select" name="status">
                                <option value="">All Statuses</option>
                                <?php foreach ($statusOptions as $option): ?>
                                    <option value="<?= hq_escape($option) ?>" <?= $statusFilter === $option ? 'selected' : '' ?>><?= hq_escape(moderator_status_label($option)) ?></option>
                                <?php endforeach; ?>
                            </select></div>
                        </div>
                        <div class="filter-actions">
                            <button type="button" class="btn-clear-filters js-clear-filters" title="Clear filters">Clear</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="col-12"><div class="analytics-card"><div class="table-responsive"><table class="table table-dark-custom align-middle m-0"><thead><tr><th>Moderator</th><th>Email</th><th>Role</th><th>Last updated</th><th>Account status</th><th class="text-end">Actions</th></tr></thead><tbody>
            <?php if (!$moderatorRows): ?>
                <tr><td colspan="6" class="text-center text-white-50 py-5"><i class="bi bi-person-x fs-3 d-block mb-2"></i>No moderator accounts match the current filters.</td></tr>
            <?php else: ?>
                <?php foreach ($moderatorRows as $row): ?>
                    <?php
                    $status = (string) ($row['status'] ?? '');
                    $roleName = (string) ($row['role_name'] ?? '');
                    ?>
                    <tr>
                        <td><div class="d-flex align-items-center gap-3"><div class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width:42px;height:42px;background:rgba(0,244,255,.1);border:1px solid rgba(0,244,255,.3);flex-shrink:0;"><i class="bi bi-person-badge txt-neon-cyan"></i></div><div><div class="fw-semibold text-white"><?= hq_escape(privacy_mask_name((string) $row['user_name'])) ?></div><div class="text-white-50 small">Account ID <?= (int) $row['user_id'] ?></div></div></div></td>
                        <td class="text-white-50"><?= hq_escape(privacy_mask_email((string) $row['email'])) ?></td>
                        <td><span class="moderator-role <?= hq_escape(moderator_role_class($roleName)) ?>"><?= hq_escape(moderator_role_label($roleName)) ?></span></td>
                        <td class="text-white-50"><?= hq_escape(hq_date((string) ($row['updated_at'] ?? ''))) ?></td>
                        <td><span class="moderator-status <?= hq_escape(moderator_status_class($status)) ?>"><?= hq_escape(moderator_status_label($status)) ?></span></td>
                        <td class="text-end"><div class="d-flex gap-2 justify-content-end">
                            <button type="button" class="btn-action btn-action-danger js-status-moderator" title="Update account status" aria-label="Update status for <?= hq_escape(privacy_mask_name((string) $row['user_name'])) ?>" data-bs-toggle="modal" data-bs-target="#statusAdminModal" data-user-id="<?= (int) $row['user_id'] ?>" data-user-name="<?= hq_escape(privacy_mask_name((string) $row['user_name'])) ?>" data-status="<?= hq_escape($status) ?>"><i class="bi bi-person-x-fill"></i></button>
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
                        <h5 class="modal-title fw-bold" id="addAdminModalLabel"><i class="bi bi-person-lock txt-neon-cyan me-2"></i> Add moderator</h5>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body d-flex flex-column gap-3">
                    <div class="moderator-mode-tabs" role="tablist" aria-label="Account setup options">
                        <button type="button" class="btn btn-neon js-moderator-mode" data-mode="create" aria-pressed="true">Create account</button>
                        <button type="button" class="btn btn-outline-secondary text-white-50 border-secondary border-opacity-25 js-moderator-mode" data-mode="hire" aria-pressed="false">Invite existing account</button>
                    </div>

                    <form id="createModeratorForm" action="moderator_action.php" method="POST">
                        <input type="hidden" name="action" value="create">
                        <div class="d-flex flex-column gap-3">
                            <div>
                                <label class="form-label text-white-50 small" for="newModeratorName">Username or display name <span class="text-danger">*</span></label>
                                <input id="newModeratorName" type="text" name="user_name" class="form-control glass-input" placeholder="e.g. ei_thinzar" maxlength="225" autocomplete="username" required>
                            </div>
                            <div>
                                <label class="form-label text-white-50 small" for="newModeratorEmail">Email Address <span class="text-danger">*</span></label>
                                <input id="newModeratorEmail" type="email" name="email" class="form-control glass-input" placeholder="moderator@mapandpump.com" maxlength="255" autocomplete="email" required>
                            </div>
                            <div>
                                <label class="form-label text-white-50 small" for="newModeratorRole">Role <span class="text-danger">*</span></label>
                                <input type="hidden" name="role_id" value="<?= hq_escape($defaultRoleId) ?>">
                                <input id="newModeratorRole" class="form-control glass-input" value="Moderator" readonly>
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
                        </div>
                        <div class="d-flex justify-content-end gap-2 mt-4">
                            <button type="button" class="btn btn-outline-secondary rounded-3 text-white-50 border-secondary border-opacity-20" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-neon" <?= !$roles ? 'disabled' : '' ?>>Create account</button>
                        </div>
                    </form>

                    <form id="hireModeratorForm" action="moderator_action.php" method="POST" class="d-none" novalidate>
                        <input type="hidden" name="action" value="hire_existing">
                        <input type="hidden" name="target_user_id" id="hireTargetUserId" value="">
                        <div class="d-flex flex-column gap-3">
                            <div class="moderator-search-wrap">
                                <label class="form-label text-white-50 small" for="hireAccountSearch">Search by name or email <span class="text-danger">*</span></label>
                                <input id="hireAccountSearch" type="search" class="form-control glass-input" placeholder="Search accounts by name or email…" autocomplete="off" aria-controls="hireSearchResults">
                                <div id="hireSearchResults" class="moderator-search-results">
                                    <div class="table-responsive"><table class="table table-dark-custom align-middle m-0"><thead><tr><th>Account</th><th>Email</th><th>Company</th><th>Role</th></tr></thead><tbody id="hireAccountsBody"><tr><td colspan="4" class="text-center text-white-50 py-4">Loading accounts…</td></tr></tbody></table></div>
                                </div>
                                <div id="hireSelectedChip" class="moderator-selected-chip" aria-live="polite">
                                    <i class="bi bi-person-check txt-neon-cyan"></i>
                                    <span id="hireSelectedLabel">No account selected</span>
                                    <button type="button" class="btn btn-sm btn-link text-white-50 ms-auto p-0 js-clear-hire-target" aria-label="Clear selected account">Clear</button>
                                </div>
                            </div>
                            <div>
                                <label class="form-label text-white-50 small" for="hireModeratorRole">Moderator role <span class="text-danger">*</span></label>
                                <input type="hidden" name="role_id" value="<?= hq_escape($defaultRoleId) ?>">
                                <input id="hireModeratorRole" class="form-control glass-input" value="Moderator" readonly>
                            </div>
                            <div>
                                <label class="form-label text-white-50 small" for="hireMessage">Invitation message <span class="text-danger">*</span></label>
                                <textarea id="hireMessage" name="hire_message" class="form-control glass-input" rows="4" maxlength="2000" placeholder="Explain why they are being invited and what access they will receive." required></textarea>
                            </div>
                        </div>
                        <div class="d-flex justify-content-end gap-2 mt-4">
                            <button type="button" class="btn btn-outline-secondary rounded-3 text-white-50 border-secondary border-opacity-20" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-neon" id="hireSubmitBtn" <?= !$roles ? 'disabled' : '' ?>>Send invitation</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade glass-modal" id="statusAdminModal" tabindex="-1" aria-labelledby="statusAdminModalLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title fw-bold" id="statusAdminModalLabel"><i class="bi bi-person-x txt-neon-cyan me-2"></i> Update moderator status</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div><form action="moderator_action.php" method="POST"><input type="hidden" name="action" value="update_status"><input type="hidden" name="user_id" id="statusModeratorId"><div class="modal-body"><label class="form-label text-white-50 small" for="statusModeratorValue">Account status</label><div class="moderator-status-select-wrap"><select id="statusModeratorValue" class="form-select glass-select moderator-status-select" name="status" aria-describedby="statusModeratorHint" required><option value="approved">Approved</option><option value="suspended">Suspended</option><option value="removed">Removed</option></select><span class="moderator-status-select-caret" aria-hidden="true"></span></div><div id="statusModeratorHint" class="form-text mt-2">Select the account status to apply.</div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-3 text-white-50 border-secondary border-opacity-20" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-neon">Update status</button></div></form></div></div></div>

    

    <footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map &amp; Pump | All Rights Reserved</footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/js/Admin.js"></script>
    <script>
        document.addEventListener('pointermove', (event) => {
            document.body.style.setProperty('--bg-x', `${(event.clientX / window.innerWidth) * 100}%`);
            document.body.style.setProperty('--bg-y', `${(event.clientY / window.innerHeight) * 100}%`);
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
            const accountsBody = document.getElementById('hireAccountsBody');
            let accounts = [];
            let accountsLoaded = false;
            let loadingAccounts = false;

            function escapeHtml(value) {
                return String(value ?? '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            }

            function maskEmail(value) {
                const email = String(value ?? '').trim();
                const separator = email.indexOf('@');
                if (separator < 1) return email;
                const local = email.slice(0, separator);
                const visible = local.slice(0, 2);
                return `${visible}${'*'.repeat(Math.max(1, local.length - visible.length))}${email.slice(separator)}`;
            }

            function maskName(value) {
                return String(value ?? '').replace(/[\p{L}\p{N}][\p{L}\p{N}'’.-]*/gu, (word) => `${word.slice(0, 1)}${'*'.repeat(Math.max(1, word.length - 1))}`);
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
                if (isHire) loadAccounts();
            }

            function clearHireTarget() {
                if (targetInput) targetInput.value = '';
                if (selectedLabel) selectedLabel.textContent = 'No account selected';
                selectedChip?.classList.remove('show');
                resultsBox?.querySelectorAll('tbody tr').forEach((row) => row.classList.remove('is-selected'));
            }

            function selectHireTarget(user) {
                if (!user || !targetInput) return;
                targetInput.value = String(user.user_id || '');
                if (selectedLabel) {
                    selectedLabel.textContent = `${maskName(user.user_name || 'Account')} · ${maskEmail(user.email || '')} (${displayAccountRole(user.role_name)})`;
                }
                selectedChip?.classList.add('show');
                resultsBox?.querySelectorAll('tbody tr').forEach((row) => row.classList.toggle('is-selected', row.dataset.userId === String(user.user_id)));
            }

            function displayAccountRole(roleName) {
                const normalizedRoleName = String(roleName || '').trim().toLowerCase();
                if (['customer', 'driver'].includes(normalizedRoleName)) return 'Driver';
                if (['hq', 'headquarter', 'headquarters', 'company'].includes(normalizedRoleName)) return 'Company';
                return roleName || 'Account';
            }

            function renderAccounts() {
                if (!accountsBody) return;
                const query = (searchInput?.value || '').trim().toLocaleLowerCase();
                const filtered = accounts.filter((row) => `${row.user_name || ''} ${row.email || ''}`.toLocaleLowerCase().includes(query));
                accountsBody.innerHTML = '';
                if (!filtered.length) {
                    accountsBody.innerHTML = `<tr><td colspan="4" class="text-center text-white-50 py-4">${accounts.length ? 'No accounts match this search.' : 'No eligible accounts found.'}</td></tr>`;
                    return;
                }
                filtered.forEach((row) => {
                    const tr = document.createElement('tr');
                    tr.dataset.userId = String(row.user_id || '');
                    if (targetInput?.value === String(row.user_id)) tr.classList.add('is-selected');
                    const displayRole = displayAccountRole(row.role_name);
                    tr.innerHTML = `<td><div class="d-flex align-items-center gap-3"><div class="rounded-3 d-flex align-items-center justify-content-center fw-bold" style="width:44px;height:44px;background:rgba(0,244,255,.1);border:1px solid rgba(0,244,255,.28);color:var(--neon-cyan)">${escapeHtml(row.initials || 'A')}</div><div><div class="fw-semibold text-white">${escapeHtml(maskName(row.user_name || 'Name unavailable'))}</div><div class="text-white-50 small">Account ID: ${escapeHtml(row.user_id || '')}</div></div></div></td><td class="text-white-50">${escapeHtml(maskEmail(row.email || 'Not provided'))}</td><td class="text-white-50">${escapeHtml(row.hq_name || 'Not linked')}</td><td class="text-white-50">${escapeHtml(displayRole)}</td>`;
                    tr.addEventListener('click', () => selectHireTarget(row));
                    accountsBody.appendChild(tr);
                });
            }

            async function loadAccounts() {
                if (accountsLoaded || loadingAccounts || !accountsBody) return;
                loadingAccounts = true;
                try {
                    const response = await fetch('moderator_user_search.php', {headers:{'Accept':'application/json'},credentials:'same-origin',cache:'no-store'});
                    const payload = await response.json();
                    if (!response.ok || !payload.ok) throw new Error(payload.message || 'Could not load accounts.');
                    accounts = Array.isArray(payload.results) ? payload.results : [];
                    accountsLoaded = true;
                    renderAccounts();
                } catch (error) { accountsBody.innerHTML = `<tr><td colspan="4" class="text-center text-danger py-4">${escapeHtml(error.message || 'Could not load accounts.')}</td></tr>`; }
                finally { loadingAccounts = false; }
            }

            modeButtons.forEach((btn) => {
                btn.addEventListener('click', () => setMode(btn.dataset.mode || 'create'));
            });

            document.querySelector('.js-clear-hire-target')?.addEventListener('click', () => {
                clearHireTarget();
            });

            searchInput?.addEventListener('input', () => { clearHireTarget(); renderAccounts(); });

            hireForm?.addEventListener('submit', (event) => {
                if (!targetInput?.value) {
                    event.preventDefault();
                    alert('Select an account from the list before sending the invitation.');
                    searchInput?.focus();
                }
            });

            setMode('create');
        })();
    </script>
</body>
</html>
