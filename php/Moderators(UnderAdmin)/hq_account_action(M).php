<?php
require_once __DIR__ . '/moderator_common.php';

moderator_require_user();
if (empty($_POST['return_to'])) {
    $_POST['return_to'] = 'view_hq_and_user_account(M).php';
}
require __DIR__ . '/../Admin(WebOwner)/hq_account_action.php';
