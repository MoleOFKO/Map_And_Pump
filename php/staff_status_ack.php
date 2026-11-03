<?php
session_start();

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/BranchManager/_helpers.php';

header('Content-Type: application/json; charset=utf-8');

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Please sign in to continue.']);
    exit;
}

$notificationId = (int) ($_POST['notification_id'] ?? $_REQUEST['notification_id'] ?? 0);
$type = strtolower(trim((string) ($_POST['type'] ?? $_REQUEST['type'] ?? '')));

try {
    if ($notificationId > 0) {
        $ok = staff_status_notification_acknowledge($pdo, $userId, $notificationId);
        if (!empty($_SESSION['pending_staff_status_events'])) {
            $_SESSION['pending_staff_status_events'] = array_values(array_filter(
                (array) $_SESSION['pending_staff_status_events'],
                static fn ($event) => !is_array($event) || ((int) ($event['notification_id'] ?? 0) !== $notificationId)
            ));
        }
        unset($_SESSION['staff_status_popup_type']);
        echo json_encode(['ok' => $ok, 'notification_id' => $notificationId]);
        exit;
    }

    $matchingTypes = [];
    if ($type === 'disabled') {
        $matchingTypes[] = 'staff_account_disabled';
    } elseif ($type === 'activated') {
        $matchingTypes[] = 'staff_account_reactivated';
    } elseif ($type === 'unavailable') {
        $matchingTypes = ['staff_account_disabled', 'staff_account_reactivated'];
    }

    $acknowledged = 0;
    foreach (staff_status_pending_for_current_user($pdo, $userId) as $event) {
        $eventType = (string) ($event['notification_type'] ?? '');
        if (!in_array($eventType, $matchingTypes, true)) {
            continue;
        }
        $eventNotificationId = (int) ($event['notification_id'] ?? 0);
        if ($eventNotificationId > 0) {
            if (staff_status_notification_acknowledge($pdo, $userId, $eventNotificationId)) {
                $acknowledged++;
            }
        }
    }

    $_SESSION['pending_staff_status_events'] = array_values(array_filter(
        $_SESSION['pending_staff_status_events'] ?? [],
        static fn ($event) => !is_array($event) || !in_array((string) ($event['notification_type'] ?? ''), $matchingTypes, true)
    ));
    unset($_SESSION['staff_status_popup_type']);

    echo json_encode(['ok' => true, 'acknowledged' => $acknowledged, 'type' => $type]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    error_log('Staff status acknowledgement failed: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Unable to save your acknowledgement.']);
    exit;
}
