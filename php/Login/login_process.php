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
require_once __DIR__ . '/../BranchManager/_helpers.php';

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

    $hqLoginStmt = $pdo->prepare(
        'SELECT headquarter_id, company_name, headquarter_email, headquarter_password, status
         FROM headquarter
         WHERE LOWER(TRIM(headquarter_email)) = LOWER(TRIM(?))
         ORDER BY headquarter_id DESC
         LIMIT 1'
    );
    $hqLoginStmt->execute([$email]);
    $hqAccount = $hqLoginStmt->fetch(PDO::FETCH_ASSOC);
    if ($hqAccount && trim((string) ($hqAccount['headquarter_password'] ?? '')) !== ''
        && password_verify($password, (string) $hqAccount['headquarter_password'])) {
        $hqStatus = strtolower(trim((string) ($hqAccount['status'] ?? '')));
        if (in_array($hqStatus, ['disabled', 'rejected', 'suspended', 'removed'], true)) {
            auth_redirect_to_login('This company account is disabled. Please contact support.', $email);
        }

        session_regenerate_id(true);
        $_SESSION['is_logged_in'] = true;
        $_SESSION['hq_auth'] = true;
        $_SESSION['user_id'] = 0;
        $_SESSION['user_public_id'] = '';
        $_SESSION['user_name'] = (string) ($hqAccount['company_name'] ?? 'Company');
        $_SESSION['user_email'] = (string) $hqAccount['headquarter_email'];
        $_SESSION['oauth_avatar_url'] = '';
        $_SESSION['user_status'] = 'active';
        $_SESSION['role_id'] = 3;
        $_SESSION['role_name'] = 'HQ';
        $_SESSION['business_role_id'] = 3;
        $_SESSION['business_role_name'] = 'HQ';
        $_SESSION['current_role_id'] = 3;
        $_SESSION['current_role_name'] = 'HQ';
        $_SESSION['headquarter_id'] = (int) $hqAccount['headquarter_id'];
        $_SESSION['branch_id'] = 0;
        $_SESSION['has_business'] = true;
        $_SESSION['active_profile_mode'] = 'business';
        $_SESSION['remember_me'] = $remember;
        $_SESSION['last_login_at'] = date('Y-m-d H:i:s');
        unset($_SESSION['standalone_staff_id'], $_SESSION['staff_id']);
        map_pump_set_remembered_email((string) $hqAccount['headquarter_email'], $remember);

        header('Location: ../HQ/index.php');
        exit;
    }

    $staffLoginStmt = $pdo->prepare(
        'SELECT staff_id, user_id, branch_id, full_name, email, staff_password, contact, staff_code, is_active
         FROM staff
         WHERE LOWER(TRIM(COALESCE(email, ""))) = LOWER(TRIM(?))
         LIMIT 1'
    );
    $staffLoginStmt->execute([$email]);
    $standaloneStaff = $staffLoginStmt->fetch(PDO::FETCH_ASSOC);

    $hasCurrentRole = map_pump_current_role_column_ready($pdo);
    $currentRoleSelect = $hasCurrentRole ? 'u.current_role_id, cr.role_name AS current_role_name,' : 'NULL AS current_role_id, NULL AS current_role_name,';
    $currentRoleJoin = $hasCurrentRole ? 'LEFT JOIN roles cr ON cr.role_id = u.current_role_id' : '';

    $stmt = $pdo->prepare(
        "SELECT u.user_id, u.user_public_id, u.user_name, u.email, u.hashed_password, u.status,
            (SELECT manager_branch.branch_password
             FROM branches manager_branch
             WHERE manager_branch.manager_user_id = u.user_id
             ORDER BY manager_branch.branch_id
             LIMIT 1) AS branch_password,
            u.oauth_avatar_url,
                u.role_id, u.business_role_id, u.license_id,
                COALESCE((SELECT owned_hq.headquarter_id
                    FROM headquarter owned_hq
                    WHERE owned_hq.headquarters_owner_id = u.user_id
                    ORDER BY owned_hq.headquarter_id DESC LIMIT 1), bas.headquarter_id) AS headquarter_id,
                COALESCE(
                    (SELECT st.branch_id FROM staff st WHERE st.user_id = u.user_id LIMIT 1),
                    (SELECT MIN(b.branch_id) FROM branches b WHERE b.manager_user_id = u.user_id),
                    (SELECT MIN(sbs.station_id) FROM station_business_scope sbs WHERE sbs.branch_manager_user_id = u.user_id)
                ) AS branch_id,
                {$currentRoleSelect}
                r.role_name, br.role_name AS business_role_name
         FROM users u
         LEFT JOIN business_account_scope bas ON bas.user_id = u.user_id
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

    $allowedStaffLogin = false;
    if ($standaloneStaff) {
        $staffPasswordHash = (string) ($standaloneStaff['staff_password'] ?? '');
        $standaloneStaffActive = ((int) ($standaloneStaff['is_active'] ?? 0)) === 1;
        if ($standaloneStaffActive && $staffPasswordHash !== '' && password_verify($password, $staffPasswordHash)) {
            $allowedStaffLogin = true;
        }
    }

    if ($allowedStaffLogin) {
        $user = null;
    }

    if (!$user && !$allowedStaffLogin) {
        auth_redirect_to_login('Invalid email or password.', $email);
    }

    if ($user) {
        $isBranchManagerAccount = (int) ($user['business_role_id'] ?? 0) === 4
            || (int) ($user['current_role_id'] ?? 0) === 4
            || (int) ($user['role_id'] ?? 0) === 4;
        $passwordHash = $isBranchManagerAccount && trim((string) ($user['branch_password'] ?? '')) !== ''
            ? (string) $user['branch_password']
            : (string) ($user['hashed_password'] ?? '');
        if ($passwordHash === '' || !password_verify($password, $passwordHash)) {
            if (!$allowedStaffLogin) {
                auth_redirect_to_login('Invalid email or password.', $email);
            }
            $user = null;
        }
    }

    if ($user) {
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
        unset($_SESSION['hq_auth']);
        unset($_SESSION['standalone_staff_id'], $_SESSION['staff_id']);
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

        // Load pending staff status notifications for the user without marking them as read.
        $pendingStaffStatusEvents = staff_status_pending_for_current_user($pdo, (int) $user['user_id']);
        $_SESSION['pending_staff_status_events'] = $pendingStaffStatusEvents;

        header('Location: ' . $destination);
        exit;
    }

    if (!$allowedStaffLogin) {
        auth_redirect_to_login('Invalid email or password.', $email);
    }

    $staffActive = ((int) ($standaloneStaff['is_active'] ?? 0)) === 1;
    if (!$staffActive) {
        auth_redirect_to_login('This staff account is disabled. Please contact your branch manager.', $email);
    }

    $_SESSION['is_logged_in'] = true;
    $staffId = (int) ($standaloneStaff['staff_id'] ?? 0);
    $linkedUserId = (int) ($standaloneStaff['user_id'] ?? 0);
    $_SESSION['user_id'] = $linkedUserId > 0 ? $linkedUserId : $staffId;
    $_SESSION['staff_id'] = $staffId;
    if ($linkedUserId <= 0) {
        $_SESSION['standalone_staff_id'] = $staffId;
    } else {
        unset($_SESSION['standalone_staff_id']);
    }
    $_SESSION['user_public_id'] = '';
    $_SESSION['user_name'] = (string) ($standaloneStaff['full_name'] ?? '');
    $_SESSION['user_email'] = (string) ($standaloneStaff['email'] ?? '');
    $_SESSION['staff_email'] = (string) ($standaloneStaff['email'] ?? '');
    $_SESSION['oauth_avatar_url'] = '';
    $_SESSION['user_status'] = 'active';
    $_SESSION['role_id'] = 5;
    $_SESSION['role_name'] = 'Staff';
    $_SESSION['business_role_id'] = $linkedUserId > 0 ? 5 : 0;
    $_SESSION['business_role_name'] = 'Staff';
    $_SESSION['headquarter_id'] = 0;
    $_SESSION['branch_id'] = (int) ($standaloneStaff['branch_id'] ?? 0);
    $_SESSION['has_business'] = false;
    $_SESSION['active_profile_mode'] = 'business';
    $_SESSION['remember_me'] = $remember;
    $_SESSION['current_role_id'] = 5;
    $_SESSION['current_role_name'] = 'Staff';
    $_SESSION['license_id'] = 0;
    $_SESSION['last_login_at'] = date('Y-m-d H:i:s');
    $_SESSION['pending_staff_status_events'] = staff_status_pending_for_current_user($pdo, (int) ($_SESSION['user_id'] ?? 0), (string) ($_SESSION['user_email'] ?? ''));
    map_pump_set_remembered_email((string) ($_SESSION['user_email'] ?? $email), $remember);

    header('Location: ../Staff/staff_dashboard.php');
    exit;
} catch (Throwable $e) {
    error_log('Login failed: ' . $e->getMessage());
    auth_redirect_to_login('Login failed. Please try again.', $email);
}
