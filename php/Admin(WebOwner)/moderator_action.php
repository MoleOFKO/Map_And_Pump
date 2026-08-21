<?php
require_once __DIR__ . '/moderator_common.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    moderator_redirect();
}

if (!moderator_schema_ready()) {
    moderator_flash('danger', 'The moderator registry is temporarily unavailable because the required database tables or columns could not be found.');
    moderator_redirect();
}

global $conn;
$action = strtolower(trim((string) ($_POST['action'] ?? '')));

function moderator_clean_user_name(string $value): string
{
    return trim(preg_replace('/\s+/', ' ', $value) ?? '');
}

function moderator_validate_profile(string $userName, string $email, int $roleId): ?string
{
    if ($userName === '' || strlen($userName) > 225) {
        return 'Please provide a moderator username or display name no longer than 225 characters.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
        return 'Please provide a valid email address no longer than 255 characters.';
    }

    if (!moderator_role_is_allowed($roleId)) {
        return 'Please select a valid Admin or Moderator role from the database.';
    }

    return null;
}

function moderator_duplicate_exists(string $userName, string $email, ?int $excludeUserId = null): bool
{
    global $conn;

    $sql = 'SELECT user_id FROM users WHERE (user_name = ? OR email = ?)';
    if ($excludeUserId !== null) {
        $sql .= ' AND user_id <> ?';
    }
    $sql .= ' LIMIT 1';

    $statement = $conn->prepare($sql);
    if (!$statement) {
        return true;
    }

    if ($excludeUserId === null) {
        $statement->bind_param('ss', $userName, $email);
    } else {
        $statement->bind_param('ssi', $userName, $email, $excludeUserId);
    }

    $success = $statement->execute();
    $exists = $success && (bool) ($statement->get_result()?->fetch_assoc());
    $statement->close();

    return $exists;
}

function moderator_target_exists(int $userId): bool
{
    global $conn;

    $statement = $conn->prepare(
        "SELECT u.user_id
         FROM users u
         JOIN roles r ON r.role_id = u.role_id
         WHERE u.user_id = ?
           AND u.headquarter_id IS NULL
           AND LOWER(TRIM(r.role_name)) IN ('admin', 'moderator')
         LIMIT 1"
    );
    if (!$statement) {
        return false;
    }
    $statement->bind_param('i', $userId);
    $success = $statement->execute();
    $exists = $success && (bool) ($statement->get_result()?->fetch_assoc());
    $statement->close();

    return $exists;
}

if ($action === 'create') {
    $userName = moderator_clean_user_name((string) ($_POST['user_name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $roleId = (int) ($_POST['role_id'] ?? 0);
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    $validationError = moderator_validate_profile($userName, $email, $roleId);
    if ($validationError !== null) {
        moderator_flash('danger', $validationError);
        moderator_redirect();
    }
    if (strlen($password) < 8 || strlen($password) > 255) {
        moderator_flash('danger', 'The moderator password must contain between 8 and 255 characters.');
        moderator_redirect();
    }
    if (!hash_equals($password, $confirmPassword)) {
        moderator_flash('danger', 'The password and confirmation password do not match.');
        moderator_redirect();
    }
    if (moderator_duplicate_exists($userName, $email)) {
        moderator_flash('danger', 'That username or email address is already registered.');
        moderator_redirect();
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $statement = $conn->prepare(
        "INSERT INTO users
            (user_name, email, hashed_password, role_id, current_role_id, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, 'approved', NOW(), NOW())"
    );
    if (!$statement) {
        moderator_flash('danger', 'The moderator account could not be prepared for insertion.');
        moderator_redirect();
    }

    $statement->bind_param('sssii', $userName, $email, $passwordHash, $roleId, $roleId);
    if (!$statement->execute()) {
        $error = $statement->error;
        $statement->close();
        error_log('[Moderator registry] Create failed: ' . $error);
        moderator_flash('danger', 'The moderator account could not be created. No partial record was saved.');
        moderator_redirect();
    }
    $newUserId = (int) $statement->insert_id;
    $statement->close();

    moderator_flash('success', 'Moderator access was created successfully.', ['user_id' => $newUserId]);
    moderator_redirect();
}

$userId = (int) ($_POST['user_id'] ?? 0);
if ($userId <= 0 || !moderator_target_exists($userId)) {
    moderator_flash('danger', 'The selected system moderator account could not be found.');
    moderator_redirect();
}

if ($action === 'update_profile') {
    $userName = moderator_clean_user_name((string) ($_POST['user_name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $roleId = (int) ($_POST['role_id'] ?? 0);
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    $validationError = moderator_validate_profile($userName, $email, $roleId);
    if ($validationError !== null) {
        moderator_flash('danger', $validationError);
        moderator_redirect();
    }
    if (moderator_duplicate_exists($userName, $email, $userId)) {
        moderator_flash('danger', 'That username or email address is already registered to another account.');
        moderator_redirect();
    }

    if ($password !== '' || $confirmPassword !== '') {
        if (strlen($password) < 8 || strlen($password) > 255) {
            moderator_flash('danger', 'A replacement password must contain between 8 and 255 characters.');
            moderator_redirect();
        }
        if (!hash_equals($password, $confirmPassword)) {
            moderator_flash('danger', 'The replacement password and confirmation password do not match.');
            moderator_redirect();
        }
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $statement = $conn->prepare(
            'UPDATE users SET user_name = ?, email = ?, role_id = ?, current_role_id = ?, hashed_password = ?, updated_at = NOW() WHERE user_id = ? AND headquarter_id IS NULL'
        );
        if ($statement) {
            $statement->bind_param('ssiisi', $userName, $email, $roleId, $roleId, $passwordHash, $userId);
        }
    } else {
        $statement = $conn->prepare(
            'UPDATE users SET user_name = ?, email = ?, role_id = ?, current_role_id = ?, updated_at = NOW() WHERE user_id = ? AND headquarter_id IS NULL'
        );
        if ($statement) {
            $statement->bind_param('ssiii', $userName, $email, $roleId, $roleId, $userId);
        }
    }

    if (!$statement || !$statement->execute()) {
        $error = $statement?->error ?? $conn->error;
        $statement?->close();
        error_log('[Moderator registry] Profile update failed: ' . $error);
        moderator_flash('danger', 'The moderator profile could not be updated.');
        moderator_redirect();
    }
    $statement->close();
    moderator_flash('success', 'The moderator profile was updated successfully.');
    moderator_redirect();
}

if ($action === 'update_status') {
    $newStatus = strtolower(trim((string) ($_POST['status'] ?? '')));
    $allowedStatuses = ['approved', 'suspended', 'removed'];
    if (!in_array($newStatus, $allowedStatuses, true)) {
        moderator_flash('danger', 'Please select a valid moderator account status.');
        moderator_redirect();
    }

    $statement = $conn->prepare(
        'UPDATE users SET status = ?, updated_at = NOW() WHERE user_id = ? AND headquarter_id IS NULL'
    );
    if (!$statement) {
        moderator_flash('danger', 'The moderator status could not be updated.');
        moderator_redirect();
    }
    $statement->bind_param('si', $newStatus, $userId);
    if (!$statement->execute()) {
        $error = $statement->error;
        $statement->close();
        error_log('[Moderator registry] Status update failed: ' . $error);
        moderator_flash('danger', 'The moderator status could not be updated.');
        moderator_redirect();
    }
    $statement->close();
    moderator_flash('success', 'The moderator status was changed to ' . moderator_status_label($newStatus) . '.');
    moderator_redirect();
}

moderator_flash('danger', 'The requested moderator action is not supported.');
moderator_redirect();
