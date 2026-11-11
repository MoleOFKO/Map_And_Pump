<?php
/**
 * Backfill staff records from role-5 user accounts and enforce staff.user_id.
 * Run from the project root: php php/migrate_staff_users.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/conn.php';

function staff_column_exists(PDO $pdo, string $column): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'staff'
           AND COLUMN_NAME = ?"
    );
    $stmt->execute([$column]);
    return (int) $stmt->fetchColumn() > 0;
}

function staff_index_exists(PDO $pdo, string $indexName): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'staff'
           AND INDEX_NAME = ?"
    );
    $stmt->execute([$indexName]);
    return (int) $stmt->fetchColumn() > 0;
}

function staff_foreign_key_exists(PDO $pdo): bool
{
    $stmt = $pdo->query(
        "SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'staff'
           AND COLUMN_NAME = 'user_id'
           AND REFERENCED_TABLE_NAME = 'users'
           AND REFERENCED_COLUMN_NAME = 'user_id'"
    );
    return (int) $stmt->fetchColumn() > 0;
}

function next_staff_code(PDO $pdo, int $branchId): string
{
    $stmt = $pdo->prepare(
        "SELECT MAX(CAST(SUBSTRING(staff_code, 4) AS UNSIGNED))
         FROM staff
         WHERE branch_id = ?"
    );
    $stmt->execute([$branchId]);
    return 'ST-' . (((int) $stmt->fetchColumn() ?: 2090) + 1);
}

try {
    $tableCheck = $pdo->query(
        "SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'staff'"
    );
    if ((int) $tableCheck->fetchColumn() === 0) {
        $pdo->exec(
            "CREATE TABLE staff (
                staff_id INT NOT NULL AUTO_INCREMENT,
                user_id INT NOT NULL,
                branch_id INT NOT NULL,
                staff_code VARCHAR(50) NOT NULL,
                full_name VARCHAR(255) NOT NULL,
                role VARCHAR(100) NOT NULL,
                shift VARCHAR(50) NULL,
                contact VARCHAR(50) NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                duty_status VARCHAR(30) NOT NULL DEFAULT 'Off Duty',
                hired_at DATE NULL,
                PRIMARY KEY (staff_id),
                UNIQUE KEY uq_staff_user_id (user_id),
                UNIQUE KEY uq_staff_code (staff_code),
                KEY idx_staff_branch (branch_id),
                CONSTRAINT fk_staff_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        echo "Created staff table with user_id foreign key.\n";
    } elseif (!staff_column_exists($pdo, 'user_id')) {
        $pdo->exec("ALTER TABLE staff ADD COLUMN user_id INT NULL AFTER staff_id");
        echo "Added staff.user_id after staff_id.\n";
    }

    $pdo->beginTransaction();

    $staffUserIds = $pdo->query('SELECT user_id FROM staff WHERE user_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
    $knownStaffUsers = array_fill_keys(array_map('intval', $staffUserIds), true);

    $users = $pdo->query(
        "SELECT u.user_id, u.user_name, u.email, COALESCE(st.branch_id, bas.station_id) AS branch_id
         FROM users u
         LEFT JOIN staff st ON st.user_id = u.user_id
         LEFT JOIN business_account_scope bas ON bas.user_id = u.user_id
         WHERE u.role_id = 5 OR u.business_role_id = 5
         ORDER BY u.user_id"
    )->fetchAll(PDO::FETCH_ASSOC);

    $hasEmail = staff_column_exists($pdo, 'email');
    $inserted = 0;
    $skipped = 0;

    foreach ($users as $user) {
        $userId = (int) $user['user_id'];
        $branchId = (int) ($user['branch_id'] ?? 0);
        if (isset($knownStaffUsers[$userId])) {
            $skipped++;
            continue;
        }
        if ($branchId <= 0) {
            echo "Skipped user {$userId}: no branch_id.\n";
            continue;
        }

        $branchCheck = $pdo->prepare('SELECT 1 FROM branches WHERE branch_id = ? LIMIT 1');
        $branchCheck->execute([$branchId]);
        if (!$branchCheck->fetchColumn()) {
            echo "Skipped user {$userId}: branch {$branchId} does not exist.\n";
            continue;
        }

        $code = next_staff_code($pdo, $branchId);
        if ($hasEmail) {
            $insert = $pdo->prepare(
                "INSERT INTO staff
                    (user_id, branch_id, staff_code, full_name, role, shift, contact, email, is_active, duty_status, hired_at)
                 VALUES (?, ?, ?, ?, 'Pump Attendant', 'Morning', '', ?, 1, 'Off Duty', CURDATE())"
            );
            $insert->execute([$userId, $branchId, $code, $user['user_name'], $user['email']]);
        } else {
            $insert = $pdo->prepare(
                "INSERT INTO staff
                    (user_id, branch_id, staff_code, full_name, role, shift, contact, is_active, duty_status, hired_at)
                 VALUES (?, ?, ?, ?, 'Pump Attendant', 'Morning', '', 1, 'Off Duty', CURDATE())"
            );
            $insert->execute([$userId, $branchId, $code, $user['user_name']]);
        }
        map_pump_account_identity_ensure($pdo, 'staff', (int) $pdo->lastInsertId(), $userId);
        $knownStaffUsers[$userId] = true;
        $inserted++;
    }

     if ($hasEmail) {
          $pdo->exec(
                "UPDATE staff st
                 INNER JOIN users u ON LOWER(TRIM(u.email)) = LOWER(TRIM(st.email))
                     AND (u.role_id = 5 OR u.business_role_id = 5)
                 SET st.user_id = u.user_id
                 WHERE st.user_id IS NULL"
          );
     }

     $pdo->exec(
          "UPDATE staff st
            INNER JOIN users u ON LOWER(TRIM(u.user_name)) = LOWER(TRIM(st.full_name))
                AND (u.role_id = 5 OR u.business_role_id = 5)
            INNER JOIN business_account_scope bas ON bas.user_id = u.user_id AND bas.station_id = st.branch_id
            LEFT JOIN users duplicate_user ON LOWER(TRIM(duplicate_user.user_name)) = LOWER(TRIM(st.full_name))
                AND (duplicate_user.role_id = 5 OR duplicate_user.business_role_id = 5)
                AND duplicate_user.user_id <> u.user_id
                AND EXISTS (
                    SELECT 1 FROM business_account_scope duplicate_scope
                    WHERE duplicate_scope.user_id = duplicate_user.user_id
                      AND duplicate_scope.station_id = st.branch_id
                )
            SET st.user_id = u.user_id
            WHERE st.user_id IS NULL
              AND duplicate_user.user_id IS NULL"
     );

    $orphanCheck = $pdo->query('SELECT COUNT(*) FROM staff WHERE user_id IS NULL');
    $orphanCount = (int) $orphanCheck->fetchColumn();
    if ($orphanCount > 0) {
        $orphans = $pdo->query(
            'SELECT staff_id, full_name, branch_id' . ($hasEmail ? ', email' : '') . '
             FROM staff
             WHERE user_id IS NULL
             ORDER BY staff_id'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($orphans as $orphan) {
            $email = $hasEmail ? ', email=' . (string) ($orphan['email'] ?? '') : '';
            echo "Unmatched staff_id={$orphan['staff_id']}, name={$orphan['full_name']}, branch_id={$orphan['branch_id']}{$email}.\n";
        }
        echo "Warning: {$orphanCount} legacy staff rows remain without a user account.\n";
    }

    if ($pdo->inTransaction()) {
        $pdo->commit();
    }

    $pdo->exec('ALTER TABLE staff MODIFY COLUMN user_id INT NULL AFTER staff_id');

    if (!staff_index_exists($pdo, 'uq_staff_user_id')) {
        $pdo->exec('ALTER TABLE staff ADD UNIQUE KEY uq_staff_user_id (user_id)');
    }

    if (!staff_foreign_key_exists($pdo)) {
        $pdo->exec(
            'ALTER TABLE staff ADD CONSTRAINT fk_staff_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE ON UPDATE CASCADE'
        );
    }

    if ($pdo->inTransaction()) {
        $pdo->commit();
    }
    echo "Backfill complete. Inserted {$inserted} staff rows; skipped {$skipped} already-linked rows.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Migration failed: {$e->getMessage()}\n");
    exit(1);
}
