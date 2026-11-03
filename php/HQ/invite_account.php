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
        "SELECT u.user_id, u.user_name,
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
    } elseif ($roleId === 4) {
        $localPart = strstr($email, '@', true);
        $userName = trim(str_replace(['.', '_', '-'], ' ', (string) $localPart));
        if ($userName === '') {
            $userName = 'Branch Manager';
        }

        account_password_setup_ensure_schema_pdo($pdo);
        $pdo->beginTransaction();
        $createAccount = $pdo->prepare(
            "INSERT INTO users (user_name, email, hashed_password, role_id, current_role_id, status, created_at, updated_at)
             VALUES (?, ?, NULL, 6, 6, 'pending', NOW(), NOW())"
        );
        $createAccount->execute([$userName, $email]);
        $invitee = [
            'user_id' => (int) $pdo->lastInsertId(),
            'user_name' => $userName,
        ];
        $createdAccount = true;
        $setupUrl = account_password_setup_create_link_pdo($pdo, (int) $invitee['user_id']);
    } else {
        throw new RuntimeException('No customer account is registered with that email address.');
    }

    $pendingStmt = $pdo->prepare(
        "SELECT invitation_id FROM invitations
         WHERE invited_user_id = ? AND headquarter_id = ? AND status = 'pending' LIMIT 1"
    );
    $pendingStmt->execute([(int)$invitee['user_id'], $hqId]);
    if ($pendingStmt->fetchColumn()) {
        throw new RuntimeException('This customer already has a pending invitation from your HQ.');
    }

    $hqStmt = $pdo->prepare('SELECT company_name FROM headquarter WHERE headquarter_id = ? LIMIT 1');
    $hqStmt->execute([$hqId]);
    $companyName = (string)($hqStmt->fetchColumn() ?: 'Map & Pump HQ');
    $roleName = $roleId === 4 ? 'Branch Manager' : 'Staff';
    $message = sprintf(
        '%s invited you to join %s as a %s at %s. Accept or decline this invitation in your Map & Pump account before continuing.',
        (string)($_SESSION['user_name'] ?? 'Your HQ administrator'),
        $companyName,
        $roleName,
        $station ? (string)$station['station_name'] : 'no assigned station'
    );

    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
    }
    $insert = $pdo->prepare(
        'INSERT INTO invitations
         (headquarter_id, invited_user_id, invited_email, business_role_id, station_id, status)
         VALUES (?, ?, ?, ?, ?, \'pending\')'
    );
    $insert->execute([$hqId, (int) $invitee['user_id'], $email, $roleId, $stationId ?: null]);
    $invitationId = (int)$pdo->lastInsertId();

    $notification = $pdo->prepare(
        'INSERT INTO notifications
         (recipient_user_id, recipient_email, notification_type, subject, message, delivery_status, delivery_note)
         VALUES (?, ?, \'hq_invitation\', ?, ?, \'pending\', NULL)'
    );
    $subject = 'New Map & Pump HQ invitation';
    $notification->execute([(int)$invitee['user_id'], $email, $subject, $message]);
    $notificationId = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE invitations SET notification_id = ? WHERE invitation_id = ?')->execute([$notificationId, $invitationId]);
    $pdo->commit();

    $setupEmailNotice = null;
    if ($createdAccount) {
        $setupEmailNotice = mail_send_account_notice($email, [
            'kind' => 'account_password_setup',
            'account_label' => 'Branch Manager',
            'account_name' => (string) $invitee['user_name'],
            'setup_url' => $setupUrl,
        ]);
    }
    $emailNotice = mail_send_account_notice($email, [
        'kind' => 'hq_invitation',
        'action' => 'hq_invited',
    ]);
    $deliveryStatus = (string) ($emailNotice['status'] ?? 'failed');
    $deliveryNote = (string) ($emailNotice['note'] ?? 'Email delivery failed.');
    if ($setupEmailNotice !== null) {
        $setupStatus = (string) ($setupEmailNotice['status'] ?? 'failed');
        $setupNote = (string) ($setupEmailNotice['note'] ?? 'Password setup email delivery failed.');
        $deliveryNote .= $setupStatus === 'sent'
            ? ' Password setup email: sent.'
            : ' Password setup email: ' . $setupStatus . ' - ' . $setupNote;
    }
    $pdo->prepare('UPDATE notifications SET delivery_status = ?, delivery_note = ? WHERE notification_id = ?')
        ->execute([$deliveryStatus, mb_substr($deliveryNote, 0, 255), $notificationId]);
    $setupStatusMessage = $setupEmailNotice !== null
        ? ' Password setup email status: ' . (string) ($setupEmailNotice['status'] ?? 'failed') . '.'
        : '';
    $_SESSION['hq_account_message'] = [
        'type' => 'success',
        'text' => ($createdAccount ? 'Branch manager account created. ' : 'Invitation created.')
            . 'The invitee must Accept or Decline before using the assigned role. Invitation email status: '
            . $deliveryStatus . '.' . $setupStatusMessage,
    ];
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['hq_account_message'] = ['type' => 'danger', 'text' => $error->getMessage()];
}

header('Location: account.php');
exit;
