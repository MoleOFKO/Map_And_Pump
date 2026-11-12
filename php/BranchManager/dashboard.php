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
$managerName = getFullDisplayName($user);
$initials = initials($managerName);
$bid      = (int)($branch['branch_id'] ?? 0);
$managerDisplayName = $managerName;
$managerAvatarUrl   = map_pump_user_avatar_url($user['avatar_url'] ?? ($_SESSION['user_avatar_url'] ?? ''), '');

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
    --navy-950:#f1f5fa; --navy-900:#ffffff; --navy-800:#f8fafc; --navy-700:#eef3f9; --navy-600:#e4ebf4;
    --blue-700:#2563eb; --blue-500:#3b82f6; --sky-400:#0284c7; --amber-400:#d97706; --amber-300:#a16207; --mint-400:#047857; --red-400:#dc2626;
    --slate-50:#0f172a; --slate-200:#1e293b; --slate-300:#334155; --slate-400:#5b6b82; background: var(--navy-950); color: var(--slate-50);
    --lm-border:rgba(15,23,42,0.09); --lm-border-strong:rgba(15,23,42,0.16); --lm-hover:rgba(15,23,42,0.045);
    --lm-shadow:0 1px 2px rgba(15,23,42,0.04), 0 8px 20px -12px rgba(15,23,42,0.12);
    --lm-shadow-hover:0 14px 28px -14px rgba(15,23,42,0.22);
    color-scheme: light;
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
    background: var(--navy-900); border-color: var(--lm-border); color: var(--slate-50);
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

  /* ---------- Light mode refinements ---------- */
  body.branch-manager-theme.light-mode .sidebar { border-right-color: var(--lm-border); }
  body.branch-manager-theme.light-mode .sidebar .disp { color: var(--slate-50) !important; }
  body.branch-manager-theme.light-mode .side-branch { background: var(--navy-800); }
  body.branch-manager-theme.light-mode .side-branch:hover { background: var(--navy-700); }
  body.branch-manager-theme.light-mode .side-link:hover { background: var(--lm-hover); color: var(--slate-50); }
  body.branch-manager-theme.light-mode .side-logout { border-top-color: var(--lm-border); }
  body.branch-manager-theme.light-mode .side-logout:hover { background: rgba(220,38,38,0.08); }
  body.branch-manager-theme.light-mode .topbar { background: rgba(255,255,255,0.86); border-bottom-color: var(--lm-border); box-shadow: 0 1px 0 rgba(15,23,42,0.02); }
  body.branch-manager-theme.light-mode .bell:hover { background: var(--navy-700); }
  body.branch-manager-theme.light-mode .bell .ping { box-shadow: 0 0 0 2px #ffffff; }
  body.branch-manager-theme.light-mode .avatar-circle,
  body.branch-manager-theme.light-mode .avatar-circle a,
  body.branch-manager-theme.light-mode .mini-avatar { color: #ffffff; }
  body.branch-manager-theme.light-mode .avatar-chip img { border-color: var(--lm-border-strong) !important; }
  body.branch-manager-theme.light-mode .staff-theme-control:hover { color: var(--slate-50); }
  body.branch-manager-theme.light-mode .notif-popup { border-color: var(--lm-border-strong); box-shadow: 0 18px 40px -14px rgba(15,23,42,0.28); }
  body.branch-manager-theme.light-mode .notif-header { border-bottom-color: var(--lm-border); }
  body.branch-manager-theme.light-mode .notif-item:hover { background-color: var(--lm-hover); }
  body.branch-manager-theme.light-mode .notif-item.unread { border-color: rgba(2,132,199,0.28); }
  body.branch-manager-theme.light-mode .page-foot { border-top-color: var(--lm-border); }

  body.branch-manager-theme.light-mode .stat-card,
  body.branch-manager-theme.light-mode .module-card,
  body.branch-manager-theme.light-mode .panel { box-shadow: var(--lm-shadow); }
  body.branch-manager-theme.light-mode .stat-card:hover,
  body.branch-manager-theme.light-mode .module-card:hover { border-color: rgba(2,132,199,0.35); box-shadow: var(--lm-shadow-hover); }
  body.branch-manager-theme.light-mode .stat-sub { border-top-color: var(--lm-border); }
  body.branch-manager-theme.light-mode .module-card.staff .ic-badge { background: rgba(4,120,87,0.10); color: var(--mint-400); }
  body.branch-manager-theme.light-mode .module-card.oil .ic-badge { background: rgba(217,119,6,0.12); color: #b45309; }
  body.branch-manager-theme.light-mode .module-card.slot .ic-badge { background: rgba(2,132,199,0.10); color: var(--sky-400); }
  body.branch-manager-theme.light-mode .module-card.profile .ic-badge { background: rgba(79,70,229,0.10); color: #4f46e5; }
  body.branch-manager-theme.light-mode .queue-row { border-bottom-color: var(--lm-border); }
  body.branch-manager-theme.light-mode .status-pill.serving { background: rgba(4,120,87,0.10); }
  body.branch-manager-theme.light-mode .status-pill.waiting { background: rgba(2,132,199,0.10); }
  body.branch-manager-theme.light-mode .alert-row { border-bottom-color: var(--lm-border); }
  body.branch-manager-theme.light-mode .alert-row:hover { background-color: var(--lm-hover); }
  body.branch-manager-theme.light-mode .tank { background: linear-gradient(180deg, #ffffff, #f4f8fc); border-color: rgba(2,132,199,0.32); box-shadow: 0 10px 20px -16px rgba(2,132,199,0.45); }
  body.branch-manager-theme.light-mode .tank .tank-body { background: #e6edf6; border-color: var(--lm-border); }
  body.branch-manager-theme.light-mode .tank-status.ok { background: rgba(4,120,87,0.10); border-color: rgba(4,120,87,0.20); }
  body.branch-manager-theme.light-mode .tank-status.low { background: rgba(220,38,38,0.08); border-color: rgba(220,38,38,0.20); }
  body.branch-manager-theme.light-mode .pg-btn { background: #ffffff; border-color: var(--lm-border-strong); color: var(--slate-300); }
  body.branch-manager-theme.light-mode .pg-btn:hover:not(:disabled) { background: #f1f5f9; color: var(--slate-50); }
  body.branch-manager-theme.light-mode .pg-btn.active,
  body.branch-manager-theme.light-mode .pg-btn.active:hover:not(:disabled) { color: #ffffff; }
  body.branch-manager-theme.light-mode .edit-color-btn { background: #ffffff; border-color: var(--lm-border-strong); color: var(--slate-50); }
  body.branch-manager-theme.light-mode .edit-color-btn:hover { background: rgba(2,132,199,0.08); border-color: rgba(2,132,199,0.40); color: var(--sky-400); }
  body.branch-manager-theme.light-mode .color-modal-backdrop { background: rgba(15,23,42,0.45); }
  body.branch-manager-theme.light-mode .color-modal { border-color: var(--lm-border-strong); box-shadow: 0 24px 60px -20px rgba(15,23,42,0.35); }
  body.branch-manager-theme.light-mode .color-modal-close { background: #f1f5f9; border-color: var(--lm-border); }
  body.branch-manager-theme.light-mode .color-modal-close:hover { background: #e2e8f0; }
  body.branch-manager-theme.light-mode .fuel-color-table th,
  body.branch-manager-theme.light-mode .fuel-color-table td { border-bottom-color: var(--lm-border); }
  body.branch-manager-theme.light-mode .fuel-color-table tbody tr:hover { background: var(--navy-800); }
  body.branch-manager-theme.light-mode .fuel-swatch,
  body.branch-manager-theme.light-mode .color-preview-chip { border-color: rgba(15,23,42,0.18); }
  body.branch-manager-theme.light-mode .color-preview { background: var(--navy-800); border-color: var(--lm-border); }
  body.branch-manager-theme.light-mode .rng-btn { background: #ffffff; border-color: var(--lm-border-strong); color: var(--slate-300); }
  body.branch-manager-theme.light-mode .rng-btn:hover { background: #f1f5f9; color: var(--slate-50); }
  body.branch-manager-theme.light-mode .rng-btn.active,
  body.branch-manager-theme.light-mode .rng-btn.active:hover { color: #000000; }
  body.branch-manager-theme.light-mode .fuel-select { background: #ffffff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1.5l5 5 5-5' fill='none' stroke='%23334155' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E") no-repeat right 14px center; border-color: var(--lm-border-strong); color: var(--slate-50); }
  body.branch-manager-theme.light-mode .fuel-select:hover { border-color: rgba(15,23,42,0.30); }
  body.branch-manager-theme.light-mode .fuel-select:focus { border-color: var(--blue-700); box-shadow: 0 0 0 3px rgba(37,99,235,0.18); }
  body.branch-manager-theme.light-mode .sc-grid { stroke: rgba(15,23,42,0.08); }
  body.branch-manager-theme.light-mode .sc-axis { stroke: rgba(15,23,42,0.22); }
  body.branch-manager-theme.light-mode .sc-cross { stroke: rgba(51,65,85,0.45); }
  body.branch-manager-theme.light-mode .sc-ball { filter: drop-shadow(0 0 5px rgba(2,132,199,0.45)); }
  body.branch-manager-theme.light-mode .sc-tip { background: rgba(255,255,255,0.98); border-color: var(--lm-border-strong); box-shadow: 0 18px 36px -14px rgba(15,23,42,0.30); }
  body.branch-manager-theme.light-mode .sc-tip .t-date,
  body.branch-manager-theme.light-mode .sc-tip .t-sum { border-bottom-color: var(--lm-border); }
  body.branch-manager-theme.light-mode .sc-tip .t-amt,
  body.branch-manager-theme.light-mode .sc-tip .t-lit,
  body.branch-manager-theme.light-mode .sc-tip .t-row b { color: var(--slate-50); }
  body.branch-manager-theme.light-mode .sc-tip .t-row i { box-shadow: inset 0 0 0 1px rgba(15,23,42,0.12); }

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
    justify-content:center; border:1px solid rgba(255,255,255,0.08); position:relative; font-size:15px; cursor:pointer;
    transition: transform 0.2s, background-color 0.2s;
  }
  .bell:hover{ background: var(--navy-700); transform: scale(1.05); }
  .bell .ping{position:absolute; top:4px; right:4px; width:10px; height:10px; border-radius:50%; background:#ef4444; box-shadow:0 0 0 2px var(--navy-800);}
  
  /* ---------- Notifications Dropdown ---------- */
  .notif-popup {
    position: absolute;
    top: 48px;
    right: 60px;
    width: 320px;
    max-height: 420px;
    overflow: hidden;
    background: var(--navy-800);
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 14px;
    box-shadow: 0 16px 32px rgba(0, 0, 0, 0.4);
    padding: 12px;
    z-index: 100;
    display: none;
    animation: fadeInDown 0.25s cubic-bezier(0.16, 1, 0.3, 1);
  }
  .notif-popup.show { display: block; }
  .notif-header { display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom:10px; margin-bottom:10px; }
  .notif-header h4 { font-size: 14px; font-weight:700; margin:0; }
  .notif-list {
    max-height: 330px;
    overflow-y: auto;
    overflow-x: auto;
    padding-right: 4px;
    scrollbar-width: thin;
    scrollbar-color: rgba(56,189,248,0.35) transparent;
  }
  .notif-list::-webkit-scrollbar { width: 8px; height: 8px; }
  .notif-list::-webkit-scrollbar-track { background: transparent; }
  .notif-list::-webkit-scrollbar-thumb { background: rgba(56,189,248,0.35); border-radius: 999px; }
  .notif-list::-webkit-scrollbar-thumb:hover { background: rgba(56,189,248,0.55); }
  .notif-item {
    padding: 10px 8px; border-radius: 10px; display: flex; gap: 10px; align-items: flex-start; transition: background-color 0.2s; border: 1px solid transparent;
  }
  .notif-item:hover { background-color: rgba(255,255,255,0.04); }
  .notif-item.unread { border-color: rgba(56,189,248,0.2); }
  .notif-item-warning .icon,
  .notif-item.error .icon,
  .notif-item.warn .icon,
  .notif-item .icon {
    display: none;
  }
  .notif-item-body {
    min-width: 0;
    flex: 1;
  }
  .notif-item-body b { display:block; font-size:12.5px; color:var(--slate-50); margin-bottom:2px; }
  .notif-item-body span { color:var(--slate-400); font-size:11.5px; display:block; white-space:normal; line-height:1.5; }
  .notif-item-body .notif-time { font-size:10px; color:var(--slate-400); margin-top:4px; }
  .notif-empty { text-align:center; padding:12px 0; color:var(--slate-400); font-size:12px; }
  @keyframes fadeInDown { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }

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
  .pg-btn.active { background:linear-gradient(135deg, #3b8dff, #1d4ed8); border-color:#2f6feb; color:#000; box-shadow:0 8px 18px -10px rgba(59,141,255,0.75); }
  .pg-btn.active:hover:not(:disabled) { background:linear-gradient(135deg, #3b8dff, #1d4ed8); border-color:#2f6feb; color:#000; box-shadow:0 8px 18px -10px rgba(59,141,255,0.75); }
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
  .rng-btn.active, .rng-btn.active:hover{background:linear-gradient(135deg,#3b8dff,#1d4ed8); border-color:#2f6feb; color:#000; box-shadow:0 8px 18px -10px rgba(59,141,255,0.75);}
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
      <a href="manage-oil.php" class="side-link"><span class="ic"><i class="fa-solid fa-gas-pump"></i></span> Manage Fuel Stock</a>
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
        <div class="bell" id="notif-bell"><i class="fa-regular fa-bell"></i><?php if ($has_unread): ?><span class="ping" id="notif-ping"></span><?php endif; ?></div>
        
        <!-- Notification Dropdown Menu -->
        <div class="notif-popup" id="notif-popup">
          <div class="notif-header">
            <h4>Notifications</h4>
          </div>
          <div class="notif-list" id="notif-list">
            <?php if (!$alerts): ?>
              <div class="notif-empty">No new notifications</div>
            <?php else:
              foreach ($alerts as $a):
                $meta = notif_ui_meta($a['type'], $a['title']);
                $icon = $meta['icon'];
                $tone = $meta['tone'];
            ?>
              <div class="notif-item <?= h($tone) ?>" data-notif-id="<?= (int)$a['notif_id'] ?>" data-link="<?= h($a['link'] ?? 'dashboard.php') ?>" data-kind="<?= h($tone) ?>">
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
          <?php if ($managerAvatarUrl !== ''): ?>
            <img src="<?= h($managerAvatarUrl) ?>" alt="Profile Picture" style="width:36px;height:36px;border-radius:50%;object-fit:cover;border:1px solid rgba(255,255,255,0.12);">
          <?php else: ?>
            <div class="avatar-circle"><a href="edit-profile.php"><?= h(mb_substr($initials,0,1)) ?></a></div>
          <?php endif; ?>
          <div><b><a href="edit-profile.php"><?= h($managerDisplayName) ?></a></b></div>
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
                $defaultTankColors = [
                  'Octane 92'      => '#38bdf8',
                  'Octane 95'      => '#2563eb',
                  'Octane 98'      => '#3b82f6',
                  'Diesel'         => '#34d399',
                  'Premium Diesel' => '#fbbf24',
                  'Petrol'         => '#38bdf8',
                ];
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
    const bellBtn = document.getElementById('notif-bell');
    const notifPopup = document.getElementById('notif-popup');
    const notifPing = document.getElementById('notif-ping');
    const notifList = document.getElementById('notif-list');

    function postDismiss(payload) {
      return fetch('api_dismiss_notification.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      }).then((r) => r.json()).catch(() => ({ ok: false }));
    }

    if (bellBtn && notifPopup) {
      bellBtn.addEventListener('click', (event) => {
        event.stopPropagation();
        const ping = bellBtn.querySelector('.ping');
        if (ping) {
          ping.style.transform = 'scale(0)';
          setTimeout(() => ping.remove(), 180);
        }
        notifPopup.classList.toggle('show');
      });

      document.addEventListener('click', (event) => {
        const clickedBell = bellBtn.contains(event.target);
        const clickedPopup = notifPopup.contains(event.target);
        if (!clickedBell && !clickedPopup) {
          notifPopup.classList.remove('show');
        }
      });
    }

    const editColorBtn = document.getElementById('editColorBtn');
    const fuelColorModal = document.getElementById('fuelColorModal');
    const closeFuelColorModal = document.getElementById('closeFuelColorModal');
    const fuelColorTableBody = document.getElementById('fuelColorTableBody');
    const saveFuelColorsBtn = document.getElementById('saveFuelColorsBtn');
    const LS_KEY = 'map_pump_tank_colors';

    function getTankEls() {
      return Array.from(document.querySelectorAll('#tank-visualizer .tank'));
    }

    function loadSavedColors() {
      try { return JSON.parse(localStorage.getItem(LS_KEY) || '{}'); } catch(e) { return {}; }
    }

    function applyColorsToTanks(colorMap) {
      getTankEls().forEach((tank) => {
        const name = tank.dataset.fuelName || '';
        const color = colorMap[name] || tank.dataset.tankColor || '#38bdf8';
        const fill = tank.querySelector('.tank-fill');
        if (fill) fill.style.background = 'linear-gradient(180deg, ' + color + ', rgba(255,255,255,0.28))';
      });
    }

    function populateColorModal() {
      if (!fuelColorTableBody) return;
      fuelColorTableBody.innerHTML = '';
      const saved = loadSavedColors();
      getTankEls().forEach((tank) => {
        const name = tank.dataset.fuelName || '';
        if (!name) return;
        const defaultColor = tank.dataset.tankColor || '#38bdf8';
        const currentColor = saved[name] || defaultColor;
        const tr = document.createElement('tr');
        tr.innerHTML =
          '<td style="display:flex;align-items:center;gap:10px;">' +
            '<span class="color-preview-chip" style="width:28px;height:28px;border-radius:8px;border:2px solid rgba(0,0,0,0.12);background:' + currentColor + ';display:inline-block;flex-shrink:0;"></span>' +
            '<span style="font-weight:600;">' + name + '</span>' +
          '</td>' +
          '<td>' +
            '<label style="display:flex;align-items:center;gap:10px;cursor:pointer;">' +
              '<input type="color" value="' + currentColor + '" data-fuel="' + name + '" style="width:28px;height:28px;border:none;border-radius:8px;padding:0;cursor:pointer;background:none;">' +
              '<span class="hex-label" style="font-size:12px;font-weight:700;">' + currentColor.toUpperCase() + '</span>' +
            '</label>' +
          '</td>';
        const input = tr.querySelector('input[type=color]');
        const chip = tr.querySelector('.color-preview-chip');
        const hexLabel = tr.querySelector('.hex-label');
        input.addEventListener('input', () => {
          chip.style.background = input.value;
          hexLabel.textContent = input.value.toUpperCase();
        });
        fuelColorTableBody.appendChild(tr);
      });
    }

    // Apply saved colors on page load
    applyColorsToTanks(loadSavedColors());

    if (editColorBtn && fuelColorModal) {
      editColorBtn.addEventListener('click', () => {
        populateColorModal();
        fuelColorModal.classList.add('show');
        fuelColorModal.setAttribute('aria-hidden', 'false');
      });
    }
    if (closeFuelColorModal && fuelColorModal) {
      closeFuelColorModal.addEventListener('click', () => {
        fuelColorModal.classList.remove('show');
        fuelColorModal.setAttribute('aria-hidden', 'true');
      });
    }
    if (saveFuelColorsBtn && fuelColorModal) {
      saveFuelColorsBtn.addEventListener('click', () => {
        const saved = loadSavedColors();
        fuelColorTableBody.querySelectorAll('input[type=color]').forEach((input) => {
          saved[input.dataset.fuel] = input.value;
        });
        localStorage.setItem(LS_KEY, JSON.stringify(saved));
        applyColorsToTanks(saved);
        fuelColorModal.classList.remove('show');
        fuelColorModal.setAttribute('aria-hidden', 'true');
      });
    }

    if (notifList) {
      notifList.addEventListener('click', (event) => {
        const item = event.target.closest('.notif-item');
        if (!item) return;

        const id = Number(item.dataset.notifId || 0);
        const link = item.dataset.link || 'dashboard.php';

        if (id > 0) {
          postDismiss({ notif_id: id }).then((res) => {
            if (res.ok && notifPing && Number(res.unread || 0) <= 0) {
              notifPing.remove();
            }
          });
        }

        if (link) {
          window.location.href = link;
        }
      });
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
<script>
  (() => {
    const chartData = <?= json_encode($sales_chart_data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    const svg = document.getElementById('salesSvg');
    const tip = document.getElementById('salesTip');
    const rangeButtons = Array.from(document.querySelectorAll('.rng-btn'));
    const fuelSelect = document.getElementById('salesFuel');
    const chartWrap = document.getElementById('salesChart');

    if (!svg || !tip || !chartWrap || !chartData) return;

    const defaultFuelColors = {
      'Octane 92': '#38bdf8',
      'Octane 95': '#60a5fa',
      'Octane 98': '#3b82f6',
      'Diesel': '#fbbf24',
      'Premium Diesel': '#f59e0b',
      'Petrol': '#38bdf8'
    };

    function compactCurrency(value) {
      const num = Number(value || 0);
      if (num >= 1000000) return (num / 1000000).toFixed(num >= 10000000 ? 1 : 2).replace(/\.0$/, '') + 'M';
      if (num >= 1000) return (num / 1000).toFixed(num >= 10000 ? 1 : 2).replace(/\.0$/, '') + 'K';
      return Math.round(num).toString();
    }

    function escapeHtml(str) {
      return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    function getFuelColor(fuelName) {
      return defaultFuelColors[fuelName] || '#38bdf8';
    }

    function getSeries(range, fuel) {
      const dataset = chartData[range] || { labels: [], rows: [] };
      const labels = dataset.labels || [];
      const rows = dataset.rows || [];
      const values = [];
      const detail = [];

      rows.forEach((row, idx) => {
        const fuelMap = row && row.fuels ? row.fuels : {};
        let total = Number(row && row.total ? row.total : 0);
        if (fuel && fuel !== 'all') {
          const entry = fuelMap[fuel] || { sales: 0, litres: 0 };
          total = Number(entry.sales || 0);
        }
        values.push(total);
        detail.push({
          label: labels[idx] || '',
          date: (dataset.dates && dataset.dates[idx]) || '',
          value: total,
          litres: fuel && fuel !== 'all' ? (fuelMap[fuel] && Number(fuelMap[fuel].litres || 0)) || 0 : (row && row.litres ? Number(row.litres) : 0),
          fuelName: fuel && fuel !== 'all' ? fuel : 'All Fuels'
        });
      });

      return { labels, detail, values };
    }

    function renderSalesChart() {
      const range = document.querySelector('.rng-btn.active')?.dataset.range || 'week';
      const fuel = fuelSelect ? fuelSelect.value : 'all';
      const series = getSeries(range, fuel);
      const values = series.values.length ? series.values : [0];
      const maxValue = Math.max(...values, 1);
      const width = chartWrap.clientWidth || 800;
      const height = chartWrap.clientHeight || 360;
      const pad = { top: 20, right: 18, bottom: 32, left: 48 };
      const innerWidth = width - pad.left - pad.right;
      const innerHeight = height - pad.top - pad.bottom;

      const points = values.map((value, idx) => {
        const x = pad.left + (values.length > 1 ? (idx / (values.length - 1)) * innerWidth : innerWidth / 2);
        const y = pad.top + innerHeight - ((value / maxValue) * innerHeight || 0);
        return { x, y, value, label: series.labels[idx] || '', detail: series.detail[idx] };
      });

      const linePath = points.map((p, i) => `${i === 0 ? 'M' : 'L'} ${p.x} ${p.y}`).join(' ');

      const grid = Array.from({ length: 5 }, (_, i) => {
        const y = pad.top + (innerHeight / 4) * i;
        const v = maxValue - ((maxValue / 4) * i);
        return `
          <line class="sc-cross" x1="${pad.left}" y1="${y}" x2="${width - pad.right}" y2="${y}"/>
          <text class="sc-tick" x="10" y="${y + 4}" text-anchor="start">${compactCurrency(v)}</text>
        `;
      }).join('');

      const xLabels = points.map((p, i) => `
        <text class="sc-tick" x="${p.x}" y="${height - 10}" text-anchor="middle">${escapeHtml(p.label)}</text>
      `).join('');

      const circles = points.map((p) => `
        <circle class="sc-ball" cx="${p.x}" cy="${p.y}" r="4.4" data-value="${p.value}" data-date="${escapeHtml(p.detail.date)}" data-label="${escapeHtml(p.detail.label)}" data-litres="${p.detail.litres}" />
      `).join('');

      svg.innerHTML = `
        <g>
          ${grid}
          <line class="sc-axis" x1="${pad.left}" y1="${pad.top + innerHeight}" x2="${width - pad.right}" y2="${pad.top + innerHeight}"/>
          <path class="sc-line" d="${linePath}"/>
          ${circles}
          ${xLabels}
        </g>
      `;

      const xRange = values.length > 1 ? points : [{ x: width / 2, y: pad.top + innerHeight }];
      xRange.forEach((pt) => {
        pt.__shown = false;
      });

      svg.querySelectorAll('.sc-ball').forEach((ball) => {
        ball.addEventListener('mouseenter', (event) => {
          const value = Number(ball.dataset.value || 0);
          const litres = Number(ball.dataset.litres || 0);
          const label = ball.dataset.label || '';
          const date = ball.dataset.date || '';
          const rect = chartWrap.getBoundingClientRect();
          tip.innerHTML = `
            <div class="t-date">${escapeHtml(date || label)}</div>
            <div class="t-sum"><span class="t-amt">${compactCurrency(value)}<small>MMK</small></span></div>
            <div class="t-row"><span><i style="background:${getFuelColor(fuel && fuel !== 'all' ? fuel : 'Octane 92')};"></i> ${escapeHtml(fuel && fuel !== 'all' ? fuel : 'All Fuels')}</span><b>${Number(litres).toLocaleString()}L</b></div>
          `;
          const x = event.clientX - rect.left + 14;
          const y = event.clientY - rect.top - 8;
          tip.style.left = `${Math.min(Math.max(x, 14), rect.width - 242)}px`;
          tip.style.top = `${Math.max(y, 12)}px`;
          tip.classList.add('show');
        });

        ball.addEventListener('mouseleave', () => {
          tip.classList.remove('show');
        });
      });
    }

    rangeButtons.forEach((button) => {
      button.addEventListener('click', () => {
        rangeButtons.forEach((btn) => btn.classList.toggle('active', btn === button));
        renderSalesChart();
      });
    });

    if (fuelSelect) {
      fuelSelect.addEventListener('change', renderSalesChart);
    }

    window.addEventListener('resize', renderSalesChart);
    renderSalesChart();
  })();
</script>
</body>
</html>
