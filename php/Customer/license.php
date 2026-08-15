<?php
require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/license_access.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: /php/Login/login.php');
    exit;
}

$stmt = $pdo->prepare(
    'SELECT l.*
     FROM users u
     LEFT JOIN user_license l ON l.license_id = u.license_id
     WHERE u.user_id = ?
     LIMIT 1'
);
$stmt->execute([$userId]);
$license = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$hasLicense = !empty($license['license_id']);
$licenseAccess = customer_license_access($pdo, $userId);
$licenseStatus = (string) ($license['status'] ?? $licenseAccess['status'] ?? 'pending');
$isApproved = (bool) ($licenseAccess['is_approved'] ?? false);
$statusLabel = customer_license_status_label($licenseStatus);
$showForm = !$hasLicense || isset($_GET['update']);
$statusPillClass = match (strtolower($licenseStatus)) {
    'approved', 'confirmed', 'active' => 'active',
    'rejected' => 'danger',
    default => 'pending',
};

include 'share_header.php';
?>

<div class="row justify-content-center">
    <div class="col-xl-9">
        <div class="dashboard-panel-card">
            <div class="d-flex align-items-start justify-content-between gap-3 mb-4 flex-wrap">
                <div>
                    <span class="status-pill <?php echo htmlspecialchars($hasLicense ? $statusPillClass : 'pending'); ?> mb-3"><i class="bi bi-person-vcard-fill"></i> <?php echo htmlspecialchars($hasLicense ? $statusLabel : 'License Required'); ?></span>
                    <h3 class="text-white mb-2"><?php echo $hasLicense ? 'Update Driver License' : 'Add Driver License'; ?></h3>
                    <p class="text-secondary mb-0" style="font-size:13px;">
                        <?php echo $isApproved
                            ? 'Your driver license is confirmed. Updating these details will send it back for Moderator review.'
                            : 'Only this Driver License page is available until a Moderator confirms your license.'; ?>
                    </p>
                </div>
            </div>

            <?php if ($hasLicense): ?>
                <div class="mb-4 rounded-3 px-3 py-3 d-flex align-items-center justify-content-between gap-3 flex-wrap" style="font-size:13px;background:rgba(10,22,44,.55);border:1px solid rgba(13,110,253,.18);">
                    <div class="d-flex align-items-center gap-3 flex-wrap">
                        <span class="status-pill <?php echo htmlspecialchars($statusPillClass); ?>" style="font-size:10px;">
                            <i class="bi <?php echo $isApproved ? 'bi-check-circle-fill' : 'bi-hourglass-split'; ?>"></i>
                            <?php echo htmlspecialchars($statusLabel); ?>
                        </span>
                        <span class="text-white fw-semibold"><?php echo htmlspecialchars((string) ($license['license_number'] ?? 'Driver License')); ?></span>
                        <span class="text-secondary"><?php echo htmlspecialchars((string) ($license['license_name'] ?? '')); ?></span>
                    </div>
                    <?php if ($showForm): ?>
                        <a href="license.php" class="btn btn-outline-secondary btn-sm rounded-3 px-3">Cancel Update</a>
                    <?php else: ?>
                        <a href="license.php?update=1" class="btn btn-outline-info btn-sm rounded-3 px-3 fw-bold">Update</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div id="license-status" role="alert" class="mb-4 d-none rounded-3 px-3 py-3" style="font-size:13px;"></div>

            <form id="license-form" enctype="multipart/form-data" class="<?php echo $showForm ? '' : 'd-none'; ?>">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label text-secondary fw-bold" style="font-size:11px;">LICENSE IMAGE</label>
                        <input type="file" name="license_image" class="form-control custom-form-field" accept="image/jpeg,image/png,image/webp" <?php echo $hasLicense ? '' : 'required'; ?>>
                        <?php if ($hasLicense && !empty($license['license_image'])): ?>
                            <small class="text-secondary d-block mt-2">Current image saved. Upload a new one only if it changed.</small>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-secondary fw-bold" style="font-size:11px;">LICENSE NUMBER</label>
                        <input type="text" name="license_number" class="form-control custom-form-field" maxlength="100" placeholder="YGN-123456" value="<?php echo htmlspecialchars((string) ($license['license_number'] ?? '')); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-secondary fw-bold" style="font-size:11px;">LICENSE NAME</label>
                        <input type="text" name="license_name" class="form-control custom-form-field" maxlength="255" value="<?php echo htmlspecialchars((string) ($license['license_name'] ?? ($_SESSION['user_name'] ?? ''))); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-secondary fw-bold" style="font-size:11px;">PHONE NUMBER</label>
                        <input type="tel" name="phone_number" class="form-control custom-form-field" maxlength="50" placeholder="09..." value="<?php echo htmlspecialchars((string) ($license['phone_number'] ?? '')); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-secondary fw-bold" style="font-size:11px;">NRC NUMBER</label>
                        <input type="text" name="nrc_number" class="form-control custom-form-field" maxlength="100" placeholder="12/ABC(N)123456" value="<?php echo htmlspecialchars((string) ($license['nrc_number'] ?? '')); ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label text-secondary fw-bold" style="font-size:11px;">DATE OF BIRTH</label>
                        <input type="date" name="date_of_birth" class="form-control custom-form-field" value="<?php echo htmlspecialchars((string) ($license['date_of_birth'] ?? '')); ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label text-secondary fw-bold" style="font-size:11px;">BLOOD TYPE</label>
                        <select name="blood_type" class="form-select custom-form-field" required>
                            <option value="">Select</option>
                            <?php foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] as $type): ?>
                                <option value="<?php echo $type; ?>" <?php echo (($license['blood_type'] ?? '') === $type) ? 'selected' : ''; ?>><?php echo $type; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-secondary fw-bold" style="font-size:11px;">LICENSE LAST VALID DATE</label>
                        <input type="date" name="license_last_valid_date" class="form-control custom-form-field" value="<?php echo htmlspecialchars((string) ($license['license_last_valid_date'] ?? '')); ?>" required>
                    </div>
                </div>

                <div class="d-flex justify-content-end mt-4">
                    <button type="submit" id="license-submit" class="btn btn-info px-4 py-2 fw-bold rounded-3" style="font-size:13px;">
                        <?php echo $hasLicense ? 'Update License' : 'Save License'; ?> <i class="bi bi-arrow-right ms-2"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    const licenseForm = document.getElementById('license-form');
    const licenseStatus = document.getElementById('license-status');
    const licenseSubmit = document.getElementById('license-submit');
    const licenseSubmitLabel = licenseSubmit.innerHTML;

    function showLicenseStatus(message, ok = false) {
        licenseStatus.textContent = message;
        licenseStatus.classList.toggle('d-none', !message);
        licenseStatus.style.background = ok ? 'rgba(27, 228, 148, 0.1)' : 'rgba(255, 77, 106, 0.1)';
        licenseStatus.style.border = ok ? '1px solid rgba(27, 228, 148, 0.25)' : '1px solid rgba(255, 77, 106, 0.25)';
        licenseStatus.style.color = ok ? '#bfffe5' : '#ffd4dc';
    }

    licenseForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!licenseForm.checkValidity()) {
            licenseForm.reportValidity();
            return;
        }

        showLicenseStatus('');
        licenseSubmit.disabled = true;
        licenseSubmit.textContent = 'Saving...';

        try {
            const response = await fetch('save_license.php', {
                method: 'POST',
                body: new FormData(licenseForm),
                credentials: 'same-origin'
            });
            const result = await response.json();
            if (!result.ok) {
                showLicenseStatus(result.message || 'Could not save your license.');
                if (result.redirect) {
                    window.setTimeout(() => window.location.href = result.redirect, 900);
                }
                return;
            }

            showLicenseStatus(result.message || 'Driver license saved.', true);
            window.setTimeout(() => window.location.href = result.redirect || 'dashboard.php', 700);
        } catch (error) {
            showLicenseStatus('Could not contact the server. Please try again.');
        } finally {
            licenseSubmit.disabled = false;
            licenseSubmit.innerHTML = licenseSubmitLabel;
        }
    });
</script>

<?php include 'share_footer.php'; ?>
