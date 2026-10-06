<?php
session_start();

require_once __DIR__ . '/Login/session_helper.php';
require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/Customer/license_access.php';
require_once __DIR__ . '/Customer/license_expiry.php';
require_once __DIR__ . '/Customer/car_access.php';
require_once __DIR__ . '/role_state.php';
require_once __DIR__ . '/profile_avatar.php';

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
        'customer' => 'php/Customer/dashboard.php',
    ];

    return $dashboards[strtolower($roleName)] ?? '/php/Customer/dashboard.php';
}

/**
 * Keep the existing signed-in account while changing only its active role.
 * Regenerating the session ID retains session-fixation protection without
 * forcing the user to authenticate a second time.
 */
function account_switch_activate(array $user, int $targetRoleId, string $targetRoleName): void
{
    session_regenerate_id(true);

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

if (empty($_SESSION['is_logged_in']) || empty($_SESSION['user_id'])) {
    account_switch_redirect_login();
}

$userId = (int) $_SESSION['user_id'];
$targetMode = strtolower(trim((string) ($_POST['mode'] ?? '')));

try {
    $stmt = $pdo->prepare(
        'SELECT u.user_id, u.user_name, u.email, u.oauth_avatar_url, u.status, u.role_id, u.business_role_id,
                u.headquarter_id, u.branch_id, u.license_id,
                primary_role.role_name AS primary_role_name,
                business_role.role_name AS business_role_name
         FROM users u
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

if ((int) ($_SESSION['role_id'] ?? 0) === 6 || strtolower(trim((string) ($_SESSION['role_name'] ?? ''))) === 'customer') {
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
    map_pump_set_current_role($pdo, $userId, $customerRoleId);
    account_switch_activate($user, $customerRoleId, 'Customer');
    header('Location: ' . account_switch_dashboard('customer', $customerRoleId));
    exit;
}

if ($targetMode === 'business') {
    $targetRoleId = $businessRoleId;
    $targetRoleName = $businessRoleName;

    if ($targetRoleId <= 0 || trim($targetRoleName) === '') {
        $_SESSION['profile_flash_message'] = 'This account does not have a linked business profile yet.';
        $_SESSION['profile_flash_type'] = 'warning';
        header('Location: /php/Customer/profile_page.php');
        exit;
    }

    map_pump_set_current_role($pdo, $userId, $targetRoleId);
    account_switch_activate($user, $targetRoleId, $targetRoleName);
    header('Location: ' . account_switch_dashboard($targetRoleName, $targetRoleId));
    exit;
}

$_SESSION['profile_flash_message'] = 'Choose a valid profile mode to switch accounts.';
$_SESSION['profile_flash_type'] = 'danger';
header('Location: /php/Customer/profile_page.php');
exit;
