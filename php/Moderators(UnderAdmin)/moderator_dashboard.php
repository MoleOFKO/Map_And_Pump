<?php
require_once __DIR__ . '/moderator_common.php';

$moderatorUser = moderator_require_user();
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
$requestSummary = ['pending' => 0, 'approved' => 0, 'approved_today' => 0, 'declined_flagged' => 0, 'pending_accounts' => 0, 'pending_car_licenses' => 0, 'pending_payment_verifications' => 0, 'pending_payment_rows' => 0, 'error' => null];
$pendingAccounts = 0;
$pendingCarLicenseReviewCount = 0;
$pendingPaymentReviewCount = 0;
$dbOnline = hq_db_available();

if ($dbOnline) {
    // Station totals from canonical `branches` table (never the dummy `stations` view).
    $scalarQueries = [
        'hqs' => 'SELECT COUNT(*) AS value FROM headquarter',
        'stations' => 'SELECT COUNT(*) AS value FROM branches',
        'reports' => 'SELECT COUNT(*) AS value FROM report',
        'reports_today' => 'SELECT COUNT(*) AS value FROM report WHERE DATE(created_at) = CURDATE()',
    ];
    foreach ($scalarQueries as $key => $sql) {
        $query = $conn->query($sql);
        if ($query) {
            $metrics[$key] = (int) (($query->fetch_assoc()['value'] ?? 0));
        }
    }

    $requestSummary = moderator_request_summary();
    $requestCounts['pending'] = $requestSummary['pending'];
    $requestCounts['approved'] = $requestSummary['approved'];
    $requestCounts['declined'] = $requestSummary['declined_flagged'];
    $requestSummaryError = $requestSummary['error'];

    $pendingAccounts = $requestSummary['pending_accounts'];
    $pendingCarLicenseReviewCount = $requestSummary['pending_car_licenses'];
    $pendingPaymentReviewCount = $requestSummary['pending_payment_rows'];
    $metrics['open_requests'] = $requestSummary['pending'];
    $metrics['pending_requests'] = $requestSummary['pending'];

    $reportQuery = $conn->query(
        'SELECT r.message, r.short_summery, r.role_id, roles.role_name
         FROM report r
         LEFT JOIN roles ON roles.role_id = r.role_id'
    );
    while ($reportQuery && ($row = $reportQuery->fetch_assoc())) {
        $meta = admin_report_metadata((string) ($row['message'] ?? ''), [
            'short_summery' => (string) ($row['short_summery'] ?? ''),
            'role_id' => $row['role_id'] ?? null,
            'role_name' => (string) ($row['role_name'] ?? ''),
        ]);
        $scope = admin_normalize_report_scope((string) $meta['scope']);
        if (!array_key_exists($scope, $reportTypes)) {
            $scope = 'system';
        }
        $reportTypes[$scope] = ($reportTypes[$scope] ?? 0) + 1;
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
if ($requestTotal > 0) {
    $pendingPct = round($requestCounts['pending'] / $requestTotal * 100, 2);
    $approvedPct = round(($requestCounts['pending'] + $requestCounts['approved']) / $requestTotal * 100, 2);
    $requestPieStyle = "background:conic-gradient(#ffc107 0 {$pendingPct}%, #1be494 {$pendingPct}% {$approvedPct}%, #ff4d6a {$approvedPct}% 100%);";
} else {
    $pendingPct = 0;
    $approvedPct = 0;
    $requestPieStyle = 'background:conic-gradient(rgba(255,255,255,.12) 0 100%);';
}

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

$updatedLabel = $updatedAt ? moderator_format_date($updatedAt) : 'Not available';
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
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&amp;display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="/css/Admin.css">
    <link rel="stylesheet" href="/css/admin-blue-pages.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .hq-flash { border-radius:14px; background:rgba(10,22,44,.82); border:1px solid var(--glass-border); color:var(--text-primary); }
        .hq-flash.alert-success { border-color:rgba(27,228,148,.4); }
        .hq-flash.alert-danger { border-color:rgba(255,77,106,.45); }
        .hq-flash.alert-warning { border-color:rgba(255,193,7,.35); }
        body.background_color.light-mode .hq-flash { background:rgba(255,255,255,.9); }
        a.metric-card-link { display:block; text-decoration:none; color:inherit; height:100%; }
        a.metric-card-link:hover .metric-card,
        a.metric-card-link:focus .metric-card {
            transform: translateY(-2px);
            border-color: rgba(0,244,255,.35);
            box-shadow: 0 10px 28px rgba(0,180,255,.12);
        }
        a.metric-card-link:focus-visible {
            outline: 2px solid var(--neon-cyan);
            outline-offset: 3px;
            border-radius: 16px;
        }
    </style>
</head>
<body class="background_color admin-blue-page" id="background_color">
<?php include __DIR__ . '/includes/moderator_navbar.php'; ?>

<main class="container my-5 admin-blue-shell">
    <?php
    $hireFlash = '';
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['moderator_hire_flash'])) {
        $hireFlash = (string) $_SESSION['moderator_hire_flash'];
        unset($_SESSION['moderator_hire_flash']);
    }
    $registryFlash = function_exists('moderator_pull_flash') ? moderator_pull_flash() : null;
    ?>
    <?php if ($hireFlash !== ''): ?>
        <div class="alert hq-flash alert-success mb-4" role="alert">
            <i class="bi bi-check-circle-fill text-success me-2"></i><?= moderator_escape($hireFlash) ?>
        </div>
    <?php elseif (is_array($registryFlash) && trim((string) ($registryFlash['message'] ?? '')) !== ''): ?>
        <div class="alert hq-flash alert-<?= moderator_escape((string) ($registryFlash['type'] ?? 'success')) ?> mb-4" role="alert">
            <i class="bi bi-info-circle-fill me-2"></i><?= moderator_escape((string) ($registryFlash['message'] ?? '')) ?>
        </div>
    <?php endif; ?>
    <?php if (function_exists('expiry_render_page_banners')) { expiry_render_page_banners('moderator', true); } ?>
    <div class="admin-blue-header d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <span class="stat-chip mb-2"><i class="bi bi-speedometer2 txt-neon-cyan"></i> Moderator Dashboard</span>
            <h1 class="fw-bold m-0 h4 text-white mt-2">Control Center Overview</h1>
            <p class="admin-blue-description">Track platform activity, review queues, and license renewals.</p>
        </div>
        <span class="admin-blue-updated"><i class="bi bi-clock-history me-1"></i> Updated <?= moderator_escape($updatedLabel) ?></span>
    </div>

    <?php
    $expiryPayload = function_exists('expiry_alert_payload') ? expiry_alert_payload(false, true) : [];
    $priorityTasks = [];
    $addPriorityTask = static function (string $title, int $count, string $status, string $url, string $icon) use (&$priorityTasks): void {
        if ($count <= 0) {
            return;
        }
        $priorityTasks[] = [
            'title' => $title,
            'count' => $count,
            'status' => $status,
            'url' => $url,
            'icon' => $icon,
        ];
    };
    $addPriorityTask('Inbound requests awaiting review', (int) $requestSummary['pending'], 'Awaiting review', 'view_requests(M).php?status=pending', 'bi-inbox');
    $addPriorityTask('Driver account verifications', $pendingAccounts, 'Awaiting verification', 'view_requests(M).php?type=account&status=pending', 'bi-person-vcard');
    $addPriorityTask('Vehicle license reviews', $pendingCarLicenseReviewCount, 'Awaiting review', 'view_requests(M).php?type=account&status=pending', 'bi-car-front');
    $addPriorityTask('Payment confirmations to verify', $pendingPaymentReviewCount, 'Awaiting verification', 'view_requests(M).php?type=payment&status=pending', 'bi-cash-coin');
    $addPriorityTask('Company licenses expiring soon', (int) ($expiryPayload['warning_hq'] ?? 0), 'Due within 10 days', 'view_hq_and_user_account(M).php?expiry=warning', 'bi-building');
    $addPriorityTask('Driver licenses expiring soon', (int) ($expiryPayload['warning_users'] ?? 0), 'Due within 10 days', 'view_users(M).php?expiry=warning', 'bi-person-vcard');
    $addPriorityTask('Expired company licenses', (int) ($expiryPayload['expired_hq'] ?? 0), 'Renewal follow-up', 'view_hq_and_user_account(M).php?expiry=expired', 'bi-building-exclamation');
    $addPriorityTask('Expired driver licenses', (int) ($expiryPayload['expired_users'] ?? 0), 'Renewal follow-up', 'view_users(M).php?expiry=expired', 'bi-person-x');
    ?>
    <div class="row g-3 mb-3">
        <div class="col-12 col-sm-6 col-xl-3">
            <a href="view_hq_and_user_account(M).php?expiry=warning" class="metric-card-link" title="Company licenses expiring within 10 days">
                <div class="analytics-card metric-card py-3">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div>
                            <p class="mb-0 metric-label text-warning">Company licenses expiring soon</p>
                            <h3 class="metric-value text-white mb-0"><?= (int) ($expiryPayload['warning_hq'] ?? 0) ?></h3>
                        </div>
                        <span class="text-warning fs-3"><i class="bi bi-building"></i></span>
                    </div>
                    <p class="text-white-50 small mb-0 mt-2">Business licenses due within 10 days</p>
                </div>
            </a>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <a href="view_users(M).php?expiry=warning" class="metric-card-link" title="Driver licenses expiring within 10 days">
                <div class="analytics-card metric-card py-3">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div>
                            <p class="mb-0 metric-label text-warning">Driver licenses expiring soon</p>
                            <h3 class="metric-value text-white mb-0"><?= (int) ($expiryPayload['warning_users'] ?? 0) ?></h3>
                        </div>
                        <span class="text-warning fs-3"><i class="bi bi-person-vcard"></i></span>
                    </div>
                    <p class="text-white-50 small mb-0 mt-2">Driver licenses due within 10 days</p>
                </div>
            </a>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <a href="view_hq_and_user_account(M).php?expiry=expired" class="metric-card-link" title="Companies with expired business licenses">
                <div class="analytics-card metric-card py-3">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div>
                            <p class="mb-0 metric-label text-danger">Expired company licenses</p>
                            <h3 class="metric-value text-white mb-0"><?= (int) ($expiryPayload['expired_hq'] ?? 0) ?></h3>
                        </div>
                        <span class="text-danger fs-3"><i class="bi bi-building-exclamation"></i></span>
                    </div>
                    <p class="text-white-50 small mb-0 mt-2">Business licenses past their expiry date</p>
                </div>
            </a>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <a href="view_users(M).php?expiry=expired" class="metric-card-link" title="Driver accounts with expired licenses">
                <div class="analytics-card metric-card py-3">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div>
                            <p class="mb-0 metric-label text-danger">Expired driver licenses</p>
                            <h3 class="metric-value text-white mb-0"><?= (int) ($expiryPayload['expired_users'] ?? 0) ?></h3>
                        </div>
                        <span class="text-danger fs-3"><i class="bi bi-person-x"></i></span>
                    </div>
                    <p class="text-white-50 small mb-0 mt-2">Driver licenses past their expiry date</p>
                </div>
            </a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-xl-3">
            <a href="view_hq_and_user_account(M).php" class="metric-card-link" title="Open View/Manage Companies" aria-label="Open View/Manage Companies">
                <div class="analytics-card metric-card">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div>
                            <p class="mb-0 metric-label">Total Companies</p>
                            <h3 class="metric-value text-white mb-0"><?= (int) $metrics['hqs'] ?></h3>
                        </div>
                        <span class="txt-neon-cyan fs-2"><i class="bi bi-building"></i></span>
                    </div>
                    <p class="text-white-50 small mb-0 mt-2"><span class="txt-neon-cyan fw-semibold"><?= (int) $metrics['stations'] ?></span> stations in total</p>
                </div>
            </a>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <a href="view_requests(M).php" class="metric-card-link" title="Open View Requests" aria-label="Open View Requests">
                <div class="analytics-card metric-card">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div>
                            <p class="mb-0 metric-label">Open Requests</p>
                            <h3 class="metric-value text-white mb-0"><?= (int) $metrics['open_requests'] ?></h3>
                        </div>
                        <span class="txt-neon-blue fs-2"><i class="bi bi-file-earmark-check"></i></span>
                    </div>
                    <p class="text-warning small mb-0 mt-2"><?= (int) $metrics['pending_requests'] ?> pending verification</p>
                </div>
            </a>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <a href="view_report(M).php" class="metric-card-link" title="Open View Report" aria-label="Open View Report">
                <div class="analytics-card metric-card">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div>
                            <p class="mb-0 metric-label">Reports</p>
                            <h3 class="metric-value text-white mb-0"><?= (int) $metrics['reports'] ?></h3>
                        </div>
                        <span class="txt-neon-cyan fs-2"><i class="bi bi-file-earmark-bar-graph"></i></span>
                    </div>
                    <p class="text-white-50 small mb-0 mt-2"><?= (int) $metrics['reports_today'] ?> generated today</p>
                </div>
            </a>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <a href="view_logs(M).php" class="metric-card-link" title="Open View Logs" aria-label="Open View Logs">
                <div class="analytics-card metric-card">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div>
                            <p class="mb-0 metric-label">Database Status</p>
                            <h3 class="metric-value text-white mb-0" style="font-size:1.45rem;"><?= $dbOnline ? 'Online' : 'Offline' ?></h3>
                        </div>
                        <span class="<?= $dbOnline ? 'text-success' : 'text-danger' ?> fs-2"><i class="bi bi-database-check"></i></span>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-12 col-xl-7">
            <div class="dashboard-card operational-snapshot-card h-100">
                <div class="mb-4">
                    <h2 class="fw-bold h5 text-white mb-1">Operational Snapshot</h2>
                </div>
                <div class="row g-4 operational-snapshot-panels">
                    <div class="col-12 col-lg-6">
                        <div class="pie-panel pie-panel-solo operational-snapshot-panel">
                            <div class="pie-chart request-pie" data-live-request-pie role="img" aria-label="Request status chart" style="<?= moderator_escape($requestPieStyle) ?>"><span data-live-request-total><?= (int) $requestTotal ?></span></div>
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
                        <div class="pie-panel pie-panel-solo operational-snapshot-panel">
                            <div class="pie-chart report-pie" role="img" aria-label="Report scope chart" style="<?= moderator_escape($reportPieStyle) ?>"><span><?= (int) $reportTotal ?></span></div>
                            <div class="pie-panel-text">
                                <h3 class="h6 fw-semibold text-white mb-3">Report Scopes</h3>
                                <ul class="chart-legend list-unstyled m-0">
                                    <li><span class="legend-dot bg-info"></span><span>Company Performance</span><strong><?= $reportHqCount ?></strong></li>
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
                    <a href="view_requests(M).php" class="dashboard-summary-link">
                        <div class="analytics-card summary-card">
                            <div class="d-flex align-items-start justify-content-between gap-3">
                                <div>
                                    <span class="stat-chip mb-3"><i class="bi bi-file-earmark-check"></i> View Requests</span>
                                    <h3 class="h5 fw-bold text-white mb-2 mt-2">Request Review Summary</h3>
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
                    <a href="view_report(M).php" class="dashboard-summary-link">
                        <div class="analytics-card summary-card">
                            <div class="d-flex align-items-start justify-content-between gap-3">
                                <div>
                                    <span class="stat-chip mb-3"><i class="bi bi-graph-up-arrow"></i> View Report</span>
                                    <h3 class="h5 fw-bold text-white mb-2 mt-2">Report Center Summary</h3>
                                </div>
                                <span class="summary-arrow"><i class="bi bi-arrow-right"></i></span>
                            </div>
                            <div class="summary-stat-grid">
                                <div><span class="txt-neon-cyan"><?= $reportHqCount ?></span><small>Company Reports</small></div>
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
                    </div>
                    <span class="stat-chip"><i class="bi bi-lightning-charge-fill txt-neon-cyan me-1"></i> Live</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-dark-custom align-middle m-0">
                        <thead>
                            <tr>
                                <th>Task</th>
                                <th>Status</th>
                                <th class="text-end">Count</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$priorityTasks): ?>
                                <tr><td colspan="3" class="text-center text-white-50 py-4"><?php if ($dbOnline): ?><i class="bi bi-check-circle text-success me-2"></i>No priority tasks need attention.<?php else: ?><i class="bi bi-database-exclamation text-warning me-2"></i>Priority tasks are unavailable while the database is offline.<?php endif; ?></td></tr>
                            <?php endif; ?>
                            <?php foreach ($priorityTasks as $task): ?>
                                <tr>
                                    <td class="fw-medium"><a href="<?= moderator_escape($task['url']) ?>" class="text-white text-decoration-none" title="Open <?= moderator_escape($task['title']) ?>"><i class="bi <?= moderator_escape($task['icon']) ?> txt-neon-cyan me-2"></i><?= moderator_escape($task['title']) ?></a></td>
                                    <td><span class="badge rounded-pill bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25"><?= moderator_escape($task['status']) ?></span></td>
                                    <td class="text-end"><a href="<?= moderator_escape($task['url']) ?>" class="btn-action" title="Open <?= moderator_escape($task['title']) ?>"><?= (int) $task['count'] ?><i class="bi bi-arrow-right ms-2"></i></a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</main>

<?php $moderatorActiveNav = 'home'; include __DIR__ . '/includes/moderator_offcanvas.php'; ?>
<footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map &amp; Pump | All Rights Reserved</footer>
<?php include __DIR__ . '/includes/moderator_footer_scripts.php'; ?>
<?php require_once __DIR__ . '/../moderator_hire_popup.php'; ?>
</body>
</html>
