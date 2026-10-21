<?php
/**
 * Self-service account deactivation for authenticated non-Customer portals.
 * The target is always the current session user; no user id is accepted from
 * the request. Customer and HQ retain their existing, role-specific flows.
 */
require_once __DIR__ . '/Login/session_helper.php';
map_pump_configure_session();
session_start();
require_once __DIR__ . '/conn.php';

const MAP_PUMP_SELF_DELETE_RETURNS = [
    '/php/Admin(WebOwner)/edit_profile.php',
    '/php/Moderators(UnderAdmin)/edit_profile(M).php',
    '/php/Staff/staff_account.php',
    '/php/BranchManager/edit-profile.php',
];

function self_delete_return_path(): string
{
    $returnTo = (string) ($_POST['return_to'] ?? '');
    return in_array($returnTo, MAP_PUMP_SELF_DELETE_RETURNS, true)
        ? $returnTo
        : '/php/Login/login.php';
}

function self_delete_fail(string $message): never
{
    $_SESSION['account_deletion_flash'] = $message;
    header('Location: ' . self_delete_return_path());
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['is_logged_in']) || empty($_SESSION['user_id'])) {
    header('Location: /php/Login/login.php');
    exit;
}

if (trim((string) ($_POST['confirmation'] ?? '')) !== 'DELETE' || ($_POST['confirm_deletion'] ?? '') !== 'yes') {
    self_delete_fail('To deactivate your account, type DELETE and confirm the checkbox.');
}

$userId = (int) $_SESSION['user_id'];

try {
    $userQuery = $pdo->prepare(
        'SELECT u.user_id, u.email, u.status, u.role_id, u.business_role_id, u.current_role_id,
                primary_role.role_name AS primary_role_name,
                business_role.role_name AS business_role_name,
                active_role.role_name AS active_role_name
         FROM users u
         LEFT JOIN roles primary_role ON primary_role.role_id = u.role_id
         LEFT JOIN roles business_role ON business_role.role_id = u.business_role_id
         LEFT JOIN roles active_role ON active_role.role_id = u.current_role_id
         WHERE u.user_id = ? LIMIT 1'
    );
    $userQuery->execute([$userId]);
    $user = $userQuery->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        self_delete_fail('Your account could not be found. Please sign in again.');
    }

    // HQ owners use the separate 30-day recovery flow in HQ/Profile. Do not
    // allow that safeguard to be bypassed through this generic endpoint.
    if (in_array(3, [
        (int) ($user['role_id'] ?? 0),
        (int) ($user['business_role_id'] ?? 0),
        (int) ($user['current_role_id'] ?? 0),
    ], true)) {
        self_delete_fail('HQ accounts must use the 30-day deletion request on the HQ profile page.');
    }

    $roles = array_map(
        static fn (?string $role): string => strtolower(trim((string) $role)),
        [$user['primary_role_name'] ?? '', $user['business_role_name'] ?? '', $user['active_role_name'] ?? '']
    );
    $isAdmin = in_array('admin', $roles, true);
    if (in_array('staff', $roles, true)) {
        self_delete_fail('Staff accounts can only be deleted by their branch manager.');
    }

    if ($isAdmin) {
        $otherAdminCount = $pdo->query(
            "SELECT COUNT(*) FROM users u
             LEFT JOIN roles primary_role ON primary_role.role_id = u.role_id
             LEFT JOIN roles business_role ON business_role.role_id = u.business_role_id
             LEFT JOIN roles active_role ON active_role.role_id = u.current_role_id
             WHERE u.user_id <> " . $userId . "
               AND LOWER(u.status) IN ('approved', 'active')
               AND (LOWER(primary_role.role_name) = 'admin'
                    OR LOWER(business_role.role_name) = 'admin'
                    OR LOWER(active_role.role_name) = 'admin')"
        );
        if ((int) $otherAdminCount->fetchColumn() < 1) {
            self_delete_fail('The last active Admin account cannot be deactivated. Create or restore another Admin first.');
        }
    }

    if (strtolower((string) $user['status']) === 'disabled') {
        self_delete_fail('This account is already deactivated.');
    }

    $disable = $pdo->prepare("UPDATE users SET status = 'disabled', updated_at = NOW() WHERE user_id = ?");
    $disable->execute([$userId]);
    if ($disable->rowCount() !== 1) {
        throw new RuntimeException('The account status could not be changed.');
    }

    map_pump_destroy_login_session();
    map_pump_configure_session();
    session_start();
    session_regenerate_id(true);
    $_SESSION['login_success'] = 'Your account has been deactivated.';
    $_SESSION['login_prefill_email'] = (string) $user['email'];
    header('Location: /php/Login/login.php');
    exit;
} catch (Throwable $exception) {
    error_log('[Account self-deletion] ' . $exception->getMessage());
    self_delete_fail('Your account could not be deactivated right now. Please try again.');
}
