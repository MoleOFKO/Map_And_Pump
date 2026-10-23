<?php
/**
 * Shared helpers for the admin request review pipeline.
 */
require_once __DIR__ . '/hq_account_common.php';
require_once __DIR__ . '/../Login/mail_templates.php';
require_once __DIR__ . '/../payment_transfers.php';

/** Latest registration payment state keyed by system request id. */
function request_payment_review_map(array $requestRows): array
{
    global $pdo;
    $requestIds = [];
    foreach ($requestRows as $row) {
        if (strtolower((string) ($row['request_type'] ?? '')) === 'hq_registration') {
            $requestIds[] = (int) ($row['request_id'] ?? 0);
        }
    }
    $requestIds = array_values(array_filter(array_unique($requestIds), static fn (int $id): bool => $id > 0));
    if (!$requestIds || !isset($pdo) || !payment_transfer_schema_ready($pdo)) {
        return [];
    }

    try {
        $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT payment_transfer_id, request_id, status, amount_mmk
             FROM payment_transfers
             WHERE payment_type = 'hq_registration' AND request_id IN ({$placeholders})
             ORDER BY payment_transfer_id DESC"
        );
        $stmt->execute($requestIds);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $payment) {
            $requestId = (int) $payment['request_id'];
            $map[$requestId] ??= $payment;
        }
        return $map;
    } catch (Throwable $e) {
        error_log('request_payment_review_map: ' . $e->getMessage());
        return [];
    }
}

/** Actionable payment declarations that have no matching HQ registration row. */
function request_standalone_payment_rows(bool $moderatorOnly = false): array
{
    global $pdo;
    if (!isset($pdo) || !payment_transfer_schema_ready($pdo)) {
        return [];
    }
    try {
        $paymentTypes = $moderatorOnly
            ? ['hq_period_extension']
            : ['hq_period_extension', 'hq_extra_station', 'hq_station_operating_quote'];
        $placeholders = implode(',', array_fill(0, count($paymentTypes), '?'));
        $stmt = $pdo->prepare(
            "SELECT pt.payment_transfer_id, pt.request_id, pt.payment_type, pt.status,
                    pt.amount_mmk, pt.description, pt.payment_method, pt.payer_user_id,
                    pt.payer_email, pt.created_at, pt.payer_headquarter_id,
                    h.company_name AS payer_hq_name, owner.user_name AS payer_name,
                    (SELECT receipt.file_name
                     FROM payment_transfer_receipts receipt
                     WHERE receipt.payment_transfer_id = pt.payment_transfer_id
                     LIMIT 1) AS receipt_name
             FROM payment_transfers pt
             LEFT JOIN headquarter h ON h.headquarter_id = pt.payer_headquarter_id
             LEFT JOIN users owner ON owner.user_id = h.headquarters_owner_id
             WHERE pt.recipient_role = 'admin'
               AND pt.status = 'payer_reported_paid'
               AND pt.payment_type IN ({$placeholders})
             ORDER BY pt.created_at DESC, pt.payment_transfer_id DESC"
        );
        $stmt->execute($paymentTypes);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('request_standalone_payment_rows: ' . $e->getMessage());
        return [];
    }
}

function request_type_label(?string $type): string
{
    return match (strtolower(trim((string) $type))) {
        'hq_registration' => 'HQ Registration',
        'station', 'new_station', 'new_station_registration' => 'New Station Registration',
        'license', 'license_modification' => 'License Modification',
        'deactivation', 'account_deactivation' => 'Account Deactivation',
        default => $type !== null && trim($type) !== '' ? ucwords(str_replace('_', ' ', $type)) : 'System Request',
    };
}

function request_type_short_label(?string $type): string
{
    return match (strtolower(trim((string) $type))) {
        'hq_registration' => 'HQ Registration',
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

    // Schema changes belong in db.sql/migrations, never in a page request.
    $result = $conn->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME IN ('system_requests', 'notifications')"
    );
    if (!$result) {
        error_log('[Request registry] Schema check failed: ' . $conn->error);
        return false;
    }

    $tables = [];
    while ($row = $result->fetch_assoc()) {
        $tables[$row['TABLE_NAME']] = true;
    }
    return isset($tables['system_requests'], $tables['notifications']);
}

