<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../profile_avatar.php';
$managerDisplayName = trim((string) ($_SESSION['user_name'] ?? 'Manager'));
$managerInitial = map_pump_profile_initial($managerDisplayName, 'M');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MAP & PUMP — Monitor Booking Slot</title>

<!-- Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Noto+Sans+Myanmar:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="icon" type="image/png" href="/images/Logo.png">
<!-- Bootstrap 5.3.3 CSS -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<!-- Bootstrap Icons -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

<!-- Font Awesome 6.5.1 CSS -->
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">

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
  @keyframes pulse{0%,100%{opacity:1;} 50%{opacity:0.35;}}

  .app-shell{display:flex; min-height:100vh;}
  
  /* Sidebar Custom Styling */
  .sidebar{
    width:252px; 
    flex-shrink:0; 
    background:var(--navy-900); 
    border-right:1px solid rgba(255,255,255,0.06); 
    display:flex; 
    flex-direction:column; 
    padding:22px 16px; 
    position:sticky; 
    top:0; 
    height:100vh; 
    z-index:60;
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
    color:var(--slate-50);
    box-shadow:inset 0 0 0 1px rgba(56,189,248,0.32);
  }
  .side-link.active:hover{transform: none;}
  .side-link .ic{font-size:16px; width:18px; text-align:center; transition: transform 0.2s ease;}
  .side-link:hover .ic{transform: scale(1.15);}
  .side-logout{
    display:flex; align-items:center; gap:12px; padding:12px; border-radius:10px; font-size:14px;
    color:var(--red-400); font-weight:600; margin-top:14px; border-top:1px solid rgba(255,255,255,0.06);
    padding-top:18px;
    transition: background 0.25s, color 0.25s, padding-left 0.2s;
  }
  .side-logout:hover{background:rgba(248,113,113,0.08); padding-left: 16px;}

  .main{flex:1; min-width:0;}
  
  .topbar{
    position:sticky; 
    top:0; 
    z-index:50; 
    display:flex; 
    align-items:center; 
    justify-content:space-between; 
    padding:16px 32px; 
    background:rgba(7,11,20,0.72); 
    backdrop-filter:blur(14px); 
    border-bottom:1px solid rgba(255,255,255,0.06);
  }
  .topbar h1{font-size:18px; font-weight:700; display:flex; align-items:center; gap:10px;}

  .live-tag{
    display:inline-flex; align-items:center; gap:6px; font-size:11px; color:var(--mint-400);
    background:rgba(52,211,153,0.12); padding:4px 10px; border-radius: 999px; font-weight:700;
  }
  .live-tag .dot{width:6px; height:6px; border-radius:50%; background:var(--mint-400); animation:pulse 1.6s infinite;}
  .topbar .date{font-size:12.5px; color:var(--slate-400); margin-top:2px; font-variant-numeric: tabular-nums;}
  .topbar-right{display:flex; align-items:center; gap:16px; position: relative;}

  .bell{
    width:36px; height:36px; border-radius:50%; background:var(--navy-800); display:flex; align-items:center;
    justify-content:center; border:1px solid rgba(255,255,255,0.08); position:relative; font-size:15px; color:var(--slate-300);
    cursor:pointer; transition: transform 0.2s, background-color 0.2s;
  }
  .bell:hover{
    background: var(--navy-700);
    transform: scale(1.05);
  }
  .bell .ping{position:absolute; top:6px; right:7px; width:7px; height:7px; border-radius:50%; background:var(--amber-400); box-shadow:0 0 0 2px var(--navy-800); transition: transform 0.2s;}

  /* ---------- Notifications Dropdown Popup ---------- */
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
    cursor: pointer;
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

  .avatar-chip{display:flex; align-items:center; gap:10px; font-size:13px; cursor: pointer; padding: 4px 8px; border-radius: 99px; transition: background 0.2s;}
  .avatar-chip:hover{background: rgba(255,255,255,0.05);}
  .avatar-circle{
    width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,var(--sky-400),var(--blue-700));
    display:flex; align-items:center; justify-content:center; font-weight:700; font-size:13px; font-family:'Space Grotesk';
  }

  /* Content Entrance Fade */
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
  .page-head p{font-size:13px; color:var(--slate-400); margin-bottom:24px;}

  /* Cards and Panels with Custom Gradients */
  .stat-card{
    background:linear-gradient(160deg, var(--navy-800), var(--navy-900)); 
    border:1px solid rgba(255,255,255,0.07); 
    border-radius:14px; 
    padding:16px 18px;
    transition: transform 0.3s ease, border-color 0.3s ease;
  }
  .stat-card:hover {
    transform: translateY(-2px);
    border-color: rgba(56,189,248,0.25);
  }
  .stat-card .n{font-family:'Space Grotesk'; font-size:22px; font-weight:700; transition: color 0.3s;}
  .stat-card .l{font-size:11.5px; color:var(--slate-400); margin-top:4px;}

  .section-title{font-size:15.5px; font-weight:700; margin-bottom:16px;}

  .pump-card{
    background:linear-gradient(160deg, var(--navy-800), var(--navy-900)); 
    border:1px solid rgba(255,255,255,0.07); 
    border-radius:16px; 
    padding:18px; 
    text-align:center;
    transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
  }
  .pump-card:hover:not(.maint) {
    transform: translateY(-4px);
    border-color: rgba(251,191,36,0.2);
    box-shadow: 0 10px 25px -10px rgba(0,0,0,0.5);
  }
  .pump-card .pnum{font-family:'Space Grotesk'; font-size:14px; color:var(--slate-400); margin-bottom:10px;}
  
  .pump-card .tok{
    font-family:'Space Grotesk'; font-size:28px; font-weight:700; color:var(--amber-300); margin-bottom:8px;
    transition: color 0.3s ease, font-size 0.3s ease;
  }
  .pump-card.idle .tok{color:var(--slate-400); font-size:16px;}
  .pump-card.maint .tok{color:var(--red-400); font-size:16px;}
  
  .pstat{font-size:10.5px; font-weight:700; padding:4px 10px; border-radius:999px; display:inline-flex; align-items:center; gap:6px; transition: all 0.3s;}
  .pstat.active{background:rgba(52,211,153,0.14); color:var(--mint-400);}
  .pstat.idle{background:rgba(139,150,173,0.14); color:var(--slate-400);}
  .pstat.maint{background:rgba(248,113,113,0.14); color:var(--red-400);}
  .pstat .d{width:6px; height:6px; border-radius:50%; background:currentColor;}

  .panel{
    background:linear-gradient(160deg, var(--navy-800), var(--navy-900)); 
    border:1px solid rgba(255,255,255,0.07); 
    border-radius:18px; 
    overflow:hidden;
  }
  
  /* Table Styling */
  .table-custom {
    margin-bottom: 0;
  }
  .table-custom thead th {
    background: transparent;
    color: var(--slate-400);
    font-size: 11.5px;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    padding: 14px 18px;
    border-bottom: 1px solid rgba(255,255,255,0.07);
    font-weight: 600;
  }
  .table-custom tbody tr {
    transition: opacity 0.3s ease, transform 0.3s ease;
  }
  .table-custom tbody td {
    background: transparent;
    color: var(--slate-50);
    padding: 14px 18px;
    font-size: 13.5px;
    border-bottom: 1px solid rgba(255,255,255,0.05);
    vertical-align: middle;
    transition: all 0.2s;
  }
  .table-custom tbody tr:last-child td {
    border-bottom: none;
  }
  .table-custom tbody tr:hover td {
    background: rgba(255,255,255,0.015);
  }

  .tok-cell{font-family:'Space Grotesk'; font-weight:700; color:var(--amber-300);}
  
  .status-pill{font-size:11px; font-weight:700; padding:4px 10px; border-radius:999px; display:inline-flex; align-items:center; gap:6px; transition: all 0.3s;}
  .status-pill.waiting{background:rgba(56,189,248,0.14); color:var(--sky-400);}
  .status-pill.serving{background:rgba(52,211,153,0.14); color:var(--mint-400);}
  .status-pill.done{background:rgba(139,150,173,0.14); color:var(--slate-400);}
  .status-pill .d{width:6px; height:6px; border-radius:50%; background:currentColor;}
  
  .act-link{font-size:12px; color:var(--sky-400); font-weight:600; margin-right:14px; transition: color 0.15s ease, opacity 0.2s; cursor: pointer; text-decoration: none;}
  .act-link:hover{color:var(--blue-500);}
  .act-link.danger{color:var(--red-400);}
  .act-link.danger:hover{color:#fca5a5;}
</style>
</head>
<body>

<div class="app-shell">
  <!-- Sidebar -->
  <aside class="sidebar">
    <a href="dashboard.php" class="text-decoration-none d-flex align-items-center gap-2 text-white fw-bold padding" style="padding: 6px 8px 0;">
      <img src="/images/Logo.png" alt="Logo" class="nav-logo">
      <span class="disp" style="font-size: 17px; letter-spacing: 0.5px; color: #ffffff; font-weight: 700;">Map & Pump</span>
    </a>
    <div class="side-role">Branch Manager Console</div>

    <div class="side-branch">
      <b>Denko - Sanchaung</b>
      <span>Branch ID: YGN-014</span>
    </div>

    <nav class="side-nav">
      <a href="dashboard.php" class="side-link"><span class="ic"><i class="fa-solid fa-house"></i></span> Dashboard</a>
      <a href="manage-staff.php" class="side-link"><span class="ic"><i class="fa-solid fa-users"></i></span> Manage Staff</a>
      <a href="manage-oil.php" class="side-link"><span class="ic"><i class="fa-solid fa-gas-pump"></i></span> Manage Oil Stock</a>
      <a href="booking-slot.php" class="side-link active"><span class="ic"><i class="fa-solid fa-ticket"></i></span> Monitor Booking Slot</a>
      <a href="view-customers.php" class="side-link"><span class="ic"><i class="fa-solid fa-address-book"></i></span> View Customers</a>
      <a href="report-hq.php" class="side-link"><span class="ic"><i class="fa-solid fa-paper-plane"></i></span> Report to HQ</a>
      <a href="edit-profile.php" class="side-link"><span class="ic"><i class="fa-solid fa-gear"></i></span> Edit Profile</a>
    </nav>

    <a href="login.php" class="side-logout"><span class="ic"><i class="fa-solid fa-right-from-bracket"></i></span> Log Out</a>
  </aside>

  <!-- Main Content Panel -->
  <div class="main">
    <!-- Topbar -->
    <div class="topbar">
      <div>
        <h1>Monitor Booking Slot <span class="live-tag ms-2"><span class="dot"></span> Live</span></h1>
        <div class="date" id="live-clock">Wednesday · July 15, 2026 · 11:38 AM</div>
      </div>
      <div class="topbar-right">
        <div class="bell" id="notif-bell"><i class="fa-regular fa-bell"></i><span class="ping"></span></div>
        
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
          <div class="avatar-circle"><a href="edit-profile.php"><?= htmlspecialchars($managerInitial, ENT_QUOTES, 'UTF-8') ?></a></div>
          <div><b><a href="edit-profile.php"><?= htmlspecialchars($managerDisplayName, ENT_QUOTES, 'UTF-8') ?></a></b></div>
        </div>
      </div>
    </div>

    <!-- Content Workspace -->
    <div class="content">
      <div class="page-head">
        <p>Track each pump's live status and the token queue in real time.</p>
      </div>

      <!-- Stats Grid -->
      <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
          <div class="stat-card">
            <div class="n" id="stat-waiting">9</div>
            <div class="l">Tokens Waiting</div>
          </div>
        </div>
        <div class="col-6 col-lg-3">
          <div class="stat-card">
            <div class="n">16 min</div>
            <div class="l">Avg Wait Time</div>
          </div>
        </div>
        <div class="col-6 col-lg-3">
          <div class="stat-card">
            <div class="n" id="stat-issued">184</div>
            <div class="l">Tokens Issued Today</div>
          </div>
        </div>
        <div class="col-6 col-lg-3">
          <div class="stat-card">
            <div class="n" style="color:var(--red-400)">1 / 4</div>
            <div class="l">Pumps Under Maintenance</div>
          </div>
        </div>
      </div>

      <!-- Pump Status Section -->
      <div class="section-title">Pump Status</div>
      <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
          <div class="pump-card" id="pump-card-1">
            <div class="pnum">PUMP #1</div>
            <div class="tok">B-042</div>
            <span class="pstat active"><span class="d"></span> Active</span>
          </div>
        </div>
        <div class="col-6 col-lg-3">
          <div class="pump-card" id="pump-card-2">
            <div class="pnum">PUMP #2</div>
            <div class="tok">B-041</div>
            <span class="pstat active"><span class="d"></span> Active</span>
          </div>
        </div>
        <div class="col-6 col-lg-3">
          <div class="pump-card idle" id="pump-card-3">
            <div class="pnum">PUMP #3</div>
            <div class="tok">Ready</div>
            <span class="pstat idle"><span class="d"></span> Idle</span>
          </div>
        </div>
        <div class="col-6 col-lg-3">
          <div class="pump-card maint" id="pump-card-4">
            <div class="pnum">PUMP #4</div>
            <div class="tok">Maintenance</div>
            <span class="pstat maint"><span class="d"></span> Maintenance</span>
          </div>
        </div>
      </div>

      <!-- Live Queue Section -->
      <div class="section-title">Live Queue</div>
      <div class="panel">
        <div class="table-responsive">
          <table class="table table-custom" id="queue-table">
            <thead>
              <tr>
                <th>Token</th>
                <th>Requested Time</th>
                <th>Est. Time</th>
                <th>Pump</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr id="row-B-041">
                <td class="tok-cell">B-041</td>
                <td>11:24 AM</td>
                <td>11:38 AM</td>
                <td class="pump-cell">#2</td>
                <td><span class="status-pill serving"><span class="d"></span> Serving</span></td>
                <td><a class="act-link danger btn-cancel" data-token="B-041">Cancel</a></td>
              </tr>
              <tr id="row-B-042">
                <td class="tok-cell">B-042</td>
                <td>11:26 AM</td>
                <td>11:40 AM</td>
                <td class="pump-cell">#1</td>
                <td><span class="status-pill serving"><span class="d"></span> Serving</span></td>
                <td><a class="act-link danger btn-cancel" data-token="B-042">Cancel</a></td>
              </tr>
              <tr id="row-B-043">
                <td class="tok-cell">B-043</td>
                <td>11:29 AM</td>
                <td>11:44 AM</td>
                <td class="pump-cell">—</td>
                <td><span class="status-pill waiting"><span class="d"></span> Waiting</span></td>
                <td>
                  <a class="act-link btn-next" data-token="B-043">Call Next</a>
                  <a class="act-link danger btn-cancel" data-token="B-043">Cancel</a>
                </td>
              </tr>
              <tr id="row-B-044">
                <td class="tok-cell">B-044</td>
                <td>11:31 AM</td>
                <td>11:48 AM</td>
                <td class="pump-cell">—</td>
                <td><span class="status-pill waiting"><span class="d"></span> Waiting</span></td>
                <td>
                  <a class="act-link btn-next" data-token="B-044">Call Next</a>
                  <a class="act-link danger btn-cancel" data-token="B-044">Cancel</a>
                </td>
              </tr>
              <tr id="row-B-045">
                <td class="tok-cell">B-045</td>
                <td>11:33 AM</td>
                <td>11:52 AM</td>
                <td class="pump-cell">—</td>
                <td><span class="status-pill waiting"><span class="d"></span> Waiting</span></td>
                <td>
                  <a class="act-link btn-next" data-token="B-045">Call Next</a>
                  <a class="act-link danger btn-cancel" data-token="B-045">Cancel</a>
                </td>
              </tr>
              <tr id="row-B-040">
                <td class="tok-cell">B-040</td>
                <td>11:18 AM</td>
                <td>11:32 AM</td>
                <td class="pump-cell">#3</td>
                <td><span class="status-pill done"><span class="d"></span> Done</span></td>
                <td>—</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="page-foot">© 2026 MAP & PUMP | All Right Reserved</div>
    </div>
  </div>
</div>

<!-- Bootstrap 5.3.3 JavaScript Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
  document.addEventListener('DOMContentLoaded', () => {
    
    // 1. Live Dynamic Clock
    function updateClock() {
      const now = new Date();
      const options = { weekday: 'long', month: 'short', day: 'numeric', year: 'numeric' };
      const dateString = now.toLocaleDateString('en-US', options);
      const timeString = now.toLocaleTimeString('en-US', { hour12: true, hour: '2-digit', minute: '2-digit', second: '2-digit' });
      document.getElementById('live-clock').textContent = `${dateString} · ${timeString}`;
    }
    setInterval(updateClock, 1000);
    updateClock();

    // 2. Notification Dropdown Toggle & Interactions
    const bellBtn = document.getElementById('notif-bell');
    const notifPopup = document.getElementById('notif-popup');
    const markAllBtn = document.getElementById('mark-all-read');
    const notifList = document.getElementById('notif-list');

    if (bellBtn && notifPopup) {
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
    }

    // 3. System States & Counters
    let tokensWaiting = 9;

    function updateWaitingStat(change) {
      tokensWaiting = Math.max(0, tokensWaiting + change);
      document.getElementById('stat-waiting').textContent = tokensWaiting;
    }

    // 4. Queue Event Delegation
    const queueTable = document.getElementById('queue-table');
    
    queueTable.addEventListener('click', (e) => {
      const target = e.target;
      
      // CALL NEXT ACTION
      if (target.classList.contains('btn-next')) {
        const token = target.getAttribute('data-token');
        const row = document.getElementById(`row-${token}`);
        
        // Find if Pump #3 is idle and available
        const pump3Card = document.getElementById('pump-card-3');
        if (pump3Card && pump3Card.classList.contains('idle')) {
          // Transition Pump #3 from Idle to Active
          pump3Card.classList.remove('idle');
          pump3Card.querySelector('.tok').textContent = token;
          pump3Card.querySelector('.tok').style.color = 'var(--amber-300)';
          pump3Card.querySelector('.tok').style.fontSize = '28px';
          
          const pstat = pump3Card.querySelector('.pstat');
          pstat.className = "pstat active";
          pstat.innerHTML = `<span class="d"></span> Active`;
          
          // Update Row Data Cells in Table
          if (row) {
            row.querySelector('.pump-cell').textContent = '#3';
            const statusCell = row.querySelector('.status-pill');
            statusCell.className = "status-pill serving";
            statusCell.innerHTML = `<span class="d"></span> Serving`;
            
            // Remove Call Next option, leave only Cancel
            const actionCell = target.parentElement;
            actionCell.innerHTML = `<a class="act-link danger btn-cancel" data-token="${token}">Cancel</a>`;
          }
          
          updateWaitingStat(-1);
        } else {
          alert("All compatible pumps are currently busy serving other tokens.");
        }
      }
      
      // CANCEL ACTION
      if (target.classList.contains('btn-cancel')) {
        const token = target.getAttribute('data-token');
        const row = document.getElementById(`row-${token}`);
        
        if (row) {
          // Check if this token was currently on a pump card
          for (let i = 1; i <= 3; i++) {
            const card = document.getElementById(`pump-card-${i}`);
            if (card && card.querySelector('.tok').textContent === token) {
              // Reset the card back to ready / idle status
              card.classList.add('idle');
              card.querySelector('.tok').textContent = "Ready";
              card.querySelector('.tok').style.color = "var(--slate-400)";
              card.querySelector('.tok').style.fontSize = "16px";
              
              const pstat = card.querySelector('.pstat');
              pstat.className = "pstat idle";
              pstat.innerHTML = `<span class="d"></span> Idle`;
            }
          }
          
          // Deduct from counter if it was in waiting status
          if (row.querySelector('.status-pill').classList.contains('waiting')) {
            updateWaitingStat(-1);
          }
          
          // Smooth row removal animation
          row.style.opacity = '0';
          row.style.transform = 'translateX(20px)';
          setTimeout(() => {
            row.remove();
          }, 300);
        }
      }
    });
  });
</script>
</body>
</html>