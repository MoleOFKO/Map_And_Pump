<?php
require_once __DIR__ . '/moderator_common.php';

$moderatorUser = moderator_require_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    request_redirect('view_requests(M).php');
}

$userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = strtolower(trim((string) ($_POST['action'] ?? '')));
$reason = trim((string) ($_POST['reason'] ?? ''));

if (!$userId || !in_array($action, ['approve', 'reject'], true)) {
    request_flash('danger', 'The account review action was invalid.');
    request_redirect('view_requests(M).php');
}

if ($action === 'reject' && $reason === '') {
    request_flash('danger', 'A rejection reason is required.');
    request_redirect('view_requests(M).php');
}

if (mb_strlen($reason) > 2000) {
    request_flash('danger', 'The rejection reason must be 2,000 characters or fewer.');
    request_redirect('view_requests(M).php');
}

if (!hq_db_available() || !hq_registry_schema_ready()) {
    request_flash('danger', hq_db_error_message());
    request_redirect('view_requests(M).php');
}

global $conn;

try {
    $conn->begin_transaction();

    $targetQuery = $conn->prepare(
        'SELECT u.user_id, u.user_name, u.email, u.status, r.role_name
         FROM users u
         INNER JOIN roles r ON r.role_id = u.role_id
         WHERE u.user_id = ?
         FOR UPDATE'
    );
    if (!$targetQuery) {
        throw new RuntimeException('Could not prepare the account lookup.');
    }
    $targetQuery->bind_param('i', $userId);
    $targetQuery->execute();
    $target = $targetQuery->get_result()?->fetch_assoc();
    $targetQuery->close();

    if (!$target) {
        throw new RuntimeException('That account could not be found.');
    }
    if (!in_array(strtolower(trim((string) $target['status'])), ['pending', 'pending_verification'], true)) {
        throw new RuntimeException('Only pending verification accounts can be approved or rejected.');
    }

    $newStatus = $action === 'approve' ? 'approved' : 'rejected';
    $update = $conn->prepare('UPDATE users SET status = ?, updated_at = NOW() WHERE user_id = ?');
    if (!$update) {
        throw new RuntimeException('Could not prepare the account status update.');
    }
    $update->bind_param('si', $newStatus, $userId);
    if (!$update->execute()) {
        throw new RuntimeException('Could not update the account status.');
    }
    $update->close();

    $logVerb = $action === 'approve' ? 'Approved' : 'Rejected';
    admin_record_log((int) $moderatorUser['user_id'], $logVerb . ' pending account #' . $userId . ' (' . $target['user_name'] . ').');
    $conn->commit();

    request_flash('success', 'Account ACCT-' . $userId . ' was ' . $newStatus . '.');
} catch (Throwable $exception) {
    $conn->rollback();
    error_log('[Moderator account review] Review failed: ' . $exception->getMessage());
    request_flash('danger', 'The account could not be reviewed. No partial changes were saved.');
}

request_redirect('view_requests(M).php');
