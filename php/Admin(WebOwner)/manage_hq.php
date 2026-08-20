<?php
require_once __DIR__ . '/hq_account_common.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$flash = hq_pull_flash();
$hqRows = [];
if (hq_db_available() && hq_registry_schema_ready()) {
    $result = $conn->query(
        "SELECT h.headquarter_id, h.company_name, h.location, h.headquarter_email,
                h.created_at, h.expire_at, owner.user_name AS owner_name,
                owner.status AS account_status
         FROM headquarter h
         INNER JOIN (
             SELECT u.headquarter_id, MIN(u.user_id) AS owner_user_id
             FROM users u INNER JOIN roles r ON r.role_id = u.role_id
             WHERE r.role_id = 3 AND LOWER(TRIM(r.role_name)) = 'hq'
             GROUP BY u.headquarter_id
         ) owners ON owners.headquarter_id = h.headquarter_id
         INNER JOIN users owner ON owner.user_id = owners.owner_user_id
         ORDER BY h.created_at DESC, h.headquarter_id DESC"
    );
    while ($result && ($row = $result->fetch_assoc())) {
        $hqRows[] = $row;
    }
}
$dbError = hq_db_available() ? null : hq_db_error_message();
$flashType = (string) ($flash['type'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Manage role 3 HQ accounts in the Map &amp; Pump admin portal.">
    <title>Manage HQ â€” Map &amp; Pump</title>
    <link rel="icon" type="image/png" href="/images/Logo.png"><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"><link rel="stylesheet" href="/css/Admin.css"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body class="background_color" id="background_color">
<?php $adminSection = 'manage_hq'; include __DIR__ . '/admin_section_nav.php'; ?>
<main class="container my-5">
    <?php if ($flash && $flashType): ?><div class="alert hq-flash alert-<?= hq_escape($flashType) ?> alert-dismissible fade show mb-4" role="alert"><i class="bi <?= $flashType === 'success' ? 'bi-check-circle-fill text-success' : 'bi-exclamation-triangle-fill text-danger' ?> me-2"></i><?= hq_escape((string) ($flash['message'] ?? '')) ?><button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button></div><?php endif; ?>
    <?php if ($dbError): ?><div class="alert hq-flash alert-danger mb-4"><i class="bi bi-database-exclamation me-2"></i><?= hq_escape($dbError) ?></div><?php endif; ?>
    <div class="mb-4"><span class="stat-chip mb-2"><i class="bi bi-buildings-fill txt-neon-cyan"></i> HQ Administration</span><h1 class="fw-bold m-0 h4 text-white mt-2">Manage HQ</h1><p class="text-white-50 m-0 small mt-1">Review existing role 3 HQ accounts. Use View HQ for account actions.</p></div>
    <div class="analytics-card"><div class="table-responsive"><table class="table table-dark-custom align-middle m-0"><thead><tr><th>HQ Account</th><th>Role</th><th>Owner</th><th>Location</th><th>Status</th></tr></thead><tbody>
    <?php if (!$hqRows): ?><tr><td colspan="5" class="text-center text-white-50 py-5">No role 3 HQ accounts found.</td></tr><?php endif; ?>
    <?php foreach ($hqRows as $row): ?><tr><td class="fw-semibold text-white"><?= hq_escape((string) $row['company_name']) ?></td><td class="text-white-50">#3 &middot; HQ</td><td class="text-white-50"><?= hq_escape((string) $row['owner_name']) ?><br><?= hq_escape((string) $row['headquarter_email']) ?></td><td class="text-white-50"><?= hq_escape((string) $row['location']) ?></td><td><span class="hq-status <?= hq_escape(hq_status_class((string) $row['account_status'])) ?>"><?= hq_escape(hq_status_label((string) $row['account_status'])) ?></span></td></tr><?php endforeach; ?>
    </tbody></table></div></div>
</main>
<footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map &amp; Pump | All Rights Reserved</footer><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script src="/js/Admin.js"></script>
</body>
</html>
