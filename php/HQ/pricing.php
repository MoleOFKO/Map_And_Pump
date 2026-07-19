<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../../css/HQstyle.css">
</head>
<body>
    <h2 class="title">Oil Types & Pricing Matrix</h2>
    <p class="subtitle">Configure corporate product grades and establish localized/global base pricing.</p>

    <div class="card">
        <h3>Define Standard Oil Grade</h3>
        <p class="subtitle" style="margin-bottom:12px;">Declare a new standard fuel class in the system directory.</p>
        <div class="form-group">
            <label>Product Fuel Grade</label>
            <input type="text" placeholder="e.g., Premium Diesel Plus" id="fuel-input">
        </div>
        <button class="btn" onclick="addGrade()">Save Fuel Grade</button>
    </div>

    <div class="card">
        <h3>Active Live Base Price</h3>
        <p class="subtitle" style="margin-bottom:12px;">Push direct modifications down to electronic station nodes.</p>
        <div class="form-group">
            <label>Select Product Grade</label>
            <select id="grade-select">
                <option>Premium Diesel Plus</option>
                <option>Unleaded Extra (95)</option>
            </select>
        </div>
        <div class="form-group">
            <label>Active Base Pump Rate ($/L)</label>
            <input type="number" step="0.01" value="1.48">
        </div>
        <button class="btn btn-success" onclick="alert('Pricing modified globally.')">Publish Base Price</button>
    </div>

    <script>
        function addGrade() {
            const grade = document.getElementById('fuel-input').value;
            if(grade) {
                alert(`Successfully registered Oil Grade: ${grade}`);
                document.getElementById('fuel-input').value = '';
            }
        }
    </script>
</body>
<footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map & Pump | All Rights Reserved</footer>
</html>