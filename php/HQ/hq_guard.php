<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../role_state.php';
require_once __DIR__ . '/../business_scope.php';
require_once __DIR__ . '/../payment_transfers.php';

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

if (!payment_transfer_ensure_hq_service_expiry_schema($pdo)) {
    error_log('HQ service period could not be checked because its schema is unavailable.');
}

$userId = (int) $_SESSION['user_id'];

try {
    $hasCurrentRole = map_pump_current_role_column_ready($pdo);
    $currentRoleSelect = $hasCurrentRole ? 'u.current_role_id, cr.role_name AS current_role_name,' : 'NULL AS current_role_id, NULL AS current_role_name,';
    $currentRoleJoin = $hasCurrentRole ? 'LEFT JOIN roles cr ON cr.role_id = u.current_role_id' : '';

    $stmt = $pdo->prepare(
        "SELECT u.user_id, u.user_name, u.email, u.role_id, u.business_role_id, u.headquarter_id, u.status,
            h.expire_at, h.business_license_expire_date, h.service_expires_at, h.status AS headquarter_status,
                {$currentRoleSelect}
                r.role_name, br.role_name AS business_role_name
         FROM users u
         LEFT JOIN headquarter h ON h.headquarter_id = u.headquarter_id
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
$userStatus = strtolower(trim((string) ($user['status'] ?? '')));
$hqActivationPending = strtolower(trim((string) ($user['headquarter_status'] ?? ''))) === 'pending';
$_SESSION['hq_activation_pending'] = $hqActivationPending;
$licenseExpiry = trim((string) ($user['business_license_expire_date'] ?? ''));
if ($licenseExpiry === '') {
    $licenseExpiry = trim((string) ($user['expire_at'] ?? ''));
}
$expiryTimestamp = $licenseExpiry !== '' ? strtotime($licenseExpiry) : false;
if ($expiryTimestamp !== false && date('Y-m-d', $expiryTimestamp) < date('Y-m-d')
    && in_array($userStatus, ['active', 'approved', 'enabled', 'ready'], true)) {
    try {
        $expireOwner = $pdo->prepare("UPDATE users SET status = 'expired', updated_at = NOW() WHERE user_id = ?");
        $expireOwner->execute([$userId]);
        $userStatus = 'expired';
    } catch (Throwable $e) {
        error_log('HQ license expiry status update failed: ' . $e->getMessage());
    }
}

$_SESSION['user_status'] = $userStatus;
$hqAccessRestricted = in_array($userStatus, ['expired', 'pending'], true) || $hqActivationPending;

$hqLicenseExpired = false;
$hqHeadquarterId = (int) ($user['headquarter_id'] ?? 0);
if ($hqHeadquarterId > 0 && ($activeRoleId === 3 || strtolower(trim($activeRoleName)) === 'hq')) {
    try {
        $hqStmt = $pdo->prepare('SELECT expire_at, service_expires_at FROM headquarter WHERE headquarter_id = ? LIMIT 1');
        if ($hqStmt && $hqStmt->execute([$hqHeadquarterId])) {
            $hqRow = $hqStmt->fetch(PDO::FETCH_ASSOC);
            $serviceExpiry = trim((string) ($hqRow['service_expires_at'] ?? ''));
            if ($serviceExpiry !== '') {
                $serviceExpiryDate = new DateTimeImmutable($serviceExpiry);
                $hqLicenseExpired = $serviceExpiryDate < new DateTimeImmutable();
            } else {
                $expireAt = trim((string) ($hqRow['expire_at'] ?? ''));
                if ($expireAt !== '') {
                $expireDate = DateTimeImmutable::createFromFormat('!Y-m-d', $expireAt);
                if ($expireDate && $expireDate->format('Y-m-d') === $expireAt) {
                        $hqLicenseExpired = $expireDate < new DateTimeImmutable('today');
                    }
                }
            }
        }
    } catch (Throwable $e) {
        error_log('HQ license guard failed while checking expire_at: ' . $e->getMessage());
    }
}

if (($user['status'] ?? '') === 'pending_deletion' && basename((string) ($_SERVER['PHP_SELF'] ?? '')) !== 'profile.php') {
    hq_guard_redirect_top('/php/HQ/profile.php');
}
$hqPage = basename((string) ($_SERVER['PHP_SELF'] ?? ''));
if ($hqActivationPending && !in_array($hqPage, ['index.php', 'profile.php'], true)) {
    hq_guard_redirect_top('/php/HQ/index.php', 'Your HQ is pending Admin or Moderator activation.');
}
if ($hqLicenseExpired && !in_array(basename((string) ($_SERVER['PHP_SELF'] ?? '')), ['profile.php', 'extend_period.php'], true)) {
    hq_guard_redirect_top('/php/HQ/profile.php', 'Your HQ service period or business license has expired. Access is limited until it is renewed.');
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

if ($hqAccessRestricted) {
    $page = basename((string) ($_SERVER['PHP_SELF'] ?? ''));
    if (!in_array($page, ['index.php', 'brand.php', 'profile.php', 'extend_period.php'], true)) {
        hq_guard_redirect_top('/php/HQ/brand.php');
    }
}
