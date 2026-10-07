<?php
require_once __DIR__ . '/admin_common.php';
require_once __DIR__ . '/request_common.php';
require_once __DIR__ . '/user_account_common.php';

admin_require_admin();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$dbOnline = hq_db_available();
$metrics = [
    'hqs' => 0,
    'open_requests' => 0,
    'pending_requests' => 0,
    'reports' => 0,
    'reports_today' => 0,
    'stations' => 0,
];
$requestCounts = ['pending' => 0, 'approved' => 0, 'declined' => 0];
$reportTypes = [
    'hq_performance' => 0,
    'stations' => 0,
    'requests' => 0,
    'system' => 0,
    'audit_logs' => 0,
];
$updatedAt = null;
$requestSummaryError = null;

if ($dbOnline) {
    // Station totals must come from `branches` (canonical table), never the dummy `stations` view.
    $scalarQueries = [
        'hqs' => 'SELECT COUNT(*) AS value FROM headquarter',
        'stations' => 'SELECT COUNT(*) AS value FROM branches',
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

    $requestSummary = request_live_status_summary();
    $requestCounts['pending'] += $requestSummary['pending'];
    $requestCounts['approved'] += $requestSummary['approved'];
    $requestCounts['declined'] += $requestSummary['declined_flagged'];
    $requestSummaryError = $requestSummary['error'];

    $pendingAccounts = pending_account_count();
    $metrics['open_requests'] += $pendingAccounts;
    $metrics['pending_requests'] += $pendingAccounts;
    $requestCounts['pending'] += $pendingAccounts;

    $reportQuery = $conn->query(
        'SELECT r.message, r.short_summery, r.role_id, roles.role_name
         FROM report r
         LEFT JOIN roles ON roles.role_id = r.role_id'
    );
    if ($reportQuery) {
        while ($row = $reportQuery->fetch_assoc()) {
            $meta = admin_report_metadata((string) ($row['message'] ?? ''), [
                'short_summery' => (string) ($row['short_summery'] ?? ''),
                'role_id' => $row['role_id'] ?? null,
                'role_name' => (string) ($row['role_name'] ?? ''),
            ]);
            $scope = admin_normalize_report_scope((string) $meta['scope']);
            if (!array_key_exists($scope, $reportTypes)) {
                // Fold unknown scopes into the Requests/System bucket.
                $scope = 'system';
            }
            $reportTypes[$scope] = ($reportTypes[$scope] ?? 0) + 1;
        }
    }

    $updatedQuery = $conn->query(
        'SELECT MAX(GREATEST(
            COALESCE(updated_at, created_at),
            COALESCE(created_at, updated_at)
        )) AS updated_at
         FROM users
         WHERE updated_at IS NOT NULL OR created_at IS NOT NULL'
    );
    if ($updatedQuery && ($row = $updatedQuery->fetch_assoc()) && !empty($row['updated_at'])) {
        $updatedAt = (string) $row['updated_at'];
    }
}

$requestTotal = array_sum($requestCounts);
// Cumulative pie stops (empty total → neutral grey ring, not a false 100% slice).
if ($requestTotal > 0) {
    $pendingPct = round($requestCounts['pending'] / $requestTotal * 100, 2);
    $approvedPct = round(($requestCounts['pending'] + $requestCounts['approved']) / $requestTotal * 100, 2);
    $requestPieStyle = "background:conic-gradient(#ffc107 0 {$pendingPct}%, #1be494 {$pendingPct}% {$approvedPct}%, #ff4d6a {$approvedPct}% 100%);";
} else {
    $pendingPct = 0;
    $approvedPct = 0;
    $requestPieStyle = 'background:conic-gradient(rgba(255,255,255,.12) 0 100%);';
}

