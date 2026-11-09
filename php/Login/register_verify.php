<?php
session_start();
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

ob_start();
set_error_handler(function (int $severity, string $message, string $file, int $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        @ob_end_clean();
        echo json_encode(['ok' => false, 'message' => 'Server error. Please try again later.']);
    }
});

define('MAP_PUMP_JSON_RESPONSE', true);
require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/mail_templates.php';
require_once __DIR__ . '/../role_state.php';

function verify_json(bool $ok, string $message, array $extra = []): never
{
    if (ob_get_length() !== false) {
        @ob_end_clean();
    }
    echo json_encode(['ok' => $ok, 'message' => $message] + $extra);
    exit;
}

function customer_role_id(PDO $pdo): int
{
    $stmt = $pdo->prepare("SELECT role_id FROM roles WHERE LOWER(role_name) = 'customer' LIMIT 1");
    $stmt->execute();
    $roleId = $stmt->fetchColumn();
    return $roleId ? (int) $roleId : 6;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    verify_json(false, 'Verification request is invalid.');
}

$csrfToken = (string) ($_POST['csrf_token'] ?? '');
if (empty($_SESSION['registration_csrf']) || !hash_equals((string) $_SESSION['registration_csrf'], $csrfToken)) {
    verify_json(false, 'Your verification session expired. Please refresh the page and try again.');
}

$pending = $_SESSION['pending_registration'] ?? null;
if (!is_array($pending) || empty($pending['user_id'])) {
    verify_json(false, 'Your verification session expired. Please register again.');
}

if ((int) ($pending['expires_at'] ?? 0) < time()) {
    unset($_SESSION['pending_registration']);
    verify_json(false, 'The verification code expired. Please register again.');
}

$code = preg_replace('/\D+/', '', (string) ($_POST['code'] ?? ''));
if (strlen($code) !== 6) {
    verify_json(false, 'Please enter the 6-digit code.');
}

$pending['attempts'] = (int) ($pending['attempts'] ?? 0) + 1;
$_SESSION['pending_registration'] = $pending;

if ($pending['attempts'] > 5) {
    unset($_SESSION['pending_registration']);
    verify_json(false, 'Too many wrong attempts. Please register again.');
}

if (!password_verify($code, (string) ($pending['code_hash'] ?? ''))) {
    verify_json(false, 'The verification code is incorrect.');
}

