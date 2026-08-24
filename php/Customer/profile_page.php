<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../conn.php';
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    $newName = trim((string) ($_POST['user_name'] ?? ''));

    if (!isset($_SESSION['user_id']) || !ctype_digit((string) $_SESSION['user_id'])) {
        $_SESSION['profile_flash_message'] = 'Please sign in before updating your profile.';
        $_SESSION['profile_flash_type'] = 'danger';
    } elseif ($newName === '') {
        $_SESSION['profile_flash_message'] = 'Please enter a display name.';
        $_SESSION['profile_flash_type'] = 'danger';
    } elseif (mb_strlen($newName) > 225) {
        $_SESSION['profile_flash_message'] = 'Display name must be 225 characters or fewer.';
        $_SESSION['profile_flash_type'] = 'danger';
    } else {
        try {
            $profileImagePath = customer_profile_image_public_path((int) $_SESSION['user_id'], $_FILES['profile_image'] ?? [], trim((string) ($_POST['profile_image_data'] ?? '')));
            if ($profileImagePath !== null) {
                $update = $pdo->prepare('UPDATE users SET user_name = ?, oauth_avatar_url = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?');
                $update->execute([$newName, $profileImagePath, (int) $_SESSION['user_id']]);
                $_SESSION['oauth_avatar_url'] = $profileImagePath;
            } else {
                $update = $pdo->prepare('UPDATE users SET user_name = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?');
                $update->execute([$newName, (int) $_SESSION['user_id']]);
            }
            $_SESSION['user_name'] = $newName;
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
        $profileImageStmt = $pdo->prepare('SELECT oauth_avatar_url FROM users WHERE user_id = ? LIMIT 1');
        $profileImageStmt->execute([(int) $_SESSION['user_id']]);
        $profileImage = (string) ($profileImageStmt->fetchColumn() ?: ($_SESSION['oauth_avatar_url'] ?? ''));
    } catch (Throwable $e) {
        error_log('Failed to load customer profile image: ' . $e->getMessage());
    }
}
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
                <div class="mb-3">
                    <label class="form-label text-secondary" style="font-size:11px; font-weight:600;">PROFILE IMAGE</label>
                    <div class="d-flex align-items-center gap-3 flex-wrap">
                        <img id="profileImagePreview" src="<?php echo htmlspecialchars($profileImage ?: '/images/Logo.png'); ?>" alt="Profile image preview" class="rounded-circle" style="width:76px;height:76px;object-fit:cover;background:rgba(13,110,253,.08);border:1px solid rgba(13,110,253,.22);">
                        <div class="flex-grow-1">
                            <input type="file" name="profile_image" id="profileImageInput" class="form-control custom-form-field" accept="image/jpeg,image/png,image/webp">
                            <input type="hidden" name="profile_image_data" id="profileImageData">
                            <small id="profileImageHelp" class="text-secondary d-block mt-2">Upload an image, or copy an image from anywhere and paste it while this page is open.</small>
                        </div>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label text-secondary" style="font-size:11px; font-weight:600;">DISPLAY FULL NAME</label>
                    <input type="text" name="user_name" value="<?php echo htmlspecialchars($_SESSION['user_name']); ?>" class="form-control custom-form-field" maxlength="225" required>
                </div>
                <div class="mb-4">
                    <label class="form-label text-secondary" style="font-size:11px; font-weight:600;">REGISTERED SYSTEM EMAIL</label>
                    <input type="email" value="<?php echo htmlspecialchars($_SESSION['user_email']); ?>" class="form-control custom-form-field" readonly>
                </div>
                <button type="submit" class="btn btn-info px-4 py-2.5 fw-bold rounded-4" style="font-size:12px; font-family:'Space Grotesk';">Save Changes</button>
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
                        <a href="car_license.php" class="btn btn-link text-info p-0 fw-bold text-decoration-none" style="font-size:12px;">
                            <i class="bi bi-arrow-left-right me-1"></i>Switch Car
                        </a>
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
<?php include 'share_footer.php'; ?>

