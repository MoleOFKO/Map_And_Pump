<?php
require_once __DIR__ . '/moderator_common.php';

moderator_require_user();
$_POST['return_to'] = 'view_report(M).php';
$_GET['return_to'] = 'view_report(M).php';
require __DIR__ . '/../Admin(WebOwner)/report_action.php';
