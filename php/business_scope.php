<?php

if (!function_exists('map_pump_current_hq_id')) {
    function map_pump_current_hq_id(PDO $pdo, ?int $userId = null): int
    {
        $userId = $userId ?? (int)($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return 0;
        }

        $stmt = $pdo->prepare(
                "SELECT COALESCE(
                    NULLIF(u.headquarter_id, 0),
                    NULLIF(bas.headquarter_id, 0),
                    (SELECT owned_hq.headquarter_id
                     FROM headquarter owned_hq
                     WHERE owned_hq.headquarters_owner_id = u.user_id
                     ORDER BY owned_hq.headquarter_id DESC
                     LIMIT 1),
                    (SELECT email_hq.headquarter_id
                     FROM headquarter email_hq
                     WHERE LOWER(TRIM(email_hq.headquarter_email)) = LOWER(TRIM(u.email))
                     ORDER BY email_hq.headquarter_id DESC
                     LIMIT 1)
                    ) AS headquarter_id
             FROM users u
             LEFT JOIN business_account_scope bas ON bas.user_id = u.user_id
             WHERE u.user_id = ?
             LIMIT 1"
        );
        $stmt->execute([$userId]);

        return (int)($stmt->fetchColumn() ?: 0);
    }
}

if (!function_exists('map_pump_manager_report_scope_sql')) {
    function map_pump_manager_report_scope_sql(): string
    {
        return "(u.role_id = ? OR u.business_role_id = ? OR u.current_role_id = ?)
            AND (
                u.headquarter_id = ?
                OR EXISTS (
                    SELECT 1
                    FROM business_account_scope report_account_scope
                    WHERE report_account_scope.user_id = u.user_id
                      AND report_account_scope.headquarter_id = ?
                )
                OR EXISTS (
                    SELECT 1
                    FROM station_business_scope report_scope
                    WHERE report_scope.branch_manager_user_id = u.user_id
                      AND report_scope.headquarter_id = ?
                )
            )";
    }
}

if (!function_exists('map_pump_manager_in_hq')) {
    function map_pump_manager_in_hq(PDO $pdo, int $managerId, int $hqId): bool
    {
        if ($managerId <= 0 || $hqId <= 0) {
            return false;
        }

        $stmt = $pdo->prepare(
            "SELECT 1
             FROM users u
             LEFT JOIN business_account_scope bas ON bas.user_id = u.user_id
             WHERE u.user_id = ?
               AND COALESCE(u.business_role_id, u.role_id) = 4
               AND (
                   COALESCE(bas.headquarter_id, u.headquarter_id) = ?
                   OR EXISTS (
                       SELECT 1 FROM station_business_scope sbs
                       WHERE sbs.branch_manager_user_id = u.user_id
                         AND sbs.headquarter_id = ?
                   )
               )
               AND LOWER(COALESCE(u.status, 'active')) NOT IN ('suspended', 'removed')
             LIMIT 1"
        );
        $stmt->execute([$managerId, $hqId, $hqId]);

        return (bool)$stmt->fetchColumn();
    }
}

if (!function_exists('map_pump_staff_can_access_branch')) {
    function map_pump_staff_can_access_branch(PDO $pdo, int $staffUserId, int $branchId): bool
    {
        if ($staffUserId <= 0 || $branchId <= 0) {
            return false;
        }

        $stmt = $pdo->prepare(
            "SELECT 1
             FROM staff st
             INNER JOIN users staff_user ON staff_user.user_id = st.user_id
             INNER JOIN branches b ON b.branch_id = st.branch_id
             INNER JOIN users manager ON manager.user_id = b.manager_user_id
             WHERE st.user_id = ?
               AND st.branch_id = ?
               AND st.is_active = 1
               AND COALESCE(staff_user.business_role_id, staff_user.role_id) = 5
               AND COALESCE(manager.business_role_id, manager.role_id) = 4
               AND LOWER(COALESCE(staff_user.status, 'active')) NOT IN ('disabled', 'suspended', 'removed')
               AND LOWER(COALESCE(manager.status, 'active')) NOT IN ('disabled', 'suspended', 'removed')
             LIMIT 1"
        );
        $stmt->execute([$staffUserId, $branchId]);

        return (bool) $stmt->fetchColumn();
    }
}

if (!function_exists('map_pump_station_in_hq')) {
    function map_pump_station_in_hq(PDO $pdo, int $stationId, int $hqId): bool
    {
        if ($stationId <= 0 || $hqId <= 0) {
            return false;
        }

        $stmt = $pdo->prepare(
            "SELECT 1
             FROM station_business_scope
             WHERE station_id = ? AND headquarter_id = ?
             LIMIT 1"
        );
        $stmt->execute([$stationId, $hqId]);

        return (bool)$stmt->fetchColumn();
    }
}

