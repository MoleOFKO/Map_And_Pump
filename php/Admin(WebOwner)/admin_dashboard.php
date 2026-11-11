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
$requestSummary = ['pending' => 0, 'approved' => 0, 'approved_today' => 0, 'declined_flagged' => 0, 'error' => null];
$discountedBalance = null;
$loyaltyBalanceError = false;
$pendingAccounts = 0;
$pendingCarLicenseReviewCount = 0;
$pendingPaymentReviewCount = 0;

if ($dbOnline) {
    // Station totals must come from `branches` (canonical table), never the dummy `stations` view.
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

    $requestSummary = request_review_queue_summary('admin');
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

    if ($pdo instanceof PDO) {
        try {
            $discountedBalance = (float) $pdo->query(
                'SELECT
                    COALESCE((SELECT SUM(balance_mmk) FROM loyalty_company_discount_accounts), 0)
                    + COALESCE((
                        SELECT balance_mmk
                        FROM loyalty_discount_account
                        WHERE account_id = 1
                    ), 0)'
            )->fetchColumn();
        } catch (Throwable $e) {
            error_log('admin_dashboard loyalty balance: ' . $e->getMessage());
            $loyaltyBalanceError = true;
        }
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

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Map &amp; Pump Admin Dashboard — Live database overview for requests, reports, companies, drivers, and system health.">
    <title>Admin Dashboard — Map &amp; Pump</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&amp;display=swap" rel="stylesheet">
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

    /* Dashboard-only presentation. Shared navigation and live hooks stay intact. */
    body.background_color {
        --dash-bg: #0c1421;
        --dash-surface: #141f30;
        --dash-raised: #192638;
        --dash-border: #29364a;
        --dash-text: #edf3fb;
        --dash-muted: #a7b5c9;
        --dash-accent: #5cd9cf;
        --dash-blue: #78aafa;
        --dash-warning: #f0c46a;
        --dash-danger: #f18d9c;
        --neon-cyan: var(--dash-accent);
        --neon-blue: var(--dash-blue);
        background: var(--dash-bg);
    }
    body.background_color.light-mode {
        --dash-bg: #f3f6fa;
        --dash-surface: #ffffff;
        --dash-raised: #f7f9fc;
        --dash-border: #dee5ee;
        --dash-text: #182b42;
        --dash-muted: #596b82;
        --dash-accent: #087f78;
        --dash-blue: #316cc0;
        --dash-warning: #96620a;
        --dash-danger: #b43b51;
    }
    .dashboard-shell {
        max-width: 1440px;
        padding-inline: clamp(18px, 3vw, 40px);
        margin-top: 36px !important;
        margin-bottom: 36px !important;
        color: var(--dash-text);
        font-family: 'Poppins', system-ui, sans-serif;
        font-size: 13px;
        line-height: 1.65;
    }
    .dashboard-shell *, .dashboard-shell *::before, .dashboard-shell *::after { box-sizing: border-box; }
    .dashboard-shell .text-white { color: var(--dash-text) !important; }
    .dashboard-shell .text-white-50, .dashboard-muted { color: var(--dash-muted) !important; }
    .dashboard-shell .txt-neon-cyan, .dashboard-shell .text-info { color: var(--dash-accent) !important; }
    .dashboard-shell .txt-neon-blue { color: var(--dash-blue) !important; }
    .dashboard-shell .text-warning { color: var(--dash-warning) !important; }
    .dashboard-shell .text-danger { color: var(--dash-danger) !important; }
    .dashboard-shell h1, .dashboard-shell h2, .dashboard-shell h3 { letter-spacing: -.025em; line-height: 1.35; }
    .dashboard-header { border-bottom: 1px solid var(--dash-border); padding-bottom: 24px; }
    .dashboard-header h1 { font-size: clamp(24px, 3vw, 32px); }
    .dashboard-eyebrow { color: var(--dash-accent); font-size: 10px; letter-spacing: .14em; text-transform: uppercase; font-weight: 600; }
    .dashboard-header p { margin: 8px 0 0; max-width: 580px; color: var(--dash-muted); }
    .dashboard-header-actions { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; }
    .dashboard-button { display: inline-flex; gap: 9px; align-items: center; justify-content: center; min-height: 42px; padding: 10px 16px; border: 1px solid var(--dash-border); border-radius: 9px; color: var(--dash-text); background: var(--dash-surface); font-size: 12px; font-weight: 500; text-decoration: none; transition: background .18s, border-color .18s; }
    .dashboard-button-primary { color: #082e2b; background: #5cd9cf; border-color: #5cd9cf; }
    .dashboard-button:hover { color: var(--dash-text); border-color: var(--dash-accent); background: var(--dash-raised); }
    .dashboard-button-primary:hover { color: #082e2b; background: #83e4dc; }
    .dashboard-section-heading { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 8px; margin: 26px 0 12px; }
    .dashboard-section-heading h2 { font-size: 14px; margin: 0; font-weight: 600; }
    .dashboard-section-heading p { margin: 0; color: var(--dash-muted); font-size: 11px; }
    .dashboard-shell .analytics-card, .dashboard-shell .dashboard-card {
        height: 100%; padding: 24px; border-radius: 12px;
        border: 1px solid var(--dash-border); background: var(--dash-surface);
        box-shadow: 0 4px 16px rgba(0,0,0,.035); backdrop-filter: none;
    }
    .dashboard-shell .metric-card { position: relative; overflow: hidden; padding: 22px !important; min-height: 150px; transition: border-color .18s, background .18s, transform .18s; }
    .dashboard-shell .metric-label { font-size: 11px; font-weight: 500; color: var(--dash-muted); line-height: 1.55; margin-bottom: 7px !important; }
    .dashboard-shell .metric-value { font-size: clamp(25px, 2.5vw, 32px); font-weight: 600; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
    .dashboard-shell .metric-card .small { font-size: 10px; line-height: 1.7; }
    .dashboard-shell .metric-card .fs-2, .dashboard-shell .metric-card .fs-3 { display: inline-flex; flex: 0 0 auto; width: 42px; height: 42px; justify-content: center; align-items: center; border: 1px solid var(--dash-border); background: var(--dash-raised); border-radius: 10px; font-size: 20px !important; }
    .dashboard-shell a.metric-card-link:hover .metric-card, .dashboard-shell a.metric-card-link:focus .metric-card { border-color: var(--dash-accent); background: var(--dash-raised); box-shadow: none; transform: translateY(-2px); }
    .dashboard-shell .license-metrics .metric-card { min-height: 130px; padding: 18px !important; }
    .dashboard-shell .license-metrics .metric-label { max-width: 185px; font-size: 10px; }
    .dashboard-shell .license-metrics .metric-value { font-size: 25px; }
    .dashboard-shell .operational-snapshot-card { padding: 24px; }
    .dashboard-shell .operational-snapshot-panels { --bs-gutter-x: 1.5rem; }
    .dashboard-shell .pie-panel { display: flex; flex-direction: column; align-items: center; gap: 22px; padding: 22px 16px; border: 1px solid var(--dash-border); border-radius: 10px; background: var(--dash-raised); min-height: 300px; height: 100%; }
    .dashboard-shell .pie-chart { position: relative; isolation: isolate; flex: 0 0 auto; width: 142px; height: 142px; border-radius: 50%; display: grid; place-items: center; box-shadow: none; }
    .dashboard-shell .pie-chart::before { content: ''; position: absolute; inset: 13px; border-radius: 50%; background: var(--dash-raised); z-index: -1; }
    .dashboard-shell .pie-chart::after { content: none; }
    .dashboard-shell .pie-chart span { position: static; transform: none; width: auto; height: auto; display: block; background: none; color: var(--dash-text); font-size: 28px; font-weight: 600; font-variant-numeric: tabular-nums; }
    .dashboard-shell .pie-panel-text { width: 100%; }
    .dashboard-shell .pie-panel-text h3 { font-size: 12px; }
    .dashboard-shell .chart-legend { display: grid; gap: 9px; color: var(--dash-muted); font-size: 10px; }
    .dashboard-shell .chart-legend li { display: grid; grid-template-columns: 8px minmax(0,1fr) auto; align-items: center; gap: 8px; }
    .dashboard-shell .chart-legend strong { color: var(--dash-text); font-weight: 600; font-variant-numeric: tabular-nums; }
    .dashboard-shell .legend-dot { display: block; width: 7px; height: 7px; border-radius: 50%; }
    .dashboard-shell .summary-card { min-height: 187px; transition: border-color .18s; }
    .dashboard-shell .dashboard-summary-link { text-decoration: none; color: inherit; display: block; height: 100%; }
    .dashboard-shell .dashboard-summary-link:hover .summary-card { border-color: var(--dash-accent); }
    .dashboard-shell .summary-card h3 { font-size: 16px; }
    .dashboard-shell .stat-chip { display: inline-flex; align-items: center; gap: 6px; padding: 4px 8px; border-radius: 5px; border: 1px solid var(--dash-border); background: var(--dash-raised); color: var(--dash-muted); font-size: 10px; font-weight: 500; }
    .dashboard-shell .summary-card .stat-chip { margin-bottom: 3px !important; }
    .dashboard-shell .summary-arrow { display: grid; place-items: center; width: 32px; height: 32px; flex: 0 0 auto; border: 1px solid var(--dash-border); border-radius: 50%; color: var(--dash-muted); }
    .dashboard-shell .summary-stat-grid { display: grid; grid-template-columns: repeat(3,minmax(0,1fr)); gap: 10px; margin-top: 20px; }
    .dashboard-shell .summary-stat-grid > div + div { border-left: 1px solid var(--dash-border); padding-left: 15px; }
    .dashboard-shell .summary-stat-grid span { display: block; font-size: 24px; font-weight: 600; font-variant-numeric: tabular-nums; }
    .dashboard-shell .summary-stat-grid small { display: block; color: var(--dash-muted); font-size: 10px; }
    body.light-mode .dashboard-shell .summary-stat-grid span[style] { color: #087f78 !important; }
    body.light-mode .dashboard-shell .summary-stat-grid span[data-live-request-count="declined"] { color: var(--dash-danger) !important; }
    .dashboard-shell .table-responsive { border: 1px solid var(--dash-border); border-radius: 9px; }
    .dashboard-shell .table-dark-custom { --bs-table-bg: transparent; --bs-table-color: var(--dash-text); --bs-table-border-color: var(--dash-border); width: 100%; color: var(--dash-text); border-collapse: collapse; }
    .dashboard-shell .table-dark-custom > :not(caption) > * > * { background: transparent; color: inherit; box-shadow: none; border-color: var(--dash-border); padding: 15px 18px; }
    .dashboard-shell .table-dark-custom thead { background: var(--dash-raised); color: var(--dash-muted); font-size: 10px; letter-spacing: .07em; text-transform: uppercase; }
    .dashboard-shell .table-dark-custom th { font-weight: 500; white-space: nowrap; }
    .dashboard-shell .table-dark-custom td { font-size: 11px; }
    .dashboard-shell .table-dark-custom tbody tr:last-child td { border-bottom: 0; }
    .dashboard-shell .table-dark-custom tbody tr:hover { background: var(--dash-raised); }
    .dashboard-shell .table-dark-custom td:first-child a { display: inline-flex; align-items: center; gap: 8px; min-height: 30px; }
    .dashboard-shell .badge { padding: 6px 10px; font-size: 9px; font-weight: 500; letter-spacing: 0; white-space: nowrap; }
    .dashboard-shell .btn-action { display: inline-flex; align-items: center; justify-content: center; min-height: 34px; min-width: 62px; border-radius: 7px; padding: 5px 10px; border: 1px solid var(--dash-border); color: var(--dash-text); background: var(--dash-raised); text-decoration: none; font-size: 11px; font-weight: 600; }
    .dashboard-shell .btn-action:hover { border-color: var(--dash-accent); color: var(--dash-accent); }
    .dashboard-shell a:focus-visible { outline: 3px solid var(--dash-accent); outline-offset: 4px; border-radius: 6px; }
    .dashboard-footer { font-family: 'Poppins', system-ui, sans-serif; color: var(--dash-muted) !important; margin-top: 0 !important; font-size: 10px !important; }
    @media (max-width: 1199.98px) and (min-width: 992px) { .dashboard-shell .pie-panel { flex-direction: row; min-height: 208px; } }
    @media (max-width: 767.98px) {
        .dashboard-shell { margin-top: 24px !important; }
        .dashboard-header { gap: 18px !important; }
        .dashboard-shell .analytics-card, .dashboard-shell .dashboard-card { padding: 18px; }
        .dashboard-shell .metric-card { min-height: 140px; padding: 18px !important; }
        .dashboard-shell .table-dark-custom { min-width: 580px; }
        .dashboard-shell .pie-panel { flex-direction: row; gap: 20px; min-height: 200px; padding: 18px; }
        .dashboard-shell .pie-chart { width: 118px; height: 118px; }
        .dashboard-shell .pie-chart::before { inset: 11px; }
        .dashboard-shell .operational-snapshot-card { padding: 18px; }
    }
    @media (max-width: 399.98px) { .dashboard-shell .pie-panel { flex-direction: column; } .dashboard-shell .dashboard-header-actions { width: 100%; } .dashboard-button { flex: 1; } }
    @media (prefers-reduced-motion: reduce) { .dashboard-shell *, .dashboard-shell *::before, .dashboard-shell *::after { transition: none !important; animation: none !important; scroll-behavior: auto !important; } }
    </style>
</head>
<body class="background_color" id="background_color">
<?php $adminSection = 'home'; include __DIR__ . '/admin_section_nav.php'; ?>

<main class="container my-5 dashboard-shell">
    <?php if (function_exists('expiry_render_page_banners')) { expiry_render_page_banners('admin', true); } ?>
    <div class="dashboard-header d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <span class="dashboard-eyebrow">Map &amp; Pump / Administration</span>
            <h1 class="fw-bold m-0 text-white mt-2">Control Center</h1>
            <p>Your network at a glance. Review requests, monitor licenses, and keep operations moving.</p>
        </div>
        <div class="dashboard-header-actions">
            <a href="view_report.php" class="dashboard-button"><i class="bi bi-file-earmark-bar-graph" aria-hidden="true"></i> View reports</a>
            <a href="view_requests.php?status=pending" class="dashboard-button dashboard-button-primary">Review requests <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        </div>
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
    $addPriorityTask('Inbound requests awaiting review', (int) $requestSummary['pending'], 'Awaiting review', 'view_requests.php?status=pending', 'bi-inbox');
    $addPriorityTask(
        'Driver account verifications',
        $pendingAccounts,
        'Awaiting verification',
        'view_requests.php?type=account&status=pending',
        'bi-person-vcard'
    );
    $addPriorityTask(
        'Vehicle license reviews',
        $pendingCarLicenseReviewCount,
        'Awaiting review',
        'view_requests.php?type=account&status=pending',
        'bi-car-front'
    );
    $addPriorityTask('Payment confirmations to verify', $pendingPaymentReviewCount, 'Awaiting verification', 'view_requests.php?type=payment&status=pending', 'bi-cash-coin');
    $addPriorityTask('Company licenses expiring soon', (int) ($expiryPayload['warning_hq'] ?? 0), 'Due within 10 days', 'view_hq_and_user_account.php?expiry=warning', 'bi-building');
    $addPriorityTask('Driver licenses expiring soon', (int) ($expiryPayload['warning_users'] ?? 0), 'Due within 10 days', 'view_users.php?expiry=warning', 'bi-person-vcard');
    $addPriorityTask('Expired company licenses', (int) ($expiryPayload['expired_hq'] ?? 0), 'Renewal follow-up', 'view_hq_and_user_account.php?expiry=expired', 'bi-building-exclamation');
    $addPriorityTask('Expired driver licenses', (int) ($expiryPayload['expired_users'] ?? 0), 'Renewal follow-up', 'view_users.php?expiry=expired', 'bi-person-x');
    ?>
    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-xl-3">
            <a href="view_hq_and_user_account.php" class="metric-card-link" title="Open View/Manage Companies" aria-label="Open View/Manage Companies">
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
                </div>
            </a>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <a href="loyalty_management.php" class="metric-card-link" title="Open Loyalty &amp; Billing Management" aria-label="Open Loyalty &amp; Billing Management">
                <div class="analytics-card metric-card">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div>
                            <p class="mb-0 metric-label">Total Discounted Amount</p>
                            <h3 class="metric-value text-white mb-0" style="font-size:1.45rem;">
                                <?= $discountedBalance !== null ? number_format($discountedBalance, 2) . ' MMK' : ($loyaltyBalanceError ? 'Unavailable' : '0.00 MMK') ?>
                            </h3>
                        </div>
                        <span class="txt-neon-cyan fs-2"><i class="bi bi-wallet2"></i></span>
                    </div>
                    <p class="text-white-50 small mb-0 mt-2">Company balances, tier pools, reward rules, and monthly payouts</p>
                </div>
            </a>
        </div>
    </div>

    <div class="dashboard-section-heading">
        <h2>License watch</h2>
        <p>Upcoming renewals and expired licenses</p>
    </div>
    <div class="row g-3 mb-4 license-metrics">
        <div class="col-12 col-sm-6 col-xl-3">
            <a href="view_hq_and_user_account.php?expiry=warning" class="metric-card-link" title="View company licenses expiring soon">
                <div class="analytics-card metric-card py-3">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div>
                            <p class="mb-0 metric-label text-warning">Company Licenses Expiring Soon</p>
                            <h3 class="metric-value text-white mb-0"><?= (int) ($expiryPayload['warning_hq'] ?? 0) ?></h3>
                        </div>
                        <span class="text-warning fs-3"><i class="bi bi-building"></i></span>
                    </div>
                    <p class="text-white-50 small mb-0 mt-2">Business licenses expiring within 10 days</p>
                </div>
            </a>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <a href="view_users.php?expiry=warning" class="metric-card-link" title="View driver licenses expiring soon">
                <div class="analytics-card metric-card py-3">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div>
                            <p class="mb-0 metric-label text-warning">Driver Licenses Expiring Soon</p>
                            <h3 class="metric-value text-white mb-0"><?= (int) ($expiryPayload['warning_users'] ?? 0) ?></h3>
                        </div>
                        <span class="text-warning fs-3"><i class="bi bi-person-vcard"></i></span>
                    </div>
                    <p class="text-white-50 small mb-0 mt-2">Driver licenses expiring within 10 days</p>
                </div>
            </a>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <a href="view_hq_and_user_account.php?expiry=expired" class="metric-card-link" title="View companies with expired licenses">
                <div class="analytics-card metric-card py-3">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div>
                            <p class="mb-0 metric-label text-danger">Expired Company Licenses</p>
                            <h3 class="metric-value text-white mb-0"><?= (int) ($expiryPayload['expired_hq'] ?? 0) ?></h3>
                        </div>
                        <span class="text-danger fs-3"><i class="bi bi-building-exclamation"></i></span>
                    </div>
                    <p class="text-white-50 small mb-0 mt-2">Company business licenses past their expiry date</p>
                </div>
            </a>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <a href="view_users.php?expiry=expired" class="metric-card-link" title="View drivers with expired licenses">
                <div class="analytics-card metric-card py-3">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div>
                            <p class="mb-0 metric-label text-danger">Expired Driver Licenses</p>
                            <h3 class="metric-value text-white mb-0"><?= (int) ($expiryPayload['expired_users'] ?? 0) ?></h3>
                        </div>
                        <span class="text-danger fs-3"><i class="bi bi-person-x"></i></span>
                    </div>
                    <p class="text-white-50 small mb-0 mt-2">Drivers with licenses past their expiry date</p>
                </div>
            </a>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-12 col-xl-7">
            <div class="dashboard-card operational-snapshot-card h-100">
                <div class="mb-4">
                    <h2 class="fw-bold h5 text-white mb-1">Operational Snapshot</h2>
                    <p class="dashboard-muted small mb-0">Request activity and report distribution</p>
                </div>
                <div class="row g-4 operational-snapshot-panels">
                    <div class="col-12 col-lg-6">
                        <div class="pie-panel pie-panel-solo operational-snapshot-panel">
                            <div class="pie-chart request-pie" data-live-request-pie role="img" aria-label="Request status chart" style="<?= hq_escape($requestPieStyle) ?>"><span data-live-request-total><?= (int) $requestTotal ?></span></div>
                            <div class="pie-panel-text">
                                <h3 class="h6 fw-semibold text-white mb-3">Request Status</h3>
                                <ul class="chart-legend list-unstyled m-0">
                                    <li><span class="legend-dot bg-warning"></span><span>Pending</span><strong data-live-request-count="pending"><?= (int) $requestCounts['pending'] ?></strong></li>
                                    <li><span class="legend-dot" style="background:#1be494;"></span><span>Approved</span><strong data-live-request-count="approved"><?= (int) $requestCounts['approved'] ?></strong></li>
                                    <li><span class="legend-dot" style="background:#ff4d6a;"></span><span>Reject</span><strong data-live-request-count="declined"><?= (int) $requestCounts['declined'] ?></strong></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-lg-6">
                        <div class="pie-panel pie-panel-solo operational-snapshot-panel">
                            <div class="pie-chart report-pie" role="img" aria-label="Report scope chart" style="<?= hq_escape($reportPieStyle) ?>"><span><?= (int) $reportTotal ?></span></div>
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
                    <a href="view_requests.php" class="dashboard-summary-link">
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
                                <div><span style="color:#ff4d6a;" data-live-request-count="declined"><?= (int) $requestCounts['declined'] ?></span><small>Reject</small></div>
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
                        <p class="dashboard-muted small mb-0">Items that need your attention. Select a task to review it.</p>
                    </div>
                    <span class="stat-chip"><i class="bi bi-list-check txt-neon-cyan me-1" aria-hidden="true"></i> Action items</span>
                </div>
                <div class="table-responsive" role="region" aria-label="Priority work queue; scroll horizontally on small screens" tabindex="0">
                    <table class="table table-dark-custom align-middle m-0">
                        <thead>
                            <tr>
                                <th scope="col">Task</th>
                                <th scope="col">Status</th>
                                <th scope="col" class="text-end">Count / Review</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$priorityTasks): ?>
                                <tr>
                                    <td colspan="3" class="text-center text-white-50 py-4">
                                        <?php if ($dbOnline): ?>
                                            <i class="bi bi-check-circle text-success me-2"></i>No priority tasks need attention.
                                        <?php else: ?>
                                            <i class="bi bi-database-exclamation text-warning me-2"></i>Priority tasks are unavailable while the database is offline.
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($priorityTasks as $task): ?>
                                <tr>
                                    <td class="fw-medium">
                                        <a href="<?= hq_escape($task['url']) ?>" class="text-white text-decoration-none" title="Open <?= hq_escape($task['title']) ?>">
                                            <i class="bi <?= hq_escape($task['icon']) ?> txt-neon-cyan me-2"></i><?= hq_escape($task['title']) ?>
                                        </a>
                                    </td>
                                    <td><span class="badge rounded-pill bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25"><?= hq_escape($task['status']) ?></span></td>
                                    <td class="text-end">
                                        <a href="<?= hq_escape($task['url']) ?>" class="btn-action" title="Open <?= hq_escape($task['title']) ?>">
                                            <?= (int) $task['count'] ?><i class="bi bi-arrow-right ms-2"></i>
                                        </a>
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

<footer class="dashboard-footer text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map &amp; Pump | All Rights Reserved</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="/js/Admin.js"></script>
<?php require_once __DIR__ . '/../moderator_hire_popup.php'; ?>
</body>
</html>