<?php
require_once __DIR__ . '/moderator_common.php';

$moderatorUser = moderator_require_user();
$metrics = ['hqs' => 0, 'open_requests' => 0, 'pending_requests' => 0, 'reports' => 0, 'reports_today' => 0, 'stations' => 0];
$requestCounts = ['pending' => 0, 'approved' => 0, 'declined' => 0];
$reportTypes = ['hq_performance' => 0, 'stations' => 0, 'requests' => 0, 'system' => 0, 'audit_logs' => 0];
$updatedAt = date('Y-m-d H:i:s');
$dbOnline = hq_db_available();

if ($dbOnline) {
    $scalarQueries = [
        'hqs' => 'SELECT COUNT(*) AS value FROM headquarter',
        'stations' => 'SELECT COUNT(*) AS value FROM stations',
        'reports' => 'SELECT COUNT(*) AS value FROM report',
        'reports_today' => 'SELECT COUNT(*) AS value FROM report WHERE DATE(created_at) = CURDATE()',
        'open_requests' => "SELECT COUNT(*) AS value FROM system_requests WHERE LOWER(status) IN ('pending', 'flagged')",
        'pending_requests' => "SELECT COUNT(*) AS value FROM system_requests WHERE LOWER(status) = 'pending'",
    ];
    foreach ($scalarQueries as $key => $sql) {
        $query = $conn->query($sql);
        if ($query) {
            $metrics[$key] = (int) (($query->fetch_assoc()['value'] ?? 0));
        }
    }

    $requestQuery = $conn->query('SELECT LOWER(status) AS status, COUNT(*) AS total FROM system_requests GROUP BY LOWER(status)');
    while ($requestQuery && ($row = $requestQuery->fetch_assoc())) {
        $status = (string) $row['status'];
        if ($status === 'pending') {
            $requestCounts['pending'] += (int) $row['total'];
        } elseif ($status === 'approved') {
            $requestCounts['approved'] += (int) $row['total'];
        } elseif (in_array($status, ['rejected', 'flagged'], true)) {
            $requestCounts['declined'] += (int) $row['total'];
        }
    }

    $reportQuery = $conn->query('SELECT message FROM report');
    while ($reportQuery && ($row = $reportQuery->fetch_assoc())) {
        $scope = (string) admin_report_metadata((string) $row['message'])['scope'];
        $reportTypes[$scope] = ($reportTypes[$scope] ?? 0) + 1;
    }

    $updatedQuery = $conn->query('SELECT GREATEST(COALESCE(MAX(updated_at), 0), COALESCE(MAX(created_at), 0)) AS updated_at FROM users');
    if ($updatedQuery && ($row = $updatedQuery->fetch_assoc()) && !empty($row['updated_at'])) {
        $updatedAt = (string) $row['updated_at'];
    }
}

$requestTotal = array_sum($requestCounts);
$pendingPct = $requestTotal > 0 ? round($requestCounts['pending'] / $requestTotal * 100, 2) : 0;
$approvedPct = $requestTotal > 0 ? round(($requestCounts['pending'] + $requestCounts['approved']) / $requestTotal * 100, 2) : 0;
$reportTotal = array_sum($reportTypes);
$reportHqPct = $reportTotal > 0 ? round($reportTypes['hq_performance'] / $reportTotal * 100, 2) : 0;
$reportStationPct = $reportTotal > 0 ? round(($reportTypes['hq_performance'] + $reportTypes['stations']) / $reportTotal * 100, 2) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Map &amp; Pump moderator dashboard with live database metrics.">
    <title>Moderator Dashboard — Map &amp; Pump</title>
    <link rel="icon" type="image/png" href="/images/Logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&amp;display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="/css/Admin.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body class="background_color" id="background_color">
