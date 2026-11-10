<?php
require_once __DIR__ . '/admin_common.php';

admin_require_admin();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$flash = admin_pull_flash();
$search = trim((string) ($_GET['search'] ?? ''));
$level = strtolower(trim((string) ($_GET['log_level'] ?? 'all')));
$actor = strtolower(trim((string) ($_GET['actor'] ?? 'all')));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));

$allowedLevels = ['all', 'info', 'warn', 'error', 'security'];
$allowedActors = ['all', 'admin', 'moderator'];
if (!in_array($level, $allowedLevels, true)) {
    $level = 'all';
}
if (!in_array($actor, $allowedActors, true)) {
    $actor = 'all';
}

// Validate optional date filters (Y-m-d).
$fromOk = $dateFrom === '' || (bool) DateTime::createFromFormat('!Y-m-d', $dateFrom);
$toOk = $dateTo === '' || (bool) DateTime::createFromFormat('!Y-m-d', $dateTo);
if (!$fromOk) {
    $dateFrom = '';
}
if (!$toOk) {
    $dateTo = '';
}
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$logs = [];
$metrics = ['total' => 0, 'security' => 0, 'warn' => 0, 'error' => 0, 'info' => 0, 'last' => null];
$dbError = null;
$pageSize = 12;
$page = max(1, (int) ($_GET['page'] ?? 1));
$totalEntries = 0;
$totalPages = 1;
$startRow = 0;
$endRow = 0;

// Actor scopes for Admin audit trail: Admin + Moderator only (never Branch Manager / HQ / Customer).
$adminActorSql = '(l.role_id = 1 OR (l.role_id IS NULL AND (COALESCE(u.current_role_id, u.business_role_id, u.role_id) = 1 OR LOWER(TRIM(COALESCE(r.role_name, \'\'))) = \'admin\')))';
$moderatorActorSql = '(l.role_id = 2 OR (l.role_id IS NULL AND (COALESCE(u.current_role_id, u.business_role_id, u.role_id) = 2 OR LOWER(TRIM(COALESCE(r.role_name, \'\'))) = \'moderator\')))';
$actorSql = match ($actor) {
    'admin' => $adminActorSql,
    'moderator' => $moderatorActorSql,
    // "all" = Admin + Moderator only (not the rest of the system).
    default => '(' . $adminActorSql . ' OR ' . $moderatorActorSql . ')',
};

