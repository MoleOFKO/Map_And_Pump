<?php
session_start();

require_once __DIR__ . '/moderator_common.php';
require_once __DIR__ . '/../profile_avatar.php';

$moderatorUser = moderator_require_user();

$userId = (int) $moderatorUser['user_id'];
$flashMessage = '';
$flashType = 'success';
$accountDeletionFlash = (string) ($_SESSION['account_deletion_flash'] ?? '');
unset($_SESSION['account_deletion_flash']);

if (!empty($_SESSION['flash_message'])) {
    $flashMessage = (string) $_SESSION['flash_message'];
    $flashType = !empty($_SESSION['flash_type']) ? (string) $_SESSION['flash_type'] : 'success';
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

$stmt = $pdo->prepare(
    'SELECT u.user_id, u.user_name, u.email, u.oauth_avatar_url, u.status,
            COALESCE(u.current_role_id, u.business_role_id, u.role_id) AS role_id,
            COALESCE(cr.role_name, br.role_name, r.role_name) AS role_name
     FROM users u
     LEFT JOIN roles r ON r.role_id = u.role_id
     LEFT JOIN roles br ON br.role_id = u.business_role_id
     LEFT JOIN roles cr ON cr.role_id = u.current_role_id
     WHERE u.user_id = ?
     LIMIT 1'
);
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    $_SESSION['flash_message'] = 'We could not locate your account details.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ../Login/logout.php');
    exit;
}

$displayName = trim((string) ($user['user_name'] ?? ''));
$userEmail = trim((string) ($user['email'] ?? ''));
$avatarUrl = trim((string) ($user['oauth_avatar_url'] ?? ''));
$avatarInitial = map_pump_profile_initial($displayName, 'M');
$avatarFallbackUrl = map_pump_profile_initial_svg($displayName, 'M');
$roleName = trim((string) ($user['role_name'] ?? 'Moderator'));
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Map &amp; Pump Moderator — Edit profile details and secure credentials.">
    <title>Edit Profile - Map & Pump</title>
    <link rel="icon" type="image/png" href="/images/Logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="/css/Admin.css">
    <link rel="stylesheet" href="/css/moderator_blue_theme.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .mod-flash{border-radius:14px;background:rgba(10,22,44,.82);border:1px solid var(--glass-border);color:var(--text-primary)}
        body.background_color.light-mode .mod-flash{background:rgba(255,255,255,.92)}
        .admin-blue-page .sticky-nav-wrapper .navigation_style{background:rgba(11,18,32,.98)!important;backdrop-filter:blur(16px)}
        .admin-blue-page.light-mode .sticky-nav-wrapper .navigation_style,.admin-blue-page[data-theme="light"] .sticky-nav-wrapper .navigation_style{background:rgba(255,255,255,.98)!important}
        .admin-blue-page .admin-blue-shell .form-label{display:block;width:100%;margin-bottom:.5rem}
        .admin-blue-page .admin-blue-shell .form-control{display:block;width:100%;min-width:0}
        .profile-settings-card { padding:clamp(24px,3vw,36px)!important; }
        .profile-settings-heading { display:flex; align-items:center; gap:12px; margin-bottom:28px; color:var(--ab-text); font-size:22px; font-weight:600; letter-spacing:-.025em; }
        .profile-settings-heading i { display:grid; place-items:center; width:38px; height:38px; border:1px solid var(--ab-border); border-radius:10px; background:var(--ab-raised); color:var(--ab-accent); font-size:17px; }
        .profile-settings-form { display:grid; gap:24px; }
        .profile-settings-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:20px 24px; }
        .profile-field { min-width:0; }.profile-field--full { grid-column:1 / -1; }
        .profile-field label { display:flex; align-items:center; gap:4px; margin:0 0 8px; color:var(--ab-muted); font-size:12px; font-weight:500; }
        .profile-field .form-control { width:100%; min-height:50px; padding:12px 14px; border-radius:10px; font-size:14px; }
        .profile-field .form-control:disabled { border-color:var(--ab-border); background:rgba(24,37,55,.55); color:var(--ab-muted); opacity:.7; }
        .profile-field-note { margin:7px 0 0; color:var(--ab-muted); font-size:11px; }
        .profile-form-actions { display:flex; justify-content:flex-end; padding-top:4px; border-top:1px solid var(--ab-border); }.profile-form-actions .btn { min-width:210px; min-height:48px; font-size:13px!important; }
        .profile-settings-card .pw-strength-bar { margin-top:10px; }
        .profile-security-card { display:flex; align-items:center; justify-content:space-between; gap:20px; }.profile-security-copy { min-width:0; }.profile-security-copy p { margin:0; color:var(--ab-muted); font-size:13px; }.profile-security-card .btn { flex:0 0 auto; min-width:170px; min-height:48px; }
        @media (min-width:992px) { .profile-settings-column > .profile-settings-card { flex:1 1 0; } }
        @media (max-width:575.98px) { .profile-settings-card { padding:22px!important; }.profile-settings-grid { grid-template-columns:1fr; gap:18px; }.profile-field--full { grid-column:auto; }.profile-form-actions .btn { width:100%; }.profile-settings-heading { margin-bottom:22px; font-size:20px; } }
        @media (max-width:575.98px) { .profile-security-card { align-items:stretch; flex-direction:column; }.profile-security-card .btn { width:100%; } }
    </style>
