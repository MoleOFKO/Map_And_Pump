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

function moderator_target_data(int $userId): ?array
{
    global $conn;

    $statement = $conn->prepare(
        "SELECT u.user_id, u.user_name, u.email, u.status,
                COALESCE(u.current_role_id, u.business_role_id, u.role_id) AS effective_role_id
         FROM users u
         LEFT JOIN roles r ON r.role_id = u.role_id
         LEFT JOIN roles br ON br.role_id = u.business_role_id
         LEFT JOIN roles cr ON cr.role_id = u.current_role_id
         WHERE u.user_id = ?
           AND NOT EXISTS (SELECT 1 FROM business_account_scope bas WHERE bas.user_id = u.user_id AND bas.headquarter_id IS NOT NULL)
           AND NOT EXISTS (SELECT 1 FROM headquarter owned_hq WHERE owned_hq.headquarters_owner_id = u.user_id)
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
        return null;
    }
    $statement->bind_param('i', $userId);
    $success = $statement->execute();
    $target = $success ? $statement->get_result()?->fetch_assoc() : null;
    $statement->close();

    return is_array($target) ? $target : null;
}

function moderator_mail_result_note(array $result): string
{
    return !empty($result['sent'])
        ? ' An email notice was sent.'
        : ' The account action was saved, but the email notice could not be delivered.';
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

    $notice = mail_send_account_notice($email, [
        'kind' => 'moderator',
        'action' => 'created',
    ]);

    moderator_flash('success', 'Moderator account was created successfully.' . moderator_mail_result_note($notice), ['user_id' => $newUserId]);
    moderator_redirect();
}

