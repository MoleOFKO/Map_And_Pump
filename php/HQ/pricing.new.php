<?php
require_once __DIR__ . '/../conn.php';

$addMessage = '';
$updateMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_grade') {
        $gradeName = trim($_POST['fuel_grade'] ?? '');
        $startingPrice = trim($_POST['starting_price'] ?? '0');

        if ($gradeName === '') {
            $errorMessage = 'Please enter a fuel grade name.';
        } else {
            $stmt = $conn->prepare('SELECT COUNT(*) AS existing FROM oil_types WHERE LOWER(oil_name) = LOWER(?)');
            if ($stmt) {
                $stmt->bind_param('s', $gradeName);
                $stmt->execute();
                $stmt->bind_result($existing);
                $stmt->fetch();
                $stmt->close();

                if ($existing > 0) {
                    $errorMessage = 'This fuel grade already exists.';
                } else {
                    $priceValue = is_numeric($startingPrice) ? (int) $startingPrice : 0;
                    $insert = $conn->prepare('INSERT INTO oil_types (oil_name, price_per_liter) VALUES (?, ?)');
                    if ($insert) {
                        $insert->bind_param('si', $gradeName, $priceValue);
                        if ($insert->execute()) {
                            $addMessage = 'Fuel grade "' . htmlspecialchars($gradeName, ENT_QUOTES) . '" added successfully.';
                        } else {
                            $errorMessage = 'Unable to add fuel grade: ' . $conn->error;
                        }
                        $insert->close();
                    } else {
                        $errorMessage = 'Unable to prepare insert statement: ' . $conn->error;
                    }
                }
            } else {
                $errorMessage = 'Unable to prepare select statement: ' . $conn->error;
            }
        }
    }

    if ($action === 'update_price') {
        $fuelType = trim($_POST['fuel_type'] ?? '');
        $newPrice = trim($_POST['fuel_price'] ?? '');

        if ($fuelType === '' || $newPrice === '') {
            $errorMessage = 'Please select a fuel grade and enter a price.';
        } elseif (!is_numeric($newPrice) || (int) $newPrice < 0) {
            $errorMessage = 'Please enter a valid non-negative price.';
        } else {
            $priceValue = (int) $newPrice;
            $stmt = $conn->prepare('UPDATE oil_types SET price_per_liter = ? WHERE oil_name = ?');
            if ($stmt) {
                $stmt->bind_param('is', $priceValue, $fuelType);
                if ($stmt->execute()) {
                    if ($stmt->affected_rows > 0) {
                        $updateMessage = 'Updated "' . htmlspecialchars($fuelType, ENT_QUOTES) . '" to ' . number_format($priceValue) . ' MMK.';
                    } else {
                        $errorMessage = 'No changes were made. Please verify the selected fuel grade.';
                    }
                } else {
                    $errorMessage = 'Unable to update price: ' . $conn->error;
                }
                $stmt->close();
            } else {
                $errorMessage = 'Unable to prepare update statement: ' . $conn->error;
            }
        }
    }
}

$grades = [];
$result = $conn->query('SELECT ot.oil_name, MAX(soi.price_per_litre) AS price_per_liter FROM oil_types ot 
                        LEFT JOIN station_oil_inventory soi ON ot.oil_type_id = soi.oil_type_id
                        GROUP BY ot.oil_type_id, ot.oil_name ORDER BY ot.oil_name');
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $grades[] = $row;
    }
    $result->close();
}
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

    <?php if ($addMessage || $updateMessage || $errorMessage): ?>
        <div class="alert <?php echo $errorMessage ? 'alert-danger' : 'alert-success'; ?>" role="alert">
            <?php echo htmlspecialchars($errorMessage ?: $addMessage ?: $updateMessage); ?>
        </div>
    <?php endif; ?>

    <div class="card mb-4 p-4">
        <h3>Define Standard Oil Grade</h3>
        <p class="subtitle" style="margin-bottom:12px;">Declare a new standard fuel class in the system directory.</p>
        <form method="POST">
            <input type="hidden" name="action" value="create_grade">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Product Fuel Grade</label>
                    <input type="text" name="fuel_grade" class="form-control" placeholder="e.g., Premium Diesel Plus" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Starting Price (MMK)</label>
                    <input type="number" name="starting_price" class="form-control" min="0" step="100" value="0">
                </div>
            </div>
            <div class="mt-3">
                <button type="submit" class="btn btn-primary">Save Fuel Grade</button>
            </div>
        </form>
    </div>

    <div class="card mb-4 p-4">
        <h3>Active Live Base Price</h3>
        <p class="subtitle" style="margin-bottom:12px;">Push direct modifications down to electronic station nodes.</p>
        <form method="POST">
            <input type="hidden" name="action" value="update_price">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Select Product Grade</label>
                    <select name="fuel_type" class="form-select" required>
                        <option value="">Select grade</option>
                        <?php foreach ($grades as $grade): ?>
                            <option value="<?php echo htmlspecialchars($grade['oil_name'], ENT_QUOTES); ?>"><?php echo htmlspecialchars($grade['oil_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Active Base Pump Rate (Kyats/L)</label>
                    <input type="number" name="fuel_price" class="form-control" step="100" min="0" placeholder="e.g., 3500" required>
                </div>
            </div>
            <div class="mt-3">
                <button type="submit" class="btn btn-success">Publish Base Price</button>
            </div>
        </form>
    </div>

    <div class="card p-4">
        <h3>Current Fuel Grades</h3>
        <div class="table-responsive">
            <table class="table table-striped table-borderless align-middle">
                <thead>
                    <tr>
                        <th>Fuel Grade</th>
                        <th>Price / L</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($grades) > 0): ?>
                        <?php foreach ($grades as $grade): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($grade['oil_name']); ?></td>
                                <td><?php echo number_format((int) $grade['price_per_liter']); ?> MMK</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="2">No fuel grades defined yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
<footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map & Pump | All Rights Reserved</footer>
</html>
