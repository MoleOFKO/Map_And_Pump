<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MAP & PUMP — Branch Dashboard</title>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<!-- Browser Tab Logo (Favicon) -->
<link rel="icon" type="image/png" href="../../images/Logo.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Noto+Sans+Myanmar:wght@400;500;600;700&display=swap" rel="stylesheet">
<!-- <link href="../../css/user_css.css" rel="stylesheet"> -->

<style>
  :root{
    --navy-950:#070b14; --navy-900:#0b1220; --navy-800:#101a30; --navy-700:#16213f;
    --blue-700:#1d4ed8; --blue-500:#2f6feb; --sky-400:#38bdf8;
    --amber-400:#fbbf24; --amber-300:#fcd34d; --mint-400:#34d399;
    --red-400:#f87171;
    --slate-50:#f8fafc; --slate-300:#c9d2e3; --slate-400:#8b96ad;
  }
  .nav-logo {
            height: 24px;
            width: auto;
            object-fit: contain;
        }
  *{box-sizing:border-box; margin:0; padding:0;}
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
  .topbar-right{display:flex; align-items:center; gap:16px;}
  .bell{
    width:36px; height:36px; border-radius:50%; background:var(--navy-800); display:flex; align-items:center; 
    justify-content:center; border:1px solid rgba(255,255,255,0.08); position:relative; font-size:15px; color:var(--slate-300);
    cursor:pointer; transition: transform 0.2s, background-color 0.2s;
  }
  .bell:hover{
    background: var(--navy-700);
    transform: scale(1.05);
  }
  .bell .ping{position:absolute; top:6px; right:7px; width:7px; height:7px; border-radius:50%; background:var(--amber-400); box-shadow:0 0 0 2px var(--navy-800);}
  .avatar-chip{display:flex; align-items:center; gap:10px; font-size:13px; cursor: pointer; padding: 4px 8px; border-radius: 99px; transition: background 0.2s;}
  .avatar-chip:hover{background: rgba(255,255,255,0.05);}
  .avatar-circle{width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,var(--sky-400),var(--blue-700)); display:flex; align-items:center; justify-content:center; font-weight:700; font-size:13px; font-family:'Space Grotesk';}

  /* Cascade Entrance Loading Animation */
  .content{
    padding:30px 32px 60px;
    opacity: 0;
    transform: translateY(10px);
    animation: layoutFadeIn 0.5s ease forwards;
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
    border-radius: 8px; cursor: pointer; transition: background-color 0.2s, opacity 0.3s, transform 0.3s;
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
    <!-- <div class="side-brand">
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
    <a href="branch_manager_dashboard.php" class="text-decoration-none d-flex align-items-center justify-content-center gap-2 text-white fw-bold">
      <img src="../../images/Logo.png" alt="Logo" class="nav-logo">
      <span style="font-size: 1rem; letter-spacing: 0.5px; color: #ffffff;">Map & Pump</span>
    </a>
    <div class="side-role">Branch Manager Console</div>

    <div class="side-branch">
      <b>Denko - Sanchaung</b>
      <span>Branch ID: YGN-014</span>
    </div>

    <nav class="side-nav">
      <a href="branch_manager_dashboard.php" class="side-link active"><span class="ic"><i class="fa-solid fa-house"></i></span> Dashboard</a>
      <a href="branch_manager_manage-staff.php" class="side-link"><span class="ic"><i class="fa-solid fa-users"></i></span> Manage Staff</a>
      <a href="branch_manager_manage-oil.php" class="side-link"><span class="ic"><i class="fa-solid fa-gas-pump"></i></span> Manage Oil Stock</a>
      <a href="booking-slot.php" class="side-link"><span class="ic"><i class="fa-solid fa-ticket"></i></span> Monitor Booking Slot</a>
      <a href="edit-profile.php" class="side-link"><span class="ic"><i class="fa-solid fa-gear"></i></span> Edit Profile</a>
    </nav>

    <a href="branch_manager_login.php" class="side-logout"><span class="ic"><i class="fa-solid fa-right-from-bracket"></i></span> Log Out</a>
  </aside>

  <div class="main">
    <div class="topbar">
      <div>
        <h1>Dashboard</h1>
        <div class="date" id="live-clock">Wednesday · July 9, 2026</div>
      </div>
      <div class="topbar-right">
        <div class="bell" id="notif-bell"><i class="fa-regular fa-bell"></i><span class="ping"></span></div>
        <div class="avatar-chip">
          <div class="avatar-circle">F</div>
          <div><b>Felix</b></div>
        </div>
      </div>
    </div>

    <div class="content">
      <div class="hello">Welcome back, Felix👋</div>
      <div class="hello-sub">Here's a quick overview of your branch today.</div>

      <div class="kpi-grid">
        <div class="kpi">
          <div class="lbl">Tokens Issued Today</div>
          <div class="val" id="issued-count">184</div>
          <div class="trend up"><i class="fa-solid fa-caret-up"></i> 12% vs yesterday</div>
        </div>
        <div class="kpi">
          <div class="lbl">Currently Waiting</div>
          <div class="val" id="waiting-count">9 cars</div>
          <div class="trend flat">Avg wait 16 min</div>
        </div>
        <div class="kpi">
          <div class="lbl">Avg Fuel Stock</div>
          <div class="val">61%</div>
          <div class="trend down"><i class="fa-solid fa-caret-down"></i> Diesel running low</div>
        </div>
        <div class="kpi">
          <div class="lbl">Staff On Duty Today</div>
          <div class="val">7 / 9</div>
          <div class="trend ok" style="color:var(--mint-400)">2 on leave</div>
        </div>
      </div>

      <div class="section-title"><h2>Modules</h2></div>
      <div class="module-grid">
        <a href="branch_manager_manage-staff.php" class="module-card staff">
          <div class="ic-badge"><i class="fa-solid fa-users"></i></div>
          <h3>Manage Staff</h3>
          <p>Manage shift schedules, duty status, and add new staff members.</p>
          <span class="go">Open <i class="fa-solid fa-arrow-right"></i></span>
        </a>
        <a href="branch_manager_manage-oil.php" class="module-card oil">
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
            <div class="queue-row"><span><span class="tok">B-041</span> · <span class="pump">Pump #2</span></span><span class="status-pill serving">Serving</span></div>
            <div class="queue-row"><span><span class="tok">B-042</span> · <span class="pump">Pump #1</span></span><span class="status-pill serving">Serving</span></div>
            <div class="queue-row"><span><span class="tok">B-043</span> · <span class="pump">—</span></span><span class="status-pill waiting">Waiting</span></div>
            <div class="queue-row"><span><span class="tok">B-044</span> · <span class="pump">—</span></span><span class="status-pill waiting">Waiting</span></div>
            <div class="queue-row"><span><span class="tok">B-045</span> · <span class="pump">—</span></span><span class="status-pill waiting">Waiting</span></div>
          </div>
        </div>

        <div class="panel">
          <h3>Alerts <span style="font-size: 11px; font-weight: normal; color: var(--slate-400); margin-left: 6px;">(Click to dismiss)</span></h3>
          <div class="alert-list" id="alert-container">
            <div class="alert-row warn"><div class="dot2"></div><div><b>Diesel Stock at 22%</b><span>Delivery needed within 2 days <small class="dismiss-txt">Dismiss</small></span></div></div>
            <div class="alert-row err"><div class="dot2"></div><div><b>Pump #4 — Maintenance</b><span>Down since 9:30 AM <small class="dismiss-txt">Dismiss</small></span></div></div>
            <div class="alert-row ok"><div class="dot2"></div><div><b>Petrol 92 Delivery Received</b><span>8,000 liters — received this morning <small class="dismiss-txt">Dismiss</small></span></div></div>
          </div>
        </div>
      </div>
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
          // Check if no alerts remain, show empty state cleanly
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
      // Simulate real-world ticket operations every 10 seconds
      const rows = Array.from(queueContainer.querySelectorAll('.queue-row'));
      if (rows.length === 0) return;

      // 10% chance to simulate a client finishing checkout, shifting the queue
      if (Math.random() > 0.4) {
        const firstActiveRow = rows[0];
        
        // Remove oldest serving row gracefully
        firstActiveRow.classList.add('fade-out');
        
        setTimeout(() => {
          firstActiveRow.remove();

          // Move the next waiting client up to status "Serving"
          const remainingRows = Array.from(queueContainer.querySelectorAll('.queue-row'));
          const nextInLine = remainingRows.find(row => row.querySelector('.status-pill').classList.contains('waiting'));
          
          if (nextInLine) {
            const pill = nextInLine.querySelector('.status-pill');
            pill.className = 'status-pill serving';
            pill.textContent = 'Serving';
            nextInLine.querySelector('.pump').textContent = `Pump #${Math.floor(Math.random() * 3) + 1}`;
          }

          // Generate a new ticket entry from the background waiting pool
          currentMaxToken++;
          tokensIssued++;
          
          const newRow = document.createElement('div');
          newRow.className = 'queue-row fade-in';
          newRow.innerHTML = `<span><span class="tok">B-0${currentMaxToken}</span> · <span class="pump">—</span></span><span class="status-pill waiting">Waiting</span>`;
          queueContainer.appendChild(newRow);

          // Update real-time counter metrics in top widgets
          currentlyWaiting = Math.max(1, currentlyWaiting + (Math.random() > 0.5 ? 1 : -1));
          waitingCounter.textContent = `${currentlyWaiting} cars`;
          issuedCounter.textContent = tokensIssued;
        }, 300);
      }
    }
    
    // Periodically update current queue state visually
    setInterval(simulateQueueRefresh, 10000);

    // 4. Reset notifications alert badge on click
    const bellBtn = document.getElementById('notif-bell');
    bellBtn.addEventListener('click', () => {
      const ping = bellBtn.querySelector('.ping');
      if (ping) {
        ping.style.transform = 'scale(0)';
        setTimeout(() => ping.remove(), 200);
      }
    });
  });
</script>
</body>
</html>