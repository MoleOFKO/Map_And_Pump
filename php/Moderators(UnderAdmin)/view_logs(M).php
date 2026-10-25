<?php
require_once __DIR__ . '/moderator_common.php';

$moderatorUser = moderator_require_user();
$flash = moderator_pull_flash();
if (!$flash) {
    $flash = admin_pull_flash();
}

$search = trim((string) ($_GET['search'] ?? ''));
$level = strtolower(trim((string) ($_GET['log_level'] ?? 'all')));
$allowedLevels = ['all', 'info', 'warn', 'error', 'security'];
$level = in_array($level, $allowedLevels, true) ? $level : 'all';
$logs = [];
$metrics = ['total' => 0, 'security' => 0, 'last' => null];
$dbError = null;
$customerAndHqLogScope = "(
    (l.role_id IS NOT NULL AND EXISTS (
        SELECT 1 FROM roles log_role
        WHERE log_role.role_id = l.role_id
          AND LOWER(TRIM(log_role.role_name)) IN ('customer', 'hq', 'headquarter', 'headquarters')
    ))
    OR (l.role_id IS NULL AND EXISTS (
        SELECT 1 FROM roles actor_role
        WHERE actor_role.role_id = COALESCE(u.current_role_id, u.business_role_id, u.role_id)
          AND LOWER(TRIM(actor_role.role_name)) IN ('customer', 'hq', 'headquarter', 'headquarters')
    ))
)";

