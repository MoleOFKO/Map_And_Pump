<?php
require_once __DIR__ . '/request_common.php';
require_once __DIR__ . '/admin_common.php';
require_once __DIR__ . '/../payment_transfers.php';

admin_require_staff();

header('Content-Type: application/json; charset=utf-8');

$requestId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$requestId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid request id is required.']);
    exit;
}
if (!hq_db_available() || !request_schema_ready()) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'The request database is temporarily unavailable.']);
    exit;
}

global $conn;
$statement = $conn->prepare(
    'SELECT r.request_id, r.headquarter_id, r.reference_code, r.request_type, r.status,
            r.sender_entity, r.sender_name, r.sender_email, r.location,
            r.request_details, r.document_name, r.document_path,
            r.headquarter_owner_email, r.headquarter_email, r.headquarter_company_logo,
            r.headquarter_contact_number, r.headquarter_expire_at,
            r.review_reason, r.reviewed_at, r.created_at,
            u.user_id AS submitter_user_id, u.user_name AS submitter_username,
            h.company_name AS hq_company_name, h.company_logo, h.contact_number,
            h.headquarter_email AS hq_email, h.headquarter_bio, h.location AS hq_location,
                 h.business_license, h.business_license_image, h.business_license_expire_date,
                 receipt.receipt_type, receipt.receipt_image
     FROM system_requests r
     LEFT JOIN users u ON u.user_id = r.submitter_user_id
     LEFT JOIN headquarter h ON h.headquarter_id = r.headquarter_id
             LEFT JOIN hq_receipt receipt ON receipt.request_id = r.request_id
     WHERE r.request_id = ?
     LIMIT 1'
);
if (!$statement) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'The request details could not be loaded.']);
    exit;
}
$statement->bind_param('i', $requestId);
$statement->execute();
$request = $statement->get_result()?->fetch_assoc();
$statement->close();

if (!$request) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Request not found.']);
    exit;
}

$registrationPayment = null;
if (strtolower((string) $request['request_type']) === 'hq_registration' && payment_transfer_schema_ready($pdo)) {
    $paymentStmt = $pdo->prepare(
        "SELECT pt.payment_transfer_id, pt.status, pt.amount_mmk, pt.payment_method,
                pt.payer_user_id, pt.payer_email,
                receipt.file_name, receipt.mime_type
         FROM payment_transfers pt
         LEFT JOIN payment_transfer_receipts receipt ON receipt.payment_transfer_id = pt.payment_transfer_id
         WHERE pt.payment_type = 'hq_registration'
           AND (pt.request_id = ? OR (COALESCE(pt.request_id, 0) = 0 AND pt.payer_headquarter_id = ? AND ? > 0))
         ORDER BY CASE WHEN pt.request_id = ? THEN 0 ELSE 1 END, pt.payment_transfer_id DESC
         LIMIT 1"
    );
    $headquarterId = (int) ($request['headquarter_id'] ?? 0);
    $paymentStmt->execute([$requestId, $headquarterId, $headquarterId, $requestId]);
    $paymentRow = $paymentStmt->fetch(PDO::FETCH_ASSOC);
    if ($paymentRow) {
        $reviewerId = (int) ($_SESSION['user_id'] ?? 0);
        $reviewerEmail = strtolower(trim((string) ($_SESSION['user_email'] ?? '')));
        $isPayer = ((int) ($paymentRow['payer_user_id'] ?? 0) === $reviewerId && $reviewerId > 0)
            || ($reviewerEmail !== '' && $reviewerEmail === strtolower(trim((string) ($paymentRow['payer_email'] ?? ''))));
        $registrationPayment = [
            'payment_transfer_id' => (int) $paymentRow['payment_transfer_id'],
            'status' => (string) $paymentRow['status'],
            'amount_mmk' => (float) $paymentRow['amount_mmk'],
            'payment_method' => (string) ($paymentRow['payment_method'] ?? ''),
            'receipt_name' => $paymentRow['file_name'],
            'receipt_mime' => $paymentRow['mime_type'],
            'is_current_user_payer' => $isPayer,
            'can_review' => payment_transfer_user_is_admin_or_moderator($pdo, $reviewerId) && !$isPayer,
        ];
    }
}
if (empty($_SESSION['payment_transfer_csrf'])) {
    $_SESSION['payment_transfer_csrf'] = bin2hex(random_bytes(32));
}

$registrationDetails = json_decode((string) ($request['request_details'] ?? ''), true);
$registrationDetails = is_array($registrationDetails) ? $registrationDetails : [];
$businessLicenseConflict = request_business_license_match_for_request($request);

echo json_encode([
    'success' => true,
    'request' => [
        'request_id' => (int) $request['request_id'],
        'reference_code' => $request['reference_code'],
        'request_type' => $request['request_type'],
        'request_type_label' => request_type_label($request['request_type']),
        'status' => $request['status'],
        'status_label' => request_status_label($request['status']),
        'sender_entity' => $request['sender_entity'],
        'sender_name' => $request['sender_name'],
        'sender_email' => $request['sender_email'],
        'location' => $request['location'],
        'request_details' => $request['request_details'],
        'registration_details' => [
            'license_id' => $registrationDetails['renewal_license_number'] ?? ($registrationDetails['license_id'] ?? null),
            'license_expiry' => $request['headquarter_expire_at'] ?? ($registrationDetails['license_expiry'] ?? null),
            'company_address' => $registrationDetails['company_address'] ?? null,
            'owner_phone' => $registrationDetails['owner_phone'] ?? null,
            'company_phone' => $registrationDetails['company_phone'] ?? null,
            'payment_method' => $registrationDetails['payment_method'] ?? null,
            'bank_name' => $registrationDetails['bank_name'] ?? null,
            'requested_station_count' => isset($registrationDetails['requested_station_count']) ? (int) $registrationDetails['requested_station_count'] : null,
        ],
        'document_name' => $request['document_name'],
        'document_path' => $request['document_path'],
        'headquarter_owner_email' => $request['headquarter_owner_email'],
        'headquarter_email' => $request['headquarter_email'],
        'headquarter_company_logo' => $request['headquarter_company_logo'],
        'headquarter_contact_number' => $request['headquarter_contact_number'],
        'headquarter_expire_at' => $request['headquarter_expire_at'],
        'review_reason' => $request['review_reason'],
        'reviewed_at' => $request['reviewed_at'],
        'created_at' => $request['created_at'],
        'submitter_user_id' => $request['submitter_user_id'] !== null ? (int) $request['submitter_user_id'] : null,
        'submitter_username' => $request['submitter_username'],
        'hq_company_name' => $request['hq_company_name'],
        'company_logo' => $request['company_logo'],
        'contact_number' => $request['contact_number'],
        'hq_email' => $request['hq_email'],
        'headquarter_bio' => $request['headquarter_bio'],
        'hq_location' => $request['hq_location'],
        'business_license' => $request['business_license'],
        'business_license_image' => $request['business_license_image'],
        'business_license_expire_date' => $request['business_license_expire_date'],
        'business_license_conflict' => $businessLicenseConflict,
        'receipt_type' => $request['receipt_type'],
        'receipt_image' => $request['receipt_image'],
        'registration_payment' => $registrationPayment,
        'payment_transfer_csrf' => (string) $_SESSION['payment_transfer_csrf'],
        'can_review_registration_payment' => (bool) ($registrationPayment['can_review'] ?? false),
    ],
]);
