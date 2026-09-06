<?php
/**
 * Shared moderator hire-invite helpers.
 * Invites are stored only in the existing `notifications` table
 * with notification_type = 'moderator_hire'.
 *
 * Encoding conventions:
 * - delivery_status: pending | accepted | declined
 * - delivery_note: JSON {"offered_role_id":N,"invited_by_user_id":N,"offered_role_name":"..."}
 * - subject: short label (e.g. "Moderator access invitation")
 * - message: admin hire message shown in the popup
 * - recipient_user_id / recipient_email: target account
 */

function moderator_hire_meta_encode(int $offeredRoleId, int $invitedByUserId, string $offeredRoleName = 'Moderator'): string
{
    $payload = [
        'offered_role_id' => $offeredRoleId,
        'invited_by_user_id' => $invitedByUserId,
        'offered_role_name' => $offeredRoleName !== '' ? $offeredRoleName : 'Moderator',
    ];

    return (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
}

/**
 * @return array{offered_role_id:int,invited_by_user_id:int,offered_role_name:string}
 */
function moderator_hire_meta_decode(?string $deliveryNote): array
{
    $defaults = [
        'offered_role_id' => 0,
        'invited_by_user_id' => 0,
        'offered_role_name' => 'Moderator',
    ];
    $raw = trim((string) $deliveryNote);
    if ($raw === '') {
        return $defaults;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $defaults;
    }

    return [
        'offered_role_id' => (int) ($decoded['offered_role_id'] ?? 0),
        'invited_by_user_id' => (int) ($decoded['invited_by_user_id'] ?? 0),
        'offered_role_name' => trim((string) ($decoded['offered_role_name'] ?? 'Moderator')) ?: 'Moderator',
    ];
}

function moderator_hire_notifications_ready_mysqli(mysqli $conn): bool
{
    $result = $conn->query(
        "SELECT COUNT(*) AS total
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'notifications'"
    );
    if (!$result) {
        return false;
    }
    $row = $result->fetch_assoc();
    return ((int) ($row['total'] ?? 0)) > 0;
}

function moderator_hire_notifications_ready_pdo(PDO $pdo): bool
{
    try {
        $count = (int) $pdo->query(
            "SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'notifications'"
        )->fetchColumn();
        return $count > 0;
    } catch (Throwable $e) {
        error_log('[Moderator hire] notifications table check failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Pending hire invites for a user (newest first).
 * Maps notification_id → invite_id for existing popup/response code.
 *
 * @return list<array<string, mixed>>
 */
function moderator_hire_pending_for_user_pdo(PDO $pdo, int $userId): array
{
    if ($userId <= 0 || !moderator_hire_notifications_ready_pdo($pdo)) {
        return [];
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT notification_id, recipient_user_id, message, delivery_status,
                    delivery_note, created_at, subject
             FROM notifications
             WHERE recipient_user_id = ?
               AND LOWER(notification_type) = 'moderator_hire'
               AND LOWER(delivery_status) = 'pending'
             ORDER BY created_at DESC, notification_id DESC
             LIMIT 10"
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $mapped = [];
        foreach ($rows as $row) {
            $meta = moderator_hire_meta_decode($row['delivery_note'] ?? null);
            $roleName = $meta['offered_role_name'];
            if ($meta['offered_role_id'] > 0) {
                try {
                    $roleStmt = $pdo->prepare('SELECT role_name FROM roles WHERE role_id = ? LIMIT 1');
                    $roleStmt->execute([$meta['offered_role_id']]);
                    $fromDb = trim((string) ($roleStmt->fetchColumn() ?: ''));
                    if ($fromDb !== '') {
                        $roleName = $fromDb;
                    }
                } catch (Throwable $e) {
                    // keep meta role name
                }
            }

            $invitedByName = 'Administrator';
            if ($meta['invited_by_user_id'] > 0) {
                try {
                    $nameStmt = $pdo->prepare('SELECT user_name FROM users WHERE user_id = ? LIMIT 1');
                    $nameStmt->execute([$meta['invited_by_user_id']]);
                    $invitedByName = (string) ($nameStmt->fetchColumn() ?: 'Administrator');
                } catch (Throwable $e) {
                    $invitedByName = 'Administrator';
                }
            }

            $mapped[] = [
                'invite_id' => (int) ($row['notification_id'] ?? 0),
                'notification_id' => (int) ($row['notification_id'] ?? 0),
                'target_user_id' => (int) ($row['recipient_user_id'] ?? 0),
                'invited_by_user_id' => $meta['invited_by_user_id'],
                'offered_role_id' => $meta['offered_role_id'],
                'message' => (string) ($row['message'] ?? ''),
                'status' => (string) ($row['delivery_status'] ?? 'pending'),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'offered_role_name' => $roleName,
                'invited_by_name' => $invitedByName,
                'subject' => (string) ($row['subject'] ?? 'Moderator access invitation'),
            ];
        }

        return $mapped;
    } catch (Throwable $e) {
        error_log('[Moderator hire] Pending lookup failed: ' . $e->getMessage());
        return [];
    }
}

function moderator_hire_store_pending_in_session(array $invites): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $_SESSION['pending_moderator_hires'] = array_values(array_filter(
        $invites,
        static fn ($row) => is_array($row) && (int) ($row['invite_id'] ?? 0) > 0
    ));
}

function moderator_hire_session_invites(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $rows = $_SESSION['pending_moderator_hires'] ?? [];
    return is_array($rows) ? array_values($rows) : [];
}

function moderator_hire_remove_from_session(int $inviteId): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $rows = moderator_hire_session_invites();
    $_SESSION['pending_moderator_hires'] = array_values(array_filter(
        $rows,
        static fn ($row) => (int) ($row['invite_id'] ?? 0) !== $inviteId
    ));
}
