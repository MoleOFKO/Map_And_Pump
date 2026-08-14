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
require_once __DIR__ . '/mailer.php';

function register_json(bool $ok, string $message, array $extra = []): never
{
    if (ob_get_length() !== false) {
        @ob_end_clean();
    }
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
    error_log('Registration code failed: ' . $e->getMessage() . ' [' . get_class($e) . ']');
    register_json(false, 'Could not send the verification email. Please check mail settings and try again.');
}
