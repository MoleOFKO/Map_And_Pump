<?php
require_once __DIR__ . '/../Login/session_helper.php';

map_pump_destroy_login_session();
map_pump_set_remembered_email('', false);

header('Location: ../Login/login.php');
exit;