// Legend buckets for Report Scopes pie (must sum to $reportTotal).
$reportHqCount = (int) $reportTypes['hq_performance'];
$reportStationCount = (int) $reportTypes['stations'];
$reportOtherCount = (int) ($reportTypes['requests'] + $reportTypes['system'] + $reportTypes['audit_logs']);
$reportTotal = $reportHqCount + $reportStationCount + $reportOtherCount;
if ($reportTotal > 0) {
    $reportHqPct = round($reportHqCount / $reportTotal * 100, 2);
    $reportStationPct = round(($reportHqCount + $reportStationCount) / $reportTotal * 100, 2);
    $reportPieStyle = "background:conic-gradient(var(--neon-cyan) 0 {$reportHqPct}%, var(--neon-blue) {$reportHqPct}% {$reportStationPct}%, #1be494 {$reportStationPct}% 100%);";
} else {
    $reportHqPct = 0;
    $reportStationPct = 0;
    $reportPieStyle = 'background:conic-gradient(rgba(255,255,255,.12) 0 100%);';
}

$updatedLabel = $updatedAt ? admin_date($updatedAt) : 'Not available';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Map &amp; Pump Admin Dashboard — Live database overview for requests, reports, HQs, and system health.">
    <title>Admin Dashboard — Map &amp; Pump</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="icon" type="image/png" href="/images/Logo.png">
    <link rel="stylesheet" href="/css/Admin.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
        .hq-flash { border-radius:14px; background:rgba(10,22,44,.82); border:1px solid var(--glass-border); color:var(--text-primary); }
        .hq-flash.alert-success { border-color:rgba(27,228,148,.4); }
        .hq-flash.alert-danger { border-color:rgba(255,77,106,.45); }
        body.background_color.light-mode .hq-flash { background:rgba(255,255,255,.9); }
    a.metric-card-link { display:block; text-decoration:none; color:inherit; height:100%; }
        a.metric-card-link:hover .metric-card,
        a.metric-card-link:focus .metric-card { transform:translateY(-2px); border-color:rgba(0,244,255,.35); box-shadow:0 10px 28px rgba(0,180,255,.12); }
        a.metric-card-link:focus-visible { outline:2px solid var(--neon-cyan); outline-offset:3px; border-radius:16px; }
        a.metric-card-link .metric-card-hint { opacity:.65; font-size:.72rem; }
        a.metric-card-link:hover .metric-card-hint { opacity:1; color:var(--neon-cyan); }
    </style>
</head>
<body class="background_color" id="background_color">
<?php $adminSection = 'home'; include __DIR__ . '/admin_section_nav.php'; ?>

