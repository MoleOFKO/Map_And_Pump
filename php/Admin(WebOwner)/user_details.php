<?php
require_once __DIR__ . '/admin_common.php';
require_once __DIR__ . '/user_account_common.php';

admin_require_staff();

header('Content-Type: application/json; charset=utf-8');

$userId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$requestContext = strtolower(trim((string) ($_GET['context'] ?? ''))) === 'request';
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
$roleIdSql = user_account_column_exists('users', 'current_role_id')
    ? 'COALESCE(u.current_role_id, u.business_role_id, u.role_id)'
    : 'COALESCE(u.business_role_id, u.role_id)';
$publicIdSelect = user_account_column_exists('users', 'user_public_id')
    ? 'u.user_public_id'
    : 'NULL AS user_public_id';
$lastLoginSelect = user_account_column_exists('users', 'last_login_at')
    ? 'u.last_login_at'
    : 'NULL AS last_login_at';
$licenseStatusSelect = user_account_column_exists('user_license', 'status')
    ? 'l.status AS license_status'
    : 'NULL AS license_status';
$licenseFaceImageSelect = user_account_column_exists('user_license', 'face_image')
    ? 'l.face_image'
    : 'NULL AS face_image';
$scopeCondition = $requestContext
    ? "AND LOWER(TRIM(u.status)) = 'pending_verification'"
    : "AND u.role_id = 6
       AND (u.business_role_id IS NULL OR u.business_role_id = 6)
       AND ({$roleIdSql} = 6)
       AND LOWER(TRIM(r.role_name)) = 'customer'";
$statement = $conn->prepare(
    "SELECT u.user_id, {$publicIdSelect}, u.user_name, u.email, u.status, u.created_at, u.updated_at, {$lastLoginSelect},
            u.license_id, bas.headquarter_id,
            {$roleIdSql} AS role_id,
            r.role_name,
            h.company_name AS headquarter_name, h.headquarters_owner_id, h.company_logo,
            h.contact_number, h.headquarter_email, h.headquarter_bio, h.location AS headquarter_location,
            h.business_license, h.business_license_image, h.business_license_expire_date, h.expire_at AS headquarter_expire_at,
            l.license_number, l.license_name, l.phone_number, l.nrc_number, l.date_of_birth,
            l.blood_type, l.license_last_valid_date, {$licenseStatusSelect},
            l.license_image, {$licenseFaceImageSelect}
     FROM users u
     LEFT JOIN roles r ON r.role_id = {$roleIdSql}
    LEFT JOIN business_account_scope bas ON bas.user_id = u.user_id
    LEFT JOIN headquarter h ON h.headquarter_id = bas.headquarter_id
     LEFT JOIN user_license l ON l.license_id = u.license_id
     WHERE u.user_id = ? {$scopeCondition}
     LIMIT 1"
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
$licenseConflicts = [];
if (!empty($user['license_id']) && trim((string) ($user['license_number'] ?? '')) !== '') {
    $conflictStatement = $conn->prepare(
        "SELECT u.user_id, u.user_name, u.email, u.status AS account_status,
                l.license_id, l.license_number, l.license_name, l.status AS license_status,
                l.license_image, l.face_image
         FROM user_license l
         INNER JOIN users u ON u.license_id = l.license_id
         WHERE UPPER(REPLACE(TRIM(l.license_number), ' ', '')) = UPPER(REPLACE(TRIM(?), ' ', ''))
           AND l.license_id <> ?
         ORDER BY CASE WHEN LOWER(TRIM(l.status)) = 'approved' THEN 0 ELSE 1 END,
                  l.license_id DESC"
    );
    if ($conflictStatement) {
        $licenseId = (int) $user['license_id'];
        $conflictStatement->bind_param('si', $user['license_number'], $licenseId);
        if ($conflictStatement->execute()) {
            $conflictResult = $conflictStatement->get_result();
            while ($conflictResult && ($conflict = $conflictResult->fetch_assoc())) {
                $licenseConflicts[] = [
                    'user_id' => (int) $conflict['user_id'],
                    'user_name' => $conflict['user_name'],
                    'email' => $conflict['email'],
                    'account_status' => $conflict['account_status'],
                    'license_id' => (int) $conflict['license_id'],
                    'license_number' => $conflict['license_number'],
                    'license_name' => $conflict['license_name'],
                    'license_status' => $conflict['license_status'],
                    'license_image' => $conflict['license_image'],
                    'face_image' => $conflict['face_image'],
                ];
            }
        }
        $conflictStatement->close();
    }
}

echo json_encode([
    'success' => true,
    'user' => [
        'user_id' => (int) $user['user_id'],
        'user_public_id' => (string) ($user['user_public_id'] ?? ''),
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
    'license_conflicts' => $licenseConflicts,
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
