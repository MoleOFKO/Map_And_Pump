<?php
require_once __DIR__ . '/hq_guard.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../profile_avatar.php';

$loggedUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : '';
$loggedUserEmail = isset($_SESSION['user_email']) ? trim((string) $_SESSION['user_email']) : '';
$extensionReturnPages = ['dashboard.php', 'brand.php', 'station.php', 'pricing.php', 'loyalty_payouts.php', 'report_from_manager.php', 'report_to_admin.php', 'account.php', 'profile.php'];
$extensionReturnTo = basename((string) ($_GET['return_to'] ?? ''));
$extensionPaymentSubmitted = in_array((string) ($_GET['extension_payment'] ?? ''), ['submitted', 'cancelled'], true)
    && in_array($extensionReturnTo, $extensionReturnPages, true);
$extensionPaymentFlash = null;
if ($extensionPaymentSubmitted && isset($_SESSION['hq_extension_flash']) && is_array($_SESSION['hq_extension_flash'])) {
    $extensionPaymentFlash = $_SESSION['hq_extension_flash'];
    unset($_SESSION['hq_extension_flash']);
}

function resolveImageUrl(?string $imagePath, string $fallback): string
{
    $imagePath = trim((string) $imagePath);
    if ($imagePath === '') {
        return $fallback;
    }

    $imagePath = str_replace('\\', '/', $imagePath);

    if (preg_match('#^(https?:)?//#i', $imagePath) || str_starts_with($imagePath, 'data:')) {
        return $imagePath;
    }

    if (str_starts_with($imagePath, '/')) {
        return $imagePath;
    }

    $imagePath = ltrim(preg_replace('#^/?php/HQ/#', '', $imagePath), '/');

    if (str_starts_with($imagePath, 'uploads/')) {
        return '/php/HQ/' . $imagePath;
    }

    if (str_starts_with($imagePath, '../') || str_starts_with($imagePath, './')) {
        return $imagePath;
    }

    return '/php/HQ/' . $imagePath;
}

// Default brand and user variables
$hq_id = isset($_SESSION['headquarter_id']) ? (int) $_SESSION['headquarter_id'] : '';
$brand_title = 'Map & Pump';
$brand_logo = '../../images/Logo.png';

$user_display_name = 'HQ Admin';
$user_avatar = '';
$user_initial = map_pump_profile_initial($user_display_name, 'H');
$user_email_display = $loggedUserEmail ?: 'hq_admin@mapandpump.com';

if ($loggedUserId > 0) {
    // Fetch User Info, Headquarter Brand details, and OAuth avatar in a single query from database
    $userHqStmt = $pdo->prepare(
        'SELECT u.user_name, u.email, u.oauth_avatar_url, 
                  h.company_name, h.company_logo, h.headquarter_id
         FROM users u
              LEFT JOIN business_account_scope bas ON bas.user_id = u.user_id
              LEFT JOIN headquarter h ON (h.headquarters_owner_id = u.user_id OR h.headquarter_id = bas.headquarter_id)
         WHERE u.user_id = ?
         LIMIT 1'
    );
    $userHqStmt->execute([$loggedUserId]);
    $userHqRow = $userHqStmt->fetch(PDO::FETCH_ASSOC);

    if ($userHqRow) {
        // Set User Details from database
        if (!empty($userHqRow['user_name'])) {
            $user_display_name = htmlspecialchars((string) $userHqRow['user_name'], ENT_QUOTES, 'UTF-8');
            $user_initial = map_pump_profile_initial((string) $userHqRow['user_name'], 'H');
        }
        if (!empty($userHqRow['email'])) {
            $user_email_display = htmlspecialchars((string) $userHqRow['email'], ENT_QUOTES, 'UTF-8');
        }
        if (!empty($userHqRow['oauth_avatar_url'])) {
            $user_avatar = resolveImageUrl($userHqRow['oauth_avatar_url'], '');
        }

        // Set Brand Details from database
        if (!empty($userHqRow['company_name'])) {
            $brand_title = htmlspecialchars((string) $userHqRow['company_name'], ENT_QUOTES, 'UTF-8');
        }
        if (!empty($userHqRow['company_logo'])) {
            $brand_logo = resolveImageUrl($userHqRow['company_logo'], '/images/Logo.png');
        }
        if (!empty($userHqRow['headquarter_id'])) {
            $hq_id = (int) $userHqRow['headquarter_id'];
        }
    }
} else {
    $brandStmt = $pdo->prepare('SELECT company_name, company_logo FROM headquarter WHERE headquarter_id = ? LIMIT 1');
    $brandStmt->execute([$hq_id]);
    $brandRow = $brandStmt->fetch(PDO::FETCH_ASSOC);
    if ($brandRow) {
        if (!empty($brandRow['company_name'])) {
            $brand_title = htmlspecialchars((string) $brandRow['company_name'], ENT_QUOTES, 'UTF-8');
        }
        if (!empty($brandRow['company_logo'])) {
            $brand_logo = resolveImageUrl($brandRow['company_logo'], '/images/Logo.png');
        }
    }
}

