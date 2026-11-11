<?php
/**
 * Shared helpers for the admin request review pipeline.
 */
require_once __DIR__ . '/hq_account_common.php';
require_once __DIR__ . '/../Login/mail_templates.php';
require_once __DIR__ . '/../payment_transfers.php';

/**
 * Preset rejection reasons for request / account review.
 * Key "other" requires free-text details.
 *
 * @return array<string, string>
 */
function request_rejection_reason_options(): array
{
    // Intentionally omit duplicate-license / duplicate-company reasons.
    // Those cases must use the existing conflict UI (Manage this account / pair decisions),
    // which updates licenses, related accounts, and HQ status correctly.
    return [
        'incomplete_documents' => 'Documents incomplete, unclear, or unreadable',
        'identity_mismatch' => 'Identity or license details do not match the submission',
        'expired_invalid_license' => 'License is expired or invalid',
        'missing_required_info' => 'Required information is missing',
        'payment_not_received' => 'Payment not received or could not be verified',
        'payment_amount_mismatch' => 'Payment amount does not match the requested package',
        'other' => 'Other (please specify)',
    ];
}

/**
 * Build the stored rejection reason from POST fields.
 * Expects reject_reason_code (+ optional reason / reject_reason_details free text).
 *
 * @return array{ok:bool, reason:string, error?:string}
 */
function request_resolve_rejection_reason(array $post): array
{
    $options = request_rejection_reason_options();
    $code = strtolower(trim((string) ($post['reject_reason_code'] ?? '')));
    $details = trim((string) ($post['reason'] ?? $post['reject_reason_details'] ?? ''));

    // Backward compatibility: older forms only posted free-text "reason".
    if ($code === '' && $details !== '') {
        if (mb_strlen($details) > 2000) {
            return ['ok' => false, 'reason' => '', 'error' => 'The rejection reason must be 2,000 characters or fewer.'];
        }
        return ['ok' => true, 'reason' => $details];
    }

    if ($code === '' || !isset($options[$code])) {
        return ['ok' => false, 'reason' => '', 'error' => 'Select a rejection reason from the list.'];
    }

    if ($code === 'other') {
        if ($details === '') {
            return ['ok' => false, 'reason' => '', 'error' => 'Please specify the other rejection reason.'];
        }
        if (mb_strlen($details) > 2000) {
            return ['ok' => false, 'reason' => '', 'error' => 'The rejection reason must be 2,000 characters or fewer.'];
        }
        return ['ok' => true, 'reason' => 'Other: ' . $details];
    }

    $label = $options[$code];
    if ($details !== '') {
        if (mb_strlen($details) > 1800) {
            return ['ok' => false, 'reason' => '', 'error' => 'Additional details must be 1,800 characters or fewer.'];
        }
        return ['ok' => true, 'reason' => $label . ' — ' . $details];
    }

    return ['ok' => true, 'reason' => $label];
}

function request_registration_payment_required(array $details): bool
{
    if (array_key_exists('registration_amount_mmk', $details)) {
        $amount = filter_var($details['registration_amount_mmk'], FILTER_VALIDATE_FLOAT);
        return $amount === false || $amount > 0;
    }

    $requestedStations = (int) ($details['requested_station_count'] ?? BILLING_HQ_INCLUDED_STATION_SLOTS);
    return $requestedStations > BILLING_HQ_INCLUDED_STATION_SLOTS;
}

