<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Moderators - Map & Pump</title>
    <!-- Browser Tab Logo (Favicon) -->
    <link rel="icon" type="image/png" href="../images/Logo.png">
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../css/user_css.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root {
            --neon-cyan: #00f4ff;
            --neon-blue: #38b6ff;
            --neon-purple: #7a5cff;
            --glass-bg: rgba(10, 22, 44, 0.65);
            --glass-border: rgba(0, 180, 255, 0.15);
            --glass-hover-border: rgba(0, 244, 255, 0.35);
        }

        body.background_color {
            background-color: #050b14 !important;
            background-image:
                radial-gradient(circle at 50% 0%, rgba(0, 244, 255, 0.15), transparent 45%),
                radial-gradient(circle at 90% 20%, rgba(122, 92, 255, 0.08), transparent 30%),
                linear-gradient(180deg, #050b14 0%, #020408 100%);
            min-height: 100vh;
            color: #d9e6ff;
            font-family: "Inter", "Segoe UI", system-ui, -apple-system, sans-serif;
            letter-spacing: -0.01em;
        }

        /* STICKY CAPSULE NAVBAR */
        .sticky-nav-wrapper {
            position: sticky;
            top: 16px;
            z-index: 1020;
            display: flex;
            justify-content: center;
            padding: 0 16px;
        }

        .navigation_style {
            background: rgba(5, 11, 20, 0.8) !important;
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: 100px !important;
            padding: 8px 24px !important;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.5), 0 0 20px rgba(0, 244, 255, 0.05);
            width: 100%;
            max-width: 1200px;
        }

        .navigation_style .navbar-toggler {
            border: 1px solid rgba(255, 255, 255, 0.1);
            padding: 4px 8px;
        }

        .navigation_style .nav-link {
            color: #a3b8cc !important;
            transition: color 0.2s ease, text-shadow 0.2s ease;
        }

        .navigation_style .nav-link:hover {
            color: #ffffff !important;
            text-shadow: 0 0 10px rgba(0, 244, 255, 0.4);
        }

        .nav-logo {
            height: 24px;
            width: auto;
            object-fit: contain;
        }

        /* MODERN CARDS WITH GLASSMORPHISM */
        .dashboard-card,
        .analytics-card {
            background: var(--glass-bg) !important;
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.3);
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }

        .dashboard-card:hover,
        .analytics-card:hover {
            border-color: rgba(0, 244, 255, 0.25);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.35), 0 0 15px rgba(0, 244, 255, 0.04);
        }

        .dashboard-card {
            padding: 28px;
        }

        /* STAT CHIPS */
        .stat-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 100px;
            background: rgba(0, 244, 255, 0.08);
            border: 1px solid rgba(0, 244, 255, 0.2);
            color: #e6f9ff;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        /* GLASS FORM CONTROLS */
        .glass-input, .glass-select {
            background: rgba(4, 9, 21, 0.6) !important;
            border: 1px solid var(--glass-border) !important;
            color: #ffffff !important;
            border-radius: 12px !important;
            padding: 10px 16px;
            transition: all 0.2s ease;
        }

        .glass-input:focus, .glass-select:focus {
            border-color: var(--neon-cyan) !important;
            box-shadow: 0 0 10px rgba(0, 244, 255, 0.15) !important;
            outline: none;
        }

        /* ACTION BUTTONS */
        .btn-neon {
            background: linear-gradient(135deg, var(--neon-blue) 0%, var(--neon-cyan) 100%);
            border: none;
            color: #050b14;
            font-weight: 600;
            border-radius: 12px;
            padding: 10px 20px;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .btn-neon:hover {
            transform: translateY(-1px);
            box-shadow: 0 0 15px rgba(0, 244, 255, 0.4);
            color: #050b14;
        }

        .btn-action {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--glass-border);
            color: #d9e6ff;
            border-radius: 10px;
            width: 36px;
            height: 36px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .btn-action:hover {
            background: rgba(0, 244, 255, 0.1);
            border-color: var(--neon-cyan);
            color: #ffffff;
        }

        .btn-action-danger:hover {
            background: rgba(220, 53, 69, 0.15);
            border-color: #dc3545;
            color: #ff8591;
        }

        /* TABLES */
        .table-dark-custom {
            --bs-table-bg: transparent;
            --bs-table-border-color: rgba(255, 255, 255, 0.06);
            color: #d9e6ff;
            font-size: 0.9rem;
        }

        .table-dark-custom th,
        .table-dark-custom td {
            padding: 16px 12px;
        }

        .table-dark-custom thead th {
            color: #8fa0be;
            font-weight: 600;
            letter-spacing: 0.06em;
            font-size: 0.75rem;
            text-transform: uppercase;
            border-bottom: 2px solid rgba(0, 244, 255, 0.15);
        }

        /* GLASS MODAL */
        .glass-modal .modal-content {
            background: rgba(8, 15, 28, 0.95) !important;
            backdrop-filter: blur(25px);
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            color: #d9e6ff;
        }

        .glass-modal .modal-header, .glass-modal .modal-footer {
            border-color: rgba(255, 255, 255, 0.08);
        }

        /* OFF-CANVAS MENU BUTTONS */
        .menu-btn-nav {
            border: 1px solid var(--glass-border);
            border-radius: 14px !important;
            padding: 12px 18px !important;
            color: #a6bfdf;
            background: rgba(255, 255, 255, 0.02);
            text-decoration: none;
            display: flex;
            align-items: center;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            width: 100%;
        }

        .menu-btn-nav:hover {
            color: #ffffff;
            background: rgba(0, 244, 255, 0.08) !important;
            border-color: var(--glass-hover-border);
            transform: translateX(4px);
            box-shadow: 0 4px 15px rgba(0, 244, 255, 0.05);
        }

        .menu-btn-nav i {
            min-width: 28px;
            font-size: 1.15rem;
        }

        .logout-btn-nav {
            border: 1px solid rgba(220, 53, 69, 0.15);
            background: rgba(220, 53, 69, 0.04);
            border-radius: 14px !important;
            padding: 12px 18px !important;
            color: #ff8591;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            width: 100%;
            font-weight: 500;
        }

        .logout-btn-nav:hover {
            color: #ffffff;
            background: rgba(220, 53, 69, 0.25) !important;
            border-color: rgba(220, 53, 69, 0.4);
            transform: translateY(-1px);
        }

        .txt-neon-cyan {
            color: var(--neon-cyan) !important;
            text-shadow: 0 0 12px rgba(0, 244, 255, 0.25);
        }

        .txt-neon-blue {
            color: var(--neon-blue) !important;
            text-shadow: 0 0 12px rgba(56, 182, 255, 0.25);
        }

        footer {
            border-top: 1px solid rgba(255, 255, 255, 0.05) !important;
        }
    </style>
</head>
<body class="background_color" id="background_color">
    
    <!-- IPHONE CAPSULE NAVBAR -->
    <div class="container sticky-nav-wrapper">
        <nav class="navbar navbar-dark text-white navbar-expand-sm navigation_style">
            <div class="container-fluid d-flex justify-content-between align-items-center p-0">
                
                <div class="d-flex align-items-center">
                    <button class="navbar-toggler me-2 border-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapsibleNavbar">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                    <a class="navbar-brand d-sm-block d-none fw-semibold txt-neon-cyan" href="admin_dashboard.php" style="font-size: 0.95rem;">Owner Portal</a>
                </div>

                <div class="text-center">
                    <a href="admin_dashboard.php" class="text-decoration-none d-flex align-items-center justify-content-center gap-2 text-white fw-bold">
                        <img src="../images/Logo.png" alt="Logo" class="nav-logo">
                        <span style="font-size: 1rem; letter-spacing: 0.5px; color: #ffffff;">Map & Pump</span>
                    </a>
                </div>
                    
                <div class="collapse navbar-collapse flex-grow-0" id="collapsibleNavbar">
                    <ul class="navbar-nav ms-auto gap-2 text-center text-sm-start">
                        <li class="nav-item">
                            <a class="nav-link fw-semibold px-3 py-1 text-white-50" href="#" role="button" data-bs-toggle="offcanvas" data-bs-target="#mainMenuOffcanvas" style="font-size: 0.9rem;">
                                <i class="bi bi-grid-fill me-1 txt-neon-cyan"></i> Menu
                            </a>
                        </li>
                    </ul>
                </div>

            </div>
        </nav>
    </div>

    <!-- MAIN MANAGE CONTENT -->
    <div class="container my-5">
        
        <!-- Header Row -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <span class="stat-chip mb-2">
                    <i class="bi bi-shield-lock-fill txt-neon-cyan"></i>
                    Security Division
                </span>
                <h1 class="fw-bold m-0 h4 text-white">Manage System Administrators</h1>
                <p class="text-white-50 m-0 small">Provision new admin profiles, alter privilege permissions, or revoke staff accounts.</p>
            </div>
            <button class="btn btn-neon d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#addAdminModal">
                <i class="bi bi-person-plus-fill"></i> Add New Admin
            </button>
        </div>

        <!-- Metric KPI Grid -->
        <div class="row g-4 mb-4">
            <div class="col-12 col-sm-4">
                <div class="dashboard-card p-3 d-flex align-items-center justify-content-between">
                    <div>
                        <p class="text-white-50 mb-1 small uppercase">Total Admins</p>
                        <h4 class="fw-bold text-white mb-0">8 Active</h4>
                    </div>
                    <span class="fs-3 txt-neon-cyan"><i class="bi bi-people-fill"></i></span>
                </div>
            </div>
            <div class="col-12 col-sm-4">
                <div class="dashboard-card p-3 d-flex align-items-center justify-content-between">
                    <div>
                        <p class="text-white-50 mb-1 small uppercase">Active Privileges</p>
                        <h4 class="fw-bold text-white mb-0">Super, Standard</h4>
                    </div>
                    <span class="fs-3 txt-neon-blue"><i class="bi bi-key-fill"></i></span>
                </div>
            </div>
            <div class="col-12 col-sm-4">
                <div class="dashboard-card p-3 d-flex align-items-center justify-content-between">
                    <div>
                        <p class="text-white-50 mb-1 small uppercase">Last Security Sync</p>
                        <h4 class="fw-bold text-white mb-0">5 Mins Ago</h4>
                    </div>
                    <span class="fs-3 text-success"><i class="bi bi-shield-check"></i></span>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Main Table Panel -->
            <div class="col-12">
                <div class="analytics-card">
                    <div class="table-responsive">
                        <table class="table table-dark-custom align-middle m-0">
                            <thead>
                                <tr>
                                    <th>Admin Identity</th>
                                    <th>Email Address</th>
                                    <th>Designation Role</th>
                                    <th>Last Authentication</th>
                                    <th>Account State</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Super Admin Row (Example) -->
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width: 40px; height: 40px; background: rgba(0, 244, 255, 0.1); border: 1px solid var(--neon-cyan);">
                                                <i class="bi bi-person-workspace txt-neon-cyan"></i>
                                            </div>
                                            <div>
                                                <div class="fw-semibold text-white">Alex Mercer</div>
                                                <div class="text-white-50 small" style="font-size: 0.8rem;">Username: @alex_sys</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-white-50">alex.mercer@mapandpump.com</td>
                                    <td>
                                        <span class="badge rounded-pill bg-info bg-opacity-10 text-info border border-info border-opacity-25 px-2.5 py-1.5">Super Admin</span>
                                    </td>
                                    <td class="text-white-50">Jul 17, 2026 - 14:15</td>
                                    <td>
                                        <span class="badge rounded-pill bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2.5 py-1">Active</span>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-flex gap-2 justify-content-end">
                                            <a href="#" class="btn-action" title="Modify Permissions"><i class="bi bi-pencil-fill"></i></a>
                                            <a href="#" class="btn-action btn-action-danger" title="Revoke Admin Access"><i class="bi bi-person-x-fill"></i></a>
                                        </div>
                                    </td>
                                </tr>
                                <!-- Standard Admin Row (Example) -->
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width: 40px; height: 40px; background: rgba(122, 92, 255, 0.1); border: 1px solid var(--neon-purple);">
                                                <i class="bi bi-person-badge txt-neon-purple"></i>
                                            </div>
                                            <div>
                                                <div class="fw-semibold text-white">Ei Thinzar</div>
                                                <div class="text-white-50 small" style="font-size: 0.8rem;">Username: @ei_thinzar</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-white-50">ei.thinzar@mapandpump.com</td>
                                    <td>
                                        <span class="badge rounded-pill bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2.5 py-1.5">Standard Admin</span>
                                    </td>
                                    <td class="text-white-50">Jul 16, 2026 - 11:42</td>
                                    <td>
                                        <span class="badge rounded-pill bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2.5 py-1">Active</span>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-flex gap-2 justify-content-end">
                                            <a href="#" class="btn-action" title="Modify Permissions"><i class="bi bi-pencil-fill"></i></a>
                                            <a href="#" class="btn-action btn-action-danger" title="Revoke Admin Access"><i class="bi bi-person-x-fill"></i></a>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- ADD ADMIN MODAL -->
    <div class="modal fade glass-modal" id="addAdminModal" tabindex="-1" aria-labelledby="addAdminModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="addAdminModalLabel"><i class="bi bi-person-lock txt-neon-cyan me-2"></i> Register New Admin</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="add_admin_action.php" method="POST">
                    <div class="modal-body d-flex flex-column gap-3">
                        <div>
                            <label class="form-label text-white-50 small">Full Representative Name</label>
                            <input type="text" name="full_name" class="form-control glass-input" placeholder="e.g., Jonathan Doe" required>
                        </div>
                        <div>
                            <label class="form-label text-white-50 small">Unique Username</label>
                            <input type="text" name="username" class="form-control glass-input" placeholder="e.g., j_doe_sys" required>
                        </div>
                        <div>
                            <label class="form-label text-white-50 small">Enterprise Email Address</label>
                            <input type="email" name="email" class="form-control glass-input" placeholder="e.g., j.doe@mapandpump.com" required>
                        </div>
                        <div>
                            <label class="form-label text-white-50 small">Authorized Privilege Level</label>
                            <select class="form-select glass-select" name="role" required>
                                <option value="standard" selected>Standard Admin (Reports & Approvals)</option>
                                <option value="super">Super Admin (Global System Configs)</option>
                            </select>
                        </div>
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label text-white-50 small">Temporary Password</label>
                                <input type="password" name="password" class="form-control glass-input" placeholder="Minimum 8 chars" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label text-white-50 small">Confirm Temp Password</label>
                                <input type="password" name="confirm_password" class="form-control glass-input" placeholder="Repeat password" required>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary rounded-3 text-white-50 border-secondary border-opacity-20" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-neon">Provision Access</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- SIDEBAR MAIN MENU -->
    <div class="offcanvas offcanvas-end profile-offcanvas" tabindex="-1" id="mainMenuOffcanvas" style="width: 320px; background: rgba(8, 15, 28, 0.98); backdrop-filter: blur(25px); border-left: 1px solid rgba(0, 114, 255, 0.2);">
        <div class="offcanvas-header pb-2">
            <h5 class="offcanvas-title text-white fw-bold">Main Menu</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
        </div>
        <div class="offcanvas-body">
            <ul class="list-unstyled d-flex flex-column gap-3">
                <li>
                    <a href="view_company.php" class="menu-btn-nav">
                        <i class="bi bi-building-gear me-3 txt-neon-cyan"></i> View HQ & User Accounts
                    </a>
                </li>
                <li>
                    <a href="view_requests.php" class="menu-btn-nav">
                        <i class="bi bi-file-earmark-check me-3 txt-neon-cyan"></i> View Requests
                    </a>
                </li>
                <li>
                    <a href="view_report.php" class="menu-btn-nav">
                        <i class="bi bi-graph-up-arrow me-3 txt-neon-cyan"></i> View Report
                    </a>
                </li>
                <li>
                    <a href="edit_profile.php" class="menu-btn-nav">
                        <i class="bi bi-person-bounding-box me-3 txt-neon-blue"></i> Edit Profile
                    </a>
                </li>
                <li>
                    <a href="manage_moderators.php" class="menu-btn-nav">
                        <i class="bi bi-people-fill me-3 txt-neon-blue"></i> Manage Moderators
                    </a>
                </li>
                <li>
                    <a href="view_logs.php" class="menu-btn-nav">
                        <i class="bi bi-terminal-split me-3 text-danger"></i> View Log Files
                    </a>
                </li>
                <hr style="border-color: rgba(255, 255, 255, 0.1);">
                <li>
                    <form action="logout.php" method="post" class="m-0">
                        <button type="submit" class="logout-btn-nav btn border-0 w-100">
                            <i class="bi bi-box-arrow-right me-2"></i> Logout
                        </button>
                    </form>
                </li>
            </ul>
        </div>
    </div>

    <footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map & Pump | All Rights Reserved</footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>