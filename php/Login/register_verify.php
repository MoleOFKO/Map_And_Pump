<?php
session_start();
ini_set('display_errors', '0');
header('Content-Type: application/json');

define('MAP_PUMP_JSON_RESPONSE', true);
require_once __DIR__ . '/../conn.php';

function verify_json(bool $ok, string $message, array $extra = []): never
{
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
if (!is_array($pending)) {
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
    $email = (string) $pending['email'];
    $stmt = $pdo->prepare('SELECT user_id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1');
    $stmt->execute([$email]);

    if ($stmt->fetchColumn()) {
        unset($_SESSION['pending_registration']);
        verify_json(false, 'That email is already registered. Please log in instead.');
    }

    $insert = $pdo->prepare(
        'INSERT INTO users (user_name, email, hashed_password, role_id, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
    );
    $insert->execute([
        (string) $pending['username'],
        $email,
        (string) $pending['password_hash'],
        customer_role_id($pdo),
        'active',
    ]);

    unset($_SESSION['pending_registration']);
    $_SESSION['login_success'] = 'Registration complete. You can log in now.';
    verify_json(true, 'Registration complete.');
} catch (Throwable $e) {
    error_log('Registration verify failed: ' . $e->getMessage());
    verify_json(false, 'Could not create your account. Please try again.');
}
