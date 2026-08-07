<?php
// Database connection configuration
$host = 'mysql-19032a73-bhonen94-b057.a.aivencloud.com';
$dbname = 'map_and_pump';
$username = 'avnadmin';
$password = 'AVNS_D_5WaEsuc_hdxjtXj1J';
$port = 27909;
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Handle Form Submissions
$alertMessage = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_grade'])) {
        $fuelGrade = trim($_POST['fuel_grade']);
        if (!empty($fuelGrade)) {
            $stmt = $pdo->prepare("INSERT INTO oil_grades (grade_name) VALUES (:grade_name)");
            $stmt->execute(['grade_name' => $fuelGrade]);
            $alertMessage = "Successfully registered Oil Grade: " . htmlspecialchars($fuelGrade);
        }
    }
    
    if (isset($_POST['publish_price'])) {
        $selectedGrade = trim($_POST['selected_grade']);
        $pumpRate = trim($_POST['pump_rate']);
        if (!empty($selectedGrade) && !empty($pumpRate)) {
            $stmt = $pdo->prepare("INSERT INTO base_prices (grade_name, pump_rate) VALUES (:grade_name, :pump_rate)");
            $stmt->execute(['grade_name' => $selectedGrade, 'pump_rate' => $pumpRate]);
            $alertMessage = "Pricing modified globally for " . htmlspecialchars($selectedGrade) . " at " . htmlspecialchars($pumpRate) . " Kyats/L.";
        }
    }
}

// Fetch existing grades for the dropdown
$gradesStmt = $pdo->query("SELECT grade_name FROM oil_grades ORDER BY id DESC");
$availableGrades = $gradesStmt->fetchAll(PDO::FETCH_ASSOC);
?>
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

    <?php if (!empty($alertMessage)): ?>
        <div class="alert alert-success mx-4" role="alert">
            <?php echo $alertMessage; ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <h3>Define Standard Oil Grade</h3>
        <p class="subtitle" style="margin-bottom:12px;">Declare a new standard fuel class in the system directory.</p>
        <form method="POST">
            <div class="form-group">
                <label>Product Fuel Grade</label>
                <input type="text" name="fuel_grade" placeholder="e.g., Premium Diesel Plus" id="fuel-input" required>
            </div>
            <button type="submit" name="save_grade" class="btn">Save Fuel Grade</button>
        </form>
    </div>

    <div class="card">
        <h3>Active Live Base Price</h3>
        <p class="subtitle" style="margin-bottom:12px;">Push direct modifications down to electronic station nodes.</p>
        <form method="POST">
            <div class="form-group">
                <label>Select Product Grade</label>
                <select id="grade-select" name="selected_grade">
                    <?php if (empty($availableGrades)): ?>
                        <option>Premium Diesel Plus</option>
                        <option>Unleaded Extra (95)</option>
                    <?php else: ?>
                        <?php foreach ($availableGrades as $grade): ?>
                            <option value="<?php echo htmlspecialchars($grade['grade_name']); ?>">
                                <?php echo htmlspecialchars($grade['grade_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Active Base Pump Rate (Kyats/L)</label>
                <input type="number" name="pump_rate" step="100" value="3500" required>
            </div>
            <button type="submit" name="publish_price" class="btn btn-success">Publish Base Price</button>
        </form>
    </div>
</body>
<footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map & Pump | All Rights Reserved</footer>
</html>
