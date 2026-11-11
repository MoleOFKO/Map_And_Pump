<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (!function_exists('map_pump_user_display_name')) {
    require_once __DIR__ . '/../profile_avatar.php';
}
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../conn.php';
require_branch_manager();

$ctx      = current_context($pdo);
$user     = $ctx['user'];
$branch   = $ctx['branch'];
$initials = initials($user['full_name']);
$bid      = (int)$branch['branch_id'];

$managerDisplayName = map_pump_user_display_name($user['full_name'] ?? ($_SESSION['user_name'] ?? ''), '');
$managerInitial     = map_pump_profile_initial($managerDisplayName, $initials ?: 'M');
$managerAvatarUrl   = map_pump_user_avatar_url($user['avatar_url'] ?? '', '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) json_out(['ok' => false, 'error' => 'CSRF failed'], 403);
    $category = $_POST['category'] ?? '';
    $subject  = trim($_POST['subject'] ?? '');
    $priority = $_POST['priority'] ?? 'low';
    $desc     = trim($_POST['description'] ?? '');

    $valid_cat = ['Stock Shortage','Equipment Issue','Staff Incident','Customer Complaint','Other'];
    if (!in_array($category, $valid_cat, true)) json_out(['ok' => false, 'error' => 'Invalid category']);
    if (!in_array($priority, ['low','medium','high'], true)) $priority = 'low';
    if ($subject === '' || $desc === '') json_out(['ok' => false, 'error' => 'Subject and description are required']);

    try {
      $accountId = map_pump_account_identity_ensure($pdo, 'user', (int) $user['user_id']);
        $stmt = $pdo->prepare("
        INSERT INTO report (user_id, submitted_by_account_id, role_id, importance, short_summery, message)
        VALUES (:u, :account_id, 4, :p, :s, :d)
        ");
        $stmt->execute([
            ':u' => $user['user_id'],
        ':account_id' => $accountId,
            ':s' => $subject,
            ':p' => $priority,
            ':d' => '[' . $category . '] ' . $desc,
        ]);
        $report_id = (int)$pdo->lastInsertId();
        log_activity($pdo, $bid, 'user', $user['user_id'], $user['full_name'], "Filed report: {$subject}");

        // Notify HQ recipients (non-fatal: the report is already saved).
        try {
            $recipients = hq_recipient_ids($pdo, (int)$user['user_id']);
            $branchName = $branch['branch_name'] ?? ('Branch #' . $bid);
            $notifSubject = '[' . ucfirst($priority) . '] Branch report: ' . $subject;
            $notifBody = sprintf(
                'New %s-priority report from %s (%s) — [%s] %s',
                $priority, $branchName, $user['full_name'], $category, $desc
            );
            foreach ($recipients as $rid) {
                notif_create($pdo, $rid, null, 'branch_report', $notifSubject, $notifBody, $report_id);
            }
        } catch (Throwable $e) {
            error_log('report-hq notify failed: ' . $e->getMessage());
        }

        json_out(['ok' => true, 'report_id' => $report_id]);
    } catch (PDOException $e) {
        json_out(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

// -------- Load recent reports --------
$stmt = $pdo->prepare("
    SELECT report_id,
           user_id AS submitted_by,
           SUBSTRING_INDEX(TRIM(BOTH ']' FROM SUBSTRING_INDEX(message, '] ', 1)), '[', -1) AS category,
           short_summery AS subject,
           importance AS priority,
           'submitted' AS status,
           CASE
               WHEN message LIKE '[%] %' THEN SUBSTRING(message, LOCATE('] ', message) + 2)
               ELSE message
           END AS description,
           created_at
    FROM report
    WHERE user_id = :u AND role_id = 4
    ORDER BY created_at DESC LIMIT 15
");
$stmt->execute([':u' => $user['user_id']]);
$reports = $stmt->fetchAll();

// -------- Load notifications --------
$alerts = [];
$has_unread = false;
try {
    $stmt = $pdo->prepare("
        SELECT notif_id, title, body, created_at, is_read,
               CASE
                   WHEN TIMESTAMPDIFF(SECOND, created_at, NOW()) < 60 THEN CONCAT(TIMESTAMPDIFF(SECOND, created_at, NOW()), ' seconds ago')
                   WHEN TIMESTAMPDIFF(MINUTE, created_at, NOW()) < 60 THEN CONCAT(TIMESTAMPDIFF(MINUTE, created_at, NOW()), ' minutes ago')
                   WHEN TIMESTAMPDIFF(HOUR, created_at, NOW()) < 24 THEN CONCAT(TIMESTAMPDIFF(HOUR, created_at, NOW()), ' hours ago')
                   ELSE DATE_FORMAT(created_at, '%Y-%m-%d')
               END AS time_ago
        FROM notifications
        WHERE user_id = :u
        ORDER BY created_at DESC
    ");
    $stmt->execute([':u' => $user['user_id']]);
    $alerts = $stmt->fetchAll();

    // Check for unread notifications
    $has_unread = array_filter($alerts, fn($a) => empty($a['is_read']) || (int)$a['is_read'] === 0);
} catch (Exception $e) {
    error_log('Failed to load notifications: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MAP & PUMP — Report to HQ</title>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="icon" type="image/png" href="/images/Logo.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Noto+Sans+Myanmar:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
  :root{
    --navy-950:#070b14; --navy-900:#0b1220; --navy-800:#101a30; --navy-700:#16213f;
    --blue-700:#1d4ed8; --blue-500:#2f6feb; --sky-400:#38bdf8;
    --amber-400:#fbbf24; --amber-300:#fcd34d; --mint-400:#34d399;
    --red-400:#f87171;
    --slate-50:#f8fafc; --slate-300:#c9d2e3; --slate-400:#8b96ad;
  }
  *{box-sizing:border-box; margin:0; padding:0;}
  body{background:var(--navy-950); color:var(--slate-50); font-family:'Inter','Space Grotesk',sans-serif; overflow-x:hidden;}
  .disp{font-family:'Space Grotesk','Noto Sans Myanmar',sans-serif;}
  a{color:inherit; text-decoration:none;}
  button{font-family:inherit;}
  @media (prefers-reduced-motion: reduce){ *{animation-duration:0.001ms !important; animation-iteration-count:1 !important; transition: none !important;} }

  .app-shell{display:flex; min-height:100vh;}

  /* ---------- sidebar ---------- */
  .sidebar{
    width:252px; flex-shrink:0; background:var(--navy-900); border-right:1px solid rgba(255,255,255,0.06);
    display:flex; flex-direction:column; padding:22px 16px; position:sticky; top:0; height:100vh; z-index:60;
  }
  .nav-logo {
    width: 32px;        
    height: auto;
    object-fit: contain;
  }
  .side-role{font-size:11px; color:var(--slate-400); font-weight:500; margin:2px 0 0 46px;}
  .side-branch{
    margin:20px 8px 0; padding:10px 12px; background:var(--navy-800); border-radius:12px;
    border:1px solid rgba(255,255,255,0.06); font-size:12.5px;
    transition: background 0.3s, border-color 0.3s;
    display:flex; align-items:center; gap:10px;
  }
  .side-branch:hover{ background: var(--navy-700); border-color: rgba(251,191,36,0.2); }
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
  .side-link:hover{ background:rgba(255,255,255,0.045); color:var(--slate-50); transform: translateX(4px); }
  .side-link.active{
    background:linear-gradient(135deg, rgba(251,191,36,0.16), rgba(29,78,216,0.10));
    color:var(--slate-50); box-shadow:inset 0 0 0 1px rgba(251,191,36,0.32);
  }
  .side-link.active:hover{transform: none;}
  .side-link .ic{font-size:16px; width:18px; text-align:center; transition: transform 0.2s ease;}
  .side-link:hover .ic{transform: scale(1.15);}
  .side-logout{
    display:flex; align-items:center; gap:12px; padding:12px; border-radius:10px; font-size:14px;
    color:var(--red-400); font-weight:600; margin-top:14px; border-top:1px solid rgba(255,255,255,0.06); padding-top:18px;
    transition: background 0.25s, color 0.25s, padding-left 0.2s;
  }
  .side-logout:hover{background:rgba(248,113,113,0.08); padding-left: 16px;}

  /* ---------- main ---------- */
  .main{flex:1; min-width:0;}
  .topbar{
    position:sticky; top:0; z-index:50; display:flex; align-items:center; justify-content:space-between;
    padding:16px 32px; background:rgba(7,11,20,0.72); backdrop-filter:blur(14px); border-bottom:1px solid rgba(255,255,255,0.06);
  }
  .topbar h1{font-size:18px; font-weight:700;}
  .topbar .date{font-size:12.5px; color:var(--slate-400); margin-top:2px; font-variant-numeric: tabular-nums;}
  .topbar-right{display:flex; align-items:center; gap:16px; position: relative;}
  .bell{
    width:36px; height:36px; border-radius:50%; background:var(--navy-800); display:flex; align-items:center;
    justify-content:center; border:1px solid rgba(255,255,255,0.08); position:relative; font-size:15px; cursor:pointer;
    transition: transform 0.2s, background-color 0.2s;
  }
  .bell:hover{ background: var(--navy-700); transform: scale(1.05); }
  .bell .ping{position:absolute; top:6px; right:7px; width:7px; height:7px; border-radius:50%; background:var(--amber-400); box-shadow:0 0 0 2px var(--navy-800);}
  
  /* ---------- Notifications Dropdown ---------- */
  .notif-popup {
    position: absolute;
    top: 48px;
    right: 60px;
    width: 320px;
    max-height: 420px;
    overflow-y: auto;
    background: var(--navy-800);
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 14px;
    box-shadow: 0 16px 32px rgba(0, 0, 0, 0.4);
    padding: 14px 12px;
    z-index: 100;
    display: none;
    animation: fadeInDown 0.25s cubic-bezier(0.16, 1, 0.3, 1);
  }
  .notif-popup.show { display: block; }
  .notif-header { display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom:10px; margin-bottom:10px; }
  .notif-header h4 { font-size: 14px; font-weight:700; margin:0; }
  .notif-header button { background: transparent; border: none; color: var(--sky-400); font-size: 11px; cursor: pointer; }
  .notif-item {
    padding: 9px 8px; border-radius: 10px; display: flex; gap: 10px; align-items: flex-start; transition: background-color 0.2s; border: 1px solid transparent;
  }
  .notif-item:hover { background-color: rgba(255,255,255,0.04); }
  .notif-item .icon {
    width: 32px; height: 32px; border-radius: 10px; display:flex; align-items:center; justify-content:center; font-size:12px; flex-shrink:0;
    background: rgba(148,163,184,0.12); color: var(--slate-200);
  }
  .notif-item.unread { border-color: rgba(56,189,248,0.2); }
  .notif-item.warning .icon { background: rgba(251,191,36,0.16); color: var(--amber-300); }
  .notif-item.error .icon { background: rgba(248,113,113,0.16); color: var(--red-400); }
  .notif-item.success .icon { background: rgba(52,211,153,0.14); color: var(--mint-400); }
  .notif-item.info .icon { background: rgba(56,189,248,0.14); color: var(--sky-400); }
  .notif-item .text b { display:block; font-size:12.5px; color:var(--slate-50); margin-bottom:2px; }
  .notif-item .text span { color:var(--slate-400); font-size:11.5px; display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .notif-item .time { font-size:10px; color:var(--slate-400); margin-top:4px; }
  @keyframes fadeInDown { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }

  .avatar-chip{display:flex; align-items:center; gap:10px; font-size:13px; cursor: pointer; padding: 4px 8px; border-radius: 99px; transition: background 0.2s;}
  .avatar-chip:hover{background: rgba(255,255,255,0.05);}
  .avatar-circle{width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,var(--sky-400),var(--blue-700)); display:flex; align-items:center; justify-content:center; font-weight:700; font-size:13px; font-family:'Space Grotesk';}

  .content{ padding:30px 32px 60px; opacity: 0; transform: translateY(10px); animation: layoutFadeIn 0.5s ease forwards; }
  @keyframes layoutFadeIn { to { opacity: 1; transform: translateY(0); } }
  .page-foot{ text-align:center; margin-top:34px; padding-top:18px; border-top:1px solid rgba(255,255,255,0.06); font-size:12px; color:var(--slate-400); }

  .page-head{margin-bottom:22px;}
  .page-head h2{font-size:20px; font-weight:700;}
  .page-head p{font-size:13px; color:var(--slate-400); margin-top:4px;}

  .layout-2col{display:grid; grid-template-columns:1fr 1.3fr; gap:20px; align-items:start;}
  @media (max-width:980px){.layout-2col{grid-template-columns:1fr;}}

  .panel{background:linear-gradient(160deg, var(--navy-800), var(--navy-900)); border:1px solid rgba(255,255,255,0.07); border-radius:18px; padding:22px;}
  .panel h3{font-size:15px; font-weight:700; margin-bottom:16px;}

  #report-list{overflow-y:auto; scrollbar-width:thin; scrollbar-color:rgba(56,189,248,0.35) transparent;}
  #report-list::-webkit-scrollbar{width:8px;}
  #report-list::-webkit-scrollbar-track{background:transparent;}
  #report-list::-webkit-scrollbar-thumb{background:rgba(56,189,248,0.35); border-radius:999px;}
  #report-list::-webkit-scrollbar-thumb:hover{background:rgba(56,189,248,0.55);}

  .form-group{margin-bottom:16px;}
  .form-group label{display:block; font-size:12px; color:var(--slate-400); margin-bottom:6px; font-weight:500;}
  .form-control-custom{
    width:100%; background:var(--navy-950); border:1px solid rgba(255,255,255,0.08);
    border-radius:10px; padding:11px 14px; color:var(--slate-50); font-size:13.5px; outline:none;
    font-family:inherit; transition: border-color 0.2s;
  }
  .form-control-custom:focus{border-color:var(--amber-400);}
  textarea.form-control-custom{resize:vertical; min-height:110px;}

  .priority-row{display:flex; gap:10px;}
  .priority-chip{
    flex:1; text-align:center; padding:9px 6px; border-radius:10px; font-size:12.5px; font-weight:600;
    border:1px solid rgba(255,255,255,0.1); color:var(--slate-300); cursor:pointer; transition: all 0.2s ease;
  }
  .priority-chip:hover{background:rgba(255,255,255,0.05);}
  .priority-chip.active[data-p="low"]{background:rgba(52,211,153,0.14); border-color:rgba(52,211,153,0.4); color:var(--mint-400);}
  .priority-chip.active[data-p="medium"]{background:rgba(251,191,36,0.14); border-color:rgba(251,191,36,0.4); color:var(--amber-300);}
  .priority-chip.active[data-p="high"]{background:rgba(248,113,113,0.14); border-color:rgba(248,113,113,0.4); color:var(--red-400);}

  .btn-report{
    width:100%; background:linear-gradient(135deg, var(--amber-400), var(--blue-700)); color:#1c1300; font-weight:700;
    font-size:14px; padding:13px; border-radius:11px; border:none; cursor:pointer; display:flex; align-items:center;
    justify-content:center; gap:8px; box-shadow:0 12px 26px -10px rgba(251,191,36,0.5); transition: filter 0.2s, transform 0.2s;
  }
  .btn-report:hover{filter:brightness(1.06); transform: translateY(-1px);}

  .report-row{
    display:flex; gap:14px; padding:14px 4px; border-bottom:1px solid rgba(255,255,255,0.05);
    transition: background 0.2s;
  }
  .report-row:last-child{border-bottom:none;}
  .report-row:hover{background:rgba(255,255,255,0.015);}
  .report-icon{
    width:38px; height:38px; border-radius:10px; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:14px;
  }
  .report-icon.low{background:rgba(52,211,153,0.14); color:var(--mint-400);}
  .report-icon.medium{background:rgba(251,191,36,0.14); color:var(--amber-300);}
  .report-icon.high{background:rgba(248,113,113,0.14); color:var(--red-400);}
  .report-body{flex:1; min-width:0;}
  .report-body b{font-size:13.5px; display:block;}
  .report-body p{font-size:12.5px; color:var(--slate-400); margin-top:3px; line-height:1.5;}
  .report-meta{display:flex; align-items:center; gap:10px; margin-top:6px; font-size:11px; color:var(--slate-400);}
  .status-pill{font-size:10.5px; font-weight:700; padding:3px 9px; border-radius:999px;}
  .status-pill.pending{background:rgba(251,191,36,0.14); color:var(--amber-300);}
  .status-pill.reviewed{background:rgba(56,189,248,0.14); color:var(--sky-400);}
  .status-pill.resolved{background:rgba(52,211,153,0.14); color:var(--mint-400);}

  body.branch-manager-theme {
    --navy-950:#070b14; --navy-900:#0b1220; --navy-800:#101a30; --navy-700:#16213f; --navy-600:#1a2438;
    --blue-700:#1d4ed8; --blue-500:#2f6feb; --sky-400:#38bdf8;
    --amber-400:#fbbf24; --amber-300:#fcd34d; --mint-400:#34d399; --red-400:#f87171;
    --slate-50:#f8fafc; --slate-300:#c9d2e3; --slate-400:#8b96ad;
    background: var(--navy-950); color: var(--slate-50);
  }
  /* ================= Light mode (refined) ================= */
  body.branch-manager-theme.light-mode {
    --navy-950:#eef2f8; --navy-900:#ffffff; --navy-800:#f6f8fc; --navy-700:#eaf0f9; --navy-600:#dfe8f5;
    --blue-700:#2563eb; --blue-500:#3b82f6; --sky-400:#2a6fdb;
    --amber-400:#c27200; --amber-300:#8f5a00; --mint-400:#0f7a5b; --red-400:#c53030;
    --slate-50:#0f1b30; --slate-200:#475569; --slate-300:#364763; --slate-400:#5b6a82;
    --lm-line:rgba(15,27,48,0.11); --lm-line-soft:rgba(15,27,48,0.07); --lm-hover:rgba(42,111,219,0.07);
    --lm-shadow:0 1px 2px rgba(15,27,48,0.05), 0 10px 24px -14px rgba(15,27,48,0.18);
    background: var(--navy-950); color: var(--slate-50);
  }
  body.branch-manager-theme.light-mode ::selection { background: rgba(42,111,219,0.22); }

  /* sidebar */
  body.branch-manager-theme.light-mode .sidebar { background:#ffffff; border-right-color: var(--lm-line); }
  body.branch-manager-theme.light-mode .sidebar .disp { color: var(--slate-50) !important; }
  body.branch-manager-theme.light-mode .side-branch { background: var(--navy-800); border-color: var(--lm-line); color: var(--slate-50); }
  body.branch-manager-theme.light-mode .side-branch:hover { background: var(--navy-700); border-color: rgba(194,114,0,0.35); }
  body.branch-manager-theme.light-mode .side-branch span { color: var(--slate-400); }
  body.branch-manager-theme.light-mode .side-branch .hq-logo { background:#ffffff; border-color: var(--lm-line); }
  body.branch-manager-theme.light-mode .side-link { color: var(--slate-300); }
  body.branch-manager-theme.light-mode .side-link:hover { background: var(--lm-hover); color: var(--slate-50); }
  body.branch-manager-theme.light-mode .side-link.active, body.branch-manager-theme.light-mode .side-link.active:hover { background: linear-gradient(135deg, rgba(194,114,0,0.13), rgba(37,99,235,0.07)); color: var(--slate-50); box-shadow: inset 0 0 0 1px rgba(194,114,0,0.38); }
  body.branch-manager-theme.light-mode .side-logout { border-top-color: var(--lm-line); }
  body.branch-manager-theme.light-mode .side-logout:hover { background: rgba(197,48,48,0.08); }

  /* topbar */
  body.branch-manager-theme.light-mode .topbar { background: rgba(255,255,255,0.86); border-bottom-color: var(--lm-line); color: var(--slate-50); }
  body.branch-manager-theme.light-mode .topbar .date { color: var(--slate-400); }
  body.branch-manager-theme.light-mode .bell { background:#ffffff; border-color: var(--lm-line); color: var(--slate-300); }
  body.branch-manager-theme.light-mode .bell:hover { background: var(--navy-700); color: var(--slate-50); }
  body.branch-manager-theme.light-mode .bell .ping { box-shadow: 0 0 0 2px #ffffff; }
  body.branch-manager-theme.light-mode .avatar-chip { background: transparent; border-color: transparent; color: var(--slate-50); }
  body.branch-manager-theme.light-mode .avatar-chip:hover { background: var(--lm-hover); }
  body.branch-manager-theme.light-mode .avatar-circle, body.branch-manager-theme.light-mode .mini-avatar { background: linear-gradient(135deg,#3b82f6,#1d4ed8); color:#ffffff; }
  body.branch-manager-theme.light-mode .avatar-circle a { color:#ffffff; }

  /* notifications */
  body.branch-manager-theme.light-mode .notif-popup { background:#ffffff; border-color: var(--lm-line); box-shadow: 0 20px 44px -16px rgba(15,27,48,0.28); color: var(--slate-50); }
  body.branch-manager-theme.light-mode .notif-header { border-bottom-color: var(--lm-line); }
  body.branch-manager-theme.light-mode .notif-item:hover { background: var(--navy-800); }
  body.branch-manager-theme.light-mode .notif-item.unread { border-color: rgba(42,111,219,0.28); background: rgba(42,111,219,0.04); }
  body.branch-manager-theme.light-mode .notif-item .icon { color: var(--slate-200); }
  body.branch-manager-theme.light-mode .notif-item.warning .icon { background: rgba(194,114,0,0.14); color: var(--amber-300); }
  body.branch-manager-theme.light-mode .notif-item.error .icon { background: rgba(197,48,48,0.12); color: var(--red-400); }
  body.branch-manager-theme.light-mode .notif-item.success .icon { background: rgba(15,122,91,0.12); color: var(--mint-400); }
  body.branch-manager-theme.light-mode .notif-item.info .icon { background: rgba(42,111,219,0.12); color: var(--sky-400); }
  body.branch-manager-theme.light-mode .notif-item .text b, body.branch-manager-theme.light-mode .notif-item-body b { color: var(--slate-50); }
  body.branch-manager-theme.light-mode .notif-item .text span, body.branch-manager-theme.light-mode .notif-item-body span { color: var(--slate-400); }
  body.branch-manager-theme.light-mode .notif-item .time, body.branch-manager-theme.light-mode .notif-time, body.branch-manager-theme.light-mode .notif-empty { color: var(--slate-400); }

  /* shell */
  body.branch-manager-theme.light-mode .page-foot { border-top-color: var(--lm-line); color: var(--slate-400); }
  body.branch-manager-theme.light-mode .page-head p { color: var(--slate-400); }
  body.branch-manager-theme.light-mode .panel { background:#ffffff; border-color: var(--lm-line); box-shadow: var(--lm-shadow); color: var(--slate-50); }
  body.branch-manager-theme.light-mode .staff-theme-control { color: var(--slate-300); }
  body.branch-manager-theme.light-mode .staff-theme-control:hover { color: var(--slate-50); }

  /* report form */
  body.branch-manager-theme.light-mode .form-group label { color: var(--slate-300); }
  body.branch-manager-theme.light-mode .form-control-custom { background:#ffffff; border-color: rgba(15,27,48,0.18); color: var(--slate-50); }
  body.branch-manager-theme.light-mode .form-control-custom::placeholder { color: var(--slate-400); opacity: 0.75; }
  body.branch-manager-theme.light-mode .form-control-custom:focus { border-color: var(--amber-400); box-shadow: 0 0 0 3px rgba(194,114,0,0.14); }
  body.branch-manager-theme.light-mode .priority-chip { background:#ffffff; border-color: rgba(15,27,48,0.16); color: var(--slate-300); }
  body.branch-manager-theme.light-mode .priority-chip:hover { background: var(--navy-700); }
  body.branch-manager-theme.light-mode .priority-chip.active[data-p="low"] { background: rgba(15,122,91,0.10); border-color: rgba(15,122,91,0.5); color: var(--mint-400); }
  body.branch-manager-theme.light-mode .priority_chip.active[data-p="medium"] { background: rgba(194,114,0,0.10); border-color: rgba(194,114,0,0.5); color: var(--amber-300); }
  body.branch-manager-theme.light-mode .priority_chip.active[data-p="high"] { background: rgba(197,48,48,0.09); border-color: rgba(197,48,48,0.5); color: var(--red-400); }
  body.branch-manager-theme.light-mode .btn-report { background: linear-gradient(135deg,#3b82f6,#1d4ed8); color:#ffffff; box-shadow: 0 12px 24px -12px rgba(37,99,235,0.6); }
  body.branch-manager-theme.light-mode .report-row { background: transparent; border-bottom-color: var(--lm-line-soft); }
  body.branch-manager-theme.light-mode .report-row:hover { background: var(--navy-800); }
  body.branch-manager-theme.light-mode .report-icon.low { background: rgba(15,122,91,0.12); color: var(--mint-400); }
  body.branch-manager-theme.light-mode .report-icon.medium { background: rgba(194,114,0,0.13); color: var(--amber-300); }
  body.branch-manager-theme.light-mode .report-icon.high { background: rgba(197,48,48,0.11); color: var(--red-400); }
  body.branch-manager-theme.light-mode .report-body p { color: var(--slate-300); }
  body.branch-manager-theme.light-mode .report-meta { color: var(--slate-400); }
  body.branch-manager-theme.light-mode .status-pill.pending { background: rgba(194,114,0,0.13); color: var(--amber-300); }
  body.branch-manager-theme.light-mode .status-pill.reviewed { background: rgba(42,111,219,0.12); color: var(--sky-400); }
  body.branch-manager-theme.light-mode .status-pill.resolved { background: rgba(15,122,91,0.12); color: var(--mint-400); }
  .staff-theme-control {
    position: relative; display: grid; width: 28px; height: 32px; place-items: center; margin: 0; color: var(--slate-300); cursor: pointer;
  }
  .staff-theme-control input { position: absolute; width: 1px; height: 1px; opacity: 0; }
  .staff-theme-control span { font-size: 1.05rem; }
  .staff-theme-control:focus-within span { outline: 2px solid var(--sky-400); outline-offset: 4px; border-radius: 3px; }
</style>
</head>
<body class="branch-manager-theme">

<div class="app-shell">
  <aside class="sidebar">
    <a href="dashboard.php" class="text-decoration-none d-flex align-items-center gap-2 text-white fw-bold padding" style="padding: 6px 8px 0;">
      <img src="/images/Logo.png" alt="Logo" class="nav-logo">
          <span class="disp" style="font-size: 17px; letter-spacing: 0.5px; color: #ffffff; font-weight: 700;">Map & Pump</span>
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
      <a href="dashboard.php" class="side-link"><span class="ic"><i class="fa-solid fa-house"></i></span> Dashboard</a>
      <a href="manage-staff.php" class="side-link"><span class="ic"><i class="fa-solid fa-users"></i></span> Manage Staff</a>
      <a href="manage-oil.php" class="side-link"><span class="ic"><i class="fa-solid fa-gas-pump"></i></span> Manage Oil Stock</a>
      <a href="booking-slot.php" class="side-link"><span class="ic"><i class="fa-solid fa-ticket"></i></span> Monitor Booking Slot</a>
      <a href="view-customers.php" class="side-link"><span class="ic"><i class="fa-solid fa-address-book"></i></span> View Customers</a>
      <a href="report-hq.php" class="side-link active"><span class="ic"><i class="fa-solid fa-paper-plane"></i></span> Report to HQ</a>
      <a href="branchprofile.php" class="side-link"><span class="ic"><i class="fa-solid fa-building"></i></span> Branch Profile</a>
      <a href="edit-profile.php" class="side-link"><span class="ic"><i class="fa-solid fa-gear"></i></span> Edit Profile</a>
    </nav>

    <a href="../Login/logout.php" class="side-logout"><span class="ic"><i class="fa-solid fa-right-from-bracket"></i></span> Log Out</a>
  </aside>

  <div class="main">
    <div class="topbar">
      <div>
        <h1>Report to HQ</h1>
        <div class="date" id="live-clock">Wednesday · July 9, 2026</div>
      </div>
      <div class="topbar-right">
        <div class="bell" id="notif-bell"><i class="fa-regular fa-bell"></i><?php if ($has_unread): ?><span class="ping" id="notif-ping"></span><?php endif; ?></div>

        <div class="notif-popup" id="notif-popup">
          <div class="notif-header">
            <h4>Notifications</h4>
          </div>
          <div class="notif-list" id="notif-list">
            <?php if (!$alerts): ?>
              <div class="notif-empty" style="text-align:center; padding:12px 0; color:var(--slate-400); font-size:12px;">No new notifications</div>
            <?php else:
              foreach ($alerts as $a):
                $meta = notif_ui_meta($a['type'], $a['title']);
                $tone = $meta['tone'];
                $icon = $meta['icon'];
            ?>
            <div class="notif-item <?= h($tone) ?>" data-notif-id="<?= (int)$a['notif_id'] ?>" data-link="<?= h($a['link'] ?? 'dashboard.php') ?>">
              <div class="icon"><i class="fa-solid <?= h($icon) ?>"></i></div>
              <div class="text">
                <b><?= h($a['title']) ?></b>
                <span><?= h($a['body']) ?></span>
                <div class="time"><?= h($a['time_ago']) ?></div>
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
          <?php if ($managerAvatarUrl !== ''): ?>
          <img src="<?= htmlspecialchars($managerAvatarUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Profile Picture" style="width:36px;height:36px;border-radius:50%;object-fit:cover;border:1px solid rgba(255,255,255,0.12)">
          <?php else: ?>
          <div class="avatar-circle"><a href="edit-profile.php"><?= htmlspecialchars($managerInitial, ENT_QUOTES, 'UTF-8') ?></a></div>
          <?php endif; ?>
          <div><b><a href="edit-profile.php"><?= htmlspecialchars($managerDisplayName, ENT_QUOTES, 'UTF-8') ?></a></b></div>
        </div>
      </div>
    </div>

    <div class="content">
      <div class="page-head">
        <h2>Report to Head Office</h2>
        <p>Send stock, equipment, staff, or other incident reports directly to HQ for review.</p>
      </div>

      <div class="layout-2col">
        <div class="panel">
          <h3>New Report</h3>
          <form id="report-form" method="POST" action="report-hq.php">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="priority" id="priority-input" value="low">
            <div class="form-group">
              <label for="report-category">Category</label>
              <select id="report-category" name="category" class="form-control-custom" required>
                <option value="">Select a category...</option>
                <option value="Stock Shortage">Stock Shortage</option>
                <option value="Equipment Issue">Equipment / Pump Issue</option>
                <option value="Staff Incident">Staff Incident</option>
                <option value="Customer Complaint">Customer Complaint</option>
                <option value="Other">Other</option>
              </select>
            </div>
            <div class="form-group">
              <label for="report-subject">Subject</label>
              <input type="text" id="report-subject" name="subject" class="form-control-custom" placeholder="e.g. Diesel tank leak at Pump #4" required>
            </div>
            <div class="form-group">
              <label>Priority</label>
              <div class="priority-row" id="priority-row">
                <div class="priority-chip active" data-p="low">Low</div>
                <div class="priority-chip" data-p="medium">Medium</div>
                <div class="priority-chip" data-p="high">High</div>
              </div>
            </div>
            <div class="form-group">
              <label for="report-desc">Description</label>
              <textarea id="report-desc" name="description" class="form-control-custom" placeholder="Describe the issue in detail..." required></textarea>
            </div>
            <button type="submit" class="btn-report"><i class="fa-solid fa-paper-plane"></i> Send Report to HQ</button>
          </form>
        </div>

        <div class="panel">
          <h3>Recent Reports</h3>
          <div id="report-list">
            <?php
            $cat_icon = [
              'Stock Shortage'     => 'fa-gas-pump',
              'Equipment Issue'    => 'fa-triangle-exclamation',
              'Staff Incident'     => 'fa-user',
              'Customer Complaint' => 'fa-comment',
              'Other'              => 'fa-flag',
            ];
            if (!$reports): ?>
              <div style="text-align:center; padding:22px; color:var(--slate-400); font-size:12.5px;">No reports filed yet.</div>
            <?php else: foreach ($reports as $r):
              $icon = $cat_icon[$r['category']] ?? 'fa-flag';
            ?>
              <div class="report-row">
                <div class="report-icon <?= h($r['priority']) ?>"><i class="fa-solid <?= h($icon) ?>"></i></div>
                <div class="report-body">
                  <b><?= h($r['subject']) ?></b>
                  <p><?= h($r['category']) ?> — <?= h(mb_strimwidth($r['description'], 0, 120, '…')) ?></p>
                  <div class="report-meta">
                    <span class="status-pill <?= h($r['status']) ?>"><?= h(ucfirst($r['status'])) ?></span>
                    <span><?= h(fmt_date($r['created_at'])) ?></span>
                  </div>
                </div>
              </div>
            <?php endforeach; endif; ?>
          </div>
        </div>
      </div>

      <div class="page-foot">© 2026 MAP & PUMP | All Right Reserved</div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  document.addEventListener('DOMContentLoaded', () => {
    function updateClock() {
      const now = new Date();
      const options = { weekday: 'long', month: 'short', day: 'numeric', year: 'numeric' };
      const dateString = now.toLocaleDateString('en-US', options);
      const timeString = now.toLocaleTimeString('en-US', { hour12: true, hour: '2-digit', minute: '2-digit', second: '2-digit' });
      document.getElementById('live-clock').textContent = `${dateString} · ${timeString}`;
    }
    setInterval(updateClock, 1000);
    updateClock();

    // Priority chip selector — sync into hidden input for server POST
    let selectedPriority = 'low';
    const priorityHidden = document.getElementById('priority-input');
    document.querySelectorAll('.priority-chip').forEach(chip => {
      chip.addEventListener('click', () => {
        document.querySelectorAll('.priority-chip').forEach(c => c.classList.remove('active'));
        chip.classList.add('active');
        selectedPriority = chip.dataset.p;
        priorityHidden.value = selectedPriority;
      });
    });

    // Submit new report → POST to server, then prepend UI
    const form = document.getElementById('report-form');
    const reportList = document.getElementById('report-list');
    // Keep overflowing recent reports within the neighbouring form card's height.
    const newReportPanel = form.closest('.panel');
    const recentReportsPanel = reportList.closest('.panel');
    const recentReportsHeading = recentReportsPanel.querySelector('h3');
    function fitRecentReports() {
      if (window.matchMedia('(max-width:980px)').matches) {
        reportList.style.maxHeight = '';
        return;
      }
      const panelStyle = getComputedStyle(recentReportsPanel);
      const listTop = reportList.getBoundingClientRect().top - recentReportsPanel.getBoundingClientRect().top;
      const availableHeight = newReportPanel.getBoundingClientRect().height - listTop
        - parseFloat(panelStyle.paddingBottom) - parseFloat(panelStyle.borderBottomWidth);
      reportList.style.maxHeight = `${Math.max(0, availableHeight)}px`;
    }
    fitRecentReports();
    const reportHeightObserver = new ResizeObserver(fitRecentReports);
    reportHeightObserver.observe(newReportPanel);
    reportHeightObserver.observe(recentReportsHeading);
    window.addEventListener('resize', fitRecentReports);
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(fitRecentReports);

    const iconMap = {
      'Stock Shortage': 'fa-gas-pump',
      'Equipment Issue': 'fa-triangle-exclamation',
      'Staff Incident': 'fa-user',
      'Customer Complaint': 'fa-comment',
      'Other': 'fa-flag'
    };

    const bellBtn = document.getElementById('notif-bell');
    const notifPopup = document.getElementById('notif-popup');
    const notifPing = document.getElementById('notif-ping');
    const notifList = document.getElementById('notif-list');

    function postDismiss(payload) {
      return fetch('api_dismiss_notification.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      }).then((r) => r.json()).catch(() => ({ ok: false }));
    }

    if (bellBtn && notifPopup) {
      bellBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const ping = bellBtn.querySelector('.ping');
        if (ping) {
          ping.style.transform = 'scale(0)';
          setTimeout(() => ping.remove(), 180);
        }
        notifPopup.classList.toggle('show');
      });

      document.addEventListener('click', (e) => {
        const clickedBell = bellBtn.contains(e.target);
        const clickedPopup = notifPopup.contains(e.target);
        if (!clickedBell && !clickedPopup) {
          notifPopup.classList.remove('show');
        }
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

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(form);
      try {
        const res = await fetch('report-hq.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.ok) { alert('Send failed: ' + (data.error || 'unknown')); return; }

        const category = document.getElementById('report-category').value;
        const subject  = document.getElementById('report-subject').value;
        const desc     = document.getElementById('report-desc').value;
        const icon     = iconMap[category] || 'fa-flag';
        const today    = new Date().toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });

        const row = document.createElement('div');
        row.className = 'report-row';
        row.style.opacity = '0';
        row.innerHTML = `
          <div class="report-icon ${selectedPriority}"><i class="fa-solid ${icon}"></i></div>
          <div class="report-body">
            <b>${subject}</b>
            <p>${category} — ${desc}</p>
            <div class="report-meta"><span class="status-pill pending">Pending</span><span>${today}</span></div>
          </div>`;
        reportList.prepend(row);
        requestAnimationFrame(() => { row.style.transition = 'opacity 0.4s'; row.style.opacity = '1'; });

        form.reset();
        document.querySelectorAll('.priority-chip').forEach(c => c.classList.remove('active'));
        document.querySelector('.priority-chip[data-p="low"]').classList.add('active');
        selectedPriority = 'low';
        priorityHidden.value = 'low';
      } catch (err) { alert('Network error: ' + err.message); }
    });
  });
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
