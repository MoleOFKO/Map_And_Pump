<?php
require_once __DIR__ . '/hq_guard.php';
require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../business_scope.php';
require_once __DIR__ . '/../invitations_schema.php';
require_once __DIR__ . '/../Login/mail_templates.php';
require_once __DIR__ . '/../Login/account_password_setup.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: account.php');
    exit;
}

$email = strtolower(trim((string)($_POST['email'] ?? '')));
$role = strtolower(trim((string)($_POST['role'] ?? '')));
$stationValue = trim((string)($_POST['station_id'] ?? ''));
$stationId = $stationValue === '' ? null : filter_var($stationValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$hqId = map_pump_current_hq_id($pdo);
$roleId = $role === 'manager' ? 4 : ($role === 'staff' ? 5 : 0);

if ($hqId <= 0 || !filter_var($email, FILTER_VALIDATE_EMAIL) || $roleId <= 0 || ($stationValue !== '' && !$stationId) || ($roleId === 5 && !$stationId)) {
    $_SESSION['hq_account_message'] = ['type' => 'danger', 'text' => 'Please provide a valid email and role. Staff invitations require a station assignment.'];
    header('Location: account.php');
    exit;
}

try {
    map_pump_ensure_invitation_schema($pdo);

    $station = null;
    if ($stationId) {
        $stationStmt = $pdo->prepare(
            "SELECT s.branch_name AS station_name,
                CASE WHEN s.latitude IS NOT NULL AND s.longitude IS NOT NULL
                     THEN CONCAT(s.latitude, ', ', s.longitude) ELSE s.address END AS location
             FROM branches s
             INNER JOIN station_business_scope sbs ON sbs.station_id = s.branch_id
             WHERE s.branch_id = ? AND sbs.headquarter_id = ? LIMIT 1"
        );
        $stationStmt->execute([$stationId, $hqId]);
        $station = $stationStmt->fetch(PDO::FETCH_ASSOC);
        if (!$station) {
            throw new RuntimeException('That station is not related to your HQ.');
        }
    }

    $userStmt = $pdo->prepare(
        "SELECT u.user_id, u.user_name, u.hashed_password, u.status,
                primary_role.role_name AS primary_role_name,
                active_role.role_name AS active_role_name
         FROM users u
         LEFT JOIN roles primary_role ON primary_role.role_id = u.role_id
         LEFT JOIN roles active_role ON active_role.role_id = u.current_role_id
         WHERE LOWER(TRIM(u.email)) = LOWER(TRIM(?))
         LIMIT 1"
    );
    $userStmt->execute([$email]);
    $invitee = $userStmt->fetch(PDO::FETCH_ASSOC);
    $createdAccount = false;
    $setupUrl = '';
    if ($invitee) {
        $isCustomer = strtolower(trim((string) ($invitee['primary_role_name'] ?? ''))) === 'customer'
            || strtolower(trim((string) ($invitee['active_role_name'] ?? ''))) === 'customer';
        if (!$isCustomer) {
            throw new RuntimeException('This email belongs to an account that cannot be invited as a branch manager or staff member.');
        }
        if ($roleId === 4 && in_array(strtolower((string) ($invitee['status'] ?? '')), ['disabled', 'banned', 'suspended', 'removed'], true)) {
            throw new RuntimeException('This customer account is disabled and cannot be added as a branch manager.');
        }
    } elseif ($roleId === 4) {
        $localPart = strstr($email, '@', true);
        $userName = trim(str_replace(['.', '_', '-'], ' ', (string) $localPart));
        if ($userName === '') {
            $userName = 'Branch Manager';
        }
        $invitee = [
            'user_id' => 0,
            'user_name' => $userName,
            'hashed_password' => null,
        ];
        $createdAccount = true;
    } else {
        throw new RuntimeException('No customer account is registered with that email address.');
    }

    if ($roleId === 4 && !$createdAccount && (int) $invitee['user_id'] > 0) {
        $existingAssignmentStmt = $pdo->prepare(
            'SELECT station_id, headquarter_id
             FROM station_business_scope
             WHERE branch_manager_user_id = ?
             LIMIT 1'
        );
        $existingAssignmentStmt->execute([(int) $invitee['user_id']]);
        $existingAssignment = $existingAssignmentStmt->fetch(PDO::FETCH_ASSOC);
        if ($existingAssignment && (int) $existingAssignment['headquarter_id'] !== $hqId) {
            throw new RuntimeException('This customer is already assigned as a branch manager under another HQ.');
        }
        if (!$stationId && $existingAssignment) {
            $stationId = (int) $existingAssignment['station_id'];
            $existingStationStmt = $pdo->prepare(
                'SELECT branch_name AS station_name
                 FROM branches
                 WHERE branch_id = ?
                 LIMIT 1'
            );
            $existingStationStmt->execute([$stationId]);
            $existingStationName = $existingStationStmt->fetchColumn();
            if ($existingStationName !== false) {
                $station = ['station_name' => (string) $existingStationName];
            }
        }
    }

    if ($roleId === 5) {
        $pendingStmt = $pdo->prepare(
            "SELECT invitation_id FROM invitations
             WHERE invited_user_id = ? AND headquarter_id = ? AND status = 'pending' LIMIT 1"
        );
        $pendingStmt->execute([(int)$invitee['user_id'], $hqId]);
        if ($pendingStmt->fetchColumn()) {
            throw new RuntimeException('This customer already has a pending invitation from your HQ.');
        }
    }

    $hqStmt = $pdo->prepare('SELECT company_name FROM headquarter WHERE headquarter_id = ? LIMIT 1');
    $hqStmt->execute([$hqId]);
    $companyName = (string)($hqStmt->fetchColumn() ?: 'Map & Pump HQ');
    $roleName = $roleId === 4 ? 'Branch Manager' : 'Staff';
    $message = sprintf(
        '%s added you to %s as a %s at %s. Sign in to Map & Pump to access your account.',
        (string)($_SESSION['user_name'] ?? 'Your HQ administrator'),
        $companyName,
        $roleName,
        $station ? (string)$station['station_name'] : 'no assigned station'
    );

    $notificationId = null;
    if ($roleId === 4) {
        account_password_setup_ensure_schema_pdo($pdo);
    }

    $pdo->beginTransaction();
    if ($roleId === 4) {
        if ($createdAccount) {
            $createAccount = $pdo->prepare(
                "INSERT INTO users
                    (user_name, email, hashed_password, headquarter_id, role_id, business_role_id, current_role_id, status, created_at, updated_at)
                 VALUES (?, ?, NULL, ?, 6, 4, 4, 'active', NOW(), NOW())"
            );
            $createAccount->execute([$invitee['user_name'], $email, $hqId]);
            $invitee['user_id'] = (int) $pdo->lastInsertId();
        } else {
            $userUpdate = $pdo->prepare(
                "UPDATE users
                 SET headquarter_id = ?, business_role_id = 4, current_role_id = 4, status = 'active', updated_at = NOW()
                 WHERE user_id = ?"
            );
            $userUpdate->execute([$hqId, (int) $invitee['user_id']]);
        }

        if ($stationId) {
            $stationManagerStmt = $pdo->prepare(
                'SELECT branch_manager_user_id FROM station_business_scope WHERE station_id = ? AND headquarter_id = ? FOR UPDATE'
            );
            $stationManagerStmt->execute([$stationId, $hqId]);
            $assignedManagerId = $stationManagerStmt->fetchColumn();
            if ($assignedManagerId && (int) $assignedManagerId !== (int) $invitee['user_id']) {
                throw new RuntimeException('This station already has a branch manager assigned.');
            }

            $otherStationStmt = $pdo->prepare(
                'SELECT station_id FROM station_business_scope
                 WHERE headquarter_id = ? AND branch_manager_user_id = ? AND station_id <> ?
                 LIMIT 1'
            );
            $otherStationStmt->execute([$hqId, (int) $invitee['user_id'], $stationId]);
            if ($otherStationStmt->fetchColumn()) {
                throw new RuntimeException('This branch manager is already assigned to another station.');
            }

            $stationManagerUpdate = $pdo->prepare(
                'UPDATE station_business_scope SET branch_manager_user_id = ? WHERE station_id = ? AND headquarter_id = ?'
            );
            $stationManagerUpdate->execute([(int) $invitee['user_id'], $stationId, $hqId]);
            $branchManagerUpdate = $pdo->prepare('UPDATE branches SET manager_user_id = ? WHERE branch_id = ?');
            $branchManagerUpdate->execute([(int) $invitee['user_id'], $stationId]);
        }

        $accountScope = $pdo->prepare(
            "INSERT INTO business_account_scope
                (user_id, business_role_id, headquarter_id, branch_manager_user_id, station_id, status)
             VALUES (?, 4, ?, NULL, ?, 'active')
             ON DUPLICATE KEY UPDATE
                business_role_id = 4, headquarter_id = VALUES(headquarter_id),
                station_id = VALUES(station_id), status = 'active'"
        );
        $accountScope->execute([(int) $invitee['user_id'], $hqId, $stationId ?: null]);

        $subject = 'Your Map & Pump branch manager account';
        $notification = $pdo->prepare(
            "INSERT INTO notifications
             (recipient_user_id, recipient_email, notification_type, subject, message, delivery_status, delivery_note)
             VALUES (?, ?, 'hq_invitation', ?, ?, 'pending', NULL)"
        );
        $notification->execute([(int) $invitee['user_id'], $email, $subject, $message]);
        $notificationId = (int) $pdo->lastInsertId();

        if (trim((string) ($invitee['hashed_password'] ?? '')) === '') {
            $setupUrl = account_password_setup_create_link_pdo($pdo, (int) $invitee['user_id']);
        }
    } else {
        $insert = $pdo->prepare(
            "INSERT INTO invitations
             (headquarter_id, invited_user_id, invited_email, business_role_id, station_id, status)
             VALUES (?, ?, ?, ?, ?, 'pending')"
        );
        $insert->execute([$hqId, (int) $invitee['user_id'], $email, $roleId, $stationId]);
        $invitationId = (int) $pdo->lastInsertId();

        $subject = 'New Map & Pump HQ invitation';
        $notification = $pdo->prepare(
            "INSERT INTO notifications
             (recipient_user_id, recipient_email, notification_type, subject, message, delivery_status, delivery_note)
             VALUES (?, ?, 'hq_invitation', ?, ?, 'pending', NULL)"
        );
        $notification->execute([(int) $invitee['user_id'], $email, $subject, $message]);
        $notificationId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE invitations SET notification_id = ? WHERE invitation_id = ?')
            ->execute([$notificationId, $invitationId]);
    }
    $pdo->commit();

    $emailNotice = mail_send_account_notice($email, [
        'kind' => 'hq_invitation',
        'company_name' => $companyName,
        'role_name' => $roleName,
        'station_name' => (string) ($station['station_name'] ?? ''),
        'account_active' => $roleId === 4,
        'password_setup_sent' => $setupUrl !== '',
    ]);
    $deliveryStatus = (string) ($emailNotice['status'] ?? 'failed');
    $deliveryNote = (string) ($emailNotice['note'] ?? 'Email delivery failed.');
    $setupEmailNotice = null;
    if ($setupUrl !== '') {
        $setupEmailNotice = mail_send_account_notice($email, [
            'kind' => 'account_password_setup',
            'account_label' => 'Branch Manager',
            'account_name' => (string) $invitee['user_name'],
            'setup_url' => $setupUrl,
        ]);
        if (($setupEmailNotice['status'] ?? '') !== 'sent') {
            $deliveryNote .= ' Password setup email: ' . (string) ($setupEmailNotice['status'] ?? 'failed')
                . ' - ' . (string) ($setupEmailNotice['note'] ?? 'Email delivery failed.');
        }
    }
    if ($notificationId !== null) {
        $pdo->prepare('UPDATE notifications SET delivery_status = ?, delivery_note = ? WHERE notification_id = ?')
            ->execute([$deliveryStatus, substr($deliveryNote, 0, 255), $notificationId]);
    }
    $setupStatus = $setupEmailNotice !== null
        ? ' Password setup email status: ' . (string) ($setupEmailNotice['status'] ?? 'failed') . '.'
        : '';
    $_SESSION['hq_account_message'] = [
        'type' => $deliveryStatus === 'sent' && ($setupEmailNotice === null || ($setupEmailNotice['status'] ?? '') === 'sent') ? 'success' : 'danger',
        'text' => ($roleId === 4 ? 'Branch manager added. ' : 'Staff invitation created. ')
            . 'Invitation email status: ' . $deliveryStatus . '.' . $setupStatus,
    ];
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['hq_account_message'] = ['type' => 'danger', 'text' => $error->getMessage()];
}

header('Location: account.php');
exit;
