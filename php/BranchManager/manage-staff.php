<?php
/**
 * manage-staff.php  —  Staff CRUD + status toggle + activity log
 * Actions handled here (form-post):
 *   ?action=add         -> POST full_name, email, contact
 *   ?action=edit        -> POST staff_id, full_name, email, contact
 *   ?action=delete      -> POST staff_id
 *   ?action=activate    -> POST staff_id
 *   ?action=disable     -> POST staff_id
 * Each returns JSON so the existing JS can keep working (AJAX-friendly).
 * Role is always 'Pump Attendant' and shift defaults to 'Morning'
 * (kept in DB for schema compatibility but not user-editable here).
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../profile_avatar.php';
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../conn.php';
require_branch_manager();

$managerDisplayName = trim((string) ($_SESSION['user_name'] ?? 'Manager'));
$managerInitial = map_pump_profile_initial($managerDisplayName, 'M');

$ctx      = current_context($pdo);
$user     = $ctx['user'];
$branch   = $ctx['branch'];
$initials = initials($user['full_name']);
$bid      = (int)$branch['branch_id'];

// -------- POST action router --------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) json_out(['ok' => false, 'error' => 'CSRF failed'], 403);
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add') {
            $full_name = trim($_POST['full_name'] ?? '');
            $email     = trim($_POST['email'] ?? '');
            $contact   = trim($_POST['contact'] ?? '');
            // role is always Pump Attendant (only staff role at branch level)
            // shift kept for schema compatibility, defaults to Morning
            $role      = 'Pump Attendant';
            $shift     = 'Morning';

            if ($full_name === '') json_out(['ok' => false, 'error' => 'Name required']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                json_out(['ok' => false, 'error' => 'Invalid email address']);
            }

            $roleStmt = $pdo->query("SELECT role_id FROM roles WHERE LOWER(role_name) = 'staff' LIMIT 1");
            $staffRoleId = (int) $roleStmt->fetchColumn();
            if ($staffRoleId <= 0) json_out(['ok' => false, 'error' => 'Staff role is not configured']);

            $pdo->beginTransaction();
            $userStmt = $pdo->prepare(
              "INSERT INTO users (user_name, email, hashed_password, role_id, business_role_id, current_role_id, headquarter_id, branch_id, status)
               SELECT :name, :email, :password, :role_id, :role_id, :customer_role, u.headquarter_id, u.branch_id, 'active'
               FROM users u WHERE u.user_id = :manager_id"
            );
            $customerRoleId = (int) $pdo->query("SELECT role_id FROM roles WHERE LOWER(role_name) = 'customer' LIMIT 1")->fetchColumn();
            $userStmt->execute([
              ':name' => $full_name, ':email' => $email,
              ':password' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
              ':role_id' => $staffRoleId, ':customer_role' => $customerRoleId,
              ':manager_id' => $user['user_id'],
            ]);
            $staffUserId = (int) $pdo->lastInsertId();

            // generate ST-#### code (starts from 2100 or next after existing)
            $stmt = $pdo->prepare("
                SELECT MAX(CAST(SUBSTRING(staff_code, 4) AS UNSIGNED)) FROM staff WHERE branch_id = :b
            ");
            $stmt->execute([':b' => $bid]);
            $next = ((int)$stmt->fetchColumn() ?: 2090) + 1;
            $code = 'ST-' . $next;

            // Detect whether the `email` column exists on the staff table.
            // If the migration in migrations.sql has been run, we save it; otherwise skip it silently.
            $hasEmailColumn = false;
            try {
                $chk = $pdo->query("SHOW COLUMNS FROM staff LIKE 'email'");
                $hasEmailColumn = ($chk && $chk->fetchColumn());
            } catch (Throwable $e) { $hasEmailColumn = false; }

            if ($hasEmailColumn) {
                $stmt = $pdo->prepare("
                    INSERT INTO staff (user_id, staff_code, branch_id, full_name, role, shift, contact, email, is_active, duty_status, hired_at)
                    VALUES (:u, :c, :b, :n, :r, :s, :p, :e, 1, 'Off Duty', CURDATE())
                ");
                  $stmt->execute([':u'=>$staffUserId, ':c'=>$code, ':b'=>$bid, ':n'=>$full_name, ':r'=>$role, ':s'=>$shift, ':p'=>$contact, ':e'=>$email]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO staff (user_id, staff_code, branch_id, full_name, role, shift, contact, is_active, duty_status, hired_at)
                    VALUES (:u, :c, :b, :n, :r, :s, :p, 1, 'Off Duty', CURDATE())
                ");
                  $stmt->execute([':u'=>$staffUserId, ':c'=>$code, ':b'=>$bid, ':n'=>$full_name, ':r'=>$role, ':s'=>$shift, ':p'=>$contact]);
            }

                $pdo->commit();

            log_activity($pdo, $bid, 'user', $user['user_id'], $user['full_name'], "Added new staff: {$full_name} ({$code})");
            json_out(['ok' => true, 'staff_id' => (int)$pdo->lastInsertId(), 'staff_code' => $code]);
        }

        if ($action === 'edit') {
            $sid       = (int)($_POST['staff_id'] ?? 0);
            $full_name = trim($_POST['full_name'] ?? '');
            $email     = trim($_POST['email'] ?? '');
            $contact   = trim($_POST['contact'] ?? '');

            if ($full_name === '') json_out(['ok' => false, 'error' => 'Name required']);
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                json_out(['ok' => false, 'error' => 'Invalid email address']);
            }

            $hasEmailColumn = false;
            try {
                $chk = $pdo->query("SHOW COLUMNS FROM staff LIKE 'email'");
                $hasEmailColumn = ($chk && $chk->fetchColumn());
            } catch (Throwable $e) { $hasEmailColumn = false; }

            if ($hasEmailColumn) {
                $stmt = $pdo->prepare("
                    UPDATE staff SET full_name = :n, contact = :p, email = :e
                    WHERE staff_id = :sid AND branch_id = :b
                ");
                $stmt->execute([
                    ':n' => $full_name, ':p' => $contact, ':e' => $email,
                    ':sid' => $sid, ':b' => $bid,
                ]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE staff SET full_name = :n, contact = :p
                    WHERE staff_id = :sid AND branch_id = :b
                ");
                $stmt->execute([
                    ':n' => $full_name, ':p' => $contact,
                    ':sid' => $sid, ':b' => $bid,
                ]);
            }
            log_activity($pdo, $bid, 'user', $user['user_id'], $user['full_name'], "Edited staff #{$sid}");
            json_out(['ok' => true]);
        }

        if ($action === 'delete') {
            $sid = (int)($_POST['staff_id'] ?? 0);
            $stmt = $pdo->prepare("DELETE FROM staff WHERE staff_id = :sid AND branch_id = :b");
            $stmt->execute([':sid' => $sid, ':b' => $bid]);
            log_activity($pdo, $bid, 'user', $user['user_id'], $user['full_name'], "Deleted staff #{$sid}");
            json_out(['ok' => true]);
        }

        if ($action === 'activate' || $action === 'disable') {
            $sid = (int)($_POST['staff_id'] ?? 0);
            $flag = $action === 'activate' ? 1 : 0;
            $stmt = $pdo->prepare("UPDATE staff SET is_active = :f WHERE staff_id = :sid AND branch_id = :b");
            $stmt->execute([':f' => $flag, ':sid' => $sid, ':b' => $bid]);
            log_activity($pdo, $bid, 'user', $user['user_id'], $user['full_name'],
                         ($flag ? 'Activated' : 'Disabled') . " staff #{$sid}");
            json_out(['ok' => true]);
        }

        json_out(['ok' => false, 'error' => 'Unknown action'], 400);
    } catch (PDOException $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
        json_out(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

// -------- Load data for display --------
$stmt = $pdo->prepare("SELECT * FROM staff WHERE branch_id = :b ORDER BY staff_code ASC");
$stmt->execute([':b' => $bid]);
$staff = $stmt->fetchAll();

$total_staff    = count($staff);
$active_staff   = count(array_filter($staff, fn($s) => (int)$s['is_active'] === 1));
$disabled_staff = $total_staff - $active_staff;

// activity log — focus on STAFF activities (actions performed on staff at this branch).
// We show WHICH staff was affected + WHAT was done. Manager name is available in the action text
// already; the primary subject of each row is the staff member.
$stmt = $pdo->prepare("
    SELECT l.log_id,
           l.user_id  AS manager_id,
           COALESCE(mgr.user_name, 'Branch Manager') AS manager_name,
           l.action,
           l.created_at
    FROM log l
    LEFT JOIN users mgr ON mgr.user_id = l.user_id
    WHERE l.user_id = :manager_id
      AND l.role_id = 4
      AND l.action LIKE '%staff%'
    ORDER BY l.created_at DESC LIMIT 30
");
$stmt->execute([':manager_id' => $user['user_id']]);
$raw_activities = $stmt->fetchAll();

// Build a quick lookup of staff by ID for the current branch so we can resolve "staff #123"
// into a readable name + code.
$staff_lookup = [];
foreach ($staff as $s) {
    $staff_lookup[(int)$s['staff_id']] = [
        'name' => $s['full_name'],
        'code' => $s['staff_code'],
    ];
}

// Parse each log entry: extract staff_id (from "staff #123") and turn the action into a short verb
$activities = [];
foreach ($raw_activities as $row) {
    $action_text = (string)$row['action'];
    $target_id   = 0;
    $target_name = null;
    $target_code = null;
    $verb        = $action_text;

    if (preg_match('/staff\s+#(\d+)/i', $action_text, $m)) {
        $target_id = (int)$m[1];
        if (isset($staff_lookup[$target_id])) {
            $target_name = $staff_lookup[$target_id]['name'];
            $target_code = $staff_lookup[$target_id]['code'];
        }
    }
    // Extract just the verb (Added / Edited / Deleted / Activated / Disabled)
    if (preg_match('/^(Added|Edited|Deleted|Activated|Disabled)/i', $action_text, $vm)) {
        $verb = ucfirst(strtolower($vm[1]));
    }
    // For "Added new staff: Name (ST-####)" pattern, capture name+code directly
    if (preg_match('/Added new staff:\s*(.+?)\s*\((ST-\d+)\)/i', $action_text, $am)) {
        $target_name = $am[1];
        $target_code = $am[2];
        $verb        = 'Added';
    }

    $activities[] = [
        'log_id'        => $row['log_id'],
        'manager_name'  => $row['manager_name'],
        'verb'          => $verb,
        'target_name'   => $target_name,
        'target_code'   => $target_code,
        'target_id'     => $target_id,
        'raw_action'    => $action_text,
        'created_at'    => $row['created_at'],
    ];
}
$activities = array_slice($activities, 0, 20);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MAP & PUMP — Manage Staff</title>

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
  .side-brand{display:flex; align-items:center; gap:10px; font-weight:700; font-size:17px; padding:6px 8px 0;}
  .side-role{font-size:11px; color:var(--slate-400); font-weight:500; margin:2px 0 0 46px;}
  .side-branch{
    margin:20px 8px 0; padding:10px 12px; background:var(--navy-800); border-radius:12px; 
    border:1px solid rgba(255,255,255,0.06); font-size:12.5px;
    transition: background 0.3s, border-color 0.3s;
    display:flex; align-items:center; gap:10px;
  }
  .side-branch:hover{
    background: var(--navy-700);
    border-color: rgba(251,191,36,0.25);
  }
  .side-branch .hq-logo{
    width:36px; height:36px; border-radius:8px; object-fit:cover; flex-shrink:0;
    background:var(--navy-950); border:1px solid rgba(255,255,255,0.08);
  }
  .side-branch .txt{min-width:0; flex:1;}
  .side-branch b{display:block; font-size:14px; font-family:'Space Grotesk'; margin-bottom:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;}
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
  .topbar-right{display:flex; align-items:center; gap:16px; position:relative;}
  
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

  /* ---------- Notification Popup Dropdown (manage-oil style) ---------- */
  .notif-popup {
    position: absolute; top: 48px; right: 80px; width: 320px;
    background: var(--navy-800); border: 1px solid rgba(255,255,255,0.12);
    border-radius: 14px; box-shadow: 0 12px 30px rgba(0,0,0,0.5);
    z-index: 100; opacity: 0; pointer-events: none; transform: translateY(-10px);
    transition: opacity 0.25s ease, transform 0.25s ease;
  }
  .notif-popup.active { opacity: 1; pointer-events: auto; transform: translateY(0); }
  .notif-header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 12px 16px; border-bottom: 1px solid rgba(255,255,255,0.06);
  }
  .notif-header h4 { font-size: 13.5px; font-weight: 700; margin: 0; }
  .notif-header button { background: none; border: none; color: var(--amber-400); font-size: 11px; cursor: pointer; font-weight: 600; }
  .notif-header button:hover { text-decoration: underline; }
  .notif-list { max-height: 260px; overflow-y: auto; }
  .notif-item {
    display: flex; gap: 12px; padding: 12px 16px; border-bottom: 1px solid rgba(255,255,255,0.04);
    font-size: 12px; transition: background 0.2s; cursor: pointer;
  }
  .notif-item:last-child { border-bottom: none; }
  .notif-item:hover { background: rgba(255,255,255,0.03); }
  .notif-item .icon { width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 12px; }
  .notif-item.warning .icon { background: rgba(248,113,113,0.15); color: var(--red-400); }
  .notif-item.info .icon { background: rgba(56,189,248,0.15); color: var(--sky-400); }
  .notif-item.success .icon { background: rgba(52,211,153,0.15); color: var(--mint-400); }
  .notif-item .text b { display: block; color: var(--slate-50); margin-bottom: 2px; }
  .notif-item .text span { color: var(--slate-400); line-height: 1.3; display: block; }
  .notif-item .time { font-size: 10px; color: var(--slate-400); margin-top: 4px; }

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

  .stat-strip{display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:26px;}
  @media (max-width:1000px){.stat-strip{grid-template-columns:repeat(2,1fr);}}
  
  .stat{
    background:linear-gradient(160deg, var(--navy-800), var(--navy-900)); border:1px solid rgba(255,255,255,0.07); 
    border-radius:14px; padding:16px 18px;
    transition: transform 0.3s cubic-bezier(0.2, 0.8, 0.2, 1), border-color 0.3s;
  }
  .stat:hover{
    transform: translateY(-3px);
    border-color: rgba(251,191,36,0.25);
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
    border-color: rgba(251,191,36,0.4);
    box-shadow: 0 0 0 1px rgba(251,191,36,0.4);
  }
  .search input{background:none; border:none; outline:none; color:var(--slate-50); font-family:inherit; font-size:13.5px; width:100%;}
  
  .chip{
    padding:9px 15px; border-radius:999px; font-size:12.5px; font-weight:600; 
    border:1px solid rgba(255,255,255,0.1); color:var(--slate-300); cursor:pointer;
    transition: all 0.2s ease;
  }
  .chip:hover{background: rgba(255,255,255,0.05); color: var(--slate-50);}
  .chip.active{background:rgba(251,191,36,0.16); border-color:rgba(251,191,36,0.4); color:var(--amber-300);}

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
    <a href="dashboard.php" class="text-decoration-none d-flex align-items-center gap-2 text-white fw-bold" style="padding: 6px 8px 0;">
      <img src="/images/Logo.png" alt="Logo" class="nav-logo">
      <span class="disp" style="font-size: 17px; letter-spacing: 0.5px; color: #ffffff; font-weight: 700;">Map & Pump</span>
    </a>
    <div class="side-role">Branch Manager Console</div>

    <?php $hqLogoSrc = hq_logo_src($branch['hq_logo'] ?? null); ?>
    <div class="side-branch">
      <?php if ($hqLogoSrc): ?>
        <img src="<?= h($hqLogoSrc) ?>" alt="HQ Logo" class="hq-logo" onerror="this.style.display='none'">
      <?php else: ?>
        <div class="hq-logo" style="display:flex; align-items:center; justify-content:center; color:var(--slate-400);"><i class="fa-solid fa-building"></i></div>
      <?php endif; ?>
      <div class="txt">
        <b><?= h($branch['branch_name']) ?></b>
        <span>Branch ID: <?= h($branch['branch_code']) ?></span>
      </div>
    </div>

    <nav class="side-nav">
      <a href="dashboard.php" class="side-link"><span class="ic"><i class="fa-solid fa-house"></i></span> Dashboard</a>
      <a href="manage-staff.php" class="side-link active"><span class="ic"><i class="fa-solid fa-users"></i></span> Manage Staff</a>
      <a href="manage-oil.php" class="side-link"><span class="ic"><i class="fa-solid fa-gas-pump"></i></span> Manage Oil Stock</a>
      <a href="booking-slot.php" class="side-link"><span class="ic"><i class="fa-solid fa-ticket"></i></span> Monitor Booking Slot</a>
      <a href="view-customers.php" class="side-link"><span class="ic"><i class="fa-solid fa-address-book"></i></span> View Customers</a>
      <a href="report-hq.php" class="side-link"><span class="ic"><i class="fa-solid fa-paper-plane"></i></span> Report to HQ</a>
      <a href="branchprofile.php" class="side-link"><span class="ic"><i class="fa-solid fa-building"></i></span> Branch Profile</a>
      <a href="edit-profile.php" class="side-link"><span class="ic"><i class="fa-solid fa-gear"></i></span> Edit Profile</a>
    </nav>

    <a href="../Login/logout.php" class="side-logout"><span class="ic"><i class="fa-solid fa-right-from-bracket"></i></span> Log Out</a>
  </aside>

  <div class="main">
    <div class="topbar">
      <div>
        <h1>Manage Staff</h1>
        <div class="date" id="live-clock">Wednesday · July 9, 2026</div>
      </div>
      <div class="topbar-right">
        <!-- Bell Trigger Button -->
        <div class="bell" id="notif-bell"><i class="fa-regular fa-bell"></i><span class="ping" id="notif-ping"></span></div>
        
        <!-- Notification Dropdown Popup (manage-oil style) -->
        <div class="notif-popup" id="notif-popup">
          <div class="notif-header">
            <h4>Notifications</h4>
            <button id="clear-notifs-btn">Mark all read</button>
          </div>
          <div class="notif-list" id="notif-list">
            <div class="notif-item warning">
              <div class="icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
              <div class="text">
                <b>Low Stock Alert</b>
                <span>Diesel level is at 22% (below limit).</span>
                <div class="time">10 mins ago</div>
              </div>
            </div>
            <div class="notif-item success">
              <div class="icon"><i class="fa-solid fa-truck-field"></i></div>
              <div class="text">
                <b>Delivery Received</b>
                <span>8,000 L of Petrol 92 added to Tank 1.</span>
                <div class="time">2 hours ago</div>
              </div>
            </div>
            <div class="notif-item info">
              <div class="icon"><i class="fa-solid fa-info-circle"></i></div>
              <div class="text">
                <b>Price Sync</b>
                <span>HQ updated fuel prices for today.</span>
                <div class="time">Yesterday</div>
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
          <h2>Staff Directory</h2>
          <p>Create staff accounts, activate/disable access, and review activity logs</p>
        </div>
        <button class="btn-primary" id="open-add-btn"><i class="fa-solid fa-plus"></i> Add New Staff</button>
      </div>

      <div class="stat-strip">
        <div class="stat"><div class="n" id="stat-total"><?= $total_staff ?></div><div class="l">Total Staff</div></div>
        <div class="stat"><div class="n" id="stat-active" style="color:var(--mint-400)"><?= $active_staff ?></div><div class="l">Active Account</div></div>
        <div class="stat"><div class="n" id="stat-disabled" style="color:var(--slate-400)"><?= $disabled_staff ?></div><div class="l">Disabled Account</div></div>
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
            <input type="text" id="directory-search" placeholder="Search by name, email, or Staff ID...">
          </div>
        </div>
        <div class="panel">
          <table id="staff-directory-table">
            <thead><tr><th>Staff</th><th>Email</th><th>Contact</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <?php
              $duty_class  = ['On Duty' => 'on', 'Off Duty' => 'off', 'Leave' => 'leave'];
              foreach ($staff as $s):
                $staff_email = $s['email'] ?? '';
              ?>
              <tr data-id="<?= (int)$s['staff_id'] ?>">
                <td>
                  <div class="staff-name">
                    <div class="mini-avatar"><?= h(initials($s['full_name'])) ?></div>
                    <div><b><?= h($s['full_name']) ?></b><span>ID: <?= h($s['staff_code']) ?></span></div>
                  </div>
                </td>
                <td><?= $staff_email !== '' ? h($staff_email) : '<span style="color:var(--slate-400);">—</span>' ?></td>
                <td><?= h($s['contact']) ?></td>
                <td><span class="status-pill <?= h($duty_class[$s['duty_status']] ?? 'off') ?>"><span class="d"></span> <?= h($s['duty_status']) ?></span></td>
                <td class="actions">
                  <a class="edit-action" data-id="<?= (int)$s['staff_id'] ?>" data-email="<?= h($staff_email) ?>">Edit</a>
                  <a class="danger delete-action" data-id="<?= (int)$s['staff_id'] ?>" data-name="<?= h($s['full_name']) ?>" data-code="<?= h($s['staff_code']) ?>">Delete</a>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if (!$staff): ?>
                <tr><td colspan="5" style="text-align:center; color:var(--slate-400); padding:22px;">No staff registered yet.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- TAB 2: ACTIVE / DISABLE STAFF -->
      <div class="tab-panel" id="tab-status">
        <div class="panel">
          <table id="status-management-table">
            <thead><tr><th>Staff</th><th>Contact</th><th>Account Status</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($staff as $s): $on = (int)$s['is_active'] === 1; ?>
              <tr data-id="<?= (int)$s['staff_id'] ?>">
                <td>
                  <div class="staff-name">
                    <div class="mini-avatar"><?= h(initials($s['full_name'])) ?></div>
                    <div><b><?= h($s['full_name']) ?></b><span>ID: <?= h($s['staff_code']) ?></span></div>
                  </div>
                </td>
                <td><?= h($s['contact']) ?></td>
                <td>
                  <span class="status-pill <?= $on ? 'on' : 'off' ?>"><span class="d"></span> <?= $on ? 'Active' : 'Disabled' ?></span>
                </td>
                <td class="actions">
                  <?php if ($on): ?>
                    <a class="danger status-toggle" data-action="disable" data-id="<?= (int)$s['staff_id'] ?>">Disable</a>
                  <?php else: ?>
                    <a class="status-toggle" style="color:var(--mint-400)" data-action="activate" data-id="<?= (int)$s['staff_id'] ?>">Activate</a>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if (!$staff): ?>
                <tr><td colspan="4" style="text-align:center; color:var(--slate-400); padding:22px;">No staff registered yet.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- TAB 3: STAFF ACTIVITY LOG (focused on the staff being affected) -->
      <div class="tab-panel" id="tab-activity">
        <div class="panel">
          <?php if (!$activities): ?>
            <div style="text-align:center; padding:22px; color:var(--slate-400); font-size:12.5px;">No staff activity yet.</div>
          <?php else: ?>
            <?php
              // colour per verb — makes the log skim-able
              $verb_color = [
                'Added'     => 'var(--mint-400)',
                'Edited'    => 'var(--sky-400)',
                'Activated' => 'var(--mint-400)',
                'Disabled'  => 'var(--amber-400)',
                'Deleted'   => 'var(--red-400)',
              ];
              $verb_icon = [
                'Added'     => 'fa-user-plus',
                'Edited'    => 'fa-pen-to-square',
                'Activated' => 'fa-toggle-on',
                'Disabled'  => 'fa-toggle-off',
                'Deleted'   => 'fa-user-minus',
              ];
            ?>
            <?php foreach ($activities as $a):
              $vcolor = $verb_color[$a['verb']] ?? 'var(--slate-300)';
              $vicon  = $verb_icon[$a['verb']] ?? 'fa-clock-rotate-left';
              $subject = $a['target_name']
                          ? $a['target_name'] . ($a['target_code'] ? ' (' . $a['target_code'] . ')' : '')
                          : ($a['target_id'] ? 'Staff #' . $a['target_id'] . ' (removed)' : 'Staff record');
            ?>
              <div class="activity-row">
                <span class="t"><?= h(date('M j · h:i A', strtotime($a['created_at']))) ?></span>
                <span>
                  <i class="fa-solid <?= h($vicon) ?>" style="color:<?= $vcolor ?>; margin-right:6px;"></i>
                  <b style="color:<?= $vcolor ?>"><?= h($a['verb']) ?></b>
                  — <b><?= h($subject) ?></b>
                  <span style="color:var(--slate-400); font-size:11.5px;"> by <?= h($a['manager_name']) ?></span>
                </span>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="page-foot">© 2026 MAP & PUMP | All Rights Reserved</div>
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
    <form id="staff-form" method="POST" action="manage-staff.php">
      <input type="hidden" name="csrf"     value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action"   id="staff-form-action" value="add">
      <input type="hidden" name="staff_id" id="staff-form-id"     value="">
      <div class="form-group">
        <label for="staff-name-input">Full Name</label>
        <input type="text" id="staff-name-input" name="full_name" class="form-control-custom" required placeholder="e.g. Aung Ko Latt">
      </div>
      <div class="form-group">
        <label for="staff-email-input">Email Address</label>
        <input type="email" id="staff-email-input" name="email" class="form-control-custom" placeholder="e.g. aungkolatt@mappump.mm">
      </div>
      <div class="form-group">
        <label for="staff-contact-input">Contact Phone</label>
        <input type="text" id="staff-contact-input" name="contact" class="form-control-custom" required placeholder="e.g. 09-751-XXXXXX">
      </div>
      <div class="modal-actions">
        <button type="button" class="btn-secondary-custom" id="modal-cancel-btn">Cancel</button>
        <button type="submit" class="btn-primary" style="box-shadow: none; padding: 10px 18px;">Save Staff</button>
      </div>
    </form>
  </div>
