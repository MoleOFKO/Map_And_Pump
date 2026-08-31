<?php
/**
 * branchprofile.php  —  Branch (station) profile management
 * Shows physical info about the station itself (not the user account).
 */
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../conn.php';
require_branch_manager();

$ctx    = current_context($conn);
$user   = $ctx['user'];
$branch = $ctx['branch'];
$initials = initials($user['full_name']);

// ------------------ POST: update branch profile ------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        redirect_with('danger', 'Invalid CSRF token. Please retry.');
    }

    $branch_name = trim($_POST['branch_name'] ?? '');
    $city        = trim($_POST['city'] ?? '');
    $township    = trim($_POST['township'] ?? '');
    $address     = trim($_POST['address'] ?? '');
    $phone       = trim($_POST['branch_phone'] ?? '');
    $email       = trim($_POST['branch_email'] ?? '');
    $opening     = trim($_POST['opening_time'] ?? '06:00');
    $closing     = trim($_POST['closing_time'] ?? '22:00');
    $is_24hr     = isset($_POST['is_24hr']) ? 1 : 0;
    $pump_count  = max(1, (int)($_POST['pump_count'] ?? 4));
    $tank_count  = max(1, (int)($_POST['tank_count'] ?? 3));
    $hq_region   = trim($_POST['hq_region'] ?? '');
    $status      = $_POST['status'] ?? 'active';
    if (!in_array($status, ['active','maintenance','closed'], true)) {
        $status = 'active';
    }

    if ($branch_name === '' || $city === '') {
        redirect_with('danger', 'Branch name and city are required.');
    }

    try {
        $stmt = $conn->prepare("
            UPDATE branches SET
                branch_name = :n, city = :c, township = :t, address = :a,
                phone = :ph, email = :em,
                opening_time = :ot, closing_time = :ct, is_24hr = :h24,
                pump_count = :pc, tank_count = :tc,
                hq_region = :hq, status = :st
            WHERE branch_id = :bid
        ");
        $stmt->execute([
            ':n' => $branch_name, ':c' => $city, ':t' => $township, ':a' => $address,
            ':ph' => $phone, ':em' => $email,
            ':ot' => $opening . ':00', ':ct' => $closing . ':00', ':h24' => $is_24hr,
            ':pc' => $pump_count, ':tc' => $tank_count,
            ':hq' => $hq_region, ':st' => $status,
            ':bid' => $branch['branch_id'],
        ]);
        log_activity($conn, $branch['branch_id'], 'user', $user['user_id'], $user['full_name'],
                     'Updated branch profile');
        redirect_with('success', 'Branch profile updated successfully.');
    } catch (PDOException $e) {
        redirect_with('danger', 'Could not update: ' . $e->getMessage());
    }
}

