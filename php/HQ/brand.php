<?php
// Database connection configuration
$host = 'gateway01.ap-southeast-1.prod.aws.tidbcloud.com'; // e.g., gateway01.us-west-2.prod.aws.tidbcloud.com
$dbname = 'map_and_pump';
$username = '3sdV1BWxtCzY7YS.root';
$password = 'GNfDltJeN7paYMLq';
$port = 4000; // Usually 4000 for TiDB, or 3306 for standard MySQL secure ports

try {
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
    
    // Required SSL options for cloud databases requiring secure transport
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true, // Set to true if you are supplying a specific CA bundle
        // If your cloud provider requires a specific CA certificate file, uncomment below:
         PDO::MYSQL_ATTR_SSL_CA => '../isrgrootx1.pem', // Path to the CA certificate file
    ];

    $pdo = new PDO($dsn, $username, $password, $options);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Handle form submission
$message = '';
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
        $uploadDir = 'uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $fileExtension = pathinfo($_FILES['company_logo']['name'], PATHINFO_EXTENSION);
        $fileName = 'logo_' . time() . '.' . $fileExtension;
        $uploadFile = $uploadDir . $fileName;
        
        if (move_uploaded_file($_FILES['company_logo']['tmp_name'], $uploadFile)) {
            $logoPath = $uploadFile;
        }
    }

    // Check if configuration already exists in database
    $stmt = $pdo->query("SELECT id, company_logo FROM headquarter LIMIT 1");
    $config = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$logoPath && $config) {
        $logoPath = $config['company_logo'];
    }

    if ($config) {
        // Update existing record
        $updateStmt = $pdo->prepare("UPDATE headquarter SET company_name = ?, headquarter_bio = ?, headquarter_email = ?, contact_number = ?, business_license = ?, location = ?, company_logo = ? WHERE id = ?");
        $updateStmt->execute([$title, $info, $email, $phone, $businessLicense, $location, $logoPath, $config['id']]);
    } else {
        // Insert new record
        $insertStmt = $pdo->prepare("INSERT INTO headquarter (company_name, headquarter_bio, headquarter_email, contact_number, business_license, location, company_logo) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $insertStmt->execute([$title, $info, $email, $phone, $businessLicense, $location, $logoPath]);
    }
    
    $message = "Corporate parameters stored successfully!";
}

// Fetch current configuration to populate the form
$stmt = $pdo->query("SELECT * FROM headquarter LIMIT 1");
$currentConfig = $stmt->fetch(PDO::FETCH_ASSOC);

$brandTitle = $currentConfig['company_name'] ?? 'Apex Fuel & Gas';
$brandInfo = $currentConfig['headquarter_bio'] ?? '';
$brandEmail = $currentConfig['headquarter_email'] ?? 'hq@apexfuelgas.com';
$brandPhone = $currentConfig['contact_number'] ?? '+1 (800) 555-APEX';
$businessLicense = $currentConfig['business_license'] ?? '';
$location = $currentConfig['location'] ?? '';
$brandLogo = $currentConfig['company_logo'] ?? 'https://via.placeholder.com/150';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="/css/HQstyle.css">
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
                        <input type="file" name="brandLogo" id="brandLogoInput" accept="image/*" style="display: none;" onchange="previewLogo(event)">
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