/**
 * Current request status totals, including separately recorded account reviews.
 *
 * @return array{pending:int,approved:int,approved_today:int,declined_flagged:int,error:?string}
 */
function request_live_status_summary(): array
{
    global $conn;

    $summary = [
        'pending' => 0,
        'approved' => 0,
        'approved_today' => 0,
        'declined_flagged' => 0,
        'error' => null,
    ];

    if (!hq_db_available() || !request_schema_ready()) {
        $summary['error'] = 'The request database is temporarily unavailable.';
        return $summary;
    }

    $requestQuery = $conn->query(
        "SELECT
            COALESCE(SUM(CASE WHEN LOWER(TRIM(status)) = 'pending' THEN 1 ELSE 0 END), 0) AS pending,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(status)) = 'approved' THEN 1 ELSE 0 END), 0) AS approved,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(status)) = 'approved' AND DATE(reviewed_at) = CURDATE() THEN 1 ELSE 0 END), 0) AS approved_today,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(status)) IN ('rejected', 'flagged') THEN 1 ELSE 0 END), 0) AS declined_flagged
         FROM system_requests"
    );
    if (!$requestQuery) {
        error_log('[Request registry] Live status summary query failed: ' . $conn->error);
        $summary['error'] = 'Request status totals could not be loaded.';
        return $summary;
    }

    $requestCounts = $requestQuery->fetch_assoc() ?: [];
    foreach (['pending', 'approved', 'approved_today', 'declined_flagged'] as $key) {
        $summary[$key] = (int) ($requestCounts[$key] ?? 0);
    }

    $accountReviewQuery = $conn->query(
        "SELECT
            COALESCE(SUM(CASE WHEN LOWER(TRIM(notification_type)) = 'account_approved' THEN 1 ELSE 0 END), 0) AS approved,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(notification_type)) = 'account_approved' AND DATE(created_at) = CURDATE() THEN 1 ELSE 0 END), 0) AS approved_today,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(notification_type)) = 'account_rejected' THEN 1 ELSE 0 END), 0) AS rejected
         FROM notifications
         WHERE LOWER(TRIM(notification_type)) IN ('account_approved', 'account_rejected')"
    );
    if (!$accountReviewQuery) {
        error_log('[Request registry] Account review totals query failed: ' . $conn->error);
        $summary['error'] = 'Account review totals could not be loaded.';
        return $summary;
    }

    $accountReviewCounts = $accountReviewQuery->fetch_assoc() ?: [];
    $summary['approved'] += (int) ($accountReviewCounts['approved'] ?? 0);
    $summary['approved_today'] += (int) ($accountReviewCounts['approved_today'] ?? 0);
    $summary['declined_flagged'] += (int) ($accountReviewCounts['rejected'] ?? 0);

    return $summary;
}

/**
 * @param array<string,mixed>|null $context  Optional structured template context
 * @return array{status:string,note:string,sent?:bool,subject?:string}
 */
function request_send_notification(string $recipient, string $subject = '', string $message = '', ?array $context = null): array
{
    if (is_array($context) && $context !== []) {
        $result = mail_send_account_notice($recipient, $context);
        return [
            'sent' => (bool) ($result['sent'] ?? false),
            'status' => (string) ($result['status'] ?? 'failed'),
            'note' => (string) ($result['note'] ?? ''),
            'subject' => (string) ($result['subject'] ?? ''),
        ];
    }

    $result = mail_send_account_notice($recipient, [
        'kind' => 'request',
        'action' => 'updated',
        'effect' => 'A request associated with this email address was updated in Map & Pump.',
    ]);

    return [
        'sent' => (bool) ($result['sent'] ?? false),
        'status' => (string) ($result['status'] ?? 'failed'),
        'note' => (string) ($result['note'] ?? ''),
        'subject' => (string) ($result['subject'] ?? ''),
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
