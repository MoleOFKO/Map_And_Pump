<?php
require_once __DIR__ . '/request_common.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$flash = request_pull_flash();
$search = trim((string) ($_GET['search'] ?? ''));
$requestType = strtolower(trim((string) ($_GET['type'] ?? '')));
$status = strtolower(trim((string) ($_GET['status'] ?? '')));
$allowedTypes = ['station', 'license', 'deactivation'];
$allowedStatuses = ['pending', 'approved', 'rejected', 'flagged'];

if (!in_array($requestType, $allowedTypes, true)) {
    $requestType = '';
}
if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}

$summary = [
    'pending' => 0,
    'approved_today' => 0,
    'declined_flagged' => 0,
];
$requestRows = [];
$dbError = null;

if (hq_db_available() && request_schema_ready()) {
    $summaryQuery = $conn->query(
        "SELECT
            COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending,
            COALESCE(SUM(CASE WHEN status = 'approved' AND reviewed_at >= CURDATE() THEN 1 ELSE 0 END), 0) AS approved_today,
            COALESCE(SUM(CASE WHEN status IN ('rejected', 'flagged') THEN 1 ELSE 0 END), 0) AS declined_flagged
         FROM system_requests"
    );
    if ($summaryQuery) {
        $summary = array_merge($summary, $summaryQuery->fetch_assoc() ?: []);
    } else {
        $dbError = 'The request summary could not be loaded.';
    }

    $where = [];
    $params = [];
    $types = '';

    if ($search !== '') {
        $where[] = '(LOWER(r.reference_code) LIKE ? OR LOWER(r.sender_entity) LIKE ? OR LOWER(r.sender_name) LIKE ?)';
        $searchLike = '%' . strtolower($search) . '%';
        array_push($params, $searchLike, $searchLike, $searchLike);
        $types .= 'sss';
    }

    if ($requestType !== '') {
        $typeValues = match ($requestType) {
            'station' => ['station', 'new_station', 'new_station_registration'],
            'license' => ['license', 'license_modification'],
            'deactivation' => ['deactivation', 'account_deactivation'],
        };
        $where[] = 'LOWER(r.request_type) IN (' . implode(',', array_fill(0, count($typeValues), '?')) . ')';
        foreach ($typeValues as $typeValue) {
            $params[] = $typeValue;
            $types .= 's';
        }
    }

    if ($status !== '') {
        $where[] = 'LOWER(r.status) = ?';
        $params[] = $status;
        $types .= 's';
    }

    $sql = <<<'SQL'
SELECT
    r.request_id,
    r.reference_code,
    r.request_type,
    r.status,
    r.sender_entity,
    r.sender_name,
    r.sender_email,
    r.location,
    r.created_at,
    r.submitter_user_id
FROM system_requests r
LEFT JOIN users u ON u.user_id = r.submitter_user_id
SQL;

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY r.created_at DESC, r.request_id DESC';

    $statement = $conn->prepare($sql);
    if (!$statement) {
        $dbError = 'The request list could not be loaded.';
    } else {
        $boundParams = array_values($params);
        if ($types !== '' && !hq_bind($statement, $types, $boundParams)) {
            $dbError = 'The request filters could not be applied.';
        } elseif (!$statement->execute()) {
            $dbError = 'The request list could not be loaded.';
        } else {
            $result = $statement->get_result();
            while ($result && ($row = $result->fetch_assoc())) {
                $requestRows[] = $row;
            }
        }
        $statement->close();
    }
} else {
    $dbError = 'The request database is temporarily unavailable.';
}

