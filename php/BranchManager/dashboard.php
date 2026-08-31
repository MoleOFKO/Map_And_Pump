<?php
/**
 * dashboard.php  —  Branch Manager landing page.
 * Loads: KPIs, live queue, alerts, recent notifications.
 */
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../conn.php';
require_branch_manager();

$ctx      = current_context($conn);
$user     = $ctx['user'];
$branch   = $ctx['branch'];
$initials = initials($user['full_name']);
$bid      = (int)$branch['branch_id'];

// ---- KPI: Tokens issued today ----
$stmt = $conn->prepare("
    SELECT COUNT(*) FROM tokens
    WHERE branch_id = :b AND DATE(requested_at) = CURDATE()
");
$stmt->execute([':b' => $bid]);
$tokens_today = (int)$stmt->fetchColumn();

// tokens issued yesterday for comparison
$stmt = $conn->prepare("
    SELECT COUNT(*) FROM tokens
    WHERE branch_id = :b AND DATE(requested_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
");
$stmt->execute([':b' => $bid]);
$tokens_yesterday = (int)$stmt->fetchColumn();
$tokens_delta_pct = $tokens_yesterday > 0
    ? round((($tokens_today - $tokens_yesterday) / $tokens_yesterday) * 100)
    : 0;

// currently waiting
$stmt = $conn->prepare("
    SELECT COUNT(*) FROM tokens WHERE branch_id = :b AND status = 'waiting'
");
$stmt->execute([':b' => $bid]);
$waiting_count = (int)$stmt->fetchColumn();

// average wait time (minutes) — for tokens completed today
$stmt = $conn->prepare("
    SELECT AVG(TIMESTAMPDIFF(MINUTE, requested_at, served_at))
    FROM tokens
    WHERE branch_id = :b AND status = 'completed' AND DATE(served_at) = CURDATE()
");
$stmt->execute([':b' => $bid]);
$avg_wait_min = round((float)$stmt->fetchColumn());

// avg fuel stock across all tanks
$stmt = $conn->prepare("
    SELECT ROUND(AVG(current_l / capacity_l * 100)) AS pct
    FROM tanks WHERE branch_id = :b AND capacity_l > 0
");
$stmt->execute([':b' => $bid]);
$avg_stock_pct = (int)$stmt->fetchColumn();

// low stock tank name (if any)
$stmt = $conn->prepare("
    SELECT fuel_name FROM tanks
    WHERE branch_id = :b AND (current_l / capacity_l * 100) < alert_pct
    ORDER BY (current_l / capacity_l) ASC LIMIT 1
");
$stmt->execute([':b' => $bid]);
$low_stock_fuel = $stmt->fetchColumn() ?: null;

// staff on duty
$stmt = $conn->prepare("
    SELECT COUNT(*) FROM staff WHERE branch_id = :b AND is_active = 1
");
$stmt->execute([':b' => $bid]);
$staff_total = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("
    SELECT COUNT(*) FROM staff
    WHERE branch_id = :b AND is_active = 1 AND duty_status = 'On Duty'
");
$stmt->execute([':b' => $bid]);
$staff_on_duty = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("
    SELECT COUNT(*) FROM staff
    WHERE branch_id = :b AND is_active = 1 AND duty_status = 'Leave'
");
$stmt->execute([':b' => $bid]);
$staff_on_leave = (int)$stmt->fetchColumn();

// current live queue (top 5)
$stmt = $conn->prepare("
    SELECT t.token_code, t.status, p.pump_no
    FROM tokens t
    LEFT JOIN pumps p ON p.pump_id = t.pump_id
    WHERE t.branch_id = :b AND t.status IN ('waiting','serving')
    ORDER BY FIELD(t.status,'serving','waiting'), t.requested_at ASC
    LIMIT 5
");
$stmt->execute([':b' => $bid]);
$queue = $stmt->fetchAll();

// alerts (unread notifications, latest 5)
$stmt = $conn->prepare("
    SELECT * FROM notifications
    WHERE branch_id = :b AND is_read = 0
    ORDER BY created_at DESC LIMIT 5
");
$stmt->execute([':b' => $bid]);
$alerts = $stmt->fetchAll();
$has_unread = count($alerts) > 0;
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
  }
  .side-branch:hover{
    background: var(--navy-700);
    border-color: rgba(56,189,248,0.2);
  }
  .side-branch b{display:block; font-size:14px; font-family:'Space Grotesk'; margin-bottom:2px;}
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

  .kpi-grid{display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:34px;}
  @media (max-width:1100px){.kpi-grid{grid-template-columns:repeat(2,1fr);}}
  
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

    <div class="side-branch">
      <b><?= h($branch['branch_name']) ?></b>
      <span>Branch ID: <?= h($branch['branch_code']) ?></span>
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

    <a href="login.php" class="side-logout"><span class="ic"><i class="fa-solid fa-right-from-bracket"></i></span> Log Out</a>
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
            <div class="notif-item">
              <i class="fa-solid fa-triangle-exclamation text-warning ic"></i>
              <div class="notif-item-body">
                <b>Diesel Stock Warning</b>
                <span>Diesel running low (22%).</span>
                <div class="notif-time">10 mins ago</div>
              </div>
            </div>
            <div class="notif-item">
              <i class="fa-solid fa-wrench text-danger ic"></i>
              <div class="notif-item-body">
                <b>Pump #4 Down</b>
                <span>Pump requires immediate inspection.</span>
                <div class="notif-time">1 hour ago</div>
              </div>
            </div>
            <div class="notif-item">
              <i class="fa-solid fa-truck-ramp-box text-success ic"></i>
              <div class="notif-item-body">
                <b>Petrol 92 Received</b>
                <span>8,000 liters added to storage.</span>
                <div class="notif-time">3 hours ago</div>
              </div>
            </div>
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
          <?php if ($tokens_delta_pct > 0): ?>
            <div class="trend up"><i class="fa-solid fa-caret-up"></i> <?= $tokens_delta_pct ?>% vs yesterday</div>
          <?php elseif ($tokens_delta_pct < 0): ?>
            <div class="trend down"><i class="fa-solid fa-caret-down"></i> <?= abs($tokens_delta_pct) ?>% vs yesterday</div>
          <?php else: ?>
            <div class="trend flat">Same as yesterday</div>
          <?php endif; ?>
        </div>
        <div class="kpi">
          <div class="lbl">Currently Waiting</div>
          <div class="val" id="waiting-count"><?= $waiting_count ?> cars</div>
          <div class="trend flat">Avg wait <?= $avg_wait_min ?: '—' ?> min</div>
        </div>
        <div class="kpi">
          <div class="lbl">Avg Fuel Stock</div>
          <div class="val"><?= $avg_stock_pct ?>%</div>
          <?php if ($low_stock_fuel): ?>
            <div class="trend down"><i class="fa-solid fa-caret-down"></i> <?= h($low_stock_fuel) ?> running low</div>
          <?php else: ?>
            <div class="trend ok" style="color:var(--mint-400)">All tanks healthy</div>
          <?php endif; ?>
        </div>
        <div class="kpi">
          <div class="lbl">Staff On Duty Today</div>
          <div class="val"><?= $staff_on_duty ?> / <?= $staff_total ?></div>
          <?php if ($staff_on_leave > 0): ?>
            <div class="trend ok" style="color:var(--mint-400)"><?= $staff_on_leave ?> on leave</div>
          <?php else: ?>
            <div class="trend flat">No one on leave</div>
          <?php endif; ?>
        </div>
      </div>

      <div class="section-title"><h2>Modules</h2></div>
      <div class="module-grid">
        <a href="manage-staff.php" class="module-card staff">
          <div class="ic-badge"><i class="fa-solid fa-users"></i></div>
          <h3>Manage Staff</h3>
          <p>Manage shift schedules, duty status, and add new staff members.</p>
          <span class="go">Open <i class="fa-solid fa-arrow-right"></i></span>
        </a>
        <a href="manage-oil.php" class="module-card oil">
          <div class="ic-badge"><i class="fa-solid fa-gas-pump"></i></div>
          <h3>Manage Oil Stock</h3>
          <p>View tank levels, delivery records, and low-stock alerts.</p>
          <span class="go">Open <i class="fa-solid fa-arrow-right"></i></span>
        </a>
        <a href="booking-slot.php" class="module-card slot">
          <div class="ic-badge"><i class="fa-solid fa-ticket"></i></div>
          <h3>Monitor Booking Slot</h3>
          <p>Track the live queue, pump status, and every token in real time.</p>
          <span class="go">Open <i class="fa-solid fa-arrow-right"></i></span>
        </a>
        <a href="view-customers.php" class="module-card slot">
          <div class="ic-badge"><i class="fa-solid fa-address-book"></i></div>
          <h3>View Customers</h3>
          <p>Browse customer records and read feedback left at your branch.</p>
          <span class="go">Open <i class="fa-solid fa-arrow-right"></i></span>
        </a>
        <a href="report-hq.php" class="module-card oil">
          <div class="ic-badge"><i class="fa-solid fa-paper-plane"></i></div>
          <h3>Report to HQ</h3>
          <p>Send incident, stock, or maintenance reports to head office.</p>
          <span class="go">Open <i class="fa-solid fa-arrow-right"></i></span>
        </a>
        <a href="edit-profile.php" class="module-card profile">
          <div class="ic-badge"><i class="fa-solid fa-gear"></i></div>
          <h3>Edit Profile</h3>
          <p>Edit your personal details and manage your linked user account.</p>
          <span class="go">Open <i class="fa-solid fa-arrow-right"></i></span>
        </a>
      </div>

      <div class="two-col">
        <div class="panel">
          <h3>Current Queue — Live</h3>
          <div class="queue-list" id="queue-container">
            <?php if (!$queue): ?>
              <div style="text-align:center; padding:20px; color:var(--slate-400); font-size:12.5px;">Queue is empty.</div>
            <?php else: foreach ($queue as $q): ?>
              <div class="queue-row">
                <span>
                  <span class="tok"><?= h($q['token_code']) ?></span> ·
                  <span class="pump"><?= $q['pump_no'] ? 'Pump #' . (int)$q['pump_no'] : '—' ?></span>
                </span>
                <span class="status-pill <?= h($q['status']) ?>"><?= h(ucfirst($q['status'])) ?></span>
              </div>
            <?php endforeach; endif; ?>
          </div>
        </div>

        <div class="panel">
          <h3>Alerts <span style="font-size: 11px; font-weight: normal; color: var(--slate-400); margin-left: 6px;">(Click to dismiss)</span></h3>
          <div class="alert-list" id="alert-container">
            <?php if (!$alerts): ?>
              <div style="text-align:center; padding:20px; color:var(--slate-400); font-size:12.5px;">All alerts cleared.</div>
            <?php else:
              $kind_map = ['warning'=>'warn','danger'=>'err','success'=>'ok','info'=>'ok'];
              foreach ($alerts as $a):
                $cls = $kind_map[$a['kind']] ?? 'ok'; ?>
              <div class="alert-row <?= h($cls) ?>" data-notif-id="<?= (int)$a['notif-id'] ?>">
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  document.addEventListener('DOMContentLoaded', () => {
    
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

    // 3. Simulated Live Queue System Updating Realtime
    let currentMaxToken = 45;
    let tokensIssued = 184;
    let currentlyWaiting = 9;
    const queueContainer = document.getElementById('queue-container');
    const waitingCounter = document.getElementById('waiting-count');
    const issuedCounter = document.getElementById('issued-count');

    function simulateQueueRefresh() {
      const rows = Array.from(queueContainer.querySelectorAll('.queue-row'));
      if (rows.length === 0) return;

      if (Math.random() > 0.4) {
        const firstActiveRow = rows[0];
        firstActiveRow.classList.add('fade-out');
        
        setTimeout(() => {
          firstActiveRow.remove();

          const remainingRows = Array.from(queueContainer.querySelectorAll('.queue-row'));
          const nextInLine = remainingRows.find(row => row.querySelector('.status-pill').classList.contains('waiting'));
          
          if (nextInLine) {
            const pill = nextInLine.querySelector('.status-pill');
            pill.className = 'status-pill serving';
            pill.textContent = 'Serving';
            nextInLine.querySelector('.pump').textContent = `Pump #${Math.floor(Math.random() * 3) + 1}`;
          }

          currentMaxToken++;
          tokensIssued++;
          
          const newRow = document.createElement('div');
          newRow.className = 'queue-row fade-in';
          newRow.innerHTML = `<span><span class="tok">B-0${currentMaxToken}</span> · <span class="pump">—</span></span><span class="status-pill waiting">Waiting</span>`;
          queueContainer.appendChild(newRow);

          currentlyWaiting = Math.max(1, currentlyWaiting + (Math.random() > 0.5 ? 1 : -1));
          waitingCounter.textContent = `${currentlyWaiting} cars`;
          issuedCounter.textContent = tokensIssued;
        }, 300);
      }
    }
    
    setInterval(simulateQueueRefresh, 10000);

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

    if (markAllBtn) {
      markAllBtn.addEventListener('click', () => {
        notifList.innerHTML = `<div style="text-align: center; padding: 12px 0; color: var(--slate-400); font-size: 12px;">No new notifications</div>`;
      });
    }
  });
</script>
</body>
</html>