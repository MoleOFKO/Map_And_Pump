<?php
session_start();

require_once __DIR__ . '/social_auth.php';

$provider = strtolower(trim((string) ($_GET['provider'] ?? $_POST['provider'] ?? '')));
if ($provider !== 'google') {
    social_login_fail('Google login is the only supported social login provider.');
}

$config = social_require_config($provider);
$state = (string) ($_GET['state'] ?? $_POST['state'] ?? '');
$expectedState = (string) ($_SESSION['social_oauth_state'][$provider] ?? '');

unset($_SESSION['social_oauth_state'][$provider]);

if ($state === '' || $expectedState === '' || !hash_equals($expectedState, $state)) {
    social_login_fail('Social login session expired. Please try again.');
}

$code = (string) ($_GET['code'] ?? $_POST['code'] ?? '');
if ($code === '') {
    social_login_fail('Social login was cancelled or failed.');
}
 
try {
    $tokenRequestMethod = 'POST';
    $token = social_http_request($config['token_url'], $tokenRequestMethod, [
        'client_id' => $config['client_id'],
        'client_secret' => $config['client_secret'],
        'code' => $code,
        'grant_type' => 'authorization_code',
        'redirect_uri' => social_callback_url($provider),
    ]);

    $accessToken = (string) ($token['access_token'] ?? '');
    $idToken = (string) ($token['id_token'] ?? '');

    if ($provider === 'google') {
        $profile = social_http_request('https://openidconnect.googleapis.com/v1/userinfo', 'GET', [], [
            'Authorization: Bearer ' . $accessToken,
        ]);

        social_sign_in_user(
            $pdo,
            $provider,
            (string) ($profile['sub'] ?? ''),
            (string) ($profile['email'] ?? ''),
            (string) ($profile['name'] ?? ''),
            isset($profile['picture']) ? (string) $profile['picture'] : null
        );
    }

    social_login_fail('Unsupported login provider.');
} catch (Throwable $e) {
    // Write provider error to a private log file to avoid printing sensitive details to console.
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0700, true);
    }
    $logFile = $logDir . '/social_oauth.log';
    $entry = '[' . date('r') . '] Social login failed for provider ' . $provider . ': ' . $e->getMessage() . "\n";
    @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);

    $_SESSION['login_error'] = 'Social login failed. Please try again.';
    // If the underlying error indicates a connectivity problem, set a safe,
    // generic debug message that will be shown to the user without leaking
    // provider internals. Otherwise do not expose details.
    $err = (string) $e->getMessage();
    if (stripos($err, 'Unable to contact login provider') !== false) {
        $_SESSION['social_login_debug'] = 'Unable to contact login provider.';
    } else {
        unset($_SESSION['social_login_debug']);
    }
    social_login_fail('Social login failed. Please try again.');
}
