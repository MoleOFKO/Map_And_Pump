<?php
require_once __DIR__ . '/hq_guard.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// // Database connection configuration
require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../billing_config.php';
require_once __DIR__ . '/../imgbb_image_host.php';
require_once __DIR__ . '/../shared/license_expiry_date.php';
require_once __DIR__ . '/../shared/privacy_display.php';
// Handle form submission
$message = '';
$loggedUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$loggedHqId = isset($_SESSION['headquarter_id']) ? (int) $_SESSION['headquarter_id'] : 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = $_POST['company_name'] ?? '';
    $info = $_POST['headquarter_bio'] ?? '';
    $email = $_POST['headquarter_email'] ?? '';
    $phone = $_POST['contact_number'] ?? '';
    $businessLicense = $_POST['business_license'] ?? '';
    $licenseExpiry = trim((string) ($_POST['business_license_expiry'] ?? ''));
    $location = $_POST['location'] ?? '';

    // Handle Logo Upload if present
    $logoPath = null;
    if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] === UPLOAD_ERR_OK) {
        $logoUpload = $_FILES['company_logo'];
        $imageInfo = @getimagesize((string) ($logoUpload['tmp_name'] ?? ''));
        $mime = is_array($imageInfo) ? (string) ($imageInfo['mime'] ?? '') : '';
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];

        if ((int) ($logoUpload['size'] ?? 0) > 2 * 1024 * 1024 || !isset($extensions[$mime])) {
            $message = 'Company logos must be JPG, PNG, GIF, or WebP images no larger than 2 MB.';
        } else {
            $fileName = 'logo_' . bin2hex(random_bytes(12)) . '.' . $extensions[$mime];
            if (map_pump_imgbb_enabled()) {
                $imageBytes = @file_get_contents((string) $logoUpload['tmp_name']);
                try {
                    if (!is_string($imageBytes)) {
                        throw new RuntimeException('The company logo could not be read.');
                    }
                    $logoPath = map_pump_imgbb_upload($imageBytes, $fileName, $mime);
                } catch (Throwable $e) {
                    error_log('HQ logo ImgBB upload failed: ' . $e->getMessage());
                    $message = 'The company logo could not be uploaded.';
                }
            } else {
                $uploadDir = __DIR__ . '/uploads/';
                if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                    $message = 'The company logo folder could not be created.';
                } else {
                    $uploadFile = $uploadDir . $fileName;
                    if (move_uploaded_file((string) $logoUpload['tmp_name'], $uploadFile)) {
                        $logoPath = 'uploads/' . $fileName;
                    } else {
                        $message = 'The company logo could not be saved.';
                    }
                }
            }
        }
    }

    // Load the headquarters belonging to the signed-in user.
    if ($loggedUserId > 0) {
        $stmt = $pdo->prepare("SELECT h.headquarter_id, h.company_logo, h.headquarter_email, h.company_name, h.location, h.business_license, h.business_license_expire_date, h.expire_at FROM users u LEFT JOIN business_account_scope bas ON bas.user_id = u.user_id LEFT JOIN headquarter h ON (h.headquarters_owner_id = u.user_id OR h.headquarter_id = bas.headquarter_id) WHERE u.user_id = ? ORDER BY (h.headquarters_owner_id = u.user_id) DESC LIMIT 1");
        $stmt->execute([$loggedUserId]);
    } elseif ($loggedHqId > 0) {
        $stmt = $pdo->prepare("SELECT headquarter_id, company_logo, headquarter_email, company_name, location, business_license, business_license_expire_date, expire_at FROM headquarter WHERE headquarter_id = ? LIMIT 1");
        $stmt->execute([$loggedHqId]);
    } else {
        $stmt = $pdo->query("SELECT headquarter_id, company_logo, headquarter_email, company_name, location, business_license, business_license_expire_date, expire_at FROM headquarter LIMIT 1");
    }
    $config = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$logoPath && $config) {
        $logoPath = $config['company_logo'];
    }

    if ($config) {
        $currentExpiry = trim((string) ($config['business_license_expire_date'] ?: $config['expire_at']));
        $licenseChanged = trim((string) $businessLicense) !== trim((string) $config['business_license'])
            || $licenseExpiry !== substr($currentExpiry, 0, 10);
        if ($licenseChanged) {
            $parsedExpiry = DateTime::createFromFormat('!Y-m-d', $licenseExpiry);
            $expiryErrors = DateTime::getLastErrors();
            $currentExpiryDate = substr($currentExpiry, 0, 10);
            if (trim((string) $businessLicense) === '' || !$parsedExpiry
                || ($expiryErrors !== false && ($expiryErrors['warning_count'] > 0 || $expiryErrors['error_count'] > 0))
                || $parsedExpiry->format('Y-m-d') !== $licenseExpiry || $licenseExpiry < date('Y-m-d')
                || ($currentExpiryDate !== '' && $licenseExpiry <= $currentExpiryDate)) {
                $message = 'License extension request failed: enter a valid license number and a new expiry date later than the current HQ deadline ('
                    . ($currentExpiryDate !== '' ? $currentExpiryDate : 'not set') . ').';
            } else {
                $pendingRequest = $pdo->prepare("SELECT request_id FROM system_requests WHERE headquarter_id = ? AND request_type = 'license_modification' AND status = 'pending' LIMIT 1");
                $pendingRequest->execute([(int) $config['headquarter_id']]);
                if ($pendingRequest->fetchColumn()) {
                    $message = 'License extension request failed: a request is already pending Admin or Moderator review.';
                } else {
                    try {
                        $pdo->beginTransaction();
                        $previousStatus = strtolower(trim((string) ($_SESSION['user_status'] ?? 'approved')));
                        if ($previousStatus === '' || $previousStatus === 'pending') {
                            $previousStatus = 'approved';
                        }
                        $details = json_encode([
                            'renewal_license_number' => trim((string) $businessLicense),
                            'previous_status' => $previousStatus,
                            'previous_expire_at' => $currentExpiryDate,
                            'requested_expire_at' => $licenseExpiry,
                            'request_kind' => 'license_expiry_extension',
                        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                        $reference = 'HQ-LIC-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
                        $request = $pdo->prepare(
                            "INSERT INTO system_requests
                                (reference_code, request_type, status, submitter_user_id, role_id, headquarter_id,
                                 sender_entity, sender_name, sender_email, location, request_details, headquarter_expire_at)
                             VALUES (?, 'license_modification', 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                        );
                        $request->execute([
                            $reference,
                            $loggedUserId > 0 ? $loggedUserId : null,
                            (int) ($_SESSION['role_id'] ?? 3),
                            (int) $config['headquarter_id'],
                            (string) ($config['company_name'] ?? $title),
                            (string) ($_SESSION['user_name'] ?? ''),
                            (string) ($_SESSION['user_email'] ?? ''),
                            (string) ($config['location'] ?? $location),
                            $details,
                            $licenseExpiry . ' 23:59:59',
                        ]);
                        $pdo->commit();
                        $message = 'License expiry extension submitted (' . $reference . '). '
                            . 'An Admin or Moderator must approve it before the new expiry date is applied.';
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        error_log('HQ license renewal submission failed: ' . $e->getMessage());
                        $message = 'License extension request failed: ' . $e->getMessage();
                    }
                }
            }
        }

        // Save the brand settings without allowing an email conflict to block a logo update.
        $updateStmt = $pdo->prepare("UPDATE headquarter SET company_name = ?, headquarter_bio = ?, contact_number = ?, location = ?, company_logo = ?, expire_at = expire_at WHERE headquarter_id = ?");
        $updateStmt->execute([$title, $info, $phone, $location, $logoPath, $config['headquarter_id']]);

        $email = trim($email);
        if ($email !== '' && strcasecmp($email, (string) $config['headquarter_email']) !== 0) {
            $emailCheck = $pdo->prepare("SELECT headquarter_id FROM headquarter WHERE headquarter_email = ? AND headquarter_id <> ? LIMIT 1");
            $emailCheck->execute([$email, $config['headquarter_id']]);

            if (!$emailCheck->fetchColumn()) {
                $emailStmt = $pdo->prepare("UPDATE headquarter SET headquarter_email = ?, expire_at = expire_at WHERE headquarter_id = ?");
                $emailStmt->execute([$email, $config['headquarter_id']]);
            } else {
                $message = "Brand settings saved, but that email is already used by another headquarters.";
            }
        }
    } else {
        $expiryDate = map_pump_parse_license_expiry_date($licenseExpiry);
        if ($expiryDate === null || trim((string) $businessLicense) === '') {
            $message = 'Enter a valid business license number and expiry date.';
        } else {
            $insertStmt = $pdo->prepare(
                'INSERT INTO headquarter
                    (company_name, headquarter_bio, headquarter_email, contact_number, business_license,
                     business_license_expire_date, expire_at, location, company_logo, station_count)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ' . BILLING_HQ_INCLUDED_STATION_SLOTS . ')'
            );
            $insertStmt->execute([
                $title,
                $info,
                $email,
                $phone,
                $businessLicense,
                $expiryDate,
                $expiryDate . ' 23:59:59',
                $location,
                $logoPath,
            ]);
        }
    }

    if ($message === '') {
        $message = "Corporate parameters stored successfully!";
    }
}

// Fetch current configuration to populate the form
if ($loggedHqId > 0) {
    $stmt = $pdo->prepare("SELECT h.*, owner.user_name AS owner_name, COALESCE((SELECT MIN(request.created_at) FROM system_requests request WHERE request.headquarter_id = h.headquarter_id AND request.request_type = 'hq_registration'), h.created_at) AS registration_date FROM headquarter h LEFT JOIN users owner ON owner.user_id = h.headquarters_owner_id WHERE h.headquarter_id = ? LIMIT 1");
    $stmt->execute([$loggedHqId]);
} elseif ($loggedUserId > 0) {
    $stmt = $pdo->prepare("SELECT h.*, COALESCE(owner.user_name, u.user_name) AS owner_name, COALESCE((SELECT MIN(request.created_at) FROM system_requests request WHERE request.headquarter_id = h.headquarter_id AND request.request_type = 'hq_registration'), h.created_at) AS registration_date FROM users u LEFT JOIN business_account_scope bas ON bas.user_id = u.user_id LEFT JOIN headquarter h ON (h.headquarters_owner_id = u.user_id OR h.headquarter_id = bas.headquarter_id) LEFT JOIN users owner ON owner.user_id = h.headquarters_owner_id WHERE u.user_id = ? ORDER BY (h.headquarters_owner_id = u.user_id) DESC LIMIT 1");
    $stmt->execute([$loggedUserId]);
} else {
    $stmt = $pdo->query("SELECT h.*, owner.user_name AS owner_name, COALESCE((SELECT MIN(request.created_at) FROM system_requests request WHERE request.headquarter_id = h.headquarter_id AND request.request_type = 'hq_registration'), h.created_at) AS registration_date FROM headquarter h LEFT JOIN users owner ON owner.user_id = h.headquarters_owner_id LIMIT 1");
}
$currentConfig = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$brandTitle = $currentConfig['company_name'] ?? 'Apex Fuel & Gas';
$brandInfo = $currentConfig['headquarter_bio'] ?? '';
$brandEmail = $currentConfig['headquarter_email'] ?? 'hq@apexfuelgas.com';
$brandPhone = $currentConfig['contact_number'] ?? '+1 (800) 555-APEX';
$businessLicense = $currentConfig['business_license'] ?? '';
$licenseExpireDate = ($currentConfig['business_license_expire_date'] ?? '') ?: substr((string) ($currentConfig['expire_at'] ?? ''), 0, 10);
$ownerName = $currentConfig['owner_name'] ?? '';
$registerDate = !empty($currentConfig['registration_date']) ? substr((string) $currentConfig['registration_date'], 0, 10) : '';
$licenseExpiryValue = trim((string) $licenseExpireDate);
$licenseExpiryDate = DateTimeImmutable::createFromFormat('!Y-m-d', $licenseExpiryValue);
$licenseExpiryDaysRemaining = null;
if ($licenseExpiryDate && $licenseExpiryDate->format('Y-m-d') === $licenseExpiryValue) {
    $licenseExpiryDaysRemaining = (int) (new DateTimeImmutable('today'))->diff($licenseExpiryDate)->format('%r%a');
}
$showLicenseExpiryReminder = $licenseExpiryDaysRemaining !== null && $licenseExpiryDaysRemaining <= 7;
$licenseReminderKey = 'hq-license-expiry:' . (int) ($currentConfig['headquarter_id'] ?? 0) . ':' . hash('sha256', strtolower(trim((string) $businessLicense)) . '|' . $licenseExpiryValue);
$location = $currentConfig['location'] ?? '';
$brandLogo = $currentConfig['company_logo'] ?? 'https://via.placeholder.com/150';
if ($brandLogo !== '' && !preg_match('#^(?:https?:)?//#i', $brandLogo)) {
    $brandLogo = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/php/HQ/brand.php')), '/') . '/' . ltrim(preg_replace('#^/php/HQ/#', '', $brandLogo), '/');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <script src="../../js/hq-theme.js"></script>
    <link rel="stylesheet" href="../../css/HQstyle.css">
    <style>
        #brandInfoModal .modal-content {
            max-height: min(90dvh, calc(100dvh - 1rem));
            overflow: hidden;
        }
        #brandInfoModal .brand-info-form {
            display: flex;
            flex: 1 1 auto;
            flex-direction: column;
            min-height: 0;
            overflow: hidden;
        }
        #brandInfoModal .brand-info-form .modal-body {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
        }
        #brandInfoModal .modal-body {
            scrollbar-color: transparent transparent;
            scrollbar-width: thin;
        }
        #brandInfoModal .modal-body::-webkit-scrollbar { width: 8px; }
        #brandInfoModal .modal-body::-webkit-scrollbar-track { background: transparent; }
        #brandInfoModal .modal-body::-webkit-scrollbar-thumb {
            background: transparent;
            border-radius: 8px;
        }
        #brandInfoModal .modal-body:hover {
            scrollbar-color: rgba(128, 145, 165, .45) transparent;
        }
        #brandInfoModal .modal-body:hover::-webkit-scrollbar-thumb { background: rgba(128, 145, 165, .4); }
        #brandLogoInput:focus-visible + label { outline: 3px solid #2679e8; outline-offset: 3px; }
    </style>
