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

$ctx      = current_context($conn);
$user     = $ctx['user'];
$branch   = $ctx['branch'];
$initials = initials($user['full_name']);
$bid      = (int)$branch['branch_id'];

$managerDisplayName = trim((string) ($_SESSION['user_name'] ?? $user['full_name'] ?? 'Manager'));
$managerInitial     = map_pump_profile_initial($managerDisplayName, $initials ?: 'M');

$q = trim($_GET['q'] ?? '');

// -------- Load customers (all who ever visited this branch OR are home-branch) --------
$where = "(c.home_branch_id = :b OR EXISTS (SELECT 1 FROM tokens t WHERE t.customer_id = c.customer_id AND t.branch_id = :b))";
$params = [':b' => $bid];
if ($q !== '') {
    $where .= " AND (c.full_name LIKE :q OR c.phone LIKE :q OR c.vehicle_no LIKE :q OR c.customer_code LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}
$stmt = $conn->prepare("
    SELECT c.* FROM customers c
    WHERE {$where}
    ORDER BY c.last_visit_at DESC, c.customer_id DESC
    LIMIT 200
");
$stmt->execute($params);
$customers = $stmt->fetchAll();

// -------- Aggregate stats --------
$stmt = $conn->prepare("
    SELECT COUNT(*) FROM customers c
    WHERE c.home_branch_id = :b OR EXISTS (SELECT 1 FROM tokens t WHERE t.customer_id = c.customer_id AND t.branch_id = :b)
");
$stmt->execute([':b' => $bid]);
$total_customers = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("
    SELECT COUNT(*) FROM customers c
    WHERE c.home_branch_id = :b AND c.created_at >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)
");
$stmt->execute([':b' => $bid]);
$new_this_month = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("SELECT AVG(rating) FROM feedback WHERE branch_id = :b");
$stmt->execute([':b' => $bid]);
$avg_rating = round((float)$stmt->fetchColumn(), 1);

$stmt = $conn->prepare("
    SELECT COUNT(*) FROM customers c
    WHERE c.tier = 'gold'
      AND (c.home_branch_id = :b OR EXISTS (SELECT 1 FROM tokens t WHERE t.customer_id = c.customer_id AND t.branch_id = :b))
");
$stmt->execute([':b' => $bid]);
$gold_count = (int)$stmt->fetchColumn();

// -------- Feedback (latest 12) --------
$stmt = $conn->prepare("
    SELECT f.*, c.full_name FROM feedback f
    LEFT JOIN customers c ON c.customer_id = f.customer_id
    WHERE f.branch_id = :b
    ORDER BY f.created_at DESC LIMIT 12
");
$stmt->execute([':b' => $bid]);
$feedbacks = $stmt->fetchAll();
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
      <img src="/images/Logo.png" alt="Logo" class="nav-logo">
          <span class="disp" style="font-size: 17px; letter-spacing: 0.5px; color: #ffffff; font-weight: 700;">Map & Pump</span>
    </a>
    <div class="side-role">Branch Manager Console</div>

    <div class="side-branch">
      <b><?= h($branch['branch_name']) ?></b>
      <span>Branch ID: <?= h($branch['branch_code']) ?></span>
    </div>

    <nav class="side-nav">
      <a href="dashboard.php" class="side-link"><span class="ic"><i class="fa-solid fa-house"></i></span> Dashboard</a>
      <a href="manage-staff.php" class="side-link"><span class="ic"><i class="fa-solid fa-users"></i></span> Manage Staff</a>
      <a href="manage-oil.php" class="side-link"><span class="ic"><i class="fa-solid fa-gas-pump"></i></span> Manage Oil Stock</a>
      <a href="booking-slot.php" class="side-link"><span class="ic"><i class="fa-solid fa-ticket"></i></span> Monitor Booking Slot</a>
      <a href="view-customers.php" class="side-link active"><span class="ic"><i class="fa-solid fa-address-book"></i></span> View Customers</a>
      <a href="report-hq.php" class="side-link"><span class="ic"><i class="fa-solid fa-paper-plane"></i></span> Report to HQ</a>
      <a href="branchprofile.php" class="side-link"><span class="ic"><i class="fa-solid fa-building"></i></span> Branch Profile</a>
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

    <div class="content">
      <div class="page-head">
        <div>
          <h2>Customers</h2>
          <p>Browse customer records at your branch and read the feedback they've left.</p>
        </div>
      </div>

      <div class="stat-strip">
        <div class="stat"><div class="n"><?= number_format($total_customers) ?></div><div class="l">Total Customers</div></div>
        <div class="stat"><div class="n"><?= number_format($new_this_month) ?></div><div class="l">New This Month</div></div>
        <div class="stat"><div class="n"><?= $avg_rating ? $avg_rating . ' ★' : '—' ?></div><div class="l">Avg. Feedback Rating</div></div>
        <div class="stat"><div class="n"><?= number_format($gold_count) ?></div><div class="l">Gold Tier Members</div></div>
      </div>

      <div class="tabs">
        <button class="tab-btn active" data-tab="tab-list">Customer List</button>
        <button class="tab-btn" data-tab="tab-feedback">Feedback</button>
      </div>

      <div class="tab-panel active" id="tab-list">
        <div class="toolbar">
          <div class="search"><i class="fa-solid fa-magnifying-glass"></i><input type="text" id="customer-search" placeholder="Search by name, phone, or vehicle no..." value="<?= h($q) ?>"></div>
        </div>
        <div class="panel">
          <table id="customer-table">
            <thead>
              <tr><th>Customer</th><th>Phone</th><th>Vehicle No.</th><th>Total Visits</th><th>Last Visit</th><th>Tier</th></tr>
            </thead>
            <tbody>
              <?php if (!$customers): ?>
                <tr><td colspan="6" style="text-align:center; color:var(--slate-400); padding:22px;">No customers found.</td></tr>
              <?php else: foreach ($customers as $c): ?>
                <tr>
                  <td>
                    <div class="cust-name">
                      <div class="mini-avatar"><?= h(initials($c['full_name'])) ?></div>
                      <div><b><?= h($c['full_name']) ?></b><span>ID: <?= h($c['customer_code']) ?></span></div>
                    </div>
                  </td>
                  <td><?= h($c['phone']) ?></td>
                  <td><?= h($c['vehicle_no']) ?></td>
                  <td><?= (int)$c['total_visits'] ?></td>
                  <td><?= h(fmt_date($c['last_visit_at'])) ?></td>
                  <td><span class="tier-pill <?= h($c['tier']) ?>"><?= h(ucfirst($c['tier'])) ?></span></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="tab-panel" id="tab-feedback">
        <div class="feedback-grid">
          <?php if (!$feedbacks): ?>
            <div style="grid-column:1/-1; text-align:center; color:var(--slate-400); padding:22px;">No feedback yet.</div>
          <?php else: foreach ($feedbacks as $f):
            $r = max(0, min(5, (int)$f['rating']));
            $stars = str_repeat('★', $r) . str_repeat('☆', 5 - $r);
          ?>
            <div class="fb-card">
              <div class="fb-head">
                <span class="fb-stars"><?= h($stars) ?></span>
                <span class="fb-date"><?= h(fmt_date($f['created_at'])) ?></span>
              </div>
              <div class="fb-name"><?= h($f['full_name'] ?: 'Anonymous') ?></div>
              <div class="fb-text"><?= h($f['body']) ?></div>
            </div>
          <?php endforeach; endif; ?>
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

    // Tab switching (Customer List <-> Feedback)
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

    // Notifications Popup Toggle, Clear Badge, & Clear All Handler
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