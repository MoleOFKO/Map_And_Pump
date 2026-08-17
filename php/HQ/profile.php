<?php
session_start();
require_once __DIR__ . '/../conn.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../Login/login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$stmt = $pdo->prepare(
    'SELECT user_id, user_name, email, oauth_avatar_url, status, role_id, business_role_id
     FROM users
     WHERE user_id = ?
     LIMIT 1'
);
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$profileName = !empty($user['user_name']) ? $user['user_name'] : 'Unknown User';
$profileEmail = !empty($user['email']) ? $user['email'] : '';
$profileImage = !empty($user['oauth_avatar_url']) ? $user['oauth_avatar_url'] : 'https://via.placeholder.com/150';
$profileStatus = !empty($user['status']) ? ucfirst($user['status']) : 'Active';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="/css/HQstyle.css">
</head>
<body>
    <h2 class="title">Account Management</h2>
    <p class="subtitle">Update your account credentials, settings, or switch between interfaces.</p>

    <div class="row g-4 align-items-center justify-content-center">
        <div class="col-lg-8">
            <div class="card h-100 p-4 text-left">
                <h3 class="mb-4">Edit Account Profile</h3>
                <div class="row align-items-center">
                    <div class="col-md-4 text-center border-end-md mb-4 mb-md-0">
                        <img id="profilePreview" src="<?= htmlspecialchars($profileImage, ENT_QUOTES, 'UTF-8') ?>" alt="Profile Picture" class="rounded-circle img-thumbnail mb-3" style="width: 150px; height: 150px; object-fit: cover; background-color: #fff;">
                        <div>
                            <label for="profilePictureInput" class="btn btn-sm btn-outline-secondary w-75 text-white">Change Profile Picture</label>
                            <input type="file" id="profilePictureInput" accept="image/*" style="display: none;" onchange="previewImage(event)">
                        </div>
                    </div>

                    <div class="col-md-8 ps-md-4">
                        <form onsubmit="saveProfile(event)">
                            <div class="mb-3">
                                <label for="profileName" class="form-label">Full Name</label>
                                <input type="text" class="form-control" id="profileName" value="<?= htmlspecialchars($profileName, ENT_QUOTES, 'UTF-8') ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="profileEmail" class="form-label">Email Address</label>
                                <input type="email" class="form-control" id="profileEmail" value="<?= htmlspecialchars($profileEmail, ENT_QUOTES, 'UTF-8') ?>" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Account Status</label>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($profileStatus, ENT_QUOTES, 'UTF-8') ?>" readonly>
                            </div>

                            <hr class="my-4">
                            <h5 class="mb-3">Change Password</h5>

                            <div class="mb-3">
                                <label for="currentPassword" class="form-label">Current Password</label>
                                <input type="password" class="form-control" id="currentPassword">
                            </div>
                            <div class="mb-3">
                                <label for="newPassword" class="form-label">New Password</label>
                                <input type="password" class="form-control" id="newPassword">
                            </div>
                            <div class="mb-3">
                                <label for="confirmPassword" class="form-label">Confirm New Password</label>
                                <input type="password" class="form-control" id="confirmPassword">
                            </div>

                            <button type="submit" class="btn btn-primary w-100">Save Changes</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card h-100 p-4 text-center">
                <h3 class="mb-3">Account Interface Switcher</h3>
                <p class="subtitle mb-4">Interlink your account proxy with downstream customer interface metrics.</p>

                <form action="../account_switch.php" method="POST">
                    <input type="hidden" name="mode" value="customer">
                    <button type="submit" class="btn btn-outline-primary w-100">Switch to Customer Profile</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        const serverName = <?= json_encode($profileName, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const serverEmail = <?= json_encode($profileEmail, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const serverImage = <?= json_encode($profileImage, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        window.addEventListener('DOMContentLoaded', () => {
            const savedName = localStorage.getItem('profile_name');
            const savedEmail = localStorage.getItem('profile_email');
            const savedImage = localStorage.getItem('profile_image');

            document.getElementById('profileName').value = savedName || serverName;
            document.getElementById('profileEmail').value = savedEmail || serverEmail;
            document.getElementById('profilePreview').src = savedImage || serverImage;
        });

        function previewImage(event) {
            const file = event.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('profilePreview').src = e.target.result;
                    localStorage.setItem('profile_image', e.target.result);
                };
                reader.readAsDataURL(file);
            }
        }

        function saveProfile(event) {
            event.preventDefault();
            const name = document.getElementById('profileName').value;
            const email = document.getElementById('profileEmail').value;
            const currentPass = document.getElementById('currentPassword').value;
            const newPass = document.getElementById('newPassword').value;
            const confirmPass = document.getElementById('confirmPassword').value;

            if (currentPass || newPass || confirmPass) {
                if (!currentPass || !newPass || !confirmPass) {
                    alert("Please fill in all password fields to change your password.");
                    return;
                }
                if (newPass !== confirmPass) {
                    alert("New passwords do not match!");
                    return;
                }
                localStorage.setItem('profile_password', newPass);
            }

            localStorage.setItem('profile_name', name);
            localStorage.setItem('profile_email', email);

            alert("Profile and security settings updated successfully!");
        }
    </script>
</body>
<footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map & Pump | All Rights Reserved</footer>
</html>
