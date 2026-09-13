<?php
require_once __DIR__ . '/moderator_common.php';
require_once __DIR__ . '/admin_common.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Create / hire / update / suspend moderators is admin-only.
$adminUser = admin_require_admin();

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
        return 'Please select the Moderator role from the database.';
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
         LEFT JOIN roles r ON r.role_id = u.role_id
         LEFT JOIN roles br ON br.role_id = u.business_role_id
         LEFT JOIN roles cr ON cr.role_id = u.current_role_id
         WHERE u.user_id = ?
           AND u.headquarter_id IS NULL
           AND (
                LOWER(TRIM(COALESCE(r.role_name, ''))) = 'moderator'
                OR LOWER(TRIM(COALESCE(br.role_name, ''))) = 'moderator'
                OR LOWER(TRIM(COALESCE(cr.role_name, ''))) = 'moderator'
           )
           AND LOWER(TRIM(COALESCE(r.role_name, ''))) <> 'admin'
           AND LOWER(TRIM(COALESCE(br.role_name, ''))) <> 'admin'
           AND LOWER(TRIM(COALESCE(cr.role_name, ''))) <> 'admin'
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

    admin_record_log((int) $adminUser['user_id'], 'Created moderator account #' . $newUserId . '.');

    moderator_flash('success', 'Moderator access was created successfully.', ['user_id' => $newUserId]);
    moderator_redirect();
}

if ($action === 'hire_existing') {
    if (!moderator_hire_schema_ready()) {
        moderator_flash('danger', 'The notifications table is unavailable, so the hire message could not be stored.');
        moderator_redirect();
    }

    $targetUserId = (int) ($_POST['target_user_id'] ?? 0);
    $roleId = (int) ($_POST['role_id'] ?? 0);
    $message = trim((string) ($_POST['hire_message'] ?? ''));
    $invitedBy = (int) ($_SESSION['user_id'] ?? 0);

    if ($invitedBy <= 0) {
        moderator_flash('danger', 'Your admin session expired. Please sign in again.');
        moderator_redirect();
    }
    if ($targetUserId <= 0 || !moderator_hire_target_eligible($targetUserId)) {
        moderator_flash('danger', 'Select an existing customer or operational account that is not already an admin or moderator.');
        moderator_redirect();
    }
    if (!moderator_role_is_allowed($roleId)) {
        moderator_flash('danger', 'Please select the Moderator role.');
        moderator_redirect();
    }
    if ($message === '' || strlen($message) > 2000) {
        moderator_flash('danger', 'Please write a hire message between 1 and 2000 characters.');
        moderator_redirect();
    }
    if (moderator_hire_pending_exists($targetUserId, $roleId)) {
        moderator_flash('danger', 'That account already has a pending hire invite for this role.');
        moderator_redirect();
    }

    $roleLabel = 'Moderator';
    foreach (moderator_role_options() as $roleOption) {
        if ((int) $roleOption['role_id'] === $roleId) {
            $roleLabel = moderator_role_label((string) $roleOption['role_name']);
            break;
        }
    }
    $subject = 'Moderator access invitation';
    $deliveryNote = moderator_hire_meta_encode($roleId, $invitedBy, $roleLabel);

    $notify = $conn->prepare(
        "INSERT INTO notifications
            (request_id, recipient_user_id, recipient_email, notification_type, subject, message, delivery_status, delivery_note, created_at)
         SELECT NULL, u.user_id, u.email, 'moderator_hire', ?, ?, 'pending', ?, NOW()
         FROM users u
         WHERE u.user_id = ?
         LIMIT 1"
    );
    if (!$notify) {
        moderator_flash('danger', 'The hire message could not be prepared for the notifications table.');
        moderator_redirect();
    }
    $notify->bind_param('sssi', $subject, $message, $deliveryNote, $targetUserId);
    if (!$notify->execute()) {
        $error = $notify->error;
        $notify->close();
        error_log('[Moderator hire] notifications insert failed: ' . $error);
        moderator_flash('danger', 'The hire message could not be saved in notifications.');
        moderator_redirect();
    }
    $notificationId = (int) $notify->insert_id;
    $notify->close();

    admin_record_log((int) $adminUser['user_id'], 'Sent moderator hire invitation #' . $notificationId . ' to user #' . $targetUserId . '.');

    moderator_flash('success', 'Hire message was saved in notifications. The selected account will see it the next time they sign in.', [
        'notification_id' => $notificationId,
        'target_user_id' => $targetUserId,
    ]);
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
    admin_record_log((int) $adminUser['user_id'], 'Updated moderator account #' . $userId . '.');
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
    admin_record_log((int) $adminUser['user_id'], 'Changed moderator account #' . $userId . ' status to ' . $newStatus . '.');
    moderator_flash('success', 'The moderator status was changed to ' . moderator_status_label($newStatus) . '.');
    moderator_redirect();
}

moderator_flash('danger', 'The requested moderator action is not supported.');
moderator_redirect();
