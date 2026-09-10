<?php
require_once __DIR__ . '/hq_guard.php';
require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../business_scope.php';
require_once __DIR__ . '/../invitations_schema.php';
require_once __DIR__ . '/../Login/mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: account.php');
    exit;
}

$email = strtolower(trim((string)($_POST['email'] ?? '')));
$role = strtolower(trim((string)($_POST['role'] ?? '')));
$stationId = filter_var($_POST['station_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$hqId = map_pump_current_hq_id($pdo);
$roleId = $role === 'manager' ? 4 : ($role === 'staff' ? 5 : 0);

if ($hqId <= 0 || !filter_var($email, FILTER_VALIDATE_EMAIL) || $roleId <= 0 || !$stationId) {
    $_SESSION['hq_account_message'] = ['type' => 'danger', 'text' => 'Please provide a valid email, role, and station.'];
    header('Location: account.php');
    exit;
}

try {
    map_pump_ensure_invitation_schema($pdo);

    $stationStmt = $pdo->prepare(
        'SELECT station_name, location FROM stations s
         INNER JOIN station_business_scope sbs ON sbs.station_id = s.station_id
         WHERE s.station_id = ? AND sbs.headquarter_id = ? LIMIT 1'
    );
    $stationStmt->execute([$stationId, $hqId]);
    $station = $stationStmt->fetch(PDO::FETCH_ASSOC);
    if (!$station) {
        throw new RuntimeException('That station is not related to your HQ.');
    }

    $userStmt = $pdo->prepare(
        "SELECT u.user_id, u.user_name
         FROM users u
         LEFT JOIN roles primary_role ON primary_role.role_id = u.role_id
         LEFT JOIN roles active_role ON active_role.role_id = u.current_role_id
         WHERE LOWER(TRIM(u.email)) = LOWER(TRIM(?))
           AND (
               LOWER(TRIM(primary_role.role_name)) = 'customer'
               OR LOWER(TRIM(active_role.role_name)) = 'customer'
           )
         LIMIT 1"
    );
    $userStmt->execute([$email]);
    $invitee = $userStmt->fetch(PDO::FETCH_ASSOC);
    if (!$invitee) {
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
        (string)$station['station_name']
    );

    $pdo->beginTransaction();
    $insert = $pdo->prepare(
        'INSERT INTO invitations
         (headquarter_id, invited_user_id, invited_email, business_role_id, station_id, status)
         VALUES (?, ?, ?, ?, ?, \'pending\')'
    );
    $insert->execute([$hqId, (int)$invitee['user_id'], $email, $roleId, $stationId]);
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

    $deliveryStatus = 'failed';
    $deliveryNote = 'Email delivery failed.';
    try {
        smtp_send_mail($email, $subject, '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>', $message);
        $deliveryStatus = 'sent';
        $deliveryNote = 'Invitation email sent.';
    } catch (Throwable $mailError) {
        error_log('[HQ invitation] Email delivery failed: ' . $mailError->getMessage());
    }
    $pdo->prepare('UPDATE notifications SET delivery_status = ?, delivery_note = ? WHERE notification_id = ?')->execute([$deliveryStatus, $deliveryNote, $notificationId]);
    $_SESSION['hq_account_message'] = ['type' => 'success', 'text' => 'Invitation created. The customer must Accept or Decline it before using the account. Email status: ' . $deliveryStatus . '.'];
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['hq_account_message'] = ['type' => 'danger', 'text' => $error->getMessage()];
}

header('Location: account.php');
exit;