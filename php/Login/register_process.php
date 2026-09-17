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
require_once __DIR__ . '/../role_state.php';

function register_json(bool $ok, string $message, array $extra = []): never
{
    if (ob_get_length() !== false) {
        @ob_end_clean();
    }
    echo json_encode(['ok' => $ok, 'message' => $message] + $extra);
    exit;
}
function register_validate_email_domain(string $domain): bool
{
    $domain = strtolower(trim($domain));
    if ($domain === '') {
        return false;
    }

    if (function_exists('dns_get_record')) {
        $mxRecords = @dns_get_record($domain, DNS_MX);
        if (is_array($mxRecords) && !empty($mxRecords)) {
            return true;
        }
    }

    if (function_exists('checkdnsrr')) {
        if (@checkdnsrr($domain, 'MX') || @checkdnsrr($domain, 'A') || @checkdnsrr($domain, 'AAAA')) {
            return true;
        }
    }

    if (function_exists('curl_init')) {
        $requestUrl = 'https://dns.google/resolve?name=' . rawurlencode($domain) . '&type=MX';
        $ch = curl_init($requestUrl);
        if ($ch !== false) {
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'MapPump-EmailDomainCheck/1.0',
            ]);

            $payload = curl_exec($ch);
            $curlError = curl_errno($ch);
            curl_close($ch);

            if ($payload !== false && $curlError === CURLE_OK) {
                $decoded = json_decode((string) $payload, true);
                if (is_array($decoded) && ((int) ($decoded['Status'] ?? -1) === 0) && isset($decoded['Answer']) && is_array($decoded['Answer']) && !empty($decoded['Answer'])) {
                    return true;
                }
            }
        }
    }

    return false;
}

function customer_role_id(PDO $pdo): int
{
    $stmt = $pdo->prepare("SELECT role_id FROM roles WHERE LOWER(role_name) = 'customer' LIMIT 1");
    $stmt->execute();
    $roleId = $stmt->fetchColumn();
    return $roleId ? (int) $roleId : 6;
}

function register_mail_setup_message(Throwable $error): string
{
    $message = strtolower($error->getMessage());
    $provider = strtolower(mail_config_value('MAIL_PROVIDER', 'smtp'));

    if ($provider === 'resend') {
        if (trim(mail_config_value('RESEND_API_KEY')) === '' || trim(mail_config_value('RESEND_FROM_EMAIL')) === '') {
            return 'Resend is not configured on Render yet. Set RESEND_API_KEY and RESEND_FROM_EMAIL, then redeploy.';
        }

        if (str_contains($message, 'sender') || str_contains($message, 'from') || str_contains($message, 'domain')) {
            return 'Resend rejected the sender address. Verify your domain in Resend and set RESEND_FROM_EMAIL to an address from that verified domain.';
        }

        return 'Could not send the verification email through Resend. Check RESEND_API_KEY, RESEND_FROM_EMAIL, and the Resend dashboard, then redeploy.';
    }

    $username = mail_config_value('SMTP_USERNAME');
    $password = mail_config_value('SMTP_PASSWORD');

    if (trim($username) === '' || trim($password) === '') {
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

$csrfToken = (string) ($_POST['csrf_token'] ?? '');
if (empty($_SESSION['registration_csrf']) || !hash_equals((string) $_SESSION['registration_csrf'], $csrfToken)) {
    register_json(false, 'Your registration session expired. Please refresh the page and try again.');
}

$username = trim((string) ($_POST['username'] ?? ''));
$email = strtolower(trim((string) ($_POST['email'] ?? '')));
$password = (string) ($_POST['password'] ?? '');
$confirmPassword = (string) ($_POST['confirm_password'] ?? '');

if ((string) ($_POST['test_data_acknowledgement'] ?? '') !== 'yes') {
    register_json(false, 'Please confirm that you will use your email only for OTP verification and fictional data for all other fields.');
}

if ($username === '' || strlen($username) > 225) {
    register_json(false, 'Please enter a username.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    register_json(false, 'Please enter a valid email address.');
}

$domain = strtolower((string) substr(strrchr($email, '@') ?: '', 1));
if ($domain !== '') {
    if (!register_validate_email_domain($domain)) {
        register_json(false, 'Email Does Not Exist, Please Try Again');
    }
}

if ($password !== $confirmPassword) {
    register_json(false, 'Password Does Not Match, Please Try Again');
}

if (strlen($password) < 8 || strlen($password) > 15) {
    register_json(false, 'Password must be 8 to 15 characters.');
}

if (!preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password) || !preg_match('/\d/', $password) || !preg_match('/[^a-zA-Z0-9]/', $password)) {
    register_json(false, 'Password must include upper/lowercase letters, a number, and a symbol.');
}

$previousPending = $_SESSION['pending_registration'] ?? null;
if (is_array($previousPending)
    && strtolower((string) ($previousPending['email'] ?? '')) === $email
    && (int) ($previousPending['last_sent_at'] ?? 0) > time() - 60) {
    register_json(false, 'Please wait one minute before requesting another verification code.');
}

try {
    $stmt = $pdo->prepare('SELECT user_id, status FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1');
    $stmt->execute([$email]);
    $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existingUser && strtolower((string) ($existingUser['status'] ?? '')) !== 'pending_verification') {
        register_json(false, 'That email is already registered. Please log in instead.');
    }

    $roleId = customer_role_id($pdo);
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $hasCurrentRole = map_pump_current_role_column_ready($pdo);
    $userId = 0;

    if ($existingUser) {
        $userId = (int) $existingUser['user_id'];
        $updateSql = $hasCurrentRole
            ? 'UPDATE users SET user_name = ?, hashed_password = ?, role_id = ?, current_role_id = ?, status = ?, updated_at = NOW() WHERE user_id = ?'
            : 'UPDATE users SET user_name = ?, hashed_password = ?, role_id = ?, status = ?, updated_at = NOW() WHERE user_id = ?';
        $update = $pdo->prepare($updateSql);
        $updateParams = [$username, $passwordHash, $roleId];
        if ($hasCurrentRole) {
            $updateParams[] = $roleId;
        }
        $updateParams[] = 'pending_verification';
        $updateParams[] = $userId;
        $update->execute($updateParams);
    } else {
        $insertSql = $hasCurrentRole
            ? 'INSERT INTO users (user_name, email, hashed_password, role_id, current_role_id, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())'
            : 'INSERT INTO users (user_name, email, hashed_password, role_id, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())';
        $insert = $pdo->prepare($insertSql);
        $insertParams = [$username, $email, $passwordHash, $roleId];
        if ($hasCurrentRole) {
            $insertParams[] = $roleId;
        }
        $insertParams[] = 'pending_verification';
        $insert->execute($insertParams);
        $userId = (int) $pdo->lastInsertId();
    }

    $code = (string) random_int(100000, 999999);
    $_SESSION['pending_registration'] = [
        'user_id' => $userId,
        'email' => $email,
        'code_hash' => password_hash($code, PASSWORD_DEFAULT),
        'expires_at' => time() + 600,
        'attempts' => 0,
        'last_sent_at' => time(),
    ];

    try {
        send_registration_code($email, $code);
        register_json(true, 'We sent a verification code to your email.');
    } catch (Throwable $mailError) {
        error_log('Registration email failed: ' . $mailError->getMessage() . ' [' . get_class($mailError) . ']');
        register_json(false, register_mail_setup_message($mailError));
    }
} catch (Throwable $e) {
    error_log('Registration code failed: ' . $e->getMessage() . ' [' . get_class($e) . ']');
    register_json(false, 'Could not start registration. Please try again.');
}
