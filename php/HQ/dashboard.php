<?php
require_once __DIR__ . '/hq_guard.php';
require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../business_scope.php';
require_once __DIR__ . '/../loyalty_points.php';
require_once __DIR__ . '/../shared/privacy_display.php';
$hqId = map_pump_current_hq_id($pdo);
$hqBillingReady = loyalty_countermeasures_schema_ready($pdo);
$hqBillingMonth = loyalty_current_year_month();
// Fetch station fuel-tank levels using the linked fuel type.
$stationTanks = [];
$tankStmt = $pdo->prepare(
  "SELECT s.branch_id AS station_id, s.branch_name AS station_name,
          COALESCE(ot.oil_name, 'Unknown fuel') AS fuel_name,
          t.fuel_price
    FROM branches s
    INNER JOIN station_business_scope sbs ON sbs.station_id = s.branch_id
    INNER JOIN tanks t ON t.branch_id = s.branch_id
    LEFT JOIN oil_types ot ON ot.oil_type_id = t.oil_type_id
    WHERE sbs.headquarter_id = ?
      AND LOWER(COALESCE(s.status, 'active')) <> 'closed'
      AND COALESCE(t.is_enabled, 1) = 1
    ORDER BY s.branch_name, ot.oil_name
     LIMIT 8"
);
$tankStmt->execute([$hqId]);
$stationTanks = $tankStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch the latest reports from managers associated with this HQ, including station-scoped managers.
$recentReports = [];
$managerRoleId = 4;
$reportsStmt = $pdo->prepare(
    "SELECT r.report_id, r.user_id, r.short_summery, r.message, r.importance, r.created_at, u.user_name
     FROM report r
     LEFT JOIN users u ON u.user_id = r.user_id
     WHERE " . map_pump_manager_report_scope_sql() . "
     ORDER BY r.created_at DESC
     LIMIT 5"
);
$reportsStmt->execute([$managerRoleId, $managerRoleId, $managerRoleId, $hqId, $hqId]);
$recentReports = $reportsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Live Stations dynamically
$stations = [];
$stationStatusSql = $hqBillingReady
    ? "CASE
           WHEN LOWER(TRIM(COALESCE(s.status, 'active'))) = 'active'
            AND NOT EXISTS (
                SELECT 1 FROM company_loyalty_station_fees fee
                WHERE fee.headquarter_id = sbs.headquarter_id
                  AND fee.station_id = s.branch_id
                  AND fee.`year_month` = ?
            ) THEN 'Paused'
           WHEN LOWER(TRIM(COALESCE(s.status, 'active'))) = 'maintenance' THEN 'Maintenance'
           WHEN LOWER(TRIM(COALESCE(s.status, 'active'))) = 'active' THEN 'Active'
           WHEN LOWER(TRIM(COALESCE(s.status, ''))) = 'closed' THEN 'Closed'
           WHEN LOWER(TRIM(COALESCE(s.status, ''))) = 'decommissioned' THEN 'Decommissioned'
           ELSE CONCAT(
               UCASE(LEFT(COALESCE(NULLIF(TRIM(s.status), ''), 'Unknown'), 1)),
               SUBSTRING(COALESCE(NULLIF(TRIM(s.status), ''), 'Unknown'), 2)
           )
       END"
    : "CASE
           WHEN LOWER(TRIM(COALESCE(s.status, 'active'))) = 'active' THEN 'Active'
           WHEN LOWER(TRIM(COALESCE(s.status, 'active'))) = 'maintenance' THEN 'Maintenance'
           WHEN LOWER(TRIM(COALESCE(s.status, ''))) = 'closed' THEN 'Closed'
           WHEN LOWER(TRIM(COALESCE(s.status, ''))) = 'decommissioned' THEN 'Decommissioned'
           ELSE CONCAT(
               UCASE(LEFT(COALESCE(NULLIF(TRIM(s.status), ''), 'Unknown'), 1)),
               SUBSTRING(COALESCE(NULLIF(TRIM(s.status), ''), 'Unknown'), 2)
           )
       END";
