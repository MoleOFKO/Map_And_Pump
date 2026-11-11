<?php
require_once __DIR__ . '/admin_common.php';

admin_require_admin();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$flash = hq_pull_flash();
$search = trim((string) ($_GET['search'] ?? ''));
$region = strtolower(trim((string) ($_GET['region'] ?? '')));
$status = strtolower(trim((string) ($_GET['status'] ?? '')));
$expiryFilter = strtolower(trim((string) ($_GET['expiry'] ?? '')));
if (!in_array($expiryFilter, ['warning', 'expired', 'any'], true)) {
    $expiryFilter = '';
}

$allowedRegions = admin_filter_hq_regions();
$allowedStatuses = admin_filter_user_statuses(
    "EXISTS (
        SELECT 1 FROM business_account_scope bas WHERE bas.user_id = u.user_id AND bas.headquarter_id IS NOT NULL
     ) OR EXISTS (
        SELECT 1 FROM headquarter h WHERE h.headquarters_owner_id = u.user_id
     )"
);
// Keep actionable statuses available even if not yet present in rows.
foreach (['active', 'approved', 'pending', 'rejected', 'suspended', 'removed'] as $fallbackStatus) {
    if (!in_array($fallbackStatus, $allowedStatuses, true)) {
        $allowedStatuses[] = $fallbackStatus;
    }
}
$allowedStatuses = hq_status_filter_options($allowedStatuses);

if ($region !== '' && !in_array($region, $allowedRegions, true)) {
    // Still allow free-text region from the query string (LIKE filter below).
}
if ($status !== '' && !in_array($status, $allowedStatuses, true)) {
    $status = '';
}

$hqRows = [];
$dbError = null;

if (hq_db_available() && hq_registry_schema_ready()) {
       // The HQ entity table is authoritative; role metadata only helps find
       // a fallback owner for legacy rows without headquarters_owner_id.
       $hqRoleId = max(0, hq_hq_role_id());
       // Keep unlinked HQ records visible and classify them as pending below.
       $hqStateSql = "COALESCE(NULLIF(h.status, ''), COALESCE(owner.status, 'pending'))";
       // Show every HQ entity by default, including legacy/custom statuses.
       // The status selector below still applies an explicit user filter.
       $where = [];
       $params = [];
       $types = '';

       if ($search !== '') {
           $where[] = '(LOWER(h.company_name) LIKE ? OR LOWER(owner.user_name) LIKE ?)';
           $searchLike = '%' . strtolower($search) . '%';
           array_push($params, $searchLike, $searchLike);
           $types .= 'ss';
       }

       if ($region !== '') {
           $where[] = 'LOWER(h.location) LIKE ?';
           $params[] = '%' . $region . '%';
           $types .= 's';
       }

       if ($status !== '') {
           if (in_array($status, ['approved', 'active'], true)) {
               $where[] = "LOWER($hqStateSql) IN ('approved', 'active')";
           } elseif (in_array($status, ['pending', 'pending_verification'], true)) {
               $where[] = "LOWER($hqStateSql) IN ('pending', 'pending_verification')";
           } else {
               $where[] = "LOWER($hqStateSql) = ?";
               $params[] = $status;
               $types .= 's';
           }
       }

       if ($expiryFilter !== '' && function_exists('expiry_hq_filter_sql')) {
           $expirySql = expiry_hq_filter_sql($expiryFilter, 'COALESCE(NULLIF(h.business_license_expire_date, \'\'), DATE(h.expire_at))');
           if ($expirySql !== '') {
               $where[] = $expirySql;
           }
       }

       // Station totals come from `branches` via station_business_scope (never the dummy `stations` view).
       $sql = "SELECT
            h.headquarter_id,
            h.company_name,
            h.location,
            h.headquarter_email,
            h.created_at,
            h.expire_at,
            COALESCE(NULLIF(h.business_license_expire_date, ''), DATE(h.expire_at)) AS license_expiry_date,
            owner.user_name AS owner_name,
            owner.email AS owner_email,
            {$hqStateSql} AS account_status,
            COALESCE(user_counts.user_count, 0) AS user_count,
            COALESCE(station_counts.station_count, 0) AS station_count
        FROM headquarter h
        LEFT JOIN (
            SELECT bas.headquarter_id,
                   COUNT(DISTINCT bas.user_id) AS user_count,
                   MIN(CASE WHEN u.role_id = {$hqRoleId} OR u.business_role_id = {$hqRoleId} OR u.current_role_id = {$hqRoleId} THEN u.user_id END) AS owner_user_id
            FROM business_account_scope bas
            INNER JOIN users u ON u.user_id = bas.user_id
            WHERE bas.headquarter_id IS NOT NULL
            GROUP BY bas.headquarter_id
        ) user_counts ON user_counts.headquarter_id = h.headquarter_id
        LEFT JOIN users owner ON owner.user_id = COALESCE(h.headquarters_owner_id, user_counts.owner_user_id)
        LEFT JOIN (
            SELECT scope.headquarter_id, COUNT(DISTINCT scope.station_id) AS station_count
            FROM station_business_scope scope
            INNER JOIN branches b ON b.branch_id = scope.station_id
            GROUP BY scope.headquarter_id
        ) station_counts ON station_counts.headquarter_id = h.headquarter_id";

       if ($where) {
           $sql .= ' WHERE ' . implode(' AND ', $where);
       }
       $sql .= ' ORDER BY h.created_at DESC, h.headquarter_id DESC';

       $statement = $conn->prepare($sql);
       if (!$statement) {
           $dbError = hq_db_error_message();
       } else {
           if ($types !== '') {
               $bound = array_values($params);
               if (!hq_bind($statement, $types, $bound)) {
                   $dbError = hq_db_error_message();
               }
           }

           if (!$dbError && !$statement->execute()) {
               $dbError = hq_db_error_message();
           } else {
               $result = $statement->get_result();
               while ($result && ($row = $result->fetch_assoc())) {
                   $hqRows[] = $row;
               }
           }

           $statement->close();
       }
} else {
    $dbError = hq_db_error_message();
}

