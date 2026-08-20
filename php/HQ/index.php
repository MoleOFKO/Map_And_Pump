<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HQ Management System</title>
    <link rel="icon" type="image/png" href="/images/Logo.png">
    <link rel="stylesheet" href="../../css/HQstyle.css">
    <style>
        :root {
            --sidebar-width: 260px;
            --header-height: 60px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 0;
            overflow-x: hidden; 
            display: flex;
            height: 100vh;
            background: var(--bg, #0f172a);
            font-family: system-ui, -apple-system, sans-serif;
        }

        .dashboard-layout {
            display: grid;
            grid-template-columns: var(--sidebar-width) 1fr;
            width: 100%;
            height: 100%;
            position: relative;
            transition: all 0.3s ease;
        }

        /* Mobile Header Bar */
        .mobile-header {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: var(--header-height);
            background: var(--card-bg, #1e293b);
            border-bottom: 1px solid var(--border, #334155);
            padding: 0 16px;
            align-items: center;
            justify-content: space-between;
            z-index: 1001;
        }

        .mobile-toggle-btn {
            background: none;
            border: none;
            color: #fff;
            font-size: 1.5rem;
            cursor: pointer;
        }

        .mobile-brand {
            color: #fff;
            font-weight: bold;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .mobile-brand img {
            height: 24px;
            width: auto;
            object-fit: contain;
        }

        /* Sidebar Styling */
        .sidebar {
            background: var(--card-bg, #1e293b);
            border-right: 1px solid var(--border, #334155);
            padding: 24px 10px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            height: 100%;
            overflow-y: hidden;
            transition: transform 0.3s ease;
            z-index: 1002;
        }

        .sidebar-brand {
            padding: 12px;
            margin-top: -5px;
            margin-bottom: 12px;
            border: 1px solid var(--border, #334155);
            border-radius: 10px;
            background: rgba(255,255,255,0.03);
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            text-align: center;
        }

        .sidebar-section-title {
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--slate-400, #94a3b8);
            margin: 8px 0 8px 6px;
        }

        .sidebar-menu {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .sidebar-menu li {
            padding: 12px 10px;
            border-radius: 8px;
            cursor: pointer;
            margin-bottom: 4px;
            font-weight: 500;
            color: var(--slate-300, #cbd5e1);
            transition: all 0.25s ease;
        }

        .sidebar-menu li:hover {
            background: rgba(56, 189, 248, 0.08);
            color: var(--sky-400, #38bdf8);
            transform: translateX(4px);
        }

        .sidebar-menu li.active {
            background: linear-gradient(135deg, rgba(56, 189, 248, 0.15), rgba(29, 78, 216, 0.2));
            color: var(--sky-400, #38bdf8);
            font-weight: 600;
            border-left: 4px solid var(--sky-400, #38bdf8);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        .nav-logo {
            height: 30px;
            width: auto;
            object-fit: contain;
        }

        /* Main Viewport Container */
        .main-viewport {
            height: 100%;
            width: 100%;
            display: flex;
            flex-direction: column;
            position: relative;
        }

        /* Sticky Top Right User Profile Menu Container */
        .top-nav-bar {
            position: absolute;
            top: 16px;
            right: 24px;
            z-index: 1005;
            display: flex;
            align-items: center;
        }

        .user-menu-button {
            display: flex;
            align-items: center;
            gap: 10px;
            background: var(--card-bg, #1e293b);
            border: 1px solid var(--border, #334155);
            padding: 6px 14px 6px 6px;
            border-radius: 30px;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            transition: all 0.2s ease;
        }

        .user-menu-button:hover {
            border-color: var(--sky-400, #38bdf8);
        }

        .user-avatar-small {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
            background: #334155;
        }

        .user-menu-name {
            color: #fff;
            font-size: 0.9rem;
            font-weight: 500;
        }

        /* Dropdown Pop up */
        .user-dropdown-popup {
            display: none;
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            width: 260px;
            background: var(--card-bg, #1e293b);
            border: 1px solid var(--border, #334155);
            border-radius: 14px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            padding: 16px;
            z-index: 1006;
        }

        .user-dropdown-popup.active {
            display: block;
        }

        .dropdown-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border, #334155);
            margin-bottom: 12px;
        }

        .user-avatar-large {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            object-fit: cover;
            background: #334155;
        }

        .dropdown-user-info .user-title {
            color: #fff;
            font-weight: 600;
            font-size: 0.95rem;
            margin: 0;
        }

        .dropdown-user-info .user-email {
            color: var(--slate-400, #94a3b8);
            font-size: 0.8rem;
            margin: 2px 0 0 0;
        }

        .edit-profile-btn {
            width: 100%;
            background: rgba(56, 189, 248, 0.1);
            color: var(--sky-400, #38bdf8);
            border: 1px solid rgba(56, 189, 248, 0.2);
            padding: 8px 12px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            text-align: center;
            transition: background 0.2s ease;
        }

        .edit-profile-btn:hover {
            background: rgba(56, 189, 248, 0.2);
        }

        .content-frame {
            width: 100%;
            height: 100%;
            border: none;
            background: var(--bg, #0f172a);
        }

        /* Overlay for mobile sidebar */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
        }

        .logout-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.55);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }

        .logout-modal-overlay.active {
            display: flex;
        }

        .logout-modal {
            width: min(100%, 420px);
            background: var(--card-bg, #1e293b);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 18px;
            padding: 28px 24px;
            box-shadow: 0 24px 80px rgba(0, 0, 0, 0.35);
        }

        .logout-modal h2 {
            margin: 0 0 12px;
            font-size: 1.25rem;
            color: #fff;
        }

        .logout-modal p {
            margin: 0 0 20px;
            color: var(--slate-300, #cbd5e1);
            line-height: 1.6;
        }

        .logout-modal-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }

        .logout-modal-actions button {
            min-width: 120px;
            border: none;
            border-radius: 10px;
            padding: 10px 16px;
            font-weight: 600;
            cursor: pointer;
        }

        .logout-modal-actions .cancel-button {
            background: rgba(148, 163, 184, 0.12);
            color: #cbd5e1;
        }

        .logout-modal-actions .confirm-button {
            background: #ef4444;
            color: #fff;
        }

        /* Responsive Breakpoints */
        @media (max-width: 992px) {
            body {
                overflow: auto;
            }
            .dashboard-layout {
                grid-template-columns: 1fr;
                padding-top: var(--header-height);
            }
            .mobile-header {
                display: flex;
            }
            .sidebar {
                position: fixed;
                top: var(--header-height);
                left: 0;
                width: var(--sidebar-width);
                height: calc(100vh - var(--header-height));
                overflow-y: auto;
                transform: translateX(-100%);
            }
            .sidebar.mobile-open {
                transform: translateX(0);
            }
            .sidebar-overlay.active {
                display: block;
            }
            .main-viewport {
                height: calc(100vh - var(--header-height));
            }
            .top-nav-bar {
                top: calc(var(--header-height) + 12px);
                right: 16px;
                display: none;
            }
        }
    </style>
</head>
<?php
    // if (session_status() !== PHP_SESSION_ACTIVE) {
    //     session_start();
    // }

    require_once __DIR__ . '/../conn.php';

    $loggedUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
    $loggedUserEmail = isset($_SESSION['user_email']) ? trim((string) $_SESSION['user_email']) : '';

    // Fetch dynamic brand information from TiDB Cloud database table 'headquarter'
    $hq_id = isset($_SESSION['headquarter_id']) ? (int) $_SESSION['headquarter_id'] : 60010;
    $brand_title = 'Map & Pump';
    $brand_logo = '../../images/Logo.png';

    if ($loggedUserId > 0) {
        $hqStmt = $pdo->prepare(
            'SELECT h.company_name, h.company_logo, h.headquarter_id
             FROM users u
             LEFT JOIN headquarter h ON h.headquarter_id = u.headquarter_id
             WHERE u.user_id = ?
             LIMIT 1'
        );
        $hqStmt->execute([$loggedUserId]);
        $hqRow = $hqStmt->fetch(PDO::FETCH_ASSOC);
        if ($hqRow) {
            if (!empty($hqRow['company_name'])) {
                $brand_title = htmlspecialchars((string) $hqRow['company_name'], ENT_QUOTES, 'UTF-8');
            }
            if (!empty($hqRow['company_logo'])) {
                $brand_logo = htmlspecialchars((string) $hqRow['company_logo'], ENT_QUOTES, 'UTF-8');
            }
            if (!empty($hqRow['headquarter_id'])) {
                $hq_id = (int) $hqRow['headquarter_id'];
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
                $brand_logo = htmlspecialchars((string) $brandRow['company_logo'], ENT_QUOTES, 'UTF-8');
            }
        }
    }

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
<body>

    <!-- Mobile Top Navigation Bar -->
    <div class="mobile-header">
        <button class="mobile-toggle-btn" onclick="toggleSidebar()">☰</button>
        <div class="mobile-brand">
            <img src="<?php echo $brand_logo; ?>" alt="Logo">
            <span><?php echo $brand_title; ?></span>
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
                    <a href="index.php" class="text-decoration-none d-flex align-items-center justify-content-center gap-2 text-white fw-bold" style="text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 8px;">
                        <img src="<?php echo $brand_logo; ?>" alt="Logo" class="nav-logo">
                        <span style="font-size: 1.1rem; letter-spacing: 0.5px; color: #ffffff;"><?php echo $brand_title; ?></span>
                    </a>
                    <div style="font-size: 0.7rem; letter-spacing: 0.5px; color: var(--slate-400, #94a3b8); margin-top: 4px;">Headquarter Control Panel</div>
                </div>
                <div class="sidebar-section-title">Features</div>
                <ul class="sidebar-menu">
                    <li onclick="routeTo('dashboard.php')" class="active">DashBoard</li>
                    <li onclick="routeTo('brand.php')">Brand Configuration</li>
                    <li onclick="routeTo('pricing.php')">Oil Types & Pricing</li>
                    <li onclick="routeTo('station.php')">Manage Stations</li>
                    <li onclick="routeTo('report_from_manager.php')">Manager Reports</li>
                    <li onclick="routeTo('report_to_admin.php')">Report to Admin</li>
                    <li onclick="routeTo('account.php')">Manager Accounts</li>
                </ul>
            </div>
            
            <ul class="sidebar-menu">
                <li onclick="handleLogout()" style="color: var(--danger, #ef4444); font-weight: bold;">Logout</li>
            </ul>
        </aside>

        <!-- Main Dynamic Viewport -->
        <main class="main-viewport">
            <div class="top-nav-bar">
                <div style="display: flex; align-items: center; gap: 12px; position: relative;">
                    <!-- Notification Bell & Popup -->
                    <div style="position: relative;">
                        <button class="user-menu-button" onclick="toggleNotificationDropdown(event)" type="button" style="padding: 8px 10px;">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: #cbd5e1;">
                                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                                <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                            </svg>
                            <span style="position: absolute; top: 6px; right: 8px; width: 8px; height: 8px; background: #ef4444; border-radius: 50%;"></span>
                        </button>

                        <div class="user-dropdown-popup" id="notificationDropdownPopup" style="width: 300px;">
                            <div class="dropdown-header" style="justify-content: space-between;">
                                <p class="user-title" style="margin: 0;">Notifications</p>
                                <span style="font-size: 0.755rem; color: var(--sky-400, #38bdf8); cursor: pointer;">Mark all read</span>
                            </div>
                            <div style="display: flex; flex-direction: column; gap: 8px; max-height: 240px; overflow-y: auto;">
                                <?php if (empty($notifications)): ?>
                                    <div style="padding: 8px; background: rgba(255,255,255,0.02); border-radius: 6px; font-size: 0.85rem; color: var(--slate-300, #cbd5e1);">
                                        No notifications yet.
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($notifications as $notification): ?>
                                        <div style="padding: 8px; background: rgba(255,255,255,0.02); border-radius: 6px; font-size: 0.85rem; color: var(--slate-300, #cbd5e1);">
                                            <strong><?= htmlspecialchars((string) ($notification['subject'] ?? 'Notification'), ENT_QUOTES, 'UTF-8') ?></strong><br>
                                            <span style="font-size: 0.75rem; color: var(--slate-400, #94a3b8);">
                                                <?= htmlspecialchars((string) ($notification['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                            <div style="font-size: 0.7rem; color: var(--slate-500, #64748b); margin-top: 4px;">
                                                <?= htmlspecialchars((string) ($notification['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <button class="user-menu-button" onclick="toggleUserDropdown(event)" type="button">
                        <img src="../../images/default-avatar.png" alt="User Logo" class="user-avatar-small" id="headerUserAvatar">
                        <span class="user-menu-name" id="headerUserName">HQ Admin</span>
                    </button>

                    <div class="user-dropdown-popup" id="userDropdownPopup">
                        <div class="dropdown-header">
                            <img src="<?php echo $brand_logo; ?>" alt="User Logo" class="user-avatar-large" id="dropdownUserAvatar">
                            <div class="dropdown-user-info">
                                <p class="user-title" id="dropdownUserName">HQ Admin</p>
                                <p class="user-email" id="dropdownUserEmail">hq_admin@mapandpump.com</p>
                            </div>
                        </div>
                        <button type="button" class="edit-profile-btn" onclick="routeTo('profile.php'); closeUserDropdown();">Edit Profile</button>
                    </div>
                </div>
            </div>

            <iframe id="viewport" class="content-frame" src="dashboard.php"></iframe>
        </main>
    </div>

    <script>
    function routeTo(path) {
        path = path.trim();
        document.getElementById('viewport').src = path;
    
        document.querySelectorAll('.sidebar-menu li').forEach(item => {
            item.classList.remove('active');
            const onclickAttr = item.getAttribute('onclick') || '';
            const match = onclickAttr.match(/routeTo\(['"]([^'"]+)['"]\)/);
            if (match && match[1] === path) {
                item.classList.add('active');
            }
        });

        if (window.innerWidth <= 992) {
            toggleSidebar();
        }
    }

    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        sidebar.classList.toggle('mobile-open');
        overlay.classList.toggle('active');
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
    </script>
</body>
</html>