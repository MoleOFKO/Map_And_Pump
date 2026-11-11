<?php

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../Customer/license_access.php';
require_once __DIR__ . '/../role_state.php';

function social_login_fail(string $message): never
{
    $_SESSION['login_error'] = $message;
    header('Location: login.php');
    exit;
}

function social_env(string $key): string
{
    $value = getenv($key);
    return is_string($value) ? trim($value) : '';
}

function social_base_url(): string
{
    $configured = rtrim(social_env('APP_BASE_URL'), '/');
    if ($configured !== '') {
        return $configured;
    }

    $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    $forwardedHost = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? '';

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    if ($forwardedProto !== '') {
        $scheme = strtolower(trim(explode(',', $forwardedProto)[0]));
        if (!in_array($scheme, ['http', 'https'], true)) {
            $scheme = 'https';
        }
    }

    $host = $forwardedHost !== '' ? trim(explode(',', $forwardedHost)[0]) : ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
    $host = trim($host);

    return $scheme . '://' . $host;
}

function social_callback_url(string $provider): string
{
    return social_base_url() . '/php/Login/social_callback.php?provider=' . rawurlencode($provider);
}

function social_http_request(string $url, string $method = 'GET', array $data = [], array $headers = []): array
{
    $method = strtoupper($method);
    $content = $data ? http_build_query($data) : '';
    $requestHeaders = $headers;

    if ($method === 'POST') {
        $requestHeaders[] = 'Content-Type: application/x-www-form-urlencoded';
    }

    // Prefer curl when available for better SSL and error handling
    if (function_exists('curl_init')) {
        $ch = curl_init();
        $requestUrl = $url;
        if ($method === 'GET' && $content !== '') {
            $requestUrl .= (str_contains($requestUrl, '?') ? '&' : '?') . $content;
        }

        curl_setopt($ch, CURLOPT_URL, $requestUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');

        if (defined('CURLOPT_IPRESOLVE')) {
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        }

        // Bypass local CA certificate verification issues on localhost
        $isLocalhost = str_contains($_SERVER['HTTP_HOST'] ?? '', 'localhost') 
                    || in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);

        if ($isLocalhost) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        } else {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
        // Respect system proxy environment variables if present (e.g., in corp networks)
        $proxy = getenv('HTTPS_PROXY') ?: getenv('https_proxy') ?: getenv('HTTP_PROXY') ?: getenv('http_proxy') ?: getenv('ALL_PROXY') ?: getenv('all_proxy');
        if ($proxy) {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
            curl_setopt($ch, CURLOPT_PROXYTYPE, stripos($proxy, 'socks5') === 0 ? CURLPROXY_SOCKS5_HOSTNAME : CURLPROXY_HTTP);
        }

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
        }

        $response = curl_exec_universal_vpn($ch);
        if ($response === false) {
            $curlErr = curl_error($ch);
            throw new RuntimeException('Unable to contact login provider: ' . $curlErr);
        }

        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // curl_close($ch);

        if ($httpStatus >= 400) {
            // Try to extract JSON error message
            $json = json_decode((string) $response, true);
            if (is_array($json) && isset($json['error'])) {
                $detail = is_array($json['error']) ? ($json['error']['message'] ?? 'Provider rejected the request.') : (string) $json['error'];
                throw new RuntimeException($detail);
            }
            throw new RuntimeException('Login provider returned HTTP ' . $httpStatus . '.');
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Login provider returned an invalid response.');
        }

        if (isset($decoded['error'])) {
            $detail = is_array($decoded['error']) ? ($decoded['error']['message'] ?? 'Provider rejected the request.') : (string) $decoded['error'];
            throw new RuntimeException($detail);
        }

        return $decoded;
    }

    // Fallback to file_get_contents with diagnostic info
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $requestHeaders),
            'content' => $method === 'POST' ? $content : '',
            'ignore_errors' => true,
            'timeout' => 20,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
        ],
    ]);

    $fullUrl = $method === 'GET' && $content !== '' ? $url . (str_contains($url, '?') ? '&' : '?') . $content : $url;
    $response = @file_get_contents($fullUrl, false, $context);

    if ($response === false) {
        $lastErr = error_get_last();
        if (function_exists('http_get_last_response_headers')) {
            $hdrArr = http_get_last_response_headers() ?: [];
        } else {
            // Avoid referencing the deprecated $http_response_header variable
            $hdrArr = [];
        }
        $hdrs = is_array($hdrArr) ? implode(' | ', $hdrArr) : '';
        $msg = 'Unable to contact login provider.' . ($lastErr ? ' ' . ($lastErr['message'] ?? '') : '') . ($hdrs ? ' Headers: ' . $hdrs : '');
        throw new RuntimeException($msg);
    }

    $decoded = json_decode((string) $response, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Login provider returned an invalid response.');
    }

    if (isset($decoded['error'])) {
        $detail = is_array($decoded['error']) ? ($decoded['error']['message'] ?? 'Provider rejected the request.') : (string) $decoded['error'];
        throw new RuntimeException($detail);
    }

    return $decoded;
}

