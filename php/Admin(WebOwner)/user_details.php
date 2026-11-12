<?php
require_once __DIR__ . '/admin_common.php';
require_once __DIR__ . '/user_account_common.php';

admin_require_staff();

header('Content-Type: application/json; charset=utf-8');

$userId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$requestContext = strtolower(trim((string) ($_GET['context'] ?? ''))) === 'request';
$comparisonContext = strtolower(trim((string) ($_GET['context'] ?? ''))) === 'compare';
$reviewerContext = $requestContext || $comparisonContext;
$contactVisibleStatuses = ['disabled', 'removed', 'suspended'];
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
$customerRoleId = customer_role_id();
$roleIdSql = $reviewerContext ? $roleIdSql : (string) $customerRoleId;
$pendingLicenseScope = user_account_column_exists('user_license', 'status')
    ? "OR (LOWER(TRIM(u.status)) IN ('active', 'approved') AND LOWER(TRIM(l.status)) = 'pending')"
    : '';
$scopeCondition = $requestContext
    ? "AND (LOWER(TRIM(u.status)) = 'pending_verification' {$pendingLicenseScope})"
    : 'AND ' . customer_account_role_guard_sql($customerRoleId);
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
    LEFT JOIN (
        SELECT user_id, MIN(headquarter_id) AS headquarter_id
        FROM business_account_scope
        WHERE headquarter_id IS NOT NULL
        GROUP BY user_id
    ) bas ON bas.user_id = u.user_id
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
    echo json_encode(['success' => false, 'message' => 'Account not found.']);
    exit;
}
$showComparedUserContact = $comparisonContext
    && in_array(strtolower(trim((string) ($user['status'] ?? ''))), $contactVisibleStatuses, true);

$actions = user_action_rows((int) $userId);
// Conflict detection still uses NRC + license number server-side.
// Sensitive identity fields (nrc_number, date_of_birth, blood_type) are never returned to staff UIs.
$licenseConflicts = [];
if (!empty($user['license_id'])
    && (trim((string) ($user['license_number'] ?? '')) !== '' || trim((string) ($user['nrc_number'] ?? '')) !== '')) {
    $eligibleAccountFilter = $requestContext
        ? "AND LOWER(TRIM(COALESCE(u.status, ''))) NOT IN ('banned', 'disabled', 'suspended', 'removed', 'deleted')"
        : '';
    $conflictLimit = $reviewerContext ? 'LIMIT 1' : '';
    $conflictStatement = $conn->prepare(
        "SELECT u.user_id, u.user_name, u.email, u.status AS account_status,
                l.license_id, l.license_number, l.nrc_number, l.license_name, l.status AS license_status,
                l.license_image, l.face_image
         FROM user_license l
         INNER JOIN users u ON u.license_id = l.license_id
         WHERE ((? <> '' AND UPPER(REPLACE(TRIM(l.license_number), ' ', '')) = UPPER(REPLACE(TRIM(?), ' ', '')))
             OR (? <> '' AND UPPER(REPLACE(TRIM(l.nrc_number), ' ', '')) = UPPER(REPLACE(TRIM(?), ' ', ''))))
           AND l.license_id <> ?
                     {$eligibleAccountFilter}
         ORDER BY u.created_at ASC, u.user_id ASC
                 {$conflictLimit}"
    );
    if ($conflictStatement) {
        $licenseId = (int) $user['license_id'];
        $licenseNumber = trim((string) ($user['license_number'] ?? ''));
        $nrcNumber = trim((string) ($user['nrc_number'] ?? ''));
        $conflictStatement->bind_param('ssssi', $licenseNumber, $licenseNumber, $nrcNumber, $nrcNumber, $licenseId);
        if ($conflictStatement->execute()) {
            $conflictResult = $conflictStatement->get_result();
            while ($conflictResult && ($conflict = $conflictResult->fetch_assoc())) {
                $duplicateFields = [];
                if ($licenseNumber !== '' && strcasecmp(preg_replace('/\s+/', '', (string) $conflict['license_number']), preg_replace('/\s+/', '', $licenseNumber)) === 0) {
                    $duplicateFields[] = 'Driver license number';
                }
                if ($nrcNumber !== '' && strcasecmp(preg_replace('/\s+/', '', (string) $conflict['nrc_number']), preg_replace('/\s+/', '', $nrcNumber)) === 0) {
                    $duplicateFields[] = 'NRC number';
                }
                // Return the matching NRC only for reviewer duplicate comparison.
                $licenseConflicts[] = [
                    'user_id' => (int) $conflict['user_id'],
                    'user_name' => $conflict['user_name'],
                    'email' => in_array(strtolower(trim((string) ($conflict['account_status'] ?? ''))), $contactVisibleStatuses, true)
                        ? $conflict['email']
                        : null,
                    'account_status' => $conflict['account_status'],
                    'license_id' => (int) $conflict['license_id'],
                    'license_number' => $conflict['license_number'],
                    'nrc_number' => $conflict['nrc_number'],
                    'license_name' => $conflict['license_name'],
                    'license_status' => $conflict['license_status'],
                    'license_image' => $conflict['license_image'],
                    'face_image' => $conflict['face_image'],
                    'duplicate_fields' => $duplicateFields,
                ];
            }
        }
        $conflictStatement->close();
    }
}

