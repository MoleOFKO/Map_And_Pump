<?php
require_once __DIR__ . '/admin_common.php';

admin_require_admin();

http_response_code(403);
header('Content-Type: text/plain; charset=UTF-8');
exit('Driver account details cannot be edited from this page.');