$stationsStmt = $pdo->prepare(
  "SELECT s.branch_id AS station_id, s.branch_name AS station_name,
        s.status AS branch_status,
        s.address,
        {$stationStatusSql} AS status
    FROM branches s
    INNER JOIN station_business_scope sbs ON sbs.station_id = s.branch_id
     WHERE sbs.headquarter_id = ?
       AND LOWER(TRIM(COALESCE(s.status, 'active'))) <> 'closed'
    ORDER BY s.branch_id DESC"
);
$stationsStmt->execute($hqBillingReady ? [$hqBillingMonth, $hqId] : [$hqId]);
$stations = $stationsStmt->fetchAll(PDO::FETCH_ASSOC);
$activeStationCount = count(array_filter($stations, static fn (array $station): bool => ($station['status'] ?? '') === 'Active'));
$maintenanceStationCount = count(array_filter($stations, static fn (array $station): bool => ($station['status'] ?? '') === 'Maintenance'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>HQ Dashboard</title>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<script src="../../js/hq-theme.js"></script>
<link rel="stylesheet" href="../../css/HQstyle.css">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;600&display=swap" rel="stylesheet">

<style>


  .dashboard-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 24px;
  }

  .full-width {
    grid-column: span 2;
  }

  .card {
    margin-bottom: 0;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
  }

  .card-clickable {
    cursor: pointer;
  }

  .card-clickable:hover {
    transform: translateY(-3px);
    box-shadow: 0 20px 35px -10px rgba(0, 0, 0, 0.7);
    border-color: rgba(56, 189, 248, 0.3);
  }

  table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 16px;
  }

  th {
    text-align: left;
    font-size: 12px;
    padding-bottom: 12px;
  }

  td {
    padding: 12px 0;
    border-top: 1px solid var(--border);
    font-size: 14px;
  }

  /* ---- 5-row scrollable tables ---- */
  .dash-scroll {
    --dash-rows: 5;
    --dash-row-h: 46px;   /* CSS fallback; JS measures the real height */
    --dash-head-h: 30px;
    max-height: calc((var(--dash-row-h) * var(--dash-rows)) + var(--dash-head-h));
    overflow-y: hidden;
    overflow-x: auto;
    margin-top: 16px;
  }

  .dash-scroll table {
    margin-top: 0;
    border-collapse: separate; /* keeps borders visible under the sticky header */
    border-spacing: 0;
  }

  .dash-scroll thead th {
    position: sticky;
    top: 0;
    z-index: 2;
    background: var(--card-bg, #0f172a);
    padding-top: 4px;
  }

  /* ---- Scrollbar styling (matches page theme) ---- */
  html,
  body,
  .dash-scroll {
    scrollbar-width: thin;
    scrollbar-color: rgba(56, 189, 248, 0.35) transparent;
  }

  body::-webkit-scrollbar,
  .dash-scroll::-webkit-scrollbar {
    width: 8px;
    height: 8px;
  }

  body::-webkit-scrollbar-track,
  .dash-scroll::-webkit-scrollbar-track {
    background: transparent;
    border-radius: 8px;
  }

  body::-webkit-scrollbar-thumb,
  .dash-scroll::-webkit-scrollbar-thumb {
    background: rgba(56, 189, 248, 0.35);
    border-radius: 8px;
    border: none;
  }

  body::-webkit-scrollbar-thumb:hover,
  .dash-scroll::-webkit-scrollbar-thumb:hover {
    background: rgba(56, 189, 248, 0.55);
  }

  .dash-scroll::-webkit-scrollbar-corner {
    background: transparent;
  }

  .badge {
    padding: 4px 8px;
    border-radius: 6px;
    font-size: 11px;
    background: #e4f4ed;
    color: #146b4f;
  }

  /* Modal styling shared with the HQ workspace. */
  .modal-content {
    background: linear-gradient(160deg, var(--navy-800, #101a30), var(--navy-900, #0b1220));
    color: var(--slate-50, #f8fafc);
    border: 1px solid var(--border);
    border-radius: 18px;
  }

  .modal-header, .modal-footer {
    border-color: var(--border);
  }

  .hq-metrics {
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
  }

  .hq-metric {
    min-width: 0;
    gap: 12px;
    padding: 16px;
  }

  @media (max-width: 900px) {
    .dashboard-grid {
      grid-template-columns: 1fr;
    }

    .full-width {
      grid-column: span 1;
    }
  }

  @media (max-width: 700px) {
    .hq-metrics {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }
</style>
</head>
<body>
  <h2 class="title">Network overview</h2>
  <p class="subtitle">A clear view of your stations, tank levels, and latest manager reports.</p>

  <div class="hq-eyebrow">Operations at a glance</div>
  <div class="hq-metrics" aria-label="Station summary">
    <a class="hq-metric" href="station.php" onclick="event.preventDefault(); navigateTo('station.php')"><i class="fa-solid fa-gas-pump" aria-hidden="true"></i><div><strong><?= count($stations) ?></strong><span>Total stations</span></div></a>
    <a class="hq-metric" href="station.php" onclick="event.preventDefault(); navigateTo('station.php')"><i class="fa-solid fa-circle-check" aria-hidden="true"></i><div><strong><?= $activeStationCount ?></strong><span>Active stations</span></div></a>
    <a class="hq-metric" href="station.php" onclick="event.preventDefault(); navigateTo('station.php')"><i class="fa-solid fa-wrench" aria-hidden="true"></i><div><strong><?= $maintenanceStationCount ?></strong><span>Under maintenance</span></div></a>
  </div>

  <div class="dashboard-grid">
    <!-- Station fuel tanks Card -->
    <div class="card">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h3 class="mb-0">Station Fuel Pricing</h3>
        <a class="small text-info" href="pricing.php" onclick="event.stopPropagation(); event.preventDefault(); navigateTo('pricing.php')">Manage fuel prices <i class="fa-solid fa-arrow-right ms-1"></i></a>
      </div>
      <div class="dash-scroll">
      <table>
        <thead><tr><th>Station</th><th>Fuel type</th><th>Price / L</th></tr></thead>
        <tbody>
          <?php
if (!empty($stationTanks)): ?>
            <?php
foreach ($stationTanks as $tank): ?>
              <tr>
                <td><?php
echo htmlspecialchars((string) $tank['station_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php
echo htmlspecialchars((string) $tank['fuel_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php
echo (float) $tank['fuel_price'] > 0 ? number_format((float) $tank['fuel_price']) . ' MMK' : 'Not set'; ?></td>
              </tr>
            <?php
endforeach; ?>
          <?php
else: ?>
            <tr><td colspan="3">No active station price found.</td></tr>
          <?php
endif; ?>
        </tbody>
      </table>
      </div>
    </div>

    <!-- Latest manager reports Card -->
    <div class="card card-clickable" onclick="navigateTo('report_from_manager.php')">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h3 class="mb-0">Latest manager reports</h3>
        <a class="small text-info" href="report_from_manager.php" onclick="event.stopPropagation(); event.preventDefault(); navigateTo('report_from_manager.php')">View all <i class="fa-solid fa-arrow-right ms-1"></i></a>
      </div>
      <?php
if (!empty($recentReports)): ?>
        <div class="dash-scroll">
        <table>
          <thead><tr><th>Branch</th><th>Summary</th><th>Date</th></tr></thead>
          <tbody>
            <?php
foreach ($recentReports as $report): ?>
              <tr>
                <td><?php
echo htmlspecialchars($report['user_name'] ?? 'Manager'); ?></td>
                <td><?php
echo htmlspecialchars(substr($report['short_summery'] ?? 'No summary provided', 0, 40)); ?>...</td>
                <td><?php
echo htmlspecialchars($report['created_at']); ?></td>
              </tr>
            <?php
endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php
else: ?>
        <p class="subtitle" style="margin-bottom: 0;">No manager reports yet. New reports will appear here.</p>
      <?php
endif; ?>
    </div>

    <!-- Station network Card -->
    <div class="card card-full full-width card-clickable" onclick="navigateTo('station.php')">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h3 class="mb-0">Station network</h3>
        <a class="small text-info" href="station.php" onclick="event.stopPropagation(); event.preventDefault(); navigateTo('station.php')">Manage stations <i class="fa-solid fa-arrow-right ms-1"></i></a>
      </div>
      <div class="dash-scroll">
      <table>
        <thead><tr><th>Branch ID</th><th>Branch Name</th><th>Address</th><th>Status</th></tr></thead>
        <tbody>
          <?php
if (!empty($stations)): ?>
            <?php
foreach ($stations as $station): ?>
              <?php
              $stationStatus = (string) ($station['status'] ?? 'Unknown');
              $stationStatusClass = match ($stationStatus) {
                  'Active' => 'bg-success',
                  'Maintenance' => 'bg-warning',
                  'Paused' => 'bg-secondary',
                  'Closed', 'Decommissioned' => 'bg-dark',
                  default => 'bg-secondary',
              };
              ?>
              <tr>
                <td><?php
echo htmlspecialchars($station['station_id']); ?></td>
                <td><?php
echo htmlspecialchars($station['station_name']); ?></td>
                <td><?php
echo htmlspecialchars((string) ($station['address'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                <td><span class="badge <?= $stationStatusClass ?>"><?php
echo htmlspecialchars($stationStatus, ENT_QUOTES, 'UTF-8'); ?></span></td>
              </tr>
            <?php
endforeach; ?>
          <?php
else: ?>
            <tr><td colspan="4">No stations monitored.</td></tr>
          <?php
endif; ?>
        </tbody>
      </table>
      </div>
    </div>
  </div>

  <footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map & Pump | All Rights Reserved</footer>

  <script>
    function navigateTo(page) {
      if (window.parent && typeof window.parent.routeTo === 'function') {
        window.parent.routeTo(page);
      } else {
        window.location.href = page;
      }
    }

    // Size each scroll box to exactly 5 rows + header, measured from real rendered rows
    function sizeDashScrollers() {
      var VISIBLE_ROWS = 5;
      document.querySelectorAll('.dash-scroll').forEach(function (box) {
        var rows = box.querySelectorAll('tbody tr');
        if (rows.length <= VISIBLE_ROWS) {
          box.style.maxHeight = 'none';
          box.style.overflowY = 'hidden';
          return;
        }
        var head = box.querySelector('thead');
        var total = head ? head.getBoundingClientRect().height : 0;
        for (var i = 0; i < VISIBLE_ROWS; i++) {
          total += rows[i].getBoundingClientRect().height;
        }
        box.style.maxHeight = Math.ceil(total) + 'px';
        box.style.overflowY = 'auto';
      });
    }

    document.addEventListener('DOMContentLoaded', sizeDashScrollers);
    window.addEventListener('load', sizeDashScrollers);

    var dashResizeTimer;
    window.addEventListener('resize', function () {
      clearTimeout(dashResizeTimer);
      dashResizeTimer = setTimeout(sizeDashScrollers, 150);
    });

  </script>
</body>
</html>
