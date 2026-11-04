<?php
session_start();

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../profile_avatar.php';
require_once __DIR__ . '/../imgbb_image_host.php';
require_once __DIR__ . '/../activity_log.php';
require_once __DIR__ . '/staff_access_guard.php';

map_pump_require_active_staff_session($pdo, false);

function redirect_to_login(string $message = 'Please sign in to continue.')
{
    $_SESSION['login_error'] = $message;
    header('Location: ../Login/login.php');
    exit;
}

$flashMessage = $_SESSION['flash_message'] ?? null;
$flashType = $_SESSION['flash_type'] ?? 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);
$accountDeletionFlash = (string) ($_SESSION['account_deletion_flash'] ?? '');
unset($_SESSION['account_deletion_flash']);

if (empty($_SESSION['is_logged_in']) || empty($_SESSION['user_id'])) {
    redirect_to_login();
}

$userId = (int) $_SESSION['user_id'];
$roleName = strtolower((string) ($_SESSION['role_name'] ?? ''));

if ($roleName !== 'staff') {
    redirect_to_login('This page is only available to staff accounts.');
}

if (empty($_SESSION['staff_csrf_token'])) {
    $_SESSION['staff_csrf_token'] = bin2hex(random_bytes(32));
}
$staffCsrfToken = (string)$_SESSION['staff_csrf_token'];

// Handle Profile Avatar Upload Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_avatar') {
    if (isset($_FILES['profile_logo']) && $_FILES['profile_logo']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['profile_logo']['tmp_name'];
        $fileSize = $_FILES['profile_logo']['size'];
        $fileType = (new finfo(FILEINFO_MIME_TYPE))->file($fileTmpPath);
        $allowedTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $maxFileSize = 2 * 1024 * 1024; // 2MB

        if (!isset($allowedTypes[$fileType])) {
            $_SESSION['flash_message'] = 'Only JPG, PNG, and WEBP images are allowed.';
            $_SESSION['flash_type'] = 'error';
        } elseif ($fileSize > $maxFileSize) {
            $_SESSION['flash_message'] = 'Image size must not exceed 2MB.';
            $_SESSION['flash_type'] = 'error';
        } else {
            $newFileName = 'avatar_' . $userId . '_' . bin2hex(random_bytes(8)) . '.' . $allowedTypes[$fileType];
            $relativePath = '/uploads/avatars/' . $newFileName;
            $imageStored = false;
            if (map_pump_imgbb_enabled()) {
                $imageBytes = @file_get_contents($fileTmpPath);
                try {
                    if (!is_string($imageBytes)) {
                        throw new RuntimeException('Could not read the profile image.');
                    }
                    $relativePath = map_pump_imgbb_upload($imageBytes, $newFileName, $fileType);
                    $imageStored = true;
                } catch (Throwable $e) {
                    error_log('Staff avatar ImgBB upload failed: ' . $e->getMessage());
                    $_SESSION['flash_message'] = 'Could not upload the profile image.';
                    $_SESSION['flash_type'] = 'error';
                }
            } else {
                $uploadDir = __DIR__ . '/../../uploads/avatars/';
                if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                    $_SESSION['flash_message'] = 'Could not prepare the profile image folder.';
                    $_SESSION['flash_type'] = 'error';
                } elseif (move_uploaded_file($fileTmpPath, $uploadDir . $newFileName)) {
                    $imageStored = true;
                }
            }

            if ($imageStored) {
                try {
                    $update = $pdo->prepare('UPDATE users SET oauth_avatar_url = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?');
                    $update->execute([$relativePath, $userId]);
                    map_pump_log_action($pdo, $userId, 'Updated staff profile image', 5);
                    $_SESSION['flash_message'] = 'Profile image updated successfully.';
                    $_SESSION['flash_type'] = 'success';
                } catch (Throwable $e) {
                    error_log('Failed to update avatar path: ' . $e->getMessage());
                    $_SESSION['flash_message'] = 'Failed to save avatar details to database.';
                    $_SESSION['flash_type'] = 'error';
                }
            } else {
                $_SESSION['flash_message'] = 'Failed to upload the image file.';
                $_SESSION['flash_type'] = 'error';
            }
        }
    } else {
        $_SESSION['flash_message'] = 'Please select a valid image file to upload.';
        $_SESSION['flash_type'] = 'error';
    }

    header('Location: staff_account.php');
    exit;
}

