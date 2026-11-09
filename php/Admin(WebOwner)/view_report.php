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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="icon" type="image/png" href="/images/Logo.png">
    <link rel="stylesheet" href="/css/Admin.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>.report-scope{display:inline-flex;border-radius:999px;padding:.35rem .7rem;font-size:.72rem;font-weight:700;background:rgba(0,244,255,.08);color:var(--neon-cyan);border:1px solid rgba(0,244,255,.24)}.report-message{max-width:460px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.report-detail-modal .modal-header{border-bottom-color:rgba(0,244,255,.16);background:linear-gradient(120deg,rgba(0,244,255,.09),rgba(37,99,235,.035) 58%,transparent)}.report-detail-modal .modal-footer{border-top-color:rgba(255,255,255,.09);background:rgba(4,13,30,.34)}.report-detail-hero{padding:1rem 1.1rem;border:1px solid rgba(0,244,255,.2);border-radius:15px;background:linear-gradient(135deg,rgba(0,244,255,.1),rgba(27,228,148,.035))}.report-detail-label{color:var(--text-muted);font-size:.7rem;letter-spacing:.08em;text-transform:uppercase}.report-detail-value{color:var(--text-primary);word-break:break-word;white-space:pre-wrap}.report-detail-card{height:100%;padding:.85rem .9rem;border:1px solid rgba(255,255,255,.1);border-radius:12px;background:rgba(255,255,255,.025)}.report-detail-content{padding:1rem;border:1px solid rgba(0,244,255,.18);border-radius:12px;background:rgba(0,244,255,.045);line-height:1.55;white-space:pre-wrap;word-break:break-word}body.background_color.light-mode .report-detail-modal .modal-header{background:linear-gradient(120deg,rgba(0,164,180,.1),rgba(37,99,235,.04) 58%,transparent)}body.background_color.light-mode .report-detail-modal .modal-footer,body.background_color.light-mode .report-detail-card{background:rgba(255,255,255,.62)}
        .report-detail-content{max-height:min(32vh,260px);overflow-y:auto;overscroll-behavior:contain;scrollbar-width:thin;scrollbar-color:rgba(0,244,255,.45) rgba(4,12,28,.9)}
        .report-detail-content::-webkit-scrollbar{width:8px}
        .report-detail-content::-webkit-scrollbar-thumb{background:rgba(0,244,255,.35);border-radius:999px}
        .report-flash{border-radius:14px;background:rgba(10,22,44,.82);border:1px solid var(--glass-border);color:var(--text-primary)}
        body.background_color.light-mode .report-flash{background:rgba(255,255,255,.92)}
        .report-table-scroll{max-height:min(60vh,520px);overflow:auto}
        .report-scope-chip{font-size:.72rem}
    </style>
