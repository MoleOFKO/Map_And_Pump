<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MAP & PUMP — Manage Oil Stock</title>

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
    border-color: rgba(52,211,153,0.2);
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
    background:linear-gradient(135deg, rgba(52,211,153,0.16), rgba(29,78,216,0.10)); 
    color:var(--slate-50); box-shadow:inset 0 0 0 1px rgba(52,211,153,0.32);
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
  .topbar h1{font-size:18px; font-weight:700;}
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

  .page-head{display:flex; align-items:center; justify-content:space-between; margin-bottom:22px; flex-wrap:wrap; gap:14px;}
  .page-head h2{font-size:20px; font-weight:700;}
  .page-head p{font-size:13px; color:var(--slate-400); margin-top:4px;}

  .btn-primary-custom{
    background:linear-gradient(135deg, var(--mint-400), #10b981); color:#06251c; font-weight:700; font-size:13.5px;
    padding:11px 18px; border-radius:11px; border:none; cursor:pointer; display:inline-flex; align-items:center; gap:8px;
    box-shadow:0 10px 24px -10px rgba(52,211,153,0.55);
    transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.25s;
  }
  .btn-primary-custom:hover{
    transform: translateY(-2px);
    box-shadow:0 12px 28px -8px rgba(52,211,153,0.7);
  }

  /* Fuel Stock Cards Styling */
  .stock-card{
    background:linear-gradient(160deg, var(--navy-800), var(--navy-900)); 
    border:1px solid rgba(255,255,255,0.07); 
    border-radius:16px; 
    padding:20px;
    transition: transform 0.3s cubic-bezier(0.2, 0.8, 0.2, 1), border-color 0.3s;
    height: 100%;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
  }
  .stock-card:hover{
    transform: translateY(-3px);
    border-color: rgba(52,211,153,0.25);
  }
  .stock-header{display:flex; align-items:center; justify-content:space-between; margin-bottom:12px;}
  .fuel-type{font-family:'Space Grotesk'; font-size:16px; font-weight:700;}
  .fuel-price{font-size:13px; color:var(--mint-400); font-weight:600;}
  
  .stock-num{font-family:'Space Grotesk'; font-size:24px; font-weight:700; margin-bottom:4px;}
  .stock-cap{font-size:12px; color:var(--slate-400); margin-bottom:14px;}
  
  .progress-custom{
    height:8px; background:rgba(255,255,255,0.06); border-radius:999px; overflow:hidden; margin-bottom:12px;
  }
  .progress-bar-custom{
    height:100%; border-radius:999px; transition: width 0.6s ease;
  }
  .progress-bar-custom.good{background:linear-gradient(90deg, var(--mint-400), #10b981);}
  .progress-bar-custom.warn{background:linear-gradient(90deg, var(--amber-400), var(--amber-300));}
  .progress-bar-custom.danger{background:linear-gradient(90deg, var(--red-400), #ef4444);}

  .stock-foot{display:flex; align-items:center; justify-content:space-between; font-size:12px;}
  .badge-stock{
    padding:4px 8px; border-radius:6px; font-weight:700; font-size:10.5px; display:inline-flex; align-items:center; gap:4px;
  }
  .badge-stock.good{background:rgba(52,211,153,0.12); color:var(--mint-400);}
  .badge-stock.warn{background:rgba(251,191,36,0.12); color:var(--amber-300);}
  .badge-stock.danger{background:rgba(248,113,113,0.12); color:var(--red-400);}

  .section-title{font-size:15.5px; font-weight:700; margin:28px 0 16px;}

  /* Panel & Custom Table Styling */
  .panel{
    background:linear-gradient(160deg, var(--navy-800), var(--navy-900)); 
    border:1px solid rgba(255,255,255,0.07); 
    border-radius:18px; 
    overflow:hidden;
  }
  
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
    transition: background-color 0.2s ease;
  }
  .table-custom tbody td {
    background: transparent;
    color: var(--slate-50);
    padding: 14px 18px;
    font-size: 13.5px;
    border-bottom: 1px solid rgba(255,255,255,0.05);
    vertical-align: middle;
  }
  .table-custom tbody tr:last-child td {
    border-bottom: none;
  }
  .table-custom tbody tr:hover td {
    background: rgba(255,255,255,0.015);
  }

  .badge-delivery{
    padding:4px 10px; border-radius:999px; font-weight:700; font-size:11px; display:inline-flex; align-items:center; gap:6px;
  }
  .badge-delivery.completed{background:rgba(52,211,153,0.14); color:var(--mint-400);}
  .badge-delivery.scheduled{background:rgba(56,189,248,0.14); color:var(--sky-400);}
  .badge-delivery .d{width:6px; height:6px; border-radius:50%; background:currentColor;}

  /* Modern Modal Styling */
  .modal-overlay {
    position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(7, 11, 20, 0.85);
    backdrop-filter: blur(8px); z-index: 100; display: flex; align-items: center; justify-content: center;
    opacity: 0; pointer-events: none; transition: opacity 0.3s ease;
  }
  .modal-overlay.active { opacity: 1; pointer-events: auto; }
  
  .modal-card-custom {
    background: linear-gradient(160deg, var(--navy-800), var(--navy-900));
    border: 1px solid rgba(255,255,255,0.1); border-radius: 20px;
    width: 100%; max-width: 480px; padding: 28px; transform: scale(0.95);
    transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
  }
  .modal-overlay.active .modal-card-custom { transform: scale(1); }
  
  .modal-header-custom { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
  .modal-header-custom h3 { font-size: 18px; font-weight: 700; margin: 0; }
  .modal-close { background: none; border: none; color: var(--slate-400); font-size: 20px; cursor: pointer; transition: color 0.2s; }
  .modal-close:hover { color: var(--slate-50); }

  .form-group { margin-bottom: 16px; }
  .form-group label { display: block; font-size: 12px; color: var(--slate-400); margin-bottom: 6px; font-weight: 500; }
  .form-control-custom {
    width: 100%; background: var(--navy-950); border: 1px solid rgba(255,255,255,0.08);
    border-radius: 10px; padding: 10px 14px; color: var(--slate-50); font-size: 13.5px; outline: none;
    transition: border-color 0.2s;
  }
  .form-control-custom:focus { border-color: var(--mint-400); }
  
  .modal-actions { display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px; }
  .btn-secondary-custom {
    background: transparent; border: 1px solid rgba(255,255,255,0.15); color: var(--slate-300);
    padding: 10px 18px; border-radius: 10px; cursor: pointer; font-size: 13px; font-weight: 600;
    transition: background 0.2s, color 0.2s;
  }
  .btn-secondary-custom:hover { background: rgba(255,255,255,0.05); color: var(--slate-50); }
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
      <a href="manage-oil.php" class="side-link active"><span class="ic"><i class="fa-solid fa-gas-pump"></i></span> Manage Oil Stock</a>
      <a href="booking-slot.php" class="side-link"><span class="ic"><i class="fa-solid fa-ticket"></i></span> Monitor Booking Slot</a>
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
        <h1>Manage Oil Stock</h1>
        <div class="date" id="live-clock">Wednesday · July 15, 2026</div>
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
          <div class="avatar-circle"><a href="edit-profile.php">Fe</a></div>
          <div><b><a href="edit-profile.php">Felix</a></b></div>
        </div>
      </div>
    </div>

    <!-- Content Workspace -->
    <div class="content">
      <div class="page-head">
        <div>
          <h2>Fuel Storage Overview</h2>
          <p>Monitor tank capacities, update retail pricing, and log incoming deliveries.</p>
        </div>
        <button class="btn-primary-custom" id="open-delivery-modal"><i class="fa-solid fa-plus"></i> Record New Delivery</button>
      </div>

      <!-- Current Stock Status Cards -->
      <div class="row g-3 mb-2">
        <!-- Petrol 92 -->
        <div class="col-12 col-sm-6 col-lg-3">
          <div class="stock-card">
            <div>
              <div class="stock-header">
                <span class="fuel-type">Petrol 92</span>
                <span class="fuel-price" id="price-92">2,850 Ks/L</span>
              </div>
              <div class="stock-num" id="vol-92">14,200 L</div>
              <div class="stock-cap">Capacity: 20,000 L (71%)</div>
              <div class="progress-custom">
                <div class="progress-bar-custom good" id="bar-92" style="width: 71%;"></div>
              </div>
            </div>
            <div class="stock-foot">
              <span class="badge-stock good" id="badge-92"><i class="fa-solid fa-circle-check"></i> Optimal</span>
              <a class="text-info fw-semibold edit-price-btn" data-fuel="92" style="cursor:pointer; font-size:11.5px;">Edit Price</a>
            </div>
          </div>
        </div>

        <!-- Petrol 95 -->
        <div class="col-12 col-sm-6 col-lg-3">
          <div class="stock-card">
            <div>
              <div class="stock-header">
                <span class="fuel-type">Petrol 95</span>
                <span class="fuel-price" id="price-95">3,100 Ks/L</span>
              </div>
              <div class="stock-num" id="vol-95">9,800 L</div>
              <div class="stock-cap">Capacity: 15,000 L (65%)</div>
              <div class="progress-custom">
                <div class="progress-bar-custom good" id="bar-95" style="width: 65%;"></div>
              </div>
            </div>
            <div class="stock-foot">
              <span class="badge-stock good" id="badge-95"><i class="fa-solid fa-circle-check"></i> Optimal</span>
              <a class="text-info fw-semibold edit-price-btn" data-fuel="95" style="cursor:pointer; font-size:11.5px;">Edit Price</a>
            </div>
          </div>
        </div>

        <!-- Diesel -->
        <div class="col-12 col-sm-6 col-lg-3">
          <div class="stock-card">
            <div>
              <div class="stock-header">
                <span class="fuel-type">Diesel</span>
                <span class="fuel-price" id="price-diesel">2,450 Ks/L</span>
              </div>
              <div class="stock-num" id="vol-diesel">4,400 L</div>
              <div class="stock-cap">Capacity: 20,000 L (22%)</div>
              <div class="progress-custom">
                <div class="progress-bar-custom danger" id="bar-diesel" style="width: 22%;"></div>
              </div>
            </div>
            <div class="stock-foot">
              <span class="badge-stock danger" id="badge-diesel"><i class="fa-solid fa-triangle-exclamation"></i> Low Stock</span>
              <a class="text-info fw-semibold edit-price-btn" data-fuel="diesel" style="cursor:pointer; font-size:11.5px;">Edit Price</a>
            </div>
          </div>
        </div>

        <!-- Premium Diesel -->
        <div class="col-12 col-sm-6 col-lg-3">
          <div class="stock-card">
            <div>
              <div class="stock-header">
                <span class="fuel-type">Premium Diesel</span>
                <span class="fuel-price" id="price-pdiesel">2,550 Ks/L</span>
              </div>
              <div class="stock-num" id="vol-pdiesel">6,100 L</div>
              <div class="stock-cap">Capacity: 15,000 L (40%)</div>
              <div class="progress-custom">
                <div class="progress-bar-custom warn" id="bar-pdiesel" style="width: 40%;"></div>
              </div>
            </div>
            <div class="stock-foot">
              <span class="badge-stock warn" id="badge-pdiesel"><i class="fa-solid fa-triangle-exclamation"></i> Moderate</span>
              <a class="text-info fw-semibold edit-price-btn" data-fuel="pdiesel" style="cursor:pointer; font-size:11.5px;">Edit Price</a>
            </div>
          </div>
        </div>
      </div>

      <!-- Recent Stock Deliveries Section -->
      <div class="section-title">Recent Stock Deliveries</div>
      <div class="panel">
        <div class="table-responsive">
          <table class="table table-custom" id="deliveries-table">
            <thead>
              <tr>
                <th>Delivery ID</th>
                <th>Fuel Type</th>
                <th>Volume Added</th>
                <th>Supplier / Tanker ID</th>
                <th>Date & Time</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td class="fw-bold text-white">DEL-8842</td>
                <td>Petrol 92</td>
                <td class="text-success fw-bold">+8,000 L</td>
                <td>Denko Main Terminal (T-12)</td>
                <td>Today, 08:30 AM</td>
                <td><span class="badge-delivery completed"><span class="d"></span> Completed</span></td>
              </tr>
              <tr>
                <td class="fw-bold text-white">DEL-8839</td>
                <td>Premium Diesel</td>
                <td class="text-success fw-bold">+5,000 L</td>
                <td>Denko Main Terminal (T-04)</td>
                <td>Yesterday, 02:15 PM</td>
                <td><span class="badge-delivery completed"><span class="d"></span> Completed</span></td>
              </tr>
              <tr>
                <td class="fw-bold text-white">DEL-8845</td>
                <td>Diesel</td>
                <td class="text-info fw-bold">+10,000 L</td>
                <td>Max Terminal (T-09)</td>
                <td>Tomorrow, 09:00 AM</td>
                <td><span class="badge-delivery scheduled"><span class="d"></span> Scheduled</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="page-foot">© 2026 MAP & PUMP | All Right Reserved</div>
    </div>
  </div>
</div>

<!-- Record Delivery Modal -->
<div class="modal-overlay" id="delivery-modal">
  <div class="modal-card-custom">
    <div class="modal-header-custom">
      <h3>Record Fuel Delivery</h3>
      <button class="modal-close" id="close-delivery-modal">&times;</button>
    </div>
    <form id="delivery-form">
      <div class="form-group">
        <label for="del-fuel">Fuel Type</label>
        <select id="del-fuel" class="form-control-custom">
          <option value="92">Petrol 92</option>
          <option value="95">Petrol 95</option>
          <option value="diesel">Diesel</option>
          <option value="pdiesel">Premium Diesel</option>
        </select>
      </div>
      <div class="form-group">
        <label for="del-volume">Volume (Liters)</label>
        <input type="number" id="del-volume" class="form-control-custom" placeholder="e.g. 5000" required>
      </div>
      <div class="form-group">
        <label for="del-supplier">Supplier / Tanker Info</label>
        <input type="text" id="del-supplier" class="form-control-custom" placeholder="e.g. Denko Main Terminal (T-15)" required>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn-secondary-custom" id="cancel-delivery-modal">Cancel</button>
        <button type="submit" class="btn-primary-custom" style="box-shadow: none; padding: 10px 18px;">Confirm Delivery</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Price Modal -->
<div class="modal-overlay" id="price-modal">
  <div class="modal-card-custom">
    <div class="modal-header-custom">
      <h3>Update Fuel Price</h3>
      <button class="modal-close" id="close-price-modal">&times;</button>
    </div>
    <form id="price-form">
      <input type="hidden" id="edit-fuel-key">
      <div class="form-group">
        <label for="new-price-input">New Retail Price (Ks/L)</label>
        <input type="number" id="new-price-input" class="form-control-custom" placeholder="e.g. 2900" required>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn-secondary-custom" id="cancel-price-modal">Cancel</button>
        <button type="submit" class="btn-primary-custom" style="box-shadow: none; padding: 10px 18px;">Update Price</button>
      </div>
    </form>
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

    // 3. Storage Data & Capacities State
    const capacities = {
      '92': 20000,
      '95': 15000,
      'diesel': 20000,
      'pdiesel': 15000
    };

    const currentVolumes = {
      '92': 14200,
      '95': 9800,
      'diesel': 4400,
      'pdiesel': 6100
    };

    function updateStockUI(key) {
      const vol = currentVolumes[key];
      const cap = capacities[key];
      const pct = Math.round((vol / cap) * 100);

      document.getElementById(`vol-${key}`).textContent = `${vol.toLocaleString()} L`;
      
      const bar = document.getElementById(`bar-${key}`);
      const badge = document.getElementById(`badge-${key}`);
      
      bar.style.width = `${pct}%`;
      bar.parentElement.previousElementSibling.textContent = `Capacity: ${cap.toLocaleString()} L (${pct}%)`;

      if (pct > 50) {
        bar.className = 'progress-bar-custom good';
        badge.className = 'badge-stock good';
        badge.innerHTML = '<i class="fa-solid fa-circle-check"></i> Optimal';
      } else if (pct > 25) {
        bar.className = 'progress-bar-custom warn';
        badge.className = 'badge-stock warn';
        badge.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Moderate';
      } else {
        bar.className = 'progress-bar-custom danger';
        badge.className = 'badge-stock danger';
        badge.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Low Stock';
      }
    }

    // 4. Record Delivery Modal Logic
    const deliveryModal = document.getElementById('delivery-modal');
    const openDeliveryBtn = document.getElementById('open-delivery-modal');
    const closeDeliveryBtn = document.getElementById('close-delivery-modal');
    const cancelDeliveryBtn = document.getElementById('cancel-delivery-modal');
    const deliveryForm = document.getElementById('delivery-form');

    function openDelivery() { deliveryModal.classList.add('active'); }
    function closeDelivery() { deliveryModal.classList.remove('active'); deliveryForm.reset(); }

    openDeliveryBtn.addEventListener('click', openDelivery);
    closeDeliveryBtn.addEventListener('click', closeDelivery);
    cancelDeliveryBtn.addEventListener('click', closeDelivery);

    deliveryForm.addEventListener('submit', (e) => {
      e.preventDefault();
      const fuelKey = document.getElementById('del-fuel').value;
      const volume = parseInt(document.getElementById('del-volume').value, 10);
      const supplier = document.getElementById('del-supplier').value;

      // Update volume safely based on max capacity
      currentVolumes[fuelKey] = Math.min(capacities[fuelKey], currentVolumes[fuelKey] + volume);
      updateStockUI(fuelKey);

      // Add record row to Deliveries Table
      const delTable = document.querySelector('#deliveries-table tbody');
      const newRow = document.createElement('tr');
      const fuelNames = { '92': 'Petrol 92', '95': 'Petrol 95', 'diesel': 'Diesel', 'pdiesel': 'Premium Diesel' };
      const randomDelId = 'DEL-' + Math.floor(8000 + Math.random() * 1000);

      newRow.innerHTML = `
        <td class="fw-bold text-white">${randomDelId}</td>
        <td>${fuelNames[fuelKey]}</td>
        <td class="text-success fw-bold">+${volume.toLocaleString()} L</td>
        <td>${supplier}</td>
        <td>Just Now</td>
        <td><span class="badge-delivery completed"><span class="d"></span> Completed</span></td>
      `;
      delTable.insertBefore(newRow, delTable.firstChild);

      closeDelivery();
    });

    // 5. Edit Price Modal Logic
    const priceModal = document.getElementById('price-modal');
    const closePriceBtn = document.getElementById('close-price-modal');
    const cancelPriceBtn = document.getElementById('cancel-price-modal');
    const priceForm = document.getElementById('price-form');

    function closePrice() { priceModal.classList.remove('active'); priceForm.reset(); }

    document.querySelectorAll('.edit-price-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const fuelKey = btn.getAttribute('data-fuel');
        document.getElementById('edit-fuel-key').value = fuelKey;
        
        const currentPriceText = document.getElementById(`price-${fuelKey}`).textContent;
        document.getElementById('new-price-input').value = currentPriceText.replace(/[^0-9]/g, '');
        
        priceModal.classList.add('active');
      });
    });

    closePriceBtn.addEventListener('click', closePrice);
    cancelPriceBtn.addEventListener('click', closePrice);

    priceForm.addEventListener('submit', (e) => {
      e.preventDefault();
      const fuelKey = document.getElementById('edit-fuel-key').value;
      const newPrice = parseInt(document.getElementById('new-price-input').value, 10);

      if (newPrice) {
        document.getElementById(`price-${fuelKey}`).textContent = `${newPrice.toLocaleString()} Ks/L`;
      }
      closePrice();
    });
  });
</script>
</body>
</html>