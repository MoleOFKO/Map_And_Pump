<?php
session_start();
ini_set('display_errors', '0');
header('Content-Type: application/json');

define('MAP_PUMP_JSON_RESPONSE', true);
require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/mailer.php';

function register_json(bool $ok, string $message, array $extra = []): never
{
    echo json_encode(['ok' => $ok, 'message' => $message] + $extra);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    register_json(false, 'Registration request is invalid.');
}

$username = trim((string) ($_POST['username'] ?? ''));
$email = strtolower(trim((string) ($_POST['email'] ?? '')));
$password = (string) ($_POST['password'] ?? '');
$confirmPassword = (string) ($_POST['confirm_password'] ?? '');

if ($username === '' || strlen($username) > 225) {
    register_json(false, 'Please enter a username.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    register_json(false, 'Please enter a valid email address.');
}

if ($password !== $confirmPassword) {
    register_json(false, 'Passwords do not match.');
}

if (strlen($password) < 8 || strlen($password) > 15) {
    register_json(false, 'Password must be 8 to 15 characters.');
}

if (!preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password) || !preg_match('/\d/', $password) || !preg_match('/[^a-zA-Z0-9]/', $password)) {
    register_json(false, 'Password must include upper/lowercase letters, a number, and a symbol.');
}

try {
    $stmt = $pdo->prepare('SELECT user_id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1');
    $stmt->execute([$email]);

    if ($stmt->fetchColumn()) {
        register_json(false, 'That email is already registered. Please log in instead.');
    }

    $code = (string) random_int(1000, 9999);
    $_SESSION['pending_registration'] = [
        'username' => $username,
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'code_hash' => password_hash($code, PASSWORD_DEFAULT),
        'expires_at' => time() + 600,
        'attempts' => 0,
    ];

    send_registration_code($email, $code);
    register_json(true, 'We sent a verification code to your email.');
} catch (Throwable $e) {
    error_log('Registration code failed: ' . $e->getMessage());
    register_json(false, 'Could not send the verification email. Please check mail settings and try again.');
}
