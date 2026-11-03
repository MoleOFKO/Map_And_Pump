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
function require_branch_manager(bool $allowUnassigned = false): void {
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

    if (!$allowUnassigned) {
        global $pdo;
        $stmt = $pdo->prepare(
            'SELECT 1
             FROM branches
             WHERE manager_user_id = :user_id
             LIMIT 1'
        );
        $userId = (int) $_SESSION['user_id'];
        $stmt->execute([':user_id' => $userId]);
        if (!$stmt->fetchColumn()) {
            header('Location: /php/BranchManager/unassigned.php');
            exit;
        }
    }
}

// -------- Load current signed-in user + their branch --------
// Returns ['user' => [...], 'branch' => [...]] or nulls if no session yet.
function current_context($db = null) {
    global $pdo, $conn;
    $dbHandle = $db ?? $pdo ?? $conn;

    $stmt = $dbHandle->prepare(
        "SELECT u.user_id,
                b.branch_id AS branch_id,
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
         LEFT JOIN branches b ON b.manager_user_id = u.user_id
         LEFT JOIN station_business_scope sbs
           ON sbs.station_id = b.branch_id
          AND sbs.branch_manager_user_id = u.user_id
          AND sbs.headquarter_id IS NOT NULL
         LEFT JOIN headquarter hq ON hq.headquarter_id = sbs.headquarter_id
         WHERE u.user_id = :uid
           AND b.manager_user_id = u.user_id
         LIMIT 1"
    );
    $stmt->execute([':uid' => $_SESSION['user_id']]);
    $row = $stmt->fetch();
    if (!$row) return ['user' => null, 'branch' => null];

    $user = [
        'user_id'    => $row['user_id'],
        'branch_id'  => $row['branch_id'],
        'full_name'  => $row['full_name'],
        'email'      => $row['email'],
        'avatar_url' => $row['avatar_url'],
        'phone'      => $row['phone'],
        'nrc'        => $row['nrc'],
        'role'       => $row['role'],
        'branch_name'=> $row['branch_name'],
        'branch_code'=> $row['branch_code'],
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

/** Create a notification row for a user or email target. */
function notif_create(PDO $db, ?int $recipientUserId, ?string $recipientEmail, string $type, string $subject, string $message, ?int $requestId = null, ?string $deliveryStatus = 'not_configured', ?string $deliveryNote = null): int {
    $sql = "
        INSERT INTO notifications
            (request_id, recipient_user_id, recipient_email, notification_type, subject, message, delivery_status, delivery_note, created_at)
        VALUES
            (:request_id, :recipient_user_id, :recipient_email, :notification_type, :subject, :message, :delivery_status, :delivery_note, NOW())
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':request_id' => $requestId,
        ':recipient_user_id' => $recipientUserId,
        ':recipient_email' => $recipientEmail !== null && trim((string)$recipientEmail) !== '' ? trim((string)$recipientEmail) : null,
        ':notification_type' => $type,
        ':subject' => $subject,
        ':message' => $message,
        ':delivery_status' => $deliveryStatus ?? 'not_configured',
        ':delivery_note' => $deliveryNote,
    ]);
    return (int) $db->lastInsertId();
}