function social_decode_jwt_payload(string $jwt): array
{
    $parts = explode('.', $jwt);
    if (count($parts) < 2) {
        throw new RuntimeException('Provider identity token is invalid.');
    }

    $payload = strtr($parts[1], '-_', '+/');
    $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
    $decoded = json_decode((string) base64_decode($payload), true);

    if (!is_array($decoded)) {
        throw new RuntimeException('Provider identity token could not be read.');
    }

    return $decoded;
}

function social_provider_config(string $provider): array
{
    return match ($provider) {
        'google' => [
            'client_id' =>  '517003577398-r239g2nh7h46upug007bov7a0cp2jm8o.apps.googleusercontent.com',
            'client_secret' => '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',
            'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url' => 'https://oauth2.googleapis.com/token',
            'scope' => 'openid email profile',
        ],
        default => [],
    };
}

function social_require_config(string $provider): array
{
    if ($provider !== 'google') {
        social_login_fail('Google login is the only supported social login provider.');
    }

    $config = social_provider_config($provider);
    if (!$config || $config['client_id'] === '' || $config['client_secret'] === '') {
        social_login_fail('Google login is not configured yet.');
    }

    return $config;
}

function social_find_customer_role_id(PDO $pdo): int
{
    $stmt = $pdo->prepare("SELECT role_id FROM roles WHERE LOWER(role_name) = 'customer' LIMIT 1");
    $stmt->execute();
    $roleId = $stmt->fetchColumn();
    return $roleId ? (int) $roleId : 6;
}

function social_dashboard_for_role(string $roleName): string
{
    $dashboardMap = [
        'admin' => '/php/Admin(WebOwner)/admin_dashboard.php',
        'moderator' => '/php/Moderators(UnderAdmin)/moderator_dashboard.php',
        'hq' => '/php/HQ/dashboard.php',
        'headquarter' => '/php/HQ/dashboard.php',
        'headquarters' => '/php/HQ/dashboard.php',
        'manager' => '/php/BranchManager/dashboard.php',
        'branch manager' => '/php/BranchManager/dashboard.php',
        'branch_manager' => '/php/BranchManager/dashboard.php',
        'staff' => '/php/Staff/staff_dashboard.php',
        'station staff' => '/php/Staff/staff_dashboard.php',
        'customer' => '/php/Customer/dashboard.php',
        'user' => '/php/Customer/dashboard.php',
    ];

    return $dashboardMap[strtolower(trim($roleName))] ?? '/php/Customer/dashboard.php';
}

