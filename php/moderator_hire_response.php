<?php
/**
 * Accept / decline a pending moderator hire invite stored in `notifications`.
 * invite_id in the form is notifications.notification_id.
 */
session_start();
require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/moderator_hire_helpers.php';
require_once __DIR__ . '/role_state.php';

function hire_response_redirect(string $fallback = '/php/Customer/dashboard.php'): never
{
    $returnTo = trim((string) ($_POST['return_to'] ?? $_SERVER['HTTP_REFERER'] ?? ''));
    if ($returnTo !== '' && str_starts_with($returnTo, '/')) {
        header('Location: ' . $returnTo);
        exit;
    }
    if ($returnTo !== '' && preg_match('#^https?://#i', $returnTo)) {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $parts = parse_url($returnTo);
        if (is_array($parts) && strtolower((string) ($parts['host'] ?? '')) === $host) {
            header('Location: ' . $returnTo);
            exit;
        }
    }
    header('Location: ' . $fallback);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    hire_response_redirect();
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$inviteId = (int) ($_POST['invite_id'] ?? 0); // notifications.notification_id
$decision = strtolower(trim((string) ($_POST['decision'] ?? '')));

if ($userId <= 0) {
    header('Location: /php/Login/login.php');
    exit;
}
if ($inviteId <= 0 || !in_array($decision, ['accepted', 'declined'], true)) {
    hire_response_redirect();
}
if (!moderator_hire_notifications_ready_pdo($pdo)) {
    hire_response_redirect();
}

try {
    $stmt = $pdo->prepare(
        "SELECT notification_id, recipient_user_id, delivery_status, delivery_note, message
         FROM notifications
         WHERE notification_id = ?
           AND recipient_user_id = ?
           AND LOWER(notification_type) = 'moderator_hire'
         LIMIT 1"
    );
    $stmt->execute([$inviteId, $userId]);
    $invite = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invite || strtolower((string) ($invite['delivery_status'] ?? '')) !== 'pending') {
        moderator_hire_remove_from_session($inviteId);
        hire_response_redirect();
    }

    $meta = moderator_hire_meta_decode($invite['delivery_note'] ?? null);
    $roleId = (int) $meta['offered_role_id'];

    if ($decision === 'declined') {
        $update = $pdo->prepare(
            "UPDATE notifications
             SET delivery_status = 'declined'
             WHERE notification_id = ?
               AND recipient_user_id = ?
               AND LOWER(delivery_status) = 'pending'"
        );
        $update->execute([$inviteId, $userId]);
        moderator_hire_remove_from_session($inviteId);
        $_SESSION['moderator_hire_flash'] = 'You declined the moderator access invitation.';
        hire_response_redirect();
    }

    $roleCheck = $pdo->prepare(
        "SELECT role_id, role_name FROM roles
         WHERE role_id = ? AND LOWER(TRIM(role_name)) = 'moderator'
         LIMIT 1"
    );
    $roleCheck->execute([$roleId]);
    $role = $roleCheck->fetch(PDO::FETCH_ASSOC);
    if (!$role) {
        $pdo->prepare(
            "UPDATE notifications
             SET delivery_status = 'declined'
             WHERE notification_id = ?
               AND recipient_user_id = ?
               AND LOWER(delivery_status) = 'pending'"
        )->execute([$inviteId, $userId]);
        moderator_hire_remove_from_session($inviteId);
        $_SESSION['moderator_hire_flash'] = 'This invitation is no longer valid. Only Moderator access can be granted through this workflow.';
        hire_response_redirect();
    }

    // Hire existing accounts as moderators via business_role_id so the
    // original role_id (e.g. customer) is preserved and account switching works.
    $hasCurrentRole = function_exists('map_pump_current_role_column_ready')
        ? map_pump_current_role_column_ready($pdo)
        : false;

    if ($hasCurrentRole) {
        $apply = $pdo->prepare(
            "UPDATE users
             SET business_role_id = ?, current_role_id = ?, status = 'approved', updated_at = NOW()
             WHERE user_id = ?"
        );
        $apply->execute([$roleId, $roleId, $userId]);
    } else {
        $apply = $pdo->prepare(
            "UPDATE users
             SET business_role_id = ?, status = 'approved', updated_at = NOW()
             WHERE user_id = ?"
        );
        $apply->execute([$roleId, $userId]);
    }

    $update = $pdo->prepare(
        "UPDATE notifications
         SET delivery_status = 'accepted'
         WHERE notification_id = ?
           AND recipient_user_id = ?
           AND LOWER(delivery_status) = 'pending'"
    );
    $update->execute([$inviteId, $userId]);

    $pdo->prepare(
        "UPDATE notifications
         SET delivery_status = 'declined'
         WHERE recipient_user_id = ?
           AND LOWER(notification_type) = 'moderator_hire'
           AND LOWER(delivery_status) = 'pending'
           AND notification_id <> ?"
    )->execute([$userId, $inviteId]);

    moderator_hire_remove_from_session($inviteId);
    $_SESSION['business_role_id'] = $roleId;
    $_SESSION['role_id'] = $roleId;
    $_SESSION['role_name'] = (string) $role['role_name'];
    $_SESSION['has_business'] = true;
    $_SESSION['moderator_hire_flash'] = 'Moderator access accepted. Your account role has been updated.';

    $roleName = strtolower(trim((string) $role['role_name']));
    header('Location: /php/Moderators(UnderAdmin)/moderator_dashboard.php');
    exit;
} catch (Throwable $e) {
    error_log('[Moderator hire] Response failed: ' . $e->getMessage());
    $_SESSION['moderator_hire_flash'] = 'Could not process the hire invitation. Please try again.';
    hire_response_redirect();
}
