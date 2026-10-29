<?php
/**
 * dashboard.php  —  Branch Manager landing page.
 * Loads: KPIs, live queue, alerts, recent notifications.
 */
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../conn.php';
require_branch_manager();

$ctx      = current_context($pdo);
$user     = $ctx['user'];
$branch   = $ctx['branch'];
$initials = initials($user['full_name']);
$bid      = (int)$branch['branch_id'];

// ---- KPI: Tokens issued today ----
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM booking bk
    INNER JOIN station_business_scope sbs ON sbs.station_id = bk.station_id
    WHERE sbs.branch_manager_user_id = :manager_id
      AND DATE(bk.created_at) = CURDATE()
");
$stmt->execute([':manager_id' => $user['user_id']]);
$tokens_today = (int)$stmt->fetchColumn();

// tokens issued yesterday for comparison
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM booking bk
    INNER JOIN station_business_scope sbs ON sbs.station_id = bk.station_id
    WHERE sbs.branch_manager_user_id = :manager_id
      AND DATE(bk.created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
");
$stmt->execute([':manager_id' => $user['user_id']]);
$tokens_yesterday = (int)$stmt->fetchColumn();
$tokens_delta_pct = $tokens_yesterday > 0
    ? round((($tokens_today - $tokens_yesterday) / $tokens_yesterday) * 100)
    : 0;

// currently waiting
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM booking bk
    INNER JOIN station_business_scope sbs ON sbs.station_id = bk.station_id
    WHERE sbs.branch_manager_user_id = :manager_id
      AND bk.status = 'expired'
");
$stmt->execute([':manager_id' => $user['user_id']]);
$expired_tokens = (int)$stmt->fetchColumn();

// average wait time (minutes) — for tokens completed today
$stmt = $pdo->prepare("
    SELECT AVG(TIMESTAMPDIFF(MINUTE, booked_at, filled_at))
    FROM usage_history uh
    INNER JOIN station_business_scope sbs ON sbs.station_id = uh.station_id
    WHERE sbs.branch_manager_user_id = :manager_id
      AND DATE(uh.filled_at) = CURDATE()
");
$stmt->execute([':manager_id' => $user['user_id']]);
$avg_wait_min = round((float)$stmt->fetchColumn());

// avg fuel stock across all tanks
$stmt = $pdo->prepare("
    SELECT ROUND(AVG(current_l / capacity_l * 100)) AS pct
    FROM tanks WHERE branch_id = :b AND capacity_l > 0
");
$stmt->execute([':b' => $bid]);
$avg_stock_pct = (int)$stmt->fetchColumn();

// low stock tank name (if any)
$stmt = $pdo->prepare("
    SELECT COALESCE(ot.oil_name, CONCAT('Fuel ', t.tank_id)) AS fuel_name
    FROM tanks t
    LEFT JOIN oil_types ot ON ot.oil_type_id = t.oil_type_id
    WHERE t.branch_id = :b AND (t.current_l / t.capacity_l * 100) < t.alert_pct
    ORDER BY (t.current_l / t.capacity_l) ASC LIMIT 1
");
$stmt->execute([':b' => $bid]);
$low_stock_fuel = $stmt->fetchColumn() ?: null;

// fetch tanks for visualizer (current liters, capacity, alert threshold)
$stmt = $pdo->prepare("
  SELECT t.tank_id,
         COALESCE(ot.oil_name, CONCAT('Fuel ', t.tank_id)) AS fuel_name,
         t.current_l,
         t.capacity_l,
         t.alert_pct
  FROM tanks t
  LEFT JOIN oil_types ot ON ot.oil_type_id = t.oil_type_id
  WHERE t.branch_id = :b AND t.capacity_l > 0
  ORDER BY COALESCE(ot.oil_name, CONCAT('Fuel ', t.tank_id)) ASC
");
$stmt->execute([':b' => $bid]);
$tanks = $stmt->fetchAll();

$defaultTankColors = [
  'Octane 92' => '#38bdf8',
  'Octane 95' => '#2563eb',
  'Diesel' => '#34d399',
  'Premium Diesel' => '#fbbf24',
  'Super Diesel' => '#f59e0b',
  'Fuel' => '#38bdf8',
];

// current live queue (top 5)
$stmt = $pdo->prepare("
    SELECT bk.qr_refrence_code AS token_code,
           CASE WHEN bk.status = 'notfilled' THEN 'waiting' ELSE bk.status END AS status,
           NULL AS pump_no
    FROM booking bk
    INNER JOIN station_business_scope sbs ON sbs.station_id = bk.station_id
    WHERE sbs.branch_manager_user_id = :manager_id
      AND bk.status IN ('notfilled','waiting','serving')
    ORDER BY FIELD(bk.status,'serving','notfilled','waiting'), bk.created_at ASC
    LIMIT 5
");
$stmt->execute([':manager_id' => $user['user_id']]);
$queue = $stmt->fetchAll();

// surface low-stock tanks as notifications (deduped, non-fatal)
try { notif_low_stock_scan($pdo, $bid, (int)$user['user_id']); } catch (Throwable $e) {}
// alerts / notifications (latest 5, normalised, with real read-state)
$alerts       = notif_recent($pdo, (int)$user['user_id'], 5);
$unread_count = notif_unread_count($pdo, (int)$user['user_id']);
$has_unread   = $unread_count > 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MAP & PUMP — Branch Dashboard</title>

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
  
  /* Reset all elements to default cursor */
  *, a, button, .bell, .avatar-chip, .alert-row, .side-link, .side-logout, .module-card, #mark-all-read {
    cursor: default !important;
  }

  body{background:var(--navy-950); color:var(--slate-50); font-family:'Inter','Space Grotesk',sans-serif; overflow-x:hidden;}
  .disp{font-family:'Space Grotesk','Noto Sans Myanmar',sans-serif;}
  a{color:inherit; text-decoration:none;}
  @media (prefers-reduced-motion: reduce){ *{animation-duration:0.001ms !important; animation-iteration-count:1 !important; transition: none !important;} }

  .app-shell{display:flex; min-height:100vh;}

  /* ---------- sidebar ---------- */
  .sidebar{
    width:252px; flex-shrink:0; background:var(--navy-900);
    border-right:1px solid rgba(255,255,255,0.06);
    display:flex; flex-direction:column; padding:22px 16px;
    position:sticky; top:0; height:100vh; z-index:60;
  }
  .nav-logo {
    width: 32px;        
    height: auto;
    object-fit: contain;
  }
  .side-brand{display:flex; align-items:center; gap:10px; font-weight:700; font-size:17px; padding:6px 8px 0;}
  .side-brand svg{width:28px; height:28px; transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);}
  .side-brand:hover svg{transform: rotate(-10deg) scale(1.1);}
  .side-role{font-size:11px; color:var(--slate-400); font-weight:500; margin:2px 0 0 46px;}
  .side-branch{
    margin:20px 8px 0; padding:10px 12px; background:var(--navy-800); border-radius:12px;
    border:1px solid rgba(255,255,255,0.06); font-size:12.5px;
    transition: background 0.3s, border-color 0.3s;
    display:flex; align-items:center; gap:10px;
  }
  .side-branch:hover{
    background: var(--navy-700);
    border-color: rgba(56,189,248,0.2);
  }
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
  .side-link:hover{
    background:rgba(255,255,255,0.045); color:var(--slate-50);
    transform: translateX(4px);
  }
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
    justify-content:center; border:1px solid rgba(255,255,255,0.08); position:relative; font-size:15px; color:var(--slate-300);
    transition: transform 0.2s, background-color 0.2s;
  }
  .bell:hover{
    background: var(--navy-700);
    transform: scale(1.05);
  }
  .bell .ping{position:absolute; top:6px; right:7px; width:7px; height:7px; border-radius:50%; background:var(--amber-400); box-shadow:0 0 0 2px var(--navy-800);}
  
  /* ---------- Notifications Dropdown ---------- */
  .notif-popup {
    position: absolute;
    top: 48px;
    right: 60px;
    width: 320px;
    background: var(--navy-800);
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 14px;
    box-shadow: 0 16px 32px rgba(0, 0, 0, 0.4);
    padding: 16px;
    z-index: 100;
    display: none;
    animation: fadeInDown 0.25s cubic-bezier(0.16, 1, 0.3, 1);
  }
  .notif-popup.show {
    display: block;
  }
  .notif-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    padding-bottom: 10px;
    margin-bottom: 10px;
  }
  .notif-header h4 {
    font-size: 14px;
    font-weight: 700;
    margin: 0;
  }
  .notif-header span {
    font-size: 11px;
    color: var(--sky-400);
  }
  .notif-item {
    padding: 10px;
    border-radius: 8px;
    font-size: 12.5px;
    transition: background-color 0.2s;
    display: flex;
    gap: 10px;
  }
  .notif-item:hover {
    background-color: rgba(255, 255, 255, 0.04);
  }
  .notif-item .ic {
    font-size: 14px;
    margin-top: 2px;
  }
  .notif-item-body b {
    display: block;
    font-size: 13px;
  }
  .notif-item-body span {
    color: var(--slate-400);
    font-size: 11.5px;
  }
  .notif-time {
    font-size: 10px;
    color: var(--slate-400);
    margin-top: 4px;
  }
  @keyframes fadeInDown {
    from { opacity: 0; transform: translateY(-8px); }
    to { opacity: 1; transform: translateY(0); }
  }

  .avatar-chip{display:flex; align-items:center; gap:10px; font-size:13px; padding: 4px 8px; border-radius: 99px; transition: background 0.2s;}
  .avatar-chip:hover{background: rgba(255,255,255,0.05);}
  .avatar-circle{width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,var(--sky-400),var(--blue-700)); display:flex; align-items:center; justify-content:center; font-weight:700; font-size:13px; font-family:'Space Grotesk';}

  /* Cascade Entrance Loading Animation */
  .content{
    padding:30px 32px 60px;
    opacity: 0;
    transform: translateY(10px);
    animation: layoutFadeIn 0.5s ease forwards;
  }

  .page-foot{
    text-align:center; margin-top:34px; padding-top:18px;
    border-top:1px solid rgba(255,255,255,0.06);
    font-size:12px; color:var(--slate-400);
  }
  @keyframes layoutFadeIn {
    to { opacity: 1; transform: translateY(0); }
  }

  .hello{font-size:22px; font-weight:700; margin-bottom:4px;}
  .hello-sub{font-size:13.5px; color:var(--slate-400); margin-bottom:26px;}

  .kpi-grid{display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-bottom:34px;}
  @media (max-width:1100px){.kpi-grid{grid-template-columns:repeat(2,1fr);}}
  @media (max-width:640px){.kpi-grid{grid-template-columns:1fr;}}
  
  .kpi{
    background:linear-gradient(160deg, var(--navy-800), var(--navy-900)); border:1px solid rgba(255,255,255,0.07);
    border-radius:16px; padding:18px 20px; position:relative; overflow:hidden;
    transition: transform 0.3s cubic-bezier(0.2, 0.8, 0.2, 1), border-color 0.3s, box-shadow 0.3s;
  }
  .kpi:hover {
    transform: translateY(-4px);
    border-color: rgba(56,189,248,0.25);
    box-shadow: 0 12px 24px -10px rgba(0, 0, 0, 0.5);
  }
  .kpi .lbl{font-size:12px; color:var(--slate-400); margin-bottom:8px;}
  .kpi .val{font-family:'Space Grotesk'; font-size:26px; font-weight:700; transition: color 0.3s;}
  .kpi:hover .val { color: var(--sky-400); }
  .kpi .trend{font-size:11.5px; margin-top:6px; font-weight:600;}
  .trend.up{color:var(--mint-400);} .trend.down{color:var(--red-400);} .trend.flat{color:var(--slate-400);}

  .section-title{display:flex; align-items:center; justify-content:space-between; margin-bottom:16px;}
  .section-title h2{font-size:16px; font-weight:700;}
  .section-title a{font-size:12.5px; color:var(--sky-400); font-weight:600;}

  .module-grid{display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:36px;}
  @media (max-width:1200px){.module-grid{grid-template-columns:repeat(2,1fr);}}
  @media (max-width:600px){.module-grid{grid-template-columns:1fr;}}
  
  .module-card{
    background:linear-gradient(160deg, var(--navy-800), var(--navy-900)); border:1px solid rgba(255,255,255,0.07);
    border-radius:18px; padding:24px; display:flex; flex-direction:column; gap:12px;
    transition: transform 0.3s cubic-bezier(0.2, 0.8, 0.2, 1), border-color 0.3s, box-shadow 0.3s;
  }
  .module-card:hover {
    transform: translateY(-6px);
    border-color: rgba(56,189,248,0.3);
    box-shadow: 0 16px 32px -12px rgba(0,0,0,0.6);
  }
  .module-card .ic-badge{width:44px; height:44px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:20px; transition: transform 0.3s;}
  .module-card:hover .ic-badge { transform: scale(1.1) rotate(2deg); }
  .module-card.staff .ic-badge{background:rgba(52,211,153,0.14); color:var(--mint-400);}
  .module-card.oil .ic-badge{background:rgba(251,191,36,0.14); color:var(--amber-400);}
  .module-card.slot .ic-badge{background:rgba(56,189,248,0.14); color:var(--sky-400);}
  .module-card.profile .ic-badge{background:rgba(148,163,255,0.16); color:#a5b4fc;}
  .module-card h3{font-size:15.5px; font-weight:700;}
  .module-card p{font-size:12.5px; color:var(--slate-400); line-height:1.6; flex:1;}
  .module-card .go{font-size:13px; font-weight:700; color:var(--sky-400); display:flex; align-items:center; gap:6px;}
  .module-card .go i { transition: transform 0.2s; }
  .module-card:hover .go i { transform: translateX(4px); }

  .two-col{display:grid; grid-template-columns:1.3fr 1fr; gap:18px;}
  @media (max-width:980px){.two-col{grid-template-columns:1fr;}}

  .panel{background:linear-gradient(160deg, var(--navy-800), var(--navy-900)); border:1px solid rgba(255,255,255,0.07); border-radius:18px; padding:22px;}
  .panel h3{font-size:15px; font-weight:700; margin-bottom:16px;}

  /* Queue animations */
  .queue-list { display: flex; flex-direction: column; }
  .queue-row{
    display:flex; align-items:center; justify-content:space-between; padding:11px 0; 
    border-bottom:1px solid rgba(255,255,255,0.05); font-size:13px;
    transition: all 0.3s ease;
  }
  .queue-row.fade-out {
    opacity: 0;
    transform: translateX(-15px);
  }
  .queue-row.fade-in {
    animation: slideInRow 0.3s ease forwards;
  }
  @keyframes slideInRow {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
  }
  .queue-row:last-child{border-bottom:none;}
  .queue-row .tok{font-family:'Space Grotesk'; font-weight:700; color:var(--amber-300);}
  .queue-row .pump{color:var(--slate-400); font-size:12px;}
  .status-pill{font-size:10.5px; font-weight:700; padding:3px 9px; border-radius:999px;}
  .status-pill.serving{background:rgba(52,211,153,0.14); color:var(--mint-400); animation: pulseGreen 2.5s infinite;}
  .status-pill.waiting{background:rgba(56,189,248,0.14); color:var(--sky-400);}

  @keyframes pulseGreen {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.65; }
  }

  /* Alerts Interactive Dismiss */
  .alert-list { display: flex; flex-direction: column; }
  .alert-row{
    display:flex; gap:12px; padding:12px; border-bottom:1px solid rgba(255,255,255,0.05); font-size:12.5px;
    border-radius: 8px; transition: background-color 0.2s, opacity 0.3s, transform 0.3s;
  }
  .alert-row:hover {
    background-color: rgba(255, 255, 255, 0.03);
  }
  .alert-row:last-child{border-bottom:none;}
  .alert-row .dot2{width:8px; height:8px; border-radius:50%; margin-top:4px; flex-shrink:0;}
  .alert-row.warn .dot2{background:var(--amber-400);}
  .alert-row.err .dot2{background:var(--red-400);}
  .alert-row.ok .dot2{background:var(--mint-400);}
  .alert-row b{display:block; margin-bottom:2px;}
  .alert-row span{color:var(--slate-400); display: flex; align-items: center; justify-content: space-between;}
  .alert-row .dismiss-txt { font-size: 10px; opacity: 0; color: var(--slate-400); transition: opacity 0.2s; }
  .alert-row:hover .dismiss-txt { opacity: 1; }
  /* Tank visualizer styles */
  .tank-visualizer { display:flex; gap:18px; align-items:stretch; justify-content:flex-start; flex-wrap:wrap; }
  .tank { width:110px; min-height:260px; background:linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01)); border:1px solid rgba(148, 197, 255, 0.8); border-radius:10px; padding:12px; display:flex; flex-direction:column; align-items:center; gap:10px; box-shadow:0 0 0 1px rgba(255,255,255,0.02); }
  .tank { --tank-accent:#7cc8ff; border-color: rgba(124, 200, 255, 0.9); box-shadow: 0 10px 20px -16px rgba(124, 200, 255, 0.65); }
  .tank .tank-body { width:36px; height:180px; background:linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01)); border-radius:6px; position:relative; overflow:hidden; border:1px solid rgba(255,255,255,0.03); }
  .tank .tank-fill { position:absolute; left:0; bottom:0; width:100%; background:linear-gradient(180deg, var(--sky-400), rgba(56,189,248,0.85)); display:flex; align-items:flex-end; justify-content:center; transition:height 0.6s ease; }
  .tank .tank-label { font-weight:700; font-size:13px; text-align:center; min-height:32px; display:flex; align-items:center; justify-content:center; }
  .tank .tank-meta { font-size:12px; color:var(--slate-400); text-align:center; }
  .tank .tank-status { margin-top:auto; padding:6px 10px; border-radius:999px; font-weight:700; font-size:11px; }
  .tank-status.ok { background: rgba(52,211,153,0.12); color: var(--mint-400); border:1px solid rgba(52,211,153,0.06); }
  .tank-status.low { background: rgba(248,113,113,0.07); color: var(--red-400); border:1px solid rgba(248,113,113,0.04); }

  /* Color Editor Modal Styles */
  .tank-panel-header{display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:14px;}
  .tank-panel-header h3{margin-bottom:0;}
  .edit-color-btn{display:inline-flex; align-items:center; gap:8px; border:1px solid rgba(255,255,255,0.12); background:rgba(255,255,255,0.03); color:var(--slate-50); border-radius:999px; padding:7px 11px; font-size:11.5px; font-weight:700; transition: all 0.2s ease;}
  .edit-color-btn:hover{background:rgba(56,189,248,0.08); border-color:rgba(56,189,248,0.28); color:var(--sky-400);}
  .color-modal-backdrop{position:fixed; inset:0; background:rgba(2,6,23,0.72); display:none; align-items:center; justify-content:center; z-index:200; padding:20px;}
  .color-modal-backdrop.show{display:flex;}
  .color-modal{width:min(720px, 100%); background:linear-gradient(160deg, var(--navy-800), var(--navy-900)); border:1px solid rgba(255,255,255,0.08); border-radius:18px; padding:22px; box-shadow:0 18px 40px rgba(0,0,0,0.45);}
  .color-modal-header{display:flex; align-items:center; justify-content:space-between; margin-bottom:16px;}
  .color-modal-header h4{font-size:18px; margin:0; font-weight:700;}
  .color-modal-close{width:32px; height:32px; border-radius:50%; background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.08); color:var(--slate-50); font-size:18px; display:flex; align-items:center; justify-content:center;}
  .fuel-color-table{width:100%; border-collapse:collapse; table-layout:fixed;}
  .fuel-color-table th, .fuel-color-table td{padding:12px 10px; border-bottom:1px solid rgba(255,255,255,0.06); text-align:left;}
  .fuel-color-table th{font-size:11px; color:var(--slate-400); text-transform:uppercase; letter-spacing:0.08em; font-weight:700;}
  .fuel-color-table tbody tr{cursor:pointer; transition:background 0.2s ease;}
  .fuel-color-table tbody tr:hover{background:rgba(255,255,255,0.02);}
  .fuel-color-row{display:flex; align-items:center; gap:10px;}
  .fuel-swatch{width:18px; height:18px; border-radius:6px; border:2px solid rgba(255,255,255,0.2); box-shadow:inset 0 0 0 1px rgba(0,0,0,0.2); flex-shrink:0;}
  .color-picker-cell{display:flex; align-items:center; gap:10px; justify-content:flex-start;}
  .color-preview{display:inline-flex; align-items:center; gap:10px; padding:8px 10px; border-radius:10px; border:1px solid rgba(255,255,255,0.08); background:rgba(255,255,255,0.02); min-width:150px; cursor:pointer;}
  .color-preview-chip{width:18px; height:18px; border-radius:6px; border:2px solid rgba(255,255,255,0.2);}
  .color-preview-text{font-size:12px; font-weight:700; color:var(--slate-50);}
  .color-picker-input{position:absolute; opacity:0; width:1px; height:1px; pointer-events:none;}
  .fuel-color-save{margin-top:18px; display:flex; justify-content:flex-end;}
  .fuel-color-save button{background:linear-gradient(135deg, var(--sky-400), var(--blue-500)); color:white; border:none; border-radius:10px; padding:10px 16px; font-weight:700; font-size:12.5px;}
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

    <?php $hqLogoSrc = hq_logo_src($branch['hq_logo'] ?? null); ?>
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
      <a href="manage-oil.php" class="side-link"><span class="ic"><i class="fa-solid fa-gas-pump"></i></span> Manage Oil Stock</a>
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
        <h1>Dashboard</h1>
        <div class="date" id="live-clock">Wednesday · July 9, 2026</div>
      </div>
      <div class="topbar-right">
        <div class="bell" id="notif-bell"><i class="fa-regular fa-bell"></i><?php if ($has_unread): ?><span class="ping"></span><?php endif; ?></div>
        
        <!-- Notification Dropdown Menu -->
        <div class="notif-popup" id="notif-popup">
          <div class="notif-header">
            <h4>Notifications</h4>
            <span id="mark-all-read">Clear All</span>
          </div>
          <div id="notif-list">
            <?php if (!$alerts): ?>
              <div class="notif-empty" style="text-align:center; padding:14px 0; color:var(--slate-400); font-size:12px;">No new notifications</div>
            <?php else:
              $icon_map = [
                'warn' => 'fa-triangle-exclamation text-warning',
                'err'  => 'fa-circle-exclamation text-danger',
                'ok'   => 'fa-truck-ramp-box text-success',
                'info' => 'fa-circle-info text-info',
              ];
              foreach ($alerts as $a):
                $ic = $icon_map[$a['kind']] ?? $icon_map['info']; ?>
              <div class="notif-item" data-notif-id="<?= (int)$a['notif_id'] ?>">
                <i class="fa-solid <?= h($ic) ?> ic"></i>
                <div class="notif-item-body">
                  <b><?= h($a['title']) ?></b>
                  <span><?= h($a['body']) ?></span>
                  <div class="notif-time"><?= h($a['time_ago']) ?></div>
                </div>
              </div>
            <?php endforeach; endif; ?>
          </div>
        </div>

        <div class="avatar-chip">
          <div class="avatar-circle"><a href="edit-profile.php"><?= h($initials) ?></a></div>
          <div><b><a href="edit-profile.php"><?= h(explode(' ', $user['full_name'])[0]) ?></a></b></div>
        </div>
      </div>
    </div>

    <div class="content">
      <div class="hello">Welcome back, <?= h(explode(' ', $user['full_name'])[0]) ?>👋</div>
      <div class="hello-sub">Here's a quick overview of your branch today.</div>

      <div class="kpi-grid">
        <div class="kpi">
          <div class="lbl">Tokens Issued Today</div>
          <div class="val" id="issued-count"><?= $tokens_today ?></div>
        </div>
        <div class="kpi">
          <div class="lbl">Expired Tokens</div>
          <div class="val" id="expired-count"><?= $expired_tokens ?> tokens</div>
        </div>
        <div class="kpi">
          <div class="lbl">Avg Fuel Stock</div>
          <div class="val"><?= $avg_stock_pct ?>%</div>
        </div>
      </div>

      <div class="two-col">
        <div>
          <div class="panel" style="padding:18px;">
            <div class="tank-panel-header">
              <h3 style="margin-bottom:0;">Tank Levels</h3>
              <button type="button" class="edit-color-btn" id="editColorBtn"><i class="fa-solid fa-palette"></i> Edit Color</button>
            </div>
            <div class="tank-visualizer">
              <?php if (empty($tanks)): ?>
                <div style="color:var(--slate-400);">No tank data configured.</div>
              <?php else: foreach ($tanks as $t): 
                $fuelName = trim((string)$t['fuel_name']);
                $fuelLabel = preg_replace('/^Petrol\s+/i', 'Octane ', $fuelName);
                $fuelKey = $fuelLabel === 'Octane 92' || $fuelLabel === 'Octane 95' || $fuelLabel === 'Octane 98' ? $fuelLabel : $fuelLabel;
                $pct = $t['capacity_l'] > 0 ? (int) round(($t['current_l'] / $t['capacity_l']) * 100) : 0;
                $status_class = $pct < (int)$t['alert_pct'] ? 'low' : 'ok';
                $tankColor = $defaultTankColors[$fuelLabel] ?? $defaultTankColors[$fuelName] ?? '#38bdf8';
                ?>
                <div class="tank" data-fuel-name="<?= h($fuelLabel) ?>" data-tank-color="<?= h($tankColor) ?>">
                  <div class="tank-label"><?= h($fuelLabel) ?></div>
                  <div class="tank-body" aria-hidden="true">
                    <div class="tank-fill" style="height:<?= max(0, min(100, $pct)) ?>%; background:linear-gradient(180deg, <?= h($tankColor) ?>, rgba(255,255,255,0.28));"></div>
                  </div>
                  <div class="tank-meta"><?= number_format((float)$t['current_l'], 2) ?>L / <?= number_format((float)$t['capacity_l'], 0) ?>L</div>
                  <div class="tank-meta"><?= $pct ?>%</div>
                  <div class="tank-status <?= $status_class ?>"><?= $status_class === 'low' ? 'Low Stock' : 'Healthy' ?></div>
                </div>
              <?php endforeach; endif; ?>
            </div>
          </div>
        </div>

        <div class="panel">
          <h3>Alerts <span style="font-size: 11px; font-weight: normal; color: var(--slate-400); margin-left: 6px;">(Click to dismiss)</span></h3>
          <div class="alert-list" id="alert-container">
            <?php if (!$alerts): ?>
              <div style="text-align:center; padding: 20px; color:var(--slate-400); font-size:12.5px;">All alerts cleared.</div>
            <?php else:
              $kind_map = ['warn'=>'warn','err'=>'err','ok'=>'ok','info'=>'ok'];
              foreach ($alerts as $a):
                $cls = $kind_map[$a['kind']] ?? 'ok'; ?>
              <div class="alert-row <?= h($cls) ?>" data-notif-id="<?= (int)$a['notif_id'] ?>">
                <div class="dot2"></div>
                <div><b><?= h($a['title']) ?></b><span><?= h($a['body']) ?> <small class="dismiss-txt">Dismiss</small></span></div>
              </div>
            <?php endforeach; endif; ?>
          </div>
        </div>
      </div>

      <div class="page-foot">© 2026 MAP & PUMP | All Right Reserved</div>
    </div>
  </div>
