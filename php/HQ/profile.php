<?php
require_once __DIR__ . '/hq_guard.php';
require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../account_deletion.php';
require_once __DIR__ . '/../profile_avatar.php';
require_once __DIR__ . '/../imgbb_image_host.php';
require_once __DIR__ . '/profile_schema.php';

if (empty($_SESSION['user_id']) && empty($_SESSION['hq_auth'])) {
    $loginUrl = '../Login/login.php';
    echo '<script>window.top.location.replace(' . json_encode($loginUrl) . ');</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '"></noscript>';
    exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$isHqAuth = !empty($_SESSION['hq_auth']);
$hqAvatarSchemaReady = true;
if ($isHqAuth) {
    try {
        hq_profile_ensure_avatar_schema($pdo);
    } catch (Throwable $exception) {
        $hqAvatarSchemaReady = false;
        error_log('HQ profile avatar schema could not be prepared: ' . $exception->getMessage());
    }
}
if (empty($_SESSION['hq_customer_link_csrf'])) {
    $_SESSION['hq_customer_link_csrf'] = bin2hex(random_bytes(32));
}
$customerLinkCsrf = (string) $_SESSION['hq_customer_link_csrf'];
if ($isHqAuth && empty($_SESSION['hq_switch_csrf'])) {
    $_SESSION['hq_switch_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'request_deletion') {
            $context = map_pump_hq_owner_context($pdo, $userId);
            $confirmation = trim((string) ($_POST['account_name_delete_account'] ?? ''));
            $expectedConfirmation = trim((string) ($context['user_name'] ?? '')) . '_delete_account';
            if (!$context || $confirmation !== $expectedConfirmation || ($_POST['delete_confirmation'] ?? '') !== 'yes') {
                http_response_code(422);
                echo json_encode(['ok' => false, 'message' => 'Enter the exact account name confirmation and choose Yes.']);
                exit;
            }

            $scheduledFor = map_pump_request_hq_account_deletion($pdo, $userId);
            if ($scheduledFor === null) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'message' => 'Only the HQ account owner can request this deletion.']);
                exit;
            }

            $_SESSION['user_status'] = 'pending_deletion';
            echo json_encode(['ok' => true, 'message' => 'Deletion scheduled.', 'scheduled_for' => $scheduledFor]);
            exit;
        }

        if ($action === 'restore_deletion') {
            if (!map_pump_restore_hq_account($pdo, $userId)) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'message' => 'This HQ account is not awaiting deletion.']);
                exit;
            }

            $_SESSION['user_status'] = 'active';
            echo json_encode(['ok' => true, 'message' => 'Your HQ account has been restored.']);
            exit;
        }

        if ($action === 'update_profile') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));

            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'message' => 'Please enter a valid name and email address.']);
                exit;
            }

            $emailCheck = $isHqAuth
                ? $pdo->prepare('SELECT headquarter_id FROM headquarter WHERE LOWER(headquarter_email) = LOWER(?) AND headquarter_id <> ? LIMIT 1')
                : $pdo->prepare('SELECT user_id FROM users WHERE LOWER(email) = LOWER(?) AND user_id <> ? LIMIT 1');
            $emailCheck->execute([$email, $isHqAuth ? (int) ($_SESSION['headquarter_id'] ?? 0) : $userId]);
            if ($emailCheck->fetchColumn()) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'message' => 'That email address is already in use.']);
                exit;
            }

            if ($isHqAuth) {
                $update = $pdo->prepare(
                    'UPDATE headquarter SET company_name = ?, headquarter_email = ? WHERE headquarter_id = ?'
                );
                $update->execute([$name, $email, (int) ($_SESSION['headquarter_id'] ?? 0)]);
            } else {
                $update = $pdo->prepare('UPDATE users SET user_name = ?, email = ?, updated_at = NOW() WHERE user_id = ?');
                $update->execute([$name, $email, $userId]);
            }
            $_SESSION['user_name'] = $name;
            $_SESSION['user_email'] = $email;
            if ($isHqAuth) {
                $avatarUrl = '';
            } else {
                $avatarStmt = $pdo->prepare('SELECT oauth_avatar_url FROM users WHERE user_id = ? LIMIT 1');
                $avatarStmt->execute([$userId]);
                $avatarUrl = map_pump_profile_avatar_url((string) $avatarStmt->fetchColumn(), '');
            }
            echo json_encode(['ok' => true, 'message' => 'Profile details updated successfully.', 'name' => $name, 'email' => $email, 'avatar_url' => $avatarUrl]);
            exit;
        }

        if ($action === 'update_avatar') {
            if ($isHqAuth && !$hqAvatarSchemaReady) {
                http_response_code(500);
                echo json_encode(['ok' => false, 'message' => 'The HQ profile picture field is not available in the database.']);
                exit;
            }
            if ($isHqAuth && (int) ($_SESSION['headquarter_id'] ?? 0) <= 0) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'message' => 'The HQ account could not be identified.']);
                exit;
            }
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

            $avatarOwnerId = $isHqAuth ? (int) $_SESSION['headquarter_id'] : $userId;
            $fileName = 'hq-profile-' . $avatarOwnerId . '-' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
            $avatarUrl = '/php/HQ/uploads/profile/' . $fileName;
            if (map_pump_imgbb_enabled()) {
                $imageBytes = @file_get_contents((string) $upload['tmp_name']);
                try {
                    if (!is_string($imageBytes)) {
                        throw new RuntimeException('The profile picture could not be read.');
                    }
                    $avatarUrl = map_pump_imgbb_upload($imageBytes, $fileName, $mime);
                } catch (Throwable $e) {
                    error_log('HQ avatar ImgBB upload failed: ' . $e->getMessage());
                    http_response_code(502);
                    echo json_encode(['ok' => false, 'message' => 'The profile picture could not be uploaded.']);
                    exit;
                }
            } else {
                $uploadDir = __DIR__ . '/uploads/profile';
                if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
                    http_response_code(500);
                    echo json_encode(['ok' => false, 'message' => 'The profile picture folder could not be prepared.']);
                    exit;
                }
                if (!move_uploaded_file((string) $upload['tmp_name'], $uploadDir . '/' . $fileName)) {
                    http_response_code(500);
                    echo json_encode(['ok' => false, 'message' => 'The profile picture could not be saved.']);
                    exit;
                }
            }

            if ($isHqAuth) {
                $update = $pdo->prepare('UPDATE headquarter SET profile_avatar_url = ? WHERE headquarter_id = ?');
                $update->execute([$avatarUrl, $avatarOwnerId]);
            } else {
                $update = $pdo->prepare('UPDATE users SET oauth_avatar_url = ?, updated_at = NOW() WHERE user_id = ?');
                $update->execute([$avatarUrl, $userId]);
            }
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

            $hqId = (int) ($_SESSION['headquarter_id'] ?? 0);
            $isHqAuth = !empty($_SESSION['hq_auth']);
            $passwordQuery = $pdo->prepare(
                $isHqAuth
                    ? 'SELECT headquarter_password AS hashed_password FROM headquarter WHERE headquarter_id = ? LIMIT 1'
                    : 'SELECT hashed_password FROM users WHERE user_id = ? LIMIT 1'
            );
            $passwordQuery->execute([$isHqAuth ? $hqId : $userId]);
            $account = $passwordQuery->fetch(PDO::FETCH_ASSOC);

            if (!$account || !password_verify($currentPassword, (string) $account['hashed_password'])) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'message' => 'The current password is incorrect.']);
                exit;
            }

            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $update = $pdo->prepare(
                $isHqAuth
                    ? 'UPDATE headquarter SET headquarter_password = ? WHERE headquarter_id = ?'
                    : 'UPDATE users SET hashed_password = ?, updated_at = NOW() WHERE user_id = ?'
            );
            $update->execute([$newHash, $isHqAuth ? $hqId : $userId]);

            echo json_encode(['ok' => true, 'message' => 'Password changed successfully.']);
            exit;
        }

        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Invalid account update action.']);
    } catch (Throwable $exception) {
        error_log('HQ profile update failed: ' . $exception->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'The account update could not be saved.']);
    }
    exit;
}

