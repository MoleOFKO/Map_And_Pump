<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>HQ Dashboard</title>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="/css/HQstyle.css">
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
          <tr>
            <td>Octane 92</td>
            <td>2,850</td>
            <td><button class="btn" onclick="openEditModal(event, 'Octane 92', '2,850')">Edit</button></td>
          </tr>
          <tr>
            <td>Octane 95</td>
            <td>2,970</td>
            <td><button class="btn" onclick="openEditModal(event, 'Octane 95', '2,970')">Edit</button></td>
          </tr>
          <tr>
            <td>Diesel</td>
            <td>2,450</td>
            <td><button class="btn" onclick="openEditModal(event, 'Diesel', '2,450')">Edit</button></td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Recent Manager Reports Card -->
    <div class="card card-clickable" onclick="navigateTo('report_from_manager.php')">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h3 class="mb-0">Recent Manager Reports</h3>
        <span class="small text-info">View All <i class="fa-solid fa-arrow-right ms-1"></i></span>
      </div>
      <p class="subtitle" style="margin-bottom: 0;">No urgent pending reports. Click to review logs.</p>
    </div>

    <!-- Live Station Monitor Card -->
    <div class="card card-full full-width card-clickable" onclick="navigateTo('station.php')">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h3 class="mb-0">Live Station Monitor</h3>
        <span class="small text-info">Manage Stations <i class="fa-solid fa-arrow-right ms-1"></i></span>
      </div>
      <table>
        <thead><tr><th>Branch ID</th><th>Location</th><th>Status</th></tr></thead>
        <tbody>
          <tr><td>YGN-014</td><td>Sanchaung</td><td><span class="badge">Operational</span></td></tr>
          <tr><td>MDY-002</td><td>Chan Aye Tharzan</td><td><span class="badge">Operational</span></td></tr>
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
          <form id="quickEditForm" onsubmit="saveQuickPrice(event)">
            <div class="mb-3">
              <label for="modalFuelType" class="form-label text-muted">Fuel Type</label>
              <input type="text" class="form-control" id="modalFuelType" readonly>
            </div>
            <div class="mb-3">
              <label for="modalFuelPrice" class="form-label text-muted">New Price (MMK)</label>
              <input type="text" class="form-control" id="modalFuelPrice" required>
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

  <!-- <script>
    function navigateTo(page) {
      if (window.parent && typeof window.parent.routeTo === 'function') {
        window.parent.routeTo(page);
        const sidebarItems = window.parent.document.querySelectorAll('.sidebar-menu li');
        sidebarItems.forEach(item => {
          if (item.getAttribute('onclick') && item.getAttribute('onclick').includes(page)) {
            sidebarItems.forEach(i => i.classList.remove('active'));
            item.classList.add('active');
          }
        });
      } else {
        window.location.href = page;
      }
    }

    function openEditModal(event, fuelType, currentPrice) {
      event.stopPropagation(); // Prevents card's main click event from triggering navigation
      
      document.getElementById('modalFuelType').value = fuelType;
      document.getElementById('modalFuelPrice').value = currentPrice;

      const editModal = new bootstrap.Modal(document.getElementById('editPricingModal'));
      editModal.show();
    }

    function saveQuickPrice(event) {
      event.preventDefault();
      const fuelType = document.getElementById('modalFuelType').value;
      const newPrice = document.getElementById('modalFuelPrice').value;

      alert(`Successfully updated price for ${fuelType} to ${newPrice} MMK`);
      
      // Close the modal programmatically
      const modalElement = document.getElementById('editPricingModal');
      const modalInstance = bootstrap.Modal.getInstance(modalElement);
      modalInstance.hide();
    }
  </script> -->
  <script>
    function navigateTo(page) {
      if (window.parent && typeof window.parent.routeTo === 'function') {
        // Call the parent frame's routeTo function directly to maintain consistent behavior and active states
        window.parent.routeTo(page);
      } else {
        window.location.href = page;
      }
    }

    function openEditModal(event, fuelType, currentPrice) {
      event.stopPropagation(); // Prevents card's main click event from triggering navigation
      
      document.getElementById('modalFuelType').value = fuelType;
      document.getElementById('modalFuelPrice').value = currentPrice;

      const editModal = new bootstrap.Modal(document.getElementById('editPricingModal'));
      editModal.show();
    }

    function saveQuickPrice(event) {
      event.preventDefault();
      const fuelType = document.getElementById('modalFuelType').value;
      const newPrice = document.getElementById('modalFuelPrice').value;

      alert(`Successfully updated price for ${fuelType} to ${newPrice} MMK`);
      
      // Close the modal programmatically
      const modalElement = document.getElementById('editPricingModal');
      const modalInstance = bootstrap.Modal.getInstance(modalElement);
      modalInstance.hide();
    }
  </script>
</body>
</html>