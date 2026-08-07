<?php
// Database configuration (Update these credentials to match your environment)
// $host = 'localhost';
// $db   = 'brand_db';
// $user = 'root';
// $pass = '';
// $charset = 'utf8mb4';
include '../conn.php';
$charset = 'utf8mb4';
$dsn = "mysql:host=$hostname;dbname=$database;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

$message = '';

// Default values
$brandTitle = "Apex Fuel & Gas";
$brandInfo = "Apex Fuel & Gas - Global Headquarters, Operations, and Terminal Management Systems.";
$brandEmail = "hq@apexfuelgas.com";
$brandPhone = "+1 (800) 555-APEX";
$brandLocation = "100 Energy Plaza, Houston, TX 77002";
$brandLicense = "BL-987654321-TX";
$brandColor = "#2563eb";
$brandLogo = "https://via.placeholder.com/150";

try {
    $pdo = new PDO($dsn, $username, $password, $options);

    // Create table if it doesn't exist with location and business license columns
    // $pdo->exec("CREATE TABLE IF NOT EXISTS brand_configurations (
    //     id INT AUTO_INCREMENT PRIMARY KEY,
    //     brand_title VARCHAR(255) NOT NULL,
    //     brand_info TEXT NOT NULL,
    //     brand_email VARCHAR(255) NOT NULL,
    //     brand_phone VARCHAR(50) NOT NULL,
    //     brand_location VARCHAR(255) NOT NULL,
    //     brand_license VARCHAR(100) NOT NULL,
    //     brand_color VARCHAR(50) NOT NULL,
    //     brand_logo VARCHAR(255) NOT NULL
    // )");

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $brandTitle = $_POST['brandTitle'] ?? $brandTitle;
        $brandInfo = $_POST['brandInfo'] ?? $brandInfo;
        $brandEmail = $_POST['brandEmail'] ?? $brandEmail;
        $brandPhone = $_POST['brandPhone'] ?? $brandPhone;
        $brandLocation = $_POST['brandLocation'] ?? $brandLocation;
        $brandLicense = $_POST['brandLicense'] ?? $brandLicense;
        $brandColor = $_POST['brandColor'] ?? $brandColor;

        // Fetch existing logo to keep if no new one is uploaded
        $stmt = $pdo->query("SELECT brand_logo FROM brand_configurations ORDER BY id DESC LIMIT 1");
        $existingConfig = $stmt->fetch();
        if ($existingConfig) {
            $brandLogo = $existingConfig['brand_logo'];
        }

        // Handle logo file upload
        if (isset($_FILES['brandLogoInput']) && $_FILES['brandLogoInput']['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['brandLogoInput']['tmp_name'];
            $fileName = $_FILES['brandLogoInput']['name'];
            $uploadFileDir = './uploads/';
            
            if (!is_dir($uploadFileDir)) {
                mkdir($uploadFileDir, 0755, true);
            }
            
            $newFileName = md5(time() . $fileName) . '.' . pathinfo($fileName, PATHINFO_EXTENSION);
            $dest_path = $uploadFileDir . $newFileName;
            
            if(move_uploaded_file($fileTmpPath, $dest_path)) {
                $brandLogo = $dest_path;
            }
        }

        // Check if a record already exists
        $stmt = $pdo->query("SELECT COUNT(*) FROM brand_configurations");
        $count = $stmt->fetchColumn();

        if ($count > 0) {
            // Update existing record
            $sql = "UPDATE brand_configurations SET brand_title = ?, brand_info = ?, brand_email = ?, brand_phone = ?, brand_location = ?, brand_license = ?, brand_color = ?, brand_logo = ? ORDER BY id DESC LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$brandTitle, $brandInfo, $brandEmail, $brandPhone, $brandLocation, $brandLicense, $brandColor, $brandLogo]);
        } else {
            // Insert new record
            $sql = "INSERT INTO brand_configurations (brand_title, brand_info, brand_email, brand_phone, brand_location, brand_license, brand_color, brand_logo) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$brandTitle, $brandInfo, $brandEmail, $brandPhone, $brandLocation, $brandLicense, $brandColor, $brandLogo]);
        }

        $message = "Corporate parameters stored successfully in the database!";
    }

    // Load latest configuration from database
    $stmt = $pdo->query("SELECT * FROM brand_configurations ORDER BY id DESC LIMIT 1");
    $config = $stmt->fetch();
    if ($config) {
        $brandTitle = $config['brand_title'];
        $brandInfo = $config['brand_info'];
        $brandEmail = $config['brand_email'];
        $brandPhone = $config['brand_phone'];
        $brandLocation = $config['brand_location'];
        $brandLicense = $config['brand_license'];
        $brandColor = $config['brand_color'];
        $brandLogo = $config['brand_logo'];
    }

} catch (\PDOException $e) {
    $message = "Database Error: " . $e->getMessage();
}
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
            <div class="alert alert-info" role="alert">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <div class="row align-items-center">
                <!-- Logo Preview & Upload -->
                <div class="col-md-4 text-center border-end-md mb-4 mb-md-0">
                    <img id="logoPreview" src="<?php echo htmlspecialchars($brandLogo); ?>" alt="Brand Logo" class="rounded-circle img-thumbnail mb-3" style="width: 150px; height: 150px; object-fit: cover; background-color: #fff;">
                    <div>
                        <input type="file" name="brandLogoInput" id="brandLogoInput" accept="image/*" style="display: none;" onchange="previewLogo(event)">
                        <label for="brandLogoInput" class="btn btn-sm btn-outline-secondary text-white">Change Logo</label>
                    </div>
                </div>

                <!-- Form Fields -->
                <div class="col-md-8 ps-md-4">
                    <div class="form-group mb-3">
                        <label class="form-label">Brand Display Title</label>
                        <input type="text" class="form-control" id="brandTitle" name="brandTitle" value="<?php echo htmlspecialchars($brandTitle); ?>" placeholder="Company Display Name">
                    </div>

                    <div class="form-group mb-3">
                        <label class="form-label">Company Information & Details</label>
                        <textarea class="form-control custom-input" id="brandInfo" name="brandInfo" rows="3" placeholder="Enter corporate background, address, or details..."><?php echo htmlspecialchars($brandInfo); ?></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Corporate Email</label>
                            <input type="email" class="form-control" id="brandEmail" name="brandEmail" value="<?php echo htmlspecialchars($brandEmail); ?>" placeholder="corp@example.com">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Support / Contact Phone</label>
                            <input type="text" class="form-control" id="brandPhone" name="brandPhone" value="<?php echo htmlspecialchars($brandPhone); ?>" placeholder="+1 (xxx) xxx-xxxx">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Headquarters Location / Address</label>
                            <input type="text" class="form-control" id="brandLocation" name="brandLocation" value="<?php echo htmlspecialchars($brandLocation); ?>" placeholder="Enter corporate location">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Business License Number</label>
                            <input type="text" class="form-control" id="brandLicense" name="brandLicense" value="<?php echo htmlspecialchars($brandLicense); ?>" placeholder="Enter business license number">
                        </div>
                    </div>

                    <div class="form-group mb-4">
                        <label class="form-label">Interface Primary Accent</label>
                        <div>
                            <input type="color" class="form-control" id="brandColor" name="brandColor" value="<?php echo htmlspecialchars($brandColor); ?>" style="width: 80px; height: 38px; padding: 2px; cursor: pointer;">
                        </div>
                    </div>

                    <div class="text-center">
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