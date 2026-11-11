<?php
require_once __DIR__ . '/moderator_common.php';

$moderatorUser = moderator_require_user();
$flash = request_pull_flash();
$paymentFlash = $_SESSION['payment_transfer_flash'] ?? null;
unset($_SESSION['payment_transfer_flash']);
if (empty($_SESSION['payment_transfer_csrf'])) {
    $_SESSION['payment_transfer_csrf'] = bin2hex(random_bytes(32));
}
$search = trim((string) ($_GET['search'] ?? ''));
$requestType = strtolower(trim((string) ($_GET['type'] ?? '')));
$status = 'pending';

// Filter keywords from live system_requests (+ account bucket + core fallbacks).
$dbTypes = function_exists('admin_filter_request_types') ? admin_filter_request_types() : [];
$allowedTypes = $dbTypes;
foreach (['account', 'payment', 'hq_registration', 'station', 'new_station', 'new_station_registration', 'license', 'license_modification', 'deactivation', 'account_deactivation'] as $fallbackType) {
    if (!in_array($fallbackType, $allowedTypes, true)) {
        $allowedTypes[] = $fallbackType;
    }
}
// Keep synthetic "account" bucket for pending user verification rows.
if (!in_array('account', $allowedTypes, true)) {
    array_unshift($allowedTypes, 'account');
}
sort($allowedTypes);
$requestTypeOptions = function_exists('admin_request_type_filter_options')
    ? admin_request_type_filter_options($allowedTypes)
    : array_values(array_unique(array_map(static function (string $type): string {
        return match ($type) {
            'new_station', 'new_station_registration' => 'station',
            'license_modification' => 'license',
            'account_deactivation' => 'deactivation',
            default => $type,
        };
    }, $allowedTypes)));

$requestType = ($requestType !== '' && in_array($requestType, $allowedTypes, true)) ? $requestType : '';
$requestTypeOption = function_exists('admin_request_type_filter_value')
    ? admin_request_type_filter_value($requestType)
    : match ($requestType) {
        'new_station', 'new_station_registration' => 'station',
        'license_modification' => 'license',
        'account_deactivation' => 'deactivation',
        default => $requestType,
    };
$requestRows = in_array($requestType, ['account', 'payment'], true) ? [] : moderator_request_rows($search, $requestType, $status);
$paymentReviewByRequest = request_payment_review_map($requestRows);
$paymentRows = in_array($requestType, ['', 'payment'], true)
    ? request_payment_verification_rows('moderator', $search, $status)
    : [];
$accountRows = [];
$carLicenseRows = [];
if (($requestType === '' || $requestType === 'account') && ($status === '' || $status === 'pending')) {
    $accountRows = pending_account_rows($search);
    $carLicenseRows = pending_car_license_rows($search);
}