/** Latest registration payment state keyed by system request id. */
function request_payment_review_map(array $requestRows): array
{
    global $pdo;
    $requestIds = [];
    $requestHeadquarters = [];
    foreach ($requestRows as $row) {
        if (strtolower((string) ($row['request_type'] ?? '')) === 'hq_registration') {
            $requestId = (int) ($row['request_id'] ?? 0);
            if ($requestId > 0) {
                $requestIds[] = $requestId;
                $requestHeadquarters[$requestId] = (int) ($row['headquarter_id'] ?? 0);
            }
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

        // Older approved HQ registrations can have a payment transfer linked
        // to the created HQ but missing its original request_id. Use the HQ
        // foreign key only as a fallback, and only for otherwise-unmatched
        // registrations, so one HQ's payment cannot mask another request.
        $unmatchedRequestIds = array_values(array_filter(
            $requestIds,
            static fn (int $id): bool => !isset($map[$id]) && ($requestHeadquarters[$id] ?? 0) > 0
        ));
        if ($unmatchedRequestIds) {
            $fallbackMarks = implode(',', array_fill(0, count($unmatchedRequestIds), '?'));
            $fallback = $pdo->prepare(
                "SELECT r.request_id, pt.payment_transfer_id, pt.status, pt.amount_mmk
                 FROM system_requests r
                 INNER JOIN payment_transfers pt
                    ON pt.payment_type = 'hq_registration'
                   AND pt.payer_headquarter_id = r.headquarter_id
                   AND (pt.request_id IS NULL OR pt.request_id = 0)
                 WHERE r.request_id IN ({$fallbackMarks})
                 ORDER BY pt.payment_transfer_id DESC"
            );
            $fallback->execute($unmatchedRequestIds);
            foreach ($fallback->fetchAll(PDO::FETCH_ASSOC) as $payment) {
                $requestId = (int) $payment['request_id'];
                $map[$requestId] ??= $payment;
            }
        }
        return $map;
    } catch (Throwable $e) {
        error_log('request_payment_review_map: ' . $e->getMessage());
        return [];
    }
}

function request_type_label(?string $type): string
{
    return match (strtolower(trim((string) $type))) {
        'hq_registration' => 'Company Registration',
        'station', 'new_station', 'new_station_registration' => 'New Station Registration',
        'license', 'license_modification' => 'License Modification',
        'deactivation', 'account_deactivation' => 'Account Deactivation',
        default => $type !== null && trim($type) !== '' ? ucwords(str_replace('_', ' ', $type)) : 'System Request',
    };
}

function request_type_short_label(?string $type): string
{
    return match (strtolower(trim((string) $type))) {
        'hq_registration' => 'Company Registration',
        'station', 'new_station', 'new_station_registration' => 'New Station Reg',
        'license', 'license_modification' => 'License Mod',
        'deactivation', 'account_deactivation' => 'Account Deactivation',
        default => request_type_label($type),
    };
}

function request_business_license_match(string $licenseNumber, int $excludeHeadquarterId = 0): ?array
{
    global $conn;
    $normalizedNumber = strtoupper(preg_replace('/\s+/', '', trim($licenseNumber)) ?? '');
    if ($normalizedNumber === '' || !hq_db_available()) {
        return null;
    }

    $excludeSql = $excludeHeadquarterId > 0 ? 'AND h.headquarter_id <> ?' : '';
    $statement = $conn->prepare(
        "SELECT h.headquarter_id, h.company_name, h.headquarter_email, h.contact_number,
                h.location, h.business_license, h.status AS headquarter_status,
                COALESCE(h.headquarters_owner_id, owner_link.user_id) AS owner_user_id,
                owner.user_name AS owner_name, owner.email AS owner_email, owner.status AS owner_status
         FROM headquarter h
         LEFT JOIN (
             SELECT bas.headquarter_id, MIN(u.user_id) AS user_id
             FROM business_account_scope bas
             INNER JOIN users u ON u.user_id = bas.user_id
             GROUP BY bas.headquarter_id
         ) owner_link ON owner_link.headquarter_id = h.headquarter_id
         LEFT JOIN users owner ON owner.user_id = COALESCE(h.headquarters_owner_id, owner_link.user_id)
         WHERE UPPER(REPLACE(TRIM(h.business_license), ' ', '')) = ?
           {$excludeSql}
           AND LOWER(TRIM(COALESCE(h.status, ''))) NOT IN ('banned', 'disabled', 'suspended', 'removed', 'rejected', 'deleted')
           AND LOWER(TRIM(COALESCE(owner.status, ''))) NOT IN ('banned', 'disabled', 'suspended', 'removed', 'deleted')
         ORDER BY h.created_at ASC, h.headquarter_id ASC
         LIMIT 1"
    );
    if (!$statement) {
        return null;
    }

    if ($excludeHeadquarterId > 0) {
        $statement->bind_param('si', $normalizedNumber, $excludeHeadquarterId);
    } else {
        $statement->bind_param('s', $normalizedNumber);
    }
    if (!$statement->execute()) {
        $statement->close();
        return null;
    }
    $match = $statement->get_result()?->fetch_assoc() ?: null;
    $statement->close();
    if (!$match) {
        return null;
    }

    $match['headquarter_id'] = (int) $match['headquarter_id'];
    $match['owner_user_id'] = $match['owner_user_id'] === null ? null : (int) $match['owner_user_id'];
    return $match;
}

function request_business_license_match_for_request(array $request): ?array
{
    $requestType = strtolower(trim((string) ($request['request_type'] ?? '')));
    if (!in_array($requestType, ['hq_registration', 'license_modification', 'license'], true)) {
        return null;
    }

    $details = json_decode((string) ($request['request_details'] ?? ''), true);
    $details = is_array($details) ? $details : [];
    $licenseNumber = $requestType === 'hq_registration'
        ? (string) ($details['license_id'] ?? '')
        : (string) ($details['renewal_license_number'] ?? '');

    return request_business_license_match($licenseNumber, (int) ($request['headquarter_id'] ?? 0));
}

/** Payment types that are visible to each review role. */
function request_payment_types_for_reviewer(string $reviewerRole, bool $includeRegistration = false): array
{
    $reviewerRole = strtolower(trim($reviewerRole));
    if ($reviewerRole === 'moderator') {
        return ['hq_period_extension', 'hq_station_package', 'hq_registration'];
    }

    $types = ['hq_period_extension', 'hq_station_package', 'hq_extra_station', 'hq_station_operating_quote'];
    if ($includeRegistration) {
        $types[] = 'hq_registration';
    }
    return $types;
}

/** Payment confirmations shown in the shared request queue. */
function request_payment_verification_rows(string $reviewerRole, string $search, string $status, bool $includeRegistration = false): array
{
    global $pdo;
    if (!isset($pdo) || !payment_transfer_schema_ready($pdo)) {
        return [];
    }
    $reviewerRole = strtolower(trim($reviewerRole));
    $status = strtolower(trim($status));
    $types = request_payment_types_for_reviewer($reviewerRole, $includeRegistration);
    $statusMap = [
        'pending' => ['pending_payment', 'payer_reported_paid'],
        'approved' => ['received'],
        'rejected' => ['rejected'],
        'reject' => ['rejected'],
    ];
    $paymentStatuses = $status === '' ? ['pending_payment', 'payer_reported_paid', 'received', 'rejected'] : ($statusMap[$status] ?? []);
    if (!$paymentStatuses) {
        return [];
    }
    $typeMarks = implode(',', array_fill(0, count($types), '?'));
    $statusMarks = implode(',', array_fill(0, count($paymentStatuses), '?'));
    $sql = "SELECT pt.payment_transfer_id, pt.payment_type, pt.status, pt.amount_mmk, pt.description, pt.payment_method,
                   pt.payer_user_id, pt.payer_email, pt.created_at, h.company_name AS payer_hq_name,
                   registration_request.status AS registration_request_status,
                   payer_user.user_name AS payer_name,
                   receipt.file_name AS receipt_name,
                   receipt.mime_type AS receipt_mime_type
            FROM payment_transfers pt
            LEFT JOIN headquarter h ON h.headquarter_id = pt.payer_headquarter_id
            LEFT JOIN system_requests registration_request
                   ON registration_request.request_id = pt.request_id
                  AND pt.payment_type = 'hq_registration'
            LEFT JOIN users payer_user ON payer_user.user_id = h.headquarters_owner_id
            LEFT JOIN (
                SELECT payment_transfer_id, MAX(file_name) AS file_name, MAX(mime_type) AS mime_type
                FROM payment_transfer_receipts
                GROUP BY payment_transfer_id
            ) receipt ON receipt.payment_transfer_id = pt.payment_transfer_id
            WHERE pt.payment_type IN ({$typeMarks})
              AND LOWER(TRIM(pt.status)) IN ({$statusMarks})
              AND (
                    pt.payment_type <> 'hq_registration'
                 OR LOWER(TRIM(COALESCE(registration_request.status, ''))) IN ('pending', 'approved')
              )";
    $params = array_merge($types, $paymentStatuses);
    if ($search !== '') {
        $sql .= " AND (CAST(pt.payment_transfer_id AS CHAR) LIKE ? OR LOWER(pt.reference_code) LIKE ?
                  OR LOWER(COALESCE(h.company_name, '')) LIKE ?
                  OR LOWER(COALESCE(payer_user.user_name, '')) LIKE ? OR LOWER(COALESCE(pt.payer_email, '')) LIKE ?
                  OR LOWER(COALESCE(pt.description, '')) LIKE ?)";
        $like = '%' . strtolower($search) . '%';
        array_push($params, '%' . $search . '%', $like, $like, $like, $like, $like);
    }
    $sql .= ' ORDER BY pt.created_at DESC, pt.payment_transfer_id DESC';
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('request_payment_verification_rows: ' . $e->getMessage());
        return [];
    }
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

    if (!hq_db_available()) {
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
 * Current request status totals, including system, account, and payment reviews.
 *
 * @return array{pending:int,approved:int,approved_today:int,declined_flagged:int,error:?string}
 */
function request_live_status_summary(string $reviewerRole = 'admin'): array
{
    global $conn, $pdo;

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

    // Include completed payment reviews in the same all-time totals used by
    // the request cards. Pending payment rows are added by the queue summary.
    if (isset($pdo) && $pdo instanceof PDO && payment_transfer_schema_ready($pdo)) {
        $paymentTypes = request_payment_types_for_reviewer($reviewerRole, true);
        $typeMarks = implode(',', array_fill(0, count($paymentTypes), '?'));
        try {
            $paymentQuery = $pdo->prepare(
                "SELECT LOWER(TRIM(status)) AS status, COUNT(*) AS total
                 FROM payment_transfers
                 WHERE payment_type IN ({$typeMarks})
                   AND LOWER(TRIM(status)) IN ('received', 'rejected')
                 GROUP BY LOWER(TRIM(status))"
            );
            $paymentQuery->execute($paymentTypes);
            foreach ($paymentQuery->fetchAll(PDO::FETCH_ASSOC) as $paymentCount) {
                $paymentStatus = strtolower(trim((string) ($paymentCount['status'] ?? '')));
                if ($paymentStatus === 'received') {
                    $summary['approved'] += (int) ($paymentCount['total'] ?? 0);
                } elseif ($paymentStatus === 'rejected') {
                    $summary['declined_flagged'] += (int) ($paymentCount['total'] ?? 0);
                }
            }
        } catch (Throwable $e) {
            error_log('[Request registry] Payment review totals query failed: ' . $e->getMessage());
            $summary['error'] = 'Payment review totals could not be loaded.';
        }
    }

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
