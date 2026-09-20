<?php
require_once __DIR__ . '/hq_guard.php';
require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../business_scope.php';
$hqId = map_pump_current_hq_id($pdo);
// Handle quick price update submission if posted
$updateMessage = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_price') {
    $stationId = filter_input(INPUT_POST, 'station_id', FILTER_VALIDATE_INT);
    $oilTypeId = filter_input(INPUT_POST, 'oil_type_id', FILTER_VALIDATE_INT);
    $newPrice = trim((string)($_POST['fuel_price'] ?? ''));

    if ($stationId && $oilTypeId && $newPrice !== '' && is_numeric($newPrice) && (float) $newPrice >= 0 && map_pump_station_in_hq($pdo, (int)$stationId, $hqId)) {
        map_pump_append_station_price($pdo, (int)$stationId, (int)$oilTypeId, (int)$newPrice);
        $updateMessage = "New station fuel price added. Previous price records were kept.";
    } else {
        $updateMessage = "Please select one of your HQ stations and enter a valid non-negative numeric price.";
    }
}

// Fetch Station fuel prices dynamically from the live station inventory
$fuelPrices = [];
$dashboardStations = [];
$latestInventorySql = map_pump_latest_station_inventory_sql();
$pricingStmt = $pdo->prepare(
  "SELECT s.branch_id AS station_id, s.branch_name AS station_name, ot.oil_type_id, ot.oil_name, li.price_per_litre AS price_per_liter
    FROM branches s
    INNER JOIN station_business_scope sbs ON sbs.station_id = s.branch_id
    INNER JOIN ({$latestInventorySql}) li ON li.station_id = s.branch_id
     INNER JOIN oil_types ot ON ot.oil_type_id = li.oil_type_id
     WHERE sbs.headquarter_id = ?
       AND COALESCE(s.status, 'Active') <> 'Decommissioned'
    ORDER BY s.branch_name, ot.oil_type_id
     LIMIT 8"
);
$pricingStmt->execute([$hqId]);
$fuelPrices = $pricingStmt->fetchAll(PDO::FETCH_ASSOC);

