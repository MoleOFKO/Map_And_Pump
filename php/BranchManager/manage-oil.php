<?php
/**
 * manage-oil.php  —  Fuel tanks
 * POST actions (all JSON-out):
 *   action=save_limits    -> alert_p92, alert_p95, alert_dsl
 *   action=toggle_fuel    -> tank_id, enabled (0|1)
 */
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../business_scope.php';
require_once __DIR__ . '/../loyalty_config.php';
require_branch_manager();

function map_pump_validate_alert_pct(mixed $raw): int {
    $value = trim((string)($raw ?? ''));
    if ($value === '' || !preg_match('/^\d+$/', $value)) {
        throw new InvalidArgumentException('Low stock threshold must be a whole number between 5 and 80 percent.');
    }

    $pct = (int)$value;
    if ($pct < 5 || $pct > 80) {
        throw new InvalidArgumentException('Low stock threshold must be between 5 and 80 percent.');
    }

    return $pct;
}

$ctx      = current_context($pdo);
$user     = $ctx['user'];
$branch   = $ctx['branch'];
$initials = initials($user['full_name']);
$bid      = (int)$branch['branch_id'];
$alerts       = notif_recent($pdo, (int)$user['user_id'], 5);
$unread_count = notif_unread_count($pdo, (int)$user['user_id']);
$has_unread   = $unread_count > 0;

function map_pump_normalize_fuel_code(?string $raw): string {
    $code = strtolower(trim((string)($raw ?? '')));
    $code = preg_replace('/[^a-z0-9_-]+/', '', $code);
    return $code;
}

function map_pump_oil_type_id_from_aliases(PDO $pdo, array $aliases): ?int {
    $clean = [];
    foreach ($aliases as $alias) {
        $name = trim((string)$alias);
        if ($name !== '') {
            $clean[] = $name;
        }
    }
    if (!$clean) {
        return null;
    }

    $placeholders = implode(',', array_fill(0, count($clean), '?'));
    $stmt = $pdo->prepare("SELECT oil_type_id FROM oil_types WHERE LOWER(oil_name) IN ({$placeholders}) ORDER BY oil_type_id LIMIT 1");
    $stmt->execute(array_map('strtolower', $clean));
    $oilTypeId = $stmt->fetchColumn();
    return $oilTypeId === false ? null : (int)$oilTypeId;
}

