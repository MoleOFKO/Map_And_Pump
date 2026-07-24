<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.    <meta name="description" content="Map & Pump Admin — Edit your profile details, secure credentials, and update system authorization information.">
    <title>Edit Profile — Map &amp; Pump</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="/images/Logo.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="/css/Admin.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body class="background_color" id="background_color">

    <!-- TOP LOADING SHIMMER BAR -->
    <div class="page-load-bar"></div>

    <!-- IPHONE CAPSULE NAVBAR -->
    <div class="container sticky-nav-wrapper">
        <nav class="navbar navbar-dark text-white navigation_style">
            <div class="container-fluid d-flex justify-content-between align-items-center p-0 gap-3">
                <a class="navbar-brand d-flex align-items-center gap-2 fw-semibold txt-neon-cyan m-0" href="admin_dashboard.php" style="font-size: 0.95rem;">
                    <img src="/images/Logo.png" alt="Logo" class="nav-logo">
                    <span class="d-none d-sm-inline">Admin Portal</span>
                </a>

                <button class="btn btn-sm menu-pill-btn" type="button" data-bs-toggle="offcanvas" data-bs-target="#mainMenuOffcanvas">
                    <i class="bi bi-grid-fill me-1 txt-neon-cyan"></i> Menu
                </button>
            </div>
        </nav>
    </div>

    <!-- MAIN CONTENT -->
    <div class="container my-5" style="max-width: 1000px;">

        <!-- Header Row -->
        <div class="mb-5">
            <span class="stat-chip mb-2">
                <i class="bi bi-person-badge-fill txt-neon-cyan"></i>
                Account Settings
            </span>
            <h1 class="fw-bold m-0 h4 text-white mt-2">Edit Profile Details</h1>
            <p class="text-white-50 m-0 small mt-1">Modify your personal details, secure credentials, and update system authorization info.</p>
        </div>

        <div class="row g-4">

            <!-- Left Panel: Avatar & Core Information + Account Switcher -->
            <div class="col-12 col-md-4 d-flex flex-column gap-4">

                <!-- Avatar Card -->
                <div class="dashboard-card text-center py-5">
                    <form action="update_avatar_action.php" method="POST" enctype="multipart/form-data" id="avatarForm">
                        <div class="profile-avatar-container mb-4">
                            <img src="https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?auto=format&fit=crop&w=150&q=80" alt="Avatar" class="profile-avatar-image" id="avatarPreview">
                            <label for="avatarInput" class="avatar-upload-trigger" title="Upload new photo">
                                <i class="bi bi-camera-fill"></i>
                            </label>
                            <input type="file" id="avatarInput" name="profile_avatar" accept="image/*" class="d-none" onchange="document.getElementById('avatarForm').submit();">
                        </div>
                    </form>

                    <h5 class="fw-bold text-white mb-1">Owner Admin</h5>
                    <p class="text-white-50 small mb-4">Super Administrator</p>

                    <div class="border-top border-white border-opacity-10 pt-4 text-start">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <span class="text-white-50 small">Role Status</span>
                            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-1">
                                <i class="bi bi-circle-fill me-1" style="font-size:0.4rem;"></i>Active
                            </span>
                        </div>
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <span class="text-white-50 small">Joined Since</span>
                            <span class="text-white-50 small font-monospace">Feb 2026</span>
                        </div>
                        <div class="d-flex align-items-center justify-content-between">
                            <span class="text-white-50 small">Last Login</span>
                            <span class="text-white-50 small font-monospace">Today, 14:15</span>
                        </div>
                    </div>
                </div>

                <!-- Role Switcher Card -->
                <div class="dashboard-card text-center p-4" style="border-color: rgba(0, 244, 255, 0.18); background: rgba(0, 244, 255, 0.025) !important;">
                    <div class="rounded-circle d-flex align-items-center justify-content-center mx-auto mb-3" style="width: 52px; height: 52px; background: rgba(0, 244, 255, 0.08); border: 1px dashed rgba(0,244,255,0.3);">
                        <i class="bi bi-person-bounding-box txt-neon-cyan fs-4"></i>
                    </div>
                    <h6 class="fw-bold text-white mb-1">Dual Account Link</h6>
                    <p class="text-white-50 small mb-4">Quickly toggle contexts to manage maps, pumps, and check consumer functionality.</p>

                    <form action="../Customer/customer_dashboard.php" method="POST" class="m-0">
                        <input type="hidden" name="target_role" value="customer">
                        <button type="submit" class="btn w-100 rounded-3 text-white d-flex align-items-center justify-content-center gap-2" style="background: rgba(0, 244, 255, 0.06); border: 1px solid rgba(0,244,255,0.2); padding: 11px; font-size:0.9rem; transition: all 0.25s ease;">
                            <i class="bi bi-arrow-left-right txt-neon-cyan"></i> Switch to Customer View
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
                                <div class="col-12 text-end mt-2">
                                    <button type="submit" class="btn btn-neon">Save Profile Changes</button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Section 2: Credentials & Security Updates -->
                    <div class="dashboard-card">
                        <h5 class="fw-bold text-white mb-4 d-flex align-items-center gap-2">
                            <i class="bi bi-shield-lock-fill txt-neon-cyan"></i> Security &amp; Password
                        </h5>
                        <form action="update_password_action.php" method="POST" id="passwordForm">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label text-white-50 small">Current Active Password</label>
                                    <input type="password" name="current_password" class="form-control glass-input" placeholder="••••••••••••" required>
                                </div>
                                <div class="col-12 col-sm-6">
                                    <label class="form-label text-white-50 small">New Password Entry</label>
                                    <input type="password" name="new_password" id="newPasswordInput" class="form-control glass-input" placeholder="Min. 8 characters" required>
                                    <!-- Password Strength Bar -->
                                    <div class="pw-strength-bar">
                                        <div class="pw-strength-fill" id="pwStrengthFill"></div>
                                    </div>
                                    <div class="pw-strength-label" id="pwStrengthLabel" style="color: rgba(217,230,255,0.4);"></div>
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

    <!-- SIDEBAR MAIN MENU -->
    <div class="offcanvas offcanvas-end" tabindex="-1" id="mainMenuOffcanvas" style="width: 320px; background: rgba(6, 12, 24, 0.98); backdrop-filter: blur(28px); border-left: 1px solid rgba(0, 180, 255, 0.18);">
        <div class="offcanvas-header pb-2 border-bottom border-white border-opacity-10">
            <div>
                <h5 class="offcanvas-title text-white fw-bold mb-0">Main Menu</h5>
                <p class="text-white-50 small mb-0" style="font-size:0.78rem;">Admin Portal</p>
            </div>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
        </div>
        <div class="offcanvas-body pt-3">
            <ul class="list-unstyled d-flex flex-column gap-2">
                <li><a href="admin_dashboard.php" class="menu-btn-nav"><i class="bi bi-house me-3 txt-neon-cyan"></i> Home</a></li>
                <li><a href="view_hq_and_user_account.php" class="menu-btn-nav"><i class="bi bi-building-gear me-3 txt-neon-cyan"></i> View HQ &amp; User Accounts</a></li>
                <li><a href="view_requests.php" class="menu-btn-nav"><i class="bi bi-file-earmark-check me-3 txt-neon-cyan"></i> View Requests</a></li>
                <li><a href="view_report.php" class="menu-btn-nav"><i class="bi bi-graph-up-arrow me-3 txt-neon-cyan"></i> View Report</a></li>
                <li><a href="edit_profile.php" class="menu-btn-nav active-nav"><i class="bi bi-person-bounding-box me-3 txt-neon-blue"></i> Edit Profile</a></li>
                <li><a href="manage_moderators.php" class="menu-btn-nav"><i class="bi bi-people-fill me-3 txt-neon-blue"></i> Manage Moderators</a></li>
                <li><a href="view_logs.php" class="menu-btn-nav"><i class="bi bi-terminal-split me-3 text-danger"></i> View Log Files</a></li>
                <hr style="border-color: rgba(255, 255, 255, 0.08); margin: 4px 0;">
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

    <footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map &amp; Pump | All Rights Reserved</footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/js/Admin.js"></script>
    <script>
        // Mouse-tracked background
        document.addEventListener('pointermove', (event) => {
            document.body.style.setProperty('--bg-x', `${(event.clientX / window.innerWidth) * 100}%`);
            document.body.style.setProperty('--bg-y', `${(event.clientY / window.innerHeight) * 100}%`);
        });

        // Password strength meter
        const pwInput  = document.getElementById('newPasswordInput');
        const pwFill   = document.getElementById('pwStrengthFill');
        const pwLabel  = document.getElementById('pwStrengthLabel');

        pwInput.addEventListener('input', () => {
            const val = pwInput.value;
            let strength = 0;
            if (val.length >= 8)                        strength++;
            if (/[A-Z]/.test(val))                      strength++;
            if (/[0-9]/.test(val))                      strength++;
            if (/[^A-Za-z0-9]/.test(val))               strength++;

            const levels = [
                { pct: '0%',   color: 'transparent',                    label: '' },
                { pct: '25%',  color: '#ff4d6a',                        label: 'Weak' },
                { pct: '50%',  color: '#ffc107',                        label: 'Fair' },
                { pct: '75%',  color: '#38b6ff',                        label: 'Good' },
                { pct: '100%', color: '#1be494',                        label: 'Strong' },
            ];

            const lvl = levels[strength];
            pwFill.style.width    = lvl.pct;
            pwFill.style.background = lvl.color;
            pwLabel.textContent   = lvl.label;
            pwLabel.style.color   = lvl.color;
        });

        document.addEventListener('pointermove', (event) => {
            document.body.style.setProperty('--bg-x', `${(event.clientX / window.innerWidth) * 100}%`);
            document.body.style.setProperty('--bg-y', `${(event.clientY / window.innerHeight) * 100}%`);
        });
    </script>
</body>
</html>