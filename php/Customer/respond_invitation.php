<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../conn.php';

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
         FROM hq_account_invitations
         WHERE invitation_id = ? AND invited_user_id = ? AND status = 'pending'
         FOR UPDATE"
    );
    $stmt->execute([$invitationId, $userId]);
    $invitation = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invitation) {
        throw new RuntimeException('This invitation is no longer pending.');
    }

    if ($decision === 'accept') {
        $userUpdate = $pdo->prepare('UPDATE users SET business_role_id = ?, headquarter_id = ?, branch_id = ?, status = \'active\', updated_at = NOW() WHERE user_id = ?');
        $userUpdate->execute([(int)$invitation['business_role_id'], (int)$invitation['headquarter_id'], (int)$invitation['station_id'], $userId]);
        $scope = $pdo->prepare(
            "INSERT INTO business_account_scope
                (user_id, business_role_id, headquarter_id, branch_manager_user_id, station_id, status)
             VALUES (?, ?, ?, NULL, ?, 'active')
             ON DUPLICATE KEY UPDATE business_role_id = VALUES(business_role_id), headquarter_id = VALUES(headquarter_id), station_id = VALUES(station_id), status = 'active'"
        );
        $scope->execute([$userId, (int)$invitation['business_role_id'], (int)$invitation['headquarter_id'], (int)$invitation['station_id']]);
    }

    $update = $pdo->prepare('UPDATE hq_account_invitations SET status = ?, responded_at = NOW() WHERE invitation_id = ?');
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