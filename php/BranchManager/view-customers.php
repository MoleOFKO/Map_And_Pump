<?php
/**
 * view-customers.php  —  Read-only customer directory + feedback for this branch.
 * Optional GET params: ?q=  (server-side search too, in addition to client-side filter)
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../profile_avatar.php';
require_branch_manager();

$ctx      = current_context($pdo);
$user     = $ctx['user'];
$branch   = $ctx['branch'];
$initials = initials($user['full_name']);
$bid      = (int)$branch['branch_id'];

$managerDisplayName = map_pump_user_display_name($user['full_name'] ?? ($_SESSION['user_name'] ?? ''), '');
$managerInitial     = map_pump_profile_initial($managerDisplayName, $initials ?: 'M');
$managerAvatarUrl   = map_pump_user_avatar_url($user['avatar_url'] ?? '', '');

$q = trim($_GET['q'] ?? '');

// -------- Load customers (all who booked at this branch) --------
$customerWhere = "EXISTS (
    SELECT 1
    FROM booking bk2
    INNER JOIN station_business_scope sbs ON sbs.station_id = bk2.station_id
    WHERE bk2.user_id = u.user_id
      AND bk2.station_id = :branch_id
      AND sbs.branch_manager_user_id = :manager_id
)";
$params = [':manager_id' => $user['user_id'], ':branch_id' => $bid];
if ($q !== '') {
    $customerWhere .= " AND (u.user_name LIKE :q OR u.email LIKE :q OR u.user_public_id LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}
$stmt = $pdo->prepare("
    SELECT
        u.user_id AS customer_id,
        COALESCE(u.user_public_id, CAST(u.user_id AS CHAR)) AS customer_code,
        u.user_name AS full_name,
        COALESCE(ltp.tier_code, '') AS tier_code,
        COUNT(DISTINCT CASE
            WHEN LOWER(COALESCE(bk.status, '')) IN ('completed', 'filled')
            THEN bk.booking_id
        END) AS total_visits,
        MAX(CASE
            WHEN LOWER(COALESCE(bk.status, '')) IN ('completed', 'filled')
            THEN bk.time_of_filled
        END) AS last_visit_at
    FROM users u
    LEFT JOIN booking bk ON bk.user_id = u.user_id AND bk.station_id = :branch_id
    LEFT JOIN loyalty_user_tier_progress ltp ON ltp.user_id = u.user_id
    WHERE {$customerWhere}
    GROUP BY u.user_id, u.user_public_id, u.user_name, ltp.tier_code
    ORDER BY last_visit_at DESC, customer_id DESC
    LIMIT 200
");
$stmt->execute($params);
$customers = $stmt->fetchAll();

// -------- Aggregate stats --------
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT bk.user_id)
    FROM booking bk
    INNER JOIN station_business_scope sbs ON sbs.station_id = bk.station_id
    WHERE bk.station_id = :branch_id
      AND sbs.branch_manager_user_id = :manager_id
");
$stmt->execute([':manager_id' => $user['user_id'], ':branch_id' => $bid]);
$total_customers = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT ROUND(AVG(f.rating), 1)
    FROM feedback f
    INNER JOIN station_business_scope sbs ON sbs.station_id = f.station_id
    WHERE f.station_id = :branch_id
      AND sbs.branch_manager_user_id = :manager_id
      AND f.rating IS NOT NULL
");
$stmt->execute([':manager_id' => $user['user_id'], ':branch_id' => $bid]);
$avg_rating = round((float)$stmt->fetchColumn(), 1);

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM feedback f
    INNER JOIN station_business_scope sbs ON sbs.station_id = f.station_id
    WHERE f.station_id = :branch_id
      AND sbs.branch_manager_user_id = :manager_id
");
$stmt->execute([':manager_id' => $user['user_id'], ':branch_id' => $bid]);
$feedback_count = (int)$stmt->fetchColumn();

$alerts       = notif_recent($pdo, (int)$user['user_id'], 5);
$unread_count = notif_unread_count($pdo, (int)$user['user_id']);
$has_unread   = $unread_count > 0;

// -------- Feedback (latest 12) --------
$stmt = $pdo->prepare("
    SELECT f.feedback_id, f.user_id, f.station_id, f.rating, f.feedback AS body, f.created_at, u.user_name AS full_name
    FROM feedback f
    LEFT JOIN users u ON u.user_id = f.user_id
    INNER JOIN station_business_scope sbs ON sbs.station_id = f.station_id
    WHERE f.station_id = :branch_id
      AND sbs.branch_manager_user_id = :manager_id
    GROUP BY f.feedback_id, f.user_id, f.station_id, f.rating, f.feedback, f.created_at, u.user_name
    ORDER BY f.created_at DESC
");
$stmt->execute([':manager_id' => $user['user_id'], ':branch_id' => $bid]);
$feedbacks = $stmt->fetchAll();

// Fetch conversation log replies for all loaded feedbacks
$feedbackIds = array_column($feedbacks, 'feedback_id');
$repliesByFeedback = [];
if (!empty($feedbackIds)) {
    $placeholders = implode(',', array_fill(0, count($feedbackIds), '?'));
    $replyStmt = $pdo->prepare("
        SELECT fr.reply_id, fr.feedback_id, fr.reply_text, fr.created_at AS reply_date, staff_user.user_name AS staff_name
        FROM feedback_replies fr
        LEFT JOIN users staff_user ON staff_user.user_id = fr.staff_id
        WHERE fr.feedback_id IN ($placeholders)
        ORDER BY fr.created_at ASC
    ");
    $replyStmt->execute($feedbackIds);
    while ($rRow = $replyStmt->fetch()) {
        $repliesByFeedback[$rRow['feedback_id']][] = $rRow;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MAP & PUMP — View Customers</title>

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
  .side-branch:hover{ background: var(--navy-700); border-color: rgba(56,189,248,0.2); }
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
    background:linear-gradient(135deg, rgba(56,189,248,0.16), rgba(29,78,216,0.12));
    color:var(--slate-50); box-shadow:inset 0 0 0 1px rgba(56,189,248,0.32);
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
  .notif-header { display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom:10px; margin-bottom:10px; }
  .notif-header h4 { font-size: 14px; font-weight:700; margin:0; }
  .notif-header button { background: transparent; border: none; color: var(--sky-400); font-size: 11px; cursor: pointer; }
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
  .notif-item {
    padding: 10px 8px; border-radius: 10px; display: flex; gap: 10px; align-items: flex-start; transition: background-color 0.2s; border: 1px solid transparent;
  }
  .notif-item:hover { background-color: rgba(255,255,255,0.04); }
  .notif-item .icon {
    width: 32px; height: 32px; border-radius: 10px; display:none; align-items:center; justify-content:center; font-size:12px; flex-shrink:0;
    background: rgba(148,163,184,0.12); color: var(--slate-200);
  }
  .notif-item.unread { border-color: rgba(56,189,248,0.2); }
  .notif-item .icon { background: rgba(148,163,184,0.12); color: var(--slate-200); }
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
  @keyframes fadeInDown { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }

  .avatar-chip{display:flex; align-items:center; gap:10px; font-size:13px; cursor: pointer; padding: 4px 8px; border-radius: 99px; transition: background 0.2s;}
  .avatar-chip:hover{background: rgba(255,255,255,0.05);}
  .avatar-circle{width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,var(--sky-400),var(--blue-700)); display:flex; align-items:center; justify-content:center; font-weight:700; font-size:13px; font-family:'Space Grotesk';}

  .content{ padding:30px 32px 60px; opacity: 0; transform: translateY(10px); animation: layoutFadeIn 0.5s ease forwards; }
  @keyframes layoutFadeIn { to { opacity: 1; transform: translateY(0); } }

  .page-foot{ text-align:center; margin-top:34px; padding-top:18px; border-top:1px solid rgba(255,255,255,0.06); font-size:12px; color:var(--slate-400); }

  .page-head{display:flex; align-items:center; justify-content:space-between; margin-bottom:22px; flex-wrap:wrap; gap:14px;}
  .page-head h2{font-size:20px; font-weight:700;}
  .page-head p{font-size:13px; color:var(--slate-400); margin-top:4px;}

  .stat-strip{display:grid; grid-template-columns:repeat(3,1fr); gap:14px; margin-bottom:26px;}
  @media (max-width:1000px){.stat-strip{grid-template-columns:repeat(2,1fr);}}
  .stat{
    background:linear-gradient(160deg, var(--navy-800), var(--navy-900)); border:1px solid rgba(255,255,255,0.07);
    border-radius:14px; padding:16px 18px; transition: transform 0.3s cubic-bezier(0.2, 0.8, 0.2, 1), border-color 0.3s;
  }
  .stat:hover{ transform: translateY(-3px); border-color: rgba(56,189,248,0.25); }
  .stat .n{font-family:'Space Grotesk'; font-size:22px; font-weight:700;}
  .stat .l{font-size:11.5px; color:var(--slate-400); margin-top:4px;}

  .tabs{display:flex; gap:8px; margin-bottom:20px; border-bottom:1px solid rgba(255,255,255,0.08); flex-wrap:wrap;}
  .tab-btn{
    background:none; border:none; color:var(--slate-400); font-family:inherit; font-size:13.5px; font-weight:600;
    padding:11px 4px; cursor:pointer; position:relative; margin-right:22px; transition: color 0.25s ease;
  }
  .tab-btn:hover{color: var(--slate-50);}
  .tab-btn.active{color:var(--slate-50);}
  .tab-btn.active::after{
    content:""; position:absolute; left:0; right:0; bottom:-1px; height:2px; background:var(--sky-400); border-radius:2px;
    animation: slideLine 0.25s ease-out;
  }
  @keyframes slideLine { from { transform: scaleX(0); } to { transform: scaleX(1); } }
  .tab-panel{display:none;}
  .tab-panel.active{ display:block; animation: panelFadeIn 0.3s ease forwards; }
  @keyframes panelFadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }

  .toolbar{display:flex; gap:12px; margin-bottom:18px; flex-wrap:wrap; align-items:center;}
  .search{
    flex:1; min-width:220px; background:var(--navy-800); border:1px solid rgba(255,255,255,0.08); border-radius:11px;
    padding:10px 14px; display:flex; align-items:center; gap:9px; font-size:13.5px; color:var(--slate-400);
    transition: border-color 0.25s, box-shadow 0.25s;
  }
  .search:focus-within{ border-color: rgba(56,189,248,0.4); box-shadow: 0 0 0 1px rgba(56,189,248,0.4); }
  .search input{background:none; border:none; outline:none; color:var(--slate-50); font-family:inherit; font-size:13.5px; width:100%;}

  .panel{background:linear-gradient(160deg, var(--navy-800), var(--navy-900)); border:1px solid rgba(255,255,255,0.07); border-radius:18px; overflow:hidden;}
  table{width:100%; border-collapse:collapse;}
  thead th{text-align:left; font-size:11.5px; color:var(--slate-400); text-transform:uppercase; letter-spacing:0.4px; padding:14px 18px; border-bottom:1px solid rgba(255,255,255,0.07);}
  tbody td{padding:14px 18px; font-size:13.5px; border-bottom:1px solid rgba(255,255,255,0.05);}
  tbody tr:last-child td{border-bottom:none;}
  tbody tr:hover{background:rgba(255,255,255,0.02);}

  /* scrollable customer table: max-height is set by JS to fit exactly 5 rows */
  .table-scroll{overflow-y:auto; overflow-x:auto;}
  .table-scroll thead th{position:sticky; top:0; z-index:2; background:var(--navy-800);}
  .table-scroll{scrollbar-width:thin; scrollbar-color:rgba(56,189,248,0.35) transparent;}
  .table-scroll::-webkit-scrollbar{width:8px; height:8px;}
  .table-scroll::-webkit-scrollbar-track{background:transparent;}
  .table-scroll::-webkit-scrollbar-thumb{background:rgba(56,189,248,0.3); border-radius:8px;}
  .table-scroll::-webkit-scrollbar-thumb:hover{background:rgba(56,189,248,0.5);}

  .cust-name{display:flex; align-items:center; gap:11px;}
  .mini-avatar{
    width:34px; height:34px; border-radius:50%; background:linear-gradient(135deg,var(--sky-400),var(--blue-700));
    display:flex; align-items:center; justify-content:center; font-weight:700; font-size:12px; font-family:'Space Grotesk'; flex-shrink:0;
  }
  .cust-name b{display:block; font-size:13.5px;}
  .cust-name span{font-size:11.5px; color:var(--slate-400);}
  .tier-pill{font-size:11px; padding:4px 10px; border-radius:999px; font-weight:600;}
  .tier-pill.bronze{background:rgba(217,119,6,0.13); color:#f5ac35; border:1px solid rgba(217,119,6,0.35);}
  .tier-pill.silver{background:rgba(139,150,173,0.13); color:var(--slate-300); border:1px solid rgba(139,150,173,0.3);}
  .tier-pill.gold{background:rgba(251,191,36,0.13); color:var(--amber-300); border:1px solid rgba(251,191,36,0.35);}
  .tier-pill.platinum{background:rgba(203,213,225,0.12); color:#e2e8f0; border:1px solid rgba(203,213,225,0.35);}
  .tier-pill.diamond{background:rgba(56,189,248,0.12); color:#7dd3fc; border:1px solid rgba(56,189,248,0.35);}
  .tier-pill.regular{background:rgba(255,255,255,0.06); color:var(--slate-400);}

  .feedback-grid{display:grid; grid-template-columns:repeat(2,1fr); gap:16px;}
  @media (max-width:900px){.feedback-grid{grid-template-columns:1fr;}}
  .fb-card{
    background:linear-gradient(160deg, var(--navy-800), var(--navy-900)); border:1px solid rgba(255,255,255,0.07);
    border-radius:16px; padding:18px 20px; transition: transform 0.3s, border-color 0.3s;
  }
  .fb-card:hover{ transform: translateY(-3px); border-color: rgba(56,189,248,0.22); }
  .fb-head{display:flex; align-items:center; justify-content:space-between; margin-bottom:10px;}
  .fb-stars{color:var(--amber-400); font-size:13px; letter-spacing:2px;}
  .fb-date{font-size:11.5px; color:var(--slate-400);}
  .fb-name{font-size:13.5px; font-weight:700; margin-bottom:6px;}
  .fb-text{font-size:13px; color:var(--slate-300); line-height:1.6;}

  /* Customer feedback frontend; existing directory and application shell are unchanged. */
  .tabs .tab-btn i{margin-right:7px;}
  .tab-btn:focus-visible,.rating-filter:focus-visible,.fb-action:focus-visible{outline:2px solid var(--sky-400); outline-offset:4px;}
  .feedback-summary{display:flex; align-items:center; gap:30px; padding:22px 24px; margin-bottom:24px; background:linear-gradient(160deg,var(--navy-800),var(--navy-900)); border:1px solid rgba(255,255,255,0.07); border-radius:16px;}
  .rating-overall{width:120px; flex-shrink:0; text-align:center;}
  .rating-average{font-family:'Space Grotesk',sans-serif; font-size:44px; line-height:1.2; font-weight:700;}
  .rating-overall .fb-stars{display:block; margin:8px 0;}
  .rating-caption{font-size:11.5px; color:var(--slate-400);}
  .rating-distribution{flex:1; min-width:0; display:grid; gap:8px;}
  .rating-row{display:flex; align-items:center; gap:14px; font-size:11.5px; color:var(--slate-300);}
  .rating-row-label{width:38px; flex-shrink:0;}
  .rating-row-label i{color:var(--amber-400);}
  .rating-track{flex:1; height:7px; overflow:hidden; border-radius:999px; background:rgba(139,150,173,0.16);}
  .rating-fill{display:block; height:100%; width:0; background:var(--amber-400); border-radius:inherit;}
  .rating-percent{width:34px; text-align:right; color:var(--slate-400);}
  .feedback-toolbar{display:flex; align-items:center; gap:18px; flex-wrap:wrap; margin-bottom:22px;}
  .rating-filters{display:flex; gap:8px; flex-wrap:wrap;}
  .rating-filter{background:var(--navy-800); border:1px solid rgba(255,255,255,0.07); border-radius:999px; padding:8px 13px; color:var(--slate-300); font-size:11.5px; font-weight:600; cursor:pointer;}
  .rating-filter.active{color:var(--sky-400); background:rgba(56,189,248,0.1); border-color:var(--sky-400);}
  .feedback-search{margin-left:auto; flex:1; max-width:300px; min-width:220px;}
  .feedback-empty{grid-column:1/-1; text-align:center; color:var(--slate-400); padding:22px;}
  .feedback-grid [hidden]{display:none !important;}
  /* Same numbered pagination as manage-oil.php's All Fuel Types grid. */
  .pg-controls {
    display: flex; align-items: center; justify-content: center;
    gap: 6px; margin-top: 18px; flex-wrap: wrap;
  }
  .pg-btn {
    background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.10);
    color: var(--slate-300); border-radius: 8px; padding: 6px 13px;
    font-size: 12.5px; font-weight: 600; cursor: pointer;
    transition: background 0.18s, color 0.18s, border-color 0.18s;
    min-width: 34px; text-align: center;
  }
  .pg-btn:hover:not(:disabled) { background: rgba(255,255,255,0.10); color: var(--slate-50); }
  .pg-btn.active { background: var(--amber-400); border-color: var(--amber-400); color: #1d1306; }
  .pg-btn:disabled { opacity: 0.35; cursor: default; }
  #feedback-pg-controls[hidden] { display: none; }
  #feedback-pg-controls .pg-btn.active,
  #feedback-pg-controls .pg-btn.active:hover:not(:disabled) { background:linear-gradient(135deg, #3b8dff, #1d4ed8); border-color:#2f6feb; color:#fff; box-shadow:0 8px 18px -10px rgba(59,141,255,0.75); }
  .feedback-grid .fb-card{display:flex; flex-direction:column; padding:22px 24px;}
  .fb-profile{display:flex; align-items:center; gap:10px; margin:8px 0 14px; flex-wrap:wrap;}
  .fb-profile .mini-avatar{background:rgba(56,189,248,0.12); color:var(--sky-400);}
  .fb-profile .fb-name{margin:0; font-size:15px; overflow-wrap:anywhere;}
  .fb-profile .tier-pill{flex-shrink:0;}
  .feedback-grid .fb-text{flex:1; font-size:13.5px; white-space:pre-wrap; overflow-wrap:anywhere;}
  .fb-actions{display:flex; align-items:center; justify-content:space-between; gap:12px; border-top:1px solid rgba(255,255,255,0.06); padding-top:16px; margin-top:20px;}
  .fb-action{background:none; border:none; padding:0; color:var(--sky-400); font-size:12.5px; font-weight:600; cursor:pointer; text-align:left;}
  .fb-action i{margin-right:6px;}
  .fb-details{color:var(--slate-400); font-size:13px; font-weight:400; white-space:nowrap; margin-left:auto;}
  .fb-details i{margin:0 0 0 5px;}
  .fb-details:hover{color:var(--slate-50);}
  .feedback-tier.gold{background:rgba(251,191,36,0.13); color:var(--amber-300); border:1px solid rgba(251,191,36,0.35);}
  .feedback-tier.silver{background:rgba(139,150,173,0.13); color:var(--slate-300); border:1px solid rgba(139,150,173,0.3);}
  .feedback-tier.bronze{background:rgba(217,119,6,0.13); color:#f5ac35; border:1px solid rgba(217,119,6,0.35);}
  .feedback-tier.regular{background:rgba(139,150,173,0.08); color:var(--slate-400); border:1px solid rgba(139,150,173,0.18);}
  .feedback-tier.platinum{background:rgba(203,213,225,0.12); color:#e2e8f0; border:1px solid rgba(203,213,225,0.35);}
  .feedback-tier.diamond{background:rgba(56,189,248,0.12); color:#7dd3fc; border:1px solid rgba(56,189,248,0.35);}
  #feedback-modal{--bs-modal-width:800px; --bs-modal-bg:var(--navy-900); --bs-modal-color:var(--slate-50); --bs-modal-border-color:rgba(255,255,255,0.12); --bs-modal-border-radius:18px; --bs-modal-header-border-color:rgba(255,255,255,0.08); --bs-modal-footer-border-color:rgba(255,255,255,0.08);}
  #feedback-modal .modal-content{box-shadow:0 24px 70px rgba(0,0,0,0.45);}
  #feedback-modal .modal-header{padding:22px 24px;}
  #feedback-modal .modal-title{font-size:20px; font-weight:700; overflow-wrap:anywhere;}
  #feedback-modal .btn-close{filter:invert(1) grayscale(100%) brightness(180%); opacity:0.6;}
  #feedback-modal .modal-body{padding:24px;}
  .feedback-modal-meta{display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:14px;}
  .feedback-modal-tier{display:inline-block; margin-bottom:18px;}
  .feedback-modal-text{background:rgba(255,255,255,0.015); border:1px solid rgba(255,255,255,0.06); border-radius:13px; padding:18px; font-size:14px; color:var(--slate-50); line-height:1.7; white-space:pre-wrap; overflow-wrap:anywhere;}
  #feedback-modal .feedback-modal-text{border-left:3px solid #fbbf24;}
  .feedback-reply-section{border-top:1px solid rgba(255,255,255,0.08); margin-top:24px; padding-top:18px;}
  .feedback-reply-label{display:block; font-size:14px; font-weight:700; margin-bottom:10px;}
  .feedback-reply-label i{color:var(--sky-400); margin-right:7px;}
  .feedback-reply-text{background:var(--navy-800); border:1px solid rgba(56,189,248,0.28); border-radius:13px; padding:16px 18px; font-size:14px; color:var(--slate-50); line-height:1.7; white-space:pre-wrap; overflow-wrap:anywhere;}
  .feedback-reply-text.is-empty{color:var(--slate-400); font-style:italic;}
  @media(max-width:650px){
    .feedback-summary{gap:18px; padding:18px 14px;}
    .rating-overall{width:90px;}
    .rating-average{font-size:36px;}
    .rating-row{gap:7px;}
    .feedback-search{max-width:none; min-width:0; width:100%; flex-basis:100%;}
    .feedback-grid .fb-card{padding:18px;}
    .fb-actions{flex-wrap:wrap;}
    #feedback-modal .modal-header,#feedback-modal .modal-body{padding:18px;}
  }
  /* Conversation Log modal */
  #conv-log-modal{--bs-modal-width:680px; --bs-modal-bg:var(--navy-900); --bs-modal-color:var(--slate-50); --bs-modal-border-color:rgba(255,255,255,0.12); --bs-modal-border-radius:18px; --bs-modal-header-border-color:rgba(255,255,255,0.08);}
  #conv-log-modal .modal-content{box-shadow:0 24px 70px rgba(0,0,0,0.55);}
  #conv-log-modal .modal-header{padding:20px 24px;}
  #conv-log-modal .modal-title{font-size:18px; font-weight:700; display:flex; align-items:center; gap:10px;}
  #conv-log-modal .modal-title i{color:var(--sky-400);}
  #conv-log-modal .btn-close{filter:invert(1) grayscale(100%) brightness(180%); opacity:0.6;}
  #conv-log-modal .modal-body{padding:22px 24px;}
  .log-timeline{display:flex; flex-direction:column; gap:14px; max-height:360px; overflow-y:auto; padding-right:6px; scrollbar-width:thin; scrollbar-color:rgba(56,189,248,0.35) transparent;}
  .log-timeline::-webkit-scrollbar{width:6px;}
  .log-timeline::-webkit-scrollbar-track{background:transparent;}
  .log-timeline::-webkit-scrollbar-thumb{background:rgba(56,189,248,0.3); border-radius:8px;}
  .log-timeline::-webkit-scrollbar-thumb:hover{background:rgba(56,189,248,0.5);}
  .log-entry{flex-shrink:0; border-radius:13px; padding:15px 17px; border:1px solid rgba(255,255,255,0.07); background:rgba(255,255,255,0.02);}
  .log-entry.is-customer{border-left:3px solid var(--amber-400);}
  .log-entry.is-staff{background:var(--navy-800); border-color:rgba(56,189,248,0.22); border-left:3px solid var(--sky-400);}
  .log-entry-head{display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:8px; flex-wrap:wrap;}
  .log-author{display:flex; align-items:center; gap:8px; font-size:13.5px; font-weight:700;}
  .log-badge{font-size:10.5px; font-weight:600; padding:2px 8px; border-radius:999px;}
  .log-badge.customer{background:rgba(251,191,36,0.14); color:var(--amber-400);}
  .log-badge.staff{background:rgba(56,189,248,0.14); color:var(--sky-400);}
  .log-badge.latest{background:rgba(52,211,153,0.14); color:var(--mint-400);}
  .log-time{font-size:11.5px; color:var(--slate-400);}
  .log-body{font-size:13.5px; color:var(--slate-300); line-height:1.6; white-space:pre-wrap; overflow-wrap:anywhere;}

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
  body.branch-manager-theme.light-mode .side-branch:hover { background: var(--navy-700); border-color: rgba(42,111,219,0.35); }
  body.branch-manager-theme.light-mode .side-branch span { color: var(--slate-400); }
  body.branch-manager-theme.light-mode .side-branch .hq-logo { background:#ffffff; border-color: var(--lm-line); }
  body.branch-manager-theme.light-mode .side-link { color: var(--slate-300); }
  body.branch-manager-theme.light-mode .side-link:hover { background: var(--lm-hover); color: var(--slate-50); }
  body.branch-manager-theme.light-mode .side-link.active, body.branch-manager-theme.light-mode .side-link.active:hover { background: linear-gradient(135deg, rgba(42,111,219,0.14), rgba(37,99,235,0.06)); color: var(--slate-50); box-shadow: inset 0 0 0 1px rgba(42,111,219,0.38); }
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

  /* stats, tabs, search */
  body.branch-manager-theme.light-mode .stat { background:#ffffff; border-color: var(--lm-line); box-shadow: var(--lm-shadow); }
  body.branch-manager-theme.light-mode .stat:hover { border-color: rgba(42,111,219,0.35); }
  body.branch-manager-theme.light-mode .tabs { border-bottom-color: var(--lm-line); }
  body.branch-manager-theme.light-mode .search { background:#ffffff; border-color: rgba(15,27,48,0.16); color: var(--slate-400); }
  body.branch-manager-theme.light-mode .search:focus-within { border-color: var(--sky-400); box-shadow: 0 0 0 3px rgba(42,111,219,0.14); }
  body.branch-manager-theme.light-mode .search input { color: var(--slate-50); }
  body.branch-manager-theme.light-mode .search input::placeholder { color: var(--slate-400); opacity: 0.8; }

  /* customer table */
  body.branch-manager-theme.light-mode .table-scroll thead th, body.branch-manager-theme.light-mode thead th { background: var(--navy-800); border-bottom-color: var(--lm-line); color: var(--slate-400); }
  body.branch-manager-theme.light-mode tbody td { border-bottom-color: var(--lm-line-soft); }
  body.branch-manager-theme.light-mode tbody tr:hover { background: rgba(42,111,219,0.045); }
  body.branch-manager-theme.light-mode .cust-name span { color: var(--slate-400); }

  /* tier pills */
  body.branch-manager-theme.light-mode .tier-pill.bronze, body.branch-manager-theme.light-mode .feedback-tier.bronze { background: rgba(161,92,7,0.10); color:#8a4f05; border-color: rgba(161,92,7,0.38); }
  body.branch-manager-theme.light-mode .tier-pill.silver, body.branch-manager-theme.light-mode .feedback-tier.silver { background: rgba(71,85,105,0.09); color:#3c4d68; border-color: rgba(71,85,105,0.30); }
  body.branch-manager-theme.light-mode .tier-pill.gold, body.branch-manager-theme.light-mode .feedback-tier.gold { background: rgba(194,114,0,0.11); color: var(--amber-300); border-color: rgba(194,114,0,0.40); }
  body.branch-manager-theme.light-mode .tier-pill.platinum, body.branch-manager-theme.light-mode .feedback-tier.platinum { background: rgba(100,116,139,0.12); color:#475569; border-color: rgba(100,116,139,0.40); }
  body.branch-manager-theme.light-mode .tier-pill.diamond, body.branch-manager-theme.light-mode .feedback-tier.diamond { background: rgba(2,132,199,0.10); color:#0369a1; border-color: rgba(2,132,199,0.38); }
  body.branch-manager-theme.light-mode .tier-pill.regular, body.branch-manager-theme.light-mode .feedback-tier.regular { background: rgba(15,27,48,0.05); color: var(--slate-400); border-color: var(--lm-line); }

  /* feedback */
  body.branch-manager-theme.light-mode .feedback-summary, body.branch-manager-theme.light-mode .fb-card { background:#ffffff; border-color: var(--lm-line); box-shadow: var(--lm-shadow); }
  body.branch-manager-theme.light-mode .fb-card:hover { border-color: rgba(42,111,219,0.35); }
  body.branch-manager-theme.light-mode .fb-profile .mini-avatar { background: rgba(42,111,219,0.10); color: var(--sky-400); }
  body.branch-manager-theme.light-mode .fb-actions { border-top-color: var(--lm-line-soft); }
  body.branch-manager-theme.light-mode .fb-details:hover { color: var(--slate-50); }
  body.branch-manager-theme.light-mode .rating-track { background: rgba(15,27,48,0.08); }
  body.branch-manager-theme.light-mode .rating-filter { background:#ffffff; border-color: rgba(15,27,48,0.16); color: var(--slate-300); }
  body.branch-manager-theme.light-mode .rating-filter:hover { background: var(--navy-700); }
  body.branch-manager-theme.light-mode .rating-filter.active, body.branch-manager-theme.light-mode .rating-filter.active:hover { background: rgba(42,111,219,0.10); border-color: var(--sky-400); color: var(--sky-400); }

  /* pagination */
  body.branch-manager-theme.light-mode .pg-btn { background:#ffffff; border-color: rgba(15,27,48,0.16); color: var(--slate-300); }
  body.branch-manager-theme.light-mode .pg-btn:hover:not(:disabled):not(.active) { background: var(--navy-700); color: var(--slate-50); }
  body.branch-manager-theme.light-mode .pg-btn.active { background: var(--amber-400); border-color: var(--amber-400); color:#ffffff; }

  /* modals */
  body.branch-manager-theme.light-mode #feedback-modal, body.branch-manager-theme.light-mode #conv-log-modal { --bs-modal-border-color: var(--lm-line); --bs-modal-header-border-color: var(--lm-line-soft); --bs-modal-footer-border-color: var(--lm-line-soft); }
  body.branch-manager-theme.light-mode #feedback-modal .modal-content, body.branch-manager-theme.light-mode #conv-log-modal .modal-content { box-shadow: 0 28px 70px -20px rgba(15,27,48,0.35); }
  body.branch-manager-theme.light-mode #feedback-modal .btn-close, body.branch-manager-theme.light-mode #conv-log-modal .btn-close { filter: none; opacity: 0.55; }
  body.branch-manager-theme.light-mode #feedback-modal .btn-close:hover, body.branch-manager-theme.light-mode #conv-log-modal .btn-close:hover { opacity: 0.9; }
  body.branch-manager-theme.light-mode .feedback-modal-text { background: var(--navy-800); border-color: var(--lm-line-soft); color: var(--slate-50); }
  body.branch-manager-theme.light-mode .feedback-reply-section { border-top-color: var(--lm-line-soft); }
  body.branch-manager-theme.light-mode .feedback-reply-text { background: rgba(42,111,219,0.05); border-color: rgba(42,111,219,0.28); color: var(--slate-50); }
  body.branch-manager-theme.light-mode .feedback-reply-text.is-empty { color: var(--slate-400); }
  body.branch-manager-theme.light-mode .log-entry { background: var(--navy-800); border-color: var(--lm-line-soft); }
  body.branch-manager-theme.light-mode .log-entry.is-customer { border-left-color: var(--amber-400); }
  body.branch-manager-theme.light-mode .log-entry.is-staff { background: rgba(42,111,219,0.05); border-color: rgba(42,111,219,0.22); border-left-color: var(--sky-400); }
  body.branch-manager-theme.light-mode .log-badge.customer { background: rgba(194,114,0,0.13); color: var(--amber-300); }
  body.branch-manager-theme.light-mode .log-badge.staff { background: rgba(42,111,219,0.12); color: var(--sky-400); }
  body.branch-manager-theme.light-mode .log-badge.latest { background: rgba(15,122,91,0.12); color: var(--mint-400); }
  .staff-theme-control {
    position: relative; display: grid; width: 28px; height: 32px; place-items: center; margin: 0; color: var(--slate-300); cursor: pointer;
  }
  .staff-theme-control input { position: absolute; width: 1px; height: 1px; opacity: 0; }
  .staff-theme-control span { font-size: 1.05rem; }
  .staff-theme-control:focus-within span { outline: 2px solid var(--sky-400); outline-offset: 4px; }
</style>
</head>
<body>

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
      <a href="dashboard.php" class="side-link active"><span class="ic"><i class="fa-solid fa-house"></i></span> Dashboard</a>
      <a href="manage-staff.php" class="side-link"><span class="ic"><i class="fa-solid fa-users"></i></span> Manage Staff</a>
      <a href="manage-oil.php" class="side-link"><span class="ic"><i class="fa-solid fa-gas-pump"></i></span> Manage Fuel Stock</a>
      <a href="booking-slot.php" class="side-link"><span class="ic"><i class="fa-solid fa-ticket"></i></span> Monitor Booking Slot</a>
      <a href="view-customers.php" class="side-link"><span class="ic"><i class="fa-solid fa-address-book"></i></span> View Customers</a>
      <a href="report-hq.php" class="side-link"><span class="ic"><i class="fa-solid fa-paper-plane"></i></span> Report to HQ</a>
      <a href="branchprofile.php" class="side-link"><span class="ic"><i class="fa-solid fa-building"></i></span> Branch Profile</a>
      <a href="edit-profile.php" class="side-link"><span class="ic"><i class="fa-solid fa-gear"></i></span> Edit Profile</a>
    </nav>

    <a href="../Login/logout.php" class="side-logout"><span class="ic"><i class="fa-solid fa-right-from-bracket"></i></span> Log Out</a>
  </aside>

  <div class="main">
    <div class="topbar">
      <div>
        <h1>View Customers</h1>
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
              <div class="notif-empty">No new notifications</div>
            <?php else:
              foreach ($alerts as $a):
                $meta = notif_ui_meta($a['type'], $a['title']);
                $icon = $meta['icon'];
                $tone = $meta['tone'];
            ?>
              <div class="notif-item <?= h($tone) ?>" data-notif-id="<?= (int)$a['notif_id'] ?>" data-link="<?= h($a['link'] ?? 'view-customers.php') ?>">
                <div class="icon"><i class="fa-solid <?= h($icon) ?>"></i></div>
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
        <div>
          <h2>Customers</h2>
          <p>Browse customer records at your branch and read the feedback they've left.</p>
        </div>
      </div>

      <div class="stat-strip">
        <div class="stat"><div class="n"><?= number_format($total_customers) ?></div><div class="l">Total Customers</div></div>
        <div class="stat"><div class="n"><?= number_format($avg_rating, 1) ?> ★</div><div class="l">Avg. Feedback Rating</div></div>
        <div class="stat"><div class="n"><?= number_format($feedback_count) ?></div><div class="l">Customer Feedbacks</div></div>
      </div>

      <div class="tabs" role="tablist" aria-label="Customer views">
        <button class="tab-btn active" id="directory-tab" data-tab="tab-list" type="button" role="tab" aria-controls="tab-list" aria-selected="true"><i class="fa-solid fa-list" aria-hidden="true"></i>Customer Directory</button>
        <button class="tab-btn" id="feedback-tab" data-tab="tab-feedback" type="button" role="tab" aria-controls="tab-feedback" aria-selected="false" tabindex="-1"><i class="fa-solid fa-comments" aria-hidden="true"></i>Feedback &amp; Reviews (<?= number_format($feedback_count) ?>)</button>
      </div>

      <div class="tab-panel active" id="tab-list" role="tabpanel" aria-labelledby="directory-tab">
        <div class="toolbar">
          <div class="search"><i class="fa-solid fa-magnifying-glass"></i><input type="text" id="customer-search" placeholder="Search by name, phone, or vehicle no..." value="<?= h($q) ?>"></div>
        </div>
        <div class="panel">
          <div class="table-scroll" id="customer-scroll">
          <table id="customer-table">
            <thead>
              <tr><th>Customer</th><th>Total Visits</th><th>Last Visit</th><th>Tier</th></tr>
            </thead>
            <tbody>
              <?php if (!$customers): ?>
                <tr><td colspan="4" style="text-align:center; color:var(--slate-400); padding:22px;">No customers found.</td></tr>
              <?php else: foreach ($customers as $c):
                $tierCode = trim((string)($c['tier_code'] ?? ''));
                $tierClass = strtolower($tierCode);
                $tierDisplay = $tierCode !== '' ? ucfirst(strtolower($tierCode)) : '-';
                if (!in_array($tierClass, ['bronze','silver','gold','platinum','diamond'], true)) {
                    $tierClass = 'regular';
                    $tierDisplay = '-';
                }
                $totalVisits = isset($c['total_visits']) && $c['total_visits'] !== null ? (int)$c['total_visits'] : 0;
                $lastVisitText = !empty($c['last_visit_at']) ? h(fmt_date($c['last_visit_at'])) : '—';
              ?>
                <tr>
                  <td>
                    <div class="cust-name">
                      <div class="mini-avatar"><?= h(initials($c['full_name'])) ?></div>
                      <div><b><?= h(privacy_mask_name((string) $c['full_name'])) ?></b><span>ID: <?= h($c['customer_code']) ?></span></div>
                    </div>
                  </td>
                  <td><?= $totalVisits ?></td>
                  <td><?= $lastVisitText ?></td>
                  <td><span class="tier-pill <?= h($tierClass) ?>"><?= h($tierDisplay) ?></span></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
          </div>
        </div>
      </div>

      <div class="tab-panel" id="tab-feedback" role="tabpanel" aria-labelledby="feedback-tab">
        <div class="feedback-summary" aria-label="Feedback rating summary" data-total-feedback="<?= (int)$feedback_count ?>">
          <div class="rating-overall">
            <div class="rating-average" id="feedback-average">0.0</div>
            <span class="fb-stars" id="feedback-summary-stars" aria-label="0 out of 5 stars">☆☆☆☆☆</span>
            <div class="rating-caption" id="feedback-rating-caption">Based on 0 reviews</div>
          </div>
          <div class="rating-distribution">
            <?php for ($level = 5; $level >= 1; $level--): ?>
              <div class="rating-row" data-rating-level="<?= $level ?>">
                <span class="rating-row-label"><?= $level ?> <i class="fa-solid fa-star" aria-hidden="true"></i></span>
                <div class="rating-track" role="progressbar" aria-label="<?= $level ?> star reviews" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span class="rating-fill"></span></div>
                <span class="rating-percent">0%</span>
              </div>
            <?php endfor; ?>
          </div>
        </div>
        <div class="feedback-toolbar">
          <div class="rating-filters" role="group" aria-label="Filter feedback by rating">
            <button type="button" class="rating-filter active" data-rating-filter="all" aria-pressed="true">All Ratings</button>
            <button type="button" class="rating-filter" data-rating-filter="5" aria-pressed="false">5 Stars ★★★★★</button>
            <button type="button" class="rating-filter" data-rating-filter="4" aria-pressed="false">4 Stars ★★★★☆</button>
            <button type="button" class="rating-filter" data-rating-filter="3" aria-pressed="false">3 Stars ★★★☆☆</button>
            <button type="button" class="rating-filter" data-rating-filter="1-2" aria-pressed="false">1–2 Stars ★★☆☆☆</button>
          </div>
          <div class="search feedback-search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><input type="search" id="feedback-search" aria-label="Search feedback text" placeholder="Search feedback text..."></div>
        </div>
        <div class="feedback-grid">
          <?php foreach ($feedbacks as $f):
            $r = max(0, min(5, (int)($f['rating'] ?? 0)));
            $stars = str_repeat('★', $r) . str_repeat('☆', 5 - $r);
            $feedbackTierCode = strtolower(trim((string)($f['tier_code'] ?? '')));
            if ($feedbackTierCode === '') {
                foreach ($customers as $feedbackCustomer) {
                    if ((string)$feedbackCustomer['customer_id'] === (string)($f['user_id'] ?? '')) {
                        $feedbackTierCode = strtolower(trim((string)($feedbackCustomer['tier_code'] ?? '')));
                        break;
                    }
                }
            }
            if (!in_array($feedbackTierCode, ['bronze','silver','gold','platinum','diamond'], true)) {
                $feedbackTierCode = 'regular';
            }
            $feedbackTierLabel = ucfirst($feedbackTierCode) . ' Tier';
            $feedbackName = privacy_mask_name((string) ($f['full_name'] ?: 'Anonymous'));
            
            $fId = (int)$f['feedback_id'];
            $cardReplies = $repliesByFeedback[$fId] ?? [];
            $repliesJson = htmlspecialchars(json_encode($cardReplies), ENT_QUOTES, 'UTF-8');
          ?>
            <div class="fb-card" data-feedback-id="<?= $fId ?>" data-rating="<?= $r ?>" data-replies='<?= $repliesJson ?>' data-created-at="<?= h($f['created_at']) ?>">
              <div class="fb-head">
                <span class="fb-stars" aria-label="<?= $r ?> out of 5 stars"><?= h($stars) ?></span>
                <span class="fb-date"><?= h(fmt_date($f['created_at'])) ?></span>
              </div>
              <div class="fb-profile">
                <div class="mini-avatar" aria-hidden="true"><?= h(initials($feedbackName)) ?></div>
                <div class="fb-name"><?= h($feedbackName) ?></div>
                <span class="tier-pill feedback-tier <?= h($feedbackTierCode) ?>"><?= h($feedbackTierLabel) ?></span>
              </div>
              <div class="fb-text"><?= h($f['body']) ?></div>
              <div class="fb-actions">
                <button type="button" class="fb-action fb-log" data-feedback-open="log"><i class="fa-regular fa-comments" aria-hidden="true"></i> View Conversation Log</button>
                <button type="button" class="fb-action fb-details" data-feedback-open="details">View Details <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
              </div>
            </div>
          <?php endforeach; ?>
          <div class="feedback-empty" id="feedback-empty" role="status"<?= $feedbacks ? ' hidden' : '' ?>>No feedback yet.</div>
        </div>
        <nav class="pg-controls" id="feedback-pg-controls" aria-label="Feedback pages" hidden></nav>
      </div>

      <div class="page-foot">© 2026 MAP & PUMP | All Right Reserved</div>
    </div>
  </div>
</div>

<div class="modal fade" id="feedback-modal" tabindex="-1" aria-labelledby="feedback-modal-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title disp" id="feedback-modal-title">Customer Feedback</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="feedback-modal-meta">
          <span class="fb-stars" id="feedback-modal-stars"></span>
          <span class="fb-date" id="feedback-modal-date"></span>
        </div>
        <span class="tier-pill feedback-tier feedback-modal-tier regular" id="feedback-modal-tier">Regular Tier</span>
        <div class="feedback-modal-text mb-3" id="feedback-modal-text"></div>
        <div class="feedback-reply-section">
          <span class="feedback-reply-label"><i class="fa-solid fa-reply" aria-hidden="true"></i> Staff Reply</span>
          <div class="feedback-reply-text" id="feedback-modal-reply"></div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="conv-log-modal" tabindex="-1" aria-labelledby="conv-log-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title disp" id="conv-log-title"><i class="fa-regular fa-comments" aria-hidden="true"></i> Conversation Log</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="log-timeline" id="feedback-conversation-log">
          <!-- Populated dynamically via JS. A scrollbar appears automatically if count > 3 -->
        </div>
      </div>
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

    // Tab switching (Customer Directory <-> Feedback & Reviews)
    const tabButtons = document.querySelectorAll('.tab-btn');
    tabButtons.forEach(btn => {
      btn.addEventListener('click', () => {
        tabButtons.forEach(b => {
          b.classList.remove('active');
          b.setAttribute('aria-selected', 'false');
          b.tabIndex = -1;
        });
        document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
        btn.classList.add('active');
        btn.setAttribute('aria-selected', 'true');
        btn.tabIndex = 0;
        document.getElementById(btn.dataset.tab).classList.add('active');
        if (btn.dataset.tab === 'tab-list') fitCustomerTable();
      });
      btn.addEventListener('keydown', (event) => {
        const buttons = Array.from(tabButtons);
        let nextIndex = buttons.indexOf(btn);
        if (event.key === 'ArrowRight') nextIndex = (nextIndex + 1) % buttons.length;
        else if (event.key === 'ArrowLeft') nextIndex = (nextIndex - 1 + buttons.length) % buttons.length;
        else if (event.key === 'Home') nextIndex = 0;
        else if (event.key === 'End') nextIndex = buttons.length - 1;
        else return;
        event.preventDefault();
        buttons[nextIndex].click();
        buttons[nextIndex].focus();
      });
    });

    // Limit customer table to 5 visible rows; scrollbar appears when there are more
    const scrollBox = document.getElementById('customer-scroll');
    const custTable = document.getElementById('customer-table');
    const VISIBLE_ROWS = 5;
    function fitCustomerTable() {
      const rows = custTable.querySelectorAll('tbody tr');
      const isEmptyState = rows.length === 1 && rows[0].children.length === 1;
      if (isEmptyState || rows.length <= VISIBLE_ROWS) {
        scrollBox.style.maxHeight = '';
        return;
      }
      let h = custTable.querySelector('thead').offsetHeight;
      for (let i = 0; i < VISIBLE_ROWS; i++) h += rows[i].offsetHeight;
      scrollBox.style.maxHeight = h + 'px';
    }
    fitCustomerTable();
    window.addEventListener('load', fitCustomerTable);
    window.addEventListener('resize', fitCustomerTable);
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(fitCustomerTable);

    // Live search filter on customer list
    const searchInput = document.getElementById('customer-search');
    searchInput.addEventListener('input', () => {
      const q = searchInput.value.trim().toLowerCase();
      document.querySelectorAll('#customer-table tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
      });
    });

    // Feedback summary and filters use only the feedback supplied by the existing query.
    // The query currently loads the latest 12; no backend/SQL changes are made here.
    const feedbackCards = Array.from(document.querySelectorAll('#tab-feedback .fb-card'));
    const ratedCards = feedbackCards.filter(card => Number(card.dataset.rating) >= 1);
    const ratingCount = ratedCards.length;
    const ratingAverage = ratingCount ? ratedCards.reduce((sum, card) => sum + Number(card.dataset.rating), 0) / ratingCount : 0;
    document.getElementById('feedback-average').textContent = ratingAverage.toFixed(1);
    const summaryStars = document.getElementById('feedback-summary-stars');
    const roundedAverage = Math.round(ratingAverage);
    summaryStars.textContent = '★'.repeat(roundedAverage) + '☆'.repeat(5 - roundedAverage);
    summaryStars.setAttribute('aria-label', `${ratingAverage.toFixed(1)} out of 5 stars`);
    const totalFeedback = Number(document.querySelector('.feedback-summary').dataset.totalFeedback);
    const displayedQualifier = totalFeedback > feedbackCards.length ? ' displayed' : '';
    document.getElementById('feedback-rating-caption').textContent = `Based on ${ratingCount}${displayedQualifier} ${ratingCount === 1 ? 'review' : 'reviews'}`;
    document.querySelectorAll('.rating-row').forEach(row => {
      const count = ratedCards.filter(card => card.dataset.rating === row.dataset.ratingLevel).length;
      const percentage = ratingCount ? count / ratingCount * 100 : 0;
      row.querySelector('.rating-fill').style.width = `${percentage}%`;
      row.querySelector('.rating-percent').textContent = `${Math.round(percentage)}%`;
      row.querySelector('.rating-track').setAttribute('aria-valuenow', percentage.toFixed(1));
      row.querySelector('.rating-track').setAttribute('aria-valuetext', `${count} of ${ratingCount} rated reviews`);
    });

    const ratingFilters = document.querySelectorAll('.rating-filter');
    const feedbackSearch = document.getElementById('feedback-search');
    const feedbackEmpty = document.getElementById('feedback-empty');
    const feedbackPageControls = document.getElementById('feedback-pg-controls');
    const FEEDBACK_PER_PAGE = 6;
    let selectedRating = 'all';

    // Each rating filter owns its page, like the separate paginators in manage-oil.php.
    // Preserve pages on reload and clamp them when results shrink, using that same behavior.
    const feedbackPageStoragePrefix = `customer_feedback_pg:${location.pathname}:${document.querySelector('.side-branch .txt span').textContent.trim()}:`;
    function makeFeedbackPaginator(ratingFilter) {
      let currentPage = 1;
      const storageKey = feedbackPageStoragePrefix + ratingFilter;
      try {
        const nav = performance.getEntriesByType('navigation')[0];
        const saved = parseInt(sessionStorage.getItem(storageKey), 10);
        if (nav && nav.type === 'reload' && saved > 0) currentPage = saved;
      } catch (e) {}
      function savePage() {
        try { sessionStorage.setItem(storageKey, String(currentPage)); } catch (e) {}
      }
      function render(items) {
        const totalPages = Math.max(1, Math.ceil(items.length / FEEDBACK_PER_PAGE));
        currentPage = Math.max(1, Math.min(currentPage, totalPages));
        savePage();
        const start = (currentPage - 1) * FEEDBACK_PER_PAGE;
        feedbackCards.forEach(card => { card.hidden = true; });
        items.slice(start, start + FEEDBACK_PER_PAGE).forEach(card => { card.hidden = false; });
        feedbackEmpty.hidden = items.length > 0;
        feedbackEmpty.textContent = feedbackCards.length ? 'No feedback matches your filters.' : 'No feedback yet.';
        feedbackPageControls.innerHTML = '';
        feedbackPageControls.hidden = totalPages <= 1;
        if (totalPages <= 1) return;
        // The fuel-grid paginator uses hideNav=true: numbered buttons, no Previous/Next.
        for (let page = 1; page <= totalPages; page++) {
          const button = document.createElement('button');
          button.type = 'button';
          button.className = 'pg-btn' + (page === currentPage ? ' active' : '');
          button.textContent = String(page);
          button.setAttribute('aria-label', `Page ${page}`);
          if (page === currentPage) button.setAttribute('aria-current', 'page');
          button.addEventListener('click', () => { currentPage = page; render(items); });
          feedbackPageControls.appendChild(button);
        }
      }
      return { render, reset: () => { currentPage = 1; savePage(); } };
    }
    const feedbackPaginators = new Map(Array.from(ratingFilters, filter => [filter.dataset.ratingFilter, makeFeedbackPaginator(filter.dataset.ratingFilter)]));
    function filterFeedback() {
      const query = feedbackSearch.value.trim().toLowerCase();
      const matchingCards = feedbackCards.filter(card => {
        const rating = Number(card.dataset.rating);
        const ratingMatches = selectedRating === 'all' || (selectedRating === '1-2' ? rating >= 1 && rating <= 2 : rating === Number(selectedRating));
        const textMatches = card.querySelector('.fb-text').textContent.toLowerCase().includes(query);
        return ratingMatches && textMatches;
      });
      feedbackPaginators.get(selectedRating).render(matchingCards);
    }
    ratingFilters.forEach(button => button.addEventListener('click', () => {
      selectedRating = button.dataset.ratingFilter;
      ratingFilters.forEach(filter => {
        const active = filter === button;
        filter.classList.toggle('active', active);
        filter.setAttribute('aria-pressed', String(active));
      });
      filterFeedback();
    }));
    feedbackSearch.addEventListener('input', () => {
      // A changed search defines a new result set for every filter; start each on page one.
      feedbackPaginators.forEach(paginator => paginator.reset());
      filterFeedback();
    });
    filterFeedback();

// -------- Feedback Modal Populator & Conversation Log Handler --------
    const modalFeedbackCards = document.querySelectorAll('.fb-card');
    const feedbackModalEl = document.getElementById('feedback-modal');
    const feedbackModal = feedbackModalEl ? new bootstrap.Modal(feedbackModalEl) : null;
    const convLogModalEl = document.getElementById('conv-log-modal');
    const convLogModal = convLogModalEl ? new bootstrap.Modal(convLogModalEl) : null;
    let feedbackOpener = null;

    [feedbackModalEl, convLogModalEl].forEach(modalEl => {
      if (!modalEl) return;
      modalEl.addEventListener('hidden.bs.modal', () => {
        if (feedbackOpener) {
          feedbackOpener.focus();
          feedbackOpener = null;
        }
      });
    });

    // Show a scrollbar only when the log has more than 3 rows
    if (convLogModalEl) {
      convLogModalEl.addEventListener('shown.bs.modal', () => {
        const list = document.getElementById('feedback-conversation-log');
        if (!list) return;
        list.style.maxHeight = '';
        const entries = list.querySelectorAll('.log-entry');
        if (entries.length > 3) {
          const gap = parseFloat(getComputedStyle(list).rowGap) || 0;
          let h = 0;
          for (let i = 0; i < 3; i++) h += entries[i].offsetHeight + gap;
          list.style.maxHeight = (h + 40) + 'px'; // 3 rows + a peek of the 4th
          list.style.overflowY = 'auto';
        } else {
          list.style.maxHeight = 'none';
          list.style.overflowY = 'visible';
        }
      });
    }

    modalFeedbackCards.forEach(card => {
      card.querySelectorAll('[data-feedback-open]').forEach(button => {
        button.addEventListener('click', () => {
          feedbackOpener = button;
          
          const nameEl = card.querySelector('.fb-name');
          const titleEl = document.getElementById('feedback-modal-title');
          if (titleEl && nameEl) {
            titleEl.textContent = `${nameEl.textContent}’s Feedback`;
          }

          let replies = [];
          try {
            replies = JSON.parse(card.dataset.replies || '[]');
          } catch(e) {
            replies = [];
          }
          const fmtStamp = (raw) => {
            if (!raw) return '';
            const d = new Date(String(raw).replace(' ', 'T'));
            if (isNaN(d)) return '';
            return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
              + ' · ' + d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
          };


          if (button.dataset.feedbackOpen === 'log') {
            // Conversation Log modal: customer feedback first, then staff replies (oldest -> latest)
            const logContainer = document.getElementById('feedback-conversation-log');
            if (logContainer) {
              logContainer.innerHTML = '';
              const customerName = nameEl ? nameEl.textContent : 'Customer';
              const cardText = card.querySelector('.fb-text');
              const customerBody = (cardText ? cardText.textContent : '').replace(/^\s*\[Usage[^\]]*\]\s*/, '');
              const first = document.createElement('div');
              first.className = 'log-entry is-customer';
              first.innerHTML = `
                <div class="log-entry-head">
                  <div class="log-author">${escapeHtml(customerName)} <span class="log-badge customer">Customer</span></div>
                  <span class="log-time">${escapeHtml(fmtStamp(card.dataset.createdAt))}</span>
                </div>
                <div class="log-body">${escapeHtml(customerBody)}</div>
              `;
              logContainer.appendChild(first);

              replies.forEach((rep, idx) => {
                const item = document.createElement('div');
                item.className = 'log-entry is-staff';
                const staffName = rep.staff_name ? rep.staff_name : 'Staff Member';
                const latest = idx === replies.length - 1 ? ' <span class="log-badge latest">Latest</span>' : '';
                item.innerHTML = `
                  <div class="log-entry-head">
                    <div class="log-author">${escapeHtml(staffName)} <span class="log-badge staff">Staff</span>${latest}</div>
                    <span class="log-time">${escapeHtml(fmtStamp(rep.reply_date))}</span>
                  </div>
                  <div class="log-body">${escapeHtml(rep.reply_text)}</div>
                `;
                logContainer.appendChild(item);
              });
            }
            if (convLogModal) {
              convLogModal.show();
            }
            return;
          }

          // View Details modal: latest staff reply only
          const starsEl = card.querySelector('.fb-stars');
          const dateEl = card.querySelector('.fb-date');
          const tierEl = card.querySelector('.feedback-tier');
          const textEl = card.querySelector('.fb-text');
          const modalStars = document.getElementById('feedback-modal-stars');
          modalStars.textContent = starsEl ? starsEl.textContent : '';
          modalStars.setAttribute('aria-label', starsEl ? starsEl.getAttribute('aria-label') || '' : '');
          document.getElementById('feedback-modal-date').textContent = dateEl ? dateEl.textContent : '';
          const modalTier = document.getElementById('feedback-modal-tier');
          modalTier.textContent = tierEl ? tierEl.textContent : 'Regular Tier';
          modalTier.className = tierEl ? `${tierEl.className} feedback-modal-tier` : 'tier-pill feedback-tier feedback-modal-tier regular';
          document.getElementById('feedback-modal-text').textContent = (textEl ? textEl.textContent : '').replace(/^\s*\[Usage[^\]]*\]\s*/, '');

          const replyBox = document.getElementById('feedback-modal-reply');
          if (replyBox) {
            const latestReply = replies.length ? replies[replies.length - 1] : null;
            if (latestReply && latestReply.reply_text) {
              replyBox.textContent = latestReply.reply_text;
              replyBox.classList.remove('is-empty');
            } else {
              replyBox.textContent = 'No staff reply yet.';
              replyBox.classList.add('is-empty');
            }
          }

          if (feedbackModal) {
            feedbackModal.show();
          }
        });
      });
    });

    function escapeHtml(str) {
      if (!str) return '';
      return str.toString()
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
    }
    feedbackModalEl.addEventListener('hidden.bs.modal', () => {
  if (feedbackOpener && !feedbackOpener.closest('.fb-card').hidden) feedbackOpener.focus();
    });

    // Notifications Popup Toggle, Clear Badge, & Item Dismiss Handler
    const bellBtn = document.getElementById('notif-bell');
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

    if (bellBtn && notifPopup) {
      const toggleNotifPopup = () => {
        const isOpen = notifPopup.classList.contains('show');
        notifPopup.classList.toggle('show', !isOpen);
        if (notifPopup.classList.contains('show')) {
          notifPopup.style.display = 'block';
          bellBtn.setAttribute('aria-expanded', 'true');
        } else {
          notifPopup.style.display = 'none';
          bellBtn.setAttribute('aria-expanded', 'false');
        }

        const ping = bellBtn.querySelector('.ping');
        if (ping) {
          ping.style.transform = 'scale(0)';
          setTimeout(() => ping.remove(), 180);
        }
      };

      bellBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        toggleNotifPopup();
      });

      document.addEventListener('click', (e) => {
        const clickedBell = bellBtn.contains(e.target);
        const clickedPopup = notifPopup.contains(e.target);
        if (!clickedBell && !clickedPopup) {
          notifPopup.classList.remove('show');
          notifPopup.style.display = 'none';
          bellBtn.setAttribute('aria-expanded', 'false');
        }
      });
    }
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
