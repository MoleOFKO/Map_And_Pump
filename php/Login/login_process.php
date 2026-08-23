<?php
session_start();

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../Customer/license_access.php';
require_once __DIR__ . '/../role_state.php';

function auth_redirect_to_login(string $message = 'Please sign in to continue.')
{
    $_SESSION['login_error'] = $message;
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    auth_redirect_to_login('Login request is invalid.');
}

$email = trim((string) ($_POST['email'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
    auth_redirect_to_login('Please enter a valid email and password.');
}

try {
    $hasCurrentRole = map_pump_current_role_column_ready($pdo);
    $currentRoleSelect = $hasCurrentRole ? 'u.current_role_id, cr.role_name AS current_role_name,' : 'NULL AS current_role_id, NULL AS current_role_name,';
    $currentRoleJoin = $hasCurrentRole ? 'LEFT JOIN roles cr ON cr.role_id = u.current_role_id' : '';

    $stmt = $pdo->prepare(
        "SELECT u.user_id, u.user_name, u.email, u.hashed_password, u.status, u.role_id, u.business_role_id, u.license_id,
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
        auth_redirect_to_login('Unable to validate your credentials right now.');
    }

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        auth_redirect_to_login('Invalid email or password.');
    }

    if (!isset($user['hashed_password']) || !password_verify($password, $user['hashed_password'])) {
        auth_redirect_to_login('Invalid email or password.');
    }

    $status = strtolower((string) ($user['status'] ?? ''));
    $allowedStatuses = ['active', 'approved', 'enabled', 'ready'];
    if ($status !== '' && !in_array($status, $allowedStatuses, true)) {
        auth_redirect_to_login('Your account is not active. Please contact support.');
    }

    [$activeRoleId, $activeRoleName] = map_pump_session_role($user);
    $roleName = trim(strtolower($activeRoleName));

    $dashboardMap = [
        'admin' => '/php/Admin(WebOwner)/admin_dashboard.php',
        'moderator' => '/php/Moderators(UnderAdmin)/moderator_dashboard.php',
        'hq' => '/php/HQ/index.php',
        'manager' => '/php/BranchManager/dashboard.php',
        'branchmanager' => '/php/BranchManager/dashboard.php',
        'branch manager' => '/php/BranchManager/dashboard.php',
        'staff' => '/php/Staff/staff_dashboard.php',
        'customer' => '../Customer/dashboard.php',
    ];

    $destination = $dashboardMap[$roleName] ?? '/php/Customer/dashboard.php';
    $customerLicenseAccess = null;
    if ($roleName === 'customer') {
        $customerLicenseAccess = customer_license_access($pdo, (int) $user['user_id']);
        if (!$customerLicenseAccess['is_approved']) {
            $destination = '/php/Customer/license.php';
        }
    }

    $_SESSION['is_logged_in'] = true;
    $_SESSION['user_id'] = (int) $user['user_id'];
    $_SESSION['user_name'] = (string) $user['user_name'];
    $_SESSION['user_email'] = (string) $user['email'];
    $_SESSION['role_id'] = $activeRoleId;
    $_SESSION['role_name'] = $activeRoleName;
    $_SESSION['business_role_id'] = (int) ($user['business_role_id'] ?? 0);
    $_SESSION['business_role_name'] = (string) ($user['business_role_name'] ?? '');
    $_SESSION['has_business'] = !empty($user['business_role_id']);
    $_SESSION['active_profile_mode'] = ($roleName === 'customer') ? 'customer' : 'business';
    map_pump_set_current_role($pdo, (int) $user['user_id'], $activeRoleId);
    if (!empty($user['license_id'])) {
        $_SESSION['license_id'] = (int) $user['license_id'];
    }
    if ($roleName === 'customer') {
        $_SESSION['license_id'] = (int) ($customerLicenseAccess['license_id'] ?? 0);
        $_SESSION['license_status'] = (string) ($customerLicenseAccess['status'] ?? 'pending');
        $_SESSION['oil_limit'] = $_SESSION['oil_limit'] ?? 50.0;
        $_SESSION['oil_used'] = $_SESSION['oil_used'] ?? 0.0;
    }

    header('Location: ' . $destination);
    exit;
} catch (Throwable $e) {
    error_log('Login failed: ' . $e->getMessage());
    auth_redirect_to_login('Login failed. Please try again.');
}