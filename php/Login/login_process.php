<?php
session_start();

require_once __DIR__ . '/../conn.php';

function auth_redirect_to_login(string $message = 'Please sign in to continue.')
{
    $_SESSION['login_error'] = $message;
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    auth_redirect_to_login('Login request is invalid.');
}

$email = trim((string) ($_POST['email'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
    auth_redirect_to_login('Please enter a valid email and password.');
}

try {
    $stmt = $pdo->prepare(
        'SELECT u.user_id, u.user_name, u.email, u.hashed_password, u.status, u.role_id,
                r.role_name
         FROM users u
         LEFT JOIN roles r ON r.role_id = u.role_id
         WHERE LOWER(u.email) = LOWER(?)
         LIMIT 1'
    );

    if (!$stmt || !$stmt->execute([$email])) {
        auth_redirect_to_login('Unable to validate your credentials right now.');
    }

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        auth_redirect_to_login('Invalid email or password.');
    }

    if (!isset($user['hashed_password']) || !password_verify($password, $user['hashed_password'])) {
        auth_redirect_to_login('Invalid email or password.');
    }

    $status = strtolower((string) ($user['status'] ?? ''));
    $allowedStatuses = ['active', 'approved', 'enabled', 'ready'];
    if ($status !== '' && !in_array($status, $allowedStatuses, true)) {
        auth_redirect_to_login('Your account is not active. Please contact support.');
    }

    $roleName = trim(strtolower((string) ($user['role_name'] ?? '')));

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

    $destination = $dashboardMap[$roleName] ?? '/php/Customer/dashboard.php';

    $_SESSION['is_logged_in'] = true;
    $_SESSION['user_id'] = (int) $user['user_id'];
    $_SESSION['user_name'] = (string) $user['user_name'];
    $_SESSION['user_email'] = (string) $user['email'];
    $_SESSION['role_id'] = (int) ($user['role_id'] ?? 0);
    $_SESSION['role_name'] = (string) ($user['role_name'] ?? 'customer');

    header('Location: ' . $destination);
    exit;
} catch (Throwable $e) {
    error_log('Login failed: ' . $e->getMessage());
    auth_redirect_to_login('Login failed. Please try again.');
}
