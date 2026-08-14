<?php
/**
 * Shared helpers for the admin request review pipeline.
 */
require_once __DIR__ . '/hq_account_common.php';

function request_type_label(?string $type): string
{
    return match (strtolower(trim((string) $type))) {
        'station', 'new_station', 'new_station_registration' => 'New Station Registration',
        'license', 'license_modification' => 'License Modification',
        'deactivation', 'account_deactivation' => 'Account Deactivation',
        'hq_registration', 'headquarter_registration' => 'HQ Registration',
        default => $type !== null && trim($type) !== '' ? ucwords(str_replace('_', ' ', $type)) : 'System Request',
    };
}

function request_type_short_label(?string $type): string
{
    return match (strtolower(trim((string) $type))) {
        'station', 'new_station', 'new_station_registration' => 'New Station Reg',
        'license', 'license_modification' => 'License Mod',
        'deactivation', 'account_deactivation' => 'Account Deactivation',
        'hq_registration', 'headquarter_registration' => 'HQ Registration',
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

/**
 * The request tables are bootstrapped because the repository has an ER
 * diagram but no executable migration for the request-review workflow.
 */
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
            headquarter_id INT UNSIGNED NULL,
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
            KEY idx_system_requests_user (submitter_user_id),
            KEY idx_system_requests_hq (headquarter_id)
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

/**
 * Older HQ registrations created a pending user but did not create the
 * matching request row. Backfill those records before the request list is
 * loaded, without duplicating an existing HQ registration request.
 */
function request_backfill_pending_hq_registrations(): bool
{
    global $conn;

    if (!hq_db_available()) {
        return false;
    }

    $sql = <<<'SQL'
INSERT INTO system_requests
    (reference_code, request_type, status, submitter_user_id, headquarter_id,
     sender_entity, sender_name, sender_email, location, request_details,
     document_name, document_path, created_at, updated_at)
SELECT
    CONCAT('HQ-REG-', h.headquarter_id),
    'hq_registration',
    'pending',
    u.user_id,
    h.headquarter_id,
    COALESCE(h.company_name, 'Unnamed HQ'),
    u.user_name,
    u.email,
    h.location,
    CONCAT(
        'HQ account registered through Register New Headquarter. ',
        'Contact: ', COALESCE(h.contact_number, 'Not provided'),
        '. License expiry: ', DATE_FORMAT(h.expire_at, '%Y-%m-%d'), '.'
    ),
    'Business license',
    h.business_license,
    NOW(),
    NOW()
FROM headquarter h
JOIN (
    SELECT u1.user_id, u1.headquarter_id, u1.user_name, u1.email, u1.status
    FROM users u1
    JOIN (
        SELECT headquarter_id, MIN(user_id) AS owner_user_id
        FROM users
        GROUP BY headquarter_id
    ) first_user ON first_user.owner_user_id = u1.user_id
) u ON u.headquarter_id = h.headquarter_id
LEFT JOIN (
    SELECT DISTINCT headquarter_id
    FROM system_requests
    WHERE request_type IN ('hq_registration', 'headquarter_registration')
) existing_request ON existing_request.headquarter_id = h.headquarter_id
WHERE LOWER(u.status) IN ('pending', 'pending_verification')
  AND existing_request.headquarter_id IS NULL
SQL;

    if (!$conn->query($sql)) {
        error_log('[Request registry] HQ registration backfill failed: ' . $conn->error);
        return false;
    }

    return true;
}

/**
 * Placeholder notification sender. It records the notification in the DB
 * and logs the intended delivery until SMTP/provider credentials are added.
 */
function request_send_notification(string $recipient, string $subject, string $message): array
{
    error_log(sprintf('[Request registry] Email placeholder for %s: %s', $recipient, $subject));

    return [
        'status' => 'not_configured',
        'note' => 'Email delivery is not configured; the notification was recorded for later delivery.',
    ];
}

function request_date(?string $value): string
{
    if (!$value) {
        return 'Not available';
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('M j, Y · g:i A', $timestamp) : $value;
}
