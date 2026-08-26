<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../profile_avatar.php';
require_once __DIR__ . '/car_access.php';

$profileFlashMessage = $_SESSION['profile_flash_message'] ?? null;
$profileFlashType = $_SESSION['profile_flash_type'] ?? 'success';
unset($_SESSION['profile_flash_message'], $_SESSION['profile_flash_type']);

function customer_profile_image_public_path(int $userId, array $file, string $imageData): ?string
{
    $uploadDir = __DIR__ . '/uploads/profile';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
        throw new RuntimeException('Could not prepare the profile upload folder.');
    }

    $bytes = null;
    $mime = null;
    if ($imageData !== '') {
        if (!preg_match('/^data:image\/(jpeg|jpg|png|webp);base64,([A-Za-z0-9+\/=]+)$/', $imageData, $matches)) {
            throw new RuntimeException('Pasted profile image must be JPG, PNG, or WEBP.');
        }
        $bytes = base64_decode($matches[2], true);
        if ($bytes === false || strlen($bytes) === 0) {
            throw new RuntimeException('Could not read the pasted profile image.');
        }
        $info = @getimagesizefromstring($bytes);
        $mime = $info['mime'] ?? null;
    } elseif (isset($file['tmp_name']) && is_uploaded_file($file['tmp_name'])) {
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Could not read the uploaded profile image.');
        }
        if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
            throw new RuntimeException('Profile image must be 5 MB or smaller.');
        }
        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
        } elseif (function_exists('mime_content_type')) {
            $mime = mime_content_type($file['tmp_name']);
        }
    }

    if ($mime === null) {
        return null;
    }

    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Profile image must be JPG, PNG, or WEBP.');
    }

    $fileName = 'profile-' . $userId . '-' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
    $targetPath = $uploadDir . '/' . $fileName;
    if ($bytes !== null) {
        if (strlen($bytes) > 5 * 1024 * 1024 || file_put_contents($targetPath, $bytes) === false) {
            throw new RuntimeException('Could not save the pasted profile image.');
        }
    } elseif (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new RuntimeException('Could not save the uploaded profile image.');
    }

    return '/php/Customer/uploads/profile/' . $fileName;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    try {
        $passwordStmt = $pdo->prepare('SELECT hashed_password FROM users WHERE user_id = ? LIMIT 1');
        $passwordStmt->execute([$userId]);
        $hashedPassword = (string) ($passwordStmt->fetchColumn() ?: '');

        if ($userId <= 0 || $currentPassword === '' || !password_verify($currentPassword, $hashedPassword)) {
            throw new RuntimeException('Current password is incorrect.');
        }
        if (strlen($newPassword) < 8 || strlen($newPassword) > 72) {
            throw new RuntimeException('New password must be between 8 and 72 characters.');
        }
        if ($newPassword !== $confirmPassword) {
            throw new RuntimeException('New passwords do not match.');
        }

        $passwordUpdate = $pdo->prepare('UPDATE users SET hashed_password = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?');
        $passwordUpdate->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
        $_SESSION['profile_flash_message'] = 'Password changed successfully.';
        $_SESSION['profile_flash_type'] = 'success';
    } catch (Throwable $e) {
        $_SESSION['profile_flash_message'] = $e->getMessage();
        $_SESSION['profile_flash_type'] = 'danger';
    }

    header('Location: profile_page.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    $newName = trim((string) ($_POST['user_name'] ?? ''));
    $newEmail = trim((string) ($_POST['email'] ?? ''));

    if (!isset($_SESSION['user_id']) || !ctype_digit((string) $_SESSION['user_id'])) {
        $_SESSION['profile_flash_message'] = 'Please sign in before updating your profile.';
        $_SESSION['profile_flash_type'] = 'danger';
    } elseif ($newName === '') {
        $_SESSION['profile_flash_message'] = 'Please enter a display name.';
        $_SESSION['profile_flash_type'] = 'danger';
    } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['profile_flash_message'] = 'Please enter a valid email address.';
        $_SESSION['profile_flash_type'] = 'danger';
    } elseif (mb_strlen($newName) > 225) {
        $_SESSION['profile_flash_message'] = 'Display name must be 225 characters or fewer.';
        $_SESSION['profile_flash_type'] = 'danger';
    } else {
        try {
            $emailCheck = $pdo->prepare('SELECT user_id FROM users WHERE LOWER(email) = LOWER(?) AND user_id <> ? LIMIT 1');
            $emailCheck->execute([$newEmail, (int) $_SESSION['user_id']]);
            if ($emailCheck->fetchColumn()) {
                throw new RuntimeException('That email address is already in use.');
            }

            $profileImagePath = customer_profile_image_public_path((int) $_SESSION['user_id'], $_FILES['profile_image'] ?? [], trim((string) ($_POST['profile_image_data'] ?? '')));
            if ($profileImagePath !== null) {
                $update = $pdo->prepare('UPDATE users SET user_name = ?, email = ?, oauth_avatar_url = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?');
                $update->execute([$newName, $newEmail, $profileImagePath, (int) $_SESSION['user_id']]);
                $_SESSION['oauth_avatar_url'] = $profileImagePath;
            } else {
                $update = $pdo->prepare('UPDATE users SET user_name = ?, email = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?');
                $update->execute([$newName, $newEmail, (int) $_SESSION['user_id']]);
            }
            $_SESSION['user_name'] = $newName;
            $_SESSION['user_email'] = $newEmail;
            $_SESSION['profile_flash_message'] = 'Shared profile updated.';
            $_SESSION['profile_flash_type'] = 'success';
        } catch (Throwable $e) {
            error_log('Failed to update customer profile name: ' . $e->getMessage());
            $_SESSION['profile_flash_message'] = 'Profile name could not be updated right now.';
            $_SESSION['profile_flash_type'] = 'danger';
        }
    }

    header('Location: profile_page.php');
    exit;
}

