<?php
session_start();
require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../profile_avatar.php';

if (empty($_SESSION['user_id'])) {
    $loginUrl = '../Login/login.php';
    echo '<script>window.top.location.replace(' . json_encode($loginUrl) . ');</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '"></noscript>';
    exit;
}

$userId = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'update_profile') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));

            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'message' => 'Please enter a valid name and email address.']);
                exit;
            }

            $update = $pdo->prepare(
                'UPDATE users SET user_name = ?, email = ?, updated_at = NOW() WHERE user_id = ?'
            );
            $update->execute([$name, $email, $userId]);
            $_SESSION['user_name'] = $name;
            $_SESSION['user_email'] = $email;

            echo json_encode(['ok' => true, 'message' => 'Profile details updated successfully.', 'name' => $name, 'email' => $email]);
            exit;
        }

        if ($action === 'update_avatar') {
            $upload = $_FILES['profile_avatar'] ?? null;
            if (!$upload || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'message' => 'Please choose a profile picture.']);
                exit;
            }

            if (($upload['size'] ?? 0) > 2 * 1024 * 1024) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'message' => 'Profile pictures must be 2 MB or smaller.']);
                exit;
            }

            $mime = '';
            if (class_exists('finfo')) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = (string) $finfo->file((string) $upload['tmp_name']);
            }

            $extensions = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
            ];

            if (!isset($extensions[$mime])) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'message' => 'Only JPG, PNG, GIF, and WebP profile pictures are supported.']);
                exit;
            }

            $uploadDir = __DIR__ . '/uploads/profile';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
                http_response_code(500);
                echo json_encode(['ok' => false, 'message' => 'The profile picture folder could not be prepared.']);
                exit;
            }

            $fileName = 'hq-profile-' . $userId . '-' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
            $targetPath = $uploadDir . '/' . $fileName;
            if (!move_uploaded_file((string) $upload['tmp_name'], $targetPath)) {
                http_response_code(500);
                echo json_encode(['ok' => false, 'message' => 'The profile picture could not be saved.']);
                exit;
            }

            $avatarUrl = '/php/HQ/uploads/profile/' . $fileName;
            $update = $pdo->prepare('UPDATE users SET oauth_avatar_url = ?, updated_at = NOW() WHERE user_id = ?');
            $update->execute([$avatarUrl, $userId]);
            $_SESSION['oauth_avatar_url'] = $avatarUrl;

            echo json_encode(['ok' => true, 'message' => 'Profile picture updated successfully.', 'avatar_url' => $avatarUrl]);
            exit;
        }

        if ($action === 'change_password') {
            $currentPassword = (string) ($_POST['current_password'] ?? '');
            $newPassword = (string) ($_POST['new_password'] ?? '');
            $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

            if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
                http_response_code(422);
                echo json_encode(['ok' => false, 'message' => 'Please fill in all password fields.']);
                exit;
            }

            if ($newPassword !== $confirmPassword) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'message' => 'New passwords do not match.']);
                exit;
            }

            if (strlen($newPassword) < 8) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'message' => 'The new password must be at least 8 characters.']);
                exit;
            }

            $passwordQuery = $pdo->prepare('SELECT hashed_password FROM users WHERE user_id = ? LIMIT 1');
            $passwordQuery->execute([$userId]);
            $account = $passwordQuery->fetch(PDO::FETCH_ASSOC);

            if (!$account || !password_verify($currentPassword, (string) $account['hashed_password'])) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'message' => 'The current password is incorrect.']);
                exit;
            }

            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $update = $pdo->prepare(
                'UPDATE users SET hashed_password = ?, updated_at = NOW() WHERE user_id = ?'
            );
            $update->execute([$newHash, $userId]);

            echo json_encode(['ok' => true, 'message' => 'Password changed successfully.']);
            exit;
        }

        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Invalid account update action.']);
    } catch (Throwable $exception) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'The account update could not be saved.']);
    }
    exit;
}

