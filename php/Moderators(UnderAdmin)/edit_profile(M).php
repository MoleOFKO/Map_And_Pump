<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile - Map & Pump</title>
    <!-- Browser Tab Logo (Favicon) -->
    <link rel="icon" type="image/png" href="../images/Logo.png">
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../../css/Admin.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
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
                        <img src="../../images/Logo.png" alt="Logo" class="nav-logo">
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
                        <form action="update_password_action.php" method="POST"> <!--Need to create update_Password_action.php that is not existing in those folder-->
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
                    <a href="moderator_dashboard.php" class="menu-btn-nav">
                        <i class="bi bi-house me-3 txt-neon-cyan"></i> Home
                    </a>
                </li>
                <li>
                    <a href="view_hq_and_user_account(M).php" class="menu-btn-nav">
                        <i class="bi bi-building-gear me-3 txt-neon-cyan"></i> View HQ & User Accounts
                    </a>
                </li>
                <li>
                    <a href="view_requests(M).php" class="menu-btn-nav">
                        <i class="bi bi-file-earmark-check me-3 txt-neon-cyan"></i> View Requests
                    </a>
                </li>
                <li>
                    <a href="view_report(M).php" class="menu-btn-nav">
                        <i class="bi bi-graph-up-arrow me-3 txt-neon-cyan"></i> View Report
                    </a>
                </li>
                <li>
                    <a href="edit_profile(M).php" class="menu-btn-nav">
                        <i class="bi bi-person-bounding-box me-3 txt-neon-blue"></i> Edit Profile
                    </a>
                </li>
                <!-- <li>
                    <a href="manage_moderators.php(M)" class="menu-btn-nav">
                        <i class="bi bi-people-fill me-3 txt-neon-blue"></i> Manage Moderators
                    </a>
                </li> -->
                <li>
                    <a href="view_logs(M).php" class="menu-btn-nav">
                        <i class="bi bi-terminal-split me-3 text-danger"></i> View Log Files
                    </a>
                </li>
                <hr style="border-color: rgba(255, 255, 255, 0.1);">
                <li>
                    <form action="logout.php" method="post" class="m-0"> <!--Need to create logout.php that is not existing in those folder-->
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