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

$pending = $_SESSION['pending_registration'] ?? null;
if (!is_array($pending) || empty($pending['user_id'])) {
    verify_json(false, 'Your verification session expired. Please register again.');
}

if ((int) ($pending['expires_at'] ?? 0) < time()) {
    unset($_SESSION['pending_registration']);
    verify_json(false, 'The verification code expired. Please register again.');
}

$code = preg_replace('/\D+/', '', (string) ($_POST['code'] ?? ''));
if (strlen($code) !== 4) {
    verify_json(false, 'Please enter the 4-digit code.');
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
                license_id, headquarter_id, branch_id, {$currentRoleSelect} status
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

    unset($_SESSION['pending_registration']);
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
    $_SESSION['headquarter_id'] = (int) ($user['headquarter_id'] ?? 0);
    $_SESSION['branch_id'] = (int) ($user['branch_id'] ?? 0);
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
    error_log('Registration verify failed: ' . $e->getMessage());
    verify_json(false, 'Could not create your account. Please try again.');
}
