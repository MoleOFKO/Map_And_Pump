<?php

if (!function_exists('map_pump_current_hq_id')) {
    function map_pump_current_hq_id(PDO $pdo, ?int $userId = null): int
    {
        $userId = $userId ?? (int)($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return 0;
        }

        $stmt = $pdo->prepare(
            "SELECT COALESCE(u.headquarter_id, bas.headquarter_id) AS headquarter_id
             FROM users u
             LEFT JOIN business_account_scope bas ON bas.user_id = u.user_id
             WHERE u.user_id = ?
             LIMIT 1"
        );
        $stmt->execute([$userId]);

        return (int)($stmt->fetchColumn() ?: 0);
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