$linkedBusinessRoleName = '';
$profileCars = [];
$activeCarId = 0;
$activeCar = [];
if (isset($_SESSION['user_id']) && ctype_digit((string) $_SESSION['user_id'])) {
    try {
        $businessStmt = $pdo->prepare(
            'SELECT u.role_id, u.business_role_id,
                    primary_role.role_name AS primary_role_name,
                    business_role.role_name AS business_role_name,
                    customer_role.role_id AS customer_role_id
             FROM users u
             LEFT JOIN roles primary_role ON primary_role.role_id = u.role_id
             LEFT JOIN roles business_role ON business_role.role_id = u.business_role_id
             LEFT JOIN roles customer_role ON LOWER(customer_role.role_name) = \'customer\'
             WHERE u.user_id = ?
             LIMIT 1'
        );
        $businessStmt->execute([(int) $_SESSION['user_id']]);
        $profileRoles = $businessStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $linkedBusinessRoleName = trim((string) ($profileRoles['business_role_name'] ?? ''));
        
        $activeCarId = customer_active_car_id($pdo, (int) $_SESSION['user_id']);

        $carsStmt = $pdo->prepare(
            'SELECT c.*
             FROM user_car uc
             INNER JOIN car_license c ON c.car_license_id = uc.car_id
             WHERE uc.user_id = ?
             ORDER BY CASE WHEN c.car_license_id = ? THEN 0 ELSE 1 END, c.updated_at DESC, c.car_license_id DESC'
        );
        $carsStmt->execute([(int) $_SESSION['user_id'], $activeCarId]);
        $profileCars = $carsStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($profileCars as $profileCarRow) {
            if ((int) $profileCarRow['car_license_id'] === $activeCarId) {
                $activeCar = $profileCarRow;
                break;
            }
        }
        if (!$activeCar && $profileCars) {
            $activeCar = $profileCars[0];
        }
    } catch (Throwable $e) {
        error_log('Failed to load customer profile modes: ' . $e->getMessage());
    }
}

