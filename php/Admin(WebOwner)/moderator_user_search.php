<?php
/**
 * JSON typeahead search for existing accounts that can be hired as moderators.
 *
 * Stability notes:
 * - Session is read then closed immediately so concurrent keystroke requests
 *   do not block each other on PHP's exclusive session lock.
 * - Admin gate accepts role_name "admin" or role_id 1 (common admin id),
 *   and also business_role when the account was promoted via hire.
 * - Schema readiness is cached for the request lifetime inside
 *   moderator_schema_ready().
 */
require_once __DIR__ . '/moderator_common.php';
require_once __DIR__ . '/../moderator_hire_helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function moderator_search_json(bool $ok, string $message = '', array $extra = []): never
{
    echo json_encode(['ok' => $ok, 'message' => $message] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
}

$adminUserId = (int) ($_SESSION['user_id'] ?? 0);
$roleName = strtolower(trim((string) ($_SESSION['role_name'] ?? '')));
$roleId = (int) ($_SESSION['role_id'] ?? 0);
$businessRoleName = strtolower(trim((string) ($_SESSION['business_role_name'] ?? '')));
$businessRoleId = (int) ($_SESSION['business_role_id'] ?? 0);

// Release the session lock before any DB work so rapid typeahead calls
// do not queue behind each other (classic intermittent-search symptom).
session_write_close();

$isAdmin = $adminUserId > 0 && (
    $roleName === 'admin'
    || $businessRoleName === 'admin'
    || $roleId === 1
    || $businessRoleId === 1
);

if (!hq_db_available()) {
    error_log('[Moderator search] Database connection unavailable.');
    moderator_search_json(false, 'The account registry is temporarily unavailable.');
}

// When session role is stale/ambiguous, confirm admin from the database.
if ($adminUserId > 0 && !$isAdmin) {
    $adminCheck = hq_prepare(
        "SELECT 1
         FROM users u
         LEFT JOIN roles r ON r.role_id = u.role_id
         LEFT JOIN roles br ON br.role_id = u.business_role_id
         WHERE u.user_id = ?
           AND (
                LOWER(TRIM(COALESCE(r.role_name, ''))) = 'admin'
             OR LOWER(TRIM(COALESCE(br.role_name, ''))) = 'admin'
           )
         LIMIT 1",
        'i',
        [$adminUserId]
    );
    if ($adminCheck && $adminCheck->execute()) {
        $isAdmin = (bool) $adminCheck->get_result()?->fetch_row();
        $adminCheck->close();
    } elseif ($adminCheck) {
        $adminCheck->close();
    }
}

if (!$isAdmin) {
    moderator_search_json(false, 'Only administrators can search accounts for moderator hiring.');
}

if (!moderator_schema_ready()) {
    error_log('[Moderator search] Schema check failed.');
    moderator_search_json(false, 'The account registry is temporarily unavailable.');
}

$query = trim((string) ($_GET['q'] ?? ''));
if (strlen($query) < 2) {
    moderator_search_json(true, '', ['results' => []]);
}

$like = '%' . strtolower($query) . '%';
$exact = strtolower($query);
$emailPrefix = $exact . '%';

// Prefer a query that accounts for business_role_id when the column exists.
// Fall back without that join if prepare fails (partial deploys / older schema).
$sqlWithBusiness = "
    SELECT u.user_id, u.user_name, u.email, u.status, r.role_name
    FROM users u
    LEFT JOIN roles r ON r.role_id = u.role_id
    LEFT JOIN roles br ON br.role_id = u.business_role_id
    WHERE (
           LOWER(u.user_name) LIKE ?
        OR LOWER(u.email) LIKE ?
    )
      AND LOWER(TRIM(COALESCE(r.role_name, ''))) NOT IN ('admin', 'moderator')
      AND LOWER(TRIM(COALESCE(br.role_name, ''))) NOT IN ('admin', 'moderator')
    ORDER BY
       CASE WHEN LOWER(u.email) = ? THEN 0
            WHEN LOWER(u.user_name) = ? THEN 1
            WHEN LOWER(u.email) LIKE ? THEN 2
            ELSE 3 END,
       u.user_name ASC
    LIMIT 12";

$sqlWithoutBusiness = "
    SELECT u.user_id, u.user_name, u.email, u.status, r.role_name
    FROM users u
    LEFT JOIN roles r ON r.role_id = u.role_id
    WHERE (
           LOWER(u.user_name) LIKE ?
        OR LOWER(u.email) LIKE ?
    )
      AND LOWER(TRIM(COALESCE(r.role_name, ''))) NOT IN ('admin', 'moderator')
    ORDER BY
       CASE WHEN LOWER(u.email) = ? THEN 0
            WHEN LOWER(u.user_name) = ? THEN 1
            WHEN LOWER(u.email) LIKE ? THEN 2
            ELSE 3 END,
       u.user_name ASC
    LIMIT 12";

$params = [$like, $like, $exact, $exact, $emailPrefix];
$statement = hq_prepare($sqlWithBusiness, 'sssss', $params);
if (!$statement) {
    $statement = hq_prepare($sqlWithoutBusiness, 'sssss', $params);
}

if (!$statement) {
    global $conn;
    error_log('[Moderator search] prepare failed: ' . ($conn->error ?? 'unknown'));
    moderator_search_json(false, 'Account search failed.');
}

if (!$statement->execute()) {
    error_log('[Moderator search] execute failed: ' . $statement->error);
    $statement->close();
    moderator_search_json(false, 'Account search failed.');
}

$result = $statement->get_result();
$results = [];
while ($result && ($row = $result->fetch_assoc())) {
    $results[] = [
        'user_id' => (int) $row['user_id'],
        'user_name' => (string) $row['user_name'],
        'email' => (string) $row['email'],
        'status' => (string) ($row['status'] ?? ''),
        'role_name' => (string) ($row['role_name'] ?? 'Unassigned'),
        'label' => trim((string) $row['user_name']) . ' · ' . trim((string) $row['email']),
    ];
}
$statement->close();

moderator_search_json(true, '', ['results' => $results]);