/** Resolve HQ recipient user IDs for a branch manager's HQ. */
function hq_recipient_ids(PDO $db, int $managerUserId): array {
    $managerUserId = (int) $managerUserId;
    if ($managerUserId <= 0) {
        return [];
    }

    $hqStmt = $db->prepare(
        "SELECT sbs.headquarter_id
         FROM station_business_scope sbs
         WHERE sbs.branch_manager_user_id = :uid
           AND sbs.headquarter_id IS NOT NULL
         ORDER BY sbs.headquarter_id ASC
         LIMIT 1"
    );
    $hqStmt->execute([':uid' => $managerUserId]);
    $hqId = (int) $hqStmt->fetchColumn();

    if ($hqId <= 0) {
        $userStmt = $db->prepare(
            "SELECT headquarter_id
             FROM users
             WHERE user_id = :uid
               AND headquarter_id IS NOT NULL
             LIMIT 1"
        );
        $userStmt->execute([':uid' => $managerUserId]);
        $hqId = (int) $userStmt->fetchColumn();
    }

    if ($hqId <= 0) {
        return [];
    }

    $stmt = $db->prepare(
        "SELECT DISTINCT u.user_id
         FROM users u
         LEFT JOIN roles r ON r.role_id = COALESCE(u.current_role_id, u.business_role_id, u.role_id)
         WHERE u.headquarter_id = :hq_id
           AND (
                LOWER(COALESCE(r.role_name, '')) IN ('hq', 'headquarter', 'headquarters', 'company admin')
                OR u.user_id = (
                    SELECT h.headquarters_owner_id
                    FROM headquarter h
                    WHERE h.headquarter_id = :hq_id
                    LIMIT 1
                )
           )
         ORDER BY u.user_id ASC"
    );
    $stmt->execute([':hq_id' => $hqId]);
    $ids = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN, 0) as $uid) {
        $ids[] = (int) $uid;
    }
    return $ids;
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
    if (preg_match('/(staff_invitation_declined|declin|reject)/', $t)) return 'warn';
    if (preg_match('/(staff_invitation_accepted|accept|approve|success|complete|added|received)/', $t)) return 'ok';
    if (preg_match('/(staff_invitation_sent|staff_invitation|invite)/', $t)) return 'info';
    if (preg_match('/(down|fail|error|danger|reject|leak|critical)/', $t)) return 'err';
    if (preg_match('/(low|warn|alert|expir|maint)/', $t))                  return 'warn';
    if (preg_match('/(receiv|deliver|approve|success|complete|added)/', $t)) return 'ok';
    return 'info';
}

/** Resolve the most relevant management page for a notification type. */
function notif_route_for(string $type): string {
    $t = strtolower($type);
    if (preg_match('/(staff_invitation|invite|invitation)/', $t)) return 'manage-staff.php';
    if (preg_match('/(stock|tank|fuel|inventory|low)/', $t)) return 'manage-oil.php';
    if (preg_match('/(report|hq|headquarter|branch report)/', $t)) return 'report-hq.php';
    if (preg_match('/(staff|employee|account|attendance)/', $t)) return 'manage-staff.php';
    if (preg_match('/(booking|queue|pump|slot|token)/', $t)) return 'booking-slot.php';
    return 'dashboard.php';
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
 * Only staff invitation lifecycle events are shown: invite, accept, decline.
 */
function notif_recent(PDO $db, int $user_id, int $limit = 5): array {
    $hasRead    = notif_has_read_column($db);
    $readSelect = $hasRead ? 'read_at' : 'NULL AS read_at';
    $limit      = max(1, min(50, $limit));

    $allowedTypes = [
        'staff_invitation_sent',
        'staff_invitation_accepted',
        'staff_invitation_declined',
    ];
    $typePlaceholders = implode(', ', array_fill(0, count($allowedTypes), '?'));

    $sql = "
        SELECT notification_id, notification_type, subject, message, created_at, {$readSelect}
        FROM notifications
        WHERE recipient_user_id = ?
          AND notification_type IN ($typePlaceholders)
        ORDER BY created_at DESC
        LIMIT {$limit}
    ";
    $stmt = $db->prepare($sql);
    $params = [$user_id];
    foreach ($allowedTypes as $type) {
        $params[] = $type;
    }
    $stmt->execute($params);

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
            'link'       => notif_route_for((string)$r['notification_type']),
        ];
    }
    return $out;
}