$stmt = $pdo->prepare(
    'SELECT user_id, user_name, email, oauth_avatar_url, role_id, business_role_id
     FROM users
     WHERE user_id = ?
     LIMIT 1'
);
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$profileName = !empty($user['user_name']) ? $user['user_name'] : 'Unknown User';
$profileEmail = !empty($user['email']) ? $user['email'] : '';
$profileImage = map_pump_profile_avatar_url((string) ($user['oauth_avatar_url'] ?? ''), '');
$profileInitial = map_pump_profile_initial($profileName, 'H');
$profileFallbackUrl = map_pump_profile_initial_svg($profileName, 'H');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../../css/HQstyle.css">
    <style>
        .update-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 1050;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: rgba(7, 11, 20, 0.72);
        }

        .update-modal-overlay.is-visible {
            display: flex;
        }

        .update-modal {
            width: min(100%, 400px);
            padding: 28px 24px 24px;
            text-align: center;
            background: linear-gradient(160deg, var(--navy-800), var(--navy-900));
            border: 1px solid rgba(52, 211, 153, 0.35);
            border-radius: 18px;
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.55);
        }

        .update-modal-icon {
            display: grid;
            width: 54px;
            height: 54px;
            margin: 0 auto 14px;
            place-items: center;
            color: var(--mint-400);
            border: 1px solid rgba(52, 211, 153, 0.35);
            border-radius: 50%;
            font-size: 1.7rem;
        }

        .update-modal h2 {
            margin-bottom: 8px;
        }

        .update-modal p {
            margin-bottom: 20px;
            color: var(--slate-300);
        }

        .update-modal button {
            min-width: 120px;
            border: 0;
        }

        .password-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 1040;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: rgba(7, 11, 20, 0.72);
        }

        .password-modal-overlay.is-visible {
            display: flex;
        }

        .password-modal {
            width: min(100%, 460px);
            padding: 28px 24px 24px;
            background: linear-gradient(160deg, var(--navy-800), var(--navy-900));
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 18px;
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.55);
        }

        .password-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 20px;
        }

        .password-modal-header h2 {
            margin: 0;
        }

        .password-modal-close {
            min-width: 0 !important;
            padding: 2px 8px !important;
            color: var(--slate-300);
            background: transparent !important;
            box-shadow: none !important;
            font-size: 1.5rem;
            line-height: 1;
        }

        .password-modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 24px;
        }

        .password-modal-actions button {
            min-width: 120px;
            border: 0;
        }
    </style>
