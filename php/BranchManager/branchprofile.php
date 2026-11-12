<?php
/**
 * branchprofile.php  —  Branch (station) profile management
 * Shows physical info about the station itself (not the user account
 */
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../conn.php';
if (!function_exists('map_pump_user_avatar_url')) {
    require_once __DIR__ . '/../profile_avatar.php';
}
require_branch_manager();

function map_pump_table_exists(PDO $pdo, string $tableName): bool {
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
        );
        $stmt->execute([$tableName]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

$ctx    = current_context($pdo);
$user   = $ctx['user'];
$branch = $ctx['branch'];
$managerName = getFullDisplayName($user);
$initials = initials($managerName);
$hqLogoSrc = function_exists('hq_logo_src') ? hq_logo_src($branch['hq_logo'] ?? null) : null;
$profileDisplayName = $managerName;
$profileAvatarUrl = map_pump_user_avatar_url($user['avatar_url'] ?? ($_SESSION['user_avatar_url'] ?? ''), '');
$pumpsTableExists = map_pump_table_exists($pdo, 'pumps');

function map_pump_branch_profile_report_data(array $manager, array $branch, array $changes): array {
    $managerName = trim((string) ($manager['full_name'] ?? $manager['user_name'] ?? 'Branch Manager'));
    $branchName = trim((string) ($branch['branch_name'] ?? 'Branch'));
    $branchCode = trim((string) ($branch['branch_code'] ?? ''));

    $summaryParts = [];
    foreach ($changes as $change) {
        $label = (string) ($change['label'] ?? 'Branch detail');
        $summaryParts[] = $label;
    }

    $summary = 'Branch profile updated';
    if (!empty($summaryParts)) {
        $summary = 'Updated branch profile: ' . implode(', ', array_slice($summaryParts, 0, 3));
        if (count($summaryParts) > 3) {
            $summary .= ' + more';
        }
    }

    $detailParts = [];
    foreach ($changes as $change) {
        $label = (string) ($change['label'] ?? 'Branch detail');
        $oldValue = $change['old'] ?? '—';
        $newValue = $change['new'] ?? '—';
        $detailParts[] = sprintf('%s: %s → %s', $label, $oldValue, $newValue);
    }

    $details = empty($detailParts)
        ? 'No specific field changes were recorded.'
        : implode('; ', $detailParts);

    $message = sprintf(
        'Branch manager %s updated the branch profile for %s%s. %s',
        $managerName,
        $branchName,
        $branchCode !== '' ? ' (' . $branchCode . ')' : '',
        $details
    );

    if (mb_strlen($message, 'UTF-8') > 255) {
        $message = mb_substr($message, 0, 252, 'UTF-8') . '...';
    }

    return [
        'importance' => 'medium',
        'short_summery' => $summary,
        'message' => $message,
    ];
}

// ------------------ POST: update branch profile ------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        redirect_with('danger', 'Invalid CSRF token. Please retry.');
    }

    $branch_name = trim($_POST['branch_name'] ?? '');
    $city        = trim($_POST['city'] ?? '');
    $township    = trim($_POST['township'] ?? '');
    $address     = trim($_POST['address'] ?? '');
    $phone       = trim($_POST['branch_phone'] ?? '');
    $email       = trim($_POST['branch_email'] ?? '');
    $opening     = trim($_POST['opening_time'] ?? '06:00');
    $closing     = trim($_POST['closing_time'] ?? '22:00');
    $is_24hr     = isset($_POST['is_24hr']) ? 1 : 0;
    $pump_count  = max(1, (int)($_POST['pump_count'] ?? 4));
    $tank_count  = max(1, (int)($_POST['tank_count'] ?? 3));
    $hq_region   = trim($_POST['hq_region'] ?? '');
    $status      = $_POST['status'] ?? 'active';
    if (!in_array($status, ['active','maintenance','closed'], true)) {
        $status = 'active';
    }

    if ($branch_name === '' || $city === '') {
        redirect_with('danger', 'Branch name and city are required.');
    }

    $changeList = [];
    $fieldChecks = [
        'Branch Name' => [$branch['branch_name'] ?? '', $branch_name],
        'City' => [$branch['city'] ?? '', $city],
        'Township' => [$branch['township'] ?? '', $township],
        'Address' => [$branch['address'] ?? '', $address],
        'Phone' => [$branch['phone'] ?? '', $phone],
        'Email' => [$branch['email'] ?? '', $email],
        'Opening Time' => [($branch['opening_time'] ?? '06:00:00'), $opening . ':00'],
        'Closing Time' => [($branch['closing_time'] ?? '22:00:00'), $closing . ':00'],
        '24 Hours Mode' => [(string)($branch['is_24hr'] ?? 0), (string)$is_24hr],
        'Pump Count' => [(string)($branch['pump_count'] ?? 0), (string)$pump_count],
        'Tank Count' => [(string)($branch['tank_count'] ?? 0), (string)$tank_count],
        'HQ Region' => [$branch['hq_region'] ?? '', $hq_region],
        'Status' => [$branch['status'] ?? 'active', $status],
    ];

    foreach ($fieldChecks as $label => [$oldValue, $newValue]) {
        if ((string)$oldValue !== (string)$newValue) {
            $changeList[] = [
                'label' => $label,
                'old' => (string)$oldValue,
                'new' => (string)$newValue,
            ];
        }
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE branches SET
                branch_name = :n, city = :c, township = :t, address = :a,
                phone = :ph, email = :em,
                opening_time = :ot, closing_time = :ct, is_24hr = :h24,
                pump_count = :pc, tank_count = :tc,
                hq_region = :hq, status = :st
            WHERE branch_id = :bid
        ");
        $stmt->execute([
            ':n' => $branch_name, ':c' => $city, ':t' => $township, ':a' => $address,
            ':ph' => $phone, ':em' => $email,
            ':ot' => $opening . ':00', ':ct' => $closing . ':00', ':h24' => $is_24hr,
            ':pc' => $pump_count, ':tc' => $tank_count,
            ':hq' => $hq_region, ':st' => $status,
            ':bid' => $branch['branch_id'],
        ]);

        if (!empty($changeList)) {
            $reportData = map_pump_branch_profile_report_data($user, $branch, $changeList);
          $accountId = map_pump_account_identity_ensure($pdo, 'user', (int) $user['user_id']);
            $insertReport = $pdo->prepare(
            "INSERT INTO report (user_id, submitted_by_account_id, role_id, importance, short_summery, message, created_at)
             VALUES (:user_id, :account_id, 4, :importance, :short_summery, :message, NOW())"
            );
            $insertReport->execute([
                ':user_id' => $user['user_id'],
            ':account_id' => $accountId,
                ':importance' => $reportData['importance'],
                ':short_summery' => $reportData['short_summery'],
                ':message' => $reportData['message'],
            ]);
            $report_id = (int)$pdo->lastInsertId();

            // Notify HQ recipients (non-fatal: report is already saved).
            try {
                $recipients = hq_recipient_ids($pdo, (int)$user['user_id']);
                $branchName = $branch['branch_name'] ?? ('Branch #' . $branch['branch_id']);
                $notifSubject = '[Medium] Branch profile updated: ' . $branchName;
                $notifBody = $reportData['message'];
                foreach ($recipients as $rid) {
                    notif_create($pdo, $rid, null, 'branch_report', $notifSubject, $notifBody, $report_id);
                }
            } catch (Throwable $e) {
                error_log('branchprofile notify failed: ' . $e->getMessage());
            }
        }

        log_activity($pdo, $branch['branch_id'], 'user', $user['user_id'], $user['full_name'],
                     'Updated branch profile');
        redirect_with('success', 'Branch profile updated successfully.');
    } catch (PDOException $e) {
        redirect_with('danger', 'Could not update: ' . $e->getMessage());
    }
}