try {
    $userId = (int) $pending['user_id'];
    $email = (string) ($pending['email'] ?? '');

    $hasCurrentRole = map_pump_current_role_column_ready($pdo);
    $currentRoleSelect = $hasCurrentRole ? 'current_role_id,' : 'NULL AS current_role_id,';
    $stmt = $pdo->prepare(
        "SELECT user_id, user_public_id, user_name, email, role_id, business_role_id,
            license_id, {$currentRoleSelect} status
         FROM users
         WHERE user_id = ? AND LOWER(email) = LOWER(?)
         LIMIT 1"
    );
    $stmt->execute([$userId, $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        unset($_SESSION['pending_registration']);
        verify_json(false, 'Your verification session expired. Please register again.');
    }

    if (strtolower((string) ($user['status'] ?? '')) !== 'pending_verification') {
        unset($_SESSION['pending_registration']);
        verify_json(false, 'That email is already registered. Please log in instead.');
    }

    $pendingStaffLink = $_SESSION['pending_staff_link'] ?? null;
    if (is_array($pendingStaffLink)) {
        if ((int) ($pendingStaffLink['user_id'] ?? 0) !== $userId) {
            verify_json(false, 'The staff account link does not match this registration. Please restart the process.');
        }

        $pdo->beginTransaction();
        $staffStmt = $pdo->prepare('SELECT staff_id, branch_id FROM staff WHERE staff_id = ? AND user_id IS NULL AND is_active = 1 LIMIT 1 FOR UPDATE');
        $staffStmt->execute([(int) ($pendingStaffLink['staff_id'] ?? 0)]);
        $staffRecord = $staffStmt->fetch(PDO::FETCH_ASSOC);
        if (!$staffRecord || (int) $staffRecord['branch_id'] !== (int) ($pendingStaffLink['branch_id'] ?? 0)) {
            throw new RuntimeException('The standalone staff assignment is no longer available.');
        }

        $branchId = (int) $staffRecord['branch_id'];
        $hqStmt = $pdo->prepare('SELECT headquarter_id FROM station_business_scope WHERE station_id = ? LIMIT 1');
        $hqStmt->execute([$branchId]);
        $headquarterId = (int) ($hqStmt->fetchColumn() ?: 0);
        if ($headquarterId <= 0) {
            throw new RuntimeException('The staff branch is not linked to a Headquarter.');
        }

        $staffRoleStmt = $pdo->query("SELECT role_id FROM roles WHERE LOWER(TRIM(role_name)) IN ('staff', 'pump attendant', 'pumpattendant') ORDER BY role_id ASC LIMIT 1");
        $staffRoleId = (int) ($staffRoleStmt ? $staffRoleStmt->fetchColumn() : 0);
        if ($staffRoleId <= 0) {
            throw new RuntimeException('The Staff role is not configured.');
        }

        $customerRoleId = customer_role_id($pdo);
        $userUpdateSql = 'UPDATE users SET role_id = ?, business_role_id = ?, status = ?, updated_at = NOW() WHERE user_id = ?';
        $userUpdateParams = [$customerRoleId, $staffRoleId, 'active', $userId];
        if ($hasCurrentRole) {
            $userUpdateSql = 'UPDATE users SET role_id = ?, current_role_id = ?, business_role_id = ?, status = ?, updated_at = NOW() WHERE user_id = ?';
            $userUpdateParams = [$customerRoleId, $staffRoleId, $staffRoleId, 'active', $userId];
        }
        $pdo->prepare($userUpdateSql)->execute($userUpdateParams);

        $scope = $pdo->prepare(
            "INSERT INTO business_account_scope
                (user_id, business_role_id, headquarter_id, branch_manager_user_id, station_id, status)
             VALUES (?, ?, ?, NULL, ?, 'active')
             ON DUPLICATE KEY UPDATE business_role_id = VALUES(business_role_id), headquarter_id = VALUES(headquarter_id), station_id = VALUES(station_id), status = 'active'"
        );
        $scope->execute([$userId, $staffRoleId, $headquarterId, $branchId]);

        $linkStaff = $pdo->prepare('UPDATE staff SET user_id = ?, email = ?, is_active = 1, updated_at = NOW() WHERE staff_id = ? AND user_id IS NULL');
        $linkStaff->execute([$userId, $email, (int) $staffRecord['staff_id']]);
        if ($linkStaff->rowCount() !== 1) {
            throw new RuntimeException('The staff account could not be linked.');
        }

        $lastLoginUpdate = $pdo->prepare('UPDATE users SET last_login_at = NOW(), updated_at = NOW() WHERE user_id = ?');
        $lastLoginUpdate->execute([$userId]);
        $pdo->commit();

        mail_send_account_notice($email, [
            'kind' => 'account',
            'action' => 'created',
            'account_label' => (string) ($user['user_name'] ?? ''),
            'effect' => 'Your account was verified and linked to your staff assignment. Customer license details are not copied and can be submitted separately if you use the Customer profile.',
        ]);

        unset($_SESSION['pending_registration'], $_SESSION['pending_staff_link']);
        session_regenerate_id(true);
        $_SESSION['is_logged_in'] = true;
        $_SESSION['user_id'] = $userId;
        $_SESSION['staff_id'] = (int) $staffRecord['staff_id'];
        unset($_SESSION['standalone_staff_id']);
        $_SESSION['user_public_id'] = (string) ($user['user_public_id'] ?? '');
        $_SESSION['user_name'] = (string) $user['user_name'];
        $_SESSION['user_email'] = (string) $email;
        $_SESSION['user_status'] = 'active';
        $_SESSION['role_id'] = $staffRoleId;
        $_SESSION['role_name'] = 'Staff';
        $_SESSION['current_role_id'] = $staffRoleId;
        $_SESSION['current_role_name'] = 'Staff';
        $_SESSION['business_role_id'] = $staffRoleId;
        $_SESSION['business_role_name'] = 'Staff';
        $_SESSION['headquarter_id'] = $headquarterId;
        $_SESSION['branch_id'] = $branchId;
        $_SESSION['license_id'] = 0;
        $_SESSION['has_business'] = true;
        $_SESSION['active_profile_mode'] = 'business';
        $_SESSION['remember_me'] = false;
        $_SESSION['last_login_at'] = date('Y-m-d H:i:s');
        map_pump_set_current_role($pdo, $userId, $staffRoleId);
        verify_json(true, 'Account verified and linked to your staff assignment.', [
            'redirect' => '../Staff/staff_dashboard.php',
        ]);
    }

    $roleId = customer_role_id($pdo);
    $updateSql = $hasCurrentRole
        ? 'UPDATE users SET role_id = ?, current_role_id = ?, status = ?, updated_at = NOW() WHERE user_id = ?'
        : 'UPDATE users SET role_id = ?, status = ?, updated_at = NOW() WHERE user_id = ?';
    $update = $pdo->prepare($updateSql);
    $updateParams = [$roleId];
    if ($hasCurrentRole) {
        $updateParams[] = $roleId;
    }
    $updateParams[] = 'active';
    $updateParams[] = $userId;
    $update->execute($updateParams);
    $lastLoginUpdate = $pdo->prepare('UPDATE users SET last_login_at = NOW(), updated_at = NOW() WHERE user_id = ?');
    $lastLoginUpdate->execute([$userId]);

    mail_send_account_notice($email, [
        'kind' => 'account',
        'action' => 'created',
        'account_label' => (string) ($user['user_name'] ?? ''),
        'effect' => 'Your Map & Pump account was created successfully. Your account is active, and you can continue by adding your driver license and any required car license details.',
    ]);

    unset($_SESSION['pending_registration']);
    session_regenerate_id(true);
    $_SESSION['is_logged_in'] = true;
    $_SESSION['user_id'] = $userId;
    $_SESSION['user_public_id'] = (string) ($user['user_public_id'] ?? '');
    $_SESSION['user_name'] = (string) $user['user_name'];
    $_SESSION['user_email'] = (string) $user['email'];
    $_SESSION['user_status'] = 'active';
    $_SESSION['role_id'] = $roleId;
    $_SESSION['role_name'] = 'customer';
    $_SESSION['current_role_id'] = $roleId;
    $_SESSION['current_role_name'] = 'customer';
    $_SESSION['business_role_id'] = (int) ($user['business_role_id'] ?? 0);
    $_SESSION['business_role_name'] = '';
    $_SESSION['headquarter_id'] = 0;
    $_SESSION['branch_id'] = 0;
    $_SESSION['license_id'] = (int) ($user['license_id'] ?? 0);
    $_SESSION['oil_limit'] = 35.0;
    $_SESSION['oil_used'] = 0.0;
    $_SESSION['oil_remaining'] = 35.0;
    $_SESSION['has_business'] = false;
    $_SESSION['active_profile_mode'] = 'customer';
    $_SESSION['remember_me'] = false;
    $_SESSION['last_login_at'] = date('Y-m-d H:i:s');
    verify_json(true, 'Registration complete. Please add your driver license.', [
        'redirect' => '../Customer/license.php',
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Registration verify failed: ' . $e->getMessage());
    verify_json(false, 'Could not create your account. Please try again.');
}