// ------------------ Fetch aggregate stats for the profile card ------------------
$stmt = $conn->prepare("SELECT COUNT(*) FROM staff WHERE branch_id = :b AND is_active = 1");
$stmt->execute([':b' => $branch['branch_id']]);
$active_staff = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("SELECT COUNT(*) FROM pumps WHERE branch_id = :b");
$stmt->execute([':b' => $branch['branch_id']]);
$pump_total = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("SELECT COUNT(*) FROM pumps WHERE branch_id = :b AND status = 'active'");
$stmt->execute([':b' => $branch['branch_id']]);
$pump_active = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("SELECT COUNT(*) FROM customers WHERE home_branch_id = :b");
$stmt->execute([':b' => $branch['branch_id']]);
$customer_total = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("
    SELECT COALESCE(SUM(current_l),0) AS filled, COALESCE(SUM(capacity_l),0) AS cap
    FROM tanks WHERE branch_id = :b
");
$stmt->execute([':b' => $branch['branch_id']]);
$row = $stmt->fetch();
$stock_pct = ($row && $row['cap'] > 0) ? round(($row['filled'] / $row['cap']) * 100) : 0;

// pumps list for the map/grid
$stmt = $conn->prepare("SELECT * FROM pumps WHERE branch_id = :b ORDER BY pump_no ASC");
$stmt->execute([':b' => $branch['branch_id']]);
$pumps = $stmt->fetchAll();

// tanks summary
$stmt = $conn->prepare("SELECT * FROM tanks WHERE branch_id = :b ORDER BY tank_id ASC");
$stmt->execute([':b' => $branch['branch_id']]);
$tanks = $stmt->fetchAll();

$flash = take_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MAP & PUMP — Branch Profile</title>

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

  /* ---------- sidebar (matches all other pages) ---------- */
  .sidebar{
    width:252px; flex-shrink:0; background:var(--navy-900);
    border-right:1px solid rgba(255,255,255,0.06);
    display:flex; flex-direction:column; padding:22px 16px;
    position:sticky; top:0; height:100vh; z-index:60;
  }
  .nav-logo{width:32px; height:auto; object-fit:contain;}
  .side-role{font-size:11px; color:var(--slate-400); font-weight:500; margin:2px 0 0 46px;}
  .side-branch{
    margin:20px 8px 0; padding:10px 12px; background:var(--navy-800); border-radius:12px;
    border:1px solid rgba(255,255,255,0.06); font-size:12.5px;
    transition: background 0.3s, border-color 0.3s;
  }
  .side-branch:hover{background:var(--navy-700); border-color:rgba(56,189,248,0.2);}
  .side-branch b{display:block; font-size:14px; font-family:'Space Grotesk'; margin-bottom:2px;}
  .side-branch span{color:var(--slate-400);}
  .side-nav{margin-top:22px; display:flex; flex-direction:column; gap:4px; flex:1;}
  .side-link{
    display:flex; align-items:center; gap:12px; padding:11px 12px; border-radius:10px; font-size:14px;
    color:var(--slate-300); font-weight:500;
    transition: background 0.25s ease, color 0.25s ease, transform 0.2s ease;
  }
  .side-link:hover{background:rgba(255,255,255,0.045); color:var(--slate-50); transform:translateX(4px);}
  .side-link.active{
    background:linear-gradient(135deg, rgba(56,189,248,0.16), rgba(29,78,216,0.12));
    color:var(--slate-50); box-shadow:inset 0 0 0 1px rgba(56,189,248,0.32);
  }
  .side-link.active:hover{transform:none;}
  .side-link .ic{font-size:16px; width:18px; text-align:center; transition: transform 0.2s ease;}
  .side-link:hover .ic{transform: scale(1.15);}
  .side-logout{
    display:flex; align-items:center; gap:12px; padding:12px; border-radius:10px; font-size:14px;
    color:var(--red-400); font-weight:600; margin-top:14px; border-top:1px solid rgba(255,255,255,0.06); padding-top:18px;
    transition: background 0.25s, padding-left 0.2s;
  }
  .side-logout:hover{background:rgba(248,113,113,0.08); padding-left:16px;}

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
  .bell:hover{background:var(--navy-700); transform:scale(1.05);}
  .bell .ping{position:absolute; top:6px; right:7px; width:7px; height:7px; border-radius:50%; background:var(--amber-400); box-shadow:0 0 0 2px var(--navy-800);}
  .avatar-chip{display:flex; align-items:center; gap:10px; font-size:13px; cursor:pointer; padding:4px 8px; border-radius:99px; transition: background 0.2s;}
  .avatar-chip:hover{background:rgba(255,255,255,0.05);}
  .avatar-circle{width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,var(--sky-400),var(--blue-700)); display:flex; align-items:center; justify-content:center; font-weight:700; font-size:13px; font-family:'Space Grotesk';}

  .content{padding:30px 32px 60px; opacity:0; transform:translateY(10px); animation: fadeIn 0.5s ease forwards;}
  @keyframes fadeIn{to{opacity:1; transform:translateY(0);}}
  .page-foot{text-align:center; margin-top:34px; padding-top:18px; border-top:1px solid rgba(255,255,255,0.06); font-size:12px; color:var(--slate-400);}

  .page-head{margin-bottom:22px;}
  .page-head h2{font-size:20px; font-weight:700;}
  .page-head p{font-size:13px; color:var(--slate-400); margin-top:4px;}

  /* ---------- panels & hero card ---------- */
  .panel{background:linear-gradient(160deg, var(--navy-800), var(--navy-900)); border:1px solid rgba(255,255,255,0.07); border-radius:18px; padding:26px;}
  .panel h3{font-size:15px; font-weight:700; margin-bottom:18px;}

  .branch-hero{
    display:flex; gap:22px; align-items:center; margin-bottom:22px;
    background:linear-gradient(135deg, rgba(56,189,248,0.10), rgba(29,78,216,0.04) 60%, transparent);
    border:1px solid rgba(56,189,248,0.16); border-radius:20px; padding:22px 26px;
  }
  .branch-logo{
    width:76px; height:76px; border-radius:20px; background:linear-gradient(135deg,var(--sky-400),var(--blue-700));
    display:flex; align-items:center; justify-content:center; font-size:30px; color:#fff; font-family:'Space Grotesk'; font-weight:700; flex-shrink:0;
  }
  .branch-hero h4{font-size:20px; font-weight:700; margin-bottom:4px;}
  .branch-hero .sub{font-size:12.5px; color:var(--slate-400);}
  .status-badge{
    display:inline-flex; align-items:center; gap:6px; font-size:11.5px; font-weight:600;
    padding:5px 12px; border-radius:999px; margin-left:8px;
  }
  .status-badge.active{background:rgba(52,211,153,0.14); color:var(--mint-400); border:1px solid rgba(52,211,153,0.3);}
  .status-badge.maintenance{background:rgba(251,191,36,0.14); color:var(--amber-300); border:1px solid rgba(251,191,36,0.3);}
  .status-badge.closed{background:rgba(248,113,113,0.14); color:var(--red-400); border:1px solid rgba(248,113,113,0.3);}
  .status-badge .d{width:6px; height:6px; border-radius:50%; background:currentColor;}

  .kpi-strip{display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:22px;}
  @media (max-width:900px){.kpi-strip{grid-template-columns:repeat(2,1fr);}}
  .kpi{background:linear-gradient(160deg, var(--navy-800), var(--navy-900)); border:1px solid rgba(255,255,255,0.06); border-radius:14px; padding:16px 18px;}
  .kpi .lbl{font-size:11.5px; color:var(--slate-400); text-transform:uppercase; letter-spacing:0.5px;}
  .kpi .val{font-size:22px; font-weight:700; margin-top:6px; font-family:'Space Grotesk';}
  .kpi .val small{font-size:12px; font-weight:500; color:var(--slate-400); margin-left:4px;}

  .layout-2col{display:grid; grid-template-columns:1.2fr 1fr; gap:20px; align-items:start;}
  @media (max-width:1080px){.layout-2col{grid-template-columns:1fr;}}

  .form-row{display:grid; grid-template-columns:1fr 1fr; gap:14px;}
  .form-row-3{display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px;}
  @media (max-width:600px){.form-row,.form-row-3{grid-template-columns:1fr;}}
  .form-group{margin-bottom:16px;}
  .form-group label{display:block; font-size:12px; color:var(--slate-400); margin-bottom:6px; font-weight:500;}
  .form-control-custom{
    width:100%; background:var(--navy-950); border:1px solid rgba(255,255,255,0.08);
    border-radius:10px; padding:11px 14px; color:var(--slate-50); font-size:13.5px; outline:none;
    font-family:inherit; transition: border-color 0.2s;
  }
  .form-control-custom:focus{border-color:var(--sky-400);}
  .form-control-custom:disabled{color:var(--slate-400); cursor:not-allowed; opacity:0.7;}
  select.form-control-custom{appearance:none; background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%238b96ad' viewBox='0 0 16 16'><path d='M8 12L2 6h12z'/></svg>"); background-repeat:no-repeat; background-position:right 14px center;}

  .checkbox-row{display:flex; align-items:center; gap:10px; padding:11px 14px; background:var(--navy-950); border:1px solid rgba(255,255,255,0.08); border-radius:10px;}
  .checkbox-row input{accent-color:var(--sky-400);}

  .btn-save{
    background:linear-gradient(135deg, var(--sky-400), var(--blue-700)); color:#fff; font-weight:700; font-size:13.5px;
    padding:12px 20px; border-radius:11px; border:none; cursor:pointer; display:inline-flex; align-items:center; gap:8px;
    box-shadow:0 10px 24px -10px rgba(56,189,248,0.55); transition: transform 0.2s, box-shadow 0.2s;
  }
  .btn-save:hover{transform:translateY(-2px); box-shadow:0 12px 28px -8px rgba(56,189,248,0.7);}

  /* right column: pumps grid + tanks summary + branch quick info */
  .pump-grid{display:grid; grid-template-columns:repeat(2,1fr); gap:10px;}
  .pump-mini{
    background:var(--navy-950); border:1px solid rgba(255,255,255,0.06); border-radius:12px; padding:12px 14px;
    display:flex; align-items:center; justify-content:space-between; font-size:13px;
    transition: border-color 0.25s;
  }
  .pump-mini:hover{border-color:rgba(56,189,248,0.24);}
  .pump-mini b{font-family:'Space Grotesk'; letter-spacing:0.5px;}
  .p-dot{width:8px; height:8px; border-radius:50%; margin-right:8px; display:inline-block;}
  .p-dot.active{background:var(--mint-400);}
  .p-dot.idle{background:var(--slate-400);}
  .p-dot.maintenance{background:var(--amber-400);}
  .p-dot.offline{background:var(--red-400);}
  .p-status{font-size:11.5px; color:var(--slate-400); display:flex; align-items:center;}

  .tank-mini{
    background:var(--navy-950); border:1px solid rgba(255,255,255,0.06); border-radius:12px; padding:12px 14px; margin-top:10px;
  }
  .tank-mini-top{display:flex; align-items:center; justify-content:space-between; font-size:13px; margin-bottom:8px;}
  .tank-mini-top b{font-family:'Space Grotesk';}
  .tank-mini-top .pct{font-weight:700; font-family:'Space Grotesk';}
  .gauge-mini{height:6px; background:rgba(255,255,255,0.06); border-radius:99px; overflow:hidden;}
  .gauge-mini i{display:block; height:100%; border-radius:99px;}

  .info-list{display:flex; flex-direction:column; gap:12px; font-size:13px;}
  .info-list .row{display:flex; justify-content:space-between; align-items:center; padding-bottom:12px; border-bottom:1px dashed rgba(255,255,255,0.06);}
  .info-list .row:last-child{border-bottom:none; padding-bottom:0;}
  .info-list .row .lbl{color:var(--slate-400); font-size:12px;}
  .info-list .row .val{font-weight:600;}

  .alert{padding:12px 16px; border-radius:12px; margin-bottom:18px; font-size:13px; display:flex; align-items:center; gap:10px;}
  .alert.success{background:rgba(52,211,153,0.10); border:1px solid rgba(52,211,153,0.3); color:var(--mint-400);}
  .alert.danger{background:rgba(248,113,113,0.10);  border:1px solid rgba(248,113,113,0.3); color:var(--red-400);}
</style>
</head>
<body>

<div class="app-shell">
  <aside class="sidebar">
    <a href="dashboard.php" class="text-decoration-none d-flex align-items-center gap-2 text-white fw-bold" style="padding: 6px 8px 0;">
      <img src="/images/Logo.png" alt="Logo" class="nav-logo">
      <span class="disp" style="font-size:17px; letter-spacing:0.5px; color:#fff; font-weight:700;">Map & Pump</span>
    </a>
    <div class="side-role">Branch Manager Console</div>

    <div class="side-branch">
      <b><?= h($branch['branch_name']) ?></b>
      <span>Branch ID: <?= h($branch['branch_code']) ?></span>
    </div>

    <nav class="side-nav">
      <a href="dashboard.php"      class="side-link"><span class="ic"><i class="fa-solid fa-house"></i></span> Dashboard</a>
      <a href="manage-staff.php"   class="side-link"><span class="ic"><i class="fa-solid fa-users"></i></span> Manage Staff</a>
      <a href="manage-oil.php"     class="side-link"><span class="ic"><i class="fa-solid fa-gas-pump"></i></span> Manage Oil Stock</a>
      <a href="booking-slot.php"   class="side-link"><span class="ic"><i class="fa-solid fa-ticket"></i></span> Monitor Booking Slot</a>
      <a href="view-customers.php" class="side-link"><span class="ic"><i class="fa-solid fa-address-book"></i></span> View Customers</a>
      <a href="report-hq.php"      class="side-link"><span class="ic"><i class="fa-solid fa-paper-plane"></i></span> Report to HQ</a>
      <a href="branchprofile.php"  class="side-link active"><span class="ic"><i class="fa-solid fa-building"></i></span> Branch Profile</a>
      <a href="edit-profile.php"   class="side-link"><span class="ic"><i class="fa-solid fa-gear"></i></span> Edit Profile</a>
    </nav>

    <a href="logout.php" class="side-logout"><span class="ic"><i class="fa-solid fa-right-from-bracket"></i></span> Log Out</a>
  </aside>

  <div class="main">
    <div class="topbar">
      <div>
        <h1>Branch Profile</h1>
        <div class="date" id="live-clock">--</div>
      </div>
      <div class="topbar-right">
        <div class="bell" id="notif-bell"><i class="fa-regular fa-bell"></i><span class="ping"></span></div>
        <div class="avatar-chip">
          <div class="avatar-circle"><?= h($initials) ?></div>
          <div><b><?= h(explode(' ', $user['full_name'])[0]) ?></b></div>
        </div>
      </div>
    </div>

    <div class="content">
      <div class="page-head">
        <h2>Station Profile</h2>
        <p>Manage physical details about this branch — address, hours, capacity, and HQ assignment.</p>
      </div>

      <?php if ($flash): ?>
        <div class="alert <?= h($flash['type']) ?>">
          <i class="fa-solid fa-<?= $flash['type'] === 'success' ? 'circle-check' : 'triangle-exclamation' ?>"></i>
          <?= h($flash['msg']) ?>
        </div>
      <?php endif; ?>

      <!-- ===== Hero card ===== -->
      <div class="branch-hero">
        <div class="branch-logo"><?= h(substr($branch['branch_code'], 0, 2)) ?></div>
        <div style="flex:1;">
          <h4>
            <?= h($branch['branch_name']) ?>
            <span class="status-badge <?= h($branch['status']) ?>"><span class="d"></span> <?= h(ucfirst($branch['status'])) ?></span>
          </h4>
          <div class="sub">
            <i class="fa-solid fa-location-dot"></i> <?= h($branch['address'] ?: 'No address on file') ?>
            &nbsp;·&nbsp;
            <i class="fa-solid fa-hashtag"></i> <?= h($branch['branch_code']) ?>
            &nbsp;·&nbsp;
            <i class="fa-solid fa-map"></i> HQ Region: <?= h($branch['hq_region'] ?: '—') ?>
          </div>
        </div>
      </div>

      <!-- ===== KPI strip ===== -->
      <div class="kpi-strip">
        <div class="kpi">
          <div class="lbl">Active Pumps</div>
          <div class="val"><?= $pump_active ?><small>/ <?= $pump_total ?></small></div>
        </div>
        <div class="kpi">
          <div class="lbl">Active Staff</div>
          <div class="val"><?= $active_staff ?></div>
        </div>
        <div class="kpi">
          <div class="lbl">Total Customers</div>
          <div class="val"><?= number_format($customer_total) ?></div>
        </div>
        <div class="kpi">
          <div class="lbl">Avg Fuel Stock</div>
          <div class="val"><?= $stock_pct ?>%</div>
        </div>
      </div>

      <!-- ===== 2-column layout ===== -->
      <div class="layout-2col">
        <!-- LEFT: editable branch details form -->
        <div class="panel">
          <h3>Edit Branch Details</h3>
          <form method="POST" action="branchprofile.php">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

            <div class="form-row">
              <div class="form-group">
                <label for="branch_name">Branch Name</label>
                <input type="text" id="branch_name" name="branch_name" class="form-control-custom" value="<?= h($branch['branch_name']) ?>" required>
              </div>
              <div class="form-group">
                <label for="branch_code">Branch Code</label>
                <input type="text" id="branch_code" class="form-control-custom" value="<?= h($branch['branch_code']) ?>" disabled>
              </div>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label for="city">City</label>
                <input type="text" id="city" name="city" class="form-control-custom" value="<?= h($branch['city']) ?>" required>
              </div>
              <div class="form-group">
                <label for="township">Township</label>
                <input type="text" id="township" name="township" class="form-control-custom" value="<?= h($branch['township']) ?>">
              </div>
            </div>

            <div class="form-group">
              <label for="address">Full Address</label>
              <input type="text" id="address" name="address" class="form-control-custom" value="<?= h($branch['address']) ?>">
            </div>

            <div class="form-row">
              <div class="form-group">
                <label for="branch_phone">Branch Phone</label>
                <input type="text" id="branch_phone" name="branch_phone" class="form-control-custom" value="<?= h($branch['phone']) ?>">
              </div>
              <div class="form-group">
                <label for="branch_email">Branch Email</label>
                <input type="email" id="branch_email" name="branch_email" class="form-control-custom" value="<?= h($branch['email']) ?>">
              </div>
            </div>

            <div class="form-row-3">
              <div class="form-group">
                <label for="opening_time">Opening Time</label>
                <input type="time" id="opening_time" name="opening_time" class="form-control-custom" value="<?= h(substr($branch['opening_time'] ?? '06:00:00', 0, 5)) ?>">
              </div>
              <div class="form-group">
                <label for="closing_time">Closing Time</label>
                <input type="time" id="closing_time" name="closing_time" class="form-control-custom" value="<?= h(substr($branch['closing_time'] ?? '22:00:00', 0, 5)) ?>">
              </div>
              <div class="form-group">
                <label>24 Hours?</label>
                <div class="checkbox-row">
                  <input type="checkbox" id="is_24hr" name="is_24hr" <?= $branch['is_24hr'] ? 'checked' : '' ?>>
                  <label for="is_24hr" style="margin:0; color:var(--slate-50); font-weight:400; cursor:pointer;">Open 24 / 7</label>
                </div>
              </div>
            </div>

            <div class="form-row-3">
              <div class="form-group">
                <label for="pump_count">Total Pumps</label>
                <input type="number" id="pump_count" name="pump_count" class="form-control-custom" min="1" max="20" value="<?= h($branch['pump_count']) ?>">
              </div>
              <div class="form-group">
                <label for="tank_count">Total Tanks</label>
                <input type="number" id="tank_count" name="tank_count" class="form-control-custom" min="1" max="20" value="<?= h($branch['tank_count']) ?>">
              </div>
              <div class="form-group">
                <label for="status">Branch Status</label>
                <select id="status" name="status" class="form-control-custom">
                  <?php foreach (['active'=>'Active','maintenance'=>'Under Maintenance','closed'=>'Closed'] as $k=>$v): ?>
                    <option value="<?= $k ?>" <?= $branch['status']===$k?'selected':'' ?>><?= $v ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="form-group">
              <label for="hq_region">HQ Region Assignment</label>
              <input type="text" id="hq_region" name="hq_region" class="form-control-custom" value="<?= h($branch['hq_region']) ?>" placeholder="e.g. Yangon Central">
            </div>

            <button type="submit" class="btn-save"><i class="fa-solid fa-check"></i> Save Branch Profile</button>
          </form>
        </div>

        <!-- RIGHT: quick info + pumps + tanks -->
        <div style="display:flex; flex-direction:column; gap:20px;">
          <div class="panel">
            <h3>Branch Quick Info</h3>
            <div class="info-list">
              <div class="row"><span class="lbl">Established</span><span class="val"><?= h(fmt_date($branch['established_at'])) ?></span></div>
              <div class="row"><span class="lbl">Operating Hours</span>
                <span class="val">
                  <?= $branch['is_24hr'] ? '24 / 7' : (fmt_time($branch['opening_time']) . ' – ' . fmt_time($branch['closing_time'])) ?>
                </span>
              </div>
              <div class="row"><span class="lbl">Total Pumps</span><span class="val"><?= (int)$branch['pump_count'] ?></span></div>
              <div class="row"><span class="lbl">Total Tanks</span><span class="val"><?= (int)$branch['tank_count'] ?></span></div>
              <div class="row"><span class="lbl">Branch Phone</span><span class="val"><?= h($branch['phone'] ?: '—') ?></span></div>
              <div class="row"><span class="lbl">Branch Email</span><span class="val"><?= h($branch['email'] ?: '—') ?></span></div>
            </div>
          </div>

          <div class="panel">
            <h3>Pumps On Site</h3>
            <div class="pump-grid">
              <?php if (!$pumps): ?>
                <div style="grid-column:1/-1; color:var(--slate-400); font-size:12.5px;">No pumps registered for this branch yet.</div>
              <?php else: foreach ($pumps as $p): ?>
                <div class="pump-mini">
                  <b>Pump #<?= (int)$p['pump_no'] ?></b>
                  <span class="p-status"><span class="p-dot <?= h($p['status']) ?>"></span><?= h(ucfirst($p['status'])) ?></span>
                </div>
              <?php endforeach; endif; ?>
            </div>
          </div>

          <div class="panel">
            <h3>Fuel Tanks Overview</h3>
            <?php if (!$tanks): ?>
              <div style="color:var(--slate-400); font-size:12.5px;">No tanks registered yet.</div>
            <?php else: foreach ($tanks as $t):
              $pct = $t['capacity_l'] > 0 ? round(($t['current_l'] / $t['capacity_l']) * 100) : 0;
              $color = $pct <= $t['alert_pct'] ? 'var(--red-400)' : ($pct < 60 ? 'var(--amber-400)' : 'var(--mint-400)');
            ?>
              <div class="tank-mini">
                <div class="tank-mini-top">
                  <b><?= h($t['fuel_name']) ?></b>
                  <span class="pct" style="color:<?= $color ?>"><?= $pct ?>%</span>
                </div>
                <div class="gauge-mini"><i style="width:<?= $pct ?>%; background:<?= $color ?>;"></i></div>
                <div style="display:flex; justify-content:space-between; font-size:11.5px; color:var(--slate-400); margin-top:6px;">
                  <span><?= number_format($t['current_l']) ?> L used of <?= number_format($t['capacity_l']) ?> L</span>
                  <span><?= $t['is_enabled'] ? 'Enabled' : 'Disabled' ?></span>
                </div>
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
  function updateClock() {
    const now = new Date();
    const opts = { weekday:'long', month:'short', day:'numeric', year:'numeric' };
    const d = now.toLocaleDateString('en-US', opts);
    const t = now.toLocaleTimeString('en-US', { hour12:true, hour:'2-digit', minute:'2-digit', second:'2-digit' });
    document.getElementById('live-clock').textContent = `${d} · ${t}`;
  }
  setInterval(updateClock, 1000); updateClock();

  const bell = document.getElementById('notif-bell');
  bell.addEventListener('click', () => {
    const ping = bell.querySelector('.ping');
    if (ping) { ping.style.transform = 'scale(0)'; setTimeout(() => ping.remove(), 200); }
  });
});
</script>
</body>
</html>
