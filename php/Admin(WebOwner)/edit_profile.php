<?php
require_once __DIR__ . '/admin_common.php';
require_once __DIR__ . '/../profile_avatar.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$user = admin_current_user(true);
$flash = admin_pull_flash();
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="icon" type="image/png" href="/images/Logo.png">
    <link rel="stylesheet" href="/css/Admin.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body class="background_color" id="background_color">
    <div class="page-load-bar"></div>
    <div class="container sticky-nav-wrapper"><nav class="navbar navbar-dark text-white navigation_style"><div class="container-fluid d-flex justify-content-between align-items-center p-0 gap-3"><a class="navbar-brand d-flex align-items-center gap-2 fw-semibold txt-neon-cyan m-0" href="admin_dashboard.php" style="font-size:.95rem;"><img src="/images/Logo.png" alt="Logo" class="nav-logo"><span class="d-none d-sm-inline">Admin Portal</span></a><div class="admin-nav-actions"><label class="cosmic-toggle nav-theme-toggle" title="Toggle light mode"><input class="toggle js-theme-toggle" type="checkbox" aria-label="Toggle light mode"><div class="slider"><span class="theme-icon theme-icon-dark"><i class="bi bi-moon-stars-fill"></i></span><span class="theme-icon theme-icon-light"><i class="bi bi-sun-fill"></i></span><div class="cosmos"></div><div class="energy-line"></div><div class="energy-line"></div><div class="energy-line"></div><div class="toggle-orb"><div class="inner-orb"></div><div class="ring"></div></div><div class="particles"><div style="--angle:30deg" class="particle"></div><div style="--angle:60deg" class="particle"></div><div style="--angle:90deg" class="particle"></div><div style="--angle:120deg" class="particle"></div><div style="--angle:150deg" class="particle"></div><div style="--angle:180deg" class="particle"></div></div></div></label><button class="btn btn-sm menu-pill-btn" type="button" data-bs-toggle="offcanvas" data-bs-target="#mainMenuOffcanvas"><i class="bi bi-grid-fill me-1 txt-neon-cyan"></i> Menu</button></div></div></nav></div>

    <div class="container my-5" style="max-width:1140px;">
        <div class="mb-4">
            <span class="stat-chip mb-2"><i class="bi bi-person-badge-fill txt-neon-cyan"></i> Account Settings</span>
            <h1 class="fw-bold m-0 h4 text-white mt-2">Edit Profile Details</h1>
            <p class="text-white-50 m-0 small mt-1">Update the signed-in admin/moderator identity and security credentials.</p>
        </div>
        
        <?php if ($flash): ?><div class="alert alert-<?= hq_escape((string) ($flash['type'] ?? 'info')) ?> border-0" role="alert"><?= hq_escape((string) ($flash['message'] ?? '')) ?></div><?php endif; ?>
        <?php if (!$user): ?><div class="alert alert-danger border-0">The live admin account could not be loaded.</div><?php else: ?>
        
        <div class="row g-4">
            
            <!-- LEFT COLUMN: Profile Overview & Extended Switch Account Card -->
            <div class="col-12 col-lg-5 d-flex flex-column gap-4">
                
                <!-- Profile Avatar & Details Card -->
                <div class="dashboard-card p-4">
                    <div class="text-center pb-4 border-bottom border-white border-opacity-10">
                        <form action="update_avatar_action.php" method="POST" enctype="multipart/form-data" id="avatarForm">
                            <div class="profile-avatar-container mb-3 mx-auto">
                                <img src="<?= hq_escape($avatarUrl !== '' ? $avatarUrl : $avatarFallbackUrl) ?>" alt="Current profile avatar" class="profile-avatar-image" id="avatarPreview">
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
                        <div class="d-flex align-items-center justify-content-between py-2 border-bottom border-white border-opacity-10">
                            <span class="text-white-50 small">Role Status</span>
                            <span class="<?= hq_escape($statusClass((string) $user['status'])) ?> small fw-semibold">
                                <i class="bi bi-circle-fill me-1" style="font-size:.4rem;"></i><?= hq_escape($statusLabel((string) $user['status'])) ?>
                            </span>
                        </div>
                        <div class="d-flex align-items-center justify-content-between py-2 border-bottom border-white border-opacity-10">
                            <span class="text-white-50 small">Joined Since</span>
                            <span class="text-white small font-monospace"><?= hq_escape(admin_date((string) $user['created_at'])) ?></span>
                        </div>
                        <div class="d-flex align-items-center justify-content-between py-2">
                            <span class="text-white-50 small">Last Login</span>
                            <span class="text-white small font-monospace"><?= hq_escape(admin_date($user['last_login_at'] ?? null)) ?></span>
                        </div>
                    </div>
                </div>

                <!-- Extended Switch Account Card (Flex-grow fills vertical space to align with Security & Password card) -->
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
                            <div class="col-12 text-end mt-3">
                                <button type="submit" class="btn btn-neon px-4">Save Changes</button>
                            </div>
                        </div>
                    </form>
                </div>

            </div>

            <!-- FULL-WIDTH COLUMN: Database-backed Profile Notice Card -->
            <div class="col-12">
                <div class="dashboard-card p-4" style="border-color:rgba(0,244,255,.18);background:rgba(0,244,255,.025)!important;">
                    <h6 class="fw-bold text-white mb-2"><i class="bi bi-database-check txt-neon-cyan me-2"></i>Database-backed profile</h6>
                    <p class="text-white-50 small mb-0">The live schema stores the username, email, password hash, role, status, timestamps, and optional avatar URL. Unsupported fields are not displayed as editable fake values.</p>
                </div>
            </div>

        </div>
        
        <?php endif; ?>
    </div>

    <div class="offcanvas offcanvas-end" tabindex="-1" id="mainMenuOffcanvas" style="width:320px;background:rgba(6,12,24,.98);backdrop-filter:blur(28px);border-left:1px solid rgba(0,180,255,.18);"><div class="offcanvas-header pb-2 border-bottom border-white border-opacity-10"><div><h5 class="offcanvas-title text-white fw-bold mb-0">Main Menu</h5><p class="text-white-50 small mb-0">Admin Portal</p></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button></div><div class="offcanvas-body pt-3"><ul class="list-unstyled d-flex flex-column gap-2"><li><a href="admin_dashboard.php" class="menu-btn-nav"><i class="bi bi-house me-3 txt-neon-cyan"></i> Home</a></li><li><a href="view_hq_and_user_account.php" class="menu-btn-nav"><i class="bi bi-building-gear me-3 txt-neon-cyan"></i> View/Manage HQ</a></li><li><a href="view_users.php" class="menu-btn-nav"><i class="bi bi-people me-3 txt-neon-cyan"></i> View/Manage Users</a></li><li><a href="view_requests.php" class="menu-btn-nav"><i class="bi bi-file-earmark-check me-3 txt-neon-cyan"></i> View Requests</a></li><li><a href="view_report.php" class="menu-btn-nav"><i class="bi bi-graph-up-arrow me-3 txt-neon-cyan"></i> View Report</a></li><li><a href="edit_profile.php" class="menu-btn-nav active-nav"><i class="bi bi-person-bounding-box me-3 txt-neon-blue"></i> Edit Profile</a></li><li><a href="manage_moderators.php" class="menu-btn-nav"><i class="bi bi-people-fill me-3 txt-neon-blue"></i> Manage Moderators</a></li><li><a href="view_logs.php" class="menu-btn-nav"><i class="bi bi-terminal-split me-3 text-danger"></i> View Log Files</a></li><hr style="border-color:rgba(255,255,255,.08);margin:4px 0;"><li><form action="../Login/logout.php" method="post" class="m-0"><button type="submit" class="logout-btn-nav btn border-0 w-100"><i class="bi bi-box-arrow-right me-2"></i> Logout</button></form></li></ul></div></div>
    <footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map &amp; Pump | All Rights Reserved</footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script src="/js/Admin.js"></script><script>
        document.getElementById('avatarInput')?.addEventListener('change', (event) => { const file = event.target.files?.[0]; if (file) { document.getElementById('avatarPreview').src = URL.createObjectURL(file); document.getElementById('avatarForm').submit(); } });
        const passwordInput = document.getElementById('newPasswordInput'); const strengthFill = document.getElementById('pwStrengthFill'); const strengthLabel = document.getElementById('pwStrengthLabel');
        passwordInput?.addEventListener('input', () => { const value = passwordInput.value; let score = 0; if (value.length >= 8) score++; if (/[A-Z]/.test(value)) score++; if (/\d/.test(value)) score++; if (/[^A-Za-z0-9]/.test(value)) score++; const levels = [{width:'0%',color:'transparent',label:''},{width:'25%',color:'#ff4d6a',label:'Weak'},{width:'50%',color:'#ffc107',label:'Fair'},{width:'75%',color:'#38b6ff',label:'Good'},{width:'100%',color:'#1be494',label:'Strong'}]; const level = levels[score]; strengthFill.style.width = level.width; strengthFill.style.background = level.color; strengthLabel.textContent = level.label; strengthLabel.style.color = level.color; });
    </script>
</body>
</html>