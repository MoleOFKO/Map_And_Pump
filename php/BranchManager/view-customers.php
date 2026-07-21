<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MAP & PUMP — View Customers</title>

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
  .side-role{font-size:11px; color:var(--slate-400); font-weight:500; margin:2px 0 0 46px;}
  .side-branch{
    margin:20px 8px 0; padding:10px 12px; background:var(--navy-800); border-radius:12px;
    border:1px solid rgba(255,255,255,0.06); font-size:12.5px;
    transition: background 0.3s, border-color 0.3s;
  }
  .side-branch:hover{ background: var(--navy-700); border-color: rgba(56,189,248,0.2); }
  .side-branch b{display:block; font-size:14px; font-family:'Space Grotesk'; margin-bottom:2px;}
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
  .topbar-right{display:flex; align-items:center; gap:16px;}
  .bell{
    width:36px; height:36px; border-radius:50%; background:var(--navy-800); display:flex; align-items:center;
    justify-content:center; border:1px solid rgba(255,255,255,0.08); position:relative; font-size:15px; cursor:pointer;
    transition: transform 0.2s, background-color 0.2s;
  }
  .bell:hover{ background: var(--navy-700); transform: scale(1.05); }
  .bell .ping{position:absolute; top:6px; right:7px; width:7px; height:7px; border-radius:50%; background:var(--amber-400); box-shadow:0 0 0 2px var(--navy-800);}
  .avatar-chip{display:flex; align-items:center; gap:10px; font-size:13px; cursor: pointer; padding: 4px 8px; border-radius: 99px; transition: background 0.2s;}
  .avatar-chip:hover{background: rgba(255,255,255,0.05);}
  .avatar-circle{width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,var(--sky-400),var(--blue-700)); display:flex; align-items:center; justify-content:center; font-weight:700; font-size:13px; font-family:'Space Grotesk';}

  .content{ padding:30px 32px 60px; opacity: 0; transform: translateY(10px); animation: layoutFadeIn 0.5s ease forwards; }
  @keyframes layoutFadeIn { to { opacity: 1; transform: translateY(0); } }

  .page-foot{ text-align:center; margin-top:34px; padding-top:18px; border-top:1px solid rgba(255,255,255,0.06); font-size:12px; color:var(--slate-400); }

  .page-head{display:flex; align-items:center; justify-content:space-between; margin-bottom:22px; flex-wrap:wrap; gap:14px;}
  .page-head h2{font-size:20px; font-weight:700;}
  .page-head p{font-size:13px; color:var(--slate-400); margin-top:4px;}

  .stat-strip{display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:26px;}
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

  .cust-name{display:flex; align-items:center; gap:11px;}
  .mini-avatar{
    width:34px; height:34px; border-radius:50%; background:linear-gradient(135deg,var(--sky-400),var(--blue-700));
    display:flex; align-items:center; justify-content:center; font-weight:700; font-size:12px; font-family:'Space Grotesk'; flex-shrink:0;
  }
  .cust-name b{display:block; font-size:13.5px;}
  .cust-name span{font-size:11.5px; color:var(--slate-400);}
  .tier-pill{font-size:11px; padding:4px 10px; border-radius:999px; font-weight:600;}
  .tier-pill.gold{background:rgba(251,191,36,0.14); color:var(--amber-300);}
  .tier-pill.silver{background:rgba(139,150,173,0.14); color:var(--slate-300);}
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
</style>
</head>
<body>

