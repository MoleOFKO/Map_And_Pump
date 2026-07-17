<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>HQ Management System</title>
    <link rel="stylesheet" href="../css/Style.css">
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
        .sidebar-menu {
            list-style: none;
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
                <h3 style="margin-bottom: 24px; padding-left: 16px; color: var(--primary);">HQ Dashboard</h3>
                <ul class="sidebar-menu">
                    <li onclick="routeTo('../brand/brand.php')" class="active">Brand Config</li>
                    <li onclick="routeTo('../price/pricing.php')">Oil Types & Pricing</li>
                    <li onclick="routeTo('../station/station.php')">Manage Stations</li>
                    <li onclick="routeTo('../report/report.php')">Manager Reports</li>
                    <li onclick="routeTo('../report/report_to_admin.php')">Report to Admin</li>
                    <li onclick="routeTo('../account/account.php')">Manager Accounts</li>
                    <li onclick="routeTo('../profile/profile.php')">Edit Profile</li>
                    <li onclick="routeTo('../switch_account/switch_account.php')" style="border-top: 1px solid var(--border); padding-top: 12px; margin-top: 12px;">Switch to Customer</li>
                </ul>
            </div>
            
            <ul class="sidebar-menu">
                <li onclick="handleLogout()" style="color: var(--danger); font-weight: bold;">Logout</li>
            </ul>
        </aside>

        <!-- Main Dynamic Viewport -->
        <main style="height: 100%; width: 100%;">
            <iframe id="viewport" class="content-frame" src="../brand/brand.php"></iframe>
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
                window.location.href = '../auth/login.php';
            }
        }
    </script>
</body>
</html>