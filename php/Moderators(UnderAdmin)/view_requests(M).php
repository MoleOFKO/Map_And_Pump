<?php
require_once __DIR__ . '/moderator_common.php';

$moderatorUser = moderator_require_user();
$flash = request_pull_flash();
$search = trim((string) ($_GET['search'] ?? ''));
$requestType = strtolower(trim((string) ($_GET['type'] ?? '')));
$status = strtolower(trim((string) ($_GET['status'] ?? '')));
$allowedTypes = ['account', 'station', 'license', 'deactivation'];
$allowedStatuses = ['pending', 'approved', 'rejected', 'flagged'];
$requestType = in_array($requestType, $allowedTypes, true) ? $requestType : '';
$status = in_array($status, $allowedStatuses, true) ? $status : '';

$requestRows = ($requestType === 'account') ? [] : moderator_request_rows($search, $requestType, $status);
$accountRows = [];
if (($requestType === '' || $requestType === 'account') && ($status === '' || $status === 'pending')) {
    $accountRows = pending_account_rows($search);
}

$summary = moderator_request_summary();
$summary['pending'] += count($accountRows);
$dbError = hq_db_available() ? null : 'The request database is temporarily unavailable.';
$flashType = (string) ($flash['type'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Requests — Map &amp; Pump</title>
    <link rel="icon" type="image/png" href="/images/Logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&amp;display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="/css/Admin.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .request-status{display:inline-flex;align-items:center;gap:.45rem;font-size:.8rem}
        .request-status:before{content:'';width:8px;height:8px;border-radius:50%;display:inline-block}
        .request-status-pending:before{background:#ffc107;box-shadow:0 0 8px #ffc107}
        .request-status-approved:before{background:#1be494;box-shadow:0 0 8px #1be494}
        .request-status-rejected:before,.request-status-flagged:before{background:#ff4d6a;box-shadow:0 0 8px #ff4d6a}
        .request-detail-label{color:var(--text-muted);font-size:.72rem;text-transform:uppercase;letter-spacing:.07em}
        .request-detail-value{color:var(--text-primary);word-break:break-word}
        .mod-flash{border-radius:14px;background:rgba(10,22,44,.82);border:1px solid var(--glass-border)}
    </style>
</head>
<body class="background_color" id="background_color">
<?php include __DIR__ . '/includes/moderator_navbar.php'; ?>
<main class="container my-5">
    <?php if ($flash && $flashType): ?>
        <div class="alert mod-flash alert-<?= moderator_escape($flashType) ?> mb-4">
            <i class="bi <?= $flashType === 'success' ? 'bi-check-circle-fill text-success' : 'bi-exclamation-triangle-fill text-danger' ?> me-2"></i><?= moderator_escape((string) ($flash['message'] ?? '')) ?>
        </div>
    <?php endif; ?>
    <?php if ($dbError): ?><div class="alert mod-flash alert-danger mb-4"><i class="bi bi-database-exclamation me-2"></i><?= moderator_escape($dbError) ?></div><?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <span class="stat-chip mb-2"><i class="bi bi-clipboard-check-fill txt-neon-cyan"></i> Review Pipeline</span>
            <h1 class="fw-bold m-0 h4 text-white mt-2">Inbound System Requests</h1>
            <p class="text-white-50 m-0 small mt-1">Review pending account verification and operational requests.</p>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-4"><div class="request-indicator-card"><div><p class="text-white-50 mb-0 small uppercase">Pending Verification</p><h4 class="fw-bold text-warning mb-0"><?= (int) $summary['pending'] ?></h4></div><span class="fs-4 text-warning"><i class="bi bi-hourglass-split"></i></span></div></div>
        <div class="col-12 col-sm-4"><div class="request-indicator-card"><div><p class="text-white-50 mb-0 small uppercase">Approved Today</p><h4 class="fw-bold text-success mb-0"><?= (int) $summary['approved_today'] ?></h4></div><span class="fs-4 text-success"><i class="bi bi-patch-check-fill"></i></span></div></div>
        <div class="col-12 col-sm-4"><div class="request-indicator-card"><div><p class="text-white-50 mb-0 small uppercase">Declined / Flagged</p><h4 class="fw-bold text-danger mb-0"><?= (int) $summary['declined_flagged'] ?></h4></div><span class="fs-4 text-danger"><i class="bi bi-shield-x"></i></span></div></div>
    </div>

    <div class="dashboard-card py-3 px-4 mb-4">
        <form class="row g-3 align-items-center" method="GET" action="view_requests(M).php">
            <div class="col-12 col-md-5"><div class="input-group"><span class="input-group-text bg-transparent border-end-0" style="border-color:var(--glass-border);color:var(--neon-cyan)"><i class="bi bi-funnel-fill"></i></span><input type="text" class="form-control glass-input border-start-0" placeholder="Filter by company, sender name, email, or reference ID..." name="search" value="<?= moderator_escape($search) ?>"></div></div>
            <div class="col-12 col-md-3"><select class="form-select glass-select" name="type"><option value="">All Request Types</option><?php foreach ($allowedTypes as $option): ?><option value="<?= $option ?>" <?= $requestType === $option ? 'selected' : '' ?>><?= $option === 'account' ? 'Account Verification' : moderator_escape(request_type_label($option)) ?></option><?php endforeach; ?></select></div>
            <div class="col-12 col-md-2"><select class="form-select glass-select" name="status"><option value="">All Statuses</option><?php foreach ($allowedStatuses as $option): ?><option value="<?= $option ?>" <?= $status === $option ? 'selected' : '' ?>><?= moderator_escape(request_status_label($option)) ?></option><?php endforeach; ?></select></div>
            <div class="col-12 col-md-2 d-grid"><button type="submit" class="btn btn-neon">Apply Filter</button></div>
        </form>
    </div>

    <div class="analytics-card"><div class="table-responsive"><table class="table table-dark-custom align-middle m-0"><thead><tr><th>Ref ID</th><th>Sender Entity</th><th>Type of Request</th><th>Submission Date</th><th>Status</th><th class="text-end">Command</th></tr></thead><tbody>
        <?php if (!$accountRows && !$requestRows): ?>
            <tr><td colspan="6" class="text-center text-white-50 py-5"><i class="bi bi-inbox fs-3 d-block mb-2"></i>No requests match the current filter.</td></tr>
        <?php endif; ?>
        <?php foreach ($accountRows as $row): ?>
            <?php
            $roleName = (string) ($row['role_name'] ?? 'User');
            $isHq = in_array(strtolower(trim($roleName)), ['hq', 'headquarter', 'headquarters'], true);
            $sender = $isHq ? (string) ($row['headquarter_name'] ?: $row['user_name']) : (string) ($row['user_name'] ?: 'Unknown user');
            ?>
            <tr>
                <td class="fw-bold txt-neon-cyan">#ACCT-<?= (int) $row['user_id'] ?></td>
                <td><div class="fw-semibold text-white"><?= moderator_escape($sender) ?></div><div class="text-white-50 small"><?= moderator_escape((string) ($row['email'] ?? 'No email')) ?></div></td>
                <td><span class="badge rounded-pill bg-info bg-opacity-10 text-info border border-info border-opacity-25 px-2 py-1">Account Verification</span></td>
                <td class="text-white-50"><?= moderator_escape(moderator_format_date((string) $row['created_at'])) ?></td>
                <td><span class="request-status request-status-pending">Pending Verification</span></td>
                <td class="text-end"><button type="button" class="btn-action js-view-account-request" title="View details" data-user-id="<?= (int) $row['user_id'] ?>" data-role-name="<?= moderator_escape($roleName) ?>"><i class="bi bi-eye-fill"></i></button></td>
            </tr>
        <?php endforeach; ?>
        <?php foreach ($requestRows as $row): ?>
            <?php $rowStatus = strtolower((string) ($row['status'] ?? 'pending')); $sender = (string) ($row['sender_entity'] ?: $row['sender_name'] ?: 'Unknown sender'); ?>
            <tr>
                <td class="fw-bold txt-neon-cyan">#<?= moderator_escape((string) $row['reference_code']) ?></td>
                <td><div class="fw-semibold text-white"><?= moderator_escape($sender) ?></div><div class="text-white-50 small"><?= moderator_escape((string) ($row['location'] ?? 'Location not provided')) ?></div></td>
                <td><span class="badge rounded-pill bg-info bg-opacity-10 text-info border border-info border-opacity-25 px-2 py-1"><?= moderator_escape(request_type_short_label((string) $row['request_type'])) ?></span></td>
                <td class="text-white-50"><?= moderator_escape(moderator_format_date((string) $row['created_at'])) ?></td>
                <td><span class="request-status <?= moderator_escape(request_status_class($rowStatus)) ?>"><?= moderator_escape(request_status_label($rowStatus)) ?></span></td>
                <td class="text-end"><button type="button" class="btn-action js-view-request" title="View details" data-request-id="<?= (int) $row['request_id'] ?>"><i class="bi bi-eye-fill"></i></button></td>
            </tr>
        <?php endforeach; ?>
    </tbody></table></div></div>
</main>

<div class="modal fade glass-modal" id="viewReqModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg"><div class="modal-content"><div class="modal-header"><h5 class="modal-title fw-bold" id="viewReqTitle"><i class="bi bi-file-earmark-text txt-neon-cyan me-2"></i> Request Details</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body" id="requestDetailsBody"><div class="text-center text-white-50 py-5"><span class="spinner-border spinner-border-sm me-2"></span>Loading request details...</div></div><div class="modal-footer" id="requestDetailsFooter"><button type="button" class="btn btn-outline-secondary rounded-3 text-white-50" data-bs-dismiss="modal">Close</button></div></div></div></div>
<div class="modal fade glass-modal" id="rejectReqModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title fw-bold"><i class="bi bi-shield-x text-danger me-2"></i> Reject Request</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><form id="rejectForm" action="request_action(M).php" method="POST"><div class="modal-body"><input type="hidden" name="request_id" id="rejectRequestId"><input type="hidden" name="user_id" id="rejectUserId"><input type="hidden" name="action" value="reject"><input type="hidden" name="return_to" value="view_requests(M).php"><div class="small text-white-50 mb-3">Target: <span class="txt-neon-cyan" id="rejectReference">—</span></div><label class="form-label text-white-50 small" for="rejectReason">Reason for rejection <span class="text-danger">*</span></label><textarea class="form-control glass-input" name="reason" id="rejectReason" rows="6" maxlength="2000" required></textarea><div class="small text-white-50 mt-3">The reason is saved in the request record and notification history. Email delivery is currently a placeholder.</div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-3 text-white-50" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger">Confirm Rejection</button></div></form></div></div></div>

<?php $moderatorActiveNav = 'requests'; include __DIR__ . '/includes/moderator_offcanvas.php'; ?>
<footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map &amp; Pump | All Rights Reserved</footer>
<?php include __DIR__ . '/includes/moderator_footer_scripts.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const details = new bootstrap.Modal(document.getElementById('viewReqModal'));
    const reject = new bootstrap.Modal(document.getElementById('rejectReqModal'));
    const body = document.getElementById('requestDetailsBody');
    const footer = document.getElementById('requestDetailsFooter');
    const rejectForm = document.getElementById('rejectForm');
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
    const date = value => value ? new Date(String(value).replace(' ', 'T')).toLocaleString() : 'Not available';
    const setFooter = html => { footer.innerHTML = html || '<button type="button" class="btn btn-outline-secondary rounded-3 text-white-50" data-bs-dismiss="modal">Close</button>'; };
    const rejectTarget = (targetType, id, label) => {
        rejectForm.action = targetType === 'account' ? 'account_review_action(M).php' : 'request_action(M).php';
        document.getElementById('rejectRequestId').value = targetType === 'request' ? id : '';
        document.getElementById('rejectUserId').value = targetType === 'account' ? id : '';
        document.getElementById('rejectReference').textContent = label || 'Selected request';
        document.getElementById('rejectReason').value = '';
        reject.show();
    };

    document.querySelectorAll('.js-view-request').forEach(button => button.addEventListener('click', async event => {
        event.preventDefault();
        body.innerHTML = '<div class="text-center text-white-50 py-5"><span class="spinner-border spinner-border-sm me-2"></span>Loading request details...</div>';
        setFooter('');
        details.show();
        try {
            const res = await fetch('request_details(M).php?id=' + encodeURIComponent(button.dataset.requestId));
            const p = await res.json();
            if (!res.ok || !p.success) throw Error(p.message || 'Unable to load request details.');
            const r = p.request;
            const doc = r.document_path ? '<a href="' + esc(r.document_path) + '" target="_blank" rel="noopener">' + esc(r.document_name || 'Open document') + '</a>' : esc(r.document_name || 'Not provided');
            body.innerHTML = '<div class="row g-4"><div class="col-12 col-md-6"><h6 class="fw-semibold text-white mb-3">SUBMITTER INFO</h6><div class="mb-3"><div class="request-detail-label">Corporate Partner</div><div class="request-detail-value">' + esc(r.sender_entity || r.hq_company_name || 'Not available') + '</div></div><div class="mb-3"><div class="request-detail-label">Authorized Official</div><div class="request-detail-value">' + esc(r.sender_name || r.submitter_username || 'Not available') + ' ' + (r.sender_email ? '(' + esc(r.sender_email) + ')' : '') + '</div></div><div class="mb-3"><div class="request-detail-label">Location</div><div class="request-detail-value">' + esc(r.location || 'Not provided') + '</div></div></div><div class="col-12 col-md-6"><h6 class="fw-semibold text-white mb-3">REQUEST SPECIFICS</h6><div class="mb-3"><div class="request-detail-label">Type</div><div class="request-detail-value">' + esc(r.request_type_label) + '</div></div><div class="mb-3"><div class="request-detail-label">Status</div><div class="request-detail-value">' + esc(r.status_label) + '</div></div><div class="mb-3"><div class="request-detail-label">Submitted</div><div class="request-detail-value">' + esc(date(r.created_at)) + '</div></div><div class="mb-3"><div class="request-detail-label">Verification Document</div><div class="request-detail-value">' + doc + '</div></div></div><div class="col-12"><div class="request-detail-label mb-2">Request Details</div><div class="request-detail-value p-3 rounded-3" style="background:rgba(0,244,255,.04);border:1px solid var(--glass-border)">' + esc(r.request_details || 'No additional details provided.') + '</div></div>' + (r.review_reason ? '<div class="col-12"><div class="request-detail-label">Review Message</div><div class="request-detail-value">' + esc(r.review_reason) + '</div></div>' : '') + '</div>';
            if (String(r.status || '').toLowerCase() === 'pending') {
                setFooter('<form action="request_action(M).php" method="POST" class="m-0"><input type="hidden" name="request_id" value="' + esc(r.request_id) + '"><input type="hidden" name="action" value="approve"><input type="hidden" name="return_to" value="view_requests(M).php"><button type="submit" class="btn btn-success">Approve</button></form><button type="button" class="btn btn-danger js-modal-reject">Reject</button>');
                footer.querySelector('.js-modal-reject').addEventListener('click', () => rejectTarget('request', r.request_id, '#' + (r.reference_code || r.request_id)));
            }
        } catch (err) {
            body.innerHTML = '<div class="alert alert-danger">' + esc(err.message) + '</div>';
        }
    }));

    document.querySelectorAll('.js-view-account-request').forEach(button => button.addEventListener('click', async event => {
        event.preventDefault();
        body.innerHTML = '<div class="text-center text-white-50 py-5"><span class="spinner-border spinner-border-sm me-2"></span>Loading account details...</div>';
        setFooter('');
        details.show();
        try {
            const res = await fetch('user_details(M).php?id=' + encodeURIComponent(button.dataset.userId));
            const p = await res.json();
            if (!res.ok || !p.success) throw Error(p.message || 'Unable to load account details.');
            const u = p.user || {};
            const label = 'ACCT-' + esc(u.user_id || button.dataset.userId);
            body.innerHTML = '<div class="row g-4"><div class="col-12 col-md-6"><h6 class="fw-semibold text-white mb-3">ACCOUNT INFO</h6><div class="mb-3"><div class="request-detail-label">Name</div><div class="request-detail-value">' + esc(u.user_name) + '</div></div><div class="mb-3"><div class="request-detail-label">Email</div><div class="request-detail-value">' + esc(u.email) + '</div></div><div class="mb-3"><div class="request-detail-label">Role</div><div class="request-detail-value">#' + esc(u.role_id) + ' · ' + esc(u.role_name || '') + '</div></div></div><div class="col-12 col-md-6"><h6 class="fw-semibold text-white mb-3">VERIFICATION</h6><div class="mb-3"><div class="request-detail-label">Status</div><div class="request-detail-value">Pending Verification</div></div><div class="mb-3"><div class="request-detail-label">Headquarter</div><div class="request-detail-value">' + esc(u.headquarter_name || 'No HQ linked') + '</div></div><div class="mb-3"><div class="request-detail-label">Submitted</div><div class="request-detail-value">' + esc(date(u.created_at)) + '</div></div></div></div>';
            setFooter('<form action="account_review_action(M).php" method="POST" class="m-0"><input type="hidden" name="user_id" value="' + esc(u.user_id) + '"><input type="hidden" name="action" value="approve"><input type="hidden" name="return_to" value="view_requests(M).php"><button type="submit" class="btn btn-success">Approve</button></form><button type="button" class="btn btn-danger js-modal-reject">Reject</button>');
            footer.querySelector('.js-modal-reject').addEventListener('click', () => rejectTarget('account', u.user_id, label));
        } catch (err) {
            body.innerHTML = '<div class="alert alert-danger">' + esc(err.message) + '</div>';
        }
    }));
});
</script>
</body>
</html>
