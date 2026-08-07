<?php
// // Database connection configuration
// $servername = "localhost";
// $username = "root";
// $password = "";
// $dbname = "your_database_name";

// // Create database connection
// $conn = new mysqli($servername, $username, $password, $dbname);

// // Check connection
// if ($conn->connect_error) {
//     die("Connection failed: " . $conn->connect_error);
// }
include '../conn.php';
$message = "";
$id = isset($_GET['headquarter_id']) ? intval($_GET['headquarter_id']) : 1; // Default to 1 if not provided
// Handle form submission to update brand configurations directly in the database
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $id = $conn->real_escape_string($_POST['headquarter_id']); // Assuming you have a hidden input for headquarter_id in your form
    $title = $conn->real_escape_string($_POST['company_name']);
    $license = $conn->real_escape_string($_POST['business_license']);
    $location = $conn->real_escape_string($_POST['location']); // Location input
    $info = $conn->real_escape_string($_POST['headquarter_bio']);
    $email = $conn->real_escape_string($_POST['brandEmail']);
    $phone = $conn->real_escape_string($_POST['brandPhone']);

    // $color = $conn->real_escape_string($_POST['brandColor']);
    
    $logoSqlPart = "";

    // Check if a new logo file was uploaded and convert it to Base64 to store in the database
    if (isset($_FILES['brandLogoInput']) && $_FILES['brandLogoInput']['error'] == 0) {
        $fileTmpPath = $_FILES['brandLogoInput']['tmp_name'];
        $fileType = mime_content_type($fileTmpPath);
        
        // Ensure it's an image
        if (strpos($fileType, 'image/') === 0) {
            $imageData = file_get_contents($fileTmpPath);
            $base64Logo = 'data:' . $fileType . ';base64,' . base64_encode($imageData);
            $base64LogoEscaped = $conn->real_escape_string($base64Logo);
            $logoSqlPart = "company_logo";
        }
    }

    // Update query storing everything (including image data string and location) directly in database columns
    $sql = "UPDATE headquarter SET 
            company_name='$title', 
            business_license='$license',
            location='$location',
            headquarter_bio='$info', 
            brand_email='$email', 
            contact_number='$phone', 
            $logoSqlPart 
            WHERE headquarter_id = $id";

    if ($conn->query($sql) === TRUE) {
        $message = "Brand details, location, and logo updated successfully in the database!";
    } else {
        $message = "Error updating record: " . $conn->error;
    }
}

// Fetch current brand details strictly from the database
$result = $conn->query("SELECT * FROM headquarter WHERE headquarter_id =$id");
$brand = ($result && $result->num_rows > 0) ? $result->fetch_assoc() : [
    'headquarter_id' => $id,
    'company_name' => '',
    'company_logo' => '',
    'business_license' => '',
    'location' => '',
    'headquarter_bio' => '',
    'brand_email' => '',
    'brand_phone' => '',
    'brand_color' => '#000000'
];
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
            <div class="alert alert-info"><?php echo $message; ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <div class="row align-items-center">
                <!-- Logo Preview & File Upload Button -->
                <div class="col-md-4 text-center border-end-md mb-4 mb-md-0">
                    <img id="logoPreview" src="<?php echo htmlspecialchars($brand['company_logo']); ?>" alt="Brand Logo" class="rounded-circle img-thumbnail mb-3" style="width: 150px; height: 150px; object-fit: cover; background-color: #fff;" onerror="this.src='https://via.placeholder.com/150?text=No+Logo'">
                    <div>
                        <input type="file" name="brandLogoInput" id="brandLogoInput" accept="image/*" style="display: none;" onchange="previewLogo(event)">
                        <label for="brandLogoInput" class="btn btn-sm btn-outline-secondary text-white cursor-pointer">Change Logo</label>
                    </div>
                </div>

                <!-- Form Fields -->
                <div class="col-md-8 ps-md-4">
                    <div class="form-group mb-3">
                        <label class="form-label">Brand Display Title</label>
                        <input type="text" class="form-control" name="company_name" id="company_name" value="<?php echo htmlspecialchars($brand['company_name']); ?>" placeholder="Company Display Name" required>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Business License Number</label>
                            <input type="text" class="form-control" name="business_license" id="business_license" value="<?php echo htmlspecialchars($brand['business_license']); ?>" placeholder="Enter official business license" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Corporate Location / Address</label>
                            <input type="text" class="form-control" name="location" id="location" value="<?php echo htmlspecialchars($brand['location']); ?>" placeholder="Enter office address or location" required>
                        </div>
                    </div>

                    <div class="form-group mb-3">
                        <label class="form-label">Company Information & Details</label>
                        <textarea class="form-control custom-input" name="headquarter_bio" id="headquarter_bio" rows="3" placeholder="Enter corporate background, address, or details..."><?php echo htmlspecialchars($brand['headquarter_bio']); ?></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Corporate Email</label>
                            <input type="email" class="form-control" name="brandEmail" id="brandEmail" value="<?php echo htmlspecialchars($brand['brand_email']); ?>" placeholder="corp@example.com" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Support / Contact Phone</label>
                            <input type="text" class="form-control" name="brandPhone" id="brandPhone" value="<?php echo htmlspecialchars($brand['brand_phone']); ?>" placeholder="+95 xxx-xxx-xxxx" required>
                        </div>
                    </div>

                    <!-- <div class="form-group mb-4">
                        <label class="form-label">Interface Primary Accent</label>
                        <div>
                            <input type="color" class="form-control" name="brandColor" id="brandColor" value="<?php echo htmlspecialchars(!empty($brand['brand_color']) ? $brand['brand_color'] : '#000000'); ?>" style="width: 80px; height: 38px; padding: 2px; cursor: pointer;">
                        </div>
                    </div> -->

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
<?php $conn->close(); ?>