<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../invitations_schema.php';
require_once __DIR__ . '/license_access.php';
require_once __DIR__ . '/license_expiry.php';
require_once __DIR__ . '/car_access.php';
require_once __DIR__ . '/../profile_avatar.php';

if (!isset($_SESSION['user_id']) || !ctype_digit((string) $_SESSION['user_id'])) {
    header('Location: ../Login/login.php');
    exit;
}

$currentPage = basename($_SERVER['PHP_SELF']);
$licenseExpiryState = customer_sync_license_expiry($pdo, (int) $_SESSION['user_id']);
$customerDriverLicenseExpired = ($licenseExpiryState['driver']['status'] ?? '') === 'expired';
$customerLicenseNotification = null;
$driverLicenseExpiry = $licenseExpiryState['driver'] ?? null;
if (is_array($driverLicenseExpiry)) {
    $licenseDaysLeft = (int) ($driverLicenseExpiry['days_left'] ?? PHP_INT_MAX);
    $licenseExpiryDate = (string) ($driverLicenseExpiry['expiry_date'] ?? '');
    if (($driverLicenseExpiry['status'] ?? '') === 'expired') {
        $customerLicenseNotification = [
            'title' => 'Driver license expired',
            'message' => 'Your driver license expired on ' . $licenseExpiryDate . '. Your account is restricted until you submit an updated license and a Moderator approves it.',
        ];
    } elseif ($licenseDaysLeft >= 0 && $licenseDaysLeft <= MAP_PUMP_LICENSE_WARNING_DAYS) {
        $expiryTiming = $licenseDaysLeft === 0 ? 'expires today' : 'expires in ' . $licenseDaysLeft . ' day' . ($licenseDaysLeft === 1 ? '' : 's');
        $customerLicenseNotification = [
            'title' => 'Driver license expiring soon',
            'message' => 'Your driver license ' . $expiryTiming . ' (' . $licenseExpiryDate . '). Update it before expiry. Every submitted update requires Moderator approval.',
        ];
    }
}
$customerLicenseNotificationCount = $customerLicenseNotification === null ? 0 : 1;
$customerSessionState = customer_refresh_session($pdo, (int) $_SESSION['user_id']);
$customerLicenseAccess = $customerSessionState['license'];

$customerPublicUserId = (string) $_SESSION['user_id'];
$customerDisplayName = trim((string) ($_SESSION['user_name'] ?? 'User'));
$customerEmail = trim((string) ($_SESSION['user_email'] ?? ''));
$customerAvatarUrl = map_pump_profile_avatar_url((string) ($_SESSION['oauth_avatar_url'] ?? ''), '');
$customerInitial = map_pump_profile_initial($customerDisplayName, 'U');
try {
    $publicIdColumn = $pdo->query(
        "SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'users'
           AND COLUMN_NAME = 'user_public_id'"
    );
    if ((int) $publicIdColumn->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE users ADD COLUMN user_public_id varchar(16) NULL AFTER user_id");
    }

    $publicIdStmt = $pdo->prepare(
        "SELECT COALESCE(NULLIF(user_public_id, ''), LPAD(UPPER(HEX(user_id)), 6, '0')) AS public_user_id
         FROM users
         WHERE user_id = ?
         LIMIT 1"
    );
    $publicIdStmt->execute([(int) $_SESSION['user_id']]);
    $customerPublicUserId = (string) ($publicIdStmt->fetchColumn() ?: $customerPublicUserId);

    $savePublicId = $pdo->prepare("UPDATE users SET user_public_id = ? WHERE user_id = ? AND (user_public_id IS NULL OR user_public_id = '')");
    $savePublicId->execute([$customerPublicUserId, (int) $_SESSION['user_id']]);
} catch (Throwable $e) {
    error_log('Failed to prepare customer public user ID: ' . $e->getMessage());
}