// ------------------ Fetch aggregate stats for the profile card ------------------
$stmt = $pdo->prepare("SELECT COUNT(*) FROM staff WHERE branch_id = :b AND is_active = 1");
$stmt->execute([':b' => $branch['branch_id']]);
$active_staff = (int)$stmt->fetchColumn();

$pump_total = 0;
$pump_active = 0;
if ($pumpsTableExists) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM pumps WHERE branch_id = :b");
    $stmt->execute([':b' => $branch['branch_id']]);
    $pump_total = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM pumps WHERE branch_id = :b AND status = 'active'");
    $stmt->execute([':b' => $branch['branch_id']]);
    $pump_active = (int)$stmt->fetchColumn();
}

$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT customer_id)
    FROM (
        SELECT bk.user_id AS customer_id
        FROM booking bk
        INNER JOIN station_business_scope sbs ON sbs.station_id = bk.station_id
        WHERE sbs.branch_manager_user_id = :manager_id
        UNION
        SELECT uh.user_id AS customer_id
        FROM usage_history uh
        INNER JOIN station_business_scope sbs ON sbs.station_id = uh.station_id
        WHERE sbs.branch_manager_user_id = :manager_id
    ) scoped_customers
");
$stmt->execute([':manager_id' => $user['user_id']]);
$customer_total = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(current_l),0) AS filled, COALESCE(SUM(capacity_l),0) AS cap
    FROM tanks WHERE branch_id = :b
");
$stmt->execute([':b' => $branch['branch_id']]);
$row = $stmt->fetch();
$stock_pct = ($row && $row['cap'] > 0) ? round(($row['filled'] / $row['cap']) * 100) : 0;