function social_sign_in_user(PDO $pdo, string $provider, string $providerId, string $email, string $name, ?string $avatarUrl = null): never
{
    if ($providerId === '') {
        social_login_fail('Your social account did not provide a valid account id.');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        social_login_fail('Your social account did not provide a valid email address.');
    }

    map_pump_current_role_column_ready($pdo);
    $userSelect = 'SELECT u.user_id, u.user_name, u.email, u.status, u.role_id, u.business_role_id, u.current_role_id,
                r.role_name, br.role_name AS business_role_name, cr.role_name AS current_role_name
         FROM users u
         LEFT JOIN roles r ON r.role_id = u.role_id
         LEFT JOIN roles br ON br.role_id = u.business_role_id
         LEFT JOIN roles cr ON cr.role_id = u.current_role_id';

    $stmt = $pdo->prepare(
        $userSelect . '
         WHERE u.oauth_provider = ? AND u.oauth_provider_id = ?
         LIMIT 1'
    );
    $stmt->execute([$provider, $providerId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $update = $pdo->prepare(
            'UPDATE users
             SET oauth_avatar_url = ?, last_login_at = NOW(), updated_at = NOW()
             WHERE user_id = ?'
        );
        $update->execute([$avatarUrl, (int) $user['user_id']]);
    } else {
        $emailStmt = $pdo->prepare(
            $userSelect . '
             WHERE LOWER(u.email) = LOWER(?)
             LIMIT 1'
        );
        $emailStmt->execute([$email]);
        if ($emailStmt->fetch(PDO::FETCH_ASSOC)) {
            social_login_fail('That email is already registered. Please log in instead.');
        }

        $roleId = social_find_customer_role_id($pdo);
        $fallbackName = explode('@', $email)[0] ?: 'Social User';
        $displayName = trim($name) !== '' ? trim($name) : $fallbackName;
        $randomPassword = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

        $insert = $pdo->prepare(
            'INSERT INTO users
                (user_name, email, hashed_password, role_id, current_role_id, status, oauth_provider, oauth_provider_id, oauth_avatar_url, last_login_at, created_at, updated_at)
             VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())'
        );
        $insert->execute([$displayName, $email, $randomPassword, $roleId, $roleId, 'active', $provider, $providerId, $avatarUrl]);

        $stmt = $pdo->prepare(
            'SELECT u.user_id, u.user_name, u.email, u.status, u.role_id, u.business_role_id, u.current_role_id,
                    r.role_name, br.role_name AS business_role_name, cr.role_name AS current_role_name
             FROM users u
             LEFT JOIN roles r ON r.role_id = u.role_id
             LEFT JOIN roles br ON br.role_id = u.business_role_id
             LEFT JOIN roles cr ON cr.role_id = u.current_role_id
             WHERE u.user_id = ?
             LIMIT 1'
        );
        $stmt->execute([(int) $pdo->lastInsertId()]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$user) {
        social_login_fail('Unable to create your account right now.');
    }

    [$activeRoleId, $activeRoleName] = map_pump_session_role($user);
    $roleName = strtolower(trim($activeRoleName));
    $isCustomerLogin = $roleName === 'customer';
    $status = strtolower(trim((string) ($user['status'] ?? '')));
    $blockedStatuses = $isCustomerLogin
        ? ['banned', 'disabled', 'deleted', 'removed']
        : ['disabled', 'banned', 'suspended', 'deleted', 'removed'];
    if (in_array($status, $blockedStatuses, true)) {
        social_login_fail('This account is disabled or banned. Please contact support.');
    }
    if (!$isCustomerLogin && $status !== '' && !in_array($status, ['active', 'approved', 'enabled', 'ready', 'pending'], true)) {
        social_login_fail('Your account is not active. Please contact support.');
    }

    $_SESSION['is_logged_in'] = true;
    $_SESSION['user_id'] = (int) $user['user_id'];
    $_SESSION['user_name'] = (string) $user['user_name'];
    $_SESSION['user_email'] = (string) $user['email'];
    $_SESSION['role_id'] = $activeRoleId;
    $_SESSION['role_name'] = $activeRoleName;
    $_SESSION['business_role_id'] = (int) ($user['business_role_id'] ?? 0);
    $_SESSION['business_role_name'] = (string) ($user['business_role_name'] ?? '');
    $_SESSION['has_business'] = !empty($user['business_role_id']);
    $_SESSION['active_profile_mode'] = strtolower($activeRoleName) === 'customer' ? 'customer' : 'business';
    map_pump_set_current_role($pdo, (int) $user['user_id'], $activeRoleId);

    $destination = social_dashboard_for_role($activeRoleName);
    if ($roleName === 'customer') {
        $licenseAccess = customer_license_access($pdo, (int) $user['user_id']);
        $_SESSION['license_id'] = (int) ($licenseAccess['license_id'] ?? 0);
        $_SESSION['license_status'] = (string) ($licenseAccess['status'] ?? 'pending');
        if (!$licenseAccess['is_approved']) {
            $destination = '../Customer/license.php';
        }
    }

    header('Location: ' . $destination);
    exit;
}

function social_local_proxy_candidates(): array
{
    $urls = [];

    // Check system environment variables first
    foreach (['HTTPS_PROXY', 'https_proxy', 'HTTP_PROXY', 'http_proxy', 'ALL_PROXY', 'all_proxy'] as $key) {
        $proxy = getenv($key);
        if (is_string($proxy) && trim($proxy) !== '') {
            $urls[] = trim($proxy);
        }
    }

    // Common local VPN proxy candidate tuples
    $knownLocal = [
        ['host' => '127.0.0.1', 'port' => 7890,  'type' => CURLPROXY_HTTP],
        ['host' => '127.0.0.1', 'port' => 10808, 'type' => CURLPROXY_SOCKS5_HOSTNAME],
        ['host' => '127.0.0.1', 'port' => 10809, 'type' => CURLPROXY_HTTP],
        ['host' => '127.0.0.1', 'port' => 1080,  'type' => CURLPROXY_SOCKS5_HOSTNAME],
        ['host' => '127.0.0.1', 'port' => 8080,  'type' => CURLPROXY_HTTP],
        ['host' => '127.0.0.1', 'port' => 8888,  'type' => CURLPROXY_HTTP],
        ['host' => '127.0.0.1', 'port' => 9050,  'type' => CURLPROXY_SOCKS5_HOSTNAME],
    ];

    foreach ($knownLocal as $item) {
        $fp = @fsockopen($item['host'], $item['port'], $errno, $errstr, 0.2);
        if ($fp !== false) {
            fclose($fp);
            $urls[] = $item;
        }
    }

    return $urls;
}

function curl_exec_universal_vpn($ch)
{
    if (!is_resource($ch) && !($ch instanceof CURLHandle)) {
        return false;
    }

    $isLocalhost = str_contains($_SERVER['HTTP_HOST'] ?? '', 'localhost') 
        || in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);

    // Fast initial connect timeout on localhost to quickly trigger proxy fallback
    $initialConnectTimeout = $isLocalhost ? 3 : 10;

    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $initialConnectTimeout);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    // Disable SSL verification on localhost to prevent proxy TLS handshake failures
    if ($isLocalhost) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }

    $envProxy = getenv('HTTPS_PROXY') ?: getenv('https_proxy') ?: getenv('HTTP_PROXY') ?: getenv('http_proxy') ?: getenv('ALL_PROXY') ?: getenv('all_proxy');
    if (is_string($envProxy) && trim($envProxy) !== '') {
        curl_setopt($ch, CURLOPT_PROXY, trim($envProxy));
    }

    // Attempt 1: Direct or Environment Execution
    $response = curl_exec($ch);
    if ($response !== false && curl_errno($ch) === CURLE_OK) {
        return $response;
    }

    // Attempt Fallbacks via Local Proxies
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    $proxyCandidates = social_local_proxy_candidates();

    foreach ($proxyCandidates as $candidate) {
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
        curl_setopt($ch, CURLOPT_FORBID_REUSE, true);

        if (is_string($candidate)) {
            curl_setopt($ch, CURLOPT_PROXY, $candidate);
        } else if (is_array($candidate)) {
            curl_setopt($ch, CURLOPT_PROXY, $candidate['host'] . ':' . $candidate['port']);
            curl_setopt($ch, CURLOPT_PROXYTYPE, $candidate['type']);
        }

        $response = curl_exec($ch);
        if ($response !== false && curl_errno($ch) === CURLE_OK) {
            return $response;
        }
    }

    return false;
}
