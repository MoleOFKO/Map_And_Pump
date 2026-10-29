<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../invitations_schema.php';
require_once __DIR__ . '/../BranchManager/_helpers.php';
require_once __DIR__ . '/license_expiry.php';

try {
    map_pump_ensure_invitation_schema($pdo);
} catch (Throwable $error) {
    $_SESSION['customer_invitation_message'] = $error->getMessage();
    header('Location: dashboard.php');
    exit;
}

$invitationId = filter_var($_POST['invitation_id'] ?? ($_GET['invitation'] ?? null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$decision = strtolower(trim((string)($_POST['decision'] ?? $_GET['decision'] ?? '')));
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$redirectLocation = 'dashboard.php';
$licenseExpiryState = $userId ? customer_sync_license_expiry($pdo, $userId) : ['driver' => ['status' => 'active']];

if (empty($_SESSION['user_id']) && $decision === '') {
    header('Location: ../Login/login.php');
    exit;
}

if (($licenseExpiryState['driver']['status'] ?? '') === 'expired') {
    header('Location: profile_page.php');
    exit;
}

if (!$invitationId || !in_array($decision, ['accept', 'decline'], true)) {
    $_SESSION['customer_invitation_message'] = 'Invalid invitation response.';
    header('Location: ' . ($userId ? 'dashboard.php' : '../Login/login.php'));
    exit;
}

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        "SELECT invitation_id, headquarter_id, invited_email, business_role_id, station_id, invited_user_id
         FROM invitations
         WHERE invitation_id = ? AND invited_user_id = ? AND status = 'pending'
         FOR UPDATE"
    );
    $stmt->execute([$invitationId, $userId]);
    $invitation = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invitation) {
        throw new RuntimeException('This invitation is no longer pending.');
    }

    $branchId = null;
    if ($decision === 'accept') {
        $roleId = (int) $invitation['business_role_id'];
        $hqId = (int) $invitation['headquarter_id'];
        $branchId = $invitation['station_id'] === null ? null : (int) $invitation['station_id'];

        if ($branchId !== null) {
            $branchStmt = $pdo->prepare(
                "SELECT b.branch_id
                 FROM branches b
                 INNER JOIN station_business_scope sbs ON sbs.station_id = b.branch_id
                 WHERE b.branch_id = ? AND sbs.headquarter_id = ?
                 LIMIT 1"
            );
            $branchStmt->execute([$branchId, $hqId]);
            if (!$branchStmt->fetchColumn()) {
                throw new RuntimeException('This invitation is not linked to a valid branch under its HQ.');
            }
        } elseif ($roleId === 5) {
            throw new RuntimeException('A staff invitation needs a station assignment before it can be accepted.');
        }

        $userDetails = $pdo->prepare('SELECT user_name, email, phone FROM users WHERE user_id = ? LIMIT 1');
        $userDetails->execute([$userId]);
        $acceptedUser = $userDetails->fetch(PDO::FETCH_ASSOC);
        if (!$acceptedUser) {
            throw new RuntimeException('The invited user account no longer exists.');
        }

        $userUpdateSql = "UPDATE users SET business_role_id = ?, headquarter_id = ?, branch_id = ?, status = 'active', updated_at = NOW()";
        $userParams = [$roleId, $hqId, $branchId, $userId];
        if (column_exists($pdo, 'users', 'current_role_id')) {
            $userUpdateSql .= ', current_role_id = ?';
            $userParams = [$roleId, $hqId, $branchId, $roleId, $userId];
        }
        $userUpdateSql .= " WHERE user_id = ?";
        $userUpdate = $pdo->prepare($userUpdateSql);
        $userUpdate->execute($userParams);

        $scope = $pdo->prepare(
            "INSERT INTO business_account_scope
                (user_id, business_role_id, headquarter_id, branch_manager_user_id, station_id, status)
             VALUES (?, ?, ?, NULL, ?, 'active')
             ON DUPLICATE KEY UPDATE business_role_id = VALUES(business_role_id), headquarter_id = VALUES(headquarter_id), station_id = VALUES(station_id), status = 'active'"
        );
        $scope->execute([$userId, $roleId, $hqId, $branchId]);

        if ($roleId === 4 && $branchId !== null) {
            $managerAssignment = $pdo->prepare(
                'UPDATE station_business_scope
                 SET branch_manager_user_id = ?
                 WHERE station_id = ? AND headquarter_id = ?'
            );
            $managerAssignment->execute([$userId, $branchId, $hqId]);

            $branchManager = $pdo->prepare(
                'UPDATE branches SET manager_user_id = ? WHERE branch_id = ?'
            );
            $branchManager->execute([$userId, $branchId]);
        }

        if ($roleId === 5) {
            $staffLookup = $pdo->prepare('SELECT staff_id FROM staff WHERE user_id = ? LIMIT 1');
            $staffLookup->execute([$userId]);
            $staffId = $staffLookup->fetchColumn();

            if ($staffId) {
                $staffUpdateSql = "UPDATE staff SET branch_id = ?, full_name = ?, is_active = 1";
                $staffUpdateParams = [$branchId, $acceptedUser['user_name'], $staffId];
                if (column_exists($pdo, 'staff', 'email')) {
                    $staffUpdateSql .= ', email = ?';
                    $staffUpdateParams = [$branchId, $acceptedUser['user_name'], $acceptedUser['email'], $staffId];
                }
                if (column_exists($pdo, 'staff', 'contact')) {
                    $staffUpdateSql .= ', contact = ?';
                    $staffUpdateParams = [$branchId, $acceptedUser['user_name'], $acceptedUser['email'], ($acceptedUser['phone'] ?? ''), $staffId];
                }
                $staffUpdateSql .= ' WHERE staff_id = ?';
                $staffUpdate = $pdo->prepare($staffUpdateSql);
                $staffUpdate->execute($staffUpdateParams);
            } else {
                $hasEmail = column_exists($pdo, 'staff', 'email');
                $legacySql = 'SELECT staff_id FROM staff WHERE user_id IS NULL AND branch_id = ? AND full_name = ? LIMIT 1';
                if ($hasEmail) {
                    $legacySql = 'SELECT staff_id FROM staff WHERE user_id IS NULL AND branch_id = ? AND (full_name = ? OR email = ?) LIMIT 1';
                }
                $legacyLookup = $pdo->prepare($legacySql);
                $legacyParams = $hasEmail
                    ? [$branchId, $acceptedUser['user_name'], $acceptedUser['email']]
                    : [$branchId, $acceptedUser['user_name']];
                $legacyLookup->execute($legacyParams);
                $legacyStaffId = $legacyLookup->fetchColumn();

                if ($legacyStaffId) {
                    $linkLegacy = $pdo->prepare('UPDATE staff SET user_id = ?, is_active = 1 WHERE staff_id = ?');
                    $linkLegacy->execute([$userId, $legacyStaffId]);
                } else {
                    $lockName = 'staff_code_gen_' . (int)$branchId;
                    $gotLock = (int) $pdo->query("SELECT GET_LOCK('" . addslashes($lockName) . "', 10)")->fetchColumn();
                    if (!$gotLock) {
                        throw new RuntimeException('Could not acquire lock for staff code generation.');
                    }
                    try {
                        $codeStmt = $pdo->prepare(
                            "SELECT COALESCE(MAX(CAST(SUBSTRING(staff_code, 4) AS UNSIGNED)), 2090)
                             FROM staff
                             WHERE branch_id = ?
                               AND staff_code REGEXP '^ST-[0-9]+$'"
                        );
                        $codeStmt->execute([$branchId]);
                        $next = max((int) $codeStmt->fetchColumn(), 2090);
                        do {
                            $next++;
                            $staffCode = 'ST-' . $next;
                            $exists = $pdo->prepare('SELECT 1 FROM staff WHERE staff_code = ? LIMIT 1');
                            $exists->execute([$staffCode]);
                        } while ($exists->fetchColumn());

                        $columns = ['user_id', 'branch_id', 'staff_code', 'full_name', 'is_active', 'duty_status', 'hired_at'];
                        $values = ['?', '?', '?', '?', '1', "'Off Duty'", 'CURDATE()'];
                        $params = [$userId, $branchId, $staffCode, $acceptedUser['user_name']];
                        if (column_exists($pdo, 'staff', 'email')) {
                            $columns[] = 'email';
                            $values[] = '?';
                            $params[] = $acceptedUser['email'];
                        }
                        if (column_exists($pdo, 'staff', 'contact')) {
                            $columns[] = 'contact';
                            $values[] = '?';
                            $params[] = $acceptedUser['phone'] ?? '';
                        }
                        if (column_exists($pdo, 'staff', 'role')) {
                            $columns[] = 'role';
                            $values[] = "'Pump Attendant'";
                        }
                        if (column_exists($pdo, 'staff', 'shift')) {
                            $columns[] = 'shift';
                            $values[] = "'Morning'";
                        }
                        if (column_exists($pdo, 'staff', 'created_at')) {
                            $columns[] = 'created_at';
                            $values[] = 'NOW()';
                        }
                        $sql = 'INSERT INTO staff (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')';
                        $stmtInsert = $pdo->prepare($sql);
                        $stmtInsert->execute($params);
                    } finally {
                        try { $pdo->query("SELECT RELEASE_LOCK('" . addslashes($lockName) . "')"); } catch (Throwable $_) {}
                    }
                }
            }
        }
    }

    $update = $pdo->prepare('UPDATE invitations SET status = ?, responded_at = NOW() WHERE invitation_id = ?');
    $update->execute([$decision === 'accept' ? 'accepted' : 'declined', $invitationId]);

    if ($decision === 'accept' && $branchId !== null) {
        $managerUserId = null;
        $managerStmt = $pdo->prepare(
            "SELECT branch_manager_user_id
             FROM station_business_scope
             WHERE station_id = ? AND branch_manager_user_id IS NOT NULL
             LIMIT 1"
        );
        $managerStmt->execute([$branchId]);
        $managerUserId = (int) $managerStmt->fetchColumn();
        if ($managerUserId > 0) {
            $notificationMessage = sprintf(
                '%s accepted the staff invitation and is now active for %s.',
                $acceptedUser['user_name'] ?? 'A staff member',
                $branchId
            );
            $pdo->prepare(
                'INSERT INTO notifications (recipient_user_id, notification_type, subject, message, delivery_status)
                 VALUES (?, ?, ?, ?, "sent")'
            )->execute([
                $managerUserId,
                'staff_invitation_accepted',
                'Staff invitation accepted',
                $notificationMessage,
            ]);
        }
    }

    $pdo->commit();
    $_SESSION['customer_invitation_message'] = $decision === 'accept' ? 'Invitation accepted. Your business account is now active.' : 'Invitation declined.';
    if ($decision === 'accept' && (int)$invitation['business_role_id'] === 4) {
        $_SESSION['role_id'] = 4;
        $_SESSION['role_name'] = 'BranchManager';
        $_SESSION['business_role_id'] = 4;
        $_SESSION['business_role_name'] = 'BranchManager';
        $_SESSION['current_role_id'] = 4;
        $_SESSION['current_role_name'] = 'BranchManager';
        $_SESSION['headquarter_id'] = (int)$invitation['headquarter_id'];
        $_SESSION['branch_id'] = $invitation['station_id'] === null ? 0 : (int)$invitation['station_id'];
        $_SESSION['active_profile_mode'] = 'business';
        $redirectLocation = '/php/BranchManager/dashboard.php';
    }
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[Customer invitation] Response failed: ' . $error->getMessage());
    $_SESSION['customer_invitation_message'] = $error->getMessage();
}

header('Location: ' . $redirectLocation);
exit;

function column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $column]);
    return (bool) $stmt->fetchColumn();
}
