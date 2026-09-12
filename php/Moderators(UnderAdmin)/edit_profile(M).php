<?php
session_start();

require_once __DIR__ . '/moderator_common.php';
require_once __DIR__ . '/../profile_avatar.php';

$moderatorUser = moderator_require_user();

$userId = (int) $moderatorUser['user_id'];
$flashMessage = '';
$flashType = 'success';

if (!empty($_SESSION['flash_message'])) {
    $flashMessage = (string) $_SESSION['flash_message'];
    $flashType = !empty($_SESSION['flash_type']) ? (string) $_SESSION['flash_type'] : 'success';
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

$stmt = $pdo->prepare(
    'SELECT u.user_id, u.user_name, u.email, u.oauth_avatar_url, u.created_at, u.last_login_at, u.status, u.role_id, r.role_name
     FROM users u
     LEFT JOIN roles r ON r.role_id = u.role_id
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
$joinedSince = $user['created_at'] ? date('M Y', strtotime((string) $user['created_at'])) : 'N/A';
$lastLogin = !empty($user['last_login_at']) ? date('M j, Y g:i A', strtotime((string) $user['last_login_at'])) : 'N/A';

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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body class="background_color" id="background_color">
<div class="page-load-bar"></div>

<?php include __DIR__ . '/includes/moderator_navbar.php'; ?>

    <div class="container my-5" style="max-width:1140px;">
        <div class="mb-4">
            <span class="stat-chip mb-2"><i class="bi bi-person-badge-fill txt-neon-cyan"></i> Account Settings</span>
            <h1 class="fw-bold m-0 h4 text-white mt-2">Edit Profile Details</h1>
            <p class="text-white-50 m-0 small mt-1">Update your account information and secure your moderator access.</p>
        </div>

        <?php if ($flashMessage !== ''): ?>
            <div class="alert alert-<?= htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8') ?> border-0" role="alert">
                <?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <div class="row g-4">

            <!-- LEFT COLUMN: Profile Overview & Extended Switch Account Card -->
            <div class="col-12 col-lg-5 d-flex flex-column gap-4">

                <!-- Profile Avatar & Details Card -->
                <div class="dashboard-card p-4">
                    <div class="text-center pb-4 border-bottom border-white border-opacity-10">
                        <form action="update_avatar_action.php" method="POST" enctype="multipart/form-data" id="avatarForm">
                            <div class="profile-avatar-container mb-3 mx-auto">
                                <img src="<?= htmlspecialchars($avatarUrl !== '' ? $avatarUrl : $avatarFallbackUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Current profile avatar" class="profile-avatar-image" id="avatarPreview">
                                <label for="avatarInput" class="avatar-upload-trigger" title="Upload new photo"><i class="bi bi-camera-fill"></i></label>
                                <input type="file" id="avatarInput" name="profile_avatar" accept="image/jpeg,image/png,image/gif,image/webp" class="d-none">
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
                        <div class="d-flex align-items-center justify-content-between py-2 border-bottom border-white border-opacity-10">
                            <span class="text-white-50 small">Role Status</span>
                            <span class="<?= htmlspecialchars($statusClass((string) $user['status']), ENT_QUOTES, 'UTF-8') ?> small fw-semibold">
                                <i class="bi bi-circle-fill me-1" style="font-size:.4rem;"></i><?= htmlspecialchars($statusLabel((string) $user['status']), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </div>
                        <div class="d-flex align-items-center justify-content-between py-2 border-bottom border-white border-opacity-10">
                            <span class="text-white-50 small">Joined Since</span>
                            <span class="text-white small font-monospace"><?= htmlspecialchars($joinedSince, ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div class="d-flex align-items-center justify-content-between py-2">
                            <span class="text-white-50 small">Last Login</span>
                            <span class="text-white small font-monospace"><?= htmlspecialchars($lastLogin, ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    </div>
                </div>

                <!-- Extended Switch Account Card (fills vertical space to align with Security & Password card) -->
                <div class="dashboard-card p-4 flex-grow-1 d-flex flex-column justify-content-between" style="border-color:rgba(0,244,255,.18);background:rgba(0,244,255,.025)!important;">
                    <div>
                        <h6 class="fw-bold text-white mb-2"><i class="bi bi-fuel-pump txt-neon-cyan me-2"></i>Customer Gas Profile</h6>
                        <p class="text-white-50 small mb-0">Switch to the customer side of this same account to manage your driver license and fill your car.</p>
                    </div>
                    <form action="../account_switch.php" method="POST" class="m-0 pt-3">
                        <input type="hidden" name="mode" value="customer">
                        <button type="submit" class="btn btn-outline-info btn-sm rounded-3 w-100 fw-bold">
                            <i class="bi bi-arrow-left-right me-1"></i>Switch to Customer Profile
                        </button>
                    </form>
                </div>

            </div>

            <!-- RIGHT COLUMN: Personal Info & Security Cards Stacked -->
            <div class="col-12 col-lg-7 d-flex flex-column gap-4">

                <div class="dashboard-card p-4">
                    <h5 class="fw-bold text-white mb-4"><i class="bi bi-sliders txt-neon-cyan me-2"></i>Personal Information</h5>
                    <form action="update_profile_action.php" method="POST">
                        <div class="row g-3">
                            <div class="col-12 col-sm-6">
                                <label class="form-label text-white-50 small" for="profileFullName">Full Name <span class="text-danger">*</span></label>
                                <input id="profileFullName" type="text" name="full_name" class="form-control glass-input" value="<?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>" maxlength="225" required>
                            </div>
                            <div class="col-12 col-sm-6">
                                <label class="form-label text-white-50 small" for="profileUsername">Username</label>
                                <input id="profileUsername" type="text" class="form-control glass-input" value="<?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>" disabled>
                            </div>
                            <div class="col-12">
                                <label class="form-label text-white-50 small" for="profileEmail">Registered Email Address <span class="text-danger">*</span></label>
                                <input id="profileEmail" type="email" name="email" class="form-control glass-input" value="<?= htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8') ?>" maxlength="255" required>
                            </div>
                            <div class="col-12 text-end mt-3">
                                <button type="submit" class="btn btn-neon px-4">Save Profile Changes</button>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="dashboard-card p-4">
                    <h5 class="fw-bold text-white mb-4"><i class="bi bi-shield-lock-fill txt-neon-cyan me-2"></i>Security &amp; Password</h5>
                    <form action="update_password_action.php" method="POST" id="passwordForm">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label text-white-50 small" for="currentPassword">Current Password <span class="text-danger">*</span></label>
                                <input id="currentPassword" type="password" name="current_password" class="form-control glass-input" placeholder="••••••••••••" required autocomplete="current-password">
                            </div>
                            <div class="col-12 col-sm-6">
                                <label class="form-label text-white-50 small" for="newPasswordInput">New Password <span class="text-danger">*</span></label>
                                <input id="newPasswordInput" type="password" name="new_password" class="form-control glass-input" placeholder="Min. 8 characters" minlength="8" maxlength="72" required autocomplete="new-password">
                                <div class="pw-strength-bar"><div class="pw-strength-fill" id="pwStrengthFill"></div></div>
                                <div class="pw-strength-label" id="pwStrengthLabel"></div>
                            </div>
                            <div class="col-12 col-sm-6">
                                <label class="form-label text-white-50 small" for="confirmPassword">Confirm New Password <span class="text-danger">*</span></label>
                                <input id="confirmPassword" type="password" name="confirm_password" class="form-control glass-input" placeholder="Repeat new password" minlength="8" maxlength="72" required autocomplete="new-password">
                            </div>
                            <div class="col-12 text-end mt-3">
                                <button type="submit" class="btn btn-neon px-4">Apply Security Update</button>
                            </div>
                        </div>
                    </form>
                </div>

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
