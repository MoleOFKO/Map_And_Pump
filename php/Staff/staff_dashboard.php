<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Dashboard - Map & Pump</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <!-- Browser Tab Logo (Favicon) -->
    <link rel="icon" type="image/png" href="/images/Logo.png">
    <link rel="stylesheet" href="/css/user_css.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .action-card {
            background-color: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 16px;
            transition: all 0.3s ease-in-out;
            cursor: pointer;
        }
        .action-card:hover {
            transform: translateY(-5px);
            background-color: rgba(255, 255, 255, 0.1);
            border-color: #ffffff;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.3);
        }
        .icon-box { font-size: calc(2.5rem + 1vw); color: #ffffff; margin-bottom: 15px; }
        .clickable-profile {
            text-decoration: none; color: inherit; display: flex; align-items: center;
            padding: 10px; border-radius: 8px; transition: background 0.2s ease-in-out;
        }
        .clickable-profile:hover { background-color: rgba(255, 255, 255, 0.1); color: #dce6f3; }
        
        /* Center Brand Formatting */
        .nav-logo { height: 32px; width: auto; object-fit: contain; }
    </style>
</head>
<body class="background_color" id="background_color">
    
    <!-- NAVBAR WITH CENTERED LOGO -->
    <div class="container">
        <nav class="navbar navbar-dark text-white navbar-expand-sm navigation_style px-3">
            <div class="container-fluid d-flex justify-content-between align-items-center">
                
                <!-- Left Section: Brand Text / Mobile Toggler -->
                <div class="d-flex align-items-center">
                    <button class="navbar-toggler me-2" type="button" data-bs-toggle="collapse" data-bs-target="#collapsibleNavbar">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                    <a class="navbar-brand expand-center d-sm-block d-none" href="staff_dashboard.php">Staff Portal</a>
                </div>

                <!-- Center Section: Logo + Name -->
                <div class="position-sm-absolute start-50 translate-middle-sm text-center my-2 my-sm-0">
                    <a href="staff_dashboard.php" class="text-decoration-none d-flex align-items-center justify-content-center gap-2 text-white fw-bold">
                        <img src="/images/Logo.png" alt="Map & Pump Logo" class="nav-logo">
                        <span style="letter-spacing: 1px;">Map & Pump</span>
                    </a>
                </div>
                    
                <!-- Right Section: Navigation Links -->
                <div class="collapse navbar-collapse flex-grow-0" id="collapsibleNavbar">
                    <ul class="navbar-nav ms-auto gap-2 text-center text-sm-start mt-2 mt-sm-0">
                        <li class="nav-item"><a href="staff_dashboard.php" class="nav-link expand-center active">Dashboard</a></li>
                        <li class="nav-item"><a class="nav-link expand-center" href="#" id="staffDropdown" role="button" data-bs-toggle="offcanvas" data-bs-target="#profileOffcanvas">Account</a></li>
                    </ul>
                </div>

            </div>
        </nav>
    </div>

    <!-- MAIN DASHBOARD CONTENT -->
    <div class="container my-5 text_color">
        <div class="row mb-4 text-center">
            <div class="col-12 px-4">
                <h1 class="fw-bold">Welcome Back, Staff</h1>
                <p class="text-white-50">Select an action below to manage active operations.</p>
            </div>
        </div>

        <div class="row g-4 justify-content-center px-2">
            <!-- Verify Booking QR -->
            <div class="col-12 col-md-6 col-lg-5">
                <a href="verify_qr.php" class="text-decoration-none h-100 d-block">
                    <div class="action-card p-4 p-sm-5 text-center h-100 d-flex flex-column align-items-center justify-content-center">
                        <div class="icon-box"><i class="bi bi-qr-code-scan"></i></div>
                        <h3 class="text_color fw-semibold">Verify Booking QR</h3>
                        <p class="text-white-50 mt-2 mb-0">Scan or manually enter client ticket QR codes to check validity.</p>
                    </div>
                </a>
            </div>

            <!-- Feedback -->
            <div class="col-12 col-md-6 col-lg-5">
                <a href="feedback_manage.php" class="text-decoration-none h-100 d-block">
                    <div class="action-card p-4 p-sm-5 text-center h-100 d-flex flex-column align-items-center justify-content-center">
                        <div class="icon-box"><i class="bi bi-chat-left-heart"></i></div>
                        <h3 class="text_color fw-semibold">Feedback</h3>
                        <p class="text-white-50 mt-2 mb-0">View, evaluate, and reply to submissions received from users.</p>
                    </div>
                </a>
            </div>
        </div>
    </div>

    <!-- PROFILE OFFCANVAS -->
    <div class="offcanvas offcanvas-end profile-offcanvas" tabindex="-1" id="profileOffcanvas">
        <div class="offcanvas-header">
            <h5 class="offcanvas-title">Staff Menu</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
        </div>
        <div class="offcanvas-body">
            <ul class="list-unstyled">
                <li class="mb-4">
                    <a href="staff_account.php" class="clickable-profile">
                        <i class="bi bi-person-gear fs-3 me-3 text-info"></i>
                        <div>
                            <div class="fw-bold text-white">Staff Session (Manage Users)</div>
                            <small class="text-white-50">Click to configure accounts</small>
                        </div>
                    </a>
                </li>
                <hr style="border-color: rgba(255,255,255,0.2)">
                <li class="mb-3"><a class="nav-link text-white d-flex align-items-center" href="logout.php"><i class="bi bi-box-arrow-right me-2 text-danger"></i> Logout</a></li>
            </ul>
        </div>
    </div>

    <footer class="text-white text-center py-3 mt-5">&copy; 2026 Map & Pump | All Rights Reserved</footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>