function map_pump_table_has_column(PDO $pdo, string $tableName, string $columnName): bool {
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND COLUMN_NAME = :column_name"
        );
        $stmt->execute([':table_name' => $tableName, ':column_name' => $columnName]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function map_pump_get_or_create_oil_type(PDO $pdo, string $fuelName): int {
    $name = trim((string)$fuelName);
    if ($name === '') {
        throw new InvalidArgumentException('Fuel name is required.');
    }

    $select = $pdo->prepare("SELECT oil_type_id FROM oil_types WHERE LOWER(oil_name) = LOWER(:oil_name) LIMIT 1");
    $select->execute([':oil_name' => $name]);
    $existing = (int)($select->fetchColumn() ?: 0);
    if ($existing > 0) {
        return $existing;
    }

    $insert = $pdo->prepare("INSERT INTO oil_types (oil_name) VALUES (:oil_name)");
    $insert->execute([':oil_name' => $name]);
    return (int)$pdo->lastInsertId();
}

function map_pump_delete_unused_oil_type(PDO $pdo, int $branchId, string $fuelName): void {
    $name = trim((string)$fuelName);
    if ($name === '') {
        return;
    }

    $usedByOtherTanks = $pdo->prepare(
        "SELECT t.tank_id
         FROM tanks t
         LEFT JOIN oil_types ot ON ot.oil_type_id = t.oil_type_id
         WHERE t.branch_id = :branch_id AND LOWER(ot.oil_name) = LOWER(:fuel_name)
         LIMIT 1"
    );
    $usedByOtherTanks->execute([':branch_id' => $branchId, ':fuel_name' => $name]);
    if ($usedByOtherTanks->fetchColumn()) {
        return;
    }

    $oilLookup = $pdo->prepare("SELECT oil_type_id FROM oil_types WHERE LOWER(oil_name) = LOWER(:oil_name) LIMIT 1");
    $oilLookup->execute([':oil_name' => $name]);
    $oilTypeId = (int)($oilLookup->fetchColumn() ?: 0);
    if ($oilTypeId > 0) {
        $pdo->prepare("DELETE FROM oil_types WHERE oil_type_id = :oil_type_id LIMIT 1")->execute([':oil_type_id' => $oilTypeId]);
    }
}

function map_pump_build_tank_update_sql(PDO $pdo, int $tankId, int $branchId, string $fuelName, int $capacity, ?float $price = null, ?int $oilTypeId = null): array {
    $setParts = [
        'capacity_l = :capacity_l',
        'updated_at = NOW()',
    ];
    $params = [
        ':capacity_l' => $capacity,
        ':tank_id' => $tankId,
        ':branch_id' => $branchId,
    ];

    if ($oilTypeId !== null && map_pump_table_has_column($pdo, 'tanks', 'oil_type_id')) {
        $setParts[] = 'oil_type_id = :oil_type_id';
        $params[':oil_type_id'] = $oilTypeId;
    }

    $sql = "UPDATE tanks SET " . implode(', ', $setParts) . " WHERE tank_id = :tank_id AND branch_id = :branch_id";
    return [$sql, $params];
}

$defaultFuelAliases = [
    'p92' => ['Petrol 92', 'Octane 92'],
    'p95' => ['Petrol 95', 'Octane 95'],
    'dsl' => ['Diesel', 'Disel'],
    'pdsl' => ['Premium Diesel', 'Premium Disel'],
];

function map_pump_load_branch_tank_rows(PDO $pdo, int $branchId): array {
    if (map_pump_table_has_column($pdo, 'tanks', 'fuel_code')) {
        $stmt = $pdo->prepare("SELECT fuel_code, fuel_name FROM tanks WHERE branch_id = :bid GROUP BY fuel_code, fuel_name ORDER BY fuel_name");
        $stmt->execute([':bid' => $branchId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmt = $pdo->prepare(
        "SELECT t.tank_id, t.branch_id, t.oil_type_id, t.capacity_l, t.current_l, t.alert_pct, t.is_enabled, t.updated_at,
                ot.oil_name AS fuel_name
         FROM tanks t
         LEFT JOIN oil_types ot ON ot.oil_type_id = t.oil_type_id
         WHERE t.branch_id = :bid
         ORDER BY ot.oil_name, t.tank_id"
    );
    $stmt->execute([':bid' => $branchId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $fuelName = trim((string)($row['fuel_name'] ?? ''));
        $code = map_pump_normalize_fuel_code($fuelName !== '' ? $fuelName : 'fuel_' . ($row['tank_id'] ?? 0));
        $row['fuel_code'] = $code;
        $row['fuel_name'] = $fuelName !== '' ? $fuelName : 'Fuel ' . ($row['tank_id'] ?? 0);
    }
    unset($row);

    return $rows;
}

function map_pump_fetch_branch_tanks(PDO $pdo, int $branchId): array {
    $stmt = $pdo->prepare(
        "SELECT t.*, ot.oil_name AS fuel_name
         FROM tanks t
         LEFT JOIN oil_types ot ON ot.oil_type_id = t.oil_type_id
         WHERE t.branch_id = :b
         ORDER BY t.tank_id ASC"
    );
    $stmt->execute([':b' => $branchId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $tanks = [];
    foreach ($rows as $tank) {
        $code = map_pump_table_has_column($pdo, 'tanks', 'fuel_code')
            ? (string)($tank['fuel_code'] ?? '')
            : map_pump_normalize_fuel_code((string)($tank['fuel_name'] ?? ''));
        if ($code === '') {
            $code = 'fuel_' . (int)($tank['tank_id'] ?? 0);
        }
        $tank['fuel_code'] = $code;
        $tank['fuel_name'] = trim((string)($tank['fuel_name'] ?? '')) !== '' ? trim((string)($tank['fuel_name'] ?? '')) : 'Fuel ' . (int)($tank['tank_id'] ?? 0);
        $tanks[$code] = $tank;
    }

    return $tanks;
}

$fuelCodeToOilName = [];
$fuelCodeToOilAliases = [];

$branchFuelRows = map_pump_load_branch_tank_rows($pdo, $bid);

foreach ($branchFuelRows as $row) {
    $code = map_pump_normalize_fuel_code((string)($row['fuel_code'] ?? ''));
    if ($code === '') continue;
    $name = trim((string)($row['fuel_name'] ?? ''));
    $fuelCodeToOilName[$code] = $name !== '' ? $name : ucfirst($code);
    $fuelCodeToOilAliases[$code] = [$name !== '' ? $name : ucfirst($code)];
}

foreach ($defaultFuelAliases as $code => $aliases) {
    $normCode = map_pump_normalize_fuel_code($code);
    if (!isset($fuelCodeToOilName[$normCode])) {
        $fuelCodeToOilName[$normCode] = $aliases[0];
    }
    if (!isset($fuelCodeToOilAliases[$normCode])) {
        $fuelCodeToOilAliases[$normCode] = $aliases;
    }
}

foreach ($fuelCodeToOilName as $code => $name) {
    if (!isset($fuelCodeToOilAliases[$code])) {
        $fuelCodeToOilAliases[$code] = [$name];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) json_out(['ok' => false, 'error' => 'CSRF failed'], 403);
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add_fuel_type') {
            $rawName = trim((string)($_POST['fuel_name'] ?? ''));
            $capacity = (int)($_POST['capacity_l'] ?? 0);
            $alertPct = map_pump_validate_alert_pct($_POST['alert_pct'] ?? null);

            $fuelCode = map_pump_normalize_fuel_code($rawName);
            if ($fuelCode === '') {
                json_out(['ok' => false, 'error' => 'Fuel display name is required.'], 422);
            }
            if ($rawName === '') {
                json_out(['ok' => false, 'error' => 'Fuel display name is required.'], 422);
            }
            if ($capacity <= 0 || $capacity > 200000) {
                json_out(['ok' => false, 'error' => 'Tank capacity must be between 1 and 200000 liters.'], 422);
            }

            $pdo->beginTransaction();
            try {
                $oilTypeId = map_pump_get_or_create_oil_type($pdo, $rawName);

                if (map_pump_table_has_column($pdo, 'tanks', 'fuel_code')) {
                    $dupCode = $pdo->prepare("SELECT tank_id FROM tanks WHERE branch_id = :bid AND LOWER(fuel_code) = LOWER(:fuel_code) LIMIT 1");
                    $dupCode->execute([':bid' => $bid, ':fuel_code' => $fuelCode]);
                    if ($dupCode->fetchColumn()) {
                        throw new RuntimeException('This fuel code already exists for this branch.');
                    }
                }

                $dupName = $pdo->prepare(
                    "SELECT t.tank_id
                     FROM tanks t
                     LEFT JOIN oil_types ot ON ot.oil_type_id = t.oil_type_id
                     WHERE t.branch_id = :bid AND LOWER(ot.oil_name) = LOWER(:fuel_name)
                     LIMIT 1"
                );
                $dupName->execute([':bid' => $bid, ':fuel_name' => $rawName]);
                if ($dupName->fetchColumn()) {
                    json_out(['ok' => false, 'error' => 'This fuel name already exists for this branch.'], 422);
                }

                if (map_pump_table_has_column($pdo, 'tanks', 'fuel_code')) {
                    $insertTank = $pdo->prepare(
                        "INSERT INTO tanks (branch_id, fuel_code, fuel_name, capacity_l, current_l, alert_pct, is_enabled, updated_at)
                         VALUES (:branch_id, :fuel_code, :fuel_name, :capacity_l, 0, :alert_pct, 1, NOW())"
                    );
                    $insertTank->execute([
                        ':branch_id' => $bid,
                        ':fuel_code' => $fuelCode,
                        ':fuel_name' => $rawName,
                        ':capacity_l' => $capacity,
                        ':alert_pct' => $alertPct,
                    ]);
                } else {
                    $insertTank = $pdo->prepare(
                        "INSERT INTO tanks (branch_id, oil_type_id, capacity_l, current_l, alert_pct, is_enabled, updated_at)
                         VALUES (:branch_id, :oil_type_id, :capacity_l, 0, :alert_pct, 1, NOW())"
                    );
                    $insertTank->execute([
                        ':branch_id' => $bid,
                        ':oil_type_id' => $oilTypeId,
                        ':capacity_l' => $capacity,
                        ':alert_pct' => $alertPct,
                    ]);
                }

                $newTankId = (int)$pdo->lastInsertId();
                $pdo->commit();
                log_activity($pdo, $bid, 'user', $user['user_id'], $user['full_name'], "Added new fuel type {$rawName} ({$fuelCode})");
                json_out(['ok' => true, 'fuel_code' => $fuelCode, 'fuel_name' => $rawName, 'tank_id' => $newTankId]);
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        // refill add/delete handlers
        if ($action === 'add_refill') {
            $tankId = (int)($_POST['tank_id'] ?? 0);
            $quantity = (float)($_POST['quantity_l'] ?? 0);
            $refillDate = map_pump_normalize_refill_date($_POST['refill_date'] ?? null) ?: date('Y-m-d H:i:s');

            if ($tankId <= 0) {
                json_out(['ok' => false, 'error' => 'Tank is required.'], 422);
            }
            if (!is_numeric($_POST['quantity_l'] ?? null) || $quantity <= 0) {
                json_out(['ok' => false, 'error' => 'Refill quantity must be greater than 0 liters.'], 422);
            }

            $tankStmt = $pdo->prepare(
                "SELECT t.tank_id, t.branch_id, t.oil_type_id, ot.oil_name AS fuel_name, t.current_l, t.capacity_l, t.is_enabled
                 FROM tanks t
                 LEFT JOIN oil_types ot ON ot.oil_type_id = t.oil_type_id
                 WHERE t.tank_id = :tank_id AND t.branch_id = :branch_id LIMIT 1"
            );
            $tankStmt->execute([':tank_id' => $tankId, ':branch_id' => $bid]);
            $tankRow = $tankStmt->fetch(PDO::FETCH_ASSOC);
            if (!$tankRow) {
                json_out(['ok' => false, 'error' => 'Fuel tank not found for this branch.'], 404);
            }

            $current = (float)($tankRow['current_l'] ?? 0);
            $capacity = (float)($tankRow['capacity_l'] ?? 0);
            $nextStock = $current + $quantity;
            if ($nextStock > $capacity) {
                json_out(['ok' => false, 'error' => 'Refill would exceed this tank capacity.'], 422);
            }

            $pdo->beginTransaction();
            try {
                $updateStmt = $pdo->prepare("UPDATE tanks SET current_l = :current_l, updated_at = NOW() WHERE tank_id = :tank_id AND branch_id = :branch_id");
                $updateStmt->execute([
                    ':current_l' => $nextStock,
                    ':tank_id' => $tankId,
                    ':branch_id' => $bid,
                ]);

                $support = map_pump_refill_support($pdo);
                if ($support['table_exists']) {
                    $insertCols = [];
                    $insertValues = [];
                    $params = [];

                    if ($support['tank_id']) {
                        $insertCols[] = $support['tank_id'];
                        $insertValues[] = ':tank_id';
                        $params[':tank_id'] = $tankId;
                    }
                    if ($support['branch_id']) {
                        $insertCols[] = $support['branch_id'];
                        $insertValues[] = ':branch_id';
                        $params[':branch_id'] = $bid;
                    }
                    if ($support['fuel_code']) {
                        $insertCols[] = $support['fuel_code'];
                        $insertValues[] = ':fuel_code';
                        $params[':fuel_code'] = map_pump_normalize_fuel_code((string)($tankRow['fuel_name'] ?? '')) ?: 'fuel_' . (int)($tankId ?: 0);
                    }
                    if ($support['qty_l']) {
                        $insertCols[] = $support['qty_l'];
                        $insertValues[] = ':quantity_l';
                        $params[':quantity_l'] = $quantity;
                    }
                    if ($support['date_col']) {
                        $insertCols[] = $support['date_col'];
                        $insertValues[] = ':update_at';
                        $params[':update_at'] = $refillDate;
                    }

                    if ($insertCols && $insertValues) {
                        $sql = 'INSERT INTO tank_refill (' . implode(', ', $insertCols) . ') VALUES (' . implode(', ', $insertValues) . ')';
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute($params);
                      }
                }

                $pdo->commit();
                log_activity($pdo, $bid, 'user', $user['user_id'], $user['full_name'], 'Refilled fuel tank ' . ($tankRow['fuel_name'] ?? 'Unknown') . ' by ' . number_format($quantity, 2) . ' L');
                json_out(['ok' => true, 'tank_id' => $tankId, 'quantity_l' => $quantity, 'current_l' => $nextStock]);
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        if ($action === 'delete_refill') {
            $refillId = (int)($_POST['refill_id'] ?? 0);
            $tankId = (int)($_POST['tank_id'] ?? 0);
            $support = map_pump_refill_support($pdo);

            if (!$support['table_exists']) {
                json_out(['ok' => true]);
            }

            $pdo->beginTransaction();
            try {
                $row = null;
                if ($refillId > 0) {
                    $fields = implode(', ', array_unique(array_filter([
                        'refill_id', 'id', 'tank_refill_id', 'record_id', 'transaction_id'
                    ], fn($value) => in_array(strtolower($value), map_pump_get_table_columns($pdo, 'tank_refill'), true))));
                    $lookup = 'SELECT * FROM tank_refill WHERE ' . ($fields !== '' ? $fields . ' = :refill_id' : '1 = 0') . ' LIMIT 1';
                    $stmt = $pdo->prepare($lookup);
                    $stmt->execute([':refill_id' => $refillId]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                } elseif ($tankId > 0 && $support['tank_id']) {
                    $sql = 'SELECT * FROM tank_refill WHERE ' . $support['tank_id'] . ' = :tank_id ORDER BY ' . ($support['date_col'] ?: 'created_at') . ' DESC LIMIT 1';
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([':tank_id' => $tankId]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                }

                if ($row) {
                    $qtyCol = $support['qty_l'] ?: null;
                    $qty = 0.0;
                    if ($qtyCol && isset($row[$qtyCol])) {
                        $qty = (float)$row[$qtyCol];
                    }
                    $deleteSql = 'DELETE FROM tank_refill WHERE ' . ($refillId > 0 ? (map_pump_first_matching_column(map_pump_get_table_columns($pdo, 'tank_refill'), ['refill_id', 'id', 'tank_refill_id', 'record_id', 'transaction_id']) ?: 'id') . ' = :refill_id' : $support['tank_id'] . ' = :tank_id') . ' ORDER BY ' . ($support['date_col'] ?: 'created_at') . ' DESC LIMIT 1';
                    $deleteStmt = $pdo->prepare($deleteSql);
                    $deleteStmt->execute($refillId > 0 ? [':refill_id' => $refillId] : [':tank_id' => $tankId]);

                    if ($tankId > 0 && $qty > 0) {
                        $tankS = $pdo->prepare("SELECT current_l FROM tanks WHERE tank_id = :tank_id AND branch_id = :branch_id LIMIT 1");
                        $tankS->execute([':tank_id' => $tankId, ':branch_id' => $bid]);
                        $current = (float)($tankS->fetchColumn() ?: 0);
                        $newCurrent = max(0.0, $current - $qty);
                        $pdo->prepare("UPDATE tanks SET current_l = :current_l, updated_at = NOW() WHERE tank_id = :tank_id AND branch_id = :branch_id")->execute([
                            ':current_l' => $newCurrent,
                            ':tank_id' => $tankId,
                            ':branch_id' => $bid,
                        ]);
                    }
                }

                $pdo->commit();
                json_out(['ok' => true]);
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        if ($action === 'save_limits') {
            $available = $pdo->prepare(
                "SELECT t.tank_id, t.alert_pct, ot.oil_name AS fuel_name
                 FROM tanks t
                 LEFT JOIN oil_types ot ON ot.oil_type_id = t.oil_type_id
                 WHERE t.branch_id = :b
                 ORDER BY ot.oil_name, t.tank_id"
            );
            $available->execute([':b' => $bid]);
            $fuelRows = $available->fetchAll(PDO::FETCH_ASSOC);

            if (!$fuelRows) {
                json_out(['ok' => false, 'error' => 'No fuel tanks are configured for this branch yet.'], 422);
            }

            $nextLimits = [];
            foreach ($fuelRows as $row) {
                $fc = map_pump_normalize_fuel_code((string)($row['fuel_name'] ?? ''));
                if ($fc === '') continue;
                $rawLimit = $_POST['alert_' . $fc] ?? null;
                try {
                    $nextLimits[$fc] = map_pump_validate_alert_pct($rawLimit);
                } catch (InvalidArgumentException $e) {
                    json_out(['ok' => false, 'error' => $e->getMessage()], 422);
                }
            }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("UPDATE tanks SET alert_pct = :p WHERE tank_id = :tank_id AND branch_id = :b");
                foreach ($fuelRows as $row) {
                    $fc = map_pump_normalize_fuel_code((string)($row['fuel_name'] ?? ''));
                    if ($fc === '' || !isset($nextLimits[$fc])) continue;
                    $stmt->execute([':p' => $nextLimits[$fc], ':tank_id' => (int)($row['tank_id'] ?? 0), ':b' => $bid]);
                }
                $pdo->commit();
                log_activity($pdo, $bid, 'user', $user['user_id'], $user['full_name'], 'Updated fuel alert thresholds');
                json_out(['ok' => true, 'limits' => $nextLimits]);
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        if ($action === 'toggle_fuel') {
            $tank_id = (int)($_POST['tank_id'] ?? 0);
            $enabled = (int)($_POST['enabled'] ?? 0) === 1 ? 1 : 0;
            $stmt = $pdo->prepare("SELECT tank_id FROM tanks WHERE tank_id = :t AND branch_id = :b LIMIT 1");
            $stmt->execute([':t' => $tank_id, ':b' => $bid]);
            if (!$stmt->fetchColumn()) {
                json_out(['ok' => false, 'error' => 'Tank not found for this station'], 404);
            }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("UPDATE tanks SET is_enabled = :e, updated_at = NOW() WHERE tank_id = :t AND branch_id = :b");
                $stmt->execute([':e' => $enabled, ':t' => $tank_id, ':b' => $bid]);
                $pdo->commit();
                log_activity($pdo, $bid, 'user', $user['user_id'], $user['full_name'], ($enabled ? 'Enabled' : 'Disabled') . " fuel type (tank #{$tank_id})");
                json_out(['ok' => true]);
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        if ($action === 'delete_fuel_type') {
            $tankId = (int)($_POST['tank_id'] ?? 0);
            if ($tankId <= 0) {
                json_out(['ok' => false, 'error' => 'Tank id is required.'], 422);
            }

            $tankStmt = $pdo->prepare(
                "SELECT t.tank_id, t.branch_id, t.oil_type_id, ot.oil_name AS fuel_name
                 FROM tanks t
                 LEFT JOIN oil_types ot ON ot.oil_type_id = t.oil_type_id
                 WHERE t.tank_id = :tank_id AND t.branch_id = :branch_id LIMIT 1"
            );
            $tankStmt->execute([':tank_id' => $tankId, ':branch_id' => $bid]);
            $tankRow = $tankStmt->fetch(PDO::FETCH_ASSOC);
            if (!$tankRow) {
                json_out(['ok' => false, 'error' => 'Fuel tank not found for this branch.'], 404);
            }

            $fuelCode = map_pump_normalize_fuel_code((string)($tankRow['fuel_name'] ?? ''));
            $fuelName = trim((string)($tankRow['fuel_name'] ?? ''));

            $pdo->beginTransaction();
            try {
                $deleteTank = $pdo->prepare("DELETE FROM tanks WHERE tank_id = :tank_id AND branch_id = :branch_id");
                $deleteTank->execute([':tank_id' => $tankId, ':branch_id' => $bid]);

                if ($fuelName !== '') {
                    $unusedFuelCheck = $pdo->prepare(
                        "SELECT t.tank_id
                         FROM tanks t
                         LEFT JOIN oil_types ot ON ot.oil_type_id = t.oil_type_id
                         WHERE t.branch_id = :branch_id AND LOWER(ot.oil_name) = LOWER(:fuel_name)
                         LIMIT 1"
                    );
                    $unusedFuelCheck->execute([':branch_id' => $bid, ':fuel_name' => $fuelName]);
                    if (!$unusedFuelCheck->fetchColumn()) {
                        $oilLookup = $pdo->prepare("SELECT oil_type_id FROM oil_types WHERE LOWER(oil_name) = LOWER(:oil_name) LIMIT 1");
                        $oilLookup->execute([':oil_name' => $fuelName]);
                        $oilTypeId = (int)($oilLookup->fetchColumn() ?: 0);
                        if ($oilTypeId > 0) {
                            $deleteOil = $pdo->prepare("DELETE FROM oil_types WHERE oil_type_id = :oil_type_id LIMIT 1");
                            $deleteOil->execute([':oil_type_id' => $oilTypeId]);
                        }
                    }
                }

                $pdo->commit();
                log_activity($pdo, $bid, 'user', $user['user_id'], $user['full_name'], "Deleted fuel type {$fuelName} ({$fuelCode})");
                json_out(['ok' => true]);
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        if ($action === 'edit_fuel_type') {
            $tankId = (int)($_POST['tank_id'] ?? 0);
            $fuelName = trim((string)($_POST['fuel_name'] ?? ''));
            $capacity = (int)($_POST['capacity_l'] ?? 0);
            $price = trim((string)($_POST['price_per_litre'] ?? ''));
            $fuelCode = map_pump_normalize_fuel_code((string)($_POST['fuel_code'] ?? ''));

            if ($tankId <= 0) json_out(['ok' => false, 'error' => 'Tank id is required.'], 422);
            if ($fuelName === '') json_out(['ok' => false, 'error' => 'Fuel name is required.'], 422);
            if ($capacity <= 0) json_out(['ok' => false, 'error' => 'Tank capacity must be greater than 0 liters.'], 422);
            if ($price === '' || !is_numeric($price) || (float)$price < 0) json_out(['ok' => false, 'error' => 'Enter a valid non-negative price.'], 422);

            $tankStmt = $pdo->prepare(
                "SELECT t.tank_id, t.branch_id, t.fuel_code, t.fuel_name, t.capacity_l, t.current_l, t.is_enabled, t.oil_type_id,
                        ot.oil_name AS fuel_name
                 FROM tanks t
                 LEFT JOIN oil_types ot ON ot.oil_type_id = t.oil_type_id
                 WHERE t.tank_id = :tank_id AND t.branch_id = :branch_id
                 LIMIT 1"
            );
            $tankStmt->execute([':tank_id' => $tankId, ':branch_id' => $bid]);
            $existing = $tankStmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing) json_out(['ok' => false, 'error' => 'Fuel tank not found for this branch.'], 404);

            if ($capacity < (float)($existing['current_l'] ?? 0)) {
                json_out(['ok' => false, 'error' => 'Capacity cannot be lower than the current stock level.'], 422);
            }

            $existingCode = map_pump_normalize_fuel_code((string)($existing['fuel_name'] ?? ''));
            if ($fuelCode !== '' && $fuelCode !== $existingCode) {
                json_out(['ok' => false, 'error' => 'Fuel code is fixed and cannot be changed.'], 422);
            }

            $duplicateStmt = $pdo->prepare(
                "SELECT t.tank_id
                 FROM tanks t
                 LEFT JOIN oil_types ot ON ot.oil_type_id = t.oil_type_id
                 WHERE t.branch_id = :branch_id AND LOWER(ot.oil_name) = LOWER(:fuel_name) AND t.tank_id != :tank_id
                 LIMIT 1"
            );
            $duplicateStmt->execute([':branch_id' => $bid, ':fuel_name' => $fuelName, ':tank_id' => $tankId]);
            if ($duplicateStmt->fetchColumn()) {
                json_out(['ok' => false, 'error' => 'This fuel name already exists for this branch.'], 422);
            }

            $previousFuelName = trim((string)($existing['fuel_name'] ?? ''));
            $nameChanged = strtolower($previousFuelName) !== strtolower($fuelName);
            $existingOilTypeId = (int)($existing['oil_type_id'] ?? 0);
            $newOilTypeId = $existingOilTypeId > 0 ? $existingOilTypeId : map_pump_get_or_create_oil_type($pdo, $previousFuelName);

            $pdo->beginTransaction();
            try {
                if ($nameChanged) {
                    $newOilTypeId = map_pump_get_or_create_oil_type($pdo, $fuelName);
                }

                $setParts = ['capacity_l = :capacity_l', 'updated_at = NOW()'];
                $params = [
                    ':capacity_l' => $capacity,
                    ':tank_id' => $tankId,
                    ':branch_id' => $bid,
                ];

                if (map_pump_table_has_column($pdo, 'tanks', 'oil_type_id')) {
                    $setParts[] = 'oil_type_id = :oil_type_id';
                    $params[':oil_type_id'] = $newOilTypeId;
                }

                $sql = 'UPDATE tanks SET ' . implode(', ', $setParts) . ' WHERE tank_id = :tank_id AND branch_id = :branch_id';
                $update = $pdo->prepare($sql);
                $update->execute($params);

                if ($nameChanged) {
                    $renameStmt = $pdo->prepare("UPDATE oil_types SET oil_name = :oil_name WHERE oil_type_id = :oil_type_id LIMIT 1");
                    $renameStmt->execute([':oil_name' => $fuelName, ':oil_type_id' => $newOilTypeId]);
                }

                if ($nameChanged && $existingOilTypeId > 0) {
                    $usedByOtherTank = $pdo->prepare(
                        "SELECT t.tank_id
                         FROM tanks t
                         LEFT JOIN oil_types ot ON ot.oil_type_id = t.oil_type_id
                         WHERE t.branch_id = :branch_id AND LOWER(ot.oil_name) = LOWER(:fuel_name) AND t.tank_id != :tank_id
                         LIMIT 1"
                    );
                    $usedByOtherTank->execute([
                        ':branch_id' => $bid,
                        ':fuel_name' => $previousFuelName,
                        ':tank_id' => $tankId,
                    ]);
                    if (!$usedByOtherTank->fetchColumn()) {
                        $pdo->prepare("DELETE FROM oil_types WHERE oil_type_id = :oil_type_id LIMIT 1")->execute([':oil_type_id' => $existingOilTypeId]);
                    }
                }

                $pdo->commit();
                log_activity($pdo, $bid, 'user', $user['user_id'], $user['full_name'], "Edited fuel type {$fuelName} ({$existingCode})");
                json_out(['ok' => true, 'fuel_name' => $fuelName, 'capacity_l' => $capacity, 'price_per_litre' => (int)round((float)$price)]);
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        json_out(['ok' => false, 'error' => 'Unknown action'], 400);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_out(['ok' => false, 'error' => $e->getMessage()], 500);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_out(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

// -------- Load data --------
$stmt = $pdo->prepare(
    "SELECT t.*, ot.oil_name AS fuel_name
     FROM tanks t
     LEFT JOIN oil_types ot ON ot.oil_type_id = t.oil_type_id
     WHERE t.branch_id = :b
     ORDER BY t.tank_id ASC"
);
$stmt->execute([':b' => $bid]);
$tanks_rows = $stmt->fetchAll();
$tanks = [];
foreach ($tanks_rows as $t) {
    $code = map_pump_table_has_column($pdo, 'tanks', 'fuel_code')
        ? (string)($t['fuel_code'] ?? '')
        : map_pump_normalize_fuel_code((string)($t['fuel_name'] ?? ''));
    if ($code === '') {
        $code = 'fuel_' . (int)($t['tank_id'] ?? 0);
    }
    $t['fuel_code'] = $code;
    if (trim((string)($t['fuel_name'] ?? '')) === '') {
        $t['fuel_name'] = 'Fuel ' . (int)($t['tank_id'] ?? 0);
    }
    $tanks[$code] = $t;
}

$tank_defs = [];
foreach ($tanks as $fc => $tank) {
    $tank_defs[$fc] = [
        'name' => $tank['fuel_name'] ?? ucfirst($fc),
        'id' => $fc,
        'cap' => (int)($tank['capacity_l'] ?? 0),
        'rem' => (float)($tank['current_l'] ?? 0),
    ];
}

$dynamicTankKeys = array_keys($tank_defs);
$dynamicPriceMap = [];
foreach ($tank_defs as $fc => $def) {
    $dynamicPriceMap[$fc] = ['capacity' => $def['cap'], 'remaining' => $def['rem'], 'alert' => (int)($tanks[$fc]['alert_pct'] ?? 25)];
}

$latestInventorySql = map_pump_latest_station_inventory_sql();
$priceByOilName = [];
if (map_pump_station_inventory_schema_ready($pdo)) {
    $priceStmt = $pdo->prepare(
        "SELECT ot.oil_name, li.price_per_litre
         FROM ({$latestInventorySql}) li
         INNER JOIN oil_types ot ON ot.oil_type_id = li.oil_type_id
         WHERE li.station_id = :b"
    );
    $priceStmt->execute([':b' => $bid]);
    foreach ($priceStmt->fetchAll(PDO::FETCH_ASSOC) as $priceRow) {
        $priceByOilName[strtolower((string)$priceRow['oil_name'])] = (int)$priceRow['price_per_litre'];
    }
}

$refillSupport = map_pump_refill_support($pdo);
$refillHistoryRows = map_pump_refill_history_rows($pdo, $bid);
$refillOptions = [];
foreach ($tanks as $fc => $tank) {
    if (!is_array($tank)) continue;
    $fuelName = trim((string)($tank['fuel_name'] ?? $fc));
    if ($fuelName === '') continue;
    $refillOptions[] = [
        'tank_id' => (int)($tank['tank_id'] ?? 0),
        'fuel_code' => (string)($tank['fuel_code'] ?? $fc),
        'fuel_name' => $fuelName,
        'current_l' => (float)($tank['current_l'] ?? 0),
        'capacity_l' => (float)($tank['capacity_l'] ?? 0),
        'is_enabled' => (int)($tank['is_enabled'] ?? 1),
    ];
}

// helper to compute % and color class
function tank_pct(?array $t): int {
    if (!$t || !isset($t['capacity_l']) || (float)$t['capacity_l'] <= 0) return 0;
    return (int)round(((float)($t['current_l'] ?? 0) / (float)$t['capacity_l']) * 100);
}

function tank_color(?array $t): string {
    if (!$t) return 'var(--slate-400)';
    $p = tank_pct($t);
    if ($p <= (int)($t['alert_pct'] ?? 25)) return 'var(--red-400)';
    if ($p < 60) return 'var(--amber-400)';
    return 'var(--mint-400)';
}

function tank_flag(?array $t): string {
    if (!$t) return '';
    $p = tank_pct($t);
    if ($p <= (int)($t['alert_pct'] ?? 25)) return '<span class="tank-flag low" id="flag-'.h($t['fuel_code']).'"><i class="fa-solid fa-triangle-exclamation"></i> Stock low — delivery needed</span>';
    if ($p < 60) return '<span class="tank-flag mid" id="flag-'.h($t['fuel_code']).'"><i class="fa-solid fa-circle-half-stroke"></i> Watch level</span>';
    return '<span class="tank-flag ok" id="flag-'.h($t['fuel_code']).'"><i class="fa-solid fa-check"></i> Stock sufficient</span>';
}

function map_pump_fuel_status_label(array $tank): string {
    $pct = tank_pct($tank);
    $alert = max(5, (int)($tank['alert_pct'] ?? 25));
    if ($pct <= $alert) return 'low';
    if ($pct < 60) return 'watch';
    return 'sufficient';
}

function map_pump_fuel_status_text(array $tank): string {
    $status = map_pump_fuel_status_label($tank);
    return $status === 'sufficient' ? 'Sufficient' : ($status === 'watch' ? 'Watch' : 'Low');
}

function map_pump_table_exists(PDO $pdo, string $tableName): bool {
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name"
        );
        $stmt->execute([':table_name' => $tableName]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function map_pump_get_table_columns(PDO $pdo, string $tableName): array {
    try {
        $stmt = $pdo->prepare(
            "SELECT COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
             ORDER BY ORDINAL_POSITION"
        );
        $stmt->execute([':table_name' => $tableName]);
        $cols = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        return array_map('strtolower', array_values($cols));
    } catch (Throwable $e) {
        return [];
    }
}

function map_pump_first_matching_column(array $columns, array $candidates): ?string {
    foreach ($candidates as $candidate) {
        $lookup = strtolower((string)$candidate);
        if (in_array($lookup, $columns, true)) {
            return $lookup;
        }
    }
    return null;
}

function map_pump_refill_support(PDO $pdo): array {
    $tableExists = map_pump_table_exists($pdo, 'tank_refill');
    $columns = $tableExists ? map_pump_get_table_columns($pdo, 'tank_refill') : [];
    return [
        'table_exists' => $tableExists,
        'columns' => $columns,
        'tank_id' => map_pump_first_matching_column($columns, ['tank_id', 'tankid']),
        'branch_id' => map_pump_first_matching_column($columns, ['branch_id', 'branchid', 'station_id', 'stationid']),
        'fuel_code' => map_pump_first_matching_column($columns, ['fuel_code', 'fuelcode', 'oil_code']),
        'fuel_name' => map_pump_first_matching_column($columns, ['fuel_name', 'fuelname', 'oil_name', 'oil_name_display']),
        'qty_l' => map_pump_first_matching_column($columns, ['quantity_l', 'qty_l', 'refill_liters', 'refill_quantity', 'quantity', 'amount_l', 'liters_added', 'added_litres', 'added_l', 'refill_amount', 'volume_l']),
        'date_col' => map_pump_first_matching_column($columns, ['refilled_at', 'refill_date', 'created_at', 'updated_at', 'update_at', 'transaction_at', 'recorded_at', 'date_added', 'logged_at']),
    ];
}

function map_pump_normalize_refill_date(?string $raw): ?string {
    $value = trim((string)($raw ?? ''));
    if ($value === '') {
        return null;
    }
    $dt = new DateTimeImmutable($value);
    return $dt->format('Y-m-d H:i:s');
}

function map_pump_refill_history_rows(PDO $pdo, int $branchId): array {
    $support = map_pump_refill_support($pdo);
    if (!$support['table_exists']) {
        return [];
    }

    $joinSql = 'FROM tank_refill tr';
    $where = [];
    $params = [];
    $select = 'SELECT tr.*';
    $orderBy = null;

    $allowedDateColumns = ['refilled_at', 'refill_date', 'transaction_at', 'created_at', 'updated_at', 'update_at', 'recorded_at', 'date_added', 'logged_at'];
    foreach ($allowedDateColumns as $candidate) {
        if (in_array($candidate, $support['columns'], true)) {
            $orderBy = $candidate;
            break;
        }
    }
    if ($orderBy === null) {
        $orderBy = map_pump_first_matching_column($support['columns'], ['refill_id', 'id', 'tank_refill_id', 'record_id', 'transaction_id']);
    }

    if ($support['branch_id']) {
        $where[] = 'tr.' . $support['branch_id'] . ' = :branch_id';
        $params[':branch_id'] = $branchId;
    }

    if ($support['tank_id']) {
        $select = 'SELECT tr.*, ot.oil_name AS tank_fuel_name, t.current_l AS tank_current_l';
        $joinSql = 'FROM tank_refill tr LEFT JOIN tanks t ON t.tank_id = tr.' . $support['tank_id'] . ' LEFT JOIN oil_types ot ON ot.oil_type_id = t.oil_type_id';
        if ($support['branch_id']) {
            $where[] = 't.branch_id = :branch_id';
        }
    }

    $sql = $select . ' ' . $joinSql;
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    if ($orderBy) {
        $sql .= ' ORDER BY tr.' . $orderBy . ' DESC';
    }
    $sql .= ' LIMIT 25';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MAP & PUMP — Manage Fuel Stock</title>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="icon" type="image/png" href="/images/Logo.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Noto+Sans+Myanmar:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
  :root{
    --navy-950:#070b14; --navy-900:#0b1220; --navy-800:#101a30; --navy-700:#16213f;
    --blue-700:#1d4ed8; --blue-500:#2f6feb; --sky-400:#38bdf8;
    --amber-400:#fbbf24; --amber-300:#fcd34d; --mint-400:#34d399;
    --red-400:#f87171;
    --slate-50:#f8fafc; --slate-300:#c9d2e3; --slate-400:#8b96ad;
  }
  *{box-sizing:border-box; margin:0; padding:0;}
  body{background:var(--navy-950); color:var(--slate-50); font-family:'Inter','Space Grotesk',sans-serif; overflow-x:hidden;}
  .disp{font-family:'Space Grotesk','Noto Sans Myanmar',sans-serif;}
  a{color:inherit; text-decoration:none;}
  button{font-family:inherit;}
  @media (prefers-reduced-motion: reduce){ *{animation-duration:0.001ms !important; animation-iteration-count:1 !important; transition: none !important;} }

  .app-shell{display:flex; min-height:100vh;}
  
  /* ---------- sidebar ---------- */
  .sidebar{
    width:252px; flex-shrink:0; background:var(--navy-900); border-right:1px solid rgba(255,255,255,0.06); 
    display:flex; flex-direction:column; padding:22px 16px; position:sticky; top:0; height:100vh; z-index:60;
  }
  .nav-logo {
    width: 32px;        
    height: auto;
    object-fit: contain;
  }
  .side-brand{display:flex; align-items:center; gap:10px; font-weight:700; font-size:17px; padding:6px 8px 0;}
  .side-role{font-size:11px; color:var(--slate-400); font-weight:500; margin:2px 0 0 46px;}
  .side-branch{
    margin:20px 8px 0; padding:10px 12px; background:var(--navy-800); border-radius:12px; 
    border:1px solid rgba(255,255,255,0.06); font-size:12.5px;
    transition: background 0.3s, border-color 0.3s;
    display:flex; align-items:center; gap:10px;
  }
  .side-branch:hover{
    background: var(--navy-700);
    border-color: rgba(251,191,36,0.25);
  }
  .side-branch .hq-logo{
    width:36px; height:36px; border-radius:8px; object-fit:cover; flex-shrink:0;
    background:var(--navy-950); border:1px solid rgba(255,255,255,0.08);
  }
  .side-branch .txt{min-width:0; flex:1;}
  .side-branch b{display:block; font-size:14px; font-family:'Space Grotesk'; margin-bottom:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;}
  .side-branch span{color:var(--slate-400);}
  .side-nav{margin-top:22px; display:flex; flex-direction:column; gap:4px; flex:1;}
  .side-link{
    display:flex; align-items:center; gap:12px; padding:11px 12px; border-radius:10px; font-size:14px; 
    color:var(--slate-300); font-weight:500;
    transition: background 0.25s ease, color 0.25s ease, transform 0.2s ease;
  }
  .side-link:hover{
    background:rgba(255,255,255,0.045); color:var(--slate-50);
    transform: translateX(4px);
  }
  .side-link.active{
    background:linear-gradient(135deg, rgba(251,191,36,0.16), rgba(29,78,216,0.08)); 
    color:var(--slate-50); box-shadow:inset 0 0 0 1px rgba(251,191,36,0.32);
  }
  .side-link.active:hover{transform: none;}
  .side-link .ic{font-size:16px; width:18px; text-align:center; transition: transform 0.2s ease;}
  .side-link:hover .ic{transform: scale(1.15);}
  .side-logout{
    display:flex; align-items:center; gap:12px; padding:12px; border-radius:10px; font-size:14px; 
    color:var(--red-400); font-weight:600; margin-top:14px; border-top:1px solid rgba(255,255,255,0.06); padding-top:18px;
    transition: background 0.25s, color 0.25s, padding-left 0.2s;
  }
  .side-logout:hover{background:rgba(248,113,113,0.08); padding-left: 16px;}

  /* ---------- main ---------- */
  .main{flex:1; min-width:0;}
  .topbar{
    position:sticky; top:0; z-index:50; display:flex; align-items:center; justify-content:space-between; 
    padding:16px 32px; background:rgba(7,11,20,0.72); backdrop-filter:blur(14px); border-bottom:1px solid rgba(255,255,255,0.06);
  }
  .topbar h1{font-size:18px; font-weight:700;}
  .topbar .date{font-size:12.5px; color:var(--slate-400); margin-top:2px; font-variant-numeric: tabular-nums;}
  .topbar-right{display:flex; align-items:center; gap:16px; position:relative;}
  
  .bell{
    width:36px; height:36px; border-radius:50%; background:var(--navy-800); display:flex; align-items:center; 
    justify-content:center; border:1px solid rgba(255,255,255,0.08); position:relative; font-size:15px; cursor:pointer;
    transition: transform 0.2s, background-color 0.2s;
  }
  .bell:hover{
    background: var(--navy-700);
    transform: scale(1.05);
  }
  .bell .ping{position:absolute; top:6px; right:7px; width:7px; height:7px; border-radius:50%; background:var(--amber-400); box-shadow:0 0 0 2px var(--navy-800);}
  
  .avatar-chip{display:flex; align-items:center; gap:10px; font-size:13px; cursor: pointer; padding: 4px 8px; border-radius: 99px; transition: background 0.2s;}
  .avatar-chip:hover{background: rgba(255,255,255,0.05);}
  .avatar-circle{width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,var(--sky-400),var(--blue-700)); display:flex; align-items:center; justify-content:center; font-weight:700; font-size:13px; font-family:'Space Grotesk';}

  /* ---------- Notification Popup Dropdown (dashboard style) ---------- */
  .notif-popup {
    position: absolute; top: 48px; right: 80px; width: 320px;
    background: var(--navy-800); border: 1px solid rgba(255,255,255,0.12);
    border-radius: 14px; box-shadow: 0 12px 30px rgba(0,0,0,0.5);
    z-index: 100; opacity: 0; pointer-events: none; transform: translateY(-10px);
    transition: opacity 0.25s ease, transform 0.25s ease;
  }
  .notif-popup.active { opacity: 1; pointer-events: auto; transform: translateY(0); }
  .notif-header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 12px 16px; border-bottom: 1px solid rgba(255,255,255,0.06);
  }
  .notif-header h4 { font-size: 13.5px; font-weight: 700; margin: 0; }
  .notif-header button { background: none; border: none; color: var(--amber-400); font-size: 11px; cursor: pointer; font-weight: 600; }
  .notif-header button:hover { text-decoration: underline; }
  .notif-list { max-height: 260px; overflow-y: auto; }
  .notif-item {
    display: flex; gap: 12px; padding: 12px 16px; border-bottom: 1px solid rgba(255,255,255,0.04);
    font-size: 12px; transition: background 0.2s; cursor: pointer;
  }
  .notif-item:last-child { border-bottom: none; }
  .notif-item:hover { background: rgba(255,255,255,0.03); }
  .notif-item .icon { width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 12px; }
  .notif-item.warning .icon { background: rgba(248,113,113,0.15); color: var(--red-400); }
  .notif-item.info .icon { background: rgba(56,189,248,0.15); color: var(--sky-400); }
  .notif-item.success .icon { background: rgba(52,211,153,0.15); color: var(--mint-400); }
  .notif-item .text b { display: block; color: var(--slate-50); margin-bottom: 2px; }
  .notif-item .text span { color: var(--slate-400); line-height: 1.3; display: block; }
  .notif-item .time { font-size: 10px; color: var(--slate-400); margin-top: 4px; }

  /* Content Entrance Fade */
  .content{
    padding:30px 32px 60px;
    opacity: 0;
    transform: translateY(10px);
    animation: layoutFadeIn 0.5s ease forwards;
  }
  .page-foot{
    text-align:center; margin-top:34px; padding-top:18px;
    border-top:1px solid rgba(255,255,255,0.06);
    font-size:12px; color:var(--slate-400);
  }
  @keyframes layoutFadeIn {
    to { opacity: 1; transform: translateY(0); }
  }

  .page-head{display:flex; align-items:center; justify-content:space-between; margin-bottom:26px; flex-wrap:wrap; gap:14px;}
  .page-head h2{font-size:20px; font-weight:700;}
  .page-head p{font-size:13px; color:var(--slate-400); margin-top:4px;}
  
  .btn-primary{
    background:linear-gradient(135deg, var(--amber-400), #d97706); color:#2b1704; font-weight:700; font-size:13.5px; 
    padding:11px 18px; border-radius:11px; border:none; cursor:pointer; display:inline-flex; align-items:center; gap:8px; 
    box-shadow:0 10px 24px -10px rgba(251,191,36,0.5);
    transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.25s;
  }
  .btn-primary:hover{
    transform: translateY(-2px);
    box-shadow:0 12px 28px -8px rgba(251,191,36,0.65);
  }
  .page-head-actions{display:flex; align-items:center; justify-content:flex-end; gap:12px; flex-wrap:wrap;}
  .page-head-actions .btn-primary{min-width:180px; justify-content:center; height:46px; padding:0 18px; border-radius:12px;}
  .action-btn-blue{background:linear-gradient(135deg, #3b8dff, #1d4ed8)!important; color:#fff!important; box-shadow:0 12px 26px -12px rgba(59,141,255,0.75)!important;}
  .action-btn-amber{background:linear-gradient(135deg, #164E63, #0f3d4d)!important; color:#f8fbff!important; box-shadow:0 12px 26px -12px rgba(22,78,99,0.72)!important;}
  .action-btn-refill{background:linear-gradient(135deg, rgba(59,141,255,0.18), rgba(29,78,216,0.18))!important; border:1px solid rgba(59,141,255,0.32)!important; color:#dfefff!important; box-shadow:none!important;}

  .stat-strip{display:grid; grid-template-columns:repeat(3,1fr); gap:14px; margin-bottom:26px;}
  /* ---------- Fuel Stocks Overview ---------- */
  .stock-overview{display:flex; align-items:flex-end; justify-content:space-between; gap:16px; margin-bottom:18px; flex-wrap:wrap;}
  .stock-overview h2{font-size:20px; font-weight:700; margin:0;}
  .stock-overview h2 span{display:inline-block; vertical-align:baseline;}
  .stock-subtitle{font-size:14px; color:var(--slate-400); margin-top:2px;}
  .stock-pills{display:flex; align-items:center; gap:8px; flex-wrap:wrap;}
  .stock-pill{display:inline-flex; align-items:center; justify-content:center; min-width:88px; border-radius:8px; padding:5px 10px; font-size:12px; font-weight:700; border:1px solid transparent;}
  .stock-pill.sufficient{background:rgba(15,118,110,0.22); color:#5eead4; border-color:rgba(94,234,212,0.25);}
  .stock-pill.watch{background:rgba(180,83,9,0.2); color:#fbbf24; border-color:rgba(251,191,36,0.25);}
  .stock-pill.low{background:rgba(127,29,29,0.22); color:#fca5a5; border-color:rgba(252,165,165,0.24);}

  /* ---------- Fuel Tanks Grid ---------- */
  .tank-grid{display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:16px; margin:0 0 26px; align-items:stretch;}
  @media (max-width:980px){.tank-grid{grid-template-columns:1fr;}}
  
  .tank{
    background:linear-gradient(160deg, var(--navy-800), var(--navy-900)); border:1px solid rgba(255,255,255,0.07); 
    border-radius:14px; padding:14px 16px 12px; transition: all 0.4s cubic-bezier(0.2, 0.8, 0.2, 1);
    min-height: 128px; display:flex; flex-direction:column; justify-content:space-between;
  }
  .tank.disabled-tank {
    opacity: 0.35;
    filter: grayscale(0.5);
    transform: scale(0.98);
    pointer-events: none;
  }
  .tank:hover:not(.disabled-tank){
    transform: translateY(-2px);
    border-color: rgba(251,191,36,0.25);
    box-shadow: 0 10px 24px -15px rgba(0,0,0,0.5);
  }
  .tank .top{display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px; gap:10px;}
  .tank .fuel-name{font-weight:700; font-size:15px; line-height:1.3;}
  .tank .cap{font-size:11px; color:var(--slate-400); margin-top:2px;}
  .tank .pct{font-family:'Space Grotesk'; font-size:22px; font-weight:700; transition: color 0.3s; line-height:1;}
  
  .gauge-track{height:8px; border-radius:999px; background:rgba(255,255,255,0.06); overflow:hidden; margin-bottom:8px;}
  .gauge-track i{display:block; height:100%; border-radius:999px; transition: width 0.8s cubic-bezier(0.34, 1.56, 0.64, 1), background-color 0.4s;}
  
  .tank .liters{font-size:12px; color:var(--slate-300); display:flex; justify-content:space-between; gap:8px; margin-top:2px;}
  
  .tank-flag{display:inline-flex; align-items:center; gap:6px; font-size:11px; font-weight:700; padding:4px 8px; border-radius:999px; margin-top:8px; transition: all 0.3s;}
  .tank-flag.ok{background:rgba(52,211,153,0.14); color:var(--mint-400);}
  .tank-flag.low{background:rgba(248,113,113,0.14); color:var(--red-400); animation: pulseAlert 2s infinite;}
  .tank-flag.mid{background:rgba(251,191,36,0.14); color:var(--amber-300);}
  .tank-flag.inactive{background:rgba(139,150,173,0.14); color:var(--slate-400);}

  .fuel-table-scroll, .refill-table-scroll{
    overflow-x: hidden;
    overflow-y: hidden;
    max-height: none;
    scrollbar-width: thin;
    scrollbar-color: rgba(148, 163, 184, 0.45) transparent;
  }
  .fuel-table-scroll.has-scroll, .refill-table-scroll.has-scroll {
    overflow-y: auto;
  }
  .fuel-table-scroll::-webkit-scrollbar, .refill-table-scroll::-webkit-scrollbar { width: 8px; }
  .fuel-table-scroll::-webkit-scrollbar-track, .refill-table-scroll::-webkit-scrollbar-track { background: transparent; }
  .fuel-table-scroll::-webkit-scrollbar-thumb, .refill-table-scroll::-webkit-scrollbar-thumb {
    background: rgba(148, 163, 184, 0.38);
    border-radius: 999px;
    border: 2px solid transparent;
    background-clip: content-box;
  }
  .fuel-table-scroll::-webkit-scrollbar-thumb:hover, .refill-table-scroll::-webkit-scrollbar-thumb:hover { background: rgba(148, 163, 184, 0.62); }
  .fuel-table-scroll table{width:100%; border-collapse:collapse;}
  .refill-table-scroll table{width:100%; border-collapse:collapse;}
  .fuel-table-scroll thead th{position:sticky; top:0; background:rgba(15,23,42,0.94); z-index:2;}
  .refill-table-scroll thead th{position:sticky; top:0; background:rgba(15,23,42,0.94); z-index:2;}
  
  .fuel-action-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
  }
  .fuel-action-table thead th {
    text-align: left;
    font-size: 11.5px;
    color: var(--slate-400);
    text-transform: uppercase;
    letter-spacing: 0.4px;
    padding: 14px 18px;
    border-bottom: 1px solid rgba(255,255,255,0.07);
    white-space: nowrap;
  }
  .fuel-action-table tbody tr {
    transition: opacity 0.2s ease, background-color 0.2s ease;
  }
  .fuel-action-table tbody tr:hover {
    background: rgba(255,255,255,0.02);
  }
  .fuel-action-table tbody td {
    padding: 14px 18px;
    font-size: 13.5px;
    border-bottom: 1px solid rgba(255,255,255,0.05);
    vertical-align: middle;
  }
  .fuel-action-table tbody tr:last-child td {
    border-bottom: none;
  }
  .fuel-name-cell {
    color: var(--slate-50);
    font-weight: 600;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .fuel-stock-meta {
    color: var(--slate-300);
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
  }
  .fuel-action-cell {
    text-align: right;
  }
  .fuel-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 10px;
    white-space: nowrap;
  }
  .fuel-row-btn {
    width: 22px;
    height: 22px;
    border: none;
    background: transparent;
    color: var(--slate-400);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    cursor: pointer;
    padding: 0;
    font-size: 14px;
    line-height: 1;
  }
  .fuel-row-btn.edit-btn { color: var(--slate-400); }
  .fuel-row-btn.toggle-btn.on { color: var(--mint-400); }
  .fuel-row-btn.toggle-btn.off { color: var(--slate-400); }
  .fuel-row-btn.delete-btn { color: var(--red-400); }
  .fuel-row-btn:hover,
  .fuel-row-btn:focus,
  .fuel-row-btn:focus-visible,
  .fuel-row-btn:active {
    background: transparent;
    box-shadow: none;
    transform: none;
    opacity: 1;
  }
  .fuel-action-table thead th:last-child,
  .fuel-action-table tbody td:last-child {
    width: 124px;
    min-width: 124px;
    max-width: 124px;
    text-align: right;
    white-space: nowrap;
    padding-left: 10px;
    padding-right: 10px;
  }
  
  /* ---------- Tabs ---------- */
  .tabs{display:flex; gap:8px; margin-bottom:22px; border-bottom:1px solid rgba(255,255,255,0.08); flex-wrap:wrap;}
  .tab-btn{
    background:none; border:none; color:var(--slate-400); font-family:inherit; font-size:13.5px; font-weight:600; 
    padding:11px 4px; cursor:pointer; position:relative; margin-right:22px;
    transition: color 0.25s ease;
  }
  .tab-btn:hover{color: var(--slate-50);}
  .tab-btn.active{color:var(--slate-50);}
  .tab-btn.active::after{
    content:""; position:absolute; left:0; right:0; bottom:-1px; height:2px; background:var(--amber-400); border-radius:2px;
    animation: slideLine 0.25s ease-out;
  }
  @keyframes slideLine {
    from { transform: scaleX(0); }
    to { transform: scaleX(1); }
  }

  .tab-panel{display:none;}
  .tab-panel.active{
    display:block;
    animation: panelFadeIn 0.3s ease forwards;
  }
  @keyframes panelFadeIn {
    from { opacity: 0; transform: translateY(4px); }
    to { opacity: 1; transform: translateY(0); }
  }

  /* ---------- Fuel Type Active Toggle Rows ---------- */
  .type-row{display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid rgba(255,255,255,0.05); transition: background-color 0.2s;}
  .type-row:hover{background-color: rgba(255,255,255,0.015);}
  .type-row:last-child{border-bottom:none;}
  .type-row .info{display:flex; align-items:center; gap:14px;}
 
  .type-row .swatch{width:12px; height:36px; border-radius:4px;}
  .type-row b{font-size:14px; display:block;}
  .type-row span{font-size:11.5px; color:var(--slate-400);}
  
  .toggle{position:relative; width:44px; height:24px; border-radius:999px; background:rgba(255,255,255,0.1); cursor:pointer; border:none; flex-shrink:0;}
  .toggle::after{content:""; position:absolute; top:3px; left:3px; width:18px; height:18px; border-radius:50%; background:var(--slate-400); transition:all .2s;}
   .toggle.on{background:rgba(52,211,153,0.28);}
  .toggle.on::after{ left:23px; background:var(--mint-400);}

  /* ---------- Modals ---------- */
  .modal-overlay {
    position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(7, 11, 20, 0.85);
    backdrop-filter: blur(8px); z-index: 100; display: flex; align-items: center; justify-content: center;
    opacity: 0; pointer-events: none; transition: opacity 0.3s ease;
  }
  .modal-overlay.active { opacity: 1; pointer-events: auto; }
  
  .modal-card-custom {
    background: linear-gradient(160deg, var(--navy-800), var(--navy-900));
    border: 1px solid rgba(255,255,255,0.1); border-radius: 20px;
    width: 100%; max-width: 480px; padding: 28px; transform: scale(0.95);
    transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
  }
  .modal-overlay.active .modal-card-custom { transform: scale(1); }
  
  .modal-header-custom { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
  .modal-header-custom h3 { font-size: 18px; font-weight: 700; margin: 0; }
  .modal-close { background: none; border: none; color: var(--slate-400); font-size: 20px; cursor: pointer; transition: color 0.2s; }
  .modal-close:hover { color: var(--slate-50); }

  .limit-modal-head { display:flex; align-items:center; gap:12px; margin-bottom:18px; }
  .limit-modal-head .limit-modal-icon {
    width:42px; height:42px; border-radius:12px; display:flex; align-items:center; justify-content:center;
    background:linear-gradient(135deg, rgba(251,191,36,0.18), rgba(59,141,255,0.18));
   
   
  }
  .limit-form-item label {
    display:block; margin:0; font-size:12.5px; font-weight:600; color:var(--slate-200); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
  }
  .limit-input-wrap { position:relative; width:110px; flex-shrink:0; }
  .limit-input-wrap input {
    width:100%; padding:10px 28px 10px 12px; border-radius:10px; border:1px solid rgba(255,255,255,0.1);
    background:rgba(7,11,20,0.8); color:var(--slate-50); font-weight:600; font-size:13px; outline:none;
  }
  .limit-input-wrap input:focus { border-color: rgba(251,191,36,0.45); box-shadow:0 0 0 1px rgba(251,191,36,0.25); }
  .limit-input-wrap .pct-suffix {
    position:absolute; right:10px; top:50%; transform:translateY(-50%); color:var(--slate-400); font-size:11px; font-weight:700;
  }

  .form-group { margin-bottom: 16px; }
  .form-group label { display: block; font-size: 12px; color: var(--slate-400); margin-bottom: 6px; font-weight: 500; }
  .form-control-custom {
    width: 100%; background: var(--navy-950); border: 1px solid rgba(255,255,255,0.08);
    border-radius: 10px; padding: 10px 14px; color: var(--slate-50); font-size: 13.5px; outline: none;
    transition: border-color 0.2s;
  }
  .form-control-custom:focus { border-color: var(--amber-400); }
  
  .modal-actions {

    display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px;
  }
  .btn-secondary-custom {
    background: transparent; border: 1px solid rgba(255,255,255,0.15); color: var(--slate-300);
    padding: 10px 18px; border-radius: 10px; cursor: pointer; font-size: 13px; font-weight: 600;
    transition: background 0.2s, color 0.2s;
  }
  .btn-secondary-custom:hover { background: rgba(255,255,255,0.05); color: var(--slate-50); }

  .refill-modal-sub { font-size:12.5px; color:var(--slate-400); margin:-6px 0 18px; line-height:1.6; }
  .refill-field { margin-bottom: 16px; }
  .refill-field label { display: block; font-size: 12px; color: var(--slate-400); margin-bottom: 6px; font-weight: 500; }
  .refill-actions {
    display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px;
  }

  .refill-modal-card { max-width: 480px; padding: 28px; }

  /* ---------- Delete Confirmation Modal ---------- */
  .delete-modal-card {
    background: var(--navy-900); border: 1px solid rgba(255,255,255,0.1); border-radius: 18px;
    padding: 24px; display: flex; flex-direction: column; align-items: center;
  }
  .delete-modal-icon {
    width: 48px; height: 48px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
    background: rgba(248,113,113,0.15); color: var(--red-400); margin-bottom: 16px;
    font-size: 24px;
  }
  .delete-modal-title {
    font-size: 18px; font-weight: 700; margin: 0 0 12px;
    text-align: center;
  }
  .delete-modal-message {
    font-size: 14px; color: var(--slate-400); margin-bottom: 24px;
    text-align: center;
  }
  .delete-modal-card .modal-actions {
    width: 100%; display: flex; justify-content: space-between; gap: 12px;
  }
  .delete-modal-card .btn-secondary-custom {
    flex: 1; padding: 10px 0; border-radius: 10px;
  }
  .delete-modal-card #delete-confirm-btn {
    flex: 1; padding: 10px 0; border-radius: 10px;
    background: linear-gradient(135deg, #f87171, #b91c1c); color: #fff; font-weight: 700;
    transition: background 0.2s;
  }
  .delete-modal-card #delete-confirm-btn:hover {
    background: linear-gradient(135deg, #dc2626, #991b1b);
  }
</style>
</head>
<body>

<div class="app-shell">
  <aside class="sidebar">
    <a href="dashboard.php" class="text-decoration-none d-flex align-items-center gap-2 text-white fw-bold" style="padding: 6px 8px 0;">
      <img src="/images/Logo.png" alt="Logo" class="nav-logo">
      <span class="disp" style="font-size: 17px; letter-spacing: 0.5px; color: #ffffff; font-weight: 700;">Map & Pump</span>
    </a>
    <div class="side-role">Branch Manager Console</div>

    <?php $hqLogoSrc = function_exists('hq_logo_src') ? hq_logo_src($branch['hq_logo'] ?? null) : null; ?>
    <div class="side-branch">
      <?php if ($hqLogoSrc): ?>
        <img src="<?= h($hqLogoSrc) ?>" alt="HQ Logo" class="hq-logo" onerror="this.style.display='none'">
      <?php else: ?>
        <div class="hq-logo" style="display:flex; align-items:center; justify-content:center; color:var(--slate-400);"><i class="fa-solid fa-building"></i></div>
      <?php endif; ?>
      <div class="txt">
        <b><?= h($branch['branch_name'] ?? 'Denko - Sanchaung') ?></b>
        <span>Branch ID: <?= h($branch['branch_code'] ?? 'YGN-014') ?></span>
      </div>
    </div>

    <nav class="side-nav">
      <a href="dashboard.php" class="side-link"><span class="ic"><i class="fa-solid fa-house"></i></span> Dashboard</a>
      <a href="manage-staff.php" class="side-link"><span class="ic"><i class="fa-solid fa-users"></i></span> Manage Staff</a>
      <a href="manage-oil.php" class="side-link active"><span class="ic"><i class="fa-solid fa-gas-pump"></i></span> Manage Fuel Stock</a>
      <a href="booking-slot.php" class="side-link"><span class="ic"><i class="fa-solid fa-ticket"></i></span> Monitor Booking Slot</a>
      <a href="view-customers.php" class="side-link"><span class="ic"><i class="fa-solid fa-address-book"></i></span> View Customers</a>
      <a href="report-hq.php" class="side-link"><span class="ic"><i class="fa-solid fa-paper-plane"></i></span> Report to HQ</a>
      <a href="branchprofile.php" class="side-link"><span class="ic"><i class="fa-solid fa-building"></i></span> Branch Profile</a>
      <a href="edit-profile.php" class="side-link"><span class="ic"><i class="fa-solid fa-gear"></i></span> Edit Profile</a>
    </nav>

    <a href="../Login/logout.php" class="side-logout"><span class="ic"><i class="fa-solid fa-right-from-bracket"></i></span> Log Out</a>
  </aside>

  <div class="main">
    <div class="topbar">
      <div>
        <h1>Manage Fuel Stock</h1>
        <div class="date" id="live-clock">Wednesday · July 9, 2026</div>
      </div>
      <div class="topbar-right">
        <!-- Bell Trigger Button -->
        <div class="bell" id="notif-bell"><i class="fa-regular fa-bell"></i><?php if ($has_unread): ?><span class="ping" id="notif-ping"></span><?php endif; ?></div>
        
        <!-- Notification Dropdown Popup (Dashboard Style) -->
        <div class="notif-popup" id="notif-popup">
          <div class="notif-header">
            <h4>Notifications</h4>
            <button id="clear-notifs-btn" type="button">Mark all read</button>
          </div>
          <div id="notif-list">
            <?php if (!$alerts): ?>
              <div class="notif-empty" style="text-align:center; padding:12px 0; color:var(--slate-400); font-size:12px;">No new notifications</div>
            <?php else:
              foreach ($alerts as $a):
                $meta = notif_ui_meta($a['type'], $a['title']);
                $tone = $meta['tone'];
                $icon = $meta['icon'];
            ?>
              <div class="notif-item <?= h($tone) ?>" data-notif-id="<?= (int)$a['notif_id'] ?>" data-link="<?= h($a['link'] ?? 'dashboard.php') ?>" data-kind="<?= h($tone) ?>">
                <div class="ic"><i class="fa-solid <?= h($icon) ?>"></i></div>
                <div class="notif-item-body">
                  <b><?= h($a['title']) ?></b>
                  <span><?= h($a['body']) ?></span>
                  <div class="notif-time"><?= h($a['time_ago']) ?></div>
                </div>
                           </div>
            <?php endforeach; endif; ?>
          </div>
        </div>

        <div class="avatar-chip">
          <div class="avatar-circle"><a href="edit-profile.php"><?= h($initials) ?></a></div>
          <div><b><a href="edit-profile.php"><?= h(explode(' ', $user['full_name'])[0]) ?></a></b></div>
        </div>
      </div>
    </div>

    <div class="content">
      <div class="page-head">
        <div>
          <h2>Fuel Stock</h2>
        </div>
        <div class="page-head-actions">
          <button class="btn-primary action-btn-blue" id="open-add-oil-modal">
            <i class="fa-solid fa-plus-circle"></i> Add Fuel Type
          </button>
          <button class="btn-primary action-btn-refill" id="open-refill-modal">
            <i class="fa-solid fa-gas-pump"></i> Refill Fuel
          </button>
          <button class="btn-primary action-btn-amber" id="open-limit-modal">
            <i class="fa-solid fa-sliders"></i> Manage Fuel Stock
          </button>
        </div>
      </div>
      <div class="tabs">
        <button class="tab-btn active" id="tabbtn-amount" onclick="showTab('amount')">Fuel Types</button>
        <button class="tab-btn" id="tabbtn-types" onclick="showTab('types')">Manage Fuel Types</button>
        <button class="tab-btn" id="tabbtn-refill-history" onclick="showTab('refill-history')">Refill History</button>
      </div>

      <!-- TAB 1: MANAGE STOCK LEVELS -->
      <div class="tab-panel active" id="tab-amount">
        <div class="stock-overview">
          <div>
            <h2><span>All Fuel Types</span></h2>
          </div>
          <div class="stock-pills">
            <?php
              $sufficientCount = 0;
              $watchCount = 0;
              $lowCount = 0;
              foreach ($tanks as $tank) {
                $p = tank_pct($tank);
                $alert = (int)($tank['alert_pct'] ?? 25);
                if ($p <= $alert) $lowCount++;
                elseif ($p < 60) $watchCount++;
                else $sufficientCount++;
              }
            ?>
            <span class="stock-pill sufficient"><?= $sufficientCount ?> Sufficient</span>
            <span class="stock-pill watch"><?= $watchCount ?> Watch</span>
            <span class="stock-pill low"><?= $lowCount ?> Low</span>
          </div>
        </div>
        <div class="tank-grid">
          <?php foreach ($tank_defs as $fc => $def): ?>
            <?php
              $t = $tanks[$fc] ?? null;
              $cap = $t ? (int)$t['capacity_l'] : $def['cap'];
              $cur = $t ? (float)$t['current_l'] : $def['rem'];
              $pct = $t ? tank_pct($t) : (int)round(($cur / $cap) * 100);
              $status = $t ? map_pump_fuel_status_label($t) : ($pct > 60 ? 'sufficient' : ($pct > 25 ? 'watch' : 'low'));
              $col = $status === 'sufficient' ? 'var(--mint-400)' : ($status === 'watch' ? 'var(--amber-300)' : 'var(--red-400)');
              $tank_id_attr = $t ? (int)$t['tank_id'] : '';
              $disabled_class = ($t && (int)$t['is_enabled'] === 0) ? 'disabled-tank' : '';
            ?>
            <div class="tank <?= $disabled_class ?>" id="tank-<?= h($fc) ?>" data-capacity="<?= $cap ?>" data-tankid="<?= $tank_id_attr ?>">
              <div class="top">
                <div>
                  <div class="fuel-name"><?= h($t['fuel_name'] ?? $def['name']) ?></div>
                </div>
                <div class="pct" id="pct-<?= h($fc) ?>" style="color:<?= $col ?>"><?= $pct ?>%</div>
              </div>
              <div class="gauge-track"><i id="gauge-<?= h($fc) ?>" style="width:<?= $pct ?>%; background:<?= $col ?>;"></i></div>
              <div class="liters">
                <span id="rem-<?= h($fc) ?>"><?= number_format($cur) ?> / <?= number_format($cap) ?> L remaining</span>
              </div>
              <?php if ($t): ?>
                <?= tank_flag($t) ?>
              <?php else: ?>
                <span class="tank-flag <?= $status === 'sufficient' ? 'ok' : ($status === 'watch' ? 'mid' : 'low') ?>" id="flag-<?= h($fc) ?>"><i class="fa-solid <?= $status === 'sufficient' ? 'fa-check' : ($status === 'watch' ? 'fa-circle-half-stroke' : 'fa-triangle-exclamation') ?>"></i> <?= $status === 'sufficient' ? 'Stock sufficient' : ($status === 'watch' ? 'Watch level' : 'Stock low') ?></span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>

      </div>

      <!-- TAB 2: MANAGE FUEL TYPES -->
      <div class="tab-panel" id="tab-types">
        <div class="panel fuel-table-scroll">
          <table class="fuel-action-table">
            <thead>
              <tr>
                <th style="width:35%;">Name</th>
                <th style="width:45%;">Stock Level</th>
                <th style="width:20%; text-align:right;">Action</th>
              </tr>
            </thead>
                       <tbody>
              <?php foreach ($tank_defs as $fc => $def): ?>
                <?php
                  $t = $tanks[$fc] ?? null;
                  $cur = $t ? (float)$t['current_l'] : $def['rem'];
                  $cap = $t ? (int)$t['capacity_l'] : $def['cap'];
                  $on = $t ? ((int)$t['is_enabled'] === 1) : true;
                  $tank_id_attr = $t ? (int)$t['tank_id'] : '';
                  $current_price = 0;
                  foreach (($fuelCodeToOilAliases[$fc] ?? [$def['name']]) as $oilAlias) {
                    $current_price = $priceByOilName[strtolower($oilAlias)] ?? $current_price;
                  }
                  $stockText = number_format($cur) . ' / ' . number_format($cap) . ' L remaining';
                ?>
                <tr data-tank-id="<?= $tank_id_attr ?>" data-fuel-code="<?= h($fc) ?>">
                  <td class="fuel-name-cell"><?= h($t['fuel_name'] ?? $def['name']) ?></td>
                  <td class="fuel-stock-meta"><?= h($stockText) ?></td>
                  <td class="fuel-action-cell">
                    <div class="fuel-actions">
                      <button type="button" class="fuel-row-btn edit-btn edit-action" data-action="edit" data-id="<?= $tank_id_attr ?>" data-fuel="<?= h($fc) ?>" data-name="<?= h($t['fuel_name'] ?? $def['name']) ?>" data-capacity="<?= (int)($t['capacity_l'] ?? $def['cap']) ?>" data-price="<?= (int)$current_price ?>" title="Edit fuel" aria-label="Edit fuel">
                        <i class="fa-solid fa-pen-to-square"></i>
                      </button>
                      <button type="button" class="fuel-row-btn toggle-btn <?= $on ? 'on' : 'off' ?>" data-action="toggle" data-id="<?= $tank_id_attr ?>" data-fuel="<?= h($fc) ?>" data-enabled="<?= $on ? '1' : '0' ?>" title="<?= $on ? 'Disable fuel' : 'Enable fuel' ?>" aria-label="<?= $on ? 'Disable fuel' : 'Enable fuel' ?>">
                        <i class="fa-solid <?= $on ? 'fa-toggle-on' : 'fa-toggle-off' ?>"></i>
                      </button>
                      <button type="button" class="fuel-row-btn delete-btn" data-action="delete" data-id="<?= $tank_id_attr ?>" data-fuel="<?= h($fc) ?>" title="Delete fuel type" aria-label="Delete fuel type">
                                                                                                                                                                                                                                                                                             <i class="fa-solid fa-trash"></i>
                      </button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- TAB 3: REFILL HISTORY -->
      <div class="tab-panel" id="tab-refill-history">
        <div class="panel refill-table-scroll">
          <table class="fuel-action-table">
            <thead>
              <tr>
                <th style="width:35%;">Fuel Name</th>
                <th style="width:25%; text-align:center;">Quantity</th>
                <th style="width:40%; text-align:right;">Refill Date</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($refillHistoryRows as $row): ?>
                <?php
                  $fuelName = $row['tank_fuel_name'] ?? 'Unknown';
                  $quantity = (float)($row['quantity_l'] ?? 0);
                  $refillDate = $row['update_at'] ?? $row['updated_at'] ?? $row['transaction_at'] ?? $row['created_at'] ?? null;
                  $refillDate = $refillDate ? date('d-m-Y', strtotime($refillDate)) : 'N/A';
                ?>
                <tr>
                  <td class="fuel-name-cell"><?= h($fuelName) ?></td>
                  <td class="fuel-stock-meta refill-history-quantity" style="text-align:center;"><?= $quantity > 0 ? number_format($quantity) . ' L' : 'N/A' ?></td>
                  <td class="fuel-stock-meta refill-history-date" style="text-align:right;"><?= h($refillDate) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="page-foot">© 2026 MAP & PUMP | All Rights Reserved</div>
    </div>
  </div>
</div>

<!-- ---------- Manage Oil Limit Modal ---------- -->
<div class="modal-overlay" id="limit-modal">
  <div class="modal-card-custom">
    <div class="modal-header-custom">
      <div class="limit-modal-head">
        <div class="limit-modal-icon"><i class="fa-solid fa-sliders"></i></div>
        <h3>Manage Oil Limit</h3>
      </div>
      <button class="modal-close" id="limit-modal-close-btn">&times;</button>
    </div>
    <p class="limit-modal-sub">
      Set the low-stock alert threshold for each fuel type. A tank flags "Low Stock" once its level falls below this percentage.
    </p>
    <form id="limit-form" method="POST" action="manage-oil.php">
      <?php if (function_exists('csrf_token')): ?>
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <?php endif; ?>
      <input type="hidden" name="action" value="save_limits">
      <div class="limit-form-list">
        <?php foreach ($tank_defs as $fc => $def): ?>
          <?php $tank = $tanks[$fc] ?? null; $limit = (int)($tank['alert_pct'] ?? 25); ?>
          <div class="limit-form-item">
            <div class="limit-name-wrap">
              <span class="limit-dot"></span>
              <label for="limit-<?= h($fc) ?>"><?= h($tank['fuel_name'] ?? $def['name']) ?></label>
            </div>
            <div class="limit-input-wrap">
              <input id="limit-<?= h($fc) ?>" name="alert_<?= h($fc) ?>" type="number" min="5" max="80" value="<?= $limit ?>" required>
              <span class="pct-suffix">%</span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn-secondary-custom" id="limit-modal-cancel-btn">Cancel</button>
        <button type="submit" class="btn-primary" style="box-shadow: none; padding: 10px 18px; background:linear-gradient(135deg, #f7d66a, #d89f06); color:#1d1306;">Save Limits</button>
      </div>
    </form>
  </div>
</div>

<!-- ---------- Add Fuel Type Modal ---------- -->
<div class="modal-overlay" id="add-oil-modal">
  <div class="modal-card-custom">
    <div class="modal-header-custom">
      <h3>Add New Fuel Type</h3>
      <button class="modal-close" id="add-oil-modal-close-btn">&times;</button>
    </div>
    <form id="add-oil-form" method="POST" action="manage-oil.php">
      <?php if (function_exists('csrf_token')): ?>
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <?php endif; ?>
      <input type="hidden" name="action" value="add_fuel_type">

      <div class="form-group">
        <label for="new-fuel-name">Fuel Display Name</label>
        <input type="text" id="new-fuel-name" name="fuel_name" class="form-control-custom" required placeholder="e.g. Octane 98">
      </div>

      <div class="form-group">
        <label for="new-tank-capacity">Tank Capacity (Liters)</label>
        <input type="number" id="new-tank-capacity" name="capacity_l" class="form-control-custom" min="1000" value="10000" required>
      </div>

      <div class="form-group">
        <label for="new-alert-pct">Low Stock Alert (%)</label>
        <input type="number" id="new-alert-pct" name="alert_pct" class="form-control-custom" min="5" max="80" value="20" required>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-secondary-custom" id="add-oil-modal-cancel-btn">Cancel</button>
        <button type="submit" class="btn-primary" style="box-shadow: none; padding: 10px 18px;">Save Fuel Type</button>
      </div>
    </form>
  </div>
</div>

<!-- ---------- Edit Fuel Type Modal ---------- -->
<div class="modal-overlay" id="edit-fuel-modal">
  <div class="modal-card-custom">
    <div class="modal-header-custom">
      <h3>Edit Fuel Type</h3>
      <button class="modal-close" id="edit-fuel-close-btn">&times;</button>
    </div>
    <form id="edit-fuel-form" method="POST" action="manage-oil.php">
      <?php if (function_exists('csrf_token')): ?>
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <?php endif; ?>
      <input type="hidden" name="action" value="edit_fuel_type">
      <input type="hidden" name="tank_id" id="edit-fuel-tank-id">
      <input type="hidden" name="fuel_code" id="edit-fuel-code">

      <div class="form-group">
        <label for="edit-fuel-name">Fuel Name</label>
        <input type="text" id="edit-fuel-name" name="fuel_name" class="form-control-custom" required>
      </div>

      <div class="form-group">
        <label for="edit-fuel-capacity">Tank Capacity (Liters)</label>
        <input type="number" id="edit-fuel-capacity" name="capacity_l" class="form-control-custom" min="1" max="200000" required>
      </div>

      <div class="form-group">
        <label for="edit-fuel-price">Fuel Price (MMK/L)</label>
        <input type="number" id="edit-fuel-price" name="price_per_litre" class="form-control-custom" min="0" step="1" required>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-secondary-custom" id="edit-fuel-cancel-btn">Cancel</button>
        <button type="submit" class="btn-primary" style="box-shadow: none; padding: 10px 18px;">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ---------- Refill Fuel Modal ---------- -->
<div class="modal-overlay" id="refill-modal">
  <div class="modal-card-custom refill-modal-card">
    <div class="modal-header-custom" style="margin-bottom: 10px;">
      <h3 class="refill-modal-title">Refill Oil</h3>
      <button class="modal-close" id="refill-modal-close-btn">&times;</button>
    </div>

    <form id="refill-form" method="POST" action="manage-oil.php">
      <?php if (function_exists('csrf_token')): ?>
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <?php endif; ?>
      <input type="hidden" name="action" value="add_refill">

      <p class="refill-modal-sub">Add fuel directly to an existing tank.</p>

      <div class="refill-field">
        <label for="refill-fuel-type">Fuel Type</label>
        <select id="refill-fuel-type" name="tank_id" class="form-control-custom">
          <?php foreach ($refillOptions as $option): ?>
            <?php if ((int)($option['is_enabled'] ?? 1) === 0) continue; ?>
            <option value="<?= (int)$option['tank_id'] ?>"><?= h($option['fuel_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="refill-field">
        <label for="refill-quantity">Quantity (Liters)</label>
        <input type="number" id="refill-quantity" name="quantity_l" class="form-control-custom" min="1" step="0.01" value="5000" required>
      </div>

      <div class="refill-actions">
        <button type="button" class="btn-secondary-custom" id="refill-modal-cancel-btn">Cancel</button>
        <button type="submit" class="btn-primary" id="refill-submit-btn" style="box-shadow: none; padding: 10px 18px;">Refill Fuel</button>
      </div>
    </form>
  </div>
</div>

<!-- ---------- Delete Confirmation Modal ---------- -->
<div class="modal-overlay" id="delete-modal">
  <div class="modal-card-custom delete-modal-card">
    <div class="delete-modal-icon">
      <i class="fa-solid fa-triangle-exclamation"></i>
    </div>
    <h3 class="delete-modal-title">Delete Fuel Record?</h3>
    <div id="delete-modal-msg" class="delete-modal-message">
      <p>Confirm this is the correct record before continuing.</p>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn-secondary-custom" id="delete-cancel-btn">Cancel</button>
      <button type="button" id="delete-confirm-btn" style="background:linear-gradient(135deg,#f87171,#b91c1c); color:#fff; font-weight: 700; font-size:13px; padding:10px 18px; border-radius:10px; border:none; cursor:pointer;">Delete Permanently</button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Tab Switching Controller
  function showTab(name){
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    document.getElementById('tabbtn-' + name).classList.add('active');
    localStorage.setItem('map_pump_oil_tab', name);
  }

  const savedTab = localStorage.getItem('map_pump_oil_tab');
  if (savedTab && document.getElementById('tab-' + savedTab)) {
    showTab(savedTab);
  }

  document.addEventListener('DOMContentLoaded', () => {

    // 1. Digital Clock Component
    function updateClock() {
      const now = new Date();
      const options = { weekday: 'long', month: 'short', day: 'numeric', year: 'numeric' };
      const dateString = now.toLocaleDateString('en-US', options);
      const timeString = now.toLocaleTimeString('en-US', { hour12: true, hour: '2-digit', minute: '2-digit', second: '2-digit' });
      document.getElementById('live-clock').textContent = `${dateString} · ${timeString}`;
    }
    setInterval(updateClock, 1000);
    updateClock();

    // 2. Notification Popup Controller (shared dashboard API)
    const bellBtn = document.getElementById('notif-bell');
    const notifPopup = document.getElementById('notif-popup');
    const notifPing = document.getElementById('notif-ping');
    const clearNotifsBtn = document.getElementById('clear-notifs-btn');
    const notifList = document.getElementById('notif-list');

    function postDismiss(payload) {
      return fetch('api_dismiss_notification.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      }).then(r => r.json()).catch(() => ({ ok: false }));
    }

    function renderNotifEmpty() {
      if (!notifList) return;
      notifList.innerHTML = '<div class="notif-empty" style="text-align:center; padding:12px 0; color:var(--slate-400); font-size:12px;">No new notifications</div>';
      if (notifPing) notifPing.remove();
    }

    if (bellBtn && notifPopup) {
      bellBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const ping = bellBtn.querySelector('.ping');
        if (ping) { ping.style.transform = 'scale(0)'; setTimeout(() => ping.remove(), 200); }
        notifPopup.classList.toggle('active');
      });

      document.addEventListener('click', (e) => {
        if (!notifPopup.contains(e.target) && !bellBtn.contains(e.target)) {
          notifPopup.classList.remove('active');
        }
      });
    }

    if (notifList) {
      notifList.addEventListener('click', (e) => {
        const item = e.target.closest('.notif-item');
        if (!item) return;
        const id = Number(item.dataset.notifId || 0);
        const link = item.dataset.link || '';
        if (id > 0) {
          postDismiss({ notif_id: id }).then((res) => {
            if (res.ok && notifPing && Number(res.unread || 0) <= 0) {
              notifPing.remove();
            }
          });
        }
        if (link) window.location.href = link;
      });
    }

    if (clearNotifsBtn) {
      clearNotifsBtn.addEventListener('click', () => {
        postDismiss({ all: true }).then((res) => {
          if (res.ok) renderNotifEmpty();
        });
      });
    }

    function updateFuelTableScrollState() {
      document.querySelectorAll('.fuel-table-scroll, .refill-table-scroll').forEach((scrollWrap) => {
        const table = scrollWrap.querySelector('table');
        if (!table) return;

        const rows = table.querySelectorAll('tbody tr');
        const visibleRows = Array.from(rows).filter(row => row.style.display !== 'none').length;
        const shouldScroll = visibleRows > 5;

        scrollWrap.classList.toggle('has-scroll', shouldScroll);
        scrollWrap.style.maxHeight = shouldScroll ? 'calc(5 * 66px + 12px)' : 'none';
        scrollWrap.style.overflowY = shouldScroll ? 'auto' : 'hidden';
      });
    }

    document.querySelectorAll('.fuel-table-scroll table, .refill-table-scroll table').forEach((table) => {
      const tbody = table.querySelector('tbody');
      if (!tbody) return;

      const observer = new MutationObserver(() => {
        updateFuelTableScrollState();
      });

      observer.observe(tbody, {
        childList: true,
        subtree: true
      });
    });

    updateFuelTableScrollState();

    const addFuelModal = document.getElementById('add-oil-modal');
    const addFuelForm = document.getElementById('add-oil-form');
    const openAddFuelBtn = document.getElementById('open-add-oil-modal');
    const closeAddFuelBtn = document.getElementById('add-oil-modal-close-btn');
    const cancelAddFuelBtn = document.getElementById('add-oil-modal-cancel-btn');

    function getCsrfToken() {
      const candidates = Array.from(document.querySelectorAll('input[name="csrf"]'))
        .map((input) => String(input.value || '').trim())
        .filter(Boolean);
      return candidates[0] || '';
    }

    const deleteModal = document.getElementById('delete-modal');
    const deleteModalTitle = deleteModal ? deleteModal.querySelector('.delete-modal-title') : null;
    const deleteModalMessage = deleteModal ? deleteModal.querySelector('.delete-modal-message') : null;
    const deleteCancelBtn = document.getElementById('delete-cancel-btn');
    const deleteConfirmBtn = document.getElementById('delete-confirm-btn');
    let pendingDeleteHandler = null;

    function closeDeleteModal() {
      if (deleteModal) deleteModal.classList.remove('active');
      if (deleteConfirmBtn) deleteConfirmBtn.disabled = false;
      pendingDeleteHandler = null;
    }

    function openDeleteModal(title, message, handler) {
      if (!deleteModal || !deleteModalTitle || !deleteModalMessage || !deleteConfirmBtn) return;
      deleteModalTitle.textContent = title;
      deleteModalMessage.innerHTML = '';
      const msg = document.createElement('p');
      msg.textContent = message;
      deleteModalMessage.appendChild(msg);
      pendingDeleteHandler = handler;
      deleteConfirmBtn.disabled = false;
      deleteModal.classList.add('active');
    }

    if (deleteCancelBtn) deleteCancelBtn.addEventListener('click', closeDeleteModal);
    if (deleteModal) deleteModal.addEventListener('click', (e) => { if (e.target === deleteModal) closeDeleteModal(); });
    if (deleteConfirmBtn) {
      deleteConfirmBtn.addEventListener('click', async () => {
        if (typeof pendingDeleteHandler !== 'function') {
          closeDeleteModal();
          return;
        }
        deleteConfirmBtn.disabled = true;
        try {
          await pendingDeleteHandler();
        } finally {
          closeDeleteModal();
        }
      });
    }

    if (openAddFuelBtn && addFuelModal) {
      openAddFuelBtn.addEventListener('click', () => {
        if (addFuelForm) addFuelForm.reset();
        addFuelModal.classList.add('active');
      });
    }

    const closeAddFuelModal = () => addFuelModal && addFuelModal.classList.remove('active');
    if (closeAddFuelBtn) closeAddFuelBtn.addEventListener('click', closeAddFuelModal);
    if (cancelAddFuelBtn) cancelAddFuelBtn.addEventListener('click', closeAddFuelModal);
    if (addFuelModal) addFuelModal.addEventListener('click', (e) => { if (e.target === addFuelModal) closeAddFuelModal(); });

    if (addFuelForm) {
      addFuelForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const formData = new FormData(addFuelForm);
        const csrfValue = getCsrfToken();
        if (csrfValue) formData.set('csrf', csrfValue);
        try {
          const res = await fetch('manage-oil.php', { method: 'POST', body: formData });
          const data = await res.json();
          if (!data.ok) {
            alert('Add fuel type failed: ' + (data.error || 'unknown'));
            return;
          }
          closeAddFuelModal();
          window.location.reload();
        } catch (err) {
          alert('Network error: ' + err.message);
        }
      });
    }

    const editFuelModal = document.getElementById('edit-fuel-modal');
    const editFuelForm = document.getElementById('edit-fuel-form');
    const editFuelCloseBtn = document.getElementById('edit-fuel-close-btn');
    const editFuelCancelBtn = document.getElementById('edit-fuel-cancel-btn');

    function openEditFuelModal(button) {
      if (!button || !editFuelModal || !editFuelForm) return;
      const id = button.dataset.id || '';
      const fuelCode = button.dataset.fuel || '';
      const name = button.dataset.name || '';
      const capacity = button.dataset.capacity || '';
      const price = button.dataset.price || '';

      document.getElementById('edit-fuel-tank-id').value = id;
      document.getElementById('edit-fuel-code').value = fuelCode;
      document.getElementById('edit-fuel-name').value = name;
      document.getElementById('edit-fuel-capacity').value = capacity;
      document.getElementById('edit-fuel-price').value = price;
      editFuelModal.classList.add('active');
    }

    const closeEditFuelModal = () => editFuelModal && editFuelModal.classList.remove('active');
    if (editFuelCloseBtn) editFuelCloseBtn.addEventListener('click', closeEditFuelModal);
    if (editFuelCancelBtn) editFuelCancelBtn.addEventListener('click', closeEditFuelModal);
    if (editFuelModal) editFuelModal.addEventListener('click', (e) => { if (e.target === editFuelModal) closeEditFuelModal(); });

    if (editFuelForm) {
      editFuelForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const formData = new FormData(editFuelForm);
        const csrfValue = getCsrfToken();
        if (csrfValue) formData.set('csrf', csrfValue);
        try {
          const res = await fetch('manage-oil.php', { method: 'POST', body: formData });
          const data = await res.json();
          if (!data.ok) {
            alert('Edit fuel type failed: ' + (data.error || 'unknown'));
            return;
          }
          closeEditFuelModal();
          window.location.reload();
        } catch (err) {
          alert('Network error: ' + err.message);
        }
      });
    }

    document.querySelectorAll('.edit-action').forEach((btn) => {
      btn.addEventListener('click', () => openEditFuelModal(btn));
    });

    document.querySelectorAll('.toggle-btn').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const formData = new FormData();
        formData.append('action', 'toggle_fuel');
        formData.append('tank_id', btn.dataset.id || '');
        formData.append('enabled', btn.dataset.enabled === '1' ? '0' : '1');
        const csrfValue = getCsrfToken();
        if (csrfValue) formData.append('csrf', csrfValue);

        try {
          const res = await fetch('manage-oil.php', { method: 'POST', body: formData });
          const data = await res.json();
          if (!data.ok) {
            alert('Failed to update fuel status: ' + (data.error || 'unknown'));
            return;
          }
          window.location.reload();
        } catch (err) {
          alert('Network error: ' + err.message);
        }
      });
    });

    function bindDeleteButtons() {
      document.querySelectorAll('.delete-btn').forEach((btn) => {
        btn.onclick = async () => {
          const row = btn.closest('tr');
          const targetName = row?.querySelector('.fuel-name-cell')?.textContent?.trim() || 'this fuel record';
          const action = btn.dataset.action || 'delete';
          const isFuelType = action === 'delete' && btn.dataset.fuel;
          const title = isFuelType ? 'Delete Fuel Type?' : 'Delete Fuel Record?';
          const message = isFuelType
            ? `Delete ${targetName}? This will remove the fuel type from the database.`
            : `Delete ${targetName}? This will remove the refill record from the database.`;

          openDeleteModal(title, message, async () => {
            const formData = new FormData();
            formData.append('action', isFuelType ? 'delete_fuel_type' : 'delete_refill');
            formData.append('tank_id', btn.dataset.id || '');
            const csrfValue = getCsrfToken();
            if (csrfValue) formData.append('csrf', csrfValue);

            try {
              const res = await fetch('manage-oil.php', { method: 'POST', body: formData });
              const data = await res.json();
              if (!data.ok) {
                alert((isFuelType ? 'Delete fuel type failed: ' : 'Delete refill record failed: ') + (data.error || 'unknown'));
                return;
              }
              window.location.reload();
            } catch (err) {
              alert('Network error: ' + err.message);
            }
          });
        };
      });
    }

    bindDeleteButtons();

    const limitModal = document.getElementById('limit-modal');
    const openLimitBtn = document.getElementById('open-limit-modal');
    const closeLimitBtn = document.getElementById('limit-modal-close-btn');
    const cancelLimitBtn = document.getElementById('limit-modal-cancel-btn');
    const limitForm = document.getElementById('limit-form');
    const closeLimitModal = () => limitModal && limitModal.classList.remove('active');

    if (openLimitBtn && limitModal) {
      openLimitBtn.addEventListener('click', () => limitModal.classList.add('active'));
    }
    if (closeLimitBtn) closeLimitBtn.addEventListener('click', closeLimitModal);
    if (cancelLimitBtn) cancelLimitBtn.addEventListener('click', closeLimitModal);
    if (limitModal) limitModal.addEventListener('click', (e) => { if (e.target === limitModal) closeLimitModal(); });

    if (limitForm) {
      limitForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const formData = new FormData(limitForm);
        const csrfValue = getCsrfToken();
        if (csrfValue) formData.set('csrf', csrfValue);
        try {
          const res = await fetch('manage-oil.php', { method: 'POST', body: formData });
          const data = await res.json();
          if (!data.ok) {
            alert('Save limits failed: ' + (data.error || 'unknown'));
            return;
          }
          closeLimitModal();
          window.location.reload();
        } catch (err) {
          alert('Network error: ' + err.message);
        }
      });
    }

    const refillModal = document.getElementById('refill-modal');
    const openRefillBtn = document.getElementById('open-refill-modal');
    const closeRefillBtn = document.getElementById('refill-modal-close-btn');
    const cancelRefillBtn = document.getElementById('refill-modal-cancel-btn');
    const refillForm = document.getElementById('refill-form');
    const closeRefillModal = () => refillModal && refillModal.classList.remove('active');

    if (openRefillBtn && refillModal) {
      openRefillBtn.addEventListener('click', () => {
        refillForm.reset();
        refillModal.classList.add('active');
      });
    }
    if (closeRefillBtn) closeRefillBtn.addEventListener('click', closeRefillModal);
    if (cancelRefillBtn) cancelRefillBtn.addEventListener('click', closeRefillModal);
    if (refillModal) refillModal.addEventListener('click', (e) => { if (e.target === refillModal) closeRefillModal(); });

    if (refillForm) {
      refillForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const formData = new FormData(refillForm);
        const csrfValue = getCsrfToken();
        if (csrfValue) formData.set('csrf', csrfValue);
        try {
          const res = await fetch('manage-oil.php', { method: 'POST', body: formData });
          const data = await res.json();
          if (!data.ok) {
            alert('Refill fuel failed: ' + (data.error || 'unknown'));
            return;
          }
          closeRefillModal();
          window.location.reload();
        } catch (err) {
          alert('Network error: ' + err.message);
        }
      });
    }

    function getThresholdStatus(percent, threshold) {
      const alert = Number(threshold ?? 25);
      if (percent <= alert) {
        return { status: 'low', label: 'Low', className: 'low', icon: 'fa-triangle-exclamation', text: 'Stock low — delivery needed' };
      }
      if (percent < 60) {
        return { status: 'watch', label: 'Watch', className: 'mid', icon: 'fa-circle-half-stroke', text: 'Watch level' };
      }
      return { status: 'sufficient', label: 'Sufficient', className: 'ok', icon: 'fa-check', text: 'Stock sufficient' };
    }
  });
</script>
</body>
</html>
