<?php
/**
 * dashboard.php  —  Branch Manager landing page.
 * Loads: KPIs, live queue, alerts, recent notifications.
 */
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../conn.php';
require_branch_manager();

$ctx      = current_context($pdo);
$user     = $ctx['user'];
$branch   = $ctx['branch'];
$initials = initials($user['full_name']);
$bid      = (int)$branch['branch_id'];

// ---- KPI: Tokens issued today ----
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM booking bk
    INNER JOIN station_business_scope sbs ON sbs.station_id = bk.station_id
    WHERE sbs.branch_manager_user_id = :manager_id
      AND DATE(bk.created_at) = CURDATE()
");
$stmt->execute([':manager_id' => $user['user_id']]);
$tokens_today = (int)$stmt->fetchColumn();

// tokens issued yesterday for comparison
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM booking bk
    INNER JOIN station_business_scope sbs ON sbs.station_id = bk.station_id
    WHERE sbs.branch_manager_user_id = :manager_id
      AND DATE(bk.created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
");
$stmt->execute([':manager_id' => $user['user_id']]);
$tokens_yesterday = (int)$stmt->fetchColumn();
$tokens_delta_pct = $tokens_yesterday > 0
    ? round((($tokens_today - $tokens_yesterday) / $tokens_yesterday) * 100)
    : 0;

// currently waiting
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM booking bk
    INNER JOIN station_business_scope sbs ON sbs.station_id = bk.station_id
    WHERE sbs.branch_manager_user_id = :manager_id
      AND bk.status = 'expired'
");
$stmt->execute([':manager_id' => $user['user_id']]);
$expired_tokens = (int)$stmt->fetchColumn();

// average wait time (minutes) — for tokens completed today
$stmt = $pdo->prepare("
    SELECT AVG(TIMESTAMPDIFF(MINUTE, booked_at, filled_at))
    FROM usage_history uh
    INNER JOIN station_business_scope sbs ON sbs.station_id = uh.station_id
    WHERE sbs.branch_manager_user_id = :manager_id
      AND DATE(uh.filled_at) = CURDATE()
");
$stmt->execute([':manager_id' => $user['user_id']]);
$avg_wait_min = round((float)$stmt->fetchColumn());

// avg fuel stock across all tanks
$stmt = $pdo->prepare("
    SELECT ROUND(AVG(current_l / capacity_l * 100)) AS pct
    FROM tanks WHERE branch_id = :b AND capacity_l > 0
");
$stmt->execute([':b' => $bid]);
$avg_stock_pct = (int)$stmt->fetchColumn();

// low stock tank name (if any)
$stmt = $pdo->prepare("
    SELECT COALESCE(ot.oil_name, CONCAT('Fuel ', t.tank_id)) AS fuel_name
    FROM tanks t
    LEFT JOIN oil_types ot ON ot.oil_type_id = t.oil_type_id
    WHERE t.branch_id = :b AND (t.current_l / t.capacity_l * 100) < t.alert_pct
    ORDER BY (t.current_l / t.capacity_l) ASC LIMIT 1
");
$stmt->execute([':b' => $bid]);
$low_stock_fuel = $stmt->fetchColumn() ?: null;

// fetch tanks for visualizer (current liters, capacity, alert threshold)
$stmt = $pdo->prepare("
  SELECT t.tank_id,
         COALESCE(ot.oil_name, CONCAT('Fuel ', t.tank_id)) AS fuel_name,
         t.current_l,
         t.capacity_l,
         t.alert_pct
  FROM tanks t
  LEFT JOIN oil_types ot ON ot.oil_type_id = t.oil_type_id
  WHERE t.branch_id = :b AND t.capacity_l > 0
  ORDER BY COALESCE(ot.oil_name, CONCAT('Fuel ', t.tank_id)) ASC
");
$stmt->execute([':b' => $bid]);
$tanks = $stmt->fetchAll();

$manager_id = (int) $user['user_id'];

$alerts = notif_recent($pdo, $manager_id, 5);
$unread_count = notif_unread_count($pdo, $manager_id);
$has_unread = $unread_count > 0;

function map_pump_format_compact_mmk(float $value): string {
    $amount = abs($value);
    if ($amount >= 1000000000) {
        return number_format($amount / 1000000000, 1, '.', '') . 'B';
    }
    if ($amount >= 1000000) {
        return number_format($amount / 1000000, 1, '.', '') . 'M';
    }
    if ($amount >= 1000) {
        return number_format($amount / 1000, 1, '.', '') . 'K';
    }
    return number_format((float) $value, 0, '.', '');
}

$revenue_stmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN DATE(uh.filled_at) = CURDATE() THEN COALESCE(uh.total_cost, 0) ELSE 0 END), 0) AS today_mmk,
        COALESCE(SUM(CASE WHEN DATE(uh.filled_at) BETWEEN DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND CURDATE() THEN COALESCE(uh.total_cost, 0) ELSE 0 END), 0) AS week_mmk,
        COALESCE(SUM(CASE WHEN DATE_FORMAT(uh.filled_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m') THEN COALESCE(uh.total_cost, 0) ELSE 0 END), 0) AS month_mmk,
        COALESCE(SUM(CASE WHEN YEAR(uh.filled_at) = YEAR(CURDATE()) THEN COALESCE(uh.total_cost, 0) ELSE 0 END), 0) AS annual_mmk,
        COALESCE(SUM(CASE WHEN uh.filled_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) THEN COALESCE(uh.total_cost, 0) ELSE 0 END), 0) AS rolling_30d_mmk
    FROM usage_history uh
    INNER JOIN station_business_scope sbs ON sbs.station_id = uh.station_id
    WHERE sbs.branch_manager_user_id = :manager_id
");
$revenue_stmt->execute([':manager_id' => $manager_id]);
$revenue_stats = $revenue_stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$annual_revenue_display  = map_pump_format_compact_mmk((float) ($revenue_stats['annual_mmk'] ?? 0));
$monthly_revenue_display = map_pump_format_compact_mmk((float) ($revenue_stats['month_mmk'] ?? 0));
$weekly_revenue_display  = map_pump_format_compact_mmk((float) ($revenue_stats['week_mmk'] ?? 0));
$today_revenue_display   = map_pump_format_compact_mmk((float) ($revenue_stats['today_mmk'] ?? 0));
$avg_daily_revenue_display = map_pump_format_compact_mmk(((float) ($revenue_stats['rolling_30d_mmk'] ?? 0) / 30));

$cancel_stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM booking_cancellation bc
    INNER JOIN booking bk ON bk.booking_id = bc.booking_id
    INNER JOIN station_business_scope sbs ON sbs.station_id = bk.station_id
    WHERE sbs.branch_manager_user_id = :manager_id
      AND DATE(bc.created_at) = CURDATE()
");
$cancel_stmt->execute([':manager_id' => $manager_id]);
$bookings_canceled_today = (int) $cancel_stmt->fetchColumn();

function map_pump_build_sales_chart_data(PDO $pdo, int $manager_id, string $range): array {
    $now = new DateTimeImmutable('now');
    $labels = [];
    $dates = [];
    $buckets = [];

    if ($range === 'week') {
        $start = $now->modify('-6 days')->setTime(0, 0, 0);
        $end = $now->setTime(23, 59, 59);
        for ($i = 0; $i < 7; $i++) {
            $day = $start->modify('+' . $i . ' days');
            $key = $day->format('Y-m-d');
            $buckets[$key] = ['total' => 0.0, 'litres' => 0.0, 'fuels' => []];
            $labels[] = $day->format('D');
            $dates[] = $day->format('D, M j, Y');
        }
        $group_sql = 'DATE(uh.filled_at)';
        $bucket_sql = 'DATE(uh.filled_at)';
    } elseif ($range === 'month') {
        $start = $now->modify('-29 days')->setTime(0, 0, 0);
        $end = $now->setTime(23, 59, 59);
        for ($i = 0; $i < 30; $i++) {
            $day = $start->modify('+' . $i . ' days');
            $key = $day->format('Y-m-d');
            $buckets[$key] = ['total' => 0.0, 'litres' => 0.0, 'fuels' => []];
            $labels[] = $day->format('d');
            $dates[] = $day->format('D, M j, Y');
        }
        $group_sql = 'DATE(uh.filled_at)';
        $bucket_sql = 'DATE(uh.filled_at)';
    } else {
        $start = new DateTimeImmutable(date('Y') . '-01-01 00:00:00');
        $end = $now->setTime(23, 59, 59);
        for ($i = 0; $i < 12; $i++) {
            $month = new DateTimeImmutable(sprintf('%d-%02d-01', (int) date('Y'), $i + 1));
            $key = $month->format('Y-m');
            $buckets[$key] = ['total' => 0.0, 'litres' => 0.0, 'fuels' => []];
            $labels[] = $month->format('M');
            $dates[] = $month->format('F Y');
        }
        $group_sql = 'DATE_FORMAT(uh.filled_at, "%Y-%m")';
        $bucket_sql = 'DATE_FORMAT(uh.filled_at, "%Y-%m")';
    }

    $stmt = $pdo->prepare("
        SELECT {$bucket_sql} AS bucket_key,
               COALESCE(ot.oil_name, 'Unknown fuel') AS fuel_name,
               SUM(COALESCE(uh.total_cost, 0)) AS sales_mmk,
               SUM(COALESCE(uh.filled_liters, 0)) AS litres_l
        FROM usage_history uh
        INNER JOIN station_business_scope sbs ON sbs.station_id = uh.station_id
        LEFT JOIN oil_types ot ON ot.oil_type_id = uh.oil_type_id
        WHERE sbs.branch_manager_user_id = :manager_id
          AND uh.filled_at >= :start_dt
          AND uh.filled_at <= :end_dt
        GROUP BY {$group_sql}, COALESCE(ot.oil_name, 'Unknown fuel')
        ORDER BY {$group_sql}
    ");
    $stmt->execute([
        ':manager_id' => $manager_id,
        ':start_dt' => $start->format('Y-m-d H:i:s'),
        ':end_dt' => $end->format('Y-m-d H:i:s'),
    ]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $bucket_key = (string) ($row['bucket_key'] ?? '');
        if ($bucket_key === '') {
            continue;
        }
        if (!isset($buckets[$bucket_key])) {
            $buckets[$bucket_key] = ['total' => 0.0, 'litres' => 0.0, 'fuels' => []];
        }
        $fuel_name = (string) ($row['fuel_name'] ?: 'Unknown fuel');
        $sales = (float) ($row['sales_mmk'] ?? 0);
        $litres = (float) ($row['litres_l'] ?? 0);
        $buckets[$bucket_key]['fuels'][$fuel_name] = ['sales' => $sales, 'litres' => $litres];
        $buckets[$bucket_key]['total'] += $sales;
        $buckets[$bucket_key]['litres'] += $litres;
    }

    $rows = [];
    if ($range === 'week' || $range === 'month') {
        for ($i = 0; $i < count($labels); $i++) {
            $day = $start->modify('+' . $i . ' days');
            $bucket_key = $day->format('Y-m-d');
            $rows[] = $buckets[$bucket_key] ?? ['total' => 0.0, 'litres' => 0.0, 'fuels' => []];
        }
    } else {
        for ($i = 0; $i < count($labels); $i++) {
            $month = new DateTimeImmutable(sprintf('%d-%02d-01', (int) date('Y'), $i + 1));
            $bucket_key = $month->format('Y-m');
            $rows[] = $buckets[$bucket_key] ?? ['total' => 0.0, 'litres' => 0.0, 'fuels' => []];
        }
    }

    return [
        'xTitle' => $range === 'year' ? 'Months' : 'Days',
        'labels' => $labels,
        'dates' => $dates,
        'rows' => $rows,
    ];
}

$sales_chart_data = [
    'week' => map_pump_build_sales_chart_data($pdo, $manager_id, 'week'),
    'month' => map_pump_build_sales_chart_data($pdo, $manager_id, 'month'),
    'year' => map_pump_build_sales_chart_data($pdo, $manager_id, 'year'),
];

$sales_fuel_names = [];
foreach ($tanks as $tankRow) {
    $fuelName = preg_replace('/^Petrol\s+/i', 'Octane ', trim((string) ($tankRow['fuel_name'] ?? '')));
    if ($fuelName === '') {
        continue;
    }
    if (!in_array($fuelName, $sales_fuel_names, true)) {
        $sales_fuel_names[] = $fuelName;
    }
}
sort($sales_fuel_names, SORT_STRING);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MAP & PUMP — Branch Dashboard</title>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="icon" type="image/png" href="/images/Logo.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@400;500;600;700&family=Noto+Sans+Myanmar:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
  :root{
    --navy-950:#070b14; --navy-900:#0b1220; --navy-800:#101a30; --navy-700:#16213f;
    --blue-700:#1d4ed8; --blue-500:#2f6feb; --sky-400:#38bdf8;
    --amber-400:#fbbf24; --amber-300:#fcd34d; --mint-400:#34d399;
    --red-400:#f87171;
    --slate-50:#f8fafc; --slate-300:#c9d2e3; --slate-400:#8b96ad;
  }
  body.branch-manager-theme {
    --navy-950:#070b14; --navy-900:#0b1220; --navy-800:#101a30; --navy-700:#16213f; --navy-600:#1a2438;
    --blue-700:#1d4ed8; --blue-500:#2f6feb; --sky-400:#38bdf8;
    --amber-400:#fbbf24; --amber-300:#fcd34d; --mint-400:#34d399; --red-400:#f87171;
    --slate-50:#f8fafc; --slate-300:#c9d2e3; --slate-400:#8b96ad;
    background: var(--navy-950); color: var(--slate-50);
  }
  body.branch-manager-theme.light-mode {
    --navy-950:#f3f6fb; --navy-900:#ffffff; --navy-800:#f7f9fc; --navy-700:#edf3fb; --navy-600:#e5edf8;
    --blue-700:#286be0; --blue-500:#3c82ff; --sky-400:#3f8cf5; --amber-400:#d98400; --amber-300:#e2a72d; --mint-400:#128e6b; --red-400:#cf3d3d;
    --slate-50:#172337; --slate-300:#3c4d68; --slate-400:#58677e; background: var(--navy-950); color: var(--slate-50);
  }
  body.branch-manager-theme.light-mode .sidebar,
  body.branch-manager-theme.light-mode .topbar,
  body.branch-manager-theme.light-mode .panel,
  body.branch-manager-theme.light-mode .stat-card,
  body.branch-manager-theme.light-mode .module-card,
  body.branch-manager-theme.light-mode .notif-popup,
  body.branch-manager-theme.light-mode .bell,
  body.branch-manager-theme.light-mode .side-branch,
  body.branch-manager-theme.light-mode .avatar-chip,
  body.branch-manager-theme.light-mode .color-modal,
  body.branch-manager-theme.light-mode .sales-panel,
  body.branch-manager-theme.light-mode .fuel-color-table tbody tr,
  body.branch-manager-theme.light-mode .form-control-custom,
  body.branch-manager-theme.light-mode .modal-card-custom,
  body.branch-manager-theme.light-mode .account-profile-card,
  body.branch-manager-theme.light-mode .account-sidecard {
    background: var(--navy-900); border-color: rgba(17,24,39,0.12); color: var(--slate-50);
  }
  body.branch-manager-theme.light-mode .side-link,
  body.branch-manager-theme.light-mode .side-branch span,
  body.branch-manager-theme.light-mode .topbar .date,
  body.branch-manager-theme.light-mode .notif-item-body span,
  body.branch-manager-theme.light-mode .alert-row span,
  body.branch-manager-theme.light-mode .queue-row .pump,
  body.branch-manager-theme.light-mode .module-card p,
  body.branch-manager-theme.light-mode .stat-card .lbl,
  body.branch-manager-theme.light-mode .panel h3,
  body.branch-manager-theme.light-mode .report-row p,
  body.branch-manager-theme.light-mode .field label,
  body.branch-manager-theme.light-mode .account-password-hint {
    color: var(--slate-300);
  }
  body.branch-manager-theme.light-mode .side-link.active,
  body.branch-manager-theme.light-mode .side-link:hover,
  body.branch-manager-theme.light-mode .avatar-chip:hover,
  body.branch-manager-theme.light-mode .bell:hover {
    background: rgba(63, 140, 245, 0.10);
    color: var(--slate-50);
  }
  .staff-theme-control {
    position: relative; display: grid; width: 28px; height: 32px; place-items: center; margin: 0; color: var(--slate-300); cursor: pointer;
  }
  .staff-theme-control input { position: absolute; width: 1px; height: 1px; opacity: 0; }
  .staff-theme-control span { font-size: 1.05rem; }
  .staff-theme-control:focus-within span { outline: 2px solid var(--sky-400); outline-offset: 4px; border-radius: 3px; }
  body.branch-manager-theme.light-mode .staff-theme-control { color: var(--slate-400); }

  *{box-sizing:border-box; margin:0; padding:0;}
  
  /* Reset all elements to default cursor */
  *, a, button, .bell, .avatar-chip, .alert-row, .side-link, .side-logout, .module-card, #mark-all-read {
    cursor: default !important;
  }

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
    display:flex; align-items:center; gap:10px;
  }
  .side-branch:hover{
    background: var(--navy-700);
    border-color: rgba(56,189,248,0.2);
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
    justify-content:center; border:1px solid rgba(255,255,255,0.08); position:relative; font-size:15px; color:var(--slate-300);
    transition: transform 0.2s, background-color 0.2s;
  }
  .bell:hover{
    background: var(--navy-700);
    transform: scale(1.05);
  }
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

  .avatar-chip{display:flex; align-items:center; gap:10px; font-size:13px; padding: 4px 8px; border-radius: 99px; transition: background 0.2s;}
  .avatar-chip:hover{background: rgba(255,255,255,0.05);}
  .avatar-circle{width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,var(--sky-400),var(--blue-700)); display:flex; align-items:center; justify-content:center; font-weight:700; font-size:13px; font-family:'Space Grotesk';}

  /* Cascade Entrance Loading Animation */
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

  /* ---------- Top stat cards ---------- */
  .stats-grid{display:grid; grid-template-columns:1fr 1fr; gap:18px; margin-bottom:18px; align-items:stretch;}
  .stats-side{display:grid; grid-template-rows:repeat(3,1fr); gap:14px;}
  .stat-card{
    background:linear-gradient(160deg, var(--navy-800), var(--navy-900)); border:1px solid rgba(255,255,255,0.07);
    border-radius:22px; padding:24px 26px; position:relative; overflow:hidden;
    transition: transform 0.3s cubic-bezier(0.2, 0.8, 0.2, 1), border-color 0.3s, box-shadow 0.3s;
  }
  .stat-card:hover{transform:translateY(-3px); border-color:rgba(56,189,248,0.25); box-shadow:0 12px 24px -10px rgba(0,0,0,0.5);}
  .stat-card .lbl{font-size:13px; color:var(--slate-400); margin-bottom:10px;}
  .stat-card .val{font-family:'Space Grotesk'; font-size:30px; font-weight:700; line-height:1.1; transition:color 0.3s;}
  .stat-card .val small{font-size:16px; font-weight:600; color:var(--slate-300); margin-left:6px;}
  .stat-card:hover .val{color:var(--sky-400);}
  .stat-side{display:flex; flex-direction:column; justify-content:center; padding:18px 26px;}
  .stat-main{display:flex; flex-direction:column;}
  .stat-main .lbl{font-size:13px; letter-spacing:0.14em; text-transform:uppercase;}
  .stat-main .val{font-size:52px;}
  .stat-main .val small{font-size:18px; letter-spacing:0.02em;}
  .stat-sub{margin-top:auto; padding-top:22px; border-top:1px solid rgba(255,255,255,0.08); display:grid; grid-template-columns:1fr 1fr; gap:18px;}
  .stat-sub .val{font-size:26px;}
  .stat-sub .val small{font-size:11px;}
  .stat-sub .lbl{margin-bottom:8px; letter-spacing:0; text-transform:none;}
  .stat-sub + .stat-sub{margin-top:22px;}
  @media (max-width:980px){ .stats-grid{grid-template-columns:1fr;} .stat-main{min-height:260px;} }

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

  .mid-row{display:grid; grid-template-columns:1fr 1fr; gap:18px; margin-bottom:18px; align-items:stretch;}
  .mid-row > .mid-col{display:flex; flex-direction:column;}
  .mid-row > .mid-col > .panel, .mid-row > .panel{flex:1;}
  @media (max-width:980px){.mid-row{grid-template-columns:1fr;}}

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
    border-radius: 8px; transition: background-color 0.2s, opacity 0.3s, transform 0.3s;
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
  /* Tank visualizer styles */
  .tank-visualizer { display:flex; gap:18px; align-items:stretch; justify-content:flex-start; flex-wrap:wrap; }
  .tank { width:110px; min-height:260px; background:linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01)); border:1px solid rgba(148, 197, 255, 0.8); border-radius:10px; padding:12px; display:flex; flex-direction:column; align-items:center; gap:10px; box-shadow:0 0 0 1px rgba(255,255,255,0.02); }
  .tank { --tank-accent:#7cc8ff; border-color: rgba(124, 200, 255, 0.9); box-shadow: 0 10px 20px -16px rgba(124, 200, 255, 0.65); }
  .tank .tank-body { width:36px; height:180px; background:linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01)); border-radius:6px; position:relative; overflow:hidden; border:1px solid rgba(255,255,255,0.03); }
  .tank .tank-fill { position:absolute; left:0; bottom:0; width:100%; background:linear-gradient(180deg, var(--sky-400), rgba(56,189,248,0.85)); display:flex; align-items:flex-end; justify-content:center; transition:height 0.6s ease; }
  .tank .tank-label { font-weight:700; font-size:13px; text-align:center; min-height:32px; display:flex; align-items:center; justify-content:center; }
  .tank .tank-meta { font-size:12px; color:var(--slate-400); text-align:center; }
  .tank .tank-status { margin-top:auto; padding:6px 10px; border-radius:999px; font-weight:700; font-size:11px; }
  .tank-status.ok { background: rgba(52,211,153,0.12); color: var(--mint-400); border:1px solid rgba(52,211,153,0.06); }
  .tank-status.low { background: rgba(248,113,113,0.07); color: var(--red-400); border:1px solid rgba(248,113,113,0.04); }
  /* Tank Levels pagination: 4 tanks per page, always on one row */
  .tank-visualizer { flex-wrap:nowrap; }
  .tank-visualizer .tank { flex:0 1 110px; min-width:0; }
  .pg-controls { display:flex; align-items:center; justify-content:center; gap:6px; margin-top:18px; flex-wrap:wrap; }
  .pg-btn { background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.10); color:var(--slate-300); border-radius:8px; padding:6px 13px; font-size:12.5px; font-weight:600; cursor:pointer; transition:background 0.18s, color 0.18s, border-color 0.18s; min-width:34px; text-align:center; }
  .pg-btn:hover:not(:disabled) { background:rgba(255,255,255,0.10); color:var(--slate-50); }
  .pg-btn.active { background:linear-gradient(135deg, #3b8dff, #1d4ed8); border-color:#2f6feb; color:#fff; box-shadow:0 8px 18px -10px rgba(59,141,255,0.75); }
  .pg-btn.active:hover:not(:disabled) { background:linear-gradient(135deg, #3b8dff, #1d4ed8); border-color:#2f6feb; color:#fff; box-shadow:0 8px 18px -10px rgba(59,141,255,0.75); }
  .pg-btn:disabled { opacity:0.35; cursor:default; }

  /* Color Editor Modal Styles */
  .tank-panel-header{display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:14px;}
  .tank-panel-header h3{margin-bottom:0;}
  .edit-color-btn{display:inline-flex; align-items:center; gap:8px; border:1px solid rgba(255,255,255,0.12); background:rgba(255,255,255,0.03); color:var(--slate-50); border-radius:999px; padding:7px 11px; font-size:11.5px; font-weight:700; transition: all 0.2s ease;}
  .edit-color-btn:hover{background:rgba(56,189,248,0.08); border-color:rgba(56,189,248,0.28); color:var(--sky-400);}
  .color-modal-backdrop{position:fixed; inset:0; background:rgba(2,6,23,0.72); display:none; align-items:center; justify-content:center; z-index:200; padding:20px;}
  .color-modal-backdrop.show{display:flex;}
  .color-modal{width:min(720px, 100%); background:linear-gradient(160deg, var(--navy-800), var(--navy-900)); border:1px solid rgba(255,255,255,0.08); border-radius:18px; padding:22px; box-shadow:0 18px 40px rgba(0,0,0,0.45);}
  .color-modal-header{display:flex; align-items:center; justify-content:space-between; margin-bottom:16px;}
  .color-modal-header h4{font-size:18px; margin:0; font-weight:700;}
  .color-modal-close{width:32px; height:32px; border-radius:50%; background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.08); color:var(--slate-50); font-size:18px; display:flex; align-items:center; justify-content:center;}
  .fuel-color-table{width:100%; border-collapse:collapse; table-layout:fixed;}
  .fuel-color-table th, .fuel-color-table td{padding:12px 10px; border-bottom:1px solid rgba(255,255,255,0.06); text-align:left;}
  .fuel-color-table th{font-size:11px; color:var(--slate-400); text-transform:uppercase; letter-spacing:0.08em; font-weight:700;}
  .fuel-color-table tbody tr{cursor:pointer; transition:background 0.2s ease;}
  .fuel-color-table tbody tr:hover{background:rgba(255,255,255,0.02);}
  .fuel-color-row{display:flex; align-items:center; gap:10px;}
  .fuel-swatch{width:18px; height:18px; border-radius:6px; border:2px solid rgba(255,255,255,0.2); box-shadow:inset 0 0 0 1px rgba(0,0,0,0.2); flex-shrink:0;}
  .color-picker-cell{display:flex; align-items:center; gap:10px; justify-content:flex-start;}
  .color-preview{display:inline-flex; align-items:center; gap:10px; padding:8px 10px; border-radius:10px; border:1px solid rgba(255,255,255,0.08); background:rgba(255,255,255,0.02); min-width:150px; cursor:pointer;}
  .color-preview-chip{width:18px; height:18px; border-radius:6px; border:2px solid rgba(255,255,255,0.2);}
  .color-preview-text{font-size:12px; font-weight:700; color:var(--slate-50);}
  .color-picker-input{position:absolute; opacity:0; width:1px; height:1px; pointer-events:none;}
  .fuel-color-save{margin-top:18px; display:flex; justify-content:flex-end;}
  .fuel-color-save button{background:linear-gradient(135deg, var(--sky-400), var(--blue-500)); color:white; border:none; border-radius:10px; padding:10px 16px; font-weight:700; font-size:12.5px;}

  /* ---------- Sales Performance ---------- */
  .sales-panel{padding:22px 26px 18px;}
  .sales-head{display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:14px;}
  .sales-head h3{margin:0;}
  .sales-controls{display:flex; align-items:center; gap:8px; flex-wrap:wrap;}
  .rng-btn{background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.10); color:var(--slate-300); border-radius:12px; padding:8px 18px; font-size:13px; font-weight:600; font-family:inherit; transition:background .18s, color .18s, border-color .18s, box-shadow .18s;}
  .rng-btn:hover{background:rgba(255,255,255,0.10); color:var(--slate-50);}
  .rng-btn.active, .rng-btn.active:hover{background:linear-gradient(135deg,#3b8dff,#1d4ed8); border-color:#2f6feb; color:#fff; box-shadow:0 8px 18px -10px rgba(59,141,255,0.75);}
  .fuel-select{appearance:none; -webkit-appearance:none; background:rgba(255,255,255,0.04) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1.5l5 5 5-5' fill='none' stroke='%23c9d2e3' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E") no-repeat right 14px center; border:1px solid rgba(255,255,255,0.14); color:var(--slate-50); border-radius:12px; padding:8px 38px 8px 14px; min-width:150px; font-size:13px; font-weight:600; font-family:inherit; outline:none; transition:border-color .18s, box-shadow .18s;}
  .fuel-select:hover{border-color:rgba(255,255,255,0.28);}
  .fuel-select:focus{border-color:#fff; box-shadow:0 0 0 2px rgba(255,255,255,0.18);}
  .fuel-select option{background:#fff; color:#111;}
  .sales-chart{position:relative; width:100%; height:360px; user-select:none; -webkit-user-select:none; touch-action:pan-y;}
  .sales-chart svg{position:absolute; inset:0; width:100%; height:100%; display:block; overflow:visible;}
  .sc-grid{stroke:rgba(148,163,184,0.14); stroke-width:1;}
  .sc-axis{stroke:rgba(148,163,184,0.28); stroke-width:1;}
  .sc-tick{fill:var(--slate-400); font-size:11.5px; font-family:'Inter','Space Grotesk',sans-serif;}
  .sc-title{fill:var(--slate-300); font-size:12px; font-weight:700; font-family:'Inter','Space Grotesk',sans-serif;}
  .sc-line{fill:none; stroke:var(--sky-400); stroke-width:2.2; stroke-linejoin:round; stroke-linecap:round;}
  .sc-dot{fill:var(--navy-900); stroke:var(--sky-400); stroke-width:2;}
  .sc-cross{stroke:rgba(201,210,227,0.55); stroke-width:1; stroke-dasharray:4 4;}
  .sc-ball{fill:var(--blue-500); stroke:#fff; stroke-width:3; filter:drop-shadow(0 0 6px rgba(56,189,248,0.8));}
  .sc-tip{position:absolute; left:0; top:0; min-width:230px; background:rgba(22,33,63,0.97); border:1px solid rgba(255,255,255,0.12); border-radius:14px; padding:14px 16px; box-shadow:0 18px 36px -12px rgba(0,0,0,0.65); pointer-events:none; opacity:0; transition:opacity .15s ease, transform .12s ease-out; z-index:5; font-size:13px;}
  .sc-tip.show{opacity:1;}
  .sc-tip .t-date{color:var(--slate-300); font-weight:500; padding-bottom:10px; border-bottom:1px solid rgba(255,255,255,0.10);}
  .sc-tip .t-sum{display:flex; align-items:baseline; justify-content:space-between; gap:18px; padding:10px 0; border-bottom:1px solid rgba(255,255,255,0.10);}
  .sc-tip .t-amt{font-family:'Space Grotesk'; font-size:20px; font-weight:700; color:#fff; white-space:nowrap;}
  .sc-tip .t-amt small{font-size:11px; font-weight:600; color:var(--slate-300); margin-left:4px;}
  .sc-tip .t-lit{font-weight:600; color:#fff; white-space:nowrap;}
  .sc-tip .t-row{display:flex; align-items:center; justify-content:space-between; gap:18px; padding-top:8px; color:var(--slate-300);}
  .sc-tip .t-row i{display:inline-block; width:9px; height:9px; border-radius:50%; background:#fff; margin-right:9px;}
  .sc-tip .t-row b{color:#fff; font-weight:600; white-space:nowrap;}
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
      <a href="dashboard.php" class="side-link active"><span class="ic"><i class="fa-solid fa-house"></i></span> Dashboard</a>
      <a href="manage-staff.php" class="side-link"><span class="ic"><i class="fa-solid fa-users"></i></span> Manage Staff</a>
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
        <h1>Dashboard</h1>
        <div class="date" id="live-clock">Wednesday · July 9, 2026</div>
      </div>
      <div class="topbar-right">
        <div class="bell" id="notif-bell"><i class="fa-regular fa-bell"></i><?php if ($has_unread): ?><span class="ping"></span><?php endif; ?></div>
        
        <!-- Notification Dropdown Menu -->
        <div class="notif-popup" id="notif-popup">
          <div class="notif-header">
            <h4>Notifications</h4>
            <span id="mark-all-read">Clear All</span>
          </div>
          <div id="notif-list">
            <?php if (!$alerts): ?>
              <div class="notif-empty" style="text-align:center; padding:14px 0; color:var(--slate-400); font-size:12px;">No new notifications</div>
            <?php else:
              $icon_map = [
                'warn' => 'fa-triangle-exclamation text-warning',
                'err'  => 'fa-circle-exclamation text-danger',
                'ok'   => 'fa-truck-ramp-box text-success',
                'info' => 'fa-circle-info text-info',
              ];
              foreach ($alerts as $a):
                $ic = $icon_map[$a['kind']] ?? $icon_map['info']; ?>
              <div class="notif-item" data-notif-id="<?= (int)$a['notif_id'] ?>">
                <i class="fa-solid <?= h($ic) ?> ic"></i>
                <div class="notif-item-body">
                  <b><?= h($a['title']) ?></b>
                  <span><?= h($a['body']) ?></span>
                  <div class="notif-time"><?= h($a['time_ago']) ?></div>
                </div>
              </div>
            <?php endforeach; endif; ?>
          </div>
        </div>

        <label class="staff-theme-control" title="Toggle light mode">
          <input class="js-theme-toggle" type="checkbox" aria-label="Toggle light mode">
          <span><i class="fa-solid fa-sun"></i></span>
        </label>

        <div class="avatar-chip">
          <div class="avatar-circle"><a href="edit-profile.php"><?= h(mb_substr($initials,0,1)) ?></a></div>
          <div><b><a href="edit-profile.php"><?= h(explode(' ', $user['full_name'])[0]) ?></a></b></div>
        </div>
      </div>
    </div>

    <div class="content">
      <div class="stats-grid">
        <div class="stat-card stat-main">
          <div class="lbl">Annual Revenue</div>
          <div class="val"><?= h($annual_revenue_display) ?><small>MMK</small></div>
          <div class="stat-sub">
            <div>
              <div class="lbl">Today's Revenue</div>
              <div class="val"><?= h($today_revenue_display) ?><small>MMK</small></div>
            </div>
            <div>
              <div class="lbl">Avg. Daily Revenue</div>
              <div class="val"><?= h($avg_daily_revenue_display) ?><small>MMK / day</small></div>
            </div>
          </div>
          <div class="stat-sub">
            <div>
              <div class="lbl">Monthly Revenue</div>
              <div class="val"><?= h($monthly_revenue_display) ?><small>MMK</small></div>
            </div>
            <div>
              <div class="lbl">Weekly Revenue</div>
              <div class="val"><?= h($weekly_revenue_display) ?><small>MMK</small></div>
            </div>
          </div>
        </div>
        <div class="stats-side">
          <div class="stat-card stat-side">
            <div class="lbl">Booking Issued Today</div>
            <div class="val" id="issued-count"><?= $tokens_today ?><small>Bookings</small></div>
          </div>
          <div class="stat-card stat-side">
            <div class="lbl">Booking Canceled Today</div>
            <div class="val" id="canceled-count"><?= (int)$bookings_canceled_today ?><small>Bookings</small></div>
          </div>
          <div class="stat-card stat-side">
            <div class="lbl">AVG Fuel Stock</div>
            <div class="val"><?= $avg_stock_pct ?><small>%</small></div>
          </div>
        </div>
      </div>

      <div class="mid-row">
        <div class="mid-col">
          <div class="panel" style="padding:18px;">
            <div class="tank-panel-header">
              <h3 style="margin-bottom:0;">Tank Levels</h3>
              <button type="button" class="edit-color-btn" id="editColorBtn"><i class="fa-solid fa-palette"></i> Edit Color</button>
            </div>
            <div class="tank-visualizer" id="tank-visualizer">
              <?php if (empty($tanks)): ?>
                <div style="color:var(--slate-400);">No tank data configured.</div>
              <?php else: foreach ($tanks as $t): 
                $fuelName = trim((string)$t['fuel_name']);
                $fuelLabel = preg_replace('/^Petrol\s+/i', 'Octane ', $fuelName);
                $fuelKey = $fuelLabel === 'Octane 92' || $fuelLabel === 'Octane 95' || $fuelLabel === 'Octane 98' ? $fuelLabel : $fuelLabel;
                $pct = $t['capacity_l'] > 0 ? (int) round(($t['current_l'] / $t['capacity_l']) * 100) : 0;
                $status_class = $pct < (int)$t['alert_pct'] ? 'low' : 'ok';
                $tankColor = $defaultTankColors[$fuelLabel] ?? $defaultTankColors[$fuelName] ?? '#38bdf8';
                ?>
                <div class="tank" data-fuel-name="<?= h($fuelLabel) ?>" data-tank-color="<?= h($tankColor) ?>">
                  <div class="tank-label"><?= h($fuelLabel) ?></div>
                  <div class="tank-body" aria-hidden="true">
                    <div class="tank-fill" style="height:<?= max(0, min(100, $pct)) ?>%; background:linear-gradient(180deg, <?= h($tankColor) ?>, rgba(255,255,255,0.28));"></div>
                  </div>
                  <div class="tank-meta"><?= number_format((float)$t['current_l'], 2) ?>L / <?= number_format((float)$t['capacity_l'], 0) ?>L</div>
                  <div class="tank-meta"><?= $pct ?>%</div>
                  <div class="tank-status <?= $status_class ?>"><?= $status_class === 'low' ? 'Low Stock' : 'Healthy' ?></div>
                </div>
              <?php endforeach; endif; ?>
            </div>
            <div class="pg-controls" id="tank-visualizer-pg-controls"></div>
          </div>
        </div>

        <div class="panel">
          <h3>Alerts <span style="font-size: 11px; font-weight: normal; color: var(--slate-400); margin-left: 6px;">(Click to dismiss)</span></h3>
          <div class="alert-list" id="alert-container">
            <?php if (!$alerts): ?>
              <div style="text-align:center; padding: 20px; color:var(--slate-400); font-size:12.5px;">All alerts cleared.</div>
            <?php else:
              $kind_map = ['warn'=>'warn','err'=>'err','ok'=>'ok','info'=>'ok'];
              foreach ($alerts as $a):
                $cls = $kind_map[$a['kind']] ?? 'ok'; ?>
              <div class="alert-row <?= h($cls) ?>" data-notif-id="<?= (int)$a['notif_id'] ?>">
                <div class="dot2"></div>
                <div><b><?= h($a['title']) ?></b><span><?= h($a['body']) ?> <small class="dismiss-txt">Dismiss</small></span></div>
              </div>
            <?php endforeach; endif; ?>
          </div>
        </div>
      </div>

      <div class="panel sales-panel" style="margin-top:18px;">
        <div class="sales-head">
          <h3>Sales Performance</h3>
          <div class="sales-controls">
            <button type="button" class="rng-btn active" data-range="week">Week</button>
            <button type="button" class="rng-btn" data-range="month">Month</button>
            <button type="button" class="rng-btn" data-range="year">Year</button>
            <select class="fuel-select" id="salesFuel" aria-label="Fuel filter">
              <option value="all">All Fuels</option>
              <?php foreach ($sales_fuel_names as $fn): ?>
                <option value="<?= h($fn) ?>"><?= h($fn) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="sales-chart" id="salesChart">
          <svg id="salesSvg" role="img" aria-label="Sales performance chart"></svg>
          <div class="sc-tip" id="salesTip"></div>
        </div>
      </div>

      <div class="page-foot">© 2026 MAP & PUMP | All Right Reserved</div>
    </div>
  </div>
</div>

<div class="color-modal-backdrop" id="fuelColorModal" aria-hidden="true">
  <div class="color-modal" role="dialog" aria-modal="true" aria-labelledby="fuelColorTitle">
    <div class="color-modal-header">
      <h4 id="fuelColorTitle">Fuel Colors</h4>
      <button type="button" class="color-modal-close" id="closeFuelColorModal" aria-label="Close">×</button>
    </div>
    <table class="fuel-color-table">
      <thead>
        <tr>
          <th>Fuel Type</th>
          <th>Color</th>
        </tr>
      </thead>
      <tbody id="fuelColorTableBody"></tbody>
    </table>
    <div class="fuel-color-save">
      <button type="button" id="saveFuelColorsBtn">Save Preferences</button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  document.addEventListener('DOMContentLoaded', () => {
    const defaultTankColors = {
      'Octane 92': '#38bdf8',
      'Octane 95': '#2563eb',
      'Diesel': '#34d399',
      'Premium Diesel': '#fbbf24',
      'Super Diesel': '#f59e0b'
    };

    const fuelColorKey = 'mapPumpTankColors';
    let savedColors = {};
    try {
      savedColors = JSON.parse(localStorage.getItem(fuelColorKey) || '{}');
    } catch (_) {}

    const normalizeFuelName = (fuelName) => fuelName ? String(fuelName).replace(/^Petrol\s+/i, 'Octane ') : fuelName;
    const getFuelColor = (fuelName, fallback) => savedColors[normalizeFuelName(fuelName)] || defaultTankColors[normalizeFuelName(fuelName)] || fallback || '#38bdf8';

    function applyTankColors() {
      document.querySelectorAll('.tank').forEach((tankEl) => {
        const fuelName = normalizeFuelName(tankEl.dataset.fuelName || '');
        const color = getFuelColor(fuelName, tankEl.dataset.tankColor || '#38bdf8');
        const fill = tankEl.querySelector('.tank-fill');
        if (fill) fill.style.background = `linear-gradient(180deg, ${color}, rgba(255,255,255,0.28))`;
        tankEl.dataset.tankColor = color;
      });
    }

    function buildFuelColorRows() {
      const rows = [...document.querySelectorAll('.tank')].map((tankEl) => {
        const fuelName = normalizeFuelName(tankEl.dataset.fuelName || '');
        return { fuelName, color: getFuelColor(fuelName, tankEl.dataset.tankColor || '#38bdf8') };
      });
      const uniqueRows = Array.from(new Map(rows.map((row) => [row.fuelName, row])).values());
      const tbody = document.getElementById('fuelColorTableBody');
      if (!tbody) return;
      tbody.innerHTML = uniqueRows.map((row) => `
        <tr data-fuel-name="${row.fuelName}">
          <td><div class="fuel-color-row"><span class="fuel-swatch" style="background:${row.color};"></span><span>${row.fuelName}</span></div></td>
          <td><div class="color-picker-cell"><label class="color-preview" for="color-${row.fuelName.replace(/\s+/g, '-')}"><span class="color-preview-chip" style="background:${row.color};"></span><span class="color-preview-text">${row.color.toUpperCase()}</span></label><input class="color-picker-input" id="color-${row.fuelName.replace(/\s+/g, '-')}" type="color" value="${row.color}" data-fuel-name="${row.fuelName}"></div></td>
        </tr>
      `).join('');

      tbody.querySelectorAll('tr').forEach((rowEl) => {
        rowEl.addEventListener('click', (event) => {
          if (event.target.closest('input')) return;
          const input = rowEl.querySelector('input[type="color"]');
          if (input) input.click();
        });
      });

      tbody.querySelectorAll('input[type="color"]').forEach((picker) => {
        picker.addEventListener('input', (event) => {
          const fuelName = event.target.dataset.fuelName;
          const newColor = event.target.value;
          savedColors[fuelName] = newColor;
          localStorage.setItem(fuelColorKey, JSON.stringify(savedColors));
          const preview = event.target.closest('.color-picker-cell');
          if (preview) {
            preview.querySelector('.color-preview-chip').style.background = newColor;
            preview.querySelector('.color-preview-text').textContent = newColor.toUpperCase();
          }
          const targetTank = document.querySelector(`.tank[data-fuel-name="${CSS.escape(fuelName)}"]`);
          if (targetTank) {
            const fill = targetTank.querySelector('.tank-fill');
            if (fill) fill.style.background = `linear-gradient(180deg, ${newColor}, rgba(255,255,255,0.28))`;
            targetTank.dataset.tankColor = newColor;
          }
        });
      });
    }

    applyTankColors();
    buildFuelColorRows();

    const modal = document.getElementById('fuelColorModal');
    document.getElementById('editColorBtn')?.addEventListener('click', () => {
      buildFuelColorRows();
      modal?.classList.add('show');
    });
    document.getElementById('closeFuelColorModal')?.addEventListener('click', () => modal?.classList.remove('show'));

    (function () {
      const wrap = document.getElementById('tank-visualizer');
      const controls = document.getElementById('tank-visualizer-pg-controls');
      if (!wrap || !controls) return;
      const tankEls = Array.from(wrap.querySelectorAll(':scope > .tank'));
      const perPage = 4;
      let currentPage = 1;
      function render() {
        const totalPages = Math.max(1, Math.ceil(tankEls.length / perPage));
        if (currentPage > totalPages) currentPage = totalPages;
        const start = (currentPage - 1) * perPage;
        tankEls.forEach((el, idx) => el.style.display = (idx >= start && idx < start + perPage) ? '' : 'none');
        controls.innerHTML = '';
        if (totalPages <= 1) return;
        for (let p = 1; p <= totalPages; p++) {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'pg-btn' + (p === currentPage ? ' active' : '');
          btn.textContent = String(p);
          btn.addEventListener('click', () => {
            currentPage = p;
            render();
          });
          controls.appendChild(btn);
        }
      }
      render();
    })();

    const chartEl = document.getElementById('salesChart');
    const chartSvg = document.getElementById('salesSvg');
    const chartTip = document.getElementById('salesTip');
    const fuelSel = document.getElementById('salesFuel');

    if (chartEl && chartSvg && chartTip && fuelSel) {
      const salesChartData = <?php echo json_encode($sales_chart_data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
      const state = { range: 'week', fuel: 'all' };
      const FUELS = [...fuelSel.options].map((o) => o.value).filter((value) => value !== 'all');
      let data = null;
      let geo = null;
      let hoverIdx = -1;
      const NS = 'http://www.w3.org/2000/svg';
      const svgEl = (tag, attrs = {}) => {
        const node = document.createElementNS(NS, tag);
        Object.entries(attrs).forEach(([key, value]) => node.setAttribute(key, String(value)));
        return node;
      };
      const esc = (s) => String(s).replace(/[&<>\"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '\"': '&quot;', "'": '&#39;' }[c]));
      const num = (v) => Math.round(Number(v || 0)).toLocaleString('en-US');
      const trim = (v) => String(parseFloat(v.toFixed(2)));
      const fmtAxis = (v) => {
        if (v >= 1e9) return trim(v / 1e9) + 'B';
        if (v >= 1e6) return trim(v / 1e6) + 'M';
        if (v >= 1e3) return trim(v / 1e3) + 'K';
        return trim(v);
      };
      const niceScale = (max) => {
        if (!(max > 0)) return { top: 1, ticks: [0, 0.2, 0.4, 0.6, 0.8, 1] };
        const rough = max / 5;
        const pow = Math.pow(10, Math.floor(Math.log10(rough)));
        const f = rough / pow;
        const step = (f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10) * pow;
        const top = Math.ceil(max / step - 1e-9) * step;
        const ticks = [];
        for (let v = 0; v <= top + step / 1000; v += step) ticks.push(v);
        return { top, ticks };
      };

      function getSalesData(range, fuel) {
        const set = salesChartData[range] || salesChartData.week || { labels: [], dates: [], rows: [] };
        const rows = (set.rows || []).map((row) => {

          const fuels = {};
          let total = 0;
          let litres = 0;
          Object.entries(row.fuels || {}).forEach(([fuelName, values]) => {
            if (fuel !== 'all' && fuelName !== fuel) return;
            const sales = Number(values.sales || 0);
            const fuelLitres = Number(values.litres || 0);
            fuels[fuelName] = { sales, litres: fuelLitres };
            total += sales;
            litres += fuelLitres;
          });
          if (fuel !== 'all' && !Object.prototype.hasOwnProperty.call(fuels, fuel)) {
            return { total: 0, litres: 0, fuels: {} };
          }
          return { total, litres, fuels };
        });
        return {
          xTitle: set.xTitle || (range === 'year' ? 'Months' : 'Days'),
          labels: set.labels || [],
          dates: set.dates || [],
          rows,
        };
      }

      function render() {
        data = getSalesData(state.range, state.fuel);
        chartSvg.innerHTML = '';
        const svg = chartSvg;
        const W = chartEl.clientWidth || 760;
        const H = chartEl.clientHeight || 360;
        const n = data.rows.length || 1;
        const maxV = Math.max(...data.rows.map((row) => Number(row.total || 0)), 0);
        const sc = niceScale(maxV);
        const L = 76, R = 26, T = 14, B = 54;
        const pw = W - L - R, ph = H - T - B;
        const xAt = (i) => n === 1 ? L + pw / 2 : L + (i * pw) / (n - 1);
        const yAt = (v) => T + ph - (v / sc.top) * ph;
        geo = { L, R, T, B, W, H, pw, ph, n, xAt, yAt };

        const defs = svgEl('defs');
        defs.innerHTML = '<linearGradient id="scArea" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#38bdf8" stop-opacity="0.38"/><stop offset="1" stop-color="#38bdf8" stop-opacity="0.03"/></linearGradient>';
        svg.appendChild(defs);

        sc.ticks.forEach((v) => {
          const y = yAt(v);
          svg.appendChild(svgEl('line', { class: v === 0 ? 'sc-axis' : 'sc-grid', x1: L, x2: L + pw, y1: y, y2: y }));
          const label = svgEl('text', { class: 'sc-tick', x: L - 10, y: y + 4, 'text-anchor': 'end' });
          label.textContent = fmtAxis(v);
          svg.appendChild(label);
        });

        const spacing = n > 1 ? pw / (n - 1) : pw;
        const every = Math.max(1, Math.ceil(40 / spacing));
        data.labels.forEach((labelText, i) => {
          if (i % every !== 0 && i !== data.labels.length - 1) return;
          const t = svgEl('text', { class: 'sc-tick', x: xAt(i), y: T + ph + 20, 'text-anchor': 'middle' });
          t.textContent = labelText;
          svg.appendChild(t);
        });

        const xt = svgEl('text', { class: 'sc-title', x: L + pw / 2, y: H - 8, 'text-anchor': 'middle' });
        xt.textContent = data.xTitle;
        svg.appendChild(xt);
        const yt = svgEl('text', { class: 'sc-title', transform: `translate(14 ${T + ph / 2}) rotate(-90)`, 'text-anchor': 'middle' });
        yt.textContent = 'Sales Amount (MMK)';
        svg.appendChild(yt);

        const pts = data.rows.map((row, i) => [xAt(i), yAt(Number(row.total || 0))]);
        if (pts.length === 0) {
          const msg = svgEl('text', { class: 'sc-tick', x: L + pw / 2, y: T + ph / 2, 'text-anchor': 'middle' });
          msg.textContent = 'No sales data';
          svg.appendChild(msg);
          return;
        }

        const line = pts.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1)).join(' ');
        svg.appendChild(svgEl('path', { d: `${line} L${pts[pts.length - 1][0].toFixed(1)} ${T + ph} L${pts[0][0].toFixed(1)} ${T + ph} Z`, fill: 'url(#scArea)' }));
        svg.appendChild(svgEl('path', { class: 'sc-line', d: line }));
        const r = n > 14 ? 2.6 : 4;
        pts.forEach((p) => svg.appendChild(svgEl('circle', { class: 'sc-dot', cx: p[0], cy: p[1], r })));

        geo.cross = svgEl('line', { class: 'sc-cross', y1: T, y2: T + ph, x1: 0, x2: 0, visibility: 'hidden' });
        geo.ball = svgEl('circle', { class: 'sc-ball', r: 7, cx: 0, cy: 0, visibility: 'hidden' });
        svg.appendChild(geo.cross);
        svg.appendChild(geo.ball);

        const hit = svgEl('rect', { x: L - 12, y: 0, width: pw + 24, height: H - B + 20, fill: 'transparent' });
        svg.appendChild(hit);
        hit.addEventListener('pointermove', onMove);
        hit.addEventListener('pointerdown', onMove);
        hit.addEventListener('pointerleave', hideTip);
        hit.addEventListener('pointercancel', hideTip);
      }

      function onMove(e) {
        if (!geo) return;
        const box = chartSvg.getBoundingClientRect();
        const x = e.clientX - box.left;
        const i = Math.max(0, Math.min(geo.n - 1, Math.round(geo.n === 1 ? 0 : ((x - geo.L) / geo.pw) * (geo.n - 1))));
        showTip(i);
      }

      function showTip(i) {
        const row = data.rows[i] || { total: 0, fuels: {} };
        const px = geo.xAt(i);
        const py = geo.yAt(Number(row.total || 0));
        geo.cross.setAttribute('x1', px);
        geo.cross.setAttribute('x2', px);
        geo.cross.setAttribute('visibility', 'visible');
        geo.ball.setAttribute('cx', px);
        geo.ball.setAttribute('cy', py);
        geo.ball.setAttribute('visibility', 'visible');

        if (i !== hoverIdx) {
          hoverIdx = i;
          const entries = Object.entries(row.fuels || {});
          chartTip.innerHTML =
            `<div class="t-date">${esc(data.dates[i] || '')}</div>` +
            `<div class="t-sum"><span class="t-amt">${num(row.total)}<small>MMK</small></span><span class="t-lit">${num(row.litres || 0)} L</span></div>` +
            (entries.length ? entries.map(([fuelName, values]) => `<div class="t-row"><span><i></i>${esc(fuelName)}</span><b>${num(values.litres || 0)} L</b></div>`).join('') : '<div class="t-row"><span><i></i>No data</span><b>0 L</b></div>');
        }

        const tw = chartTip.offsetWidth, th = chartTip.offsetHeight, gap = 16;
        let tx = px + gap;
        if (tx + tw > geo.W - 4) tx = px - gap - tw;
        tx = Math.max(4, tx);
        const ty = Math.max(4, Math.min(geo.H - th - 4, py - th * 0.4));
        chartTip.style.transform = `translate(${tx}px, ${ty}px)`;
        chartTip.classList.add('show');
      }

      function hideTip() {
        hoverIdx = -1;
        chartTip.classList.remove('show');
        if (geo && geo.cross) {
          geo.cross.setAttribute('visibility', 'hidden');
          geo.ball.setAttribute('visibility', 'hidden');
        }
      }

      document.querySelectorAll('.rng-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
          document.querySelectorAll('.rng-btn').forEach((x) => x.classList.toggle('active', x === btn));
          state.range = btn.dataset.range;
          render();
        });
      });

      fuelSel.addEventListener('change', () => {
        state.fuel = fuelSel.value;
        render();
      });

      let lastW = 0;
      new ResizeObserver(() => {
        const w = chartEl.clientWidth;
        if (w !== lastW) {
          lastW = w;
          render();
        }
      }).observe(chartEl);

      render();
    }
  });
</script>
<script>
  (function () {
    const root = document.body;
    const storedTheme = localStorage.getItem('map_pump_branch_manager_theme');
    const toggle = document.querySelector('.js-theme-toggle');
    const icon = document.querySelector('.staff-theme-control span i');

    function applyTheme(isLight) {
      root.classList.toggle('light-mode', isLight);
      if (toggle) toggle.checked = isLight;
      if (icon) {
        icon.className = isLight ? 'fa-solid fa-moon' : 'fa-solid fa-sun';
      }
      const control = document.querySelector('.staff-theme-control');
      if (control) control.title = isLight ? 'Toggle dark mode' : 'Toggle light mode';
      localStorage.setItem('map_pump_branch_manager_theme', isLight ? 'light' : 'dark');
    }

    root.classList.add('branch-manager-theme');
    applyTheme(storedTheme === 'light');

    if (toggle) {
      toggle.addEventListener('change', function () {
        applyTheme(this.checked);
      });
    }
  })();
</script>
</body>
</html>
