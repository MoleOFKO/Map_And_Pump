<?php
/**
 * _helpers.php  —  shared session, auth, and helpers.
 * Every page includes this after conn.php.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../conn.php';   // provides $pdo and $conn


// -------- Auth guard --------
// Redirects to login if the current visitor is not signed in as a branch manager.
function require_branch_manager(): void {
    $roleName = strtolower(trim((string) ($_SESSION['role_name'] ?? $_SESSION['role'] ?? '')));
    $roleName = str_replace([' ', '_'], '', $roleName);
    $roleId = (int) ($_SESSION['role_id'] ?? $_SESSION['current_role_id'] ?? 0);

    if (
        empty($_SESSION['is_logged_in'])
        || empty($_SESSION['user_id'])
        || ($roleId !== 4 && $roleName !== 'branchmanager' && $roleName !== 'manager')
    ) {
        header('Location: login.php');
        exit;
    }
}

// -------- Load current signed-in user + their branch --------
// Returns ['user' => [...], 'branch' => [...]] or nulls if no session yet.
function current_context($db = null) {
    global $pdo, $conn;
    $dbHandle = $db ?? $pdo ?? $conn;

    $hasStationBranch = false;
    try {
        $columnCheck = $dbHandle->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'stations'
               AND COLUMN_NAME = 'branch_id'"
        );
        $hasStationBranch = (int)$columnCheck->fetchColumn() > 0;
    } catch (Throwable $e) {
        $hasStationBranch = false;
    }
    $branchExpression = $hasStationBranch
        ? 'COALESCE(u.branch_id, s.branch_id, s.station_id)'
        : 'COALESCE(u.branch_id, s.station_id)';

    $stmt = $dbHandle->prepare("
        SELECT u.user_id,
               {$branchExpression} AS branch_id,
               u.user_name AS full_name,
               u.email,
               u.oauth_avatar_url AS avatar_url,
               NULL AS phone,
               NULL AS nrc,
               COALESCE(r.role_name, 'BranchManager') AS role,
               b.branch_code AS branch_code,
               b.branch_name AS branch_name,
               b.city, b.township,
               b.address AS address,
               b.phone AS branch_phone, b.email AS branch_email,
               b.opening_time, b.closing_time, b.is_24hr,
               b.pump_count, b.tank_count, b.hq_region, b.established_at,
               COALESCE(b.status, LOWER(s.status), 'active') AS branch_status,
               hq.company_name AS hq_name,
               hq.company_logo AS hq_logo
        FROM users u
        LEFT JOIN roles r ON r.role_id = COALESCE(u.current_role_id, u.business_role_id, u.role_id)
        LEFT JOIN (
            SELECT branch_manager_user_id, MIN(station_id) AS station_id, MIN(headquarter_id) AS headquarter_id
            FROM station_business_scope
            GROUP BY branch_manager_user_id
        ) managed ON managed.branch_manager_user_id = u.user_id
        LEFT JOIN stations s ON s.station_id = managed.station_id
        LEFT JOIN branches b ON b.branch_id = {$branchExpression}
                LEFT JOIN headquarter hq ON hq.headquarter_id = COALESCE(u.headquarter_id, managed.headquarter_id)
                WHERE u.user_id = :uid
                    AND b.manager_user_id = u.user_id
        LIMIT 1
    ");
    $stmt->execute([':uid' => $_SESSION['user_id']]);
    $row = $stmt->fetch();
    if (!$row) return ['user' => null, 'branch' => null];

    $user = [
        'user_id'   => $row['user_id'],
        'branch_id' => $row['branch_id'],
        'full_name' => $row['full_name'],
        'email'     => $row['email'],
        'avatar_url'=> $row['avatar_url'],
        'phone'     => $row['phone'],
        'nrc'       => $row['nrc'],
        'role'      => $row['role'],
        'branch_name' => $row['branch_name'],
        'branch_code' => $row['branch_code'],
    ];
    $branch = [
        'branch_id'      => $row['branch_id'],
        'branch_code'    => $row['branch_code'],
        'branch_name'    => $row['branch_name'],
        'city'           => $row['city'],
        'township'       => $row['township'],
        'address'        => $row['address'],
        'phone'          => $row['branch_phone'],
        'email'          => $row['branch_email'],
        'opening_time'   => $row['opening_time'],
        'closing_time'   => $row['closing_time'],
        'is_24hr'        => (int)$row['is_24hr'],
        'pump_count'     => (int)$row['pump_count'],
        'tank_count'     => (int)$row['tank_count'],
        'hq_region'      => $row['hq_region'],
        'established_at' => $row['established_at'],
        'status'         => $row['branch_status'],
        'hq_name'        => $row['hq_name'],
        'hq_logo'        => $row['hq_logo'],
    ];
    return ['user' => $user, 'branch' => $branch];
}

/** Resolve HQ logo path to a usable URL — handles absolute URLs and relative uploads/ paths */
function hq_logo_src(?string $logo): ?string {
    if (!$logo) return null;
    $logo = trim($logo);
    if ($logo === '') return null;
    // Already an absolute URL
    if (preg_match('#^https?://#i', $logo)) return $logo;
    // Root-relative path
    if (str_starts_with($logo, '/')) return $logo;
    // Relative to HQ uploads folder
    return '/' . ltrim($logo, '/');
}

// -------- View helpers --------

/** Two-letter initials from a name, e.g. "Felix Aung" -> "FA" */
function initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    if (!$parts) return '?';
    $ini = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $ini .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $ini ?: '?';
}

/** Safe HTML output */
function h($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Format 06:00:00 -> "6:00 AM" */
function fmt_time(?string $t): string {
    if (!$t) return '—';
    return date('g:i A', strtotime($t));
}

/** "Jul 18, 2026" */
function fmt_date(?string $d): string {
    if (!$d) return '—';
    return date('M j, Y', strtotime($d));
}

/** Human relative time e.g. "10 mins ago" */
function time_ago(?string $ts): string {
    if (!$ts) return '';
    $diff = time() - strtotime($ts);
    if ($diff < 60)      return $diff . ' sec ago';
    if ($diff < 3600)    return floor($diff/60)   . ' mins ago';
    if ($diff < 86400)   return floor($diff/3600) . ' hours ago';
    if ($diff < 86400*7) return floor($diff/86400). ' days ago';
    return date('M j', strtotime($ts));
}

/** CSRF token helpers */
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}
function csrf_check(?string $t): bool {
    return !empty($_SESSION['csrf']) && is_string($t) && hash_equals($_SESSION['csrf'], $t);
}

/** Redirect back to the current page with a flash message */
function redirect_with(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}
function take_flash(): ?array {
    if (empty($_SESSION['flash'])) return null;
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $f;
}

/** JSON response for AJAX endpoints */
function json_out($payload, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Log a manager / staff action */
function log_activity(PDO $conn, int $branch_id, string $actor_type, int $actor_id, string $actor_name, string $action): void {
    require_once __DIR__ . '/../activity_log.php';
    map_pump_log_action($conn, $actor_id, $action, 4);
}
