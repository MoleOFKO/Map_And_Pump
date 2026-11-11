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
    $requestPieStyle = "background:conic-gradient(#ffc107 0 {$pendingPct}%, #5c91f5 {$pendingPct}% {$approvedPct}%, #ff4d6a {$approvedPct}% 100%);";
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
    $reportPieStyle = "background:conic-gradient(var(--report-hq-color) 0 {$reportHqPct}%, var(--report-station-color) {$reportHqPct}% {$reportStationPct}%, var(--report-other-color) {$reportStationPct}% 100%);";
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
/* Dashboard-only styles: shared navigation and live hooks remain untouched. */
body.background_color {
    --dash-bg: #0b1220;
    --dash-surface: #121d2d;
    --dash-raised: #182537;
    --dash-border: #27364a;
    --dash-text: #edf3fa;
    --dash-muted: #a4b3c6;
    --dash-accent: #79aaff;
    --dash-blue: #89b5ff;
    --dash-warning: #f7ca72;
    --dash-danger: #ff9aab;
    --neon-cyan: var(--dash-accent);
    --neon-blue: var(--dash-blue);
    --report-hq-color: #4eaaa5;
    --report-station-color: #648bd2;
    --report-other-color: #a47b44;
    background: radial-gradient(ellipse at 90% 0%, rgba(45, 80, 155, .13), transparent 48%), var(--dash-bg);
    color: var(--dash-text);
}
body.background_color.light-mode {
    --dash-bg: #f1f5f8;
    --dash-surface: #ffffff;
    --dash-raised: #f4f7fa;
    --dash-border: #d9e3ec;
    --dash-text: #172b40;
    --dash-muted: #53667b;
    --dash-accent: #285ec7;
    --dash-blue: #3267b2;
    --dash-warning: #865b08;
    --dash-danger: #b0354c;
    --report-hq-color: #176f6b;
    --report-station-color: #315da8;
    --report-other-color: #80541f;
}
.dashboard-shell {
    max-width: 1480px;
    padding-inline: clamp(16px, 3.5vw, 48px);
    margin: 36px auto 24px !important;
    color: var(--dash-text);
    font: 14px/1.65 'Poppins', system-ui, sans-serif;
}
.dashboard-shell *, .dashboard-shell *::before, .dashboard-shell *::after { box-sizing: border-box; }
.dashboard-shell .text-white { color: var(--dash-text) !important; }
.dashboard-shell .text-white-50, .dashboard-muted { color: var(--dash-muted) !important; }
.dashboard-shell .txt-neon-cyan, .dashboard-shell .text-info { color: var(--dash-accent) !important; }
.dashboard-shell .txt-neon-blue { color: var(--dash-blue) !important; }
.dashboard-shell .text-warning { color: var(--dash-warning) !important; }
.dashboard-shell .text-danger { color: var(--dash-danger) !important; }
.dashboard-shell h1, .dashboard-shell h2, .dashboard-shell h3 { letter-spacing: -.035em; line-height: 1.3; }
.dashboard-header { padding: 8px 0 28px; border-bottom: 1px solid var(--dash-border); }
.dashboard-header h1 { font-size: clamp(28px, 3vw, 38px); font-weight: 600 !important; }
.dashboard-eyebrow { color: var(--dash-accent); font-size: 11px; letter-spacing: .13em; text-transform: uppercase; font-weight: 600; }
.dashboard-header p { max-width: 580px; margin: 12px 0 0; color: var(--dash-muted); font-size: 13px; }
.dashboard-header-tools { display: flex; flex-direction: column; align-items: flex-end; gap: 14px; }
.dashboard-connection { display: inline-flex; align-items: center; gap: 7px; color: var(--dash-muted); font-size: 12px; }
.dashboard-connection::before { content: ''; width: 7px; height: 7px; border-radius: 50%; background: var(--dash-accent); box-shadow: 0 0 0 4px rgba(121,170,255,.08); }
.dashboard-connection.is-offline::before { background: var(--dash-warning); box-shadow: none; }
.dashboard-header-actions { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; }
.dashboard-button { display: inline-flex; align-items: center; justify-content: center; gap: 9px; min-height: 44px; padding: 11px 16px; border: 1px solid var(--dash-border); border-radius: 9px; color: var(--dash-text); background: var(--dash-surface); font-size: 12px; font-weight: 500; text-decoration: none; transition: background .2s, border-color .2s, transform .2s; }
.dashboard-button-primary { background: #79aaff; color: #10284c; border-color: #79aaff; box-shadow: 0 4px 16px rgba(64,112,218,.12); }
.dashboard-button:hover { color: var(--dash-text); border-color: var(--dash-accent); background: var(--dash-raised); }
.dashboard-button-primary:hover { color: #10284c; background: #9cc1ff; transform: translateY(-1px); }
.dashboard-section-heading { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 8px; margin: 26px 0 14px; }
.dashboard-section-heading h2 { font-size: 16px; font-weight: 600; margin: 0; }
.dashboard-section-heading p { color: var(--dash-muted); font-size: 12px; margin: 0; }
.dashboard-shell .analytics-card, .dashboard-shell .dashboard-card { height: 100%; padding: 24px; border: 1px solid var(--dash-border); border-radius: 14px; background: var(--dash-surface); box-shadow: 0 6px 24px rgba(0,0,0,.08); backdrop-filter: none; }
.dashboard-shell a.metric-card-link { display: block; height: 100%; text-decoration: none; color: inherit; }
.dashboard-shell .metric-card { position: relative; overflow: hidden; padding: 24px !important; min-height: 164px; background: linear-gradient(135deg, rgba(121,170,255,.025), transparent 80%), var(--dash-surface); transition: border-color .2s, transform .2s, background .2s; }
.dashboard-shell .metric-card::before { content: ''; position: absolute; top: 24px; left: 0; width: 3px; height: 32px; background: var(--dash-accent); border-radius: 0 4px 4px 0; }
.dashboard-shell .metric-label { color: var(--dash-muted); font-size: 12px; font-weight: 500; line-height: 1.5; margin-bottom: 10px !important; }
.dashboard-shell .metric-value { font-size: clamp(28px, 2.6vw, 36px); font-weight: 600; letter-spacing: -.04em; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
.dashboard-shell .metric-card .small { font-size: 12px; line-height: 1.65; }
.dashboard-shell .metric-card .fs-2, .dashboard-shell .metric-card .fs-3 { display: inline-flex; flex: 0 0 auto; justify-content: center; align-items: center; width: 44px; height: 44px; border-radius: 12px; border: 1px solid var(--dash-border); background: var(--dash-raised); font-size: 20px !important; }
.dashboard-shell a.metric-card-link:hover .metric-card { border-color: var(--dash-accent); transform: translateY(-3px); box-shadow: 0 8px 24px rgba(0,0,0,.1); }
.dashboard-shell .license-metrics { --bs-gutter-x: 0; --bs-gutter-y: 0; border: 1px solid var(--dash-border); border-radius: 14px; overflow: hidden; background: var(--dash-surface); }
.dashboard-shell .license-metrics > div + div { border-left: 1px solid var(--dash-border); }
.dashboard-shell .license-metrics .metric-card { border: 0; border-radius: 0; min-height: 152px; padding: 20px !important; background: transparent; box-shadow: none; }
.dashboard-shell .license-metrics .metric-card::before { display: none; }
.dashboard-shell .license-metrics a:hover .metric-card { transform: none; background: var(--dash-raised); box-shadow: none; }
.dashboard-shell .license-metrics .metric-label { font-size: 12px; }
.dashboard-shell .license-metrics .metric-value { font-size: 28px; }
.dashboard-shell .license-metrics .metric-card .small { font-size: 11px; }
.dashboard-shell .license-metrics .metric-card .fs-3 { width: 36px; height: 36px; font-size: 17px !important; border: 0; }
.dashboard-shell .operational-snapshot-card { padding: 26px; }
.dashboard-shell .operational-snapshot-card h2 { font-size: 18px; font-weight: 600 !important; }
.dashboard-shell .operational-snapshot-card .small { font-size: 12px; }
.dashboard-shell .operational-snapshot-panels { --bs-gutter-x: 1.25rem; --bs-gutter-y: 1.25rem; }
.dashboard-shell .pie-panel { display: flex; flex-direction: column; align-items: center; gap: 28px; padding: 28px 20px 24px; min-height: 338px; height: 100%; border: 1px solid var(--dash-border); border-radius: 12px; background: var(--dash-raised); }
.dashboard-shell .pie-chart { position: relative; isolation: isolate; flex: 0 0 auto; width: 160px; height: 160px; border-radius: 50%; display: grid; place-items: center; box-shadow: none; }
.dashboard-shell .pie-chart::before { content: ''; position: absolute; inset: 12px; border-radius: 50%; background: var(--dash-raised); z-index: 0; }
.dashboard-shell .pie-chart::after { content: none; }
.dashboard-shell .pie-chart span { position: relative; inset: auto; transform: none; z-index: 1; display: block; width: auto; height: auto; border: 0; border-radius: 0; background: none; color: var(--dash-text); font-size: 32px; font-weight: 600; line-height: 1.2; font-variant-numeric: tabular-nums; }
.dashboard-shell .pie-chart span::after { content: 'TOTAL'; display: block; text-align: center; color: var(--dash-muted); font-size: 10px; font-weight: 500; letter-spacing: .12em; margin-top: 8px; }
.dashboard-shell .pie-panel-text { width: 100%; }
.dashboard-shell .pie-panel-text h3 { font-size: 14px; }
.dashboard-shell .chart-legend { display: grid; gap: 12px; color: var(--dash-muted); font-size: 12px; }
.dashboard-shell .chart-legend li { display: grid; grid-template-columns: 8px minmax(0,1fr) auto; gap: 9px; align-items: center; }
.dashboard-shell .chart-legend strong { color: var(--dash-text); font-weight: 600; font-variant-numeric: tabular-nums; }
.dashboard-shell .legend-dot { display: block; width: 8px; height: 8px; border-radius: 50%; }
.dashboard-shell .report-pie + .pie-panel-text .bg-info { background: var(--report-hq-color) !important; }
.dashboard-shell .report-pie + .pie-panel-text .bg-primary { background: var(--report-station-color) !important; }
.dashboard-shell .report-pie + .pie-panel-text .report-other-dot { background: var(--report-other-color); }
.dashboard-shell .summary-card { min-height: 205px; transition: border-color .2s, background .2s; }
.dashboard-shell .dashboard-summary-link { display: block; height: 100%; color: inherit; text-decoration: none; }
.dashboard-shell .dashboard-summary-link:hover .summary-card { background: var(--dash-raised); border-color: var(--dash-accent); }
.dashboard-shell .summary-card h3 { font-size: 17px; font-weight: 600 !important; }
.dashboard-shell .stat-chip { display: inline-flex; align-items: center; gap: 7px; padding: 5px 9px; border: 1px solid var(--dash-border); border-radius: 6px; background: var(--dash-raised); color: var(--dash-muted); font-size: 11px; font-weight: 500; }
.dashboard-shell .summary-card .stat-chip { margin-bottom: 4px !important; }
.dashboard-shell .summary-arrow { display: grid; place-items: center; flex: 0 0 auto; width: 36px; height: 36px; border: 1px solid var(--dash-border); border-radius: 10px; color: var(--dash-accent); }
.dashboard-shell .summary-stat-grid { display: grid; grid-template-columns: repeat(3,minmax(0,1fr)); gap: 12px; margin-top: 20px; }
.dashboard-shell .summary-stat-grid > div + div { border-left: 1px solid var(--dash-border); padding-left: 16px; }
.dashboard-shell .summary-stat-grid span { display: block; font-size: 27px; font-weight: 600; line-height: 1.4; font-variant-numeric: tabular-nums; }
.dashboard-shell .summary-stat-grid small { display: block; font-size: 11px; color: var(--dash-muted); margin-top: 4px; }
body.light-mode .dashboard-shell .summary-stat-grid span[style] { color: #285ec7 !important; }
body.light-mode .dashboard-shell .summary-stat-grid span[data-live-request-count="declined"] { color: var(--dash-danger) !important; }
.dashboard-shell .table-responsive { border: 1px solid var(--dash-border); border-radius: 10px; }
.dashboard-shell .table-dark-custom { --bs-table-bg: transparent; --bs-table-color: var(--dash-text); --bs-table-border-color: var(--dash-border); width: 100%; border-collapse: collapse; color: var(--dash-text); }
.dashboard-shell .table-dark-custom > :not(caption) > * > * { padding: 16px 20px; background: transparent; color: inherit; border-color: var(--dash-border); box-shadow: none; }
.dashboard-shell .table-dark-custom thead { background: var(--dash-raised); color: var(--dash-muted); font-size: 11px; letter-spacing: .06em; text-transform: uppercase; }
.dashboard-shell .table-dark-custom th { font-weight: 500; white-space: nowrap; }
.dashboard-shell .table-dark-custom td { font-size: 12px; }
.dashboard-shell .table-dark-custom tbody tr:last-child td { border-bottom: 0; }
.dashboard-shell .table-dark-custom tbody tr:hover { background: var(--dash-raised); }
.dashboard-shell .table-dark-custom td:first-child a { display: inline-flex; gap: 9px; align-items: center; min-height: 44px; }
.dashboard-shell .table-dark-custom td:first-child .bi { display: inline-grid; place-items: center; flex: 0 0 auto; width: 34px; height: 34px; border: 1px solid var(--dash-border); background: var(--dash-raised); border-radius: 8px; }
.dashboard-shell .badge { font-size: 11px; font-weight: 500; padding: 7px 10px; letter-spacing: 0; line-height: 1.3; white-space: nowrap; }
.dashboard-shell .btn-action { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 44px; min-width: 76px; padding: 8px 12px; border: 1px solid var(--dash-border); border-radius: 8px; background: var(--dash-raised); color: var(--dash-text); font-size: 12px; font-weight: 600; text-decoration: none; }
.dashboard-shell .btn-action:hover { border-color: var(--dash-accent); color: var(--dash-accent); }
.dashboard-shell a:focus-visible, .dashboard-shell [tabindex]:focus-visible { outline: 3px solid var(--dash-accent); outline-offset: 4px; border-radius: 6px; }
.dashboard-shell .hq-flash { padding: 14px 18px; border: 1px solid var(--dash-border); border-radius: 10px; background: var(--dash-raised); color: var(--dash-text); font-size: 13px; }
.dashboard-shell .hq-flash.alert-success { border-color: var(--dash-accent); }
.dashboard-shell .hq-flash.alert-danger { border-color: var(--dash-danger); }
.dashboard-shell .hq-flash.alert-warning { border-color: rgba(247,202,114,.4); }
.dashboard-footer { margin-top: 0 !important; color: var(--dash-muted) !important; font: 11px/1.6 'Poppins', system-ui, sans-serif; }
@media (min-width: 992px) and (max-width: 1199.98px) { .dashboard-shell .pie-panel { flex-direction: row; min-height: 250px; } }
@media (max-width: 1199.98px) { .dashboard-shell .license-metrics > div:nth-child(3) { border-left: 0; } .dashboard-shell .license-metrics > div:nth-child(n+3) { border-top: 1px solid var(--dash-border); } }
@media (max-width: 767.98px) {
    .dashboard-shell { margin-top: 22px !important; }
    .dashboard-header { gap: 22px !important; }
    .dashboard-header-tools { align-items: flex-start; width: 100%; }
    .dashboard-shell .analytics-card, .dashboard-shell .dashboard-card { padding: 20px; }
    .dashboard-shell .metric-card { padding: 20px !important; min-height: 148px; }
    .dashboard-shell .pie-panel { flex-direction: row; gap: 20px; min-height: 205px; padding: 20px; }
    .dashboard-shell .pie-chart { width: 128px; height: 128px; }
    .dashboard-shell .pie-chart::before { inset: 10px; }
    .dashboard-shell .pie-chart span { font-size: 27px; }
    .dashboard-shell .table-dark-custom { min-width: 620px; }
    .dashboard-shell .summary-stat-grid > div + div { padding-left: 12px; }
}
@media (max-width: 575.98px) {
    .dashboard-shell .license-metrics > div + div { border-left: 0; border-top: 1px solid var(--dash-border); }
    .dashboard-shell .license-metrics .metric-card { min-height: 125px; }
    .dashboard-header-actions { width: 100%; }
    .dashboard-button { flex: 1; }
}
@media (max-width: 419.98px) { .dashboard-shell .pie-panel { flex-direction: column; } .dashboard-shell .summary-stat-grid { gap: 8px; } }
@media (prefers-reduced-motion: reduce) { .dashboard-shell *, .dashboard-shell *::before, .dashboard-shell *::after { animation: none !important; transition: none !important; scroll-behavior: auto !important; } }
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
        <div class="dashboard-header-tools">
            <span class="dashboard-connection<?= $dbOnline ? '' : ' is-offline' ?>">
                <?= $dbOnline ? 'Database connected' : 'Database offline' ?>
            </span>
            <div class="dashboard-header-actions">
                <a href="view_report.php" class="dashboard-button"><i class="bi bi-file-earmark-bar-graph" aria-hidden="true"></i> View reports</a>
                <a href="view_requests.php?status=pending" class="dashboard-button dashboard-button-primary">Review requests <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
            </div>
        </div>
    </div>

    <?php if (!$dbOnline): ?>
        <div class="alert alert-warning hq-flash" role="status">
            <i class="bi bi-database-exclamation me-2" aria-hidden="true"></i>Database offline. The figures below are fallback values, not current operational data.
        </div>
    <?php elseif ($requestSummaryError): ?>
        <div class="alert alert-warning hq-flash" role="status">
            <i class="bi bi-exclamation-circle me-2" aria-hidden="true"></i>Request review data could not be loaded. Queue counts may be incomplete.
        </div>
    <?php endif; ?>

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
                            <h3 class="metric-value text-white mb-0" style="font-size:clamp(20px, 1.65vw, 26px);">
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
                                    <li><span class="legend-dot" style="background:#5c91f5;"></span><span>Approved</span><strong data-live-request-count="approved"><?= (int) $requestCounts['approved'] ?></strong></li>
                                    <li><span class="legend-dot" style="background:#ff4d6a;"></span><span>Declined</span><strong data-live-request-count="declined"><?= (int) $requestCounts['declined'] ?></strong></li>
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
                                    <li><span class="legend-dot report-other-dot"></span><span>Requests/System</span><strong><?= $reportOtherCount ?></strong></li>
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
                                <div><span style="color:#5c91f5;" data-live-request-count="approved"><?= (int) $requestCounts['approved'] ?></span><small>Approved</small></div>
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
                                </div>
                                <span class="summary-arrow"><i class="bi bi-arrow-right"></i></span>
                            </div>
                            <div class="summary-stat-grid">
                                <div><span class="txt-neon-cyan"><?= $reportHqCount ?></span><small>Company Reports</small></div>
                                <div><span class="txt-neon-blue"><?= $reportStationCount ?></span><small>Station Logs</small></div>
                                <div><span style="color:#5c91f5;"><?= $reportOtherCount ?></span><small>Requests / System</small></div>
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
                    <span class="stat-chip"><i class="bi bi-list-check txt-neon-cyan" aria-hidden="true"></i> <?= count($priorityTasks) ?> task categories</span>
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
                                    <td><span class="badge rounded-pill <?= $task['status'] === 'Renewal follow-up' ? 'bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25' : 'bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25' ?>"><?= hq_escape($task['status']) ?></span></td>
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