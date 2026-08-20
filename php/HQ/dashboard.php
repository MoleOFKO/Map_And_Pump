<?php
// $hostname = "mysql-19032a73-bhonen94-b057.a.aivencloud.com";
// $port = 27909;
// $username = "avnadmin";
// $password = "AVNS_D_5WaEsuc_hdxjtXj1J";
// $database = "map_and_pump"; // Change this to your database name if different

// // Create connection
// $conn = new mysqli($hostname, $username, $password, $database, $port);
// // include ('../conn.php');
// // Check connection
// if ($conn->connect_error) {
//     $db_error = "Database connection failed.";
// }
require_once __DIR__ . '/../conn.php';
// Handle quick price update submission if posted
$updateMessage = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_price') {
    $fuelType = trim((string)($_POST['fuel_type'] ?? ''));
    $newPrice = trim((string)($_POST['fuel_price'] ?? ''));

    if ($fuelType !== '' && $newPrice !== '' && is_numeric($newPrice) && (float) $newPrice >= 0) {
        $stmt = $conn->prepare("UPDATE station_oil_inventory soi JOIN oil_types ot ON ot.oil_type_id = soi.oil_type_id SET soi.price_per_litre = ? WHERE ot.oil_name = ?");
        if ($stmt) {
            $priceValue = (int) $newPrice;
            $stmt->bind_param("is", $priceValue, $fuelType);
            if ($stmt->execute()) {
                $updateMessage = "Successfully updated price for " . htmlspecialchars($fuelType) . " to " . htmlspecialchars($newPrice) . " MMK";
            } else {
                $updateMessage = "Error updating price: " . $conn->error;
            }
            $stmt->close();
        }
    } else {
        $updateMessage = "Please enter a valid non-negative numeric price.";
    }
}

// Fetch National Fuel Pricing dynamically from the live station inventory
$fuelPrices = [];
$pricingResult = $conn->query("SELECT ot.oil_name, MAX(soi.price_per_litre) AS price_per_liter FROM oil_types ot LEFT JOIN station_oil_inventory soi ON ot.oil_type_id = soi.oil_type_id GROUP BY ot.oil_type_id, ot.oil_name ORDER BY ot.oil_name");
if ($pricingResult) {
    while ($row = $pricingResult->fetch_assoc()) {
        $fuelPrices[] = $row;
    }
}

// Fetch recent manager reports by the user's original business role, since role_id is normalized to the customer profile in the account-switch flow.
$recentReports = [];
$reportsQuery = "SELECT r.report_id, r.user_id, r.short_summery, r.message, r.importance, r.created_at, u.user_name, COALESCE(u.business_role_id, u.role_id) AS source_role_id FROM report r LEFT JOIN users u ON u.user_id = r.user_id WHERE COALESCE(u.business_role_id, u.role_id) = 4 ORDER BY r.created_at DESC LIMIT 5";
$reportsResult = $conn->query($reportsQuery);
if ($reportsResult) {
    while ($row = $reportsResult->fetch_assoc()) {
        $recentReports[] = $row;
    }
}