$flashType = $flash['type'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Map &amp; Pump Admin — Review and action inbound system requests including partner registrations, station changes, and licensing.">
    <title>View Requests — Map &amp; Pump</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&amp;display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="icon" type="image/png" href="/images/Logo.png">
    <link rel="stylesheet" href="/css/Admin.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .request-status { display:inline-flex; align-items:center; gap:.45rem; color:rgba(255,255,255,.68); font-size:.8rem; white-space:nowrap; }
        .request-status::before { content:''; width:8px; height:8px; border-radius:50%; display:inline-block; }
        .request-status-pending::before { background:#ffc107; box-shadow:0 0 8px #ffc107; }
        .request-status-approved::before { background:#1be494; box-shadow:0 0 8px #1be494; }
        .request-status-rejected::before { background:#ff4d6a; box-shadow:0 0 8px #ff4d6a; }
        .request-status-flagged::before { background:#ff9f43; box-shadow:0 0 8px #ff9f43; }
        .request-flash { border-radius:14px; background:rgba(10,22,44,.82); border:1px solid var(--glass-border); color:var(--text-primary); }
        .request-modal-table { --bs-table-bg:transparent; --bs-table-color:var(--text-primary); --bs-table-border-color:var(--glass-border); }
        .request-modal-table th { color:var(--text-muted); font-size:.72rem; text-transform:uppercase; letter-spacing:.05em; }
        .request-detail-label { color:var(--text-muted); font-size:.72rem; text-transform:uppercase; letter-spacing:.07em; }
        .request-detail-value { color:var(--text-primary); word-break:break-word; white-space:pre-wrap; }
        body.background_color.light-mode .request-flash { background:rgba(255,255,255,.9); }
        body.background_color.light-mode .request-status { color:var(--text-muted); }
    </style>
</head>
<body class="background_color" id="background_color">
    <div class="page-load-bar"></div>

    <div class="container sticky-nav-wrapper">
        <nav class="navbar navbar-dark text-white navigation_style">
            <div class="container-fluid d-flex justify-content-between align-items-center p-0 gap-3">
                <a class="navbar-brand d-flex align-items-center gap-2 fw-semibold txt-neon-cyan m-0" href="admin_dashboard.php" style="font-size:.95rem;">
                    <img src="/images/Logo.png" alt="Logo" class="nav-logo"><span class="d-none d-sm-inline">Admin Portal</span>
                </a>
                <div class="admin-nav-actions">
                    <label class="cosmic-toggle nav-theme-toggle" title="Toggle light mode">
                        <input class="toggle js-theme-toggle" type="checkbox" aria-label="Toggle light mode">
                        <div class="slider"><span class="theme-icon theme-icon-dark"><i class="bi bi-moon-stars-fill"></i></span><span class="theme-icon theme-icon-light"><i class="bi bi-sun-fill"></i></span><div class="cosmos"></div><div class="toggle-orb"><div class="inner-orb"></div><div class="ring"></div></div></div>
                    </label>
                    <button class="btn btn-sm menu-pill-btn" type="button" data-bs-toggle="offcanvas" data-bs-target="#mainMenuOffcanvas"><i class="bi bi-grid-fill me-1 txt-neon-cyan"></i> Menu</button>
                </div>
            </div>
        </nav>
    </div>

    <main class="container my-5">
        <?php if ($flash && $flashType): ?>
            <div class="alert request-flash alert-<?= hq_escape($flashType) ?> alert-dismissible fade show mb-4" role="alert">
                <i class="bi <?= $flashType === 'success' ? 'bi-check-circle-fill text-success' : 'bi-exclamation-triangle-fill text-danger' ?> me-2"></i><?= hq_escape($flash['message'] ?? '') ?>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div><span class="stat-chip mb-2"><i class="bi bi-clipboard-check-fill txt-neon-cyan"></i> Review Pipeline</span><h1 class="fw-bold m-0 h4 text-white mt-2">Inbound System Requests</h1><p class="text-white-50 m-0 small mt-1">Approve or deny partner registration, station adjustments, and licensing requests.</p></div>
        </div>

        <?php if ($dbError): ?><div class="alert request-flash alert-danger mb-4" role="alert"><i class="bi bi-database-exclamation me-2"></i><?= hq_escape($dbError) ?></div><?php endif; ?>

        <div class="row g-3 mb-4">
            <div class="col-12 col-sm-4"><div class="request-indicator-card"><div><p class="text-white-50 mb-1 small fw-semibold" style="text-transform:uppercase;letter-spacing:.06em;font-size:.72rem;">Pending Verification</p><h4 class="fw-bold text-warning mb-0" style="font-size:1.8rem;letter-spacing:-.04em;"><?= (int) $summary['pending'] ?></h4></div><span class="fs-3 text-warning"><i class="bi bi-hourglass-split"></i></span></div></div>
            <div class="col-12 col-sm-4"><div class="request-indicator-card"><div><p class="text-white-50 mb-1 small fw-semibold" style="text-transform:uppercase;letter-spacing:.06em;font-size:.72rem;">Approved Today</p><h4 class="fw-bold mb-0" style="font-size:1.8rem;letter-spacing:-.04em;color:#1be494;"><?= (int) $summary['approved_today'] ?></h4></div><span class="fs-3" style="color:#1be494;"><i class="bi bi-patch-check-fill"></i></span></div></div>
            <div class="col-12 col-sm-4"><div class="request-indicator-card"><div><p class="text-white-50 mb-1 small fw-semibold" style="text-transform:uppercase;letter-spacing:.06em;font-size:.72rem;">Declined / Flagged</p><h4 class="fw-bold mb-0" style="font-size:1.8rem;letter-spacing:-.04em;color:#ff4d6a;"><?= (int) $summary['declined_flagged'] ?></h4></div><span class="fs-3" style="color:#ff4d6a;"><i class="bi bi-shield-x"></i></span></div></div>
        </div>

        <div class="row g-4">
            <div class="col-12"><div class="dashboard-card py-3 px-4"><form class="row g-3 align-items-center" method="GET" action="view_requests.php">
                <div class="col-12 col-md-5"><div class="input-group"><span class="input-group-text bg-transparent border-end-0" style="border-color:var(--glass-border);color:var(--neon-cyan);border-radius:12px 0 0 12px;"><i class="bi bi-funnel-fill"></i></span><input type="text" class="form-control glass-input border-start-0" placeholder="Filter by company, sender name, or reference ID..." name="search" value="<?= hq_escape($search) ?>"></div></div>
                <div class="col-12 col-md-3"><select class="form-select glass-select" name="type"><option value="" <?= $requestType === '' ? 'selected' : '' ?>>All Request Types</option><option value="station" <?= $requestType === 'station' ? 'selected' : '' ?>>New Station Registration</option><option value="license" <?= $requestType === 'license' ? 'selected' : '' ?>>License Modification</option><option value="deactivation" <?= $requestType === 'deactivation' ? 'selected' : '' ?>>Account Deactivation</option></select></div>
                <div class="col-12 col-md-2"><select class="form-select glass-select" name="status"><option value="" <?= $status === '' ? 'selected' : '' ?>>All Statuses</option><option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option><option value="approved" <?= $status === 'approved' ? 'selected' : '' ?>>Approved</option><option value="rejected" <?= $status === 'rejected' ? 'selected' : '' ?>>Rejected</option><option value="flagged" <?= $status === 'flagged' ? 'selected' : '' ?>>Flagged</option></select></div>
                <div class="col-12 col-md-2 d-grid"><button type="submit" class="btn btn-neon">Apply Filter</button></div>
            </form></div></div>

            <div class="col-12"><div class="analytics-card"><div class="table-responsive"><table class="table table-dark-custom align-middle m-0"><thead><tr><th>Ref ID</th><th>Sender Entity</th><th>Type of Request</th><th>Submission Date</th><th>Status</th><th class="text-end">Command</th></tr></thead><tbody>
                <?php if (!$requestRows): ?><tr><td colspan="6" class="text-center text-white-50 py-5"><i class="bi bi-inbox fs-3 d-block mb-2"></i>No requests match the current filter.</td></tr><?php endif; ?>
                <?php foreach ($requestRows as $row): ?>
                    <?php $sender = (string) ($row['sender_entity'] ?: $row['sender_name'] ?: 'Unknown sender'); $location = (string) ($row['location'] ?: 'Location not provided'); $rowStatus = (string) ($row['status'] ?: 'pending'); ?>
                    <tr>
                        <td class="fw-bold txt-neon-cyan">#<?= hq_escape($row['reference_code']) ?></td>
                        <td><div class="fw-semibold text-white"><?= hq_escape($sender) ?></div><div class="text-white-50 small" style="font-size:.8rem;"><?= hq_escape($location) ?></div></td>
                        <td><span class="badge rounded-pill bg-info bg-opacity-10 text-info border border-info border-opacity-25 px-2 py-1"><?= hq_escape(request_type_short_label($row['request_type'])) ?></span></td>
                        <td class="text-white-50"><?= hq_escape(request_date($row['created_at'])) ?></td>
                        <td><span class="request-status <?= request_status_class($rowStatus) ?>"><?= hq_escape(request_status_label($rowStatus)) ?></span></td>
                        <td class="text-end"><div class="d-flex gap-2 justify-content-end">
                            <button type="button" class="btn-action js-view-request" title="View Request Details" aria-label="View request <?= hq_escape($row['reference_code']) ?> details" data-request-id="<?= (int) $row['request_id'] ?>"><i class="bi bi-eye-fill"></i></button>
                            <?php if (strtolower($rowStatus) === 'pending'): ?><a href="#" class="btn-action btn-action-success js-request-approve" title="Approve" aria-label="Approve request <?= hq_escape($row['reference_code']) ?>" data-request-id="<?= (int) $row['request_id'] ?>" data-reference="<?= hq_escape($row['reference_code']) ?>"><i class="bi bi-check-lg"></i></a><a href="#" class="btn-action btn-action-danger js-request-reject" title="Reject" aria-label="Reject request <?= hq_escape($row['reference_code']) ?>" data-request-id="<?= (int) $row['request_id'] ?>" data-reference="<?= hq_escape($row['reference_code']) ?>" data-sender="<?= hq_escape($sender) ?>"><i class="bi bi-x-lg"></i></a><?php endif; ?>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
            </tbody></table></div></div></div>
        </div>
    </main>

    <form action="request_action.php" method="POST" id="approveRequestForm" class="d-none"><input type="hidden" name="request_id" id="approveRequestId"><input type="hidden" name="action" value="approve"></form>

    <div class="modal fade glass-modal" id="viewReqModal" tabindex="-1" aria-labelledby="viewReqModalLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content"><div class="modal-header"><h5 class="modal-title fw-bold" id="viewReqModalLabel"><i class="bi bi-file-earmark-text txt-neon-cyan me-2"></i> Request Details</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body" id="requestDetailsBody"><div class="text-center text-white-50 py-5"><div class="spinner-border spinner-border-sm txt-neon-cyan mb-2" role="status"></div><div>Loading request details…</div></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-3 text-white-50 border-secondary border-opacity-20" data-bs-dismiss="modal">Close</button></div></div></div></div>

    <div class="modal fade glass-modal" id="rejectReqModal" tabindex="-1" aria-labelledby="rejectReqModalLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title fw-bold" id="rejectReqModalLabel"><i class="bi bi-shield-x text-danger me-2"></i> Reject Request</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div><form action="request_action.php" method="POST"><div class="modal-body d-flex flex-column gap-3"><input type="hidden" name="request_id" id="rejectRequestId"><input type="hidden" name="action" value="reject"><div class="small text-white-50">Target request: <span class="txt-neon-cyan fw-semibold" id="rejectRequestReference">—</span></div><div class="small text-white-50">Sender: <span class="text-white" id="rejectRequestSender">—</span></div><div><label class="form-label text-white-50 small" for="rejectReason">Reason for rejection</label><textarea class="form-control glass-input" name="reason" id="rejectReason" rows="5" maxlength="2000" placeholder="Explain why this request is being rejected..." required style="resize:none;"></textarea></div><div class="small text-white-50"><i class="bi bi-envelope me-1 txt-neon-cyan"></i>The reason is recorded and a notification is created for the request account. Email delivery uses the configured placeholder until SMTP is added.</div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-3 text-white-50 border-secondary border-opacity-20" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger">Confirm Rejection</button></div></form></div></div></div>

    <div class="offcanvas offcanvas-end" tabindex="-1" id="mainMenuOffcanvas" style="width:320px;background:rgba(6,12,24,.98);backdrop-filter:blur(28px);border-left:1px solid rgba(0,180,255,.18);"><div class="offcanvas-header pb-2 border-bottom border-white border-opacity-10"><div><h5 class="offcanvas-title text-white fw-bold mb-0">Main Menu</h5><p class="text-white-50 small mb-0" style="font-size:.78rem;">Admin Portal</p></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button></div><div class="offcanvas-body pt-3"><ul class="list-unstyled d-flex flex-column gap-2"><li><a href="admin_dashboard.php" class="menu-btn-nav"><i class="bi bi-house me-3 txt-neon-cyan"></i> Home</a></li><li><a href="view_hq_and_user_account.php" class="menu-btn-nav"><i class="bi bi-building-gear me-3 txt-neon-cyan"></i> View/Manage HQ</a></li><li><a href="view_users.php" class="menu-btn-nav"><i class="bi bi-people me-3 txt-neon-cyan"></i> View/Manage Users</a></li><li><a href="view_requests.php" class="menu-btn-nav active-nav"><i class="bi bi-file-earmark-check me-3 txt-neon-cyan"></i> View Requests</a></li><li><a href="view_report.php" class="menu-btn-nav"><i class="bi bi-graph-up-arrow me-3 txt-neon-cyan"></i> View Report</a></li><li><a href="edit_profile.php" class="menu-btn-nav"><i class="bi bi-person-bounding-box me-3 txt-neon-blue"></i> Edit Profile</a></li><li><a href="manage_moderators.php" class="menu-btn-nav"><i class="bi bi-people-fill me-3 txt-neon-blue"></i> Manage Moderators</a></li><li><a href="view_logs.php" class="menu-btn-nav"><i class="bi bi-terminal-split me-3 text-danger"></i> View Log Files</a></li><hr style="border-color:rgba(255,255,255,.08);margin:4px 0;"><li><form action="../Login/logout.php" method="post" class="m-0"><button type="submit" class="logout-btn-nav btn border-0 w-100"><i class="bi bi-box-arrow-right me-2"></i> Logout</button></form></li></ul></div></div>

    <footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map &amp; Pump | All Rights Reserved</footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script src="/js/Admin.js"></script>
    <script>
        (() => {
            const detailsModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('viewReqModal'));
            const rejectModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('rejectReqModal'));
            const detailsTitle = document.getElementById('viewReqModalLabel');
            const detailsBody = document.getElementById('requestDetailsBody');
            const rejectId = document.getElementById('rejectRequestId');
            const rejectReference = document.getElementById('rejectRequestReference');
            const rejectSender = document.getElementById('rejectRequestSender');
            const rejectReason = document.getElementById('rejectReason');
            const approveId = document.getElementById('approveRequestId');
            const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (character) => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[character]));
            const formatDate = (value) => value ? new Date(String(value).replace(' ', 'T')).toLocaleString() : 'Not available';
            const statusClass = (status) => ({approved:'request-status-approved', rejected:'request-status-rejected', flagged:'request-status-flagged'}[String(status || '').toLowerCase()] || 'request-status-pending');
            const statusLabel = (status) => ({pending:'Pending', approved:'Approved', rejected:'Rejected', flagged:'Flagged'}[String(status || '').toLowerCase()] || 'Pending');

            document.querySelectorAll('.js-request-approve').forEach((button) => {
                button.addEventListener('click', (event) => {
                    event.preventDefault();
                    approveId.value = button.dataset.requestId || '';
                    document.getElementById('approveRequestForm').submit();
                });
            });

            document.querySelectorAll('.js-request-reject').forEach((button) => {
                button.addEventListener('click', (event) => {
                    event.preventDefault();
                    rejectId.value = button.dataset.requestId || '';
                    rejectReference.textContent = button.dataset.reference || 'Selected request';
                    rejectSender.textContent = button.dataset.sender || 'Unknown sender';
                    rejectReason.value = '';
                    rejectModal.show();
                });
            });

            const renderDetails = (payload) => {
                const request = payload.request || {};
                const documentMarkup = request.document_name ? '<a href="' + escapeHtml(request.document_path || '#') + '" class="txt-neon-blue text-decoration-none"' + (request.document_path ? ' target="_blank" rel="noopener"' : '') + '><i class="bi bi-file-earmark-text me-2"></i>' + escapeHtml(request.document_name) + '</a>' : '<span class="text-white-50">No document attached</span>';
                detailsTitle.innerHTML = '<i class="bi bi-file-earmark-text txt-neon-cyan me-2"></i> Request ' + escapeHtml(request.reference_code || 'Details');
                detailsBody.innerHTML = '<div class="row g-4"><div class="col-12 col-md-6"><h6 class="fw-semibold text-white mb-3" style="letter-spacing:.5px;font-size:.78rem;text-transform:uppercase;">Submitter Info</h6><div class="mb-3"><div class="request-detail-label">Corporate Partner</div><div class="request-detail-value">' + escapeHtml(request.sender_entity || request.hq_company_name || 'Not available') + '</div></div><div class="mb-3"><div class="request-detail-label">Authorized Official</div><div class="request-detail-value">' + escapeHtml(request.sender_name || request.submitter_username || 'Not available') + (request.sender_email ? ' (' + escapeHtml(request.sender_email) + ')' : '') + '</div></div><div class="mb-3"><div class="request-detail-label">Operation Hub Location</div><div class="request-detail-value">' + escapeHtml(request.location || 'Not provided') + '</div></div></div><div class="col-12 col-md-6"><h6 class="fw-semibold text-white mb-3" style="letter-spacing:.5px;font-size:.78rem;text-transform:uppercase;">Request Specifics</h6><div class="mb-3"><div class="request-detail-label">Request Type</div><div class="request-detail-value">' + escapeHtml(request.request_type_label || request.request_type || 'Not available') + '</div></div><div class="mb-3"><div class="request-detail-label">Status</div><div><span class="request-status ' + statusClass(request.status) + '">' + escapeHtml(request.status_label || statusLabel(request.status)) + '</span></div></div><div class="mb-3"><div class="request-detail-label">Submitted</div><div class="request-detail-value">' + escapeHtml(formatDate(request.created_at)) + '</div></div><div class="mb-3"><div class="request-detail-label">Verification Document</div><div class="request-detail-value">' + documentMarkup + '</div></div></div><div class="col-12"><h6 class="fw-semibold text-white mb-2" style="letter-spacing:.5px;font-size:.78rem;text-transform:uppercase;">Request Details</h6><div class="request-detail-value p-3 rounded-3" style="background:rgba(0,244,255,.04);border:1px solid var(--glass-border);">' + escapeHtml(request.request_details || 'No additional details provided.') + '</div></div>' + (request.review_reason ? '<div class="col-12"><div class="request-detail-label">Review Message</div><div class="request-detail-value">' + escapeHtml(request.review_reason) + '</div></div>' : '') + '</div>';
            };

            document.querySelectorAll('.js-view-request').forEach((button) => {
                button.addEventListener('click', async () => {
                    detailsTitle.innerHTML = '<i class="bi bi-file-earmark-text txt-neon-cyan me-2"></i> Request Details';
                    detailsBody.innerHTML = '<div class="text-center text-white-50 py-5"><div class="spinner-border spinner-border-sm txt-neon-cyan mb-2" role="status"></div><div>Loading request details…</div></div>';
                    detailsModal.show();
                    try {
                        const response = await fetch('request_details.php?id=' + encodeURIComponent(button.dataset.requestId), {headers:{Accept:'application/json'}});
                        const payload = await response.json();
                        if (!response.ok || !payload.success) throw new Error(payload.message || 'Could not load request details.');
                        renderDetails(payload);
                    } catch (error) {
                        detailsBody.innerHTML = '<div class="alert request-flash alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>' + escapeHtml(error.message) + '</div>';
                    }
                });
            });
        })();
    </script>
    <script>document.addEventListener('pointermove',(event)=>{document.body.style.setProperty('--bg-x',`${(event.clientX/window.innerWidth)*100}%`);document.body.style.setProperty('--bg-y',`${(event.clientY/window.innerHeight)*100}%`);});</script>
</body>
</html>
