<?php
require_once __DIR__ . '/moderator_common.php';

moderator_require_user();
$_POST['return_to'] = 'view_users(M).php';
require __DIR__ . '/../Admin(WebOwner)/user_account_action.php';
