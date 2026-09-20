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

    $branchExpression = 'COALESCE(u.branch_id, managed.station_id)';

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
               COALESCE(b.status, 'active') AS branch_status,
               hq.company_name AS hq_name,
               hq.company_logo AS hq_logo
        FROM users u
        LEFT JOIN roles r ON r.role_id = COALESCE(u.current_role_id, u.business_role_id, u.role_id)
        LEFT JOIN (
            SELECT branch_manager_user_id, MIN(station_id) AS station_id, MIN(headquarter_id) AS headquarter_id
            FROM station_business_scope
            GROUP BY branch_manager_user_id
        ) managed ON managed.branch_manager_user_id = u.user_id
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

// ============================================================
//  Notifications  —  read-state tracking + shared helpers
//  The notifications table shipped without a read column, so the bell /
//  alerts UI could never persist a "read" state. These helpers add a
//  nullable `read_at` column on first use (non-destructive) and expose
//  count / list / mark-read operations used by every Branch Manager page.
// ============================================================

/**
 * Ensure notifications.read_at exists. Detection runs at most once per
 * request; the ADD COLUMN is attempted a single time and any failure is
 * swallowed so pages keep working on a read-only DB user (every row is
 * then treated as unread). Returns true when the column can be queried.
 */
function notif_has_read_column(PDO $db): bool {
    static $checked = null;
    if ($checked !== null) return $checked;

    try {
        $stmt = $db->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'notifications'
               AND COLUMN_NAME = 'read_at'"
        );
        $exists = (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return $checked = false;
    }

    if (!$exists) {
        try {
            $db->exec("ALTER TABLE notifications ADD COLUMN read_at TIMESTAMP NULL DEFAULT NULL");
            $exists = true;
        } catch (Throwable $e) {
            $exists = false; // no DDL permission — degrade gracefully
        }
    }
    return $checked = $exists;
}

/** Map a notification_type string onto a UI kind: warn | err | ok | info */
function notif_kind(string $type): string {
    $t = strtolower($type);
    if (preg_match('/(down|fail|error|danger|reject|leak|critical)/', $t)) return 'err';
    if (preg_match('/(low|warn|alert|expir|maint)/', $t))                  return 'warn';
    if (preg_match('/(receiv|deliver|approve|success|complete|added)/', $t)) return 'ok';
    return 'info';
}

/** Count unread notifications for a manager. */
function notif_unread_count(PDO $db, int $user_id): int {
    $sql = "SELECT COUNT(*) FROM notifications WHERE recipient_user_id = :u";
    if (notif_has_read_column($db)) $sql .= " AND read_at IS NULL";
    $stmt = $db->prepare($sql);
    $stmt->execute([':u' => $user_id]);
    return (int)$stmt->fetchColumn();
}

/**
 * Recent notifications for a manager, normalised for the bell + alerts UI.
 * Each row: notif_id, kind, type, title, body, created_at, time_ago, is_read
 */
function notif_recent(PDO $db, int $user_id, int $limit = 5): array {
    $hasRead    = notif_has_read_column($db);
    $readSelect = $hasRead ? 'read_at' : 'NULL AS read_at';
    $limit      = max(1, min(50, $limit));

    $stmt = $db->prepare("
        SELECT notification_id, notification_type, subject, message, created_at, {$readSelect}
        FROM notifications
        WHERE recipient_user_id = :u
        ORDER BY created_at DESC
        LIMIT {$limit}
    ");
    $stmt->execute([':u' => $user_id]);

    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[] = [
            'notif_id'   => (int)$r['notification_id'],
            'kind'       => notif_kind((string)$r['notification_type']),
            'type'       => $r['notification_type'],
            'title'      => $r['subject'],
            'body'       => $r['message'],
            'created_at' => $r['created_at'],
            'time_ago'   => time_ago($r['created_at']),
            'is_read'    => $hasRead ? !empty($r['read_at']) : false,
        ];
    }
    return $out;
}

