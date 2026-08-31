<?php
require_once __DIR__ . '/hq_account_common.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$flash = hq_pull_flash();
$hqRows = [];
if (hq_db_available() && hq_registry_schema_ready()) {
    $hqRoleId = hq_hq_role_id();
    if ($hqRoleId > 0) {
        $stmt = $conn->prepare(
           "SELECT h.headquarter_id, h.company_name, h.location, h.headquarter_email,
                   h.created_at, h.expire_at, owner.user_name AS owner_name,
                   owner.status AS account_status
             FROM headquarter h
             INNER JOIN (
               SELECT u.headquarter_id, MIN(u.user_id) AS owner_user_id
                 FROM users u
                 WHERE (u.role_id = 3 OR u.business_role_id = 3)
                 GROUP BY u.headquarter_id
             ) owners ON owners.headquarter_id = h.headquarter_id
             INNER JOIN users owner ON owner.user_id = owners.owner_user_id
             ORDER BY h.created_at DESC, h.headquarter_id DESC"
        );
        if ($stmt) {
           if ($stmt->execute()) {
               $result = $stmt->get_result();
               while ($result && ($row = $result->fetch_assoc())) {
                   $hqRows[] = $row;
               }
           }
           $stmt->close();
        }
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
    <div class="mb-4"><span class="stat-chip mb-2"><i class="bi bi-buildings-fill txt-neon-cyan"></i> HQ Administration</span><h1 class="fw-bold m-0 h4 text-white mt-2">Manage HQ</h1><p class="text-white-50 m-0 small mt-1">Review and manage role 3 HQ accounts directly from this page.</p></div>
    <div class="analytics-card"><div class="table-responsive"><table class="table table-dark-custom align-middle m-0"><thead><tr><th>HQ Account</th><th>Role</th><th>Owner</th><th>Location</th><th>Status</th><th class="text-end">Command</th></tr></thead><tbody>
    <?php if (!$hqRows): ?><tr><td colspan="5" class="text-center text-white-50 py-5">No role 3 HQ accounts found.</td></tr><?php endif; ?>
    <?php foreach ($hqRows as $row): ?><tr><td class="fw-semibold text-white"><?= hq_escape((string) $row['company_name']) ?></td><td class="text-white-50">#3 &middot; HQ</td><td class="text-white-50"><?= hq_escape((string) $row['owner_name']) ?><br><?= hq_escape((string) $row['headquarter_email']) ?></td><td class="text-white-50"><?= hq_escape((string) $row['location']) ?></td><td><span class="hq-status <?= hq_escape(hq_status_class((string) $row['account_status'])) ?>"><?= hq_escape(hq_status_label((string) $row['account_status'])) ?></span></td><td class="text-end"><div class="d-flex gap-2 justify-content-end flex-wrap"><div class="dropdown"><button type="button" class="btn-action dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="View details"><span class="me-1"><i class="bi bi-eye-fill"></i></span>View details</button><ul class="dropdown-menu dropdown-menu-end"><?php $accountStatus = strtolower((string) ($row['account_status'] ?? 'pending')); if ($accountStatus === 'pending') { ?><li><form method="POST" action="hq_account_action.php" class="d-inline"><input type="hidden" name="headquarter_id" value="<?= (int) $row['headquarter_id'] ?>"><input type="hidden" name="action_type" value="approve"><input type="hidden" name="reason" value="Approved after HQ review."><input type="hidden" name="return_to" value="manage_hq.php"><button type="submit" class="dropdown-item text-start">Approve</button></form></li><li><form method="POST" action="hq_account_action.php" class="d-inline"><input type="hidden" name="headquarter_id" value="<?= (int) $row['headquarter_id'] ?>"><input type="hidden" name="action_type" value="reject"><input type="hidden" name="reason" value="Rejected after HQ review."><input type="hidden" name="return_to" value="manage_hq.php"><button type="submit" class="dropdown-item text-start">Reject</button></form></li><?php } else { ?><li><button type="button" class="dropdown-item js-edit-hq" title="Edit HQ" data-bs-toggle="modal" data-bs-target="#editHQModal" data-hq-id="<?= (int) $row['headquarter_id'] ?>" data-hq-name="<?= hq_escape((string) $row['company_name']) ?>" data-logo="<?= hq_escape((string) ($row['company_logo'] ?? '')) ?>" data-contact="<?= hq_escape((string) ($row['contact_number'] ?? '')) ?>" data-owner-name="<?= hq_escape((string) ($row['owner_name'] ?? '')) ?>" data-owner-email="<?= hq_escape((string) ($row['owner_email'] ?? $row['headquarter_email'] ?? '')) ?>" data-region="<?= hq_escape($rowRegion) ?>" data-address="<?= hq_escape($rowAddress) ?>" data-license="<?= hq_escape((string) ($row['business_license'] ?? '')) ?>" data-expire-at="<?= hq_escape($expiry) ?>">Edit details</button></li><?php } ?></ul></div><button type="button" class="btn-action btn-action-danger js-hq-action" title="Suspend / Remove HQ" data-hq-id="<?= (int) $row['headquarter_id'] ?>" data-hq-name="<?= hq_escape((string) $row['company_name']) ?>"><i class="bi bi-trash3-fill"></i> Remove / Suspend</button></div></td></tr><?php endforeach; ?>
    </tbody></table></div></div>
</main>

<!-- Edit HQ modal (admin) -->
<div class="modal fade glass-modal" id="editHQModal" tabindex="-1" aria-labelledby="editHQLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title fw-bold" id="editHQLabel"><i class="bi bi-pencil-square txt-neon-cyan me-2"></i> Edit HQ</h5><p class="text-white-50 small mb-0 mt-1">Update HQ and owner details in the live database.</p></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><form action="hq_update_action.php" method="POST"><input type="hidden" name="headquarter_id" id="editHQId"><div class="modal-body d-flex flex-column gap-3"><div class="manage-hq-note"><span class="text-danger">*</span> Changing the owner email also updates the linked HQ account email.</div><div><label class="form-label text-white-50 small" for="editHqName">Company / HQ Name <span class="text-danger">*</span></label><input id="editHqName" type="text" name="hq_name" class="form-control glass-input" maxlength="255" required></div><div><label class="form-label text-white-50 small" for="editLogo">Company Logo URL / Storage Path <span class="text-danger">*</span></label><input id="editLogo" type="text" name="company_logo" class="form-control glass-input" maxlength="255" required></div><div class="row g-3"><div class="col-12 col-md-6"><label class="form-label text-white-50 small" for="editOwnerName">Owner Name <span class="text-danger">*</span></label><input id="editOwnerName" type="text" name="owner_name" class="form-control glass-input" maxlength="225" required></div><div class="col-12 col-md-6"><label class="form-label text-white-50 small" for="editOwnerEmail">Owner Email <span class="text-danger">*</span></label><input id="editOwnerEmail" type="email" name="owner_email" class="form-control glass-input" maxlength="225" required></div></div><div><label class="form-label text-white-50 small" for="editContact">Contact Number <span class="text-danger">*</span></label><input id="editContact" type="text" name="contact_number" class="form-control glass-input" maxlength="50" required></div><div><label class="form-label text-white-50 small" for="editRegion">Regional Headquarters Office <span class="text-danger">*</span></label><select id="editRegion" name="region" class="form-select glass-select" required><option value="">Select region</option><option value="yangon">Yangon Region</option><option value="mandalay">Mandalay Region</option><option value="naypyidaw">Naypyidaw</option></select></div><div><label class="form-label text-white-50 small" for="editAddress">Physical Address Details <span class="text-danger">*</span></label><textarea id="editAddress" name="address" rows="3" maxlength="220" class="form-control glass-input" required></textarea></div><div><label class="form-label text-white-50 small" for="editLicense">Business License <span class="text-danger">*</span></label><input id="editLicense" type="text" name="business_license" class="form-control glass-input" maxlength="255" required></div><div><label class="form-label text-white-50 small" for="editExpire">License Expiry Date <span class="text-danger">*</span></label><input id="editExpire" type="date" name="expire_at" min="<?= date('Y-m-d') ?>" class="form-control glass-input" required></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-3 text-white-50" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-neon">Save HQ Changes</button></div></form></div></div></div>
<div class="modal fade glass-modal" id="hqActionModal" tabindex="-1" aria-labelledby="hqActionLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title fw-bold" id="hqActionLabel"><i class="bi bi-shield-exclamation text-danger me-2"></i> Suspend or Remove HQ</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><form action="hq_account_action.php" method="POST"><div class="modal-body d-flex flex-column gap-3"><input type="hidden" name="headquarter_id" id="hqActionId"><input type="hidden" name="return_to" value="manage_hq.php"><div class="small text-white-50">Target HQ: <span class="txt-neon-cyan fw-semibold" id="hqActionName">Selected HQ</span></div><div><label class="form-label text-white-50 small" for="hqActionType">Account action</label><select class="form-select glass-select" name="action_type" id="hqActionType" required><option value="suspend">Suspend account</option><option value="remove">Remove account</option></select></div><div><label class="form-label text-white-50 small" for="hqActionReason">Reason <span class="text-danger">*</span></label><textarea class="form-control glass-input" name="reason" id="hqActionReason" rows="5" maxlength="2000" required></textarea></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-3 text-white-50" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger">Save Action</button></div></form></div></div></div>
<footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map &amp; Pump | All Rights Reserved</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="/js/Admin.js"></script>
<script>
document.addEventListener('DOMContentLoaded',()=>{const actionModal=bootstrap.Modal.getOrCreateInstance(document.getElementById('hqActionModal'));document.querySelectorAll('.js-edit-hq').forEach(button=>button.addEventListener('click',()=>{const fields={editHQId:'hqId',editHqName:'hqName',editLogo:'logo',editContact:'contact',editOwnerName:'ownerName',editOwnerEmail:'ownerEmail',editRegion:'region',editAddress:'address',editLicense:'license',editExpire:'expireAt'};Object.entries(fields).forEach(([id,key])=>{const field=document.getElementById(id);if(field)field.value=button.dataset[key]||'';});}));document.querySelectorAll('.js-hq-action').forEach(button=>button.addEventListener('click',()=>{document.getElementById('hqActionId').value=button.dataset.hqId||'';document.getElementById('hqActionName').textContent=button.dataset.hqName||'Selected HQ';document.getElementById('hqActionReason').value='';actionModal.show();}));});
</script>
</body>
</html>