</head>
<body class="background_color admin-blue-page" id="background_color">
<?php include __DIR__ . '/includes/moderator_navbar.php'; ?>

    <div class="container my-5 admin-blue-shell" style="max-width:1140px;">
        <div class="admin-blue-header mb-4">
            <div class="companies-eyebrow">Account settings</div>
            <h1 class="mb-0">Edit Profile Details</h1>
            <p class="admin-blue-description">Update your moderator profile, avatar, and sign-in credentials.</p>
        </div>

        <?php if ($flashMessage !== ''): ?>
            <div class="alert mod-flash alert-<?= htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8') ?> border-0" role="alert">
                <?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>
        <?php if ($accountDeletionFlash !== ''): ?><div class="alert mod-flash mod-flash alert-danger border-0" role="alert"><?= htmlspecialchars($accountDeletionFlash, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

        <div class="row g-4">

            <!-- Profile overview -->
            <div class="col-12 col-lg-6">
                <div class="dashboard-card profile-overview-card p-4 h-100">
                    <div class="text-center pb-4 border-bottom border-white border-opacity-10">
                        <form action="update_avatar_action.php" method="POST" enctype="multipart/form-data" id="avatarForm">
                            <div class="profile-avatar-container mb-3 mx-auto">
                                <img src="<?= htmlspecialchars($avatarUrl !== '' ? $avatarUrl : $avatarFallbackUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Current profile avatar" class="profile-avatar-image" id="avatarPreview">
                                <label for="avatarInput" class="avatar-upload-trigger" title="Upload new photo"><i class="bi bi-camera-fill"></i></label>
                                <input type="file" id="avatarInput" name="profile_avatar" accept="image/jpeg,image/png,image/webp" class="d-none">
                            </div>
                            <button type="submit" class="btn btn-outline-info btn-sm rounded-3 px-3">Upload Image</button>
                        </form>

                        <h5 class="fw-bold text-white mb-1 mt-3"><?= htmlspecialchars($displayName !== '' ? $displayName : 'Moderator', ENT_QUOTES, 'UTF-8') ?></h5>
                        <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25 px-3 py-1 rounded-pill">
                            <?= htmlspecialchars(ucwords($roleName), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </div>

                    <div class="pt-4">
                        <h6 class="text-white-50 text-uppercase fw-semibold mb-3 small" style="letter-spacing:0.5px;">Account Overview</h6>
                        <div class="d-flex align-items-center justify-content-between py-2">
                            <span class="text-white-50 small">Role Status</span>
                            <span class="<?= htmlspecialchars($statusClass((string) $user['status']), ENT_QUOTES, 'UTF-8') ?> small fw-semibold">
                                <i class="bi bi-circle-fill me-1" style="font-size:.4rem;"></i><?= htmlspecialchars($statusLabel((string) $user['status']), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Personal information -->
            <div class="col-12 col-lg-6">
                <div class="dashboard-card profile-settings-card profile-settings-card--personal h-100">
                    <h2 class="profile-settings-heading"><i class="bi bi-sliders"></i>Personal Information</h2>
                    <form action="update_profile_action.php" method="POST" class="profile-settings-form">
                        <div class="profile-settings-grid">
                            <div class="profile-field">
                                <label for="profileFullName">Full Name <span class="text-danger">*</span></label>
                                <input id="profileFullName" type="text" name="full_name" class="form-control glass-input" value="<?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>" maxlength="225" required>
                            </div>
                            <div class="profile-field">
                                <label for="profileUsername">Username</label>
                                <input id="profileUsername" type="text" class="form-control glass-input" value="<?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>" disabled>
                                <p class="profile-field-note">Usernames are managed by an administrator.</p>
                            </div>
                            <div class="profile-field profile-field--full">
                                <label for="profileEmail">Registered Email Address <span class="text-danger">*</span></label>
                                <input id="profileEmail" type="email" name="email" class="form-control glass-input" value="<?= htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8') ?>" maxlength="255" required>
                            </div>
                        </div>
                        <div class="profile-form-actions">
                            <button type="submit" class="btn btn-neon px-4">Save Profile Changes</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Driver Profile switch -->
            <div class="col-12 col-lg-6">
                <div class="dashboard-card p-4 h-100" style="border-color:rgba(0,244,255,.18);background:rgba(0,244,255,.025)!important;">
                    <h6 class="fw-bold text-white mb-2"><i class="bi bi-fuel-pump txt-neon-cyan me-2"></i>Driver Gas Profile</h6>
                    <form action="../account_switch.php" method="POST" class="m-0 mt-3">
                        <input type="hidden" name="mode" value="customer">
                        <button type="submit" class="btn btn-outline-info btn-sm rounded-3 w-100 fw-bold">
                            <i class="bi bi-arrow-left-right me-1"></i>Switch to Driver Profile
                        </button>
                    </form>
                </div>
            </div>

            <!-- Security -->
            <div class="col-12 col-lg-6">
                <div class="dashboard-card profile-settings-card h-100">
                    <div class="profile-security-card">
                        <div class="profile-security-copy">
                            <h2 class="profile-settings-heading mb-2"><i class="bi bi-shield-lock-fill"></i>Security &amp; Password</h2>
                            <p>Use a strong, unique password to keep your moderator account protected.</p>
                        </div>
                        <button type="button" class="btn btn-neon" data-bs-toggle="modal" data-bs-target="#changePasswordModal"><i class="bi bi-key-fill"></i>Change Password</button>
                    </div>
                </div>
            </div>

            <div class="col-12"><div class="dashboard-card p-4 border border-danger border-opacity-25" style="background:rgba(255,77,106,.045)!important;"><h6 class="fw-bold text-danger mb-2"><i class="bi bi-exclamation-triangle-fill me-2"></i>Delete Account</h6><p class="text-white-50 small">This deactivates your Moderator account and signs you out. Type <code>DELETE</code> to continue.</p><form action="/php/account_self_delete.php" method="POST" class="row g-3 align-items-end"><input type="hidden" name="return_to" value="/php/Moderators(UnderAdmin)/edit_profile(M).php"><div class="col-12 col-md-5"><label class="form-label text-white-50 small">Confirmation</label><input class="form-control glass-input" name="confirmation" autocomplete="off" placeholder="Type DELETE" required></div><div class="col-12 col-md-4"><label class="form-check text-white-50 small mb-0"><input class="form-check-input" type="checkbox" name="confirm_deletion" value="yes" required> I understand this disables my account.</label></div><div class="col-12 col-md-3 text-md-end"><button class="btn btn-outline-danger w-100" type="submit">Deactivate Account</button></div></form></div></div>

        </div>
    </div>

    <div class="modal fade" id="changePasswordModal" tabindex="-1" aria-labelledby="changePasswordModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <div class="companies-eyebrow mb-1">Account security</div>
                        <h2 class="modal-title" id="changePasswordModalTitle">Change Password</h2>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="update_password_action.php" method="POST" id="passwordForm">
                    <div class="modal-body">
                        <p class="text-white-50 small mb-4">Confirm your current password, then choose a new password with at least 8 characters.</p>
                        <div class="profile-settings-grid">
                            <div class="profile-field profile-field--full">
                                <label for="currentPassword">Current Password <span class="text-danger">*</span></label>
                                <input id="currentPassword" type="password" name="current_password" class="form-control glass-input" placeholder="Enter your current password" required autocomplete="current-password">
                            </div>
                            <div class="profile-field">
                                <label for="newPasswordInput">New Password <span class="text-danger">*</span></label>
                                <input id="newPasswordInput" type="password" name="new_password" class="form-control glass-input" placeholder="Min. 8 characters" minlength="8" maxlength="72" required autocomplete="new-password">
                                <div class="pw-strength-bar"><div class="pw-strength-fill" id="pwStrengthFill"></div></div>
                                <div class="pw-strength-label" id="pwStrengthLabel"></div>
                            </div>
                            <div class="profile-field">
                                <label for="confirmPassword">Confirm New Password <span class="text-danger">*</span></label>
                                <input id="confirmPassword" type="password" name="confirm_password" class="form-control glass-input" placeholder="Repeat new password" minlength="8" maxlength="72" required autocomplete="new-password">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-neon"><i class="bi bi-shield-check"></i>Update Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<?php $moderatorActiveNav = 'profile'; include __DIR__ . '/includes/moderator_offcanvas.php'; ?>

    <footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map & Pump | All Rights Reserved</footer>
<?php include __DIR__ . '/includes/moderator_footer_scripts.php'; ?>
    <script>
        document.getElementById('avatarInput')?.addEventListener('change', (event) => {
            const file = event.target.files?.[0];
            if (file) {
                document.getElementById('avatarPreview').src = URL.createObjectURL(file);
                document.getElementById('avatarForm').submit();
            }
        });
        const passwordInput = document.getElementById('newPasswordInput');
        const strengthFill = document.getElementById('pwStrengthFill');
        const strengthLabel = document.getElementById('pwStrengthLabel');
        passwordInput?.addEventListener('input', () => {
            const value = passwordInput.value;
            let score = 0;
            if (value.length >= 8) score++;
            if (/[A-Z]/.test(value)) score++;
            if (/\d/.test(value)) score++;
            if (/[^A-Za-z0-9]/.test(value)) score++;
            const levels = [
                { width: '0%', color: 'transparent', label: '' },
                { width: '25%', color: '#ff4d6a', label: 'Weak' },
                { width: '50%', color: '#ffc107', label: 'Fair' },
                { width: '75%', color: '#38b6ff', label: 'Good' },
                { width: '100%', color: '#1be494', label: 'Strong' },
            ];
            const level = levels[score];
            strengthFill.style.width = level.width;
            strengthFill.style.background = level.color;
            strengthLabel.textContent = level.label;
            strengthLabel.style.color = level.color;
        });
    </script>
</body>
</html>
</content>