/** Mark one notification read (only if it belongs to this manager). Returns rows affected. */
function notif_mark_read(PDO $db, int $user_id, int $notif_id): int {
    if ($notif_id <= 0 || !notif_has_read_column($db)) return 0;
    $stmt = $db->prepare("
        UPDATE notifications SET read_at = NOW()
        WHERE notification_id = :n AND recipient_user_id = :u AND read_at IS NULL
    ");
    $stmt->execute([':n' => $notif_id, ':u' => $user_id]);
    return $stmt->rowCount();
}

/** Mark every unread notification for this manager read. Returns rows affected. */
function notif_mark_all_read(PDO $db, int $user_id): int {
    if (!notif_has_read_column($db)) return 0;
    $stmt = $db->prepare("
        UPDATE notifications SET read_at = NOW()
        WHERE recipient_user_id = :u AND read_at IS NULL
    ");
    $stmt->execute([':u' => $user_id]);
    return $stmt->rowCount();
}

// ============================================================
//  Notification creation + routing (HQ reports, low-stock alerts)
// ============================================================

/**
 * Insert a notification row. Returns the new notification_id.
 * recipient_user_id OR recipient_email may be null (at least one should be set).
 */
function notif_create(
    PDO $db,
    ?int $recipient_user_id,
    ?string $recipient_email,
    string $type,
    string $subject,
    string $message,
    ?int $request_id = null
): int {
    $stmt = $db->prepare("
        INSERT INTO notifications
            (request_id, recipient_user_id, recipient_email, notification_type, subject, message, delivery_status)
        VALUES
            (:rid, :uid, :email, :type, :subj, :msg, 'not_configured')
    ");
    $stmt->execute([
        ':rid'   => $request_id,
        ':uid'   => $recipient_user_id,
        ':email' => $recipient_email,
        ':type'  => $type,
        ':subj'  => mb_substr($subject, 0, 255),
        ':msg'   => $message,
    ]);
    return (int)$db->lastInsertId();
}

/**
 * Resolve the HQ user(s) a branch manager should report to:
 * the HQ owner plus any HQ-role (role_id 3) users under the same headquarter(s).
 * Returns an array of user_ids (may be empty).
 */
function hq_recipient_ids(PDO $db, int $manager_user_id): array {
    $stmt = $db->prepare("
        SELECT DISTINCT headquarter_id
        FROM station_business_scope
        WHERE branch_manager_user_id = :m AND headquarter_id IS NOT NULL
    ");
    $stmt->execute([':m' => $manager_user_id]);
    $hqIds = array_values(array_filter(array_map('intval', array_column($stmt->fetchAll(), 'headquarter_id'))));
    if (!$hqIds) return [];

    $in = implode(',', array_fill(0, count($hqIds), '?'));
    $sql = "
        SELECT DISTINCT u.user_id
        FROM users u
        WHERE (
            u.headquarter_id IN ($in)
            AND (u.role_id = 3 OR u.current_role_id = 3 OR u.business_role_id = 3)
        )
        OR u.user_id IN (
            SELECT headquarters_owner_id FROM headquarter
            WHERE headquarter_id IN ($in) AND headquarters_owner_id IS NOT NULL
        )
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge($hqIds, $hqIds));
    return array_values(array_filter(array_map('intval', array_column($stmt->fetchAll(), 'user_id'))));
}

/**
 * Scan a branch's tanks and raise a low-stock notification for each fuel that
 * has dropped below its alert threshold. De-duplicated: an unread low-stock
 * notice for the same fuel raised within the last 6h is not repeated.
 * Returns the number of new notifications created.
 */
function notif_low_stock_scan(PDO $db, int $branch_id, int $recipient_user_id): int {
    $stmt = $db->prepare("
        SELECT fuel_name, current_l, capacity_l, alert_pct,
               ROUND(current_l / capacity_l * 100) AS pct
        FROM tanks
        WHERE branch_id = :b AND capacity_l > 0 AND is_enabled = 1
          AND (current_l / capacity_l * 100) < alert_pct
    ");
    $stmt->execute([':b' => $branch_id]);
    $low = $stmt->fetchAll();
    if (!$low) return 0;

    $hasRead = notif_has_read_column($db);
    $created = 0;

    foreach ($low as $t) {
        $subject = 'Low stock: ' . $t['fuel_name'];

        $dedupeSql = "SELECT COUNT(*) FROM notifications
                      WHERE recipient_user_id = :u
                        AND notification_type = 'stock_low'
                        AND subject = :subj
                        AND created_at > (NOW() - INTERVAL 6 HOUR)";
        if ($hasRead) $dedupeSql .= " AND read_at IS NULL";
        $d = $db->prepare($dedupeSql);
        $d->execute([':u' => $recipient_user_id, ':subj' => $subject]);
        if ((int)$d->fetchColumn() > 0) continue;

        $msg = sprintf(
            '%s is at %d%% (%s / %s L), below the %d%% alert level.',
            $t['fuel_name'], (int)$t['pct'],
            number_format((int)$t['current_l']), number_format((int)$t['capacity_l']),
            (int)$t['alert_pct']
        );
        notif_create($db, $recipient_user_id, null, 'stock_low', $subject, $msg);
        $created++;
    }
    return $created;
}
