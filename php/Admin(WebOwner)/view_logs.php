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
$displayLimit = 500;

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
    $metricSql = "SELECT COUNT(*) AS total, MAX(l.created_at) AS last
         FROM log l
         LEFT JOIN users u ON u.user_id = l.user_id
         LEFT JOIN roles r ON r.role_id = COALESCE(u.current_role_id, u.business_role_id, u.role_id)
         WHERE {$actorSql}";
    $metricQuery = $conn->query($metricSql);
    if ($metricQuery) {
        $metricRow = $metricQuery->fetch_assoc() ?: [];
        $metrics['total'] = (int) ($metricRow['total'] ?? 0);
        $metrics['last'] = $metricRow['last'] ?? null;
    }

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

    $sql = 'SELECT l.log_id, l.user_id, l.role_id AS log_role_id, l.action, l.created_at,
                   u.user_name, u.email,
                   COALESCE(r.role_name, \'\') AS actor_role
            FROM log l
            LEFT JOIN users u ON u.user_id = l.user_id
            LEFT JOIN roles r ON r.role_id = COALESCE(u.current_role_id, u.business_role_id, u.role_id)
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY l.created_at DESC, l.log_id DESC
            LIMIT ' . (int) $displayLimit;

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
                $metrics[$row['level']] = ($metrics[$row['level']] ?? 0) + 1;
                if ($level === 'all' || $row['level'] === $level) {
                    $logs[] = $row;
                }
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
        .terminal-panel {
            max-height: min(62vh, 560px);
        }
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
            <a class="btn btn-neon log-header-action d-flex align-items-center gap-2 js-export-logs" href="log_action.php?<?= hq_escape($exportQuery) ?>">
                <i class="bi bi-cloud-arrow-down-fill"></i> Export CSV
            </a>
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

    <div class="dashboard-card py-3 px-4 mb-4">
        <form class="row g-3 align-items-end" method="GET" action="view_logs.php">
            <div class="col-12 col-sm-6 col-xl-2">
                <label class="form-label text-white-50 small mb-1" for="logSearch">Search</label>
                <div class="input-group">
                    <span class="input-group-text bg-transparent border-end-0" style="border-color:var(--glass-border);color:var(--neon-cyan);border-radius:12px 0 0 12px;"><i class="bi bi-terminal"></i></span>
                    <input id="logSearch" type="search" class="form-control glass-input border-start-0" placeholder="Action, username, or email…" name="search" value="<?= hq_escape($search) ?>">
                </div>
            </div>
            <div class="col-6 col-xl-2">
                <label class="form-label text-white-50 small mb-1" for="logActor">Actor</label>
                <select id="logActor" class="form-select glass-select" name="actor">
                    <option value="all" <?= $actor === 'all' ? 'selected' : '' ?>>Admin &amp; Moderator</option>
                    <option value="admin" <?= $actor === 'admin' ? 'selected' : '' ?>>Admin only</option>
                    <option value="moderator" <?= $actor === 'moderator' ? 'selected' : '' ?>>Moderator only</option>
                </select>
            </div>
            <div class="col-6 col-xl-2">
                <label class="form-label text-white-50 small mb-1" for="logLevel">Level</label>
                <select id="logLevel" class="form-select glass-select" name="log_level">
                    <option value="all" <?= $level === 'all' ? 'selected' : '' ?>>All levels</option>
                    <?php foreach (['info' => 'INFO', 'warn' => 'WARN', 'error' => 'ERROR', 'security' => 'SECURITY'] as $value => $label): ?>
                        <option value="<?= $value ?>" <?= $level === $value ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-xl-2">
                <label class="form-label text-white-50 small mb-1" for="logDateFrom">From</label>
                <input id="logDateFrom" type="date" class="form-control glass-input" name="date_from" value="<?= hq_escape($dateFrom) ?>">
            </div>
            <div class="col-6 col-xl-2">
                <label class="form-label text-white-50 small mb-1" for="logDateTo">To</label>
                <input id="logDateTo" type="date" class="form-control glass-input" name="date_to" value="<?= hq_escape($dateTo) ?>">
            </div>
            <div class="col-12 col-xl-2 d-flex">
                <button type="button" class="btn btn-neon flex-grow-1 js-clear-filters" title="Clear filters">Clear</button>
            </div>
        </form>
    </div>

    <div class="analytics-card p-1">
        <div class="terminal-panel">
            <?php if (!$logs): ?>
                <div><span class="log-warn">[INFO]</span> No log entries match the current filters.</div>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                    <?php
                    $logClass = $log['level'] === 'security' ? 'log-sec' : 'log-' . $log['level'];
                    $actorName = (string) ($log['user_name'] ?? '');
                    $actorRole = (string) ($log['actor_role'] ?? '');
                    ?>
                    <div>
                        <span class="log-time">[<?= hq_escape(admin_date((string) $log['created_at'])) ?>]</span>
                        <span class="<?= hq_escape($logClass) ?>">[<?= strtoupper(hq_escape((string) $log['level'])) ?>]</span>
                        &nbsp;&nbsp;<?= hq_escape((string) $log['action']) ?>
                        <?php if ($actorName !== ''): ?>
                            <span class="txt-neon-cyan">(@<?= hq_escape($actorName) ?><?= $actorRole !== '' ? ' · ' . hq_escape($actorRole) : '' ?>)</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <div class="mt-2">
                <span class="txt-neon-cyan">&gt; Showing up to <?= (int) $displayLimit ?> newest matching rows. Scroll for more within this window.</span>
                <span class="term-cursor"></span>
            </div>
        </div>
    </div>
</main>

<footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map &amp; Pump | All Rights Reserved</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="/js/Admin.js"></script>
</body>
</html>