/** Map notification metadata to compact UI icon + color family. */
function notif_ui_meta(string $type, string $title = ''): array {
    $src = strtolower(trim($type . ' ' . $title));

    if (preg_match('/(staff_invitation_sent|invite|invitation)/', $src)) {
        return ['icon' => 'fa-user-plus', 'tone' => 'info'];
    }
    if (preg_match('/(staff_invitation_accepted|accept|approved|joined)/', $src)) {
        return ['icon' => 'fa-user-check', 'tone' => 'success'];
    }
    if (preg_match('/(staff_invitation_declined|declined|decline|rejected)/', $src)) {
        return ['icon' => 'fa-user-slash', 'tone' => 'warning'];
    }
    if (preg_match('/(staff|employee|user|manager|attendance|activation|deactivation)/', $src)) {
        return ['icon' => 'fa-users', 'tone' => 'success'];
    }
    if (preg_match('/(stock|fuel|oil|tank|inventory|delivery|low stock|restock)/', $src)) {
        return ['icon' => 'fa-droplet', 'tone' => 'warning'];
    }
    if (preg_match('/(booking|queue|token|slot|pump|service)/', $src)) {
        return ['icon' => 'fa-ticket', 'tone' => 'info'];
    }
    if (preg_match('/(report|hq|headquarter|incident|complaint|review)/', $src)) {
        return ['icon' => 'fa-file-lines', 'tone' => 'info'];
    }
    if (preg_match('/(branch|profile|update|system|account|maintenance)/', $src)) {
        return ['icon' => 'fa-building', 'tone' => 'error'];
    }
    if (preg_match('/(success|approved|complete|delivered|received|created)/', $src)) {
        return ['icon' => 'fa-circle-check', 'tone' => 'success'];
    }
    if (preg_match('/(warn|warning|low|alert|expir|fail|issue|problem)/', $src)) {
        return ['icon' => 'fa-triangle-exclamation', 'tone' => 'warning'];
    }

    return ['icon' => 'fa-circle-info', 'tone' => 'info'];
}

/** Create or reuse a pending staff status notification for a specific employee. */
function staff_status_notification_create(PDO $db, int $recipientUserId, int $staffId, string $action, ?string $recipientEmail = null): int {
    $action = strtolower(trim($action));
    if (!in_array($action, ['activate', 'disable'], true)) {
        throw new InvalidArgumentException('Unsupported staff status action.');
    }

    $isActivation = $action === 'activate';
    $type = $isActivation ? 'staff_account_reactivated' : 'staff_account_disabled';
    $subject = $isActivation ? 'Staff account reactivated' : 'Staff account disabled';
    $message = $isActivation
        ? 'Your Branch Manager has reactivated your staff account. You can now access your previous staff account again.'
        : 'Your Branch Manager has disabled your staff account.';

    $recipientEmail = trim((string) ($recipientEmail ?? ''));
    error_log(sprintf(
        '[staff_status_notification_create] recipient_user_id=%d staff_id=%d action=%s type=%s',
        $recipientUserId,
        $staffId,
        $action,
        $type
    ));

    $insertSql = "
        INSERT INTO notifications
            (request_id, recipient_user_id, recipient_email, notification_type, subject, message, delivery_status, created_at)
        VALUES
            (:request_id, :recipient_user_id, :recipient_email, :notification_type, :subject, :message, 'pending', NOW())";

    $stmt = $db->prepare($insertSql);
    try {
        $stmt->execute([
            ':request_id' => $staffId,
            ':recipient_user_id' => $recipientUserId,
            ':recipient_email' => $recipientEmail !== '' ? $recipientEmail : null,
            ':notification_type' => $type,
            ':subject' => $subject,
            ':message' => $message,
        ]);
        $insertedId = (int) $db->lastInsertId();
        error_log(sprintf(
            '[staff_status_notification_create] insert_succeeded notification_id=%d recipient_user_id=%d staff_id=%d action=%s type=%s',
            $insertedId,
            $recipientUserId,
            $staffId,
            $action,
            $type
        ));
        return $insertedId;
    } catch (Throwable $e) {
        error_log(sprintf(
            '[staff_status_notification_create] insert_failed recipient_user_id=%d staff_id=%d action=%s type=%s exception=%s',
            $recipientUserId,
            $staffId,
            $action,
            $type,
            $e->getMessage()
        ));
        throw $e;
    }
}