<main class="container my-5">
    <?php if (function_exists('expiry_render_page_banners')) { expiry_render_page_banners('admin', true); } ?>
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <span class="stat-chip mb-2"><i class="bi bi-speedometer2 txt-neon-cyan"></i> Admin Dashboard</span>
            <h1 class="fw-bold m-0 h4 text-white mt-2">Control Center Overview</h1>
            <p class="text-white-50 m-0 small mt-1">Live counts</p>
        </div>
        <span class="stat-chip"><i class="bi bi-clock-history me-1"></i> Updated <?= hq_escape($updatedLabel) ?></span>
    </div>

    <?php
    $expiryPayload = function_exists('expiry_alert_payload') ? expiry_alert_payload(false, true) : [];
    $expiringSoon = (int) ($expiryPayload['warning_hq'] ?? 0) + (int) ($expiryPayload['warning_users'] ?? 0);
    $expiredCount = (int) ($expiryPayload['expired_hq'] ?? 0) + (int) ($expiryPayload['expired_users'] ?? 0);
    ?>
    <div class="row g-3 mb-3">
        <div class="col-12 col-sm-6">
            <a href="view_hq_and_user_account.php?expiry=warning" class="metric-card-link" title="HQ and users expiring within 10 days">
                <div class="analytics-card metric-card py-3">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div>
                            <p class="mb-0 metric-label text-warning">Expiring soon</p>
                            <h3 class="metric-value text-white mb-0"><?= $expiringSoon ?></h3>
                        </div>
                        <span class="text-warning fs-3"><i class="bi bi-clock-history"></i></span>
                    </div>
                    <p class="text-white-50 small mb-0 mt-2"><?= (int) ($expiryPayload['warning_hq'] ?? 0) ?> HQ · <?= (int) ($expiryPayload['warning_users'] ?? 0) ?> users (≤10 days)</p>
                </div>
            </a>
        </div>
        <div class="col-12 col-sm-6">
            <a href="view_users.php?expiry=expired" class="metric-card-link" title="Accounts with expired licenses">
                <div class="analytics-card metric-card py-3">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div>
                            <p class="mb-0 metric-label text-danger">Expired licenses</p>
                            <h3 class="metric-value text-white mb-0"><?= $expiredCount ?></h3>
                        </div>
                        <span class="text-danger fs-3"><i class="bi bi-x-octagon"></i></span>
                    </div>
                    <p class="text-white-50 small mb-0 mt-2"><?= (int) ($expiryPayload['expired_hq'] ?? 0) ?> HQ · <?= (int) ($expiryPayload['expired_users'] ?? 0) ?> users (auto-suspended)</p>
                </div>
            </a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-xl-3">
            <a href="view_hq_and_user_account.php" class="metric-card-link" title="Open View/Manage HQ" aria-label="Open View/Manage HQ">
                <div class="analytics-card metric-card">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div>
                            <p class="mb-0 metric-label">Total HQs</p>
                            <h3 class="metric-value text-white mb-0"><?= (int) $metrics['hqs'] ?></h3>
                        </div>
                        <span class="txt-neon-cyan fs-2"><i class="bi bi-building"></i></span>
                    </div>
                    <p class="text-white-50 small mb-0 mt-2"><?= (int) $metrics['stations'] ?> stations in <code>branches</code></p>
                    <p class="metric-card-hint text-white-50 mb-0 mt-2"><i class="bi bi-box-arrow-up-right me-1"></i>View / Manage HQ</p>
                </div>
            </a>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <a href="view_requests.php" class="metric-card-link" title="Open View Requests" aria-label="Open View Requests">
                <div class="analytics-card metric-card">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div>
                            <p class="mb-0 metric-label">Open Requests</p>
                            <h3 class="metric-value text-white mb-0"><?= (int) $metrics['open_requests'] ?></h3>
                        </div>
                        <span class="txt-neon-blue fs-2"><i class="bi bi-file-earmark-check"></i></span>
                    </div>
                    <p class="text-warning small mb-0 mt-2"><?= (int) $metrics['pending_requests'] ?> pending verification</p>
                    <p class="metric-card-hint text-white-50 mb-0 mt-2"><i class="bi bi-box-arrow-up-right me-1"></i>View Requests</p>
                </div>
            </a>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <a href="view_report.php" class="metric-card-link" title="Open View Report" aria-label="Open View Report">
                <div class="analytics-card metric-card">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div>
                            <p class="mb-0 metric-label">Reports</p>
                            <h3 class="metric-value text-white mb-0"><?= (int) $metrics['reports'] ?></h3>
                        </div>
                        <span class="txt-neon-cyan fs-2"><i class="bi bi-file-earmark-bar-graph"></i></span>
                    </div>
                    <p class="text-white-50 small mb-0 mt-2"><?= (int) $metrics['reports_today'] ?> generated today</p>
                    <p class="metric-card-hint text-white-50 mb-0 mt-2"><i class="bi bi-box-arrow-up-right me-1"></i>View Report</p>
                </div>
            </a>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <a href="view_logs.php" class="metric-card-link" title="Open View Logs" aria-label="Open View Logs">
                <div class="analytics-card metric-card">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div>
                            <p class="mb-0 metric-label">Database Status</p>
                            <h3 class="metric-value text-white mb-0" style="font-size:1.45rem;"><?= $dbOnline ? 'Online' : 'Offline' ?></h3>
                        </div>
                        <span class="<?= $dbOnline ? 'text-success' : 'text-danger' ?> fs-2"><i class="bi bi-database-check"></i></span>
                    </div>
                    <p class="<?= $dbOnline ? 'text-success' : 'text-danger' ?> small mb-0 mt-2"><?= $dbOnline ? 'map_and_pump connected' : 'Connection unavailable' ?></p>
                    <p class="metric-card-hint text-white-50 mb-0 mt-2"><i class="bi bi-box-arrow-up-right me-1"></i>View Logs</p>
                </div>
            </a>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-12 col-xl-7">
            <div class="dashboard-card h-100">
                <div class="mb-4">
                    <h2 class="fw-bold h5 text-white mb-1">Operational Snapshot</h2>
                    <p class="text-white-50 mb-0 small">Pending counts include account verification and operational requests.</p>
                </div>
                <div class="row g-4">
                    <div class="col-12 col-lg-6">
                        <div class="pie-panel pie-panel-solo">
                            <div class="pie-chart request-pie" data-live-request-pie role="img" aria-label="Request status chart" style="<?= hq_escape($requestPieStyle) ?>"><span data-live-request-total><?= (int) $requestTotal ?></span></div>
                            <div class="pie-panel-text">
                                <h3 class="h6 fw-semibold text-white mb-3">Request Status</h3>
                                <ul class="chart-legend list-unstyled m-0">
                                    <li><span class="legend-dot bg-warning"></span><span>Pending</span><strong data-live-request-count="pending"><?= (int) $requestCounts['pending'] ?></strong></li>
                                    <li><span class="legend-dot" style="background:#1be494;"></span><span>Approved</span><strong data-live-request-count="approved"><?= (int) $requestCounts['approved'] ?></strong></li>
                                    <li><span class="legend-dot" style="background:#ff4d6a;"></span><span>Declined/Flagged</span><strong data-live-request-count="declined"><?= (int) $requestCounts['declined'] ?></strong></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-lg-6">
                        <div class="pie-panel pie-panel-solo">
                            <div class="pie-chart report-pie" role="img" aria-label="Report scope chart" style="<?= hq_escape($reportPieStyle) ?>"><span><?= (int) $reportTotal ?></span></div>
                            <div class="pie-panel-text">
                                <h3 class="h6 fw-semibold text-white mb-3">Report Scopes</h3>
                                <ul class="chart-legend list-unstyled m-0">
                                    <li><span class="legend-dot bg-info"></span><span>HQ Performance</span><strong><?= $reportHqCount ?></strong></li>
                                    <li><span class="legend-dot bg-primary"></span><span>Station Logs</span><strong><?= $reportStationCount ?></strong></li>
                                    <li><span class="legend-dot" style="background:#1be494;"></span><span>Requests/System</span><strong><?= $reportOtherCount ?></strong></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-xl-5">
            <div class="row g-4 h-100">
                <div class="col-12">
                    <a href="view_requests.php" class="dashboard-summary-link">
                        <div class="analytics-card summary-card">
                            <div class="d-flex align-items-start justify-content-between gap-3">
                                <div>
                                    <span class="stat-chip mb-3"><i class="bi bi-file-earmark-check"></i> View Requests</span>
                                    <h3 class="h5 fw-bold text-white mb-2 mt-2">Request Review Summary</h3>
                                    <p class="text-white-50 small mb-3">Pending partner approvals, station changes, and flagged actions.</p>
                                </div>
                                <span class="summary-arrow"><i class="bi bi-arrow-right"></i></span>
                            </div>
                            <div class="summary-stat-grid">
                                <div><span class="text-warning" data-live-request-count="pending"><?= (int) $requestCounts['pending'] ?></span><small>Pending</small></div>
                                <div><span style="color:#1be494;" data-live-request-count="approved"><?= (int) $requestCounts['approved'] ?></span><small>Approved</small></div>
                                <div><span style="color:#ff4d6a;" data-live-request-count="declined"><?= (int) $requestCounts['declined'] ?></span><small>Declined</small></div>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col-12">
                    <a href="view_report.php" class="dashboard-summary-link">
                        <div class="analytics-card summary-card">
                            <div class="d-flex align-items-start justify-content-between gap-3">
                                <div>
                                    <span class="stat-chip mb-3"><i class="bi bi-graph-up-arrow"></i> View Report</span>
                                    <h3 class="h5 fw-bold text-white mb-2 mt-2">Report Center Summary</h3>
                                    <p class="text-white-50 small mb-3">Review generated reports and export data summaries.</p>
                                </div>
                                <span class="summary-arrow"><i class="bi bi-arrow-right"></i></span>
                            </div>
                            <div class="summary-stat-grid">
                                <div><span class="txt-neon-cyan"><?= $reportHqCount ?></span><small>HQ Reports</small></div>
                                <div><span class="txt-neon-blue"><?= $reportStationCount ?></span><small>Station Logs</small></div>
                                <div><span style="color:#1be494;"><?= $reportOtherCount ?></span><small>Audit Files</small></div>
                            </div>
                        </div>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mt-1">
        <div class="col-12">
            <div class="analytics-card">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-4">
                    <div>
                        <h3 class="h5 fw-semibold mb-1 text-white">Priority Work Queue</h3>
                        <p class="text-white-50 mb-0 small">Open the corresponding live database page for the next action.</p>
                    </div>
                    <span class="stat-chip"><i class="bi bi-lightning-charge-fill txt-neon-cyan me-1"></i> Live</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-dark-custom align-middle m-0">
                        <thead>
                            <tr>
                                <th>Area</th>
                                <th>Status</th>
                                <th>Next Action</th>
                                <th class="text-end">Open</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="fw-medium text-white">Inbound Requests</td>
                                <td><span class="badge rounded-pill bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25"><?= (int) $metrics['open_requests'] ?> Open</span></td>
                                <td class="text-white-50">Review pending and flagged submissions.</td>
                                <td class="text-end"><a href="view_requests.php" class="btn-action" title="Open View Requests"><i class="bi bi-arrow-right"></i></a></td>
                            </tr>
                            <tr>
                                <td class="fw-medium text-white">System Reports</td>
                                <td><span class="badge rounded-pill bg-info bg-opacity-10 text-info border border-info border-opacity-25"><?= (int) $metrics['reports'] ?> Stored</span></td>
                                <td class="text-white-50">Generate, download, or delete report records.</td>
                                <td class="text-end"><a href="view_report.php" class="btn-action" title="Open View Report"><i class="bi bi-arrow-right"></i></a></td>
                            </tr>
                            <tr>
                                <td class="fw-medium text-white">View/Manage HQ</td>
                                <td><span class="badge rounded-pill bg-success bg-opacity-10 text-success border border-success border-opacity-25"><?= (int) $metrics['hqs'] ?> HQ · <?= (int) $metrics['stations'] ?> Stations</span></td>
                                <td class="text-white-50">View, edit, suspend, or remove HQ accounts.</td>
                                <td class="text-end"><a href="view_hq_and_user_account.php" class="btn-action" title="Open View/Manage HQ"><i class="bi bi-arrow-right"></i></a></td>
                            </tr>
                            <tr>
                                <td class="fw-medium text-white">View/Manage Users</td>
                                <td><span class="badge rounded-pill bg-success bg-opacity-10 text-success border border-success border-opacity-25">Non-HQ accounts</span></td>
                                <td class="text-white-50">View, edit, suspend, or remove user accounts.</td>
                                <td class="text-end"><a href="view_users.php" class="btn-action" title="Open View/Manage Users"><i class="bi bi-arrow-right"></i></a></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</main>

<footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map &amp; Pump | All Rights Reserved</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="/js/Admin.js"></script>
<?php require_once __DIR__ . '/../moderator_hire_popup.php'; ?>
</body>
</html>
