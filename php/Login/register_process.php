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
function register_is_local_request(): bool
{
    if (filter_var(mail_config_value('RENDER', 'false'), FILTER_VALIDATE_BOOLEAN)) {
        return false;
    }

    $appEnv = strtolower(mail_config_value('APP_ENV', mail_config_value('ENVIRONMENT', '')));
    if (in_array($appEnv, ['production', 'prod'], true)) {
        return false;
    }

    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host) ?? $host;
    $remoteAddr = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
        || in_array($remoteAddr, ['127.0.0.1', '::1'], true)
        || str_ends_with($host, '.test')
        || str_ends_with($host, '.local');
}

function register_local_mail_fallback_enabled(): bool
{
    $configured = mail_config_value('MAIL_LOCAL_FALLBACK', 'false');

    return register_is_local_request()
        && filter_var($configured, FILTER_VALIDATE_BOOLEAN);
}

function register_mail_setup_message(Throwable $error): string
{
    $message = strtolower($error->getMessage());
    $username = mail_config_value('SMTP_USERNAME');
    $password = mail_config_value('SMTP_PASSWORD');
    $provider = strtolower(mail_config_value('MAIL_PROVIDER', 'auto'));

    if ($provider === 'resend' || str_contains($message, 'resend')) {
        if (mail_config_value('RESEND_API_KEY') === '') {
            return 'Resend is selected, but RESEND_API_KEY is missing in Render. Add your new Resend API key, then redeploy.';
        }

        if (mail_config_value('RESEND_FROM_EMAIL') === '') {
            return 'Resend is selected, but RESEND_FROM_EMAIL is missing in Render. Use an address on your verified domain, for example noreply@map-and-pump.online.';
        }

        if (str_contains($message, 'not verified') || str_contains($message, 'domain') || str_contains($message, 'sender') || str_contains($message, 'from')) {
            return 'Resend rejected the sender email. Verify map-and-pump.online in Resend DNS, then set RESEND_FROM_EMAIL to an address on that exact verified domain, for example noreply@map-and-pump.online.';
        }

        return 'Resend email is not configured correctly. Check RESEND_API_KEY, RESEND_FROM_EMAIL, and the verified domain in Resend, then redeploy.';
    }

    if (($provider === 'smtp' || $provider === 'auto') && (trim($username) === '' || trim($password) === '')) {
        return 'Email is not configured on Render yet. Set SMTP_USERNAME to mapa.and.pump@gmail.com and set SMTP_PASSWORD to a Gmail App Password, then redeploy.';
    }

    if (str_contains($message, 'username and password not accepted') || str_contains($message, 'authenticate') || str_contains($message, 'authentication failed')) {
        return 'Gmail rejected the SMTP login. In Render, SMTP_PASSWORD must be a 16-character Gmail App Password for mapa.and.pump@gmail.com, not the normal Gmail password.';
    }

    if (str_contains($message, 'could not connect') || str_contains($message, 'connection refused') || str_contains($message, 'timed out')) {
        return 'The site could not connect to the mail server. Check SMTP_HOST=smtp.gmail.com and SMTP_PORT=587 in Render, then redeploy.';
    }

    if (str_contains($message, 'sender') || str_contains($message, 'from')) {
        return 'The sender email is not accepted. Set SMTP_USERNAME and SMTP_FROM_EMAIL to the same Gmail account: mapa.and.pump@gmail.com.';
    }

    return 'Could not send the verification email. Check Render environment variables for SMTP_USERNAME, SMTP_PASSWORD, SMTP_FROM_EMAIL, SMTP_HOST, and SMTP_PORT, then redeploy.';
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

    try {
        send_registration_code($email, $code);
        register_json(true, 'We sent a verification code to your email.');
    } catch (Throwable $mailError) {
        error_log('Registration email failed: ' . $mailError->getMessage() . ' [' . get_class($mailError) . ']');

        if (!register_local_mail_fallback_enabled()) {
            register_json(false, register_mail_setup_message($mailError));
        }

        error_log(sprintf('[Map&Pump local registration] Verification code for %s: %s', $email, $code));
        register_json(true, 'Localhost mode: email could not be sent, so use this verification code: ' . $code, [
            'dev_code' => $code,
            'mail_warning' => 'SMTP failed. This fallback only works on localhost.',
        ]);
    }
} catch (Throwable $e) {
    error_log('Registration code failed: ' . $e->getMessage() . ' [' . get_class($e) . ']');
    register_json(false, 'Could not start registration. Please try again.');
}
