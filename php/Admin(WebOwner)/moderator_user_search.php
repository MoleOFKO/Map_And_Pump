<?php
/**
 * JSON typeahead search for existing accounts that can be hired as moderators.
 */
require_once __DIR__ . '/moderator_common.php';
require_once __DIR__ . '/../moderator_hire_helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function moderator_search_json(bool $ok, string $message = '', array $extra = []): never
{
    echo json_encode(['ok' => $ok, 'message' => $message] + $extra);
    exit;
}

$adminUserId = (int) ($_SESSION['user_id'] ?? 0);
$roleName = strtolower(trim((string) ($_SESSION['role_name'] ?? '')));
if ($adminUserId <= 0 || $roleName !== 'admin') {
    moderator_search_json(false, 'Only administrators can search accounts for moderator hiring.');
}

if (!moderator_schema_ready() || !hq_db_available()) {
    moderator_search_json(false, 'The account registry is temporarily unavailable.');
}

$query = trim((string) ($_GET['q'] ?? ''));
if (strlen($query) < 2) {
    moderator_search_json(true, '', ['results' => []]);
}

$like = '%' . strtolower($query) . '%';
$statement = hq_prepare(
    "SELECT u.user_id, u.user_name, u.email, u.status, r.role_name
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
     LIMIT 12",
    'sssss',
    [$like, $like, strtolower($query), strtolower($query), strtolower($query) . '%']
);

if (!$statement || !$statement->execute()) {
    $statement?->close();
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
