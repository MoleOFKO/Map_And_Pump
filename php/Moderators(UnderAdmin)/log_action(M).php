<?php
require_once __DIR__ . '/moderator_common.php';
require_once __DIR__ . '/../Admin(WebOwner)/admin_common.php';

$moderatorUser = moderator_require_user();

// Moderators may view logs on view_logs(M).php only.
// Clear and export are admin-exclusive and must not be proxied from this route.
$action = strtolower(trim((string) ($_POST['action'] ?? $_GET['action'] ?? '')));
if (in_array($action, ['clear', 'export'], true) || $action !== '') {
    moderator_flash('danger', 'Only administrators can clear or export audit logs.');
    moderator_redirect('view_logs(M).php');
}

moderator_redirect('view_logs(M).php');
