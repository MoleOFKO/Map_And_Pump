<?php
session_start();

require_once __DIR__ . '/Login/session_helper.php';
require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/Customer/license_access.php';
require_once __DIR__ . '/Customer/license_expiry.php';
require_once __DIR__ . '/Customer/car_access.php';
require_once __DIR__ . '/role_state.php';
require_once __DIR__ . '/profile_avatar.php';
require_once __DIR__ . '/Staff/staff_access_guard.php';
require_once __DIR__ . '/BranchManager/_helpers.php';

function account_switch_redirect_login(string $message = 'Please sign in to continue.'): void
{
    $_SESSION['login_error'] = $message;
    header('Location: /php/Login/login.php');
    exit;
}

function account_switch_role_id(PDO $pdo, string $roleName): int
{
    $stmt = $pdo->prepare('SELECT role_id FROM roles WHERE LOWER(role_name) = LOWER(?) LIMIT 1');
    $stmt->execute([$roleName]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

function account_switch_dashboard(string $roleName, int $roleId = 0): string
{
    $roleDashboards = [
        1 => '/php/Admin(WebOwner)/admin_dashboard.php',
        2 => '/php/Moderators(UnderAdmin)/moderator_dashboard.php',
        3 => '/php/HQ/index.php',
        4 => '/php/BranchManager/dashboard.php',
        5 => '/php/Staff/staff_dashboard.php',
        6 => '/php/Customer/dashboard.php',
    ];

    if (isset($roleDashboards[$roleId])) {
        return $roleDashboards[$roleId];
    }

    $dashboards = [
        'admin' => '/php/Admin(WebOwner)/admin_dashboard.php',
        'moderator' => '/php/Moderators(UnderAdmin)/moderator_dashboard.php',
        'hq' => '/php/HQ/index.php',
        'manager' => '/php/BranchManager/dashboard.php',
        'branchmanager' => '/php/BranchManager/dashboard.php',
        'branch manager' => '/php/BranchManager/dashboard.php',
        'staff' => '/php/Staff/staff_dashboard.php',
        'customer' => '/php/Customer/dashboard.php',
    ];

    return $dashboards[strtolower($roleName)] ?? '/php/Customer/dashboard.php';
}

function account_switch_allowed_staff_record(PDO $pdo, int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    $staffRoleId = account_switch_role_id($pdo, 'staff');
    if ($staffRoleId <= 0) {
        return null;
    }

    try {
        $stmt = $pdo->prepare(
                'SELECT u.user_id, u.user_name, u.email, u.status, u.role_id, u.business_role_id, u.current_role_id,
                    COALESCE(owned_hq.headquarter_id, bas.headquarter_id) AS headquarter_id,
                    s.staff_id, s.branch_id, s.is_active, s.full_name, s.staff_code,
                    COALESCE(r.role_name, \'staff\') AS current_role_name
             FROM users u
             LEFT JOIN business_account_scope bas ON bas.user_id = u.user_id
             LEFT JOIN headquarter owned_hq ON owned_hq.headquarters_owner_id = u.user_id
             LEFT JOIN staff s ON s.user_id = u.user_id
             LEFT JOIN roles r ON r.role_id = u.current_role_id
             WHERE u.user_id = ?
             LIMIT 1'
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return null;
        }

        $rowStatus = strtolower(trim((string) ($user['status'] ?? 'active')));
        $isActiveStaff = ((int) ($user['is_active'] ?? 0) === 1)
            || ((int) ($user['business_role_id'] ?? 0) === $staffRoleId)
            || ((int) ($user['current_role_id'] ?? 0) === $staffRoleId)
            || ((int) ($user['role_id'] ?? 0) === $staffRoleId);

        if (!$isActiveStaff || in_array($rowStatus, ['disabled', 'suspended', 'removed', 'banned'], true)) {
            return null;
        }

        if (((int) ($user['business_role_id'] ?? 0) !== $staffRoleId) && ((int) ($user['current_role_id'] ?? 0) !== $staffRoleId)) {
            return null;
        }

        return $user;
    } catch (Throwable $e) {
        error_log('Account switch staff entitlement validation failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Keep the existing signed-in account while changing only its active role.
 * Regenerating the session ID retains session-fixation protection without
 * forcing the user to authenticate a second time.
 */
function account_switch_activate(array $user, int $targetRoleId, string $targetRoleName): void
{
    session_regenerate_id(true);

    unset($_SESSION['hq_auth'], $_SESSION['hq_switch_csrf']);
    $_SESSION['is_logged_in'] = true;
    $_SESSION['user_id'] = (int) $user['user_id'];
    $_SESSION['user_name'] = (string) $user['user_name'];
    $_SESSION['user_email'] = (string) $user['email'];
    $_SESSION['oauth_avatar_url'] = (string) ($user['oauth_avatar_url'] ?? '');
    $_SESSION['user_status'] = (string) ($user['status'] ?? '');
    $_SESSION['role_id'] = $targetRoleId;
    $_SESSION['role_name'] = $targetRoleName;
    $_SESSION['current_role_id'] = $targetRoleId;
    $_SESSION['current_role_name'] = $targetRoleName;
    $_SESSION['business_role_id'] = (int) ($user['business_role_id'] ?? 0);
    $_SESSION['business_role_name'] = (string) ($user['business_role_name'] ?? '');
    $_SESSION['headquarter_id'] = (int) ($user['headquarter_id'] ?? 0);
    $_SESSION['branch_id'] = (int) ($user['branch_id'] ?? 0);
    $_SESSION['license_id'] = (int) ($user['license_id'] ?? 0);
    $_SESSION['has_business'] = !empty($user['business_role_id']);
    $_SESSION['active_profile_mode'] = strtolower($targetRoleName) === 'customer' ? 'customer' : 'business';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /php/Customer/profile_page.php');
    exit;
}

if (empty($_SESSION['is_logged_in']) || (empty($_SESSION['user_id']) && empty($_SESSION['hq_auth']))) {
    account_switch_redirect_login();
}

if (strtolower((string)($_SESSION['role_name'] ?? '')) === 'staff') {
    $staffCsrfToken = (string)($_SESSION['staff_csrf_token'] ?? '');
    if ($staffCsrfToken === '' || !hash_equals($staffCsrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        exit('Invalid request token. Reload the page and try again.');
    }
}

$userId = (int) $_SESSION['user_id'];
$targetMode = strtolower(trim((string) ($_POST['mode'] ?? '')));

if (!empty($_SESSION['hq_auth'])) {
    $hqSwitchToken = (string) ($_SESSION['hq_switch_csrf'] ?? '');
    if ($hqSwitchToken === '' || !hash_equals($hqSwitchToken, (string) ($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        exit('Invalid request token. Reload the page and try again.');
    }

    if ($targetMode !== 'customer') {
        account_switch_redirect_login('Choose the linked customer profile to switch accounts.');
    }

    $customerRoleId = account_switch_role_id($pdo, 'customer');
    $headquarterId = (int) ($_SESSION['headquarter_id'] ?? 0);
    if ($customerRoleId <= 0 || $headquarterId <= 0) {
        account_switch_redirect_login('The linked customer profile is unavailable.');
    }

    $linkedCustomerStmt = $pdo->prepare(
        "SELECT u.user_id, u.user_public_id, u.user_name, u.email, u.oauth_avatar_url,
                u.status, u.role_id, u.business_role_id, u.license_id, bas.headquarter_id,
                NULL AS branch_id, customer_role.role_name AS business_role_name
         FROM headquarter h
         INNER JOIN business_account_scope bas ON bas.headquarter_id = h.headquarter_id
             AND bas.business_role_id = ?
         INNER JOIN users u ON u.user_id = bas.user_id
         LEFT JOIN roles customer_role ON customer_role.role_id = bas.business_role_id
                 WHERE h.headquarter_id = ?
           AND u.role_id = ?
           AND LOWER(TRIM(u.status)) IN ('active', 'approved', 'enabled', 'ready', 'pending', 'license_expired')
         LIMIT 1"
    );
    $linkedCustomerStmt->execute([$customerRoleId, $headquarterId, $customerRoleId]);
    $linkedCustomer = $linkedCustomerStmt->fetch(PDO::FETCH_ASSOC);
    if (!$linkedCustomer) {
        $ownerCustomerStmt = $pdo->prepare(
            "SELECT u.user_id, u.user_public_id, u.user_name, u.email, u.oauth_avatar_url,
                    u.status, u.role_id, u.business_role_id, u.license_id,
                    h.headquarter_id, NULL AS branch_id,
                    business_role.role_name AS business_role_name
             FROM headquarter h
             INNER JOIN users u ON u.user_id = h.headquarters_owner_id
             LEFT JOIN roles business_role ON business_role.role_id = u.business_role_id
             WHERE h.headquarter_id = ? AND u.role_id = ?
               AND LOWER(TRIM(u.status)) IN ('active', 'approved', 'enabled', 'ready', 'pending', 'license_expired')
             LIMIT 1"
        );
        $ownerCustomerStmt->execute([$headquarterId, $customerRoleId]);
        $linkedCustomer = $ownerCustomerStmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$linkedCustomer) {
        account_switch_redirect_login('No active customer account is linked to this HQ.');
    }

    customer_sync_license_expiry($pdo, (int) $linkedCustomer['user_id']);
    map_pump_set_current_role($pdo, (int) $linkedCustomer['user_id'], $customerRoleId);
    account_switch_activate($linkedCustomer, $customerRoleId, 'Customer');
    $licenseAccess = customer_license_access($pdo, (int) $linkedCustomer['user_id']);
    header('Location: ' . ($licenseAccess['is_approved']
        ? account_switch_dashboard('customer', $customerRoleId)
        : '/php/Customer/license.php'));
    exit;
}

try {
    $stmt = $pdo->prepare(
        'SELECT u.user_id, u.user_public_id, u.user_name, u.email, u.oauth_avatar_url, u.status, u.role_id, u.business_role_id,
                COALESCE(owned_hq.headquarter_id, bas.headquarter_id) AS headquarter_id,
                bas.business_role_id AS scope_business_role_id,
                bas.headquarter_id AS scope_headquarter_id,
                COALESCE(
                    (SELECT st.branch_id FROM staff st WHERE st.user_id = u.user_id LIMIT 1),
                    (SELECT MIN(b.branch_id) FROM branches b WHERE b.manager_user_id = u.user_id),
                    (SELECT MIN(sbs.station_id) FROM station_business_scope sbs WHERE sbs.branch_manager_user_id = u.user_id)
                ) AS branch_id,
                u.license_id,
                primary_role.role_name AS primary_role_name,
                business_role.role_name AS business_role_name
         FROM users u
         LEFT JOIN business_account_scope bas ON bas.user_id = u.user_id
         LEFT JOIN headquarter owned_hq ON owned_hq.headquarters_owner_id = u.user_id
         LEFT JOIN roles primary_role ON primary_role.role_id = u.role_id
         LEFT JOIN roles business_role ON business_role.role_id = u.business_role_id
         WHERE u.user_id = ?
         LIMIT 1'
    );
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Account switch failed while loading user: ' . $e->getMessage());
    $user = null;
}

if (!$user) {
    account_switch_redirect_login('Your account could not be found. Please sign in again.');
}

if ($targetMode !== 'business' && ((int) ($_SESSION['role_id'] ?? 0) === 6 || strtolower(trim((string) ($_SESSION['role_name'] ?? ''))) === 'customer')) {
    $licenseExpiryState = customer_sync_license_expiry($pdo, $userId);
    if (($licenseExpiryState['driver']['status'] ?? '') === 'expired') {
        header('Location: /php/Customer/profile_page.php');
        exit;
    }
}

$customerRoleId = account_switch_role_id($pdo, 'customer');
$businessRoleId = (int) ($user['business_role_id'] ?? 0);
$businessRoleName = (string) ($user['business_role_name'] ?? '');

if ($targetMode === 'customer') {
    if ($customerRoleId <= 0) {
        account_switch_redirect_login('The Customer role is unavailable. Please sign in again.');
    }

    if (!empty($_SESSION['user_id'])) {
        foreach (staff_status_pending_for_current_user($pdo, (int) $_SESSION['user_id']) as $statusEvent) {
            $statusNotificationId = (int) ($statusEvent['notification_id'] ?? 0);
            if ($statusNotificationId > 0) {
                staff_status_notification_acknowledge($pdo, (int) $_SESSION['user_id'], $statusNotificationId);
            }
        }
    }

    unset($_SESSION['staff_status_popup_type']);
    $_SESSION['pending_staff_status_events'] = [];

    $hqRoleId = account_switch_role_id($pdo, 'HQ');
    $isHqProfile = $hqRoleId > 0 && (
        (int) ($user['role_id'] ?? 0) === $hqRoleId
        || (int) ($user['business_role_id'] ?? 0) === $hqRoleId
        || (int) ($_SESSION['role_id'] ?? 0) === $hqRoleId
    );
    if ($isHqProfile && (int) ($user['headquarter_id'] ?? 0) > 0) {
        $linkedCustomerStmt = $pdo->prepare(
            "SELECT u.user_id, u.user_public_id, u.user_name, u.email, u.oauth_avatar_url,
                    u.status, u.role_id, u.business_role_id, u.license_id,
                    bas.headquarter_id
             FROM business_account_scope bas
             INNER JOIN users u ON u.user_id = bas.user_id
                 INNER JOIN headquarter h ON h.headquarter_id = bas.headquarter_id
             WHERE bas.headquarter_id = ? AND bas.business_role_id = ?
               AND u.role_id = ?
               AND LOWER(TRIM(u.status)) IN ('active', 'approved', 'enabled', 'ready', 'pending', 'license_expired')
             LIMIT 1"
        );
        $linkedCustomerStmt->execute([(int) $user['headquarter_id'], $customerRoleId, $customerRoleId]);
        $linkedCustomer = $linkedCustomerStmt->fetch(PDO::FETCH_ASSOC);
        if (!$linkedCustomer) {
            $ownerCustomerStmt = $pdo->prepare(
                "SELECT u.user_id, u.user_public_id, u.user_name, u.email, u.oauth_avatar_url,
                        u.status, u.role_id, u.business_role_id, u.license_id,
                        h.headquarter_id, business_role.role_name AS business_role_name
                 FROM headquarter h
                 INNER JOIN users u ON u.user_id = h.headquarters_owner_id
                 LEFT JOIN roles business_role ON business_role.role_id = u.business_role_id
                 WHERE h.headquarter_id = ? AND u.role_id = ?
                   AND LOWER(TRIM(u.status)) IN ('active', 'approved', 'enabled', 'ready', 'pending', 'license_expired')
                 LIMIT 1"
            );
            $ownerCustomerStmt->execute([(int) $user['headquarter_id'], $customerRoleId]);
            $linkedCustomer = $ownerCustomerStmt->fetch(PDO::FETCH_ASSOC);
        }
        if ($linkedCustomer) {
            customer_sync_license_expiry($pdo, (int) $linkedCustomer['user_id']);
            map_pump_set_current_role($pdo, (int) $linkedCustomer['user_id'], $customerRoleId);
            account_switch_activate($linkedCustomer, $customerRoleId, 'Customer');
            $licenseAccess = customer_license_access($pdo, (int) $linkedCustomer['user_id']);
            header('Location: ' . ($licenseAccess['is_approved']
                ? account_switch_dashboard('customer', $customerRoleId)
                : '/php/Customer/license.php'));
            exit;
        }
    }

    map_pump_set_current_role($pdo, $userId, $customerRoleId);
    account_switch_activate($user, $customerRoleId, 'Customer');
    header('Location: ' . account_switch_dashboard('customer', $customerRoleId));
    exit;
}

if ($targetMode === 'business') {
    $scopedHqId = (int) ($user['scope_headquarter_id'] ?? 0);
    if ((int) ($user['scope_business_role_id'] ?? 0) === $customerRoleId && $scopedHqId > 0) {
        $hqRoleId = account_switch_role_id($pdo, 'HQ');
        $linkedHqStmt = $pdo->prepare(
            'SELECT owner.user_id, owner.user_public_id, owner.user_name, owner.email,
                    owner.oauth_avatar_url, owner.status, owner.role_id, owner.business_role_id,
                    owner.license_id, h.headquarter_id,
                    primary_role.role_name AS primary_role_name,
                    business_role.role_name AS business_role_name
             FROM headquarter h
             INNER JOIN users owner ON owner.user_id = h.headquarters_owner_id
             LEFT JOIN roles primary_role ON primary_role.role_id = owner.role_id
             LEFT JOIN roles business_role ON business_role.role_id = owner.business_role_id
             WHERE h.headquarter_id = ?
               AND (owner.role_id = ? OR owner.business_role_id = ? OR owner.current_role_id = ?)
               AND LOWER(TRIM(owner.status)) IN (\'active\', \'approved\', \'pending\')
             LIMIT 1'
        );
        $linkedHqStmt->execute([$scopedHqId, $hqRoleId, $hqRoleId, $hqRoleId]);
        $linkedHq = $linkedHqStmt->fetch(PDO::FETCH_ASSOC);
        if (!$linkedHq) {
            account_switch_redirect_login('The linked HQ account is unavailable.');
        }
        map_pump_set_current_role($pdo, (int) $linkedHq['user_id'], $hqRoleId);
        account_switch_activate($linkedHq, $hqRoleId, 'HQ');
        header('Location: ' . account_switch_dashboard('HQ', $hqRoleId));
        exit;
    }

    $staffRoleId = account_switch_role_id($pdo, 'staff');
    $staffRecord = $userId > 0 ? account_switch_allowed_staff_record($pdo, $userId) : null;
    $isCustomerSwitchingToStaff = $businessRoleId > 0 && $staffRoleId > 0 && $businessRoleId === $staffRoleId;

    if ($isCustomerSwitchingToStaff && $staffRecord === null) {
        $_SESSION['staff_status_popup_type'] = 'unavailable';
        $_SESSION['profile_flash_message'] = 'Your staff account is disabled and cannot be reopened from this session.';
        $_SESSION['profile_flash_type'] = 'danger';
        header('Location: /php/Customer/dashboard.php');
        exit;
    }

    if ($businessRoleId <= 0 || trim($businessRoleName) === '') {
        $_SESSION['staff_status_popup_type'] = 'unavailable';
        $_SESSION['profile_flash_message'] = 'This account does not have a linked business profile yet.';
        $_SESSION['profile_flash_type'] = 'warning';
        header('Location: /php/Customer/profile_page.php');
        exit;
    }

    if ($businessRoleId !== $staffRoleId && strtolower(trim($businessRoleName)) !== 'staff' && strtolower(trim($businessRoleName)) !== 'pump attendant' && strtolower(trim($businessRoleName)) !== 'pumpattendant') {
        $targetRoleId = $businessRoleId;
        $targetRoleName = $businessRoleName;
    } else {
        if ($staffRecord === null) {
            $_SESSION['staff_status_popup_type'] = 'unavailable';
            $_SESSION['profile_flash_message'] = 'This staff account is not active and cannot be accessed.';
            $_SESSION['profile_flash_type'] = 'danger';
            header('Location: /php/Customer/dashboard.php');
            exit;
        }
        $targetRoleId = $staffRoleId;
        $targetRoleName = 'Staff';
    }

    map_pump_set_current_role($pdo, $userId, $targetRoleId);
    account_switch_activate($user, $targetRoleId, $targetRoleName);

    if (strtolower($targetRoleName) === 'staff') {
        $activationEvent = null;
        foreach (staff_status_pending_for_current_user($pdo, $userId) as $event) {
            if (($event['notification_type'] ?? '') === 'staff_account_reactivated') {
                $activationEvent = $event;
                break;
            }
        }
        if ($activationEvent && !empty($activationEvent['notification_id'])) {
            staff_status_notification_acknowledge($pdo, $userId, (int) $activationEvent['notification_id']);
            $_SESSION['pending_staff_status_events'] = array_values(array_filter(
                ($_SESSION['pending_staff_status_events'] ?? []),
                static fn ($event) => !is_array($event) || ((int) ($event['notification_id'] ?? 0) !== (int) $activationEvent['notification_id'])
            ));
            unset($_SESSION['staff_status_popup_type']);
        }
    }

    header('Location: ' . account_switch_dashboard($targetRoleName, $targetRoleId));
    exit;
}

$_SESSION['profile_flash_message'] = 'Choose a valid profile mode to switch accounts.';
$_SESSION['profile_flash_type'] = 'danger';
header('Location: /php/Customer/profile_page.php');
exit;
