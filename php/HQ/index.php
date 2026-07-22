<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>HQ Management System</title>
    <link rel="stylesheet" href="/css/HQstyle.css">
    <style>
        body {
            padding: 0;
            overflow: hidden;
            display: flex;
            height: 100vh;
        }
        .dashboard-layout {
            display: grid;
            grid-template-columns: 260px 1fr;
            width: 100%;
            height: 100%;
        }
        .sidebar {
            background: var(--card-bg);
            border-right: 1px solid var(--border);
            padding: 24px 16px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .sidebar-brand {
            padding: 12px;
            margin-bottom: 12px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: rgba(255,255,255,0.03);
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .sidebar-section-title {
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--text-muted);
            margin: 8px 0 8px 6px;
        }
        .sidebar-menu {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .sidebar-menu li {
            padding: 12px 16px;
            border-radius: 6px;
            cursor: pointer;
            margin-bottom: 6px;
            font-weight: 500;
            transition: all 0.2s;
            color: var(--text-muted);
        }
        .sidebar-menu li:hover, .sidebar-menu li.active {
            background: var(--bg);
            color: var(--primary);
            font-weight: 600;
        }
        .nav-logo {
            height: 30px;
            width: auto;
            object-fit: contain;
        }
        .content-frame {
            width: 100%;
            height: 100%;
            border: none;
            background: var(--bg);
        }
    </style>
</head>
<body>

    <div class="dashboard-layout">
        <!-- Sidebar Navigation -->
        <aside class="sidebar">
            <div>
                <div class="sidebar-brand text-center">
                    <a href="index.php" class="text-decoration-none d-flex align-items-center justify-content-center gap-2 text-white fw-bold">
                        <img src="/images/Logo.png" alt="Logo" class="nav-logo">
                        <span style="font-size: 1.5rem; letter-spacing: 0.5px; color: #ffffff;">Map & Pump</span>
                    </a>
                </div>
                <div class="sidebar-section-title">Features</div>
                <ul class="sidebar-menu">
                    <li onclick="routeTo('dashboard.php')" class="active">HQ DashBoard</li>
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
                <li onclick="routeTo(' switch_account.php')" style="border-top: 1px solid var(--border); padding-top: 12px; margin-top: 12px;">Switch to Customer Account</li>
                <li onclick="handleLogout()" style="color: var(--danger); font-weight: bold;">Logout</li>
            </ul>
        </aside>

        <!-- Main Dynamic Viewport -->
        <main style="height: 100%; width: 100%;">
            <iframe id="viewport" class="content-frame" src="dashboard.php"></iframe>
        </main>
    </div>

    <script>
        // Module Router Injection
        function routeTo(path) {
            document.getElementById('viewport').src = path;
            
            // Handle Navigation Highlights
            document.querySelectorAll('.sidebar-menu li').forEach(item => {
                item.classList.remove('active');
            });
            event.target.classList.add('active');
        }

        function handleLogout() {
            if(confirm("Are you sure you want to log out?")) {
                window.location.href = 'login.php';
            }
        }
    </script>
</body>
</html>