// tanks summary
$stmt = $pdo->prepare("
    SELECT t.*, COALESCE(ot.oil_name, CONCAT('Fuel ', t.tank_id)) AS fuel_name
    FROM tanks t
    LEFT JOIN oil_types ot ON ot.oil_type_id = t.oil_type_id
    WHERE t.branch_id = :b
    ORDER BY t.tank_id ASC
");
$stmt->execute([':b' => $branch['branch_id']]);
$tanks = $stmt->fetchAll();

$flash = take_flash();
$alerts       = notif_recent($pdo, (int)$user['user_id'], 5);
$unread_count = notif_unread_count($pdo, (int)$user['user_id']);
$has_unread   = $unread_count > 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MAP & PUMP — Branch Profile</title>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="icon" type="image/png" href="/images/Logo.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@400;500;600;700&family=Noto+Sans+Myanmar:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
  :root{
    --navy-950:#070b14; --navy-900:#0b1220; --navy-800:#101a30; --navy-700:#16213f;
    --blue-700:#1d4ed8; --blue-500:#2f6feb; --sky-400:#38bdf8;
    --amber-400:#fbbf24; --amber-300:#fcd34d; --mint-400:#34d399;
    --red-400:#f87171;
    --slate-50:#f8fafc; --slate-300:#c9d2e3; --slate-400:#8b96ad;
  }
  body.branch-manager-theme {
    --navy-950:#070b14; --navy-900:#0b1220; --navy-800:#101a30; --navy-700:#16213f; --navy-600:#1a2438;
    --blue-700:#1d4ed8; --blue-500:#2f6feb; --sky-400:#38bdf8;
    --amber-400:#fbbf24; --amber-300:#fcd34d; --mint-400:#34d399; --red-400:#f87171;
    --slate-50:#f8fafc; --slate-300:#c9d2e3; --slate-400:#8b96ad;
    background: var(--navy-950); color: var(--slate-50);
  }
  body.branch-manager-theme.light-mode {
    --navy-950:#f3f6fb; --navy-900:#ffffff; --navy-800:#f7f9fc; --navy-700:#edf3fb; --navy-600:#e5edf8;
    --blue-700:#286be0; --blue-500:#3c82ff; --sky-400:#3f8cf5; --amber-400:#d98400; --amber-300:#e2a72d; --mint-400:#128e6b; --red-400:#cf3d3d;
    --slate-50:#172337; --slate-300:#3c4d68; --slate-400:#58677e; background: var(--navy-950); color: var(--slate-50);
  }
  body.branch-manager-theme.light-mode .sidebar,
  body.branch-manager-theme.light-mode .topbar,
  body.branch-manager-theme.light-mode .panel,
  body.branch-manager-theme.light-mode .kpi,
  body.branch-manager-theme.light-mode .tank-mini,
  body.branch-manager-theme.light-mode .notif-popup,
  body.branch-manager-theme.light-mode .bell,
  body.branch-manager-theme.light-mode .side-branch,
  body.branch-manager-theme.light-mode .avatar-chip,
  body.branch-manager-theme.light-mode .form-control-custom,
  body.branch-manager-theme.light-mode .checkbox-row,
  body.branch-manager-theme.light-mode .tank-carousel-nav {
    background: var(--navy-900); border-color: rgba(17,24,39,0.12); color: var(--slate-50);
  }
  body.branch-manager-theme.light-mode .sidebar { border-right-color: rgba(17,24,39,0.12); }
  body.branch-manager-theme.light-mode .topbar { border-bottom-color: rgba(17,24,39,0.12); }
  body.branch-manager-theme.light-mode .side-link,
  body.branch-manager-theme.light-mode .side-branch span,
  body.branch-manager-theme.light-mode .topbar .date,
  body.branch-manager-theme.light-mode .notif-item .text span,
  body.branch-manager-theme.light-mode .panel h3,
  body.branch-manager-theme.light-mode .form-group label {
    color: var(--slate-300);
  }
  body.branch-manager-theme.light-mode .side-link.active,
  body.branch-manager-theme.light-mode .side-link:hover,
  body.branch-manager-theme.light-mode .avatar-chip:hover,
  body.branch-manager-theme.light-mode .bell:hover {
    background: rgba(63, 140, 245, 0.10);
    color: var(--slate-50);
  }
  body.branch-manager-theme.light-mode .sidebar .disp { color: var(--slate-50) !important; }
  body.branch-manager-theme.light-mode .side-logout { border-top-color: rgba(17,24,39,0.12); }
  body.branch-manager-theme.light-mode .side-logout:hover { background: rgba(207,61,61,0.08); }
  body.branch-manager-theme.light-mode .side-branch .hq-logo { background: var(--navy-950); border-color: rgba(17,24,39,0.12); }
  body.branch-manager-theme.light-mode .panel,
  body.branch-manager-theme.light-mode .kpi { background: linear-gradient(160deg, var(--navy-800), var(--navy-900)); }
  body.branch-manager-theme.light-mode .branch-hero { border-color: rgba(63,140,245,0.28); }
  body.branch-manager-theme.light-mode .page-foot { border-top-color: rgba(17,24,39,0.12); }
  body.branch-manager-theme.light-mode .info-list .row { border-bottom-color: rgba(17,24,39,0.12); }
  body.branch-manager-theme.light-mode .gauge-mini { background: rgba(17,24,39,0.10); }
  body.branch-manager-theme.light-mode .tank-dot { background: rgba(17,24,39,0.22); }
  body.branch-manager-theme.light-mode .tank-dot.active { background: var(--slate-50); }
  body.branch-manager-theme.light-mode .btn-edit-mode { background: rgba(17,24,39,0.04); border-color: rgba(17,24,39,0.18); }
  body.branch-manager-theme.light-mode .btn-edit-mode:hover,
  body.branch-manager-theme.light-mode .btn-edit-mode.is-editing { background: rgba(63,140,245,0.12); border-color: var(--sky-400); color: var(--sky-400); }
  body.branch-manager-theme.light-mode .notif-popup { box-shadow: 0 16px 32px rgba(17,24,39,0.16); border-color: rgba(17,24,39,0.12); }
  body.branch-manager-theme.light-mode .notif-header { border-bottom-color: rgba(17,24,39,0.12); }
  body.branch-manager-theme.light-mode .notif-item:hover { background-color: rgba(17,24,39,0.04); }
  body.branch-manager-theme.light-mode .tank-carousel-nav:hover { background: var(--navy-700); border-color: var(--sky-400); }
  body.branch-manager-theme.light-mode .modal-content { border-color: rgba(17,24,39,0.12) !important; color: var(--slate-50); }
  .staff-theme-control {
    position: relative; display: grid; width: 28px; height: 32px; place-items: center; margin: 0; color: var(--slate-300); cursor: pointer;
  }
  .staff-theme-control input { position: absolute; width: 1px; height: 1px; opacity: 0; }
  .staff-theme-control span { font-size: 1.05rem; }
  .staff-theme-control:focus-within span { outline: 2px solid var(--sky-400); outline-offset: 4px; border-radius: 3px; }
  body.branch-manager-theme.light-mode .staff-theme-control { color: var(--slate-400); }

  *{box-sizing:border-box; margin:0; padding:0;}
  body{background:var(--navy-950); color:var(--slate-50); font-family:'Inter','Space Grotesk',sans-serif; overflow-x:hidden;}
  .disp{font-family:'Space Grotesk','Noto Sans Myanmar',sans-serif;}
  a{color:inherit; text-decoration:none;}
  button{font-family:inherit;}
  @media (prefers-reduced-motion: reduce){ *{animation-duration:0.001ms !important; animation-iteration-count:1 !important; transition: none !important;} }

  .app-shell{display:flex; min-height:100vh;}

  /* ---------- sidebar (matches all other pages) ---------- */
  .sidebar{
    width:252px; flex-shrink:0; background:var(--navy-900);
    border-right:1px solid rgba(255,255,255,0.06);
    display:flex; flex-direction:column; padding:22px 16px;
    position:sticky; top:0; height:100vh; z-index:60;
  }
  .nav-logo{width:32px; height:auto; object-fit:contain;}
  .side-role{font-size:11px; color:var(--slate-400); font-weight:500; margin:2px 0 0 46px;}
  .side-branch{
    margin:20px 8px 0; padding:10px 12px; background:var(--navy-800); border-radius:12px;
    border:1px solid rgba(255,255,255,0.06); font-size:12.5px;
    transition: background 0.3s, border-color 0.3s;
    display:flex; align-items:center; gap:10px;
  }
  .side-branch:hover{background:var(--navy-700); border-color:rgba(56,189,248,0.2);}
  .side-branch .hq-logo{
    width:36px; height:36px; border-radius:8px; object-fit:cover; flex-shrink:0;
    background:var(--navy-950); border:1px solid rgba(255,255,255,0.08);
  }
  .side-branch .txt{min-width:0; flex:1;}
  .side-branch b{display:block; font-size:14px; font-family:'Space Grotesk'; margin-bottom:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;}
  .side-branch span{color:var(--slate-400);}
  .side-nav{margin-top:22px; display:flex; flex-direction:column; gap:4px; flex:1;}
  .side-link{
    display:flex; align-items:center; gap:12px; padding:11px 12px; border-radius:10px; font-size:14px;
    color:var(--slate-300); font-weight:500;
    transition: background 0.25s ease, color 0.25s ease, transform 0.2s ease;
  }
  .side-link:hover{background:rgba(255,255,255,0.045); color:var(--slate-50); transform:translateX(4px);}
  .side-link.active{
    background:linear-gradient(135deg, rgba(56,189,248,0.16), rgba(29,78,216,0.12));
    color:var(--slate-50); box-shadow:inset 0 0 0 1px rgba(56,189,248,0.32);
  }
  .side-link.active:hover{transform:none;}
  .side-link .ic{font-size:16px; width:18px; text-align:center; transition: transform 0.2s ease;}
  .side-link:hover .ic{transform: scale(1.15);}
  .side-logout{
    display:flex; align-items:center; gap:12px; padding:12px; border-radius:10px; font-size:14px;
    color:var(--red-400); font-weight:600; margin-top:14px; border-top:1px solid rgba(255,255,255,0.06); padding-top:18px;
    transition: background 0.25s, padding-left 0.2s;
  }
  .side-logout:hover{background:rgba(248,113,113,0.08); padding-left:16px;}

  .main{flex:1; min-width:0;}
  .topbar{
    position:sticky; top:0; z-index:50; display:flex; align-items:center; justify-content:space-between;
    padding:16px 32px; background:rgba(7,11,20,0.72); backdrop-filter:blur(14px); border-bottom:1px solid rgba(255,255,255,0.06);
  }
  .topbar h1{font-size:18px; font-weight:700;}
  .topbar .date{font-size:12.5px; color:var(--slate-400); margin-top:2px; font-variant-numeric: tabular-nums;}
  .topbar-right{display:flex; align-items:center; gap:16px; position:relative;}
  .bell{
    width:36px; height:36px; border-radius:50%; background:var(--navy-800); display:flex; align-items:center;
    justify-content:center; border:1px solid rgba(255,255,255,0.08); position:relative; font-size:15px; cursor:pointer;
    transition: transform 0.2s, background-color 0.2s;
  }
  .bell:hover{background:var(--navy-700); transform:scale(1.05);}
  .bell .ping{position:absolute; top:6px; right:7px; width:7px; height:7px; border-radius:50%; background:var(--amber-400); box-shadow:0 0 0 2px var(--navy-800);}
  .avatar-chip{display:flex; align-items:center; gap:10px; font-size:13px; cursor:pointer; padding:4px 8px; border-radius:99px; transition: background 0.2s;}
  .avatar-chip:hover{background:rgba(255,255,255,0.05);}
  .avatar-circle{width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,var(--sky-400),var(--blue-700)); display:flex; align-items:center; justify-content:center; font-weight:700; font-size:13px; font-family:'Space Grotesk';}

  .content{padding:30px 32px 60px; opacity:0; transform:translateY(10px); animation: fadeIn 0.5s ease forwards;}
  @keyframes fadeIn{to{opacity:1; transform:translateY(0);}}
  .page-foot{text-align:center; margin-top:34px; padding-top:18px; border-top:1px solid rgba(255,255,255,0.06); font-size:12px; color:var(--slate-400);}

  .page-head{margin-bottom:22px;}
  .page-head h2{font-size:20px; font-weight:700;}
  .page-head p{font-size:13px; color:var(--slate-400); margin-top:4px;}

  /* ---------- panels & hero card ---------- */
  .panel{background:linear-gradient(160deg, var(--navy-800), var(--navy-900)); border:1px solid rgba(255,255,255,0.07); border-radius:18px; padding:26px;}
  .panel h3{font-size:15px; font-weight:700; margin-bottom:18px;}

  .branch-hero{
    display:flex; gap:22px; align-items:center; margin-bottom:22px;
    background:linear-gradient(135deg, rgba(56,189,248,0.10), rgba(29,78,216,0.04) 60%, transparent);
    border:1px solid rgba(56,189,248,0.16); border-radius:20px; padding:22px 26px;
  }
  .branch-logo{
    width:76px; height:76px; border-radius:20px; background:linear-gradient(135deg,var(--sky-400),var(--blue-700));
    display:flex; align-items:center; justify-content:center; font-size:30px; color:#fff; font-family:'Space Grotesk'; font-weight:700; flex-shrink:0;
  }
  .branch-hero h4{font-size:20px; font-weight:700; margin-bottom:4px;}
  .branch-hero .sub{font-size:12.5px; color:var(--slate-400);}
  .status-badge{
    display:inline-flex; align-items:center; gap:6px; font-size:11.5px; font-weight:600;
    padding:5px 12px; border-radius:999px; margin-left:8px;
  }
  .status-badge.active{background:rgba(52,211,153,0.14); color:var(--mint-400); border:1px solid rgba(52,211,153,0.3);}
  .status-badge.maintenance{background:rgba(251,191,36,0.14); color:var(--amber-300); border:1px solid rgba(251,191,36,0.3);}
  .status-badge.closed{background:rgba(248,113,113,0.14); color:var(--red-400); border:1px solid rgba(248,113,113,0.3);}
  .status-badge .d{width:6px; height:6px; border-radius:50%; background:currentColor;}

  .kpi-strip{display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:22px;}
  @media (max-width:900px){.kpi-strip{grid-template-columns:repeat(2,1fr);}}
  .kpi{background:linear-gradient(160deg, var(--navy-800), var(--navy-900)); border:1px solid rgba(255,255,255,0.06); border-radius:14px; padding:16px 18px;}
  .kpi .lbl{font-size:11.5px; color:var(--slate-400); text-transform:uppercase; letter-spacing:0.5px;}
  .kpi .val{font-size:22px; font-weight:700; margin-top:6px; font-family:'Space Grotesk';}
  .kpi .val small{font-size:12px; font-weight:500; color:var(--slate-400); margin-left:4px;}

  .layout-2col{display:grid; grid-template-columns:1.2fr 1fr; gap:20px; align-items:start;}
  @media (max-width:1080px){.layout-2col{grid-template-columns:1fr;}}

  .form-row{display:grid; grid-template-columns:1fr 1fr; gap:14px;}
  .form-row-3{display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px;}
  @media (max-width:600px){.form-row,.form-row-3{grid-template-columns:1fr;}}
  .form-group{margin-bottom:16px;}
  .form-group label{display:block; font-size:12px; color:var(--slate-400); margin-bottom:6px; font-weight:500;}
  .form-control-custom{
    width:100%; background:var(--navy-950); border:1px solid rgba(255,255,255,0.08);
    border-radius:10px; padding:11px 14px; color:var(--slate-50); font-size:13.5px; outline:none;
    font-family:inherit; transition: border-color 0.2s;
  }
  .form-control-custom:focus{border-color:var(--sky-400);}
  .form-control-custom:disabled{color:var(--slate-400); cursor:not-allowed; opacity:0.7;}
  select.form-control-custom{appearance:none; background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%238b96ad' viewBox='0 0 16 16'><path d='M8 12L2 6h12z'/></svg>"); background-repeat:no-repeat; background-position:right 14px center;}

  .checkbox-row{display:flex; align-items:center; gap:10px; padding:11px 14px; background:var(--navy-950); border:1px solid rgba(255,255,255,0.08); border-radius:10px;}
  .checkbox-row input{accent-color:var(--sky-400);}

  .btn-save{
    background:linear-gradient(135deg, var(--sky-400), var(--blue-700)); color:#fff; font-weight:700; font-size:13.5px;
    padding:12px 20px; border-radius:11px; border:none; cursor:pointer; display:inline-flex; align-items:center; gap:8px;
    box-shadow:0 10px 24px -10px rgba(56,189,248,0.55); transition: transform 0.2s, box-shadow 0.2s;
  }
  .btn-save:hover:not(:disabled){transform:translateY(-2px); box-shadow:0 12px 28px -8px rgba(56,189,248,0.7);}
  .btn-save{transition: transform 0.2s, box-shadow 0.2s, opacity 0.2s, filter 0.2s;}
  .btn-save:disabled{opacity:0.4; filter:grayscale(35%); cursor:not-allowed; pointer-events:none; box-shadow:none;}

  /* ---------- redesign: overview grid, details panel header, edit mode ---------- */
  .top-overview-grid{display:grid; grid-template-columns:1.6fr 0.85fr; gap:18px; margin-bottom:22px; align-items:stretch;}
  @media (max-width:980px){.top-overview-grid{grid-template-columns:1fr;}}
  .top-overview-grid .branch-hero{margin-bottom:0; padding:24px 26px;}
  .branch-hero h4{display:flex; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:6px;}
  .branch-hero h4 .status-badge{margin-left:0; padding:4px 12px;}
  .branch-hero .sub{font-size:13px; display:flex; align-items:center; flex-wrap:wrap; gap:10px;}
  .kpi-stack{display:flex; flex-direction:column; gap:14px;}
  .kpi-stack .kpi{flex:1; padding:16px 20px; display:flex; flex-direction:column; justify-content:center;}
  .kpi-stack .kpi .val{font-size:24px;}

  .panel-header{display:flex; align-items:center; justify-content:space-between; margin-bottom:20px;}
  .panel-header h3{font-size:16px; margin:0;}
  .btn-edit-mode{
    display:inline-flex; align-items:center; gap:8px; background:rgba(255,255,255,0.05);
    border:1px solid rgba(255,255,255,0.14); color:var(--slate-50); font-size:13.5px; font-weight:600;
    padding:8px 14px; border-radius:10px; cursor:pointer;
    transition: background 0.2s, border-color 0.2s, color 0.2s;
  }
  .btn-edit-mode:hover{background:rgba(56,189,248,0.12); border-color:var(--sky-400); color:var(--sky-400);}
  .btn-edit-mode.is-editing{background:rgba(56,189,248,0.16); border-color:var(--sky-400); color:var(--sky-400);}
  .form-control-custom:disabled{color:var(--slate-300);}
  .checkbox-row input:disabled, .checkbox-row input:disabled + label{cursor:not-allowed !important;}
  .details-form{margin-bottom:20px;}
  .info-list .row{flex-wrap:nowrap; margin:0; --bs-gutter-x:0;}
  .info-list .row > *{width:auto; max-width:none; padding:0; margin-top:0;}
  .layout-2col.equal{grid-template-columns:1fr 1fr;}
  @media (max-width:1080px){.layout-2col.equal{grid-template-columns:1fr;}}

  /* right column: tanks summary + branch quick info */
  .tank-mini{
    background:var(--navy-950); border:1px solid rgba(255,255,255,0.06); border-radius:14px; padding:16px 18px;
  }
  .tank-mini-top{display:flex; align-items:center; justify-content:space-between; gap:12px; font-size:14px; margin-bottom:12px;}
  .tank-mini-top b{font-family:'Space Grotesk'; font-size:15px; letter-spacing:0.3px;}
  .tank-mini-top .pct{font-weight:700; font-family:'Space Grotesk'; font-size:18px; line-height:1;}
  .gauge-mini{height:8px; background:rgba(255,255,255,0.06); border-radius:99px; overflow:hidden;}
  .gauge-mini i{display:block; height:100%; border-radius:99px;}
  .tank-mini-meta{display:flex; align-items:center; justify-content:space-between; gap:12px; font-size:12px; color:var(--slate-400); margin-top:12px;}
  .tank-status{display:inline-flex; align-items:center; gap:6px; padding:3px 10px; border-radius:99px; font-size:11px; font-weight:600; background:rgba(148,163,184,0.12); color:var(--slate-400);}
  .tank-status::before{content:''; width:6px; height:6px; border-radius:50%; background:currentColor;}
  .tank-status.on{background:rgba(52,211,153,0.12); color:var(--mint-400);}

  .info-list{display:flex; flex-direction:column; gap:12px; font-size:13px;}
  .info-list .row{display:flex; justify-content:space-between; align-items:center; padding-bottom:12px; border-bottom:1px dashed rgba(255,255,255,0.06);}
  .info-list .row:last-child{border-bottom:none; padding-bottom:0;}
  .info-list .row .lbl{color:var(--slate-400); font-size:12px;}
  .info-list .row .val{font-weight:600;}

  .alert{padding:12px 16px; border-radius:12px; margin-bottom:18px; font-size:13px; display:flex; align-items:center; gap:10px;}
  .alert.success{background:rgba(52,211,153,0.10); border:1px solid rgba(52,211,153,0.3); color:var(--mint-400);}
  .alert.danger{background:rgba(248,113,113,0.10);  border:1px solid rgba(248,113,113,0.3); color:var(--red-400);}

  .notif-popup {
    position: absolute;
    top: 48px;
    right: 60px;
    width: 320px;
    max-height: 420px;
    overflow: hidden;
    background: var(--navy-800);
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 14px;
    box-shadow: 0 16px 32px rgba(0, 0, 0, 0.4);
    padding: 12px;
    z-index: 100;
    display: none;
    animation: fadeInDown 0.25s cubic-bezier(0.16, 1, 0.3, 1);
  }
  .notif-popup.show { display: block; }
  .notif-header { display:flex; align-items:center; justify-content:space-between; border-bottom:1px solid rgba(255,255,255,0.08); padding-bottom:10px; margin-bottom:10px; }
  .notif-header h4 { font-size:14px; font-weight:700; margin:0; }
  .notif-header button { background:transparent; border:none; color:var(--sky-400); font-size:11px; cursor:pointer; }
  .notif-list {
    max-height: 330px;
    overflow-y: auto;
    overflow-x: auto;
    padding-right: 4px;
    scrollbar-width: thin;
    scrollbar-color: rgba(56,189,248,0.35) transparent;
  }
  .notif-list::-webkit-scrollbar { width: 8px; height: 8px; }
  .notif-list::-webkit-scrollbar-track { background: transparent; }
  .notif-list::-webkit-scrollbar-thumb { background: rgba(56,189,248,0.35); border-radius: 999px; }
  .notif-list::-webkit-scrollbar-thumb:hover { background: rgba(56,189,248,0.55); }
  .notif-item { padding:10px 8px; border-radius:10px; display:flex; gap:10px; align-items:flex-start; transition:background-color 0.2s; border:1px solid transparent; }
  .notif-item:hover { background-color:rgba(255,255,255,0.04); }
  .notif-item .icon { width:32px; height:32px; border-radius:10px; display:none; align-items:center; justify-content:center; font-size:12px; flex-shrink:0; background:rgba(148,163,184,0.12); color:var(--slate-200); }
  .notif-item.unread { border-color: rgba(56,189,248,0.2); }
  .notif-item.warning .icon { background: rgba(251,191,36,0.16); color: var(--amber-300); }
  .notif-item.error .icon { background: rgba(248,113,113,0.16); color: var(--red-400); }
  .notif-item.success .icon { background: rgba(52,211,153,0.14); color: var(--mint-400); }
  .notif-item.info .icon { background: rgba(56,189,248,0.14); color: var(--sky-400); }
  .notif-item .text b { display:block; font-size:12.5px; color:var(--slate-50); margin-bottom:2px; }
  .notif-item .text span { color:var(--slate-400); font-size:11.5px; display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .notif-item .time { font-size:10px; color:var(--slate-400); margin-top:4px; }
  .notif-item-body {
    min-width: 0;
    flex: 1;
  }
  .notif-item-body b { display:block; font-size:12.5px; color:var(--slate-50); margin-bottom:2px; }
  .notif-item-body span { color:var(--slate-400); font-size:11.5px; display:block; white-space:normal; line-height:1.5; }
  .notif-item-body .notif-time { font-size:10px; color:var(--slate-400); margin-top:4px; }
  .notif-empty { text-align:center; padding:12px 0; color:var(--slate-400); font-size:12px; }
  @keyframes fadeInDown { from { opacity:0; transform: translateY(-8px); } to { opacity:1; transform: translateY(0); } }

  /* ---------- Tank Carousel Styles ---------- */
  .tank-panel-head{display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:18px;}
  .tank-panel-head h3{margin-bottom:0;}
  .tank-carousel-controls{display:flex; align-items:center; gap:8px;}
  .tank-counter{font-size:12px; color:var(--slate-400); font-family:'Space Grotesk'; min-width:36px; text-align:center;}
  .tank-carousel-container { position: relative; min-width: 0; }
  .tank-slider-track { display: flex; overflow: hidden; scroll-behavior: smooth; width: 100%; min-width: 0; }
  .tank-slide { flex: 0 0 100%; min-width: 0; }
  .tank-carousel-nav {
    background: var(--navy-900); border: 1px solid rgba(255,255,255,0.12);
    color: var(--slate-50); width: 34px; height: 34px; border-radius: 50%; font-size: 13px; padding: 0;
    display: flex; align-items:center; justify-content:center; cursor: pointer;
    transition: background 0.2s, border-color 0.2s;
  }
  .tank-carousel-nav:hover { background: var(--navy-700); border-color: var(--sky-400); }
  .tank-carousel-dots {
    display: flex; justify-content: center; gap: 6px; margin-top: 16px;
  }
  .tank-dot {
    width: 8px; height: 8px; border-radius: 50%; background: rgba(255,255,255,0.2);
    display: block; transition: background 0.2s, width 0.2s; pointer-events: none;
  }
  .tank-dot.active { background: var(--slate-50); width: 20px; border-radius: 4px; }
</style>
</head>
<body>

<div class="app-shell">
  <aside class="sidebar">
    <a href="dashboard.php" class="text-decoration-none d-flex align-items-center gap-2 text-white fw-bold" style="padding: 6px 8px 0;">
      <img src="/images/Logo.png" alt="Logo" class="nav-logo">
      <span class="disp" style="font-size:17px; letter-spacing:0.5px; color:#fff; font-weight:700;">Map & Pump</span>
    </a>
    <div class="side-role">Branch Manager Console</div>

    <?php $hqLogoSrc = function_exists('hq_logo_src') ? hq_logo_src($branch['hq_logo'] ?? null) : null; ?>
    <div class="side-branch">
      <?php if ($hqLogoSrc): ?>
        <img src="<?= h($hqLogoSrc) ?>" alt="HQ Logo" class="hq-logo" onerror="this.style.display='none'">
      <?php else: ?>
        <div class="hq-logo" style="display:flex; align-items:center; justify-content:center; color:var(--slate-400);"><i class="fa-solid fa-building"></i></div>
      <?php endif; ?>
      <div class="txt">
        <b><?= h($branch['branch_name']) ?></b>
        <span>Branch ID: <?= h($branch['branch_code']) ?></span>
      </div>
    </div>

    <nav class="side-nav">
      <a href="dashboard.php" class="side-link active"><span class="ic"><i class="fa-solid fa-house"></i></span> Dashboard</a>
      <a href="manage-staff.php" class="side-link"><span class="ic"><i class="fa-solid fa-users"></i></span> Manage Staff</a>
      <a href="manage-oil.php" class="side-link"><span class="ic"><i class="fa-solid fa-gas-pump"></i></span> Manage Fuel Stock</a>
      <a href="booking-slot.php" class="side-link"><span class="ic"><i class="fa-solid fa-ticket"></i></span> Monitor Booking Slot</a>
      <a href="view-customers.php" class="side-link"><span class="ic"><i class="fa-solid fa-address-book"></i></span> View Customers</a>
      <a href="report-hq.php" class="side-link"><span class="ic"><i class="fa-solid fa-paper-plane"></i></span> Report to HQ</a>
      <a href="branchprofile.php" class="side-link"><span class="ic"><i class="fa-solid fa-building"></i></span> Branch Profile</a>
      <a href="edit-profile.php" class="side-link"><span class="ic"><i class="fa-solid fa-gear"></i></span> Edit Profile</a>
    </nav>

    <a href="logout.php" class="side-logout"><span class="ic"><i class="fa-solid fa-right-from-bracket"></i></span> Log Out</a>
  </aside>

  <div class="main">
    <div class="topbar">
      <div>
        <h1>Branch Profile</h1>
        <div class="date" id="live-clock">--</div>
      </div>
      <div class="topbar-right">
        <div class="bell" id="notif-bell"><i class="fa-regular fa-bell"></i><?php if ($has_unread): ?><span class="ping" id="notif-ping"></span><?php endif; ?></div>
        <div class="notif-popup" id="notif-popup">
          <div class="notif-header">
            <h4>Notifications</h4>
          </div>
          <div class="notif-list" id="notif-list">
            <?php if (!$alerts): ?>
              <div class="notif-empty">No new notifications</div>
            <?php else:
              foreach ($alerts as $a):
                $meta = notif_ui_meta($a['type'], $a['title']);
                $icon = $meta['icon'];
                $tone = $meta['tone'];
            ?>
              <div class="notif-item <?= h($tone) ?>" data-notif-id="<?= (int)$a['notif_id'] ?>" data-link="<?= h($a['link'] ?? 'dashboard.php') ?>" data-kind="<?= h($tone) ?>">
                <div class="notif-item-body">
                  <b><?= h($a['title']) ?></b>
                  <span><?= h($a['body']) ?></span>
                  <div class="notif-time"><?= h($a['time_ago']) ?></div>
                </div>
              </div>
            <?php endforeach; endif; ?>
          </div>
        </div>
        <label class="staff-theme-control" title="Toggle light mode">
          <input class="js-theme-toggle" type="checkbox" aria-label="Toggle light mode">
          <span><i class="fa-solid fa-sun"></i></span>
        </label>

        <div class="avatar-chip">
          <?php if ($profileAvatarUrl !== ''): ?>
            <img src="<?= h($profileAvatarUrl) ?>" alt="Profile Picture" style="width:36px;height:36px;border-radius:50%;object-fit:cover;border:1px solid rgba(255,255,255,0.12);">
          <?php else: ?>
            <div class="avatar-circle"><a href="edit-profile.php"><?= h(mb_substr($initials,0,1)) ?></a></div>
          <?php endif; ?>
          <div><b><a href="edit-profile.php"><?= h($profileDisplayName) ?></a></b></div>
        </div>
      </div>
    </div>

    <div class="content">
      <div class="page-head">
        <h2>Station Profile</h2>
        <p>Manage physical details about this branch — address, hours, capacity, and HQ assignment.</p>
      </div>

      <?php if ($flash): ?>
        <div class="alert <?= h($flash['type']) ?>">
          <i class="fa-solid fa-<?= $flash['type'] === 'success' ? 'circle-check' : 'triangle-exclamation' ?>"></i>
          <?= h($flash['msg']) ?>
        </div>
      <?php endif; ?>

      <!-- ===== Hero card + stacked KPIs ===== -->
      <div class="top-overview-grid">
        <div class="branch-hero">
          <div class="branch-logo" style="overflow:hidden;">
            <?php if ($hqLogoSrc): ?>
              <img src="<?= h($hqLogoSrc) ?>" alt="HQ branch profile image" style="width:100%; height:100%; object-fit:cover; border-radius:20px; display:block;">
            <?php else: ?>
              <span><?= h(mb_substr($branch['branch_code'] ?? 'BR', 0, 2)) ?></span>
            <?php endif; ?>
          </div>
          <div style="flex:1;">
            <h4>
              <?= h($branch['branch_name']) ?>
              <span class="status-badge <?= h($branch['status']) ?>"><span class="d"></span> <?= h(ucfirst($branch['status'])) ?></span>
            </h4>
            <div class="sub">
              <span><i class="fa-solid fa-location-dot"></i> <?= h((string) ($branch['address'] ?: 'No address on file')) ?></span>
              <span>·</span>
              <span><i class="fa-solid fa-hashtag"></i> <?= h($branch['branch_code']) ?></span>
            </div>
          </div>
        </div>

        <div class="kpi-stack">
          <div class="kpi">
            <div class="lbl">Active Staff</div>
            <div class="val"><?= $active_staff ?></div>
          </div>
          <div class="kpi">
            <div class="lbl">Total Customers</div>
            <div class="val"><?= number_format($customer_total) ?></div>
          </div>
        </div>
      </div>

      <!-- ===== Branch details (edit mode toggle) ===== -->
      <div class="panel details-form">
        <div class="panel-header">
          <h3>Branch Details</h3>
          <button type="button" class="btn-edit-mode" id="toggleEditBtn">
            <i class="fa-regular fa-pen-to-square"></i>
            <span id="toggleEditText">Edit</span>
          </button>
        </div>
        <form method="POST" action="branchprofile.php" id="branchForm">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

            <div class="form-row">
              <div class="form-group">
                <label for="branch_name">Branch Name</label>
                <input type="text" id="branch_name" name="branch_name" class="form-control-custom editable-field" disabled value="<?= h($branch['branch_name']) ?>" required>
              </div>
              <div class="form-group">
                <label for="branch_code">Branch Code</label>
                <input type="text" id="branch_code" class="form-control-custom" value="<?= h($branch['branch_code']) ?>" disabled>
              </div>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label for="city">City</label>
                <input type="text" id="city" name="city" class="form-control-custom editable-field" disabled value="<?= h($branch['city']) ?>" required>
              </div>
              <div class="form-group">
                <label for="township">Township</label>
                <input type="text" id="township" name="township" class="form-control-custom editable-field" disabled value="<?= h($branch['township']) ?>">
              </div>
            </div>

            <div class="form-group">
              <label for="address">Full Address</label>
              <input type="text" id="address" name="address" class="form-control-custom editable-field" disabled value="<?= h((string) $branch['address']) ?>">
            </div>

            <div class="form-row">
              <div class="form-group">
                <label for="branch_phone">Branch Phone</label>
                <input type="text" id="branch_phone" name="branch_phone" class="form-control-custom editable-field" disabled value="<?= h((string) $branch['phone']) ?>">
              </div>
              <div class="form-group">
                <label for="branch_email">Branch Email</label>
                <input type="email" id="branch_email" name="branch_email" class="form-control-custom editable-field" disabled value="<?= h((string) $branch['email']) ?>">
              </div>
            </div>

            <div class="form-row-3">
              <div class="form-group">
                <label for="opening_time">Opening Time</label>
                <input type="time" id="opening_time" name="opening_time" class="form-control-custom editable-field" disabled value="<?= h(substr($branch['opening_time'] ?? '06:00:00', 0, 5)) ?>">
              </div>
              <div class="form-group">
                <label for="closing_time">Closing Time</label>
                <input type="time" id="closing_time" name="closing_time" class="form-control-custom editable-field" disabled value="<?= h(substr($branch['closing_time'] ?? '22:00:00', 0, 5)) ?>">
              </div>
              <div class="form-group">
                <label>24 Hours?</label>
                <div class="checkbox-row">
                  <input type="checkbox" id="is_24hr" class="editable-field" disabled name="is_24hr" <?= $branch['is_24hr'] ? 'checked' : '' ?>>
                  <label for="is_24hr" style="margin:0; color:var(--slate-50); font-weight:400; cursor:pointer;">Open 24 / 7</label>
                </div>
              </div>
            </div>

            <!-- Not shown in UI; kept so saving does not reset these stored values -->
            <input type="hidden" name="pump_count" value="<?= h($branch['pump_count']) ?>">
            <input type="hidden" name="tank_count" value="<?= h($branch['tank_count']) ?>">
            <input type="hidden" name="status" value="<?= h($branch['status']) ?>">
            <input type="hidden" name="hq_region" value="<?= h($branch['hq_region']) ?>">

            <button type="submit" class="btn-save" id="saveBtn" disabled><i class="fa-solid fa-check"></i> Send Report &amp; Save Changes</button>
          </form>
        </div>


      <div class="page-foot">© 2026 MAP & PUMP | All Right Reserved</div>
    </div>
  </div>
</div>

<div class="modal fade" id="notifModal" tabindex="-1" aria-labelledby="notifModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content" style="background:var(--navy-900); border:1px solid rgba(255,255,255,0.06);">
      <div class="modal-header">
        <h5 class="modal-title" id="notifModalLabel">Recent Notifications</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" style="padding:16px 18px;">
        <?php if (!$alerts): ?>
          <div style="color:var(--slate-400); font-size:14px; text-align:center; padding:40px 0;">
            <i class="fa-solid fa-bell" style="font-size:28px; opacity:0.6;"></i>
            <div style="margin-top:12px;">No new notifications</div>
          </div>
        <?php else: foreach ($alerts as $a): ?>
          <div class="alert alert-<?= h($a['type']) ?>" style="border-radius:10px; margin-bottom:12px;">
            <div style="display:flex; align-items:center; gap:10px;">
              <div class="flex-shrink-0">
                <div class="p-2 rounded-circle" style="background:rgba(255,255,255,0.08);">
                  <i class="fa-solid fa-<?= $a['type'] === 'success' ? 'circle-check' : ($a['type'] === 'info' ? 'info-circle' : 'triangle-exclamation') ?>" style="color:inherit;"></i>
                </div>
              </div>
              <div class="flex-grow-1">
                <div style="font-weight:500;"><?= h($a['title']) ?></div>
                <div style="font-size:12px; color:var(--slate-400); margin-top:2px;"><?= h($a['message']) ?></div>
              </div>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  function updateClock() {
    const now = new Date();
    const opts = { weekday:'long', month:'short', day:'numeric', year:'numeric' };
    const d = now.toLocaleDateString('en-US', opts);
    const t = now.toLocaleTimeString('en-US', { hour12:true, hour:'2-digit', minute:'2-digit', second:'2-digit' });
    document.getElementById('live-clock').textContent = `${d} · ${t}`;
  }
  setInterval(updateClock, 1000); updateClock();

  const bell = document.getElementById('notif-bell');
  const notifPopup = document.getElementById('notif-popup');
  const notifPing = document.getElementById('notif-ping');
  const notifList = document.getElementById('notif-list');

  function postDismiss(payload) {
    return fetch('api_dismiss_notification.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    }).then(r => r.json()).catch(() => ({ ok: false }));
  }

  if (bell && notifPopup) {
    bell.addEventListener('click', (e) => {
      e.stopPropagation();
      const ping = bell.querySelector('.ping');
      if (ping) {
        ping.style.transform = 'scale(0)';
        setTimeout(() => ping.remove(), 180);
      }
      notifPopup.classList.toggle('show');
    });

    document.addEventListener('click', (e) => {
      const clickedBell = bell.contains(e.target);
      const clickedPopup = notifPopup.contains(e.target);
      if (!clickedBell && !clickedPopup) {
        notifPopup.classList.remove('show');
      }
    });
  }

  const toggleEditBtn = document.getElementById('toggleEditBtn');
  const toggleEditText = document.getElementById('toggleEditText');
  const saveBtn = document.getElementById('saveBtn');
  const editableFields = document.querySelectorAll('.editable-field');

  if (toggleEditBtn) {
    toggleEditBtn.addEventListener('click', () => {
      const isEditing = toggleEditBtn.classList.toggle('is-editing');
      toggleEditText.textContent = isEditing ? 'Cancel' : 'Edit';
      editableFields.forEach((field) => {
        field.disabled = !isEditing;
      });
      if (saveBtn) saveBtn.disabled = !isEditing;
    });
  }

  if (notifList) {
    notifList.addEventListener('click', (e) => {
      const item = e.target.closest('.notif-item');
      if (!item) return;
      const id = Number(item.dataset.notifId || 0);
      const link = item.dataset.link || 'dashboard.php';
      if (id > 0) {
        postDismiss({ notif_id: id }).then((res) => {
          if (res.ok && notifPing && Number(res.unread || 0) <= 0) {
            notifPing.remove();
          }
        });
      }
      if (link) {
        window.location.href = link;
      }
    });
  }
});
// Tank Carousel Slider Controller
  const tankTrack = document.getElementById('tank-slider-track');
  const tankSlides = document.querySelectorAll('.tank-slide');
  const tankPrev = document.getElementById('tank-prev');
  const tankNext = document.getElementById('tank-next');
  const tankDots = document.querySelectorAll('.tank-dot');
  const tankCounter = document.getElementById('tank-counter');
  
  if (tankTrack && tankSlides.length > 0) {
    let currentTankIndex = 0;
    const totalTanks = tankSlides.length;

    function updateTankSlide(index) {
      if (index < 0) currentTankIndex = totalTanks - 1;
      else if (index >= totalTanks) currentTankIndex = 0;
      else currentTankIndex = index;

      tankTrack.scrollTo({
        left: tankTrack.clientWidth * currentTankIndex,
        behavior: 'smooth'
      });

      tankDots.forEach((dot, idx) => {
        dot.classList.toggle('active', idx === currentTankIndex);
      });
      if (tankCounter) tankCounter.textContent = (currentTankIndex + 1) + ' / ' + totalTanks;
    }

    if (tankNext) tankNext.addEventListener('click', () => updateTankSlide(currentTankIndex + 1));
    if (tankPrev) tankPrev.addEventListener('click', () => updateTankSlide(currentTankIndex - 1));

  }
</script>
<script>
  (function () {
    const root = document.body;
    const storedTheme = localStorage.getItem('map_pump_branch_manager_theme');
    const toggle = document.querySelector('.js-theme-toggle');
    const icon = document.querySelector('.staff-theme-control span i');

    function applyTheme(isLight) {
      root.classList.toggle('light-mode', isLight);
      if (toggle) toggle.checked = isLight;
      if (icon) {
        icon.className = isLight ? 'fa-solid fa-moon' : 'fa-solid fa-sun';
      }
      const control = document.querySelector('.staff-theme-control');
      if (control) control.title = isLight ? 'Toggle dark mode' : 'Toggle light mode';
      localStorage.setItem('map_pump_branch_manager_theme', isLight ? 'light' : 'dark');
    }

    root.classList.add('branch-manager-theme');
    applyTheme(storedTheme === 'light');

    if (toggle) {
      toggle.addEventListener('change', function () {
        applyTheme(this.checked);
      });
    }
  })();
</script>
</body>
</html>
