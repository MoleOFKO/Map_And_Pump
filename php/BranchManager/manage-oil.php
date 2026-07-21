<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MAP & PUMP — Manage Oil Stock</title>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="icon" type="image/png" href="../../images/Logo.png">
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
    border-color: rgba(251,191,36,0.25);
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
    background:linear-gradient(135deg, rgba(251,191,36,0.16), rgba(29,78,216,0.08)); 
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
  .topbar-right{display:flex; align-items:center; gap:16px;}
  .bell{
    width:36px; height:36px; border-radius:50%; background:var(--navy-800); display:flex; align-items:center; 
    justify-content:center; border:1px solid rgba(255,255,255,0.08); position:relative; font-size:15px; cursor:pointer;
    transition: transform 0.2s, background-color 0.2s;
  }
  .bell:hover{
    background: var(--navy-700);
    transform: scale(1.05);
  }
  .bell .ping{position:absolute; top:6px; right:7px; width:7px; height:7px; border-radius:50%; background:var(--amber-400); box-shadow:0 0 0 2px var(--navy-800);}
  .avatar-chip{display:flex; align-items:center; gap:10px; font-size:13px; cursor: pointer; padding: 4px 8px; border-radius: 99px; transition: background 0.2s;}
  .avatar-chip:hover{background: rgba(255,255,255,0.05);}
  .avatar-circle{width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,var(--sky-400),var(--blue-700)); display:flex; align-items:center; justify-content:center; font-weight:700; font-size:13px; font-family:'Space Grotesk';}

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

  .page-head{display:flex; align-items:center; justify-content:space-between; margin-bottom:26px; flex-wrap:wrap; gap:14px;}
  .page-head h2{font-size:20px; font-weight:700;}
  .page-head p{font-size:13px; color:var(--slate-400); margin-top:4px;}
  
  .btn-primary{
    background:linear-gradient(135deg, var(--amber-400), #d97706); color:#2b1704; font-weight:700; font-size:13.5px; 
    padding:11px 18px; border-radius:11px; border:none; cursor:pointer; display:inline-flex; align-items:center; gap:8px; 
    box-shadow:0 10px 24px -10px rgba(251,191,36,0.5);
    transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.25s;
  }
  .btn-primary:hover{
    transform: translateY(-2px);
    box-shadow:0 12px 28px -8px rgba(251,191,36,0.65);
  }

  /* ---------- Fuel Tanks Grid ---------- */
  .tank-grid{display:grid; grid-template-columns:repeat(3,1fr); gap:18px; margin-bottom:34px;}
  @media (max-width:980px){.tank-grid{grid-template-columns:1fr;}}
  
  .tank{
    background:linear-gradient(160deg, var(--navy-800), var(--navy-900)); border:1px solid rgba(255,255,255,0.07); 
    border-radius:18px; padding:22px; transition: all 0.4s cubic-bezier(0.2, 0.8, 0.2, 1);
  }
  .tank.disabled-tank {
    opacity: 0.35;
    filter: grayscale(0.5);
    transform: scale(0.98);
    pointer-events: none;
  }
  .tank:hover:not(.disabled-tank){
    transform: translateY(-4px);
    border-color: rgba(251,191,36,0.25);
    box-shadow: 0 12px 30px -15px rgba(0,0,0,0.5);
  }
  .tank .top{display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:16px;}
  .tank .fuel-name{font-weight:700; font-size:15.5px;}
  .tank .cap{font-size:11.5px; color:var(--slate-400); margin-top:2px;}
  .tank .pct{font-family:'Space Grotesk'; font-size:26px; font-weight:700; transition: color 0.3s;}
  
  .gauge-track{height:10px; border-radius:999px; background:rgba(255,255,255,0.06); overflow:hidden; margin-bottom:10px;}
  .gauge-track i{display:block; height:100%; border-radius:999px; transition: width 0.8s cubic-bezier(0.34, 1.56, 0.64, 1), background-color 0.4s;}
  
  .tank .liters{font-size:12.5px; color:var(--slate-300); display:flex; justify-content:space-between;}
  
  .tank-flag{display:inline-flex; align-items:center; gap:6px; font-size:11px; font-weight:700; padding:4px 10px; border-radius:999px; margin-top:12px; transition: all 0.3s;}
  .tank-flag.ok{background:rgba(52,211,153,0.14); color:var(--mint-400);}
  .tank-flag.low{background:rgba(248,113,113,0.14); color:var(--red-400); animation: pulseAlert 2s infinite;}
  .tank-flag.mid{background:rgba(251,191,36,0.14); color:var(--amber-300);}
  .tank-flag.inactive{background:rgba(139,150,173,0.14); color:var(--slate-400);}

  @keyframes pulseAlert {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.7; }
  }

  .section-title{display:flex; align-items:center; justify-content:space-between; margin-bottom:16px;}
  .section-title h3{font-size:15.5px; font-weight:700;}

  /* ---------- Tables & Styling ---------- */
  .panel{background:linear-gradient(160deg, var(--navy-800), var(--navy-900)); border:1px solid rgba(255,255,255,0.07); border-radius:18px; overflow:hidden; margin-bottom:30px;}
  table{width:100%; border-collapse:collapse;}
  thead th{text-align:left; font-size:11.5px; color:var(--slate-400); text-transform:uppercase; letter-spacing:0.4px; padding:14px 18px; border-bottom:1px solid rgba(255,255,255,0.07);}
  
  tbody tr { transition: opacity 0.3s ease, transform 0.3s ease, background-color 0.2s ease; }
  tbody td{padding:14px 18px; font-size:13.5px; border-bottom:1px solid rgba(255,255,255,0.05);}
  tbody tr:last-child td{border-bottom:none;}
  tbody tr:hover{background:rgba(255,255,255,0.02);}
  
  .fuel-tag{font-size:11px; font-weight:700; padding:4px 10px; border-radius:999px;}
  .fuel-tag.p92{background:rgba(56,189,248,0.14); color:var(--sky-400);}
  .fuel-tag.p95{background:rgba(52,211,153,0.14); color:var(--mint-400);}
  .fuel-tag.dsl{background:rgba(251,191,36,0.14); color:var(--amber-300);}

  /* ---------- Usage Chart ---------- */
  .weekly{background:linear-gradient(160deg, var(--navy-800), var(--navy-900)); border:1px solid rgba(255,255,255,0.07); border-radius:18px; padding:22px;}
  .bars{display:flex; align-items:flex-end; gap:14px; height:150px; margin-top:20px;}
  .bar-col{flex:1; display:flex; flex-direction:column; align-items:center; justify-content:flex-end; height:100%; gap:8px;}
  .bar-col i{
    width:100%; max-width:34px; border-radius:6px 6px 2px 2px; 
    background:linear-gradient(180deg, var(--sky-400), var(--blue-700));
    transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1), filter 0.2s;
    cursor: pointer;
  }
  .bar-col:hover i{
    transform: scaleY(1.04);
    filter: brightness(1.15) drop-shadow(0 0 8px rgba(56,189,248,0.4));
  }
  .bar-col span{font-size:11px; color:var(--slate-400);}

  /* ---------- Tabs ---------- */
  .tabs{display:flex; gap:8px; margin-bottom:22px; border-bottom:1px solid rgba(255,255,255,0.08); flex-wrap:wrap;}
  .tab-btn{
    background:none; border:none; color:var(--slate-400); font-family:inherit; font-size:13.5px; font-weight:600; 
    padding:11px 4px; cursor:pointer; position:relative; margin-right:22px;
    transition: color 0.25s ease;
  }
  .tab-btn:hover{color: var(--slate-50);}
  .tab-btn.active{color:var(--slate-50);}
  .tab-btn.active::after{
    content:""; position:absolute; left:0; right:0; bottom:-1px; height:2px; background:var(--amber-400); border-radius:2px;
    animation: slideLine 0.25s ease-out;
  }
  @keyframes slideLine {
    from { transform: scaleX(0); }
    to { transform: scaleX(1); }
  }

  .tab-panel{display:none;}
  .tab-panel.active{
    display:block;
    animation: panelFadeIn 0.3s ease forwards;
  }
  @keyframes panelFadeIn {
    from { opacity: 0; transform: translateY(4px); }
    to { opacity: 1; transform: translateY(0); }
  }

  /* ---------- Fuel Type Active Toggle Rows ---------- */
  .type-row{display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid rgba(255,255,255,0.05); transition: background-color 0.2s;}
  .type-row:hover{background-color: rgba(255,255,255,0.015);}
  .type-row:last-child{border-bottom:none;}
  .type-row .info{display:flex; align-items:center; gap:14px;}
  .type-row .swatch{width:12px; height:36px; border-radius:4px;}
  .type-row b{font-size:14px; display:block;}
  .type-row span{font-size:11.5px; color:var(--slate-400);}
  
  .toggle{position:relative; width:44px; height:24px; border-radius:999px; background:rgba(255,255,255,0.1); cursor:pointer; border:none; flex-shrink:0;}
  .toggle::after{content:""; position:absolute; top:3px; left:3px; width:18px; height:18px; border-radius:50%; background:var(--slate-400); transition:all .2s;}
  .toggle.on{background:rgba(52,211,153,0.28);}
  .toggle.on::after{left:23px; background:var(--mint-400);}

  /* ---------- Dynamic Overlay Modal CSS ---------- */
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
  .form-control-custom:focus { border-color: var(--amber-400); }
  
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
  <aside class="sidebar">
  <!--  <div class="side-brand">
      <svg viewBox="0 0 100 120" fill="none">
        <defs><linearGradient id="g1" x1="0" y1="0" x2="100" y2="120">
          <stop offset="0" stop-color="#38bdf8"/><stop offset="1" stop-color="#1d4ed8"/>
        </linearGradient></defs>
        <path d="M50 4 L92 18 L92 54 C92 82 74 100 50 116 C26 100 8 82 8 54 L8 18 Z" fill="url(#g1)"/>
        <circle cx="48" cy="52" r="24" stroke="white" stroke-width="5" fill="none"/>
        <path d="M36 52 L45 62 L64 40" stroke="white" stroke-width="6" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
      </svg>
        MAP & PUMP
    </div> -->
    <a href="dashboard.php" class="text-decoration-none d-flex align-items-center gap-2 text-white fw-bold padding" style="padding: 6px 8px 0;">
      <img src="../../images/Logo.png" alt="Logo" class="nav-logo">
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

  <div class="main">
    <div class="topbar">
      <div>
        <h1>Manage Oil Stock</h1>
        <div class="date" id="live-clock">Wednesday · July 9, 2026</div>
      </div>
      <div class="topbar-right">
        <div class="bell" id="notif-bell"><i class="fa-regular fa-bell"></i><span class="ping"></span></div>
        <div class="avatar-chip">
          <div class="avatar-circle"><a href="edit-profile.php">Fe</a></div>
          <div><b><a href="edit-profile.php">Felix</a></b></div>
        </div>
      </div>
    </div>

    <div class="content">
      <div class="page-head">
        <div>
          <h2>Fuel Stock</h2>
          <p>Manage stock levels and sales availability by fuel type</p>
        </div>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
          <button class="btn-secondary-custom" id="open-limit-modal"><i class="fa-solid fa-gauge-high"></i> Manage Oil Limit</button>
          <button class="btn-primary" id="open-delivery-modal"><i class="fa-solid fa-plus"></i> Add Delivery Record</button>
        </div>
      </div>

      <div class="tabs">
        <button class="tab-btn active" id="tabbtn-amount" onclick="showTab('amount')">Manage Stock Levels</button>
        <button class="tab-btn" id="tabbtn-types" onclick="showTab('types')">Active / Disable Fuel Types</button>
      </div>

      <!-- TAB 1: MANAGE STOCK LEVELS -->
      <div class="tab-panel active" id="tab-amount">
        <div class="tank-grid">
          <!-- Petrol 92 Tank -->
          <div class="tank" id="tank-p92" data-capacity="15000">
            <div class="top">
              <div><div class="fuel-name">Petrol 92</div><div class="cap">Tank Capacity — 15,000 L</div></div>
              <div class="pct" id="pct-p92" style="color:var(--mint-400)">74%</div>
            </div>
            <div class="gauge-track"><i id="gauge-p92" style="width:74%; background:var(--mint-400);"></i></div>
            <div class="liters">
              <span id="rem-p92">11,100 L remaining</span>
              <span id="used-p92">3,900 L used</span>
            </div>
            <span class="tank-flag ok" id="flag-p92"><i class="fa-solid fa-check"></i> Stock sufficient</span>
          </div>

          <!-- Petrol 95 Tank -->
          <div class="tank" id="tank-p95" data-capacity="10000">
            <div class="top">
              <div><div class="fuel-name">Petrol 95</div><div class="cap">Tank Capacity — 10,000 L</div></div>
              <div class="pct" id="pct-p95" style="color:var(--amber-300)">48%</div>
            </div>
            <div class="gauge-track"><i id="gauge-p95" style="width:48%; background:var(--amber-400);"></i></div>
            <div class="liters">
              <span id="rem-p95">4,800 L remaining</span>
              <span id="used-p95">5,200 L used</span>
            </div>
            <span class="tank-flag mid" id="flag-p95"><i class="fa-solid fa-circle-half-stroke"></i> ~4 days remaining</span>
          </div>

          <!-- Diesel Tank -->
          <div class="tank" id="tank-dsl" data-capacity="12000">
            <div class="top">
              <div><div class="fuel-name">Diesel</div><div class="cap">Tank Capacity — 12,000 L</div></div>
              <div class="pct" id="pct-dsl" style="color:var(--red-400)">22%</div>
            </div>
            <div class="gauge-track"><i id="gauge-dsl" style="width:22%; background:var(--red-400);"></i></div>
            <div class="liters">
              <span id="rem-dsl">2,640 L remaining</span>
              <span id="used-dsl">9,360 L used</span>
            </div>
            <span class="tank-flag low" id="flag-dsl"><i class="fa-solid fa-triangle-exclamation"></i> Stock low — delivery needed</span>
          </div>
        </div>

        <div class="section-title"><h3></h3></div>
        <div class="panel">
          <table id="delivery-table">
            <thead><tr><th>Date</th><th>Fuel</th><th>Quantity (Liters)</th><th>Supplier</th><th>Received By</th></tr></thead>
            <tbody>
              <tr data-fuel="Petrol 92"><td>Jul 9</td><td><span class="fuel-tag p92">Petrol 92</span></td><td>8,000 L</td><td>MPPE Distribution</td><td>Leon</td></tr>
              <tr data-fuel="Petrol 95"><td>Jul 7</td><td><span class="fuel-tag p95">Petrol 95</span></td><td>5,000 L</td><td>Puma Energy</td><td>Xiao</td></tr>
              <tr data-fuel="Diesel"><td>Jul 5</td><td><span class="fuel-tag dsl">Diesel</span></td><td>6,500 L</td><td>MPPE Distribution</td><td>Thiago</td></tr>
              <tr data-fuel="Petrol 92"><td>Jul 2</td><td><span class="fuel-tag p92">Petrol 92</span></td><td>7,500 L</td><td>Max Energy</td><td>Felix</td></tr>
            </tbody>
          </table>
        </div>

        <div class="section-title"><h3>Weekly Usage (Petrol 92)</h3></div>
        <div class="weekly">
          <div class="bars">
            <div class="bar-col" title="Daily Consumed: 2,600L"><i style="height:52%;"></i><span>Mon</span></div>
            <div class="bar-col" title="Daily Consumed: 3,200L"><i style="height:64%;"></i><span>Tue</span></div>
            <div class="bar-col" title="Daily Consumed: 2,400L"><i style="height:48%;"></i><span>Wed</span></div>
            <div class="bar-col" title="Daily Consumed: 3,550L"><i style="height:71%;"></i><span>Thu</span></div>
            <div class="bar-col" title="Daily Consumed: 4,000L"><i style="height:80%;"></i><span>Fri</span></div>
            <div class="bar-col" title="Daily Consumed: 4,750L"><i style="height:95%; background:linear-gradient(180deg, var(--amber-300), #d97706);"></i><span>Sat</span></div>
            <div class="bar-col" title="Daily Consumed: 4,400L"><i style="height:88%; background:linear-gradient(180deg, var(--amber-300), #d97706);"></i><span>Sun</span></div>
          </div>
        </div>
      </div>

      <!-- TAB 2: ACTIVE / DISABLE FUEL TYPES -->
      <div class="tab-panel" id="tab-types">
        <div class="panel">
          <div class="type-row" data-tank-id="tank-p92">
            <div class="info">
              <div class="swatch" style="background:var(--sky-400);"></div>
              <div><b>Petrol 92</b><span class="rem-type-lbl">Current stock — 11,100 L</span></div>
            </div>
            <button class="toggle on" data-target="tank-p92"></button>
          </div>
          <div class="type-row" data-tank-id="tank-p95">
            <div class="info">
              <div class="swatch" style="background:var(--mint-400);"></div>
              <div><b>Petrol 95</b><span class="rem-type-lbl">Current stock — 4,800 L</span></div>
            </div>
            <button class="toggle on" data-target="tank-p95"></button>
          </div>
          <div class="type-row" data-tank-id="tank-dsl">
            <div class="info">
              <div class="swatch" style="background:var(--amber-400);"></div>
              <div><b>Diesel</b><span class="rem-type-lbl">Current stock — 2,640 L</span></div>
            </div>
            <button class="toggle on" data-target="tank-dsl"></button>
          </div>
          <div class="type-row">
            <div class="info">
              <div class="swatch" style="background:var(--slate-400);"></div>
              <div><b>Premium Diesel</b><span>Not yet available at this branch</span></div>
            </div>
            <button class="toggle" disabled></button>
          </div>
        </div>
      </div>

      <div class="page-foot">© 2026 MAP & PUMP | All Right Reserved</div>
    </div>
  </div>
