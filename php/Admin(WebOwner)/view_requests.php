<?php
require_once __DIR__ . '/admin_common.php';
require_once __DIR__ . '/request_common.php';
require_once __DIR__ . '/user_account_common.php';

admin_require_admin();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$flash = request_pull_flash();
$paymentFlash = $_SESSION['payment_transfer_flash'] ?? null;
unset($_SESSION['payment_transfer_flash']);
if (empty($_SESSION['payment_transfer_csrf'])) {
    $_SESSION['payment_transfer_csrf'] = bin2hex(random_bytes(32));
}
$accountReviewSuccess = $_SESSION['account_review_success'] ?? null;
unset($_SESSION['account_review_success']);
if (!is_array($accountReviewSuccess) || !in_array($accountReviewSuccess['action'] ?? '', ['approval', 'rejection'], true)) {
    $accountReviewSuccess = null;
}
$search = trim((string) ($_GET['search'] ?? ''));
$requestType = strtolower(trim((string) ($_GET['type'] ?? '')));
$status = strtolower(trim((string) ($_GET['status'] ?? 'pending')));
if (!in_array($status, ['pending', 'approved', 'reject'], true)) {
    $status = 'pending';
}

$dbTypes = admin_filter_request_types();
$allowedTypes = $dbTypes;
// Ensure common type keywords remain selectable even if table is empty.
foreach (['account', 'car_license', 'payment', 'hq_registration', 'station', 'new_station', 'new_station_registration', 'license', 'license_modification', 'deactivation', 'account_deactivation'] as $fallbackType) {
    if (!in_array($fallbackType, $allowedTypes, true)) {
        $allowedTypes[] = $fallbackType;
    }
}
sort($allowedTypes);
$requestTypeOptions = admin_request_type_filter_options($allowedTypes);

if ($requestType !== '' && !in_array($requestType, $allowedTypes, true)) {
    $requestType = '';
}
$summary = [
    'pending' => 0,
    'approved' => 0,
    'declined_flagged' => 0,
];
$requestRows = [];
$accountRows = [];
$carLicenseRows = [];
$dbError = null;

