<?php
/**
 * Shared helpers for the admin request review pipeline.
 */
require_once __DIR__ . '/hq_account_common.php';
require_once __DIR__ . '/../Login/mailer.php';

function request_type_label(?string $type): string
{
    return match (strtolower(trim((string) $type))) {
        'station', 'new_station', 'new_station_registration' => 'New Station Registration',
        'license', 'license_modification' => 'License Modification',
        'deactivation', 'account_deactivation' => 'Account Deactivation',
        default => $type !== null && trim($type) !== '' ? ucwords(str_replace('_', ' ', $type)) : 'System Request',
    };
}

function request_type_short_label(?string $type): string
{
    return match (strtolower(trim((string) $type))) {
        'station', 'new_station', 'new_station_registration' => 'New Station Reg',
        'license', 'license_modification' => 'License Mod',
        'deactivation', 'account_deactivation' => 'Account Deactivation',
        default => request_type_label($type),
    };
}

function request_status_label(?string $status): string
{
    return match (strtolower(trim((string) $status))) {
        'pending' => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'flagged' => 'Flagged',
        default => $status !== null && trim($status) !== '' ? ucwords(str_replace('_', ' ', $status)) : 'Pending',
    };
}

function request_status_class(?string $status): string
{
    return match (strtolower(trim((string) $status))) {
        'approved' => 'request-status-approved',
        'rejected' => 'request-status-rejected',
        'flagged' => 'request-status-flagged',
        default => 'request-status-pending',
    };
}

function request_flash(string $type, string $message): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $_SESSION['request_registry_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function request_pull_flash(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $flash = $_SESSION['request_registry_flash'] ?? null;
    unset($_SESSION['request_registry_flash']);

    return is_array($flash) ? $flash : null;
}

function request_redirect(string $location = 'view_requests.php'): never
{
    $returnTo = trim((string) ($_POST['return_to'] ?? $_GET['return_to'] ?? ''));
    $allowedReturnTargets = [
        'moderator_dashboard.php',
        'manage_hq(M).php',
        'manage_users(M).php',
        'view_hq_and_user_account(M).php',
        'view_users(M).php',
        'view_users.php',
        'view_requests.php',
        'view_requests(M).php',
        'view_report(M).php',
        'edit_profile(M).php',
        'view_logs(M).php',
    ];
    if (in_array($returnTo, $allowedReturnTargets, true)) {
        $location = $returnTo;
    }
    header('Location: ' . $location);
    exit;
}

function request_schema_ready(): bool
{
    global $conn;

    if (!hq_registry_schema_ready()) {
        return false;
    }

    $queries = [
        "CREATE TABLE IF NOT EXISTS system_requests (
            request_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            reference_code VARCHAR(40) NOT NULL,
            request_type VARCHAR(60) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            submitter_user_id INT UNSIGNED NULL,
            role_id INT UNSIGNED NULL,
            sender_entity VARCHAR(255) NOT NULL,
            sender_name VARCHAR(255) NULL,
            sender_email VARCHAR(255) NULL,
            location VARCHAR(255) NULL,
            request_details TEXT NULL,
            document_name VARCHAR(255) NULL,
            document_path VARCHAR(500) NULL,
            review_reason TEXT NULL,
            reviewed_by INT UNSIGNED NULL,
            reviewed_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (request_id),
            UNIQUE KEY uq_system_requests_reference (reference_code),
            KEY idx_system_requests_status (status),
            KEY idx_system_requests_type (request_type),
            KEY idx_system_requests_created (created_at),
            KEY idx_system_requests_user (submitter_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS notifications (
            notification_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_id INT UNSIGNED NULL,
            recipient_user_id INT UNSIGNED NULL,
            recipient_email VARCHAR(255) NULL,
            notification_type VARCHAR(40) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            delivery_status VARCHAR(30) NOT NULL DEFAULT 'not_configured',
            delivery_note VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (notification_id),
            KEY idx_notifications_request (request_id),
            KEY idx_notifications_recipient (recipient_user_id),
            KEY idx_notifications_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    foreach ($queries as $query) {
        if (!$conn->query($query)) {
            error_log('[Request registry] Schema bootstrap failed: ' . $conn->error);
            return false;
        }
    }

    return true;
}

function request_send_notification(string $recipient, string $subject, string $message): array
{
    $recipient = trim($recipient);

    if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        return [
            'status' => 'skipped',
            'note' => 'No valid requester email address was available.',
        ];
    }

    $safeSubject = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
    $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
    $html = '<div style="font-family:Arial,sans-serif;line-height:1.5;color:#111827">'
        . '<h2>' . $safeSubject . '</h2>'
        . '<p>' . $safeMessage . '</p>'
        . '<p style="color:#6b7280;font-size:13px">Map & Pump request registry</p>'
        . '</div>';

    try {
        smtp_send_mail($recipient, $subject, $html, $message);

        return [
            'status' => 'sent',
            'note' => 'Email sent to requester.',
        ];
    } catch (Throwable $e) {
        $note = 'Email delivery failed: ' . $e->getMessage();
        error_log('[Request registry] ' . $note);

        return [
            'status' => 'failed',
            'note' => substr($note, 0, 255),
        ];
    }
}
function request_date(?string $value): string
{
    if (!$value) {
        return 'Not available';
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('M j, Y · g:i A', $timestamp) : $value;
}

