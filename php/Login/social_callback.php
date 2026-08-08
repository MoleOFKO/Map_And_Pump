<?php
session_start();

require_once __DIR__ . '/social_auth.php';

$provider = strtolower(trim((string) ($_GET['provider'] ?? $_POST['provider'] ?? '')));
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
    $token = social_http_request($config['token_url'], 'POST', [
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

    if ($provider === 'facebook') {
        $profile = social_http_request('https://graph.facebook.com/me', 'GET', [
            'fields' => 'id,name,email,picture.type(large)',
            'access_token' => $accessToken,
        ]);

        $avatarUrl = $profile['picture']['data']['url'] ?? null;

        social_sign_in_user(
            $pdo,
            $provider,
            (string) ($profile['id'] ?? ''),
            (string) ($profile['email'] ?? ''),
            (string) ($profile['name'] ?? ''),
            is_string($avatarUrl) ? $avatarUrl : null
        );
    }

    if ($provider === 'apple') {
        $payload = social_decode_jwt_payload($idToken);
        $now = time();

        if (($payload['iss'] ?? '') !== 'https://appleid.apple.com') {
            throw new RuntimeException('Apple identity token issuer is invalid.');
        }
        if (($payload['aud'] ?? '') !== $config['client_id']) {
            throw new RuntimeException('Apple identity token audience is invalid.');
        }
        if ((int) ($payload['exp'] ?? 0) < $now) {
            throw new RuntimeException('Apple identity token has expired.');
        }

        $name = '';
        if (isset($_POST['user'])) {
            $appleUser = json_decode((string) $_POST['user'], true);
            if (is_array($appleUser)) {
                $firstName = (string) ($appleUser['name']['firstName'] ?? '');
                $lastName = (string) ($appleUser['name']['lastName'] ?? '');
                $name = trim($firstName . ' ' . $lastName);
            }
        }

        social_sign_in_user(
            $pdo,
            $provider,
            (string) ($payload['sub'] ?? ''),
            (string) ($payload['email'] ?? ''),
            $name
        );
    }

    social_login_fail('Unsupported login provider.');
} catch (Throwable $e) {
    error_log('Social login failed: ' . $e->getMessage());
    social_login_fail('Social login failed. Please try again.');
}
