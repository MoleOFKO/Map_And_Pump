<?php
require_once __DIR__ . '/admin_common.php';
require_once __DIR__ . '/user_account_common.php';

admin_require_admin();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$flash = hq_pull_flash();
$search = trim((string) ($_GET['search'] ?? ''));
$status = strtolower(trim((string) ($_GET['status'] ?? '')));
$expiryFilter = strtolower(trim((string) ($_GET['expiry'] ?? '')));
if (!in_array($expiryFilter, ['warning', 'expired', 'any'], true)) {
    $expiryFilter = '';
}
// Status keywords from live customer/user rows (plus common actionable values).
$allowedStatuses = customer_account_status_options();
$status = ($status !== '' && in_array($status, $allowedStatuses, true)) ? $status : '';
$userRows = customer_account_rows($search, $status, $expiryFilter);
$duplicateDriverIds = [];
foreach ($userRows as $userRow) {
    if ((int) ($userRow['license_conflict_count'] ?? 0) > 0) {
        $duplicateDriverIds[] = (int) $userRow['user_id'];
    }
}
$dbError = hq_db_available() ? null : hq_db_error_message();
$flashType = (string) ($flash['type'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="View and manage driver accounts in the Map &amp; Pump admin portal.">
    <title>View/Manage Drivers — Map &amp; Pump</title>
    <link rel="icon" type="image/png" href="/images/Logo.png"><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&amp;display=swap" rel="stylesheet"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"><link rel="stylesheet" href="/css/Admin.css"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
        .hq-status { display:inline-flex; align-items:center; gap:.45rem; color:rgba(255,255,255,.68); font-size:.8rem; white-space:nowrap; }
        .hq-status::before { content:''; width:8px; height:8px; border-radius:50%; display:inline-block; }
        .hq-status-active::before { background:#79aaff; box-shadow:0 0 8px #79aaff; }
        .hq-status-pending::before { background:#ffc107; box-shadow:0 0 8px #ffc107; }
        .hq-status-suspended::before { background:#ff9f43; box-shadow:0 0 8px #ff9f43; }
        .hq-status-removed::before { background:#ff4d6a; box-shadow:0 0 8px #ff4d6a; }
        .hq-flash { border-radius:14px; background:rgba(10,22,44,.82); border:1px solid var(--glass-border); color:var(--text-primary); }
        .hq-flash.alert-success { border-color:rgba(121,170,255,.4); }
        .hq-flash.alert-danger { border-color:rgba(255,77,106,.45); }
        body.background_color.light-mode .hq-flash { background:rgba(255,255,255,.9); }
        body.background_color.light-mode .hq-status { color:var(--text-muted); }
        .mod-detail-label { color:var(--text-muted); font-size:.72rem; text-transform:uppercase; letter-spacing:.07em; }
        .mod-detail-value { color:var(--text-primary); word-break:break-word; }
        .hq-modal-table { --bs-table-bg:transparent; --bs-table-color:var(--text-primary); --bs-table-border-color:var(--glass-border); }
        .hq-modal-table th { color:var(--text-muted); font-size:.72rem; text-transform:uppercase; letter-spacing:.05em; }
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
<style id="uniform-detail-boxes">
/* Consistent detail cards in the Companies and Drivers popups only. */
.admin-blue-page :is(#hqDetailsBody,#userDetailsBody) .row:has(> div > .p-3.rounded-3 > :is(.hq-detail-label,.mod-detail-label)) {
    display: grid;
    grid-template-columns: repeat(3,minmax(0,1fr));
    grid-auto-rows: 1fr;
    gap: 16px;
    margin-left: 0;
    margin-right: 0;
    margin-top: 0;
}
.admin-blue-page :is(#hqDetailsBody,#userDetailsBody) .row:has(> div > .p-3.rounded-3 > :is(.hq-detail-label,.mod-detail-label)) > div {
    width: auto;
    max-width: none;
    min-width: 0;
    margin-top: 0;
    padding: 0;
    display: flex;
}
.admin-blue-page :is(#hqDetailsBody,#userDetailsBody) .row > div > .p-3.rounded-3:has(> :is(.hq-detail-label,.mod-detail-label)) {
    display: flex;
    flex-direction: column;
    align-items: stretch;
    justify-content: flex-start;
    width: 100%;
    height: 100% !important;
    min-height: 116px;
    padding: 18px !important;
    border-radius: 10px !important;
    overflow-wrap: anywhere;
}
.admin-blue-page :is(#hqDetailsBody,#userDetailsBody) :is(.hq-detail-value,.mod-detail-value) {
    min-width: 0;
    overflow-wrap: anywhere;
    word-break: normal;
    line-height: 1.65;
}
/* Comparison panels stay a balanced pair, rather than becoming a 3-column grid. */
.admin-blue-page #userDetailsBody .row:has(> div > .p-3.rounded-3 > .mod-detail-label.mb-2) {
    grid-template-columns: repeat(2,minmax(0,1fr));
}
@media (max-width: 991.98px) {
    .admin-blue-page :is(#hqDetailsBody,#userDetailsBody) .row:has(> div > .p-3.rounded-3 > :is(.hq-detail-label,.mod-detail-label)) { grid-template-columns: repeat(2,minmax(0,1fr)); }
}
@media (max-width: 575.98px) {
    .admin-blue-page :is(#hqDetailsBody,#userDetailsBody) .row:has(> div > .p-3.rounded-3 > :is(.hq-detail-label,.mod-detail-label)) { grid-template-columns: minmax(0,1fr); gap: 12px; }
    .admin-blue-page :is(#hqDetailsBody,#userDetailsBody) .row > div > .p-3.rounded-3:has(> :is(.hq-detail-label,.mod-detail-label)) { min-height: 104px; padding: 16px !important; }
}
</style>
</head>
<body class="background_color admin-blue-page" id="background_color">
<?php $adminSection = 'view_users'; include __DIR__ . '/admin_section_nav.php'; ?>

<main class="container my-5 admin-blue-shell">
    <?php if ($flash && $flashType): ?><div class="alert hq-flash alert-<?= hq_escape($flashType) ?> alert-dismissible fade show mb-4" role="alert"><i class="bi <?= $flashType === 'success' ? 'bi-check-circle-fill text-success' : 'bi-exclamation-triangle-fill text-danger' ?> me-2"></i><?= hq_escape((string) ($flash['message'] ?? '')) ?><button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button></div><?php endif; ?>
    <?php if ($dbError): ?><div class="alert hq-flash alert-danger mb-4"><i class="bi bi-database-exclamation me-2"></i><?= hq_escape($dbError) ?></div><?php endif; ?>
    <?php if (function_exists('expiry_render_page_banners')) { expiry_render_page_banners('admin', true); } ?>
    <div class="admin-blue-header d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3"><div><span class="stat-chip mb-2"><i class="bi bi-person-vcard-fill txt-neon-cyan"></i> Driver Administration</span><h1 class="fw-bold m-0 h4 text-white mt-2">View/Manage Drivers</h1>
<p class="admin-blue-description">Manage driver accounts, check license status, and review linked company details.</p></div></div>
    <div class="dashboard-card admin-filter-card mb-4">
        <form class="admin-filter-form" method="GET" action="view_users.php">
            <div class="filter-group filter-group--search">
                <label class="filter-label" for="userSearch">Search</label>
                <div class="filter-search">
                    <i class="bi bi-search filter-search-icon" aria-hidden="true"></i>
                    <input id="userSearch" type="search" class="form-control filter-control filter-input" placeholder="Search by driver name, email, or company..." name="search" value="<?= hq_escape($search) ?>">
                </div>
            </div>
            <div class="filter-group">
                <label class="filter-label" for="userStatus">Status</label>
                <div class="filter-select-wrap"><select id="userStatus" class="form-select filter-control filter-select" name="status">
                    <option value="">All Status</option>
                    <?php foreach ($allowedStatuses as $option): ?>
                        <option value="<?= $option ?>" <?= $status === $option ? 'selected' : '' ?>><?= hq_escape(admin_filter_label($option)) ?></option>
                    <?php endforeach; ?>
                </select></div>
            </div>
            <div class="filter-group">
                <?= function_exists('expiry_filter_select_html') ? expiry_filter_select_html($expiryFilter, 'userExpiryFilter', 'hq_escape') : '' ?>
            </div>
            <div class="filter-actions">
                <button type="button" class="btn-clear-filters js-clear-filters" title="Clear filters">Clear</button>
            </div>
        </form>
    </div>
    <div class="analytics-card"><div class="table-responsive user-table-scroll"><table class="table table-dark-custom align-middle m-0"><thead><tr><th>Driver Account</th><th>Email</th><th>Company</th><th>Account Type</th><th>Status</th><th class="text-end">Actions</th></tr></thead><tbody>
    <?php if (!$userRows): ?><tr><td colspan="6" class="text-center text-white-50 py-5"><i class="bi bi-person-x fs-3 d-block mb-2"></i>No driver accounts match the current filter.</td></tr><?php endif; ?>
    <?php foreach ($userRows as $row):
        $userStatus = strtolower(trim((string) ($row['status'] ?? 'pending')));
        $contactEmail = trim((string) ($row['email'] ?? ''));
        $showContact = in_array($userStatus, ['disabled', 'removed', 'suspended'], true)
            && filter_var($contactEmail, FILTER_VALIDATE_EMAIL);
    ?>
        <tr>
            <td><div class="d-flex align-items-center gap-3"><div class="rounded-3 d-flex align-items-center justify-content-center fw-bold" style="width:44px;height:44px;background:rgba(0,244,255,.1);border:1px solid rgba(0,244,255,.28);color:var(--neon-cyan)"><?= hq_escape(hq_initials((string) ($row['user_name'] ?? 'Driver'))) ?></div><div><div class="fw-semibold text-white"><?= hq_escape((string) ($row['user_name'] ?? 'Name unavailable')) ?></div><div class="text-white-50 small">Driver ID: <?= hq_escape((string) (trim((string) ($row['user_public_id'] ?? '')) ?: 'Not available')) ?></div></div></div></td>
            <td class="text-white-50"><?php if ($showContact): ?><a href="mailto:<?= hq_escape($contactEmail) ?>" class="text-white-50 text-decoration-none"><i class="bi bi-envelope me-1" aria-hidden="true"></i><?= hq_escape($contactEmail) ?></a><?php else: ?>—<?php endif; ?></td>
            <td class="text-white-50"><?= hq_escape((string) ($row['headquarter_name'] ?? 'Not linked')) ?></td>
            <td class="text-white-50">Driver</td>
            <td><span class="hq-status <?= hq_escape(hq_status_class($userStatus)) ?>"><?= hq_escape(hq_status_label($userStatus)) ?></span><?php if (function_exists("expiry_badge_html")): ?><div class="mt-1"><?= expiry_badge_html(isset($row["license_last_valid_date"]) ? (string) $row["license_last_valid_date"] : null) ?></div><?php endif; ?></td>
            <td class="text-end"><div class="d-flex gap-2 justify-content-end"><a href="#" class="btn-action js-view-user" title="View driver details" data-user-id="<?= (int) $row['user_id'] ?>"><i class="bi bi-eye-fill"></i></a><?php if ($userStatus === 'pending_verification'): ?><a href="view_requests.php?type=account&amp;status=pending" class="btn-action" title="Open account verification request"><i class="bi bi-arrow-up-right-circle-fill"></i></a><?php elseif (!empty($row['staff_access_protected'])): ?><span class="badge rounded-pill bg-secondary bg-opacity-25 text-white-50" title="Account changes are unavailable for Admin and Moderator accounts">Restricted</span><?php else: ?><button type="button" class="btn-action btn-action-danger js-user-action" title="Manage driver account" data-user-id="<?= (int) $row['user_id'] ?>" data-user-name="<?= hq_escape((string) $row['user_name']) ?>"><i class="bi bi-gear-fill"></i></button><?php endif; ?></div></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
</main>
<div class="modal fade glass-modal" id="userActionModal" tabindex="-1" aria-labelledby="userActionLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title fw-bold" id="userActionLabel"><i class="bi bi-shield-exclamation text-danger me-2"></i> Manage driver account</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><form action="user_account_action.php" method="POST"><div class="modal-body d-flex flex-column gap-3"><input type="hidden" name="user_id" id="userActionId"><input type="hidden" name="return_to" value="view_users.php"><div class="small text-white-50">Driver: <span class="txt-neon-cyan fw-semibold" id="userActionName">Selected driver</span></div><div><label class="form-label text-white-50 small" for="userActionType">Account action</label><select class="form-select glass-select account-action-select" name="action_type" id="userActionType" required><option value="suspend">Suspend account</option><option value="remove">Remove account</option><option value="restore">Restore account</option></select></div><div><label class="form-label text-white-50 small" for="userActionReason">Reason <span class="text-danger">*</span></label><textarea class="form-control glass-input" name="reason" id="userActionReason" rows="5" maxlength="2000" required></textarea></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-3 text-white-50" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger">Apply action</button></div></form></div></div></div>

<!-- User details modal (loaded via AJAX) -->
<div class="modal fade glass-modal" id="userDetailsModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg"><div class="modal-content"><div class="modal-header"><h5 class="modal-title fw-bold"><i class="bi bi-person-check txt-neon-cyan me-2"></i> Driver Details</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body" id="userDetailsBody"><div class="text-center text-white-50 py-5"><span class="spinner-border spinner-border-sm me-2"></span>Loading driver details...</div></div></div></div></div>

<footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map &amp; Pump | All Rights Reserved</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="/js/Admin.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const duplicateDriverIds = new Set(<?= json_encode($duplicateDriverIds, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
    const applyDuplicateBadges = (root = document) => {
        root.querySelectorAll('.js-view-user').forEach((button) => {
            if (!duplicateDriverIds.has(Number(button.dataset.userId))) return;
            const statusCell = button.closest('tr')?.children[4];
            if (!statusCell || statusCell.querySelector('[data-duplicate-driver-badge]')) return;
            const badge = document.createElement('span');
            badge.className = 'badge bg-warning text-dark mt-1';
            badge.dataset.duplicateDriverBadge = 'true';
            badge.textContent = 'Duplicate license/NRC';
            statusCell.appendChild(badge);
        });
    };
    applyDuplicateBadges();
    const userTableBody = document.querySelector('.user-table-scroll tbody');
    if (userTableBody) {
        new MutationObserver(() => applyDuplicateBadges(userTableBody)).observe(userTableBody, { childList: true, subtree: true });
    }

    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('userActionModal'));
    const actionTypeEl = document.getElementById('userActionType');
    const actionSubmitBtn = document.querySelector('#userActionModal button[type="submit"]');
    function syncUserActionSubmit() {
        if (!actionTypeEl || !actionSubmitBtn) return;
        const kind = actionTypeEl.value;
        actionSubmitBtn.textContent = kind === 'remove' ? 'Confirm removal' : (kind === 'restore' ? 'Confirm restoration' : 'Confirm suspension');
        actionSubmitBtn.classList.toggle('btn-danger', kind !== 'restore');
        actionSubmitBtn.classList.toggle('btn-neon', kind === 'restore');
    }
    actionTypeEl?.addEventListener('change', syncUserActionSubmit);

    // Event delegation so row actions still work after live-filter AJAX table swaps.
    document.addEventListener('click', (event) => {
        const button = event.target.closest('.js-user-action');
        if (!button) return;
        document.getElementById('userActionId').value = button.dataset.userId || '';
        document.getElementById('userActionName').textContent = button.dataset.userName || 'Selected driver';
        document.getElementById('userActionReason').value = '';
        if (actionTypeEl) actionTypeEl.value = 'suspend';
        syncUserActionSubmit();
        modal.show();
    });

    // User details view (AJAX)
    const detailsModalEl = document.getElementById('userDetailsModal');
    const detailsModal = detailsModalEl ? new bootstrap.Modal(detailsModalEl) : null;
    const detailsBody = document.getElementById('userDetailsBody');
    const esc = value => String(value ?? '').replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character]));
    const contactLink = (email, status) => {
        const address = String(email || '').trim();
        const canContact = ['disabled', 'removed', 'suspended'].includes(String(status || '').toLowerCase());
        return canContact && address
            ? '<a href="mailto:' + encodeURIComponent(address) + '">' + esc(address) + ' <i class="bi bi-envelope" aria-hidden="true"></i></a>'
            : 'Not available for this account status';
    };
    const date = value => value ? new Date(String(value).replace(' ', 'T')).toLocaleString() : 'Not available';

    const statusClass = (status) => ({active:'hq-status-active', approved:'hq-status-active', suspended:'hq-status-suspended', removed:'hq-status-removed'}[String(status || '').toLowerCase()] || 'hq-status-pending');
    const statusLabel = (status) => ({active:'Approved', approved:'Approved', suspended:'Suspended', removed:'Removed', pending:'Pending', pending_verification:'Pending Verification'}[String(status || '').toLowerCase()] || (status ? String(status) : 'Unknown'));
    
    <?= function_exists('expiry_js_detail_helper') ? expiry_js_detail_helper() : 'function expiryDetailAlert(){return "";}' ?>
    const card = (label, value) =>
        '<div class="p-3 rounded-3 h-100" style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);">' +
        '<div class="mod-detail-label">' + label + '</div>' +
        '<div class="mod-detail-value mt-1 text-break">' + value + '</div></div>';

    document.addEventListener('click', async event => {
        const button = event.target.closest('.js-view-user');
        if (!button) return;
        event.preventDefault();
        if (!detailsModal) return;
        detailsBody.innerHTML = '<div class="text-center text-white-50 py-5"><span class="spinner-border spinner-border-sm me-2"></span>Loading driver details...</div>';
        detailsModal.show();
        try {
            const response = await fetch('user_details.php?id=' + encodeURIComponent(button.dataset.userId) + '&context=compare', { headers: { Accept: 'application/json' }, cache: 'no-store' });
            const payload = await response.json();
            if (!response.ok || !payload.success) throw new Error(payload.message || 'Unable to load driver details.');
            const u = payload.user || {};
            const license = payload.license || null;
            const hq = payload.headquarter || null;
            const actions = payload.actions || [];
            const conflicts = payload.license_conflicts || [];

            const profile =
                '<div class="d-flex align-items-center gap-3 mb-4 pb-3 border-bottom border-white border-opacity-10">' +
                '<div class="rounded-3 d-flex align-items-center justify-content-center fw-bold" style="width:52px;height:52px;background:rgba(0,244,255,.1);border:1px solid rgba(0,244,255,.28);color:var(--neon-cyan);font-size:1.1rem;">' +
                esc(String(u.user_name || 'U').slice(0, 2).toUpperCase()) +
                '</div><div><div class="fw-semibold text-white fs-5">' + esc(u.user_name || 'Name unavailable') + '</div>' +
                '<div class="text-white-50 small">Driver ID ' + esc(u.user_public_id || 'Not available') + ' · <span class="hq-status ' + statusClass(u.status) + '">' + esc(statusLabel(u.status)) + '</span></div></div></div>';

            const accountGrid =
                '<div class="row g-3 mb-4">' +
                '<div class="col-12 col-sm-6 col-lg-4">' + card('Contact email', contactLink(u.email, u.status)) + '</div>' +
                '<div class="col-12 col-sm-6 col-lg-4">' + card('Account type', 'Driver') + '</div>' +
                '<div class="col-12 col-sm-6 col-lg-4">' + card('Company', esc(u.headquarter_name || 'Not linked')) + '</div>' +
                '<div class="col-6 col-sm-4 col-lg-4">' + card('Created', esc(date(u.created_at))) + '</div>' +
                '<div class="col-6 col-sm-4 col-lg-4">' + card('Updated', esc(date(u.updated_at))) + '</div>' +
                '<div class="col-12 col-sm-4 col-lg-4">' + card('Last login', esc(date(u.last_login_at))) + '</div>' +
                '</div>';

            let licenseBlock = '';
            if (license) {
                // Account view: minimum license fields only. NRC, DOB, blood type, phone are never shown.
                licenseBlock =
                    '<div class="d-flex align-items-center justify-content-between mb-2"><h6 class="text-white fw-semibold mb-0"><i class="bi bi-card-heading txt-neon-cyan me-2"></i>License</h6></div>' +
                    '<div class="row g-3 mb-4">' +
                    '<div class="col-12 col-sm-6 col-lg-4">' + card('License name', esc(license.license_name || '—')) + '</div>' +
                    '<div class="col-12 col-sm-6 col-lg-4">' + card('License number', esc(license.license_number || '—')) + '</div>' +
                    '<div class="col-6 col-sm-4 col-lg-4">' + card('Status', esc(license.status || '—')) + '</div>' +
                    '<div class="col-6 col-sm-4 col-lg-4">' + card('Valid until', esc(license.license_last_valid_date || '—')) + '</div>' +
                    '</div>';
            }

            let hqBlock = '';
            if (hq) {
                // Account view: company name + location only.
                hqBlock =
                    '<div class="d-flex align-items-center justify-content-between mb-2"><h6 class="text-white fw-semibold mb-0"><i class="bi bi-building txt-neon-cyan me-2"></i>Linked Company</h6></div>' +
                    '<div class="row g-3 mb-4">' +
                    '<div class="col-12 col-sm-6 col-lg-6">' + card('Company', esc(hq.company_name || '—')) + '</div>' +
                    '<div class="col-12 col-sm-6 col-lg-6">' + card('Location', esc(hq.location || '—')) + '</div>' +
                    '</div>';
            }

            const history =
                '<div class="d-flex align-items-center justify-content-between mb-2"><h6 class="text-white fw-semibold mb-0"><i class="bi bi-clock-history txt-neon-cyan me-2"></i>Account action history</h6>' +
                '<span class="badge rounded-pill" style="background:rgba(0,244,255,.12);color:#7eefff;border:1px solid rgba(0,244,255,.25);">' + actions.length + '</span></div>' +
                (actions.length
                    ? '<div class="table-responsive"><table class="table hq-modal-table align-middle mb-0"><thead><tr><th>Action</th><th>Reason</th><th>When</th></tr></thead><tbody>' +
                      actions.map(a =>
                          '<tr><td><span class="badge text-bg-dark border border-secondary border-opacity-25">' + esc(String(a.action_type || '').toUpperCase()) + '</span></td>' +
                          '<td class="text-white-50">' + esc(a.reason || '—') + '</td>' +
                          '<td class="text-white-50 small">' + esc(date(a.created_at)) + '</td></tr>'
                      ).join('') +
                      '</tbody></table></div>'
                    : '<div class="text-white-50 py-3"><i class="bi bi-inbox me-1"></i>No action history.</div>');

            let duplicateBlock = '';
            if (conflicts.length) {
                const existing = conflicts[0] || {};
                const matchTypes = Array.isArray(existing.duplicate_fields) && existing.duplicate_fields.length
                    ? existing.duplicate_fields.join(', ')
                    : 'Driver license number or NRC number';
                const docThumb = (path, label) => {
                    if (!path) return '';
                    return '<div class="mod-detail-value small mt-2">' + esc(label) + ': <a href="' + esc(path) + '" target="_blank" rel="noopener" class="txt-neon-cyan">View</a></div>';
                };
                duplicateBlock =
                    '<div class="alert alert-warning d-flex align-items-center justify-content-between gap-3 flex-wrap mb-4"><span><i class="bi bi-exclamation-triangle-fill me-2"></i>Automated conflict detection matched on: ' + esc(matchTypes) + '.</span></div>' +
                    '<div class="row g-3 mb-4">' +
                    '<div class="col-12 col-lg-6">' +
                    '<div class="p-3 rounded-3 h-100" style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);">' +
                    '<div class="mod-detail-label mb-2">Current driver</div>' +
                    '<div class="mod-detail-value fw-semibold mb-2">' + esc(u.user_name || 'Name unavailable') + '</div>' +
                    '<div class="mod-detail-value small text-white-50 mb-2">' + contactLink(u.email, u.status) + '</div>' +
                    '<div class="mod-detail-value small">License: ' + esc((license && license.license_number) || 'Not provided') + '</div>' +
                    '<div class="mod-detail-value small mt-1">NRC: ' + esc((license && license.nrc_number) || 'Not provided') + '</div>' +
                    '<div class="mod-detail-value small mt-1">Matched on: ' + esc(matchTypes) + '</div>' +
                    docThumb(license && license.license_image, 'License image') +
                    docThumb(license && license.face_image, 'Face image') +
                    '</div>' +
                    '</div>' +
                    '<div class="col-12 col-lg-6">' +
                    '<div class="p-3 rounded-3 h-100" style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);">' +
                    '<div class="mod-detail-label mb-2">Existing account</div>' +
                    '<div class="mod-detail-value fw-semibold mb-2">' + esc(existing.user_name || 'Name unavailable') + '</div>' +
                    '<div class="mod-detail-value small text-white-50 mb-2">' + contactLink(existing.email, existing.account_status) + '</div>' +
                    '<div class="mod-detail-value small">License: ' + esc(existing.license_number || 'Not provided') + '</div>' +
                    '<div class="mod-detail-value small mt-1">NRC: ' + esc(existing.nrc_number || 'Not provided') + '</div>' +
                    '<div class="mod-detail-value small mt-1">Matched on: ' + esc(matchTypes) + '</div>' +
                    docThumb(existing.license_image, 'License image') +
                    docThumb(existing.face_image, 'Face image') +
                    '</div>' +
                    '</div>' +
                    '</div>' +
                    '<form action="license_conflict_action.php" method="POST" class="p-3 rounded-3" style="background:rgba(255,193,7,.05);border:1px solid rgba(255,193,7,.25);">' +
                    '<input type="hidden" name="license_type" value="driver">' +
                    '<input type="hidden" name="record_id" value="' + esc(String((license && license.license_id) || u.license_id || '')) + '">' +
                    '<input type="hidden" name="existing_record_id" value="' + esc(String(existing.license_id || '')) + '">' +
                    '<label class="form-label text-white-50 small" for="duplicateDriverDecision">Duplicate decision</label>' +
                    '<select class="form-select glass-select mb-3" id="duplicateDriverDecision" name="decision" data-user-duplicate-decision>' +
                    '<option value="approve_new_disable_existing">Approve new driver and disable the existing account</option>' +
                    '<option value="keep_existing_reject_new">Keep the existing account and reject this new request</option>' +
                    '<option value="accept_both">Accept both accounts</option>' +
                    '<option value="disable_both">Disable both accounts</option>' +
                    '</select>' +
                    '<div data-user-duplicate-reason hidden>' +
                    '<label class="form-label text-white-50 small" for="duplicateDriverReason">Reason</label>' +
                    '<textarea class="form-control glass-input" id="duplicateDriverReason" name="reason" rows="3" maxlength="2000" placeholder="Explain why the duplicate driver account needs this action."></textarea>' +
                    '</div>' +
                    '<div class="d-flex gap-2 mt-3"><button type="submit" class="btn btn-warning">Confirm decision</button><button type="button" class="btn btn-outline-secondary" data-user-duplicate-cancel>Cancel</button></div>' +
                    '</form>';
            }

            detailsBody.innerHTML = expiryDetailAlert((license && license.license_last_valid_date) || '', 'license') + profile + accountGrid + duplicateBlock + licenseBlock + hqBlock + history;
            const decisionField = detailsBody.querySelector('[data-user-duplicate-decision]');
            const reasonField = detailsBody.querySelector('[data-user-duplicate-reason]');
            if (decisionField) {
                const sync = () => {
                    const disableChoice = ['approve_new_disable_existing', 'keep_existing_reject_new', 'disable_both'].includes(decisionField.value || '');
                    if (reasonField) reasonField.hidden = !disableChoice;
                    const reasonInput = detailsBody.querySelector('#duplicateDriverReason');
                    if (reasonInput) reasonInput.required = disableChoice;
                };
                decisionField.addEventListener('change', sync);
                sync();
            }
            detailsBody.querySelector('[data-user-duplicate-cancel]')?.addEventListener('click', () => {
                const form = detailsBody.querySelector('[action="license_conflict_action.php"]');
                if (form) form.hidden = true;
            });
        } catch (error) {
            detailsBody.innerHTML = '<div class="alert alert-danger">'+esc(error.message)+'</div>';
        }
    });

});
</script>
</body>
</html>
