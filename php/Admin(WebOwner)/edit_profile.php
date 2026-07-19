<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile - Map & Pump</title>
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

        .glass-input::placeholder {
            color: rgba(217, 230, 255, 0.4);
        }

        .glass-input:disabled {
            background: rgba(255, 255, 255, 0.02) !important;
            border-color: rgba(255, 255, 255, 0.05) !important;
            color: rgba(217, 230, 255, 0.3) !important;
        }

        /* ACTION BUTTONS */
        .btn-neon {
            background: linear-gradient(135deg, var(--neon-blue) 0%, var(--neon-cyan) 100%);
            border: none;
            color: #050b14;
            font-weight: 600;
            border-radius: 12px;
            padding: 10px 24px;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .btn-neon:hover {
            transform: translateY(-1px);
            box-shadow: 0 0 15px rgba(0, 244, 255, 0.4);
            color: #050b14;
        }

        /* PROFILE IMAGE WORKSPACE */
        .profile-avatar-container {
            position: relative;
            width: 110px;
            height: 110px;
            margin: 0 auto;
        }

        .profile-avatar-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
            border: 2px solid var(--neon-blue);
            box-shadow: 0 0 15px rgba(56, 182, 255, 0.25);
        }

        .avatar-upload-trigger {
            position: absolute;
            bottom: 0;
            right: 0;
            background: var(--neon-cyan);
            color: #050b14;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            cursor: pointer;
            box-shadow: 0 0 10px rgba(0, 244, 255, 0.5);
            transition: transform 0.2s ease;
        }

        .avatar-upload-trigger:hover {
            transform: scale(1.1);
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
    <div class="container my-5" style="max-width: 1000px;">
        
        <!-- Header Row -->
        <div class="mb-5">
            <span class="stat-chip mb-2">
                <i class="bi bi-person-badge-fill txt-neon-cyan"></i>
                Account Settings
            </span>
            <h1 class="fw-bold m-0 h4 text-white">Edit Profile Details</h1>
            <p class="text-white-50 m-0 small">Modify your personal details, secure credentials, and update system authorization info.</p>
        </div>

        <div class="row g-4">
            
            <!-- Left Panel: Avatar & Core Information + Account Switcher -->
            <div class="col-12 col-md-4 d-flex flex-column gap-4">
                
                <!-- Avatar Card -->
                <div class="dashboard-card text-center py-5">
                    <form action="update_avatar_action.php" method="POST" enctype="multipart/form-data" id="avatarForm">
                        <div class="profile-avatar-container mb-4">
                            <img src="https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?auto=format&fit=crop&w=150&q=80" alt="Avatar" class="profile-avatar-image" id="avatarPreview">
                            <label for="avatarInput" class="avatar-upload-trigger">
                                <i class="bi bi-camera-fill"></i>
                            </label>
                            <input type="file" id="avatarInput" name="profile_avatar" accept="image/*" class="d-none" onchange="document.getElementById('avatarForm').submit();">
                        </div>
                    </form>
                    
                    <h5 class="fw-bold text-white mb-1">Owner Admin</h5>
                    <p class="text-white-50 small mb-4">Super Administrator</p>
                    
                    <div class="border-top border-white border-opacity-10 pt-4 text-start">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="text-white-50 small">Role Status</span>
                            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2.5 py-1">Active</span>
                        </div>
                        <div class="d-flex align-items-center justify-content-between">
                            <span class="text-white-50 small">Joined Since</span>
                            <span class="text-white-50 small font-monospace">Feb 2026</span>
                        </div>
                    </div>
                </div>

                <!-- NEW: Role Switcher Action Card -->
                <div class="dashboard-card text-center p-4 border border-info border-opacity-20" style="background: rgba(0, 244, 255, 0.03) !important;">
                    <div class="rounded-circle d-flex align-items-center justify-content-center mx-auto mb-3" style="width: 50px; height: 50px; background: rgba(0, 244, 255, 0.1); border: 1px dashed var(--neon-cyan);">
                        <i class="bi bi-person-bounding-box txt-neon-cyan fs-4"></i>
                    </div>
                    <h6 class="fw-bold text-white mb-1">Dual Account Link</h6>
                    <p class="text-white-50 small mb-4">Quickly toggle contexts to manage maps, pumps, and check consumer functionality.</p>
                    
                    <!-- Direct Form Action to handle Session Switching -->
                    <form action="../Customer/customer_dashboard.php" method="POST" class="m-0">
                        <input type="hidden" name="target_role" value="customer">
                        <button type="submit" class="btn btn-outline-info w-100 rounded-3 text-white border-info border-opacity-25 d-flex align-items-center justify-content-center gap-2" style="background: rgba(0, 244, 255, 0.05); padding: 11px;">
                            <i class="bi bi-arrow-left-right text-info"></i> Switch to Customer View
                        </button>
                    </form>
                </div>

            </div>

            <!-- Right Panel: Profile and Password Form Workspaces -->
            <div class="col-12 col-md-8">
                <div class="d-flex flex-column gap-4">
                    
                    <!-- Section 1: Profile Information -->
                    <div class="dashboard-card">
                        <h5 class="fw-bold text-white mb-4 d-flex align-items-center gap-2">
                            <i class="bi bi-sliders txt-neon-cyan"></i> Personal Information
                        </h5>
                        <form action="update_profile_action.php" method="POST">
                            <div class="row g-3">
                                <div class="col-12 col-sm-6">
                                    <label class="form-label text-white-50 small">Full Name</label>
                                    <input type="text" name="full_name" class="form-control glass-input" value="Owner Admin" required>
                                </div>
                                <div class="col-12 col-sm-6">
                                    <label class="form-label text-white-50 small">Username</label>
                                    <input type="text" class="form-control glass-input" value="admin_owner" disabled>
                                </div>
                                <div class="col-12">
                                    <label class="form-label text-white-50 small">Registered Email Address</label>
                                    <input type="email" name="email" class="form-control glass-input" value="owner@mapandpump.com" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label text-white-50 small">Contact Mobile</label>
                                    <input type="tel" name="phone" class="form-control glass-input" value="+95 9 123 456 789" required>
                                </div>
                                <div class="col-12 text-end mt-4">
                                    <button type="submit" class="btn btn-neon">Save Profile Changes</button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Section 2: Credentials & Security Updates -->
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
                                <div class="col-12 text-end mt-4">
                                    <button type="submit" class="btn btn-neon">Apply Security Update</button>
                                </div>
                            </div>
                        </form>
                    </div>

                </div>
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