// Fetch notifications from database
$notifications = [];
if ($loggedUserId > 0 && $loggedUserEmail !== '') {
    $notificationStmt = $pdo->prepare(
        'SELECT notification_id, subject, message, notification_type, created_at
         FROM notifications
         WHERE recipient_user_id = :user_id
            OR LOWER(recipient_email) = LOWER(:email)
         ORDER BY created_at DESC
         LIMIT 5'
    );
    $notificationStmt->execute([
        ':user_id' => $loggedUserId,
        ':email' => $loggedUserEmail,
    ]);
    $notifications = $notificationStmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($loggedUserId > 0) {
    $notificationStmt = $pdo->prepare(
        'SELECT notification_id, subject, message, notification_type, created_at
         FROM notifications
         WHERE recipient_user_id = :user_id
         ORDER BY created_at DESC
         LIMIT 5'
    );
    $notificationStmt->execute([':user_id' => $loggedUserId]);
    $notifications = $notificationStmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($loggedUserEmail !== '') {
    $notificationStmt = $pdo->prepare(
        'SELECT notification_id, subject, message, notification_type, created_at
         FROM notifications
         WHERE LOWER(recipient_email) = LOWER(:email)
         ORDER BY created_at DESC
         LIMIT 5'
    );
    $notificationStmt->execute([':email' => $loggedUserEmail]);
    $notifications = $notificationStmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Map&Pump (HQ) - Management System</title>
    <link rel="icon" type="image/png" href="../../images/Logo.png">
    <script src="../../js/hq-theme.js"></script>
<link rel="stylesheet" href="../../css/HQstyle.css">
    <link rel="stylesheet" href="../../css/HQshell.css">
</head>
<body>

    <!-- Mobile Top Navigation Bar -->
    <div class="mobile-header">
        <button type="button" aria-label="Toggle navigation" aria-controls="sidebar" aria-expanded="false" class="mobile-toggle-btn" onclick="toggleSidebar()">☰</button>
        <div class="mobile-brand">
            <img src="<?php
echo htmlspecialchars($brand_logo, ENT_QUOTES, 'UTF-8'); ?>" alt="Logo">
            <span><?php
echo $brand_title; ?></span>
        </div>
        <div style="width: 24px;"></div>
    </div>

    <!-- Background Overlay for Mobile Menu -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <div class="logout-modal-overlay" id="logoutModalOverlay" onclick="cancelLogout()">
        <div class="logout-modal" onclick="event.stopPropagation()">
            <h2>Confirm Logout</h2>
            <p>Are you sure you want to log out?</p>
            <div class="logout-modal-actions">
                <button type="button" class="cancel-button" onclick="cancelLogout()">Cancel</button>
                <button type="button" class="confirm-button" onclick="confirmLogout()">Logout</button>
            </div>
        </div>
    </div>

    <div class="dashboard-layout">
        <!-- Sidebar Navigation -->
        <aside class="sidebar" id="sidebar">
            <div>
                <div class="sidebar-brand">
                    <a href="index.php" style="text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 8px;">
                        <img src="<?php
echo htmlspecialchars($brand_logo, ENT_QUOTES, 'UTF-8'); ?>" alt="Logo" class="nav-logo">
                        <span style="font-size: 1.1rem; letter-spacing: 0.5px; color: var(--slate-50);"><?php
echo $brand_title; ?></span>
                    </a>
                    <div style="font-size: 0.7rem; letter-spacing: 0.5px; color: var(--slate-400, #94a3b8); margin-top: 4px;">Headquarters workspace</div>
                </div>
                <div class="sidebar-section-title">Workspace</div>
                <ul class="sidebar-menu">
                    <?php if (!$hqAccessRestricted): ?>
                    <li class="active"><button type="button" onclick="routeTo('dashboard.php')"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z"/></svg>Overview</button></li>
                    <?php endif; ?>
                    <?php if (empty($_SESSION['hq_activation_pending'])): ?>
                    <li class="<?= $hqAccessRestricted ? 'active' : '' ?>"><button type="button" onclick="routeTo('brand.php')"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l9 5v8l-9 5-9-5V8z M3 8l9 5 9-5 M12 13v8"/></svg>Brand Setting</button></li>
                    <li><button type="button" onclick="routeTo('extend_period.php')"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2 M16 2l3 3 M8 2 5 5"/></svg>Extend Service Period</button></li>
                    <?php endif; ?>
                    <?php if (!$hqAccessRestricted): ?>
                    <li><button type="button" onclick="routeTo('loyalty_payouts.php')"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1v22 M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>Discount payments</button></li>
                    <li><button type="button" onclick="routeTo('pricing.php')"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v18 M17 7H9a3 3 0 000 6h6a3 3 0 010 6H6"/></svg>Oil Types & Pricing</button></li>
                    <li><button type="button" onclick="routeTo('station.php')"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 21V4h10v17 M3 21h12 M6 7h6v5H6z M14 10h3l3 3v5a2 2 0 01-4 0v-4"/></svg>Manage Stations</button></li>
                    <li><button type="button" onclick="routeTo('report_from_manager.php')"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M5 3h14v18H5z M8 7h8 M8 11h8 M8 15h5"/></svg>Manager Reports</button></li>
                    <li><button type="button" onclick="routeTo('report_to_admin.php')"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16v12H8l-4 4z M8 8h8 M8 12h5"/></svg>Report to Admin</button></li>
                    <li><button type="button" onclick="routeTo('account.php')"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2 M9 3a4 4 0 110 8 4 4 0 010-8 M16 4a4 4 0 010 7 M22 21v-2a4 4 0 00-3-4"/></svg>Employee Accounts</button></li>
                    <?php endif; ?>
                </ul>
            </div>
            
            <ul class="sidebar-menu">
                <li><button type="button" class="logout-button" onclick="handleLogout()">Sign out</button></li>
            </ul>
        </aside>

        <!-- Main Dynamic Viewport -->
        <main class="main-viewport">
            <?php if (!empty($_SESSION['hq_activation_pending'])): ?><div role="status" style="margin:1rem 1.25rem;padding:1rem 1.25rem;border:1px solid rgba(255,193,7,.38);border-radius:10px;background:rgba(255,193,7,.08);color:#fef3c7;">Your HQ is pending Admin or Moderator approval. Management tools will be available after activation.</div><?php endif; ?>
            <?php if (is_array($extensionPaymentFlash) && !empty($extensionPaymentFlash['message'])): ?><div class="alert alert-success" role="status" style="margin:1rem 1.25rem;"> <?= htmlspecialchars((string) $extensionPaymentFlash['message'], ENT_QUOTES, 'UTF-8') ?><?php if (!empty($extensionPaymentFlash['show_payment_status'])): ?> <a href="/php/payment_center.php">View payment status and review process</a>.<?php endif; ?></div><?php endif; ?>
            <div class="top-nav-bar"><div class="hq-breadcrumb">Headquarters / <span id="currentPageLabel">Overview</span></div>
                <div style="display: flex; align-items: center; gap: 12px; position: relative;">
                    <button type="button" class="user-menu-button hq-theme-toggle" data-hq-theme-toggle aria-label="Switch to light mode" aria-pressed="false">
                        <svg class="theme-sun" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5"/></svg>
                        <svg class="theme-moon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M20 15.2A8.5 8.5 0 018.8 4 8.5 8.5 0 1020 15.2z"/></svg>
                    </button>
                    <!-- Notification Bell & Popup -->
                    <div style="position: relative;">
                        <button class="user-menu-button" onclick="toggleNotificationDropdown(event)" type="button" aria-label="Notifications" style="padding: 8px 10px;">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--slate-300);">
                                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                                <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                            </svg>
                            <?php if (!empty($notifications)): ?><span style="position: absolute; top: 6px; right: 8px; width: 8px; height: 8px; background: #ef4444; border-radius: 50%;"></span><?php endif; ?>
                        </button>

                        <div class="user-dropdown-popup" id="notificationDropdownPopup" style="width: 300px;">
                            <div class="dropdown-header" style="justify-content: space-between;">
                                <p class="user-title" style="margin: 0;">Notifications</p>
                            </div>
                            <div style="display: flex; flex-direction: column; gap: 8px; max-height: 240px; overflow-y: auto;">
                                <?php
if (empty($notifications)): ?>
                                    <div style="padding: 8px; background: rgba(255,255,255,0.02); border-radius: 6px; font-size: 0.85rem; color: var(--slate-300, #cbd5e1);">
                                        No notifications yet.
                                    </div>
                                <?php
else: ?>
                                    <?php
foreach ($notifications as $notification): ?>
                                        <div style="padding: 8px; background: rgba(255,255,255,0.02); border-radius: 6px; font-size: 0.85rem; color: var(--slate-300, #cbd5e1);">
                                            <strong><?= htmlspecialchars((string) ($notification['subject'] ?? 'Notification'), ENT_QUOTES, 'UTF-8') ?></strong><br>
                                            <span style="font-size: 0.75rem; color: var(--slate-400, #94a3b8);">
                                                <?= htmlspecialchars((string) ($notification['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                            <div style="font-size: 0.7rem; color: var(--slate-500, #64748b); margin-top: 4px;">
                                                <?= htmlspecialchars((string) ($notification['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                        </div>
                                    <?php
endforeach; ?>
                                <?php
endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- User Profile Menu Button (Top Right) -->
                    <button class="user-menu-button" onclick="toggleUserDropdown(event)" type="button">
                        <?php
if ($user_avatar !== ''): ?><img src="<?php
echo htmlspecialchars($user_avatar, ENT_QUOTES, 'UTF-8'); ?>" alt="User Avatar" class="user-avatar-small" id="headerUserAvatar"><?php
else: ?><span class="user-avatar-small user-avatar-initial" id="headerUserAvatar"><?php
echo htmlspecialchars($user_initial, ENT_QUOTES, 'UTF-8'); ?></span><?php
endif; ?>
                        <span class="user-menu-name" id="headerUserName"><?php
echo $user_display_name; ?></span>
                    </button>

                    <!-- User Dropdown Popup -->
                    <div class="user-dropdown-popup" id="userDropdownPopup">
                        <div class="dropdown-header">
                            <?php
if ($user_avatar !== ''): ?><img src="<?php
echo htmlspecialchars($user_avatar, ENT_QUOTES, 'UTF-8'); ?>" alt="User Avatar" class="user-avatar-large" id="dropdownUserAvatar"><?php
else: ?><span class="user-avatar-large user-avatar-initial" id="dropdownUserAvatar"><?php
echo htmlspecialchars($user_initial, ENT_QUOTES, 'UTF-8'); ?></span><?php
endif; ?>
                            <div class="dropdown-user-info">
                                <p class="user-title" id="dropdownUserName"><?php
echo $user_display_name; ?></p>
                                <p class="user-email" id="dropdownUserEmail"><?php
echo $user_email_display; ?></p>
                            </div>
                        </div>
                        <button type="button" class="edit-profile-btn" onclick="routeTo('profile.php'); closeUserDropdown();">Edit Profile</button>
                    </div>
                </div>
            </div>

            <?php if ($hqAccessRestricted): ?>
                <div class="alert alert-warning m-3" role="status">
                    Your business license <?= $userStatus === 'expired' ? 'has expired' : 'renewal is awaiting admin approval' ?>. Access to HQ operations is paused until the license is approved.
                </div>
            <?php endif; ?>
            <iframe title="HQ workspace content" id="viewport" class="content-frame" src="<?= htmlspecialchars($hqAccessRestricted ? 'brand.php' : ($extensionPaymentSubmitted ? $extensionReturnTo : 'dashboard.php'), ENT_QUOTES, 'UTF-8') ?>"></iframe>
        </main>
    </div>

    <script>
    document.querySelector('.sidebar-menu li.active button')?.setAttribute('aria-current', 'page');
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            closeUserDropdown(); closeNotificationDropdown(); cancelLogout();
            if (document.getElementById('sidebar').classList.contains('mobile-open')) toggleSidebar();
        }
    });
    function routeTo(path) {
        path = path.trim();
        const targetPage = path;
        if (path === 'extend_period.php') {
            const previousPage = new URL(document.getElementById('viewport').src, window.location.href).pathname.split('/').pop();
            const allowedReturnPages = ['dashboard.php', 'brand.php', 'station.php', 'pricing.php', 'loyalty_payouts.php', 'report_from_manager.php', 'report_to_admin.php', 'account.php', 'profile.php'];
            const returnTo = allowedReturnPages.includes(previousPage) ? previousPage : 'dashboard.php';
            path += '?return_to=' + encodeURIComponent(returnTo);
        }
        if (path === 'profile.php') document.getElementById('currentPageLabel').textContent = 'Profile';
        document.getElementById('viewport').src = path;
    
        document.querySelectorAll('.sidebar-menu li').forEach(item => {
            item.classList.remove('active');
            const button = item.querySelector('button');
            const onclickAttr = button?.getAttribute('onclick') || '';
            button?.removeAttribute('aria-current');
            const match = onclickAttr.match(/routeTo\(['"]([^'"]+)['"]\)/);
            if (match && match[1] === targetPage) {
                item.classList.add('active');
                button.setAttribute('aria-current', 'page');
                document.getElementById('currentPageLabel').textContent = button.textContent;
            }
        });

        if (window.innerWidth <= 992 && document.getElementById('sidebar').classList.contains('mobile-open')) {
            toggleSidebar();
        }
    }

    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        sidebar.classList.toggle('mobile-open');
        overlay.classList.toggle('active');
        document.querySelector('.mobile-toggle-btn').setAttribute('aria-expanded', sidebar.classList.contains('mobile-open'));
    }

    function closeUserDropdown() {
        const popup = document.getElementById('userDropdownPopup');
        if (popup) popup.classList.remove('active');
    }

    function closeNotificationDropdown() {
        const popup = document.getElementById('notificationDropdownPopup');
        if (popup) popup.classList.remove('active');
    }

    window.addEventListener('click', function() {
        closeUserDropdown();
        closeNotificationDropdown();
    });

    function handleLogout() {
        document.getElementById('logoutModalOverlay').classList.add('active');
    }

    function cancelLogout() {
        document.getElementById('logoutModalOverlay').classList.remove('active');
    }

    function confirmLogout() {
        window.location.href = '../Login/login.php';
    }

    function toggleNotificationDropdown(event) {
        event.stopPropagation();
        closeUserDropdown();
        const popup = document.getElementById('notificationDropdownPopup');
        popup.classList.toggle('active');
    }

    function toggleUserDropdown(event) {
        event.stopPropagation();
        closeNotificationDropdown();
        const popup = document.getElementById('userDropdownPopup');
        popup.classList.toggle('active');
    }

    window.addEventListener('message', function (event) {
        if (event.origin !== window.location.origin || event.data?.type !== 'map-pump-profile-updated') return;

        const profile = event.data.profile || {};
        if (profile.name) {
            document.getElementById('headerUserName').textContent = profile.name;
            document.getElementById('dropdownUserName').textContent = profile.name;
        }
        if (profile.email) {
            document.getElementById('dropdownUserEmail').textContent = profile.email;
        }
        if (profile.avatarUrl) {
            ['headerUserAvatar', 'dropdownUserAvatar'].forEach(function (id) {
                const currentAvatar = document.getElementById(id);
                if (!currentAvatar) return;
                const image = document.createElement('img');
                image.id = id;
                image.className = currentAvatar.className.replace('user-avatar-initial', '').trim();
                image.src = profile.avatarUrl + (profile.avatarUrl.includes('?') ? '&' : '?') + 'v=' + Date.now();
                image.alt = 'User Avatar';
                currentAvatar.replaceWith(image);
            });
        } else if (profile.name) {
            const initial = Array.from(profile.name.trim())[0]?.toUpperCase() || 'H';
            ['headerUserAvatar', 'dropdownUserAvatar'].forEach(function (id) {
                const currentAvatar = document.getElementById(id);
                if (currentAvatar?.classList.contains('user-avatar-initial')) {
                    currentAvatar.textContent = initial;
                }
            });
        }
    });
    </script>
<?php require_once __DIR__ . '/../moderator_hire_popup.php'; ?>
</body>
</html>