if (hq_db_available()) {
    $where = [$actorSql];
    $params = [];
    $types = '';

    if ($search !== '') {
        $where[] = '(LOWER(l.action) LIKE ? OR LOWER(COALESCE(u.user_name, \'\')) LIKE ? OR LOWER(COALESCE(u.email, \'\')) LIKE ?)';
        $like = '%' . strtolower($search) . '%';
        array_push($params, $like, $like, $like);
        $types .= 'sss';
    }
    if ($dateFrom !== '') {
        $where[] = 'DATE(l.created_at) >= ?';
        $params[] = $dateFrom;
        $types .= 's';
    }
    if ($dateTo !== '') {
        $where[] = 'DATE(l.created_at) <= ?';
        $params[] = $dateTo;
        $types .= 's';
    }

    $actionValueSql = "LOWER(COALESCE(l.action, ''))";
    $securityPattern = 'security|auth|login|password|permission|suspend|remove|approve|reject';
    $errorPattern = 'error|failed|exception|fatal';
    $warnPattern = 'warn|warning|latency|expired';
    $securityLevelSql = "{$actionValueSql} REGEXP '{$securityPattern}'";
    $errorLevelSql = "{$actionValueSql} REGEXP '{$errorPattern}'";
    $warnLevelSql = "{$actionValueSql} REGEXP '{$warnPattern}'";
    $levelSql = match ($level) {
        'security' => $securityLevelSql,
        'error' => "NOT ({$securityLevelSql}) AND ({$errorLevelSql})",
        'warn' => "NOT ({$securityLevelSql}) AND NOT ({$errorLevelSql}) AND ({$warnLevelSql})",
        'info' => "NOT ({$securityLevelSql}) AND NOT ({$errorLevelSql}) AND NOT ({$warnLevelSql})",
        default => '',
    };
    if ($levelSql !== '') {
        $where[] = '(' . $levelSql . ')';
    }

    // Keep every summary card in the same actor, search, date, and level scope
    // as the log rows; the count is independent of the current page.
    $whereSql = implode(' AND ', $where);
    $metricSql = "SELECT COUNT(*) AS total, MAX(l.created_at) AS last,
            COALESCE(SUM(CASE WHEN LOWER(COALESCE(l.action, '')) REGEXP 'security|auth|login|password|permission|suspend|remove|approve|reject' THEN 1 ELSE 0 END), 0) AS security,
            COALESCE(SUM(CASE WHEN LOWER(COALESCE(l.action, '')) REGEXP 'security|auth|login|password|permission|suspend|remove|approve|reject' THEN 0
                              WHEN LOWER(COALESCE(l.action, '')) REGEXP 'error|failed|exception|fatal' THEN 1 ELSE 0 END), 0) AS error,
            COALESCE(SUM(CASE WHEN LOWER(COALESCE(l.action, '')) REGEXP 'security|auth|login|password|permission|suspend|remove|approve|reject|error|failed|exception|fatal' THEN 0
                              WHEN LOWER(COALESCE(l.action, '')) REGEXP 'warn|warning|latency|expired' THEN 1 ELSE 0 END), 0) AS warn,
            COALESCE(SUM(CASE WHEN LOWER(COALESCE(l.action, '')) REGEXP 'security|auth|login|password|permission|suspend|remove|approve|reject|error|failed|exception|fatal|warn|warning|latency|expired' THEN 0 ELSE 1 END), 0) AS info
         FROM log l
         LEFT JOIN users u ON u.user_id = l.user_id
         LEFT JOIN roles r ON r.role_id = COALESCE(u.current_role_id, u.business_role_id, u.role_id)
         WHERE {$whereSql}";
    $metricQuery = $conn->prepare($metricSql);
    if ($metricQuery) {
        if ($types !== '') {
            $bound = array_values($params);
            hq_bind($metricQuery, $types, $bound);
        }
        if ($metricQuery->execute()) {
            $metricRow = $metricQuery->get_result()?->fetch_assoc() ?: [];
            foreach (['total', 'security', 'warn', 'error', 'info'] as $key) {
                $metrics[$key] = (int) ($metricRow[$key] ?? 0);
            }
            $metrics['last'] = $metricRow['last'] ?? null;
        } else {
            $dbError = 'The audit summary could not be loaded.';
        }
        $metricQuery->close();
    } else {
        $dbError = 'The audit summary could not be prepared.';
    }

    $totalEntries = (int) $metrics['total'];
    $totalPages = max(1, (int) ceil($totalEntries / $pageSize));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $pageSize;
    $startRow = $totalEntries > 0 ? $offset + 1 : 0;
    $endRow = min($offset + $pageSize, $totalEntries);

    $sql = 'SELECT l.log_id, l.user_id, l.role_id AS log_role_id, l.action, l.created_at,
                   u.user_name, u.email,
                   COALESCE(r.role_name, \'\') AS actor_role
            FROM log l
            LEFT JOIN users u ON u.user_id = l.user_id
            LEFT JOIN roles r ON r.role_id = COALESCE(u.current_role_id, u.business_role_id, u.role_id)
            WHERE ' . $whereSql . '
            ORDER BY l.created_at DESC, l.log_id DESC
            LIMIT ' . (int) $pageSize . ' OFFSET ' . (int) $offset;

    $query = $conn->prepare($sql);
    if ($query) {
        if ($types !== '') {
            $bound = array_values($params);
            hq_bind($query, $types, $bound);
        }
        if ($query->execute()) {
            $result = $query->get_result();
            while ($result && ($row = $result->fetch_assoc())) {
                $row['level'] = admin_log_level((string) $row['action']);
                $logs[] = $row;
            }
        } else {
            $dbError = 'The audit log could not be loaded.';
        }
        $query->close();
    } else {
        $dbError = 'The audit log could not be prepared.';
    }
} else {
    $dbError = 'The log database is temporarily unavailable.';
}

$flashType = (string) ($flash['type'] ?? '');
$exportQuery = http_build_query([
    'action' => 'export',
    'search' => $search,
    'log_level' => $level,
    'actor' => $actor,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
]);
$pageUrl = static function (int $targetPage) use ($search, $level, $actor, $dateFrom, $dateTo): string {
    return 'view_logs.php?' . http_build_query([
        'search' => $search,
        'log_level' => $level,
        'actor' => $actor,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'page' => $targetPage,
    ]);
};
$paginationPages = [];
if ($totalPages <= 5) {
    $paginationPages = range(1, $totalPages);
} else {
    $paginationPages[] = 1;
    $windowStart = max(2, $page - 1);
    $windowEnd = min($totalPages - 1, $page + 1);
    if ($windowStart > 2) {
        $paginationPages[] = 'ellipsis';
    }
    for ($number = $windowStart; $number <= $windowEnd; $number++) {
        $paginationPages[] = $number;
    }
    if ($windowEnd < $totalPages - 1) {
        $paginationPages[] = 'ellipsis';
    }
    $paginationPages[] = $totalPages;
}
$formatLogEntry = static function (array $log): array {
    $action = trim((string) ($log['action'] ?? ''));
    $actorName = trim((string) ($log['user_name'] ?? ''));
    if ($actorName !== '' && stripos($action, $actorName . ' ') === 0) {
        $action = substr($action, strlen($actorName) + 1);
    }

    $targets = [];
    if (preg_match_all('/#(\d+)(?:\s*\(([^)]*)\))?/u', $action, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $target = '#' . $match[1];
            if (!empty($match[2])) {
                $target .= ' · ' . trim($match[2]);
            }
            $targets[] = $target;
        }
        $action = preg_replace('/\s*#\d+(?:\s*\([^)]*\))?/u', '', $action) ?? $action;
    }
    $action = preg_replace('/\s+as\s+[^.]+\.?$/i', '', $action) ?? $action;
    $action = preg_replace('/\s+for record\s*\.?$/i', '', $action) ?? $action;
    $action = trim($action, " \t\n\r\0\x0B.");

    $timestamp = strtotime((string) ($log['created_at'] ?? ''));
    $roleId = (int) ($log['log_role_id'] ?? 0);
    $role = match ($roleId) {
        1 => 'Admin',
        2 => 'Moderator',
        default => trim((string) ($log['actor_role'] ?? '')),
    };
    if ($role !== '' && $roleId !== 1 && $roleId !== 2) {
        $role = ucwords(strtolower($role));
    }

    return [
        'action' => $action !== '' ? $action : 'Activity recorded',
        'target' => $targets ? implode(' · ', $targets) : '',
        'date' => $timestamp !== false ? date('M j, Y', $timestamp) : 'Unknown date',
        'time' => $timestamp !== false ? date('g:i A', $timestamp) : '',
        'actor' => $actorName !== '' ? '@' . $actorName : '@Unknown actor',
        'role' => $role !== '' ? $role : 'Staff',
    ];
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Map &amp; Pump Admin — Database-backed audit logs.">
    <title>View Log Files — Map &amp; Pump</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="icon" type="image/png" href="/images/Logo.png">
    <link rel="stylesheet" href="/css/Admin.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .log-flash { border-radius:14px; background:rgba(10,22,44,.82); border:1px solid var(--glass-border); color:var(--text-primary); }
        body.background_color.light-mode .log-flash { background:rgba(255,255,255,.92); }
    </style>
</head>
<body class="background_color" id="background_color">
<?php $adminSection = 'logs'; include __DIR__ . '/admin_section_nav.php'; ?>

<main class="container my-5">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <span class="stat-chip mb-2"><i class="bi bi-terminal-split txt-neon-cyan"></i> System Audit Trail</span>
            <h1 class="fw-bold m-0 h4 text-white mt-2">View Log Files</h1>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <form id="clearLogsForm" action="log_action.php" method="POST" class="m-0" onsubmit="return confirm(this.dataset.confirmMessage || 'Clear audit entries matching the current filters? This cannot be undone.');">
                <input type="hidden" name="action" value="clear">
                <input type="hidden" name="actor" value="<?= hq_escape($actor) ?>">
                <input type="hidden" name="search" value="<?= hq_escape($search) ?>">
                <input type="hidden" name="log_level" value="<?= hq_escape($level) ?>">
                <input type="hidden" name="date_from" value="<?= hq_escape($dateFrom) ?>">
                <input type="hidden" name="date_to" value="<?= hq_escape($dateTo) ?>">
                <input type="hidden" name="return_to" value="view_logs.php">
                <button type="submit" class="btn btn-clear-logs log-header-action rounded-3 d-flex align-items-center gap-2">
                    <i class="bi bi-trash3-fill"></i> Clear Filtered Logs
                </button>
            </form>
        </div>
    </div>

    <?php if ($flash && $flashType !== ''): ?>
        <div class="alert log-flash alert-<?= hq_escape($flashType) ?> alert-dismissible fade show mb-4" role="alert">
            <i class="bi <?= $flashType === 'success' ? 'bi-check-circle-fill text-success' : 'bi-exclamation-triangle-fill text-danger' ?> me-2"></i><?= hq_escape((string) ($flash['message'] ?? '')) ?>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if ($dbError): ?>
        <div class="alert log-flash alert-danger mb-4" role="alert"><i class="bi bi-database-exclamation me-2"></i><?= hq_escape($dbError) ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-md-3">
            <div class="dashboard-card metric-card">
                <p class="mb-1 metric-label">Entries in scope</p>
                <h4 class="fw-bold text-white mb-0"><?= (int) $metrics['total'] ?></h4>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-md-3">
            <div class="dashboard-card metric-card">
                <p class="mb-1 metric-label">Security</p>
                <h4 class="fw-bold text-white mb-0"><?= (int) ($metrics['security'] ?? 0) ?></h4>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-md-3">
            <div class="dashboard-card metric-card">
                <p class="mb-1 metric-label">Warn / Error</p>
                <h4 class="fw-bold text-white mb-0"><?= (int) (($metrics['warn'] ?? 0) + ($metrics['error'] ?? 0)) ?></h4>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-md-3">
            <div class="dashboard-card metric-card">
                <p class="mb-1 metric-label">Last event</p>
                <h4 class="fw-bold text-white mb-0" style="font-size:1.05rem;"><?= hq_escape($metrics['last'] ? admin_date((string) $metrics['last']) : 'No entries') ?></h4>
            </div>
        </div>
    </div>

    <div class="dashboard-card admin-filter-card mb-4">
        <form class="activity-log-filter-form" method="GET" action="view_logs.php">
            <div class="activity-search-row">
                <label class="filter-label" for="logSearch">Search</label>
                <div class="filter-search">
                    <i class="bi bi-search filter-search-icon" aria-hidden="true"></i>
                    <input id="logSearch" type="search" class="form-control filter-control filter-input" placeholder="Search action, username, or email" name="search" value="<?= hq_escape($search) ?>">
                </div>
            </div>
            <div class="activity-log-filter-row">
                <div class="filter-group">
                    <label class="filter-label" for="logActor">Actor</label>
                    <div class="filter-select-wrap"><select id="logActor" class="form-select filter-control filter-select" name="actor">
                        <option value="all" <?= $actor === 'all' ? 'selected' : '' ?>>Admin &amp; Moderator</option>
                        <option value="admin" <?= $actor === 'admin' ? 'selected' : '' ?>>Admin only</option>
                        <option value="moderator" <?= $actor === 'moderator' ? 'selected' : '' ?>>Moderator only</option>
                    </select></div>
                </div>
                <div class="filter-group">
                    <label class="filter-label" for="logLevel">Level</label>
                    <div class="filter-select-wrap"><select id="logLevel" class="form-select filter-control filter-select" name="log_level">
                        <option value="all" <?= $level === 'all' ? 'selected' : '' ?>>All levels</option>
                        <?php foreach (['info' => 'INFO', 'warn' => 'WARN', 'error' => 'ERROR', 'security' => 'SECURITY'] as $value => $label): ?>
                            <option value="<?= $value ?>" <?= $level === $value ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select></div>
                </div>
                <div class="filter-group">
                    <label class="filter-label" for="logDateFrom">From</label>
                    <input id="logDateFrom" type="date" class="form-control filter-control filter-input" name="date_from" value="<?= hq_escape($dateFrom) ?>">
                </div>
                <div class="filter-group">
                    <label class="filter-label" for="logDateTo">To</label>
                    <input id="logDateTo" type="date" class="form-control filter-control filter-input" name="date_to" value="<?= hq_escape($dateTo) ?>">
                </div>
                <button type="button" class="btn-clear-filters js-clear-filters" title="Clear filters">✕ Clear filters</button>
            </div>
        </form>
    </div>

    <div class="analytics-card activity-log-card">
        <div class="activity-log-header">
            <div>
                <h2 class="activity-log-title">Activity log</h2>
                <p class="activity-log-subtitle">Showing <?= (int) $startRow ?>–<?= (int) $endRow ?> of <?= (int) $totalEntries ?> entries</p>
            </div>
            <a class="btn activity-export-btn js-export-logs" href="log_action.php?<?= hq_escape($exportQuery) ?>"><i class="bi bi-download me-1" aria-hidden="true"></i>Export CSV</a>
        </div>
        <div class="table-responsive activity-log-table-wrap">
            <table class="table table-dark-custom activity-log-table align-middle m-0">
                <thead><tr><th scope="col">Time</th><th scope="col">Level</th><th scope="col">Action</th><th scope="col">Target</th><th scope="col">Actor</th></tr></thead>
                <tbody>
                <?php if (!$logs): ?>
                    <tr><td class="activity-log-empty" colspan="5">No log entries match the current filters.</td></tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <?php
                        $entry = $formatLogEntry($log);
                        $levelName = (string) $log['level'];
                        $levelClass = in_array($levelName, ['security', 'info', 'warn', 'error'], true) ? $levelName : 'info';
                        ?>
                        <tr>
                            <td><span class="activity-log-date"><?= hq_escape($entry['date']) ?></span><span class="activity-log-time"><?= hq_escape($entry['time']) ?></span></td>
                            <td><span class="activity-level-badge activity-level-<?= hq_escape($levelClass) ?>"><span class="activity-level-dot" aria-hidden="true"></span><?= hq_escape(ucfirst($levelName)) ?></span></td>
                            <td class="activity-log-action"><?= hq_escape($entry['action']) ?></td>
                            <td>
                                <?php if ($entry['target'] !== ''): ?>
                                    <span class="activity-log-target"><code><?= hq_escape($entry['target']) ?></code><button type="button" class="activity-copy-btn" data-copy-text="<?= hq_escape($entry['target']) ?>" aria-label="Copy target <?= hq_escape($entry['target']) ?>">Copy</button></span>
                                <?php else: ?>
                                    <span class="activity-log-no-target">—</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="activity-log-actor"><?= hq_escape($entry['actor']) ?></span><span class="activity-log-role"><?= hq_escape($entry['role']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="activity-log-footer">
            <span class="activity-log-rows">Rows <?= (int) $startRow ?>–<?= (int) $endRow ?></span>
            <nav class="activity-pagination" aria-label="Activity log pages">
                <a class="activity-page-link<?= $page <= 1 ? ' is-disabled' : '' ?>" href="<?= hq_escape($pageUrl(max(1, $page - 1))) ?>"<?= $page <= 1 ? ' aria-disabled="true" tabindex="-1"' : '' ?>>&lt; Prev</a>
                <?php foreach ($paginationPages as $paginationPage): ?>
                    <?php if ($paginationPage === 'ellipsis'): ?>
                        <span class="activity-page-ellipsis" aria-hidden="true">…</span>
                    <?php else: ?>
                        <?php $pageNumber = (int) $paginationPage; ?>
                        <a class="activity-page-link<?= $pageNumber === $page ? ' is-current' : '' ?>" href="<?= hq_escape($pageUrl($pageNumber)) ?>"<?= $pageNumber === $page ? ' aria-current="page"' : '' ?>><?= $pageNumber ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
                <a class="activity-page-link<?= $page >= $totalPages ? ' is-disabled' : '' ?>" href="<?= hq_escape($pageUrl(min($totalPages, $page + 1))) ?>"<?= $page >= $totalPages ? ' aria-disabled="true" tabindex="-1"' : '' ?>>Next &gt;</a>
            </nav>
        </div>
    </div>
</main>

<footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map &amp; Pump | All Rights Reserved</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="/js/Admin.js"></script>
</body>
</html>
