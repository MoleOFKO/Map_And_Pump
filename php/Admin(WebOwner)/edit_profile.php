<?php
require_once __DIR__ . '/admin_common.php';
require_once __DIR__ . '/../profile_avatar.php';

$user = admin_require_admin();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$flash = admin_pull_flash();
$accountDeletionFlash = (string) ($_SESSION['account_deletion_flash'] ?? '');
unset($_SESSION['account_deletion_flash']);
$statusLabel = static function (?string $status): string {
    return match (strtolower(trim((string) $status))) {
        'approved', 'active' => 'Approved',
        'pending', 'pending_verification' => 'Pending',
        'suspended' => 'Suspended',
        'removed' => 'Revoked',
        default => ucwords(str_replace('_', ' ', (string) $status)),
    };
};
$statusClass = static function (?string $status): string {
    return match (strtolower(trim((string) $status))) {
        'approved', 'active' => 'text-success',
        'suspended' => 'text-warning',
        'removed' => 'text-danger',
        default => 'text-white-50',
    };
};
$avatarUrl = trim((string) ($user['oauth_avatar_url'] ?? ''));
$avatarInitial = map_pump_profile_initial((string) ($user['user_name'] ?? ''), 'A');
$avatarFallbackUrl = map_pump_profile_initial_svg((string) ($user['user_name'] ?? ''), 'A');
$hasCustomerProfile = admin_user_has_role($user, ['customer']);
$customerVehicle = null;
$customerVehicleLoadError = false;
if ($hasCustomerProfile) {
    global $pdo;
    if ($pdo instanceof PDO) {
        try {
            $vehicleStatement = $pdo->prepare(
                'SELECT c.car_plate_number, c.vehicle_name, c.vehicle_type
                 FROM user_car uc
                 INNER JOIN car_license c ON c.car_license_id = uc.car_id
                 WHERE uc.user_id = ?
                 ORDER BY uc.is_active DESC, c.updated_at DESC, c.car_license_id DESC
                 LIMIT 1'
            );
            $vehicleStatement->execute([(int) $user['user_id']]);
            $customerVehicle = $vehicleStatement->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            error_log('[Admin profile] Failed to load linked customer vehicle: ' . $e->getMessage());
            $customerVehicleLoadError = true;
        }
    } else {
        $customerVehicleLoadError = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Map &amp; Pump Admin — Edit profile details and secure credentials.">
    <title>Edit Profile — Map &amp; Pump</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&amp;display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="icon" type="image/png" href="/images/Logo.png">
    <link rel="stylesheet" href="/css/Admin.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
        body.background_color.admin-blue-page {
            --ab-bg: #0b1220;
            --ab-surface: #121d2d;
            --ab-raised: #182537;
            --ab-border: #27364a;
            --ab-text: #edf3fa;
            --ab-muted: #a4b3c6;
            --ab-accent: #79aaff;
            --ab-warning: #f7ca72;
            --ab-danger: #ff9aab;
            --neon-cyan: var(--ab-accent);
            --glass-border: var(--ab-border);
            --text-primary: var(--ab-text);
            --text-muted: var(--ab-muted);
            min-height: 100vh;
            color: var(--ab-text);
            font-family: 'Poppins',system-ui,sans-serif;
            background: radial-gradient(ellipse at 90% 0%, rgba(45,80,155,.13), transparent 48%), var(--ab-bg);
            background-repeat: no-repeat;
        }
        body.background_color.admin-blue-page:is(.light-mode,[data-theme="light"]) {
            --ab-bg: #f1f5f8;
            --ab-surface: #fff;
            --ab-raised: #f4f7fa;
            --ab-border: #d9e3ec;
            --ab-text: #172b40;
            --ab-muted: #53667b;
            --ab-accent: #285ec7;
            --ab-warning: #865b08;
            --ab-danger: #b0354c;
        }
        .admin-blue-page .admin-blue-shell {
            max-width: 1480px;
            padding-inline: clamp(16px,3.5vw,48px);
            margin: 36px auto 24px !important;
            color: var(--ab-text);
            font: 14px/1.65 'Poppins',system-ui,sans-serif;
        }
        .admin-blue-page .admin-blue-header { padding: 8px 0 28px; border-bottom: 1px solid var(--ab-border); }
        .admin-blue-page .admin-blue-header h1 {
            margin-top: 8px !important;
            color: var(--ab-text) !important;
            font-size: clamp(26px,3vw,38px);
            font-weight: 600 !important;
            line-height: 1.3;
        }
        .admin-blue-page .admin-blue-header .stat-chip {
            padding: 0;
            border: 0;
            border-radius: 0;
            background: none;
            color: var(--ab-accent);
            font-size: 11px;
            font-weight: 600;
            letter-spacing: .13em;
            text-transform: uppercase;
        }
        .admin-blue-page .admin-blue-description { max-width: 660px; margin: 12px 0 0; color: var(--ab-muted); font-size: 13px; }
        .admin-blue-page .admin-blue-shell :is(.text-white,.fw-bold) { color: var(--ab-text) !important; }
        .admin-blue-page .admin-blue-shell :is(.text-white-50,.form-label) { color: var(--ab-muted) !important; }
        .admin-blue-page .admin-blue-shell :is(.txt-neon-cyan,.text-info,.text-success) { color: var(--ab-accent) !important; }
        .admin-blue-page .admin-blue-shell .text-warning { color: var(--ab-warning) !important; }
        .admin-blue-page .admin-blue-shell .text-danger { color: var(--ab-danger) !important; }
        .admin-blue-page .admin-blue-shell .dashboard-card {
            border: 1px solid var(--ab-border);
            border-radius: 14px;
            background: var(--ab-surface);
            color: var(--ab-text);
            box-shadow: 0 6px 24px rgba(0,0,0,.08);
        }
        .admin-blue-page .admin-blue-shell :is(.form-control,.form-select) {
            min-height: 44px;
            border: 1px solid var(--ab-border);
            border-radius: 8px;
            background: var(--ab-raised);
            color: var(--ab-text);
            font: 13px/1.5 'Poppins',system-ui,sans-serif;
            box-shadow: none;
        }
        .admin-blue-page .admin-blue-shell :is(.form-control,.form-select):focus {
            border-color: var(--ab-accent);
            outline: 2px solid var(--ab-accent);
            outline-offset: 2px;
        }
        .admin-blue-page .admin-blue-shell .form-control::placeholder { color: var(--ab-muted); }
        .admin-blue-page .admin-blue-shell :is(.btn,.btn-outline-info) {
            min-height: 40px;
            border-radius: 8px;
            font-family: inherit;
            font-size: 12px;
            font-weight: 500;
        }
        .admin-blue-page .admin-blue-shell .btn-neon {
            border: 1px solid #79aaff;
            background: #79aaff;
            color: #10284c;
            box-shadow: none;
        }
        .admin-blue-page .admin-blue-shell .btn-outline-info {
            border-color: var(--ab-border);
            background: var(--ab-raised);
            color: var(--ab-accent);
        }
        .admin-blue-page .admin-blue-shell .profile-flash,
        .admin-blue-page .admin-blue-shell .alert {
            border: 1px solid var(--ab-border);
            border-radius: 10px;
            background: var(--ab-raised);
            color: var(--ab-text);
        }
        .admin-blue-page .admin-blue-shell .badge.bg-info {
            border-color: var(--ab-border) !important;
            background: rgba(121,170,255,.1) !important;
            color: var(--ab-accent) !important;
        }
        .admin-blue-page footer { color: var(--ab-muted) !important; font: 11px/1.6 'Poppins',system-ui,sans-serif; }
        @media (max-width: 767.98px) {
            .admin-blue-page .admin-blue-shell { margin-top: 22px !important; }
        }
    </style>
</head>
<body class="background_color admin-blue-page" id="background_color">
<?php $adminSection = 'profile'; include __DIR__ . '/admin_section_nav.php'; ?>

    <main class="container my-5 admin-blue-shell">
        <div class="admin-blue-header mb-4">
            <span class="stat-chip mb-2"><i class="bi bi-person-badge-fill txt-neon-cyan"></i> Account Settings</span>
            <h1 class="fw-bold m-0 h4 text-white mt-2">Edit Profile Details</h1>
            <p class="admin-blue-description">Manage your profile information, password, and account security.</p>
        </div>
        
        <?php if ($flash): ?>
            <div class="alert profile-flash alert-<?= hq_escape((string) ($flash['type'] ?? 'info')) ?> alert-dismissible fade show mb-4" role="alert">
                <i class="bi <?= (($flash['type'] ?? '') === 'success') ? 'bi-check-circle-fill text-success' : 'bi-exclamation-triangle-fill text-danger' ?> me-2"></i><?= hq_escape((string) ($flash['message'] ?? '')) ?>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if ($accountDeletionFlash !== ''): ?>
            <div class="alert profile-flash alert-danger alert-dismissible fade show mb-4" role="alert">
                <i class="bi bi-exclamation-triangle-fill text-danger me-2"></i><?= hq_escape($accountDeletionFlash) ?>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if (!$user): ?><div class="alert alert-danger border-0">The live admin account could not be loaded.</div><?php else: ?>
        
        <div class="row g-4">
            
            <!-- Profile overview and personal information -->
            <div class="col-12 col-lg-6">
                
                <!-- Profile Avatar & Details Card -->
                <div class="dashboard-card p-4 h-100">
                    <div class="text-center pb-4 border-bottom border-white border-opacity-10">
                        <form action="update_avatar_action.php" method="POST" enctype="multipart/form-data" id="avatarForm">
                            <div class="profile-avatar-container mb-3 mx-auto">
                                <img src="<?= hq_escape($avatarUrl !== '' ? $avatarUrl : $avatarFallbackUrl) ?>" alt="Current profile avatar" class="profile-avatar-image" id="avatarPreview" onerror="this.onerror=null;this.src='<?= hq_escape($avatarFallbackUrl) ?>';">
                                <label for="avatarInput" class="avatar-upload-trigger" title="Upload new photo"><i class="bi bi-camera-fill"></i></label>
                                <input type="file" id="avatarInput" name="profile_avatar" accept="image/jpeg,image/png,image/gif,image/webp" class="d-none">
                            </div>
                            <button type="submit" class="btn btn-outline-info btn-sm rounded-3 px-3">Upload Image</button>
                        </form>

                        <h5 class="fw-bold text-white mb-1 mt-3"><?= hq_escape((string) $user['user_name']) ?></h5>
                        <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25 px-3 py-1 rounded-pill">
                            <?= hq_escape(ucwords((string) ($user['role_name'] ?? 'Admin'))) ?>
                        </span>
                    </div>

                    <div class="pt-4">
                        <h6 class="text-white-50 text-uppercase fw-semibold mb-3 small" style="letter-spacing:0.5px;">Account Overview</h6>
                        <div class="d-flex align-items-center justify-content-between py-2">
                            <span class="text-white-50 small">Role Status</span>
                            <span class="<?= hq_escape($statusClass((string) $user['status'])) ?> small fw-semibold">
                                <i class="bi bi-circle-fill me-1" style="font-size:.4rem;"></i><?= hq_escape($statusLabel((string) $user['status'])) ?>
                            </span>
                        </div>
                    </div>
                </div>

            </div>

            <div class="col-12 col-lg-6">
                <div class="dashboard-card p-4 h-100">
                    <h5 class="fw-bold text-white mb-4"><i class="bi bi-sliders txt-neon-cyan me-2"></i>Personal Information</h5>
                    <form action="update_profile_action.php" method="POST">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label text-white-50 small" for="profileUserName">Username / Display Name <span class="text-danger">*</span></label>
                                <input id="profileUserName" type="text" name="user_name" class="form-control glass-input" value="<?= hq_escape((string) $user['user_name']) ?>" maxlength="225" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label text-white-50 small" for="profileEmail">Registered Email Address <span class="text-danger">*</span></label>
                                <input id="profileEmail" type="email" name="email" class="form-control glass-input" value="<?= hq_escape((string) $user['email']) ?>" maxlength="255" required>
                            </div>
                            <div class="col-12 text-end mt-3">
                                <button type="submit" class="btn btn-neon px-4">Save Profile Changes</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Compact linked driver profile beside Security & Password -->
            <div class="col-12 col-lg-5">
                <div class="dashboard-card p-4 h-100">
                    <h6 class="fw-bold text-white mb-3"><i class="bi bi-fuel-pump txt-neon-cyan me-2"></i>Driver Gas Profile</h6>
                    <?php if ($hasCustomerProfile): ?>
                        <div class="small text-white-50 mb-2">
                            <span class="text-white fw-semibold"><?= hq_escape((string) $user['user_name']) ?></span>
                            <span class="d-block"><?= hq_escape((string) $user['email']) ?></span>
                        </div>
                        <?php if ($customerVehicle): ?>
                            <div class="small text-white-50 mb-3">
                                <i class="bi bi-car-front me-1 txt-neon-cyan"></i>
                                <?= hq_escape((string) $customerVehicle['car_plate_number']) ?>
                                <?php
                                $vehicleDescription = trim(implode(' / ', array_filter([
                                    trim((string) ($customerVehicle['vehicle_name'] ?? '')),
                                    trim((string) ($customerVehicle['vehicle_type'] ?? '')),
                                ])));
                                ?>
                                <?php if ($vehicleDescription !== ''): ?> <span class="text-white-50">· <?= hq_escape($vehicleDescription) ?></span><?php endif; ?>
                            </div>
                        <?php elseif ($customerVehicleLoadError): ?>
                            <p class="small text-warning mb-3">Vehicle details are temporarily unavailable.</p>
                        <?php else: ?>
                            <p class="small text-white-50 mb-3">No vehicle is linked to this driver profile yet.</p>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="small text-white-50 mb-3">No driver profile is linked to this account.</p>
                    <?php endif; ?>
                    <form action="../account_switch.php" method="POST" class="m-0">
                        <input type="hidden" name="mode" value="customer">
                        <button type="submit" class="btn btn-outline-info btn-sm rounded-3 w-100 fw-bold">
                            <i class="bi bi-arrow-left-right me-1"></i>Switch to Driver Profile
                        </button>
                    </form>
                </div>
            </div>

            <div class="col-12 col-lg-7">
                <div class="dashboard-card p-4">
                    <h5 class="fw-bold text-white mb-4"><i class="bi bi-shield-lock-fill txt-neon-cyan me-2"></i>Security &amp; Password</h5>
                    <form action="update_password_action.php" method="POST" id="passwordForm">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label text-white-50 small" for="currentPassword">Current Password <span class="text-danger">*</span></label>
                                <input id="currentPassword" type="password" name="current_password" class="form-control glass-input" required autocomplete="current-password">
                            </div>
                            <div class="col-12 col-sm-6">
                                <label class="form-label text-white-50 small" for="newPasswordInput">New Password <span class="text-danger">*</span></label>
                                <input id="newPasswordInput" type="password" name="new_password" class="form-control glass-input" minlength="8" maxlength="72" required autocomplete="new-password">
                                <div class="pw-strength-bar"><div class="pw-strength-fill" id="pwStrengthFill"></div></div>
                                <div class="pw-strength-label" id="pwStrengthLabel"></div>
                            </div>
                            <div class="col-12 col-sm-6">
                                <label class="form-label text-white-50 small" for="confirmPassword">Confirm New Password <span class="text-danger">*</span></label>
                                <input id="confirmPassword" type="password" name="confirm_password" class="form-control glass-input" minlength="8" maxlength="72" required autocomplete="new-password">
                            </div>
                            <div class="col-12">
                                <p class="text-white-50 small mb-0"><i class="bi bi-info-circle txt-neon-cyan me-1"></i>Use 8–72 characters with upper &amp; lowercase letters, a number, and a symbol.</p>
                                <div id="passwordMatchHint" class="small mt-1" aria-live="polite"></div>
                            </div>
                            <div class="col-12 text-end mt-3">
                                <button type="submit" class="btn btn-neon px-4" id="passwordSubmitBtn">Update Password</button>
                            </div>
                        </div>
                    </form>
                </div>

            </div>

            <div class="col-12">
                <div class="dashboard-card p-4 border border-danger border-opacity-25" style="background:rgba(255,77,106,.045)!important;">
                    <h6 class="fw-bold text-danger mb-2"><i class="bi bi-exclamation-triangle-fill me-2"></i>Delete Account</h6>
                    <p class="text-white-50 small">This deactivates your own Admin account and signs you out. Type <code>DELETE</code> to confirm. The last active Admin account cannot be deactivated.</p>
                    <form action="/php/account_self_delete.php" method="POST" class="row g-3 align-items-end">
                        <input type="hidden" name="return_to" value="/php/Admin(WebOwner)/edit_profile.php">
                        <div class="col-12 col-md-5"><label class="form-label text-white-50 small">Confirmation</label><input class="form-control glass-input" name="confirmation" autocomplete="off" placeholder="Type DELETE" required></div>
                        <div class="col-12 col-md-4"><label class="form-check text-white-50 small mb-0"><input class="form-check-input" type="checkbox" name="confirm_deletion" value="yes" required> I understand this disables my account.</label></div>
                        <div class="col-12 col-md-3 text-md-end"><button class="btn btn-outline-danger w-100" type="submit">Deactivate Account</button></div>
                    </form>
                </div>
            </div>

        </div>
        
        <?php endif; ?>
    </main>

    <footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map &amp; Pump | All Rights Reserved</footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script src="/js/Admin.js"></script><script>
        document.getElementById('avatarInput')?.addEventListener('change', (event) => {
            const file = event.target.files?.[0];
            if (!file) return;
            if (file.size > 2 * 1024 * 1024) {
                alert('Profile images must be 2 MB or smaller.');
                event.target.value = '';
                return;
            }
            document.getElementById('avatarPreview').src = URL.createObjectURL(file);
            document.getElementById('avatarForm').submit();
        });
        const passwordInput = document.getElementById('newPasswordInput');
        const confirmInput = document.getElementById('confirmPassword');
        const strengthFill = document.getElementById('pwStrengthFill');
        const strengthLabel = document.getElementById('pwStrengthLabel');
        const matchHint = document.getElementById('passwordMatchHint');
        const passwordForm = document.getElementById('passwordForm');
        const updateStrength = () => {
            const value = passwordInput?.value || '';
            let score = 0;
            if (value.length >= 8) score++;
            if (/[A-Z]/.test(value)) score++;
            if (/\d/.test(value)) score++;
            if (/[^A-Za-z0-9]/.test(value)) score++;
            const levels = [
                {width:'0%',color:'transparent',label:''},
                {width:'25%',color:'#ff4d6a',label:'Weak'},
                {width:'50%',color:'#ffc107',label:'Fair'},
                {width:'75%',color:'#38b6ff',label:'Good'},
                {width:'100%',color:'#1be494',label:'Strong'}
            ];
            const level = levels[score];
            if (strengthFill) { strengthFill.style.width = level.width; strengthFill.style.background = level.color; }
            if (strengthLabel) { strengthLabel.textContent = level.label; strengthLabel.style.color = level.color; }
        };
        const updateMatch = () => {
            if (!matchHint || !passwordInput || !confirmInput) return;
            if (!confirmInput.value) { matchHint.textContent = ''; return; }
            const ok = passwordInput.value === confirmInput.value;
            matchHint.textContent = ok ? 'Passwords match.' : 'Passwords do not match.';
            matchHint.style.color = ok ? '#1be494' : '#ff4d6a';
        };
        passwordInput?.addEventListener('input', () => { updateStrength(); updateMatch(); });
        confirmInput?.addEventListener('input', updateMatch);
        passwordForm?.addEventListener('submit', (event) => {
            if (passwordInput && confirmInput && passwordInput.value !== confirmInput.value) {
                event.preventDefault();
                updateMatch();
                confirmInput.focus();
            }
        });
    </script>
</body>
</html>