if ($isHqAuth) {
    $hqAvatarSelect = $hqAvatarSchemaReady ? 'profile_avatar_url' : 'NULL';
    $stmt = $pdo->prepare(
        "SELECT headquarter_id AS user_id, company_name AS user_name, headquarter_email AS email,
                {$hqAvatarSelect} AS oauth_avatar_url, 3 AS role_id, 3 AS business_role_id
         FROM headquarter WHERE headquarter_id = ? LIMIT 1"
    );
    $stmt->execute([(int) ($_SESSION['headquarter_id'] ?? 0)]);
} else {
    $stmt = $pdo->prepare(
        'SELECT user_id, user_name, email, oauth_avatar_url, role_id, business_role_id
         FROM users WHERE user_id = ? LIMIT 1'
    );
    $stmt->execute([$userId]);
}
$user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$deletionContext = map_pump_hq_owner_context($pdo, $userId);
$deletionPending = ($deletionContext['status'] ?? '') === 'pending_deletion';
$deletionScheduledFor = (string) ($deletionContext['account_deletion_scheduled_for'] ?? '');

$profileName = !empty($user['user_name']) ? $user['user_name'] : 'Unknown User';
$profileEmail = !empty($user['email']) ? $user['email'] : '';
$profileImage = map_pump_profile_avatar_url((string) ($user['oauth_avatar_url'] ?? ''), '');
$profileInitial = map_pump_profile_initial($profileName, 'H');
$profileFallbackUrl = map_pump_profile_initial_svg($profileName, 'H');
$customerRoleStmt = $pdo->query("SELECT role_id FROM roles WHERE LOWER(TRIM(role_name)) = 'customer' LIMIT 1");
$customerRoleId = (int) ($customerRoleStmt->fetchColumn() ?: 0);
$headquarterId = (int) ($_SESSION['headquarter_id'] ?? 0);
$ownerCustomer = null;
if ($headquarterId > 0 && $customerRoleId > 0) {
    $ownerCustomerStmt = $pdo->prepare(
        'SELECT u.user_id, u.user_name, u.email, u.status, u.role_id
         FROM headquarter h
         INNER JOIN users u ON u.user_id = h.headquarters_owner_id
         WHERE h.headquarter_id = ? AND u.role_id = ?
         LIMIT 1'
    );
    $ownerCustomerStmt->execute([$headquarterId, $customerRoleId]);
    $ownerCustomer = $ownerCustomerStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
$linkedCustomer = null;
$canLinkCustomer = false;
if ($headquarterId > 0 && $customerRoleId > 0) {
    $customerLinkStmt = $pdo->prepare(
        'SELECT u.user_id, u.user_name, u.email, u.status
         FROM headquarter h
         INNER JOIN business_account_scope bas ON bas.headquarter_id = h.headquarter_id
             AND bas.business_role_id = ?
         INNER JOIN users u ON u.user_id = bas.user_id
            WHERE h.headquarter_id = ? AND u.role_id = ?
         LIMIT 1'
    );
    $customerLinkStmt->execute([$customerRoleId, $headquarterId, $customerRoleId]);
    $linkedCustomer = $customerLinkStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($isHqAuth) {
        $ownerCheck = $pdo->prepare('SELECT 1 FROM headquarter WHERE headquarter_id = ? AND headquarters_owner_id IS NULL LIMIT 1');
        $ownerCheck->execute([$headquarterId]);
    } else {
        $ownerCheck = $pdo->prepare('SELECT 1 FROM headquarter WHERE headquarter_id = ? AND headquarters_owner_id = ? LIMIT 1');
        $ownerCheck->execute([$headquarterId, $userId]);
    }
    $canLinkCustomer = (bool) $ownerCheck->fetchColumn();
}
$linkedCustomerCanSwitch = $linkedCustomer !== null
    && in_array(strtolower(trim((string) ($linkedCustomer['status'] ?? ''))), ['active', 'approved', 'license_expired'], true);
$ownerCustomerCanSwitch = $ownerCustomer !== null
    && in_array(strtolower(trim((string) ($ownerCustomer['status'] ?? ''))), ['active', 'approved', 'license_expired'], true);
$hasCustomerProfile = $ownerCustomerCanSwitch || $linkedCustomerCanSwitch;
$linkedCustomerUnavailable = ($ownerCustomer !== null || $linkedCustomer !== null) && !$hasCustomerProfile;
$canLinkCustomer = $canLinkCustomer && $linkedCustomer === null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <script src="../../js/hq-theme.js"></script>
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
            border: 1px solid var(--border);
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

        .hq-deletion-card {
            border: 1px solid rgba(248, 113, 113, 0.32) !important;
            background: linear-gradient(145deg, rgba(127, 29, 29, 0.2), rgba(15, 23, 42, 0.88));
        }

        .hq-deletion-card.is-pending {
            border-color: rgba(251, 191, 36, 0.5) !important;
            background: linear-gradient(145deg, rgba(120, 53, 15, 0.24), rgba(15, 23, 42, 0.88));
        }

        .hq-delete-token {
            display: block;
            width: fit-content;
            max-width: 100%;
            margin: 0 0 10px;
            padding: 8px 11px;
            overflow-wrap: anywhere;
            color: #ffe4e6;
            background: rgba(127, 29, 29, 0.32);
            border: 1px solid rgba(248, 113, 113, 0.35);
            border-radius: 8px;
        }

        .hq-deletion-form .form-control {
            max-width: 520px;
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
                            <input type="file" id="profilePictureInput" name="profile_avatar" accept="image/*" style="display: none;" onchange="window.previewImage(event)">
                            <div id="profilePictureStatus" class="small mt-2" role="status" aria-live="polite"></div>
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
                    <p class="subtitle mb-4">Link a customer account to this HQ to switch between the two profiles.</p>
                    <?php if ($hasCustomerProfile): ?>
                        <form action="../account_switch.php" method="POST" target="_top">
                            <input type="hidden" name="mode" value="customer">
                            <?php if ($isHqAuth): ?>
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['hq_switch_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                            <?php endif; ?>
                            <button type="submit" class="btn btn-outline-primary w-100">Switch to Customer Profile</button>
                        </form>
                    <?php elseif ($canLinkCustomer): ?>
                        <button type="button" class="btn btn-outline-primary w-100" onclick="openCustomerLinkModal()">Link to Customer Account</button>
                    <?php elseif ($linkedCustomerUnavailable): ?>
                        <button type="button" class="btn btn-outline-primary w-100" disabled>Switch to Customer Profile</button>
                        <p class="small text-warning mb-0">The linked customer account is currently unavailable.</p>
                    <?php else: ?>
                        <p class="small text-muted mb-0">No customer account is linked to this HQ.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-4">
        <div class="col-12">
            <div class="card p-4 hq-deletion-card <?= $deletionPending ? 'is-pending' : '' ?>">
                <?php if ($deletionPending): ?>
                    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
                        <div>
                            <span class="badge text-bg-warning mb-2">Deletion scheduled</span>
                            <h3 class="mb-2">Your HQ account is pending deletion</h3>
                            <p class="subtitle mb-1">Your account information will be permanently removed after <strong><?= htmlspecialchars($deletionScheduledFor, ENT_QUOTES, 'UTF-8') ?></strong>.</p>
                            <p class="subtitle mb-0">Restore the account before that deadline to cancel the deletion.</p>
                        </div>
                        <form onsubmit="restoreHqAccount(event)">
                            <button type="submit" class="btn btn-success">Restore HQ Account</button>
                        </form>
                    </div>
                <?php else: ?>
                    <h3 class="mb-2">Delete HQ account</h3>
                    <p class="subtitle mb-3">This starts a 30-day recovery period. Your HQ information is not deleted immediately, and the account owner can restore it during that period.</p>
                    <form class="hq-deletion-form" onsubmit="requestHqDeletion(event)">
                        <label for="hqDeleteConfirmation" class="form-label">Type this exact text to continue</label>
                        <code class="hq-delete-token"><?= htmlspecialchars($profileName . '_delete_account', ENT_QUOTES, 'UTF-8') ?></code>
                        <input type="text" class="form-control mb-3" id="hqDeleteConfirmation" name="account_name_delete_account" autocomplete="off" required>
                        <div class="d-flex flex-wrap align-items-center gap-3">
                            <div class="form-check form-check-inline mb-0">
                                <input class="form-check-input" type="radio" name="delete_confirmation" id="hqDeleteYes" value="yes" required>
                                <label class="form-check-label" for="hqDeleteYes">Yes, schedule deletion</label>
                            </div>
                            <div class="form-check form-check-inline mb-0">
                                <input class="form-check-input" type="radio" name="delete_confirmation" id="hqDeleteNo" value="no">
                                <label class="form-check-label" for="hqDeleteNo">No, keep account</label>
                            </div>
                            <button type="submit" class="btn btn-outline-danger ms-auto">Start 30-day deletion</button>
                        </div>
                    </form>
                <?php endif; ?>
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

    <?php if ($canLinkCustomer && !$hasCustomerProfile): ?>
    <div class="password-modal-overlay" id="customerLinkModal" role="dialog" aria-modal="true" aria-labelledby="customerLinkModalTitle" aria-hidden="true">
        <div class="password-modal" role="document">
            <div class="password-modal-header">
                <h2 id="customerLinkModalTitle">Link a Customer Account</h2>
                <button type="button" class="password-modal-close" aria-label="Close" onclick="closeCustomerLinkModal()">&times;</button>
            </div>
            <p class="text-white-50">Choose how to link a customer profile. Verification codes expire after 10 minutes.</p>
            <div id="customerLinkStatus" class="alert d-none" role="alert"></div>
            <div id="customerLinkOptions" class="d-grid gap-3">
                <button type="button" class="btn btn-primary" onclick="showCustomerLinkForm('new')">Create a new customer account</button>
                <button type="button" class="btn btn-outline-primary" onclick="showCustomerLinkForm('existing')">Link with existing account</button>
            </div>
            <form id="newCustomerAccountForm" class="d-none mt-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($customerLinkCsrf, ENT_QUOTES, 'UTF-8') ?>">
                <div class="mb-3">
                    <label class="form-label" for="linkCustomerUsername">Username</label>
                    <input class="form-control" id="linkCustomerUsername" name="username" maxlength="225" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="linkCustomerNewEmail">Email</label>
                    <input class="form-control" id="linkCustomerNewEmail" name="email" type="email" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="linkCustomerPassword">Password</label>
                    <input class="form-control" id="linkCustomerPassword" name="password" type="password" minlength="8" maxlength="15" autocomplete="new-password" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="linkCustomerConfirmPassword">Confirm password</label>
                    <input class="form-control" id="linkCustomerConfirmPassword" name="confirm_password" type="password" minlength="8" maxlength="15" autocomplete="new-password" required>
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" id="linkCustomerPrivacyAck" name="test_data_acknowledgement" type="checkbox" value="yes" required>
                    <label class="form-check-label text-white-50" for="linkCustomerPrivacyAck">I understand this is a school demo. I will use an accessible email for verification and fictional information for other fields.</label>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary" onclick="showCustomerLinkOptions()">Back</button>
                    <button type="submit" class="btn btn-primary flex-grow-1">Send verification code</button>
                </div>
            </form>
            <form id="existingCustomerAccountForm" class="d-none mt-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($customerLinkCsrf, ENT_QUOTES, 'UTF-8') ?>">
                <div class="mb-3">
                    <label class="form-label" for="linkCustomerExistingEmail">Customer account email</label>
                    <input class="form-control" id="linkCustomerExistingEmail" name="email" type="email" required>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary" onclick="showCustomerLinkOptions()">Back</button>
                    <button type="submit" class="btn btn-primary flex-grow-1">Send verification code</button>
                </div>
            </form>
            <form id="customerLinkVerifyForm" class="d-none mt-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($customerLinkCsrf, ENT_QUOTES, 'UTF-8') ?>">
                <div class="mb-3">
                    <label class="form-label" for="linkCustomerCode">6-digit verification code</label>
                    <input class="form-control" id="linkCustomerCode" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" required>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary" onclick="showCustomerLinkOptions()">Cancel</button>
                    <button type="submit" class="btn btn-primary flex-grow-1">Verify and link</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script>
        const serverName = <?= json_encode($profileName, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const serverEmail = <?= json_encode($profileEmail, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const serverImage = <?= json_encode(map_pump_profile_avatar_url($profileImage, $profileFallbackUrl), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        window.addEventListener('DOMContentLoaded', () => {
            document.getElementById('profilePreview').src = serverImage;
        });

        window.previewImage = async function(event) {
            const input = event.currentTarget;
            const file = input.files[0];
            if (!file) return;

            const preview = document.getElementById('profilePreview');
            const status = document.getElementById('profilePictureStatus');
            const previewUrl = URL.createObjectURL(file);
            preview.src = previewUrl;
            status.className = 'small mt-2 text-muted';
            status.textContent = 'Saving profile picture...';
            input.disabled = true;
            let keepPreviewUrl = false;

            const data = new FormData();
            data.append('action', 'update_avatar');
            data.append('profile_avatar', file);

            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: data,
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' }
                });
                const result = await response.json().catch(() => null);
                if (!response.ok || !result?.ok) {
                    throw new Error(result?.message || `Profile picture upload failed (${response.status}).`);
                }

                if (result.avatar_url) {
                    const savedUrl = new URL(result.avatar_url, window.location.href);
                    savedUrl.searchParams.set('v', Date.now().toString());
                    preview.src = savedUrl.toString();
                }
                status.className = 'small mt-2 text-success';
                status.textContent = result.message || 'Profile picture updated successfully.';
                notifyParentProfileChange({ avatarUrl: result.avatar_url || '' });
            } catch (error) {
                keepPreviewUrl = true;
                status.className = 'small mt-2 text-danger';
                status.textContent = `${error.message || 'Profile picture could not be saved.'} The selected image is only a preview and was not saved.`;
            } finally {
                input.disabled = false;
                input.value = '';
                if (!keepPreviewUrl) URL.revokeObjectURL(previewUrl);
            }
        };

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

        async function requestHqDeletion(event) {
            event.preventDefault();
            const formData = new FormData(event.target);
            formData.append('action', 'request_deletion');
            if (await submitAccountUpdate(formData)) {
                window.location.reload();
            }
        }

        async function restoreHqAccount(event) {
            event.preventDefault();
            const formData = new FormData();
            formData.append('action', 'restore_deletion');
            if (await submitAccountUpdate(formData)) {
                window.location.reload();
            }
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
                if (formData.get('action') === 'update_profile') {
                    notifyParentProfileChange({
                        name: result.name || document.getElementById('profileName').value,
                        email: result.email || document.getElementById('profileEmail').value,
                        avatarUrl: result.avatar_url || ''
                    });
                }
                openUpdateModal(result.message);
                return true;
            } catch (error) {
                alert(error.message);
                return false;
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

        function notifyParentProfileChange(profile) {
            if (window.parent === window) return;
            window.parent.postMessage({ type: 'map-pump-profile-updated', profile }, window.location.origin);
        }

        function openCustomerLinkModal() {
            const modal = document.getElementById('customerLinkModal');
            if (!modal) return;
            showCustomerLinkOptions();
            modal.classList.add('is-visible');
            modal.setAttribute('aria-hidden', 'false');
        }

        function closeCustomerLinkModal() {
            const modal = document.getElementById('customerLinkModal');
            if (!modal) return;
            modal.classList.remove('is-visible');
            modal.setAttribute('aria-hidden', 'true');
        }

        function showCustomerLinkStatus(message, ok = false) {
            const status = document.getElementById('customerLinkStatus');
            if (!status) return;
            status.textContent = message;
            status.className = `alert ${ok ? 'alert-success' : 'alert-danger'}`;
        }

        function showCustomerLinkOptions() {
            document.getElementById('customerLinkOptions')?.classList.remove('d-none');
            document.getElementById('newCustomerAccountForm')?.classList.add('d-none');
            document.getElementById('existingCustomerAccountForm')?.classList.add('d-none');
            document.getElementById('customerLinkVerifyForm')?.classList.add('d-none');
            const status = document.getElementById('customerLinkStatus');
            if (status) {
                status.className = 'alert d-none';
                status.textContent = '';
            }
        }

        function showCustomerLinkForm(kind) {
            document.getElementById('customerLinkOptions')?.classList.add('d-none');
            document.getElementById(kind === 'new' ? 'newCustomerAccountForm' : 'existingCustomerAccountForm')?.classList.remove('d-none');
            const status = document.getElementById('customerLinkStatus');
            if (status) {
                status.className = 'alert d-none';
                status.textContent = '';
            }
        }

        async function sendCustomerLinkRequest(form, action) {
            const data = new FormData(form);
            data.set('action', action);
            const response = await fetch('link_customer_account.php', {
                method: 'POST',
                body: data,
                credentials: 'same-origin'
            });
            const result = await response.json();
            if (!response.ok || !result.ok) {
                throw new Error(result.message || 'The customer account could not be linked.');
            }
            return result;
        }

        for (const [formId, action] of [
            ['newCustomerAccountForm', 'start_new_registration'],
            ['existingCustomerAccountForm', 'send_existing_code']
        ]) {
            document.getElementById(formId)?.addEventListener('submit', async (event) => {
                event.preventDefault();
                try {
                    const result = await sendCustomerLinkRequest(event.currentTarget, action);
                    showCustomerLinkStatus(result.message, true);
                    document.getElementById('linkCustomerPassword')?.value = '';
                    document.getElementById('linkCustomerConfirmPassword')?.value = '';
                    document.getElementById('customerLinkOptions')?.classList.add('d-none');
                    document.getElementById('newCustomerAccountForm')?.classList.add('d-none');
                    document.getElementById('existingCustomerAccountForm')?.classList.add('d-none');
                    document.getElementById('customerLinkVerifyForm')?.classList.remove('d-none');
                    document.getElementById('linkCustomerCode')?.focus();
                } catch (error) {
                    showCustomerLinkStatus(error.message);
                }
            });
        }

        document.getElementById('customerLinkVerifyForm')?.addEventListener('submit', async (event) => {
            event.preventDefault();
            try {
                const result = await sendCustomerLinkRequest(event.currentTarget, 'verify_code');
                showCustomerLinkStatus(result.message, true);
                window.location.reload();
            } catch (error) {
                showCustomerLinkStatus(error.message);
            }
        });

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

        document.getElementById('customerLinkModal')?.addEventListener('click', (event) => {
            if (event.target.id === 'customerLinkModal') {
                closeCustomerLinkModal();
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closePasswordModal();
                closeUpdateModal();
                closeCustomerLinkModal();
            }
        });
    </script>
</body>
<footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map & Pump | All Rights Reserved</footer>
</html>
