<?php

function customer_license_status_column_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    try {
        $stmt = $pdo->query(
            "SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'user_license'
               AND COLUMN_NAME = 'status'"
        );
        $ready = ((int) $stmt->fetchColumn()) > 0;

        if (!$ready) {
            $pdo->exec("ALTER TABLE user_license ADD COLUMN status varchar(20) NOT NULL DEFAULT 'pending' AFTER license_last_valid_date");
            $ready = true;
        }
    } catch (Throwable $e) {
        error_log('Driver license status column check failed: ' . $e->getMessage());
        $ready = false;
    }

    return $ready;
}

function customer_license_access(PDO $pdo, int $userId): array
{
    $hasStatusColumn = customer_license_status_column_ready($pdo);
    $statusSelect = $hasStatusColumn ? 'l.status' : "'pending' AS status";

    $stmt = $pdo->prepare(
        "SELECT u.license_id, {$statusSelect}
         FROM users u
         LEFT JOIN user_license l ON l.license_id = u.license_id
         WHERE u.user_id = ?
         LIMIT 1"
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $status = strtolower(trim((string) ($row['status'] ?? 'pending')));

    return [
        'has_license' => !empty($row['license_id']),
        'license_id' => (int) ($row['license_id'] ?? 0),
        'status' => $status ?: 'pending',
        'is_approved' => in_array($status, ['approved', 'confirmed', 'active'], true),
        'has_status_column' => $hasStatusColumn,
    ];
}

function customer_license_valid_for_booking(PDO $pdo, int $userId, bool $lock = false): bool
{
    if ($userId <= 0 || !customer_license_status_column_ready($pdo)) return false;
    $sql = "SELECT l.status, l.license_last_valid_date
            FROM users u INNER JOIN user_license l ON l.license_id = u.license_id
            WHERE u.user_id = ? LIMIT 1" . ($lock ? ' FOR UPDATE' : '');
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row
        && in_array(strtolower(trim((string)$row['status'])), ['approved', 'confirmed', 'active'], true)
        && !empty($row['license_last_valid_date'])
        && (string)$row['license_last_valid_date'] >= date('Y-m-d');
}

function customer_license_status_label(?string $status): string
{
    return match (strtolower(trim((string) $status))) {
        'approved', 'confirmed', 'active' => 'Confirmed',
        'expired' => 'Expired',
        'rejected' => 'Rejected',
        default => 'Pending Review',
    };
}