</head>
<body>
    <h2 class="title">Brand Configuration</h2>
    <p class="subtitle">Set global brand styles, logos, and configurations.</p>
    
    <?php if (!empty($message)): ?>
        <div class="alert alert-success" role="alert"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <section class="card p-4" aria-labelledby="brandSummaryTitle">
        <div class="row align-items-center g-4">
            <div class="col-auto">
                <img src="<?= htmlspecialchars($brandLogo) ?>" alt="Brand Logo" class="rounded-circle img-thumbnail" style="width: 112px; height: 112px; object-fit: cover; background-color: #fff;">
            </div>
            <div class="col">
                <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                    <div>
                        <h3 id="brandSummaryTitle" class="mb-1"><?= htmlspecialchars(privacy_mask_name((string) $brandTitle), ENT_QUOTES, 'UTF-8') ?></h3>
                        <p class="text-muted mb-0"><?= htmlspecialchars(privacy_mask_free_text((string) ($brandInfo ?: 'No company information added.')), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                    <button type="button" class="btn btn-primary flex-shrink-0" data-bs-toggle="modal" data-bs-target="#brandInfoModal">Change Info</button>
                </div>
                <dl class="row mb-0">
                    <dt class="col-sm-4 col-lg-3">Corporate Email</dt><dd class="col-sm-8 col-lg-9"><?= htmlspecialchars(privacy_mask_email((string) ($brandEmail ?: 'Not provided')), ENT_QUOTES, 'UTF-8') ?></dd>
                    <dt class="col-sm-4 col-lg-3">Contact Phone</dt><dd class="col-sm-8 col-lg-9"><?= htmlspecialchars(privacy_mask_phone((string) ($brandPhone ?: 'Not provided')), ENT_QUOTES, 'UTF-8') ?></dd>
                    <dt class="col-sm-4 col-lg-3">Business License</dt><dd class="col-sm-8 col-lg-9"><?= htmlspecialchars(privacy_mask_identifier((string) ($businessLicense ?: 'Not provided')), ENT_QUOTES, 'UTF-8') ?></dd>
                    <dt class="col-sm-4 col-lg-3">License Expire Date</dt><dd class="col-sm-8 col-lg-9"><?= htmlspecialchars($licenseExpireDate ?: 'Not provided') ?></dd>
                    <dt class="col-sm-4 col-lg-3">Owner Name</dt><dd class="col-sm-8 col-lg-9"><?= htmlspecialchars(privacy_mask_name((string) ($ownerName ?: 'Not available')), ENT_QUOTES, 'UTF-8') ?></dd>
                    <dt class="col-sm-4 col-lg-3">Register Date</dt><dd class="col-sm-8 col-lg-9"><?= htmlspecialchars($registerDate ?: 'Not available') ?></dd>
                    <dt class="col-sm-4 col-lg-3">Location</dt><dd class="col-sm-8 col-lg-9 mb-0"><?= htmlspecialchars(privacy_mask_location((string) ($location ?: 'Not provided')), ENT_QUOTES, 'UTF-8') ?></dd>
                </dl>
            </div>
        </div>
    </section>

    <div class="modal fade" id="brandInfoModal" tabindex="-1" aria-labelledby="brandInfoModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <form class="brand-info-form" method="POST" enctype="multipart/form-data">
                    <div class="modal-header">
                        <h5 class="modal-title" id="brandInfoModalTitle">Change Brand Info</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row align-items-center g-4">
                            <div class="col-md-4 text-center">
                                <img id="logoPreview" src="<?= htmlspecialchars($brandLogo) ?>" alt="Brand Logo Preview" class="rounded-circle img-thumbnail mb-3" style="width: 140px; height: 140px; object-fit: cover; background-color: #fff;">
                                <input type="file" name="company_logo" id="brandLogoInput" accept="image/jpeg,image/png,image/gif,image/webp" class="visually-hidden" onchange="previewLogo(event)">
                                <label for="brandLogoInput" class="btn btn-outline-secondary">Change Logo</label>
                                <div class="form-text mt-2">JPG, PNG, GIF, or WebP up to 2 MB.</div>
                            </div>
                            <div class="col-md-8">
                                <div class="form-group">
                                    <label class="form-label" for="companyName">Brand Display Title</label>
                                    <input id="companyName" type="text" class="form-control" name="company_name" value="<?= htmlspecialchars($brandTitle) ?>" placeholder="Company Display Name">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="headquarterBio">Company Information &amp; Details</label>
                                    <textarea id="headquarterBio" class="form-control custom-input" name="headquarter_bio" rows="3" placeholder="Enter corporate background, address, or details..."><?= htmlspecialchars($brandInfo) ?></textarea>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="headquarterEmail">Corporate Email</label>
                                <input id="headquarterEmail" type="email" class="form-control" name="headquarter_email" value="<?= htmlspecialchars($brandEmail) ?>" placeholder="corp@example.com">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="contactNumber">Support / Contact Phone</label>
                                <input id="contactNumber" type="text" class="form-control" name="contact_number" value="<?= htmlspecialchars($brandPhone) ?>" placeholder="+1 (xxx) xxx-xxxx">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="businessLicense">Business License</label>
                                <input id="businessLicense" type="text" class="form-control" name="business_license" value="<?= htmlspecialchars($businessLicense) ?>" placeholder="License Number">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="licenseExpireDate">License Expire Date</label>
                                <input id="licenseExpireDate" type="date" class="form-control" name="business_license_expiry" value="<?= htmlspecialchars(substr((string) $licenseExpireDate, 0, 10)) ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="brandOwnerName">Owner Name</label>
                                <input id="brandOwnerName" type="text" class="form-control" value="<?= htmlspecialchars($ownerName) ?>" readonly>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="brandRegisterDate">Register Date</label>
                                <input id="brandRegisterDate" type="date" class="form-control" value="<?= htmlspecialchars($registerDate) ?>" readonly>
                            </div>
                            <div class="col-12 mb-3">
                                <label class="form-label" for="brandLocation">Location</label>
                                <input id="brandLocation" type="text" class="form-control" name="location" value="<?= htmlspecialchars($location) ?>" placeholder="Office Location / City">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if ($showLicenseExpiryReminder): ?>
        <div class="modal fade" id="licenseExpiryReminderModal" tabindex="-1" aria-labelledby="licenseExpiryReminderTitle" aria-hidden="true" data-reminder-key="<?= htmlspecialchars($licenseReminderKey, ENT_QUOTES, 'UTF-8') ?>">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="licenseExpiryReminderTitle">Business License Reminder</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-warning mb-0" role="alert">
                            <?php if ($licenseExpiryDaysRemaining < 0): ?>
                                Your business license expired <?= abs($licenseExpiryDaysRemaining) ?> day<?= abs($licenseExpiryDaysRemaining) === 1 ? '' : 's' ?> ago, on <?= htmlspecialchars($licenseExpiryDate->format('F j, Y')) ?>.
                            <?php elseif ($licenseExpiryDaysRemaining === 0): ?>
                                Your business license expires today, <?= htmlspecialchars($licenseExpiryDate->format('F j, Y')) ?>.
                            <?php else: ?>
                                Your business license expires in <?= $licenseExpiryDaysRemaining ?> day<?= $licenseExpiryDaysRemaining === 1 ? '' : 's' ?>, on <?= htmlspecialchars($licenseExpiryDate->format('F j, Y')) ?>.
                            <?php endif; ?>
                            Please update your license information.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Understood</button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const reminderModal = document.getElementById('licenseExpiryReminderModal');
            if (!reminderModal) return;

            const reminderKey = reminderModal.dataset.reminderKey;
            const reminderInterval = 24 * 60 * 60 * 1000;
            let shouldShowReminder = true;

            try {
                const lastShownAt = Number(localStorage.getItem(reminderKey) || 0);
                shouldShowReminder = !lastShownAt || Date.now() - lastShownAt >= reminderInterval;
                if (shouldShowReminder) localStorage.setItem(reminderKey, String(Date.now()));
            } catch (error) {
                shouldShowReminder = true;
            }

            if (shouldShowReminder) bootstrap.Modal.getOrCreateInstance(reminderModal).show();
        });

        function previewLogo(event) {
            const file = event.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('logoPreview').src = e.target.result;
                }
                reader.readAsDataURL(file);
            }
        }
    </script>
</body>
<footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map & Pump | All Rights Reserved</footer>
</html>
