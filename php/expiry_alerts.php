<?php
/**
 * License / account expiry alerts for Admin + Moderator portals.
 *
 * Warning window: 0 ≤ days_left ≤ MAP_PUMP_EXPIRY_WARNING_DAYS (10).
 * Past deadline: auto-suspend related accounts (idempotent) + surface danger alerts.
 *
 * HQ source: headquarter.business_license_expire_date (with expire_at fallback)
 * User source: user_license.license_last_valid_date (driver license)
 */

if (!defined('MAP_PUMP_EXPIRY_WARNING_DAYS')) {
    define('MAP_PUMP_EXPIRY_WARNING_DAYS', 10);
}

/**
 * Days from today to $dateYmd (negative = already past).
 */
function expiry_days_left(?string $dateYmd): ?int
{
    if ($dateYmd === null || trim($dateYmd) === '') {
        return null;
    }
    $day = substr(trim($dateYmd), 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
        $ts = strtotime($dateYmd);
        if (!$ts) {
            return null;
        }
        $day = date('Y-m-d', $ts);
    }
    try {
        $today = new DateTimeImmutable('today');
        $expiry = new DateTimeImmutable($day);
        return (int) $today->diff($expiry)->format('%r%a');
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * @return 'ok'|'warning'|'expired'|'none'
 */
function expiry_bucket(?string $dateYmd): string
{
    $days = expiry_days_left($dateYmd);
    if ($days === null) {
        return 'none';
    }
    if ($days < 0) {
        return 'expired';
    }
    if ($days <= MAP_PUMP_EXPIRY_WARNING_DAYS) {
        return 'warning';
    }
    return 'ok';
}

function expiry_active_user_status_sql(string $column): string
{
    return "LOWER(TRIM({$column})) IN ('approved','active','enabled','ready')";
}

/**
 * Ensure mysqli $conn is available via hq helpers when possible.
 */
function expiry_db_ready(): bool
{
    if (function_exists('hq_db_available')) {
        return hq_db_available();
    }
    global $conn;
    return isset($conn) && $conn instanceof mysqli;
}

/**
 * Auto-suspend HQ owner accounts whose business license expired.
 * Idempotent: only transitions active/approved → suspended once.
 *
 * @return list<array{headquarter_id:int,company_name:string,owner_user_id:int,expire_at:string}>
 */
function expiry_sync_hq_expired(): array
{
    // HQ business-license expiry stays on the headquarter record and does not
    // suspend the owner user account. Access is restricted only to the HQ profile
    // until the license is renewed.
    return [];
}

/**
 * Auto-suspend customer users whose driver license expired.
 *
 * @return list<array{user_id:int,user_name:string,expire_at:string}>
 */
function expiry_sync_users_expired(): array
{
    global $conn;
    $changed = [];
    if (!expiry_db_ready()) {
        return $changed;
    }

    $sql = <<<'SQL'
SELECT u.user_id, u.user_name, u.status, l.license_last_valid_date, l.license_id
FROM users u
INNER JOIN roles base_role ON base_role.role_id = u.role_id
INNER JOIN user_license l ON l.license_id = u.license_id
WHERE u.role_id = 6
  AND LOWER(TRIM(base_role.role_name)) = 'customer'
  AND l.license_last_valid_date IS NOT NULL
  AND DATE(l.license_last_valid_date) < CURDATE()
  AND LOWER(TRIM(u.status)) IN ('approved','active','enabled','ready')
SQL;

    $result = $conn->query($sql);
    if (!$result) {
        return $changed;
    }

    while ($row = $result->fetch_assoc()) {
        $userId = (int) ($row['user_id'] ?? 0);
        $licenseId = (int) ($row['license_id'] ?? 0);
        if ($userId <= 0) {
            continue;
        }
        $expireDate = substr((string) ($row['license_last_valid_date'] ?? ''), 0, 10);
        $reason = 'Driver license expired on ' . $expireDate . '. Account suspended automatically.';

        $upd = $conn->prepare("UPDATE users SET status = 'suspended', updated_at = NOW() WHERE user_id = ? AND LOWER(TRIM(status)) IN ('approved','active','enabled','ready')");
        if ($upd) {
            $upd->bind_param('i', $userId);
            $upd->execute();
            $affected = $upd->affected_rows;
            $upd->close();
            if ($affected > 0) {
                if ($licenseId > 0) {
                    $lic = $conn->prepare("UPDATE user_license SET status = 'expired' WHERE license_id = ?");
                    if ($lic) {
                        $lic->bind_param('i', $licenseId);
                        $lic->execute();
                        $lic->close();
                    }
                }
                expiry_record_user_action($userId, 'suspend', $reason);
                if (function_exists('admin_record_log')) {
                    admin_record_log($userId, 'Auto-suspended user (driver license expired ' . $expireDate . ')');
                }
                $changed[] = [
                    'user_id' => $userId,
                    'user_name' => (string) ($row['user_name'] ?? 'User'),
                    'expire_at' => $expireDate,
                ];
            }
        }
    }

    return $changed;
}

function expiry_record_user_action(int $userId, string $actionType, string $reason): void
{
    global $conn;
    if ($userId <= 0 || !expiry_db_ready()) {
        return;
    }
    if (function_exists('user_action_table_ready') && !user_action_table_ready()) {
        return;
    }
    // Table may exist without helper loaded.
    $check = $conn->query("SHOW TABLES LIKE 'user_account_actions'");
    if (!$check || $check->num_rows === 0) {
        return;
    }
    $note = 'Automatic expiry enforcement';
    $status = 'system';
    $stmt = $conn->prepare(
        'INSERT INTO user_account_actions (user_id, action_type, reason, notification_status, notification_note)
         VALUES (?, ?, ?, ?, ?)'
    );
    if ($stmt) {
        $stmt->bind_param('issss', $userId, $actionType, $reason, $status, $note);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Run both syncs. Safe to call on dashboard / list loads.
 *
 * @return array{hq:list,users:list}
 */
function expiry_sync_all(): array
{
    return [
        'hq' => expiry_sync_hq_expired(),
        'users' => expiry_sync_users_expired(),
    ];
}

/**
 * HQ rows in warning or expired buckets (for lists / bell).
 *
 * @return list<array>
 */
function expiry_hq_attention_rows(string $bucket = 'any', int $limit = 50): array
{
    global $conn;
    $rows = [];
    if (!expiry_db_ready()) {
        return $rows;
    }

    $licenseExpirySql = "COALESCE(NULLIF(h.business_license_expire_date, ''), DATE(h.expire_at))";
    $whereExtra = '';
    if ($bucket === 'warning') {
        $whereExtra = ' AND DATE(' . $licenseExpirySql . ') BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ' . (int) MAP_PUMP_EXPIRY_WARNING_DAYS . ' DAY)';
    } elseif ($bucket === 'expired') {
        $whereExtra = ' AND DATE(' . $licenseExpirySql . ') < CURDATE()';
    } else {
        $whereExtra = ' AND DATE(' . $licenseExpirySql . ') <= DATE_ADD(CURDATE(), INTERVAL ' . (int) MAP_PUMP_EXPIRY_WARNING_DAYS . ' DAY)';
    }

    $sql = "
SELECT h.headquarter_id, h.company_name, {$licenseExpirySql} AS expire_at, h.location,
       owner.user_id AS owner_user_id, owner.user_name AS owner_name,
       COALESCE(owner.status, 'pending') AS account_status
FROM headquarter h
LEFT JOIN (
    SELECT bas.headquarter_id, MIN(u.user_id) AS owner_user_id
    FROM business_account_scope bas
    INNER JOIN users u ON u.user_id = bas.user_id
    WHERE u.role_id = 3 OR u.business_role_id = 3
    GROUP BY bas.headquarter_id
) owners ON owners.headquarter_id = h.headquarter_id
LEFT JOIN users owner ON owner.user_id = COALESCE(h.headquarters_owner_id, owners.owner_user_id)
WHERE {$licenseExpirySql} IS NOT NULL
{$whereExtra}
ORDER BY {$licenseExpirySql} ASC
LIMIT " . (int) $limit;

    $result = $conn->query($sql);
    if (!$result) {
        return $rows;
    }
    while ($row = $result->fetch_assoc()) {
        $expire = substr((string) ($row['expire_at'] ?? ''), 0, 10);
        $days = expiry_days_left($expire);
        $row['expire_date'] = $expire;
        $row['days_left'] = $days;
        $row['expiry_bucket'] = expiry_bucket($expire);
        $rows[] = $row;
    }
    return $rows;
}

/**
 * Customer users with driver license in warning/expired buckets.
 *
 * @return list<array>
 */
function expiry_user_attention_rows(string $bucket = 'any', int $limit = 50): array
{
    global $conn;
    $rows = [];
    if (!expiry_db_ready()) {
        return $rows;
    }

    $whereExtra = '';
    if ($bucket === 'warning') {
        $whereExtra = ' AND DATE(l.license_last_valid_date) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ' . (int) MAP_PUMP_EXPIRY_WARNING_DAYS . ' DAY)';
    } elseif ($bucket === 'expired') {
        $whereExtra = ' AND DATE(l.license_last_valid_date) < CURDATE()';
    } else {
        $whereExtra = ' AND DATE(l.license_last_valid_date) <= DATE_ADD(CURDATE(), INTERVAL ' . (int) MAP_PUMP_EXPIRY_WARNING_DAYS . ' DAY)';
    }

    $sql = "
SELECT u.user_id, u.user_name, u.email, u.status, l.license_last_valid_date, l.license_number
FROM users u
INNER JOIN roles base_role ON base_role.role_id = u.role_id
INNER JOIN user_license l ON l.license_id = u.license_id
WHERE u.role_id = 6
  AND LOWER(TRIM(base_role.role_name)) = 'customer'
  AND l.license_last_valid_date IS NOT NULL
{$whereExtra}
ORDER BY l.license_last_valid_date ASC
LIMIT " . (int) $limit;

    $result = $conn->query($sql);
    if (!$result) {
        return $rows;
    }
    while ($row = $result->fetch_assoc()) {
        $expire = substr((string) ($row['license_last_valid_date'] ?? ''), 0, 10);
        $days = expiry_days_left($expire);
        $row['expire_date'] = $expire;
        $row['days_left'] = $days;
        $row['expiry_bucket'] = expiry_bucket($expire);
        $rows[] = $row;
    }
    return $rows;
}

/**
 * Combined payload for banners + navbar bell.
 * Session-cached ~5 minutes unless $forceRefresh.
 *
 * @return array{
 *   warning_hq:int, warning_users:int, expired_hq:int, expired_users:int,
 *   total:int, items:list<array>, synced_hq:int, synced_users:int
 * }
 */
function expiry_alert_payload(bool $forceRefresh = false, bool $runSync = true): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }

    $cacheKey = 'map_pump_expiry_payload';
    $cacheAtKey = 'map_pump_expiry_payload_at';
    $ttl = 300;

    if (!$forceRefresh && isset($_SESSION[$cacheKey], $_SESSION[$cacheAtKey])) {
        if ((time() - (int) $_SESSION[$cacheAtKey]) < $ttl) {
            return $_SESSION[$cacheKey];
        }
    }

    $syncedHq = [];
    $syncedUsers = [];
    if ($runSync) {
        $sync = expiry_sync_all();
        $syncedHq = $sync['hq'];
        $syncedUsers = $sync['users'];
    }

    $hqWarning = expiry_hq_attention_rows('warning', 30);
    $hqExpired = expiry_hq_attention_rows('expired', 30);
    $userWarning = expiry_user_attention_rows('warning', 30);
    $userExpired = expiry_user_attention_rows('expired', 30);

    $items = [];
    foreach ($hqExpired as $row) {
        $items[] = [
            'kind' => 'hq',
            'bucket' => 'expired',
            'title' => (string) ($row['company_name'] ?? 'HQ'),
            'subtitle' => 'Business license expired ' . ($row['expire_date'] ?? '') . ' · renewal required',
            'url' => 'view_hq_and_user_account.php?expiry=expired&search=' . rawurlencode((string) ($row['company_name'] ?? '')),
            'url_mod' => 'view_hq_and_user_account(M).php?expiry=expired&search=' . rawurlencode((string) ($row['company_name'] ?? '')),
            'days_left' => $row['days_left'],
        ];
    }
    foreach ($userExpired as $row) {
        $items[] = [
            'kind' => 'user',
            'bucket' => 'expired',
            'title' => (string) ($row['user_name'] ?? 'User'),
            'subtitle' => 'Driver license expired ' . ($row['expire_date'] ?? '') . ' · account suspended',
            'url' => 'view_users.php?expiry=expired&search=' . rawurlencode((string) ($row['user_name'] ?? '')),
            'url_mod' => 'view_users(M).php?expiry=expired&search=' . rawurlencode((string) ($row['user_name'] ?? '')),
            'days_left' => $row['days_left'],
        ];
    }
    foreach ($hqWarning as $row) {
        $d = (int) ($row['days_left'] ?? 0);
        $items[] = [
            'kind' => 'hq',
            'bucket' => 'warning',
            'title' => (string) ($row['company_name'] ?? 'HQ'),
            'subtitle' => 'Business license expires ' . ($row['expire_date'] ?? '') . ' (' . $d . ' day' . ($d === 1 ? '' : 's') . ' left)',
            'url' => 'view_hq_and_user_account.php?expiry=warning&search=' . rawurlencode((string) ($row['company_name'] ?? '')),
            'url_mod' => 'view_hq_and_user_account(M).php?expiry=warning&search=' . rawurlencode((string) ($row['company_name'] ?? '')),
            'days_left' => $row['days_left'],
        ];
    }
    foreach ($userWarning as $row) {
        $d = (int) ($row['days_left'] ?? 0);
        $items[] = [
            'kind' => 'user',
            'bucket' => 'warning',
            'title' => (string) ($row['user_name'] ?? 'User'),
            'subtitle' => 'Driver license expires ' . ($row['expire_date'] ?? '') . ' (' . $d . ' day' . ($d === 1 ? '' : 's') . ' left)',
            'url' => 'view_users.php?expiry=warning&search=' . rawurlencode((string) ($row['user_name'] ?? '')),
            'url_mod' => 'view_users(M).php?expiry=warning&search=' . rawurlencode((string) ($row['user_name'] ?? '')),
            'days_left' => $row['days_left'],
        ];
    }

    $payload = [
        'warning_hq' => count($hqWarning),
        'warning_users' => count($userWarning),
        'expired_hq' => count($hqExpired),
        'expired_users' => count($userExpired),
        'total' => count($hqWarning) + count($userWarning) + count($hqExpired) + count($userExpired),
        'items' => array_slice($items, 0, 20),
        'synced_hq' => count($syncedHq),
        'synced_users' => count($syncedUsers),
        'warning_days' => MAP_PUMP_EXPIRY_WARNING_DAYS,
    ];

    $_SESSION[$cacheKey] = $payload;
    $_SESSION[$cacheAtKey] = time();

    return $payload;
}

/**
 * Render navbar bell dropdown (Admin or Moderator).
 *
 * @param 'admin'|'moderator' $portal
 */
function expiry_render_nav_bell(string $portal = 'admin'): void
{
    // Uses session cache; runs sync only on cache miss (see expiry_alert_payload).
    $payload = expiry_alert_payload(false, true);
    $total = (int) ($payload['total'] ?? 0);
    $items = $payload['items'] ?? [];
    $isMod = $portal === 'moderator';
    $hqAll = $isMod
        ? 'view_hq_and_user_account(M).php?expiry=any'
        : 'view_hq_and_user_account.php?expiry=any';
    $userAll = $isMod
        ? 'view_users(M).php?expiry=any'
        : 'view_users.php?expiry=any';
    ?>
    <div class="dropdown expiry-bell-wrap">
        <button class="btn btn-sm expiry-bell-btn" type="button" id="expiryBellBtn" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" title="License expiry alerts" aria-label="License expiry notifications">
            <i class="bi bi-bell<?= $total > 0 ? '-fill' : '' ?>"></i>
            <?php if ($total > 0): ?>
                <span class="expiry-bell-badge"><?= $total > 99 ? '99+' : (int) $total ?></span>
            <?php endif; ?>
        </button>
        <div class="dropdown-menu dropdown-menu-end expiry-bell-menu p-0" aria-labelledby="expiryBellBtn">
            <div class="expiry-bell-header px-3 py-2">
                <div class="fw-semibold text-white small">License deadlines</div>
                <div class="text-white-50" style="font-size:.72rem;">Within <?= (int) MAP_PUMP_EXPIRY_WARNING_DAYS ?> days or expired</div>
            </div>
            <div class="expiry-bell-body">
                <?php if (!$items): ?>
                    <div class="px-3 py-4 text-center text-white-50 small">
                        <i class="bi bi-check-circle d-block mb-1" style="font-size:1.25rem;color:var(--neon-cyan);"></i>
                        No license deadlines need attention.
                    </div>
                <?php else: ?>
                    <?php foreach ($items as $item):
                        $href = $isMod ? ($item['url_mod'] ?? $item['url']) : ($item['url'] ?? '#');
                        $isExpired = ($item['bucket'] ?? '') === 'expired';
                        ?>
                        <a class="expiry-bell-item" href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>">
                            <span class="expiry-bell-dot <?= $isExpired ? 'is-expired' : 'is-warning' ?>"></span>
                            <span class="min-width-0">
                                <span class="d-block text-white small fw-semibold text-truncate"><?= htmlspecialchars((string) $item['title'], ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="d-block text-white-50 text-truncate" style="font-size:.72rem;"><?= htmlspecialchars((string) $item['subtitle'], ENT_QUOTES, 'UTF-8') ?></span>
                            </span>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="expiry-bell-footer px-2 py-2 d-flex gap-1">
                <a class="btn btn-sm btn-outline-secondary text-white-50 border-secondary border-opacity-25 flex-fill" href="<?= htmlspecialchars($hqAll, ENT_QUOTES, 'UTF-8') ?>">HQ</a>
                <a class="btn btn-sm btn-outline-secondary text-white-50 border-secondary border-opacity-25 flex-fill" href="<?= htmlspecialchars($userAll, ENT_QUOTES, 'UTF-8') ?>">Users</a>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Bootstrap banners for dashboard / list pages.
 *
 * @param 'admin'|'moderator' $portal
 */
function expiry_render_page_banners(string $portal = 'admin', bool $runSync = true): void
{
    $payload = expiry_alert_payload(false, $runSync);
    $wHq = (int) ($payload['warning_hq'] ?? 0);
    $wUser = (int) ($payload['warning_users'] ?? 0);
    $eHq = (int) ($payload['expired_hq'] ?? 0);
    $eUser = (int) ($payload['expired_users'] ?? 0);
    $synced = (int) ($payload['synced_hq'] ?? 0) + (int) ($payload['synced_users'] ?? 0);
    $isMod = $portal === 'moderator';
    $hqWarnUrl = $isMod ? 'view_hq_and_user_account(M).php?expiry=warning' : 'view_hq_and_user_account.php?expiry=warning';
    $userWarnUrl = $isMod ? 'view_users(M).php?expiry=warning' : 'view_users.php?expiry=warning';
    $hqExpUrl = $isMod ? 'view_hq_and_user_account(M).php?expiry=expired' : 'view_hq_and_user_account.php?expiry=expired';
    $userExpUrl = $isMod ? 'view_users(M).php?expiry=expired' : 'view_users.php?expiry=expired';

    if ($synced > 0): ?>
        <div class="alert hq-flash alert-danger alert-dismissible fade show mb-3" role="alert">
            <i class="bi bi-x-circle-fill me-2"></i>
            <?= (int) $synced ?> account<?= $synced === 1 ? '' : 's' ?> suspended automatically because a license deadline passed.
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif;

    if ($eHq > 0 || $eUser > 0): ?>
        <div class="alert hq-flash alert-danger alert-dismissible fade show mb-3" role="alert">
            <i class="bi bi-exclamation-octagon-fill me-2"></i>
            License deadline passed:
            <?php if ($eHq > 0): ?><a href="<?= htmlspecialchars($hqExpUrl, ENT_QUOTES, 'UTF-8') ?>" class="alert-link"><?= $eHq ?> <?= $isMod ? ($eHq === 1 ? 'company' : 'companies') : 'Headquarter' ?></a><?php endif; ?>
            <?php if ($eHq > 0 && $eUser > 0): ?> and <?php endif; ?>
            <?php if ($eUser > 0): ?><a href="<?= htmlspecialchars($userExpUrl, ENT_QUOTES, 'UTF-8') ?>" class="alert-link"><?= $eUser ?> driver<?= $eUser === 1 ? '' : 's' ?></a><?php endif; ?>
            — review and renew the required documents immediately.
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif;

    if ($wHq > 0 || $wUser > 0): ?>
        <div class="alert hq-flash alert-warning alert-dismissible fade show mb-3" role="status">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            License deadlines approaching (within <?= (int) MAP_PUMP_EXPIRY_WARNING_DAYS ?> days):
            <?php if ($wHq > 0): ?><a href="<?= htmlspecialchars($hqWarnUrl, ENT_QUOTES, 'UTF-8') ?>" class="alert-link"><?= $wHq ?> <?= $isMod ? ($wHq === 1 ? 'company' : 'companies') : 'Headquarter' ?></a><?php endif; ?>
            <?php if ($wHq > 0 && $wUser > 0): ?> and <?php endif; ?>
            <?php if ($wUser > 0): ?><a href="<?= htmlspecialchars($userWarnUrl, ENT_QUOTES, 'UTF-8') ?>" class="alert-link"><?= $wUser ?> driver<?= $wUser === 1 ? '' : 's' ?></a><?php endif; ?>.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif;
}

/**
 * Small chip HTML for a table cell.
 */
function expiry_badge_html(?string $dateYmd): string
{
    $bucket = expiry_bucket($dateYmd);
    if ($bucket === 'none' || $bucket === 'ok') {
        return '';
    }
    $days = expiry_days_left($dateYmd);
    if ($bucket === 'expired') {
        return '<span class="expiry-chip is-expired" title="License expired"><i class="bi bi-x-circle-fill"></i> Expired</span>';
    }
    $d = (int) $days;
    $label = $d === 0 ? 'Expires today' : ('Expires in ' . $d . 'd');
    return '<span class="expiry-chip is-warning" title="License expires soon"><i class="bi bi-clock-fill"></i> ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
}

/**
 * SQL fragment for HQ list expiry filter (on the supplied license-date column).
 */
function expiry_hq_filter_sql(string $expiryParam, string $column = 'h.expire_at'): string
{
    $expiryParam = strtolower(trim($expiryParam));
    $days = (int) MAP_PUMP_EXPIRY_WARNING_DAYS;
    return match ($expiryParam) {
        'warning' => "({$column} IS NOT NULL AND DATE({$column}) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL {$days} DAY))",
        'expired' => "({$column} IS NOT NULL AND DATE({$column}) < CURDATE())",
        'any' => "({$column} IS NOT NULL AND DATE({$column}) <= DATE_ADD(CURDATE(), INTERVAL {$days} DAY))",
        default => '',
    };
}

/**
 * SQL fragment for user list expiry filter (on license date column).
 */
function expiry_user_filter_sql(string $expiryParam, string $column = 'l.license_last_valid_date'): string
{
    return expiry_hq_filter_sql($expiryParam, $column);
}


/**
 * Visible expiry filter <select> for list forms.
 */
function expiry_filter_select_html(string $selected = '', string $id = 'expiryFilter', string $escapeFn = 'hq_escape'): string
{
    $esc = static function (string $v) use ($escapeFn): string {
        if ($escapeFn !== '' && function_exists($escapeFn)) {
            return $escapeFn($v);
        }
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    };
    $selected = strtolower(trim($selected));
    $opts = [
        '' => 'All license dates',
        'warning' => 'Expiring within ' . (int) MAP_PUMP_EXPIRY_WARNING_DAYS . ' days',
        'expired' => 'Expired',
        'any' => 'Expiring or expired',
    ];
    $html = '<label class="form-label small mb-1 filter-label" for="' . $esc($id) . '">License expiry</label>';
    $html .= '<div class="filter-select-wrap"><select id="' . $esc($id) . '" class="form-select filter-control filter-select" name="expiry" aria-label="License expiry filter">';
    foreach ($opts as $value => $label) {
        $sel = ($selected === $value) ? ' selected' : '';
        $html .= '<option value="' . $esc((string) $value) . '"' . $sel . '>' . $esc($label) . '</option>';
    }
    $html .= '</select></div>';
    return $html;
}

/**
 * HTML alert block for detail modals.
 */
function expiry_detail_alert_html(?string $dateYmd, string $kind = 'license'): string
{
    $bucket = expiry_bucket($dateYmd);
    if ($bucket === 'none' || $bucket === 'ok') {
        return '';
    }
    $days = expiry_days_left($dateYmd);
    $label = $kind === 'hq' ? 'Business license' : 'Driver license';
    $date = substr((string) $dateYmd, 0, 10);
    if ($bucket === 'expired') {
        if ($kind === 'hq') {
            return '<div class="alert alert-danger py-2 mb-3" role="alert"><i class="bi bi-x-circle-fill me-2"></i>'
                . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
                . ' expired on ' . htmlspecialchars($date, ENT_QUOTES, 'UTF-8')
                . '. HQ access is limited to the profile until the business license is renewed.</div>';
        }
        return '<div class="alert alert-danger py-2 mb-3" role="alert"><i class="bi bi-x-circle-fill me-2"></i>'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
            . ' expired on ' . htmlspecialchars($date, ENT_QUOTES, 'UTF-8')
            . '. Related account should be suspended until the license is renewed.</div>';
    }
    $d = (int) $days;
    $when = $d === 0 ? 'today' : ('in ' . $d . ' day' . ($d === 1 ? '' : 's'));
    return '<div class="alert alert-warning py-2 mb-3" role="status"><i class="bi bi-exclamation-triangle-fill me-2"></i>'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
        . ' expires ' . $when . ' (' . htmlspecialchars($date, ENT_QUOTES, 'UTF-8') . '). Renew before the deadline to avoid automatic suspension.</div>';
}

/**
 * Client-side helper for detail modals (window days = MAP_PUMP_EXPIRY_WARNING_DAYS).
 */
function expiry_js_detail_helper(): string
{
    $days = (int) MAP_PUMP_EXPIRY_WARNING_DAYS;
    return <<<JS
function expiryDetailAlert(dateStr, kind) {
    if (!dateStr) return '';
    const day = String(dateStr).slice(0, 10);
    if (!/^\\d{4}-\\d{2}-\\d{2}\$/.test(day)) return '';
    const today = new Date(); today.setHours(0,0,0,0);
    const exp = new Date(day + 'T00:00:00');
    const diff = Math.round((exp - today) / 86400000);
    const label = kind === 'hq' ? 'Business license' : 'Driver license';
    if (diff < 0) {
        if (kind === 'hq') {
            return '<div class="alert alert-danger py-2 mb-3" role="alert"><i class="bi bi-x-circle-fill me-2"></i>' + label + ' expired on ' + day + '. HQ access is limited to the profile until the business license is renewed.</div>';
        }
        return '<div class="alert alert-danger py-2 mb-3" role="alert"><i class="bi bi-x-circle-fill me-2"></i>' + label + ' expired on ' + day + '. Related account should be suspended until the license is renewed.</div>';
    }
    if (diff <= {$days}) {
        const when = diff === 0 ? 'today' : ('in ' + diff + ' day' + (diff === 1 ? '' : 's'));
        return '<div class="alert alert-warning py-2 mb-3" role="status"><i class="bi bi-exclamation-triangle-fill me-2"></i>' + label + ' expires ' + when + ' (' + day + '). Renew before the deadline to avoid automatic suspension.</div>';
    }
    return '';
}
JS;
}

/**
 * Invalidate session cache after cron or forced sync.
 */
function expiry_clear_payload_cache(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    unset($_SESSION['map_pump_expiry_payload'], $_SESSION['map_pump_expiry_payload_at']);
}
