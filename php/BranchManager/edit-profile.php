<?php
/**
 * edit-profile.php — User's personal profile (branch manager account).
 * Handles POST submit to update the branch manager account name/email with CSRF protection.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../profile_avatar.php';


require_branch_manager();

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $message = 'Invalid CSRF token. Please refresh and try again.';
        $message_type = 'danger';
    } else {
        $full_name = trim($_POST['full_name'] ?? '');
        $email     = trim($_POST['email'] ?? '');

        if ($full_name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Name and a valid email are required.';
            $message_type = 'danger';
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET user_name = :n, email = :e, updated_at = NOW()
                    WHERE user_id = :uid
                ");
                $stmt->execute([
                    ':n'   => $full_name,
                    ':e'   => $email,
                    ':uid' => $_SESSION['user_id'],
                ]);

                $_SESSION['user_name']  = $full_name;
                $_SESSION['user_email'] = $email;

                $message = 'Profile updated successfully.';
                $message_type = 'success';
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $message = 'That email is already in use by another account.';
                } else {
                    error_log('Branch manager profile update failed: ' . $e->getMessage());
                    $message = 'Could not update profile right now.';
                }
                $message_type = 'danger';
            }
        }
    }
}

$ctx      = current_context($pdo);
$user     = $ctx['user'];
$branch   = $ctx['branch'];

$managerDisplayName = !empty($user['full_name']) ? $user['full_name'] : 'Manager';
$initials           = function_exists('map_pump_profile_initial') 
                      ? map_pump_profile_initial($managerDisplayName, 'M') 
                      : initials($managerDisplayName);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MAP & PUMP — Edit Profile</title>

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

    .sidebar{
      width:252px; flex-shrink:0; background:var(--navy-900); border-right:1px solid rgba(255,255,255,0.06);
      display:flex; flex-direction:column; padding:22px 16px; position:sticky; top:0; height:100vh; z-index:60;
    }
    .nav-logo { width: 32px; height: auto; object-fit: contain; }
    .side-role{font-size:11px; color:var(--slate-400); font-weight:500; margin:2px 0 0 46px;}
    .side-branch{
      margin:20px 8px 0; padding:10px 12px; background:var(--navy-800); border-radius:12px;
      border:1px solid rgba(255,255,255,0.06); font-size:12.5px;
      transition: background 0.3s, border-color 0.3s;
    }
    .side-branch:hover{ background: var(--navy-700); border-color: rgba(52,211,153,0.2); }
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

    .panel{background:linear-gradient(160deg, var(--navy-800), var(--navy-900)); border:1px solid rgba(255,255,255,0.07); border-radius:18px; padding:26px;}
    .panel h3{font-size:15px; font-weight:700; margin-bottom:18px;}

    .avatar-big{
      width:88px; height:88px; border-radius:50%; margin:0 auto 14px; background:linear-gradient(135deg,var(--sky-400),var(--blue-700));
      display:flex; align-items:center; justify-content:center; font-weight:700; font-size:30px; font-family:'Space Grotesk'; position:relative;
    }
    .avatar-edit-btn{
      position:absolute; bottom:-2px; right:-2px; width:30px; height:30px; border-radius:50%; background:var(--navy-950);
      border:2px solid var(--navy-800); display:flex; align-items:center; justify-content:center; font-size:12px; color:var(--slate-300);
      cursor:pointer; transition: background 0.2s;
    }
    .avatar-edit-btn:hover{background:var(--navy-700);}
    .profile-card-center{text-align:center;}
    .profile-card-center h4{font-size:16px; font-weight:700; margin-bottom:2px;}
    .profile-card-center span{font-size:12.5px; color:var(--slate-400);}
    .profile-tag{
      display:inline-flex; align-items:center; gap:6px; margin-top:12px; font-size:11.5px; color:var(--mint-400);
      background:rgba(52,211,153,0.12); border:1px solid rgba(52,211,153,0.3); padding:5px 12px; border-radius:999px;
    }

    .form-group{margin-bottom:16px;}
    .form-row{display:grid; grid-template-columns:1fr 1fr; gap:14px;}
    @media (max-width:560px){.form-row{grid-template-columns:1fr;}}
    .form-group label{display:block; font-size:12px; color:var(--slate-400); margin-bottom:6px; font-weight:500;}
    .form-control-custom{
      width:100%; background:var(--navy-950); border:1px solid rgba(255,255,255,0.08);
      border-radius:10px; padding:11px 14px; color:var(--slate-50); font-size:13.5px; outline:none;
      font-family:inherit; transition: border-color 0.2s;
    }
    .form-control-custom:focus{border-color:var(--sky-400);}
    .form-control-custom:disabled{color:var(--slate-400); cursor:not-allowed; opacity:0.7;}

    .btn-save{
      background:linear-gradient(135deg, var(--sky-400), var(--blue-700)); color:#fff; font-weight:700; font-size:13.5px;
      padding:12px 20px; border-radius:11px; border:none; cursor:pointer; display:inline-flex; align-items:center; gap:8px;
      box-shadow:0 10px 24px -10px rgba(56,189,248,0.55); transition: transform 0.2s, box-shadow 0.2s;
    }
    .btn-save:hover{ transform: translateY(-2px); box-shadow:0 12px 28px -8px rgba(56,189,248,0.7); }

    .danger-zone{
      margin-top:22px; padding:18px 20px; border-radius:14px; border:1px solid rgba(248,113,113,0.28);
      background:rgba(248,113,113,0.06);
    }
    .danger-zone h4{font-size:13.5px; font-weight:700; color:var(--red-400); margin-bottom:6px;}
    .danger-zone p{font-size:12.5px; color:var(--slate-300); line-height:1.6; margin-bottom:14px;}
    .btn-danger-outline{
      background:transparent; border:1px solid rgba(248,113,113,0.4); color:var(--red-400); font-weight:700; font-size:13px;
      padding:10px 16px; border-radius:10px; cursor:pointer; display:inline-flex; align-items:center; gap:8px; transition: all 0.2s;
    }
    .btn-danger-outline:hover{background:rgba(248,113,113,0.12);}

    /* Modal */
    .modal-overlay {
      position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(7, 11, 20, 0.85);
      backdrop-filter: blur(8px); z-index: 100; display: flex; align-items: center; justify-content: center;
      opacity: 0; pointer-events: none; transition: opacity 0.3s ease;
    }
    .modal-overlay.active { opacity: 1; pointer-events: auto; }
    .modal-card-custom {
      background: linear-gradient(160deg, var(--navy-800), var(--navy-900));
      border: 1px solid rgba(248,113,113,0.25); border-radius: 20px;
      width: 100%; max-width: 440px; padding: 28px; transform: scale(0.95);
      transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
    }
    .modal-overlay.active .modal-card-custom { transform: scale(1); }
    .modal-icon{
      width:52px; height:52px; border-radius:50%; background:rgba(248,113,113,0.14); color:var(--red-400);
      display:flex; align-items:center; justify-content:center; font-size:22px; margin-bottom:16px;
    }
    .modal-card-custom h3{font-size:17px; font-weight:700; margin-bottom:8px;}
    .modal-card-custom p{font-size:13px; color:var(--slate-300); line-height:1.6; margin-bottom:22px;}
    .modal-actions{display:flex; gap:12px; justify-content:flex-end;}
    .btn-secondary-custom{
      background: transparent; border: 1px solid rgba(255,255,255,0.15); color: var(--slate-300);
      padding: 10px 18px; border-radius: 10px; cursor: pointer; font-size: 13px; font-weight: 600;
      transition: background 0.2s, color 0.2s;
    }
    .btn-secondary-custom:hover { background: rgba(255,255,255,0.05); color: var(--slate-50); }
    .btn-confirm-danger{
      background:linear-gradient(135deg, #f87171, #b91c1c); color:#fff; font-weight:700; font-size:13px;
      padding:10px 18px; border-radius:10px; border:none; cursor:pointer; transition: filter 0.2s;
    }
    .btn-confirm-danger:hover{filter:brightness(1.08);}
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
      <b><?= htmlspecialchars($branch['branch_name'] ?? $user['branch_name'] ?? 'Unassigned Branch') ?></b>
      <span>Branch ID: <?= htmlspecialchars($branch['branch_code'] ?? $user['branch_code'] ?? 'N/A') ?></span>
    </div>

    <nav class="side-nav">
      <a href="dashboard.php" class="side-link"><span class="ic"><i class="fa-solid fa-house"></i></span> Dashboard</a>
      <a href="manage-staff.php" class="side-link"><span class="ic"><i class="fa-solid fa-users"></i></span> Manage Staff</a>
      <a href="manage-oil.php" class="side-link"><span class="ic"><i class="fa-solid fa-gas-pump"></i></span> Manage Oil Stock</a>
      <a href="booking-slot.php" class="side-link"><span class="ic"><i class="fa-solid fa-ticket"></i></span> Monitor Booking Slot</a>
      <a href="view-customers.php" class="side-link"><span class="ic"><i class="fa-solid fa-address-book"></i></span> View Customers</a>
      <a href="report-hq.php" class="side-link"><span class="ic"><i class="fa-solid fa-paper-plane"></i></span> Report to HQ</a>
      <a href="branchprofile.php" class="side-link"><span class="ic"><i class="fa-solid fa-building"></i></span> Branch Profile</a>
      <a href="edit-profile.php" class="side-link active"><span class="ic"><i class="fa-solid fa-gear"></i></span> Edit Profile</a>
    </nav>

    <a href="../Login/logout.php" class="side-logout"><span class="ic"><i class="fa-solid fa-right-from-bracket"></i></span> Log Out</a>
  </aside>

  <div class="main">
    <div class="topbar">
      <div>
        <h1>Edit Profile</h1>
        <div class="date" id="live-clock">--</div>
      </div>
      <div class="topbar-right">
        <div class="bell" id="notif-bell"><i class="fa-regular fa-bell"></i><span class="ping"></span></div>
        <div class="avatar-chip">
          <div class="avatar-circle"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></div>
          <div><b><?= htmlspecialchars(explode(' ', $user['full_name'] ?? 'Manager')[0]) ?></b></div>
        </div>
      </div>
    </div>

    <div class="content">
      <div class="page-head">
        <h2>Your Profile</h2>
        <p>Update your personal details or manage your linked user account.</p>
      </div>

      <?php if (!empty($message)): ?>
        <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert" style="border-radius:12px; background:var(--navy-800); color:#fff; border-color:rgba(255,255,255,0.1);">
          <?= htmlspecialchars($message) ?>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <div class="layout-2col">
        <div class="panel profile-card-center">
          <div class="avatar-big"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?>
            <div class="avatar-edit-btn"><i class="fa-solid fa-camera"></i></div>
          </div>
          <h4><?= htmlspecialchars($user['full_name'] ?? 'Manager') ?></h4>
          <span>Branch Manager</span>
          <div class="profile-tag"><i class="fa-solid fa-shield-halved"></i> Verified Manager Account</div>

          <div class="danger-zone" style="text-align:left; margin-top:26px;">
            <h4><i class="fa-solid fa-triangle-exclamation"></i> Danger Zone</h4>
            <p>Switch to the customer side of this same account to manage your driver license and fill your car. You can return to Branch Manager after your license is confirmed.</p>
            <button class="btn-danger-outline" id="switch-account-btn" type="button"><i class="fa-solid fa-right-left"></i> Switch to Customer Profile</button>
          </div>
        </div>

        <div class="panel">
          <h3>Personal Details</h3>
          <form id="profile-form" action="edit-profile.php" method="POST">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <div class="form-row">
              <div class="form-group">
                <label for="pf-name">Full Name</label>
                <input type="text" id="pf-name" name="full_name" class="form-control-custom" value="<?= htmlspecialchars($user['full_name'] ?? '') ?>" required>
              </div>
              <div class="form-group">
                <label for="pf-phone">Phone Number</label>
                <input type="text" id="pf-phone" name="phone" class="form-control-custom" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" required>
              </div>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label for="pf-email">Email Address</label>
                <input type="email" id="pf-email" name="email" class="form-control-custom" value="<?= htmlspecialchars($user['email'] ?? '') ?>" required>
              </div>
              <div class="form-group">
                <label for="pf-nrc">NRC / National ID</label>
                <input type="text" id="pf-nrc" name="nrc" class="form-control-custom" value="<?= htmlspecialchars($user['nrc'] ?? '') ?>">
              </div>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label for="pf-branch">Branch</label>
                <input type="text" id="pf-branch" class="form-control-custom" value="<?= htmlspecialchars(($branch['branch_name'] ?? $user['branch_name'] ?? 'Unassigned') . ' (' . ($branch['branch_code'] ?? $user['branch_code'] ?? 'N/A') . ')') ?>" disabled>
              </div>
              <div class="form-group">
                <label for="pf-role">Role</label>
                <input type="text" id="pf-role" class="form-control-custom" value="Branch Manager" disabled>
              </div>
            </div>
            <button type="submit" class="btn-save"><i class="fa-solid fa-check"></i> Save Changes</button>
          </form>
        </div>
      </div>

      <div class="page-foot">© 2026 MAP & PUMP | All Right Reserved</div>
    </div>
  </div>
</div>

<!-- ---------- Change to Customer Account Confirmation Modal ---------- -->
<div class="modal-overlay" id="switch-modal">
  <div class="modal-card-custom">
    <div class="modal-icon"><i class="fa-solid fa-right-left"></i></div>
    <h3>Switch to Customer Profile?</h3>
    <p>This keeps your Branch Manager role linked in the database and only changes the active profile for this session.</p>
    <div class="modal-actions">
      <button type="button" class="btn-secondary-custom" id="switch-cancel-btn">Cancel</button>
      <form action="../account_switch.php" method="POST" style="display:inline;">
        <input type="hidden" name="mode" value="customer">
        <button type="submit" class="btn-confirm-danger" id="switch-confirm-btn">Switch Profile</button>
      </form>
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

    // Account Switch Modal Handlers
    const switchModal = document.getElementById('switch-modal');
    document.getElementById('switch-account-btn').addEventListener('click', () => switchModal.classList.add('active'));
    document.getElementById('switch-cancel-btn').addEventListener('click', () => switchModal.classList.remove('active'));
    switchModal.addEventListener('click', (e) => { if (e.target === switchModal) switchModal.classList.remove('active'); });

    // Notification bell clear
    const bellBtn = document.getElementById('notif-bell');
    bellBtn.addEventListener('click', () => {
      const ping = bellBtn.querySelector('.ping');
      if (ping) { ping.style.transform = 'scale(0)'; setTimeout(() => ping.remove(), 200); }
    });
  });
</script>
</body>
</html>
