<?php
require_once __DIR__ . '/moderator_common.php';

moderator_require_user();

http_response_code(403);
header('Content-Type: text/plain; charset=UTF-8');
exit('User account details cannot be edited from this page.');
