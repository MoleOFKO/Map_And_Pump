<?php
require_once __DIR__ . '/moderator_common.php';

moderator_require_user();
$_POST['return_to'] = 'manage_hq(M).php';
require __DIR__ . '/../Admin(WebOwner)/hq_register_action.php';
