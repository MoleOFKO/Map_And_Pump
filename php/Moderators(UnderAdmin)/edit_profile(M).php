<?php
session_start();

require_once __DIR__ . '/moderator_common.php';

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
    'SELECT u.user_id, u.user_name, u.email, u.oauth_avatar_url, u.created_at, u.status, u.role_id, r.role_name
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
if ($avatarUrl === '') {
    $avatarUrl = '/images/Logo.png';
}
$roleName = trim((string) ($user['role_name'] ?? 'Moderator'));
$accountStatus = ucfirst((string) ($user['status'] ?? 'active'));
$joinedSince = $user['created_at'] ? date('M Y', strtotime((string) $user['created_at'])) : 'N/A';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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

<?php include __DIR__ . '/includes/moderator_navbar.php'; ?>

    <div class="container my-5" style="max-width: 1000px;">
        <div class="mb-5">
            <span class="stat-chip mb-2">
                <i class="bi bi-person-badge-fill txt-neon-cyan"></i>
                Account Settings
            </span>
            <h1 class="fw-bold m-0 h4 text-white">Edit Profile Details</h1>
            <p class="text-white-50 m-0 small">Update your account information and secure your moderator access.</p>
        </div>

        <?php if ($flashMessage !== ''): ?>
            <div class="alert alert-<?= htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8') ?> rounded-3 mb-4" role="alert">
                <?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-12 col-md-4 d-flex flex-column gap-4">
                <div class="dashboard-card text-center py-5">
                    <form action="update_avatar_action.php" method="POST" enctype="multipart/form-data" id="avatarForm">
                        <div class="profile-avatar-container mb-4">
                            <img src="<?= htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Avatar" class="profile-avatar-image" id="avatarPreview">
                            <label for="avatarInput" class="avatar-upload-trigger" title="Upload new photo">
                                <i class="bi bi-camera-fill"></i>
                            </label>
                            <input type="file" id="avatarInput" name="profile_avatar" accept="image/*" class="d-none" onchange="document.getElementById('avatarForm').submit();">
                        </div>
                    </form>

                    <h5 class="fw-bold text-white mb-1"><?= htmlspecialchars($displayName !== '' ? $displayName : 'Moderator') ?></h5>
                    <p class="text-white-50 small mb-4"><?= htmlspecialchars($roleName) ?></p>

                    <div class="border-top border-white border-opacity-10 pt-4 text-start">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="text-white-50 small">Role Status</span>
                            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2.5 py-1"><?= htmlspecialchars($accountStatus) ?></span>
                        </div>
                        <div class="d-flex align-items-center justify-content-between">
                            <span class="text-white-50 small">Joined Since</span>
                            <span class="text-white-50 small font-monospace"><?= htmlspecialchars($joinedSince) ?></span>
                        </div>
                    </div>
                </div>
                <div class="dashboard-card p-4" style="border-color:rgba(0,244,255,.18);background:rgba(0,244,255,.025)!important;">
                    <h6 class="fw-bold text-white mb-2"><i class="bi bi-fuel-pump txt-neon-cyan me-2"></i>Customer Gas Profile</h6>
                    <p class="text-white-50 small mb-3">Switch to the customer side of this same account to manage your driver license and fill your car.</p>
                    <form action="../account_switch.php" method="POST">
                        <input type="hidden" name="mode" value="customer">
                        <button type="submit" class="btn btn-outline-info btn-sm rounded-3 w-100 fw-bold">
                            <i class="bi bi-arrow-left-right me-1"></i>Switch to Customer Profile
                        </button>
                    </form>
                </div>
            </div>

            <div class="col-12 col-md-8">
                <div class="d-flex flex-column gap-4">
                    <div class="dashboard-card">
                        <h5 class="fw-bold text-white mb-4 d-flex align-items-center gap-2">
                            <i class="bi bi-sliders txt-neon-cyan"></i> Personal Information
                        </h5>
                        <form action="update_profile_action.php" method="POST">
                            <div class="row g-3">
                                <div class="col-12 col-sm-6">
                                    <label class="form-label text-white-50 small">Full Name</label>
                                    <input type="text" name="full_name" class="form-control glass-input" value="<?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>" required>
                                </div>
                                <div class="col-12 col-sm-6">
                                    <label class="form-label text-white-50 small">Username</label>
                                    <input type="text" class="form-control glass-input" value="<?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>" disabled>
                                </div>
                                <div class="col-12">
                                    <label class="form-label text-white-50 small">Registered Email Address</label>
                                    <input type="email" name="email" class="form-control glass-input" value="<?= htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8') ?>" required>
                                </div>
                                <div class="col-12 text-end mt-2">
                                    <button type="submit" class="btn btn-neon">Save Profile Changes</button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <div class="dashboard-card">
                        <h5 class="fw-bold text-white mb-4 d-flex align-items-center gap-2">
                            <i class="bi bi-shield-lock-fill txt-neon-cyan"></i> Security & Password
                        </h5>
                        <form action="update_password_action.php" method="POST">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label text-white-50 small">Current Active Password</label>
                                    <input type="password" name="current_password" class="form-control glass-input" placeholder="••••••••••••" required>
                                </div>
                                <div class="col-12 col-sm-6">
                                    <label class="form-label text-white-50 small">New Password Entry</label>
                                    <input type="password" name="new_password" class="form-control glass-input" placeholder="Min. 8 characters" required>
                                </div>
                                <div class="col-12 col-sm-6">
                                    <label class="form-label text-white-50 small">Repeat Password Entry</label>
                                    <input type="password" name="confirm_password" class="form-control glass-input" placeholder="Repeat new password" required>
                                </div>
                                <div class="col-12 text-end mt-2">
                                    <button type="submit" class="btn btn-neon">Apply Security Update</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php $moderatorActiveNav = 'profile'; include __DIR__ . '/includes/moderator_offcanvas.php'; ?>

    <footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map & Pump | All Rights Reserved</footer>
<?php include __DIR__ . '/includes/moderator_footer_scripts.php'; ?>
</body>
</html>