$dashboardStationStmt = $pdo->prepare(
    "SELECT s.branch_id AS station_id, s.branch_name AS station_name
    FROM branches s
    INNER JOIN station_business_scope sbs ON sbs.station_id = s.branch_id
     WHERE sbs.headquarter_id = ?
       AND COALESCE(s.status, 'Active') <> 'Decommissioned'
    ORDER BY s.branch_name"
);
$dashboardStationStmt->execute([$hqId]);
$dashboardStations = $dashboardStationStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch recent manager reports by the user's original business role, since role_id is normalized to the customer profile in the account-switch flow.
$recentReports = [];
$reportsStmt = $pdo->prepare(
    "SELECT r.report_id, r.user_id, r.short_summery, r.message, r.importance, r.created_at, u.user_name, COALESCE(u.business_role_id, u.role_id) AS source_role_id
     FROM report r
     LEFT JOIN users u ON u.user_id = r.user_id
     INNER JOIN business_account_scope bas ON bas.user_id = u.user_id
     WHERE COALESCE(u.business_role_id, u.role_id) = 4
       AND bas.headquarter_id = ?
     ORDER BY r.created_at DESC LIMIT 5"
);
$reportsStmt->execute([$hqId]);
$recentReports = $reportsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Live Stations dynamically
$stations = [];
$stationsStmt = $pdo->prepare(
  "SELECT s.branch_id AS station_id, s.branch_name AS station_name,
        CASE WHEN s.latitude IS NOT NULL AND s.longitude IS NOT NULL
          THEN CONCAT(s.latitude, ', ', s.longitude) ELSE s.address END AS location,
        s.status
    FROM branches s
    INNER JOIN station_business_scope sbs ON sbs.station_id = s.branch_id
     WHERE sbs.headquarter_id = ?
    ORDER BY s.branch_id DESC"
);
$stationsStmt->execute([$hqId]);
$stations = $stationsStmt->fetchAll(PDO::FETCH_ASSOC);
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
    overflow-y: auto;
    overflow-x: auto;
    margin-top: 16px;
  }

  /* rows with an Edit button are taller */
  .dash-scroll.dash-scroll-tall {
    --dash-row-h: 62px;
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

  @media (max-width: 900px) {
    .dashboard-grid {
      grid-template-columns: 1fr;
    }

    .full-width {
      grid-column: span 1;
    }
  }
</style>
</head>
<body>
  <h2 class="title">Network overview</h2>
  <p class="subtitle">A clear view of your stations, fuel prices, and latest manager reports.</p>

  <?php
if (!empty($updateMessage)): ?>
    <div class="alert alert-info alert-dismissible fade show" role="alert">
      <?php
echo $updateMessage; ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php
endif; ?>

  <div class="hq-eyebrow">Operations at a glance</div>
  <div class="hq-metrics" aria-label="Station summary">
    <a class="hq-metric" href="station.php" onclick="event.preventDefault(); navigateTo('station.php')"><i class="fa-solid fa-gas-pump" aria-hidden="true"></i><div><strong><?= count($stations) ?></strong><span>Total stations</span></div></a>
    <a class="hq-metric" href="station.php" onclick="event.preventDefault(); navigateTo('station.php')"><i class="fa-solid fa-circle-check" aria-hidden="true"></i><div><strong><?= count(array_filter($stations, static fn($s) => ($s['status'] ?: 'Active') === 'Active')) ?></strong><span>Active stations</span></div></a>
    <a class="hq-metric" href="station.php" onclick="event.preventDefault(); navigateTo('station.php')"><i class="fa-solid fa-wrench" aria-hidden="true"></i><div><strong><?= count(array_filter($stations, static fn($s) => $s['status'] === 'Maintenance')) ?></strong><span>Under maintenance</span></div></a>
  </div>

  <div class="dashboard-grid">
    <!-- Station fuel prices Card -->
    <div class="card card-clickable" onclick="navigateTo('pricing.php')">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h3 class="mb-0">Station fuel prices</h3>
        <a class="small text-info" href="pricing.php" onclick="event.stopPropagation(); event.preventDefault(); navigateTo('pricing.php')">Manage <i class="fa-solid fa-arrow-right ms-1"></i></a>
      </div>
      <div class="dash-scroll dash-scroll-tall">
      <table>
        <thead><tr><th>Station</th><th>Type</th><th>Price (MMK)</th><th>Action</th></tr></thead>
        <tbody>
          <?php
if (!empty($fuelPrices)): ?>
            <?php
foreach ($fuelPrices as $fuel): ?>
              <tr>
                <td><?php
echo htmlspecialchars($fuel['station_name']); ?></td>
                <td><?php
echo htmlspecialchars($fuel['oil_name']); ?></td>
                <td><?php
echo number_format($fuel['price_per_liter']); ?></td>
                <td><button class="btn" onclick="openEditModal(event, '<?php
echo (int)$fuel['station_id']; ?>', '<?php
echo (int)$fuel['oil_type_id']; ?>', '<?php
echo htmlspecialchars($fuel['station_name'] . ' / ' . $fuel['oil_name'], ENT_QUOTES); ?>', '<?php
echo htmlspecialchars($fuel['price_per_liter'], ENT_QUOTES); ?>')">Edit</button></td>
              </tr>
            <?php
endforeach; ?>
          <?php
else: ?>
            <tr><td colspan="4">No pricing records found.</td></tr>
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
        <thead><tr><th>Branch ID</th><th>Branch Name</th><th>Location</th><th>Status</th></tr></thead>
        <tbody>
          <?php
if (!empty($stations)): ?>
            <?php
foreach ($stations as $station): ?>
              <tr>
                <td><?php
echo htmlspecialchars($station['station_id']); ?></td>
                <td><?php
echo htmlspecialchars($station['station_name']); ?></td>
                <td><?php
echo htmlspecialchars($station['location']); ?></td>
                <td><span class="badge <?= ($station['status'] ?? '') === 'Maintenance' ? 'bg-warning' : ((($station['status'] ?: 'Active') === 'Active') ? 'bg-success' : 'bg-secondary') ?>"><?php
echo htmlspecialchars($station['status'] ?: 'Active'); ?></span></td>
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

  <!-- Edit Pricing Modal Pop-up -->
  <div class="modal fade" id="editPricingModal" tabindex="-1" aria-labelledby="editPricingModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content p-3">
        <div class="modal-header border-0">
          <h5 class="modal-title" id="editPricingModalLabel">Quick Edit Fuel Price</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <form id="quickEditForm" method="POST" action="">
            <input type="hidden" name="action" value="update_price">
            <div class="mb-3">
              <label for="modalFuelLabel" class="form-label text-muted">Station / Fuel Type</label>
              <input type="hidden" id="modalStationId" name="station_id">
              <input type="hidden" id="modalOilTypeId" name="oil_type_id">
              <input type="text" class="form-control" id="modalFuelLabel" readonly>
            </div>
            <div class="mb-3">
              <label for="modalFuelPrice" class="form-label text-muted">New Price (MMK)</label>
              <input type="text" class="form-control" id="modalFuelPrice" name="fuel_price" required>
            </div>
            <div class="d-flex justify-content-end gap-2 mt-4">
              <button type="button" class="btn btn-outline-secondary text-white" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>

  <footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map & Pump | All Rights Reserved</footer>

  <!-- Bootstrap JS Bundle (Required for Modals) -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

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
          return;
        }
        var head = box.querySelector('thead');
        var total = head ? head.getBoundingClientRect().height : 0;
        for (var i = 0; i < VISIBLE_ROWS; i++) {
          total += rows[i].getBoundingClientRect().height;
        }
        box.style.maxHeight = Math.ceil(total) + 'px';
      });
    }

    document.addEventListener('DOMContentLoaded', sizeDashScrollers);
    window.addEventListener('load', sizeDashScrollers);

    var dashResizeTimer;
    window.addEventListener('resize', function () {
      clearTimeout(dashResizeTimer);
      dashResizeTimer = setTimeout(sizeDashScrollers, 150);
    });

    function openEditModal(event, stationId, oilTypeId, label, currentPrice) {
      event.stopPropagation();
      
      document.getElementById('modalStationId').value = stationId;
      document.getElementById('modalOilTypeId').value = oilTypeId;
      document.getElementById('modalFuelLabel').value = label;
      document.getElementById('modalFuelPrice').value = currentPrice;

      const editModal = new bootstrap.Modal(document.getElementById('editPricingModal'));
      editModal.show();
    }
  </script>
</body>
</html>
