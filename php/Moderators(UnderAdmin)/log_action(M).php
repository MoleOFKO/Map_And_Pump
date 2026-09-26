<?php
/**
 * Moderator log action gate.
 * Moderators may only view logs on view_logs(M).php.
 * Clear and export are Admin-exclusive and must never run from this route.
 */
require_once __DIR__ . '/moderator_common.php';

moderator_require_user();

$action = strtolower(trim((string) ($_POST['action'] ?? $_GET['action'] ?? '')));

if ($action === '' || in_array($action, ['clear', 'export', 'delete', 'purge'], true)) {
    moderator_flash(
        'danger',
        $action === ''
            ? 'Log maintenance actions are not available in the Moderator portal.'
            : 'Only administrators can clear or export audit logs.'
    );
    moderator_redirect('view_logs(M).php');
}

// Any other unexpected action is also refused.
moderator_flash('danger', 'That log action is not permitted for Moderator accounts.');
moderator_redirect('view_logs(M).php');
