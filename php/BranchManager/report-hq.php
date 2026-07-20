<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MAP & PUMP — Report to HQ</title>

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

  .sidebar{
    width:252px; flex-shrink:0; background:var(--navy-900); border-right:1px solid rgba(255,255,255,0.06);
    display:flex; flex-direction:column; padding:22px 16px; position:sticky; top:0; height:100vh; z-index:60;
  }
  .side-role{font-size:11px; color:var(--slate-400); font-weight:500; margin:2px 0 0 46px;}
  .side-branch{
    margin:20px 8px 0; padding:10px 12px; background:var(--navy-800); border-radius:12px;
    border:1px solid rgba(255,255,255,0.06); font-size:12.5px;
    transition: background 0.3s, border-color 0.3s;
  }
  .side-branch:hover{ background: var(--navy-700); border-color: rgba(251,191,36,0.2); }
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

  .page-head{margin-bottom:22px;}
  .page-head h2{font-size:20px; font-weight:700;}
  .page-head p{font-size:13px; color:var(--slate-400); margin-top:4px;}

  .layout-2col{display:grid; grid-template-columns:1fr 1.3fr; gap:20px; align-items:start;}
  @media (max-width:980px){.layout-2col{grid-template-columns:1fr;}}

  .panel{background:linear-gradient(160deg, var(--navy-800), var(--navy-900)); border:1px solid rgba(255,255,255,0.07); border-radius:18px; padding:22px;}
  .panel h3{font-size:15px; font-weight:700; margin-bottom:16px;}

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
</style>
</head>
<body>

<div class="app-shell">
  <aside class="sidebar">
    <a href="dashboard.php" class="text-decoration-none d-flex align-items-center justify-content-center gap-2 text-white fw-bold">
    <img src="../../images/Logo.png" alt="Logo" class="nav-logo">
     <span style="font-size: 1rem; letter-spacing: 0.5px; color: #ffffff;">Map & Pump</span>
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
      <a href="view-customers.php" class="side-link"><span class="ic"><i class="fa-solid fa-address-book"></i></span> View Customers</a>
      <a href="report-hq.php" class="side-link active"><span class="ic"><i class="fa-solid fa-paper-plane"></i></span> Report to HQ</a>
      <a href="edit-profile.php" class="side-link"><span class="ic"><i class="fa-solid fa-gear"></i></span> Edit Profile</a>
    </nav>

    <a href="login.php" class="side-logout"><span class="ic"><i class="fa-solid fa-right-from-bracket"></i></span> Log Out</a>
  </aside>

  <div class="main">
    <div class="topbar">
      <div>
        <h1>Report to HQ</h1>
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
        <h2>Report to Head Office</h2>
        <p>Send stock, equipment, staff, or other incident reports directly to HQ for review.</p>
      </div>

      <div class="layout-2col">
        <div class="panel">
          <h3>New Report</h3>
          <form id="report-form">
            <div class="form-group">
              <label for="report-category">Category</label>
              <select id="report-category" class="form-control-custom" required>
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
              <input type="text" id="report-subject" class="form-control-custom" placeholder="e.g. Diesel tank leak at Pump #4" required>
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
              <textarea id="report-desc" class="form-control-custom" placeholder="Describe the issue in detail..." required></textarea>
            </div>
            <button type="submit" class="btn-report"><i class="fa-solid fa-paper-plane"></i> Send Report to HQ</button>
          </form>
        </div>

        <div class="panel">
          <h3>Recent Reports</h3>
          <div id="report-list">
            <div class="report-row">
              <div class="report-icon high"><i class="fa-solid fa-triangle-exclamation"></i></div>
              <div class="report-body">
                <b>Pump #4 hydraulic fault</b>
                <p>Equipment Issue — Pump stopped mid-transaction twice this morning.</p>
                <div class="report-meta"><span class="status-pill pending">Pending</span><span>Jul 19, 2026</span></div>
              </div>
            </div>
            <div class="report-row">
              <div class="report-icon medium"><i class="fa-solid fa-gas-pump"></i></div>
              <div class="report-body">
                <b>Diesel stock running low</b>
                <p>Stock Shortage — Current level at 22%, delivery requested within 2 days.</p>
                <div class="report-meta"><span class="status-pill reviewed">Reviewed</span><span>Jul 18, 2026</span></div>
              </div>
            </div>
            <div class="report-row">
              <div class="report-icon low"><i class="fa-solid fa-user"></i></div>
              <div class="report-body">
                <b>Staff late check-in pattern</b>
                <p>Staff Incident — Two staff repeatedly late for morning shift this week.</p>
                <div class="report-meta"><span class="status-pill resolved">Resolved</span><span>Jul 14, 2026</span></div>
              </div>
            </div>
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

    // Priority chip selector
    let selectedPriority = 'low';
    document.querySelectorAll('.priority-chip').forEach(chip => {
      chip.addEventListener('click', () => {
        document.querySelectorAll('.priority-chip').forEach(c => c.classList.remove('active'));
        chip.classList.add('active');
        selectedPriority = chip.dataset.p;
      });
    });

    // Submit new report — prepend to Recent Reports list
    const form = document.getElementById('report-form');
    const reportList = document.getElementById('report-list');
    const iconMap = {
      'Stock Shortage': 'fa-gas-pump',
      'Equipment Issue': 'fa-triangle-exclamation',
      'Staff Incident': 'fa-user',
      'Customer Complaint': 'fa-comment',
      'Other': 'fa-flag'
    };

    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const category = document.getElementById('report-category').value;
      const subject = document.getElementById('report-subject').value;
      const desc = document.getElementById('report-desc').value;
      const icon = iconMap[category] || 'fa-flag';
      const today = new Date().toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });

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
