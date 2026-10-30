<?php
require_once __DIR__ . '/session_helper.php';

$remember = !empty($_POST['remember']);
map_pump_configure_session($remember);
session_start();

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../Customer/license_expiry.php';
require_once __DIR__ . '/../account_deletion.php';
require_once __DIR__ . '/../Customer/license_access.php';
require_once __DIR__ . '/../Customer/car_access.php';
require_once __DIR__ . '/../role_state.php';
require_once __DIR__ . '/../moderator_hire_helpers.php';

function auth_redirect_to_login(string $message = 'Please sign in to continue.', string $prefillEmail = '')
{
    $_SESSION['login_error'] = $message;
    if (filter_var($prefillEmail, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['login_prefill_email'] = $prefillEmail;
    }
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    auth_redirect_to_login('Login request is invalid.');
}

$email = trim((string) ($_POST['email'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
    auth_redirect_to_login('Please enter a valid email and password.', $email);
}

try {
    map_pump_cleanup_due_account_deletions($pdo);
    $hasCurrentRole = map_pump_current_role_column_ready($pdo);
    $currentRoleSelect = $hasCurrentRole ? 'u.current_role_id, cr.role_name AS current_role_name,' : 'NULL AS current_role_id, NULL AS current_role_name,';
    $currentRoleJoin = $hasCurrentRole ? 'LEFT JOIN roles cr ON cr.role_id = u.current_role_id' : '';

    $stmt = $pdo->prepare(
        "SELECT u.user_id, u.user_public_id, u.user_name, u.email, u.hashed_password, u.status,
            u.oauth_avatar_url,
                u.role_id, u.business_role_id, u.license_id, u.headquarter_id,
                COALESCE(
                    (SELECT st.branch_id FROM staff st WHERE st.user_id = u.user_id LIMIT 1),
                    (SELECT MIN(b.branch_id) FROM branches b WHERE b.manager_user_id = u.user_id),
                    (SELECT MIN(sbs.station_id) FROM station_business_scope sbs WHERE sbs.branch_manager_user_id = u.user_id)
                ) AS branch_id,
                {$currentRoleSelect}
                r.role_name, br.role_name AS business_role_name
         FROM users u
         LEFT JOIN roles r ON r.role_id = u.role_id
         LEFT JOIN roles br ON br.role_id = u.business_role_id
         {$currentRoleJoin}
         WHERE LOWER(u.email) = LOWER(?)
         LIMIT 1"
    );

    if (!$stmt || !$stmt->execute([$email])) {
        auth_redirect_to_login('Unable to validate your credentials right now.', $email);
    }

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        auth_redirect_to_login('Invalid email or password.', $email);
    }

    if (!isset($user['hashed_password']) || !password_verify($password, $user['hashed_password'])) {
        auth_redirect_to_login('Invalid email or password.', $email);
    }

    $licenseExpiryState = customer_sync_license_expiry($pdo, (int) $user['user_id']);
    if (($licenseExpiryState['driver']['status'] ?? '') === 'expired') {
        $user['status'] = 'license_expired';
    }

    $hqLicenseExpired = false;
    $isHqAccount = (int) ($user['business_role_id'] ?? 0) === 3 || (int) ($user['role_id'] ?? 0) === 3
        || strtolower(trim((string) ($user['role_name'] ?? ''))) === 'hq'
        || strtolower(trim((string) ($user['business_role_name'] ?? ''))) === 'hq';
    if ($isHqAccount) {
        $hqId = (int) ($user['headquarter_id'] ?? 0);
        if ($hqId > 0) {
            $hqStmt = $pdo->prepare('SELECT expire_at FROM headquarter WHERE headquarter_id = ? LIMIT 1');
            if ($hqStmt && $hqStmt->execute([$hqId])) {
                $hqRow = $hqStmt->fetch(PDO::FETCH_ASSOC);
                $expireAt = trim((string) ($hqRow['expire_at'] ?? ''));
                if ($expireAt !== '') {
                    $expireDate = DateTimeImmutable::createFromFormat('!Y-m-d', $expireAt);
                    if ($expireDate && $expireDate->format('Y-m-d') === $expireAt) {
                        $hqLicenseExpired = $expireDate < new DateTimeImmutable('today');
                    }
                }
            }
        }
    }

    $status = strtolower((string) ($user['status'] ?? ''));
    $blockedStatuses = ['disabled', 'banned', 'suspended', 'removed'];
    if (in_array($status, $blockedStatuses, true)) {
        auth_redirect_to_login('This account is disabled or banned. Please contact support.', $email);
    }
    $allowedStatuses = ['active', 'approved', 'enabled', 'ready', 'pending'];
    $isHqAccount = (int) ($user['business_role_id'] ?? 0) === 3 || (int) ($user['role_id'] ?? 0) === 3;
    if ($isHqAccount) {
        $allowedStatuses[] = 'expired';
    }
    $isExpiredCustomer = $status === 'license_expired' && strtolower(trim((string) ($user['role_name'] ?? ''))) === 'customer';
    if ($isExpiredCustomer) {
        $allowedStatuses[] = 'license_expired';
    }
    if ($status === 'pending_deletion' && $isHqAccount) {
        $allowedStatuses[] = 'pending_deletion';
    }
    if ($status !== '' && !in_array($status, $allowedStatuses, true)) {
        auth_redirect_to_login('Your account is not active. Please contact support.', $email);
    }

    [$activeRoleId, $activeRoleName] = map_pump_session_role($user);
    $roleName = trim(strtolower($activeRoleName));

    $dashboardMap = [
        'admin' => '/php/Admin(WebOwner)/admin_dashboard.php',
        'moderator' => '../Moderators(UnderAdmin)/moderator_dashboard.php',
        'hq' => '../HQ/index.php',
        'manager' => '/php/BranchManager/dashboard.php',
        'branchmanager' => '../BranchManager/dashboard.php',
        'branch manager' => '../BranchManager/dashboard.php',
        'staff' => '../Staff/staff_dashboard.php',
        'customer' => '../Customer/dashboard.php',
    ];

    $destination = $dashboardMap[$roleName] ?? '/php/Customer/dashboard.php';
    if ($status === 'pending_deletion' && $isHqAccount) {
        $destination = '/php/HQ/profile.php';
    } elseif ($hqLicenseExpired) {
        $destination = '/php/HQ/profile.php';
    } elseif ($isExpiredCustomer) {
        $destination = '../Customer/license.php';
    }
    $customerLicenseAccess = null;
    if ($roleName === 'customer') {
        $customerLicenseAccess = customer_license_access($pdo, (int) $user['user_id']);
        if ($isExpiredCustomer) {
            $destination = '../Customer/profile_page.php';
        } elseif (!$customerLicenseAccess['is_approved']) {
            $destination = '../Customer/license.php';
        }
    }
    if ($isHqAccount && in_array($status, ['expired', 'pending'], true)) {
        $destination = '/php/HQ/index.php';
    }

    $_SESSION['is_logged_in'] = true;
    $_SESSION['user_id'] = (int) $user['user_id'];
    $_SESSION['user_public_id'] = (string) ($user['user_public_id'] ?? '');
    $_SESSION['user_name'] = (string) $user['user_name'];
    $_SESSION['user_email'] = (string) $user['email'];
    $_SESSION['oauth_avatar_url'] = (string) ($user['oauth_avatar_url'] ?? '');
    $_SESSION['user_status'] = (string) ($user['status'] ?? '');
    $_SESSION['role_id'] = $activeRoleId;
    $_SESSION['role_name'] = $activeRoleName;
    $_SESSION['business_role_id'] = (int) ($user['business_role_id'] ?? 0);
    $_SESSION['business_role_name'] = (string) ($user['business_role_name'] ?? '');
    $_SESSION['headquarter_id'] = (int) ($user['headquarter_id'] ?? 0);
    $_SESSION['branch_id'] = (int) ($user['branch_id'] ?? 0);
    $_SESSION['has_business'] = !empty($user['business_role_id']);
    $_SESSION['active_profile_mode'] = ($roleName === 'customer') ? 'customer' : 'business';
    $_SESSION['remember_me'] = $remember;
    map_pump_set_current_role($pdo, (int) $user['user_id'], $activeRoleId);
    $_SESSION['current_role_id'] = $activeRoleId;
    $_SESSION['current_role_name'] = $activeRoleName;
    if (!empty($user['license_id'])) {
        $_SESSION['license_id'] = (int) $user['license_id'];
    } else {
        $_SESSION['license_id'] = 0;
    }
    if ($roleName === 'customer') {
        customer_refresh_session($pdo, (int) $user['user_id'], $customerLicenseAccess);
    }

    map_pump_set_remembered_email((string) $user['email'], $remember);
    $lastLoginUpdate = $pdo->prepare('UPDATE users SET last_login_at = NOW(), updated_at = NOW() WHERE user_id = ?');
    $lastLoginUpdate->execute([(int) $user['user_id']]);
    $_SESSION['last_login_at'] = date('Y-m-d H:i:s');

    // Load pending moderator hire invites so destination pages can show a popup.
    $pendingHires = moderator_hire_pending_for_user_pdo($pdo, (int) $user['user_id']);
    moderator_hire_store_pending_in_session($pendingHires);

    header('Location: ' . $destination);
    exit;
} catch (Throwable $e) {
    error_log('Login failed: ' . $e->getMessage());
    auth_redirect_to_login('Login failed. Please try again.', $email);
}
