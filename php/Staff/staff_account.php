<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Account - Map & Pump</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="icon" type="image/png" href="/images/Logo.png">
    <link rel="stylesheet" href="/css/Admin.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .avatar-large { 
            width: 80px; 
            height: 80px; 
            background-color: rgba(255, 255, 255, 0.05); 
            color: #ffffff; 
            border-radius: 50%; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-size: 2.2rem;
            border: 2px solid rgba(0, 244, 255, 0.25);
            box-shadow: 0 0 15px rgba(0, 244, 255, 0.1);
        }
    </style>
</head>
<body class="background_color" id="background_color">

    <!-- TOP LOADING SHIMMER BAR -->
    <div class="page-load-bar"></div>

    <!-- IPHONE CAPSULE NAVBAR -->
    <div class="container sticky-nav-wrapper">
        <nav class="navbar navbar-dark text-white navigation_style">
            <div class="container-fluid d-flex justify-content-between align-items-center p-0 gap-3">
                <a class="navbar-brand d-flex align-items-center gap-2 fw-semibold txt-neon-cyan m-0" href="staff_dashboard.php" style="font-size: 0.95rem;">
                    <img src="/images/Logo.png" alt="Logo" class="nav-logo">
                    <span class="d-none d-sm-inline">Staff Portal</span>
                </a>

                <div class="admin-nav-actions">
                    <label class="cosmic-toggle nav-theme-toggle" title="Toggle light mode">
                        <input class="toggle js-theme-toggle" type="checkbox" aria-label="Toggle light mode" />
                        <div class="slider">
                            <span class="theme-icon theme-icon-dark"><i class="bi bi-moon-stars-fill"></i></span>
                            <span class="theme-icon theme-icon-light"><i class="bi bi-sun-fill"></i></span>
                            <div class="cosmos"></div>
                            <div class="energy-line"></div>
                            <div class="energy-line"></div>
                            <div class="energy-line"></div>
                            <div class="toggle-orb">
                                <div class="inner-orb"></div>
                                <div class="ring"></div>
                            </div>
                            <div class="particles">
                                <div style="--angle: 30deg" class="particle"></div>
                                <div style="--angle: 60deg" class="particle"></div>
                                <div style="--angle: 90deg" class="particle"></div>
                                <div style="--angle: 120deg" class="particle"></div>
                                <div style="--angle: 150deg" class="particle"></div>
                                <div style="--angle: 180deg" class="particle"></div>
                            </div>
                        </div>
                    </label>
                    <button class="btn btn-sm menu-pill-btn" type="button" data-bs-toggle="offcanvas" data-bs-target="#mainMenuOffcanvas">
                        <i class="bi bi-grid-fill me-1 txt-neon-cyan"></i> Menu
                    </button>
                </div>
            </div>
        </nav>
    </div>

    <!-- MAIN CONTENT AREA -->
    <div class="container my-5 text_color">
        <div class="row justify-content-center">
            <div class="col-12 col-md-10 col-lg-8 px-3">
                
                <!-- Back Link -->
                <div class="mb-3 ms-1">
                    <a href="staff_dashboard.php" class="stat-chip text-decoration-none">
                        <i class="bi bi-arrow-left"></i> Back to Dashboard
                    </a>
                </div>

                <div class="row g-4 mt-1">
                    <!-- Left Column: Account Details (Watch Mode) -->
                    <div class="col-12 col-md-5">
                        <div class="analytics-card p-4 text-center h-100 d-flex flex-column align-items-center justify-content-center">
                            <div class="avatar-large mb-3">
                                <span>S</span>
                            </div>
                            <!-- Dynamic values can be swapped here via PHP backend -->
                            <h4 class="fw-bold mb-1 text-white">Staff Account</h4>
                            <span class="stat-chip mb-4" style="color: var(--neon-cyan); border-color: rgba(0, 244, 255, 0.3);">System Operator</span>
                            
                            <div class="w-100 text-start border-top border-secondary border-opacity-25 pt-3 mt-2">
                                <label class="form-label text-white-50 small mb-0">Staff ID</label>
                                <p class="fw-mono text-white mb-3" style="font-family: monospace;">#EMP-2026</p>

                                <label class="form-label text-white-50 small mb-0">Username</label>
                                <p class="text-white mb-3">staff_pump01</p>

                                <label class="form-label text-white-50 small mb-0">Full Name</label>
                                <p class="text-white mb-0">Jane Doe</p>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column: Change Password / Update Security Settings -->
                    <div class="col-12 col-md-7">
                        <div class="dashboard-card h-100">
                            <h3 class="fw-bold mb-2 text-white"><i class="bi bi-shield-lock me-2 text-warning"></i>Security Settings</h3>
                            <p class="text-white-50 small mb-4">Keep your portal access protected by modifying your master passcode credentials regularly.</p>
                            
                            <form action="change_password_process.php" method="POST">
                                <div class="mb-3">
                                    <label for="currentPassword" class="form-label text-white-50 small">Current Password</label>
                                    <div class="input-group">
                                        <span class="input-group-text glass-input text-white-50 border-end-0"><i class="bi bi-lock"></i></span>
                                        <input type="password" class="form-control glass-input border-start-0" id="currentPassword" name="current_password" required>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="newPassword" class="form-label text-white-50 small">New Password</label>
                                    <div class="input-group">
                                        <span class="input-group-text glass-input text-white-50 border-end-0"><i class="bi bi-key"></i></span>
                                        <input type="password" class="form-control glass-input border-start-0" id="newPassword" name="new_password" required>
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <label for="confirmPassword" class="form-label text-white-50 small">Confirm New Password</label>
                                    <div class="input-group">
                                        <span class="input-group-text glass-input text-white-50 border-end-0"><i class="bi bi-check2-circle"></i></span>
                                        <input type="password" class="form-control glass-input border-start-0" id="confirmPassword" name="confirm_password" required>
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-warning w-100 py-2 fw-bold text-dark rounded-pill shadow">
                                    <i class="bi bi-arrow-repeat me-2"></i>Save New Password
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Safe Logout Option below -->
                <div class="text-center mt-4">
                    <a href="logout.php" class="text-danger small text-decoration-none fw-semibold">
                        <i class="bi bi-box-arrow-right me-1"></i> Securely Log Out of This Session
                    </a>
                </div>

            </div>
        </div>
    </div>

    <!-- SIDEBAR MAIN MENU -->
    <div class="offcanvas offcanvas-end" tabindex="-1" id="mainMenuOffcanvas" style="width: 320px; background: rgba(6, 12, 24, 0.98); backdrop-filter: blur(28px); border-left: 1px solid rgba(0, 180, 255, 0.18);">
        <div class="offcanvas-header pb-2 border-bottom border-white border-opacity-10">
            <div>
                <h5 class="offcanvas-title text-white fw-bold mb-0">Main Menu</h5>
                <p class="text-white-50 small mb-0" style="font-size:0.78rem;">Staff Portal</p>
            </div>
            <label class="cosmic-toggle float-end" title="Toggle light mode">
                <input class="toggle js-theme-toggle" id="themeToggleMenu" type="checkbox" aria-label="Toggle light mode" />
                <div class="slider">
                    <span class="theme-icon theme-icon-dark"><i class="bi bi-moon-stars-fill"></i></span>
                    <span class="theme-icon theme-icon-light"><i class="bi bi-sun-fill"></i></span>
                    <div class="cosmos"></div>
                    <div class="energy-line"></div>
                    <div class="energy-line"></div>
                    <div class="energy-line"></div>
                    <div class="toggle-orb">
                    <div class="inner-orb"></div>
                    <div class="ring"></div>
                    </div>
                    <div class="particles">
                    <div style="--angle: 30deg" class="particle"></div>
                    <div style="--angle: 60deg" class="particle"></div>
                    <div style="--angle: 90deg" class="particle"></div>
                    <div style="--angle: 120deg" class="particle"></div>
                    <div style="--angle: 150deg" class="particle"></div>
                    <div style="--angle: 180deg" class="particle"></div>
                    </div>
                </div>
            </label>

            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
        </div>
        <div class="offcanvas-body pt-3">
            <ul class="list-unstyled d-flex flex-column gap-2">
                <li><a href="staff_dashboard.php" class="menu-btn-nav"><i class="bi bi-house me-3 txt-neon-cyan"></i> Dashboard</a></li>
                <li><a href="verify_qr.php" class="menu-btn-nav"><i class="bi bi-qr-code-scan me-3 txt-neon-cyan"></i> Verify QR</a></li>
                <li><a href="feedback_manage.php" class="menu-btn-nav"><i class="bi bi-chat-left-heart me-3 txt-neon-cyan"></i> Feedback</a></li>
                <li><a href="staff_account.php" class="menu-btn-nav active-nav"><i class="bi bi-person-gear me-3 txt-neon-blue"></i> Account</a></li>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/js/Admin.js"></script>
    <script>
        document.addEventListener('pointermove', (event) => {
            document.body.style.setProperty('--bg-x', `${(event.clientX / window.innerWidth) * 100}%`);
            document.body.style.setProperty('--bg-y', `${(event.clientY / window.innerHeight) * 100}%`);
        });
    </script>
</body>
</html>