$summary = moderator_request_summary();
$dbError = $summary['error'] ?? (hq_db_available() ? null : 'The request database is temporarily unavailable.');
$flashType = (string) ($flash['type'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Map &amp; Pump Admin — Review and action inbound system requests including partner registrations, station changes, and licensing.">
    <title>View Requests — Map &amp; Pump</title>
    <link rel="icon" type="image/png" href="/images/Logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&amp;display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
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
        .request-detail-modal .modal-content { overflow:hidden; }
        .request-detail-scroll {
            max-height: min(40vh, 320px);
            overflow-y: auto;
            overscroll-behavior: contain;
            scrollbar-width: thin;
            scrollbar-color: rgba(0,244,255,.45) rgba(4,12,28,.9);
        }
        .request-detail-scroll::-webkit-scrollbar { width: 8px; }
        .request-detail-scroll::-webkit-scrollbar-thumb { background: rgba(0,244,255,.35); border-radius: 999px; }
        .request-detail-modal .modal-header { padding:1.1rem 1.35rem; border-bottom-color:rgba(0,244,255,.16); background:linear-gradient(120deg,rgba(0,244,255,.09),rgba(37,99,235,.035) 58%,transparent); }
        .request-detail-modal .modal-body { padding:1.35rem; }
        .request-detail-modal .modal-footer { padding:1rem 1.35rem; border-top-color:rgba(255,255,255,.09); background:rgba(4,13,30,.34); }
        .request-account-hero { display:flex; align-items:center; gap:1rem; padding:1rem 1.1rem; border:1px solid rgba(0,244,255,.2); border-radius:16px; background:linear-gradient(135deg,rgba(0,244,255,.1),rgba(27,228,148,.035)); box-shadow:inset 0 1px 0 rgba(255,255,255,.05); }
        .request-account-avatar { width:3.15rem; height:3.15rem; flex:0 0 3.15rem; display:grid; place-items:center; border:1px solid rgba(0,244,255,.35); border-radius:13px; color:var(--neon-cyan); background:rgba(0,244,255,.09); font-weight:800; letter-spacing:.03em; }
        .request-account-kicker { color:var(--text-muted); font-size:.7rem; letter-spacing:.09em; text-transform:uppercase; }
        .request-account-name { color:var(--text-primary); font-size:1.05rem; font-weight:700; line-height:1.25; word-break:break-word; }
        .request-detail-section + .request-detail-section { margin-top:1.45rem; padding-top:1.45rem; border-top:1px solid rgba(255,255,255,.09); }
        .request-detail-section-title { display:flex; align-items:center; gap:.55rem; margin:0 0 .85rem; color:var(--text-primary); font-size:.78rem; font-weight:700; letter-spacing:.07em; text-transform:uppercase; }
        .request-detail-section-title i { color:var(--neon-cyan); font-size:.95rem; }
        .request-detail-grid { display:grid; gap:.75rem; grid-template-columns:repeat(3,minmax(0,1fr)); }
        .request-detail-grid--two { grid-template-columns:repeat(2,minmax(0,1fr)); }
        .request-detail-card { min-width:0; padding:.85rem .9rem; border:1px solid rgba(255,255,255,.1); border-radius:12px; background:rgba(255,255,255,.025); }
        .request-detail-card--wide { grid-column:span 2; }
        .request-detail-card--full { grid-column:1 / -1; }
        .request-detail-card .request-detail-label { margin-bottom:.35rem; }
        .request-detail-card .request-detail-value { font-size:.9rem; line-height:1.45; }
        .request-detail-callout { padding:1rem; border:1px solid rgba(0,244,255,.18); border-radius:12px; background:rgba(0,244,255,.045); }
        .request-review-success .modal-content { border-color:rgba(27,228,148,.35); }
        .request-review-success-icon { width:4.25rem; height:4.25rem; display:grid; place-items:center; margin:0 auto 1rem; border:1px solid rgba(27,228,148,.45); border-radius:50%; background:rgba(27,228,148,.12); color:#1be494; font-size:1.85rem; box-shadow:0 0 24px rgba(27,228,148,.12); }
        body.background_color.light-mode .request-detail-modal .modal-header { background:linear-gradient(120deg,rgba(0,164,180,.1),rgba(37,99,235,.04) 58%,transparent); }
        body.background_color.light-mode .request-detail-modal .modal-footer { background:rgba(255,255,255,.55); }
        body.background_color.light-mode .request-account-hero { background:linear-gradient(135deg,rgba(0,190,210,.1),rgba(27,228,148,.045)); }
        body.background_color.light-mode .request-detail-card { background:rgba(255,255,255,.62); }
        @media (max-width:767.98px) { .request-detail-modal .modal-body { padding:1rem; } .request-detail-grid, .request-detail-grid--two { grid-template-columns:1fr; } .request-detail-card--wide, .request-detail-card--full { grid-column:auto; } }
        body.background_color.light-mode .request-flash { background:rgba(255,255,255,.9); }
        body.background_color.light-mode .request-status { color:var(--text-muted); }

        /* Account details modal */
        .acct-profile-header{display:flex;align-items:center;gap:1.1rem;padding:1.1rem 1.3rem;border-radius:16px;background:linear-gradient(135deg,rgba(0,180,255,.14),rgba(27,228,148,.03));border:1px solid var(--glass-border);margin-bottom:1.4rem;flex-wrap:wrap;}
        .acct-avatar{width:58px;height:58px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1.35rem;color:#04121f;background:linear-gradient(135deg,#00f4ff,#1be494);flex-shrink:0;box-shadow:0 0 18px rgba(0,244,255,.4);letter-spacing:-.02em;}
        .acct-profile-meta{min-width:0;flex:1 1 auto;}
        .acct-profile-name{color:#fff;font-weight:700;font-size:1.02rem;line-height:1.25;word-break:break-word;}
        .acct-profile-sub{color:rgba(255,255,255,.6);font-size:.8rem;word-break:break-word;}
        .acct-badge{display:inline-flex;align-items:center;gap:.35rem;font-size:.68rem;font-weight:700;padding:.32rem .7rem;border-radius:999px;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;}
        .acct-badge-pending{background:rgba(255,193,7,.12);color:#ffc107;border:1px solid rgba(255,193,7,.32);}
        .acct-badge-role{background:rgba(0,244,255,.1);color:var(--neon-cyan);border:1px solid rgba(0,244,255,.28);}
        .acct-section{border-radius:14px;background:rgba(0,244,255,.035);border:1px solid var(--glass-border);padding:1.1rem 1.25rem;margin-bottom:1.1rem;}
        .acct-section:last-child{margin-bottom:0;}
        .acct-section-title{display:flex;align-items:center;gap:.5rem;color:#fff;font-weight:700;font-size:.75rem;text-transform:uppercase;letter-spacing:.07em;margin-bottom:1rem;}
        .acct-section-title i{color:var(--neon-cyan);font-size:.85rem;}
        .acct-field-row{display:flex;align-items:flex-start;gap:.65rem;margin-bottom:.9rem;}
        .acct-field-row:last-child{margin-bottom:0;}
        .acct-field-icon{width:30px;height:30px;border-radius:9px;background:rgba(0,244,255,.08);display:flex;align-items:center;justify-content:center;color:var(--neon-cyan);font-size:.85rem;flex-shrink:0;margin-top:.05rem;}
        .acct-doc-grid{display:flex;flex-wrap:wrap;gap:.85rem;margin-top:.3rem;}
        .acct-doc-card{width:128px;border-radius:12px;overflow:hidden;border:1px solid var(--glass-border);background:rgba(255,255,255,.02);text-decoration:none;transition:transform .15s ease,border-color .15s ease,box-shadow .15s ease;display:block;}
        .acct-doc-card:hover{transform:translateY(-3px);border-color:var(--neon-cyan);box-shadow:0 8px 20px rgba(0,244,255,.15);}
        .acct-doc-thumb{width:100%;height:88px;object-fit:cover;display:block;background:rgba(0,0,0,.25);}
        .acct-doc-empty{width:100%;height:88px;display:flex;align-items:center;justify-content:center;color:var(--text-muted);font-size:1.4rem;background:rgba(255,255,255,.02);}
        .acct-doc-label{font-size:.66rem;color:rgba(255,255,255,.65);padding:.4rem .5rem;text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;border-top:1px solid var(--glass-border);}
        .acct-empty-note{border-radius:12px;border:1px dashed var(--glass-border);padding:.9rem 1.1rem;color:rgba(255,255,255,.55);font-size:.82rem;display:flex;align-items:center;gap:.6rem;}

        /* Modal footer action styles: Make buttons long & centered */
        #requestDetailsFooter { display: flex; gap: 0.75rem; width: 100%; }
        #requestDetailsFooter form { display: flex; flex: 1; margin: 0; }
        .acct-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            width: 100%;
            gap: 0.5rem;
            padding: 0.65rem 2rem;
            border-radius: 999px;
            font-size: 0.85rem;
            font-weight: 700;
            letter-spacing: 0.02em;
            border: 1px solid transparent;
            background: rgba(255, 255, 255, 0.02);
            cursor: pointer;
            transition: transform .15s ease, box-shadow .15s ease, background .15s ease;
            flex: 1;
        }
        .acct-btn:active{transform:translateY(1px);}
        .acct-btn-approve{color:#1be494;border-color:rgba(27,228,148,.4);background:rgba(27,228,148,.08);}
        .acct-btn-approve:hover{background:rgba(27,228,148,.16);box-shadow:0 0 18px rgba(27,228,148,.35);color:#1be494;}
        .acct-btn-reject{color:#ff4d6a;border-color:rgba(255,77,106,.4);background:rgba(255,77,106,.08);}
        .acct-btn-reject:hover{background:rgba(255,77,106,.16);box-shadow:0 0 18px rgba(255,77,106,.35);color:#ff4d6a;}
        body.background_color.light-mode .acct-profile-header{background:linear-gradient(135deg,rgba(0,150,220,.08),rgba(0,150,220,.02));}
        body.background_color.light-mode .acct-profile-name{color:#12233f;}
    </style>
</head>
<body class="background_color" id="background_color">
<?php include __DIR__ . '/includes/moderator_navbar.php'; ?>
<main class="container my-5">
    <?php if (is_array($paymentFlash)): ?><div class="alert request-flash alert-<?= moderator_escape((string) ($paymentFlash['type'] ?? 'info')) ?> alert-dismissible fade show mb-4" role="status"><?= moderator_escape((string) ($paymentFlash['message'] ?? 'Payment status updated.')) ?><button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
    <?php if ($flash && $flashType): ?>
        <div class="alert request-flash alert-<?= moderator_escape($flashType) ?> alert-dismissible fade show mb-4" role="alert">
            <i class="bi <?= $flashType === 'success' ? 'bi-check-circle-fill text-success' : 'bi-exclamation-triangle-fill text-danger' ?> me-2"></i><?= moderator_escape((string) ($flash['message'] ?? '')) ?>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if ($dbError): ?><div class="alert request-flash alert-danger mb-4" role="alert"><i class="bi bi-database-exclamation me-2"></i><?= moderator_escape($dbError) ?></div><?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <span class="stat-chip mb-2"><i class="bi bi-clipboard-check-fill txt-neon-cyan"></i> Review Pipeline</span>
            <h1 class="fw-bold m-0 h4 text-white mt-2">Inbound System Requests</h1>

        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-4"><div class="request-indicator-card"><div><p class="text-white-50 mb-1 small fw-semibold" style="text-transform:uppercase;letter-spacing:.06em;font-size:.72rem;">Pending Verification</p><h4 class="fw-bold text-warning mb-0" style="font-size:1.8rem;letter-spacing:-.04em;"><?= (int) $summary['pending'] ?></h4></div><span class="fs-3 text-warning"><i class="bi bi-hourglass-split"></i></span></div></div>
        <div class="col-12 col-sm-4"><div class="request-indicator-card"><div><p class="text-white-50 mb-1 small fw-semibold" style="text-transform:uppercase;letter-spacing:.06em;font-size:.72rem;">Approved</p><h4 class="fw-bold mb-0" data-live-request-count="approved" style="font-size:1.8rem;letter-spacing:-.04em;color:#1be494;"><?= (int) $summary['approved'] ?></h4></div><span class="fs-3" style="color:#1be494;"><i class="bi bi-patch-check-fill"></i></span></div></div>
        <div class="col-12 col-sm-4"><div class="request-indicator-card"><div><p class="text-white-50 mb-1 small fw-semibold" style="text-transform:uppercase;letter-spacing:.06em;font-size:.72rem;">Declined / Flagged</p><h4 class="fw-bold mb-0" data-live-request-count="declined_flagged" style="font-size:1.8rem;letter-spacing:-.04em;color:#ff4d6a;"><?= (int) $summary['declined_flagged'] ?></h4></div><span class="fs-3" style="color:#ff4d6a;"><i class="bi bi-shield-x"></i></span></div></div>
    </div>

    <div class="row g-4">
    <div class="col-12"><div class="dashboard-card py-3 px-4">
        <form class="row g-3 align-items-center" method="GET" action="view_requests(M).php">
            <div class="col-12 col-md-5"><div class="input-group"><span class="input-group-text bg-transparent border-end-0" style="border-color:var(--glass-border);color:var(--neon-cyan);border-radius:12px 0 0 12px;"><i class="bi bi-funnel-fill"></i></span><input type="text" class="form-control glass-input border-start-0" placeholder="Filter by company, sender name, email, or reference ID..." name="search" value="<?= moderator_escape($search) ?>"></div></div>
            <div class="col-12 col-md-3"><select class="form-select glass-select" name="type"><option value="">All Request Types</option><?php foreach ($requestTypeOptions as $option): ?><option value="<?= moderator_escape($option) ?>" <?= $requestTypeOption === $option ? 'selected' : '' ?>><?= $option === 'account' ? 'Users' : moderator_escape(function_exists('admin_filter_label') ? admin_filter_label($option) : (function_exists('request_type_label') ? request_type_label($option) : $option)) ?></option><?php endforeach; ?></select></div>
            <div class="col-12 col-md-2"><select class="form-select glass-select" aria-label="Status filter fixed to Pending" disabled><option value="pending" selected>Pending</option></select><input type="hidden" name="status" value="pending"></div>
            <div class="col-12 col-md-2 d-flex gap-2"><button type="button" class="btn btn-neon flex-grow-1 js-clear-filters" title="Clear filters">Clear</button></div>
        </form>
    </div></div>

    <div class="col-12"><div class="analytics-card"><div class="table-responsive"><table id="requestQueueTable" class="table table-dark-custom align-middle m-0"><thead><tr><th>Reference ID</th><th>Sender</th><th>Request type</th><th>Submitted</th><th>Status</th><th class="text-end">Actions</th></tr></thead><tbody>
        <?php if (!$accountRows && !$carLicenseRows && !$requestRows && !$paymentRows): ?>
            <tr><td colspan="6" class="text-center text-white-50 py-5"><i class="bi bi-inbox fs-3 d-block mb-2"></i>No requests match the current filter.</td></tr>
        <?php endif; ?>
        <?php foreach ($accountRows as $row): ?>
            <?php
            $roleName = (string) ($row['role_name'] ?? '');
            $normalizedRoleName = strtolower(trim($roleName));
            $isCompany = in_array($normalizedRoleName, ['hq', 'headquarter', 'headquarters', 'company'], true);
            $entityLabel = $isCompany ? 'Company' : (in_array($normalizedRoleName, ['customer', 'driver'], true) ? 'Driver' : 'Account');
            $sender = $isCompany ? (string) ($row['headquarter_name'] ?: $row['user_name']) : (string) ($row['user_name'] ?: 'Unknown account');
            ?>
            <tr data-created-at="<?= (int) (strtotime((string) ($row['created_at'] ?? '')) ?: 0) ?>">
                <td class="fw-bold txt-neon-cyan">#ACCT-<?= (int) $row['user_id'] ?></td>
                <td><div class="fw-semibold text-white"><?= moderator_escape($sender) ?></div><div class="text-white-50 small"><?= moderator_escape((string) ($row['email'] ?? 'Not provided')) ?></div></td>
                <td><span class="badge rounded-pill bg-info bg-opacity-10 text-info border border-info border-opacity-25 px-2 py-1"><?= moderator_escape($entityLabel) ?> Verification</span><?php if ((int) ($row['license_conflict_count'] ?? 0) > 0): ?><span class="badge bg-warning text-dark ms-1">Duplicate license</span><?php endif; ?></td>
                <td class="text-white-50"><?= moderator_escape(moderator_format_date((string) $row['created_at'])) ?></td>
                <td><span class="request-status request-status-pending">Pending</span></td>
                <td class="text-end"><div class="d-flex gap-2 justify-content-end">
                    <button type="button" class="btn-action js-view-account-request" title="View Details" aria-label="View details for account ACCT-<?= (int) $row['user_id'] ?>" data-user-id="<?= (int) $row['user_id'] ?>" data-role-name="<?= moderator_escape($roleName) ?>"><i class="bi bi-eye-fill"></i></button>
                </div></td>
            </tr>
        <?php endforeach; ?>
        <?php foreach ($carLicenseRows as $row): ?>
            <?php $carSender = (string) ($row['user_name'] ?: $row['car_owner_name'] ?: 'Unknown owner'); ?>
            <tr data-created-at="<?= (int) (strtotime((string) ($row['created_at'] ?? '')) ?: 0) ?>">
                <td class="fw-bold txt-neon-cyan">#CAR-<?= (int) $row['car_license_id'] ?></td>
                <td><div class="fw-semibold text-white"><?= moderator_escape($carSender) ?></div><div class="text-white-50 small"><?= moderator_escape((string) ($row['car_plate_number'] ?? '')) ?><?= !empty($row['email']) ? ' · ' . moderator_escape((string) $row['email']) : '' ?></div></td>
                <td><span class="badge rounded-pill bg-info bg-opacity-10 text-info border border-info border-opacity-25 px-2 py-1">Car License Review</span><?php if ((int) $row['conflict_count'] > 0): ?><span class="badge bg-warning text-dark ms-1">Duplicate plate</span><?php endif; ?></td>
                <td class="text-white-50"><?= moderator_escape(moderator_format_date((string) $row['created_at'])) ?></td>
                <td><span class="request-status request-status-pending">Pending</span></td>
                <td class="text-end"><div class="d-flex gap-2 justify-content-end">
                    <button type="button" class="btn-action js-view-car-license" title="View Details" aria-label="View details for car license #<?= (int) $row['car_license_id'] ?>" data-car-license-id="<?= (int) $row['car_license_id'] ?>"><i class="bi bi-eye-fill"></i></button>
                </div></td>
            </tr>
        <?php endforeach; ?>
        <?php foreach ($requestRows as $row): ?>
            <?php
            $rowStatus = strtolower((string) ($row['status'] ?? 'pending'));
            $requestTypeName = strtolower((string) ($row['request_type'] ?? ''));
            $sender = (string) ($row['sender_entity'] ?: $row['sender_name'] ?: 'Unknown sender');
            $payment = $paymentReviewByRequest[(int) $row['request_id']] ?? null;
            $paymentState = strtolower((string) ($payment['status'] ?? ''));
            $requestDetails = json_decode((string) ($row['request_details'] ?? ''), true);
            $requestDetails = is_array($requestDetails) ? $requestDetails : [];
            $requiresRegistrationPayment = $requestTypeName === 'hq_registration' && request_registration_payment_required($requestDetails);
            ?>
            <tr data-created-at="<?= (int) (strtotime((string) ($row['created_at'] ?? '')) ?: 0) ?>">
                <td class="fw-bold txt-neon-cyan">#<?= moderator_escape((string) $row['reference_code']) ?></td>
                <td><div class="fw-semibold text-white"><?= moderator_escape($sender) ?></div><div class="text-white-50 small"><?= moderator_escape((string) ($row['location'] ?? 'Location not provided')) ?></div></td>
                <td><span class="badge rounded-pill bg-info bg-opacity-10 text-info border border-info border-opacity-25 px-2 py-1"><?= moderator_escape(request_type_short_label((string) $row['request_type'])) ?></span></td>
                <td class="text-white-50"><?= moderator_escape(moderator_format_date((string) $row['created_at'])) ?></td>
                <td><span class="request-status <?= moderator_escape(request_status_class($rowStatus)) ?>"><?= moderator_escape(request_status_label($rowStatus)) ?></span><?php if ($requestTypeName === 'hq_registration'): ?><div class="small mt-1"><span class="badge rounded-pill <?= $paymentState === 'received' ? 'bg-success' : ($paymentState === 'payer_reported_paid' ? 'bg-warning text-dark' : 'bg-secondary') ?>"><?= moderator_escape(match ($paymentState) { 'received' => 'Payment received', 'payer_reported_paid' => 'Payment needs verification', 'rejected' => 'Payment not received', 'cancelled' => 'Payment cancelled', 'pending_payment' => 'Awaiting payer', default => $requiresRegistrationPayment ? 'Payment record missing' : 'No payment required' }) ?></span></div><?php endif; ?></td>
                <td class="text-end"><div class="d-flex gap-2 justify-content-end">
                    <button type="button" class="btn-action js-view-request" title="View Details" aria-label="View details for request <?= moderator_escape((string) $row['reference_code']) ?>" data-request-id="<?= (int) $row['request_id'] ?>"><i class="bi bi-eye-fill"></i></button>
                </div></td>
            </tr>
        <?php endforeach; ?>
        <?php foreach ($paymentRows as $paymentRow): ?>
            <?php $paymentId = (int) $paymentRow['payment_transfer_id']; $paymentState = (string) $paymentRow['status']; $paymentStatus = in_array($paymentState, ['pending_payment', 'payer_reported_paid'], true) ? 'pending' : ($paymentState === 'received' ? 'approved' : 'rejected'); $isPayer = ((int) ($paymentRow['payer_user_id'] ?? 0) === (int) ($_SESSION['user_id'] ?? 0)) || (strtolower((string) ($_SESSION['user_email'] ?? '')) !== '' && strtolower((string) ($_SESSION['user_email'] ?? '')) === strtolower((string) ($paymentRow['payer_email'] ?? ''))); $isRegistrationPayment = (string) ($paymentRow['payment_type'] ?? '') === 'hq_registration'; $registrationCanComplete = in_array(strtolower(trim((string) ($paymentRow['registration_request_status'] ?? ''))), ['pending', 'approved'], true); ?>
            <tr data-created-at="<?= (int) (strtotime((string) ($paymentRow['created_at'] ?? '')) ?: 0) ?>">
                <td class="fw-bold txt-neon-cyan">#PAY-<?= $paymentId ?></td>
                <td><div class="fw-semibold text-white"><?= moderator_escape((string) ($paymentRow['payer_hq_name'] ?: $paymentRow['payer_name'] ?: 'Company payer')) ?></div><div class="text-white-50 small"><?= moderator_escape((string) ($paymentRow['description'] ?? '')) ?></div><div class="text-white-50 small"><?= number_format((float) $paymentRow['amount_mmk'], 2) ?> MMK · <?= moderator_escape((string) ($paymentRow['payer_email'] ?? '')) ?></div></td>
                <td><span class="badge rounded-pill bg-info bg-opacity-10 text-info border border-info border-opacity-25 px-2 py-1">Payment Verification</span><div class="text-white-50 small mt-1"><?= moderator_escape(match ((string) $paymentRow['payment_type']) { 'hq_station_package' => 'Company Station Package', default => 'Company Service Extension' }) ?></div></td>
                <td class="text-white-50"><?= moderator_escape(moderator_format_date((string) $paymentRow['created_at'])) ?></td>
                <td><span class="request-status <?= moderator_escape(request_status_class($paymentStatus)) ?>"><?= moderator_escape(request_status_label($paymentStatus)) ?></span></td>
                <td class="text-end"><div class="d-flex gap-2 justify-content-end align-items-center">
                    <button type="button" class="btn-action js-view-payment" title="View Details" aria-label="View details for payment #PAY-<?= $paymentId ?>"
                        data-payment-id="<?= $paymentId ?>" data-payment-type="Company Service Extension"
                        data-payment-status="<?= moderator_escape($paymentState) ?>" data-payment-name="<?= moderator_escape((string) ($paymentRow['payer_hq_name'] ?: $paymentRow['payer_name'] ?: 'Company payer')) ?>"
                        data-payment-email="<?= moderator_escape((string) ($paymentRow['payer_email'] ?? '')) ?>" data-payment-description="<?= moderator_escape((string) ($paymentRow['description'] ?? '')) ?>"
                        data-payment-amount="<?= moderator_escape(number_format((float) $paymentRow['amount_mmk'], 2)) ?>" data-payment-date="<?= moderator_escape(moderator_format_date((string) $paymentRow['created_at'])) ?>"
                        data-payment-method="<?= moderator_escape((string) ($paymentRow['payment_method'] ?? 'Not provided')) ?>" data-receipt-name="<?= moderator_escape((string) ($paymentRow['receipt_name'] ?? '')) ?>"
                        data-receipt-mime="<?= moderator_escape((string) ($paymentRow['receipt_mime_type'] ?? '')) ?>" data-is-payer="<?= $isPayer ? 'yes' : 'no' ?>"><i class="bi bi-eye-fill"></i></button>
                    <?php if ($paymentState === 'payer_reported_paid' && !$isPayer && (!$isRegistrationPayment || $registrationCanComplete)): ?><div class="d-none" id="paymentActions<?= $paymentId ?>">
                        <form action="/php/Payment/action.php" method="POST" class="m-0 flex-fill"><input type="hidden" name="csrf_token" value="<?= moderator_escape((string) ($_SESSION['payment_transfer_csrf'] ?? '')) ?>"><input type="hidden" name="transfer_id" value="<?= $paymentId ?>"><input type="hidden" name="action" value="confirm_received"><input type="hidden" name="return_to" value="/php/Moderators(UnderAdmin)/view_requests(M).php"><button type="submit" class="btn btn-success w-100" onclick="return confirm('Confirm receipt only after checking your bank or wallet records.')">Approve</button></form>
                        <form action="/php/Payment/action.php" method="POST" class="m-0 flex-fill"><input type="hidden" name="csrf_token" value="<?= moderator_escape((string) ($_SESSION['payment_transfer_csrf'] ?? '')) ?>"><input type="hidden" name="transfer_id" value="<?= $paymentId ?>"><input type="hidden" name="action" value="mark_not_received"><input type="hidden" name="return_to" value="/php/Moderators(UnderAdmin)/view_requests(M).php"><input type="hidden" name="decision_note" value="Payment not verified by Moderator."><button type="submit" class="btn btn-danger w-100" onclick="return confirm('Record that the payment was not received or could not be verified?')">Reject</button></form>
                    </div><?php endif; ?>
                </div></td>
            </tr>
        <?php endforeach; ?>
    </tbody></table></div></div></div>
    </div>
</main>

<div class="modal fade glass-modal request-detail-modal" id="paymentDetailsModal" tabindex="-1" aria-labelledby="paymentDetailsTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-xl"><div class="modal-content"><div class="modal-header"><h5 class="modal-title fw-bold" id="paymentDetailsTitle"><i class="bi bi-receipt-cutoff txt-neon-cyan me-2"></i> Payment Verification Details</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body" id="paymentDetailsBody"></div><div class="modal-footer" id="paymentDetailsFooter"><button type="button" class="btn btn-outline-secondary rounded-3 text-white-50 border-secondary border-opacity-20" data-bs-dismiss="modal">Close</button></div></div></div></div>

<form action="request_action(M).php" method="POST" id="approveRequestForm" class="d-none"><input type="hidden" name="request_id" id="approveRequestId"><input type="hidden" name="action" value="approve"><input type="hidden" name="return_to" value="view_requests(M).php"></form>

<div class="modal fade glass-modal request-detail-modal" id="viewReqModal" tabindex="-1" aria-labelledby="viewReqTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-xl"><div class="modal-content"><div class="modal-header"><h5 class="modal-title fw-bold" id="viewReqTitle"><i class="bi bi-file-earmark-text txt-neon-cyan me-2"></i> Request Details</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body" id="requestDetailsBody"><div class="text-center text-white-50 py-5"><div class="spinner-border spinner-border-sm txt-neon-cyan mb-2" role="status"></div><div>Loading request details…</div></div></div><div class="modal-footer" id="requestDetailsFooter"><button type="button" class="btn btn-outline-secondary rounded-3 text-white-50 border-secondary border-opacity-20 w-100" data-bs-dismiss="modal">Close</button></div></div></div></div>

<div class="modal fade glass-modal" id="rejectReqModal" tabindex="-1" aria-labelledby="rejectReqModalLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title fw-bold" id="rejectReqModalLabel"><i class="bi bi-shield-x text-danger me-2"></i> Reject Request</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div><form action="request_action(M).php" method="POST" id="rejectReqForm"><div class="modal-body d-flex flex-column gap-3"><input type="hidden" name="request_id" id="rejectRequestId"><input type="hidden" name="user_id" id="rejectUserId"><input type="hidden" name="action" value="reject"><input type="hidden" name="return_to" value="view_requests(M).php"><div class="small text-white-50">Target request: <span class="txt-neon-cyan fw-semibold" id="rejectRequestReference">—</span></div><div class="small text-white-50">Sender: <span class="text-white" id="rejectRequestSender">—</span></div><div><label class="form-label text-white-50 small" for="rejectReason">Reason for rejection</label><textarea class="form-control glass-input" name="reason" id="rejectReason" rows="5" maxlength="2000" placeholder="Explain why this request is being rejected..." required style="resize:none;"></textarea></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-3 text-white-50 border-secondary border-opacity-20" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger">Confirm Rejection</button></div></form></div></div></div>

<?php $moderatorActiveNav = 'requests'; include __DIR__ . '/includes/moderator_offcanvas.php'; ?>
<footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map &amp; Pump | All Rights Reserved</footer>
<?php include __DIR__ . '/includes/moderator_footer_scripts.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const details = window.createAdminModalController(document.getElementById('viewReqModal'));
    const detailsTitle = document.getElementById('viewReqTitle');
    const reject = window.createAdminModalController(document.getElementById('rejectReqModal'));
    const body = document.getElementById('requestDetailsBody');
    const footer = document.getElementById('requestDetailsFooter');
    const rejectForm = document.getElementById('rejectReqForm');
    const rejectId = document.getElementById('rejectRequestId');
    const rejectUserId = document.getElementById('rejectUserId');
    const rejectReference = document.getElementById('rejectRequestReference');
    const rejectSender = document.getElementById('rejectRequestSender');
    const rejectReason = document.getElementById('rejectReason');
    const approveId = document.getElementById('approveRequestId');
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
    const date = value => value ? new Date(String(value).replace(' ', 'T')).toLocaleString() : 'Not available';
    const setFooter = html => { footer.innerHTML = html || ''; };
    const fileLink = (path, label) => path ? '<a href="' + esc(path) + '" target="_blank" rel="noopener" class="txt-neon-blue text-decoration-none"><i class="bi bi-box-arrow-up-right me-1"></i>' + esc(label || 'Open file') + '</a>' : '<span class="text-white-50">Not uploaded</span>';
    const isImagePath = (path) => /\.(png|jpe?g|gif|webp|bmp|svg)(\?.*)?$/i.test(String(path || ''));
    const initialsOf = (name) => { const parts = String(name || '').trim().split(/\s+/).filter(Boolean); if (!parts.length) return '?'; return (parts[0][0] + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase(); };
    const docCard = (path, label, icon = 'bi-file-earmark-text') => {
        if (!path) return '<div class="acct-doc-card"><div class="acct-doc-empty"><i class="bi bi-file-earmark-x"></i></div><div class="acct-doc-label">Not uploaded</div></div>';
        const inner = isImagePath(path) ? '<img src="' + esc(path) + '" alt="' + esc(label) + '" class="acct-doc-thumb" loading="lazy">' : '<div class="acct-doc-empty"><i class="bi ' + icon + '"></i></div>';
        return '<a href="' + esc(path) + '" target="_blank" rel="noopener" class="acct-doc-card" title="' + esc(label) + '">' + inner + '<div class="acct-doc-label">' + esc(label) + '</div></a>';
    };
    const markUnavailableDocImages = root => root.querySelectorAll('img.acct-doc-thumb').forEach(image => {
        image.addEventListener('error', () => {
            const card = image.closest('.acct-doc-card');
            const placeholder = document.createElement('div');
            placeholder.className = 'acct-doc-empty';
            const icon = document.createElement('i');
            icon.className = 'bi bi-file-earmark-x';
            placeholder.appendChild(icon);
            image.replaceWith(placeholder);
            if (card) {
                card.removeAttribute('href');
                card.removeAttribute('target');
                card.removeAttribute('rel');
                card.setAttribute('aria-disabled', 'true');
                const label = card.querySelector('.acct-doc-label');
                if (label) label.textContent = 'File unavailable';
            }
        }, { once: true });
    });
    const fieldRow = (icon, label, value) => '<div class="acct-field-row"><div class="acct-field-icon"><i class="bi ' + icon + '"></i></div><div class="min-width-0"><div class="request-detail-label">' + esc(label) + '</div><div class="request-detail-value">' + (value || '<span class="text-white-50">Not provided</span>') + '</div></div></div>';
    const licenseConflictMarkup = (current, conflicts) => {
        if (!current || !Array.isArray(conflicts) || !conflicts.length) return '';
        const recordCard = (title, record, currentRecord = false) => '<div class="col-12 col-lg-6"><section class="acct-section h-100"><div class="acct-section-title">' + esc(title) + '</div>' + fieldRow('bi-person', 'Driver', esc(record.user_name || 'Name unavailable')) + fieldRow('bi-envelope', 'Email', esc(record.email || 'Not provided')) + fieldRow('bi-hash', 'License number', esc(record.license_number || 'Not provided')) + fieldRow('bi-card-checklist', 'Decision status', esc(currentRecord ? (record.status || 'pending') : (record.license_status || 'pending'))) + '<div class="request-detail-label mb-2">License and face images</div><div class="acct-doc-grid">' + docCard(record.license_image, 'License image', 'bi-card-image') + docCard(record.face_image, 'Face image', 'bi-person-bounding-box') + '</div></section></div>';
        const comparisons = conflicts.map(record => recordCard('Matching record · ACCT-' + record.user_id, record)).join('');
        const decisions = '<form action="license_conflict_action(M).php" method="POST" class="acct-section mt-3" data-license-conflict-form><input type="hidden" name="license_type" value="driver"><input type="hidden" name="record_id" value="' + esc(current.license_id) + '"><label class="form-label text-white-50 small" for="driver-license-decision">Decision for matching licenses</label><select class="form-select glass-select" id="driver-license-decision" name="decision" data-conflict-decision><option value="allow_all">Allow all matching licenses</option><option value="block_current">Block this license; allow the other matches</option><option value="block_all">Block all matching licenses</option></select><div class="mt-3" data-conflict-reason hidden><label class="form-label text-white-50 small" for="driver-license-reason">Reason required for blocking</label><textarea class="form-control glass-input" id="driver-license-reason" name="reason" rows="3" maxlength="2000" placeholder="Explain why the duplicate license record(s) are blocked."></textarea></div><button type="submit" class="btn btn-warning mt-3">Confirm license decision and notify affected drivers</button></form>';
        return '<div class="alert alert-warning d-flex align-items-center justify-content-between gap-3 flex-wrap"><span><i class="bi bi-exclamation-triangle-fill me-2"></i>Same driver-license number found on ' + conflicts.length + ' other record(s). You can decide each matching set independently.</span><button type="button" class="btn btn-sm btn-outline-warning" data-license-compare-toggle aria-expanded="false"><i class="bi bi-layout-split me-1"></i>Compare</button></div><div data-license-comparison hidden><div class="row g-3">' + recordCard('Current · ACCT-' + current.user_id, current, true) + comparisons + '</div>' + decisions + '</div>';
    };
    const rejectTarget = (targetType, id, label, sender) => {
        rejectForm.action = targetType === 'account' ? 'account_review_action(M).php' : 'request_action(M).php';
        rejectId.value = targetType === 'request' ? id : '';
        rejectUserId.value = targetType === 'account' ? id : '';
        rejectReference.textContent = label || 'Selected request';
        rejectSender.textContent = sender || 'Unknown sender';
        rejectReason.value = '';
        reject.show();
    };
    document.addEventListener('click', event => {
        const target = event.target instanceof Element ? event.target : null;
        if (!target) return;

        const approveButton = target.closest('.js-request-approve');
        if (approveButton) {
            event.preventDefault();
            approveId.value = approveButton.dataset.requestId || '';
            document.getElementById('approveRequestForm')?.requestSubmit();
            return;
        }

        const rejectButton = target.closest('.js-request-reject');
        if (rejectButton) {
            event.preventDefault();
            rejectTarget(
                'request',
                rejectButton.dataset.requestId || '',
                rejectButton.dataset.reference || 'Selected request',
                rejectButton.dataset.sender || 'Unknown sender'
            );
        }
    });
    document.addEventListener('click', async event => {
        const button = event.target.closest('.js-view-request');
        if (!button) return;
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
            const receipt = r.receipt_image ? '<a href="' + esc(r.receipt_image) + '" target="_blank" rel="noopener" class="d-inline-block text-decoration-none"><img src="' + esc(r.receipt_image) + '" alt="Payment receipt proof" style="display:block;width:100%;max-width:260px;max-height:220px;object-fit:contain;border-radius:12px;border:1px solid var(--glass-border);background:rgba(255,255,255,.04);padding:.35rem;"><span class="txt-neon-cyan d-inline-flex align-items-center gap-1 mt-2"><i class="bi bi-receipt"></i>Open ' + esc(r.receipt_type || 'payment') + ' receipt</span></a>' : '<span class="text-white-50">No receipt proof uploaded</span>';
            let details = {};
            try { details = JSON.parse(r.request_details || '{}'); } catch (_) {}
            const detailLabels = {company_address:'Company address', owner_phone:'Owner phone', company_phone:'Company phone', license_id:'License ID', renewal_license_number:'Renewal license number', license_expiry:'License expiry', payment_method:'Payment method', bank_name:'Bank name', requested_station_count:'Stations requested'};
            const readableDetails = Object.entries(detailLabels).filter(([key]) => details[key] !== undefined && details[key] !== null && String(details[key]).trim() !== '').map(([key,label]) => '<div class="mb-2"><div class="request-detail-label">' + label + '</div><div class="request-detail-value">' + esc(details[key]) + '</div></div>').join('') || esc(r.request_details || 'No additional details provided.');
            body.innerHTML = '<div class="row g-4"><div class="col-12 col-md-6"><h6 class="fw-semibold text-white mb-3">SUBMITTER INFORMATION</h6><div class="mb-3"><div class="request-detail-label">Company</div><div class="request-detail-value">' + esc(r.sender_entity || r.hq_company_name || 'Not available') + '</div></div><div class="mb-3"><div class="request-detail-label">Authorized Official</div><div class="request-detail-value">' + esc(r.sender_name || r.submitter_username || 'Not available') + ' ' + (r.sender_email ? '(' + esc(r.sender_email) + ')' : '') + '</div></div><div class="mb-3"><div class="request-detail-label">Location</div><div class="request-detail-value">' + esc(r.location || 'Not provided') + '</div></div></div><div class="col-12 col-md-6"><h6 class="fw-semibold text-white mb-3">REQUEST INFORMATION</h6><div class="mb-3"><div class="request-detail-label">Type</div><div class="request-detail-value">' + esc(r.request_type_label) + '</div></div><div class="mb-3"><div class="request-detail-label">Status</div><div class="request-detail-value">' + esc(r.status_label) + '</div></div><div class="mb-3"><div class="request-detail-label">Submitted</div><div class="request-detail-value">' + esc(date(r.created_at)) + '</div></div><div class="mb-3"><div class="request-detail-label">Verification Document</div><div class="request-detail-value">' + doc + '</div></div><div class="mb-3"><div class="request-detail-label">Payment Receipt Proof</div><div class="request-detail-value">' + receipt + '</div></div></div><div class="col-12"><div class="request-detail-label mb-2">Request Details</div><div class="request-detail-value p-3 rounded-3" style="background:rgba(0,244,255,.04);border:1px solid var(--glass-border)">' + readableDetails + '</div></div>' + (r.review_reason ? '<div class="col-12"><div class="request-detail-label">Review Message</div><div class="request-detail-value">' + esc(r.review_reason) + '</div></div>' : '') + '</div>';
            const payment = r.registration_payment || null;
            if (payment) {
                const receiptLink = payment.receipt_name ? '<a href="/php/Payment/receipt.php?id=' + encodeURIComponent(payment.payment_transfer_id) + '&view=1" target="_blank" rel="noopener">' + esc(payment.receipt_name) + '</a>' : 'No screenshot uploaded';
                const registrationFlowNote = payment.status === 'received'
                    ? 'Payment is recorded as received.'
                    : 'Confirming receipt completes the company registration. No separate registration approval is needed.';
                body.insertAdjacentHTML('afterbegin', '<div class="mb-3 p-3 rounded-3" style="background:rgba(255,193,7,.06);border:1px solid rgba(255,193,7,.25)"><div class="request-detail-label">Registration payment</div><div class="request-detail-value fw-semibold">' + esc(payment.status) + ' · ' + esc(Number(payment.amount_mmk).toLocaleString()) + ' MMK</div><div class="request-detail-value small mt-1">' + esc(payment.payment_method || 'Method not provided') + ' · ' + receiptLink + '</div><div class="small text-white-50 mt-2">A screenshot is evidence for review, not proof of receipt. ' + registrationFlowNote + '</div>' + (payment.is_current_user_payer ? '<div class="small text-warning mt-2">You submitted this payment. Another reviewer must confirm it.</div>' : '') + '</div>');
            }
            if (String(r.status || '').toLowerCase() === 'pending') {
                setFooter('<form action="request_action(M).php" method="POST" class="m-0 flex-fill"><input type="hidden" name="request_id" value="' + esc(r.request_id) + '"><input type="hidden" name="action" value="approve"><input type="hidden" name="return_to" value="view_requests(M).php"><button type="submit" class="btn btn-success w-100"><i class="bi bi-check-lg me-1"></i>Approve</button></form><button type="button" class="btn btn-danger flex-fill js-modal-reject"><i class="bi bi-x-lg me-1"></i>Reject</button>');
                footer.querySelector('.js-modal-reject').addEventListener('click', () => rejectTarget('request', r.request_id, '#' + (r.reference_code || r.request_id), esc(r.sender_name || 'Unknown')));
            }
        } catch (err) {
            body.innerHTML = '<div class="alert alert-danger">' + esc(err.message) + '</div>';
        }
    });

    document.addEventListener('click', async event => {
        const button = event.target.closest('.js-view-account-request');
        if (!button) return;
        event.preventDefault();
        body.innerHTML = '<div class="text-center text-white-50 py-5"><span class="spinner-border spinner-border-sm me-2"></span>Loading account details...</div>';
        setFooter('');
        details.show();
        try {
            const res = await fetch('user_details(M).php?id=' + encodeURIComponent(button.dataset.userId) + '&context=request');
            const p = await res.json();
            if (!res.ok || !p.success) throw Error(p.message || 'Unable to load account details.');
            const u = p.user || {};
            const l = p.license;
            const h = p.headquarter;
            const licenseExpiryDate = String((l && l.license_last_valid_date) || '').slice(0, 10);
            const currentDate = new Date();
            const today = [currentDate.getFullYear(), String(currentDate.getMonth() + 1).padStart(2, '0'), String(currentDate.getDate()).padStart(2, '0')].join('-');
            const licenseReviewWarning = licenseExpiryDate && licenseExpiryDate < today
                ? '<div class="alert alert-warning py-2 mb-3" role="status"><i class="bi bi-exclamation-triangle-fill me-2"></i>Driver license expired on ' + esc(licenseExpiryDate) + '. Review a current license before recording an account decision.</div>'
                : '';
            const label = 'ACCT-' + esc(u.user_id || button.dataset.userId);
            const isPendingAccount = String(u.status || '').toLowerCase() === 'pending_verification';
            const userName = esc(u.user_name || 'Unknown account');
            const userEmail = esc(u.email || 'Not provided');
            const roleNameSource = String(u.role_name || '');
            const normalizedRoleName = roleNameSource.trim().toLowerCase();
            const roleName = esc(['customer', 'driver'].includes(normalizedRoleName) ? 'Driver' : (['hq', 'headquarter', 'headquarters', 'company'].includes(normalizedRoleName) ? 'Company' : (roleNameSource || 'Account')));
            const licenseBlock = l ? '<div class="acct-section"><div class="acct-section-title"><i class="bi bi-card-list"></i> Driver License Details</div>' + fieldRow('bi-hash', 'License Number', esc(l.license_number || 'Not provided')) + fieldRow('bi-person-vcard', 'License Name', esc(l.license_name || 'Not provided')) + fieldRow('bi-telephone', 'Phone', esc(l.phone_number || 'Not provided')) + fieldRow('bi-file-earmark', 'NRC', esc(l.nrc_number || 'Not provided')) + fieldRow('bi-calendar', 'Date of Birth', esc(l.date_of_birth || 'Not provided')) + fieldRow('bi-droplet', 'Blood Type', esc(l.blood_type || 'Not provided')) + fieldRow('bi-check-circle', 'License Valid Until', esc(l.license_last_valid_date || 'Not provided')) + '<div class="acct-field-row"><div class="acct-field-icon"><i class="bi bi-image"></i></div><div class="min-width-0"><div class="request-detail-label">License Documents</div><div class="request-detail-value"><div class="acct-doc-grid">' + docCard(l.license_image, 'License Image', 'bi-file-earmark-image') + docCard(l.face_image, 'Face Image', 'bi-person-circle') + '</div></div></div></div></div>' : '<div class="acct-empty-note"><i class="bi bi-info-circle-fill"></i><span>No driver license has been linked to this account yet.</span></div>';
            const hqBlock = h ? '<div class="acct-section"><div class="acct-section-title"><i class="bi bi-building"></i> Company Details</div>' + fieldRow('bi-shop', 'Company Name', esc(h.company_name || u.headquarter_name || 'Not provided')) + fieldRow('bi-envelope', 'Company email', esc(h.headquarter_email || 'Not provided')) + fieldRow('bi-telephone', 'Contact Number', esc(h.contact_number || 'Not provided')) + fieldRow('bi-geo-alt', 'Location', esc(h.location || u.headquarter_location || 'Not provided')) + fieldRow('bi-file-earmark-text', 'Business License Number', esc(h.business_license || 'Not provided')) + fieldRow('bi-calendar-check', 'License Expiry', esc(h.business_license_expire_date || h.expire_at || 'Not provided')) + '<div class="acct-field-row"><div class="acct-field-icon"><i class="bi bi-image"></i></div><div class="min-width-0"><div class="request-detail-label">Company Documents</div><div class="request-detail-value"><div class="acct-doc-grid">' + docCard(h.company_logo, 'Company Logo', 'bi-image') + docCard(h.business_license_image, 'Business License', 'bi-file-earmark-pdf') + '</div></div></div></div><div class="acct-field-row"><div class="acct-field-icon"><i class="bi bi-chat-dots"></i></div><div class="min-width-0"><div class="request-detail-label">Company Bio</div><div class="request-detail-value">' + esc(h.headquarter_bio || 'Not provided') + '</div></div></div></div>' : '';
            body.innerHTML = '<div class="acct-profile-header"><div class="acct-avatar">' + initialsOf(u.user_name) + '</div><div class="acct-profile-meta"><div class="acct-profile-name">' + userName + '</div><div class="acct-profile-sub">' + userEmail + '</div></div><div class="acct-badge acct-badge-pending"><i class="bi bi-clock-fill"></i> Pending Verification</div><div class="acct-badge acct-badge-role"><i class="bi bi-tag-fill"></i> ' + roleName + '</div></div><div class="acct-section"><div class="acct-section-title"><i class="bi bi-person-check"></i> Account Information</div>' + fieldRow('bi-person', 'Name', userName) + fieldRow('bi-envelope', 'Email Address', userEmail) + fieldRow('bi-tag', 'Role', roleName + ' (#' + esc(u.role_id) + ')') + fieldRow('bi-calendar', 'Account Created', date(u.created_at)) + '</div>' + licenseReviewWarning + licenseConflictMarkup(l, payload.license_conflicts || []) + licenseBlock + hqBlock;
            markUnavailableDocImages(body);
            body.querySelector('[data-license-compare-toggle]')?.addEventListener('click', event => {
                const button = event.currentTarget;
                const comparison = body.querySelector('[data-license-comparison]');
                if (!comparison) return;
                comparison.hidden = !comparison.hidden;
                button.setAttribute('aria-expanded', String(!comparison.hidden));
                button.innerHTML = comparison.hidden ? '<i class="bi bi-layout-split me-1"></i>Compare' : '<i class="bi bi-eye-slash me-1"></i>Hide comparison';
                markUnavailableDocImages(comparison);
            });
            body.querySelector('[data-conflict-decision]')?.addEventListener('change', event => {
                const reasonField = body.querySelector('[data-conflict-reason]');
                const reasonInput = reasonField?.querySelector('textarea');
                const blocking = event.currentTarget.value !== 'allow_all';
                if (reasonField) reasonField.hidden = !blocking;
                if (reasonInput) reasonInput.required = blocking;
            });
            if (isPendingAccount) {
                setFooter('<form action="account_review_action(M).php" method="POST" class="m-0 flex-fill"><input type="hidden" name="user_id" value="' + esc(u.user_id) + '"><input type="hidden" name="action" value="approve"><input type="hidden" name="return_to" value="view_requests(M).php"><button type="submit" class="acct-btn acct-btn-approve w-100"><i class="bi bi-check-lg me-1"></i>Approve</button></form><button type="button" class="acct-btn acct-btn-reject w-100 js-modal-reject"><i class="bi bi-x-lg me-1"></i>Reject</button>');
                footer.querySelector('.js-modal-reject').addEventListener('click', () => rejectTarget('account', u.user_id, label, userName));
            }
        } catch (err) {
            body.innerHTML = '<div class="alert alert-danger">' + esc(err.message) + '</div>';
        }
    });

    document.addEventListener('click', async event => {
        const button = event.target.closest('.js-view-car-license');
        if (!button) return;
        detailsTitle.innerHTML = '<i class="bi bi-car-front txt-neon-cyan me-2"></i> Car License Review';
        body.innerHTML = '<div class="text-center text-white-50 py-5">Loading car-license details…</div>';
        setFooter('');
        details.show();
        try {
            const response = await fetch('../Admin(WebOwner)/car_license_details.php?id=' + encodeURIComponent(button.dataset.carLicenseId), { headers: { Accept: 'application/json' } });
            const payload = await response.json();
            if (!response.ok || !payload.success) throw new Error(payload.message || 'Could not load car-license details.');
            const car = payload.car || {};
            const conflicts = payload.conflicts || [];
            const owners = items => (items || []).map(owner => esc(owner.user_name || 'Name unavailable') + ' (' + esc(owner.email || 'Not provided') + ')').join('<br>') || '<span class="text-white-50">No driver is linked.</span>';
            const card = (title, record, ownerList = []) => '<div class="col-12 col-lg-6"><section class="acct-section h-100"><div class="acct-section-title">' + esc(title) + '</div>' + fieldRow('bi-person', 'Driver account(s)', owners(ownerList)) + fieldRow('bi-person-vcard', 'Registered owner', esc(record.car_owner_name || 'Not provided')) + fieldRow('bi-hash', 'Plate number', esc(record.car_plate_number || 'Not provided')) + fieldRow('bi-car-front', 'Vehicle', esc([record.vehicle_name, record.vehicle_type].filter(Boolean).join(' · ') || 'Not provided')) + fieldRow('bi-upc-scan', 'Chassis number', esc(record.chassis_number || 'Not provided')) + fieldRow('bi-card-checklist', 'Status', esc(record.status || 'pending')) + '<div class="request-detail-label mb-2">License images</div><div class="acct-doc-grid">' + docCard(record.front_image, 'Front image', 'bi-card-image') + docCard(record.back_image, 'Back image', 'bi-card-image') + '</div></section></div>';
            const decisionForm = '<form action="license_conflict_action(M).php" method="POST" class="acct-section mt-3" data-conflict-decision-form><input type="hidden" name="license_type" value="car"><input type="hidden" name="record_id" value="' + esc(car.car_license_id) + '"><label class="form-label text-white-50 small" for="car-license-decision">Decision for matching plates</label><select class="form-select glass-select" id="car-license-decision" name="decision" data-conflict-decision><option value="allow_all">Allow all matching plates</option><option value="block_current">Block this plate; allow the other matches</option><option value="block_all">Block all matching plates</option></select><div class="mt-3" data-conflict-reason hidden><label class="form-label text-white-50 small" for="car-license-reason">Reason required for blocking</label><textarea class="form-control glass-input" id="car-license-reason" name="reason" rows="3" maxlength="2000" placeholder="Explain why the duplicate car-license record(s) are blocked."></textarea></div><button type="submit" class="btn btn-warning mt-3">Confirm license decision and notify affected drivers</button></form>';
            const conflictMarkup = conflicts.length
                ? '<div class="alert alert-warning d-flex align-items-center justify-content-between gap-3 flex-wrap"><span><i class="bi bi-exclamation-triangle-fill me-2"></i>Same normalized plate found on ' + conflicts.length + ' other record(s).</span><button type="button" class="btn btn-sm btn-outline-warning" data-car-compare-toggle aria-expanded="false"><i class="bi bi-layout-split me-1"></i>Compare</button></div><div data-car-comparison hidden><div class="row g-3">' + card('Current · CAR-' + car.car_license_id, car, car.owners) + conflicts.map(match => card('Matching record · CAR-' + match.car_license_id, match, match.user_id ? [{ user_id: match.user_id, user_name: match.user_name, email: match.email }] : [])).join('') + '</div>' + decisionForm + '</div>'
                : '<div class="alert alert-success">No plate records match this search.</div>';
            body.innerHTML = '<div class="acct-section">' + fieldRow('bi-hash', 'Plate number', esc(car.car_plate_number || '')) + fieldRow('bi-person', 'Driver account(s)', owners(car.owners)) + fieldRow('bi-calendar', 'Submitted', esc(date(car.created_at))) + '</div>' + conflictMarkup;
            body.querySelector('[data-car-compare-toggle]')?.addEventListener('click', compareEvent => {
                const compareButton = compareEvent.currentTarget;
                const comparison = body.querySelector('[data-car-comparison]');
                if (!comparison) return;
                comparison.hidden = !comparison.hidden;
                compareButton.setAttribute('aria-expanded', String(!comparison.hidden));
                compareButton.innerHTML = comparison.hidden ? '<i class="bi bi-layout-split me-1"></i>Compare' : '<i class="bi bi-eye-slash me-1"></i>Hide comparison';
                markUnavailableDocImages(comparison);
            });
            body.querySelector('[data-conflict-decision]')?.addEventListener('change', decisionEvent => {
                const reasonField = body.querySelector('[data-conflict-reason]');
                const reasonInput = reasonField?.querySelector('textarea');
                const blocking = decisionEvent.currentTarget.value !== 'allow_all';
                if (reasonField) reasonField.hidden = !blocking;
                if (reasonInput) reasonInput.required = blocking;
            });
            markUnavailableDocImages(body);
            const carId = esc(car.car_license_id);
            const isPendingCar = ['pending', 'pending_verification'].includes(String(car.status || '').toLowerCase());
            setFooter(!isPendingCar
                ? ''
                : '<form action="car_license_review_action(M).php" method="POST" class="m-0 flex-fill"><input type="hidden" name="car_license_id" value="' + carId + '"><input type="hidden" name="action" value="approve"><input type="hidden" name="return_to" value="view_requests(M).php"><button type="submit" class="acct-btn acct-btn-approve w-100">Approve</button></form><form action="car_license_review_action(M).php" method="POST" class="m-0 flex-fill"><input type="hidden" name="car_license_id" value="' + carId + '"><input type="hidden" name="action" value="reject"><input type="hidden" name="return_to" value="view_requests(M).php"><button type="submit" class="acct-btn acct-btn-reject w-100">Reject</button></form>');
        } catch (error) {
            body.innerHTML = '<div class="alert alert-danger">' + esc(error.message) + '</div>';
        }
    });
});
</script>
<script>
(() => {
    const modal = window.createAdminModalController(document.getElementById('paymentDetailsModal'));
    const body = document.getElementById('paymentDetailsBody');
    const footer = document.getElementById('paymentDetailsFooter');
    const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
    document.addEventListener('click', event => {
        const button = event.target.closest('.js-view-payment');
        if (!button) return;
        const data = button.dataset;
        const receiptUrl = '/php/Payment/receipt.php?id=' + encodeURIComponent(data.paymentId) + '&view=1';
        let evidence = '<div class="text-white-50">No payment evidence was uploaded.</div>';
        if (data.receiptName && data.receiptMime.startsWith('image/')) {
            evidence = '<img src="' + receiptUrl + '" alt="Payment evidence: ' + escape(data.receiptName) + '" class="img-fluid rounded-3" style="display:block;max-width:100%;max-height:60vh;object-fit:contain;margin:auto;border:1px solid var(--glass-border);background:rgba(255,255,255,.04);padding:.35rem;">';
        } else if (data.receiptName && data.receiptMime === 'application/pdf') {
            evidence = '<iframe src="' + receiptUrl + '" title="Payment evidence: ' + escape(data.receiptName) + '" style="width:100%;height:60vh;border:1px solid var(--glass-border);border-radius:12px;background:#fff"></iframe>';
        } else if (data.receiptName) {
            evidence = '<div class="text-white-50">Preview is unavailable for this file type. <a href="' + receiptUrl + '" target="_blank" rel="noopener">Open ' + escape(data.receiptName) + '</a></div>';
        }
        const statusLabel = data.paymentStatus === 'payer_reported_paid' ? 'Payer reported paid · Awaiting independent verification' : (data.paymentStatus === 'received' ? 'Payment received' : 'Payment not received');
                body.innerHTML = '<div class="row g-4"><div class="col-12 col-lg-5"><h6 class="fw-semibold text-white mb-3" style="letter-spacing:.5px;font-size:.78rem;text-transform:uppercase">Payment Information</h6><div class="mb-3"><div class="request-detail-label">Payer / Company</div><div class="request-detail-value">' + escape(data.paymentName) + '</div></div><div class="mb-3"><div class="request-detail-label">Email</div><div class="request-detail-value">' + escape(data.paymentEmail || 'Not provided') + '</div></div><div class="mb-3"><div class="request-detail-label">Payment Type</div><div class="request-detail-value">' + escape(data.paymentType) + '</div></div><div class="mb-3"><div class="request-detail-label">Amount</div><div class="request-detail-value">' + escape(data.paymentAmount) + ' MMK</div></div><div class="mb-3"><div class="request-detail-label">Payment Method</div><div class="request-detail-value">' + escape(data.paymentMethod || 'Not provided') + '</div></div><div class="mb-3"><div class="request-detail-label">Submitted</div><div class="request-detail-value">' + escape(data.paymentDate) + '</div></div><div class="mb-3"><div class="request-detail-label">Status</div><div class="request-detail-value">' + escape(statusLabel) + '</div></div><div class="mb-3"><div class="request-detail-label">Description</div><div class="request-detail-value">' + escape(data.paymentDescription || 'No additional details provided.') + '</div></div></div><div class="col-12 col-lg-7"><h6 class="fw-semibold text-white mb-3" style="letter-spacing:.5px;font-size:.78rem;text-transform:uppercase">Payment Evidence</h6><div class="request-detail-value p-3 rounded-3" style="background:rgba(0,244,255,.04);border:1px solid var(--glass-border);min-height:220px">' + evidence + '</div><div class="small text-white-50 mt-2">A payer declaration or screenshot is evidence for review, not proof of received money.</div></div></div>';
        const actions = document.getElementById('paymentActions' + data.paymentId);
        footer.innerHTML = actions ? actions.innerHTML : (data.paymentStatus === 'payer_reported_paid' && data.isPayer === 'yes' ? '<div class="small text-warning me-auto">You submitted this payment. Another reviewer must review it.</div>' : '');
        modal.show();
    });
})();
</script>
<script>
(() => {
    const tableBody = document.querySelector('#requestQueueTable tbody');
    if (!tableBody) return;
    Array.from(tableBody.querySelectorAll('tr[data-created-at]'))
        .sort((first, second) => Number(second.dataset.createdAt || 0) - Number(first.dataset.createdAt || 0))
        .forEach((row) => tableBody.appendChild(row));
})();
</script>
</body>
</html>
