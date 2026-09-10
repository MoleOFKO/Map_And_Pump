<?php
require_once __DIR__ . '/moderator_common.php';

$moderatorUser = moderator_require_user();
$flash = admin_pull_flash();
$search = trim((string) ($_GET['search'] ?? ''));
$scopeFilter = strtolower(trim((string) ($_GET['scope'] ?? '')));
$formatFilter = strtolower(trim((string) ($_GET['format'] ?? '')));
$allowedScopes = ['hq_performance', 'stations', 'requests', 'system'];
$allowedFormats = ['pdf', 'csv', 'record'];
$scopeFilter = in_array($scopeFilter, $allowedScopes, true) ? $scopeFilter : '';
$formatFilter = in_array($formatFilter, $allowedFormats, true) ? $formatFilter : '';
$metrics = ['hqs' => 0, 'stations' => 0, 'requests' => 0, 'reports' => 0, 'reports_today' => 0];
$reports = [];
$dbError = null;

if (hq_db_available()) {
    foreach (['hqs' => 'SELECT COUNT(*) AS value FROM headquarter', 'stations' => 'SELECT COUNT(*) AS value FROM stations', 'requests' => 'SELECT COUNT(*) AS value FROM system_requests', 'reports' => 'SELECT COUNT(*) AS value FROM report', 'reports_today' => 'SELECT COUNT(*) AS value FROM report WHERE DATE(created_at)=CURDATE()'] as $key => $sql) {
        $query = $conn->query($sql);
        if ($query) {
            $metrics[$key] = (int) (($query->fetch_assoc()['value'] ?? 0));
        }
    }
    $query = $conn->query(
        'SELECT p.report_id, p.short_summery, p.message, p.importance, p.created_at, p.role_id,
                u.user_name, r.role_name
         FROM report p
         LEFT JOIN users u ON u.user_id = p.user_id
         LEFT JOIN roles r ON r.role_id = p.role_id
         ORDER BY p.created_at DESC, p.report_id DESC'
    );
    while ($query && ($row = $query->fetch_assoc())) {
        $metadata = admin_report_metadata((string) $row['message'], [
            'short_summery' => (string) ($row['short_summery'] ?? ''),
            'role_id' => $row['role_id'] ?? null,
            'role_name' => (string) ($row['role_name'] ?? ''),
        ]);
        $metadata['scope'] = admin_normalize_report_scope((string) $metadata['scope']);
        $haystack = strtolower(implode(' ', [(string) $row['report_id'], (string) $row['short_summery'], (string) $row['user_name']]));
        if ($search !== '' && !str_contains($haystack, strtolower($search))) {
            continue;
        }
        if ($scopeFilter !== '' && $metadata['scope'] !== $scopeFilter) {
            continue;
        }
        if ($formatFilter !== '' && $metadata['format'] !== $formatFilter) {
            continue;
        }
        $row['metadata'] = $metadata;
        $reports[] = $row;
    }
} else {
    $dbError = hq_db_error_message();
}
$flashType = (string) ($flash['type'] ?? '');
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>View Reports — Map &amp; Pump</title><link rel="icon" type="image/png" href="/images/Logo.png"><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.googleapis.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&amp;display=swap" rel="stylesheet"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"><link rel="stylesheet" href="/css/Admin.css"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"><style>.report-scope{display:inline-flex;border-radius:999px;padding:.35rem .7rem;font-size:.72rem;font-weight:700;background:rgba(0,244,255,.08);color:var(--neon-cyan);border:1px solid rgba(0,244,255,.24)}.report-message{max-width:430px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.mod-flash{border-radius:14px;background:rgba(10,22,44,.82);border:1px solid var(--glass-border)}</style></head>
<body class="background_color" id="background_color"><?php include __DIR__ . '/includes/moderator_navbar.php'; ?><main class="container my-5">
<?php if ($flash && $flashType): ?><div class="alert mod-flash alert-<?= moderator_escape($flashType) ?> mb-4"><i class="bi <?= $flashType === 'success' ? 'bi-check-circle-fill text-success' : 'bi-exclamation-triangle-fill text-danger' ?> me-2"></i><?= moderator_escape((string)($flash['message']??'')) ?></div><?php endif; ?><?php if ($dbError): ?><div class="alert mod-flash alert-danger mb-4"><i class="bi bi-database-exclamation me-2"></i><?= moderator_escape($dbError) ?></div><?php endif; ?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3"><div><span class="stat-chip mb-2"><i class="bi bi-bar-chart-line-fill txt-neon-cyan"></i> Analytics Center</span><h1 class="fw-bold m-0 h4 text-white mt-2">System Reports &amp; Logs</h1><p class="text-white-50 m-0 small mt-1">Review live report records and generate new database summaries.</p></div><button class="btn btn-neon d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#generateReportModal"><i class="bi bi-file-earmark-plus-fill"></i> Generate Report</button></div>
<div class="row g-4 mb-4"><div class="col-12 col-sm-6 col-md-3"><div class="dashboard-card p-3"><p class="text-white-50 mb-1 small uppercase">Total HQs Registered</p><h4 class="fw-bold text-white mb-0"><?= $metrics['hqs'] ?></h4></div></div><div class="col-12 col-sm-6 col-md-3"><div class="dashboard-card p-3"><p class="text-white-50 mb-1 small uppercase">Active Stations</p><h4 class="fw-bold text-white mb-0"><?= $metrics['stations'] ?></h4></div></div><div class="col-12 col-sm-6 col-md-3"><div class="dashboard-card p-3"><p class="text-white-50 mb-1 small uppercase">Requests Stored</p><h4 class="fw-bold text-white mb-0"><?= $metrics['requests'] ?></h4></div></div><div class="col-12 col-sm-6 col-md-3"><div class="dashboard-card p-3"><p class="text-white-50 mb-1 small uppercase">Reports Generated</p><h4 class="fw-bold text-white mb-0"><?= $metrics['reports'] ?></h4><small class="text-success"><?= $metrics['reports_today'] ?> created today</small></div></div></div>
<div class="dashboard-card py-3 px-4 mb-4"><form class="row g-3 align-items-center" method="GET" action="view_report(M).php"><div class="col-12 col-md-5"><div class="input-group"><span class="input-group-text bg-transparent border-end-0" style="border-color:var(--glass-border);color:var(--neon-cyan)"><i class="bi bi-search"></i></span><input type="search" class="form-control glass-input border-start-0" placeholder="Search by report name, ID, or author..." name="search" value="<?= moderator_escape($search) ?>"></div></div><div class="col-12 col-md-3"><select class="form-select glass-select" name="scope"><option value="">All Scopes</option><?php foreach ($allowedScopes as $option): ?><option value="<?= $option ?>" <?= $scopeFilter === $option ? 'selected' : '' ?>><?= moderator_escape(admin_scope_label($option)) ?></option><?php endforeach; ?></select></div><div class="col-12 col-md-2"><select class="form-select glass-select" name="format"><option value="">All Formats</option><?php foreach ($allowedFormats as $option): ?><option value="<?= $option ?>" <?= $formatFilter === $option ? 'selected' : '' ?>><?= moderator_escape(admin_format_label($option)) ?></option><?php endforeach; ?></select></div><div class="col-12 col-md-2 d-grid"><button type="submit" class="btn btn-neon">Apply Filter</button></div></form></div>
<div class="analytics-card"><div class="table-responsive"><table class="table table-dark-custom align-middle m-0"><thead><tr><th>Report Details</th><th>Scope</th><th>Date Range</th><th>Generated By</th><th>Format</th><th class="text-end">Actions</th></tr></thead><tbody><?php if (!$reports): ?><tr><td colspan="6" class="text-center text-white-50 py-5"><i class="bi bi-file-earmark-x fs-3 d-block mb-2"></i>No database reports match the current filters.</td></tr><?php else: ?><?php foreach ($reports as $report): ?><?php $meta=$report['metadata']; ?><tr><td><div class="fw-semibold text-white"><?= moderator_escape((string)$report['short_summery']) ?></div><div class="text-white-50 small">ID: #<?= (int)$report['report_id'] ?></div><div class="text-white-50 small report-message" title="<?= moderator_escape((string)$meta['summary']) ?>"><?= moderator_escape((string)$meta['summary']) ?></div></td><td><span class="report-scope"><?= moderator_escape(admin_scope_label((string)$meta['scope'])) ?></span></td><td class="text-white-50"><?= moderator_escape($meta['start_date']&&$meta['end_date']?$meta['start_date'].' – '.$meta['end_date']:moderator_format_date((string)$report['created_at'])) ?></td><td><div class="text-white"><?= moderator_escape((string)($report['user_name']?:'System')) ?></div><div class="text-white-50 small"><?= moderator_escape((string)($report['role_name']?:'Moderator')) ?></div></td><td class="text-white-50"><?= moderator_escape(admin_format_label((string)$meta['format'])) ?></td><td class="text-end"><div class="d-flex gap-2 justify-content-end"><a href="report_action(M).php?action=download&amp;report_id=<?= (int)$report['report_id'] ?>" class="btn-action" title="Download Report"><i class="bi bi-cloud-arrow-down-fill"></i></a><form action="report_action(M).php" method="POST" class="m-0" onsubmit="return confirm('Delete this report archive?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="report_id" value="<?= (int)$report['report_id'] ?>"><button type="submit" class="btn-action btn-action-danger" title="Delete Archive"><i class="bi bi-trash3-fill"></i></button></form></div></td></tr><?php endforeach; ?><?php endif; ?></tbody></table></div></div>
</main>
<div class="modal fade glass-modal" id="generateReportModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title fw-bold"><i class="bi bi-bar-chart-steps txt-neon-cyan me-2"></i> Configure Custom Report</h5><p class="text-white-50 small mb-0 mt-1">The summary is generated from current database counts.</p></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><form action="report_action(M).php" method="POST"><input type="hidden" name="action" value="generate"><div class="modal-body d-flex flex-column gap-3"><div><label class="form-label text-white-50 small" for="reportTitle">Report Title <span class="text-danger">*</span></label><input id="reportTitle" type="text" name="report_title" class="form-control glass-input" maxlength="255" required></div><div><label class="form-label text-white-50 small" for="reportScope">Target Data Scope <span class="text-danger">*</span></label><select id="reportScope" class="form-select glass-select" name="scope" required><option value="hq_performance">HQ Performance Summary</option><option value="stations">Physical Pump Station Audit</option><option value="requests">System Approvals &amp; Rejections</option><option value="system">System Security &amp; Audit</option></select></div><div class="row g-3"><div class="col-12 col-sm-6"><label class="form-label text-white-50 small" for="startDate">Start Date <span class="text-danger">*</span></label><input id="startDate" type="date" name="start_date" class="form-control glass-input" required></div><div class="col-12 col-sm-6"><label class="form-label text-white-50 small" for="endDate">End Date <span class="text-danger">*</span></label><input id="endDate" type="date" name="end_date" class="form-control glass-input" required></div></div><div><label class="form-label text-white-50 small d-block">Export Format <span class="text-danger">*</span></label><div class="d-flex gap-4"><label class="text-white"><input type="radio" name="format" value="pdf" checked> Print / PDF</label><label class="text-white"><input type="radio" name="format" value="csv"> CSV</label></div></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-3 text-white-50" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-neon">Compile Data</button></div></form></div></div></div>
<?php $moderatorActiveNav = 'report'; include __DIR__ . '/includes/moderator_offcanvas.php'; ?><footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map &amp; Pump | All Rights Reserved</footer><?php include __DIR__ . '/includes/moderator_footer_scripts.php'; ?>
</body></html>
