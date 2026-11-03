<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function map_pump_staff_record_for_user(PDO $pdo, int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    try {
        $roleStmt = $pdo->query(
            "SELECT role_id
             FROM roles
             WHERE LOWER(TRIM(role_name)) IN ('staff', 'pump attendant', 'pumpattendant')
             ORDER BY role_id ASC
             LIMIT 1"
        );
        $staffRoleId = (int) ($roleStmt ? $roleStmt->fetchColumn() : 0);

        $stmt = $pdo->prepare(
            "SELECT u.user_id, u.user_name, u.email, u.status, u.role_id, u.business_role_id, u.current_role_id, u.headquarter_id,
                    s.staff_id, s.branch_id, s.is_active, s.full_name, s.staff_code,
                    r.role_name AS current_role_name,
                    br.role_name AS business_role_name
             FROM users u
             LEFT JOIN staff s ON s.user_id = u.user_id
             LEFT JOIN roles r ON r.role_id = u.current_role_id
             LEFT JOIN roles br ON br.role_id = u.business_role_id
             WHERE u.user_id = :user_id
             LIMIT 1"
        );
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $status = strtolower(trim((string) ($row['status'] ?? 'active')));
        $isActive = (int) ($row['is_active'] ?? 0) === 1;
        $matchedStaffRole = $staffRoleId > 0
            && ((int) ($row['business_role_id'] ?? 0) === $staffRoleId
                || (int) ($row['current_role_id'] ?? 0) === $staffRoleId
                || (int) ($row['role_id'] ?? 0) === $staffRoleId);

        if (!$isActive || !$matchedStaffRole || in_array($status, ['disabled', 'suspended', 'removed', 'banned'], true)) {
            return null;
        }

        return $row;
    } catch (Throwable $e) {
        error_log('Staff access guard failed while checking staff record: ' . $e->getMessage());
        return null;
    }
}

function map_pump_require_active_staff_session(PDO $pdo, bool $forAjax = false): array
{
    $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
    if ($userId <= 0) {
        $_SESSION['login_error'] = 'Please sign in to continue.';
        if ($forAjax) {
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Please sign in to continue.']);
            exit;
        }

        header('Location: /php/Login/login.php');
        exit;
    }

    $row = map_pump_staff_record_for_user($pdo, $userId);
    if ($row === null) {
        $_SESSION['profile_flash_message'] = 'Your staff account is disabled or no longer available.';
        $_SESSION['profile_flash_type'] = 'danger';
        if ($forAjax) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Your staff account is disabled or no longer available.']);
            exit;
        }

        header('Location: /php/Customer/dashboard.php');
        exit;
    }

    $_SESSION['role_id'] = (int) (($row['current_role_id'] ?? $row['business_role_id'] ?? $row['role_id'] ?? 5) ?: 5);
    $_SESSION['role_name'] = 'Staff';
    $_SESSION['current_role_id'] = (int) (($row['current_role_id'] ?? $row['business_role_id'] ?? $row['role_id'] ?? 5) ?: 5);
    $_SESSION['current_role_name'] = 'Staff';
    $_SESSION['business_role_id'] = (int) ($row['business_role_id'] ?? 0);
    $_SESSION['business_role_name'] = (string) ($row['business_role_name'] ?? 'Staff');
    $_SESSION['headquarter_id'] = (int) ($row['headquarter_id'] ?? 0);
    $_SESSION['active_profile_mode'] = 'business';

    return $row;
}