// Handle Profile Update Request (Name + Email)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    $newName = trim((string) ($_POST['user_name'] ?? ''));
    $newEmail = trim((string) ($_POST['email'] ?? ''));

    if ($newName === '') {
        $_SESSION['flash_message'] = 'Please enter a display name.';
        $_SESSION['flash_type'] = 'error';
    } elseif (mb_strlen($newName) > 225) {
        $_SESSION['flash_message'] = 'Display name must be 225 characters or fewer.';
        $_SESSION['flash_type'] = 'error';
    } elseif ($newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['flash_message'] = 'Please provide a valid email address.';
        $_SESSION['flash_type'] = 'error';
    } else {
        try {
            $checkEmail = $pdo->prepare('SELECT user_id FROM users WHERE email = ? AND user_id != ? LIMIT 1');
            $checkEmail->execute([$newEmail, $userId]);
            
            if ($checkEmail->fetch()) {
                $_SESSION['flash_message'] = 'That email address is already in use by another account.';
                $_SESSION['flash_type'] = 'error';
            } else {
                $update = $pdo->prepare('UPDATE users SET user_name = ?, email = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?');
                $update->execute([$newName, $newEmail, $userId]);
                map_pump_log_action($pdo, $userId, 'Updated staff profile details', 5);
                $_SESSION['user_name'] = $newName;
                $_SESSION['flash_message'] = 'Profile details updated successfully.';
                $_SESSION['flash_type'] = 'success';
            }
        } catch (Throwable $e) {
            error_log('Failed to update staff profile: ' . $e->getMessage());
            $_SESSION['flash_message'] = 'Profile could not be updated right now.';
            $_SESSION['flash_type'] = 'error';
        }
    }

    header('Location: staff_account.php');
    exit;
}

try {
    $stmt = $pdo->prepare(
        'SELECT u.user_id, u.user_name, u.email, u.oauth_avatar_url, u.status, u.role_id, u.business_role_id,
                COALESCE(bas.headquarter_id, sbs.headquarter_id) AS headquarter_id, st.branch_id, u.created_at,
                r.role_name, br.role_name AS business_role_name,
                h.company_name AS headquarter_name,
                b.branch_name, b.branch_code
         FROM users u
         LEFT JOIN roles r ON r.role_id = u.role_id
         LEFT JOIN roles br ON br.role_id = u.business_role_id
         LEFT JOIN staff st ON st.user_id = u.user_id
         LEFT JOIN branches b ON b.branch_id = st.branch_id
         LEFT JOIN business_account_scope bas ON bas.user_id = u.user_id
         LEFT JOIN station_business_scope sbs ON sbs.station_id = st.branch_id
         LEFT JOIN headquarter h ON h.headquarter_id = COALESCE(bas.headquarter_id, sbs.headquarter_id)
         WHERE u.user_id = ?
         LIMIT 1'
    );
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Failed to load staff account: ' . $e->getMessage());
    $user = null;
}

if (!$user) {
    redirect_to_login('Your staff session could not be found. Please sign in again.');
}