$profileImage = '';
if (isset($_SESSION['user_id']) && ctype_digit((string) $_SESSION['user_id'])) {
    try {
        $profileImageStmt = $pdo->prepare('SELECT user_name, email, oauth_avatar_url FROM users WHERE user_id = ? LIMIT 1');
        $profileImageStmt->execute([(int) $_SESSION['user_id']]);
        $profileUserRow = $profileImageStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($profileUserRow) {
            $_SESSION['user_name'] = trim((string) ($profileUserRow['user_name'] ?? $_SESSION['user_name'] ?? ''));
            $_SESSION['user_email'] = trim((string) ($profileUserRow['email'] ?? $_SESSION['user_email'] ?? ''));
        }
        $profileImage = map_pump_profile_avatar_url((string) ($profileUserRow['oauth_avatar_url'] ?? $_SESSION['oauth_avatar_url'] ?? ''), '');
    } catch (Throwable $e) {
        error_log('Failed to load customer profile image: ' . $e->getMessage());
    }
}
$profileFallbackUrl = map_pump_profile_initial_svg((string) ($_SESSION['user_name'] ?? ''), 'U');
include 'share_header.php';
?>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="dashboard-panel-card h-100">
            <h3 class="text-white mb-1">Profile Configurations</h3>
            <p class="text-secondary mb-4" style="font-size:13px;">Manage the shared account profile used by customer and business modes.</p>

            <?php if ($profileFlashMessage): ?>
                <div class="alert alert-<?php echo htmlspecialchars($profileFlashType === 'success' ? 'success' : ($profileFlashType === 'warning' ? 'warning' : 'danger')); ?> border-0 rounded-4" role="alert">
                    <?php echo htmlspecialchars($profileFlashMessage); ?>
                </div>
            <?php endif; ?>

            <form class="space-y-4" action="profile_page.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_profile">
                <div class="mb-3 text-center">
                    <label class="form-label text-secondary" style="font-size:11px; font-weight:600;">PROFILE IMAGE</label>
                    <div class="d-flex flex-column align-items-center gap-3">
                        <img id="profileImagePreview" src="<?php echo htmlspecialchars(map_pump_profile_avatar_url($profileImage, $profileFallbackUrl), ENT_QUOTES, 'UTF-8'); ?>" alt="Profile image preview" class="rounded-circle" style="width:76px;height:76px;object-fit:cover;background:rgba(13,110,253,.08);border:1px solid rgba(13,110,253,.22);">
                        <div class="w-100">
                            <label for="profileImageInput" class="btn btn-outline-info btn-sm px-3 rounded-3 fw-bold mb-0" style="font-size:12px;cursor:pointer;">Change Profile Image</label>
                            <input type="file" name="profile_image" id="profileImageInput" class="d-none" accept="image/jpeg,image/png,image/webp">
                            <input type="hidden" name="profile_image_data" id="profileImageData">
                            <small id="profileImageHelp" class="text-secondary d-block mt-2 mx-auto" style="max-width:320px;">Upload an image, or copy an image from anywhere and paste it while this page is open.</small>
                        </div>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label text-secondary" style="font-size:11px; font-weight:600;">DISPLAY FULL NAME</label>
                    <div class="form-control custom-form-field text-secondary">
                        <?php echo htmlspecialchars($_SESSION['user_name']); ?>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label text-secondary" style="font-size:11px; font-weight:600;">REGISTERED SYSTEM EMAIL</label>
                    <div class="form-control custom-form-field text-secondary">
                        <?php echo htmlspecialchars($_SESSION['user_email']); ?>
                    </div>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <button type="button" class="btn btn-outline-info px-4 py-2.5 fw-bold rounded-4" data-bs-toggle="modal" data-bs-target="#changeProfileModal" style="font-size:12px; font-family:'Space Grotesk';">Change Profile</button>
                    <button type="button" class="btn btn-outline-info px-4 py-2.5 fw-bold rounded-4" data-bs-toggle="modal" data-bs-target="#changePasswordModal" style="font-size:12px; font-family:'Space Grotesk';">Change Password</button>
                </div>
            </form>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="d-flex flex-column gap-4 h-100">
            <div class="dashboard-panel-card">
                <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
                    <div class="d-flex align-items-start gap-3">
                        <div class="bg-primary bg-opacity-10 p-2.5 rounded-3 text-sky-400">
                            <i class="bi bi-arrow-left-right fs-4"></i>
                        </div>
                        <div>
                            <h3 class="text-white mb-1">Account Profile Mode</h3>
                            <p class="text-secondary mb-0" style="font-size:13px;">Customer is the basic gas-fill profile. Switch back to the linked business role anytime.</p>
                        </div>
                    </div>
                </div>
                <?php if ($linkedBusinessRoleName !== ''): ?>
                    <form action="../account_switch.php" method="POST" class="m-0">
                        <input type="hidden" name="mode" value="business">
                        <button type="submit" class="btn btn-outline-info btn-sm px-3 rounded-3 fw-bold" style="font-size:12px;">
                            <i class="bi bi-briefcase-fill me-1"></i>Switch to <?php echo htmlspecialchars($linkedBusinessRoleName); ?> Profile
                        </button>
                    </form>
                <?php else: ?>
                    <span class="text-secondary" style="font-size:12px;">No linked business profile is available for this account yet.</span>
                <?php endif; ?>
            </div>

            <div class="dashboard-panel-card">
                <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
                    <div class="d-flex align-items-start gap-3">
                        <div class="bg-primary bg-opacity-10 p-2.5 rounded-3 text-sky-400">
                            <i class="bi bi-person-vcard fs-4"></i>
                        </div>
                        <div>
                            <h3 class="text-white mb-1">Driver License</h3>
                            <p class="text-secondary mb-0" style="font-size:13px;">Keep the driver's license details for this user up to date.</p>
                        </div>
                    </div>
                    <a href="license.php" class="btn btn-outline-info btn-sm px-3 rounded-3 fw-bold" style="font-size:12px;">Edit</a>
                </div>
            </div>

            <div class="dashboard-panel-card flex-grow-1">
                <div class="d-flex align-items-start justify-content-between gap-3 mb-3 flex-wrap">
                    <div class="d-flex align-items-start gap-3">
                        <div class="bg-primary bg-opacity-10 p-2.5 rounded-3 text-sky-400">
                            <i class="bi bi-car-front-fill fs-4"></i>
                        </div>
                        <div>
                            <h3 class="text-white mb-1">Car License</h3>
                            <p class="text-secondary mb-0" style="font-size:13px;">Manage the registration and license details for your cars.</p>
                        </div>
                    </div>
                    <?php if ($activeCar): ?>
                        <a href="car_license.php?update=1&car_id=<?php echo (int) $activeCar['car_license_id']; ?>" class="btn btn-outline-info btn-sm px-3 rounded-3 fw-bold" style="font-size:12px;">Edit</a>
                    <?php else: ?>
                        <a href="car_license.php?add=1" class="btn btn-info btn-sm px-3 rounded-3 fw-bold" style="font-size:12px;">Add</a>
                    <?php endif; ?>
                </div>

                <?php if ($activeCar): ?>
                    <div class="rounded-3 px-3 py-3 mb-3" style="background:rgba(10,22,44,.55);border:1px solid rgba(13,110,253,.18);">
                        <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
                            <div>
                                <span class="status-pill active mb-2" style="font-size:10px;"><i class="bi bi-check-circle-fill"></i> Active Car</span>
                                <div class="text-white fw-semibold"><?php echo htmlspecialchars((string) $activeCar['car_plate_number']); ?></div>
                                <div class="text-secondary" style="font-size:12px;"><?php echo htmlspecialchars(trim((string) ($activeCar['vehicle_name'] ?? '') . ' / ' . (string) ($activeCar['vehicle_type'] ?? ''))); ?></div>
                            </div>
                            <a href="car_license.php?add=1" class="btn btn-outline-info btn-sm px-3 rounded-3 fw-bold" style="font-size:12px;"><i class="bi bi-plus-lg me-1"></i>Add Car</a>
                        </div>
                    </div>
                    <?php if (count($profileCars) > 1): ?>
                        <button type="button" class="btn btn-link text-info p-0 fw-bold text-decoration-none text-start" data-bs-toggle="modal" data-bs-target="#carSwitchModal" style="font-size:12px;">
                            <i class="bi bi-arrow-left-right me-1"></i>Switch Car
                        </button>
                    <?php else: ?>
                        <span class="text-secondary" style="font-size:12px;">Add another car to enable switching.</span>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="text-secondary" style="font-size:12px;">No car license added yet.</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const input = document.getElementById('profileImageInput');
    const data = document.getElementById('profileImageData');
    const preview = document.getElementById('profileImagePreview');
    const help = document.getElementById('profileImageHelp');
    function previewFile(file) {
        if (!file || !file.type.startsWith('image/')) return;
        data.value = '';
        const reader = new FileReader();
        reader.onload = () => { preview.src = reader.result; };
        reader.readAsDataURL(file);
    }

    input?.addEventListener('change', () => previewFile(input.files?.[0]));
    document.addEventListener('paste', (event) => {
        const item = Array.from(event.clipboardData?.items || []).find((entry) => entry.type.startsWith('image/'));
        if (!item) return;
        const file = item.getAsFile();
        if (!file) return;
        const reader = new FileReader();
        reader.onload = () => {
            data.value = String(reader.result || '');
            preview.src = data.value;
            if (input) input.value = '';
            if (help) help.textContent = 'Pasted image ready. Save Changes will copy it into the server profile folder.';
        };
        reader.readAsDataURL(file);
    });
})();
</script>

