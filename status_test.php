<?php
require __DIR__ . '/php/conn.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$manager = $pdo->query("SELECT u.user_id, u.user_name, u.email, b.branch_id FROM users u JOIN branches b ON b.manager_user_id = u.user_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$manager) { echo "NO_MANAGER\n"; exit(0); }
$staff = $pdo->query("SELECT s.staff_id, s.user_id, s.full_name, s.branch_id, s.is_active FROM staff s WHERE s.branch_id = {$manager['branch_id']} ORDER BY s.staff_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$staff) { echo "NO_STAFF\n"; exit(0); }
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$_SESSION['is_logged_in'] = 1;
$_SESSION['user_id'] = (int)$manager['user_id'];
$_SESSION['user_name'] = $manager['user_name'];
$_SESSION['role_id'] = 4;
$_SESSION['role_name'] = 'BranchManager';
$_SESSION['current_role_id'] = 4;
$_SESSION['csrf'] = 'test-csrf';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['csrf' => $_SESSION['csrf'], 'action' => 'disable', 'staff_id' => (string)$staff['staff_id']];
ob_start();
require __DIR__ . '/php/BranchManager/manage-staff.php';
$output = ob_get_clean();
$rows = $pdo->query("SELECT notification_id, recipient_user_id, request_id, notification_type, subject, message, delivery_status FROM notifications WHERE recipient_user_id = {$staff['user_id']} AND request_id = {$staff['staff_id']} AND notification_type = 'staff_account_disabled' ORDER BY notification_id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
var_export(['manager' => $manager, 'staff' => $staff, 'output' => $output, 'rows' => $rows]);