<?php include __DIR__ . '/includes/moderator_navbar.php'; ?>
<main class="container my-5">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div><span class="stat-chip mb-2"><i class="bi bi-speedometer2 txt-neon-cyan"></i> Moderator Dashboard</span><h1 class="fw-bold m-0 h4 text-white mt-2">Control Center Overview</h1><p class="text-white-50 m-0 small mt-1">Live requests, reports, HQ accounts, and service health.</p></div>
        <span class="stat-chip"><i class="bi bi-clock-history me-1"></i> Updated <?= moderator_escape(moderator_format_date($updatedAt)) ?></span>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-xl-3"><div class="analytics-card metric-card"><p class="mb-0 metric-label">Total HQs</p><h3 class="metric-value text-white mb-0"><?= (int) $metrics['hqs'] ?></h3><p class="text-white-50 small mb-0 mt-2">Registered headquarter records</p></div></div>
        <div class="col-12 col-sm-6 col-xl-3"><div class="analytics-card metric-card"><p class="mb-0 metric-label">Open Requests</p><h3 class="metric-value text-white mb-0"><?= (int) $metrics['open_requests'] ?></h3><p class="text-warning small mb-0 mt-2"><?= (int) $metrics['pending_requests'] ?> pending verification</p></div></div>
        <div class="col-12 col-sm-6 col-xl-3"><div class="analytics-card metric-card"><p class="mb-0 metric-label">Reports</p><h3 class="metric-value text-white mb-0"><?= (int) $metrics['reports'] ?></h3><p class="text-white-50 small mb-0 mt-2"><?= (int) $metrics['reports_today'] ?> generated today</p></div></div>
        <div class="col-12 col-sm-6 col-xl-3"><div class="analytics-card metric-card"><p class="mb-0 metric-label">Database Status</p><h3 class="metric-value text-white mb-0" style="font-size:1.45rem;"><?= $dbOnline ? 'Online' : 'Offline' ?></h3><p class="<?= $dbOnline ? 'text-success' : 'text-danger' ?> small mb-0 mt-2"><?= $dbOnline ? 'map_and_pump connected' : 'Connection unavailable' ?></p></div></div>
    </div>

    <div class="row g-4">
        <div class="col-12 col-xl-7"><div class="dashboard-card h-100"><div class="mb-4"><h2 class="fw-bold h5 text-white mb-1">Operational Snapshot</h2><p class="text-white-50 mb-0 small">Current request and report distributions.</p></div><div class="row g-4">
            <div class="col-12 col-lg-6"><div class="pie-panel"><div class="pie-chart request-pie" role="img" aria-label="Request status chart" style="background:conic-gradient(#ffc107 0 <?= $pendingPct ?>%, #1be494 <?= $pendingPct ?>% <?= $approvedPct ?>%, #ff4d6a <?= $approvedPct ?>% 100%);"><span><?= $requestTotal ?></span></div><div class="flex-grow-1"><h3 class="h6 fw-semibold text-white mb-3">Request Status</h3><ul class="chart-legend list-unstyled m-0"><li><span class="legend-dot bg-warning"></span><span>Pending</span><strong><?= $requestCounts['pending'] ?></strong></li><li><span class="legend-dot" style="background:#1be494;"></span><span>Approved</span><strong><?= $requestCounts['approved'] ?></strong></li><li><span class="legend-dot" style="background:#ff4d6a;"></span><span>Declined/Flagged</span><strong><?= $requestCounts['declined'] ?></strong></li></ul></div></div></div>
            <div class="col-12 col-lg-6"><div class="pie-panel"><div class="pie-chart report-pie" role="img" aria-label="Report scope chart" style="background:conic-gradient(var(--neon-cyan) 0 <?= $reportHqPct ?>%, var(--neon-blue) <?= $reportHqPct ?>% <?= $reportStationPct ?>%, #1be494 <?= $reportStationPct ?>% 100%);"><span><?= $reportTotal ?></span></div><div class="flex-grow-1"><h3 class="h6 fw-semibold text-white mb-3">Report Scopes</h3><ul class="chart-legend list-unstyled m-0"><li><span class="legend-dot bg-info"></span><span>HQ Performance</span><strong><?= $reportTypes['hq_performance'] ?></strong></li><li><span class="legend-dot bg-primary"></span><span>Station Logs</span><strong><?= $reportTypes['stations'] ?></strong></li><li><span class="legend-dot" style="background:#1be494;"></span><span>Requests/System</span><strong><?= $reportTypes['requests'] + $reportTypes['system'] + $reportTypes['audit_logs'] ?></strong></li></ul></div></div></div>
        </div></div></div>
        <div class="col-12 col-xl-5"><div class="row g-4 h-100"><div class="col-12"><a href="view_requests(M).php" class="dashboard-summary-link"><div class="analytics-card summary-card"><span class="stat-chip mb-3"><i class="bi bi-file-earmark-check"></i> View Requests</span><h3 class="h5 fw-bold text-white mb-2 mt-2">Request Review Summary</h3><p class="text-white-50 small mb-3">Review pending, approved, rejected, and flagged submissions.</p><div class="summary-stat-grid"><div><span class="text-warning"><?= $requestCounts['pending'] ?></span><small>Pending</small></div><div><span style="color:#1be494;"><?= $requestCounts['approved'] ?></span><small>Approved</small></div><div><span style="color:#ff4d6a;"><?= $requestCounts['declined'] ?></span><small>Declined</small></div></div></div></a></div><div class="col-12"><a href="view_report(M).php" class="dashboard-summary-link"><div class="analytics-card summary-card"><span class="stat-chip mb-3"><i class="bi bi-graph-up-arrow"></i> View Reports</span><h3 class="h5 fw-bold text-white mb-2 mt-2">Report Center Summary</h3><p class="text-white-50 small mb-3">Review generated reports and export data summaries.</p><div class="summary-stat-grid"><div><span class="txt-neon-cyan"><?= $reportTypes['hq_performance'] ?></span><small>HQ Reports</small></div><div><span class="txt-neon-blue"><?= $reportTypes['stations'] ?></span><small>Station Logs</small></div><div><span style="color:#1be494;"><?= $reportTypes['requests'] + $reportTypes['system'] + $reportTypes['audit_logs'] ?></span><small>Audit Files</small></div></div></div></a></div></div></div>
    </div>

    <div class="row g-4 mt-1"><div class="col-12"><div class="analytics-card"><div class="d-flex justify-content-between align-items-center mb-4"><div><h3 class="h5 fw-semibold mb-1 text-white">Priority Work Queue</h3><p class="text-white-50 mb-0 small">Open the corresponding live database page.</p></div><span class="stat-chip"><i class="bi bi-lightning-charge-fill txt-neon-cyan me-1"></i> Live</span></div><div class="table-responsive"><table class="table table-dark-custom align-middle m-0"><thead><tr><th>Area</th><th>Status</th><th>Next Action</th><th class="text-end">Open</th></tr></thead><tbody><tr><td class="fw-medium text-white">Inbound Requests</td><td><span class="badge rounded-pill bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25"><?= $metrics['open_requests'] ?> Open</span></td><td class="text-white-50">Review pending and flagged submissions.</td><td class="text-end"><a href="view_requests(M).php" class="btn-action" title="Open requests"><i class="bi bi-arrow-right"></i></a></td></tr><tr><td class="fw-medium text-white">System Reports</td><td><span class="badge rounded-pill bg-info bg-opacity-10 text-info border border-info border-opacity-25"><?= $metrics['reports'] ?> Stored</span></td><td class="text-white-50">Generate, download, or delete report records.</td><td class="text-end"><a href="view_report(M).php" class="btn-action" title="Open reports"><i class="bi bi-arrow-right"></i></a></td></tr><tr><td class="fw-medium text-white">Manage HQ</td><td><span class="badge rounded-pill bg-success bg-opacity-10 text-success border border-success border-opacity-25"><?= $metrics['hqs'] ?> HQ Accounts</span></td><td class="text-white-50">Register and edit role 3 HQ accounts.</td><td class="text-end"><a href="manage_hq(M).php" class="btn-action" title="Manage HQ"><i class="bi bi-arrow-right"></i></a></td></tr><tr><td class="fw-medium text-white">View HQ</td><td><span class="badge rounded-pill bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25">HQ only</span></td><td class="text-white-50">View role 3 HQ accounts and actions.</td><td class="text-end"><a href="view_hq_and_user_account(M).php" class="btn-action" title="View HQ"><i class="bi bi-arrow-right"></i></a></td></tr><tr><td class="fw-medium text-white">Manage Users</td><td><span class="badge rounded-pill bg-success bg-opacity-10 text-success border border-success border-opacity-25">Customer accounts</span></td><td class="text-white-50">Edit role 6 Customer account details.</td><td class="text-end"><a href="manage_users(M).php" class="btn-action" title="Manage Users"><i class="bi bi-arrow-right"></i></a></td></tr><tr><td class="fw-medium text-white">View Users</td><td><span class="badge rounded-pill bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25">Customer only</span></td><td class="text-white-50">View or suspend/remove role 6 Customers.</td><td class="text-end"><a href="view_users(M).php" class="btn-action" title="View Users"><i class="bi bi-arrow-right"></i></a></td></tr></tbody></table></div></div></div></div>
</main>
<?php $moderatorActiveNav = 'home'; include __DIR__ . '/includes/moderator_offcanvas.php'; ?><footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map &amp; Pump | All Rights Reserved</footer><?php include __DIR__ . '/includes/moderator_footer_scripts.php'; ?>
</body>
</html>
