<?php

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../Customer/license_access.php';
require_once __DIR__ . '/../Customer/car_access.php';
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

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $requestHeaders),
            'content' => $method === 'POST' ? $content : '',
            'ignore_errors' => true,
            'timeout' => 20,
        ],
    ]);

    $fullUrl = $method === 'GET' && $content !== '' ? $url . (str_contains($url, '?') ? '&' : '?') . $content : $url;
    $response = @file_get_contents($fullUrl, false, $context);

    if ($response === false) {
        throw new RuntimeException('Unable to contact login provider.');
    }

    $decoded = json_decode($response, true);
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
            'client_id' => '513039511125-mlrd4m0lqn87radhimv0ms0vhtpvm9mh.apps.googleusercontent.com',
            'client_secret' => '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',
            'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url' => 'https://oauth2.googleapis.com/token',
            'scope' => 'openid email profile',
        ],
        'facebook' => [
            'client_id' => '1398149002263202',
            'client_secret' => 'b4d9b22007f8cb40ca1a70da6db0c832',
            'auth_url' => 'https://www.facebook.com/v20.0/dialog/oauth',
            'token_url' => 'https://graph.facebook.com/v20.0/oauth/access_token',
            'scope' => 'email public_profile',
        ],
        'apple' => [
            'client_id' => social_env('APPLE_CLIENT_ID'),
            'client_secret' => social_env('APPLE_CLIENT_SECRET'),
            'auth_url' => 'https://appleid.apple.com/auth/authorize',
            'token_url' => 'https://appleid.apple.com/auth/token',
            'scope' => 'name email',
        ],
        default => [],
    };
}

function social_require_config(string $provider): array
{
    $config = social_provider_config($provider);
    if (!$config || $config['client_id'] === '' || $config['client_secret'] === '') {
        social_login_fail(ucfirst($provider) . ' login is not configured yet.');
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
    $stmt = $pdo->prepare(
        'SELECT u.user_id, u.user_name, u.email, u.status, u.role_id, u.business_role_id, u.current_role_id,
                r.role_name, br.role_name AS business_role_name, cr.role_name AS current_role_name
         FROM users u
         LEFT JOIN roles r ON r.role_id = u.role_id
         LEFT JOIN roles br ON br.role_id = u.business_role_id
         LEFT JOIN roles cr ON cr.role_id = u.current_role_id
         WHERE (u.oauth_provider = ? AND u.oauth_provider_id = ?)
            OR LOWER(u.email) = LOWER(?)
         LIMIT 1'
    );
    $stmt->execute([$provider, $providerId, $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $update = $pdo->prepare(
            'UPDATE users
             SET oauth_provider = ?, oauth_provider_id = ?, oauth_avatar_url = ?, last_login_at = NOW(), updated_at = NOW()
             WHERE user_id = ?'
        );
        $update->execute([$provider, $providerId, $avatarUrl, (int) $user['user_id']]);
    } else {
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

    $status = strtolower((string) ($user['status'] ?? ''));
    if ($status !== '' && !in_array($status, ['active', 'approved', 'enabled', 'ready'], true)) {
        social_login_fail('Your account is not active. Please contact support.');
    }

    $_SESSION['is_logged_in'] = true;
    $_SESSION['user_id'] = (int) $user['user_id'];
    $_SESSION['user_name'] = (string) $user['user_name'];
    $_SESSION['user_email'] = (string) $user['email'];
    [$activeRoleId, $activeRoleName] = map_pump_session_role($user);
    $_SESSION['role_id'] = $activeRoleId;
    $_SESSION['role_name'] = $activeRoleName;
    $_SESSION['business_role_id'] = (int) ($user['business_role_id'] ?? 0);
    $_SESSION['business_role_name'] = (string) ($user['business_role_name'] ?? '');
    $_SESSION['has_business'] = !empty($user['business_role_id']);
    $_SESSION['active_profile_mode'] = strtolower($activeRoleName) === 'customer' ? 'customer' : 'business';
    map_pump_set_current_role($pdo, (int) $user['user_id'], $activeRoleId);

    $roleName = strtolower($activeRoleName);
    $destination = social_dashboard_for_role($activeRoleName);
    if ($roleName === 'customer') {
        $licenseAccess = customer_license_access($pdo, (int) $user['user_id']);
        customer_refresh_session($pdo, (int) $user['user_id'], $licenseAccess);
        if (!$licenseAccess['is_approved']) {
            $destination = '/php/Customer/license.php';
        }
    }

    header('Location: ' . $destination);
    exit;
}
