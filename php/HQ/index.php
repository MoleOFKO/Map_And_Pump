<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HQ Management System</title>
    <link rel="stylesheet" href="/css/HQstyle.css">
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
            /* Allow scrolling on mobile, lock viewport on large screens if desired */
            overflow-x: hidden; 
            display: flex;
            height: 100vh;
            background: var(--bg, #0f172a);
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
        }

        /* Sidebar Styling */
        .sidebar {
            background: var(--card-bg, #1e293b);
            border-right: 1px solid var(--border, #334155);
            padding: 24px 16px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            height: 100%;
            overflow-y: auto;
            transition: transform 0.3s ease;
            z-index: 1002;
        }

        .sidebar-brand {
            padding: 12px;
            margin-bottom: 12px;
            border: 1px solid var(--border, #334155);
            border-radius: 10px;
            background: rgba(255,255,255,0.03);
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
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
            padding: 12px 16px;
            border-radius: 8px;
            cursor: pointer;
            margin-bottom: 6px;
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
        }
    </style>
</head>
<body>

    <!-- Mobile Top Navigation Bar -->
    <div class="mobile-header">
        <button class="mobile-toggle-btn" onclick="toggleSidebar()">☰</button>
        <div class="mobile-brand">Map & Pump HQ</div>
        <div style="width: 24px;"></div> <!-- Spacer for balancing layout -->
    </div>

    <!-- Background Overlay for Mobile Menu -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <div class="dashboard-layout">
        <!-- Sidebar Navigation -->
        <aside class="sidebar" id="sidebar">
            <div>
                <div class="sidebar-brand text-center">
                    <a href="index.php" class="text-decoration-none d-flex align-items-center justify-content-center gap-1 text-white fw-bold">
                        <img src="/images/Logo.png" alt="Logo" class="nav-logo">
                        <span style="font-size: 1.3rem; letter-spacing: 0.5px; color: #ffffff;">Map & Pump</span>
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
                    <li onclick="routeTo('profile.php')">Edit Profile</li>
                </ul>
            </div>
            
            <ul class="sidebar-menu">
                <li onclick="routeTo('switch_account.php')" style="border-top: 1px solid rgba(255, 255, 255, 0.06); padding-top: 12px; margin-top: 12px;">Switch to Customer Account</li>
                <li onclick="handleLogout()" style="color: var(--danger, #ef4444); font-weight: bold;">Logout</li>
            </ul>
        </aside>

        <!-- Main Dynamic Viewport -->
        <main class="main-viewport">
            <iframe id="viewport" class="content-frame" src="dashboard.php"></iframe>
        </main>
    </div>

    <script>
    function routeTo(path) {
        document.getElementById('viewport').src = path.trim();
    
        // Handle Navigation Highlights cleanly
        document.querySelectorAll('.sidebar-menu li').forEach(item => {
            item.classList.remove('active');
            const onclickAttr = item.getAttribute('onclick') || ''; 
            if (onclickAttr.includes(path.trim())) {
                item.classList.add('active');
            }
        });

        // Auto-close sidebar on mobile after clicking an item
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
    </script>
</body>
</html>