$regionLabel = static function (string $value) {
    return admin_filter_label($value);
};

$flashType = $flash['type'] ?? '';
$flashContext = $flash['context'] ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Map &amp; Pump Admin — View and manage company accounts and their company details.">
    <title>View/Manage Companies — Map &amp; Pump</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&amp;display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="icon" type="image/png" href="/images/Logo.png">
    <link rel="stylesheet" href="/css/Admin.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .hq-status {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            color: rgba(255, 255, 255, .68);
            font-size: .8rem;
            white-space: nowrap;
        }
        .hq-status::before {
            content: '';
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
        }
        .hq-status-active::before { background: #79aaff; box-shadow: 0 0 8px #79aaff; }
        .hq-status-pending::before { background: #ffc107; box-shadow: 0 0 8px #ffc107; }
        .hq-status-suspended::before { background: #ff9f43; box-shadow: 0 0 8px #ff9f43; }
        .hq-status-removed::before { background: #ff4d6a; box-shadow: 0 0 8px #ff4d6a; }
        .hq-flash { border-radius: 14px; background: rgba(10, 22, 44, .82); border: 1px solid var(--glass-border); color: var(--text-primary); }
        .hq-flash.alert-success { border-color: rgba(121,170,255, .4); }
        .hq-flash.alert-danger { border-color: rgba(255, 77, 106, .45); }
        .hq-credential-box { border: 1px solid rgba(121,170,255, .2); border-radius: 12px; background: rgba(121,170,255, .06); }
        .hq-detail-label { color: var(--text-muted); font-size: .72rem; text-transform: uppercase; letter-spacing: .07em; }
        .hq-detail-value { color: var(--text-primary); word-break: break-word; }
        .hq-modal-table { --bs-table-bg: transparent; --bs-table-color: var(--text-primary); --bs-table-border-color: var(--glass-border); }
        .hq-modal-table th { color: var(--text-muted); font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; }
        body.background_color.light-mode .hq-flash { background: rgba(255,255,255,.9); }
        body.background_color.light-mode .hq-status { color: var(--text-muted); }
        .hq-table-scroll {
            max-height: min(60vh, 520px);
            overflow: auto;
        }

        /* Page-local UI treatment; backend and shared JavaScript are unchanged. */
        body.background_color {
            --company-bg: #0c1421; --company-surface: #141f30; --company-raised: #192638;
            --company-border: #29364a; --company-text: #edf3fb; --company-muted: #a7b5c9;
            --company-accent: #79aaff; --company-warning: #f0c46a; --company-danger: #f18d9c;
            background: var(--company-bg);
        }
        body.background_color.light-mode {
            --company-bg: #f3f6fa; --company-surface: #ffffff; --company-raised: #f7f9fc;
            --company-border: #dee5ee; --company-text: #182b42; --company-muted: #596b82;
            --company-accent: #285ec7; --company-warning: #96620a; --company-danger: #b43b51;
        }
        :is(.companies-shell, #accountActionModal, #hqDetailsModal) {
            --text-primary: var(--company-text); --text-muted: var(--company-muted);
            --glass-border: var(--company-border); --neon-cyan: var(--company-accent);
            font-family: 'Poppins', system-ui, sans-serif; color: var(--company-text); font-size: 13px; line-height: 1.65;
        }
        .companies-shell { max-width: 1440px; padding-inline: clamp(18px,3vw,40px); margin-block: 36px !important; }
        :is(.companies-shell, #accountActionModal, #hqDetailsModal) .text-white { color: var(--company-text) !important; }
        :is(.companies-shell, #accountActionModal, #hqDetailsModal) .text-white-50 { color: var(--company-muted) !important; }
        :is(.companies-shell, #accountActionModal, #hqDetailsModal) .txt-neon-cyan { color: var(--company-accent) !important; }
        :is(.companies-shell, #accountActionModal, #hqDetailsModal) .text-danger { color: var(--company-danger) !important; }
        :is(.companies-shell, #accountActionModal, #hqDetailsModal) .text-success { color: var(--company-accent) !important; }
        .companies-header { border-bottom: 1px solid var(--company-border); padding-bottom: 24px; }
        .companies-eyebrow { color: var(--company-accent); font-size: 10px; font-weight: 600; letter-spacing: .14em; text-transform: uppercase; }
        .companies-header h1 { font-size: clamp(24px,3vw,32px); letter-spacing: -.035em; line-height: 1.3; }
        .companies-header p { margin: 9px 0 0; color: var(--company-muted); font-size: 12px; max-width: 620px; }
        .companies-header-note { color: var(--company-muted); padding: 8px 12px; border: 1px solid var(--company-border); border-radius: 8px; background: var(--company-surface); font-size: 11px; }
        .companies-shell :is(.dashboard-card,.analytics-card) { background: var(--company-surface); border: 1px solid var(--company-border); border-radius: 12px; padding: 24px; box-shadow: 0 4px 16px rgba(0,0,0,.035); backdrop-filter: none; }
        .companies-shell .admin-filter-card { padding: 20px 24px; }
        .companies-filter-heading { display: flex; align-items: center; gap: 8px; font-size: 12px; font-weight: 600; margin-bottom: 16px; }
        .companies-shell .admin-filter-form { display: grid; grid-template-columns: minmax(230px,2fr) repeat(3,minmax(140px,1fr)) auto; gap: 14px; align-items: end; }
        .companies-shell .filter-group { min-width: 0; }
        .companies-shell .filter-group:not(:has(input,select)) { display: none; }
        .companies-shell :is(.filter-label,.filter-group label) { display: block; margin: 0 0 7px; color: var(--company-muted); font-size: 10px; font-weight: 500; }
        .companies-shell .filter-search { position: relative; }
        .companies-shell .filter-search-icon { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: var(--company-muted); pointer-events: none; z-index: 1; }
        .companies-shell .filter-input { padding-left: 36px !important; }
        :is(.companies-shell, #accountActionModal) :is(.form-control,.form-select) { width: 100%; min-height: 44px; background-color: var(--company-raised); color: var(--company-text); border: 1px solid var(--company-border); border-radius: 8px; font-family: inherit; font-size: 12px; padding: 10px 12px; box-shadow: none; }
        :is(.companies-shell, #accountActionModal) .form-select { padding-right: 34px; }
        :is(.companies-shell, #accountActionModal) :is(.form-control,.form-select):focus { border-color: var(--company-accent); outline: 2px solid var(--company-accent); outline-offset: 2px; box-shadow: none; }
        :is(.companies-shell, #accountActionModal) .form-control::placeholder { color: var(--company-muted); opacity: .85; }
        :is(.companies-shell, #accountActionModal) select option { background: var(--company-surface); color: var(--company-text); }
        body:not(.light-mode) :is(.companies-shell, #accountActionModal) .form-select { --bs-form-select-bg-img: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23a7b5c9' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e"); color-scheme: dark; }
        .companies-shell .filter-actions { display: flex; gap: 8px; align-items: center; }
        .companies-shell :is(.btn-clear-filters,.companies-apply) { min-height: 44px; padding: 10px 14px; border-radius: 8px; border: 1px solid var(--company-border); background: var(--company-raised); color: var(--company-text); font-size: 11px; font-weight: 500; white-space: nowrap; }
        .companies-shell .companies-apply { background: #79aaff; border-color: #79aaff; color: #10284c; }
        .companies-shell .companies-apply:hover { background: #9cc1ff; }
        .companies-shell .btn-clear-filters:hover { border-color: var(--company-accent); }
        .companies-directory-heading { display: flex; flex-wrap: wrap; gap: 8px; align-items: baseline; justify-content: space-between; margin-bottom: 18px; }
        .companies-directory-heading h2 { font-size: 16px; font-weight: 600; letter-spacing: -.025em; margin: 0; }
        .companies-directory-heading p { color: var(--company-muted); font-size: 10px; margin: 0; }
        .companies-scroll-hint { display: none; color: var(--company-muted); font-size: 10px; margin: 0 0 12px; }
        .companies-shell .hq-table-scroll { max-height: min(68vh,640px); overflow: auto; border: 1px solid var(--company-border); border-radius: 9px; scrollbar-color: var(--company-border) var(--company-raised); }
        .companies-shell .table-dark-custom { width: 100%; min-width: 1060px; --bs-table-bg: transparent; --bs-table-color: var(--company-text); --bs-table-border-color: var(--company-border); }
        .companies-shell .table-dark-custom > :not(caption) > * > * { color: inherit; background: transparent; box-shadow: none; padding: 20px 16px; border-color: var(--company-border); }
        .companies-shell .table-dark-custom thead th { position: sticky; top: 0; z-index: 2; background: var(--company-raised); color: var(--company-muted); padding-block: 13px; font-size: 9px; font-weight: 500; text-transform: uppercase; letter-spacing: .08em; white-space: nowrap; }
        .companies-shell .table-dark-custom tbody { font-size: 11px; }
        .companies-shell .table-dark-custom tbody tr:hover { background: var(--company-raised); }
        .companies-shell .table-dark-custom tbody tr:last-child td { border-bottom: 0; }
        .companies-shell .table-dark-custom td:first-child { width: 42%; min-width: 360px; }
        .companies-shell .table-dark-custom td:nth-child(3) { min-width: 180px; }
        .companies-shell .table-dark-custom td .small { font-size: 10px !important; margin-top: 3px; overflow-wrap: anywhere; }
        .companies-shell .table-dark-custom td:first-child .fw-semibold { font-size: 12px; }
        .companies-shell .company-avatar { width: 42px; height: 42px; flex-shrink: 0; font-size: 12px; color: var(--company-accent) !important; border: 1px solid var(--company-border); background: var(--company-raised); }
        :is(.companies-shell, #hqDetailsModal) .hq-status { display: inline-flex; align-items: center; color: var(--company-text); font-size: 10px; padding: 4px 8px; border: 1px solid var(--company-border); border-radius: 6px; background: var(--company-raised); white-space: nowrap; }
        :is(.companies-shell, #hqDetailsModal) .hq-status::before { width: 6px; height: 6px; box-shadow: none; flex-shrink: 0; }
        :is(.companies-shell, #hqDetailsModal) .hq-status-active::before { background: var(--company-accent); }
        :is(.companies-shell, #hqDetailsModal) .hq-status-pending::before { background: var(--company-warning); }
        :is(.companies-shell, #hqDetailsModal) .hq-status-suspended::before { background: #e79452; }
        :is(.companies-shell, #hqDetailsModal) .hq-status-removed::before { background: var(--company-danger); }
        .companies-shell .stat-chip { display: inline-flex; align-items: center; gap: 4px; border: 1px solid var(--company-border); background: var(--company-raised); color: var(--company-muted); border-radius: 6px; padding: 4px 8px; font-size: 10px !important; font-variant-numeric: tabular-nums; }
        .companies-shell .btn-action { display: inline-flex; align-items: center; justify-content: center; width: 38px; height: 38px; border: 1px solid var(--company-border); border-radius: 8px; color: var(--company-muted); background: var(--company-raised); text-decoration: none; }
        .companies-shell .btn-action:hover { color: var(--company-accent); border-color: var(--company-accent); }
        :is(.companies-shell, #accountActionModal, #hqDetailsModal) :is(a,button,[tabindex="0"]):focus-visible { outline: 3px solid var(--company-accent); outline-offset: 3px; }
        :is(.companies-shell, #hqDetailsModal) .hq-flash { padding: 18px 20px; color: var(--company-text); background: var(--company-surface); border-color: var(--company-border); border-radius: 10px; }
        :is(.companies-shell, #hqDetailsModal) .hq-flash.alert-danger { border-color: var(--company-danger); }
        .companies-shell .hq-flash.alert-success { border-color: var(--company-accent); }
        .companies-shell .hq-credential-box { background: var(--company-raised); border-color: var(--company-border); }
        .companies-shell code { color: var(--company-accent); }
        :is(#accountActionModal, #hqDetailsModal) .modal-content { background: var(--company-surface); color: var(--company-text); border: 1px solid var(--company-border); border-radius: 14px; box-shadow: 0 24px 100px rgba(0,0,0,.25); }
        :is(#accountActionModal, #hqDetailsModal) :is(.modal-header,.modal-footer) { border-color: var(--company-border); padding: 20px 24px; }
        :is(#accountActionModal, #hqDetailsModal) .modal-body { padding: 24px; }
        :is(#accountActionModal, #hqDetailsModal) .modal-title { font-size: 17px; letter-spacing: -.025em; }
        #accountActionModal .modal-content > form { min-height: 0; display: flex; flex-direction: column; overflow: hidden; }
        #accountActionModal .modal-footer .btn { min-height: 42px; font-size: 12px; padding: 10px 16px; border-radius: 8px; }
        #accountActionModal .btn-neon { background: #79aaff; border: 1px solid #79aaff; color: #10284c; }
        #accountActionModal .btn-neon:hover { background: #9cc1ff; }
        #accountActionModal .form-label { font-size: 11px; margin-bottom: 8px; }
        #accountActionModal textarea { resize: vertical !important; }
        #hqDetailsModal .hq-detail-label { color: var(--company-muted); font-size: 9px; font-weight: 500; }
        #hqDetailsModal .hq-detail-value { color: var(--company-text); font-size: 12px; }
        #hqDetailsBody .p-3.rounded-3 { background: var(--company-raised) !important; border-color: var(--company-border) !important; }
        #hqDetailsBody .badge.rounded-pill { color: var(--company-accent) !important; background: var(--company-raised) !important; border-color: var(--company-border) !important; }
        #hqDetailsBody .table-responsive { border: 1px solid var(--company-border); border-radius: 8px; }
        #hqDetailsBody .hq-modal-table { min-width: 660px; --bs-table-bg: transparent; --bs-table-color: var(--company-text); --bs-table-border-color: var(--company-border); font-size: 11px; }
        #hqDetailsBody .hq-modal-table th { background: var(--company-raised); color: var(--company-muted); font-size: 9px; font-weight: 500; padding: 12px 14px; }
        #hqDetailsBody .hq-modal-table td { padding: 14px; }
        #hqDetailsBody .hq-modal-table td .small { font-size: 10px; }
        #hqDetailsBody .hq-modal-table tr:last-child td { border-bottom: 0; }
        body.light-mode :is(.companies-shell, #accountActionModal, #hqDetailsModal) .btn-close-white { filter: none; }
        .companies-footer { color: var(--company-muted) !important; font-family: 'Poppins',system-ui,sans-serif; font-size: 10px !important; margin-top: 0 !important; }
        @media (max-width: 1199.98px) { .companies-shell .admin-filter-form { grid-template-columns: repeat(3,minmax(0,1fr)); } .companies-shell .filter-group--search { grid-column: span 2; } .companies-shell .filter-actions { justify-content: flex-end; } }
        @media (max-width: 767.98px) { .companies-shell { margin-top: 24px !important; } .companies-shell :is(.dashboard-card,.analytics-card) { padding: 18px; } .companies-shell .admin-filter-form { grid-template-columns: repeat(2,minmax(0,1fr)); } .companies-shell .filter-group:has(#hqExpiryFilter) { grid-column: span 2; } .companies-shell .filter-actions { grid-column: span 2; } .companies-shell .filter-actions button { flex: 1; } .companies-scroll-hint { display: block; } :is(#accountActionModal, #hqDetailsModal) :is(.modal-body,.modal-header,.modal-footer) { padding: 18px; } }
        @media (prefers-reduced-motion: reduce) { :is(.companies-shell, #accountActionModal, #hqDetailsModal) * { transition: none !important; animation: none !important; } }
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
<?php $adminSection = 'view_hq'; include __DIR__ . '/admin_section_nav.php'; ?>

    <main class="container my-5 companies-shell admin-blue-shell">
        <?php if ($flash && $flashType): ?>
            <div class="alert hq-flash alert-<?= hq_escape($flashType) ?> alert-dismissible fade show mb-4" role="alert">
                <div class="d-flex gap-2 align-items-start">
                    <i class="bi <?= $flashType === 'success' ? 'bi-check-circle-fill text-success' : 'bi-exclamation-triangle-fill text-danger' ?> mt-1"></i>
                    <div class="flex-grow-1">
                        <div><?= hq_escape($flash['message'] ?? '') ?></div>
                        <?php if ($flashType === 'success' && !empty($flashContext['temporary_password'])): ?>
                            <div class="hq-credential-box p-3 mt-3 small">
                                <div class="fw-semibold txt-neon-cyan mb-1">Temporary login credentials — save these now</div>
                                <div>Username: <code><?= hq_escape($flashContext['username'] ?? '') ?></code></div>
                                <div>Temporary password: <code><?= hq_escape($flashContext['temporary_password']) ?></code></div>
                                <div class="text-white-50 mt-2">Email: <?= hq_escape($flashContext['email'] ?? '') ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (function_exists('expiry_render_page_banners')) { expiry_render_page_banners('admin', true); } ?>
        <div class="admin-blue-header companies-header d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <span class="companies-eyebrow">Map &amp; Pump / Company Administration</span>
                <h1 class="fw-bold m-0 text-white mt-2">Companies</h1>
                <p>Manage company accounts, review license status, and explore linked stations and users.</p>
            </div>
            <span class="companies-header-note"><i class="bi bi-shield-check me-2" aria-hidden="true"></i>Company registry</span>
        </div>

        <?php if ($dbError): ?>
            <div class="alert hq-flash alert-danger mb-4" role="alert">
                <i class="bi bi-database-exclamation me-2"></i><?= hq_escape($dbError) ?>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-12">
                <div class="dashboard-card admin-filter-card">
                    <div class="companies-filter-heading"><i class="bi bi-sliders txt-neon-cyan" aria-hidden="true"></i> Filter companies</div>
                    <form class="admin-filter-form" method="GET" action="view_hq_and_user_account.php">
                        <div class="filter-group filter-group--search">
                            <label class="filter-label" for="hqSearch">Search</label>
                            <div class="filter-search">
                                <i class="bi bi-search filter-search-icon" aria-hidden="true"></i>
                                <input id="hqSearch" type="search" class="form-control filter-control filter-input" placeholder="Company or owner name" name="search" value="<?= hq_escape($search) ?>">
                            </div>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label" for="hqRegion">Region</label>
                            <div class="filter-select-wrap"><select id="hqRegion" class="form-select filter-control filter-select" name="region">
                                <option value="" <?= $region === '' ? 'selected' : '' ?>>All Regions</option>
                                <?php foreach ($allowedRegions as $option): ?>
                                    <option value="<?= $option ?>" <?= $region === $option ? 'selected' : '' ?>><?= hq_escape($regionLabel($option)) ?></option>
                                <?php endforeach; ?>
                            </select></div>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label" for="hqStatus">Owner status</label>
                            <div class="filter-select-wrap"><select id="hqStatus" class="form-select filter-control filter-select" name="status">
                                <option value="" <?= $status === '' ? 'selected' : '' ?>>All Status</option>
                                <?php foreach ($allowedStatuses as $option): ?>
                                    <option value="<?= hq_escape($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= hq_escape(hq_status_label($option)) ?></option>
                                <?php endforeach; ?>
                            </select></div>
                        </div>
                        <div class="filter-group">
                            <?= function_exists('expiry_filter_select_html') ? expiry_filter_select_html($expiryFilter, 'hqExpiryFilter', 'hq_escape') : '' ?>
                        </div>
                        <div class="filter-actions">
                            <button type="submit" class="companies-apply">Apply filters</button>
                            <button type="button" class="btn-clear-filters js-clear-filters" title="Clear filters">Clear</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="col-12">
                <div class="analytics-card">
                    <div class="companies-directory-heading"><h2>Company directory</h2><p>View details or manage an account using the row actions</p></div>
                    <p class="companies-scroll-hint"><i class="bi bi-arrow-left-right me-1" aria-hidden="true"></i> Scroll across for status and account actions</p>
                    <div class="table-responsive hq-table-scroll" role="region" aria-label="Company directory; scroll for more columns and rows" tabindex="0">
                        <table class="table table-dark-custom align-middle m-0">
                            <thead>
                                <tr><th scope="col">Company &amp; Owner</th><th scope="col">Role</th><th scope="col">Location</th><th scope="col">Stations</th><th scope="col">Owner status</th><th scope="col" class="text-end">Actions</th></tr>
                            </thead>
                            <tbody>
                                <?php if (!$hqRows): ?>
                                    <tr><td colspan="6" class="text-center text-white-50 py-5"><i class="bi bi-building-x fs-3 d-block mb-2"></i>No companies match the current filter.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($hqRows as $row): ?>
                                    <?php
                                    $companyName = (string) ($row['company_name'] ?? 'Company name unavailable');
                                    $ownerName = (string) ($row['owner_name'] ?? 'Owner account unavailable');
                                    $ownerEmail = (string) ($row['owner_email'] ?? 'Not provided');
                                    $rowStatus = (string) ($row['account_status'] ?? 'pending');
                                    $stationCount = (int) ($row['station_count'] ?? 0);
                                    $locationParts = preg_split('/\s+[—-]\s+/u', (string) ($row['location'] ?? ''), 2) ?: [];
                                    $locationRegion = $locationParts[0] ?? 'Not provided';
                                    $locationAddress = $locationParts[1] ?? '';
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center gap-3">
                                                <div class="company-avatar rounded-3 d-flex align-items-center justify-content-center text-white fw-bold">
                                                    <?= hq_escape(hq_initials($companyName)) ?>
                                                </div>
                                                <div>
                                                    <div class="fw-semibold text-white"><?= hq_escape($companyName) ?></div>
                                                    <div class="text-white-50 small" style="font-size:.8rem;">Owner: <?= hq_escape($ownerName) ?> (<?= hq_escape($ownerEmail) ?>)</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-white-50">#3 &middot; Company</td>
                                        <td><div class="text-white"><?= hq_escape($locationRegion) ?></div><?php if ($locationAddress !== ''): ?><div class="text-white-50 small" style="font-size:.8rem;"><?= hq_escape($locationAddress) ?></div><?php endif; ?></td>
                                        <td class="text-white-50"><span class="stat-chip" style="font-size:.75rem;"><i class="bi bi-fuel-pump me-1 txt-neon-cyan"></i><?= $stationCount ?></span></td>
                                        <td>
                                            <span class="hq-status <?= hq_status_class($rowStatus) ?>"><?= hq_escape(hq_status_label($rowStatus)) ?></span>
                                            <?php if (function_exists('expiry_badge_html')): ?>
                                                <div class="mt-1"><?= expiry_badge_html(isset($row['license_expiry_date']) ? (string) $row['license_expiry_date'] : null) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-flex gap-2 justify-content-end">
                                                <a href="#" class="btn-action js-view-hq" title="View Stations &amp; Accounts" aria-label="View <?= hq_escape($companyName) ?> details" data-hq-id="<?= (int) $row['headquarter_id'] ?>"><i class="bi bi-eye-fill"></i></a>
                                                <a href="#" class="btn-action btn-action-danger js-account-action" title="Manage company account" aria-label="Change status for <?= hq_escape($companyName) ?>" data-hq-id="<?= (int) $row['headquarter_id'] ?>" data-hq-name="<?= hq_escape($companyName) ?>" data-hq-status="<?= hq_escape($rowStatus) ?>"><i class="bi bi-gear-fill"></i></a>
                                            </div>
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

    <div class="modal fade glass-modal" id="accountActionModal" tabindex="-1" aria-labelledby="accountActionModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title fw-bold" id="accountActionModalLabel"><i class="bi bi-shield-exclamation text-danger me-2"></i> Manage company account</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <form action="hq_account_action.php" method="POST">
                    <div class="modal-body d-flex flex-column gap-3">
                        <input type="hidden" name="headquarter_id" id="actionHQId"><input type="hidden" name="return_to" value="view_hq_and_user_account.php">
                        <div class="small text-white-50">Company: <span class="txt-neon-cyan fw-semibold" id="actionHQName">—</span></div>
                        <div><label class="form-label text-white-50 small" for="actionType">Account action</label><select class="form-select glass-select account-action-select" name="action_type" id="actionType" required><option value="activate">Activate pending company</option><option value="suspend">Suspend account</option><option value="remove">Remove account</option><option value="restore">Restore account</option></select></div>
                        <div><label class="form-label text-white-50 small" for="actionReason">Reason</label><textarea class="form-control glass-input" name="reason" id="actionReason" rows="5" maxlength="2000" placeholder="Explain why this company account is being suspended, removed, or restored..." required style="resize:none;"></textarea></div>

                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-3 text-white-50 border-secondary border-opacity-20" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger" id="actionSubmit">Confirm Suspend</button></div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade glass-modal" id="hqDetailsModal" tabindex="-1" aria-labelledby="hqDetailsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title fw-bold" id="hqDetailsModalLabel"><i class="bi bi-building txt-neon-cyan me-2"></i> Company Details</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <div class="modal-body" id="hqDetailsBody"><div class="text-center text-white-50 py-5"><div class="spinner-border spinner-border-sm txt-neon-cyan mb-2" role="status"></div><div>Loading company details…</div></div></div>
            </div>
        </div>
    </div>


    <footer class="companies-footer text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map &amp; Pump | All Rights Reserved</footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/js/Admin.js"></script>
    <script>
        (() => {
            const actionModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('accountActionModal'));
            const detailsModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('hqDetailsModal'));
            const actionId = document.getElementById('actionHQId');
            const actionName = document.getElementById('actionHQName');
            const actionType = document.getElementById('actionType');
            const actionSubmit = document.getElementById('actionSubmit');
            const detailsTitle = document.getElementById('hqDetailsModalLabel');
            const detailsBody = document.getElementById('hqDetailsBody');

            const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (character) => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[character]));
            const formatDate = (value) => value ? new Date(String(value).replace(' ', 'T')).toLocaleString() : 'Not available';
            const formatLicenseExpiry = (value) => {
                const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
                if (!match) return 'Not available';
                const year = Number(match[1]);
                const month = Number(match[2]);
                const day = Number(match[3]);
                const leapYear = year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0);
                const daysInMonth = [31, leapYear ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31][month - 1];
                if (month < 1 || month > 12 || day < 1 || day > daysInMonth) return 'Not available';
                const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
                return monthNames[month - 1] + ' ' + day + ', ' + year;
            };
            const statusClass = (status) => ({active:'hq-status-active', approved:'hq-status-active', suspended:'hq-status-suspended', removed:'hq-status-removed'}[String(status || '').toLowerCase()] || 'hq-status-pending');
            const statusLabel = (status) => ({active:'Active', approved:'Approved', suspended:'Suspended', removed:'Removed', pending:'Pending Verification', pending_verification:'Pending Verification'}[String(status || '').toLowerCase()] || (status ? String(status) : 'Unknown'));
            const stationStatusLabel = (status) => ({active:'Active', approved:'Active', decommissioned:'Decommissioned', suspended:'Suspended', removed:'Removed', offline:'Offline'}[String(status || '').toLowerCase()] || (status ? String(status) : 'Unknown'));

            const updateActionButton = () => {
                const kind = actionType.value;
                actionSubmit.textContent = ({activate:'Confirm activation', remove:'Confirm removal', restore:'Confirm restoration', suspend:'Confirm suspension'})[kind];
                actionSubmit.classList.toggle('btn-danger', !['activate', 'restore'].includes(kind));
                actionSubmit.classList.toggle('btn-neon', ['activate', 'restore'].includes(kind));
            };
            actionType.addEventListener('change', updateActionButton);

            document.addEventListener('click', (event) => {
                const button = event.target.closest('.js-account-action');
                if (!button) return;
                event.preventDefault();
                actionId.value = button.dataset.hqId || '';
                actionName.textContent = button.dataset.hqName || 'Selected company';
                actionType.value = button.dataset.hqStatus === 'pending' ? 'activate' : 'suspend';
                updateActionButton();
                document.getElementById('actionReason').value = '';
                actionModal.show();
            });

            const sectionTitle = (icon, title, count) =>
                '<div class="d-flex align-items-center justify-content-between mb-2 mt-1">' +
                '<h6 class="text-white fw-semibold mb-0"><i class="bi ' + icon + ' txt-neon-cyan me-2"></i>' + title + '</h6>' +
                '<span class="badge rounded-pill" style="background:rgba(0,244,255,.12);color:#7eefff;border:1px solid rgba(0,244,255,.25);font-weight:600;">' + count + '</span></div>';

            const emptyRow = (cols, message) =>
                '<tr><td colspan="' + cols + '" class="text-center text-white-50 py-4"><i class="bi bi-inbox me-1"></i>' + escapeHtml(message) + '</td></tr>';


            <?= function_exists('expiry_js_detail_helper') ? expiry_js_detail_helper() : 'function expiryDetailAlert(){return "";}' ?>
            const renderDetails = (payload) => {
                const hq = payload.hq || {};
                const accounts = payload.accounts || [];
                const stations = payload.stations || [];
                const actions = payload.actions || [];
                detailsTitle.innerHTML = '<i class="bi bi-building txt-neon-cyan me-2"></i>' + escapeHtml(hq.company_name || 'Company Details');

                const registeredDay = String(hq.created_at || '').slice(0, 10);
                const expiryDay = String(hq.license_expiry_date || '').slice(0, 10);
                const currentDate = new Date();
                const today = [currentDate.getFullYear(), String(currentDate.getMonth() + 1).padStart(2, '0'), String(currentDate.getDate()).padStart(2, '0')].join('-');
                const licenseDateWarning = registeredDay && expiryDay && expiryDay <= registeredDay
                    ? '<div class="alert alert-warning py-2 mb-3" role="status"><i class="bi bi-exclamation-triangle-fill me-2"></i>License expiry must be after registration. Verify the current business license before changing account status.</div>'
                    : (typeof expiryDetailAlert === 'function' ? expiryDetailAlert(expiryDay, 'hq') : (expiryDay && expiryDay < today ? '<div class="alert alert-danger py-2 mb-3" role="alert"><i class="bi bi-x-circle-fill me-2"></i>This business license has expired. Company access is limited to the profile until the business license is renewed.</div>' : ''));

                const metaCards =
                    '<div class="row g-3 mb-4">' +
                    '<div class="col-12 col-md-6"><div class="p-3 rounded-3" style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);"><div class="hq-detail-label">Company location</div><div class="hq-detail-value mt-1">' + escapeHtml(hq.location || 'Not available') + '</div></div></div>' +
                    '<div class="col-6 col-md-3"><div class="p-3 rounded-3 h-100" style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);"><div class="hq-detail-label">Contact</div><div class="hq-detail-value mt-1">' + escapeHtml(hq.contact_number || '—') + '</div></div></div>' +
                    '<div class="col-6 col-md-3"><div class="p-3 rounded-3 h-100" style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);"><div class="hq-detail-label">Email</div><div class="hq-detail-value mt-1 text-break">' + escapeHtml(hq.headquarter_email || '—') + '</div></div></div>' +
                    '<div class="col-6 col-md-3"><div class="p-3 rounded-3 h-100" style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);"><div class="hq-detail-label">Owner</div><div class="hq-detail-value mt-1">' + escapeHtml(hq.owner_name || '—') + '</div></div></div>' +
                    '<div class="col-6 col-md-3"><div class="p-3 rounded-3 h-100" style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);"><div class="hq-detail-label">Registered</div><div class="hq-detail-value mt-1">' + escapeHtml(formatDate(hq.created_at)) + '</div></div></div>' +
                    '<div class="col-6 col-md-3"><div class="p-3 rounded-3 h-100" style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);"><div class="hq-detail-label">License expiry</div><div class="hq-detail-value mt-1">' + escapeHtml(formatLicenseExpiry(hq.license_expiry_date)) + '</div></div></div>' +
                    '<div class="col-6 col-md-3"><div class="p-3 rounded-3 h-100" style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);"><div class="hq-detail-label">Business license</div><div class="hq-detail-value mt-1 text-break">' + escapeHtml(hq.business_license || '—') + '</div></div></div>' +
                    '</div>';

                const roleLabel = role => ({hq: 'Company', headquarter: 'Company', headquarters: 'Company', customer: 'Driver'}[String(role || '').trim().toLowerCase()] || (role || '—'));
                const accountRows = accounts.length
                    ? accounts.map((account) =>
                        '<tr>' +
                        '<td><div class="fw-semibold text-white">' + escapeHtml(account.username || '—') + '</div><div class="text-white-50 small">ID ' + escapeHtml(account.user_id || '') + '</div></td>' +
                        '<td class="text-white-50 text-break">' + escapeHtml(account.email || '—') + '</td>' +
                        '<td class="text-white-50"><div>' + escapeHtml(roleLabel(account.role_name)) + '</div><div class="small text-white-50">Base #' + escapeHtml(account.role_id ?? '—') + ' · Business #' + escapeHtml(account.business_role_id ?? '—') + '</div></td>' +
                        '<td><span class="hq-status ' + statusClass(account.status) + '">' + escapeHtml(statusLabel(account.status)) + '</span></td>' +
                        '<td class="text-white-50 small">' + escapeHtml(formatDate(account.created_at)) + '</td>' +
                        '</tr>').join('')
                    : emptyRow(5, 'No accounts are linked to this company.');

                const stationRows = stations.length
                    ? stations.map((station) =>
                        '<tr>' +
                        '<td><div class="fw-semibold text-white">' + escapeHtml(station.station_name || '—') + '</div><div class="text-white-50 small">ID ' + escapeHtml(station.station_id || '') + '</div></td>' +
                        '<td class="text-white-50">' + escapeHtml(station.location || '—') + '</td>' +
                        '<td class="text-white-50">' + escapeHtml(station.manager_name || '—') + '</td>' +
                        '<td><span class="hq-status ' + statusClass(station.status) + '">' + escapeHtml(stationStatusLabel(station.status)) + '</span></td>' +
                        '<td class="text-white-50 small">' + escapeHtml(formatDate(station.created_at)) + '</td>' +
                        '</tr>').join('')
                    : emptyRow(5, 'No stations are linked to this company.');

                const actionRows = actions.length
                    ? actions.map((action) =>
                        '<tr>' +
                        '<td><span class="badge text-bg-dark border border-secondary border-opacity-25">' + escapeHtml(String(action.action_type || '').toUpperCase()) + '</span></td>' +
                        '<td class="text-white-50">' + escapeHtml(action.reason || '—') + '</td>' +
                        '<td class="text-white-50 small">' + escapeHtml(formatDate(action.created_at)) + '</td>' +
                        '</tr>').join('')
                    : emptyRow(3, 'No suspend/remove history.');

                detailsBody.innerHTML =
                    licenseDateWarning + metaCards +
                    sectionTitle('bi-people-fill', 'Linked accounts', accounts.length) +
                    '<div class="table-responsive mb-4"><table class="table hq-modal-table align-middle mb-0"><thead><tr><th>Account</th><th>Email</th><th>Role</th><th>Status</th><th>Created</th></tr></thead><tbody>' + accountRows + '</tbody></table></div>' +
                    sectionTitle('bi-fuel-pump-fill', 'Linked stations', stations.length) +
                    '<div class="table-responsive mb-4"><table class="table hq-modal-table align-middle mb-0"><thead><tr><th>Station</th><th>Location</th><th>Manager</th><th>Status</th><th>Created</th></tr></thead><tbody>' + stationRows + '</tbody></table></div>' +
                    sectionTitle('bi-clock-history', 'Account action history', actions.length) +
                    '<div class="table-responsive"><table class="table hq-modal-table align-middle mb-0"><thead><tr><th>Action</th><th>Reason</th><th>Recorded</th></tr></thead><tbody>' + actionRows + '</tbody></table></div>';
            };

            document.addEventListener('click', async (event) => {
                const button = event.target.closest('.js-view-hq');
                if (!button) return;
                event.preventDefault();
                detailsTitle.innerHTML = '<i class="bi bi-building txt-neon-cyan me-2"></i> Stations &amp; Accounts';
                detailsBody.innerHTML = '<div class="text-center text-white-50 py-5"><div class="spinner-border spinner-border-sm txt-neon-cyan mb-2" role="status"></div><div>Loading stations &amp; accounts…</div></div>';
                detailsModal.show();
                try {
                    const response = await fetch('hq_details.php?id=' + encodeURIComponent(button.dataset.hqId), {headers: {Accept: 'application/json'}, cache: 'no-store'});
                    const payload = await response.json();
                    if (!response.ok || !payload.success) throw new Error(payload.message || 'Could not load company details.');
                    renderDetails(payload);
                } catch (error) {
                    detailsBody.innerHTML = '<div class="alert hq-flash alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>' + escapeHtml(error.message) + '</div>';
                }
            });

        })();
    </script>
    <script>document.addEventListener('pointermove', (event) => { document.body.style.setProperty('--bg-x', `${(event.clientX / window.innerWidth) * 100}%`); document.body.style.setProperty('--bg-y', `${(event.clientY / window.innerHeight) * 100}%`); });</script>
</body>
</html>