if (hq_db_available()) {
    $metricQuery = $conn->prepare(
        "SELECT COUNT(*) AS total, MAX(l.created_at) AS last
         FROM log l
         LEFT JOIN users u ON u.user_id = l.user_id
         WHERE {$customerAndHqLogScope}"
    );
    if ($metricQuery && $metricQuery->execute() && ($metricRow = $metricQuery->get_result()->fetch_assoc())) {
        $metrics['total'] = (int) ($metricRow['total'] ?? 0);
        $metrics['last'] = $metricRow['last'] ?? null;
    }
    $metricQuery?->close();
    $securityQuery = $conn->prepare(
        "SELECT l.action
         FROM log l
         LEFT JOIN users u ON u.user_id = l.user_id
         WHERE {$customerAndHqLogScope}"
    );
    $securityQuery?->execute();
    $securityResult = $securityQuery?->get_result();
    while ($securityResult && ($securityRow = $securityResult->fetch_assoc())) {
        if (admin_log_level((string) $securityRow['action']) === 'security') {
            $metrics['security']++;
        }
    }
    $securityQuery?->close();

    $query = $conn->prepare(
        'SELECT l.log_id, l.user_id, l.action, l.created_at, u.user_name, u.email
         FROM log l
         LEFT JOIN users u ON u.user_id = l.user_id
         WHERE ' . $customerAndHqLogScope . '
           AND (LOWER(l.action) LIKE ? OR LOWER(COALESCE(u.user_name, \'\')) LIKE ?)
         ORDER BY l.created_at DESC, l.log_id DESC'
    );
    if ($query) {
        $like = '%' . strtolower($search) . '%';
        $query->bind_param('ss', $like, $like);
        if ($query->execute()) {
            $result = $query->get_result();
            while ($result && ($row = $result->fetch_assoc())) {
                $row['level'] = admin_log_level((string) $row['action']);
                if ($level === 'all' || $level === $row['level']) {
                    $logs[] = $row;
                }
            }
        }
        $query->close();
    }
} else {
    $dbError = hq_db_error_message();
}

$flashType = (string) ($flash['type'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Logs — Map &amp; Pump</title>
    <link rel="icon" type="image/png" href="/images/Logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&amp;display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="/css/Admin.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .mod-flash{border-radius:14px;background:rgba(10,22,44,.82);border:1px solid var(--glass-border);color:var(--text-primary)}
        body.background_color.light-mode .mod-flash{background:rgba(255,255,255,.92)}
        .mod-log-note{border-radius:12px;border:1px solid rgba(0,244,255,.2);background:rgba(0,244,255,.06);padding:.65rem 1rem}
        body.background_color.light-mode .mod-log-note{background:rgba(11,94,215,.06);border-color:rgba(11,94,215,.2)}
    </style>
</head>
<body class="background_color" id="background_color">
<?php include __DIR__ . '/includes/moderator_navbar.php'; ?>
<main class="container my-5">
    <?php if ($flash && $flashType): ?><div class="alert mod-flash alert-<?= moderator_escape($flashType) ?> mb-4"><i class="bi <?= $flashType === 'success' ? 'bi-check-circle-fill text-success' : 'bi-exclamation-triangle-fill text-danger' ?> me-2"></i><?= moderator_escape((string) ($flash['message'] ?? '')) ?></div><?php endif; ?>
    <?php if ($dbError): ?><div class="alert mod-flash alert-danger mb-4"><i class="bi bi-database-exclamation me-2"></i><?= moderator_escape($dbError) ?></div><?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div><span class="stat-chip mb-2"><i class="bi bi-terminal-split txt-neon-cyan"></i> Account Activity</span><h1 class="fw-bold m-0 h4 text-white mt-2">View Log Files</h1><p class="text-white-50 m-0 small mt-1">Inspect activity from customer and HQ accounts only.</p></div>
        <div class="mod-log-note text-white-50 small align-self-center"><i class="bi bi-eye me-1 txt-neon-cyan"></i>View only — Moderators can read logs. Clear, export, and any log changes are restricted to administrators.</div>
    </div>

    <div class="row g-4 mb-4"><div class="col-12 col-sm-4"><div class="dashboard-card metric-card"><p class="mb-1 metric-label">HQ &amp; Customer Entries</p><h4 class="fw-bold text-white mb-0"><?= $metrics['total'] ?></h4><small class="text-white-50">Scoped live log records</small></div></div><div class="col-12 col-sm-4"><div class="dashboard-card metric-card"><p class="mb-1 metric-label">Security Entries</p><h4 class="fw-bold text-white mb-0"><?= $metrics['security'] ?></h4><small class="text-white-50">Detected from scoped action text</small></div></div><div class="col-12 col-sm-4"><div class="dashboard-card metric-card"><p class="mb-1 metric-label">Last Event</p><h4 class="fw-bold text-white mb-0" style="font-size:1.05rem"><?= moderator_escape($metrics['last'] ? moderator_format_date((string) $metrics['last']) : 'No entries') ?></h4><small class="text-white-50">Latest HQ or customer timestamp</small></div></div></div>

    <div class="dashboard-card py-3 px-4 mb-4"><form class="row g-3 align-items-center" method="GET" action="view_logs(M).php"><div class="col-12 col-md-5"><div class="input-group"><span class="input-group-text bg-transparent border-end-0" style="border-color:var(--glass-border);color:var(--neon-cyan)"><i class="bi bi-terminal"></i></span><input type="search" class="form-control glass-input border-start-0" placeholder="Search action or username..." name="search" value="<?= moderator_escape($search) ?>"></div></div><div class="col-12 col-md-4"><select class="form-select glass-select" name="log_level"><option value="all">All Debug &amp; Status Levels</option><?php foreach (array_slice($allowedLevels, 1) as $option): ?><option value="<?= moderator_escape($option) ?>" <?= $level === $option ? 'selected' : '' ?>><?= strtoupper(moderator_escape($option)) ?> Outputs</option><?php endforeach; ?></select></div><div class="col-12 col-md-3 d-flex gap-2"><button type="button" class="btn btn-neon flex-grow-1 js-clear-filters" title="Clear filters">Clear</button></div></form></div>

    <div class="analytics-card p-1"><div class="terminal-panel"><?php if (!$logs): ?><div><span class="log-warn">[INFO]</span> No log entries match the current filters.</div><?php else: ?><?php foreach ($logs as $log): ?><?php $class = $log['level'] === 'security' ? 'log-sec' : 'log-' . $log['level']; ?><div><span class="log-time">[<?= moderator_escape((string) $log['created_at']) ?>]</span> <span class="<?= moderator_escape($class) ?>">[<?= strtoupper(moderator_escape((string) $log['level'])) ?>]</span>&nbsp;&nbsp;<?= moderator_escape((string) $log['action']) ?><?php if (!empty($log['user_name'])): ?> <span class="txt-neon-cyan">(@<?= moderator_escape((string) $log['user_name']) ?>)</span><?php endif; ?></div><?php endforeach; ?><?php endif; ?><div class="mt-2"><span class="txt-neon-cyan">&gt; Database audit scan complete.</span><span class="term-cursor"></span></div></div></div>
</main>
<?php $moderatorActiveNav = 'logs'; include __DIR__ . '/includes/moderator_offcanvas.php'; ?><footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map &amp; Pump | All Rights Reserved</footer><?php include __DIR__ . '/includes/moderator_footer_scripts.php'; ?>
</body>
</html>
