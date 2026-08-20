<?php
// // Database connection configuration
// $host = 'gateway01.ap-southeast-1.prod.aws.tidbcloud.com'; // e.g., gateway01.us-west-2.prod.aws.tidbcloud.com
// $dbname = 'map_and_pump';
// $username = '3sdV1BWxtCzY7YS.root';
// $password = 'GNfDltJeN7paYMLq';
// $port = 4000; // Usually 4000 for TiDB, or 3306 for standard MySQL secure ports

// try {
//     $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
    
//     // Required SSL options for cloud databases requiring secure transport
//     $options = [
//         PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
//         PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true, // Set to true if you are supplying a specific CA bundle
//         // If your cloud provider requires a specific CA certificate file, uncomment below:
//          PDO::MYSQL_ATTR_SSL_CA => '../isrgrootx1.pem', // Path to the CA certificate file
//     ];

//     $pdo = new PDO($dsn, $username, $password, $options);
// } catch (PDOException $e) {
//     die("Database connection failed: " . $e->getMessage());
// }
require_once __DIR__ . '/../conn.php';
// Handle form submission
$message = '';
$loggedUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$loggedHqId = isset($_SESSION['headquarter_id']) ? (int) $_SESSION['headquarter_id'] : 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = $_POST['company_name'] ?? '';
    $info = $_POST['headquarter_bio'] ?? '';
    $email = $_POST['headquarter_email'] ?? '';
    $phone = $_POST['contact_number'] ?? '';
    $businessLicense = $_POST['business_license'] ?? '';
    $location = $_POST['location'] ?? '';

    // Handle Logo Upload if present
    $logoPath = null;
    if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $fileExtension = pathinfo($_FILES['company_logo']['name'], PATHINFO_EXTENSION);
        $fileName = 'logo_' . time() . '.' . $fileExtension;
        $uploadFile = $uploadDir . $fileName;

        if (move_uploaded_file($_FILES['company_logo']['tmp_name'], $uploadFile)) {
            $logoPath = 'uploads/' . $fileName;
        }
    }

    // Load the headquarters belonging to the signed-in user.
    if ($loggedUserId > 0) {
        $stmt = $pdo->prepare("SELECT h.headquarter_id, h.company_logo, h.headquarter_email FROM users u JOIN headquarter h ON h.headquarter_id = u.headquarter_id WHERE u.user_id = ? LIMIT 1");
        $stmt->execute([$loggedUserId]);
    } elseif ($loggedHqId > 0) {
        $stmt = $pdo->prepare("SELECT headquarter_id, company_logo, headquarter_email FROM headquarter WHERE headquarter_id = ? LIMIT 1");
        $stmt->execute([$loggedHqId]);
    } else {
        $stmt = $pdo->query("SELECT headquarter_id, company_logo, headquarter_email FROM headquarter LIMIT 1");
    }
    $config = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$logoPath && $config) {
        $logoPath = $config['company_logo'];
    }

    if ($config) {
        // Save the brand settings without allowing an email conflict to block a logo update.
        $updateStmt = $pdo->prepare("UPDATE headquarter SET company_name = ?, headquarter_bio = ?, contact_number = ?, business_license = ?, location = ?, company_logo = ? WHERE headquarter_id = ?");
        $updateStmt->execute([$title, $info, $phone, $businessLicense, $location, $logoPath, $config['headquarter_id']]);

        $email = trim($email);
        if ($email !== '' && strcasecmp($email, (string) $config['headquarter_email']) !== 0) {
            $emailCheck = $pdo->prepare("SELECT headquarter_id FROM headquarter WHERE headquarter_email = ? AND headquarter_id <> ? LIMIT 1");
            $emailCheck->execute([$email, $config['headquarter_id']]);

            if (!$emailCheck->fetchColumn()) {
                $emailStmt = $pdo->prepare("UPDATE headquarter SET headquarter_email = ? WHERE headquarter_id = ?");
                $emailStmt->execute([$email, $config['headquarter_id']]);
            } else {
                $message = "Brand settings saved, but that email is already used by another headquarters.";
            }
        }
    } else {
        // Insert new record
        $insertStmt = $pdo->prepare("INSERT INTO headquarter (company_name, headquarter_bio, headquarter_email, contact_number, business_license, location, company_logo) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $insertStmt->execute([$title, $info, $email, $phone, $businessLicense, $location, $logoPath]);
    }

    if ($message === '') {
        $message = "Corporate parameters stored successfully!";
    }
}

