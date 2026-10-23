<?php
require_once __DIR__ . '/moderator_common.php';

moderator_require_user();
$_POST['return_to'] = 'view_requests(M).php';
require __DIR__ . '/../Admin(WebOwner)/car_license_review_action.php';