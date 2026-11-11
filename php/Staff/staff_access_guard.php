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

        $isStaffIdSession = !empty($_SESSION['staff_id']) || !empty($_SESSION['standalone_staff_id']);
        $staffIdentityCondition = $isStaffIdSession
            ? 's.staff_id = :staff_id'
            : 's.user_id = :user_id';
        $stmt = $pdo->prepare(
                "SELECT s.staff_id, s.branch_id, s.is_active, s.full_name, s.staff_code, s.email, s.contact, s.staff_password, s.created_at,
                    u.user_id, u.user_name, u.email AS user_email, u.status, u.role_id, u.business_role_id, u.current_role_id,
                    COALESCE(bas.headquarter_id, sbs.headquarter_id) AS headquarter_id,
                    h.company_name AS headquarter_name, h.company_logo AS headquarter_logo,
                    b.branch_name, b.branch_code,
                    r.role_name AS current_role_name,
                    br.role_name AS business_role_name
             FROM staff s
             LEFT JOIN users u ON u.user_id = s.user_id
             LEFT JOIN branches b ON b.branch_id = s.branch_id
             LEFT JOIN business_account_scope bas ON bas.user_id = u.user_id
             LEFT JOIN station_business_scope sbs ON sbs.station_id = s.branch_id
             LEFT JOIN headquarter h ON h.headquarter_id = COALESCE(bas.headquarter_id, sbs.headquarter_id)
             LEFT JOIN roles r ON r.role_id = u.current_role_id
             LEFT JOIN roles br ON br.role_id = u.business_role_id
             WHERE {$staffIdentityCondition}
             LIMIT 1"
        );
        $staffId = (int) ($_SESSION['standalone_staff_id'] ?? $_SESSION['staff_id'] ?? 0);
        $stmt->execute($isStaffIdSession ? [':staff_id' => $staffId] : [':user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $status = strtolower(trim((string) ($row['status'] ?? 'active')));
        $isActive = (int) ($row['is_active'] ?? 0) === 1;
        $matchedStaffRole = $staffRoleId > 0
            && ((int) ($row['business_role_id'] ?? 0) === $staffRoleId
                || (int) ($row['current_role_id'] ?? 0) === $staffRoleId
                || (int) ($row['role_id'] ?? 0) === $staffRoleId
                || ((string) ($row['business_role_name'] ?? '') !== '' && strtolower(trim((string) $row['business_role_name'])) === 'staff')
                || ((string) ($row['current_role_name'] ?? '') !== '' && strtolower(trim((string) $row['current_role_name'])) === 'staff'));

        if (!$isActive || in_array($status, ['disabled', 'suspended', 'removed', 'banned'], true)) {
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
    $_SESSION['branch_id'] = (int) ($row['branch_id'] ?? 0);
    $_SESSION['active_profile_mode'] = 'business';

    return $row;
}
