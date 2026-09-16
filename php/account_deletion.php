<?php

const MAP_PUMP_ACCOUNT_DELETION_DAYS = 30;

function map_pump_account_deletion_schema_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    try {
        $columns = [
            'account_deletion_requested_at' => "ALTER TABLE users ADD COLUMN account_deletion_requested_at datetime NULL AFTER status",
            'account_deletion_scheduled_for' => "ALTER TABLE users ADD COLUMN account_deletion_scheduled_for datetime NULL AFTER account_deletion_requested_at",
        ];
        foreach ($columns as $column => $alterSql) {
            $check = $pdo->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = ?"
            );
            $check->execute([$column]);
            if ((int) $check->fetchColumn() === 0) {
                $pdo->exec($alterSql);
            }
        }
        return $ready = true;
    } catch (Throwable $e) {
        error_log('Account deletion schema check failed: ' . $e->getMessage());
        return $ready = false;
    }
}

function map_pump_hq_owner_context(PDO $pdo, int $userId): ?array
{
    if ($userId <= 0 || !map_pump_account_deletion_schema_ready($pdo)) {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT u.user_id, u.user_name, u.status, u.role_id, u.business_role_id,
                u.headquarter_id, u.account_deletion_requested_at, u.account_deletion_scheduled_for,
                h.company_name, h.headquarters_owner_id
         FROM users u
         LEFT JOIN headquarter h ON h.headquarter_id = u.headquarter_id
         WHERE u.user_id = ?
           AND (u.business_role_id = 3 OR u.role_id = 3)
           AND u.headquarter_id IS NOT NULL
           AND h.headquarter_id IS NOT NULL
           AND (h.headquarters_owner_id IS NULL OR h.headquarters_owner_id = u.user_id)
         LIMIT 1"
    );
    $stmt->execute([$userId]);
    $context = $stmt->fetch(PDO::FETCH_ASSOC);
    return $context ?: null;
}

function map_pump_request_hq_account_deletion(PDO $pdo, int $userId): ?string
{
    $context = map_pump_hq_owner_context($pdo, $userId);
    if (!$context) {
        return null;
    }

    $scheduledFor = (new DateTimeImmutable('now'))->modify('+' . MAP_PUMP_ACCOUNT_DELETION_DAYS . ' days')->format('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        "UPDATE users
         SET status = 'pending_deletion', account_deletion_requested_at = NOW(),
             account_deletion_scheduled_for = ?, updated_at = NOW()
         WHERE user_id = ?"
    );
    $stmt->execute([$scheduledFor, $userId]);
    return $scheduledFor;
}

function map_pump_restore_hq_account(PDO $pdo, int $userId): bool
{
    $context = map_pump_hq_owner_context($pdo, $userId);
    if (!$context || ($context['status'] ?? '') !== 'pending_deletion') {
        return false;
    }

    $stmt = $pdo->prepare(
        "UPDATE users
         SET status = 'active', account_deletion_requested_at = NULL,
             account_deletion_scheduled_for = NULL, updated_at = NOW()
         WHERE user_id = ? AND status = 'pending_deletion'"
    );
    $stmt->execute([$userId]);
    return $stmt->rowCount() === 1;
}

function map_pump_cleanup_due_account_deletions(PDO $pdo): int
{
    if (!map_pump_account_deletion_schema_ready($pdo)) {
        return 0;
    }

    $stmt = $pdo->query(
        "SELECT u.user_id, u.headquarter_id
         FROM users u
         INNER JOIN headquarter h ON h.headquarter_id = u.headquarter_id
         WHERE u.status = 'pending_deletion'
           AND u.account_deletion_scheduled_for IS NOT NULL
           AND u.account_deletion_scheduled_for <= NOW()
           AND (u.business_role_id = 3 OR u.role_id = 3)"
    );
    $dueAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $deleted = 0;

    foreach ($dueAccounts as $account) {
        $userId = (int) $account['user_id'];
        try {
            $pdo->beginTransaction();
            $clearOwner = $pdo->prepare('UPDATE headquarter SET headquarters_owner_id = NULL WHERE headquarter_id = ? AND headquarters_owner_id = ?');
            $clearOwner->execute([(int) $account['headquarter_id'], $userId]);
            $deleteUser = $pdo->prepare("DELETE FROM users WHERE user_id = ? AND status = 'pending_deletion' AND account_deletion_scheduled_for <= NOW()");
            $deleteUser->execute([$userId]);
            $pdo->commit();
            $deleted += $deleteUser->rowCount();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Could not purge due account ' . $userId . ': ' . $e->getMessage());
        }
    }

    return $deleted;
}
