<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Booking QR - Map & Pump</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <!-- Browser Tab Logo (Favicon) -->
    <link rel="icon" type="image/png" href="/images/Logo.png">
    <link rel="stylesheet" href="/css/customer.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .qr-card { background-color: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.15); border-radius: 20px; backdrop-filter: blur(10px); }
        .scanner-window {
            position: relative; width: 100%; max-width: 280px; height: 280px;
            background: #0d141b; border-radius: 16px; overflow: hidden; border: 2px solid rgba(255, 255, 255, 0.1);
        }
        @media (min-width: 400px) { .scanner-window { max-width: 320px; height: 320px; } }
        .scanner-laser { position: absolute; width: 100%; height: 3px; background-color: #00ff66; box-shadow: 0 0 12px #00ff66; top: 0; animation: scanAnimation 2.5s infinite linear; }
        @keyframes scanAnimation { 0% { top: 0%; } 50% { top: 100%; } 100% { top: 0%; } }
        .target-box { position: absolute; top: 15%; left: 15%; right: 15%; bottom: 15%; border: 2px dashed rgba(255, 255, 255, 0.3); border-radius: 12px; display: flex; align-items: center; justify-content: center; }
        .custom-input { background-color: rgba(255, 255, 255, 0.08); border: 1px solid rgba(255, 255, 255, 0.2); color: #ffffff !important; border-radius: 8px; }
        .custom-input:focus { background-color: rgba(255, 255, 255, 0.12); border-color: #ffffff; box-shadow: none; }
        .nav-logo { height: 32px; width: auto; object-fit: contain; }
    </style>
</head>
<body class="background_color" id="background_color">

    <!-- NAVBAR WITH CENTERED LOGO -->
    <div class="container">
        <nav class="navbar navbar-dark text-white navbar-expand-sm navigation_style px-3">
            <div class="container-fluid d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                    <button class="navbar-toggler me-2" type="button" data-bs-toggle="collapse" data-bs-target="#collapsibleNavbar"><span class="navbar-toggler-icon"></span></button>
                    <a class="navbar-brand expand-center d-sm-block d-none" href="staff_dashboard.php">Staff Portal</a>
                </div>
                <div class="position-sm-absolute start-50 translate-middle-sm text-center my-2 my-sm-0">
                    <a href="staff_dashboard.php" class="text-decoration-none d-flex align-items-center justify-content-center gap-2 text-white fw-bold">
                        <img src="/images/Logo.png" alt="Map & Pump Logo" class="nav-logo">
                        <span style="letter-spacing: 1px;">Map & Pump</span>
                    </a>
                </div>
                <div class="collapse navbar-collapse flex-grow-0" id="collapsibleNavbar">
                    <ul class="navbar-nav ms-auto gap-2 text-center text-sm-start mt-2 mt-sm-0">
                        <li class="nav-item"><a href="staff_dashboard.php" class="nav-link expand-center">Dashboard</a></li>
                        <li class="nav-item"><a class="nav-link expand-center" href="#" id="staffDropdown" role="button" data-bs-toggle="offcanvas" data-bs-target="#profileOffcanvas">Account</a></li>
                    </ul>
                </div>
            </div>
        </nav>
    </div>

    <!-- MAIN INTERFACE -->
    <div class="container my-4 my-sm-5 text_color">
        <div class="row justify-content-center">
            <div class="col-12 col-sm-10 col-md-8 col-lg-6 px-3">
                <div class="mb-3 ms-1">
                    <a href="staff_dashboard.php" class="text-white text-decoration-none small d-inline-flex align-items-center"><i class="bi bi-arrow-left me-2"></i> Back</a>
                </div>
                <div class="qr-card p-4 p-sm-5 text-center">
                    <h2 class="fw-bold mb-2">Verify Booking QR</h2>
                    <p class="text-white-50 mb-4 small">Position the user's booking QR code within the frame to automatically scan it.</p>
                    <div class="d-flex justify-content-center mb-4">
                        <div class="scanner-window d-flex align-items-center justify-content-center">
                            <div class="scanner-laser"></div>
                            <div class="target-box"><i class="bi bi-qr-code text-white-50" style="font-size: calc(3.5rem + 1vw);"></i></div>
                            <small class="text-white-50 position-absolute bottom-0 mb-3 w-100 text-center px-2"><i class="bi bi-camera-video me-1"></i> Seeking Stream...</small>
                        </div>
                    </div>
                    <div class="d-flex align-items-center my-4">
                        <hr class="flex-grow-1" style="border-color: rgba(255,255,255,0.15)">
                        <span class="mx-2 text-white-50 small fw-bold" style="font-size: 0.75rem;">OR MANUALLY ENTER</span>
                        <hr class="flex-grow-1" style="border-color: rgba(255,255,255,0.15)">
                    </div>
                    <form action="verify_process.php" method="POST" class="text-start">
                        <div class="mb-3">
                            <label for="bookingCode" class="form-label text-white-50 small">Booking Reference Code</label>
                            <div class="input-group">
                                <span class="input-group-text custom-input text-white-50 border-end-0"><i class="bi bi-hash"></i></span>
                                <input type="text" class="form-control custom-input border-start-0 ps-0" id="bookingCode" name="booking_code" placeholder="e.g. BK-984102" required autocomplete="off">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-light w-100 py-2 fw-semibold"><i class="bi bi-check-circle-fill me-2"></i> Verify Code</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- OFFCANVAS -->
    <div class="offcanvas offcanvas-end profile-offcanvas" tabindex="-1" id="profileOffcanvas">
        <div class="offcanvas-header">
            <h5 class="offcanvas-title">Staff Menu</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
        </div>
        <div class="offcanvas-body">
            <ul class="list-unstyled">
                <li class="mb-4">
                    <a href="staff_account.php" class="text-decoration-none text-white d-flex align-items-center p-2 rounded" style="background: rgba(255,255,255,0.05)">
                        <i class="bi bi-person-gear fs-3 me-3 text-info"></i>
                        <div><div class="fw-bold">Staff Session (Manage Users)</div><small class="text-white-50">Click to configure accounts</small></div>
                    </a>
                </li>
                <hr style="border-color: rgba(255,255,255,0.2)">
                <li class="mb-3"><a class="nav-link text-white d-flex align-items-center" href="logout.php"><i class="bi bi-box-arrow-right me-2 text-danger"></i> Logout</a></li>
            </ul>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>