</div>

<!-- ---------- Manage Oil Limit Modal (extends Manage Oil) ---------- -->
<div class="modal-overlay" id="limit-modal">
  <div class="modal-card-custom">
    <div class="modal-header-custom">
      <h3>Manage Oil Limit</h3>
      <button class="modal-close" id="limit-modal-close-btn">&times;</button>
    </div>
    <p style="font-size:12.5px; color:var(--slate-400); margin:-8px 0 18px; line-height:1.6;">
      Set the low-stock alert threshold for each fuel type. A tank flags "Low Stock" once its level falls below this percentage.
    </p>
    <form id="limit-form">
      <div class="form-group">
        <label for="limit-p92">Petrol 92 — Alert Threshold (%)</label>
        <input type="number" id="limit-p92" class="form-control-custom" min="5" max="80" value="25" required>
      </div>
      <div class="form-group">
        <label for="limit-p95">Petrol 95 — Alert Threshold (%)</label>
        <input type="number" id="limit-p95" class="form-control-custom" min="5" max="80" value="25" required>
      </div>
      <div class="form-group">
        <label for="limit-dsl">Diesel — Alert Threshold (%)</label>
        <input type="number" id="limit-dsl" class="form-control-custom" min="5" max="80" value="30" required>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn-secondary-custom" id="limit-modal-cancel-btn">Cancel</button>
        <button type="submit" class="btn-primary" style="box-shadow: none; padding: 10px 18px;">Save Limits</button>
      </div>
    </form>
  </div>
