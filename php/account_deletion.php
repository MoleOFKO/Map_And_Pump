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
            'account_deletion_requested_at' => "ALTER TABLE headquarter ADD COLUMN account_deletion_requested_at datetime NULL",
            'account_deletion_scheduled_for' => "ALTER TABLE headquarter ADD COLUMN account_deletion_scheduled_for datetime NULL",
        ];
        foreach ($columns as $column => $alterSql) {
            $check = $pdo->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'headquarter' AND COLUMN_NAME = ?"
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
                u.headquarter_id, h.account_deletion_requested_at, h.account_deletion_scheduled_for,
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

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "UPDATE headquarter
             SET account_deletion_requested_at = NOW(),
                 account_deletion_scheduled_for = DATE_ADD(NOW(), INTERVAL " . MAP_PUMP_ACCOUNT_DELETION_DAYS . " DAY)
             WHERE headquarter_id = ?"
        );
        $stmt->execute([(int) $context['headquarter_id']]);
        $status = $pdo->prepare("UPDATE users SET status = 'pending_deletion', updated_at = NOW() WHERE user_id = ?");
        $status->execute([$userId]);
        $deadline = $pdo->prepare('SELECT account_deletion_scheduled_for FROM headquarter WHERE headquarter_id = ?');
        $deadline->execute([(int) $context['headquarter_id']]);
        $scheduledFor = $deadline->fetchColumn();
        $pdo->commit();
        return $scheduledFor === false ? null : (string) $scheduledFor;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function map_pump_restore_hq_account(PDO $pdo, int $userId): bool
{
    $context = map_pump_hq_owner_context($pdo, $userId);
    if (!$context || ($context['status'] ?? '') !== 'pending_deletion') {
        return false;
    }

    $pdo->beginTransaction();
    try {
        $status = $pdo->prepare("UPDATE users SET status = 'active', updated_at = NOW() WHERE user_id = ? AND status = 'pending_deletion'");
        $status->execute([$userId]);
        if ($status->rowCount() !== 1) {
            $pdo->rollBack();
            return false;
        }
        $clearDeadline = $pdo->prepare(
            'UPDATE headquarter
             SET account_deletion_requested_at = NULL, account_deletion_scheduled_for = NULL
             WHERE headquarter_id = ?'
        );
        $clearDeadline->execute([(int) $context['headquarter_id']]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
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
           AND h.account_deletion_requested_at <= DATE_SUB(NOW(), INTERVAL " . MAP_PUMP_ACCOUNT_DELETION_DAYS . " DAY)
           AND h.account_deletion_scheduled_for <= NOW()
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
                        $deleteUser = $pdo->prepare(
                                "DELETE u FROM users u
                                 INNER JOIN headquarter h ON h.headquarter_id = u.headquarter_id
                                 WHERE u.user_id = ? AND u.status = 'pending_deletion'
                                     AND h.account_deletion_requested_at <= DATE_SUB(NOW(), INTERVAL " . MAP_PUMP_ACCOUNT_DELETION_DAYS . " DAY)
                                     AND h.account_deletion_scheduled_for <= NOW()"
                        );
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