<!-- Custom CSS to fix modal locking / backdrop issues -->
<style>
.modal-backdrop {
    display: none !important;
}
.modal.fade {
    background: rgba(0, 0, 0, 0.6);
}
.car-switch-modal .modal-content {
    background: rgba(6, 12, 24, 0.98);
    border: 1px solid rgba(13, 110, 253, 0.22);
    border-radius: 20px;
    color: #fff;
}
.car-switch-modal .modal-header,
.car-switch-modal .modal-footer {
    border-color: rgba(255, 255, 255, 0.08);
}
.car-switch-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 10px;
}
.car-switch-option {
    position: relative;
    display: block;
    cursor: pointer;
}
.car-switch-option input {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}
.car-switch-card {
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 14px;
    background: rgba(4, 9, 21, 0.42);
    padding: 12px 15px;
    transition: all .18s ease;
}
.car-switch-option input:checked + .car-switch-card {
    border-color: rgba(27, 228, 148, 0.58);
    background: linear-gradient(135deg, rgba(27, 228, 148, 0.12), rgba(13, 110, 253, 0.08));
}
</style>

<!-- Change Profile Modal -->
<div class="modal fade" id="changeProfileModal" tabindex="-1" aria-labelledby="changeProfileModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content dashboard-panel-card">
            <div class="modal-header border-secondary border-opacity-25">
                <h5 class="modal-title text-white" id="changeProfileModalLabel">Change Profile</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="profile_page.php" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_profile">
                    <div class="mb-3">
                        <label class="form-label text-secondary" for="modalProfileUserName">DISPLAY FULL NAME</label>
                        <input type="text" id="modalProfileUserName" name="user_name" value="<?php echo htmlspecialchars($_SESSION['user_name']); ?>" class="form-control custom-form-field" maxlength="225" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-secondary" for="modalProfileEmail">REGISTERED SYSTEM EMAIL</label>
                        <input type="email" id="modalProfileEmail" name="email" value="<?php echo htmlspecialchars($_SESSION['user_email']); ?>" class="form-control custom-form-field" maxlength="255" required>
                    </div>
                </div>
                <div class="modal-footer border-secondary border-opacity-25">
                    <button type="button" class="btn btn-outline-secondary rounded-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info fw-bold rounded-3">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Change Password Modal -->