if ($action === 'hire_existing') {
    if (!moderator_hire_schema_ready()) {
        moderator_flash('danger', 'No moderator invitation was sent because the notification service is unavailable.');
        moderator_redirect();
    }

    $targetUserId = (int) ($_POST['target_user_id'] ?? 0);
    $roleId = (int) ($_POST['role_id'] ?? 0);
    $message = trim((string) ($_POST['hire_message'] ?? ''));
    $invitedBy = (int) ($_SESSION['user_id'] ?? 0);

    if ($invitedBy <= 0) {
        moderator_flash('danger', 'Your administrator session has expired. Sign in again to send the invitation.');
        moderator_redirect();
    }
    if ($targetUserId <= 0) {
        moderator_flash('danger', 'Select an account to send a moderator invitation.');
        moderator_redirect();
    }
    if (!moderator_hire_target_eligible($targetUserId)) {
        moderator_flash(
            'danger',
            'This account is not eligible for a moderator invitation. Select a customer or operational account without an Admin or Moderator role.'
        );
        moderator_redirect();
    }
    if (!moderator_role_is_allowed($roleId)) {
        moderator_flash('danger', 'Select the Moderator role before sending the invitation.');
        moderator_redirect();
    }
    if ($message === '' || strlen($message) > 2000) {
        moderator_flash('danger', 'Enter an invitation message between 1 and 2,000 characters.');
        moderator_redirect();
    }
    if (moderator_hire_pending_exists($targetUserId, $roleId)) {
        moderator_flash('danger', 'A Moderator invitation is already pending for this account. Wait for the recipient to respond.');
        moderator_redirect();
    }

    $contactQuery = $conn->prepare('SELECT user_id, user_name, email FROM users WHERE user_id = ? LIMIT 1');
    if (!$contactQuery) {
        moderator_flash('danger', 'The selected account could not be loaded.');
        moderator_redirect();
    }
    $contactQuery->bind_param('i', $targetUserId);
    $contactQuery->execute();
    $targetContact = $contactQuery->get_result()?->fetch_assoc();
    $contactQuery->close();
    if (!$targetContact) {
        moderator_flash('danger', 'The selected account was not found.');
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
    if (strlen($deliveryNote) > 255) {
        // notifications.delivery_note is varchar(255); keep invite usable if labels grow.
        $deliveryNote = moderator_hire_meta_encode($roleId, $invitedBy, 'Moderator');
    }
    $recipientEmail = trim((string) ($targetContact['email'] ?? ''));
    if ($recipientEmail === '') {
        moderator_flash('danger', 'The selected account does not have an email address.');
        moderator_redirect();
    }

    $notify = $conn->prepare(
        "INSERT INTO notifications
            (request_id, recipient_user_id, recipient_email, notification_type, subject, message, delivery_status, delivery_note, created_at)
         VALUES (NULL, ?, ?, 'moderator_hire', ?, ?, 'pending', ?, NOW())"
    );
    if (!$notify) {
        moderator_flash('danger', 'The invitation could not be prepared for delivery.');
        moderator_redirect();
    }
    $notify->bind_param('issss', $targetUserId, $recipientEmail, $subject, $message, $deliveryNote);
    if (!$notify->execute()) {
        $error = $notify->error;
        $notify->close();
        error_log('[Moderator hire] notifications insert failed: ' . $error);
        moderator_flash('danger', 'The invitation could not be saved. Please try again.');
        moderator_redirect();
    }
    $notificationId = (int) $notify->insert_id;
    $affected = (int) $notify->affected_rows;
    $notify->close();

    if ($affected < 1 || $notificationId <= 0) {
        error_log('[Moderator hire] insert reported success but notification_id=' . $notificationId . ' affected=' . $affected);
        moderator_flash('danger', 'The invitation could not be recorded. Please try again.');
        moderator_redirect();
    }

    $emailNotice = mail_send_account_notice($recipientEmail, [
        'kind' => 'moderator_invitation',
        'action' => 'invited',
    ]);

    admin_record_log(
        (int) $adminUser['user_id'],
        'Sent moderator access invitation #' . $notificationId . ' to user #' . $targetUserId . '; email ' . (string) ($emailNotice['status'] ?? 'unknown') . '.'
    );

    $targetLabel = trim((string) ($targetContact['user_name'] ?? '')) !== ''
        ? (string) $targetContact['user_name']
        : $recipientEmail;
    moderator_flash(
        'success',
        'Moderator invitation sent to ' . $targetLabel . ' (notification #' . $notificationId . '). '
        . 'The recipient will be prompted to accept or decline the invitation after signing in.'
        . moderator_mail_result_note($emailNotice),
        [
            'notification_id' => $notificationId,
            'target_user_id' => $targetUserId,
        ]
    );
    moderator_redirect();
}


$userId = (int) ($_POST['user_id'] ?? 0);
$target = $userId > 0 ? moderator_target_data($userId) : null;
if (!$target) {
    moderator_flash('danger', 'The selected system moderator account could not be found.');
    moderator_redirect();
}

if ($action === 'update_profile') {
    $userName = moderator_clean_user_name((string) ($_POST['user_name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $roleId = (int) ($_POST['role_id'] ?? 0);

    $validationError = moderator_validate_profile($userName, $email, $roleId);
    if ($validationError !== null) {
        moderator_flash('danger', $validationError);
        moderator_redirect();
    }
    if (moderator_duplicate_exists($userName, $email, $userId)) {
        moderator_flash('danger', 'That username or email address is already registered to another account.');
        moderator_redirect();
    }

    // Administrators may manage moderator identity, role, and status, but
    // must never change another account's password through this workflow.
    $statement = $conn->prepare(
        'UPDATE users SET user_name = ?, email = ?, role_id = ?, current_role_id = ?, updated_at = NOW() WHERE user_id = ? AND NOT EXISTS (SELECT 1 FROM business_account_scope bas WHERE bas.user_id = users.user_id AND bas.headquarter_id IS NOT NULL) AND NOT EXISTS (SELECT 1 FROM headquarter owned_hq WHERE owned_hq.headquarters_owner_id = users.user_id)'
    );
    if ($statement) {
        $statement->bind_param('ssiii', $userName, $email, $roleId, $roleId, $userId);
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

    $oldEmail = (string) ($target['email'] ?? '');
    $emailChanged = strcasecmp($oldEmail, $email) !== 0;
    $profileChanged = trim((string) ($target['user_name'] ?? '')) !== $userName
        || (int) ($target['effective_role_id'] ?? 0) !== $roleId;
    $notice = null;
    if ($emailChanged || $profileChanged) {
        $notice = mail_send_account_notice($oldEmail, [
            'kind' => 'security',
            'action' => $emailChanged ? 'email_changed' : 'profile_changed',
        ]);
    }

    moderator_flash('success', 'The moderator profile was updated successfully.' . ($notice !== null ? moderator_mail_result_note($notice) : ''));
    moderator_redirect();
}

if ($action === 'update_status') {
    $newStatus = strtolower(trim((string) ($_POST['status'] ?? '')));
    $allowedStatuses = ['approved', 'suspended', 'removed'];
    if (!in_array($newStatus, $allowedStatuses, true)) {
        moderator_flash('danger', 'Please select a valid moderator account status.');
        moderator_redirect();
    }

    $previousStatus = strtolower(trim((string) ($target['status'] ?? '')));
    $statement = $conn->prepare(
        'UPDATE users SET status = ?, updated_at = NOW() WHERE user_id = ?
         AND NOT EXISTS (SELECT 1 FROM business_account_scope bas WHERE bas.user_id = users.user_id AND bas.headquarter_id IS NOT NULL)
         AND NOT EXISTS (SELECT 1 FROM headquarter owned_hq WHERE owned_hq.headquarters_owner_id = users.user_id)'
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
    $notice = null;
    if ($previousStatus !== $newStatus) {
        $notice = mail_send_account_notice((string) ($target['email'] ?? ''), [
            'kind' => 'moderator',
            'action' => $newStatus,
        ]);
    }
    moderator_flash('success', 'The moderator status was changed to ' . moderator_status_label($newStatus) . '.' . ($notice !== null ? moderator_mail_result_note($notice) : ''));
    moderator_redirect();
}

moderator_flash('danger', 'The requested moderator action is not supported.');
moderator_redirect();
