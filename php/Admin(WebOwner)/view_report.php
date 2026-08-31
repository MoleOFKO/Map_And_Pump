<?php
require_once __DIR__ . '/admin_common.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$flash = admin_pull_flash();
$search = trim((string) ($_GET['search'] ?? ''));
$scopeFilter = strtolower(trim((string) ($_GET['scope'] ?? '')));
$formatFilter = strtolower(trim((string) ($_GET['format'] ?? '')));
$allowedScopes = ['hq_performance', 'stations', 'requests', 'system'];
$allowedFormats = ['pdf', 'csv', 'record'];
if (!in_array($scopeFilter, $allowedScopes, true)) {
    $scopeFilter = '';
}
if (!in_array($formatFilter, $allowedFormats, true)) {
    $formatFilter = '';
}

$reports = [];
$allReports = [];
$metrics = ['hqs' => 0, 'stations' => 0, 'requests' => 0, 'reports' => 0, 'reports_today' => 0];
$scopeCounts = ['hq_performance' => 0, 'stations' => 0, 'requests' => 0, 'system' => 0, 'audit_logs' => 0];
$dbError = null;

if (hq_db_available()) {
    $queries = [
        'hqs' => 'SELECT COUNT(*) AS total FROM headquarter',
        'stations' => 'SELECT COUNT(*) AS total FROM stations',
        'requests' => 'SELECT COUNT(*) AS total FROM system_requests',
    ];
    foreach ($queries as $key => $sql) {
        $query = $conn->query($sql);
        if ($query) {
            $metrics[$key] = (int) (($query->fetch_assoc()['total'] ?? 0));
        }
    }
    $reportMetric = $conn->query("SELECT COUNT(*) AS total, COALESCE(SUM(DATE(created_at) = CURDATE()), 0) AS today FROM report");
    if ($reportMetric) {
        $metricRow = $reportMetric->fetch_assoc() ?: [];
        $metrics['reports'] = (int) ($metricRow['total'] ?? 0);
        $metrics['reports_today'] = (int) ($metricRow['today'] ?? 0);
    }

    $reportQuery = $conn->query(
        'SELECT p.report_id, p.short_summery, p.message, p.importance, p.created_at,
                u.user_name, r.role_name
         FROM report p
         LEFT JOIN users u ON u.user_id = p.user_id
         LEFT JOIN roles r ON r.role_id = p.role_id
         ORDER BY p.created_at DESC, p.report_id DESC'
    );
    if ($reportQuery) {
        while ($row = $reportQuery->fetch_assoc()) {
            $row['metadata'] = admin_report_metadata((string) $row['message']);
            $allReports[] = $row;
        }
    } else {
        $dbError = 'The report records could not be loaded.';
    }
} else {
    $dbError = 'The report database is temporarily unavailable.';
}

foreach ($allReports as $row) {
    $metadata = $row['metadata'];
    $scope = (string) $metadata['scope'];
    $scopeCounts[$scope] = ($scopeCounts[$scope] ?? 0) + 1;
    $haystack = strtolower(implode(' ', [(string) $row['report_id'], (string) $row['short_summery'], (string) $row['user_name']]));
    if ($search !== '' && !str_contains($haystack, strtolower($search))) {
        continue;
    }
    if ($scopeFilter !== '' && $scope !== $scopeFilter) {
        continue;
    }
    if ($formatFilter !== '' && (string) $metadata['format'] !== $formatFilter) {
        continue;
    }
    $reports[] = $row;
}