/** Pending staff status notifications for one user. Retrieval is read-only; it never marks read_at. */
function staff_status_pending_for_user_pdo(PDO $db, int $userId): array {
    if ($userId <= 0) {
        return [];
    }

    $types = ['staff_account_disabled', 'staff_account_reactivated'];
    $typeList = implode(', ', array_fill(0, count($types), '?'));

    $sql = "
        SELECT notification_id, recipient_user_id, request_id, notification_type, subject, message, delivery_status, created_at
        FROM notifications
        WHERE recipient_user_id = ?
          AND notification_type IN ($typeList)";

    if (notif_has_read_column($db)) {
        $sql .= ' AND read_at IS NULL';
    }

    $sql .= ' ORDER BY created_at DESC, notification_id DESC';

    $params = [$userId];
    foreach ($types as $type) {
        $params[] = $type;
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $results = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $results[] = [
            'notification_id' => (int) ($row['notification_id'] ?? 0),
            'recipient_user_id' => (int) ($row['recipient_user_id'] ?? 0),
            'staff_id' => (int) ($row['request_id'] ?? 0),
            'notification_type' => (string) ($row['notification_type'] ?? ''),
            'subject' => (string) ($row['subject'] ?? ''),
            'message' => (string) ($row['message'] ?? ''),
            'delivery_status' => (string) ($row['delivery_status'] ?? 'pending'),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'is_read' => false,
        ];
    }

    return $results;
}

/** Fetch only the current user's pending staff status notifications; retrieval does not set read_at. */
function staff_status_pending_for_current_user(PDO $db, int $userId): array {
    if ($userId <= 0) {
        return [];
    }

    return staff_status_pending_for_user_pdo($db, $userId);
}

function staff_status_popup_type_from_events(array $events): ?string {
    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }
        $eventType = strtolower(trim((string) ($event['notification_type'] ?? '')));
        if ($eventType === 'staff_account_disabled') {
            return 'disabled';
        }
        if ($eventType === 'staff_account_reactivated') {
            return 'activated';
        }
    }
    return null;
}

function staff_status_store_pending_in_session(array $events): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $_SESSION['pending_staff_status_events'] = array_values(array_filter(
        $events,
        static fn ($row) => is_array($row) && (int) ($row['notification_id'] ?? 0) > 0
    ));
}

function staff_status_store_pending_in_session_for_user(PDO $db, int $userId): ?string {
    if ($userId <= 0) {
        return null;
    }

    $events = staff_status_pending_for_current_user($db, $userId);
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $_SESSION['pending_staff_status_events'] = $events;
    $popupType = staff_status_popup_type_from_events($events);
    if ($popupType !== null) {
        $_SESSION['staff_status_popup_type'] = $popupType;
        return $popupType;
    }

    unset($_SESSION['staff_status_popup_type']);
    return null;
}

function staff_status_session_events(): array {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $rows = $_SESSION['pending_staff_status_events'] ?? [];
    return is_array($rows) ? array_values($rows) : [];
}

/** Explicitly acknowledge only the current user's own staff-status notification. */
function staff_status_notification_acknowledge(PDO $db, int $userId, int $notificationId): bool {
    if ($userId <= 0 || $notificationId <= 0) {
        return false;
    }

    $sql = "
        UPDATE notifications
        SET read_at = NOW()
        WHERE notification_id = :notification_id
          AND recipient_user_id = :recipient_user_id
          AND notification_type IN ('staff_account_disabled', 'staff_account_reactivated')
          AND read_at IS NULL";

    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':notification_id' => $notificationId,
        ':recipient_user_id' => $userId,
    ]);

    return $stmt->rowCount() === 1;
}