$linkedBusinessRoleNameHeader = '';
$pendingInvitation = null;
try {
    map_pump_ensure_invitation_schema($pdo);

    $profileModeStmt = $pdo->prepare(
        'SELECT u.user_name, u.email, u.oauth_avatar_url, u.role_id, u.business_role_id,
                primary_role.role_name AS primary_role_name,
                business_role.role_name AS business_role_name,
                customer_role.role_id AS customer_role_id
         FROM users u
         LEFT JOIN roles primary_role ON primary_role.role_id = u.role_id
         LEFT JOIN roles business_role ON business_role.role_id = u.business_role_id
         LEFT JOIN roles customer_role ON LOWER(customer_role.role_name) = \'customer\'
         WHERE u.user_id = ?
         LIMIT 1'
    );
    $profileModeStmt->execute([(int) $_SESSION['user_id']]);
    $profileModeRoles = $profileModeStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if ($profileModeRoles) {
        $customerDisplayName = trim((string) ($profileModeRoles['user_name'] ?? $customerDisplayName)) ?: $customerDisplayName;
        $customerEmail = trim((string) ($profileModeRoles['email'] ?? $customerEmail));
        $customerAvatarUrl = map_pump_profile_avatar_url((string) ($profileModeRoles['oauth_avatar_url'] ?? $customerAvatarUrl), '');
        $customerInitial = map_pump_profile_initial($customerDisplayName, 'U');
        $_SESSION['user_name'] = $customerDisplayName;
        $_SESSION['user_email'] = $customerEmail;
        if ($customerAvatarUrl !== '') {
            $_SESSION['oauth_avatar_url'] = $customerAvatarUrl;
        } else {
            unset($_SESSION['oauth_avatar_url']);
        }
    }
    $linkedBusinessRoleNameHeader = trim((string) ($profileModeRoles['business_role_name'] ?? ''));
} catch (Throwable $e) {
    error_log('Failed to load customer account profile modes: ' . $e->getMessage());
}

try {
    $invitationStmt = $pdo->prepare(
        "SELECT i.invitation_id, i.business_role_id, h.company_name, s.branch_name AS station_name
         FROM invitations i
         INNER JOIN headquarter h ON h.headquarter_id = i.headquarter_id
         INNER JOIN branches s ON s.branch_id = i.station_id
         WHERE i.invited_user_id = ? AND i.status = 'pending'
         ORDER BY i.created_at ASC LIMIT 1"
    );
    $invitationStmt->execute([(int)$_SESSION['user_id']]);
    $pendingInvitation = $invitationStmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    error_log('Failed to load HQ invitation: ' . $e->getMessage());
}

if ($customerDriverLicenseExpired && !in_array($currentPage, ['profile_page.php', 'license.php'], true)) {
    header('Location: profile_page.php');
    exit;
}
if (!$customerDriverLicenseExpired && $currentPage !== 'license.php' && !$customerLicenseAccess['is_approved']) {
    header('Location: license.php');
    exit;
}

// Dynamic title matching your flat file structure
$viewTitle = "Dashboard Overview";
if ($currentPage == 'track_book.php') $viewTitle = "Active Reservation Hub";
if ($currentPage == 'usage_history.php') $viewTitle = "Usage Reports Log";
if ($currentPage == 'feedback.php') $viewTitle = "Feedback & Replies";
if ($currentPage == 'profile_page.php') $viewTitle = "Portal Profile Settings";
if ($currentPage == 'license.php') $viewTitle = "Driver License Setup";
if ($currentPage == 'car_license.php') $viewTitle = "Car License Setup";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Map & Pump — <?php echo $viewTitle; ?></title>
    <link rel="icon" type="image/png" href="/images/Logo.png">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Noto+Sans+Myanmar:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../css/customer.css">