</head>
<body>
    <div class="container py-4">
        <h2 class="title">Account Management</h2>
        <p class="subtitle">Update your account credentials, settings, or switch between interfaces.</p>

        <div class="row g-4">
            <!-- Left Column: Edit Account Profile -->
            <div class="col-lg-8">
                <div class="card h-100 p-4 text-left">
                    <h3 class="mb-4">Edit Account Profile</h3>
                    
                    <!-- Profile Picture Centered at Top -->
                    <div class="text-center mb-4">
                        <img id="profilePreview" src="<?= htmlspecialchars(map_pump_profile_avatar_url($profileImage, $profileFallbackUrl), ENT_QUOTES, 'UTF-8') ?>" alt="Profile Picture" class="rounded-circle img-thumbnail mb-3" style="width: 130px; height: 130px; object-fit: cover; background-color: #fff;">
                        <div>
                            <label for="profilePictureInput" class="btn btn-sm btn-outline-secondary text-white">Change Profile Picture</label>
                            <input type="file" id="profilePictureInput" name="profile_avatar" accept="image/*" style="display: none;" onchange="previewImage(event)">
                        </div>
                    </div>

                    <!-- Profile Form Fields -->
                    <form onsubmit="saveProfile(event)">
                        <div class="mb-3">
                            <label for="profileName" class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="profileName" value="<?= htmlspecialchars($profileName, ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="profileEmail" class="form-label">Email Address</label>
                            <input type="email" class="form-control" id="profileEmail" value="<?= htmlspecialchars($profileEmail, ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">Save Changes</button>
                    </form>
                </div>
            </div>

            <!-- Right Column: Stacked Cards for Password & Switcher -->
            <div class="col-lg-4 d-flex flex-column gap-4">
                <!-- Change Password Card -->
                <div class="card p-4 text-center">
                    <h3 class="mb-3">Change Password</h3>
                    <p class="subtitle mb-4">Update your account password securely.</p>
                    <button type="button" class="btn btn-outline-primary w-100" onclick="openPasswordModal()">Change Password</button>
                </div>

                <!-- Account Interface Switcher Card -->
                <div class="card p-4 text-center">
                    <h3 class="mb-3">Account Interface Switcher</h3>
                    <p class="subtitle mb-4">Interlink your account proxy with downstream customer interface metrics.</p>

                    <form action="../account_switch.php" method="POST">
                        <input type="hidden" name="mode" value="customer">
                        <button type="submit" class="btn btn-outline-primary w-100">Switch to Customer Profile</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Update Success Modal -->
    <div class="update-modal-overlay" id="updateModal" role="dialog" aria-modal="true" aria-labelledby="updateModalTitle" aria-hidden="true">
        <div class="update-modal" role="document">
            <div class="update-modal-icon" aria-hidden="true">&#10003;</div>
            <h2 id="updateModalTitle">Profile Updated</h2>
            <p>Your account details have been updated successfully.</p>
            <button type="button" class="btn btn-primary" onclick="closeUpdateModal()">Done</button>
        </div>
    </div>

    <!-- Password Change Modal -->
    <div class="password-modal-overlay" id="passwordModal" role="dialog" aria-modal="true" aria-labelledby="passwordModalTitle" aria-hidden="true">
        <div class="password-modal" role="document">
            <div class="password-modal-header">
                <h2 id="passwordModalTitle">Change Password</h2>
                <button type="button" class="password-modal-close" aria-label="Close" onclick="closePasswordModal()">&times;</button>
            </div>

            <form onsubmit="savePassword(event)">
                <div class="mb-3">
                    <label for="currentPassword" class="form-label">Current Password</label>
                    <input type="password" class="form-control" id="currentPassword" name="current_password" required>
                </div>
                <div class="mb-3">
                    <label for="newPassword" class="form-label">New Password</label>
                    <input type="password" class="form-control" id="newPassword" name="new_password" required>
                </div>
                <div class="mb-3">
                    <label for="confirmPassword" class="form-label">Confirm New Password</label>
                    <input type="password" class="form-control" id="confirmPassword" name="confirm_password" required>
                </div>

                <div class="password-modal-actions">
                    <button type="button" class="btn btn-outline-secondary" onclick="closePasswordModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Password</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const serverName = <?= json_encode($profileName, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const serverEmail = <?= json_encode($profileEmail, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const serverImage = <?= json_encode(map_pump_profile_avatar_url($profileImage, $profileFallbackUrl), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        window.addEventListener('DOMContentLoaded', () => {
            document.getElementById('profilePreview').src = serverImage;
        });

        function previewImage(event) {
            const file = event.target.files[0];
            if (!file) return;

            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('profilePreview').src = e.target.result;
            };
            reader.readAsDataURL(file);

            const data = new FormData();
            data.append('action', 'update_avatar');
            data.append('profile_avatar', file);

            fetch('profile.php', {
                method: 'POST',
                body: data,
                credentials: 'same-origin'
            })
                .then(response => response.json())
                .then(result => {
                    if (!result.ok) {
                        throw new Error(result.message || 'Profile picture could not be saved.');
                    }
                    if (result.avatar_url) {
                        localStorage.removeItem('profile_image');
                        document.getElementById('profilePreview').src = result.avatar_url;
                    }
                })
                .catch(error => {
                    alert(error.message || 'Profile picture could not be saved.');
                });
        }

        async function saveProfile(event) {
            event.preventDefault();
            const name = document.getElementById('profileName').value;
            const email = document.getElementById('profileEmail').value;

            await submitAccountUpdate('update_profile', {
                name,
                email
            });
        }

        async function savePassword(event) {
            event.preventDefault();
            const form = event.target;
            const formData = new FormData(form);
            formData.append('action', 'change_password');

            await submitAccountUpdate(formData);
        }

        async function submitAccountUpdate(action, fields = {}) {
            const formData = action instanceof FormData ? action : new FormData();
            if (!(action instanceof FormData)) {
                formData.append('action', action);
                Object.entries(fields).forEach(([name, value]) => formData.append(name, value));
            }

            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                });
                const result = await response.json();

                if (!response.ok || !result.ok) {
                    throw new Error(result.message || 'The account update could not be saved.');
                }

                if (formData.get('action') === 'change_password') {
                    closePasswordModal();
                    document.querySelector('#passwordModal form').reset();
                }
                openUpdateModal(result.message);
            } catch (error) {
                alert(error.message);
            }
        }

        function openPasswordModal() {
            const modal = document.getElementById('passwordModal');
            modal.classList.add('is-visible');
            modal.setAttribute('aria-hidden', 'false');
            document.getElementById('currentPassword').focus();
        }

        function closePasswordModal() {
            const modal = document.getElementById('passwordModal');
            modal.classList.remove('is-visible');
            modal.setAttribute('aria-hidden', 'true');
        }

        function openUpdateModal(message = 'Your account details have been updated successfully.') {
            const modal = document.getElementById('updateModal');
            modal.querySelector('p').textContent = message;
            modal.classList.add('is-visible');
            modal.setAttribute('aria-hidden', 'false');
        }

        function closeUpdateModal() {
            const modal = document.getElementById('updateModal');
            modal.classList.remove('is-visible');
            modal.setAttribute('aria-hidden', 'true');
        }

        document.getElementById('updateModal').addEventListener('click', (event) => {
            if (event.target.id === 'updateModal') {
                closeUpdateModal();
            }
        });

        document.getElementById('passwordModal').addEventListener('click', (event) => {
            if (event.target.id === 'passwordModal') {
                closePasswordModal();
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closePasswordModal();
                closeUpdateModal();
            }
        });
    </script>
</body>
<footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map & Pump | All Rights Reserved</footer>
</html>
