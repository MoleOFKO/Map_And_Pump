<?php
require_once __DIR__ . '/admin_common.php';
require_once __DIR__ . '/request_common.php';
require_once __DIR__ . '/user_account_common.php';

admin_require_admin();

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
        'stations' => "SELECT COUNT(*) AS total FROM branches WHERE LOWER(TRIM(status)) = 'active'",
    ];
    foreach ($queries as $key => $sql) {
        $query = $conn->query($sql);
        if ($query) {
            $metrics[$key] = (int) (($query->fetch_assoc()['total'] ?? 0));
        }
    }
    // Keep this card aligned with the shared, all-type pending review queue.
    $requestSummary = request_review_queue_summary('admin');
    $metrics['requests'] = (int) $requestSummary['pending'];
    if (!empty($requestSummary['error'])) {
        $dbError = (string) $requestSummary['error'];
    }
    $reportMetric = $conn->query("SELECT COUNT(*) AS total, COALESCE(SUM(DATE(created_at) = CURDATE()), 0) AS today FROM report");
    if ($reportMetric) {
        $metricRow = $reportMetric->fetch_assoc() ?: [];
        $metrics['reports'] = (int) ($metricRow['total'] ?? 0);
        $metrics['reports_today'] = (int) ($metricRow['today'] ?? 0);
    }

    $reportQuery = $conn->query(
        'SELECT p.report_id, p.short_summery, p.message, p.importance, p.created_at, p.role_id,
                u.user_name, r.role_name
         FROM report p
         LEFT JOIN users u ON u.user_id = p.user_id
         LEFT JOIN roles r ON r.role_id = p.role_id
         ORDER BY p.created_at DESC, p.report_id DESC'
    );
    if ($reportQuery) {
        while ($row = $reportQuery->fetch_assoc()) {
            $meta = admin_report_metadata((string) $row['message'], [
                'short_summery' => (string) ($row['short_summery'] ?? ''),
                'role_id' => $row['role_id'] ?? null,
                'role_name' => (string) ($row['role_name'] ?? ''),
            ]);
            $meta['scope'] = admin_normalize_report_scope((string) $meta['scope']);
            $row['metadata'] = $meta;
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
    $scope = admin_normalize_report_scope((string) $metadata['scope']);
    if (!array_key_exists($scope, $scopeCounts)) {
        $scope = 'system';
    }
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
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&amp;display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="icon" type="image/png" href="/images/Logo.png">
    <link rel="stylesheet" href="/css/Admin.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .report-scope{display:inline-flex;border-radius:999px;padding:.35rem .7rem;font-size:.72rem;font-weight:700;background:rgba(0,244,255,.08);color:var(--neon-cyan);border:1px solid rgba(0,244,255,.24)}
        .report-message{max-width:460px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .report-detail-modal .modal-dialog{max-width:min(920px,96vw)}
        .report-detail-modal .modal-content{border:1px solid rgba(0,244,255,.16);border-radius:18px;overflow:hidden;background:rgba(8,16,30,.96);box-shadow:0 24px 60px rgba(0,0,0,.45)}
        .report-detail-modal .modal-header{border-bottom:1px solid rgba(0,244,255,.14);background:linear-gradient(120deg,rgba(0,244,255,.1),rgba(37,99,235,.04) 55%,transparent);padding:1rem 1.25rem}
        .report-detail-modal .modal-body{padding:1.25rem 1.35rem 1.4rem}
        .report-detail-modal .modal-footer{border-top:1px solid rgba(255,255,255,.08);background:rgba(4,13,30,.45);padding:.9rem 1.25rem;gap:.65rem}
        .report-detail-hero{display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:1rem;padding:1.15rem 1.2rem;border:1px solid rgba(0,244,255,.22);border-radius:16px;background:linear-gradient(135deg,rgba(0,244,255,.11),rgba(27,228,148,.04) 55%,rgba(8,16,30,.2))}
        .report-detail-hero-main{min-width:0;flex:1 1 240px}
        .report-detail-hero-meta{display:flex;flex-wrap:wrap;gap:.45rem;justify-content:flex-end;align-content:flex-start}
        .report-detail-chip{display:inline-flex;align-items:center;gap:.35rem;padding:.35rem .7rem;border-radius:999px;border:1px solid rgba(0,244,255,.22);background:rgba(0,244,255,.08);color:var(--neon-cyan);font-size:.72rem;font-weight:600;white-space:nowrap}
        .report-detail-chip.is-muted{color:var(--text-muted);border-color:rgba(255,255,255,.12);background:rgba(255,255,255,.04)}
        .report-detail-label{color:var(--text-muted);font-size:.68rem;letter-spacing:.08em;text-transform:uppercase;font-weight:600}
        .report-detail-value{color:var(--text-primary);word-break:break-word;overflow-wrap:anywhere;line-height:1.45}
        .report-detail-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.85rem;margin:1.15rem 0 1.25rem}
        .report-detail-card{display:flex;flex-direction:column;gap:.35rem;min-height:92px;padding:.95rem 1rem;border:1px solid rgba(255,255,255,.1);border-radius:14px;background:rgba(255,255,255,.03)}
        .report-detail-section{margin-top:.25rem}
        .report-detail-section + .report-detail-section{margin-top:1rem}
        .report-detail-section-head{display:flex;align-items:center;justify-content:space-between;gap:.75rem;margin-bottom:.55rem}
        .report-detail-content{max-height:min(36vh,300px);overflow-y:auto;padding:1rem 1.05rem;border:1px solid rgba(0,244,255,.16);border-radius:14px;background:rgba(0,244,255,.04);line-height:1.6;white-space:pre-wrap;word-break:break-word;overscroll-behavior:contain;scrollbar-width:thin;scrollbar-color:rgba(0,244,255,.4) rgba(4,12,28,.85)}
        .report-detail-content::-webkit-scrollbar{width:8px}
        .report-detail-content::-webkit-scrollbar-thumb{background:rgba(0,244,255,.35);border-radius:999px}
        @media (max-width:991.98px){.report-detail-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media (max-width:575.98px){
            .report-detail-grid{grid-template-columns:minmax(0,1fr)}
            .report-detail-hero{padding:1rem}
            .report-detail-hero-meta{justify-content:flex-start}
            .report-detail-card{min-height:0}
        }
        body.background_color.light-mode .report-detail-modal .modal-content{background:#fff;box-shadow:0 18px 48px rgba(23,43,64,.14)}
        body.background_color.light-mode .report-detail-modal .modal-header{background:linear-gradient(120deg,rgba(0,164,180,.1),rgba(37,99,235,.04) 58%,transparent)}
        body.background_color.light-mode .report-detail-modal .modal-footer,
        body.background_color.light-mode .report-detail-card{background:rgba(255,255,255,.9)}
        body.background_color.light-mode .report-detail-hero{background:linear-gradient(135deg,rgba(40,94,199,.08),rgba(255,255,255,.7))}
        body.background_color.light-mode .report-detail-content{background:rgba(40,94,199,.04);border-color:rgba(40,94,199,.14)}
        .report-flash{border-radius:14px;background:rgba(10,22,44,.82);border:1px solid var(--glass-border);color:var(--text-primary)}
        body.background_color.light-mode .report-flash{background:rgba(255,255,255,.92)}
        .report-table-scroll{max-height:min(60vh,520px);overflow:auto}
        .report-scope-chip{font-size:.72rem}
    </style>
    <style>
        body.background_color.admin-blue-page {
            --ab-bg: #0b1220;
            --ab-surface: #121d2d;
            --ab-raised: #182537;
            --ab-border: #27364a;
            --ab-text: #edf3fa;
            --ab-muted: #a4b3c6;
            --ab-accent: #79aaff;
            --ab-warning: #f7ca72;
            --ab-danger: #ff9aab;
            --neon-cyan: var(--ab-accent);
            --glass-border: var(--ab-border);
            --text-primary: var(--ab-text);
            --text-muted: var(--ab-muted);
            min-height: 100vh;
            color: var(--ab-text);
            font-family: 'Poppins',system-ui,sans-serif;
            background: radial-gradient(ellipse at 90% 0%, rgba(45,80,155,.13), transparent 48%), var(--ab-bg);
            background-repeat: no-repeat;
        }
        body.background_color.admin-blue-page:is(.light-mode,[data-theme="light"]) {
            --ab-bg: #f1f5f8;
            --ab-surface: #fff;
            --ab-raised: #f4f7fa;
            --ab-border: #d9e3ec;
            --ab-text: #172b40;
            --ab-muted: #53667b;
            --ab-accent: #285ec7;
            --ab-warning: #865b08;
            --ab-danger: #b0354c;
        }
        body.admin-blue-page main.container {
            max-width: 1480px;
            padding-inline: clamp(16px,3.5vw,48px);
            margin: 36px auto 24px !important;
            color: var(--ab-text);
            font: 14px/1.65 'Poppins',system-ui,sans-serif;
        }
        body.admin-blue-page main.container > .d-flex:first-child {
            padding: 8px 0 28px;
            border-bottom: 1px solid var(--ab-border);
        }
        body.admin-blue-page main.container > .d-flex:first-child .stat-chip {
            padding: 0;
            border: 0;
            border-radius: 0;
            background: none;
            color: var(--ab-accent);
            font-size: 11px;
            font-weight: 600;
            letter-spacing: .13em;
            text-transform: uppercase;
        }
        body.admin-blue-page main.container > .d-flex:first-child h1 {
            margin-top: 8px !important;
            color: var(--ab-text) !important;
            font-size: clamp(26px,3vw,38px);
            font-weight: 600 !important;
            line-height: 1.3;
        }
        body.admin-blue-page main.container :is(.text-white,.fw-bold) { color: var(--ab-text) !important; }
        body.admin-blue-page main.container :is(.text-white-50,.metric-label) { color: var(--ab-muted) !important; }
        body.admin-blue-page main.container .txt-neon-cyan { color: var(--ab-accent) !important; }
        body.admin-blue-page main.container :is(.analytics-card,.dashboard-card) {
            padding: 24px;
            border: 1px solid var(--ab-border);
            border-radius: 14px;
            background: var(--ab-surface);
            color: var(--ab-text);
            box-shadow: 0 6px 24px rgba(0,0,0,.08);
        }
        body.admin-blue-page main.container .metric-card {
            position: relative;
            height: 100%;
            min-height: 148px;
            overflow: hidden;
            background: linear-gradient(135deg,rgba(121,170,255,.025),transparent 80%),var(--ab-surface);
        }
        body.admin-blue-page main.container .metric-card::before {
            position: absolute;
            top: 24px;
            left: 0;
            width: 3px;
            height: 32px;
            border-radius: 0 4px 4px 0;
            background: var(--ab-accent);
            content: '';
        }
        body.admin-blue-page main.container .metric-card h4 { font-size: 34px; font-variant-numeric: tabular-nums; }
        body.admin-blue-page main.container .admin-filter-card { padding: 22px 24px; }
        body.admin-blue-page main.container .admin-filter-form { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 16px; }
        body.admin-blue-page main.container .filter-group { flex: 1 1 170px; min-width: 0; }
        body.admin-blue-page main.container .filter-group--search { flex: 2 1 300px; }
        body.admin-blue-page main.container :is(.filter-label,.filter-group label) {
            display: block;
            margin-bottom: 8px;
            color: var(--ab-muted);
            font-size: 12px;
            font-weight: 500;
        }
        body.admin-blue-page main.container .filter-search { position: relative; }
        body.admin-blue-page main.container .filter-search-icon {
            position: absolute;
            top: 50%;
            left: 13px;
            z-index: 1;
            color: var(--ab-muted);
            pointer-events: none;
            transform: translateY(-50%);
        }
        body.admin-blue-page main.container .filter-input { padding-left: 38px !important; }
        body.admin-blue-page main.container :is(.form-control,.form-select) {
            min-height: 44px;
            width: 100%;
            border: 1px solid var(--ab-border);
            border-radius: 8px;
            background-color: var(--ab-raised);
            color: var(--ab-text);
            font: 13px/1.5 'Poppins',system-ui,sans-serif;
            padding: 11px 12px;
            box-shadow: none;
        }
        body.admin-blue-page main.container :is(.form-control,.form-select):focus {
            border-color: var(--ab-accent);
            outline: 2px solid var(--ab-accent);
            outline-offset: 2px;
        }
        body.admin-blue-page main.container .filter-actions { display: flex; flex: 0 0 auto; }
        body.admin-blue-page main.container .btn-clear-filters {
            min-height: 44px;
            border: 1px solid var(--ab-border);
            border-radius: 8px;
            background: var(--ab-raised);
            color: var(--ab-text);
            padding: 10px 16px;
        }
        body.admin-blue-page main.container .report-scope {
            border-color: var(--ab-border);
            border-radius: 6px;
            background: var(--ab-raised);
            color: var(--ab-muted);
            font-size: 11px;
            font-weight: 500;
        }
        body.admin-blue-page main.container .report-table-scroll { max-height: min(68vh,640px); }
        body.admin-blue-page main.container .table-responsive {
            border: 1px solid var(--ab-border);
            border-radius: 10px;
            scrollbar-color: var(--ab-border) var(--ab-raised);
        }
        body.admin-blue-page main.container .table {
            --bs-table-bg: transparent;
            --bs-table-color: var(--ab-text);
            --bs-table-border-color: var(--ab-border);
            color: var(--ab-text);
            font-size: 13px;
        }
        body.admin-blue-page main.container .table > :not(caption) > * > * {
            padding: 16px;
            color: inherit;
            background: transparent;
            border-color: var(--ab-border);
            box-shadow: none;
        }
        body.admin-blue-page main.container .table thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: var(--ab-raised);
            color: var(--ab-muted);
            font-size: 11px;
            font-weight: 500;
            letter-spacing: .06em;
            text-transform: uppercase;
            white-space: nowrap;
        }
        body.admin-blue-page main.container .table tbody tr:hover { background: var(--ab-raised); }
        body.admin-blue-page main.container .table-dark-custom { min-width: 960px; }
        body.admin-blue-page .report-flash,
        body.admin-blue-page .alert-danger {
            border: 1px solid var(--ab-border);
            border-radius: 10px;
            background: var(--ab-raised);
            color: var(--ab-text);
        }
        body.admin-blue-page .modal .modal-content {
            border: 1px solid var(--ab-border);
            border-radius: 18px;
            background: var(--ab-surface) !important;
            color: var(--ab-text);
            box-shadow: 0 22px 50px rgba(0,0,0,.28);
        }
        body.admin-blue-page .modal :is(.modal-header,.modal-footer) {
            border-color: var(--ab-border);
            background: var(--ab-surface);
        }
        body.admin-blue-page .modal .modal-title,
        body.admin-blue-page .modal .text-white { color: var(--ab-text) !important; }
        body.admin-blue-page .modal .text-white-50 { color: var(--ab-muted) !important; }
        body.admin-blue-page .modal .report-detail-hero {
            border-color: rgba(121,170,255,.28);
            border-radius: 16px;
            background: linear-gradient(135deg, rgba(121,170,255,.12), var(--ab-raised) 70%);
            color: var(--ab-text);
        }
        body.admin-blue-page .modal :is(.report-detail-card,.report-detail-content) {
            border-color: var(--ab-border);
            border-radius: 14px;
            background: var(--ab-raised);
            color: var(--ab-text);
        }
        body.admin-blue-page .modal .report-detail-chip {
            border-color: rgba(121,170,255,.28);
            background: rgba(121,170,255,.1);
            color: var(--ab-accent);
        }
        body.admin-blue-page .modal .report-detail-chip.is-muted {
            border-color: var(--ab-border);
            background: var(--ab-raised);
            color: var(--ab-muted);
        }
        body.admin-blue-page .modal .report-detail-label { color: var(--ab-muted); }
        body.admin-blue-page footer { color: var(--ab-muted) !important; font: 11px/1.6 'Poppins',system-ui,sans-serif; }
        @media (max-width: 767.98px) {
            body.admin-blue-page main.container { margin-top: 22px !important; }
            body.admin-blue-page main.container :is(.analytics-card,.dashboard-card) { padding: 20px; }
            body.admin-blue-page main.container .filter-group { flex-basis: calc(50% - 8px); }
            body.admin-blue-page main.container .filter-actions { flex-basis: 100%; justify-content: flex-end; }
        }
        @media (max-width: 419.98px) {
            body.admin-blue-page main.container .filter-group { flex-basis: 100%; }
        }
    </style>
</head>
<body class="background_color admin-blue-page" id="background_color">
<?php $adminSection = 'report'; include __DIR__ . '/admin_section_nav.php'; ?>

    
    

    <main class="container my-5"><div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3"><div><span class="stat-chip mb-2"><i class="bi bi-bar-chart-line-fill txt-neon-cyan"></i> Analytics Center</span><h1 class="fw-bold m-0 h4 text-white mt-2">System Reports</h1></div></div>
        <?php if ($flash && $flashType !== ''): ?><div class="alert report-flash alert-<?= hq_escape($flashType) ?> alert-dismissible fade show" role="alert"><i class="bi <?= $flashType === 'success' ? 'bi-check-circle-fill text-success' : 'bi-exclamation-triangle-fill text-danger' ?> me-2"></i><?= hq_escape((string) ($flash['message'] ?? '')) ?><button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
        <?php if ($dbError): ?><div class="alert alert-danger border-0" role="alert"><?= hq_escape($dbError) ?></div><?php endif; ?>

        <div class="row g-4 mb-4"><div class="col-12 col-sm-6 col-md-3"><div class="dashboard-card metric-card"><p class="mb-1 metric-label">Total Companies Registered</p><h4 class="fw-bold text-white mb-0"><?= (int) $metrics['hqs'] ?></h4></div></div><div class="col-12 col-sm-6 col-md-3"><div class="dashboard-card metric-card"><p class="mb-1 metric-label">Active Stations</p><h4 class="fw-bold text-white mb-0"><?= (int) $metrics['stations'] ?></h4></div></div><div class="col-12 col-sm-6 col-md-3"><div class="dashboard-card metric-card"><p class="mb-1 metric-label">Pending Requests</p><h4 class="fw-bold text-white mb-0"><?= (int) $metrics['requests'] ?></h4></div></div><div class="col-12 col-sm-6 col-md-3"><div class="dashboard-card metric-card"><p class="mb-1 metric-label">Reports Generated</p><h4 class="fw-bold text-white mb-0"><?= (int) $metrics['reports'] ?></h4><small class="text-success"><?= (int) $metrics['reports_today'] ?> created today</small></div></div></div>

        <div class="d-flex flex-wrap gap-2 mb-4">
            <span class="stat-chip report-scope-chip"><i class="bi bi-building me-1"></i>Companies <?= (int) ($scopeCounts['hq_performance'] ?? 0) ?></span>
            <span class="stat-chip report-scope-chip"><i class="bi bi-fuel-pump me-1"></i>Stations <?= (int) ($scopeCounts['stations'] ?? 0) ?></span>
            <span class="stat-chip report-scope-chip"><i class="bi bi-inbox me-1"></i>Requests <?= (int) ($scopeCounts['requests'] ?? 0) ?></span>
            <span class="stat-chip report-scope-chip"><i class="bi bi-shield-lock me-1"></i>System <?= (int) (($scopeCounts['system'] ?? 0) + ($scopeCounts['audit_logs'] ?? 0)) ?></span>
        </div>

        <div class="dashboard-card admin-filter-card mb-4"><form class="admin-filter-form" method="GET" action="view_report.php"><div class="filter-group filter-group--search"><label class="filter-label" for="reportSearch">Search</label><div class="filter-search"><i class="bi bi-search filter-search-icon" aria-hidden="true"></i><input id="reportSearch" type="text" class="form-control filter-control filter-input" placeholder="Search by report name, ID, or author..." name="search" value="<?= hq_escape($search) ?>"></div></div><div class="filter-group"><label class="filter-label" for="reportScope">Scope</label><div class="filter-select-wrap"><select id="reportScope" class="form-select filter-control filter-select" name="scope"><option value="">All Scopes</option><?php foreach ($allowedScopes as $option): ?><option value="<?= hq_escape($option) ?>" <?= $scopeFilter === $option ? 'selected' : '' ?>><?= hq_escape(admin_scope_label($option)) ?></option><?php endforeach; ?></select></div></div><div class="filter-group"><label class="filter-label" for="reportFormat">Format</label><div class="filter-select-wrap"><select id="reportFormat" class="form-select filter-control filter-select" name="format"><option value="">All Formats</option><option value="pdf" <?= $formatFilter === 'pdf' ? 'selected' : '' ?>>Print / PDF</option><option value="csv" <?= $formatFilter === 'csv' ? 'selected' : '' ?>>CSV</option><option value="record" <?= $formatFilter === 'record' ? 'selected' : '' ?>>Database Record</option></select></div></div><div class="filter-actions"><button type="button" class="btn-clear-filters js-clear-filters" title="Clear filters">Clear</button></div></form></div>

        <div class="analytics-card"><div class="table-responsive report-table-scroll"><table class="table table-dark-custom align-middle m-0"><thead><tr><th>Report Details</th><th>Scope</th><th>Date Range</th><th>Generated By</th><th>Format</th><th class="text-end">Actions</th></tr></thead><tbody><?php if (!$reports): ?><tr><td colspan="6" class="text-center text-white-50 py-5">No database reports match the current filters.</td></tr><?php else: ?><?php foreach ($reports as $report): ?><?php $metadata = $report['metadata']; ?><tr><td><div class="d-flex align-items-center gap-3"><div class="rounded-3 d-flex align-items-center justify-content-center text-white" style="width:44px;height:44px;background:rgba(0,244,255,.1);border:1px solid rgba(0,244,255,.25);flex-shrink:0;"><i class="bi bi-file-earmark-bar-graph-fill fs-5 txt-neon-cyan"></i></div><div><div class="fw-semibold text-white"><?= hq_escape((string) $report['short_summery']) ?></div><div class="text-white-50 small">ID: #<?= (int) $report['report_id'] ?></div><div class="text-white-50 small report-message" title="<?= hq_escape((string) $metadata['summary']) ?>"><?= hq_escape((string) $metadata['summary']) ?></div></div></div></td><td><span class="report-scope"><?= hq_escape(admin_scope_label((string) $metadata['scope'])) ?></span></td><td class="text-white-50"><?= hq_escape($metadata['start_date'] && $metadata['end_date'] ? $metadata['start_date'] . ' – ' . $metadata['end_date'] : admin_date((string) $report['created_at'])) ?></td><td><div class="text-white"><?= hq_escape((string) ($report['user_name'] ?: 'System')) ?></div><div class="text-white-50 small"><?= hq_escape((string) ($report['role_name'] ?: 'Admin')) ?></div></td><td class="text-white-50"><?= hq_escape(admin_format_label((string) $metadata['format'])) ?></td><td class="text-end"><div class="d-flex gap-2 justify-content-end"><button type="button" class="btn-action js-view-report" title="View Details" aria-label="View details for report <?= (int) $report['report_id'] ?>" data-report-id="<?= (int) $report['report_id'] ?>"><i class="bi bi-eye-fill"></i></button><a class="btn-action" title="Download" href="report_action.php?action=download&amp;report_id=<?= (int) $report['report_id'] ?>"><i class="bi bi-download"></i></a><form action="report_action.php" method="POST" class="m-0" onsubmit="return confirm('Delete this report archive?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="report_id" value="<?= (int) $report['report_id'] ?>"><button type="submit" class="btn-action btn-action-danger" title="Delete Archive"><i class="bi bi-trash3-fill"></i></button></form></div></td></tr><?php endforeach; ?><?php endif; ?></tbody></table></div></div>
    </main>

    <div class="modal fade glass-modal report-detail-modal" id="reportDetailsModal" tabindex="-1" aria-labelledby="reportDetailsModalLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-xl"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title fw-bold" id="reportDetailsModalLabel"><i class="bi bi-file-earmark-bar-graph-fill txt-neon-cyan me-2"></i>Report Details</h5></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body" id="reportDetailsBody"><div class="text-center text-white-50 py-5"><div class="spinner-border spinner-border-sm txt-neon-cyan mb-2" role="status"></div><div>Loading report details…</div></div></div><div class="modal-footer" id="reportDetailsFooter"><button type="button" class="btn btn-outline-secondary rounded-3 text-white-50 border-secondary border-opacity-20" data-bs-dismiss="modal">Close</button></div></div></div></div>

    
    <footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map &amp; Pump | All Rights Reserved</footer><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script src="/js/Admin.js"></script><script>
    (() => {
        const modalElement = document.getElementById('reportDetailsModal');
        const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
        const body = document.getElementById('reportDetailsBody');
        const title = document.getElementById('reportDetailsModalLabel');
        const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (character) => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[character]));
        const formatDate = (value) => value ? new Date(String(value).replace(' ', 'T')).toLocaleString() : 'Not available';
        const scopeLabel = (scope) => ({hq_performance:'Company Performance',stations:'Stations',requests:'Requests',audit_logs:'Audit Logs',system:'System'}[String(scope || '').toLowerCase()] || 'System');
        const formatLabel = (format) => ({pdf:'Print / PDF',csv:'CSV',record:'Database Record'}[String(format || '').toLowerCase()] || 'Database Record');
        const card = (label, value) => '<div class="report-detail-card"><div class="report-detail-label">' + escapeHtml(label) + '</div><div class="report-detail-value">' + value + '</div></div>';
        const chip = (label, muted) => '<span class="report-detail-chip' + (muted ? ' is-muted' : '') + '">' + escapeHtml(label) + '</span>';

        document.addEventListener('click', async (event) => {
            const button = event.target.closest('.js-view-report');
            if (!button) return;
                title.innerHTML = '<i class="bi bi-file-earmark-bar-graph-fill txt-neon-cyan me-2"></i>Report Details';
                body.innerHTML = '<div class="text-center text-white-50 py-5"><div class="spinner-border spinner-border-sm txt-neon-cyan mb-2" role="status"></div><div>Loading report details…</div></div>';
                modal.show();
                try {
                    const response = await fetch('report_details.php?id=' + encodeURIComponent(button.dataset.reportId), {headers:{Accept:'application/json'},cache:'no-store'});
                    const payload = await response.json();
                    if (!response.ok || !payload.success) throw new Error(payload.message || 'Could not load report details.');
                    const report = payload.report || {};
                    const metadata = payload.metadata || {};
                    const dateRange = metadata.start_date && metadata.end_date ? metadata.start_date + ' – ' + metadata.end_date : formatDate(report.created_at);
                    const summaryText = String(metadata.summary || '').trim();
                    const messageText = String(report.message || '').trim();
                    const showRawMessage = messageText !== '' && messageText !== summaryText;
                    title.innerHTML = '<i class="bi bi-file-earmark-bar-graph-fill txt-neon-cyan me-2"></i>' + escapeHtml(report.title || 'Report Details');
                    body.innerHTML =
                        '<div class="report-detail-hero">' +
                            '<div class="report-detail-hero-main">' +
                                '<div class="report-detail-label mb-1">Report #' + escapeHtml(report.report_id) + '</div>' +
                                '<div class="fw-semibold text-white fs-5 text-break">' + escapeHtml(report.title || 'Untitled report') + '</div>' +
                                '<div class="text-white-50 small mt-2"><i class="bi bi-clock-history me-1"></i>Created ' + escapeHtml(formatDate(report.created_at)) + '</div>' +
                            '</div>' +
                            '<div class="report-detail-hero-meta">' +
                                chip(scopeLabel(metadata.scope)) +
                                chip(formatLabel(metadata.format), true) +
                                chip(report.importance || 'Normal', true) +
                            '</div>' +
                        '</div>' +
                        '<div class="report-detail-grid">' +
                            card('Scope', escapeHtml(scopeLabel(metadata.scope))) +
                            card('Format', escapeHtml(formatLabel(metadata.format))) +
                            card('Importance', escapeHtml(report.importance || 'Normal')) +
                            card('Generated by', escapeHtml(report.author || 'System') + '<div class="text-white-50 small mt-1">' + escapeHtml(report.role_name || 'Admin') + '</div>') +
                            card('Date range', escapeHtml(dateRange)) +
                            card('Report ID', '#' + escapeHtml(report.report_id)) +
                        '</div>' +
                        '<div class="report-detail-section">' +
                            '<div class="report-detail-section-head"><div class="report-detail-label mb-0">Report summary</div></div>' +
                            '<div class="report-detail-content">' + escapeHtml(summaryText || messageText || 'No summary was stored.') + '</div>' +
                        '</div>' +
                        (showRawMessage
                            ? ('<div class="report-detail-section">' +
                                '<div class="report-detail-section-head"><div class="report-detail-label mb-0">Stored database message</div></div>' +
                                '<div class="report-detail-content">' + escapeHtml(messageText) + '</div>' +
                               '</div>')
                            : '');
                    const footer = document.getElementById('reportDetailsFooter');
                    if (footer) {
                        footer.innerHTML = '<a class="btn btn-neon" href="report_action.php?action=download&report_id=' + encodeURIComponent(report.report_id) + '"><i class="bi bi-download me-1"></i>Download</a>' +
                            '<button type="button" class="btn btn-outline-secondary rounded-3 text-white-50 border-secondary border-opacity-20" data-bs-dismiss="modal">Close</button>';
                    }
                } catch (error) {
                    body.innerHTML = '<div class="alert alert-danger border-0"><i class="bi bi-exclamation-triangle me-2"></i>' + escapeHtml(error.message) + '</div>';
                }
        });
    })();
    </script>
</body>
</html>