if (!function_exists('map_pump_ensure_station_scope_manager_nullable')) {
    function map_pump_ensure_station_scope_manager_nullable(PDO $pdo): void
    {
        $stmt = $pdo->prepare(
            "SELECT IS_NULLABLE
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'station_business_scope'
               AND COLUMN_NAME = 'branch_manager_user_id'
             LIMIT 1"
        );
        $stmt->execute();
        $nullable = $stmt->fetchColumn();
        if ($nullable === false) {
            throw new RuntimeException('Station business scope schema is unavailable.');
        }
        if (strtoupper((string) $nullable) !== 'YES') {
            $pdo->exec('ALTER TABLE station_business_scope MODIFY COLUMN branch_manager_user_id INT NULL');
        }
    }
}

if (!function_exists('map_pump_latest_station_inventory_sql')) {
    function map_pump_latest_station_inventory_sql(): string
    {
        return "SELECT soi.*
                FROM station_oil_inventory soi
                INNER JOIN (
                    SELECT station_id, oil_type_id, MAX(inventory_id) AS latest_inventory_id
                    FROM station_oil_inventory
                    GROUP BY station_id, oil_type_id
                ) latest ON latest.latest_inventory_id = soi.inventory_id";
    }
}

if (!function_exists('map_pump_append_station_price')) {
    function map_pump_append_station_price(PDO $pdo, int $stationId, int $oilTypeId, int $pricePerLitre, ?int $active = null): void
    {
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            // Booking creation locks this same branch row before its final
            // price read, so a newly published price cannot race the snapshot.
            $branchLock = $pdo->prepare('SELECT branch_id FROM branches WHERE branch_id = ? LIMIT 1 FOR UPDATE');
            $branchLock->execute([$stationId]);
            if (!$branchLock->fetchColumn()) {
                throw new RuntimeException('Cannot publish a price for a missing station.');
            }

            $stmt = $pdo->prepare(
                "SELECT is_active, current_amount_liters
                 FROM station_oil_inventory
                 WHERE station_id = :station_id AND oil_type_id = :oil_type_id
                 ORDER BY inventory_id DESC
                 LIMIT 1"
            );
            $stmt->execute([
                ':station_id' => $stationId,
                ':oil_type_id' => $oilTypeId,
            ]);
            $latest = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $isActive = $active ?? (int)($latest['is_active'] ?? 1);
            $currentAmount = (float)($latest['current_amount_liters'] ?? 0);

            $insert = $pdo->prepare(
                "INSERT INTO station_oil_inventory
                    (station_id, oil_type_id, price_per_litre, is_active, current_amount_liters, updated_at)
                 VALUES
                    (:station_id, :oil_type_id, :price_per_litre, :is_active, :current_amount_liters, NOW())"
            );
            $insert->execute([
                ':station_id' => $stationId,
                ':oil_type_id' => $oilTypeId,
                ':price_per_litre' => $pricePerLitre,
                ':is_active' => $isActive ? 1 : 0,
                ':current_amount_liters' => $currentAmount,
            ]);
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

if (!function_exists('map_pump_append_station_inventory_snapshot')) {
    /**
     * Preserve a traceable inventory history. Public queries always read the
     * newest row, so stock and availability change together without rewriting
     * older price records.
     */
    function map_pump_append_station_inventory_snapshot(PDO $pdo, int $stationId, int $oilTypeId, float $amountLiters, ?int $active = null): void
    {
        $stmt = $pdo->prepare(
            "SELECT price_per_litre, is_active
             FROM station_oil_inventory
             WHERE station_id = :station_id AND oil_type_id = :oil_type_id
             ORDER BY inventory_id DESC
             LIMIT 1"
        );
        $stmt->execute([
            ':station_id' => $stationId,
            ':oil_type_id' => $oilTypeId,
        ]);
        $latest = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$latest) {
            throw new RuntimeException('Station fuel inventory has not been initialized.');
        }

        $insert = $pdo->prepare(
            "INSERT INTO station_oil_inventory
                (station_id, oil_type_id, price_per_litre, is_active, current_amount_liters, updated_at)
             VALUES
                (:station_id, :oil_type_id, :price_per_litre, :is_active, :current_amount_liters, NOW())"
        );
        $insert->execute([
            ':station_id' => $stationId,
            ':oil_type_id' => $oilTypeId,
            ':price_per_litre' => (int) $latest['price_per_litre'],
            ':is_active' => $active ?? (int) $latest['is_active'],
            ':current_amount_liters' => max(0, $amountLiters),
        ]);
    }
}
