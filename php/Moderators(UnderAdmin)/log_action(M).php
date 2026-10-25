<?php
/**
 * Moderator log action gate.
 *
 * Moderators may only read logs on view_logs(M).php.
 * Clear, export, delete, purge, and any other maintenance action
 * are Admin-exclusive and must never run from this route.
 */
require_once __DIR__ . '/moderator_common.php';

moderator_require_user();

$action = strtolower(trim((string) ($_POST['action'] ?? $_GET['action'] ?? '')));

$blockedActions = [
    'clear',
    'export',
    'delete',
    'purge',
    'remove',
    'truncate',
    'wipe',
    'edit',
    'update',
];

if ($action === '' || in_array($action, $blockedActions, true)) {
    moderator_flash(
        'danger',
        $action === ''
            ? 'Log maintenance actions are not available in the Moderator portal. Logs are view-only.'
            : 'Only administrators can clear, export, or change audit logs. Moderator access is view-only.'
    );
    moderator_redirect('view_logs(M).php');
}

// Any other unexpected action is also refused.
moderator_flash('danger', 'That log action is not permitted for Moderator accounts. Logs are view-only.');
moderator_redirect('view_logs(M).php');