$displayName = trim((string) ($user['user_name'] ?? ''));
$displayEmail = trim((string) ($user['email'] ?? ''));
$avatarPath = trim((string) ($user['oauth_avatar_url'] ?? ''));
$staffId = 'EMP-' . str_pad((string) $user['user_id'], 4, '0', STR_PAD_LEFT);
$initial = map_pump_profile_initial($displayName, 'S');
$accountRole = trim((string) ($user['role_name'] ?? 'Staff')) ?: 'Staff';
$headquarterName = trim((string) ($user['headquarter_name'] ?? '')) ?: 'Not assigned yet';
$branchName = trim((string) ($user['branch_name'] ?? '')) ?: 'Not assigned yet';
$branchCode = trim((string) ($user['branch_code'] ?? '')) ?: 'N/A';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Account - Map & Pump</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="icon" type="image/png" href="/images/Logo.png">
    <link rel="stylesheet" href="../../css/Admin.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../../css/StaffDashboard.css">
    <style>
        .avatar-large { 
            width: 80px; 
            height: 80px; 
            background-color: rgba(255, 255, 255, 0.05); 
            color: #ffffff; 
            border-radius: 50%; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-size: 2.2rem;
            border: 2px solid rgba(0, 244, 255, 0.25);
            box-shadow: 0 0 15px rgba(0, 244, 255, 0.1);
            overflow: hidden;
        }
        .avatar-large img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .assignment-panel {
            background: linear-gradient(135deg, rgba(6, 17, 34, 0.82), rgba(24, 35, 52, 0.82));
            border: 1px solid rgba(88, 166, 255, 0.16);
            border-radius: 12px;
            padding: 0.75rem 0.8rem;
            margin-top: 0.75rem;
        }
        .assignment-inline {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .password-input-group {
            display: flex;
            align-items: center;
            min-height: 46px;
            overflow: hidden;
            border: 1px solid var(--staff-border, rgba(13, 110, 253, 0.15));
            border-radius: 12px;
            background: rgba(4, 9, 21, 0.65);
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .password-input-group:focus-within {
            border-color: var(--neon-cyan);
            box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.12), 0 0 15px rgba(13, 110, 253, 0.08);
        }
        .password-input-group .password-field-icon {
            flex: 0 0 auto;
            padding: 0 0 0 15px;
            color: rgba(217, 230, 255, 0.55);
        }
        .password-input-group .password-field {
            min-width: 0;
            flex: 1 1 auto;
            border: 0 !important;
            border-radius: 0 !important;
            background: transparent !important;
            box-shadow: none !important;
            padding: 10px 12px;
        }
        .password-input-group .password-field:focus {
            outline: none;
        }
        .password-visibility-toggle {
            display: grid;
            flex: 0 0 46px;
            align-self: stretch;
            place-items: center;
            padding: 0;
            border: 0 !important;
            border-radius: 0 !important;
            background: transparent !important;
            color: rgba(217, 230, 255, 0.65) !important;
            cursor: pointer;
        }
        .password-visibility-toggle:hover,
        .password-visibility-toggle:focus-visible {
            color: var(--neon-cyan) !important;
            outline: none;
        }
        .assignment-inline i {
            color: var(--neon-cyan);
            font-size: 0.8rem;
        }
        .staff-security-modal .modal-content {
            background: rgba(6, 12, 24, 0.98);
            border: 1px solid rgba(0, 244, 255, 0.22);
            box-shadow: 0 24px 70px rgba(0, 0, 0, 0.45), 0 0 28px rgba(0, 244, 255, 0.08);
            color: #fff;
            border-radius: 20px;
        }
        .staff-security-modal .modal-header,
        .staff-security-modal .modal-footer {
            border-color: rgba(255, 255, 255, 0.08);
        }
        body.background_color.light-mode #changePasswordModal .modal-content,
        body.background_color[data-theme="light"] #changePasswordModal .modal-content {
            background: #fff;
            border-color: #d8e0ea;
            box-shadow: 0 24px 70px rgba(15, 23, 42, 0.2);
            color: #1f2937;
        }
        body.background_color.light-mode #changePasswordModal .modal-title,
        body.background_color[data-theme="light"] #changePasswordModal .modal-title,
        body.background_color.light-mode #changePasswordModal .form-label,
        body.background_color[data-theme="light"] #changePasswordModal .form-label,
        body.background_color.light-mode #changePasswordModal .modal-header p,
        body.background_color[data-theme="light"] #changePasswordModal .modal-header p {
            color: #334155 !important;
        }
        body.background_color.light-mode #changePasswordModal .modal-header,
        body.background_color[data-theme="light"] #changePasswordModal .modal-header,
        body.background_color.light-mode #changePasswordModal .modal-footer,
        body.background_color[data-theme="light"] #changePasswordModal .modal-footer {
            border-color: #e2e8f0;
        }
        body.background_color.light-mode #changePasswordModal .staff-security-icon,
        body.background_color[data-theme="light"] #changePasswordModal .staff-security-icon {
            color: #334155;
            background: #f1f5f9;
            border-color: #dbe3ec;
        }
        body.background_color.light-mode #changePasswordModal .password-input-group,
        body.background_color[data-theme="light"] #changePasswordModal .password-input-group {
            background: #f8fafc;
            border-color: #cbd5e1;
        }
        body.background_color.light-mode #changePasswordModal .password-input-group:focus-within,
        body.background_color[data-theme="light"] #changePasswordModal .password-input-group:focus-within {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.14);
        }
        body.background_color.light-mode #changePasswordModal .password-field-icon,
        body.background_color[data-theme="light"] #changePasswordModal .password-field-icon,
        body.background_color.light-mode #changePasswordModal .password-visibility-toggle,
        body.background_color[data-theme="light"] #changePasswordModal .password-visibility-toggle {
            color: #475569 !important;
        }
        body.background_color.light-mode #changePasswordModal .password-visibility-toggle:hover,
        body.background_color[data-theme="light"] #changePasswordModal .password-visibility-toggle:hover,
        body.background_color.light-mode #changePasswordModal .password-visibility-toggle:focus-visible,
        body.background_color[data-theme="light"] #changePasswordModal .password-visibility-toggle:focus-visible {
            color: #1d4ed8 !important;
        }
        body.background_color.light-mode #changePasswordModal .password-field,
        body.background_color[data-theme="light"] #changePasswordModal .password-field {
            color: #1f2937 !important;
            caret-color: #1f2937;
        }
        body.background_color.light-mode #changePasswordModal .btn-close-white,
        body.background_color[data-theme="light"] #changePasswordModal .btn-close-white {
            filter: none;
            opacity: 0.65;
        }
        body.background_color.light-mode #changePasswordModal .btn-outline-light,
        body.background_color[data-theme="light"] #changePasswordModal .btn-outline-light {
            color: #334155;
            border-color: #cbd5e1;
        }
        body.background_color.light-mode #changePasswordModal .btn-outline-light:hover,
        body.background_color[data-theme="light"] #changePasswordModal .btn-outline-light:hover {
            color: #1e293b;
            background: #f1f5f9;
        }
        .staff-security-icon {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--neon-cyan);
            background: rgba(0, 244, 255, 0.08);
            border: 1px solid rgba(0, 244, 255, 0.2);
        }
        body.background_color.light-mode .avatar-large {
            background: linear-gradient(135deg, #0b5ed7, #084298);
            color: #ffffff;
            border-color: rgba(11, 94, 215, 0.34);
            box-shadow: 0 12px 28px rgba(11, 94, 215, 0.22);
        }
        body.background_color.light-mode .avatar-large span {
            color: #ffffff !important;
            text-shadow: 0 1px 2px rgba(8, 30, 63, 0.35);
        }
    </style>
</head>
<body class="background_color staff-dashboard" id="background_color">

    <div class="page-load-bar"></div>
    <?php $staffPageTitle = 'My account'; $staffActivePage = 'account'; require __DIR__ . '/staff_layout_start.php'; ?>

    <div class="container sticky-nav-wrapper">
        <nav class="navbar navbar-dark text-white navigation_style">
            <div class="container-fluid d-flex justify-content-between align-items-center p-0 gap-3">
                <a class="navbar-brand d-flex align-items-center gap-2 fw-semibold txt-neon-cyan m-0" href="staff_dashboard.php" style="font-size: 0.95rem;">
                    <img src="/images/Logo.png" alt="Logo" class="nav-logo">
                    <span class="d-none d-sm-inline">Staff Portal</span>
                </a>

                <div class="admin-nav-actions">
                    <label class="cosmic-toggle nav-theme-toggle" title="Toggle light mode">
                        <input class="toggle js-theme-toggle" type="checkbox" aria-label="Toggle light mode" />
                        <div class="slider">
                            <span class="theme-icon theme-icon-dark"><i class="bi bi-moon-stars-fill"></i></span>
                            <span class="theme-icon theme-icon-light"><i class="bi bi-sun-fill"></i></span>
                            <div class="cosmos"></div>
                            <div class="energy-line"></div>
                            <div class="energy-line"></div>
                            <div class="energy-line"></div>
                            <div class="toggle-orb">
                                <div class="inner-orb"></div>
                                <div class="ring"></div>
                            </div>
                            <div class="particles">
                                <div style="--angle: 30deg" class="particle"></div>
                                <div style="--angle: 60deg" class="particle"></div>
                                <div style="--angle: 90deg" class="particle"></div>
                                <div style="--angle: 120deg" class="particle"></div>
                                <div style="--angle: 150deg" class="particle"></div>
                                <div style="--angle: 180deg" class="particle"></div>
                            </div>
                        </div>
                    </label>
                    <button class="btn btn-sm menu-pill-btn" type="button" data-bs-toggle="offcanvas" data-bs-target="#mainMenuOffcanvas">
                        <i class="bi bi-grid-fill me-1 txt-neon-cyan"></i> Menu
                    </button>
                </div>
            </div>
        </nav>
    </div>

    <div class="container staff-page-content text_color">
        <div class="row justify-content-center">
            <div class="col-12 col-md-10 col-lg-8 px-3">
                
                <div class="mb-3 ms-1">
                    <a href="staff_dashboard.php" class="stat-chip text-decoration-none">
                        <i class="bi bi-arrow-left"></i> Back to Dashboard
                    </a>
                </div>

                <?php if ($flashMessage): ?>
                    <div class="alert alert-<?= htmlspecialchars($flashType === 'error' ? 'danger' : 'success') ?> rounded-3 border-0 mb-4" role="alert">
                        <?= htmlspecialchars($flashMessage) ?>
                    </div>
                <?php endif; ?>
                <?php if ($accountDeletionFlash !== ''): ?><div class="alert alert-danger border-0 rounded-3 mb-4"><?= htmlspecialchars($accountDeletionFlash) ?></div><?php endif; ?>

                <div class="row g-4 mt-1">
                    <!-- Staff Account Card -->
                    <div class="col-12 col-md-5">
                        <div class="analytics-card p-4 text-center h-100 d-flex flex-column align-items-center justify-content-between">
                            <div class="w-100 d-flex flex-column align-items-center">
                                <div class="avatar-large mb-2">
                                    <?php if ($avatarPath): ?>
                                        <img src="<?= htmlspecialchars($avatarPath) ?>" alt="Profile Logo">
                                    <?php else: ?>
                                        <span><?= htmlspecialchars($initial) ?></span>
                                    <?php endif; ?>
                                </div>

                                <form id="avatarUploadForm" action="staff_account.php" method="POST" enctype="multipart/form-data" class="d-none">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($staffCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="update_avatar">
                                    <input type="file" id="profileLogoInput" name="profile_logo" accept="image/jpeg,image/png,image/webp">
                                </form>

                                <button type="button" id="changeProfilePictureButton" class="btn btn-sm btn-outline-info rounded-pill px-3 py-1 mb-3 text-nowrap" style="font-size: 0.78rem;">
                                    <i class="bi bi-camera me-1"></i> Change Profile Picture
                                </button>

                                <h4 class="fw-bold mb-1 text-white">Staff Account</h4>
                                <span class="stat-chip mb-4" style="color: var(--neon-cyan); border-color: rgba(0, 244, 255, 0.3);"><?= htmlspecialchars($accountRole) ?></span>
                                
                                <div class="w-100 text-start border-top border-secondary border-opacity-25 pt-3 mt-2 mb-3">
                                    <label class="form-label text-white-50 small mb-0">Staff ID</label>
                                    <p class="fw-mono text-white mb-3" style="font-family: monospace;">#<?= htmlspecialchars($staffId) ?></p>

                                    <label class="form-label text-white-50 small mb-0">Display Name</label>
                                    <p class="text-white mb-3"><?= htmlspecialchars($displayName ?: 'Not set') ?></p>

                                    <label class="form-label text-white-50 small mb-0">Email Address</label>
                                    <p class="text-white mb-0"><?= htmlspecialchars($displayEmail ?: 'Not set') ?></p>

                                    <div class="assignment-panel">
                                        <div class="small text-white-50 text-uppercase mb-2" style="letter-spacing:0.08em; font-size:0.62rem;">Work location</div>
                                        <div class="assignment-inline">
                                            <i class="bi bi-building"></i>
                                            <span class="text-white"><?= htmlspecialchars($headquarterName) ?></span>
                                        </div>
                                        <div class="assignment-inline">
                                            <i class="bi bi-shop"></i>
                                            <span class="text-white"><?= htmlspecialchars($branchName) ?> (<?= htmlspecialchars($branchCode) ?>)</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <button type="button" class="btn btn-info w-100 py-2 fw-bold rounded-pill shadow" data-bs-toggle="modal" data-bs-target="#editProfileModal">
                                <i class="bi bi-pencil-square me-2"></i>Change Profile
                            </button>
                        </div>
                    </div>

                    <!-- Right Column Split Cards -->
                    <div class="col-12 col-md-7 d-flex flex-column gap-4">
                        <!-- Card 1: Account Profile Mode -->
                        <div class="dashboard-card p-4 d-flex flex-column justify-content-between h-100">
                            <div>
                                <h3 class="fw-bold mb-2 text-white"><i class="bi bi-arrow-left-right me-2 txt-neon-blue"></i>Account Profile Mode</h3>
                                <p class="text-white-50 small mb-4">Switching profiles keeps this signed-in session active and opens the Customer profile for the same account.</p>
                            </div>
                            <form action="../account_switch.php" method="POST">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($staffCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="mode" value="customer">
                                <button type="submit" class="btn btn-outline-info w-100 py-2 fw-bold rounded-pill">
                                    <i class="bi bi-fuel-pump me-2"></i>Switch to Customer Profile
                                </button>
                            </form>
                        </div>

                        <!-- Card 2: Security Settings -->
                        <div class="dashboard-card p-4 d-flex flex-column justify-content-between h-100">
                            <div>
                                <div class="d-flex align-items-center gap-3 mb-2">
                                    <span class="staff-security-icon"><i class="bi bi-shield-lock"></i></span>
                                    <h3 class="fw-bold mb-0 text-white">Security Settings</h3>
                                </div>
                                <p class="text-white-50 small mb-4">Update your password in a protected floating window to secure your staff credentials.</p>
                            </div>
                            <button type="button" class="btn btn-outline-info w-100 py-2 fw-bold rounded-pill" data-bs-toggle="modal" data-bs-target="#changePasswordModal">
                                <i class="bi bi-key-fill me-2"></i>Change Password
                            </button>
                        </div>
                    </div>
                </div>

                <div class="text-center mt-4">
                    <a href="../Login/logout.php" class="text-danger small text-decoration-none fw-semibold">
                        <i class="bi bi-box-arrow-right me-1"></i> Securely Log Out of This Session
                    </a>
                </div>

            </div>
        </div>
    </div>

    <!-- CHANGE PROFILE DETAILS MODAL -->
    <div class="modal fade staff-security-modal" id="editProfileModal" tabindex="-1" aria-labelledby="editProfileModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form action="staff_account.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($staffCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="update_profile">
                    <div class="modal-header px-4 pt-4">
                        <div class="d-flex align-items-center gap-3">
                            <span class="staff-security-icon"><i class="bi bi-person-gear"></i></span>
                            <div>
                                <h5 class="modal-title fw-bold text-white mb-0" id="editProfileModalLabel">Change Profile</h5>
                                <p class="text-white-50 small mb-0">Update your account's display name and email address.</p>
                            </div>
                        </div>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body px-4">
                        <div class="mb-3">
                            <label for="profileUserName" class="form-label text-white-50 small">Display Name</label>
                            <div class="input-group">
                                <span class="input-group-text glass-input text-white-50 border-end-0"><i class="bi bi-person"></i></span>
                                <input type="text" class="form-control glass-input border-start-0 text-white" id="profileUserName" name="user_name" value="<?= htmlspecialchars($displayName) ?>" maxlength="225" required>
                            </div>
                        </div>
                        <div class="mb-0">
                            <label for="profileEmail" class="form-label text-white-50 small">Email Address</label>
                            <div class="input-group">
                                <span class="input-group-text glass-input text-white-50 border-end-0"><i class="bi bi-envelope"></i></span>
                                <input type="email" class="form-control glass-input border-start-0 text-white" id="profileEmail" name="email" value="<?= htmlspecialchars($displayEmail) ?>" maxlength="255" required>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer px-4 pb-4">
                        <button type="button" class="btn btn-outline-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-info rounded-pill px-4 fw-bold">
                            <i class="bi bi-save me-2"></i>Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- CHANGE PASSWORD FLOATING WINDOW -->
    <div class="modal fade staff-security-modal" id="changePasswordModal" tabindex="-1" aria-labelledby="changePasswordModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form action="change_password_process.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($staffCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="modal-header px-4 pt-4">
                        <div class="d-flex align-items-center gap-3">
                            <span class="staff-security-icon"><i class="bi bi-shield-lock"></i></span>
                            <div>
                                <h5 class="modal-title fw-bold text-white mb-0" id="changePasswordModalLabel">Change Password</h5>
                                <p class="text-white-50 small mb-0">Confirm your current password before saving a new one.</p>
                            </div>
                        </div>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body px-4">
                        <div class="mb-3">
                            <label for="currentPassword" class="form-label text-white-50 small">Current Password</label>
                            <div class="password-input-group">
                                <span class="password-field-icon"><i class="bi bi-lock"></i></span>
                                <input type="password" class="form-control glass-input password-field" id="currentPassword" name="current_password" required>
                                <button type="button" class="password-visibility-toggle" data-password-target="currentPassword" aria-label="Show current password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="newPassword" class="form-label text-white-50 small">New Password</label>
                            <div class="password-input-group">
                                <span class="password-field-icon"><i class="bi bi-key"></i></span>
                                <input type="password" class="form-control glass-input password-field" id="newPassword" name="new_password" required>
                                <button type="button" class="password-visibility-toggle" data-password-target="newPassword" aria-label="Show new password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
                            </div>
                        </div>

                        <div class="mb-0">
                            <label for="confirmPassword" class="form-label text-white-50 small">Confirm New Password</label>
                            <div class="password-input-group">
                                <span class="password-field-icon"><i class="bi bi-check2-circle"></i></span>
                                <input type="password" class="form-control glass-input password-field" id="confirmPassword" name="confirm_password" required>
                                <button type="button" class="password-visibility-toggle" data-password-target="confirmPassword" aria-label="Show password confirmation" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer px-4 pb-4">
                        <button type="button" class="btn btn-outline-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-info rounded-pill px-4 fw-bold">
                            <i class="bi bi-save me-2"></i>Save Password
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- SIDEBAR MAIN MENU -->
    <div class="offcanvas offcanvas-end legacy-staff-menu" tabindex="-1" id="legacyMainMenuOffcanvas" style="width: 320px; background: rgba(6, 12, 24, 0.98); backdrop-filter: blur(28px); border-left: 1px solid rgba(0, 180, 255, 0.18);">
        <div class="offcanvas-header pb-2 border-bottom border-white border-opacity-10">
            <div>
                <h5 class="offcanvas-title text-white fw-bold mb-0">Main Menu</h5>
                <p class="text-white-50 small mb-0" style="font-size:0.78rem;">Staff Portal</p>
            </div>
            <label class="cosmic-toggle float-end" title="Toggle light mode">
                <input class="toggle js-theme-toggle" id="themeToggleMenu" type="checkbox" aria-label="Toggle light mode" />
                <div class="slider">
                    <span class="theme-icon theme-icon-dark"><i class="bi bi-moon-stars-fill"></i></span>
                    <span class="theme-icon theme-icon-light"><i class="bi bi-sun-fill"></i></span>
                    <div class="cosmos"></div>
                    <div class="energy-line"></div>
                    <div class="energy-line"></div>
                    <div class="energy-line"></div>
                    <div class="toggle-orb">
                        <div class="inner-orb"></div>
                        <div class="ring"></div>
                    </div>
                    <div class="particles">
                        <div style="--angle: 30deg" class="particle"></div>
                        <div style="--angle: 60deg" class="particle"></div>
                        <div style="--angle: 90deg" class="particle"></div>
                        <div style="--angle: 120deg" class="particle"></div>
                        <div style="--angle: 150deg" class="particle"></div>
                        <div style="--angle: 180deg" class="particle"></div>
                    </div>
                </div>
            </label>

            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
        </div>
        <div class="offcanvas-body pt-3">
            <ul class="list-unstyled d-flex flex-column gap-2">
                <li><a href="staff_dashboard.php" class="menu-btn-nav"><i class="bi bi-house me-3 txt-neon-cyan"></i> Dashboard</a></li>
                <li><a href="verify_qr.php" class="menu-btn-nav"><i class="bi bi-qr-code-scan me-3 txt-neon-cyan"></i> Verify QR</a></li>
                <li><a href="feedback_manage.php" class="menu-btn-nav"><i class="bi bi-chat-left-heart me-3 txt-neon-cyan"></i> Feedback</a></li>
                <li><a href="staff_account.php" class="menu-btn-nav active-nav"><i class="bi bi-person-gear me-3 txt-neon-blue"></i> Account</a></li>
                <hr style="border-color: rgba(255, 255, 255, 0.08); margin: 4px 0;">
                <li>
                    <form action="../Login/logout.php" method="post" class="m-0">
                        <button type="submit" class="logout-btn-nav btn border-0 w-100">
                            <i class="bi bi-box-arrow-right me-2"></i> Logout
                        </button>
                    </form>
                </li>
            </ul>
        </div>
    </div>

    <?php require __DIR__ . '/staff_layout_end.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/js/Admin.js"></script>
    <script>
        document.querySelectorAll('#editProfileModal, #changePasswordModal').forEach((modal) => {
            document.body.append(modal);
        });

        const avatarUploadForm = document.getElementById('avatarUploadForm');
        const changeProfilePictureButton = document.getElementById('changeProfilePictureButton');
        const profileLogoInput = document.getElementById('profileLogoInput');

        changeProfilePictureButton.addEventListener('click', () => profileLogoInput.click());
        profileLogoInput.addEventListener('change', () => {
            if (profileLogoInput.files.length > 0) {
                avatarUploadForm.submit();
            }
        });

        document.querySelectorAll('.password-visibility-toggle').forEach((toggle) => {
            toggle.addEventListener('click', () => {
                const input = document.getElementById(toggle.dataset.passwordTarget);
                const icon = toggle.querySelector('i');
                if (!input || !icon) return;

                const isVisible = input.type === 'password';
                input.type = isVisible ? 'text' : 'password';
                toggle.setAttribute('aria-pressed', String(isVisible));
                toggle.setAttribute('aria-label', `${isVisible ? 'Hide' : 'Show'} ${toggle.dataset.passwordTarget === 'currentPassword' ? 'current password' : toggle.dataset.passwordTarget === 'newPassword' ? 'new password' : 'password confirmation'}`);
                icon.classList.toggle('bi-eye', !isVisible);
                icon.classList.toggle('bi-eye-slash', isVisible);
            });
        });

        document.addEventListener('pointermove', (event) => {
            document.body.style.setProperty('--bg-x', `${(event.clientX / window.innerWidth) * 100}%`);
            document.body.style.setProperty('--bg-y', `${(event.clientY / window.innerHeight) * 100}%`);
        });
    </script>
</body>
</html>