<div class="modal fade" id="changePasswordModal" tabindex="-1" aria-labelledby="changePasswordModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content dashboard-panel-card">
            <div class="modal-header border-secondary border-opacity-25">
                <h5 class="modal-title text-white" id="changePasswordModalLabel">Change Password</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="profile_page.php" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="change_password">
                    <div class="mb-3">
                        <label class="form-label text-secondary" for="modalCurrentPassword">CURRENT PASSWORD</label>
                        <input type="password" id="modalCurrentPassword" name="current_password" class="form-control custom-form-field" autocomplete="current-password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-secondary" for="modalNewPassword">NEW PASSWORD</label>
                        <input type="password" id="modalNewPassword" name="new_password" class="form-control custom-form-field" minlength="8" maxlength="72" autocomplete="new-password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-secondary" for="modalConfirmPassword">CONFIRM PASSWORD</label>
                        <input type="password" id="modalConfirmPassword" name="confirm_password" class="form-control custom-form-field" minlength="8" maxlength="72" autocomplete="new-password" required>
                    </div>
                </div>
                <div class="modal-footer border-secondary border-opacity-25">
                    <button type="button" class="btn btn-outline-secondary rounded-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info fw-bold rounded-3">Update Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Switch Active Car Modal -->
<?php if (count($profileCars) > 1): ?>
    <div class="modal fade car-switch-modal" id="carSwitchModal" tabindex="-1" aria-labelledby="carSwitchModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <form id="profile-car-switch-form" method="POST" action="save_car_license.php" data-current-car="<?php echo (int) $activeCarId; ?>">
                    <div class="modal-header px-4 pt-4">
                        <div>
                            <h5 class="modal-title fw-bold" id="carSwitchModalLabel"><i class="bi bi-car-front-fill text-info me-2"></i>Choose Active Car</h5>
                            <p class="text-white-50 small mb-0 mt-1">This car will be used for your next fuel booking.</p>
                        </div>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body px-4">
                        <input type="hidden" name="action" value="switch">
                        <div class="car-switch-grid" role="radiogroup" aria-label="Choose active car">
                            <?php foreach ($profileCars as $car): ?>
                                <?php
                                    $carId = (int) $car['car_license_id'];
                                    $isActiveCar = $carId === (int) $activeCarId;
                                    $vehicleLabel = trim((string) ($car['vehicle_name'] ?? 'Vehicle')) ?: 'Vehicle';
                                    $typeLabel = trim((string) ($car['vehicle_type'] ?? 'Vehicle')) ?: 'Vehicle';
                                    $locationLabel = trim((string) ($car['location'] ?? 'Location not set')) ?: 'Location not set';
                                ?>
                                <label class="car-switch-option">
                                    <input type="radio" name="car_license_id" value="<?php echo $carId; ?>" <?php echo $isActiveCar ? 'checked' : ''; ?> required>
                                    <span class="car-switch-card d-block">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <span class="text-white fw-bold fs-5"><?php echo htmlspecialchars((string) $car['car_plate_number']); ?></span>
                                            <span class="badge <?php echo $isActiveCar ? 'bg-success bg-opacity-25 text-success' : 'bg-secondary bg-opacity-25 text-white-50'; ?>"><?php echo $isActiveCar ? 'Active' : 'Saved'; ?></span>
                                        </div>
                                        <div class="text-secondary small">
                                            <div><i class="bi bi-car-front me-1"></i><?php echo htmlspecialchars($vehicleLabel . ' / ' . $typeLabel); ?></div>
                                            <div><i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($locationLabel); ?></div>
                                        </div>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="modal-footer px-4 pb-4">
                        <span class="car-switch-hint text-secondary small flex-grow-1" id="profileCarSwitchHint">Select a car to switch.</span>
                        <button type="button" class="btn btn-outline-light rounded-3 px-3 btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-info rounded-3 px-3 fw-bold btn-sm" id="profileCarSwitchSubmit" disabled>
                            <i class="bi bi-check2-circle me-1"></i>Switch
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const carSwitchModal = document.getElementById('carSwitchModal');
        if (carSwitchModal && carSwitchModal.parentElement !== document.body) {
            document.body.appendChild(carSwitchModal);
        }

        const carSwitchForm = document.getElementById('profile-car-switch-form');
        const carSwitchButton = document.getElementById('profileCarSwitchSubmit');
        const carSwitchHint = document.getElementById('profileCarSwitchHint');
        const carSwitchCurrentId = carSwitchForm ? Number(carSwitchForm.dataset.currentCar || 0) : 0;
        const carSwitchInputs = carSwitchForm ? Array.from(carSwitchForm.querySelectorAll('input[name="car_license_id"]')) : [];

        function refreshCarSwitchState() {
            if (!carSwitchForm || !carSwitchButton) return;
            const selected = carSwitchInputs.find((input) => input.checked);
            const selectedId = selected ? Number(selected.value || 0) : 0;
            const hasChanged = selectedId > 0 && selectedId !== carSwitchCurrentId;
            carSwitchButton.disabled = !hasChanged;
            if (carSwitchHint) {
                carSwitchHint.textContent = hasChanged ? 'Ready to switch.' : 'Choose another saved car.';
            }
        }

        carSwitchInputs.forEach((input) => input.addEventListener('change', refreshCarSwitchState));
        refreshCarSwitchState();

        if (carSwitchForm) {
            carSwitchForm.addEventListener('submit', async (event) => {
                event.preventDefault();
                if (carSwitchButton) {
                    carSwitchButton.disabled = true;
                    carSwitchButton.textContent = 'Switching...';
                }

                try {
                    const response = await fetch('save_car_license.php', {
                        method: 'POST',
                        body: new FormData(carSwitchForm),
                        credentials: 'same-origin'
                    });
                    const result = await response.json();
                    if (result.ok) {
                        // Reload seamlessly to update UI state in-place across both pages seamlessly
                        window.location.reload();
                    } else {
                        alert(result.message || 'Could not switch active car.');
                        if (carSwitchButton) {
                            carSwitchButton.disabled = false;
                            carSwitchButton.innerHTML = '<i class="bi bi-check2-circle me-1"></i>Switch';
                        }
                    }
                } catch (err) {
                    alert('Could not contact the server. Please try again.');
                    if (carSwitchButton) {
                        carSwitchButton.disabled = false;
                        carSwitchButton.innerHTML = '<i class="bi bi-check2-circle me-1"></i>Switch';
                    }
                }
            });
        }
    });
    </script>
<?php endif; ?>

<?php include 'share_footer.php'; ?>