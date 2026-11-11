<?php

function map_pump_account_identity_ensure(PDO $pdo, string $type, int $entityId, ?int $userId = null): int
{
    if ($entityId <= 0) {
        throw new InvalidArgumentException('An account identity requires a valid entity ID.');
    }

    $accountTypes = [
        'user' => 'user_id',
        'staff' => 'staff_id',
        'branch' => 'branch_id',
        'headquarter' => 'headquarter_id',
    ];
    if (!isset($accountTypes[$type])) {
        throw new InvalidArgumentException('Unsupported account identity type.');
    }

    if ($type === 'staff' && $userId !== null && $userId > 0) {
        $staffAccount = $pdo->prepare(
            'SELECT account_id, user_id FROM accounts WHERE staff_id = ? LIMIT 1'
        );
        $staffAccount->execute([$entityId]);
        $existingStaffAccount = $staffAccount->fetch(PDO::FETCH_ASSOC);
        if ($existingStaffAccount) {
            $existingUserId = (int) ($existingStaffAccount['user_id'] ?? 0);
            if ($existingUserId > 0 && $existingUserId !== $userId) {
                throw new RuntimeException('The staff record is already linked to a different account.');
            }
            if ($existingUserId === 0) {
                $linkStaff = $pdo->prepare(
                    "UPDATE accounts SET user_id = ?, account_type = 'user' WHERE account_id = ?"
                );
                $linkStaff->execute([$userId, (int) $existingStaffAccount['account_id']]);
            }
            return (int) $existingStaffAccount['account_id'];
        }

        $userAccount = $pdo->prepare(
            'SELECT account_id, staff_id FROM accounts WHERE user_id = ? LIMIT 1'
        );
        $userAccount->execute([$userId]);
        $existingUserAccount = $userAccount->fetch(PDO::FETCH_ASSOC);
        if ($existingUserAccount) {
            $existingStaffId = (int) ($existingUserAccount['staff_id'] ?? 0);
            if ($existingStaffId > 0 && $existingStaffId !== $entityId) {
                throw new RuntimeException('The user account is already linked to a different staff record.');
            }
            $linkStaff = $pdo->prepare(
                'UPDATE accounts SET staff_id = ? WHERE account_id = ?'
            );
            $linkStaff->execute([$entityId, (int) $existingUserAccount['account_id']]);
            return (int) $existingUserAccount['account_id'];
        }

        $createLinkedAccount = $pdo->prepare(
            "INSERT INTO accounts (account_type, user_id, staff_id) VALUES ('user', ?, ?)"
        );
        $createLinkedAccount->execute([$userId, $entityId]);
        return (int) $pdo->lastInsertId();
    }

    $column = $accountTypes[$type];
    $find = $pdo->prepare("SELECT account_id FROM accounts WHERE {$column} = ? LIMIT 1");
    $find->execute([$entityId]);
    $accountId = (int) ($find->fetchColumn() ?: 0);
    if ($accountId > 0) {
        return $accountId;
    }

    $create = $pdo->prepare("INSERT INTO accounts (account_type, {$column}) VALUES (?, ?)");
    $create->execute([$type, $entityId]);
    return (int) $pdo->lastInsertId();
}