// Fetch Live Stations dynamically
$stations = [];
$stationsResult = $conn->query("SELECT station_id, station_name, location, status FROM stations");
if ($stationsResult) {
    while ($row = $stationsResult->fetch_assoc()) {
        $stations[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>HQ Dashboard</title>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="../../css/HQstyle.css">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;600&display=swap" rel="stylesheet">

<style>
  body {
    padding: 30px 32px 60px;
  }

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
    border-top: 1px solid rgba(255, 255, 255, 0.08);
    font-size: 14px;
  }

  .badge {
    padding: 4px 8px;
    border-radius: 6px;
    font-size: 11px;
    background: #064e3b;
    color: #34d399;
  }

  /* Custom styling for Bootstrap Modal to match HQ dark theme */
  .modal-content {
    background: linear-gradient(160deg, var(--navy-800, #101a30), var(--navy-900, #0b1220));
    color: var(--slate-50, #f8fafc);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 18px;
  }

  .modal-header, .modal-footer {
    border-color: rgba(255, 255, 255, 0.08);
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
  <h2 class="title">Headquarters Dashboard</h2>
  <p class="subtitle">Monitor national pricing, reporting activity, and station health in one place.</p>

  <?php if (!empty($updateMessage)): ?>
    <div class="alert alert-info alert-dismissible fade show" role="alert">
      <?php echo $updateMessage; ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php endif; ?>

  <div class="dashboard-grid">
    <!-- National Fuel Pricing Card -->
    <div class="card card-clickable" onclick="navigateTo('pricing.php')">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h3 class="mb-0">National Fuel Pricing</h3>
        <span class="small text-info">Manage <i class="fa-solid fa-arrow-right ms-1"></i></span>
      </div>
      <table>
        <thead><tr><th>Type</th><th>Price (MMK)</th><th>Action</th></tr></thead>
        <tbody>
          <?php if (!empty($fuelPrices)): ?>
            <?php foreach ($fuelPrices as $fuel): ?>
              <tr>
                <td><?php echo htmlspecialchars($fuel['oil_name']); ?></td>
                <td><?php echo number_format($fuel['price_per_liter']); ?></td>
                <td><button class="btn" onclick="openEditModal(event, '<?php echo htmlspecialchars($fuel['oil_name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($fuel['price_per_liter'], ENT_QUOTES); ?>')">Edit</button></td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="3">No pricing records found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Recent Manager Reports Card -->
    <div class="card card-clickable" onclick="navigateTo('report_from_manager.php')">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h3 class="mb-0">Recent Manager Reports</h3>
        <span class="small text-info">View All <i class="fa-solid fa-arrow-right ms-1"></i></span>
      </div>
      <?php if (!empty($recentReports)): ?>
        <table>
          <thead><tr><th>Branch</th><th>Summary</th><th>Date</th></tr></thead>
          <tbody>
            <?php foreach ($recentReports as $report): ?>
              <tr>
                <td><?php echo htmlspecialchars($report['user_name'] ?? 'Manager'); ?></td>
                <td><?php echo htmlspecialchars(substr($report['short_summery'] ?? 'No summary provided', 0, 40)); ?>...</td>
                <td><?php echo htmlspecialchars($report['created_at']); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <p class="subtitle" style="margin-bottom: 0;">No urgent pending reports. Click to review logs.</p>
      <?php endif; ?>
    </div>

    <!-- Live Station Monitor Card -->
    <div class="card card-full full-width card-clickable" onclick="navigateTo('station.php')">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h3 class="mb-0">Live Station Monitor</h3>
        <span class="small text-info">Manage Stations <i class="fa-solid fa-arrow-right ms-1"></i></span>
      </div>
      <table>
        <thead><tr><th>Branch ID</th><th>Branch Name</th><th>Location</th><th>Status</th></tr></thead>
        <tbody>
          <?php if (!empty($stations)): ?>
            <?php foreach ($stations as $station): ?>
              <tr>
                <td><?php echo htmlspecialchars($station['station_id']); ?></td>
                <td><?php echo htmlspecialchars($station['station_name']); ?></td>
                <td><?php echo htmlspecialchars($station['location']); ?></td>
                <td><span class="badge"><?php echo htmlspecialchars($station['status'] ?: 'Active'); ?></span></td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="4">No stations monitored.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
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
              <label for="modalFuelType" class="form-label text-muted">Fuel Type</label>
              <input type="text" class="form-control" id="modalFuelType" name="fuel_type" readonly>
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

    function openEditModal(event, fuelType, currentPrice) {
      event.stopPropagation();
      
      document.getElementById('modalFuelType').value = fuelType;
      document.getElementById('modalFuelPrice').value = currentPrice;

      const editModal = new bootstrap.Modal(document.getElementById('editPricingModal'));
      editModal.show();
    }
  </script>
</body>
</html>