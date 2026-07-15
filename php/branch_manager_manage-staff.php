<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PetroQ — Manage Staff</title>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

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
  @keyframes layoutFadeIn {
    to { opacity: 1; transform: translateY(0); }
  }

  .page-head{display:flex; align-items:center; justify-content:space-between; margin-bottom:22px; flex-wrap:wrap; gap:14px;}
  .page-head h2{font-size:20px; font-weight:700;}
  .page-head p{font-size:13px; color:var(--slate-400); margin-top:4px;}
  
  .btn-primary{
    background:linear-gradient(135deg, var(--mint-400), #10b981); color:#06251c; font-weight:700; font-size:13.5px;
    padding:11px 18px; border-radius:11px; border:none; cursor:pointer; display:inline-flex; align-items:center; gap:8px;
    box-shadow:0 10px 24px -10px rgba(52,211,153,0.55);
    transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.25s;
  }
  .btn-primary:hover{
    transform: translateY(-2px);
    box-shadow:0 12px 28px -8px rgba(52,211,153,0.7);
  }

  .stat-strip{display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:26px;}
  @media (max-width:1000px){.stat-strip{grid-template-columns:repeat(2,1fr);}}
  
  .stat{
    background:linear-gradient(160deg, var(--navy-800), var(--navy-900)); border:1px solid rgba(255,255,255,0.07); 
    border-radius:14px; padding:16px 18px;
    transition: transform 0.3s cubic-bezier(0.2, 0.8, 0.2, 1), border-color 0.3s;
  }
  .stat:hover{
    transform: translateY(-3px);
    border-color: rgba(52,211,153,0.25);
  }
  .stat .n{font-family:'Space Grotesk'; font-size:22px; font-weight:700;}
  .stat .l{font-size:11.5px; color:var(--slate-400); margin-top:4px;}

  .toolbar{display:flex; gap:12px; margin-bottom:18px; flex-wrap:wrap; align-items:center;}
  .search{
    flex:1; min-width:220px; background:var(--navy-800); border:1px solid rgba(255,255,255,0.08); border-radius:11px;
    padding:10px 14px; display:flex; align-items:center; gap:9px; font-size:13.5px; color:var(--slate-400);
    transition: border-color 0.25s, box-shadow 0.25s;
  }
  .search:focus-within{
    border-color: rgba(52,211,153,0.4);
    box-shadow: 0 0 0 1px rgba(52,211,153,0.4);
  }
  .search input{background:none; border:none; outline:none; color:var(--slate-50); font-family:inherit; font-size:13.5px; width:100%;}
  
  .chip{
    padding:9px 15px; border-radius:999px; font-size:12.5px; font-weight:600; 
    border:1px solid rgba(255,255,255,0.1); color:var(--slate-300); cursor:pointer;
    transition: all 0.2s ease;
  }
  .chip:hover{background: rgba(255,255,255,0.05); color: var(--slate-50);}
  .chip.active{background:rgba(52,211,153,0.14); border-color:rgba(52,211,153,0.4); color:var(--mint-400);}

  .panel{background:linear-gradient(160deg, var(--navy-800), var(--navy-900)); border:1px solid rgba(255,255,255,0.07); border-radius:18px; overflow:hidden;}
  table{width:100%; border-collapse:collapse;}
  thead th{text-align:left; font-size:11.5px; color:var(--slate-400); text-transform:uppercase; letter-spacing:0.4px; padding:14px 18px; border-bottom:1px solid rgba(255,255,255,0.07);}
  
  tbody tr { transition: opacity 0.2s ease, transform 0.2s ease, background-color 0.2s ease; }
  tbody td{padding:14px 18px; font-size:13.5px; border-bottom:1px solid rgba(255,255,255,0.05);}
  tbody tr:last-child td{border-bottom:none;}
  tbody tr:hover{background:rgba(255,255,255,0.02);}
  
  td.actions a{margin-right:12px; font-weight:600; text-decoration:none; cursor: pointer; transition: opacity 0.2s;}
  td.actions a:hover{opacity: 0.8;}
  td.actions a.danger{color:var(--red-400);}
  
  .staff-name{display:flex; align-items:center; gap:11px;}
  .mini-avatar{
    width:34px; height:34px; border-radius:50%; background:linear-gradient(135deg,var(--sky-400),var(--blue-700)); 
    display:flex; align-items:center; justify-content:center; font-weight:700; font-size:12px; font-family:'Space Grotesk'; flex-shrink:0;
  }
  .staff-name b{display:block; font-size:13.5px;}
  .staff-name span{font-size:11.5px; color:var(--slate-400);}
  .role-pill{font-size:11px; padding:4px 10px; border-radius:999px; background:rgba(255,255,255,0.06); color:var(--slate-300);}
  
  .status-pill{font-size:11px; font-weight:700; padding:4px 10px; border-radius:999px; display:inline-flex; align-items:center; gap:6px; transition: all 0.3s;}
  .status-pill.on{background:rgba(52,211,153,0.14); color:var(--mint-400);}
  .status-pill.off{background:rgba(139,150,173,0.14); color:var(--slate-400);}
  .status-pill.leave{background:rgba(251,191,36,0.14); color:var(--amber-300);}
  .status-pill .d{width:6px; height:6px; border-radius:50%; background:currentColor;}
  
  .tabs{display:flex; gap:8px; margin-bottom:20px; border-bottom:1px solid rgba(255,255,255,0.08); flex-wrap:wrap;}
  .tab-btn{
    background:none; border:none; color:var(--slate-400); font-family:inherit; font-size:13.5px; font-weight:600;
    padding:11px 4px; cursor:pointer; position:relative; margin-right:22px; transition: color 0.25s ease;
  }
  .tab-btn:hover{color: var(--slate-50);}
  .tab-btn.active{color:var(--slate-50);}
  .tab-btn.active::after{
    content:""; position:absolute; left:0; right:0; bottom:-1px; height:2px; background:var(--mint-400); border-radius:2px;
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

  .activity-row{
    display:flex; gap:14px; padding:13px 18px; border-bottom:1px solid rgba(255,255,255,0.05); font-size:13px;
    transition: background 0.2s;
  }
  .activity-row:hover{background: rgba(255,255,255,0.01);}
  .activity-row:last-child{border-bottom:none;}
  .activity-row .t{font-size:11.5px; color:var(--slate-400); min-width:70px;}
  .activity-row b{color:var(--slate-50);}

  /* ---------- Modern Modal CSS ---------- */
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
  <aside class="sidebar">
    <div class="side-brand">
      <svg viewBox="0 0 100 120" fill="none">
        <defs><linearGradient id="g1" x1="0" y1="0" x2="100" y2="120">
          <stop offset="0" stop-color="#38bdf8"/><stop offset="1" stop-color="#1d4ed8"/>
        </linearGradient></defs>
        <path d="M50 4 L92 18 L92 54 C92 82 74 100 50 116 C26 100 8 82 8 54 L8 18 Z" fill="url(#g1)"/>
        <circle cx="48" cy="52" r="24" stroke="white" stroke-width="5" fill="none"/>
        <path d="M36 52 L45 62 L64 40" stroke="white" stroke-width="6" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
      </svg>
      MAP & PUMP
    </div>
    <div class="side-role">Branch Manager Console</div>

    <div class="side-branch">
      <b>Denko - Sanchaung</b>
      <span>Branch ID: YGN-014</span>
    </div>

    <nav class="side-nav">
      <a href="branch_manager_dashboard.php" class="side-link"><span class="ic"><i class="fa-solid fa-house"></i></span> Dashboard</a>
      <a href="branch_manager_manage-staff.php" class="side-link active"><span class="ic"><i class="fa-solid fa-users"></i></span> Manage Staff</a>
      <a href="branch_manager_manage-oil.php" class="side-link"><span class="ic"><i class="fa-solid fa-gas-pump"></i></span> Manage Oil Stock</a>
      <a href="booking-slot.php" class="side-link"><span class="ic"><i class="fa-solid fa-ticket"></i></span> Monitor Booking Slot</a>
      <a href="edit-profile.php" class="side-link"><span class="ic"><i class="fa-solid fa-gear"></i></span> Edit Profile</a>
    </nav>

    <a href="branch_manager_login.php" class="side-logout"><span class="ic"><i class="fa-solid fa-right-from-bracket"></i></span> Log Out</a>
  </aside>

  <div class="main">
    <div class="topbar">
      <div>
        <h1>Manage Staff</h1>
        <div class="date" id="live-clock">Wednesday · July 9, 2026</div>
      </div>
      <div class="topbar-right">
        <div class="bell" id="notif-bell"><i class="fa-regular fa-bell"></i><span class="ping"></span></div>
        <div class="avatar-chip">
          <div class="avatar-circle">Fe</div>
          <div><b>Felix</b></div>
        </div>
      </div>
    </div>

    <div class="content">
      <div class="page-head">
        <div>
          <h2>Staff Directory</h2>
          <p>Create staff accounts, activate/disable access, and review activity logs</p>
        </div>
        <button class="btn-primary" id="open-add-btn"><i class="fa-solid fa-plus"></i> Add New Staff</button>
      </div>

      <div class="stat-strip">
        <div class="stat"><div class="n" id="stat-total">9</div><div class="l">Total Staff</div></div>
        <div class="stat"><div class="n" id="stat-active" style="color:var(--mint-400)">7</div><div class="l">Active Account</div></div>
        <div class="stat"><div class="n" id="stat-disabled" style="color:var(--slate-400)">2</div><div class="l">Disabled Account</div></div>
        <div class="stat"><div class="n">3</div><div class="l">Shift Groups</div></div>
      </div>

      <div class="tabs">
        <button class="tab-btn active" id="tabbtn-create" onclick="showTab('create')">Create / Delete Staff Account</button>
        <button class="tab-btn" id="tabbtn-status" onclick="showTab('status')">Active / Disable Staff Account</button>
        <button class="tab-btn" id="tabbtn-activity" onclick="showTab('activity')">View Staff Activity</button>
      </div>

      <!-- TAB 1: CREATE / DELETE STAFF -->
      <div class="tab-panel active" id="tab-create">
        <div class="toolbar">
          <div class="search">
            <i class="fa-solid fa-magnifying-glass"></i> 
            <input type="text" id="directory-search" placeholder="Search by name or Staff ID...">
          </div>
          <span class="chip active" data-shift="All">All</span>
          <span class="chip" data-shift="Morning">Morning</span>
          <span class="chip" data-shift="Afternoon">Afternoon</span>
          <span class="chip" data-shift="Night">Night</span>
        </div>
        <div class="panel">
          <table id="staff-directory-table">
            <thead><tr><th>Staff</th><th>Role</th><th>Shift</th><th>Status</th><th>Contact</th><th></th></tr></thead>
            <tbody>
              <tr data-shift="Morning"><td><div class="staff-name"><div class="mini-avatar">Jo</div><div><b>Joao</b><span>ID: ST-2091</span></div></div></td><td><span class="role-pill">Pump Attendant</span></td><td>Morning (6AM–2PM)</td><td><span class="status-pill on"><span class="d"></span> On Duty</span></td><td>09-751-XXXXXX</td><td class="actions"><a class="edit-action">Edit</a><a class="danger delete-action">Delete</a></td></tr>
              <tr data-shift="Morning"><td><div class="staff-name"><div class="mini-avatar">Le</div><div><b>Leon</b><span>ID: ST-2092</span></div></div></td><td><span class="role-pill">Cashier</span></td><td>Morning (6AM–2PM)</td><td><span class="status-pill on"><span class="d"></span> On Duty</span></td><td>09-799-XXXXXX</td><td class="actions"><a class="edit-action">Edit</a><a class="danger delete-action">Delete</a></td></tr>
              <tr data-shift="Afternoon"><td><div class="staff-name"><div class="mini-avatar">Xi</div><div><b>Xiao</b><span>ID: ST-2093</span></div></div></td><td><span class="role-pill">Pump Attendant</span></td><td>Afternoon (2PM–10PM)</td><td><span class="status-pill off"><span class="d"></span> Off Duty</span></td><td>09-425-XXXXXX</td><td class="actions"><a class="edit-action">Edit</a><a class="danger delete-action">Delete</a></td></tr>
              <tr data-shift="Afternoon"><td><div class="staff-name"><div class="mini-avatar">He</div><div><b>Henry</b><span>ID: ST-2094</span></div></div></td><td><span class="role-pill">Supervisor</span></td><td>Afternoon (2PM–10PM)</td><td><span class="status-pill leave"><span class="d"></span> Leave</span></td><td>09-660-XXXXXX</td><td class="actions"><a class="edit-action">Edit</a><a class="danger delete-action">Delete</a></td></tr>
              <tr data-shift="Night"><td><div class="staff-name"><div class="mini-avatar">Th</div><div><b>Thiago</b><span>ID: ST-2095</span></div></div></td><td><span class="role-pill">Pump Attendant</span></td><td>Night (10PM–6AM)</td><td><span class="status-pill off"><span class="d"></span> Off Duty</span></td><td>09-511-XXXXXX</td><td class="actions"><a class="edit-action">Edit</a><a class="danger delete-action">Delete</a></td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- TAB 2: ACTIVE / DISABLE STAFF -->
      <div class="tab-panel" id="tab-status">
        <div class="panel">
          <table id="status-management-table">
            <thead><tr><th>Staff</th><th>Role</th><th>Account Status</th><th></th></tr></thead>
            <tbody>
              <tr data-id="ST-2091"><td><div class="staff-name"><div class="mini-avatar">Jo</div><div><b>Joao</b><span>ID: ST-2091</span></div></div></td><td><span class="role-pill">Pump Attendant</span></td><td><span class="status-pill on"><span class="d"></span> Active</span></td><td class="actions"><a class="danger status-toggle" data-action="disable">Disable</a></td></tr>
              <tr data-id="ST-2092"><td><div class="staff-name"><div class="mini-avatar">Le<Leg></Leg></div><div><b>Leon</b><span>ID: ST-2092</span></div></div></td><td><span class="role-pill">Cashier</span></td><td><span class="status-pill on"><span class="d"></span> Active</span></td><td class="actions"><a class="danger status-toggle" data-action="disable">Disable</a></td></tr>
              <tr data-id="ST-2093"><td><div class="staff-name"><div class="mini-avatar">Xi</div><div><b>Xiao</b><span>ID: ST-2093</span></div></div></td><td><span class="role-pill">Pump Attendant</span></td><td><span class="status-pill on"><span class="d"></span> Active</span></td><td class="actions"><a class="danger status-toggle" data-action="disable">Disable</a></td></tr>
              <tr data-id="ST-2089"><td><div class="staff-name"><div class="mini-avatar">He</div><div><b>Henry</b><span>ID: ST-2089</span></div></div></td><td><span class="role-pill">Pump Attendant</span></td><td><span class="status-pill off"><span class="d"></span> Disabled</span></td><td class="actions"><a class="status-toggle" style="color:var(--mint-400)" data-action="activate">Activate</a></td></tr>
              <tr data-id="ST-2085"><td><div class="staff-name"><div class="mini-avatar">Th</div><div><b>Thiago</b><span>ID: ST-2085</span></div></div></td><td><span class="role-pill">Cashier</span></td><td><span class="status-pill off"><span class="d"></span> Disabled</span></td><td class="actions"><a class="status-toggle" style="color:var(--mint-400)" data-action="activate">Activate</a></td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- TAB 3: STAFF ACTIVITY LOG -->
      <div class="tab-panel" id="tab-activity">
        <div class="panel">
          <div class="activity-row"><span class="t">11:24 AM</span><span><b>Joao</b> — Started serving Token B-041 at Pump #2</span></div>
          <div class="activity-row"><span class="t">10:58 AM</span><span><b>Leon</b> — Shift check-in (Morning)</span></div>
          <div class="activity-row"><span class="t">10:15 AM</span><span><b>Xiao</b> — Confirmed Petrol 92 delivery record</span></div>
          <div class="activity-row"><span class="t">09:42 AM</span><span><b>Henry</b> — Submitted a leave request</span></div>
          <div class="activity-row"><span class="t">09:05 AM</span><span><b>Thiago</b> — Shift check-in (Morning)</span></div>
          <div class="activity-row"><span class="t">08:40 AM</span><span><b>Felix</b> — Disabled Lu's account</span></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ---------- Add / Edit Staff Modal ---------- -->
<div class="modal-overlay" id="staff-modal">
  <div class="modal-card-custom">
    <div class="modal-header-custom">
      <h3 id="modal-title">Add New Staff Member</h3>
      <button class="modal-close" id="modal-close-btn">&times;</button>
    </div>
    <form id="staff-form">
      <div class="form-group">
        <label for="staff-name-input">Full Name</label>
        <input type="text" id="staff-name-input" class="form-control-custom" required placeholder="e.g. Aung Ko Latt">
      </div>
      <div class="form-group">
        <label for="staff-role-input">Role</label>
        <select id="staff-role-input" class="form-control-custom">
          <option>Pump Attendant</option>
          <option>Cashier</option>
          <option>Supervisor</option>
        </select>
      </div>
      <div class="form-group">
        <label for="staff-shift-input">Shift</label>
        <select id="staff-shift-input" class="form-control-custom">
          <option value="Morning">Morning (6AM–2PM)</option>
          <option value="Afternoon">Afternoon (2PM–10PM)</option>
          <option value="Night">Night (10PM–6AM)</option>
        </select>
      </div>
      <div class="form-group">
        <label for="staff-contact-input">Contact Phone</label>
        <input type="text" id="staff-contact-input" class="form-control-custom" required placeholder="e.g. 09-751-XXXXXX">
      </div>
      <div class="modal-actions">
        <button type="button" class="btn-secondary-custom" id="modal-cancel-btn">Cancel</button>
        <button type="submit" class="btn-primary" style="box-shadow: none; padding: 10px 18px;">Save Staff</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Dynamic Tab Navigating Logic
  function showTab(name){
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    document.getElementById('tabbtn-' + name).classList.add('active');
  }

  document.addEventListener('DOMContentLoaded', () => {
    
    // 1. Live Interactive Digital Clock
    function updateClock() {
      const now = new Date();
      const options = { weekday: 'long', month: 'short', day: 'numeric', year: 'numeric' };
      const dateString = now.toLocaleDateString('en-US', options);
      const timeString = now.toLocaleTimeString('en-US', { hour12: true, hour: '2-digit', minute: '2-digit', second: '2-digit' });
      document.getElementById('live-clock').textContent = `${dateString} · ${timeString}`;
    }
    setInterval(updateClock, 1000);
    updateClock();

    // Remove notification badge on click
    const bellBtn = document.getElementById('notif-bell');
    bellBtn.addEventListener('click', () => {
      const ping = bellBtn.querySelector('.ping');
      if (ping) {
        ping.style.transform = 'scale(0)';
        setTimeout(() => ping.remove(), 200);
      }
    });

    // 2. Client Side Dynamic Search Filtering System
    const searchInput = document.getElementById('directory-search');
    const directoryRows = document.querySelectorAll('#staff-directory-table tbody tr');

    searchInput.addEventListener('input', filterDirectory);

    // 3. Shift Selection Filtering System
    const chips = document.querySelectorAll('.chip');
    let activeShift = 'All';

    chips.forEach(chip => {
      chip.addEventListener('click', () => {
        chips.forEach(c => c.classList.remove('active'));
        chip.classList.add('active');
        activeShift = chip.getAttribute('data-shift');
        filterDirectory();
      });
    });

    function filterDirectory() {
      const query = searchInput.value.toLowerCase().trim();
      directoryRows.forEach(row => {
        const nameAndId = row.querySelector('.staff-name').textContent.toLowerCase();
        const rowShift = row.getAttribute('data-shift');

        const matchesSearch = nameAndId.includes(query);
        const matchesShift = (activeShift === 'All' || rowShift === activeShift);

        if (matchesSearch && matchesShift) {
          row.style.display = '';
          setTimeout(() => { row.style.opacity = '1'; row.style.transform = 'translateY(0)'; }, 20);
        } else {
          row.style.opacity = '0';
          row.style.transform = 'translateY(4px)';
          setTimeout(() => { row.style.display = 'none'; }, 200);
        }
      });
    }

    // 4. Client Side Dynamic Delete Action
    const directoryTable = document.getElementById('staff-directory-table');
    directoryTable.addEventListener('click', (e) => {
      if (e.target.classList.contains('delete-action')) {
        e.preventDefault();
        const row = e.target.closest('tr');
        if (confirm(`Are you sure you want to delete this account?`)) {
          row.style.opacity = '0';
          row.style.transform = 'translateX(-20px)';
          setTimeout(() => {
            row.remove();
            updateTotalMetrics();
          }, 300);
        }
      }
    });

    // 5. Account Activation Status Changer logic
    const statusTable = document.getElementById('status-management-table');
    statusTable.addEventListener('click', (e) => {
      const toggleBtn = e.target.closest('.status-toggle');
      if (toggleBtn) {
        e.preventDefault();
        const row = toggleBtn.closest('tr');
        const pill = row.querySelector('.status-pill');
        const action = toggleBtn.getAttribute('data-action');

        if (action === 'disable') {
          pill.className = 'status-pill off';
          pill.innerHTML = '<span class="d"></span> Disabled';
          toggleBtn.textContent = 'Activate';
          toggleBtn.style.color = 'var(--mint-400)';
          toggleBtn.setAttribute('data-action', 'activate');
          toggleBtn.classList.remove('danger');
        } else {
          pill.className = 'status-pill on';
          pill.innerHTML = '<span class="d"></span> Active';
          toggleBtn.textContent = 'Disable';
          toggleBtn.style.color = '';
          toggleBtn.setAttribute('data-action', 'disable');
          toggleBtn.classList.add('danger');
        }
        updateTotalMetrics();
      }
    });

    function updateTotalMetrics() {
      const totalStaff = document.querySelectorAll('#staff-directory-table tbody tr').length;
      const totalActive = document.querySelectorAll('#status-management-table tbody .status-pill.on').length;
      const totalDisabled = document.querySelectorAll('#status-management-table tbody .status-pill.off').length;
      
      document.getElementById('stat-total').textContent = totalStaff;
      document.getElementById('stat-active').textContent = totalActive;
      document.getElementById('stat-disabled').textContent = totalDisabled;
    }

    // 6. Interactive Form Dialog Logic
    const modal = document.getElementById('staff-modal');
    const form = document.getElementById('staff-form');
    const openAddBtn = document.getElementById('open-add-btn');
    const cancelBtn = document.getElementById('modal-cancel-btn');
    const closeBtn = document.getElementById('modal-close-btn');
    let editTargetRow = null;

    const nameInput = document.getElementById('staff-name-input');
    const roleSelect = document.getElementById('staff-role-input');
    const shiftSelect = document.getElementById('staff-shift-input');
    const contactInput = document.getElementById('staff-contact-input');

    function openModal(isEdit = false, name="", role="", shift="Morning", contact="") {
      if (isEdit) {
        document.getElementById('modal-title').textContent = "Edit Staff Member Details";
        nameInput.value = name;
        roleSelect.value = role;
        shiftSelect.value = shift;
        contactInput.value = contact;
      } else {
        document.getElementById('modal-title').textContent = "Add New Staff Member";
        form.reset();
      }
      modal.classList.add('active');
    }

    function closeModal() {
      modal.classList.remove('active');
      editTargetRow = null;
    }

    openAddBtn.addEventListener('click', () => openModal(false));
    cancelBtn.addEventListener('click', closeModal);
    closeBtn.addEventListener('click', closeModal);

    // Edit actions
    directoryTable.addEventListener('click', (e) => {
      if (e.target.classList.contains('edit-action')) {
        e.preventDefault();
        editTargetRow = e.target.closest('tr');
        const name = editTargetRow.querySelector('.staff-name b').textContent;
        const role = editTargetRow.querySelector('.role-pill').textContent;
        const shiftText = editTargetRow.cells[2].textContent;
        const contact = editTargetRow.cells[4].textContent;

        let shiftVal = 'Morning';
        if (shiftText.includes('Afternoon')) shiftVal = 'Afternoon';
        if (shiftText.includes('Night')) shiftVal = 'Night';

        openModal(true, name, role, shiftVal, contact);
      }
    });

    // Form Submit handling
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const inputName = nameInput.value;
      const inputRole = roleSelect.value;
      const inputShift = shiftSelect.value;
      const inputContact = contactInput.value;
      const avatarLetters = inputName.split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase();

      if (editTargetRow) {
        // Edit flow
        editTargetRow.querySelector('.staff-name b').textContent = inputName;
        editTargetRow.querySelector('.staff-name .mini-avatar').textContent = avatarLetters;
        editTargetRow.querySelector('.role-pill').textContent = inputRole;
        editTargetRow.cells[2].textContent = `${inputShift} (${inputShift === 'Morning' ? '6AM–2PM' : inputShift === 'Afternoon' ? '2PM–10PM' : '10PM–6AM'})`;
        editTargetRow.cells[4].textContent = inputContact;
        editTargetRow.setAttribute('data-shift', inputShift);
      } else {
        // Insert flow
        const randomId = Math.floor(1000 + Math.random() * 9000);
        const newDirRow = document.createElement('tr');
        newDirRow.setAttribute('data-shift', inputShift);
        newDirRow.innerHTML = `
          <td><div class="staff-name"><div class="mini-avatar">${avatarLetters}</div><div><b>${inputName}</b><span>ID: ST-${randomId}</span></div></div></td>
          <td><span class="role-pill">${inputRole}</span></td>
          <td>${inputShift} (${inputShift === 'Morning' ? '6AM–2PM' : inputShift === 'Afternoon' ? '2PM–10PM' : '10PM–6AM'})</td>
          <td><span class="status-pill off"><span class="d"></span> Off Duty</span></td>
          <td>${inputContact}</td>
          <td class="actions"><a class="edit-action">Edit</a><a class="danger delete-action">Delete</a></td>
        `;
        document.querySelector('#staff-directory-table tbody').appendChild(newDirRow);

        // Also add to Status Control view
        const newStatusRow = document.createElement('tr');
        newStatusRow.setAttribute('data-id', `ST-${randomId}`);
        newStatusRow.innerHTML = `
          <td><div class="staff-name"><div class="mini-avatar">${avatarLetters}</div><div><b>${inputName}</b><span>ID: ST-${randomId}</span></div></div></td>
          <td><span class="role-pill">${inputRole}</span></td>
          <td><span class="status-pill on"><span class="d"></span> Active</span></td>
          <td class="actions"><a class="danger status-toggle" data-action="disable">Disable</a></td>
        `;
        document.querySelector('#status-management-table tbody').appendChild(newStatusRow);
      }

      closeModal();
      updateTotalMetrics();
    });
  });
</script>
</body>
</html>