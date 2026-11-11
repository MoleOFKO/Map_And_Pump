<?php
session_start();

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../profile_avatar.php';
require_once __DIR__ . '/../imgbb_image_host.php';
require_once __DIR__ . '/../activity_log.php';
require_once __DIR__ . '/../role_state.php';
require_once __DIR__ . '/staff_access_guard.php';
require_once __DIR__ . '/../shared/privacy_display.php';

$staffRecord = map_pump_require_active_staff_session($pdo, false);
$isStandaloneStaff = !empty($_SESSION['standalone_staff_id']);

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
if (empty($_SESSION['registration_csrf'])) {
    $_SESSION['registration_csrf'] = bin2hex(random_bytes(32));
}
$staffLinkRegistrationCsrf = (string) $_SESSION['registration_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'link_existing_account') {
    if (!$isStandaloneStaff || $staffCsrfToken === '' || !hash_equals($staffCsrfToken, (string) ($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        exit('Invalid request. Reload the page and try again.');
    }

    $accountEmail = trim((string) ($_POST['account_email'] ?? ''));
    $accountPassword = (string) ($_POST['account_password'] ?? '');
    if (!filter_var($accountEmail, FILTER_VALIDATE_EMAIL) || $accountPassword === '') {
        $_SESSION['flash_message'] = 'Enter the email and password for the existing customer account.';
        $_SESSION['flash_type'] = 'error';
        header('Location: staff_account.php');
        exit;
    }

    try {
        $hasCurrentRole = map_pump_current_role_column_ready($pdo);
        $pdo->beginTransaction();

        $staffStmt = $pdo->prepare('SELECT staff_id, branch_id, full_name FROM staff WHERE staff_id = ? AND user_id IS NULL AND is_active = 1 LIMIT 1 FOR UPDATE');
        $staffStmt->execute([(int) $_SESSION['standalone_staff_id']]);
        $standaloneRecord = $staffStmt->fetch(PDO::FETCH_ASSOC);
        if (!$standaloneRecord) {
            throw new RuntimeException('This standalone staff account is no longer available to link.');
        }

        $accountStmt = $pdo->prepare(
            'SELECT u.user_id, u.user_name, u.email, u.hashed_password, u.status, u.role_id, u.business_role_id,
                    primary_role.role_name
             FROM users u
             LEFT JOIN roles primary_role ON primary_role.role_id = u.role_id
             WHERE LOWER(TRIM(u.email)) = LOWER(TRIM(?))
             LIMIT 1 FOR UPDATE'
        );
        $accountStmt->execute([$accountEmail]);
        $existingAccount = $accountStmt->fetch(PDO::FETCH_ASSOC);
        if (!$existingAccount || !password_verify($accountPassword, (string) ($existingAccount['hashed_password'] ?? ''))) {
            throw new RuntimeException('The existing account email or password is incorrect.');
        }

        $existingStatus = strtolower(trim((string) ($existingAccount['status'] ?? '')));
        $existingRole = strtolower(trim((string) ($existingAccount['role_name'] ?? '')));
        if (!in_array($existingStatus, ['active', 'approved', 'enabled', 'ready'], true)
            || $existingRole !== 'customer'
            || (int) ($existingAccount['business_role_id'] ?? 0) > 0) {
            throw new RuntimeException('Only an active customer account without another linked business role can be linked.');
        }

        $existingStaff = $pdo->prepare('SELECT staff_id FROM staff WHERE user_id = ? LIMIT 1');
        $existingStaff->execute([(int) $existingAccount['user_id']]);
        if ($existingStaff->fetchColumn()) {
            throw new RuntimeException('This customer account is already linked to a staff record.');
        }

        $branchId = (int) $standaloneRecord['branch_id'];
        $hqStmt = $pdo->prepare('SELECT headquarter_id FROM station_business_scope WHERE station_id = ? LIMIT 1');
        $hqStmt->execute([$branchId]);
        $headquarterId = (int) ($hqStmt->fetchColumn() ?: 0);
        if ($headquarterId <= 0) {
            throw new RuntimeException('The assigned branch is not linked to a Headquarter. Contact the branch manager.');
        }

        $staffRoleStmt = $pdo->query("SELECT role_id FROM roles WHERE LOWER(TRIM(role_name)) IN ('staff', 'pump attendant', 'pumpattendant') ORDER BY role_id ASC LIMIT 1");
        $staffRoleId = (int) ($staffRoleStmt ? $staffRoleStmt->fetchColumn() : 0);
        if ($staffRoleId <= 0) {
            throw new RuntimeException('The Staff role is not configured. Contact support.');
        }

        $linkedUserId = (int) $existingAccount['user_id'];
        $userUpdateSql = 'UPDATE users SET business_role_id = ?, updated_at = NOW()';
        $userUpdateParams = [$staffRoleId];
        if ($hasCurrentRole) {
            $userUpdateSql .= ', current_role_id = ?';
            $userUpdateParams[] = $staffRoleId;
        }
        $userUpdateSql .= ' WHERE user_id = ?';
        $userUpdateParams[] = $linkedUserId;
        $pdo->prepare($userUpdateSql)->execute($userUpdateParams);

        $scope = $pdo->prepare(
            "INSERT INTO business_account_scope
                (user_id, business_role_id, headquarter_id, branch_manager_user_id, station_id, status)
             VALUES (?, ?, ?, NULL, ?, 'active')
             ON DUPLICATE KEY UPDATE business_role_id = VALUES(business_role_id), headquarter_id = VALUES(headquarter_id), station_id = VALUES(station_id), status = 'active'"
        );
        $scope->execute([$linkedUserId, $staffRoleId, $headquarterId, $branchId]);

        $linkStaff = $pdo->prepare('UPDATE staff SET user_id = ?, email = ?, staff_password = ?, is_active = 1, updated_at = NOW() WHERE staff_id = ? AND user_id IS NULL');
        $linkStaff->execute([
            $linkedUserId,
            (string) $existingAccount['email'],
            (string) $existingAccount['hashed_password'],
            (int) $standaloneRecord['staff_id'],
        ]);
        if ($linkStaff->rowCount() !== 1) {
            throw new RuntimeException('The staff account changed while it was being linked. Please try again.');
        }

        $pdo->commit();
        session_regenerate_id(true);
        $_SESSION['user_id'] = $linkedUserId;
        $_SESSION['staff_id'] = (int) $standaloneRecord['staff_id'];
        unset($_SESSION['standalone_staff_id']);
        $_SESSION['user_name'] = (string) $existingAccount['user_name'];
        $_SESSION['user_email'] = (string) $existingAccount['email'];
        $_SESSION['staff_email'] = (string) $existingAccount['email'];
        $_SESSION['user_status'] = $existingStatus;
        $_SESSION['role_id'] = $staffRoleId;
        $_SESSION['role_name'] = 'Staff';
        $_SESSION['current_role_id'] = $staffRoleId;
        $_SESSION['current_role_name'] = 'Staff';
        $_SESSION['business_role_id'] = $staffRoleId;
        $_SESSION['business_role_name'] = 'Staff';
        $_SESSION['headquarter_id'] = $headquarterId;
        $_SESSION['branch_id'] = $branchId;
        $_SESSION['has_business'] = true;
        $_SESSION['active_profile_mode'] = 'business';
        $_SESSION['flash_message'] = 'Your staff account is now linked. Use the linked account email and password to sign in.';
        $_SESSION['flash_type'] = 'success';
        header('Location: staff_account.php');
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Staff account linking failed: ' . $e->getMessage());
        $_SESSION['flash_message'] = $e instanceof RuntimeException
            ? $e->getMessage()
            : 'Could not link the account right now. Please try again.';
        $_SESSION['flash_type'] = 'error';
        header('Location: staff_account.php');
        exit;
    }
}

// Handle Profile Avatar Upload Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_avatar') {
    if ($isStandaloneStaff) {
        $_SESSION['flash_message'] = 'Profile pictures are not available for standalone staff accounts.';
        $_SESSION['flash_type'] = 'error';
        header('Location: staff_account.php');
        exit;
    }

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
    if ($isStandaloneStaff && ($staffCsrfToken === '' || !hash_equals($staffCsrfToken, (string) ($_POST['csrf_token'] ?? '')))) {
        http_response_code(403);
        exit('Invalid request token. Reload the page and try again.');
    }

    $newName = trim((string) ($_POST['user_name'] ?? ''));
    $newEmail = trim((string) ($_POST['email'] ?? ''));

    if ($newName === '') {
        $_SESSION['flash_message'] = 'Please enter a display name.';
        $_SESSION['flash_type'] = 'error';
    } elseif (mb_strlen($newName) > ($isStandaloneStaff ? 120 : 225)) {
        $_SESSION['flash_message'] = 'Display name is too long.';
        $_SESSION['flash_type'] = 'error';
    } elseif ($newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['flash_message'] = 'Please provide a valid email address.';
        $_SESSION['flash_type'] = 'error';
    } else {
        try {
            if ($isStandaloneStaff) {
                $userEmailCheck = $pdo->prepare('SELECT user_id FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) LIMIT 1');
                $userEmailCheck->execute([$newEmail]);
                $staffEmailCheck = $pdo->prepare('SELECT staff_id FROM staff WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) AND staff_id != ? LIMIT 1');
                $staffEmailCheck->execute([$newEmail, (int) $staffRecord['staff_id']]);
                if ($userEmailCheck->fetchColumn() || $staffEmailCheck->fetchColumn()) {
                    $_SESSION['flash_message'] = 'That email address is already in use by another account.';
                    $_SESSION['flash_type'] = 'error';
                } else {
                    $update = $pdo->prepare('UPDATE staff SET full_name = ?, email = ?, updated_at = CURRENT_TIMESTAMP WHERE staff_id = ?');
                    $update->execute([$newName, $newEmail, (int) $staffRecord['staff_id']]);
                    $_SESSION['user_name'] = $newName;
                    $_SESSION['user_email'] = $newEmail;
                    $_SESSION['staff_email'] = $newEmail;
                    $_SESSION['flash_message'] = 'Profile details updated successfully.';
                    $_SESSION['flash_type'] = 'success';
                }
            } else {
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

if ($isStandaloneStaff) {
    $user = [
        'user_id' => (int) $staffRecord['staff_id'],
        'user_name' => (string) ($staffRecord['full_name'] ?? ''),
        'email' => (string) ($staffRecord['email'] ?? ''),
        'oauth_avatar_url' => '',
        'role_name' => 'Staff',
        'headquarter_name' => (string) ($staffRecord['headquarter_name'] ?? ''),
        'branch_name' => (string) ($staffRecord['branch_name'] ?? ''),
        'branch_code' => (string) ($staffRecord['branch_code'] ?? ''),
        'staff_code' => (string) ($staffRecord['staff_code'] ?? ''),
    ];
} else {
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
}

if (!$user) {
    redirect_to_login('Your staff session could not be found. Please sign in again.');
}

$displayName = trim((string) ($user['user_name'] ?? ''));
$displayEmail = trim((string) ($user['email'] ?? ''));
$avatarPath = trim((string) ($user['oauth_avatar_url'] ?? ''));
$staffId = $isStandaloneStaff
    ? (string) ($user['staff_code'] ?? 'ST-' . $user['user_id'])
    : 'EMP-' . str_pad((string) $user['user_id'], 4, '0', STR_PAD_LEFT);
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
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="icon" type="image/png" href="/images/Logo.png">
    <link rel="stylesheet" href="../../css/Admin.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../../css/StaffDashboard.css">
    <style>

        /* Account-page styles are intentionally scoped to avoid changing other staff pages. */
        body.staff-account-ui {
            --account-bg: #0d1422;
            --account-surface: #141e2e;
            --account-soft: #192538;
            --account-text: #eef3fc;
            --account-muted: #a4b1c5;
            --account-border: #29374a;
            --account-blue: #6b9dff;
            --account-primary: #2563eb;
            --account-grid: rgba(151, 174, 209, .045);
            --account-shadow: 0 8px 28px rgba(0, 0, 0, .12);
            background: var(--account-bg) !important;
            color: var(--account-text);
            font-family: 'Poppins', sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        body.staff-account-ui.light-mode,
        body.staff-account-ui[data-theme="light"] {
            --account-bg: #fbfcfe;
            --account-surface: #ffffff;
            --account-soft: #f6f8fc;
            --account-text: #111c30;
            --account-muted: #65748a;
            --account-border: #e5eaf2;
            --account-blue: #2563eb;
            --account-grid: rgba(40, 69, 110, .035);
            --account-shadow: 0 8px 28px rgba(27, 47, 79, .045);
        }
        .staff-account-ui .sticky-nav-wrapper { max-width: 1120px; }
        .staff-account-ui .navigation_style {
            margin-top: 16px; padding: 14px 20px;
            border: 1px solid var(--account-border); border-radius: 12px;
            background: var(--account-surface) !important;
            box-shadow: none; backdrop-filter: none;
        }
        .staff-account-ui .navigation_style .navbar-brand { color: var(--account-text) !important; }
        .staff-account-ui .menu-pill-btn {
            border: 1px solid var(--account-border); border-radius: 7px;
            background: var(--account-soft); color: var(--account-text); padding: 8px 14px;
        }
        .staff-account-ui .nav-theme-toggle { margin: 0; }
        .staff-account-ui::before, .staff-account-ui::after { display: none !important; }
        .staff-account-ui .account-theme-toggle { position: relative; display: grid; place-items: center; width: 36px; height: 36px; margin: 0; border: 1px solid var(--account-border); border-radius: 7px; color: var(--account-muted); cursor: pointer; }
        .staff-account-ui .account-theme-toggle .toggle { position: absolute; inset: 0; opacity: 0; width: 100%; height: 100%; margin: 0; cursor: pointer; }
        .staff-account-ui .account-theme-toggle:focus-within { outline: 3px solid var(--account-blue); outline-offset: 3px; }
        .staff-account-ui .admin-nav-actions { display: flex; align-items: center; gap: 10px; }
        .staff-account-ui .nav-logo { width: 26px; height: 26px; object-fit: contain; }
        .account-page { max-width: 1120px; padding: 32px 24px 48px; margin-top: 0; }
        .account-breadcrumb { display: flex; align-items: center; gap: 12px; font-size: 12px; color: var(--account-muted); }
        .account-breadcrumb a { color: var(--account-muted); text-decoration: none; }
        .account-breadcrumb a:hover { color: var(--account-blue); }
        .account-hero {
            position: relative; padding: 44px 0 38px;
            background-image: linear-gradient(var(--account-grid) 1px, transparent 1px), linear-gradient(90deg, var(--account-grid) 1px, transparent 1px);
            background-size: 28px 28px;
        }
        .account-eyebrow { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; font-size: 10px; font-weight: 600; letter-spacing: .13em; color: var(--account-blue); text-transform: uppercase; }
        .account-eyebrow::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: var(--account-blue); }
        .account-hero h1 { font-size: clamp(28px, 4vw, 38px); font-weight: 600; letter-spacing: -.045em; margin: 0 0 12px; color: var(--account-text); }
        .account-hero p { max-width: 580px; font-size: 13px; line-height: 1.8; color: var(--account-muted); margin: 0; }
        .account-card { background: var(--account-surface); border: 1px solid var(--account-border); border-radius: 12px; box-shadow: var(--account-shadow); overflow: hidden; }
        .account-profile-summary { padding: 26px 28px; display: flex; align-items: center; gap: 20px; }
        .account-avatar-wrap { position: relative; flex-shrink: 0; }
        .staff-account-ui .avatar-large { width: 76px; height: 76px; border-radius: 18px; display: grid; place-items: center; background: var(--account-soft); color: var(--account-blue); border: 1px solid var(--account-border); font-size: 30px; font-weight: 500; overflow: hidden; box-shadow: none; }
        .staff-account-ui .avatar-large img { width: 100%; height: 100%; object-fit: cover; }
        .account-avatar-edit { position: absolute; bottom: -5px; right: -5px; width: 28px; height: 28px; display: grid; place-items: center; border: 2px solid var(--account-surface); border-radius: 8px; background: var(--account-primary); color: white; font-size: 12px; cursor: pointer; }
        .account-person { min-width: 0; flex: 1; }
        .account-person h2 { font-size: 20px; font-weight: 600; letter-spacing: -.025em; margin: 0 0 6px; overflow-wrap: anywhere; }
        .account-person p { color: var(--account-muted); font-size: 12px; margin: 0; overflow-wrap: anywhere; }
        .account-role { display: inline-flex; align-items: center; gap: 6px; padding: 5px 10px; font-size: 10px; font-weight: 500; border-radius: 6px; background: var(--account-soft); color: var(--account-blue); border: 1px solid var(--account-border); flex-shrink: 0; }
        .account-main-grid { display: grid; grid-template-columns: minmax(0, 1.45fr) minmax(0, 1fr); gap: 24px; margin-top: 24px; align-items: start; }
        .account-card-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 23px 26px; border-bottom: 1px solid var(--account-border); }
        .account-card h2, .account-card h3 { color: var(--account-text); }
        .account-card-head h2, .account-setting h2 { font-size: 14px; font-weight: 600; margin: 0 0 5px; letter-spacing: -.015em; }
        .account-card-head p, .account-setting p { color: var(--account-muted); font-size: 11px; line-height: 1.8; margin: 0; }
        .account-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 10px 15px; font-size: 11px; font-weight: 500; line-height: 1.5; border: 1px solid var(--account-border); border-radius: 7px; background: var(--account-surface); color: var(--account-text); text-decoration: none; transition: background .15s, border-color .15s, transform .15s; }
        .account-btn:hover { background: var(--account-soft); border-color: var(--account-blue); color: var(--account-text); }
        .account-btn-primary { background: var(--account-primary); border-color: var(--account-primary); color: #fff; }
        .account-btn-primary:hover { background: #1d4ed8; border-color: #1d4ed8; color: #fff; }
        .account-details { margin: 0; padding: 4px 26px; }
        .account-detail-row { padding: 20px 0; display: grid; grid-template-columns: 145px minmax(0, 1fr); align-items: baseline; gap: 16px; border-bottom: 1px solid var(--account-border); font-size: 12px; }
        .account-detail-row:last-child { border-bottom: 0; }
        .account-detail-row dt { color: var(--account-muted); font-weight: 400; }
        .account-detail-row dd { margin: 0; color: var(--account-text); font-weight: 500; overflow-wrap: anywhere; }
        .account-detail-row code { color: var(--account-text); font-size: 11px; background: var(--account-soft); border: 1px solid var(--account-border); padding: 4px 7px; border-radius: 4px; }
        .account-assignment { border-top: 1px solid var(--account-border); padding: 22px 26px 26px; }
        .account-section-label { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: .1em; color: var(--account-muted); margin: 0 0 16px; }
        .account-location { display: flex; align-items: start; gap: 12px; padding: 12px 14px; border: 1px solid var(--account-border); background: var(--account-soft); border-radius: 8px; }
        .account-location > i { color: var(--account-blue); margin-top: 2px; }
        .account-location h3 { font-size: 12px; font-weight: 500; margin: 0 0 5px; overflow-wrap: anywhere; }
        .account-location p { color: var(--account-muted); font-size: 11px; margin: 0; line-height: 1.8; overflow-wrap: anywhere; }
        .account-settings { display: flex; flex-direction: column; gap: 20px; }
        .account-setting { padding: 24px; }
        .account-setting-heading { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
        .account-icon { display: grid; place-items: center; width: 36px; height: 36px; border-radius: 8px; background: var(--account-soft); border: 1px solid var(--account-border); color: var(--account-blue); flex-shrink: 0; }
        .account-setting-heading h2 { margin: 0; }
        .account-setting > p { margin-bottom: 20px; }
        .account-setting .account-btn { width: 100%; }
        .account-session { display: flex; align-items: center; justify-content: space-between; gap: 20px; padding: 20px 0 0; margin-top: 24px; border-top: 1px solid var(--account-border); }
        .account-session p { margin: 0; color: var(--account-muted); font-size: 11px; display: flex; align-items: center; gap: 8px; }
        .account-logout { color: #dc4b56; font-size: 11px; font-weight: 500; text-decoration: none; padding: 8px 0; white-space: nowrap; }
        .account-logout:hover { color: #c72a36; text-decoration: underline; }
        .staff-account-ui .staff-security-modal .modal-content { background: var(--account-surface) !important; color: var(--account-text) !important; border: 1px solid var(--account-border) !important; box-shadow: 0 24px 80px rgba(0, 0, 0, .2) !important; border-radius: 14px; }
        .staff-account-ui .staff-security-modal .modal-header, .staff-account-ui .staff-security-modal .modal-footer { border-color: var(--account-border) !important; }
        .staff-account-ui .staff-security-modal .text-white, .staff-account-ui .staff-security-modal .modal-title { color: var(--account-text) !important; }
        .staff-account-ui .staff-security-modal .text-white-50, .staff-account-ui .staff-security-modal .form-label { color: var(--account-muted) !important; }
        .staff-account-ui .staff-security-modal .modal-title { font-size: 16px; }
        .staff-account-ui .staff-security-modal .small { font-size: 11px; line-height: 1.8; }
        .staff-account-ui .staff-security-icon { display: grid; place-items: center; width: 40px; height: 40px; flex-shrink: 0; background: var(--account-soft) !important; border: 1px solid var(--account-border) !important; color: var(--account-blue) !important; border-radius: 9px; }
        .staff-account-ui .staff-security-modal .glass-input { background: var(--account-soft) !important; color: var(--account-text) !important; border-color: var(--account-border) !important; font-size: 12px; min-height: 44px; box-shadow: none; }
        .staff-account-ui .staff-security-modal .input-group-text { color: var(--account-muted) !important; }
        .staff-account-ui .password-input-group { display: flex; align-items: center; overflow: hidden; border: 1px solid var(--account-border); border-radius: 7px; background: var(--account-soft); }
        .staff-account-ui .password-field-icon { color: var(--account-muted); padding-left: 14px; }
        .staff-account-ui .staff-security-modal .password-field { flex: 1; min-width: 0; border: 0 !important; background: transparent !important; padding: 11px 12px; }
        .staff-account-ui .password-visibility-toggle { flex: 0 0 44px; align-self: stretch; border: 0; background: transparent; color: var(--account-muted); }
        .staff-account-ui .password-visibility-toggle:hover { color: var(--account-blue); }
        .staff-account-ui .password-input-group:focus-within, .staff-account-ui .staff-security-modal .input-group:focus-within { border-color: var(--account-blue); border-radius: 7px; box-shadow: 0 0 0 3px rgba(37, 99, 235, .14); }
        .staff-account-ui .staff-security-modal .btn-info { background: var(--account-primary); border-color: var(--account-primary); color: white; }
        .staff-account-ui .staff-security-modal .btn-outline-light { color: var(--account-text); border-color: var(--account-border); background: var(--account-surface); }
        .staff-account-ui .staff-security-modal .btn-outline-light:hover { background: var(--account-soft); }
        .staff-account-ui .staff-security-modal .btn { border-radius: 7px !important; font-size: 12px; font-weight: 500 !important; padding-top: 10px; padding-bottom: 10px; }
        .staff-account-ui.light-mode .staff-security-modal .btn-close-white, .staff-account-ui[data-theme="light"] .staff-security-modal .btn-close-white { filter: none; }
        .account-page :is(button, a):focus-visible, .staff-account-ui .staff-security-modal button:focus-visible { outline: 3px solid var(--account-blue); outline-offset: 3px; }
        .account-page .alert { font-size: 12px; line-height: 1.8; }
        @media (max-width: 767px) {
            .account-page { padding: 24px 18px 36px; }
            .account-hero { padding: 30px 0; }
            .account-main-grid { grid-template-columns: 1fr; gap: 20px; margin-top: 20px; }
            .account-profile-summary { padding: 22px 20px; display: grid; grid-template-columns: 76px minmax(0, 1fr); gap: 10px 14px; }
            .account-avatar-wrap { grid-row: 1 / 3; }
            .account-role { grid-column: 2; justify-self: start; }
            .account-person h2 { font-size: 17px; }
            .account-card-head { padding: 20px; }
            .account-details { padding: 4px 20px; }
            .account-detail-row { grid-template-columns: 110px minmax(0, 1fr); gap: 12px; }
            .account-assignment { padding: 20px; }
            .account-setting { padding: 22px 20px; }
            .account-session { align-items: flex-start; }
        }
        @media (max-width: 420px) {
            .account-card-head { flex-wrap: wrap; }
            .account-role { margin: 0; }
            .account-detail-row { grid-template-columns: 1fr; gap: 7px; padding: 16px 0; }
            .account-session { flex-direction: column; gap: 8px; }
        }
        @media (prefers-reduced-motion: reduce) { .staff-account-ui *, .staff-account-ui *::before, .staff-account-ui *::after { animation: none !important; transition: none !important; } }
    </style>
</head>
<body class="background_color staff-dashboard staff-account-ui light-mode" id="background_color">

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
                    <label class="account-theme-toggle" title="Toggle light mode">
                        <input class="toggle js-theme-toggle" type="checkbox" aria-label="Toggle light mode" />
                        <i class="bi bi-circle-half" aria-hidden="true"></i>
                    </label>
                    <button class="btn btn-sm menu-pill-btn" type="button" data-bs-toggle="offcanvas" data-bs-target="#mainMenuOffcanvas">
                        <i class="bi bi-grid-fill me-1 txt-neon-cyan"></i> Menu
                    </button>
                </div>
            </div>
        </nav>
    </div>

    <main class="container account-page staff-page-content" aria-labelledby="accountPageTitle">
        <nav class="account-breadcrumb" aria-label="Breadcrumb">
            <a href="staff_dashboard.php"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Dashboard</a>
            <span aria-hidden="true">/</span><span aria-current="page">My account</span>
        </nav>

        <header class="account-hero">
            <div class="account-eyebrow">Staff portal</div>
            <h1 id="accountPageTitle">Your account, at a glance.</h1>
            <p>Manage your personal details, view your work location, and keep your account secure.</p>
        </header>

        <?php if ($flashMessage): ?>
            <div class="alert alert-<?= htmlspecialchars($flashType === 'error' ? 'danger' : 'success') ?> rounded-3 mb-4" role="alert">
                <i class="bi <?= $flashType === 'error' ? 'bi-exclamation-circle' : 'bi-check-circle' ?> me-2" aria-hidden="true"></i><?= htmlspecialchars($flashMessage) ?>
            </div>
        <?php endif; ?>
        <?php if ($accountDeletionFlash !== ''): ?>
            <div class="alert alert-danger rounded-3 mb-4" role="alert"><?= htmlspecialchars($accountDeletionFlash) ?></div>
        <?php endif; ?>

        <section class="account-card account-profile-summary" aria-label="Profile overview">
            <div class="account-avatar-wrap">
                <div class="avatar-large">
                    <?php if ($avatarPath): ?>
                        <img src="<?= htmlspecialchars($avatarPath) ?>" alt="Your profile picture">
                    <?php else: ?>
                        <span aria-hidden="true"><?= htmlspecialchars($initial) ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!$isStandaloneStaff): ?>
                    <form id="avatarUploadForm" action="staff_account.php" method="POST" enctype="multipart/form-data" class="d-none">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($staffCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="update_avatar">
                        <input type="file" id="profileLogoInput" name="profile_logo" accept="image/jpeg,image/png,image/webp" aria-label="Choose a profile picture">
                    </form>
                    <button type="button" id="changeProfilePictureButton" class="account-avatar-edit" aria-label="Change profile picture" title="Change picture · JPG, PNG or WEBP, up to 2 MB"><i class="bi bi-camera" aria-hidden="true"></i></button>
                <?php endif; ?>
            </div>
            <div class="account-person">
                <h2><?= htmlspecialchars($displayName ?: 'Staff Account') ?></h2>
                <p><?= htmlspecialchars($displayEmail ?: 'Email not set') ?></p>
            </div>
            <span class="account-role"><i class="bi bi-person-badge" aria-hidden="true"></i><?= htmlspecialchars($accountRole) ?></span>
        </section>

        <div class="account-main-grid">
            <section class="account-card" aria-labelledby="personalDetailsTitle">
                <div class="account-card-head">
                    <div><h2 id="personalDetailsTitle">Personal details</h2><p>Your staff account information.</p></div>
                    <button type="button" class="account-btn account-btn-primary" data-bs-toggle="modal" data-bs-target="#editProfileModal"><i class="bi bi-pencil" aria-hidden="true"></i> Edit profile</button>
                </div>
                <dl class="account-details">
                    <div class="account-detail-row"><dt>Staff ID</dt><dd><code>#<?= htmlspecialchars($staffId) ?></code></dd></div>
                    <div class="account-detail-row"><dt>Display name</dt><dd><?= htmlspecialchars((string) ($displayName ?: 'Not set'), ENT_QUOTES, 'UTF-8') ?></dd></div>
                    <div class="account-detail-row"><dt>Email address</dt><dd><?= htmlspecialchars((string) ($displayEmail ?: 'Not set'), ENT_QUOTES, 'UTF-8') ?></dd></div>
                    <div class="account-detail-row"><dt>Account role</dt><dd><?= htmlspecialchars($accountRole) ?></dd></div>
                </dl>
                <div class="account-assignment">
                    <h3 class="account-section-label">Work location</h3>
                    <div class="account-location">
                        <i class="bi bi-building" aria-hidden="true"></i>
                        <div><h3><?= htmlspecialchars($headquarterName) ?></h3><p><?= htmlspecialchars($branchName) ?> <span aria-hidden="true">·</span> <?= htmlspecialchars($branchCode) ?></p></div>
                    </div>
                </div>
            </section>

            <aside class="account-settings" aria-label="Account settings">
                <section class="account-card account-setting">
                    <div class="account-setting-heading"><span class="account-icon"><i class="bi bi-shield-lock" aria-hidden="true"></i></span><h2>Security settings</h2></div>
                    <p>Set a staff-only password without changing the customer account password.</p>
                    <button type="button" class="account-btn" data-bs-toggle="modal" data-bs-target="#changePasswordModal"><i class="bi bi-key" aria-hidden="true"></i> Change password <i class="bi bi-arrow-right ms-auto" aria-hidden="true"></i></button>
                </section>
                <?php if ($isStandaloneStaff): ?>
                    <section class="account-card account-setting">
                        <div class="account-setting-heading"><span class="account-icon"><i class="bi bi-link-45deg" aria-hidden="true"></i></span><h2>Link customer account</h2></div>
                        <p>Connect this staff assignment to your existing customer account. Both passwords stay unchanged.</p>
                        <button type="button" class="account-btn" data-bs-toggle="modal" data-bs-target="#linkExistingAccountModal"><i class="bi bi-person-plus" aria-hidden="true"></i> Link existing account <i class="bi bi-arrow-right ms-auto" aria-hidden="true"></i></button>
                    </section>
                <?php endif; ?>
                <?php if (!$isStandaloneStaff): ?>
                    <section class="account-card account-setting">
                        <div class="account-setting-heading"><span class="account-icon"><i class="bi bi-arrow-left-right" aria-hidden="true"></i></span><h2>Account profile mode</h2></div>
                        <p>Open the Customer profile for this account without signing out of your current session.</p>
                        <form action="../account_switch.php" method="POST">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($staffCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="mode" value="customer">
                            <button type="submit" class="account-btn"><i class="bi bi-fuel-pump" aria-hidden="true"></i> Switch to Customer <i class="bi bi-arrow-right ms-auto" aria-hidden="true"></i></button>
                        </form>
                    </section>
                <?php endif; ?>
            </aside>
        </div>
        <footer class="account-session">
            <p><i class="bi bi-info-circle" aria-hidden="true"></i> Finished for now? Sign out on shared devices.</p>
            <a href="../Login/logout.php" class="account-logout"><i class="bi bi-box-arrow-right me-1" aria-hidden="true"></i> Sign out</a>
        </footer>
    </main>

    <?php if ($isStandaloneStaff): ?>
        <?php $pendingStaffLink = $_SESSION['pending_staff_link'] ?? null; ?>
        <div class="modal fade staff-security-modal" id="linkExistingAccountModal" tabindex="-1" aria-labelledby="linkExistingAccountModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header px-4 pt-4">
                        <div class="d-flex align-items-center gap-3">
                            <span class="staff-security-icon"><i class="bi bi-link-45deg"></i></span>
                            <div>
                                <h5 class="modal-title fw-bold text-white mb-0" id="linkExistingAccountModalLabel">Connect an account</h5>
                                <p class="text-white-50 small mb-0">Link an existing account or create one for this staff assignment.</p>
                            </div>
                        </div>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body px-4">
                        <div class="btn-group w-100 mb-3" role="tablist" aria-label="Choose account option">
                            <button type="button" class="btn btn-outline-info active" id="existing-account-tab" role="tab" aria-selected="true" aria-controls="existing-account-panel">Existing account</button>
                            <button type="button" class="btn btn-outline-info" id="create-account-tab" role="tab" aria-selected="false" aria-controls="create-account-panel">Create account</button>
                        </div>
                        <section id="existing-account-panel" role="tabpanel" aria-labelledby="existing-account-tab">
                            <form action="staff_account.php" method="POST">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($staffCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="link_existing_account">
                            <div class="mb-3">
                                <label for="linkAccountEmail" class="form-label text-white-50 small">Customer account email</label>
                                <input type="email" class="form-control glass-input text-white" id="linkAccountEmail" name="account_email" autocomplete="username" required>
                            </div>
                            <div class="mb-0">
                                <label for="linkAccountPassword" class="form-label text-white-50 small">Customer account password</label>
                                <input type="password" class="form-control glass-input text-white" id="linkAccountPassword" name="account_password" autocomplete="current-password" required>
                            </div>
                                <button type="submit" class="btn btn-info rounded-pill px-4 fw-bold mt-3"><i class="bi bi-link-45deg me-2"></i>Link existing account</button>
                            </form>
                        </section>
                        <section id="create-account-panel" role="tabpanel" aria-labelledby="create-account-tab" hidden>
                            <?php if (is_array($pendingStaffLink) && (int) ($pendingStaffLink['staff_id'] ?? 0) === (int) $staffRecord['staff_id']): ?>
                                <p class="text-white-50 small">Enter the verification code sent to <?= htmlspecialchars((string) ($pendingStaffLink['email'] ?? 'your email'), ENT_QUOTES, 'UTF-8') ?>. After verification, this account will be linked automatically.</p>
                                <form id="staff-link-verify-form">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($staffLinkRegistrationCsrf, ENT_QUOTES, 'UTF-8') ?>">
                                    <div class="mb-3">
                                        <label for="staffLinkVerificationCode" class="form-label text-white-50 small">6-digit verification code</label>
                                        <input type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" class="form-control glass-input text-white" id="staffLinkVerificationCode" name="code" required>
                                    </div>
                                    <p id="staff-link-verify-status" class="small mb-2" role="alert"></p>
                                    <button type="submit" class="btn btn-info rounded-pill px-4 fw-bold">Verify and connect</button>
                                </form>
                            <?php else: ?>
                                <form id="staff-link-create-form">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($staffLinkRegistrationCsrf, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="staff_link" value="1">
                                    <div class="mb-3">
                                        <label for="staffLinkUsername" class="form-label text-white-50 small">Username</label>
                                        <input type="text" class="form-control glass-input text-white" id="staffLinkUsername" name="username" maxlength="225" autocomplete="name" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="staffLinkEmail" class="form-label text-white-50 small">Email</label>
                                        <input type="email" class="form-control glass-input text-white" id="staffLinkEmail" name="email" maxlength="255" autocomplete="email" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="staffLinkPassword" class="form-label text-white-50 small">Password</label>
                                        <input type="password" class="form-control glass-input text-white" id="staffLinkPassword" name="password" minlength="8" maxlength="15" autocomplete="new-password" required>
                                        <small class="text-white-50">8-15 characters with uppercase, lowercase, a number, and a symbol.</small>
                                    </div>
                                    <div class="mb-3">
                                        <label for="staffLinkConfirmPassword" class="form-label text-white-50 small">Confirm password</label>
                                        <input type="password" class="form-control glass-input text-white" id="staffLinkConfirmPassword" name="confirm_password" autocomplete="new-password" required>
                                    </div>
                                    <label class="d-flex gap-2 align-items-start text-white-50 small mb-3">
                                        <input type="checkbox" name="test_data_acknowledgement" value="yes" required>
                                        <span>I understand this is a school demo. I will use an accessible email for verification and fictional information for other fields.</span>
                                    </label>
                                    <p class="text-white-50 small">An email code is required. No customer license is copied or requested here; license details remain separate.</p>
                                    <p id="staff-link-create-status" class="small mb-2" role="alert"></p>
                                    <button type="submit" class="btn btn-info rounded-pill px-4 fw-bold">Create and connect account</button>
                                </form>
                                <form id="staff-link-verify-form" hidden>
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($staffLinkRegistrationCsrf, ENT_QUOTES, 'UTF-8') ?>">
                                    <p id="staff-link-verify-destination" class="text-white-50 small"></p>
                                    <div class="mb-3">
                                        <label for="staffLinkVerificationCode" class="form-label text-white-50 small">6-digit verification code</label>
                                        <input type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" class="form-control glass-input text-white" id="staffLinkVerificationCode" name="code" required>
                                    </div>
                                    <p id="staff-link-verify-status" class="small mb-2" role="alert"></p>
                                    <button type="submit" class="btn btn-info rounded-pill px-4 fw-bold">Verify and connect</button>
                                </form>
                            <?php endif; ?>
                        </section>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

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
                                <h5 class="modal-title fw-bold text-white mb-0" id="editProfileModalLabel">Edit profile</h5>
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
                                <input type="text" class="form-control glass-input border-start-0 text-white" id="profileUserName" name="user_name" value="<?= htmlspecialchars($displayName) ?>" maxlength="<?= $isStandaloneStaff ? 120 : 225 ?>" autocomplete="name" required>
                            </div>
                        </div>
                        <div class="mb-0">
                            <label for="profileEmail" class="form-label text-white-50 small">Email Address</label>
                            <div class="input-group">
                                <span class="input-group-text glass-input text-white-50 border-end-0"><i class="bi bi-envelope"></i></span>
                                <input type="email" class="form-control glass-input border-start-0 text-white" id="profileEmail" name="email" value="<?= htmlspecialchars($displayEmail) ?>" maxlength="255" autocomplete="email" required>
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
                                <p class="text-white-50 small mb-0">Set a staff-only password. Your customer password will not change.</p>
                            </div>
                        </div>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body px-4">
                        <div class="mb-3">
                            <label for="currentPassword" class="form-label text-white-50 small">Current password, if already set</label>
                            <div class="password-input-group">
                                <span class="password-field-icon"><i class="bi bi-lock"></i></span>
                                <input type="password" class="form-control glass-input password-field" id="currentPassword" name="current_password" autocomplete="current-password">
                                <button type="button" class="password-visibility-toggle" data-password-target="currentPassword" aria-label="Show current password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="newPassword" class="form-label text-white-50 small">New Password</label>
                            <div class="password-input-group">
                                <span class="password-field-icon"><i class="bi bi-key"></i></span>
                                <input type="password" class="form-control glass-input password-field" id="newPassword" name="new_password" autocomplete="new-password" required>
                                <button type="button" class="password-visibility-toggle" data-password-target="newPassword" aria-label="Show new password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
                            </div>
                        </div>

                        <div class="mb-0">
                            <label for="confirmPassword" class="form-label text-white-50 small">Confirm New Password</label>
                            <div class="password-input-group">
                                <span class="password-field-icon"><i class="bi bi-check2-circle"></i></span>
                                <input type="password" class="form-control glass-input password-field" id="confirmPassword" name="confirm_password" autocomplete="new-password" required>
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
        document.querySelectorAll('#editProfileModal, #changePasswordModal, #linkExistingAccountModal').forEach((modal) => {
            document.body.append(modal);
        });

        const existingAccountTab = document.getElementById('existing-account-tab');
        const createAccountTab = document.getElementById('create-account-tab');
        const existingAccountPanel = document.getElementById('existing-account-panel');
        const createAccountPanel = document.getElementById('create-account-panel');
        if (existingAccountTab && createAccountTab && existingAccountPanel && createAccountPanel) {
            const selectAccountTab = (showCreate) => {
                existingAccountTab.classList.toggle('active', !showCreate);
                createAccountTab.classList.toggle('active', showCreate);
                existingAccountTab.setAttribute('aria-selected', String(!showCreate));
                createAccountTab.setAttribute('aria-selected', String(showCreate));
                existingAccountPanel.hidden = showCreate;
                createAccountPanel.hidden = !showCreate;
            };
            existingAccountTab.addEventListener('click', () => selectAccountTab(false));
            createAccountTab.addEventListener('click', () => selectAccountTab(true));

            const registrationCsrf = <?= json_encode($staffLinkRegistrationCsrf) ?>;
            const createForm = document.getElementById('staff-link-create-form');
            const verifyForm = document.getElementById('staff-link-verify-form');
            const createStatus = document.getElementById('staff-link-create-status');
            const verifyStatus = document.getElementById('staff-link-verify-status');
            const verifyDestination = document.getElementById('staff-link-verify-destination');

            createForm?.addEventListener('submit', async (event) => {
                event.preventDefault();
                const submitButton = createForm.querySelector('button[type="submit"]');
                const payload = new FormData(createForm);
                payload.set('csrf_token', registrationCsrf);
                payload.set('staff_link', '1');
                submitButton.disabled = true;
                createStatus.textContent = '';
                createStatus.className = 'small mb-2';
                try {
                    const response = await fetch('../Login/register_process.php', { method: 'POST', body: payload, credentials: 'same-origin' });
                    const result = await response.json();
                    if (!response.ok || !result.ok) throw new Error(result.message || 'Could not create the account.');
                    createForm.hidden = true;
                    verifyForm.hidden = false;
                    verifyDestination.textContent = result.message || 'Enter the verification code sent to your email.';
                    verifyStatus.textContent = '';
                    document.getElementById('staffLinkVerificationCode')?.focus();
                } catch (error) {
                    createStatus.textContent = error.message || 'Could not create the account. Please try again.';
                    createStatus.classList.add('text-danger');
                } finally {
                    submitButton.disabled = false;
                }
            });

            verifyForm?.addEventListener('submit', async (event) => {
                event.preventDefault();
                const submitButton = verifyForm.querySelector('button[type="submit"]');
                submitButton.disabled = true;
                verifyStatus.textContent = '';
                verifyStatus.className = 'small mb-2';
                try {
                    const response = await fetch('../Login/register_verify.php', {
                        method: 'POST',
                        body: new FormData(verifyForm),
                        credentials: 'same-origin'
                    });
                    const result = await response.json();
                    if (!response.ok || !result.ok) throw new Error(result.message || 'Verification failed.');
                    window.location.href = new URL(result.redirect || 'staff_dashboard.php', window.location.href).href;
                } catch (error) {
                    verifyStatus.textContent = error.message || 'Verification failed. Please try again.';
                    verifyStatus.classList.add('text-danger');
                } finally {
                    submitButton.disabled = false;
                }
            });
        }

        function showAvatarError(message) {
            let alert = document.getElementById('avatarUploadError');
            if (!alert) {
                alert = document.createElement('div');
                alert.id = 'avatarUploadError';
                alert.className = 'alert alert-danger rounded-3 mb-4';
                alert.setAttribute('role', 'alert');
                document.querySelector('.account-profile-summary').before(alert);
            }
            alert.textContent = message;
        }

        document.querySelectorAll('#editProfileModal, #changePasswordModal, #linkExistingAccountModal').forEach((modal) => {
            modal.addEventListener('shown.bs.modal', () => modal.querySelector('input:not([type="hidden"])')?.focus());
        });

        const confirmPassword = document.getElementById('confirmPassword');
        const newPassword = document.getElementById('newPassword');
        function validatePasswordMatch() {
            confirmPassword.setCustomValidity(confirmPassword.value && confirmPassword.value !== newPassword.value ? 'The new passwords do not match.' : '');
        }
        confirmPassword.addEventListener('input', validatePasswordMatch);
        newPassword.addEventListener('input', validatePasswordMatch);

        const avatarUploadForm = document.getElementById('avatarUploadForm');
        const changeProfilePictureButton = document.getElementById('changeProfilePictureButton');
        const profileLogoInput = document.getElementById('profileLogoInput');

        if (avatarUploadForm && changeProfilePictureButton && profileLogoInput) {
            changeProfilePictureButton.addEventListener('click', () => profileLogoInput.click());
            profileLogoInput.addEventListener('change', () => {
                const file = profileLogoInput.files[0];
                if (!file) return;
                if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 2 * 1024 * 1024) {
                    showAvatarError('Choose a JPG, PNG, or WEBP image no larger than 2 MB.');
                    profileLogoInput.value = '';
                    return;
                }
                changeProfilePictureButton.disabled = true;
                changeProfilePictureButton.setAttribute('aria-label', 'Uploading profile picture');
                avatarUploadForm.submit();
            });
        }

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

    </script>
</body>
</html>