if (hq_db_available() && request_schema_ready()) {
    $summary = request_review_queue_summary('admin');
    $dbError = $summary['error'];

    $where = [];
    $params = [];
    $types = '';

    if ($search !== '') {
        $where[] = '(LOWER(r.reference_code) LIKE ? OR LOWER(r.sender_entity) LIKE ? OR LOWER(r.sender_name) LIKE ? OR LOWER(COALESCE(r.sender_email, \'\')) LIKE ?)';
        $searchLike = '%' . strtolower($search) . '%';
        array_push($params, $searchLike, $searchLike, $searchLike, $searchLike);
        $types .= 'ssss';
    }

    if ($requestType !== '' && $requestType !== 'payment') {
        // Exact keyword from DB, plus related aliases for grouped types.
        $typeValues = match ($requestType) {
            'account' => ['account'],
            'station', 'new_station', 'new_station_registration' => ['station', 'new_station', 'new_station_registration'],
            'license', 'license_modification' => ['license', 'license_modification'],
            'deactivation', 'account_deactivation' => ['deactivation', 'account_deactivation'],
            default => [$requestType],
        };
        $where[] = 'LOWER(r.request_type) IN (' . implode(',', array_fill(0, count($typeValues), '?')) . ')';
        foreach ($typeValues as $typeValue) {
            $params[] = $typeValue;
            $types .= 's';
        }
    }

    if ($status === 'reject') {
        $where[] = "LOWER(r.status) IN ('rejected', 'flagged')";
    } elseif ($status !== '') {
        $where[] = 'LOWER(TRIM(r.status)) = ?';
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
    r.request_details,
    r.created_at,
    r.submitter_user_id,
    r.headquarter_id
FROM system_requests r
LEFT JOIN users u ON u.user_id = r.submitter_user_id
SQL;

    if (in_array($requestType, ['account', 'car_license', 'payment'], true)) {
        $requestRows = [];
    } else {
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
    }

} else {
    $dbError = 'The request database is temporarily unavailable.';
}

$paymentRows = in_array($requestType, ['', 'payment'], true)
    ? request_payment_verification_rows('admin', $search, $status, true)
    : [];
foreach ($requestRows as &$requestRow) {
    $requestRow['business_license_conflict'] = request_business_license_match_for_request($requestRow);
}
unset($requestRow);

// Account verification is stored on users rather than system_requests. Keep it
// available even when the separate system-request registry is unavailable.
if (hq_db_available() && ($requestType === '' || $requestType === 'account')) {
    $accountRows = pending_account_rows($search, $status);
}
if (hq_db_available() && ($requestType === '' || $requestType === 'car_license')) {
    $carLicenseRows = pending_car_license_rows($search, $status);
}
$paymentReviewByRequest = request_payment_review_map($requestRows);

// The page summary and the unfiltered all-types list must describe the same
// pending queue. Surface a clear warning and leave a server-side breadcrumb
// if any row query fails after the aggregate counters succeeded.
if ($dbError === null && $requestType === '' && $search === '' && $status === 'pending') {
    $listedPendingRows = count($accountRows) + count($carLicenseRows) + count($requestRows) + count($paymentRows);
    if ($listedPendingRows !== (int) ($summary['pending'] ?? 0)) {
        error_log(sprintf(
            '[Request registry] Pending list mismatch: summary=%d rows=%d',
            (int) ($summary['pending'] ?? 0),
            $listedPendingRows
        ));
        $dbError = 'Some pending requests could not be loaded. Refresh the page or check the server log for the affected request type.';
    }
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
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&amp;display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="icon" type="image/png" href="/images/Logo.png">
    <link rel="stylesheet" href="/css/Admin.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .request-status { display:inline-flex; align-items:center; gap:.45rem; color:rgba(255,255,255,.68); font-size:.8rem; white-space:nowrap; }
        .request-status::before { content:''; width:8px; height:8px; border-radius:50%; display:inline-block; }
        .request-status-pending::before { background:#ffc107; box-shadow:0 0 8px #ffc107; }
        .request-status-approved::before { background:#79aaff; box-shadow:0 0 8px #79aaff; }
        .request-status-rejected::before { background:#ff4d6a; box-shadow:0 0 8px #ff4d6a; }
        .request-status-flagged::before { background:#ff9f43; box-shadow:0 0 8px #ff9f43; }
        .request-flash { border-radius:14px; background:rgba(10,22,44,.82); border:1px solid var(--glass-border); color:var(--text-primary); }
        .request-modal-table { --bs-table-bg:transparent; --bs-table-color:var(--text-primary); --bs-table-border-color:var(--glass-border); }
        .request-modal-table th { color:var(--text-muted); font-size:.72rem; text-transform:uppercase; letter-spacing:.05em; }
        .request-detail-label { color:var(--text-muted); font-size:.72rem; text-transform:uppercase; letter-spacing:.07em; }
        .request-detail-value { color:var(--text-primary); word-break:break-word; white-space:pre-wrap; }
        .request-detail-scroll {
            max-height: min(28vh, 220px);
            overflow-y: auto;
            overscroll-behavior: contain;
            scrollbar-width: thin;
            scrollbar-color: rgba(121,170,255,.45) rgba(4,12,28,.9);
        }
        .request-detail-scroll::-webkit-scrollbar { width:8px; }
        .request-detail-scroll::-webkit-scrollbar-thumb {
            background:rgba(121,170,255,.35); border-radius:999px;
        }
        .request-detail-modal .modal-content { overflow:hidden; border:1px solid rgba(148,163,184,.2); border-radius:18px; background:#101722 !important; color:#e7edf5; box-shadow:0 28px 80px rgba(0,0,0,.48); }
        .request-detail-modal .modal-header { padding:1.1rem 1.4rem; border-bottom-color:rgba(148,163,184,.14); background:#121b27; }
        .request-detail-modal .modal-title { color:#f1f5f9; font-size:1rem; font-weight:650 !important; letter-spacing:-.01em; }
        .request-detail-modal .modal-body { padding:1.4rem; color:#d5deea; font-size:.9rem; line-height:1.55; }
        .request-detail-modal .modal-body h6 { color:#e8eef6 !important; font-size:.72rem !important; font-weight:650 !important; letter-spacing:.075em !important; }
        .request-detail-modal .request-action-row { box-sizing:border-box; position:relative; z-index:1; display:flex; flex-wrap:wrap; gap:.65rem; overflow:visible; padding:1rem 1.4rem; border-top:1px solid rgba(148,163,184,.14); background:#0d141e; }
        .request-detail-modal .request-action-row form { box-sizing:border-box; display:flex; flex:1 1 11rem; min-width:0; margin:0; }
        .request-detail-modal .request-action-row > .request-action-btn { flex:1 1 11rem; }
        .request-detail-modal .request-action-btn { box-sizing:border-box; display:inline-flex; min-height:44px; align-items:center; justify-content:center; gap:.45rem; width:100%; padding:.65rem 1rem; border:1px solid transparent; border-radius:10px; background:transparent; color:#dce5ef; font-family:inherit; font-size:.84rem; font-weight:600; line-height:1.25; text-align:center; text-decoration:none; transition:background-color .16s ease,border-color .16s ease,color .16s ease,box-shadow .16s ease; }
        .request-detail-modal .request-action-btn:hover { border-color:currentColor; }
        .request-detail-modal .request-action-btn:focus-visible { outline:2px solid #a9c5df; outline-offset:3px; box-shadow:none; }
        .request-detail-modal .request-action-btn:disabled { cursor:not-allowed; opacity:.5; }
        .request-detail-modal .request-action-btn--approve { border-color:#79aaff; background:#79aaff; color:#fff; box-shadow:0 2px 6px rgba(39,93,68,.22); }
        .request-detail-modal .request-action-btn--approve:hover { border-color:#9cc1ff; background:#9cc1ff; color:#fff; box-shadow:0 0 0 3px rgba(95,164,132,.14); }
        .request-detail-modal .request-action-btn--payment-reject { border-color:#b94a58; background:#b94a58; color:#fff; box-shadow:0 2px 6px rgba(122,38,51,.22); }
        .request-detail-modal .request-action-btn--payment-reject:hover { border-color:#a43d4a; background:#a43d4a; color:#fff; box-shadow:0 0 0 3px rgba(185,74,88,.16); }
        .request-detail-modal .request-action-btn--reject { border-color:#a96572; background:transparent; color:#e5bfc6; }
        .request-detail-modal .request-action-btn--reject:hover { border-color:#a96572; background:rgba(154,79,94,.2); color:#ffe8eb; }
        .request-detail-modal .request-detail-label { color:#9aa9bb; font-size:.68rem; font-weight:600; letter-spacing:.075em; }
        .request-detail-modal .request-detail-value { color:#e1e8f0; font-size:.88rem; line-height:1.55; }
        .request-detail-modal .request-detail-card { border-color:rgba(148,163,184,.16); border-radius:12px; background:rgba(148,163,184,.035); }
        .request-detail-modal .request-detail-section + .request-detail-section { border-top-color:rgba(148,163,184,.14); }
        .request-detail-modal .request-detail-callout { border-color:rgba(125,155,190,.24); background:rgba(83,112,148,.08); }
        .request-account-hero { display:flex; align-items:center; gap:1rem; padding:1rem 1.1rem; border:1px solid rgba(121,170,255,.2); border-radius:16px; background:linear-gradient(135deg,rgba(121,170,255,.1),rgba(121,170,255,.035)); box-shadow:inset 0 1px 0 rgba(255,255,255,.05); }
        .request-account-avatar { width:3.15rem; height:3.15rem; flex:0 0 3.15rem; display:grid; place-items:center; border:1px solid rgba(121,170,255,.35); border-radius:13px; color:var(--neon-cyan); background:rgba(121,170,255,.09); font-weight:800; letter-spacing:.03em; }
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
        .request-detail-callout { padding:1rem; border:1px solid rgba(121,170,255,.18); border-radius:12px; background:rgba(121,170,255,.045); }
        .request-review-success .modal-content { border-color:rgba(121,170,255,.35); }
        .request-review-success-icon { width:4.25rem; height:4.25rem; display:grid; place-items:center; margin:0 auto 1rem; border:1px solid rgba(121,170,255,.45); border-radius:50%; background:rgba(121,170,255,.12); color:#79aaff; font-size:1.85rem; box-shadow:0 0 24px rgba(121,170,255,.12); }
        body.background_color.light-mode .request-detail-modal .modal-content,
        body.background_color[data-theme="light"] .request-detail-modal .modal-content { border-color:#d8dee8; background:#fff !important; color:#202a38; box-shadow:0 24px 64px rgba(15,23,42,.18); }
        body.background_color.light-mode .request-detail-modal .modal-header,
        body.background_color[data-theme="light"] .request-detail-modal .modal-header { border-bottom-color:#e5e9f0; background:#fff; }
        body.background_color.light-mode .request-detail-modal .modal-title,
        body.background_color[data-theme="light"] .request-detail-modal .modal-title,
        body.background_color.light-mode .request-detail-modal .modal-body h6,
        body.background_color[data-theme="light"] .request-detail-modal .modal-body h6 { color:#202a38 !important; }
        body.background_color.light-mode .request-detail-modal .modal-body,
        body.background_color[data-theme="light"] .request-detail-modal .modal-body,
        body.background_color.light-mode .request-detail-modal .text-white,
        body.background_color[data-theme="light"] .request-detail-modal .text-white { color:#263244 !important; }
        body.background_color.light-mode .request-detail-modal .text-white-50,
        body.background_color[data-theme="light"] .request-detail-modal .text-white-50 { color:#596779 !important; }
        body.background_color.light-mode .request-detail-modal .request-detail-label,
        body.background_color[data-theme="light"] .request-detail-modal .request-detail-label { color:#667487 !important; }
        body.background_color.light-mode .request-detail-modal .request-detail-value,
        body.background_color[data-theme="light"] .request-detail-modal .request-detail-value { color:#263244 !important; }
        body.background_color.light-mode .request-detail-modal .txt-neon-cyan,
        body.background_color[data-theme="light"] .request-detail-modal .txt-neon-cyan { color:#285ec7 !important; }
        body.background_color.light-mode .request-detail-modal .txt-neon-blue,
        body.background_color[data-theme="light"] .request-detail-modal .txt-neon-blue { color:#245db2 !important; }
        body.background_color.light-mode .request-detail-modal .request-action-row,
        body.background_color[data-theme="light"] .request-detail-modal .request-action-row { border-top-color:#e2e7ee; background:#f3f5f8; }
        body.background_color.light-mode .request-detail-modal .btn-close-white,
        body.background_color[data-theme="light"] .request-detail-modal .btn-close-white { filter:brightness(0) saturate(100%); opacity:.65; }
        body.background_color.light-mode .request-detail-modal .request-action-btn,
        body.background_color[data-theme="light"] .request-detail-modal .request-action-btn { border-color:#cbd4e0; background:transparent; color:#344256; }
        body.background_color.light-mode .request-detail-modal .request-action-btn:hover,
        body.background_color[data-theme="light"] .request-detail-modal .request-action-btn:hover { border-color:#9aa9bc; background:#f1f5f9; color:#172335; }
        body.background_color.light-mode .request-detail-modal .request-action-btn--approve,
        body.background_color[data-theme="light"] .request-detail-modal .request-action-btn--approve { border-color:#79aaff; background:#79aaff; color:#fff; box-shadow:0 2px 5px rgba(39,93,68,.16); }
        body.background_color.light-mode .request-detail-modal .request-action-btn--approve:hover,
        body.background_color[data-theme="light"] .request-detail-modal .request-action-btn--approve:hover { border-color:#9cc1ff; background:#9cc1ff; color:#fff; box-shadow:0 0 0 3px rgba(79,141,114,.14); }
        body.background_color.light-mode .request-detail-modal .request-action-btn--payment-reject,
        body.background_color[data-theme="light"] .request-detail-modal .request-action-btn--payment-reject { border-color:#b94a58; background:#b94a58; color:#fff; }
        body.background_color.light-mode .request-detail-modal .request-action-btn--payment-reject:hover,
        body.background_color[data-theme="light"] .request-detail-modal .request-action-btn--payment-reject:hover { border-color:#a43d4a; background:#a43d4a; color:#fff; }
        body.background_color.light-mode .request-detail-modal .request-action-btn--secondary,
        body.background_color[data-theme="light"] .request-detail-modal .request-action-btn--secondary { border-color:#c5ceda; background:transparent; color:#344256; }
        body.background_color.light-mode .request-detail-modal .request-action-btn--secondary:hover,
        body.background_color[data-theme="light"] .request-detail-modal .request-action-btn--secondary:hover { border-color:#8e9caf; background:#edf1f5; color:#172335; }
        body.background_color.light-mode .request-detail-modal .request-action-btn--reject,
        body.background_color[data-theme="light"] .request-detail-modal .request-action-btn--reject { border-color:#bd6a75; background:transparent; color:#923b48; }
        body.background_color.light-mode .request-detail-modal .request-action-btn--reject:hover,
        body.background_color[data-theme="light"] .request-detail-modal .request-action-btn--reject:hover { border-color:#a94f5c; background:#fff4f4; color:#7f2937; }
        body.background_color.light-mode .request-account-hero,
        body.background_color[data-theme="light"] .request-account-hero { border-color:#d8e2eb; background:#f5f9fc; box-shadow:none; }
        body.background_color.light-mode .request-account-name,
        body.background_color[data-theme="light"] .request-account-name { color:#202a38; }
        body.background_color.light-mode .request-account-kicker,
        body.background_color[data-theme="light"] .request-account-kicker { color:#667487; }
        body.background_color.light-mode .request-account-avatar,
        body.background_color[data-theme="light"] .request-account-avatar { border-color:#b9dbe2; background:#e9f4f6; color:#285ec7; }
        body.background_color.light-mode .request-detail-card,
        body.background_color[data-theme="light"] .request-detail-card { border-color:#e0e6ee; background:#f8fafc; }
        body.background_color.light-mode .request-detail-section + .request-detail-section,
        body.background_color[data-theme="light"] .request-detail-section + .request-detail-section { border-top-color:#e2e8f0; }
        body.background_color.light-mode .request-detail-callout,
        body.background_color[data-theme="light"] .request-detail-callout { border-color:#cbd9e7; background:#f2f7fb; }
        body.background_color.light-mode .request-detail-scroll,
        body.background_color[data-theme="light"] .request-detail-scroll { scrollbar-color:#9aa9bc #f1f5f9; }
        body.background_color.light-mode .request-detail-scroll::-webkit-scrollbar-thumb,
        body.background_color[data-theme="light"] .request-detail-scroll::-webkit-scrollbar-thumb { background:#9aa9bc; }
        body.background_color.light-mode .request-detail-modal .request-detail-value a,
        body.background_color[data-theme="light"] .request-detail-modal .request-detail-value a { color:#245db2; }
        body.background_color.light-mode .request-detail-modal .text-warning,
        body.background_color[data-theme="light"] .request-detail-modal .text-warning { color:#8a5a00 !important; }
        body.background_color.light-mode .request-detail-modal .request-status,
        body.background_color[data-theme="light"] .request-detail-modal .request-status { color:#465568; }
        body.background_color.light-mode .request-detail-modal .acct-profile-name,
        body.background_color[data-theme="light"] .request-detail-modal .acct-profile-name,
        body.background_color.light-mode .request-detail-modal .acct-section-title,
        body.background_color[data-theme="light"] .request-detail-modal .acct-section-title { color:#202a38; }
        body.background_color.light-mode .request-detail-modal .acct-profile-sub,
        body.background_color[data-theme="light"] .request-detail-modal .acct-profile-sub { color:#596779; }
        body.background_color.light-mode .request-detail-modal .acct-section,
        body.background_color[data-theme="light"] .request-detail-modal .acct-section { border-color:#e0e6ee; background:#f8fafc; }
        body.background_color.light-mode .request-detail-modal .acct-field-icon,
        body.background_color[data-theme="light"] .request-detail-modal .acct-field-icon { background:#e9f4f6; color:#285ec7; }
        body.background_color.light-mode .request-detail-modal .acct-doc-card,
        body.background_color[data-theme="light"] .request-detail-modal .acct-doc-card { border-color:#d8dee8; background:#fff; }
        body.background_color.light-mode .request-detail-modal .acct-doc-thumb,
        body.background_color[data-theme="light"] .request-detail-modal .acct-doc-thumb { background:#f1f5f9; }
        body.background_color.light-mode .request-detail-modal .acct-doc-label,
        body.background_color[data-theme="light"] .request-detail-modal .acct-doc-label { border-top-color:#e0e6ee; color:#465568; }
        body.background_color.light-mode .request-detail-modal .acct-badge-pending,
        body.background_color[data-theme="light"] .request-detail-modal .acct-badge-pending { border-color:#e8c879; background:#fff8e6; color:#77520a; }
        body.background_color.light-mode .request-detail-modal .acct-badge-role,
        body.background_color[data-theme="light"] .request-detail-modal .acct-badge-role { border-color:#b9dbe2; background:#e9f4f6; color:#285ec7; }
        @media (max-width:767.98px) { .request-detail-modal .modal-body { padding:1rem; } .request-detail-modal .modal-header { padding:1rem; } .request-detail-modal .request-action-row { padding:.85rem 1rem; } .request-detail-modal .request-action-row form, .request-detail-modal .request-action-row > .request-action-btn { flex-basis:100%; } .request-detail-grid, .request-detail-grid--two { grid-template-columns:1fr; } .request-detail-card--wide, .request-detail-card--full { grid-column:auto; } }
        body.background_color.light-mode .request-flash { background:rgba(255,255,255,.9); }
        body.background_color.light-mode .request-status { color:var(--text-muted); }

        /* Account details modal */
        .acct-profile-header{display:flex;align-items:center;gap:1.1rem;padding:1.1rem 1.3rem;border-radius:16px;background:linear-gradient(135deg,rgba(0,180,255,.14),rgba(121,170,255,.03));border:1px solid var(--glass-border);margin-bottom:1.4rem;flex-wrap:wrap;}
        .acct-avatar{width:58px;height:58px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1.35rem;color:#04121f;background:linear-gradient(135deg,#89b5ff,#79aaff);flex-shrink:0;box-shadow:0 0 18px rgba(121,170,255,.4);letter-spacing:-.02em;}
        .acct-profile-meta{min-width:0;flex:1 1 auto;}
        .acct-profile-name{color:#fff;font-weight:700;font-size:1.02rem;line-height:1.25;word-break:break-word;}
        .acct-profile-sub{color:rgba(255,255,255,.6);font-size:.8rem;word-break:break-word;}
        .acct-badge{display:inline-flex;align-items:center;gap:.35rem;font-size:.68rem;font-weight:700;padding:.32rem .7rem;border-radius:999px;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;}
        .acct-badge-pending{background:rgba(255,193,7,.12);color:#ffc107;border:1px solid rgba(255,193,7,.32);}
        .acct-badge-role{background:rgba(121,170,255,.1);color:var(--neon-cyan);border:1px solid rgba(121,170,255,.28);}
        .acct-section{border-radius:14px;background:rgba(121,170,255,.035);border:1px solid var(--glass-border);padding:1.1rem 1.25rem;margin-bottom:1.1rem;}
        .acct-section:last-child{margin-bottom:0;}
        .acct-section-title{display:flex;align-items:center;gap:.5rem;color:#fff;font-weight:700;font-size:.75rem;text-transform:uppercase;letter-spacing:.07em;margin-bottom:1rem;}
        .acct-section-title i{color:var(--neon-cyan);font-size:.85rem;}
        .acct-field-row{display:flex;align-items:flex-start;gap:.65rem;margin-bottom:.9rem;}
        .acct-field-row:last-child{margin-bottom:0;}
        .acct-field-icon{width:30px;height:30px;border-radius:9px;background:rgba(121,170,255,.08);display:flex;align-items:center;justify-content:center;color:var(--neon-cyan);font-size:.85rem;flex-shrink:0;margin-top:.05rem;}
        .acct-doc-grid{display:flex;flex-wrap:wrap;gap:.85rem;margin-top:.3rem;}
        .acct-doc-card{width:128px;border-radius:12px;overflow:hidden;border:1px solid var(--glass-border);background:rgba(255,255,255,.02);text-decoration:none;transition:transform .15s ease,border-color .15s ease,box-shadow .15s ease;display:block;}
        .acct-doc-card:hover{transform:translateY(-3px);border-color:var(--neon-cyan);box-shadow:0 8px 20px rgba(121,170,255,.15);}
        .acct-doc-thumb{width:100%;height:128px;object-fit:contain;display:block;background:rgba(0,0,0,.25);}
        .acct-doc-empty{width:100%;height:88px;display:flex;align-items:center;justify-content:center;color:var(--text-muted);font-size:1.4rem;background:rgba(255,255,255,.02);}
        .acct-doc-label{font-size:.66rem;color:rgba(255,255,255,.65);padding:.4rem .5rem;text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;border-top:1px solid var(--glass-border);}
        .acct-empty-note{border-radius:12px;border:1px dashed var(--glass-border);padding:.9rem 1.1rem;color:rgba(255,255,255,.55);font-size:.82rem;display:flex;align-items:center;gap:.6rem;}
        
        body.background_color.light-mode .acct-profile-header{background:linear-gradient(135deg,rgba(0,150,220,.08),rgba(0,150,220,.02));}
        body.background_color.light-mode .acct-profile-name{color:#12233f;}
    </style>
<style>
/* Page-local blue theme. Embedded after legacy styles; no shared files required. */
body.background_color.admin-blue-page {
    --ab-bg: #0b1220; --ab-surface: #121d2d; --ab-raised: #182537;
    --ab-border: #27364a; --ab-text: #edf3fa; --ab-muted: #a4b3c6;
    --ab-accent: #79aaff; --ab-warning: #f7ca72; --ab-danger: #ff9aab;
    --company-bg: var(--ab-bg); --company-surface: var(--ab-surface); --company-raised: var(--ab-raised);
    --company-border: var(--ab-border); --company-text: var(--ab-text); --company-muted: var(--ab-muted);
    --company-accent: var(--ab-accent); --company-warning: var(--ab-warning); --company-danger: var(--ab-danger);
    --neon-cyan: var(--ab-accent); --neon-blue: #89b5ff; --glass-border: var(--ab-border);
    --text-primary: var(--ab-text); --text-muted: var(--ab-muted);
    background: radial-gradient(ellipse at 90% 0%, rgba(45,80,155,.13), transparent 48%), var(--ab-bg);
    min-height: 100vh; background-repeat: no-repeat;
    color: var(--ab-text);
}
body.background_color.admin-blue-page:is(.light-mode,[data-theme="light"]) {
    --ab-bg: #f1f5f8; --ab-surface: #fff; --ab-raised: #f4f7fa; --ab-border: #d9e3ec;
    --ab-text: #172b40; --ab-muted: #53667b; --ab-accent: #285ec7;
    --ab-warning: #865b08; --ab-danger: #b0354c; --neon-blue: #3267b2;
}
.admin-blue-page :is(.admin-blue-shell,.modal) { color: var(--ab-text); font: 14px/1.65 'Poppins',system-ui,sans-serif; }
.admin-blue-page .admin-blue-shell { max-width: 1480px; padding-inline: clamp(16px,3.5vw,48px); margin: 36px auto 24px !important; }
.admin-blue-page :is(.admin-blue-shell,.modal) *, .admin-blue-page :is(.admin-blue-shell,.modal) *::before, .admin-blue-page :is(.admin-blue-shell,.modal) *::after { box-sizing: border-box; }
.admin-blue-page :is(.admin-blue-shell,.modal) :is(h1,h2,h3,h4,h5) { letter-spacing: -.03em; line-height: 1.3; }
.admin-blue-page .admin-blue-header { padding: 8px 0 28px; border-bottom: 1px solid var(--ab-border); }
.admin-blue-page .admin-blue-header h1 { color: var(--ab-text); font-size: clamp(26px,3vw,38px); font-weight: 600 !important; margin-top: 8px !important; }
.admin-blue-page .admin-blue-header :is(.stat-chip,.companies-eyebrow) { background: none; border: 0; border-radius: 0; padding: 0; color: var(--ab-accent); font-size: 11px !important; font-weight: 600; letter-spacing: .13em; text-transform: uppercase; }
.admin-blue-page .admin-blue-description { color: var(--ab-muted); font-size: 13px; margin: 12px 0 0; max-width: 660px; }
.admin-blue-page :is(.admin-blue-shell,.modal) .text-white { color: var(--ab-text) !important; }
.admin-blue-page :is(.admin-blue-shell,.modal) :is(.text-white-50,.loyalty-muted) { color: var(--ab-muted) !important; }
.admin-blue-page :is(.admin-blue-shell,.modal) :is(.txt-neon-cyan,.txt-neon-blue,.text-info,.text-success) { color: var(--ab-accent) !important; }
.admin-blue-page :is(.admin-blue-shell,.modal) .text-warning { color: var(--ab-warning) !important; }
.admin-blue-page :is(.admin-blue-shell,.modal) .text-danger { color: var(--ab-danger) !important; }
.admin-blue-page :is(.admin-blue-shell,.modal) .small { font-size: 12px !important; }
.admin-blue-page .admin-blue-shell :is(.analytics-card,.dashboard-card,.request-indicator-card,.loyalty-metric-card) { padding: 24px; border: 1px solid var(--ab-border); border-radius: 14px; background: var(--ab-surface); color: var(--ab-text); box-shadow: 0 6px 24px rgba(0,0,0,.08); backdrop-filter: none; }
.admin-blue-page .admin-blue-shell :is(.metric-card,.request-indicator-card,.loyalty-metric-card) { position: relative; height: 100%; min-height: 148px; overflow: hidden; background: linear-gradient(135deg,rgba(121,170,255,.025),transparent 80%),var(--ab-surface); }
.admin-blue-page .admin-blue-shell :is(.metric-card,.request-indicator-card,.loyalty-metric-card)::before { content: ''; position: absolute; left: 0; top: 24px; height: 32px; width: 3px; border-radius: 0 4px 4px 0; background: var(--ab-accent); }
.admin-blue-page .admin-blue-shell :is(.metric-label,.loyalty-metric-card span) { display: block; color: var(--ab-muted); font-size: 12px; font-weight: 500; margin-bottom: 10px !important; }
.admin-blue-page .admin-blue-shell .metric-card h4 { font-size: 34px; font-weight: 600 !important; font-variant-numeric: tabular-nums; }
.admin-blue-page .admin-blue-shell .request-indicator-grid { display: grid; grid-template-columns: repeat(3,minmax(0,1fr)); gap: 20px; margin-bottom: 24px; }
.admin-blue-page .admin-blue-shell .request-indicator-card { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
.admin-blue-page .admin-blue-shell .request-indicator-card p { text-transform: none !important; letter-spacing: 0 !important; font-size: 12px !important; font-weight: 500 !important; }
.admin-blue-page .admin-blue-shell .request-indicator-card h4 { font-size: 34px !important; font-weight: 600 !important; font-variant-numeric: tabular-nums; }
.admin-blue-page .admin-blue-shell .request-indicator-card .fs-3 { display: grid; place-items: center; flex-shrink: 0; width: 44px; height: 44px; border-radius: 12px; border: 1px solid var(--ab-border); background: var(--ab-raised); font-size: 20px !important; }
.admin-blue-page .admin-blue-shell [data-live-request-count="approved"], .admin-blue-page .admin-blue-shell .request-indicator-card:nth-child(2) .fs-3 { color: var(--ab-accent) !important; }
.admin-blue-page .admin-blue-shell [data-live-request-count="declined_flagged"], .admin-blue-page .admin-blue-shell .request-indicator-card:nth-child(3) .fs-3 { color: var(--ab-danger) !important; }
.admin-blue-page .admin-blue-shell .admin-filter-card { padding: 22px 24px; }
.admin-blue-page .admin-blue-shell .admin-filter-form { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 16px; }
.admin-blue-page .admin-blue-shell .filter-group { flex: 1 1 170px; min-width: 0; }
.admin-blue-page .admin-blue-shell .filter-group--search { flex: 2 1 300px; }
.admin-blue-page .admin-blue-shell .filter-group:not(:has(input,select)) { display: none; }
.admin-blue-page .admin-blue-shell :is(.filter-label,.filter-group label), .admin-blue-page :is(.admin-blue-shell,.modal) .form-label { display: block; font-size: 12px; font-weight: 500; color: var(--ab-muted); margin-bottom: 8px; }
.admin-blue-page .admin-blue-shell .filter-search { position: relative; }
.admin-blue-page .admin-blue-shell .filter-search-icon { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); z-index: 1; color: var(--ab-muted); pointer-events: none; }
.admin-blue-page .admin-blue-shell .filter-input { padding-left: 38px !important; }
.admin-blue-page :is(.admin-blue-shell,.modal) :is(.form-control,.form-select) { min-height: 44px; width: 100%; border: 1px solid var(--ab-border); border-radius: 8px; background-color: var(--ab-raised); color: var(--ab-text); font: 13px/1.5 'Poppins',system-ui,sans-serif; padding: 11px 12px; box-shadow: none; }
.admin-blue-page :is(.admin-blue-shell,.modal) .form-select { padding-right: 36px; }
.admin-blue-page:not(.light-mode):not([data-theme="light"]) :is(.admin-blue-shell,.modal) :is(.form-control,.form-select) { color-scheme: dark; }
.admin-blue-page:not(.light-mode):not([data-theme="light"]) :is(.admin-blue-shell,.modal) .form-select { --bs-form-select-bg-img: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23a4b3c6' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e"); }
.admin-blue-page :is(.admin-blue-shell,.modal) .form-control::placeholder { color: var(--ab-muted); opacity: .8; }
.admin-blue-page :is(.admin-blue-shell,.modal) :is(.form-control,.form-select):focus { border-color: var(--ab-accent); outline: 2px solid var(--ab-accent); outline-offset: 2px; box-shadow: none; }
.admin-blue-page :is(.admin-blue-shell,.modal) select option { background: var(--ab-surface); color: var(--ab-text); }
.admin-blue-page .admin-blue-shell .filter-actions { display: flex; flex: 0 0 auto; gap: 8px; }
.admin-blue-page :is(.admin-blue-shell,.modal) :is(.btn,.btn-clear-filters,.companies-apply) { display: inline-flex; align-items: center; justify-content: center; gap: 6px; min-height: 44px; border-radius: 8px; padding: 10px 16px; font-family: inherit; font-size: 12px; font-weight: 500; text-decoration: none; }
.admin-blue-page .admin-blue-shell .btn-clear-filters { background: var(--ab-raised); border: 1px solid var(--ab-border); color: var(--ab-text); }
.admin-blue-page :is(.admin-blue-shell,.modal) :is(.btn-primary,.btn-success,.btn-neon,.companies-apply) { background: #79aaff; border: 1px solid #79aaff; color: #10284c; box-shadow: none; }
.admin-blue-page :is(.admin-blue-shell,.modal) :is(.btn-primary,.btn-success,.btn-neon,.companies-apply):hover { background: #9cc1ff; border-color: #9cc1ff; color: #10284c; }
.admin-blue-page :is(.admin-blue-shell,.modal) .btn-outline-secondary { background: transparent; border-color: var(--ab-border) !important; color: var(--ab-muted); }
.admin-blue-page :is(.admin-blue-shell,.modal) :is(.btn-action,.btn-clear-filters,.btn-outline-secondary):hover { color: var(--ab-accent); border-color: var(--ab-accent) !important; }
.admin-blue-page :is(.admin-blue-shell,.modal) .btn-action { display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; min-width: 44px; width: 44px; height: 44px; padding: 8px; border: 1px solid var(--ab-border); border-radius: 8px; background: var(--ab-raised); color: var(--ab-muted); text-decoration: none; }
.admin-blue-page :is(.admin-blue-shell,.modal) .btn-action-danger:hover { color: var(--ab-danger); border-color: var(--ab-danger) !important; }
.admin-blue-page :is(.admin-blue-shell,.modal) :is(.stat-chip,.report-scope,.loyalty-status,.hq-status,.request-status) { display: inline-flex; align-items: center; gap: 7px; border: 1px solid var(--ab-border); border-radius: 6px; padding: 5px 9px; background: var(--ab-raised); color: var(--ab-muted); font-size: 11px !important; font-weight: 500; line-height: 1.5; white-space: nowrap; }
.admin-blue-page :is(.admin-blue-shell,.modal) :is(.hq-status,.request-status)::before { content: ''; display: block; flex: 0 0 auto; width: 6px; height: 6px; border-radius: 50%; box-shadow: none; background: var(--ab-warning); }
.admin-blue-page :is(.admin-blue-shell,.modal) :is(.hq-status-active,.request-status-approved)::before { background: var(--ab-accent); }
.admin-blue-page :is(.admin-blue-shell,.modal) :is(.hq-status-removed,.request-status-rejected)::before { background: var(--ab-danger); }
.admin-blue-page :is(.admin-blue-shell,.modal) :is(.hq-status-suspended,.request-status-flagged)::before { background: #e79452; }
.admin-blue-page :is(.admin-blue-shell,.modal) .badge { font-size: 11px; font-weight: 500; line-height: 1.45; }
.admin-blue-page :is(.admin-blue-shell,.modal) :is(.bg-info,.bg-success) { background-color: rgba(121,170,255,var(--bs-bg-opacity,1)) !important; }
.admin-blue-page :is(.admin-blue-shell,.modal) .border-info { border-color: rgba(121,170,255,var(--bs-border-opacity,1)) !important; }
.admin-blue-page .admin-blue-shell .company-avatar { width: 44px; height: 44px; flex: 0 0 auto; border: 1px solid var(--ab-border); background: var(--ab-raised); color: var(--ab-accent) !important; font-size: 13px; }
.admin-blue-page :is(.admin-blue-shell,.modal) .table-responsive { overflow: auto; border: 1px solid var(--ab-border); border-radius: 10px; scrollbar-color: var(--ab-border) var(--ab-raised); }
.admin-blue-page .admin-blue-shell :is(.hq-table-scroll,.report-table-scroll,.user-table-scroll) { max-height: min(68vh,640px); }
.admin-blue-page :is(.admin-blue-shell,.modal) .table { --bs-table-bg: transparent; --bs-table-color: var(--ab-text); --bs-table-border-color: var(--ab-border); width: 100%; color: var(--ab-text); font-size: 13px; margin-bottom: 0; }
.admin-blue-page :is(.admin-blue-shell,.modal) .table > :not(caption) > * > * { padding: 16px; color: inherit; background: transparent; border-color: var(--ab-border); box-shadow: none; }
.admin-blue-page :is(.admin-blue-shell,.modal) .table thead th { background: var(--ab-raised); color: var(--ab-muted); font-size: 11px; font-weight: 500; letter-spacing: .06em; text-transform: uppercase; white-space: nowrap; }
.admin-blue-page .admin-blue-shell :is(.hq-table-scroll,.report-table-scroll,.user-table-scroll) thead th { position: sticky; top: 0; z-index: 2; }
.admin-blue-page :is(.admin-blue-shell,.modal) .table tbody { font-size: 13px; }
.admin-blue-page :is(.admin-blue-shell,.modal) .table tbody tr:hover { background: var(--ab-raised); }
.admin-blue-page :is(.admin-blue-shell,.modal) .table tbody tr:last-child > * { border-bottom: 0; }
.admin-blue-page .admin-blue-shell .table-dark-custom { min-width: 960px; }
.admin-blue-page .admin-blue-shell .table-dark-custom td .small { font-size: 12px !important; }
.admin-blue-page .admin-blue-shell .table-dark-custom td:first-child .fw-semibold { font-size: 13px; }
.admin-blue-page .admin-blue-shell .companies-directory-heading p { font-size: 12px; }
.admin-blue-page .admin-blue-shell .companies-scroll-hint { font-size: 12px; }
.admin-blue-page :is(.admin-blue-shell,.modal) .alert { border-radius: 10px; padding: 16px 20px; border: 1px solid var(--ab-border); background: var(--ab-raised); color: var(--ab-text); font-size: 13px; }
.admin-blue-page :is(.admin-blue-shell,.modal) .alert-danger { border-color: rgba(255,154,171,.4) !important; }
.admin-blue-page :is(.admin-blue-shell,.modal) .alert-warning { border-color: rgba(247,202,114,.4) !important; }
.admin-blue-page :is(.admin-blue-shell,.modal) .alert-success { border-color: rgba(121,170,255,.4) !important; }
.admin-blue-page :is(.admin-blue-shell,.modal) :is(a,button,summary,[tabindex="0"]):focus-visible { outline: 3px solid var(--ab-accent); outline-offset: 3px; }
.admin-blue-page .modal .modal-content { border: 1px solid var(--ab-border); border-radius: 14px; background: var(--ab-surface) !important; color: var(--ab-text); box-shadow: 0 24px 100px rgba(0,0,0,.3); }
.admin-blue-page .modal :is(.modal-header,.modal-footer) { padding: 20px 24px; border-color: var(--ab-border); background: var(--ab-surface); }
.admin-blue-page .modal .modal-title { color: var(--ab-text) !important; font-size: 18px; font-weight: 600 !important; }
.admin-blue-page .modal .modal-body { padding: 24px; font-size: 14px; color: var(--ab-text); }
.admin-blue-page .modal :is(.hq-detail-label,.mod-detail-label,.report-detail-label,.request-detail-label,.request-account-kicker) { color: var(--ab-muted); font-size: 11px; font-weight: 500; letter-spacing: .07em; text-transform: uppercase; }
.admin-blue-page .modal :is(.hq-detail-value,.mod-detail-value,.report-detail-value,.request-detail-value,.request-account-name) { color: var(--ab-text); font-size: 13px; line-height: 1.65; overflow-wrap: anywhere; }
.admin-blue-page .modal :is(.report-detail-card,.request-detail-card,.acct-section,.request-detail-callout,.report-detail-content,.report-detail-hero,.request-account-hero,.acct-profile-header) { background: var(--ab-raised); border-color: var(--ab-border); box-shadow: none; }
.admin-blue-page .modal :is(.report-detail-card,.report-detail-hero,.report-detail-content) { padding: 18px; border-radius: 10px; }
.admin-blue-page .modal :is(.acct-profile-name,.acct-section-title) { color: var(--ab-text); }
.admin-blue-page .modal :is(.acct-profile-sub,.acct-doc-label,.acct-empty-note) { color: var(--ab-muted); }
.admin-blue-page .modal :is(.acct-avatar,.request-account-avatar) { background: #79aaff; border-color: #79aaff; color: #10284c; box-shadow: none; }
.admin-blue-page .modal :is(.acct-field-icon,.acct-badge-role) { background: rgba(121,170,255,.1); border-color: var(--ab-border); color: var(--ab-accent); }
.admin-blue-page .modal .request-review-success-icon { color: var(--ab-accent); border-color: var(--ab-accent); background: rgba(121,170,255,.1); box-shadow: none; }
.admin-blue-page .modal .request-action-row { border-color: var(--ab-border); background: var(--ab-raised); }
.admin-blue-page .modal .request-action-btn--approve { background: #79aaff; border-color: #79aaff; color: #10284c; box-shadow: none; }
.admin-blue-page .modal .request-action-btn--approve:hover { background: #9cc1ff; border-color: #9cc1ff; color: #10284c; box-shadow: none; }
.admin-blue-page .modal .request-detail-scroll { scrollbar-color: var(--ab-border) var(--ab-raised); }
.admin-blue-page .modal :is(.hq-modal-table,.request-modal-table) { min-width: 640px; }
.admin-blue-page .modal .p-3.rounded-3:has(.mod-detail-label,.hq-detail-label) { border-color: var(--ab-border) !important; background: var(--ab-raised) !important; }
.admin-blue-page:is(.light-mode,[data-theme="light"]) .btn-close-white { filter: none; }
.admin-blue-page .loyalty-metric-card { display: flex; justify-content: space-between; align-items: center; gap: 18px; }
.admin-blue-page .loyalty-metric-card strong { display: block; font-size: clamp(22px,2.2vw,30px); font-weight: 600; line-height: 1.35; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
.admin-blue-page .loyalty-metric-card strong small { display: block; margin-top: 6px; font-size: 11px; font-weight: 500; color: var(--ab-muted); letter-spacing: .1em; }
.admin-blue-page .loyalty-metric-card > i, .admin-blue-page .loyalty-section-icon { display: grid; place-items: center; flex: 0 0 auto; width: 44px; height: 44px; border: 1px solid var(--ab-border); border-radius: 12px; background: var(--ab-raised); color: var(--ab-accent); font-size: 20px; }
.admin-blue-page .loyalty-section-heading { display: flex; align-items: center; gap: 14px; margin-bottom: 24px; }
.admin-blue-page .loyalty-section-heading h2 { font-size: 18px; font-weight: 600; }
.admin-blue-page .loyalty-budget-control { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 24px; }
.admin-blue-page .loyalty-budget-input { flex: 1 1 260px; min-width: 0; }
.admin-blue-page .loyalty-budget-status { display: grid; grid-template-columns: auto auto; gap: 8px 24px; flex: 1 1 310px; border-left: 1px solid var(--ab-border); padding-left: 24px; font-size: 12px; }
.admin-blue-page .loyalty-budget-status span { color: var(--ab-muted); }
.admin-blue-page .loyalty-budget-status strong { font-weight: 500; font-variant-numeric: tabular-nums; text-align: right; }
.admin-blue-page #loyalty-budget-remaining { color: var(--ab-accent); }
.admin-blue-page .loyalty-budget-save { min-width: 100px; }
.admin-blue-page .loyalty-advanced-settings { margin-top: 28px; border: 1px solid var(--ab-border); border-radius: 10px; overflow: hidden; }
.admin-blue-page .loyalty-advanced-settings summary { padding: 16px 20px; cursor: pointer; background: var(--ab-raised); color: var(--ab-accent); font-size: 13px; font-weight: 500; }
.admin-blue-page .loyalty-advanced-content { padding: 24px; }
.admin-blue-page .loyalty-form-actions { display: flex; justify-content: flex-end; margin-top: 20px; }
.admin-blue-page .loyalty-table:has(input) { min-width: 520px; }
.admin-blue-page .loyalty-table:has(.loyalty-status) { min-width: 840px; }
.admin-blue-page .loyalty-company-balance { color: var(--ab-accent) !important; font-variant-numeric: tabular-nums; }
.admin-blue-page .loyalty-payout-instructions { font-size: 13px; max-width: 740px; }
.admin-blue-page .loyalty-payout-balance { display: block; padding: 14px 18px; border: 1px solid var(--ab-border); border-radius: 8px; background: var(--ab-raised); color: var(--ab-muted); font-size: 12px; }
.admin-blue-page .loyalty-payout-balance strong { color: var(--ab-text); font-weight: 500; }
.admin-blue-page .loyalty-optional { font-size: 11px; margin-left: 6px; font-weight: 400; }
.admin-blue-page .loyalty-status-pending { color: var(--ab-warning) !important; }
.admin-blue-page :is(.loyalty-status-confirmed,.loyalty-status-paid,.loyalty-status-approved) { color: var(--ab-accent) !important; }
.admin-blue-page :is(.loyalty-status-rejected,.loyalty-status-cancelled) { color: var(--ab-danger) !important; }
.admin-blue-page :is(#accountActionModal,#hqDetailsModal) :is(.hq-detail-label,.form-label,.hq-modal-table th) { font-size: 11px; }
.admin-blue-page :is(#accountActionModal,#hqDetailsModal) :is(.hq-detail-value,.hq-modal-table,.hq-modal-table td .small) { font-size: 13px; }
.admin-blue-page .modal .request-action-btn--approve { background: #79aaff !important; border-color: #79aaff !important; color: #10284c !important; }
.admin-blue-page .modal .request-action-btn--approve:hover { background: #9cc1ff !important; border-color: #9cc1ff !important; color: #10284c !important; }
.admin-blue-page .modal :is(.text-white,.modal-body h6) { color: var(--ab-text) !important; }
.admin-blue-page .modal :is(.txt-neon-cyan,.txt-neon-blue) { color: var(--ab-accent) !important; }
.admin-blue-page :is(.admin-blue-shell,.modal) :is(.rounded-3,.badge)[style*="rgba(0,244,255"] { background: var(--ab-raised) !important; border-color: var(--ab-border) !important; color: var(--ab-accent) !important; }
.admin-blue-page footer { color: var(--ab-muted) !important; margin-top: 0 !important; font: 11px/1.6 'Poppins',system-ui,sans-serif; }
@media (max-width: 991.98px) { .admin-blue-page .admin-blue-shell .filter-group--search { flex-basis: 100%; } .admin-blue-page .loyalty-budget-status { border-left: 0; padding-left: 0; } }
@media (max-width: 767.98px) {
    .admin-blue-page .admin-blue-shell { margin-top: 22px !important; }
    .admin-blue-page .admin-blue-shell :is(.analytics-card,.dashboard-card,.request-indicator-card,.loyalty-metric-card) { padding: 20px; }
    .admin-blue-page .admin-blue-shell .request-indicator-grid { gap: 12px; grid-template-columns: 1fr; }
    .admin-blue-page .admin-blue-shell .request-indicator-card { min-height: 120px; }
    .admin-blue-page .admin-blue-shell .filter-group { flex-basis: calc(50% - 8px); }
    .admin-blue-page .admin-blue-shell .filter-actions { flex-basis: 100%; justify-content: flex-end; }
    .admin-blue-page .modal :is(.modal-header,.modal-body,.modal-footer) { padding: 18px; }
    .admin-blue-page .loyalty-budget-control { gap: 18px; }
    .admin-blue-page .loyalty-budget-status { flex-basis: 100%; padding-top: 18px; border-top: 1px solid var(--ab-border); }
    .admin-blue-page .loyalty-budget-save { width: 100%; }
    .admin-blue-page .loyalty-advanced-content { padding: 18px; }
}
@media (max-width: 419.98px) { .admin-blue-page .admin-blue-shell .filter-group { flex-basis: 100%; } .admin-blue-page .loyalty-budget-status { gap: 8px 12px; } }
@media (prefers-reduced-motion: reduce) { .admin-blue-page :is(.admin-blue-shell,.modal) *, .admin-blue-page :is(.admin-blue-shell,.modal) *::before { transition: none !important; animation: none !important; scroll-behavior: auto !important; } }
</style>
<style id="uniform-request-boxes">
/* Requests: presentation only; preserve review forms and evidence layout. */
.admin-blue-page #requestDetailsBody .acct-section:has(> .acct-field-row),
.admin-blue-page #requestDetailsBody .row:has(> div > .acct-field-row) {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  grid-auto-rows: 1fr;
  gap: 16px;
}
.admin-blue-page #requestDetailsBody .acct-section > .acct-section-title,
.admin-blue-page #requestDetailsBody .acct-section > .request-detail-label,
.admin-blue-page #requestDetailsBody .acct-section > .acct-doc-grid {
  grid-column: 1 / -1;
}
.admin-blue-page #requestDetailsBody .acct-section:has(> .acct-field-row) {
  grid-template-rows: auto;
  grid-auto-rows: 1fr;
  align-items: stretch;
}
.admin-blue-page #requestDetailsBody .row:has(> div > .acct-field-row) { margin: 0; }
.admin-blue-page #requestDetailsBody .row:has(> div > .acct-field-row) > div {
  width: auto; min-width: 0; margin: 0; padding: 0; display: flex;
}
.admin-blue-page #requestDetailsBody .acct-field-row {
  min-width: 0; width: 100%; margin: 0; padding: 18px;
  min-height: 116px; border: 1px solid var(--ab-border);
  border-radius: 12px; background: var(--ab-surface);
  align-items: flex-start;
}
.admin-blue-page #requestDetailsBody .acct-field-row > .min-width-0 { min-width: 0; flex: 1; }
.admin-blue-page #requestDetailsBody .acct-field-icon { flex-shrink: 0; }
.admin-blue-page #requestDetailsBody .acct-field-row .request-status {
  max-width: 100%; white-space: normal; overflow-wrap: anywhere;
}
.admin-blue-page :is(#requestDetailsBody, #paymentDetailsBody) .request-detail-value {
  overflow-wrap: anywhere; word-break: normal;
}
.admin-blue-page #requestDetailsBody .row:has(> div > section.acct-section) {
  display: grid; grid-template-columns: repeat(2, minmax(0, 1fr));
  grid-auto-rows: 1fr; gap: 16px; margin: 0;
}
.admin-blue-page #requestDetailsBody .row:has(> div > section.acct-section) > div {
  width: auto; min-width: 0; padding: 0; margin: 0;
}
.admin-blue-page #requestDetailsBody section.acct-section:has(> .acct-field-row) {
  grid-template-columns: 1fr; grid-auto-rows: auto; align-content: start;
}
.admin-blue-page #requestDetailsBody > .row.g-4 {
  display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; margin: 0;
}
.admin-blue-page #requestDetailsBody > .row.g-4 > div {
  width: auto; min-width: 0; margin: 0; padding: 18px;
  border: 1px solid var(--ab-border); border-radius: 12px; background: var(--ab-raised);
}
.admin-blue-page #requestDetailsBody > .row.g-4 > .col-12:not(.col-md-6) { grid-column: 1 / -1; }
.admin-blue-page :is(#requestDetailsBody > .row.g-4, #paymentDetailsBody > .row.g-4) > div > div.mb-3 {
  padding: 16px; min-height: 104px; border: 1px solid var(--ab-border);
  border-radius: 12px; background: var(--ab-surface); margin-bottom: 12px !important;
}
.admin-blue-page #requestDetailsBody .request-detail-grid { grid-auto-rows: 1fr; gap: 16px; }
.admin-blue-page #requestDetailsBody .request-detail-card { padding: 18px; min-height: 116px; }
@media (max-width: 991.98px) {
  .admin-blue-page #requestDetailsBody .acct-section:has(> .acct-field-row),
  .admin-blue-page #requestDetailsBody .row:has(> div > .acct-field-row) { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .admin-blue-page #requestDetailsBody section.acct-section:has(> .acct-field-row) { grid-template-columns: 1fr; }
}
@media (max-width: 575.98px) {
  .admin-blue-page #requestDetailsBody .acct-section:has(> .acct-field-row),
  .admin-blue-page #requestDetailsBody .row:has(> div > .acct-field-row),
  .admin-blue-page #requestDetailsBody .row:has(> div > section.acct-section),
  .admin-blue-page #requestDetailsBody > .row.g-4 { grid-template-columns: 1fr; gap: 12px; }
  .admin-blue-page #requestDetailsBody .acct-field-row { padding: 16px; min-height: 104px; }
}</style>
</head>
<body class="background_color admin-blue-page" id="background_color">
<?php $adminSection = 'requests'; include __DIR__ . '/admin_section_nav.php'; ?>

    

    

    <main class="container my-5 admin-blue-shell">
        <?php if (is_array($paymentFlash)): ?><div class="alert request-flash alert-<?= hq_escape((string) ($paymentFlash['type'] ?? 'info')) ?> alert-dismissible fade show mb-4" role="status"><?= hq_escape((string) ($paymentFlash['message'] ?? 'Payment status updated.')) ?><button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
        <?php if ($flash && $flashType && !$accountReviewSuccess): ?>
            <div class="alert request-flash alert-<?= hq_escape($flashType) ?> alert-dismissible fade show mb-4" role="alert">
                <i class="bi <?= $flashType === 'success' ? 'bi-check-circle-fill text-success' : 'bi-exclamation-triangle-fill text-danger' ?> me-2"></i><?= hq_escape($flash['message'] ?? '') ?>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="admin-blue-header d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div><span class="stat-chip mb-2"><i class="bi bi-clipboard-check-fill txt-neon-cyan"></i> Review Pipeline</span><h1 class="fw-bold m-0 h4 text-white mt-2">Inbound Requests &amp; Payment Verifications</h1>
<p class="admin-blue-description">Review incoming requests, verify payment evidence, and manage account decisions.</p></div>
        </div>

        <?php if ($dbError): ?><div class="alert request-flash alert-danger mb-4" role="alert"><i class="bi bi-database-exclamation me-2"></i><?= hq_escape($dbError) ?></div><?php endif; ?>

        <div class="request-indicator-grid">
            <div class="request-indicator-card"><div><p class="text-white-50 mb-1 small fw-semibold" style="text-transform:uppercase;letter-spacing:.06em;font-size:.72rem;">Pending Verification</p><h4 class="fw-bold text-warning mb-0" style="font-size:1.8rem;letter-spacing:-.04em;"><?= (int) $summary['pending'] ?></h4></div><span class="fs-3 text-warning"><i class="bi bi-hourglass-split"></i></span></div>
            <div class="request-indicator-card"><div><p class="text-white-50 mb-1 small fw-semibold" style="text-transform:uppercase;letter-spacing:.06em;font-size:.72rem;">Approved</p><h4 class="fw-bold mb-0" data-live-request-count="approved" style="font-size:1.8rem;letter-spacing:-.04em;color:#1be494;"><?= (int) $summary['approved'] ?></h4></div><span class="fs-3" style="color:#1be494;"><i class="bi bi-patch-check-fill"></i></span></div>
            <div class="request-indicator-card"><div><p class="text-white-50 mb-1 small fw-semibold" style="text-transform:uppercase;letter-spacing:.06em;font-size:.72rem;">Reject</p><h4 class="fw-bold mb-0" data-live-request-count="declined_flagged" style="font-size:1.8rem;letter-spacing:-.04em;color:#ff4d6a;"><?= (int) $summary['declined_flagged'] ?></h4></div><span class="fs-3" style="color:#ff4d6a;"><i class="bi bi-shield-x"></i></span></div>
        </div>

        <div class="row g-4">
            <div class="col-12"><div class="dashboard-card admin-filter-card"><form class="admin-filter-form" method="GET" action="view_requests.php">
                <div class="filter-group filter-group--search"><label class="filter-label" for="requestSearch">Search</label><div class="filter-search"><i class="bi bi-search filter-search-icon" aria-hidden="true"></i><input id="requestSearch" type="text" class="form-control filter-control filter-input" placeholder="Company, sender, email, or reference ID..." name="search" value="<?= hq_escape($search) ?>"></div></div>
                <div class="filter-group"><label class="filter-label" for="requestType">Request type</label><div class="filter-select-wrap"><select id="requestType" class="form-select filter-control filter-select" name="type"><option value="" <?= $requestType === '' ? 'selected' : '' ?>>All Request Types</option><?php foreach ($requestTypeOptions as $option): ?><option value="<?= hq_escape($option) ?>" <?= admin_request_type_filter_value($requestType) === $option ? 'selected' : '' ?>><?= hq_escape(admin_filter_label($option)) ?></option><?php endforeach; ?></select></div></div>
                <div class="filter-group"><label class="filter-label" for="requestStatus">Status</label><div class="filter-select-wrap"><select id="requestStatus" class="form-select filter-control filter-select" name="status" aria-label="Filter by request status"><?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'reject' => 'Reject'] as $statusValue => $statusLabel): ?><option value="<?= hq_escape($statusValue) ?>" <?= $status === $statusValue ? 'selected' : '' ?>><?= hq_escape($statusLabel) ?></option><?php endforeach; ?></select></div></div>
                <div class="filter-actions">
                    <button type="button" class="btn-clear-filters js-clear-filters" title="Clear filters">Clear</button>
                </div>
            </form></div></div>

            <div class="col-12"><div class="analytics-card"><div class="table-responsive"><table id="requestQueueTable" class="table table-dark-custom align-middle m-0"><thead><tr><th>Reference ID</th><th>Sender</th><th>Request type</th><th>Submitted</th><th>Status</th><th class="text-end">Actions</th></tr></thead><tbody>
                <?php if (!$accountRows && !$carLicenseRows && !$requestRows && !$paymentRows): ?><tr><td colspan="6" class="text-center text-white-50 py-5"><i class="bi bi-inbox fs-3 d-block mb-2"></i>No requests match the current filter.</td></tr><?php endif; ?>
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
                        <td><div class="fw-semibold text-white"><?= hq_escape($sender) ?></div><div class="text-white-50 small" style="font-size:.8rem;"><?= hq_escape((string) ($row['email'] ?? 'Not provided')) ?></div></td>
                        <td><span class="badge rounded-pill bg-info bg-opacity-10 text-info border border-info border-opacity-25 px-2 py-1"><?= hq_escape($entityLabel) ?> Verification</span><?php if ((int) ($row['license_conflict_count'] ?? 0) > 0): ?><span class="badge bg-warning text-dark ms-1">Duplicate license</span><?php endif; ?></td>
                        <td class="text-white-50"><?= hq_escape(request_date($row['created_at'])) ?></td>
                        <td><span class="request-status <?= request_status_class((string) $row['status']) ?>"><?= hq_escape(request_status_label((string) $row['status'])) ?></span></td>
                        <td class="text-end"><div class="d-flex gap-2 justify-content-end">
                            <button type="button" class="btn-action js-view-account-request" title="View Details" aria-label="View details for account ACCT-<?= (int) $row['user_id'] ?>" data-user-id="<?= (int) $row['user_id'] ?>" data-role-name="<?= hq_escape($roleName) ?>"><i class="bi bi-eye-fill"></i></button>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                <?php foreach ($carLicenseRows as $row): ?>
                    <?php $carSender = (string) ($row['user_name'] ?: $row['car_owner_name'] ?: 'Unknown owner'); $carStatus = (string) ($row['status'] ?? 'pending'); ?>
                    <tr data-created-at="<?= (int) (strtotime((string) ($row['created_at'] ?? '')) ?: 0) ?>">
                        <td class="fw-bold txt-neon-cyan">#CAR-<?= (int) $row['car_license_id'] ?></td>
                        <td><div class="fw-semibold text-white"><?= hq_escape($carSender) ?></div><div class="text-white-50 small"><?= hq_escape((string) ($row['car_plate_number'] ?? '')) ?><?= !empty($row['email']) ? ' · ' . hq_escape((string) $row['email']) : '' ?></div></td>
                        <td><span class="badge rounded-pill bg-info bg-opacity-10 text-info border border-info border-opacity-25 px-2 py-1">Car License Review</span><?php if ((int) $row['conflict_count'] > 0): ?><span class="badge bg-warning text-dark ms-1">Duplicate plate</span><?php endif; ?></td>
                        <td class="text-white-50"><?= hq_escape(request_date($row['created_at'])) ?></td>
                        <td><span class="request-status <?= request_status_class($carStatus) ?>"><?= hq_escape(request_status_label($carStatus)) ?></span></td>
                        <td class="text-end"><div class="d-flex gap-2 justify-content-end">
                            <button type="button" class="btn-action js-view-car-license" title="View Details" aria-label="View details for car license #<?= (int) $row['car_license_id'] ?>" data-car-license-id="<?= (int) $row['car_license_id'] ?>"><i class="bi bi-eye-fill"></i></button>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                <?php foreach ($requestRows as $row): ?>
                    <?php $sender = (string) ($row['sender_entity'] ?: $row['sender_name'] ?: 'Unknown sender'); $location = (string) ($row['location'] ?: 'Location not provided'); $rowStatus = (string) ($row['status'] ?: 'pending'); $payment = $paymentReviewByRequest[(int) $row['request_id']] ?? null; $paymentState = strtolower((string) ($payment['status'] ?? '')); ?>
                    <tr data-created-at="<?= (int) (strtotime((string) ($row['created_at'] ?? '')) ?: 0) ?>">
                        <td class="fw-bold txt-neon-cyan">#<?= hq_escape($row['reference_code']) ?></td>
                        <td><div class="fw-semibold text-white"><?= hq_escape($sender) ?></div><div class="text-white-50 small" style="font-size:.8rem;"><?= hq_escape($location) ?></div></td>
                        <td><span class="badge rounded-pill bg-info bg-opacity-10 text-info border border-info border-opacity-25 px-2 py-1"><?= hq_escape(request_type_short_label($row['request_type'])) ?></span><?php if (!empty($row['business_license_conflict'])): ?><span class="badge bg-warning text-dark ms-1">Duplicate business license</span><?php endif; ?></td>
                        <td class="text-white-50"><?= hq_escape(request_date($row['created_at'])) ?></td>
                        <td><span class="request-status <?= request_status_class($rowStatus) ?>"><?= hq_escape(request_status_label($rowStatus)) ?></span><?php if (strtolower((string) $row['request_type']) === 'hq_registration'): ?><div class="mt-1"><span class="badge rounded-pill <?= $paymentState === 'received' ? 'bg-success' : ($paymentState === 'payer_reported_paid' ? 'bg-warning text-dark' : 'bg-secondary') ?>"><?= hq_escape(match ($paymentState) { 'received' => 'Payment received', 'payer_reported_paid' => 'Payment needs verification', 'rejected' => 'Payment not received', 'cancelled' => 'Payment cancelled', 'pending_payment' => 'Awaiting payer', default => 'Payment record missing' }) ?></span></div><?php endif; ?></td>
                        <td class="text-end"><div class="d-flex gap-2 justify-content-end">
                            <button type="button" class="btn-action js-view-request" title="View Details" aria-label="View details for request <?= hq_escape($row['reference_code']) ?>" data-request-id="<?= (int) $row['request_id'] ?>"><i class="bi bi-eye-fill"></i></button>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                <?php foreach ($paymentRows as $paymentRow): ?>
                    <?php
                    $paymentId = (int) $paymentRow['payment_transfer_id'];
                    $paymentState = (string) $paymentRow['status'];
                    $paymentStatus = match (strtolower($paymentState)) {
                        'received' => 'approved',
                        'rejected' => 'rejected',
                        default => 'pending',
                    };
                    $paymentStatusLabel = match ($paymentStatus) {
                        'approved' => 'Received',
                        'rejected' => 'Rejected',
                        default => 'Pending',
                    };
                    $paymentTypeLabel = match ((string) $paymentRow['payment_type']) { 'hq_registration' => 'Company Registration Payment', 'hq_period_extension' => 'Company Service Extension', 'hq_station_package' => 'Company Station Package', 'hq_extra_station' => 'Extra Stations (legacy)', 'hq_station_operating_quote' => 'Station Operating Fee (legacy)', default => 'Payment' };
                    $isRegistrationPayment = (string) $paymentRow['payment_type'] === 'hq_registration';
                    $registrationCanComplete = in_array(
                        strtolower(trim((string) ($paymentRow['registration_request_status'] ?? ''))),
                        ['pending', 'approved'],
                        true
                    );
                    $isPayer = ((int) ($paymentRow['payer_user_id'] ?? 0) === (int) ($_SESSION['user_id'] ?? 0)) || (strtolower((string) ($_SESSION['user_email'] ?? '')) !== '' && strtolower((string) ($_SESSION['user_email'] ?? '')) === strtolower((string) ($paymentRow['payer_email'] ?? '')));
                    ?>
                    <tr data-created-at="<?= (int) (strtotime((string) ($paymentRow['created_at'] ?? '')) ?: 0) ?>">
                        <td class="fw-bold txt-neon-cyan">#PAY-<?= $paymentId ?></td>
                        <td><div class="fw-semibold text-white"><?= hq_escape((string) ($paymentRow['payer_hq_name'] ?: $paymentRow['payer_name'] ?: 'Company payer')) ?></div><div class="text-white-50 small"><?= hq_escape((string) ($paymentRow['description'] ?? '')) ?></div><div class="text-white-50 small"><?= number_format((float) $paymentRow['amount_mmk'], 2) ?> MMK · <?= hq_escape((string) ($paymentRow['payer_email'] ?? '')) ?></div></td>
                        <td><span class="badge rounded-pill bg-info bg-opacity-10 text-info border border-info border-opacity-25 px-2 py-1">Payment Verification</span><div class="text-white-50 small mt-1"><?= hq_escape($paymentTypeLabel) ?></div></td>
                        <td class="text-white-50"><?= hq_escape(request_date((string) $paymentRow['created_at'])) ?></td>
                        <td><span class="request-status <?= request_status_class($paymentStatus) ?>"><?= hq_escape($paymentStatusLabel) ?></span></td>
                        <td class="text-end"><div class="d-flex gap-2 justify-content-end align-items-center">
                            <button type="button" class="btn-action js-view-payment" title="View Details" aria-label="View details for payment #PAY-<?= $paymentId ?>"
                                data-payment-id="<?= $paymentId ?>" data-payment-type="<?= hq_escape($paymentTypeLabel) ?>"
                                data-payment-status="<?= hq_escape($paymentState) ?>" data-payment-name="<?= hq_escape((string) ($paymentRow['payer_hq_name'] ?: $paymentRow['payer_name'] ?: 'Company payer')) ?>"
                                data-payment-email="<?= hq_escape((string) ($paymentRow['payer_email'] ?? '')) ?>" data-payment-description="<?= hq_escape((string) ($paymentRow['description'] ?? '')) ?>"
                                data-payment-amount="<?= hq_escape(number_format((float) $paymentRow['amount_mmk'], 2)) ?>" data-payment-date="<?= hq_escape(request_date((string) $paymentRow['created_at'])) ?>"
                                data-payment-method="<?= hq_escape((string) ($paymentRow['payment_method'] ?? 'Not provided')) ?>" data-receipt-name="<?= hq_escape((string) ($paymentRow['receipt_name'] ?? '')) ?>"
                                data-receipt-mime="<?= hq_escape((string) ($paymentRow['receipt_mime_type'] ?? '')) ?>" data-is-payer="<?= $isPayer ? 'yes' : 'no' ?>"><i class="bi bi-eye-fill"></i></button>
                            <?php
                            $canShowPaymentActions = $paymentState === 'payer_reported_paid' && !$isPayer && (!$isRegistrationPayment || $registrationCanComplete);
                            $paymentActionHint = '';
                            if ($paymentState === 'pending_payment') {
                                $paymentActionHint = 'Awaiting payer to report payment and upload evidence. Approve/Reject appear after that.';
                            } elseif ($isPayer && in_array($paymentState, ['pending_payment', 'payer_reported_paid'], true)) {
                                $paymentActionHint = 'You are the payer on this record. Another Admin or Moderator must verify it.';
                            } elseif ($isRegistrationPayment && $paymentState === 'payer_reported_paid' && !$registrationCanComplete) {
                                $paymentActionHint = 'Linked company registration is not pending/approved, so verification is blocked.';
                            }
                            ?>
                            <?php if ($canShowPaymentActions): ?><div class="d-none" id="paymentActions<?= $paymentId ?>">
                                <form action="/php/Payment/action.php" method="POST" class="m-0 flex-fill"><input type="hidden" name="csrf_token" value="<?= hq_escape((string) $_SESSION['payment_transfer_csrf']) ?>"><input type="hidden" name="transfer_id" value="<?= $paymentId ?>"><input type="hidden" name="action" value="confirm_received"><input type="hidden" name="return_to" value="/php/Admin(WebOwner)/view_requests.php"><button type="submit" class="request-action-btn request-action-btn--approve" onclick="return confirm('<?= $isRegistrationPayment ? 'Confirm the funds were received. This will also complete the company registration.' : 'Confirm receipt only after checking your bank or wallet records.' ?>')">Approve</button></form>
                                <form action="/php/Payment/action.php" method="POST" class="m-0 flex-fill"><input type="hidden" name="csrf_token" value="<?= hq_escape((string) $_SESSION['payment_transfer_csrf']) ?>"><input type="hidden" name="transfer_id" value="<?= $paymentId ?>"><input type="hidden" name="action" value="mark_not_received"><input type="hidden" name="return_to" value="/php/Admin(WebOwner)/view_requests.php"><input type="hidden" name="decision_note" value="Payment not verified by Admin."><button type="submit" class="request-action-btn request-action-btn--payment-reject" onclick="return confirm('Record that the payment was not received or could not be verified?')">Reject</button></form>
                            </div><?php elseif ($paymentActionHint !== ''): ?><div class="d-none" id="paymentActions<?= $paymentId ?>"><div class="small text-warning me-auto"><?= hq_escape($paymentActionHint) ?></div></div><?php endif; ?>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
            </tbody></table></div></div></div>
        </div>
    </main>

    <div class="modal fade glass-modal request-detail-modal" id="paymentDetailsModal" tabindex="-1" aria-labelledby="paymentDetailsTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-xl"><div class="modal-content"><div class="modal-header"><h5 class="modal-title fw-bold" id="paymentDetailsTitle"><i class="bi bi-receipt-cutoff txt-neon-cyan me-2"></i> Payment Verification Details</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body" id="paymentDetailsBody"></div><div class="modal-footer request-action-row" id="paymentDetailsFooter"><button type="button" class="btn btn-outline-secondary rounded-3 text-white-50 border-secondary border-opacity-20" data-bs-dismiss="modal">Close</button></div></div></div></div>

    <form action="request_action.php" method="POST" id="approveRequestForm" class="d-none"><input type="hidden" name="request_id" id="approveRequestId"><input type="hidden" name="action" value="approve"><input type="hidden" name="return_to" value="view_requests.php"></form>

    <div class="modal fade glass-modal request-detail-modal" id="viewReqModal" tabindex="-1" aria-labelledby="viewReqModalLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-xl"><div class="modal-content"><div class="modal-header"><h5 class="modal-title fw-bold" id="viewReqModalLabel"><i class="bi bi-file-earmark-text txt-neon-cyan me-2"></i> Request Details</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body" id="requestDetailsBody"><div class="text-center text-white-50 py-5"><div class="spinner-border spinner-border-sm txt-neon-cyan mb-2" role="status"></div><div>Loading request details…</div></div></div><div class="modal-footer request-action-row" id="requestDetailsFooter"><button type="button" class="btn btn-outline-secondary rounded-3 text-white-50 border-secondary border-opacity-20" data-bs-dismiss="modal">Close</button></div></div></div></div>

    <?php if ($accountReviewSuccess): ?>
        <div class="modal fade glass-modal request-review-success" id="accountReviewSuccessModal" tabindex="-1" aria-labelledby="accountReviewSuccessLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-sm"><div class="modal-content"><div class="modal-body text-center py-4 px-4"><div class="request-review-success-icon"><i class="bi bi-check-lg"></i></div><h5 class="fw-bold text-white mb-2" id="accountReviewSuccessLabel">Action completed</h5><p class="text-white-50 mb-1">The <?= hq_escape((string) $accountReviewSuccess['action']) ?> is executed successfully!</p><p class="small text-white-50 mb-0">Account ACCT-<?= (int) ($accountReviewSuccess['account_id'] ?? 0) ?></p></div><div class="modal-footer justify-content-center"><button type="button" class="btn btn-neon px-4" data-bs-dismiss="modal">Done</button></div></div></div></div>
    <?php endif; ?>

    <div class="modal fade glass-modal" id="rejectReqModal" tabindex="-1" aria-labelledby="rejectReqModalLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title fw-bold" id="rejectReqModalLabel"><i class="bi bi-shield-x text-danger me-2"></i> Reject Request</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div><form action="request_action.php" method="POST" id="rejectReqForm"><div class="modal-body d-flex flex-column gap-3"><input type="hidden" name="request_id" id="rejectRequestId"><input type="hidden" name="user_id" id="rejectUserId"><input type="hidden" name="action" value="reject"><input type="hidden" name="return_to" value="view_requests.php"><div class="small text-white-50">Target request: <span class="txt-neon-cyan fw-semibold" id="rejectRequestReference">—</span></div><div class="small text-white-50">Sender: <span class="text-white" id="rejectRequestSender">—</span></div><div><label class="form-label text-white-50 small" for="rejectReasonCode">Reason for rejection <span class="text-danger">*</span></label><select class="form-select glass-select" name="reject_reason_code" id="rejectReasonCode" required><option value="" selected disabled>Select a reason…</option><?php foreach (request_rejection_reason_options() as $reasonCode => $reasonLabel): ?><option value="<?= hq_escape($reasonCode) ?>"><?= hq_escape($reasonLabel) ?></option><?php endforeach; ?></select></div><div id="rejectReasonDetailsWrap" hidden><label class="form-label text-white-50 small" for="rejectReason">Additional details <span class="text-danger" id="rejectReasonRequiredMark" hidden>*</span></label><textarea class="form-control glass-input" name="reason" id="rejectReason" rows="4" maxlength="2000" placeholder="Add optional details, or specify the reason when Other is selected…" style="resize:none;"></textarea><div class="form-text text-white-50 small" id="rejectReasonHint">Optional for preset reasons. Required when you choose Other.</div></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-3 text-white-50 border-secondary border-opacity-20" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger">Reject</button></div></form></div></div></div>

    

    <footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map &amp; Pump | All Rights Reserved</footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script src="/js/Admin.js"></script>
    <script>
        (() => {
            const detailsModal = window.createAdminModalController(document.getElementById('viewReqModal'));
            const rejectModal = window.createAdminModalController(document.getElementById('rejectReqModal'));
            const detailsTitle = document.getElementById('viewReqModalLabel');
            const detailsBody = document.getElementById('requestDetailsBody');
            const detailsFooter = document.getElementById('requestDetailsFooter');
            const rejectForm = document.getElementById('rejectReqForm');
            const rejectId = document.getElementById('rejectRequestId');
            const rejectUserId = document.getElementById('rejectUserId');
            const rejectReference = document.getElementById('rejectRequestReference');
            const rejectSender = document.getElementById('rejectRequestSender');
            const rejectReasonCode = document.getElementById('rejectReasonCode');
            const rejectReason = document.getElementById('rejectReason');
            const rejectReasonDetailsWrap = document.getElementById('rejectReasonDetailsWrap');
            const rejectReasonRequiredMark = document.getElementById('rejectReasonRequiredMark');
            const rejectReasonHint = document.getElementById('rejectReasonHint');
            const approveId = document.getElementById('approveRequestId');
            const syncRejectReasonUi = () => {
                const code = String(rejectReasonCode?.value || '');
                const isOther = code === 'other';
                if (rejectReasonDetailsWrap) {
                    rejectReasonDetailsWrap.hidden = code === '';
                }
                if (rejectReason) {
                    rejectReason.required = isOther;
                    rejectReason.placeholder = isOther
                        ? 'Describe the rejection reason…'
                        : 'Optional notes for this rejection…';
                }
                if (rejectReasonRequiredMark) {
                    rejectReasonRequiredMark.hidden = !isOther;
                }
                if (rejectReasonHint) {
                    rejectReasonHint.textContent = isOther
                        ? 'Required when you choose Other.'
                        : 'Optional for preset reasons. Required when you choose Other.';
                }
            };
            rejectReasonCode?.addEventListener('change', syncRejectReasonUi);
            const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (character) => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[character]));
            const formatDate = (value) => value ? new Date(String(value).replace(' ', 'T')).toLocaleString() : 'Not available';
            const statusClass = (status) => ({approved:'request-status-approved', rejected:'request-status-rejected', flagged:'request-status-flagged'}[String(status || '').toLowerCase()] || 'request-status-pending');
            const statusLabel = (status) => ({pending:'Pending', approved:'Approved', rejected:'Reject', flagged:'Reject'}[String(status || '').toLowerCase()] || 'Pending');
            const defaultFooter = '';
            const setFooter = (html = defaultFooter) => { detailsFooter.innerHTML = html; };
            const fileLink = (path, label) => path ? '<a href="' + escapeHtml(path) + '" target="_blank" rel="noopener" class="txt-neon-blue text-decoration-none"><i class="bi bi-box-arrow-up-right me-1"></i>' + escapeHtml(label || 'Open file') + '</a>' : '<span class="text-white-50">Not uploaded</span>';
            const isImagePath = (path) => /\.(png|jpe?g|gif|webp|bmp|svg)(\?.*)?$/i.test(String(path || ''));
            const publicDocumentUrl = (value) => {
                const path = String(value || '').trim().replace(/\\/g, '/');
                if (!path || /^(https?:)?\/\//i.test(path) || /^data:/i.test(path)) return path;
                const customerUploadPath = path.replace(/^\.?\//, '').replace(/^Customer\//, '');
                if (customerUploadPath.startsWith('uploads/licenses/')) return '/php/Customer/' + customerUploadPath;
                if (path.startsWith('/')) return path;
                if (/^(\.\.\/|\.\/)/.test(path)) return new URL(path, window.location.href).pathname;
                return '/' + path;
            };
            const initialsOf = (name) => { const parts = String(name || '').trim().split(/\s+/).filter(Boolean); if (!parts.length) return '?'; return (parts[0][0] + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase(); };
            const docCard = (path, label, icon = 'bi-file-earmark-text') => {
                if (!path) return '<div class="acct-doc-card"><div class="acct-doc-empty"><i class="bi bi-file-earmark-x"></i></div><div class="acct-doc-label">Not uploaded</div></div>';
                const url = publicDocumentUrl(path);
                const inner = isImagePath(path) ? '<img src="' + escapeHtml(url) + '" alt="' + escapeHtml(label) + '" class="acct-doc-thumb" loading="lazy">' : '<div class="acct-doc-empty"><i class="bi ' + icon + '"></i></div>';
                return '<a href="' + escapeHtml(url) + '" target="_blank" rel="noopener" class="acct-doc-card" title="' + escapeHtml(label) + '">' + inner + '<div class="acct-doc-label">' + escapeHtml(label) + '</div></a>';
            };
            const licenseConflictMarkup = (current, conflicts) => {
                if (!current || !Array.isArray(conflicts) || !conflicts.length) return '';
                const recordCard = (title, record, currentRecord = false) => '<div class="col-12 col-lg-6"><section class="acct-section h-100"><div class="acct-section-title">' + escapeHtml(title) + '</div>' + fieldRow('bi-person', 'Driver', escapeHtml(record.user_name || 'Name unavailable')) + fieldRow('bi-envelope', 'Email', escapeHtml(record.email || 'Not provided')) + fieldRow('bi-hash', 'License number', escapeHtml(record.license_number || 'Not provided')) + fieldRow('bi-card-checklist', 'Decision status', escapeHtml(currentRecord ? (record.status || 'pending') : (record.license_status || 'pending'))) + '<div class="request-detail-label mb-2">License and face images</div><div class="acct-doc-grid">' + docCard(record.license_image, 'License image', 'bi-card-image') + docCard(record.face_image, 'Face image', 'bi-person-bounding-box') + '</div></section></div>';
                const comparisons = conflicts.map((record) => recordCard('Matching record · ACCT-' + record.user_id, record)).join('');
                const decisions = '<form action="license_conflict_action.php" method="POST" class="acct-section mt-3" data-license-conflict-form><input type="hidden" name="license_type" value="driver"><input type="hidden" name="record_id" value="' + escapeHtml(current.license_id) + '"><label class="form-label text-white-50 small" for="driver-license-decision">Decision for matching licenses</label><select class="form-select glass-select" id="driver-license-decision" name="decision" data-conflict-decision><option value="allow_all">Allow all matching licenses</option><option value="block_current">Block this license; allow the other matches</option><option value="block_all">Block all matching licenses</option></select><div class="mt-3" data-conflict-reason hidden><label class="form-label text-white-50 small" for="driver-license-reason">Reason required for blocking</label><textarea class="form-control glass-input" id="driver-license-reason" name="reason" rows="3" maxlength="2000" placeholder="Explain why the duplicate license record(s) are blocked."></textarea></div><button type="submit" class="btn btn-warning mt-3">Confirm license decision and notify affected drivers</button></form>';
                return '<div class="alert alert-warning d-flex align-items-center justify-content-between gap-3 flex-wrap"><span><i class="bi bi-exclamation-triangle-fill me-2"></i>Same driver-license number found on ' + conflicts.length + ' other record(s). You can decide each matching set independently.</span><button type="button" class="btn btn-sm btn-outline-warning" data-license-compare-toggle aria-expanded="false"><i class="bi bi-layout-split me-1"></i>Compare</button></div><div data-license-comparison hidden><div class="row g-3">' + recordCard('Current · ACCT-' + current.user_id, current, true) + comparisons + '</div>' + decisions + '</div>';
            };
            const configurePairDecisionForm = (form, licenseType, existingRecordId) => {
                if (!form) return;
                const existingIdInput = document.createElement('input');
                existingIdInput.type = 'hidden';
                existingIdInput.name = 'existing_record_id';
                existingIdInput.value = String(existingRecordId || '');
                form.appendChild(existingIdInput);
                const select = form.querySelector('[data-conflict-decision]');
                if (select) {
                    select.innerHTML = '<option value="approve_new_disable_existing">Approve new request; disable existing account</option>' +
                        '<option value="keep_existing_reject_new">Keep existing account; reject and disable new</option>' +
                        '<option value="accept_both">Accept both accounts</option>' +
                        '<option value="disable_both">Disable both accounts</option>';
                    select.value = 'approve_new_disable_existing';
                }
                const reason = form.querySelector('textarea');
                const syncReason = () => {
                    const disableDecision = ['approve_new_disable_existing', 'keep_existing_reject_new', 'disable_both'].includes(select?.value || '');
                    const reasonField = form.querySelector('[data-conflict-reason]');
                    if (reasonField) reasonField.hidden = !disableDecision;
                    if (reason) reason.required = disableDecision;
                };
                select?.addEventListener('change', syncReason);
                syncReason();
                const submit = form.querySelector('button[type="submit"]');
                if (submit) submit.textContent = 'Confirm decision';
                if (!form.querySelector('[data-conflict-cancel]')) {
                    const cancel = document.createElement('button');
                    cancel.type = 'button';
                    cancel.className = 'btn btn-outline-secondary mt-3 ms-2';
                    cancel.dataset.conflictCancel = 'true';
                    cancel.textContent = 'Cancel';
                    submit?.before(cancel);
                    cancel.addEventListener('click', () => { form.hidden = true; });
                }
                form.hidden = true;
            };
            const revealPairDecision = (form) => {
                const comparison = form?.closest('[data-license-comparison], [data-car-comparison]');
                if (comparison) comparison.hidden = false;
                if (form) {
                    form.hidden = false;
                    form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            };
            const markUnavailableDocImages = (root) => root.querySelectorAll('img.acct-doc-thumb').forEach((image) => {
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
            const fieldRow = (icon, label, value) => '<div class="acct-field-row"><div class="acct-field-icon"><i class="bi ' + icon + '"></i></div><div class="min-width-0"><div class="request-detail-label">' + escapeHtml(label) + '</div><div class="request-detail-value">' + (value || '<span class="text-white-50">Not provided</span>') + '</div></div></div>';
            const rejectTarget = (targetType, id, label, sender) => {
                rejectForm.action = targetType === 'account' ? 'account_review_action.php' : 'request_action.php';
                rejectId.value = targetType === 'request' ? id : '';
                rejectUserId.value = targetType === 'account' ? id : '';
                rejectReference.textContent = label || 'Selected request';
                rejectSender.textContent = sender || 'Unknown sender';
                if (rejectReasonCode) {
                    rejectReasonCode.value = '';
                }
                if (rejectReason) {
                    rejectReason.value = '';
                }
                syncRejectReasonUi();
                rejectModal.show();
            };
            document.addEventListener('click', (event) => {
                const approveBtn = event.target.closest('.js-request-approve');
                if (approveBtn) {
                    event.preventDefault();
                    approveId.value = approveBtn.dataset.requestId || '';
                    document.getElementById('approveRequestForm').submit();
                    return;
                }
                const rejectBtn = event.target.closest('.js-request-reject');
                if (rejectBtn) {
                    event.preventDefault();
                    rejectTarget('request', rejectBtn.dataset.requestId || '', rejectBtn.dataset.reference || 'Selected request', rejectBtn.dataset.sender || 'Unknown sender');
                }
            });

            const renderDetails = (payload) => {
                const request = payload.request || {};
                const registrationDetails = request.registration_details || {};
                const documentMarkup = request.document_name ? '<a href="' + escapeHtml(request.document_path || '#') + '" class="txt-neon-blue text-decoration-none"' + (request.document_path ? ' target="_blank" rel="noopener"' : '') + '><i class="bi bi-file-earmark-text me-2"></i>' + escapeHtml(request.document_name) + '</a>' : '<span class="text-white-50">No document attached</span>';
                const receiptMarkup = request.receipt_image ? '<a href="' + escapeHtml(request.receipt_image) + '" target="_blank" rel="noopener" class="d-inline-block text-decoration-none"><img src="' + escapeHtml(request.receipt_image) + '" alt="Payment receipt proof" style="display:block;width:100%;max-width:260px;max-height:220px;object-fit:contain;border-radius:12px;border:1px solid var(--glass-border);background:rgba(255,255,255,.04);padding:.35rem;"><span class="txt-neon-blue d-inline-flex align-items-center gap-1 mt-2"><i class="bi bi-receipt"></i>Open ' + escapeHtml(request.receipt_type || 'payment') + ' receipt</span></a>' : '<span class="text-white-50">No receipt attached</span>';
                const licenseMarkup = (registrationDetails.license_id ? '<div class="mb-2"><strong>License ID:</strong> ' + escapeHtml(registrationDetails.license_id) + '</div>' : '') + (registrationDetails.license_expiry ? '<div class="mb-2"><strong>License Expiry:</strong> ' + escapeHtml(registrationDetails.license_expiry) + '</div>' : '') + (registrationDetails.requested_station_count ? '<div class="mb-2"><strong>Stations requested:</strong> ' + escapeHtml(registrationDetails.requested_station_count) + '</div>' : '');
                detailsTitle.innerHTML = '<i class="bi bi-file-earmark-text txt-neon-cyan me-2"></i> Request ' + escapeHtml(request.reference_code || 'Details');
                let parsedDetails = {};
                try { parsedDetails = JSON.parse(request.request_details || '{}'); } catch (_) {}
                const detailLabels = {company_address:'Company address', owner_phone:'Owner phone', company_phone:'Company phone', license_id:'License ID', renewal_license_number:'Renewal license number', license_expiry:'License expiry', payment_method:'Payment method', bank_name:'Bank name', requested_station_count:'Stations requested'};
                const detailRows = Object.entries(detailLabels).filter(([key]) => parsedDetails[key] !== undefined && parsedDetails[key] !== null && String(parsedDetails[key]).trim() !== '').map(([key,label]) => '<div class="mb-2"><div class="request-detail-label">' + label + '</div><div class="request-detail-value">' + escapeHtml(parsedDetails[key]) + '</div></div>').join('');
                const readableDetails = detailRows || (Object.keys(parsedDetails).length ? Object.entries(parsedDetails).map(([key,value]) => '<div class="mb-2"><div class="request-detail-label">' + escapeHtml(key.replaceAll('_',' ')) + '</div><div class="request-detail-value">' + escapeHtml(value) + '</div></div>').join('') : escapeHtml(request.request_details || 'No additional details provided.'));
                detailsBody.innerHTML = '<div class="row g-4"><div class="col-12 col-md-6"><h6 class="fw-semibold text-white mb-3" style="letter-spacing:.5px;font-size:.78rem;text-transform:uppercase;">Submitter information</h6><div class="mb-3"><div class="request-detail-label">Company</div><div class="request-detail-value">' + escapeHtml(request.sender_entity || request.hq_company_name || 'Not available') + '</div></div><div class="mb-3"><div class="request-detail-label">Authorized Official</div><div class="request-detail-value">' + escapeHtml(request.sender_name || request.submitter_username || 'Not available') + (request.sender_email ? ' (' + escapeHtml(request.sender_email) + ')' : '') + '</div></div><div class="mb-3"><div class="request-detail-label">Company location</div><div class="request-detail-value">' + escapeHtml(request.location || 'Not provided') + '</div></div></div><div class="col-12 col-md-6"><h6 class="fw-semibold text-white mb-3" style="letter-spacing:.5px;font-size:.78rem;text-transform:uppercase;">Request information</h6><div class="mb-3"><div class="request-detail-label">Request Type</div><div class="request-detail-value">' + escapeHtml(request.request_type_label || request.request_type || 'Not available') + '</div></div><div class="mb-3"><div class="request-detail-label">Status</div><div><span class="request-status ' + statusClass(request.status) + '">' + escapeHtml(statusLabel(request.status)) + '</span></div></div><div class="mb-3"><div class="request-detail-label">Submitted</div><div class="request-detail-value">' + escapeHtml(formatDate(request.created_at)) + '</div></div><div class="mb-3"><div class="request-detail-label">Verification Document</div><div class="request-detail-value">' + documentMarkup + '</div></div></div><div class="col-12"><h6 class="fw-semibold text-white mb-2" style="letter-spacing:.5px;font-size:.78rem;text-transform:uppercase;">Request Details</h6><div class="request-detail-value request-detail-scroll p-3 rounded-3" style="background:rgba(0,244,255,.04);border:1px solid var(--glass-border);">' + readableDetails + '</div></div>' + (request.review_reason ? '<div class="col-12"><div class="request-detail-label">Review Message</div><div class="request-detail-value request-detail-scroll">' + escapeHtml(request.review_reason) + '</div></div>' : '') + '</div>';
                detailsBody.insertAdjacentHTML('afterbegin', '<div class="mb-3"><div class="request-detail-label">Business License</div><div class="request-detail-value">' + licenseMarkup + documentMarkup + '</div></div><div class="mb-3"><div class="request-detail-label">Payment Receipt</div><div class="request-detail-value">' + receiptMarkup + '</div></div>');
                const payment = request.registration_payment;
                if (payment) {
                    const paymentLabels = {pending_payment:'Awaiting payer declaration', payer_reported_paid:'Payer reported paid — verify receipt', received:'Payment recorded received', rejected:'Payment not received', cancelled:'Payment cancelled'};
                    const paymentReceipt = payment.receipt_name ? '<a href="/php/Payment/receipt.php?id=' + encodeURIComponent(payment.payment_transfer_id) + '" target="_blank" rel="noopener">' + escapeHtml(payment.receipt_name) + '</a>' : 'No payment evidence uploaded';
                    const registrationFlowNote = payment.status === 'received'
                        ? 'Payment is recorded as received.'
                        : 'Confirming receipt completes the company registration. No separate registration approval is needed.';
                    detailsBody.insertAdjacentHTML('afterbegin', '<div class="mb-3 p-3 rounded-3" style="background:rgba(255,193,7,.06);border:1px solid rgba(255,193,7,.25)"><div class="request-detail-label">Registration payment verification</div><div class="request-detail-value fw-semibold">' + escapeHtml(paymentLabels[payment.status] || payment.status) + '</div><div class="request-detail-value small mt-1">Amount: ' + escapeHtml(Number(payment.amount_mmk).toLocaleString()) + ' MMK · Method: ' + escapeHtml(payment.payment_method || 'Not provided') + '</div><div class="request-detail-value small mt-1">' + paymentReceipt + '</div><div class="small text-white-50 mt-2">A screenshot or payer declaration is evidence for review, not proof of received money. ' + registrationFlowNote + '</div>' + (payment.is_current_user_payer ? '<div class="small text-warning mt-2">You submitted this payment. Another reviewer must confirm it.</div>' : '') + '</div>');
                }
                const businessConflict = request.business_license_conflict || null;
                if (String(request.status || '').toLowerCase() === 'pending') {
                    if (businessConflict && businessConflict.headquarter_id) {
                        setFooter('<button type="button" class="request-action-btn request-action-btn--approve" data-business-dupe-manage><i class="bi bi-person-gear"></i>Manage this account</button>');
                        detailsFooter.querySelector('[data-business-dupe-manage]')?.addEventListener('click', () => {
                            const form = detailsBody.querySelector('[data-business-duplicate-form]');
                            if (form) {
                                form.hidden = false;
                                form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                            }
                        });
                    } else {
                        setFooter(
                            '<form action="request_action.php" method="POST" class="m-0 flex-grow-1"><input type="hidden" name="request_id" value="' + escapeHtml(request.request_id) + '"><input type="hidden" name="action" value="approve"><input type="hidden" name="return_to" value="view_requests.php"><button type="submit" class="request-action-btn request-action-btn--approve">Approve</button></form>' +
                            '<button type="button" class="request-action-btn request-action-btn--reject js-modal-reject flex-grow-1">Reject</button>'
                        );
                        detailsFooter.querySelector('.js-modal-reject')?.addEventListener('click', () => rejectTarget('request', request.request_id, request.reference_code || request.request_id, request.sender_entity || request.sender_name || 'Unknown sender'));
                    }
                } else {
                    setFooter();
                }

                if (businessConflict && businessConflict.headquarter_id) {
                    const businessForm = '<form action="request_action.php" method="POST" class="acct-section mt-3" data-business-duplicate-form hidden><input type="hidden" name="request_id" value="' + escapeHtml(request.request_id) + '"><input type="hidden" name="action" value="approve"><input type="hidden" name="return_to" value="view_requests.php"><input type="hidden" name="existing_headquarter_id" value="' + escapeHtml(businessConflict.headquarter_id) + '"><label class="form-label text-white-50 small" for="business-duplicate-decision">Duplicate company decision</label><select class="form-select glass-select" id="business-duplicate-decision" name="duplicate_decision"><option value="approve_new_disable_existing">Approve new request and disable the existing company</option><option value="keep_existing_reject_new">Keep the existing company and reject the new request</option><option value="accept_both">Accept both companies</option><option value="disable_both">Disable both companies</option></select><div class="mt-3" data-business-conflict-reason hidden><label class="form-label text-white-50 small" for="business-duplicate-reason">Reason required when disabling a duplicate company</label><textarea class="form-control glass-input" id="business-duplicate-reason" name="reason" rows="3" maxlength="2000" placeholder="Explain the duplicate-company decision."></textarea></div><button type="submit" class="btn btn-warning mt-3">Confirm decision</button></form>';
                    detailsBody.insertAdjacentHTML('beforeend', businessForm);
                    const decisionField = detailsBody.querySelector('[name="duplicate_decision"]');
                    const reasonField = detailsBody.querySelector('[data-business-conflict-reason]');
                    const syncBusinessDecision = () => {
                        const disableChoice = ['approve_new_disable_existing', 'keep_existing_reject_new', 'disable_both'].includes(decisionField?.value || '');
                        if (reasonField) reasonField.hidden = !disableChoice;
                        const reasonInput = detailsBody.querySelector('#business-duplicate-reason');
                        if (reasonInput) reasonInput.required = disableChoice;
                    };
                    decisionField?.addEventListener('change', syncBusinessDecision);
                    syncBusinessDecision();
                }
            };

            document.addEventListener('click', async (event) => {
                const viewReqBtn = event.target.closest('.js-view-request');
                if (viewReqBtn) {
                    detailsTitle.innerHTML = '<i class="bi bi-file-earmark-text txt-neon-cyan me-2"></i> Request Details';
                    detailsBody.innerHTML = '<div class="text-center text-white-50 py-5"><div class="spinner-border spinner-border-sm txt-neon-cyan mb-2" role="status"></div><div>Loading request details…</div></div>';
                    setFooter();
                    detailsModal.show();
                    try {
                        const response = await fetch('request_details.php?id=' + encodeURIComponent(viewReqBtn.dataset.requestId), {headers:{Accept:'application/json'}});
                        const payload = await response.json();
                        if (!response.ok || !payload.success) throw new Error(payload.message || 'Could not load request details.');
                        renderDetails(payload);
                    } catch (error) {
                        detailsBody.innerHTML = '<div class="alert request-flash alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>' + escapeHtml(error.message) + '</div>';
                    }
                    return;
                }

                const button = event.target.closest('.js-view-account-request');
                if (!button) return;
                    event.preventDefault();
                    detailsTitle.innerHTML = '<i class="bi bi-person-check txt-neon-cyan me-2"></i> User Account Verification';
                    detailsBody.innerHTML = '<div class="text-center text-white-50 py-5"><div class="spinner-border spinner-border-sm txt-neon-cyan mb-2" role="status"></div><div>Loading account details…</div></div>';
                    setFooter();
                    detailsModal.show();
                    try {
                        const response = await fetch('user_details.php?id=' + encodeURIComponent(button.dataset.userId) + '&context=request', {headers:{Accept:'application/json'}});
                        const payload = await response.json();
                        if (!response.ok || !payload.success) throw new Error(payload.message || 'Could not load account details.');
                        const u = payload.user || {};
                        const l = payload.license;
                        const h = payload.headquarter;
                        const licenseExpiryDate = String((l && l.license_last_valid_date) || '').slice(0, 10);
                        const currentDate = new Date();
                        const today = [currentDate.getFullYear(), String(currentDate.getMonth() + 1).padStart(2, '0'), String(currentDate.getDate()).padStart(2, '0')].join('-');
                        const licenseReviewWarning = licenseExpiryDate && licenseExpiryDate < today
                            ? '<div class="alert alert-warning py-2 mb-3" role="status"><i class="bi bi-exclamation-triangle-fill me-2"></i>Driver license expired on ' + escapeHtml(licenseExpiryDate) + '. Review a current license before recording an account decision.</div>'
                            : '';
                        const label = 'ACCT-' + escapeHtml(u.user_id || button.dataset.userId);
                        detailsTitle.innerHTML = '<i class="bi bi-person-check txt-neon-cyan me-2"></i> Account ' + label;
                        const roleNameSource = String(u.role_name || '');
                        const normalizedRoleName = roleNameSource.trim().toLowerCase();
                        const roleName = escapeHtml(['customer', 'driver'].includes(normalizedRoleName) ? 'Driver' : (['hq', 'headquarter', 'headquarters', 'company'].includes(normalizedRoleName) ? 'Company' : (roleNameSource || 'Account')));
                        const isPending = String((u.status || 'pending')).toLowerCase() !== 'approved';
                        const profileHeader = '<div class="acct-profile-header"><div class="acct-avatar">' + escapeHtml(initialsOf(u.user_name || u.email || 'U')) + '</div><div class="acct-profile-meta"><div class="acct-profile-name">' + escapeHtml(u.user_name || 'Name unavailable') + '</div><div class="acct-profile-sub"><i class="bi bi-envelope me-1"></i>' + escapeHtml(u.email || 'Not provided on file') + '</div><div class="d-flex gap-2 flex-wrap mt-2"><span class="acct-badge acct-badge-role"><i class="bi bi-person-badge"></i>' + roleName + '</span>' + (isPending ? '<span class="acct-badge acct-badge-pending"><i class="bi bi-hourglass-split"></i>Pending Verification</span>' : '') + '</div></div></div>';

                        const accountInfoSection = '<div class="acct-section"><div class="acct-section-title"><i class="bi bi-person-vcard"></i>Account Info</div>' + fieldRow('bi-person', 'Full Name', escapeHtml(u.user_name || 'Not available')) + fieldRow('bi-envelope-at', 'Email', escapeHtml(u.email || 'Not available')) + fieldRow('bi-shield-lock', 'Role', '#' + escapeHtml(u.role_id || '') + ' &middot; ' + roleName) + fieldRow('bi-calendar3', 'Submitted', escapeHtml(formatDate(u.created_at))) + '</div>';

                        const verificationSection = '<div class="acct-section"><div class="acct-section-title"><i class="bi bi-patch-check"></i>Verification Status</div>' + fieldRow('bi-person-check', 'Account Status', '<span class="request-status request-status-pending">Pending Verification</span>') + fieldRow('bi-card-checklist', 'License Status', '<span class="request-status ' + statusClass((l && l.status) || 'pending') + '">' + escapeHtml(statusLabel((l && l.status) || 'pending')) + '</span>') + '</div>';

                        const licenseBlock = l ? '<div class="acct-section"><div class="acct-section-title"><i class="bi bi-card-heading"></i>Driver License Details</div><div class="row g-3">' +
                            '<div class="col-12 col-md-6">' + fieldRow('bi-hash', 'License Number', escapeHtml(l.license_number || 'Not provided')) + '</div>' +
                            '<div class="col-12 col-md-6">' + fieldRow('bi-person-lines-fill', 'License Name', escapeHtml(l.license_name || 'Not provided')) + '</div>' +
                            '<div class="col-12 col-md-6">' + fieldRow('bi-telephone', 'Phone', escapeHtml(l.phone_number || 'Not provided')) + '</div>' +
                            '<div class="col-12 col-md-6">' + fieldRow('bi-person-vcard', 'NRC', escapeHtml(l.nrc_number || 'Not provided')) + '</div>' +
                            '<div class="col-12 col-md-6">' + fieldRow('bi-cake2', 'Date of Birth', escapeHtml(l.date_of_birth || 'Not provided')) + '</div>' +
                            '<div class="col-12 col-md-6">' + fieldRow('bi-droplet', 'Blood Type', escapeHtml(l.blood_type || 'Not provided')) + '</div>' +
                            '<div class="col-12 col-md-6">' + fieldRow('bi-calendar-check', 'Valid Until', escapeHtml(l.license_last_valid_date || 'Not provided')) + '</div>' +
                            '</div><div class="mt-2"><div class="request-detail-label mb-2">Uploaded Documents</div><div class="acct-doc-grid">' + docCard(l.license_image, 'License image', 'bi-card-image') + docCard(l.face_image, 'Face photo', 'bi-person-bounding-box') + '</div></div></div>'
                            : '<div class="acct-section"><div class="acct-empty-note"><i class="bi bi-info-circle txt-neon-cyan"></i>No driver license has been linked to this account yet.</div></div>';

                        const companyBlock = h ? '<div class="acct-section"><div class="acct-section-title"><i class="bi bi-building"></i>Company Details</div><div class="row g-3">' +
                            '<div class="col-12 col-md-6">' + fieldRow('bi-building', 'Company', escapeHtml(h.company_name || u.headquarter_name || 'Not provided')) + '</div>' +
                            '<div class="col-12 col-md-6">' + fieldRow('bi-envelope', 'Company email', escapeHtml(h.headquarter_email || 'Not provided')) + '</div>' +
                            '<div class="col-12 col-md-6">' + fieldRow('bi-telephone-outbound', 'Contact', escapeHtml(h.contact_number || 'Not provided')) + '</div>' +
                            '<div class="col-12 col-md-6">' + fieldRow('bi-geo-alt', 'Location', escapeHtml(h.location || u.headquarter_location || 'Not provided')) + '</div>' +
                            '<div class="col-12 col-md-6">' + fieldRow('bi-file-earmark-ruled', 'Business License', escapeHtml(h.business_license || 'Not provided')) + '</div>' +
                            '<div class="col-12 col-md-6">' + fieldRow('bi-calendar-x', 'License Expiry', escapeHtml(h.business_license_expire_date || h.expire_at || 'Not provided')) + '</div>' +
                            '<div class="col-12">' + fieldRow('bi-chat-square-text', 'Company Bio', escapeHtml(h.headquarter_bio || 'Not provided')) + '</div>' +
                            '</div><div class="mt-2"><div class="request-detail-label mb-2">Uploaded Documents</div><div class="acct-doc-grid">' + docCard(h.company_logo, 'Company logo', 'bi-image') + docCard(h.business_license_image, 'License / receipt', 'bi-file-earmark-text') + '</div></div></div>'
                            : '';

                        detailsBody.innerHTML = profileHeader + accountInfoSection + verificationSection + licenseReviewWarning + licenseConflictMarkup(l, payload.license_conflicts || []) + licenseBlock + companyBlock;
                        configurePairDecisionForm(
                            detailsBody.querySelector('[data-license-conflict-form]'),
                            'driver',
                            payload.license_conflicts?.[0]?.license_id
                        );
                        markUnavailableDocImages(detailsBody);
                        detailsBody.querySelector('[data-license-compare-toggle]')?.addEventListener('click', (event) => {
                            const button = event.currentTarget;
                            const comparison = detailsBody.querySelector('[data-license-comparison]');
                            if (!comparison) return;
                            comparison.hidden = !comparison.hidden;
                            button.setAttribute('aria-expanded', String(!comparison.hidden));
                            button.innerHTML = comparison.hidden ? '<i class="bi bi-layout-split me-1"></i>Compare' : '<i class="bi bi-eye-slash me-1"></i>Hide comparison';
                            markUnavailableDocImages(comparison);
                        });
                        detailsBody.querySelector('[data-conflict-decision]')?.addEventListener('change', (event) => {
                            const reasonField = detailsBody.querySelector('[data-conflict-reason]');
                            const reasonInput = reasonField?.querySelector('textarea');
                            const blocking = event.currentTarget.value !== 'allow_all';
                            if (reasonField) reasonField.hidden = !blocking;
                            if (reasonInput) reasonInput.required = blocking;
                        });
                        if (String(u.status || '').toLowerCase() === 'pending_verification') {
                            setFooter('<form action="account_review_action.php" method="POST" class="m-0"><input type="hidden" name="user_id" value="' + escapeHtml(u.user_id) + '"><input type="hidden" name="action" value="approve"><input type="hidden" name="return_to" value="view_requests.php"><button type="submit" class="request-action-btn request-action-btn--approve"><i class="bi bi-check-lg"></i>Approve</button></form><button type="button" class="request-action-btn request-action-btn--reject js-modal-reject"><i class="bi bi-x-lg"></i>Reject</button>');
                            detailsFooter.querySelector('.js-modal-reject').addEventListener('click', () => rejectTarget('account', u.user_id, label, u.user_name || u.email || 'Unknown user'));
                        }
                        if (String(u.status || '').toLowerCase() === 'pending_verification' && (payload.license_conflicts || []).length) {
                            setFooter('<button type="button" class="request-action-btn request-action-btn--approve" data-manage-driver-duplicate><i class="bi bi-person-gear"></i>Manage this account</button>');
                            detailsFooter.querySelector('[data-manage-driver-duplicate]')?.addEventListener('click', () => {
                                revealPairDecision(detailsBody.querySelector('[data-license-conflict-form]'));
                            });
                        }
                    } catch (error) {
                        detailsBody.innerHTML = '<div class="alert request-flash alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>' + escapeHtml(error.message) + '</div>';
                    }
            });

            document.addEventListener('click', async (event) => {
                const button = event.target.closest('.js-view-car-license');
                if (!button) return;
                detailsTitle.innerHTML = '<i class="bi bi-car-front txt-neon-cyan me-2"></i> Car License Review';
                detailsBody.innerHTML = '<div class="text-center text-white-50 py-5">Loading car-license details…</div>';
                setFooter();
                detailsModal.show();
                try {
                    const response = await fetch('car_license_details.php?id=' + encodeURIComponent(button.dataset.carLicenseId), { headers: { Accept: 'application/json' } });
                    const payload = await response.json();
                    if (!response.ok || !payload.success) throw new Error(payload.message || 'Could not load car-license details.');
                    const car = payload.car || {};
                    const conflicts = payload.conflicts || [];
                    const owners = (items) => (items || []).map((owner) => escapeHtml(owner.user_name || 'Name unavailable') + ' (' + escapeHtml(owner.email || 'Not provided') + ')').join('<br>') || '<span class="text-white-50">No driver is linked.</span>';
                    const card = (title, record, ownerList = []) => '<div class="col-12 col-lg-6"><section class="acct-section h-100"><div class="acct-section-title">' + escapeHtml(title) + '</div>' + fieldRow('bi-person', 'Driver account(s)', owners(ownerList)) + fieldRow('bi-person-vcard', 'Registered owner', escapeHtml(record.car_owner_name || 'Not provided')) + fieldRow('bi-hash', 'Plate number', escapeHtml(record.car_plate_number || 'Not provided')) + fieldRow('bi-car-front', 'Vehicle', escapeHtml([record.vehicle_name, record.vehicle_type].filter(Boolean).join(' · ') || 'Not provided')) + fieldRow('bi-upc-scan', 'Chassis number', escapeHtml(record.chassis_number || 'Not provided')) + fieldRow('bi-card-checklist', 'Status', escapeHtml(record.status || 'pending')) + '<div class="request-detail-label mb-2">License images</div><div class="acct-doc-grid">' + docCard(record.front_image, 'Front image', 'bi-card-image') + docCard(record.back_image, 'Back image', 'bi-card-image') + '</div></section></div>';
                    const decisionForm = '<form action="license_conflict_action.php" method="POST" class="acct-section mt-3" data-conflict-decision-form><input type="hidden" name="license_type" value="car"><input type="hidden" name="record_id" value="' + escapeHtml(car.car_license_id) + '"><label class="form-label text-white-50 small" for="car-license-decision">Decision for matching plates</label><select class="form-select glass-select" id="car-license-decision" name="decision" data-conflict-decision><option value="allow_all">Allow all matching plates</option><option value="block_current">Block this plate; allow the other matches</option><option value="block_all">Block all matching plates</option></select><div class="mt-3" data-conflict-reason hidden><label class="form-label text-white-50 small" for="car-license-reason">Reason required for blocking</label><textarea class="form-control glass-input" id="car-license-reason" name="reason" rows="3" maxlength="2000" placeholder="Explain why the duplicate car-license record(s) are blocked."></textarea></div><button type="submit" class="btn btn-warning mt-3">Confirm license decision and notify affected drivers</button></form>';
                    const conflictMarkup = conflicts.length
                        ? '<div class="alert alert-warning d-flex align-items-center justify-content-between gap-3 flex-wrap"><span><i class="bi bi-exclamation-triangle-fill me-2"></i>Same normalized plate found on ' + conflicts.length + ' other record(s).</span><button type="button" class="btn btn-sm btn-outline-warning" data-car-compare-toggle aria-expanded="false"><i class="bi bi-layout-split me-1"></i>Compare</button></div><div data-car-comparison hidden><div class="row g-3">' + card('Current · CAR-' + car.car_license_id, car, car.owners) + conflicts.map((match) => card('Matching record · CAR-' + match.car_license_id, match, match.user_id ? [{ user_id: match.user_id, user_name: match.user_name, email: match.email }] : [])).join('') + '</div>' + decisionForm + '</div>'
                        : '<div class="alert alert-success">No plate records match this search.</div>';
                    detailsBody.innerHTML = '<div class="acct-section">' + fieldRow('bi-hash', 'Plate number', escapeHtml(car.car_plate_number || '')) + fieldRow('bi-person', 'Driver account(s)', owners(car.owners)) + fieldRow('bi-calendar', 'Submitted', escapeHtml(formatDate(car.created_at))) + '</div>' + conflictMarkup;
                    configurePairDecisionForm(
                        detailsBody.querySelector('[data-conflict-decision-form]'),
                        'car',
                        conflicts[0]?.car_license_id
                    );
                    detailsBody.querySelector('[data-car-compare-toggle]')?.addEventListener('click', (compareEvent) => {
                        const compareButton = compareEvent.currentTarget;
                        const comparison = detailsBody.querySelector('[data-car-comparison]');
                        if (!comparison) return;
                        comparison.hidden = !comparison.hidden;
                        compareButton.setAttribute('aria-expanded', String(!comparison.hidden));
                        compareButton.innerHTML = comparison.hidden ? '<i class="bi bi-layout-split me-1"></i>Compare' : '<i class="bi bi-eye-slash me-1"></i>Hide comparison';
                        markUnavailableDocImages(comparison);
                    });
                    detailsBody.querySelector('[data-conflict-decision]')?.addEventListener('change', (decisionEvent) => {
                        const reasonField = detailsBody.querySelector('[data-conflict-reason]');
                        const reasonInput = reasonField?.querySelector('textarea');
                        const blocking = decisionEvent.currentTarget.value !== 'allow_all';
                        if (reasonField) reasonField.hidden = !blocking;
                        if (reasonInput) reasonInput.required = blocking;
                    });
                    markUnavailableDocImages(detailsBody);
                    const carId = escapeHtml(car.car_license_id);
                    const isPendingCar = ['pending', 'pending_verification'].includes(String(car.status || '').toLowerCase());
                    setFooter(!isPendingCar
                        ? ''
                        : '<form action="car_license_review_action.php" method="POST" class="m-0"><input type="hidden" name="car_license_id" value="' + carId + '"><input type="hidden" name="action" value="approve"><input type="hidden" name="return_to" value="view_requests.php"><button type="submit" class="request-action-btn request-action-btn--approve">Approve</button></form><form action="car_license_review_action.php" method="POST" class="m-0"><input type="hidden" name="car_license_id" value="' + carId + '"><input type="hidden" name="action" value="reject"><input type="hidden" name="return_to" value="view_requests.php"><button type="submit" class="request-action-btn request-action-btn--reject">Reject</button></form>');
                    if (isPendingCar && conflicts.length) {
                        setFooter('<button type="button" class="request-action-btn request-action-btn--approve" data-manage-car-duplicate><i class="bi bi-person-gear"></i>Manage this account</button>');
                        detailsFooter.querySelector('[data-manage-car-duplicate]')?.addEventListener('click', () => {
                            revealPairDecision(detailsBody.querySelector('[data-conflict-decision-form]'));
                        });
                    }
                } catch (error) {
                    detailsBody.innerHTML = '<div class="alert request-flash alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>' + escapeHtml(error.message) + '</div>';
                }
            });
        })();
    </script>
    <?php if ($accountReviewSuccess): ?>
        <script>
            (() => {
                const successModal = document.getElementById('accountReviewSuccessModal');
                if (successModal) {
                    window.createAdminModalController(successModal).show();
                }
            })();
        </script>
    <?php endif; ?>
    <script>
        (() => {
            const modalElement = document.getElementById('paymentDetailsModal');
            const modal = window.createAdminModalController(modalElement);
            const body = document.getElementById('paymentDetailsBody');
            const footer = document.getElementById('paymentDetailsFooter');
            const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
            document.addEventListener('click', (event) => {
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
                const statusLabel = data.paymentStatus === 'pending_payment'
                    ? 'Awaiting payer payment · not yet reported'
                    : (data.paymentStatus === 'payer_reported_paid'
                        ? 'Payer reported paid · Awaiting independent verification'
                        : (data.paymentStatus === 'received' ? 'Payment received' : 'Payment not received'));
                body.innerHTML = '<div class="row g-4"><div class="col-12 col-lg-5"><h6 class="fw-semibold text-white mb-3" style="letter-spacing:.5px;font-size:.78rem;text-transform:uppercase">Payment Information</h6><div class="mb-3"><div class="request-detail-label">Payer / Company</div><div class="request-detail-value">' + escape(data.paymentName) + '</div></div><div class="mb-3"><div class="request-detail-label">Email</div><div class="request-detail-value">' + escape(data.paymentEmail || 'Not provided') + '</div></div><div class="mb-3"><div class="request-detail-label">Payment Type</div><div class="request-detail-value">' + escape(data.paymentType) + '</div></div><div class="mb-3"><div class="request-detail-label">Amount</div><div class="request-detail-value">' + escape(data.paymentAmount) + ' MMK</div></div><div class="mb-3"><div class="request-detail-label">Payment Method</div><div class="request-detail-value">' + escape(data.paymentMethod || 'Not provided') + '</div></div><div class="mb-3"><div class="request-detail-label">Submitted</div><div class="request-detail-value">' + escape(data.paymentDate) + '</div></div><div class="mb-3"><div class="request-detail-label">Status</div><div class="request-detail-value">' + escape(statusLabel) + '</div></div><div class="mb-3"><div class="request-detail-label">Description</div><div class="request-detail-value">' + escape(data.paymentDescription || 'No additional details provided.') + '</div></div></div><div class="col-12 col-lg-7"><h6 class="fw-semibold text-white mb-3" style="letter-spacing:.5px;font-size:.78rem;text-transform:uppercase">Payment Evidence</h6><div class="request-detail-value p-3 rounded-3" style="background:rgba(0,244,255,.04);border:1px solid var(--glass-border);min-height:220px">' + evidence + '</div><div class="small text-white-50 mt-2">A payer declaration or screenshot is evidence for review, not proof of received money.</div></div></div>';
                const actions = document.getElementById('paymentActions' + data.paymentId);
                footer.innerHTML = actions ? actions.innerHTML : (data.paymentStatus === 'payer_reported_paid' && data.isPayer === 'yes' ? '<div class="small text-warning me-auto">You submitted this payment. Another Admin must review it.</div>' : '');
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
    <script>document.addEventListener('pointermove',(event)=>{document.body.style.setProperty('--bg-x',`${(event.clientX/window.innerWidth)*100}%`);document.body.style.setProperty('--bg-y',`${(event.clientY/window.innerHeight)*100}%`);});</script>
</body>
</html>