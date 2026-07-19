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
    <div class="card">
      <h3>National Fuel Pricing</h3>
      <table>
        <thead><tr><th>Type</th><th>Price (MMK)</th><th>Action</th></tr></thead>
        <tbody>
          <tr><td>Octane 92</td><td>2,850</td><td><button class="btn">Edit</button></td></tr>
          <tr><td>Octane 95</td><td>2,970</td><td><button class="btn">Edit</button></td></tr>
          <tr><td>Diesel</td><td>2,450</td><td><button class="btn">Edit</button></td></tr>
        </tbody>
      </table>
    </div>

    <div class="card">
      <h3>Recent Manager Reports</h3>
      <p class="subtitle" style="margin-bottom: 0;">No urgent pending reports.</p>
    </div>

    <div class="card full-width">
      <h3>Live Station Monitor</h3>
      <table>
        <thead><tr><th>Branch ID</th><th>Location</th><th>Status</th></tr></thead>
        <tbody>
          <tr><td>YGN-014</td><td>Sanchaung</td><td><span class="badge">Operational</span></td></tr>
          <tr><td>MDY-002</td><td>Chan Aye Tharzan</td><td><span class="badge">Operational</span></td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map & Pump | All Rights Reserved</footer>
</body>
</html>