<div class="app-shell">
  <aside class="sidebar">
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
      <a href="manage-oil.php" class="side-link"><span class="ic"><i class="fa-solid fa-gas-pump"></i></span> Manage Oil Stock</a>
      <a href="booking-slot.php" class="side-link"><span class="ic"><i class="fa-solid fa-ticket"></i></span> Monitor Booking Slot</a>
      <a href="view-customers.php" class="side-link active"><span class="ic"><i class="fa-solid fa-address-book"></i></span> View Customers</a>
      <a href="report-hq.php" class="side-link"><span class="ic"><i class="fa-solid fa-paper-plane"></i></span> Report to HQ</a>
      <a href="edit-profile.php" class="side-link"><span class="ic"><i class="fa-solid fa-gear"></i></span> Edit Profile</a>
    </nav>

    <a href="login.php" class="side-logout"><span class="ic"><i class="fa-solid fa-right-from-bracket"></i></span> Log Out</a>
  </aside>

  <div class="main">
    <div class="topbar">
      <div>
        <h1>View Customers</h1>
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
          <h2>Customers</h2>
          <p>Browse customer records at your branch and read the feedback they've left.</p>
        </div>
      </div>

      <div class="stat-strip">
        <div class="stat"><div class="n">1,248</div><div class="l">Total Customers</div></div>
        <div class="stat"><div class="n">86</div><div class="l">New This Month</div></div>
        <div class="stat"><div class="n">4.6 ★</div><div class="l">Avg. Feedback Rating</div></div>
        <div class="stat"><div class="n">312</div><div class="l">Gold Tier Members</div></div>
      </div>

      <div class="tabs">
        <button class="tab-btn active" data-tab="tab-list">Customer List</button>
        <button class="tab-btn" data-tab="tab-feedback">Feedback</button>
      </div>

      <div class="tab-panel active" id="tab-list">
        <div class="toolbar">
          <div class="search"><i class="fa-solid fa-magnifying-glass"></i><input type="text" id="customer-search" placeholder="Search by name, phone, or vehicle no..."></div>
        </div>
        <div class="panel">
          <table id="customer-table">
            <thead>
              <tr><th>Customer</th><th>Phone</th><th>Vehicle No.</th><th>Total Visits</th><th>Last Visit</th><th>Tier</th></tr>
            </thead>
            <tbody>
              <tr><td><div class="cust-name"><div class="mini-avatar">KZ</div><div><b>Kyaw Zin Htet</b><span>ID: CU-1042</span></div></div></td><td>09-751-XXXX01</td><td>YGN-2C-4471</td><td>38</td><td>Jul 18, 2026</td><td><span class="tier-pill gold">Gold</span></td></tr>
              <tr><td><div class="cust-name"><div class="mini-avatar">SM</div><div><b>Su Myat Noe</b><span>ID: CU-1043</span></div></div></td><td>09-751-XXXX02</td><td>YGN-4B-2210</td><td>21</td><td>Jul 17, 2026</td><td><span class="tier-pill silver">Silver</span></td></tr>
              <tr><td><div class="cust-name"><div class="mini-avatar">TH</div><div><b>Thura Han</b><span>ID: CU-1044</span></div></div></td><td>09-751-XXXX03</td><td>YGN-6K-9081</td><td>9</td><td>Jul 15, 2026</td><td><span class="tier-pill regular">Regular</span></td></tr>
              <tr><td><div class="cust-name"><div class="mini-avatar">NW</div><div><b>Nilar Win</b><span>ID: CU-1045</span></div></div></td><td>09-751-XXXX04</td><td>YGN-2C-7734</td><td>52</td><td>Jul 18, 2026</td><td><span class="tier-pill gold">Gold</span></td></tr>
              <tr><td><div class="cust-name"><div class="mini-avatar">AK</div><div><b>Aung Kyaw</b><span>ID: CU-1046</span></div></div></td><td>09-751-XXXX05</td><td>YGN-8D-1123</td><td>4</td><td>Jul 10, 2026</td><td><span class="tier-pill regular">Regular</span></td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="tab-panel" id="tab-feedback">
        <div class="feedback-grid">
          <div class="fb-card">
            <div class="fb-head"><span class="fb-stars">★★★★★</span><span class="fb-date">Jul 18, 2026</span></div>
            <div class="fb-name">Kyaw Zin Htet</div>
            <div class="fb-text">Very fast service today, no waiting at all. Pump #2 staff was friendly and helpful.</div>
          </div>
          <div class="fb-card">
            <div class="fb-head"><span class="fb-stars">★★★★☆</span><span class="fb-date">Jul 17, 2026</span></div>
            <div class="fb-name">Su Myat Noe</div>
            <div class="fb-text">Good experience overall, but the queue app token number was a bit delayed to update.</div>
          </div>
          <div class="fb-card">
            <div class="fb-head"><span class="fb-stars">★★★☆☆</span><span class="fb-date">Jul 16, 2026</span></div>
            <div class="fb-name">Thura Han</div>
            <div class="fb-text">Diesel pump was under maintenance, had to reroute to another branch nearby.</div>
          </div>
          <div class="fb-card">
            <div class="fb-head"><span class="fb-stars">★★★★★</span><span class="fb-date">Jul 15, 2026</span></div>
            <div class="fb-name">Nilar Win</div>
            <div class="fb-text">Loyalty discount applied correctly and the receipt was sent to my email right away.</div>
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

    // Tab switching (Customer List <-> Feedback / View Feedback From Customers)
    const tabButtons = document.querySelectorAll('.tab-btn');
    tabButtons.forEach(btn => {
      btn.addEventListener('click', () => {
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById(btn.dataset.tab).classList.add('active');
      });
    });

    // Live search filter on customer list
    const searchInput = document.getElementById('customer-search');
    searchInput.addEventListener('input', () => {
      const q = searchInput.value.trim().toLowerCase();
      document.querySelectorAll('#customer-table tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
      });
    });

    const bellBtn = document.getElementById('notif-bell');
    bellBtn.addEventListener('click', () => {
      const ping = bellBtn.querySelector('.ping');
      if (ping) { ping.style.transform = 'scale(0)'; setTimeout(() => ping.remove(), 200); }
    });
  });
</script>
</body>
</html>