</div>

<!-- ---------- Delete Confirmation Modal (custom red danger dialog) ---------- -->
<div class="modal-overlay" id="delete-modal">
  <div class="modal-card-custom" style="max-width:440px; border:1px solid rgba(248,113,113,0.25);">
    <div style="width:52px; height:52px; border-radius:50%; background:rgba(248,113,113,0.14); color:var(--red-400); display:flex; align-items:center; justify-content:center; font-size:22px; margin-bottom:16px;">
      <i class="fa-solid fa-triangle-exclamation"></i>
    </div>
    <h3 style="font-size:17px; font-weight:700; margin-bottom:8px;">Delete Staff Account?</h3>
    <p id="delete-modal-msg" style="font-size:13px; color:var(--slate-300); line-height:1.6; margin-bottom:22px;">
      This will permanently remove this staff member and cannot be undone.
    </p>
    <div class="modal-actions">
      <button type="button" class="btn-secondary-custom" id="delete-cancel-btn">Cancel</button>
      <button type="button" id="delete-confirm-btn"
        style="background:linear-gradient(135deg,#f87171,#b91c1c); color:#fff; font-weight:700; font-size:13px; padding:10px 18px; border-radius:10px; border:none; cursor:pointer;">
        Delete Permanently
      </button>
    </div>
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

    // 2. Notification Popup Controller (manage-oil.php matching)
    const bellBtn = document.getElementById('notif-bell');
    const notifPopup = document.getElementById('notif-popup');
    const notifPing = document.getElementById('notif-ping');
    const clearNotifsBtn = document.getElementById('clear-notifs-btn');

    bellBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      notifPopup.classList.toggle('active');
      if (notifPing) {
        notifPing.style.opacity = '0';
      }
    });

    // Close popup on outside click
    document.addEventListener('click', (e) => {
      if (!notifPopup.contains(e.target) && !bellBtn.contains(e.target)) {
        notifPopup.classList.remove('active');
      }
    });

    // Clear notifications
    clearNotifsBtn.addEventListener('click', () => {
      const notifList = document.getElementById('notif-list');
      notifList.innerHTML = `
        <div style="padding: 20px; text-align: center; color: var(--slate-400); font-size: 12px;">
          No unread notifications
        </div>`;
      if (notifPing) notifPing.remove();
    });

    // 3. Client Side Dynamic Search Filtering (name, email, staff ID)
    const searchInput = document.getElementById('directory-search');
    const directoryRows = document.querySelectorAll('#staff-directory-table tbody tr');
    searchInput.addEventListener('input', filterDirectory);

    function filterDirectory() {
      const query = searchInput.value.toLowerCase().trim();
      directoryRows.forEach(row => {
        // search across whole row text so email column is included
        const rowText = row.textContent.toLowerCase();
        const matchesSearch = query === '' || rowText.includes(query);
        if (matchesSearch) {
          row.style.display = '';
          setTimeout(() => { row.style.opacity = '1'; row.style.transform = 'translateY(0)'; }, 20);
        } else {
          row.style.opacity = '0';
          row.style.transform = 'translateY(4px)';
          setTimeout(() => { row.style.display = 'none'; }, 200);
        }
      });
    }

    // 5. Server-side Delete Action (uses the custom danger modal instead of window.confirm)
    const csrfToken = '<?= h(csrf_token()) ?>';
    const directoryTable = document.getElementById('staff-directory-table');
    const deleteModal   = document.getElementById('delete-modal');
    const deleteMsg     = document.getElementById('delete-modal-msg');
    const deleteCancel  = document.getElementById('delete-cancel-btn');
    const deleteConfirm = document.getElementById('delete-confirm-btn');
    let pendingDeleteRow = null;

    function openDeleteModal(row) {
      pendingDeleteRow = row;
      const name = row.querySelector('.delete-action')?.dataset.name || 'this staff member';
      const code = row.querySelector('.delete-action')?.dataset.code || '';
      deleteMsg.innerHTML = `You are about to permanently remove <b>${name}</b>${code ? ` (<b>${code}</b>)` : ''}. This action cannot be undone.`;
      deleteModal.classList.add('active');
    }
    function closeDeleteModal() {
      deleteModal.classList.remove('active');
      pendingDeleteRow = null;
    }
    deleteCancel.addEventListener('click', closeDeleteModal);
    deleteModal.addEventListener('click', (e) => { if (e.target === deleteModal) closeDeleteModal(); });

    directoryTable.addEventListener('click', (e) => {
      if (e.target.classList.contains('delete-action')) {
        e.preventDefault();
        openDeleteModal(e.target.closest('tr'));
      }
    });

    deleteConfirm.addEventListener('click', async () => {
      if (!pendingDeleteRow) return;
      const row = pendingDeleteRow;
      const sid = row.dataset.id;
      deleteConfirm.disabled = true;
      deleteConfirm.textContent = 'Deleting...';

      const fd = new FormData();
      fd.append('csrf', csrfToken);
      fd.append('action', 'delete');
      fd.append('staff_id', sid);
      try {
        const res = await fetch('manage-staff.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.ok) { alert('Delete failed: ' + (data.error || 'unknown')); return; }
        closeDeleteModal();
        row.style.opacity = '0';
        row.style.transform = 'translateX(-20px)';
        setTimeout(() => {
          row.remove();
          document.querySelector(`#status-management-table tr[data-id="${sid}"]`)?.remove();
          updateTotalMetrics();
        }, 300);
      } catch (err) {
        alert('Network error: ' + err.message);
      } finally {
        deleteConfirm.disabled = false;
        deleteConfirm.textContent = 'Delete Permanently';
      }
    });

    // 6. Server-side Account Activation Status Changer
    const statusTable = document.getElementById('status-management-table');
    statusTable.addEventListener('click', async (e) => {
      const toggleBtn = e.target.closest('.status-toggle');
      if (!toggleBtn) return;
      e.preventDefault();
      const row = toggleBtn.closest('tr');
      const sid = toggleBtn.dataset.id;
      const action = toggleBtn.getAttribute('data-action');

      const fd = new FormData();
      fd.append('csrf', csrfToken);
      fd.append('action', action);
      fd.append('staff_id', sid);
      try {
        const res = await fetch('manage-staff.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.ok) { alert('Update failed: ' + (data.error || 'unknown')); return; }

        const pill = row.querySelector('.status-pill');
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
      } catch (err) { alert('Network error: ' + err.message); }
    });

    function updateTotalMetrics() {
      const totalStaff = document.querySelectorAll('#staff-directory-table tbody tr').length;
      const totalActive = document.querySelectorAll('#status-management-table tbody .status-pill.on').length;
      const totalDisabled = document.querySelectorAll('#status-management-table tbody .status-pill.off').length;
      
      document.getElementById('stat-total').textContent = totalStaff;
      document.getElementById('stat-active').textContent = totalActive;
      document.getElementById('stat-disabled').textContent = totalDisabled;
    }

    // 7. Interactive Form Dialog Logic
    const modal = document.getElementById('staff-modal');
    const form = document.getElementById('staff-form');
    const openAddBtn = document.getElementById('open-add-btn');
    const cancelBtn = document.getElementById('modal-cancel-btn');
    const closeBtn = document.getElementById('modal-close-btn');
    let editTargetRow = null;

    const nameInput = document.getElementById('staff-name-input');
    const emailInput = document.getElementById('staff-email-input');
    const contactInput = document.getElementById('staff-contact-input');

    function openModal(isEdit = false, name = "", email = "", contact = "") {
      if (isEdit) {
        document.getElementById('modal-title').textContent = "Edit Staff Member Details";
        nameInput.value = name;
        emailInput.value = email;
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

    openAddBtn.addEventListener('click', () => {
      document.getElementById('staff-form-action').value = 'add';
      document.getElementById('staff-form-id').value = '';
      openModal(false);
    });
    cancelBtn.addEventListener('click', closeModal);
    closeBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });

    // Edit actions
    directoryTable.addEventListener('click', (e) => {
      if (e.target.classList.contains('edit-action')) {
        e.preventDefault();
        editTargetRow = e.target.closest('tr');
        const name    = editTargetRow.querySelector('.staff-name b').textContent.trim();
        const email   = e.target.dataset.email || '';
        // Contact is now column index 2 (Staff | Email | Contact | Status | Actions)
        const contact = editTargetRow.cells[2].textContent.trim();

        document.getElementById('staff-form-action').value = 'edit';
        document.getElementById('staff-form-id').value = editTargetRow.dataset.id;
        openModal(true, name, email, contact);
      }
    });

    // Server-side Form Submit
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(form);
      try {
        const res = await fetch('manage-staff.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.ok) { alert('Save failed: ' + (data.error || 'unknown')); return; }
        // simplest reliable path: reload the page so PHP re-renders fresh data
        closeModal();
        window.location.reload();
      } catch (err) { alert('Network error: ' + err.message); }
    });
  });
</script>
</body>
</html>