$flashType = (string) ($flash['type'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Map &amp; Pump Admin — Database-backed system reports and logs.">
    <title>View Report — Map &amp; Pump</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="icon" type="image/png" href="/images/Logo.png">
    <link rel="stylesheet" href="/css/Admin.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>.report-scope{display:inline-flex;border-radius:999px;padding:.35rem .7rem;font-size:.72rem;font-weight:700;background:rgba(0,244,255,.08);color:var(--neon-cyan);border:1px solid rgba(0,244,255,.24)}.report-message{max-width:460px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}</style>
</head>
<body class="background_color" id="background_color">
    <div class="page-load-bar"></div>
    <div class="container sticky-nav-wrapper"><nav class="navbar navbar-dark text-white navigation_style"><div class="container-fluid d-flex justify-content-between align-items-center p-0 gap-3"><a class="navbar-brand d-flex align-items-center gap-2 fw-semibold txt-neon-cyan m-0" href="admin_dashboard.php" style="font-size:.95rem;"><img src="/images/Logo.png" alt="Logo" class="nav-logo"><span class="d-none d-sm-inline">Admin Portal</span></a><div class="admin-nav-actions"><label class="cosmic-toggle nav-theme-toggle" title="Toggle light mode"><input class="toggle js-theme-toggle" type="checkbox" aria-label="Toggle light mode"><div class="slider"><span class="theme-icon theme-icon-dark"><i class="bi bi-moon-stars-fill"></i></span><span class="theme-icon theme-icon-light"><i class="bi bi-sun-fill"></i></span><div class="cosmos"></div><div class="energy-line"></div><div class="energy-line"></div><div class="energy-line"></div><div class="toggle-orb"><div class="inner-orb"></div><div class="ring"></div></div><div class="particles"><div style="--angle:30deg" class="particle"></div><div style="--angle:60deg" class="particle"></div><div style="--angle:90deg" class="particle"></div><div style="--angle:120deg" class="particle"></div><div style="--angle:150deg" class="particle"></div><div style="--angle:180deg" class="particle"></div></div></div></label><button class="btn btn-sm menu-pill-btn" type="button" data-bs-toggle="offcanvas" data-bs-target="#mainMenuOffcanvas"><i class="bi bi-grid-fill me-1 txt-neon-cyan"></i> Menu</button></div></div></nav></div>

    <div class="container my-5"><div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3"><div><span class="stat-chip mb-2"><i class="bi bi-bar-chart-line-fill txt-neon-cyan"></i> Analytics Center</span><h1 class="fw-bold m-0 h4 text-white mt-2">System Reports &amp; Logs</h1><p class="text-white-50 m-0 small mt-1">Generate, filter, download, and remove reports stored in the live database.</p></div><button class="btn btn-neon d-flex align-items-center gap-2" type="button" data-bs-toggle="modal" data-bs-target="#generateReportModal"><i class="bi bi-file-earmark-plus-fill"></i> Generate Report</button></div>
        <?php if ($flash && $flashType !== ''): ?><div class="alert alert-<?= hq_escape($flashType) ?> border-0" role="alert"><?= hq_escape((string) ($flash['message'] ?? '')) ?></div><?php endif; ?>
        <?php if ($dbError): ?><div class="alert alert-danger border-0" role="alert"><?= hq_escape($dbError) ?></div><?php endif; ?>

        <div class="row g-4 mb-4"><div class="col-12 col-sm-6 col-md-3"><div class="dashboard-card metric-card"><p class="mb-1 metric-label">Total HQs Registered</p><h4 class="fw-bold text-white mb-0"><?= (int) $metrics['hqs'] ?></h4><small class="text-white-50">Live headquarter records</small></div></div><div class="col-12 col-sm-6 col-md-3"><div class="dashboard-card metric-card"><p class="mb-1 metric-label">Active Stations</p><h4 class="fw-bold text-white mb-0"><?= (int) $metrics['stations'] ?></h4><small class="text-white-50">Live station records</small></div></div><div class="col-12 col-sm-6 col-md-3"><div class="dashboard-card metric-card"><p class="mb-1 metric-label">Requests Stored</p><h4 class="fw-bold text-white mb-0"><?= (int) $metrics['requests'] ?></h4><small class="text-white-50">System request records</small></div></div><div class="col-12 col-sm-6 col-md-3"><div class="dashboard-card metric-card"><p class="mb-1 metric-label">Reports Generated</p><h4 class="fw-bold text-white mb-0"><?= (int) $metrics['reports'] ?></h4><small class="text-success"><?= (int) $metrics['reports_today'] ?> created today</small></div></div></div>

        <div class="dashboard-card py-3 px-4 mb-4"><form class="row g-3 align-items-center" method="GET" action="view_report.php"><div class="col-12 col-md-5"><div class="input-group"><span class="input-group-text bg-transparent border-end-0" style="border-color:var(--glass-border);color:var(--neon-cyan);border-radius:12px 0 0 12px;"><i class="bi bi-search"></i></span><input type="text" class="form-control glass-input border-start-0" placeholder="Search by report name, ID, or author..." name="search" value="<?= hq_escape($search) ?>"></div></div><div class="col-12 col-md-3"><select class="form-select glass-select" name="scope"><option value="">All Scopes</option><?php foreach ($allowedScopes as $option): ?><option value="<?= hq_escape($option) ?>" <?= $scopeFilter === $option ? 'selected' : '' ?>><?= hq_escape(admin_scope_label($option)) ?></option><?php endforeach; ?></select></div><div class="col-12 col-md-2"><select class="form-select glass-select" name="format"><option value="">All Formats</option><option value="pdf" <?= $formatFilter === 'pdf' ? 'selected' : '' ?>>Print / PDF</option><option value="csv" <?= $formatFilter === 'csv' ? 'selected' : '' ?>>CSV</option><option value="record" <?= $formatFilter === 'record' ? 'selected' : '' ?>>Database Record</option></select></div><div class="col-12 col-md-2 d-grid"><button type="submit" class="btn btn-neon">Apply Filter</button></div></form></div>

        <div class="analytics-card"><div class="table-responsive"><table class="table table-dark-custom align-middle m-0"><thead><tr><th>Report Details</th><th>Scope</th><th>Date Range</th><th>Generated By</th><th>Format</th><th class="text-end">Actions</th></tr></thead><tbody><?php if (!$reports): ?><tr><td colspan="6" class="text-center text-white-50 py-5">No database reports match the current filters.</td></tr><?php else: ?><?php foreach ($reports as $report): ?><?php $metadata = $report['metadata']; ?><tr><td><div class="d-flex align-items-center gap-3"><div class="rounded-3 d-flex align-items-center justify-content-center text-white" style="width:44px;height:44px;background:rgba(0,244,255,.1);border:1px solid rgba(0,244,255,.25);flex-shrink:0;"><i class="bi bi-file-earmark-bar-graph-fill fs-5 txt-neon-cyan"></i></div><div><div class="fw-semibold text-white"><?= hq_escape((string) $report['short_summery']) ?></div><div class="text-white-50 small">ID: #<?= (int) $report['report_id'] ?></div><div class="text-white-50 small report-message" title="<?= hq_escape((string) $metadata['summary']) ?>"><?= hq_escape((string) $metadata['summary']) ?></div></div></div></td><td><span class="report-scope"><?= hq_escape(admin_scope_label((string) $metadata['scope'])) ?></span></td><td class="text-white-50"><?= hq_escape($metadata['start_date'] && $metadata['end_date'] ? $metadata['start_date'] . ' – ' . $metadata['end_date'] : admin_date((string) $report['created_at'])) ?></td><td><div class="text-white"><?= hq_escape((string) ($report['user_name'] ?: 'System')) ?></div><div class="text-white-50 small"><?= hq_escape((string) ($report['role_name'] ?: 'Admin')) ?></div></td><td class="text-white-50"><?= hq_escape(admin_format_label((string) $metadata['format'])) ?></td><td class="text-end"><div class="d-flex gap-2 justify-content-end"><a href="report_action.php?action=download&amp;report_id=<?= (int) $report['report_id'] ?>" class="btn-action" title="Download or print report"><i class="bi bi-cloud-arrow-down-fill"></i></a><form action="report_action.php" method="POST" class="m-0" onsubmit="return confirm('Delete this report archive?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="report_id" value="<?= (int) $report['report_id'] ?>"><button type="submit" class="btn-action btn-action-danger" title="Delete Archive"><i class="bi bi-trash3-fill"></i></button></form></div></td></tr><?php endforeach; ?><?php endif; ?></tbody></table></div></div>
    </div>

    <div class="modal fade glass-modal" id="generateReportModal" tabindex="-1" aria-labelledby="generateReportModalLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title fw-bold" id="generateReportModalLabel"><i class="bi bi-bar-chart-steps txt-neon-cyan me-2"></i> Configure Custom Report</h5><p class="text-white-50 small mb-0 mt-1">The report summary is generated from current database counts.</p></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div><form action="report_action.php" method="POST"><input type="hidden" name="action" value="generate"><div class="modal-body d-flex flex-column gap-3"><div><label class="form-label text-white-50 small" for="reportTitle">Report Title <span class="text-danger">*</span></label><input id="reportTitle" type="text" name="report_title" class="form-control glass-input" maxlength="255" placeholder="e.g. August HQ Performance Summary" required></div><div><label class="form-label text-white-50 small" for="reportScope">Target Data Scope <span class="text-danger">*</span></label><select id="reportScope" class="form-select glass-select" name="scope" required><option value="hq_performance">HQ Performance Summary</option><option value="stations">Physical Pump Station Audit</option><option value="requests">System Approvals &amp; Rejections</option><option value="system">Overall System Security Audit</option></select></div><div class="row g-2"><div class="col-12 col-md-6"><label class="form-label text-white-50 small" for="startDate">Start Date <span class="text-danger">*</span></label><input id="startDate" type="date" name="start_date" class="form-control glass-input" required></div><div class="col-12 col-md-6"><label class="form-label text-white-50 small" for="endDate">End Date <span class="text-danger">*</span></label><input id="endDate" type="date" name="end_date" class="form-control glass-input" required></div></div><div><label class="form-label text-white-50 small">Export Format <span class="text-danger">*</span></label><div class="d-flex flex-wrap gap-4 mt-1"><div class="form-check"><input class="form-check-input" type="radio" name="format" id="formatPdf" value="pdf" checked><label class="form-check-label text-white" for="formatPdf">Print / PDF</label></div><div class="form-check"><input class="form-check-input" type="radio" name="format" id="formatCsv" value="csv"><label class="form-check-label text-white" for="formatCsv">CSV</label></div></div></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-3 text-white-50 border-secondary border-opacity-20" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-neon">Generate Report</button></div></form></div></div></div>

    <div class="offcanvas offcanvas-end" tabindex="-1" id="mainMenuOffcanvas" style="width:320px;background:rgba(6,12,24,.98);backdrop-filter:blur(28px);border-left:1px solid rgba(0,180,255,.18);"><div class="offcanvas-header pb-2 border-bottom border-white border-opacity-10"><div><h5 class="offcanvas-title text-white fw-bold mb-0">Main Menu</h5><p class="text-white-50 small mb-0">Admin Portal</p></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button></div><div class="offcanvas-body pt-3"><ul class="list-unstyled d-flex flex-column gap-2"><li><a href="admin_dashboard.php" class="menu-btn-nav"><i class="bi bi-house me-3 txt-neon-cyan"></i> Home</a></li><li><a href="view_hq_and_user_account.php" class="menu-btn-nav"><i class="bi bi-building-gear me-3 txt-neon-cyan"></i> View/Manage HQ</a></li><li><a href="view_users.php" class="menu-btn-nav"><i class="bi bi-people me-3 txt-neon-cyan"></i> View/Manage Users</a></li><li><a href="view_requests.php" class="menu-btn-nav"><i class="bi bi-file-earmark-check me-3 txt-neon-cyan"></i> View Requests</a></li><li><a href="view_report.php" class="menu-btn-nav active-nav"><i class="bi bi-graph-up-arrow me-3 txt-neon-blue"></i> View Report</a></li><li><a href="edit_profile.php" class="menu-btn-nav"><i class="bi bi-person-bounding-box me-3 txt-neon-blue"></i> Edit Profile</a></li><li><a href="manage_moderators.php" class="menu-btn-nav"><i class="bi bi-people-fill me-3 txt-neon-blue"></i> Manage Moderators</a></li><li><a href="view_logs.php" class="menu-btn-nav"><i class="bi bi-terminal-split me-3 text-danger"></i> View Log Files</a></li><hr style="border-color:rgba(255,255,255,.08);margin:4px 0;"><li><form action="../Login/logout.php" method="post" class="m-0"><button type="submit" class="logout-btn-nav btn border-0 w-100"><i class="bi bi-box-arrow-right me-2"></i> Logout</button></form></li></ul></div></div>
    <footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map &amp; Pump | All Rights Reserved</footer><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script src="/js/Admin.js"></script>
</body>
</html>
