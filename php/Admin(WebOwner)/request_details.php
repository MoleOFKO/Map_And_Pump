<?php
require_once __DIR__ . '/request_common.php';

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
    'SELECT r.request_id, r.reference_code, r.request_type, r.status,
            r.sender_entity, r.sender_name, r.sender_email, r.location,
            r.role_id, r.headquarter_id, r.headquarter_owner_email,
            r.headquarter_email, r.headquarter_company_logo,
            r.headquarter_contact_number, r.headquarter_expire_at,
            r.request_details, r.document_name, r.document_path,
            r.review_reason, r.reviewed_at, r.created_at,
            u.user_id AS submitter_user_id, u.user_name AS submitter_username,
            h.headquarter_id, h.company_name AS hq_company_name
     FROM system_requests r
     LEFT JOIN users u ON u.user_id = r.submitter_user_id
     LEFT JOIN headquarter h ON h.headquarter_id = r.headquarter_id
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
        'role_id' => $request['role_id'] !== null ? (int) $request['role_id'] : null,
        'headquarter_id' => $request['headquarter_id'] !== null ? (int) $request['headquarter_id'] : null,
        'headquarter_owner_email' => $request['headquarter_owner_email'],
        'headquarter_email' => $request['headquarter_email'],
        'headquarter_company_logo' => $request['headquarter_company_logo'],
        'headquarter_contact_number' => $request['headquarter_contact_number'],
        'headquarter_expire_at' => $request['headquarter_expire_at'],
        'location' => $request['location'],
        'request_details' => $request['request_details'],
        'document_name' => $request['document_name'],
        'document_path' => $request['document_path'],
        'review_reason' => $request['review_reason'],
        'reviewed_at' => $request['reviewed_at'],
        'created_at' => $request['created_at'],
        'submitter_user_id' => $request['submitter_user_id'] !== null ? (int) $request['submitter_user_id'] : null,
        'submitter_username' => $request['submitter_username'],
        'hq_company_name' => $request['hq_company_name'],
    ],
]);