</div>

<div class="color-modal-backdrop" id="fuelColorModal" aria-hidden="true">
  <div class="color-modal" role="dialog" aria-modal="true" aria-labelledby="fuelColorTitle">
    <div class="color-modal-header">
      <h4 id="fuelColorTitle">Fuel Colors</h4>
      <button type="button" class="color-modal-close" id="closeFuelColorModal" aria-label="Close">×</button>
    </div>
    <table class="fuel-color-table">
      <thead>
        <tr>
          <th>Fuel Type</th>
          <th>Color</th>
        </tr>
      </thead>
      <tbody id="fuelColorTableBody"></tbody>
    </table>
    <div class="fuel-color-save">
      <button type="button" id="saveFuelColorsBtn">Save Preferences</button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  document.addEventListener('DOMContentLoaded', () => {
    const defaultTankColors = {
      'Octane 92': '#38bdf8',
      'Octane 95': '#2563eb',
      'Diesel': '#34d399',
      'Premium Diesel': '#fbbf24',
      'Super Diesel': '#f59e0b'
    };

    const fuelColorKey = 'mapPumpTankColors';
    const savedColors = JSON.parse(localStorage.getItem(fuelColorKey) || '{}');
    const tankMap = [...document.querySelectorAll('.tank')].reduce((acc, tankEl) => {
      const fuelName = tankEl.dataset.fuelName;
      acc[fuelName] = tankEl;
      return acc;
    }, {});

    function normalizeFuelName(fuelName) {
      if (!fuelName) return fuelName;
      return fuelName.replace(/^Petrol\s+/i, 'Octane ');
    }

    function getFuelColor(fuelName, fallback) {
      const normalized = normalizeFuelName(fuelName);
      return savedColors[normalized] || defaultTankColors[normalized] || fallback || '#38bdf8';
    }

    function applyTankColors() {
      document.querySelectorAll('.tank').forEach((tankEl) => {
        const fuelName = normalizeFuelName(tankEl.dataset.fuelName || '');
        const color = getFuelColor(fuelName, tankEl.dataset.tankColor || '#38bdf8');
        const fill = tankEl.querySelector('.tank-fill');
        if (fill) {
          fill.style.background = `linear-gradient(180deg, ${color}, rgba(255,255,255,0.28))`;
        }
        tankEl.dataset.tankColor = color;
        tankEl.style.setProperty('--tank-accent', color);
      });
    }

    function buildFuelColorRows() {
      const rows = [...document.querySelectorAll('.tank')].map((tankEl) => {
        const fuelName = normalizeFuelName(tankEl.dataset.fuelName || '');
        return {
          fuelName,
          color: getFuelColor(fuelName, tankEl.dataset.tankColor || '#38bdf8')
        };
      });

      const uniqueRows = Array.from(new Map(rows.map(item => [item.fuelName, item])).values());
      const tbody = document.getElementById('fuelColorTableBody');

      tbody.innerHTML = uniqueRows.map((row) => `
        <tr data-fuel-name="${row.fuelName}">
          <td>
            <div class="fuel-color-row">
              <span class="fuel-swatch" style="background:${row.color};"></span>
              <span>${row.fuelName}</span>
            </div>
          </td>
          <td>
            <div class="color-picker-cell">
              <label class="color-preview" for="color-${row.fuelName.replace(/\s+/g, '-')}">
                <span class="color-preview-chip" style="background:${row.color};"></span>
                <span class="color-preview-text">${row.color.toUpperCase()}</span>
              </label>
              <input class="color-picker-input" id="color-${row.fuelName.replace(/\s+/g, '-')}" type="color" value="${row.color}" data-fuel-name="${row.fuelName}">
            </div>
          </td>
        </tr>
      `).join('');

      tbody.querySelectorAll('tr').forEach((rowEl) => {
        rowEl.addEventListener('click', (event) => {
          if (event.target.closest('input')) return;
          const input = rowEl.querySelector('input[type="color"]');
          if (input) input.click();
        });
      });

      tbody.querySelectorAll('input[type="color"]').forEach((picker) => {
        picker.addEventListener('input', (event) => {
          const fuelName = event.target.dataset.fuelName;
          const newColor = event.target.value;
          savedColors[fuelName] = newColor;
          const previewChip = event.target.closest('.color-picker-cell')?.querySelector('.color-preview-chip');
          const previewText = event.target.closest('.color-picker-cell')?.querySelector('.color-preview-text');
          if (previewChip) previewChip.style.background = newColor;
          if (previewText) previewText.textContent = newColor.toUpperCase();

          const targetTank = document.querySelector(`.tank[data-fuel-name="${CSS.escape(fuelName)}"]`);
          if (targetTank) {
            const fill = targetTank.querySelector('.tank-fill');
            if (fill) fill.style.background = `linear-gradient(180deg, ${newColor}, rgba(255,255,255,0.28))`;
            targetTank.dataset.tankColor = newColor;
            targetTank.style.setProperty('--tank-accent', newColor);
          }
        });
      });
    }

    applyTankColors();
    buildFuelColorRows();

    document.getElementById('editColorBtn')?.addEventListener('click', () => {
      buildFuelColorRows();
      document.getElementById('fuelColorModal').classList.add('show');
    });

    document.getElementById('closeFuelColorModal')?.addEventListener('click', () => {
      document.getElementById('fuelColorModal').classList.remove('show');
    });

    document.getElementById('fuelColorModal')?.addEventListener('click', (event) => {
      if (event.target === event.currentTarget) {
        event.currentTarget.classList.remove('show');
      }
    });

    document.getElementById('saveFuelColorsBtn')?.addEventListener('click', () => {
      localStorage.setItem(fuelColorKey, JSON.stringify(savedColors));
      applyTankColors();
      document.getElementById('fuelColorModal').classList.remove('show');
    });

    // 1. Interactive Live Clock (Myanmar Timezone Format)
    function updateClock() {
      const now = new Date();
      const options = { weekday: 'long', month: 'short', day: 'numeric', year: 'numeric' };
      const dateString = now.toLocaleDateString('en-US', options);
      const timeString = now.toLocaleTimeString('en-US', { hour12: true, hour: '2-digit', minute: '2-digit', second: '2-digit' });
      document.getElementById('live-clock').textContent = `${dateString} · ${timeString}`;
    }
    setInterval(updateClock, 1000);
    updateClock();

    // 2. Interactive Alert Dismissal Sequence
    const alertList = document.getElementById('alert-container');
    alertList.addEventListener('click', (e) => {
      const alertRow = e.target.closest('.alert-row');
      if (alertRow) {
        alertRow.style.transform = 'scale(0.95)';
        alertRow.style.opacity = '0';
        setTimeout(() => {
          alertRow.remove();
          if (alertList.children.length === 0) {
            alertList.innerHTML = `<div style="text-align: center; padding: 20px 0; color: var(--slate-400); font-size: 12.5px;">All alerts cleared.</div>`;
          }
        }, 300);
      }
    });

    // 4. Toggle Notifications Popup & Reset Badge
    const bellBtn = document.getElementById('notif-bell');
    const notifPopup = document.getElementById('notif-popup');
    const markAllBtn = document.getElementById('mark-all-read');
    const notifList = document.getElementById('notif-list');

    bellBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      const ping = bellBtn.querySelector('.ping');
      if (ping) {
        ping.style.transform = 'scale(0)';
        setTimeout(() => ping.remove(), 200);
      }
      notifPopup.classList.toggle('show');
    });

    document.addEventListener('click', (e) => {
      if (!notifPopup.contains(e.target) && !bellBtn.contains(e.target)) {
        notifPopup.classList.remove('show');
      }
    });

    function postDismiss(payload) {
      return fetch('api_dismiss_notification.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      }).then(r => r.json()).catch(() => ({ ok:false }));
    }

    const alertContainer = document.getElementById('alert-container');
    if (alertContainer) {
      alertContainer.addEventListener('click', (e) => {
        const row = e.target.closest('.alert-row');
        if (!row) return;
        const id = parseInt(row.dataset.notifId || '0', 10);
        row.style.opacity = '0.4';
        postDismiss(id > 0 ? { notif_id: id } : { all: true }).then(res => {
          if (res.ok) {
            row.remove();
            if (!alertContainer.querySelector('.alert-row')) {
              alertContainer.innerHTML = '<div style="text-align:center; padding:20px; color:var(--slate-400); font-size:12.5px;">All alerts cleared.</div>';
            }
          } else {
            row.style.opacity = '1';
          }
        });
      });
    }

    if (markAllBtn) {
      markAllBtn.addEventListener('click', () => {
        postDismiss({ all: true }).then(() => {
          notifList.innerHTML = `<div class="notif-empty" style="text-align: center; padding: 12px 0; color: var(--slate-400); font-size: 12px;">No new notifications</div>`;
          if (alertContainer) {
            alertContainer.innerHTML = '<div style="text-align:center; padding:20px; color:var(--slate-400); font-size:12.5px;">All alerts cleared.</div>';
          }
          const ping = bellBtn.querySelector('.ping');
          if (ping) ping.remove();
        });
      });
    }
  });
</script>
<?php require_once __DIR__ . '/../moderator_hire_popup.php'; ?>
</body>
</html>
