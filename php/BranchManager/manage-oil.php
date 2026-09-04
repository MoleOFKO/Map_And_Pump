<?php
/**
 * manage-oil.php  —  Fuel tanks, delivery records, alert thresholds, type toggles
 * POST actions (all JSON-out):
 *   action=add_delivery   -> fuel_code, quantity_l, supplier, received_by
 *   action=save_limits    -> alert_p92, alert_p95, alert_dsl
 *   action=toggle_fuel    -> tank_id, enabled (0|1)
 */
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../business_scope.php';
require_branch_manager();

$ctx      = current_context($pdo);
$user     = $ctx['user'];
$branch   = $ctx['branch'];
$initials = initials($user['full_name']);
$bid      = (int)$branch['branch_id'];
$fuelCodeToOilName = [
    'p92' => 'Octane 92',
    'p95' => 'Octane 95',
    'dsl' => 'Disel',
    'pdsl' => 'Premium Disel',
];
$fuelCodeToOilAliases = [
    'p92' => ['Octane 92'],
    'p95' => ['Octane 95'],
    'dsl' => ['Disel', 'Diesel'],
    'pdsl' => ['Premium Disel', 'Premium Diesel'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) json_out(['ok' => false, 'error' => 'CSRF failed'], 403);
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add_delivery') {
            $fuel_code = $_POST['fuel_code'] ?? '';
            $qty       = max(1, (int)($_POST['quantity_l'] ?? 0));
            $supplier  = trim($_POST['supplier'] ?? '');
            $rec_by    = trim($_POST['received_by'] ?? '');

            $stmt = $pdo->prepare("SELECT tank_id, capacity_l, current_l FROM tanks WHERE branch_id = :b AND fuel_code = :f LIMIT 1");
            $stmt->execute([':b' => $bid, ':f' => $fuel_code]);
            $tank = $stmt->fetch();
            if (!$tank) json_out(['ok' => false, 'error' => 'Tank not found for this fuel']);

            $new_level = min((int)$tank['capacity_l'], (int)$tank['current_l'] + $qty);

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                INSERT INTO deliveries (branch_id, tank_id, fuel_code, quantity_l, supplier, received_by)
                VALUES (:b, :t, :f, :q, :s, :r)
            ");
            $stmt->execute([':b'=>$bid, ':t'=>$tank['tank_id'], ':f'=>$fuel_code, ':q'=>$qty, ':s'=>$supplier, ':r'=>$rec_by]);

            $stmt = $pdo->prepare("UPDATE tanks SET current_l = :l WHERE tank_id = :t");
            $stmt->execute([':l' => $new_level, ':t' => $tank['tank_id']]);
            $pdo->commit();

            log_activity($pdo, $bid, 'user', $user['user_id'], $user['full_name'],
                         "Recorded {$qty}L {$fuel_code} delivery from {$supplier}");
            json_out(['ok' => true]);
        }

        if ($action === 'save_limits') {
            $map = [
                'p92' => (int)($_POST['alert_p92'] ?? 25),
                'p95' => (int)($_POST['alert_p95'] ?? 25),
                'dsl' => (int)($_POST['alert_dsl'] ?? 30),
                'pdsl' => (int)($_POST['alert_pdsl'] ?? 30),
            ];
            $stmt = $pdo->prepare("UPDATE tanks SET alert_pct = :p WHERE branch_id = :b AND fuel_code = :f");
            foreach ($map as $fc => $pct) {
                $pct = max(5, min(80, $pct));
                $stmt->execute([':p' => $pct, ':b' => $bid, ':f' => $fc]);
            }
            log_activity($pdo, $bid, 'user', $user['user_id'], $user['full_name'], 'Updated fuel alert thresholds');
            json_out(['ok' => true]);
        }

        if ($action === 'toggle_fuel') {
            $tank_id = (int)($_POST['tank_id'] ?? 0);
            $enabled = (int)($_POST['enabled'] ?? 0) === 1 ? 1 : 0;
            $stmt = $pdo->prepare("SELECT fuel_code FROM tanks WHERE tank_id = :t AND branch_id = :b LIMIT 1");
            $stmt->execute([':t' => $tank_id, ':b' => $bid]);
            $fuelCode = (string)($stmt->fetchColumn() ?: '');
            if ($fuelCode === '') json_out(['ok' => false, 'error' => 'Tank not found for this station'], 404);

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE tanks SET is_enabled = :e WHERE tank_id = :t AND branch_id = :b");
            $stmt->execute([':e' => $enabled, ':t' => $tank_id, ':b' => $bid]);

            $oilAliases = $fuelCodeToOilAliases[$fuelCode] ?? [];
            if ($oilAliases) {
                $placeholders = implode(',', array_fill(0, count($oilAliases), '?'));
                $oilStmt = $pdo->prepare("SELECT oil_type_id FROM oil_types WHERE LOWER(oil_name) IN ({$placeholders}) LIMIT 1");
                $oilStmt->execute(array_map('strtolower', $oilAliases));
                $oilTypeId = (int)($oilStmt->fetchColumn() ?: 0);
                if ($oilTypeId > 0) {
                    $invStmt = $pdo->prepare("UPDATE station_oil_inventory SET is_active = :e WHERE station_id = :s AND oil_type_id = :o");
                    $invStmt->execute([':e' => $enabled, ':s' => $bid, ':o' => $oilTypeId]);
                }
            }
            $pdo->commit();
            log_activity($pdo, $bid, 'user', $user['user_id'], $user['full_name'],
                         ($enabled ? 'Enabled' : 'Disabled') . " fuel type (tank #{$tank_id})");
            json_out(['ok' => true]);
        }

        if ($action === 'update_price') {
            $fuel_code = $_POST['fuel_code'] ?? '';
            $price = trim((string)($_POST['price_per_litre'] ?? ''));
            if (!isset($fuelCodeToOilName[$fuel_code])) json_out(['ok' => false, 'error' => 'Invalid fuel type'], 422);
            if ($price === '' || !is_numeric($price) || (int)$price < 0) json_out(['ok' => false, 'error' => 'Enter a valid non-negative price'], 422);

            $oilAliases = $fuelCodeToOilAliases[$fuel_code];
            $placeholders = implode(',', array_fill(0, count($oilAliases), '?'));
            $oilStmt = $pdo->prepare("SELECT oil_type_id FROM oil_types WHERE LOWER(oil_name) IN ({$placeholders}) LIMIT 1");
            $oilStmt->execute(array_map('strtolower', $oilAliases));
            $oilTypeId = (int)($oilStmt->fetchColumn() ?: 0);
            if ($oilTypeId <= 0) json_out(['ok' => false, 'error' => 'Fuel grade is missing in oil_types'], 422);

            $activeStmt = $pdo->prepare("SELECT is_enabled FROM tanks WHERE branch_id = :b AND fuel_code = :f LIMIT 1");
            $activeStmt->execute([':b' => $bid, ':f' => $fuel_code]);
            $active = (int)($activeStmt->fetchColumn() ?: 1);
            map_pump_append_station_price($pdo, $bid, $oilTypeId, (int)$price, $active);
            log_activity($pdo, $bid, 'user', $user['user_id'], $user['full_name'],
                         "Updated {$fuel_code} station price to {$price} MMK");
            json_out(['ok' => true]);
        }

        json_out(['ok' => false, 'error' => 'Unknown action'], 400);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_out(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

// -------- Load data --------
$stmt = $pdo->prepare("SELECT * FROM tanks WHERE branch_id = :b ORDER BY FIELD(fuel_code, 'p92','p95','dsl','pdsl'), tank_id");
$stmt->execute([':b' => $bid]);
$tanks_rows = $stmt->fetchAll();
// index by fuel_code for easy lookup
$tanks = [];
foreach ($tanks_rows as $t) $tanks[$t['fuel_code']] = $t;

$latestInventorySql = map_pump_latest_station_inventory_sql();
$priceStmt = $pdo->prepare(
    "SELECT ot.oil_name, li.price_per_litre
     FROM ({$latestInventorySql}) li
     INNER JOIN oil_types ot ON ot.oil_type_id = li.oil_type_id
     WHERE li.station_id = :b"
);
$priceStmt->execute([':b' => $bid]);
$priceByOilName = [];
foreach ($priceStmt->fetchAll(PDO::FETCH_ASSOC) as $priceRow) {
    $priceByOilName[strtolower((string)$priceRow['oil_name'])] = (int)$priceRow['price_per_litre'];
}

// recent deliveries (last 8)
$stmt = $pdo->prepare("
    SELECT d.*, t.fuel_name FROM deliveries d
    LEFT JOIN tanks t ON t.tank_id = d.tank_id
    WHERE d.branch_id = :b
    ORDER BY d.received_at DESC LIMIT 8
");
$stmt->execute([':b' => $bid]);
$deliveries = $stmt->fetchAll();

// weekly usage — Mon..Sun sum of Petrol 92 deliveries into tank (proxy for consumption)
// (In real use you'd have a `sales` table; we use delivery movement as placeholder.)
$stmt = $pdo->prepare("
    SELECT DATE(d.received_at) AS d, SUM(d.quantity_l) AS q
    FROM deliveries d
    WHERE d.branch_id = :b AND d.fuel_code = 'p92'
      AND d.received_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(d.received_at)
");
$stmt->execute([':b' => $bid]);
$weekly = [];
foreach ($stmt->fetchAll() as $r) $weekly[$r['d']] = (int)$r['q'];

// build a Mon..Sun ordered array of last 7 days
$week_series = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} day"));
    $week_series[] = [
        'label' => date('D', strtotime($d)),
        'value' => $weekly[$d] ?? 0,
    ];
}
$week_max = max(1, max(array_column($week_series, 'value')));

// helper to compute % and color class
function tank_pct(?array $t): int {
    if (!$t || !$t['capacity_l']) return 0;
    return (int)round(($t['current_l'] / $t['capacity_l']) * 100);
}
function tank_color(?array $t): string {
    if (!$t) return 'var(--slate-400)';
    $p = tank_pct($t);
    if ($p <= (int)$t['alert_pct']) return 'var(--red-400)';
    if ($p < 60) return 'var(--amber-400)';
    return 'var(--mint-400)';
}
function tank_flag(?array $t): string {
    if (!$t) return '';
    $p = tank_pct($t);
    if ($p <= (int)$t['alert_pct']) return '<span class="tank-flag low" id="flag-'.h($t['fuel_code']).'"><i class="fa-solid fa-triangle-exclamation"></i> Stock low — delivery needed</span>';
    if ($p < 60) return '<span class="tank-flag mid" id="flag-'.h($t['fuel_code']).'"><i class="fa-solid fa-circle-half-stroke"></i> Watch level</span>';
    return '<span class="tank-flag ok" id="flag-'.h($t['fuel_code']).'"><i class="fa-solid fa-check"></i> Stock sufficient</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MAP & PUMP — Manage Oil Stock</title>

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

  /* ---------- Notification Popup Dropdown (dashboard.php style) ---------- */
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

  /* ---------- Modals ---------- */
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

    <div class="side-branch">
      <b><?= h($branch['branch_name'] ?? 'Denko - Sanchaung') ?></b>
      <span>Branch ID: <?= h($branch['branch_code'] ?? 'YGN-014') ?></span>
    </div>

    <nav class="side-nav">
      <a href="dashboard.php" class="side-link"><span class="ic"><i class="fa-solid fa-house"></i></span> Dashboard</a>
      <a href="manage-staff.php" class="side-link"><span class="ic"><i class="fa-solid fa-users"></i></span> Manage Staff</a>
      <a href="manage-oil.php" class="side-link active"><span class="ic"><i class="fa-solid fa-gas-pump"></i></span> Manage Oil Stock</a>
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
        <h1>Manage Oil Stock</h1>
        <div class="date" id="live-clock">Wednesday · July 9, 2026</div>
      </div>
      <div class="topbar-right">
        <!-- Bell Trigger Button -->
        <div class="bell" id="notif-bell"><i class="fa-regular fa-bell"></i><span class="ping" id="notif-ping"></span></div>
        
        <!-- Notification Dropdown Popup (Dashboard Style) -->
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
          <div class="avatar-circle"><a href="edit-profile.php"><?= h($initials ?? 'M') ?></a></div>
          <div><b><a href="edit-profile.php"><?= h(explode(' ', $user['full_name'] ?? 'Manager')[0]) ?></a></b></div>
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
          <?php
          $tank_defs = [
            'p92' => ['name' => 'Petrol 92', 'id' => 'p92', 'cap' => 15000, 'rem' => 11100],
            'p95' => ['name' => 'Petrol 95', 'id' => 'p95', 'cap' => 10000, 'rem' => 4800],
            'dsl' => ['name' => 'Diesel',    'id' => 'dsl', 'cap' => 12000, 'rem' => 2640],
            'pdsl' => ['name' => 'Premium Diesel', 'id' => 'pdsl', 'cap' => 12000, 'rem' => 0],
          ];
          foreach ($tank_defs as $fc => $def):
            $t = $tanks[$fc] ?? null;
            $cap = $t ? (int)$t['capacity_l'] : $def['cap'];
            $cur = $t ? (int)$t['current_l'] : $def['rem'];
            $pct = $t ? tank_pct($t) : (int)round(($cur / $cap) * 100);
            $col = $t ? tank_color($t) : ($pct > 55 ? 'var(--mint-400)' : ($pct > 25 ? 'var(--amber-300)' : 'var(--red-400)'));
            $used = $cap - $cur;
            $tank_id_attr = $t ? (int)$t['tank_id'] : '';
            $disabled_class = ($t && (int)$t['is_enabled'] === 0) ? 'disabled-tank' : '';
          ?>
          <div class="tank <?= $disabled_class ?>" id="tank-<?= $fc ?>" data-capacity="<?= $cap ?>" data-tankid="<?= $tank_id_attr ?>">
            <div class="top">
              <div>
                <div class="fuel-name"><?= h($t['fuel_name'] ?? $def['name']) ?></div>
                <div class="cap">Tank Capacity — <?= number_format($cap) ?> L</div>
              </div>
              <div class="pct" id="pct-<?= $fc ?>" style="color:<?= $col ?>"><?= $pct ?>%</div>
            </div>
            <div class="gauge-track"><i id="gauge-<?= $fc ?>" style="width:<?= $pct ?>%; background:<?= $col ?>;"></i></div>
            <div class="liters">
              <span id="rem-<?= $fc ?>"><?= number_format($cur) ?> L remaining</span>
              <span id="used-<?= $fc ?>"><?= number_format($used) ?> L used</span>
            </div>
            <?php if ($t): ?>
              <?= tank_flag($t) ?>
            <?php else: ?>
              <?php if ($fc === 'p92'): ?>
                <span class="tank-flag ok" id="flag-p92"><i class="fa-solid fa-check"></i> Stock sufficient</span>
              <?php elseif ($fc === 'p95'): ?>
                <span class="tank-flag mid" id="flag-p95"><i class="fa-solid fa-circle-half-stroke"></i> ~4 days remaining</span>
              <?php else: ?>
                <span class="tank-flag low" id="flag-dsl"><i class="fa-solid fa-triangle-exclamation"></i> Stock low — delivery needed</span>
              <?php endif; ?>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>

        <div class="section-title"><h3>Recent Delivery Log</h3></div>
        <div class="panel">
          <table id="delivery-table">
            <thead><tr><th>Date</th><th>Fuel</th><th>Quantity (Liters)</th><th>Supplier</th><th>Received By</th></tr></thead>
            <tbody>
              <?php
              $fuel_tag_class = ['p92' => 'p92', 'p95' => 'p95', 'dsl' => 'dsl'];
              if (isset($deliveries) && $deliveries):
                foreach ($deliveries as $d):
                  $fc = $d['fuel_code'];
                  $fname = $d['fuel_name'] ?? strtoupper($fc);
              ?>
                <tr data-fuel="<?= h($fname) ?>">
                  <td><?= h(date('M j', strtotime($d['received_at']))) ?></td>
                  <td><span class="fuel-tag <?= h($fuel_tag_class[$fc] ?? 'p92') ?>"><?= h($fname) ?></span></td>
                  <td><?= number_format($d['quantity_l']) ?> L</td>
                  <td><?= h($d['supplier']) ?></td>
                  <td><?= h($d['received_by']) ?></td>
                </tr>
              <?php endforeach; else: ?>
                <tr data-fuel="Petrol 92"><td>Jul 9</td><td><span class="fuel-tag p92">Petrol 92</span></td><td>8,000 L</td><td>MPPE Distribution</td><td>Leon</td></tr>
                <tr data-fuel="Petrol 95"><td>Jul 7</td><td><span class="fuel-tag p95">Petrol 95</span></td><td>5,000 L</td><td>Puma Energy</td><td>Xiao</td></tr>
                <tr data-fuel="Diesel"><td>Jul 5</td><td><span class="fuel-tag dsl">Diesel</span></td><td>6,500 L</td><td>MPPE Distribution</td><td>Thiago</td></tr>
                <tr data-fuel="Petrol 92"><td>Jul 2</td><td><span class="fuel-tag p92">Petrol 92</span></td><td>7,500 L</td><td>Max Energy</td><td>Felix</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="section-title"><h3>Weekly Usage (Petrol 92)</h3></div>
        <div class="weekly">
          <div class="bars">
            <?php if (isset($week_series) && $week_series): ?>
              <?php foreach ($week_series as $day):
                $h_pct = round(($day['value'] / $week_max) * 100);
                $is_peak = $h_pct >= 85;
                $bar_bg = $is_peak ? 'background:linear-gradient(180deg, var(--amber-300), #d97706);' : '';
              ?>
                <div class="bar-col" title="Daily Consumed: <?= number_format($day['value']) ?>L">
                  <i style="height:<?= max(2, $h_pct) ?>%; <?= $bar_bg ?>"></i><span><?= h($day['label']) ?></span>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="bar-col" title="Daily Consumed: 2,600L"><i style="height:52%;"></i><span>Mon</span></div>
              <div class="bar-col" title="Daily Consumed: 3,200L"><i style="height:64%;"></i><span>Tue</span></div>
              <div class="bar-col" title="Daily Consumed: 2,400L"><i style="height:48%;"></i><span>Wed</span></div>
              <div class="bar-col" title="Daily Consumed: 3,550L"><i style="height:71%;"></i><span>Thu</span></div>
              <div class="bar-col" title="Daily Consumed: 4,000L"><i style="height:80%;"></i><span>Fri</span></div>
              <div class="bar-col" title="Daily Consumed: 4,750L"><i style="height:95%; background:linear-gradient(180deg, var(--amber-300), #d97706);"></i><span>Sat</span></div>
              <div class="bar-col" title="Daily Consumed: 4,400L"><i style="height:88%; background:linear-gradient(180deg, var(--amber-300), #d97706);"></i><span>Sun</span></div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- TAB 2: ACTIVE / DISABLE FUEL TYPES -->
      <div class="tab-panel" id="tab-types">
        <div class="panel">
          <?php
          $swatch_map = ['p92' => 'var(--sky-400)', 'p95' => 'var(--mint-400)', 'dsl' => 'var(--amber-400)', 'pdsl' => 'var(--blue-500)'];
          foreach ($tank_defs as $fc => $def):
            $t = $tanks[$fc] ?? null;
            $cur = $t ? (int)$t['current_l'] : $def['rem'];
            $swatch = $swatch_map[$fc] ?? 'var(--slate-400)';
            $on = $t ? ((int)$t['is_enabled'] === 1) : true;
            $tank_id_attr = $t ? (int)$t['tank_id'] : '';
            $current_price = 0;
            foreach (($fuelCodeToOilAliases[$fc] ?? [$def['name']]) as $oilAlias) {
              $current_price = $priceByOilName[strtolower($oilAlias)] ?? $current_price;
            }
          ?>
          <div class="type-row" data-tank-id="tank-<?= $fc ?>" data-real-id="<?= $tank_id_attr ?>">
            <div class="info">
              <div class="swatch" style="background:<?= $swatch ?>;"></div>
              <div>
                <b><?= h($t['fuel_name'] ?? $def['name']) ?></b>
                <span class="rem-type-lbl">Current stock — <?= number_format($cur) ?> L · Price <?= number_format($current_price) ?> MMK/L</span>
              </div>
            </div>
            <button class="toggle <?= $on ? 'on' : '' ?>" data-target="tank-<?= $fc ?>" data-tank-id="<?= $tank_id_attr ?>"></button>
          </div>
          <?php endforeach; ?>
        </div>

        <div class="panel" style="padding:18px; margin-top:18px;">
          <div class="section-title"><h3>Station Fuel Price</h3></div>
          <form id="price-form" method="POST" action="manage-oil.php" style="display:grid; grid-template-columns: minmax(180px,1fr) minmax(180px,1fr) auto; gap:12px; align-items:end;">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_price">
            <div class="form-group" style="margin-bottom:0;">
              <label for="price-fuel-input">Fuel Grade</label>
              <select id="price-fuel-input" name="fuel_code" class="form-control-custom" required>
                <?php foreach ($tank_defs as $fc => $def): ?>
                  <option value="<?= h($fc) ?>"><?= h($def['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group" style="margin-bottom:0;">
              <label for="price-input">New Price (MMK/L)</label>
              <input type="number" id="price-input" name="price_per_litre" class="form-control-custom" min="0" step="1" required placeholder="e.g. 3500">
            </div>
            <button type="submit" class="btn-primary" style="box-shadow:none; height:44px;">Save Price</button>
          </form>
        </div>
      </div>

      <div class="page-foot">© 2026 MAP & PUMP | All Rights Reserved</div>
    </div>
  </div>
</div>

<!-- ---------- Manage Oil Limit Modal ---------- -->
<div class="modal-overlay" id="limit-modal">
  <div class="modal-card-custom">
    <div class="modal-header-custom">
      <h3>Manage Oil Limit</h3>
      <button class="modal-close" id="limit-modal-close-btn">&times;</button>
    </div>
    <p style="font-size:12.5px; color:var(--slate-400); margin:-8px 0 18px; line-height:1.6;">
      Set the low-stock alert threshold for each fuel type. A tank flags "Low Stock" once its level falls below this percentage.
    </p>
    <form id="limit-form" method="POST" action="manage-oil.php">
      <?php if (function_exists('csrf_token')): ?>
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <?php endif; ?>
      <input type="hidden" name="action" value="save_limits">
      <div class="form-group">
        <label for="limit-p92">Petrol 92 — Alert Threshold (%)</label>
        <input type="number" id="limit-p92" name="alert_p92" class="form-control-custom" min="5" max="80" value="<?= (int)($tanks['p92']['alert_pct'] ?? 25) ?>" required>
      </div>
      <div class="form-group">
        <label for="limit-p95">Petrol 95 — Alert Threshold (%)</label>
        <input type="number" id="limit-p95" name="alert_p95" class="form-control-custom" min="5" max="80" value="<?= (int)($tanks['p95']['alert_pct'] ?? 25) ?>" required>
      </div>
      <div class="form-group">
        <label for="limit-dsl">Diesel — Alert Threshold (%)</label>
        <input type="number" id="limit-dsl" name="alert_dsl" class="form-control-custom" min="5" max="80" value="<?= (int)($tanks['dsl']['alert_pct'] ?? 30) ?>" required>
      </div>
      <div class="form-group">
        <label for="limit-pdsl">Premium Diesel — Alert Threshold (%)</label>
        <input type="number" id="limit-pdsl" name="alert_pdsl" class="form-control-custom" min="5" max="80" value="<?= (int)($tanks['pdsl']['alert_pct'] ?? 30) ?>" required>
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
    <form id="delivery-form" method="POST" action="manage-oil.php">
      <?php if (function_exists('csrf_token')): ?>
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <?php endif; ?>
      <input type="hidden" name="action" value="add_delivery">
      <div class="form-group">
        <label for="fuel-type-input">Fuel Grade</label>
        <select id="fuel-type-input" name="fuel_code" class="form-control-custom">
          <option value="p92">Petrol 92</option>
          <option value="p95">Petrol 95</option>
          <option value="dsl">Diesel</option>
          <option value="pdsl">Premium Diesel</option>
        </select>
      </div>
      <div class="form-group">
        <label for="quantity-input">Quantity (Liters)</label>
        <input type="number" id="quantity-input" name="quantity_l" class="form-control-custom" required min="1" max="15000" placeholder="e.g. 5000">
      </div>
      <div class="form-group">
        <label for="supplier-input">Supplier</label>
        <input type="text" id="supplier-input" name="supplier" class="form-control-custom" required placeholder="e.g. Puma Energy">
      </div>
      <div class="form-group">
        <label for="handler-input">Received By</label>
        <input type="text" id="handler-input" name="received_by" class="form-control-custom" required placeholder="e.g. Felix">
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

    // 2. Notification Popup Controller (dashboard.php matching)
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

    // 3. Local State Management (hydrated from server)
    const stockDB = {
      p92: { capacity: <?= (int)($tanks['p92']['capacity_l'] ?? 15000) ?>, remaining: <?= (int)($tanks['p92']['current_l'] ?? 11100) ?> },
      p95: { capacity: <?= (int)($tanks['p95']['capacity_l'] ?? 10000) ?>, remaining: <?= (int)($tanks['p95']['current_l'] ?? 4800) ?> },
      dsl: { capacity: <?= (int)($tanks['dsl']['capacity_l'] ?? 12000) ?>, remaining: <?= (int)($tanks['dsl']['current_l'] ?? 2640) ?> },
      pdsl: { capacity: <?= (int)($tanks['pdsl']['capacity_l'] ?? 12000) ?>, remaining: <?= (int)($tanks['pdsl']['current_l'] ?? 0) ?> }
    };

    let oilLimits = {
      p92: <?= (int)($tanks['p92']['alert_pct'] ?? 25) ?>,
      p95: <?= (int)($tanks['p95']['alert_pct'] ?? 25) ?>,
      dsl: <?= (int)($tanks['dsl']['alert_pct'] ?? 30) ?>,
      pdsl: <?= (int)($tanks['pdsl']['alert_pct'] ?? 30) ?>
    };

    // Synchronize visual state across tanks
    function syncTanksUI() {
      ['p92', 'p95', 'dsl', 'pdsl'].forEach(key => {
        const item = stockDB[key];
        const used = item.capacity - item.remaining;
        const percent = Math.min(100, Math.round((item.remaining / item.capacity) * 100));

        const pctEl = document.getElementById(`pct-${key}`);
        const gaugeEl = document.getElementById(`gauge-${key}`);
        const remEl = document.getElementById(`rem-${key}`);
        const usedEl = document.getElementById(`used-${key}`);
        const flagEl = document.getElementById(`flag-${key}`);

        if (pctEl) pctEl.textContent = `${percent}%`;
        if (gaugeEl) gaugeEl.style.width = `${percent}%`;
        if (remEl) remEl.textContent = `${item.remaining.toLocaleString()} L remaining`;
        if (usedEl) usedEl.textContent = `${used.toLocaleString()} L used`;

        const typeRow = document.querySelector(`.type-row[data-tank-id="tank-${key}"] .rem-type-lbl`);
        if(typeRow) typeRow.textContent = `Current stock — ${item.remaining.toLocaleString()} L`;

        if (flagEl && gaugeEl && pctEl) {
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
        }
      });
    }

    // Modal Control: Threshold Limits
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
      oilLimits = {
        p92: parseInt(document.getElementById('limit-p92').value),
        p95: parseInt(document.getElementById('limit-p95').value),
        dsl: parseInt(document.getElementById('limit-dsl').value),
        pdsl: parseInt(document.getElementById('limit-pdsl').value)
      };
      syncTanksUI();
    });

    // Modal Control: Delivery Records
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

    // Dynamic Switch Controls
    const toggleButtons = document.querySelectorAll('.toggle:not([disabled])');
    toggleButtons.forEach(btn => {
      btn.addEventListener('click', async () => {
        const wasOn = btn.classList.contains('on');
        const nextOn = !wasOn;
        const realTankId = btn.getAttribute('data-tank-id');
        if (!realTankId) {
          alert('This fuel type is not initialized for this station yet.');
          return;
        }

        const fd = new FormData();
        fd.append('csrf', '<?= h(csrf_token()) ?>');
        fd.append('action', 'toggle_fuel');
        fd.append('tank_id', realTankId);
        fd.append('enabled', nextOn ? '1' : '0');

        try {
          const res = await fetch('manage-oil.php', { method: 'POST', body: fd });
          const data = await res.json();
          if (!data.ok) {
            alert('Update failed: ' + (data.error || 'unknown'));
            return;
          }
        } catch (err) {
          alert('Network error: ' + err.message);
          return;
        }

        btn.classList.toggle('on', nextOn);
        const targetId = btn.getAttribute('data-target');
        const tankCard = document.getElementById(targetId);

        if (tankCard) {
          if (!nextOn) {
            tankCard.classList.add('disabled-tank');
            const flag = tankCard.querySelector('.tank-flag');
            if (flag) {
              flag.className = "tank-flag inactive";
              flag.innerHTML = `<i class="fa-solid fa-ban"></i> Out of Service`;
            }
          } else {
            tankCard.classList.remove('disabled-tank');
            syncTanksUI();
          }
        }
      });
    });

    const priceForm = document.getElementById('price-form');
    if (priceForm) {
      priceForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        try {
          const res = await fetch('manage-oil.php', { method: 'POST', body: new FormData(priceForm) });
          const data = await res.json();
          if (!data.ok) {
            alert('Price update failed: ' + (data.error || 'unknown'));
            return;
          }
          window.location.reload();
        } catch (err) {
          alert('Network error: ' + err.message);
        }
      });
    }
  });
</script>
</body>
</html>
