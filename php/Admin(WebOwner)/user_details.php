<?php
require_once __DIR__ . '/admin_common.php';

admin_require_staff();

header('Content-Type: application/json; charset=utf-8');

$userId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$userId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid user id is required.']);
    exit;
}

if (!hq_db_available() || !hq_registry_schema_ready()) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => hq_db_error_message()]);
    exit;
}

global $conn;
$statement = $conn->prepare(
    'SELECT u.user_id, u.user_name, u.email, u.status, u.created_at, u.updated_at, u.last_login_at,
            u.license_id, u.headquarter_id,
            COALESCE(u.current_role_id, u.business_role_id, u.role_id) AS role_id,
            r.role_name,
            h.company_name AS headquarter_name, h.headquarters_owner_id, h.company_logo,
            h.contact_number, h.headquarter_email, h.headquarter_bio, h.location AS headquarter_location,
            h.business_license, h.business_license_image, h.business_license_expire_date, h.expire_at AS headquarter_expire_at,
            l.license_number, l.license_name, l.phone_number, l.nrc_number, l.date_of_birth,
            l.blood_type, l.license_last_valid_date, l.status AS license_status,
            l.license_image, l.face_image
     FROM users u
     LEFT JOIN roles r ON r.role_id = COALESCE(u.current_role_id, u.business_role_id, u.role_id)
     LEFT JOIN headquarter h ON h.headquarter_id = u.headquarter_id
     LEFT JOIN user_license l ON l.license_id = u.license_id
     WHERE u.user_id = ?
       AND u.role_id = 6
       AND (u.business_role_id IS NULL OR u.business_role_id = 6)
       AND (u.current_role_id IS NULL OR u.current_role_id = 6)
       AND LOWER(TRIM(r.role_name)) = 'customer'
     LIMIT 1'
);

if (!$statement) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => hq_db_error_message()]);
    exit;
}

$statement->bind_param('i', $userId);
$statement->execute();
$user = $statement->get_result()?->fetch_assoc();
$statement->close();

if (!$user) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'User not found.']);
    exit;
}

$actions = user_action_rows((int) $userId);

echo json_encode([
    'success' => true,
    'user' => [
        'user_id' => (int) $user['user_id'],
        'user_name' => $user['user_name'],
        'email' => $user['email'],
        'status' => $user['status'],
        'created_at' => $user['created_at'],
        'updated_at' => $user['updated_at'],
        'last_login_at' => $user['last_login_at'],
        'license_id' => isset($user['license_id']) ? (int) $user['license_id'] : null,
        'headquarter_id' => isset($user['headquarter_id']) ? (int) $user['headquarter_id'] : null,
        'headquarter_name' => $user['headquarter_name'],
        'headquarter_location' => $user['headquarter_location'],
        'role_id' => isset($user['role_id']) ? (int) $user['role_id'] : null,
        'role_name' => $user['role_name'],
    ],
    'license' => $user['license_id'] !== null ? [
        'license_id' => (int) $user['license_id'],
        'license_number' => $user['license_number'],
        'license_name' => $user['license_name'],
        'phone_number' => $user['phone_number'],
        'nrc_number' => $user['nrc_number'],
        'date_of_birth' => $user['date_of_birth'],
        'blood_type' => $user['blood_type'],
        'license_last_valid_date' => $user['license_last_valid_date'],
        'status' => $user['license_status'],
        'license_image' => $user['license_image'],
        'face_image' => $user['face_image'],
    ] : null,
    'headquarter' => $user['headquarter_id'] !== null ? [
        'headquarter_id' => (int) $user['headquarter_id'],
        'company_name' => $user['headquarter_name'],
        'headquarters_owner_id' => isset($user['headquarters_owner_id']) ? (int) $user['headquarters_owner_id'] : null,
        'company_logo' => $user['company_logo'],
        'contact_number' => $user['contact_number'],
        'headquarter_email' => $user['headquarter_email'],
        'headquarter_bio' => $user['headquarter_bio'],
        'location' => $user['headquarter_location'],
        'business_license' => $user['business_license'],
        'business_license_image' => $user['business_license_image'],
        'business_license_expire_date' => $user['business_license_expire_date'],
        'expire_at' => $user['headquarter_expire_at'],
    ] : null,
    'actions' => $actions,
]);