</head>
<body class="background_color" id="background_color">
<?php $adminSection = 'report'; include __DIR__ . '/admin_section_nav.php'; ?>

    
    

    <main class="container my-5"><div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3"><div><span class="stat-chip mb-2"><i class="bi bi-bar-chart-line-fill txt-neon-cyan"></i> Analytics Center</span><h1 class="fw-bold m-0 h4 text-white mt-2">System Reports &amp; Logs</h1></div></div>
        <?php if ($flash && $flashType !== ''): ?><div class="alert report-flash alert-<?= hq_escape($flashType) ?> alert-dismissible fade show" role="alert"><i class="bi <?= $flashType === 'success' ? 'bi-check-circle-fill text-success' : 'bi-exclamation-triangle-fill text-danger' ?> me-2"></i><?= hq_escape((string) ($flash['message'] ?? '')) ?><button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
        <?php if ($dbError): ?><div class="alert alert-danger border-0" role="alert"><?= hq_escape($dbError) ?></div><?php endif; ?>

        <div class="row g-4 mb-4"><div class="col-12 col-sm-6 col-md-3"><div class="dashboard-card metric-card"><p class="mb-1 metric-label">Total Companies Registered</p><h4 class="fw-bold text-white mb-0"><?= (int) $metrics['hqs'] ?></h4></div></div><div class="col-12 col-sm-6 col-md-3"><div class="dashboard-card metric-card"><p class="mb-1 metric-label">Active Stations</p><h4 class="fw-bold text-white mb-0"><?= (int) $metrics['stations'] ?></h4></div></div><div class="col-12 col-sm-6 col-md-3"><div class="dashboard-card metric-card"><p class="mb-1 metric-label">Pending Requests</p><h4 class="fw-bold text-white mb-0"><?= (int) $metrics['requests'] ?></h4></div></div><div class="col-12 col-sm-6 col-md-3"><div class="dashboard-card metric-card"><p class="mb-1 metric-label">Reports Generated</p><h4 class="fw-bold text-white mb-0"><?= (int) $metrics['reports'] ?></h4><small class="text-success"><?= (int) $metrics['reports_today'] ?> created today</small></div></div></div>

        <div class="d-flex flex-wrap gap-2 mb-4">
            <span class="stat-chip report-scope-chip"><i class="bi bi-building me-1"></i>Companies <?= (int) ($scopeCounts['hq_performance'] ?? 0) ?></span>
            <span class="stat-chip report-scope-chip"><i class="bi bi-fuel-pump me-1"></i>Stations <?= (int) ($scopeCounts['stations'] ?? 0) ?></span>
            <span class="stat-chip report-scope-chip"><i class="bi bi-inbox me-1"></i>Requests <?= (int) ($scopeCounts['requests'] ?? 0) ?></span>
            <span class="stat-chip report-scope-chip"><i class="bi bi-shield-lock me-1"></i>System <?= (int) (($scopeCounts['system'] ?? 0) + ($scopeCounts['audit_logs'] ?? 0)) ?></span>
        </div>

        <div class="dashboard-card py-3 px-4 mb-4"><form class="row g-3 align-items-center" method="GET" action="view_report.php"><div class="col-12 col-md-5"><div class="input-group"><span class="input-group-text bg-transparent border-end-0" style="border-color:var(--glass-border);color:var(--neon-cyan);border-radius:12px 0 0 12px;"><i class="bi bi-search"></i></span><input type="text" class="form-control glass-input border-start-0" placeholder="Search by report name, ID, or author..." name="search" value="<?= hq_escape($search) ?>"></div></div><div class="col-12 col-md-3"><select class="form-select glass-select" name="scope"><option value="">All Scopes</option><?php foreach ($allowedScopes as $option): ?><option value="<?= hq_escape($option) ?>" <?= $scopeFilter === $option ? 'selected' : '' ?>><?= hq_escape(admin_scope_label($option)) ?></option><?php endforeach; ?></select></div><div class="col-12 col-md-2"><select class="form-select glass-select" name="format"><option value="">All Formats</option><option value="pdf" <?= $formatFilter === 'pdf' ? 'selected' : '' ?>>Print / PDF</option><option value="csv" <?= $formatFilter === 'csv' ? 'selected' : '' ?>>CSV</option><option value="record" <?= $formatFilter === 'record' ? 'selected' : '' ?>>Database Record</option></select></div><div class="col-12 col-md-2 d-flex"><button type="button" class="btn btn-neon flex-grow-1 js-clear-filters" title="Clear filters">Clear</button></div></form></div>

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
        const card = (label, value) => '<div class="report-detail-card"><div class="report-detail-label mb-1">' + escapeHtml(label) + '</div><div class="report-detail-value">' + value + '</div></div>';

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
                    title.innerHTML = '<i class="bi bi-file-earmark-bar-graph-fill txt-neon-cyan me-2"></i>' + escapeHtml(report.title || 'Report Details');
                    body.innerHTML = '<div class="report-detail-hero mb-4"><div class="report-detail-label mb-1">Database report · #' + escapeHtml(report.report_id) + '</div><div class="fw-bold text-white fs-5 text-break">' + escapeHtml(report.title || 'Untitled report') + '</div><div class="text-white-50 small mt-1">Created ' + escapeHtml(formatDate(report.created_at)) + '</div></div>' +
                        '<div class="row g-3 mb-4">' +
                        '<div class="col-12 col-md-4">' + card('Scope', escapeHtml(scopeLabel(metadata.scope))) + '</div>' +
                        '<div class="col-12 col-md-4">' + card('Format', escapeHtml(formatLabel(metadata.format))) + '</div>' +
                        '<div class="col-12 col-md-4">' + card('Importance', escapeHtml(report.importance || 'Normal')) + '</div>' +
                        '<div class="col-12 col-md-6">' + card('Generated by', escapeHtml(report.author || 'System') + '<div class="text-white-50 small mt-1">' + escapeHtml(report.role_name || 'Admin') + '</div>') + '</div>' +
                        '<div class="col-12 col-md-6">' + card('Date range', escapeHtml(dateRange)) + '</div>' +
                        '</div>' +
                        '<div class="report-detail-label mb-2">Report summary</div><div class="report-detail-content mb-4">' + escapeHtml(metadata.summary || report.message || 'No summary was stored.') + '</div>' +
                        '<div class="report-detail-label mb-2">Stored database message</div><div class="report-detail-content">' + escapeHtml(report.message || 'No message was stored.') + '</div>';
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
