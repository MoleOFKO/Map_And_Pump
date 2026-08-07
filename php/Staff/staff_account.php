<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Account - Map & Pump</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <!-- Browser Tab Logo (Favicon) -->
    <link rel="icon" type="image/png" href="/images/Logo.png">
    <link rel="stylesheet" href="/css/customer.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .profile-card { 
            background-color: rgba(255, 255, 255, 0.05); 
            border: 1px solid rgba(255, 255, 255, 0.12); 
            border-radius: 16px; 
            backdrop-filter: blur(10px);
        }
        .avatar-large { 
            width: 80px; 
            height: 80px; 
            background-color: rgba(255, 255, 255, 0.1); 
            color: #ffffff; 
            border-radius: 50%; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-size: 2rem;
            border: 2px solid rgba(255, 255, 255, 0.2);
        }
        .custom-input { 
            background-color: rgba(255, 255, 255, 0.06); 
            border: 1px solid rgba(255, 255, 255, 0.15); 
            color: #ffffff !important; 
        }
        .custom-input:focus { 
            background-color: rgba(255, 255, 255, 0.12); 
            border-color: #ffffff; 
            box-shadow: none; 
        }
        .custom-input:disabled {
            background-color: rgba(0, 0, 0, 0.25);
            border-color: rgba(255, 255, 255, 0.05);
            color: rgba(255, 255, 255, 0.5) !important;
        }
        .nav-logo { height: 32px; width: auto; object-fit: contain; }
        
        @media (max-width: 575.98px) {
            .mobile-dropdown-menu {
                background: #141f2a;
                border: 1px solid rgba(255, 255, 255, 0.15);
                border-radius: 12px;
                padding: 10px;
                box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5);
                position: absolute;
                top: 50px;
                left: 0;
                right: 0;
                z-index: 1020;
            }
        }
    </style>
</head>
<body class="background_color" id="background_color">

    <!-- NAVBAR WITH PERFECTLY CENTERED LOGO -->
    <div class="container mt-3">
        <nav class="navbar navbar-dark navbar-expand-sm navigation_style position-relative px-3 py-2" style="min-height: 60px;">
            <div class="container-fluid d-flex align-items-center justify-content-between p-0">
                
                <button class="navbar-toggler border-0 p-1" type="button" data-bs-toggle="collapse" data-bs-target="#collapsibleNavbar">
                    <span class="navbar-toggler-icon" style="font-size: 1.1rem;"></span>
                </button>

                <div class="position-absolute start-50 top-50 translate-middle" style="z-index: 1030;">
                    <a href="staff_dashboard.php" class="text-decoration-none d-flex align-items-center gap-2 text-white fw-bold m-0" style="white-space: nowrap;">
                        <img src="/images/Logo.png" alt="Map & Pump Logo" class="nav-logo">
                        <span style="letter-spacing: 0.5px; font-size: 1.1rem;">Map & Pump</span>
                    </a>
                </div>
                    
                <div class="collapse navbar-collapse justify-content-end" id="collapsibleNavbar">
                    <ul class="navbar-nav gap-1 text-center mt-3 mt-sm-0 mobile-dropdown-menu">
                        <li class="nav-item"><a href="staff_dashboard.php" class="nav-link px-3">Dashboard</a></li>
                        <li class="nav-item"><a class="nav-link px-3 active" href="staff_account.php">Account</a></li>
                    </ul>
                </div>
                <div class="d-sm-none" style="width: 32px;"></div>
            </div>
        </nav>
    </div>

    <!-- MAIN CONTENT AREA -->
    <div class="container my-4 my-sm-5 text_color">
        <div class="row justify-content-center">
            <div class="col-12 col-md-10 col-lg-8 px-3">
                
                <!-- Back Link -->
                <div class="mb-3 ms-1">
                    <a href="staff_dashboard.php" class="text-white text-decoration-none small d-inline-flex align-items-center">
                        <i class="bi bi-arrow-left me-2"></i> Back to Dashboard
                    </a>
                </div>

                <div class="row g-4">
                    <!-- Left Column: Account Details (Watch Mode) -->
                    <div class="col-12 col-md-5">
                        <div class="profile-card p-4 text-center h-100 d-flex flex-column align-items-center justify-content-center">
                            <div class="avatar-large mb-3">
                                <span>S</span>
                            </div>
                            <!-- Dynamic values can be swapped here via PHP backend -->
                            <h4 class="fw-bold mb-1 text-white">Staff Account</h4>
                            <span class="badge bg-info text-dark rounded-pill px-3 py-1 small fw-bold mb-4">System Operator</span>
                            
                            <div class="w-100 text-start border-top border-secondary pt-3 mt-2">
                                <label class="form-label text-white-50 small mb-0">Staff ID</label>
                                <p class="fw-mono text-white mb-3">#EMP-2026</p>

                                <label class="form-label text-white-50 small mb-0">Username</label>
                                <p class="text-white mb-3">staff_pump01</p>

                                <label class="form-label text-white-50 small mb-0">Full Name</label>
                                <p class="text-white mb-0">Jane Doe</p>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column: Change Password / Update Security Settings -->
                    <div class="col-12 col-md-7">
                        <div class="profile-card p-4 p-sm-5 h-100">
                            <h3 class="fw-bold mb-2 text-white"><i class="bi bi-shield-lock me-2 text-warning"></i>Security Settings</h3>
                            <p class="text-white-50 small mb-4">Keep your portal access protected by modifying your master passcode credentials regularly.</p>
                            
                            <form action="change_password_process.php" method="POST">
                                <div class="mb-3">
                                    <label for="currentPassword" class="form-label text-white-50 small">Current Password</label>
                                    <div class="input-group">
                                        <span class="input-group-text custom-input text-white-50 border-end-0"><i class="bi bi-lock"></i></span>
                                        <input type="password" class="form-control custom-input border-start-0" id="currentPassword" name="current_password" required>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="newPassword" class="form-label text-white-50 small">New Password</label>
                                    <div class="input-group">
                                        <span class="input-group-text custom-input text-white-50 border-end-0"><i class="bi bi-key"></i></span>
                                        <input type="password" class="form-control custom-input border-start-0" id="newPassword" name="new_password" required>
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <label for="confirmPassword" class="form-label text-white-50 small">Confirm New Password</label>
                                    <div class="input-group">
                                        <span class="input-group-text custom-input text-white-50 border-end-0"><i class="bi bi-check2-circle"></i></span>
                                        <input type="password" class="form-control custom-input border-start-0" id="confirmPassword" name="confirm_password" required>
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-warning w-100 py-2 fw-bold text-dark">
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>