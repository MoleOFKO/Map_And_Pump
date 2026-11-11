<?php
require_once __DIR__ . '/hq_guard.php';
require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../Login/mailer.php';
require_once __DIR__ . '/../Login/mail_templates.php';
require_once __DIR__ . '/../role_state.php';

header('Content-Type: application/json; charset=utf-8');

function hq_customer_link_respond(bool $ok, string $message, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(['ok' => $ok, 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['is_logged_in'])) {
    hq_customer_link_respond(false, 'Please sign in again before linking an account.', 401);
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$headquarterId = (int) ($_SESSION['headquarter_id'] ?? 0);
$csrfToken = (string) ($_POST['csrf_token'] ?? '');
if (($userId <= 0 && empty($_SESSION['hq_auth'])) || $headquarterId <= 0 || empty($_SESSION['hq_customer_link_csrf'])
    || !hash_equals((string) $_SESSION['hq_customer_link_csrf'], $csrfToken)) {
    hq_customer_link_respond(false, 'Your session expired. Refresh the page and try again.', 403);
}

try {
$ownerCheck = !empty($_SESSION['hq_auth'])
    ? $pdo->prepare('SELECT 1 FROM headquarter WHERE headquarter_id = ? AND headquarters_owner_id IS NULL LIMIT 1')
    : $pdo->prepare('SELECT 1 FROM headquarter WHERE headquarter_id = ? AND headquarters_owner_id = ? LIMIT 1');
$ownerCheck->execute(!empty($_SESSION['hq_auth']) ? [$headquarterId] : [$headquarterId, $userId]);
if (!$ownerCheck->fetchColumn()) {
    hq_customer_link_respond(false, 'Only the HQ account owner can link a customer account.', 403);
}

$customerRoleStmt = $pdo->query("SELECT role_id FROM roles WHERE LOWER(TRIM(role_name)) = 'customer' LIMIT 1");
$customerRoleId = (int) ($customerRoleStmt->fetchColumn() ?: 0);
if ($customerRoleId <= 0) {
    hq_customer_link_respond(false, 'The Customer role is unavailable.', 503);
}
if ($userId > 0) {
    $ownerCustomerRole = $pdo->prepare('SELECT role_id FROM users WHERE user_id = ? LIMIT 1');
    $ownerCustomerRole->execute([$userId]);
    if ((int) $ownerCustomerRole->fetchColumn() === $customerRoleId) {
        hq_customer_link_respond(false, 'This HQ owner account already has a customer profile.', 409);
    }
}
$linkedCustomerCheck = $pdo->prepare(
    'SELECT 1 FROM business_account_scope WHERE headquarter_id = ? AND business_role_id = ? LIMIT 1'
);
$linkedCustomerCheck->execute([$headquarterId, $customerRoleId]);
if ($linkedCustomerCheck->fetchColumn()) {
    hq_customer_link_respond(false, 'A customer account is already linked to this HQ.', 409);
}

$action = trim((string) ($_POST['action'] ?? ''));
$now = time();
$pending = $_SESSION['hq_customer_link_otp'] ?? null;

    if ($action === 'send_existing_code') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            hq_customer_link_respond(false, 'Enter a valid customer account email address.', 422);
        }

        if (is_array($pending) && (int) ($pending['last_sent_at'] ?? 0) > $now - 60) {
            hq_customer_link_respond(false, 'Please wait one minute before requesting another code.', 429);
        }

        $accountStmt = $pdo->prepare(
            "SELECT u.user_id
             FROM users u
             WHERE LOWER(TRIM(u.email)) = ?
               AND u.role_id = ?
               AND COALESCE(u.business_role_id, 0) = 0
               AND LOWER(TRIM(u.status)) IN ('active', 'approved', 'license_expired')
               AND NOT EXISTS (
                   SELECT 1 FROM business_account_scope bas WHERE bas.user_id = u.user_id
               )
               AND NOT EXISTS (
                   SELECT 1 FROM headquarter owned_hq WHERE owned_hq.headquarters_owner_id = u.user_id
               )
             LIMIT 1"
        );
        $accountStmt->execute([$email, $customerRoleId]);
        $targetUserId = (int) ($accountStmt->fetchColumn() ?: 0);
        if ($targetUserId <= 0 || $targetUserId === $userId) {
            hq_customer_link_respond(false, 'No eligible, unlinked customer account was found for that email.', 404);
        }

        $code = (string) random_int(100000, 999999);
        send_registration_code($email, $code);
        $_SESSION['hq_customer_link_otp'] = [
            'kind' => 'existing',
            'headquarter_id' => $headquarterId,
            'user_id' => $targetUserId,
            'email' => $email,
            'code_hash' => password_hash($code, PASSWORD_DEFAULT),
            'expires_at' => $now + 600,
            'attempts' => 0,
            'last_sent_at' => $now,
        ];
        hq_customer_link_respond(true, 'A 6-digit verification code was sent to that customer account email.');
    }

    if ($action === 'start_new_registration') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if ($username === '' || strlen($username) > 225) {
            hq_customer_link_respond(false, 'Enter a username no longer than 225 characters.', 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            hq_customer_link_respond(false, 'Enter a valid email address.', 422);
        }
        if ($password !== $confirmPassword) {
            hq_customer_link_respond(false, 'Passwords do not match.', 422);
        }
        if (strlen($password) < 8 || strlen($password) > 15
            || !preg_match('/[a-z]/', $password)
            || !preg_match('/[A-Z]/', $password)
            || !preg_match('/\d/', $password)
            || !preg_match('/[^a-zA-Z0-9]/', $password)) {
            hq_customer_link_respond(false, 'Password must be 8 to 15 characters and include upper/lowercase letters, a number, and a symbol.', 422);
        }
        if ((string) ($_POST['test_data_acknowledgement'] ?? '') !== 'yes') {
            hq_customer_link_respond(false, 'Confirm the school-demo privacy notice to continue.', 422);
        }
        if (is_array($pending) && (int) ($pending['last_sent_at'] ?? 0) > $now - 60) {
            hq_customer_link_respond(false, 'Please wait one minute before requesting another code.', 429);
        }

        $existingStmt = $pdo->prepare('SELECT 1 FROM users WHERE LOWER(TRIM(email)) = ? LIMIT 1');
        $existingStmt->execute([$email]);
        if ($existingStmt->fetchColumn()) {
            hq_customer_link_respond(false, 'That email is already registered. Link the existing customer account instead.', 409);
        }

        $code = (string) random_int(100000, 999999);
        send_registration_code($email, $code);
        $_SESSION['hq_customer_link_otp'] = [
            'kind' => 'new',
            'headquarter_id' => $headquarterId,
            'username' => $username,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'code_hash' => password_hash($code, PASSWORD_DEFAULT),
            'expires_at' => $now + 600,
            'attempts' => 0,
            'last_sent_at' => $now,
        ];
        hq_customer_link_respond(true, 'A 6-digit verification code was sent. Enter it to create and link the customer account.');
    }

    if ($action === 'verify_code') {
        if (!is_array($pending)
            || (int) ($pending['headquarter_id'] ?? 0) !== $headquarterId
            || (int) ($pending['expires_at'] ?? 0) < $now) {
            unset($_SESSION['hq_customer_link_otp']);
            hq_customer_link_respond(false, 'The verification code expired. Request a new code.', 422);
        }

        $pending['attempts'] = (int) ($pending['attempts'] ?? 0) + 1;
        if ($pending['attempts'] > 5) {
            unset($_SESSION['hq_customer_link_otp']);
            hq_customer_link_respond(false, 'Too many incorrect attempts. Request a new code.', 429);
        }
        $_SESSION['hq_customer_link_otp'] = $pending;

        $code = preg_replace('/\D+/', '', (string) ($_POST['code'] ?? ''));
        if (strlen($code) !== 6 || !password_verify($code, (string) ($pending['code_hash'] ?? ''))) {
            hq_customer_link_respond(false, 'The verification code is incorrect.', 422);
        }

        $hasCurrentRole = ($pending['kind'] ?? '') === 'new'
            ? map_pump_current_role_column_ready($pdo)
            : false;
        $pdo->beginTransaction();
        $ownerCheck = !empty($_SESSION['hq_auth'])
            ? $pdo->prepare('SELECT 1 FROM headquarter WHERE headquarter_id = ? AND headquarters_owner_id IS NULL FOR UPDATE')
            : $pdo->prepare('SELECT 1 FROM headquarter WHERE headquarter_id = ? AND headquarters_owner_id = ? FOR UPDATE');
        $ownerCheck->execute(!empty($_SESSION['hq_auth']) ? [$headquarterId] : [$headquarterId, $userId]);
        if (!$ownerCheck->fetchColumn()) {
            throw new RuntimeException('The HQ owner changed before the account could be linked.');
        }
        $linkedCustomerCheck = $pdo->prepare(
            'SELECT 1 FROM business_account_scope WHERE headquarter_id = ? AND business_role_id = ? LIMIT 1 FOR UPDATE'
        );
        $linkedCustomerCheck->execute([$headquarterId, $customerRoleId]);
        if ($linkedCustomerCheck->fetchColumn()) {
            throw new RuntimeException('A customer account has already been linked to this HQ.');
        }

        if (($pending['kind'] ?? '') === 'existing') {
            $targetStmt = $pdo->prepare(
                "SELECT u.user_id
                 FROM users u
                 WHERE u.user_id = ? AND LOWER(TRIM(u.email)) = ? AND u.role_id = ?
                   AND COALESCE(u.business_role_id, 0) = 0
                   AND LOWER(TRIM(u.status)) IN ('active', 'approved', 'license_expired')
                   AND NOT EXISTS (
                       SELECT 1 FROM business_account_scope bas WHERE bas.user_id = u.user_id
                   )
                   AND NOT EXISTS (
                       SELECT 1 FROM headquarter owned_hq WHERE owned_hq.headquarters_owner_id = u.user_id
                   )
                 FOR UPDATE"
            );
            $targetStmt->execute([(int) ($pending['user_id'] ?? 0), (string) ($pending['email'] ?? ''), $customerRoleId]);
            $targetUserId = (int) ($targetStmt->fetchColumn() ?: 0);
            if ($targetUserId <= 0 || $targetUserId === $userId) {
                throw new RuntimeException('The customer account is no longer eligible to link.');
            }
        } elseif (($pending['kind'] ?? '') === 'new') {
            $duplicate = $pdo->prepare('SELECT 1 FROM users WHERE LOWER(TRIM(email)) = ? LIMIT 1 FOR UPDATE');
            $duplicate->execute([(string) ($pending['email'] ?? '')]);
            if ($duplicate->fetchColumn()) {
                throw new RuntimeException('That email was registered while verification was in progress. Link the existing account instead.');
            }

            $userInsert = $hasCurrentRole
                ? 'INSERT INTO users (user_name, email, hashed_password, role_id, current_role_id, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, \'active\', NOW(), NOW())'
                : 'INSERT INTO users (user_name, email, hashed_password, role_id, status, created_at, updated_at) VALUES (?, ?, ?, ?, \'active\', NOW(), NOW())';
            $insert = $pdo->prepare($userInsert);
            $insertParams = [
                (string) $pending['username'],
                (string) $pending['email'],
                (string) $pending['password_hash'],
                $customerRoleId,
            ];
            if ($hasCurrentRole) {
                $insertParams[] = $customerRoleId;
            }
            $insert->execute($insertParams);
            $targetUserId = (int) $pdo->lastInsertId();
        } else {
            throw new RuntimeException('The customer account verification request is invalid.');
        }

        $scopeInsert = $pdo->prepare(
            "INSERT INTO business_account_scope
                (user_id, business_role_id, headquarter_id, branch_manager_user_id, station_id, status)
             VALUES (?, ?, ?, NULL, NULL, 'active')"
        );
        $scopeInsert->execute([$targetUserId, $customerRoleId, $headquarterId]);
        $pdo->commit();

        if (($pending['kind'] ?? '') === 'new') {
            try {
                mail_send_account_notice((string) $pending['email'], [
                    'kind' => 'account',
                    'action' => 'created',
                    'account_label' => (string) $pending['username'],
                    'effect' => 'Your Map & Pump customer account was created and linked to an HQ. Sign in with the email address and password you chose.',
                ]);
            } catch (Throwable $mailError) {
                error_log('Linked customer account welcome email failed: ' . $mailError->getMessage());
            }
        }

        unset($_SESSION['hq_customer_link_otp']);
        hq_customer_link_respond(true, 'The customer account was verified and linked to this HQ.');
    }

    hq_customer_link_respond(false, 'Invalid account-linking action.', 400);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('HQ customer account linking failed: ' . $error->getMessage());
    hq_customer_link_respond(false, $error instanceof RuntimeException
        ? $error->getMessage()
        : 'The customer account could not be linked. Please try again.', 500);
}
