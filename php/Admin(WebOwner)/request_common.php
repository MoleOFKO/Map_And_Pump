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
function request_is_hq_registration(?string $type): bool
{
    return in_array(strtolower(trim((string) $type)), ['hq_registration', 'headquarter_registration'], true);
}

function request_column_exists(string $column): bool
{
    global $conn;

    $statement = $conn->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'system_requests\' AND COLUMN_NAME = ? LIMIT 1'
    );
    if (!$statement) {
        return false;
    }
    $statement->bind_param('s', $column);
    $statement->execute();
    $exists = (bool) $statement->get_result()?->fetch_row();
    $statement->close();

    return $exists;
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
            headquarter_id INT UNSIGNED NULL,
            sender_entity VARCHAR(255) NOT NULL,
            sender_name VARCHAR(255) NULL,
            sender_email VARCHAR(255) NULL,
            location VARCHAR(255) NULL,
            request_details TEXT NULL,
            document_name VARCHAR(255) NULL,
            document_path VARCHAR(500) NULL,
            headquarter_owner_email VARCHAR(255) NULL,
            headquarter_email VARCHAR(255) NULL,
            headquarter_company_logo VARCHAR(255) NULL,
            headquarter_contact_number VARCHAR(50) NULL,
            headquarter_expire_at DATETIME NULL,
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

    $requiredColumns = [
        'role_id' => 'INT UNSIGNED NULL',
        'headquarter_id' => 'INT UNSIGNED NULL',
        'headquarter_owner_email' => 'VARCHAR(255) NULL',
        'headquarter_email' => 'VARCHAR(255) NULL',
        'headquarter_company_logo' => 'VARCHAR(255) NULL',
        'headquarter_contact_number' => 'VARCHAR(50) NULL',
        'headquarter_expire_at' => 'DATETIME NULL',
    ];
    foreach ($requiredColumns as $column => $definition) {
        if (!request_column_exists($column) && !$conn->query("ALTER TABLE system_requests ADD COLUMN $column $definition")) {
            error_log('[Request registry] Schema migration failed for ' . $column . ': ' . $conn->error);
            return false;
        }
    }

    $indexCheck = $conn->query("SHOW INDEX FROM system_requests WHERE Key_name = 'idx_system_requests_headquarter'");
    if ((!$indexCheck || $indexCheck->num_rows === 0) && !$conn->query('ALTER TABLE system_requests ADD KEY idx_system_requests_headquarter (headquarter_id)')) {
        error_log('[Request registry] HQ request index migration failed: ' . $conn->error);
        return false;
    }

    // Legacy rows were created before headquarter_id was replaced by role_id in
    // the live database. Preserve their existing HQ link when one is present.
    $conn->query(
        "UPDATE system_requests r
         INNER JOIN headquarter h ON h.headquarter_id = r.role_id
         SET r.headquarter_id = h.headquarter_id
         WHERE r.headquarter_id IS NULL
           AND LOWER(r.request_type) IN ('hq_registration', 'headquarter_registration')"
    );

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

    if (!hq_db_available() || !request_schema_ready()) {
        return false;
    }

    $sql = <<<'SQL'
INSERT INTO system_requests
    (reference_code, request_type, status, submitter_user_id, role_id, headquarter_id,
     sender_entity, sender_name, sender_email, location, request_details,
     document_name, document_path, headquarter_owner_email, headquarter_email,
     headquarter_company_logo, headquarter_contact_number, headquarter_expire_at,
     created_at, updated_at)
SELECT
    CONCAT('HQ-REG-', h.headquarter_id),
    'hq_registration',
    'pending',
    u.user_id,
    3,
    h.headquarter_id,
    COALESCE(h.company_name, 'Unnamed HQ'),
    u.user_name,
    u.email,
    h.location,
    CONCAT(
        'Legacy HQ account registered before the approval-only workflow. ',
        'Contact: ', COALESCE(h.contact_number, 'Not provided'),
        '. License expiry: ', DATE_FORMAT(h.expire_at, '%Y-%m-%d'), '.'
    ),
    'Business license',
    h.business_license,
    u.email,
    h.headquarter_email,
    h.company_logo,
    h.contact_number,
    h.expire_at,
    NOW(),
    NOW()
FROM headquarter h
JOIN (
    SELECT u1.user_id, u1.headquarter_id, u1.user_name, u1.email, u1.status
    FROM users u1
    JOIN (
        SELECT headquarter_id, MIN(user_id) AS owner_user_id
        FROM users
        WHERE role_id = 3
        GROUP BY headquarter_id
    ) first_user ON first_user.owner_user_id = u1.user_id
) u ON u.headquarter_id = h.headquarter_id
LEFT JOIN (
    SELECT DISTINCT headquarter_id
    FROM system_requests
    WHERE request_type IN ('hq_registration', 'headquarter_registration')
      AND headquarter_id IS NOT NULL
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
