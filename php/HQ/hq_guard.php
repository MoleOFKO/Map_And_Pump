<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../role_state.php';
require_once __DIR__ . '/../business_scope.php';

function hq_guard_redirect_top(string $url, string $message = ''): void
{
    if ($message !== '') {
        $_SESSION['login_error'] = $message;
    }

    echo '<script>window.top.location.replace(' . json_encode($url) . ');</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></noscript>';
    exit;
}

function hq_guard_dashboard_for_role(string $roleName, int $roleId): string
{
    $roleKey = strtolower(trim($roleName));
    $dashboardsById = [
        1 => '/php/Admin(WebOwner)/admin_dashboard.php',
        2 => '/php/Moderators(UnderAdmin)/moderator_dashboard.php',
        3 => '/php/HQ/index.php',
        4 => '/php/BranchManager/dashboard.php',
        5 => '/php/Staff/staff_dashboard.php',
        6 => '/php/Customer/dashboard.php',
    ];
    $dashboardsByName = [
        'admin' => '/php/Admin(WebOwner)/admin_dashboard.php',
        'moderator' => '/php/Moderators(UnderAdmin)/moderator_dashboard.php',
        'hq' => '/php/HQ/index.php',
        'manager' => '/php/BranchManager/dashboard.php',
        'branchmanager' => '/php/BranchManager/dashboard.php',
        'branch manager' => '/php/BranchManager/dashboard.php',
        'staff' => '/php/Staff/staff_dashboard.php',
        'customer' => '/php/Customer/dashboard.php',
    ];

    return $dashboardsByName[$roleKey] ?? ($dashboardsById[$roleId] ?? '/php/Customer/dashboard.php');
}

if (empty($_SESSION['is_logged_in']) || empty($_SESSION['user_id'])) {
    hq_guard_redirect_top('/php/Login/login.php', 'Please sign in to continue.');
}

$userId = (int) $_SESSION['user_id'];

try {
    $hasCurrentRole = map_pump_current_role_column_ready($pdo);
    $currentRoleSelect = $hasCurrentRole ? 'u.current_role_id, cr.role_name AS current_role_name,' : 'NULL AS current_role_id, NULL AS current_role_name,';
    $currentRoleJoin = $hasCurrentRole ? 'LEFT JOIN roles cr ON cr.role_id = u.current_role_id' : '';

    $stmt = $pdo->prepare(
        "SELECT u.user_id, u.user_name, u.email, u.role_id, u.business_role_id, u.headquarter_id,
                {$currentRoleSelect}
                r.role_name, br.role_name AS business_role_name
         FROM users u
         LEFT JOIN roles r ON r.role_id = u.role_id
         LEFT JOIN roles br ON br.role_id = u.business_role_id
         {$currentRoleJoin}
         WHERE u.user_id = ?
         LIMIT 1"
    );
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('HQ role guard failed while loading user: ' . $e->getMessage());
    $user = null;
}

if (!$user) {
    hq_guard_redirect_top('/php/Login/login.php', 'Your account could not be found. Please sign in again.');
}

[$activeRoleId, $activeRoleName] = map_pump_session_role($user);
$activeRoleKey = strtolower(trim($activeRoleName));

if (($user['status'] ?? '') === 'pending_deletion' && basename((string) ($_SERVER['PHP_SELF'] ?? '')) !== 'profile.php') {
    hq_guard_redirect_top('/php/HQ/profile.php');
}

$_SESSION['role_id'] = $activeRoleId;
$_SESSION['current_role_id'] = $activeRoleId;
$_SESSION['role_name'] = $activeRoleName;
$_SESSION['business_role_id'] = (int) ($user['business_role_id'] ?? 0);
$_SESSION['business_role_name'] = (string) ($user['business_role_name'] ?? '');
$_SESSION['has_business'] = !empty($user['business_role_id']);
$_SESSION['active_profile_mode'] = ($activeRoleKey === 'customer') ? 'customer' : 'business';
$_SESSION['user_name'] = (string) ($user['user_name'] ?? $_SESSION['user_name'] ?? '');
$_SESSION['user_email'] = (string) ($user['email'] ?? $_SESSION['user_email'] ?? '');
$_SESSION['headquarter_id'] = map_pump_current_hq_id($pdo, $userId);

if ($activeRoleId !== 3 && $activeRoleKey !== 'hq') {
    hq_guard_redirect_top(hq_guard_dashboard_for_role($activeRoleName, $activeRoleId));
}
