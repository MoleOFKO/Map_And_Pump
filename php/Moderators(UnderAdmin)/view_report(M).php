<?php
require_once __DIR__ . '/moderator_common.php';

$moderatorUser = moderator_require_user();
$flash = admin_pull_flash();
$search = trim((string) ($_GET['search'] ?? ''));
$scopeFilter = strtolower(trim((string) ($_GET['scope'] ?? '')));
$formatFilter = strtolower(trim((string) ($_GET['format'] ?? '')));
// Core scopes + any seen in live report metadata after load.
$allowedScopes = ['hq_performance', 'stations', 'requests', 'system', 'audit_logs'];
$allowedFormats = ['pdf', 'csv', 'record'];
$scopeFilter = in_array($scopeFilter, $allowedScopes, true) ? $scopeFilter : '';
$formatFilter = in_array($formatFilter, $allowedFormats, true) ? $formatFilter : '';
$metrics = ['hqs' => 0, 'stations' => 0, 'requests' => 0, 'reports' => 0, 'reports_today' => 0];
$reports = [];
$dbError = null;

if (hq_db_available()) {
    foreach (['hqs' => 'SELECT COUNT(*) AS value FROM headquarter', 'stations' => 'SELECT COUNT(*) AS value FROM branches', 'requests' => 'SELECT COUNT(*) AS value FROM system_requests', 'reports' => 'SELECT COUNT(*) AS value FROM report', 'reports_today' => 'SELECT COUNT(*) AS value FROM report WHERE DATE(created_at)=CURDATE()'] as $key => $sql) {
        $query = $conn->query($sql);
        if ($query) {
            $metrics[$key] = (int) (($query->fetch_assoc()['value'] ?? 0));
        }
    }
    // Account verifications live in users and are part of the same review queue.
    $metrics['requests'] += pending_account_count();
    $query = $conn->query(
        'SELECT p.report_id, p.user_id, p.short_summery, p.message, p.importance, p.created_at, p.role_id,
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
// Surface any scopes present in the filtered dataset.
foreach ($reports as $reportRow) {
    $scopeSeen = (string) (($reportRow['metadata']['scope'] ?? '') ?: '');
    if ($scopeSeen !== '' && !in_array($scopeSeen, $allowedScopes, true)) {
        $allowedScopes[] = $scopeSeen;
    }
}
$flashType = (string) ($flash['type'] ?? '');

?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>View Reports — Map &amp; Pump</title><link rel="icon" type="image/png" href="/images/Logo.png"><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.googleapis.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&amp;display=swap" rel="stylesheet"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"><link rel="stylesheet" href="/css/Admin.css"><link rel="stylesheet" href="/css/moderator_blue_theme.css"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"><style>
.report-scope{display:inline-flex;border-radius:999px;padding:.35rem .7rem;font-size:.72rem;font-weight:700;background:rgba(0,244,255,.08);color:var(--neon-cyan);border:1px solid rgba(0,244,255,.24)}
.report-message{max-width:430px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.report-table-scroll{max-height:min(60vh,520px);overflow:auto}
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
@media(max-width:991.98px){.report-detail-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:575.98px){.report-detail-grid{grid-template-columns:minmax(0,1fr)}.report-detail-hero{padding:1rem}.report-detail-hero-meta{justify-content:flex-start}.report-detail-card{min-height:0}}
body.background_color.light-mode .report-detail-modal .modal-content{background:#fff;box-shadow:0 18px 48px rgba(23,43,64,.14)}
body.background_color.light-mode .report-detail-modal .modal-header{background:linear-gradient(120deg,rgba(0,164,180,.1),rgba(37,99,235,.04) 58%,transparent)}
body.background_color.light-mode .report-detail-modal .modal-footer,
body.background_color.light-mode .report-detail-card{background:rgba(255,255,255,.9)}
body.background_color.light-mode .report-detail-hero{background:linear-gradient(135deg,rgba(40,94,199,.08),rgba(255,255,255,.7))}
body.background_color.light-mode .report-detail-content{background:rgba(40,94,199,.04);border-color:rgba(40,94,199,.14)}
.mod-flash{border-radius:14px;background:rgba(10,22,44,.82);border:1px solid var(--glass-border);color:var(--text-primary)}
body.background_color.light-mode .mod-flash{background:rgba(255,255,255,.92)}
</style></head>
<body class="background_color admin-blue-page" id="background_color"><?php include __DIR__ . '/includes/moderator_navbar.php'; ?><main class="container my-5 admin-blue-shell">
<?php if ($flash && $flashType): ?><div class="alert mod-flash alert-<?= moderator_escape($flashType) ?> mb-4"><i class="bi <?= $flashType === 'success' ? 'bi-check-circle-fill text-success' : 'bi-exclamation-triangle-fill text-danger' ?> me-2"></i><?= moderator_escape((string)($flash['message']??'')) ?></div><?php endif; ?><?php if ($dbError): ?><div class="alert mod-flash alert-danger mb-4"><i class="bi bi-database-exclamation me-2"></i><?= moderator_escape($dbError) ?></div><?php endif; ?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3"><div class="admin-blue-header flex-grow-1"><div class="companies-eyebrow">Analytics center</div><h1 class="mb-0">System Reports &amp; Logs</h1><p class="admin-blue-description">Review archived reports and generate new operational summaries.</p></div><button class="btn btn-neon d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#generateReportModal"><i class="bi bi-file-earmark-plus-fill"></i> Generate Report</button></div>
<div class="row g-4 mb-4"><div class="col-12 col-sm-6 col-md-3"><div class="dashboard-card p-3"><p class="text-white-50 mb-1 small uppercase">Total Companies Registered</p><h4 class="fw-bold text-white mb-0"><?= $metrics['hqs'] ?></h4></div></div><div class="col-12 col-sm-6 col-md-3"><div class="dashboard-card p-3"><p class="text-white-50 mb-1 small uppercase">Stations (<code>branches</code>)</p><h4 class="fw-bold text-white mb-0"><?= $metrics['stations'] ?></h4></div></div><div class="col-12 col-sm-6 col-md-3"><div class="dashboard-card p-3"><p class="text-white-50 mb-1 small uppercase">Requests in Queue</p><h4 class="fw-bold text-white mb-0"><?= $metrics['requests'] ?></h4></div></div><div class="col-12 col-sm-6 col-md-3"><div class="dashboard-card p-3"><p class="text-white-50 mb-1 small uppercase">Reports Generated</p><h4 class="fw-bold text-white mb-0"><?= $metrics['reports'] ?></h4><small class="text-success"><?= $metrics['reports_today'] ?> created today</small></div></div></div>
<div class="dashboard-card py-3 px-4 mb-4"><form class="row g-3 align-items-center" method="GET" action="view_report(M).php"><div class="col-12 col-md-4"><div class="input-group"><span class="input-group-text bg-transparent border-end-0" style="border-color:var(--glass-border);color:var(--neon-cyan)"><i class="bi bi-search"></i></span><input type="search" class="form-control glass-input border-start-0" placeholder="Search by report name, ID, or author..." name="search" value="<?= moderator_escape($search) ?>"></div></div><div class="col-12 col-md-3"><select class="form-select glass-select" name="scope"><option value="">All Scopes</option><?php foreach ($allowedScopes as $option): ?><option value="<?= moderator_escape($option) ?>" <?= $scopeFilter === $option ? 'selected' : '' ?>><?= moderator_escape(admin_scope_label($option)) ?></option><?php endforeach; ?></select></div><div class="col-12 col-md-2"><select class="form-select glass-select" name="format"><option value="">All Formats</option><?php foreach ($allowedFormats as $option): ?><option value="<?= moderator_escape($option) ?>" <?= $formatFilter === $option ? 'selected' : '' ?>><?= moderator_escape(admin_format_label($option)) ?></option><?php endforeach; ?></select></div><div class="col-12 col-md-3 d-flex gap-2"><button type="button" class="btn btn-neon flex-grow-1 js-clear-filters" title="Clear filters">Clear</button></div></form></div>
<div class="analytics-card"><div class="table-responsive report-table-scroll"><table class="table table-dark-custom align-middle m-0"><thead><tr><th>Report Details</th><th>Scope</th><th>Date Range</th><th>Generated By</th><th>Format</th><th class="text-end">Actions</th></tr></thead><tbody><?php if (!$reports): ?><tr><td colspan="6" class="text-center text-white-50 py-5"><i class="bi bi-file-earmark-x fs-3 d-block mb-2"></i>No database reports match the current filters.</td></tr><?php else: ?><?php foreach ($reports as $report): ?><?php $meta=$report['metadata']; ?><tr><td><div class="fw-semibold text-white"><?= moderator_escape((string)$report['short_summery']) ?></div><div class="text-white-50 small">ID: #<?= (int)$report['report_id'] ?></div><div class="text-white-50 small report-message" title="<?= moderator_escape((string)$meta['summary']) ?>"><?= moderator_escape((string)$meta['summary']) ?></div></td><td><span class="report-scope"><?= moderator_escape(admin_scope_label((string)$meta['scope'])) ?></span></td><td class="text-white-50"><?= moderator_escape($meta['start_date']&&$meta['end_date']?$meta['start_date'].' – '.$meta['end_date']:moderator_format_date((string)$report['created_at'])) ?></td><td><div class="text-white"><?= moderator_escape((string)($report['user_name']?:'System')) ?></div><div class="text-white-50 small"><?= moderator_escape((string)($report['role_name']?:'Moderator')) ?></div></td><td class="text-white-50"><?= moderator_escape(admin_format_label((string)$meta['format'])) ?></td><td class="text-end"><div class="d-flex gap-2 justify-content-end"><button type="button" class="btn-action js-view-report" title="View Details" aria-label="View details for report <?= (int)$report['report_id'] ?>" data-report-id="<?= (int)$report['report_id'] ?>"><i class="bi bi-eye-fill"></i></button><a href="report_action(M).php?action=download&amp;report_id=<?= (int)$report['report_id'] ?>" class="btn-action" title="Download Report"><i class="bi bi-cloud-arrow-down-fill"></i></a><?php if ((int) $report['user_id'] === (int) $moderatorUser['user_id']): ?><form action="report_action(M).php" method="POST" class="m-0" onsubmit="return confirm('Delete this report archive?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="report_id" value="<?= (int)$report['report_id'] ?>"><button type="submit" class="btn-action btn-action-danger" title="Delete Your Report"><i class="bi bi-trash3-fill"></i></button></form><?php endif; ?></div></td></tr><?php endforeach; ?><?php endif; ?></tbody></table></div></div>
</main>
<div class="modal fade glass-modal report-detail-modal" id="reportDetailsModal" tabindex="-1" aria-labelledby="reportDetailsModalLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-xl"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title fw-bold" id="reportDetailsModalLabel"><i class="bi bi-file-earmark-bar-graph-fill txt-neon-cyan me-2"></i>Report Details</h5></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body" id="reportDetailsBody"><div class="text-center text-white-50 py-5"><div class="spinner-border spinner-border-sm txt-neon-cyan mb-2" role="status"></div><div>Loading report details…</div></div></div><div class="modal-footer" id="reportDetailsFooter"><button type="button" class="btn btn-outline-secondary rounded-3 text-white-50 border-secondary border-opacity-20" data-bs-dismiss="modal">Close</button></div></div></div></div>
<div class="modal fade glass-modal" id="generateReportModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title fw-bold"><i class="bi bi-bar-chart-steps txt-neon-cyan me-2"></i> Configure Custom Report</h5></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><form action="report_action(M).php" method="POST"><input type="hidden" name="action" value="generate"><div class="modal-body d-flex flex-column gap-3"><div><label class="form-label text-white-50 small" for="reportTitle">Report Title <span class="text-danger">*</span></label><input id="reportTitle" type="text" name="report_title" class="form-control glass-input" maxlength="255" required></div><div><label class="form-label text-white-50 small" for="reportScope">Target Data Scope <span class="text-danger">*</span></label><select id="reportScope" class="form-select glass-select" name="scope" required><option value="hq_performance">Company Performance Summary</option><option value="stations">Physical Pump Station Audit</option><option value="requests">System Approvals &amp; Rejections</option></select></div><div class="row g-3"><div class="col-12 col-sm-6"><label class="form-label text-white-50 small" for="startDate">Start Date <span class="text-danger">*</span></label><input id="startDate" type="date" name="start_date" class="form-control glass-input" required></div><div class="col-12 col-sm-6"><label class="form-label text-white-50 small" for="endDate">End Date <span class="text-danger">*</span></label><input id="endDate" type="date" name="end_date" class="form-control glass-input" required></div></div><div><label class="form-label text-white-50 small d-block">Export Format <span class="text-danger">*</span></label><div class="d-flex gap-4"><label class="text-white"><input type="radio" name="format" value="pdf" checked> Print / PDF</label><label class="text-white"><input type="radio" name="format" value="csv"> CSV</label></div></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-3 text-white-50" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-neon">Compile Data</button></div></form></div></div></div>
<?php $moderatorActiveNav = 'report'; include __DIR__ . '/includes/moderator_offcanvas.php'; ?><footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map &amp; Pump | All Rights Reserved</footer><?php include __DIR__ . '/includes/moderator_footer_scripts.php'; ?>
<script>
(() => {
    const modalElement = document.getElementById('reportDetailsModal');
    const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
    const body = document.getElementById('reportDetailsBody');
    const title = document.getElementById('reportDetailsModalLabel');
    const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[character]));
    const formatDate = value => value ? new Date(String(value).replace(' ', 'T')).toLocaleString() : 'Not available';
    const scopeLabel = scope => ({hq_performance:'Company Performance',stations:'Stations',requests:'Requests',audit_logs:'Audit Logs',system:'System'}[String(scope || '').toLowerCase()] || 'System');
    const formatLabel = format => ({pdf:'Print / PDF',csv:'CSV',record:'Database Record'}[String(format || '').toLowerCase()] || 'Database Record');
    const card = (label, value) => '<div class="report-detail-card"><div class="report-detail-label">' + escapeHtml(label) + '</div><div class="report-detail-value">' + value + '</div></div>';
    const chip = (label, muted) => '<span class="report-detail-chip' + (muted ? ' is-muted' : '') + '">' + escapeHtml(label) + '</span>';

    document.addEventListener('click', async event => {
        const button = event.target.closest('.js-view-report');
        if (!button) return;

        title.innerHTML = '<i class="bi bi-file-earmark-bar-graph-fill txt-neon-cyan me-2"></i>Report Details';
        body.innerHTML = '<div class="text-center text-white-50 py-5"><div class="spinner-border spinner-border-sm txt-neon-cyan mb-2" role="status"></div><div>Loading report details…</div></div>';
        modal.show();
        try {
            const response = await fetch('../Admin(WebOwner)/report_details.php?id=' + encodeURIComponent(button.dataset.reportId), {headers:{Accept:'application/json'},cache:'no-store'});
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
                    card('Generated by', escapeHtml(report.author || 'System') + '<div class="text-white-50 small mt-1">' + escapeHtml(report.role_name || 'Moderator') + '</div>') +
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
            document.getElementById('reportDetailsFooter').innerHTML = '<a class="btn btn-neon" href="report_action(M).php?action=download&report_id=' + encodeURIComponent(report.report_id) + '"><i class="bi bi-download me-1"></i>Download</a>' +
                '<button type="button" class="btn btn-outline-secondary rounded-3 text-white-50 border-secondary border-opacity-20" data-bs-dismiss="modal">Close</button>';
        } catch (error) {
            body.innerHTML = '<div class="alert alert-danger border-0"><i class="bi bi-exclamation-triangle me-2"></i>' + escapeHtml(error.message) + '</div>';
        }
    });
})();
</script>
</body></html>
