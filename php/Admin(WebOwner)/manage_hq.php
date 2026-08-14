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
    <title>Manage HQ — Map &amp; Pump</title>
    <link rel="icon" type="image/png" href="/images/Logo.png"><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"><link rel="stylesheet" href="/css/Admin.css"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body class="background_color" id="background_color">
<?php $adminSection = 'manage_hq'; include __DIR__ . '/admin_section_nav.php'; ?>
<main class="container my-5">
    <?php if ($flash && $flashType): ?><div class="alert hq-flash alert-<?= hq_escape($flashType) ?> alert-dismissible fade show mb-4" role="alert"><i class="bi <?= $flashType === 'success' ? 'bi-check-circle-fill text-success' : 'bi-exclamation-triangle-fill text-danger' ?> me-2"></i><?= hq_escape((string) ($flash['message'] ?? '')) ?><button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button></div><?php endif; ?>
    <?php if ($dbError): ?><div class="alert hq-flash alert-danger mb-4"><i class="bi bi-database-exclamation me-2"></i><?= hq_escape($dbError) ?></div><?php endif; ?>
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3"><div><span class="stat-chip mb-2"><i class="bi bi-buildings-fill txt-neon-cyan"></i> HQ Administration</span><h1 class="fw-bold m-0 h4 text-white mt-2">Manage HQ</h1><p class="text-white-50 m-0 small mt-1">Register role 3 HQ accounts. View HQ is reserved for inspection and account actions.</p></div><button type="button" class="btn btn-neon d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#registerHQModal"><i class="bi bi-building-add"></i> Register New HQ</button></div>
    <div class="analytics-card"><div class="table-responsive"><table class="table table-dark-custom align-middle m-0"><thead><tr><th>HQ Account</th><th>Role</th><th>Owner</th><th>Location</th><th>Status</th></tr></thead><tbody>
    <?php if (!$hqRows): ?><tr><td colspan="5" class="text-center text-white-50 py-5">No role 3 HQ accounts found.</td></tr><?php endif; ?>
    <?php foreach ($hqRows as $row): ?><tr><td class="fw-semibold text-white"><?= hq_escape((string) $row['company_name']) ?></td><td class="text-white-50">#3 &middot; HQ</td><td class="text-white-50"><?= hq_escape((string) $row['owner_name']) ?><br><?= hq_escape((string) $row['headquarter_email']) ?></td><td class="text-white-50"><?= hq_escape((string) $row['location']) ?></td><td><span class="hq-status <?= hq_escape(hq_status_class((string) $row['account_status'])) ?>"><?= hq_escape(hq_status_label((string) $row['account_status'])) ?></span></td></tr><?php endforeach; ?>
    </tbody></table></div></div>
</main>
<div class="modal fade glass-modal" id="registerHQModal" tabindex="-1" aria-labelledby="registerHQLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title fw-bold" id="registerHQLabel"><i class="bi bi-building-add txt-neon-cyan me-2"></i> Register New Headquarter</h5><p class="text-white-50 small mb-0 mt-1">Create a role 3 HQ owner account and pending registration request.</p></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><form action="hq_register_action.php" method="POST"><div class="modal-body d-flex flex-column gap-3"><div class="small text-white-50"><span class="text-danger">*</span> All required fields must be completed.</div><div><label class="form-label text-white-50 small" for="adminHqName">Company / HQ Name <span class="text-danger">*</span></label><input id="adminHqName" type="text" name="hq_name" class="form-control glass-input" maxlength="255" required></div><div><label class="form-label text-white-50 small" for="adminHqLogo">Company Logo URL / Storage Path <span class="text-danger">*</span></label><input id="adminHqLogo" type="text" name="company_logo" class="form-control glass-input" maxlength="255" required></div><div class="row g-3"><div class="col-md-6"><label class="form-label text-white-50 small" for="adminOwnerName">Owner Name <span class="text-danger">*</span></label><input id="adminOwnerName" type="text" name="owner_name" class="form-control glass-input" maxlength="225" required></div><div class="col-md-6"><label class="form-label text-white-50 small" for="adminOwnerEmail">Owner Email <span class="text-danger">*</span></label><input id="adminOwnerEmail" type="email" name="owner_email" class="form-control glass-input" maxlength="225" required></div></div><div><label class="form-label text-white-50 small" for="adminContact">Contact Number <span class="text-danger">*</span></label><input id="adminContact" type="text" name="contact_number" class="form-control glass-input" maxlength="50" required></div><div><label class="form-label text-white-50 small" for="adminRegion">Region <span class="text-danger">*</span></label><select id="adminRegion" name="region" class="form-select glass-select" required><option value="">Select region</option><option value="yangon">Yangon Region</option><option value="mandalay">Mandalay Region</option><option value="naypyidaw">Naypyidaw</option></select></div><div><label class="form-label text-white-50 small" for="adminAddress">Physical Address <span class="text-danger">*</span></label><textarea id="adminAddress" name="address" rows="3" maxlength="220" class="form-control glass-input" required></textarea></div><div><label class="form-label text-white-50 small" for="adminLicense">Business License <span class="text-danger">*</span></label><input id="adminLicense" type="text" name="business_license" class="form-control glass-input" maxlength="255" required></div><div><label class="form-label text-white-50 small" for="adminExpire">License Expiry <span class="text-danger">*</span></label><input id="adminExpire" type="date" name="expire_at" min="<?= date('Y-m-d') ?>" class="form-control glass-input" required></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-3 text-white-50" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-neon">Register Account</button></div></form></div></div></div>
<footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map &amp; Pump | All Rights Reserved</footer><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script src="/js/Admin.js"></script>
</body>
</html>