$hasConflicts = count($licenseConflicts) > 0;
$includeLicenseImages = $reviewerContext || $hasConflicts;

$licensePayload = null;
if ($user['license_id'] !== null) {
    $licensePayload = [
        'license_id' => (int) $user['license_id'],
        'license_number' => $user['license_number'],
        'license_name' => $user['license_name'],
        'license_last_valid_date' => $user['license_last_valid_date'],
        'status' => $user['license_status'],
    ];
    // Request review and conflict investigation need document images.
    if ($includeLicenseImages) {
        $licensePayload['license_image'] = $user['license_image'];
        $licensePayload['face_image'] = $user['face_image'];
    }
    if ($hasConflicts) {
        $licensePayload['nrc_number'] = $user['nrc_number'];
    }
    // Phone, date of birth, and blood type remain omitted from staff responses.
}

$headquarterPayload = null;
if ($user['headquarter_id'] !== null) {
    if ($reviewerContext) {
        // Request review: company identity needed to approve/reject linkage.
        $headquarterPayload = [
            'headquarter_id' => (int) $user['headquarter_id'],
            'company_name' => $user['headquarter_name'],
            'location' => $user['headquarter_location'],
            'business_license' => $user['business_license'],
            'business_license_image' => $user['business_license_image'],
            'business_license_expire_date' => $user['business_license_expire_date'],
            'expire_at' => $user['headquarter_expire_at'],
            'company_logo' => $user['company_logo'],
        ];
    } else {
        // Account management: minimum company summary only.
        $headquarterPayload = [
            'headquarter_id' => (int) $user['headquarter_id'],
            'company_name' => $user['headquarter_name'],
            'location' => $user['headquarter_location'],
        ];
    }
}

$responsePayload = [
    'success' => true,
    'context' => $requestContext ? 'request' : ($comparisonContext ? 'compare' : 'account'),
    'user' => [
        'user_id' => (int) $user['user_id'],
        'user_public_id' => (string) ($user['user_public_id'] ?? ''),
        'user_name' => $user['user_name'],
        'email' => ($requestContext || $showComparedUserContact) ? $user['email'] : null,
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
    'license' => $licensePayload,
    'license_conflicts' => $licenseConflicts,
    'headquarter' => $headquarterPayload,
    'actions' => $requestContext ? [] : $actions,
];
$responsePayload = $reviewerContext ? $responsePayload : privacy_mask_sensitive_data($responsePayload);
echo json_encode($responsePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