// Fetch current configuration to populate the form
if ($loggedUserId > 0) {
    $stmt = $pdo->prepare("SELECT h.* FROM users u JOIN headquarter h ON h.headquarter_id = u.headquarter_id WHERE u.user_id = ? LIMIT 1");
    $stmt->execute([$loggedUserId]);
} elseif ($loggedHqId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM headquarter WHERE headquarter_id = ? LIMIT 1");
    $stmt->execute([$loggedHqId]);
} else {
    $stmt = $pdo->query("SELECT * FROM headquarter LIMIT 1");
}
$currentConfig = $stmt->fetch(PDO::FETCH_ASSOC);

$brandTitle = $currentConfig['company_name'] ?? 'Apex Fuel & Gas';
$brandInfo = $currentConfig['headquarter_bio'] ?? '';
$brandEmail = $currentConfig['headquarter_email'] ?? 'hq@apexfuelgas.com';
$brandPhone = $currentConfig['contact_number'] ?? '+1 (800) 555-APEX';
$businessLicense = $currentConfig['business_license'] ?? '';
$location = $currentConfig['location'] ?? '';
$brandLogo = $currentConfig['company_logo'] ?? 'https://via.placeholder.com/150';
if ($brandLogo !== '' && !preg_match('#^(?:https?:)?//#i', $brandLogo)) {
    $brandLogo = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/php/HQ/brand.php')), '/') . '/' . ltrim(preg_replace('#^/php/HQ/#', '', $brandLogo), '/');
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
    <h2 class="title">Brand Configuration</h2>
    <p class="subtitle">Set global brand styles, logos, and configurations.</p>
    
    <div class="card p-4">
        <?php if (!empty($message)): ?>
            <div class="alert alert-success" role="alert">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <div class="row align-items-center">
                <!-- Logo Preview & Upload -->
                <div class="col-md-4 text-center border-end-md mb-4 mb-md-0">
                    <img id="logoPreview" src="<?= htmlspecialchars($brandLogo) ?>" alt="Brand Logo" class="rounded-circle img-thumbnail mb-3" style="width: 150px; height: 150px; object-fit: cover; background-color: #fff;">
                    <div>
                        <input type="file" name="company_logo" id="brandLogoInput" accept="image/*" style="display: none;" onchange="previewLogo(event)">
                        <label for="brandLogoInput" class="btn btn-sm btn-outline-secondary text-white bg-secondary">Change Logo</label>
                    </div>
                </div>

                <!-- Form Fields -->
                <div class="col-md-8 ps-md-4">
                    <div class="form-group mb-3">
                        <label class="form-label">Brand Display Title</label>
                        <input type="text" class="form-control" name="company_name" value="<?= htmlspecialchars($brandTitle) ?>" placeholder="Company Display Name">
                    </div>
            
                    <div class="form-group mb-3">
                        <label class="form-label">Company Information & Details</label>
                        <textarea class="form-control custom-input" name="headquarter_bio" rows="3" placeholder="Enter corporate background, address, or details..."><?= htmlspecialchars($brandInfo) ?></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Corporate Email</label>
                            <input type="email" class="form-control" name="headquarter_email" value="<?= htmlspecialchars($brandEmail) ?>" placeholder="corp@example.com">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Support / Contact Phone</label>
                            <input type="text" class="form-control" name="contact_number" value="<?= htmlspecialchars($brandPhone) ?>" placeholder="+1 (xxx) xxx-xxxx">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Business License</label>
                            <input type="text" class="form-control" name="business_license" value="<?= htmlspecialchars($businessLicense) ?>" placeholder="License Number">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Location</label>
                            <input type="text" class="form-control" name="location" value="<?= htmlspecialchars($location) ?>" placeholder="Office Location / City">
                        </div>
                    </div>

                    <div class="text-center mt-3">
                        <button type="submit" class="btn btn-primary px-4">Apply Brand Config</button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <script>
        function previewLogo(event) {
            const file = event.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('logoPreview').src = e.target.result;
                }
                reader.readAsDataURL(file);
            }
        }
    </script>
</body>
<footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map & Pump | All Rights Reserved</footer>
</html>