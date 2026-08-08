<?php
session_start();

require_once __DIR__ . '/social_auth.php';

$provider = strtolower(trim((string) ($_GET['provider'] ?? '')));
$config = social_require_config($provider);

$state = bin2hex(random_bytes(32));
$_SESSION['social_oauth_state'][$provider] = $state;

$params = [
    'client_id' => $config['client_id'],
    'redirect_uri' => social_callback_url($provider),
    'response_type' => 'code',
    'scope' => $config['scope'],
    'state' => $state,
];

if ($provider === 'google') {
    $params['access_type'] = 'online';
    $params['prompt'] = 'select_account';
}

if ($provider === 'apple') {
    $params['response_mode'] = 'form_post';
}

header('Location: ' . $config['auth_url'] . '?' . http_build_query($params));
exit;