</div>

<!-- ---------- Add Delivery Record Modal ---------- -->
<div class="modal-overlay" id="delivery-modal">
  <div class="modal-card-custom">
    <div class="modal-header-custom">
      <h3>Add Fuel Delivery Record</h3>
      <button class="modal-close" id="modal-close-btn">&times;</button>
    </div>
    <form id="delivery-form">
      <div class="form-group">
        <label for="fuel-type-input">Fuel Grade</label>
        <select id="fuel-type-input" class="form-control-custom">
          <option value="p92">Petrol 92</option>
          <option value="p95">Petrol 95</option>
          <option value="dsl">Diesel</option>
        </select>
      </div>
      <div class="form-group">
        <label for="quantity-input">Quantity (Liters)</label>
        <input type="number" id="quantity-input" class="form-control-custom" required min="1" max="15000" placeholder="e.g. 5000">
      </div>
      <div class="form-group">
        <label for="supplier-input">Supplier</label>
        <input type="text" id="supplier-input" class="form-control-custom" required placeholder="e.g. Puma Energy">
      </div>
      <div class="form-group">
        <label for="handler-input">Received By</label>
        <input type="text" id="handler-input" class="form-control-custom" required placeholder="e.g. Felix">
      </div>
      <div class="modal-actions">
        <button type="button" class="btn-secondary-custom" id="modal-cancel-btn">Cancel</button>
        <button type="submit" class="btn-primary" style="box-shadow: none; padding: 10px 18px;">Submit Record</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Tab Switching Controller
  function showTab(name){
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    document.getElementById('tabbtn-' + name).classList.add('active');
  }

  document.addEventListener('DOMContentLoaded', () => {

    // 1. Digital Clock Component
    function updateClock() {
      const now = new Date();
      const options = { weekday: 'long', month: 'short', day: 'numeric', year: 'numeric' };
      const dateString = now.toLocaleDateString('en-US', options);
      const timeString = now.toLocaleTimeString('en-US', { hour12: true, hour: '2-digit', minute: '2-digit', second: '2-digit' });
      document.getElementById('live-clock').textContent = `${dateString} · ${timeString}`;
    }
    setInterval(updateClock, 1000);
    updateClock();

    // Notification bell close out micro-interaction
    const bellBtn = document.getElementById('notif-bell');
    bellBtn.addEventListener('click', () => {
      const ping = bellBtn.querySelector('.ping');
      if (ping) {
        ping.style.transform = 'scale(0)';
        setTimeout(() => ping.remove(), 200);
      }
    });

    // 2. Local App Database Simulation (Tracks absolute levels)
    const stockDB = {
      p92: { capacity: 15000, remaining: 11100 },
      p95: { capacity: 10000, remaining: 4800 },
      dsl: { capacity: 12000, remaining: 2640 }
    };

    // Manage Oil Limit — per-fuel low-stock alert thresholds (%)
    let oilLimits = { p92: 25, p95: 25, dsl: 30 };

    // Reflect database stats visually in layout elements
    function syncTanksUI() {
      ['p92', 'p95', 'dsl'].forEach(key => {
        const item = stockDB[key];
        const used = item.capacity - item.remaining;
        const percent = Math.min(100, Math.round((item.remaining / item.capacity) * 100));

        // Sync main dials and text
        document.getElementById(`pct-${key}`).textContent = `${percent}%`;
        document.getElementById(`gauge-${key}`).style.width = `${percent}%`;
        document.getElementById(`rem-${key}`).textContent = `${item.remaining.toLocaleString()} L remaining`;
        document.getElementById(`used-${key}`).textContent = `${used.toLocaleString()} L used`;

        // Sync Second Tab Description Subtext
        const typeRow = document.querySelector(`.type-row[data-tank-id="tank-${key}"] .rem-type-lbl`);
        if(typeRow) typeRow.textContent = `Current stock — ${item.remaining.toLocaleString()} L`;

        // Style updates dynamically based on threshold flags
        const flagEl = document.getElementById(`flag-${key}`);
        const gaugeEl = document.getElementById(`gauge-${key}`);
        const pctEl = document.getElementById(`pct-${key}`);

        const lowLimit = oilLimits[key];
        const midLimit = lowLimit + 30;
        if (percent > midLimit) {
          flagEl.className = "tank-flag ok";
          flagEl.innerHTML = `<i class="fa-solid fa-check"></i> Stock sufficient`;
          gaugeEl.style.backgroundColor = "var(--mint-400)";
          pctEl.style.color = "var(--mint-400)";
        } else if (percent > lowLimit) {
          flagEl.className = "tank-flag mid";
          flagEl.innerHTML = `<i class="fa-solid fa-circle-half-stroke"></i> ~${Math.ceil((item.remaining / 1200))} days remaining`;
          gaugeEl.style.backgroundColor = "var(--amber-400)";
          pctEl.style.color = "var(--amber-300)";
        } else {
          flagEl.className = "tank-flag low";
          flagEl.innerHTML = `<i class="fa-solid fa-triangle-exclamation"></i> Stock low (below ${lowLimit}% limit) — delivery needed`;
          gaugeEl.style.backgroundColor = "var(--red-400)";
          pctEl.style.color = "var(--red-400)";
        }
      });
    }

    // 2b. Manage Oil Limit Modal (extends Manage Oil)
    const limitModal = document.getElementById('limit-modal');
    const limitForm = document.getElementById('limit-form');
    const openLimitBtn = document.getElementById('open-limit-modal');
    const limitCloseBtn = document.getElementById('limit-modal-close-btn');
    const limitCancelBtn = document.getElementById('limit-modal-cancel-btn');

    openLimitBtn.addEventListener('click', () => {
      document.getElementById('limit-p92').value = oilLimits.p92;
      document.getElementById('limit-p95').value = oilLimits.p95;
      document.getElementById('limit-dsl').value = oilLimits.dsl;
      limitModal.classList.add('active');
    });
    const closeLimitModal = () => limitModal.classList.remove('active');
    limitCloseBtn.addEventListener('click', closeLimitModal);
    limitCancelBtn.addEventListener('click', closeLimitModal);
    limitModal.addEventListener('click', (e) => { if (e.target === limitModal) closeLimitModal(); });

    limitForm.addEventListener('submit', (e) => {
      e.preventDefault();
      oilLimits = {
        p92: parseInt(document.getElementById('limit-p92').value),
        p95: parseInt(document.getElementById('limit-p95').value),
        dsl: parseInt(document.getElementById('limit-dsl').value)
      };
      syncTanksUI();
      closeLimitModal();
    });

    // 3. Modal Overlay Dialog Management
    const modal = document.getElementById('delivery-modal');
    const form = document.getElementById('delivery-form');
    const openBtn = document.getElementById('open-delivery-modal');
    const closeBtn = document.getElementById('modal-close-btn');
    const cancelBtn = document.getElementById('modal-cancel-btn');

    openBtn.addEventListener('click', () => {
      form.reset();
      modal.classList.add('active');
    });

    const closeModal = () => modal.classList.remove('active');
    closeBtn.addEventListener('click', closeModal);
    cancelBtn.addEventListener('click', closeModal);

    // 4. Record Submission Handling
    form.addEventListener('submit', (e) => {
      e.preventDefault();

      const fuelKey = document.getElementById('fuel-type-input').value;
      const quantityVal = parseInt(document.getElementById('quantity-input').value);
      const supplierVal = document.getElementById('supplier-input').value;
      const handlerVal = document.getElementById('handler-input').value;

      // Restrict delivery spillages beyond maximum tank capacities
      const spec = stockDB[fuelKey];
      if (spec.remaining + quantityVal > spec.capacity) {
        alert(`Warning: This delivery of ${quantityVal} L exceeds the maximum capacity limits of this tank. Current available space is only ${spec.capacity - spec.remaining} L.`);
        return;
      }

      // Add to simulated Database
      spec.remaining += quantityVal;

      // Add record dynamically to the directory table
      const fuelLabels = {
        p92: { title: "Petrol 92", badge: "p92" },
        p95: { title: "Petrol 95", badge: "p95" },
        dsl: { title: "Diesel", badge: "dsl" }
      };

      const tableBody = document.querySelector('#delivery-table tbody');
      const newRow = document.createElement('tr');
      newRow.setAttribute('data-fuel', fuelLabels[fuelKey].title);
      
      const today = new Date();
      const monthNames = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
      const formattedDate = `${monthNames[today.getMonth()]} ${today.getDate()}`;

      newRow.innerHTML = `
        <td>${formattedDate}</td>
        <td><span class="fuel-tag ${fuelLabels[fuelKey].badge}">${fuelLabels[fuelKey].title}</span></td>
        <td>${quantityVal.toLocaleString()} L</td>
        <td>${supplierVal}</td>
        <td>${handlerVal}</td>
      `;

      tableBody.insertBefore(newRow, tableBody.firstChild);

      // Trigger recalculation logic with visuals
      syncTanksUI();
      closeModal();
    });

    // 5. Dynamic active/disable switch controls on fuel profiles
    const toggleButtons = document.querySelectorAll('.toggle:not([disabled])');
    toggleButtons.forEach(btn => {
      btn.addEventListener('click', () => {
        btn.classList.toggle('on');
        const targetId = btn.getAttribute('data-target');
        const tankCard = document.getElementById(targetId);

        if (tankCard) {
          if (!btn.classList.contains('on')) {
            tankCard.classList.add('disabled-tank');
            const flag = tankCard.querySelector('.tank-flag');
            flag.className = "tank-flag inactive";
            flag.innerHTML = `<i class="fa-solid fa-ban"></i> Out of Service`;
          } else {
            tankCard.classList.remove('disabled-tank');
            syncTanksUI(); // recalculate true stats
          }
        }
      });
    });
  });
</script>
</body>
</html>