<link rel="stylesheet" href="../../css/customer-theme.css">
<script src="../../js/customer-theme.js"></script>

    <style>
        /* Layout + structure only. Colors come from customer.css (admin parity theme). */
        :root {
            --navy-950: #040a18; --navy-900: #0a162c; --navy-800: #101a30; --navy-700: #16213f;
            --blue-700: #0a58ca; --blue-500: #0d6efd; --sky-400: #0d6efd; --mint-400: #1be494;
            --amber-400: #ffc107; --red-400: #ff4d6a; --slate-50: #e8f3ff; --slate-300: #a3b8cc;
            --slate-400: #8b96ad;
        }
        body { background-color: var(--navy-950); color: var(--slate-50); font-family: 'Inter', system-ui, -apple-system, sans-serif; min-height: 100vh; overflow-x: hidden; }
        h1, h2, h3, h4, h5, .disp-brand, .token-no { font-family: 'Space Grotesk', 'Noto Sans Myanmar', 'Inter', sans-serif; font-weight: 700; letter-spacing: -0.02em; }

        .sidebar-nav { width: 280px; height: 100vh; position: fixed; top: 0; left: 0; display: flex; flex-direction: column; justify-content: space-between; z-index: 100; }
        .nav-item-link { display: flex; align-items: center; gap: 12px; padding: 14px 20px; text-decoration: none; border-radius: 12px; font-weight: 500; margin: 4px 16px; }

        .dashboard-header { height: 75px; position: sticky; top: 0; z-index: 90; margin-left: 280px; }
        .main-frame { margin-left: 280px; padding: 40px; min-height: calc(100vh - 75px); }

        .dashboard-panel-card { border-radius: 20px; padding: 28px; }
        .station-selector-card { border-radius: 16px; padding: 20px; cursor: pointer; }

        .status-pill { padding: 6px 14px; border-radius: 99px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; text-transform: uppercase; letter-spacing: 0.03em; }

        .step-circle { width: 38px; height: 38px; border-radius: 50%; border: 2px solid var(--navy-700); background-color: var(--navy-900); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px; color: var(--slate-400); transition: all 0.3s ease; }

        .custom-form-field { border-radius: 12px !important; padding: 12px 16px !important; }

        .fuel-tag { padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; display: inline-block; }
        .fuel-92 { background-color: #2e1065; color: #d8b4fe; }
        .fuel-95 { background-color: #064e3b; color: #6ee7b7; }
        .fuel-diesel { background-color: #7c2d12; color: #ffedd5; }

        @media (max-width: 991px) { .sidebar-nav { display: none; } .dashboard-header, .main-frame { margin-left: 0; } }
    </style>
</head>
<body>

    <?php if ($pendingInvitation && !$customerDriverLicenseExpired): ?>
        <div class="modal fade" id="hqInvitationModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-labelledby="hqInvitationTitle" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content" style="background:#0a162c;color:#e8f3ff;border:1px solid rgba(13,110,253,.45);border-radius:18px;">
                    <div class="modal-header border-0"><h5 class="modal-title" id="hqInvitationTitle"><i class="bi bi-building-check me-2 text-info"></i>Business invitation</h5></div>
                    <div class="modal-body"><p><strong><?= htmlspecialchars((string)$pendingInvitation['company_name'], ENT_QUOTES, 'UTF-8') ?></strong> invited you as a <strong><?= (int)$pendingInvitation['business_role_id'] === 4 ? 'Branch Manager' : 'Staff' ?></strong> for <strong><?= htmlspecialchars((string)$pendingInvitation['station_name'], ENT_QUOTES, 'UTF-8') ?></strong>.</p><p class="text-warning mb-0">You must Accept or Decline this invitation before you can use this account.</p></div>
                    <div class="modal-footer border-0">
                        <form method="POST" action="respond_invitation.php"><input type="hidden" name="invitation_id" value="<?= (int)$pendingInvitation['invitation_id'] ?>"><input type="hidden" name="decision" value="decline"><button type="submit" class="btn btn-outline-danger">Decline</button></form>
                        <form method="POST" action="respond_invitation.php"><input type="hidden" name="invitation_id" value="<?= (int)$pendingInvitation['invitation_id'] ?>"><input type="hidden" name="decision" value="accept"><button type="submit" class="btn btn-primary">Accept invitation</button></form>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <aside class="sidebar-nav">
        <div>
            <div class="p-4 d-flex align-items-center gap-3 border-bottom border-secondary border-opacity-10">
                <div class="text-sky-400 d-flex align-items-center justify-content-center" style="width: 42px; height: 42px;">
                    <img src="/images/Logo.png" alt="Map & Pump Logo" style="max-width: 100%; max-height: 100%; object-fit: contain;">
                </div>
                <div>
                    <span class="disp-brand fs-5 text-white">MAP & PUMP</span>
                    <small class="d-block text-secondary text-uppercase tracking-wider" style="font-size:9px;">Driver Portal</small>
                </div>
            </div>

            <div class="mt-4">
                <?php if ($customerDriverLicenseExpired): ?>
                    <a href="profile_page.php" class="nav-item-link <?php echo ($currentPage == 'profile_page.php') ? 'active' : ''; ?>">
                        <i class="bi bi-person-fill-gear"></i> Edit Profile
                    </a>
                    <a href="license.php?update=1" class="nav-item-link <?php echo ($currentPage == 'license.php') ? 'active' : ''; ?>">
                        <i class="bi bi-person-vcard-fill"></i> Renew Driver License
                    </a>
                <?php elseif ($customerLicenseAccess['is_approved']): ?>
                    <a href="dashboard.php" class="nav-item-link <?php echo ($currentPage == 'dashboard.php') ? 'active' : ''; ?>">
                        <i class="bi bi-grid-1x2-fill"></i> Dashboard
                    </a>
                    <a href="track_book.php" class="nav-item-link <?php echo ($currentPage == 'track_book.php') ? 'active' : ''; ?>">
                        <i class="bi bi-geo-alt-fill"></i> Track & Book
                    </a>
                    <a href="usage_history.php" class="nav-item-link <?php echo ($currentPage == 'usage_history.php') ? 'active' : ''; ?>">
                        <i class="bi bi-clipboard2-pulse-fill"></i> Usage History
                    </a>
                    <a href="feedback.php" class="nav-item-link <?php echo ($currentPage == 'feedback.php') ? 'active' : ''; ?>">
                        <i class="bi bi-chat-square-text-fill"></i> Feedback & Replies
                    </a>
                    <a href="profile_page.php" class="nav-item-link <?php echo ($currentPage == 'profile_page.php') ? 'active' : ''; ?>">
                        <i class="bi bi-person-fill-gear"></i> Edit Profile
                    </a>
                    <a href="car_license.php" class="nav-item-link <?php echo ($currentPage == 'car_license.php') ? 'active' : ''; ?>">
                        <i class="bi bi-car-front-fill"></i> Car License
                    </a>
                <?php endif; ?>
                <?php if (!$customerDriverLicenseExpired && !$customerLicenseAccess['is_approved']): ?>
                    <a href="license.php" class="nav-item-link <?php echo ($currentPage == 'license.php') ? 'active' : ''; ?>">
                        <i class="bi bi-person-vcard-fill"></i> Driver License
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="p-3 border-t border-secondary border-opacity-10 bg-black bg-opacity-20">
            <a href="profile_page.php" class="clickable-profile gap-3 mb-3" aria-label="Open profile settings">
                <?php if ($customerAvatarUrl !== ''): ?>
                    <img src="<?php echo htmlspecialchars($customerAvatarUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Profile image" class="rounded-circle" style="width:40px; height:40px; object-fit:cover; background:rgba(13,110,253,.08); border:1px solid rgba(13,110,253,.22);">
                <?php else: ?>
                    <div class="rounded-circle bg-info bg-opacity-10 text-info d-flex align-items-center justify-content-center" style="width:40px; height:40px; font-weight:700;">
                        <?php echo htmlspecialchars($customerInitial, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>
                <div>
                    <span class="fw-bold d-block text-white" style="font-size: 13px;"><?php echo htmlspecialchars($customerDisplayName, ENT_QUOTES, 'UTF-8'); ?></span>
                    <small class="text-secondary" style="font-size: 11px;">ID <?php echo htmlspecialchars($customerPublicUserId); ?></small>
                </div>
            </a>
            <?php if (!$customerDriverLicenseExpired && $linkedBusinessRoleNameHeader !== ''): ?>
                <form action="../account_switch.php" method="POST" class="mb-3">
                    <input type="hidden" name="mode" value="business">
                    <button type="submit" class="btn btn-outline-info btn-sm w-100 py-2 border-opacity-25" style="border-radius:10px;">
                        <i class="bi bi-arrow-left-right me-1"></i> Switch Account
                    </button>
                </form>
            <?php elseif (!$customerDriverLicenseExpired): ?>
                <a href="profile_page.php" class="btn btn-outline-info btn-sm w-100 py-2 border-opacity-25 mb-3" style="border-radius:10px;">
                    <i class="bi bi-arrow-left-right me-1"></i> Switch Account
                </a>
            <?php endif; ?>
            <a href="../Login/logout.php" class="btn btn-outline-danger btn-sm w-100 py-2 border-opacity-10" style="border-radius:10px;">
                <i class="bi bi-box-arrow-right me-1"></i> Sign Out Session
            </a>
        </div>
    </aside>

    <header class="dashboard-header d-flex align-items-center justify-content-between px-4">
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-dark d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileSidebar">
                <i class="fa-solid fa-bars"></i>
            </button>
            <h2 id="view-title" class="mb-0 fs-4 text-white"><?php echo $viewTitle; ?></h2>
        </div>

        <div class="d-flex align-items-center gap-4">
            <label class="customer-theme-toggle mb-0" title="Toggle day/night mode">
                <input class="js-customer-theme-toggle" type="checkbox" aria-label="Toggle day/night mode" role="switch">
                <span class="slider">
                    <span class="theme-icon moon"><i class="bi bi-moon-stars-fill"></i></span>
                    <span class="theme-icon sun"><i class="bi bi-sun-fill"></i></span>
                    <span class="toggle-spark spark-one"></span>
                    <span class="toggle-spark spark-two"></span>
                    <span class="orb"></span>
                </span>
            </label>
            <div class="position-relative" id="notif-hub">
                <button type="button" class="btn btn-link p-0 border-0 text-secondary" id="notif-bell" onclick="toggleAlertBox()" aria-label="Notifications, <?php echo $customerLicenseNotificationCount; ?> available" aria-expanded="false" aria-controls="notif-alert-panel" style="line-height:1;">
                    <i class="bi bi-bell-fill fs-5"></i>
                    <?php if ($customerLicenseNotificationCount > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" id="notif-count"><?php echo $customerLicenseNotificationCount; ?></span>
                    <?php endif; ?>
                </button>
                <div id="notif-alert-panel" class="position-absolute end-0 mt-3 bg-dark border border-secondary border-opacity-20 rounded-3 shadow-lg py-2 d-none" style="width:min(340px, calc(100vw - 32px)); z-index:1000;">
                    <div class="px-3 pb-2 border-bottom border-secondary border-opacity-10 fw-semibold text-white">Notifications</div>
                    <?php if ($customerLicenseNotification !== null): ?>
                        <a href="license.php?update=1" class="d-block px-3 py-3 text-decoration-none border-bottom border-secondary border-opacity-10" style="color:inherit;">
                            <span class="d-flex align-items-center gap-2 text-warning fw-semibold small"><i class="bi bi-exclamation-triangle-fill"></i><?php echo htmlspecialchars($customerLicenseNotification['title'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="d-block mt-2 text-white-50" style="font-size:12px;line-height:1.5;"><?php echo htmlspecialchars($customerLicenseNotification['message'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="d-inline-block mt-2 text-info small fw-semibold">Review or update license <i class="bi bi-arrow-up-right"></i></span>
                        </a>
                    <?php else: ?>
                        <p class="px-3 py-4 text-center text-secondary mb-0" style="font-size:12px;">No license notifications.</p>
                    <?php endif; ?>
                </div>
            </div>
            <span class="bg-secondary bg-opacity-25" style="width:1px; height:24px;"></span>
            <div class="d-none d-sm-flex align-items-center gap-2">
                <span class="status-pill active" style="font-size:10px;">Online Node</span>
            </div>
        </div>
    </header>

    <main class="main-frame">
        <?php if ($customerDriverLicenseExpired): ?>
            <div class="alert alert-danger" role="alert">
                Your driver license has expired. Your bookings have been cancelled. Access is limited to your profile and license renewal.
                <a class="alert-link" href="license.php?update=1">Renew your license</a>.
            </div>
        <?php endif; ?>
