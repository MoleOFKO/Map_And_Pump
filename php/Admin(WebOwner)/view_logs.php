<?php
require_once __DIR__ . '/admin_common.php';

admin_require_admin();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$flash = admin_pull_flash();
$search = trim((string) ($_GET['search'] ?? ''));
$level = strtolower(trim((string) ($_GET['log_level'] ?? 'all')));
$allowedLevels = ['all', 'info', 'warn', 'error', 'security'];
if (!in_array($level, $allowedLevels, true)) {
    $level = 'all';
}

$logs = [];
$metrics = ['total' => 0, 'security' => 0, 'warn' => 0, 'error' => 0, 'last' => null];
$dbError = null;
$moderatorLogScope = '(l.role_id = 2 OR (l.role_id IS NULL AND (u.role_id = 2 OR u.business_role_id = 2 OR u.current_role_id = 2)))';

if (hq_db_available()) {
    $metricQuery = $conn->prepare(
        "SELECT COUNT(*) AS total, MAX(l.created_at) AS last
         FROM log l
         LEFT JOIN users u ON u.user_id = l.user_id
         WHERE {$moderatorLogScope}"
    );
    if ($metricQuery && $metricQuery->execute()) {
        $metricRow = $metricQuery->get_result()->fetch_assoc() ?: [];
        $metrics['total'] = (int) ($metricRow['total'] ?? 0);
        $metrics['last'] = $metricRow['last'] ?? null;
    }
    $metricQuery?->close();
    $query = $conn->prepare(
        'SELECT l.log_id, l.user_id, l.action, l.created_at, u.user_name, u.email
         FROM log l
         LEFT JOIN users u ON u.user_id = l.user_id
         WHERE ' . $moderatorLogScope . '
           AND (LOWER(l.action) LIKE ? OR LOWER(COALESCE(u.user_name, \'\')) LIKE ?)
         ORDER BY l.created_at DESC, l.log_id DESC'
    );
    if ($query) {
        $like = '%' . strtolower($search) . '%';
        $query->bind_param('ss', $like, $like);
        $query->execute();
        $result = $query->get_result();
        while ($result && ($row = $result->fetch_assoc())) {
            $row['level'] = admin_log_level((string) $row['action']);
            $metrics[$row['level']] = ($metrics[$row['level']] ?? 0) + 1;
            if ($level === 'all' || $row['level'] === $level) {
                $logs[] = $row;
            }
        }
        $query->close();
    } else {
        $dbError = 'The audit log could not be loaded.';
    }
} else {
    $dbError = 'The log database is temporarily unavailable.';
}

$flashType = (string) ($flash['type'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Map &amp; Pump Admin — Database-backed audit logs.">
    <title>View Log Files — Map &amp; Pump</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="icon" type="image/png" href="/images/Logo.png">
    <link rel="stylesheet" href="/css/Admin.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body class="background_color" id="background_color">
    <div class="page-load-bar"></div><div class="container sticky-nav-wrapper"><nav class="navbar navbar-dark text-white navigation_style"><div class="container-fluid d-flex justify-content-between align-items-center p-0 gap-3"><a class="navbar-brand d-flex align-items-center gap-2 fw-semibold txt-neon-cyan m-0" href="admin_dashboard.php" style="font-size:.95rem;"><img src="/images/Logo.png" alt="Logo" class="nav-logo"><span class="d-none d-sm-inline">Admin Portal</span></a><div class="admin-nav-actions"><label class="cosmic-toggle nav-theme-toggle" title="Toggle light mode"><input class="toggle js-theme-toggle" type="checkbox" aria-label="Toggle light mode"><div class="slider"><span class="theme-icon theme-icon-dark"><i class="bi bi-moon-stars-fill"></i></span><span class="theme-icon theme-icon-light"><i class="bi bi-sun-fill"></i></span><div class="cosmos"></div><div class="energy-line"></div><div class="energy-line"></div><div class="energy-line"></div><div class="toggle-orb"><div class="inner-orb"></div><div class="ring"></div></div><div class="particles"><div style="--angle:30deg" class="particle"></div><div style="--angle:60deg" class="particle"></div><div style="--angle:90deg" class="particle"></div><div style="--angle:120deg" class="particle"></div><div style="--angle:150deg" class="particle"></div><div style="--angle:180deg" class="particle"></div></div></div></label><button class="btn btn-sm menu-pill-btn" type="button" data-bs-toggle="offcanvas" data-bs-target="#mainMenuOffcanvas"><i class="bi bi-grid-fill me-1 txt-neon-cyan"></i> Menu</button></div></div></nav></div>

    <div class="container my-5"><div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3"><div><span class="stat-chip mb-2"><i class="bi bi-terminal-split txt-neon-cyan"></i> Moderator Audit Trail</span><h1 class="fw-bold m-0 h4 text-white mt-2">View Log Files</h1><p class="text-white-50 m-0 small mt-1">Review actions performed by moderator accounts only.</p></div><div class="d-flex gap-2 flex-wrap"><form action="log_action.php" method="POST" class="m-0" onsubmit="return confirm('Clear the moderator audit entries currently available on this page? This cannot be undone.');"><input type="hidden" name="action" value="clear"><button type="submit" class="btn btn-outline-danger btn-sm border-danger border-opacity-20 text-danger rounded-3 d-flex align-items-center gap-2"><i class="bi bi-trash3-fill"></i> Clear Moderator Logs</button></form><a class="btn btn-neon d-flex align-items-center gap-2" href="log_action.php?action=export&amp;search=<?= rawurlencode($search) ?>&amp;log_level=<?= rawurlencode($level) ?>"><i class="bi bi-cloud-arrow-down-fill"></i> Export CSV</a></div></div>
        <?php if ($flash && $flashType !== ''): ?><div class="alert alert-<?= hq_escape($flashType) ?> border-0" role="alert"><?= hq_escape((string) ($flash['message'] ?? '')) ?></div><?php endif; ?><?php if ($dbError): ?><div class="alert alert-danger border-0" role="alert"><?= hq_escape($dbError) ?></div><?php endif; ?>
        <div class="row g-4 mb-4"><div class="col-12 col-sm-6 col-md-3"><div class="dashboard-card metric-card"><p class="mb-1 metric-label">Audit Table</p><h4 class="fw-bold text-white mb-0" style="font-size:1.15rem;">log</h4><small class="text-white-50">Moderator-scoped view</small></div></div><div class="col-12 col-sm-6 col-md-3"><div class="dashboard-card metric-card"><p class="mb-1 metric-label">Moderator Entries</p><h4 class="fw-bold text-white mb-0"><?= (int) $metrics['total'] ?></h4><small class="text-white-50">Matching audit records</small></div></div><div class="col-12 col-sm-6 col-md-3"><div class="dashboard-card metric-card"><p class="mb-1 metric-label">Security Entries</p><h4 class="fw-bold text-white mb-0"><?= (int) $metrics['security'] ?></h4><small class="text-white-50">Moderator auth and account actions</small></div></div><div class="col-12 col-sm-6 col-md-3"><div class="dashboard-card metric-card"><p class="mb-1 metric-label">Last Event</p><h4 class="fw-bold text-white mb-0" style="font-size:1.05rem;"><?= hq_escape($metrics['last'] ? admin_date((string) $metrics['last']) : 'No entries') ?></h4><small class="text-white-50">Latest moderator timestamp</small></div></div></div>
        <div class="dashboard-card py-3 px-4 mb-4"><form class="row g-3 align-items-center" method="GET" action="view_logs.php"><div class="col-12 col-md-5"><div class="input-group"><span class="input-group-text bg-transparent border-end-0" style="border-color:var(--glass-border);color:var(--neon-cyan);border-radius:12px 0 0 12px;"><i class="bi bi-terminal"></i></span><input type="text" class="form-control glass-input border-start-0" placeholder="Search action or username..." name="search" value="<?= hq_escape($search) ?>"></div></div><div class="col-12 col-md-4"><select class="form-select glass-select" name="log_level"><option value="all">All Debug &amp; Status Levels</option><?php foreach (['info' => 'INFO Outputs', 'warn' => 'WARN Outputs', 'error' => 'ERROR Outputs', 'security' => 'SECURITY Protocols'] as $value => $label): ?><option value="<?= $value ?>" <?= $level === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div><div class="col-12 col-md-3 d-grid"><button type="submit" class="btn btn-neon">Re-Scan Database Logs</button></div></form></div>
        <div class="analytics-card p-1"><div class="terminal-panel"><?php if (!$logs): ?><div><span class="log-warn">[INFO]</span> No log entries match the current filters.</div><?php else: ?><?php foreach ($logs as $log): ?><?php $logClass = $log['level'] === 'security' ? 'log-sec' : 'log-' . $log['level']; ?><div><span class="log-time">[<?= hq_escape((string) $log['created_at']) ?>]</span> <span class="<?= hq_escape($logClass) ?>">[<?= strtoupper(hq_escape((string) $log['level'])) ?>]</span>&nbsp;&nbsp;<?= hq_escape((string) $log['action']) ?><?php if (!empty($log['user_name'])): ?> <span class="txt-neon-cyan">(@<?= hq_escape((string) $log['user_name']) ?>)</span><?php endif; ?></div><?php endforeach; ?><?php endif; ?><div class="mt-2"><span class="txt-neon-cyan">&gt; Database audit scan complete.</span><span class="term-cursor"></span></div></div></div>
    </div>

    <div class="offcanvas offcanvas-end" tabindex="-1" id="mainMenuOffcanvas" style="width:320px;background:rgba(6,12,24,.98);backdrop-filter:blur(28px);border-left:1px solid rgba(0,180,255,.18);"><div class="offcanvas-header pb-2 border-bottom border-white border-opacity-10"><div><h5 class="offcanvas-title text-white fw-bold mb-0">Main Menu</h5><p class="text-white-50 small mb-0">Admin Portal</p></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button></div><div class="offcanvas-body pt-3"><ul class="list-unstyled d-flex flex-column gap-2"><li><a href="admin_dashboard.php" class="menu-btn-nav"><i class="bi bi-house me-3 txt-neon-cyan"></i> Home</a></li><li><a href="view_hq_and_user_account.php" class="menu-btn-nav"><i class="bi bi-building-gear me-3 txt-neon-cyan"></i> View/Manage HQ</a></li><li><a href="view_users.php" class="menu-btn-nav"><i class="bi bi-people me-3 txt-neon-cyan"></i> View/Manage Users</a></li><li><a href="view_requests.php" class="menu-btn-nav"><i class="bi bi-file-earmark-check me-3 txt-neon-cyan"></i> View Requests</a></li><li><a href="view_report.php" class="menu-btn-nav"><i class="bi bi-graph-up-arrow me-3 txt-neon-cyan"></i> View Report</a></li><li><a href="edit_profile.php" class="menu-btn-nav"><i class="bi bi-person-bounding-box me-3 txt-neon-blue"></i> Edit Profile</a></li><li><a href="manage_moderators.php" class="menu-btn-nav"><i class="bi bi-people-fill me-3 txt-neon-blue"></i> Manage Moderators</a></li><li><a href="view_logs.php" class="menu-btn-nav active-nav"><i class="bi bi-terminal-split me-3 text-danger"></i> View Log Files</a></li><hr style="border-color:rgba(255,255,255,.08);margin:4px 0;"><li><form action="../Login/logout.php" method="post" class="m-0"><button type="submit" class="logout-btn-nav btn border-0 w-100"><i class="bi bi-box-arrow-right me-2"></i> Logout</button></form></li></ul></div></div><footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map &amp; Pump | All Rights Reserved</footer><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script src="/js/Admin.js"></script>
</body>
</html>
