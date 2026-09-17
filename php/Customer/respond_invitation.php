<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../invitations_schema.php';

try {
    map_pump_ensure_invitation_schema($pdo);
} catch (Throwable $error) {
    $_SESSION['customer_invitation_message'] = $error->getMessage();
    header('Location: dashboard.php');
    exit;
}

if (empty($_SESSION['user_id'])) {
    header('Location: ../Login/login.php');
    exit;
}

$invitationId = filter_var($_POST['invitation_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$decision = strtolower(trim((string)($_POST['decision'] ?? '')));
$userId = (int)$_SESSION['user_id'];

if (!$invitationId || !in_array($decision, ['accept', 'decline'], true)) {
    $_SESSION['customer_invitation_message'] = 'Invalid invitation response.';
    header('Location: dashboard.php');
    exit;
}

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        "SELECT invitation_id, headquarter_id, invited_email, business_role_id, station_id
         FROM invitations
         WHERE invitation_id = ? AND invited_user_id = ? AND status = 'pending'
         FOR UPDATE"
    );
    $stmt->execute([$invitationId, $userId]);
    $invitation = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invitation) {
        throw new RuntimeException('This invitation is no longer pending.');
    }

    if ($decision === 'accept') {
        $roleId = (int) $invitation['business_role_id'];
        $hqId = (int) $invitation['headquarter_id'];
        $branchId = (int) $invitation['station_id'];

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

        $userDetails = $pdo->prepare('SELECT user_name, email FROM users WHERE user_id = ? LIMIT 1');
        $userDetails->execute([$userId]);
        $acceptedUser = $userDetails->fetch(PDO::FETCH_ASSOC);
        if (!$acceptedUser) {
            throw new RuntimeException('The invited user account no longer exists.');
        }

        $userUpdate = $pdo->prepare(
            "UPDATE users
             SET business_role_id = ?, headquarter_id = ?, branch_id = ?, status = 'active', updated_at = NOW()
             WHERE user_id = ?"
        );
        $userUpdate->execute([$roleId, $hqId, $branchId, $userId]);
        $scope = $pdo->prepare(
            "INSERT INTO business_account_scope
                (user_id, business_role_id, headquarter_id, branch_manager_user_id, station_id, status)
             VALUES (?, ?, ?, NULL, ?, 'active')
             ON DUPLICATE KEY UPDATE business_role_id = VALUES(business_role_id), headquarter_id = VALUES(headquarter_id), station_id = VALUES(station_id), status = 'active'"
        );
        $scope->execute([$userId, $roleId, $hqId, $branchId]);

        if ($roleId === 5) {
            $staffLookup = $pdo->prepare('SELECT staff_id FROM staff WHERE user_id = ? LIMIT 1');
            $staffLookup->execute([$userId]);
            $staffId = $staffLookup->fetchColumn();

            if ($staffId) {
                $staffUpdate = $pdo->prepare(
                    "UPDATE staff SET branch_id = ?, full_name = ?, is_active = 1 WHERE staff_id = ?"
                );
                $staffUpdate->execute([$branchId, $acceptedUser['user_name'], $staffId]);
            } else {
                $hasEmail = (bool) $pdo->query("SHOW COLUMNS FROM staff LIKE 'email'")->fetchColumn();
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
                    $codeStmt = $pdo->prepare(
                        'SELECT MAX(CAST(SUBSTRING(staff_code, 4) AS UNSIGNED)) FROM staff WHERE branch_id = ?'
                    );
                    $codeStmt->execute([$branchId]);
                    $staffCode = 'ST-' . (((int) $codeStmt->fetchColumn() ?: 2090) + 1);

                    if ($hasEmail) {
                        $staffInsert = $pdo->prepare(
                            "INSERT INTO staff
                                (user_id, branch_id, staff_code, full_name, role, shift, contact, email, is_active, duty_status, hired_at)
                             VALUES (?, ?, ?, ?, 'Pump Attendant', 'Morning', '', ?, 1, 'Off Duty', CURDATE())"
                        );
                        $staffInsert->execute([$userId, $branchId, $staffCode, $acceptedUser['user_name'], $acceptedUser['email']]);
                    } else {
                        $staffInsert = $pdo->prepare(
                            "INSERT INTO staff
                                (user_id, branch_id, staff_code, full_name, role, shift, contact, is_active, duty_status, hired_at)
                             VALUES (?, ?, ?, ?, 'Pump Attendant', 'Morning', '', 1, 'Off Duty', CURDATE())"
                        );
                        $staffInsert->execute([$userId, $branchId, $staffCode, $acceptedUser['user_name']]);
                    }
                }
            }
        }
    }

    $update = $pdo->prepare('UPDATE invitations SET status = ?, responded_at = NOW() WHERE invitation_id = ?');
    $update->execute([$decision === 'accept' ? 'accepted' : 'declined', $invitationId]);
    $pdo->commit();
    $_SESSION['customer_invitation_message'] = $decision === 'accept' ? 'Invitation accepted. Your business account is now active.' : 'Invitation declined.';
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[Customer invitation] Response failed: ' . $error->getMessage());
    $_SESSION['customer_invitation_message'] = $error->getMessage();
}

header('Location: dashboard.php');
exit;