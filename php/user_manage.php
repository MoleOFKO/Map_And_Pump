<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage User Accounts - Map & Pump</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../css/user_css.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .account-card { background-color: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.12); border-radius: 16px; }
        .avatar-placeholder { width: 44px; height: 44px; background-color: rgba(255, 255, 255, 0.1); color: #ffffff; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; }
        .status-active { background-color: rgba(25, 135, 84, 0.15); color: #198754; border: 1px solid rgba(25, 135, 84, 0.3); }
        .search-input { background-color: rgba(255, 255, 255, 0.08); border: 1px solid rgba(255, 255, 255, 0.15); color: #ffffff !important; }
        .search-input:focus { background-color: rgba(255, 255, 255, 0.12); border-color: #ffffff; box-shadow: none; }
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
                        <img src="../images/Logo.png" alt="Map & Pump Logo" class="nav-logo">
                        <span style="letter-spacing: 1px;">Map & Pump</span>
                    </a>
                </div>
                <div class="collapse navbar-collapse flex-grow-0" id="collapsibleNavbar">
                    <ul class="navbar-nav ms-auto gap-2 text-center text-sm-start mt-2 mt-sm-0">
                        <li class="nav-item"><a href="staff_dashboard.php" class="nav-link expand-center">Dashboard</a></li>
                        <li class="nav-item"><a class="nav-link expand-center active" href="#" id="staffDropdown" role="button" data-bs-toggle="offcanvas" data-bs-target="#profileOffcanvas">Account</a></li>
                    </ul>
                </div>
            </div>
        </nav>
    </div>

    <div class="container my-4 my-sm-5 text_color">
        <div class="row mb-4 align-items-center g-3 px-2">
            <div class="col-12 col-md-7">
                <div class="mb-1"><a href="staff_dashboard.php" class="text-white text-decoration-none small d-inline-flex align-items-center"><i class="bi bi-arrow-left me-2"></i> Back</a></div>
                <h2 class="fw-bold m-0">User Account Management</h2>
            </div>
            <div class="col-12 col-md-5">
                <div class="input-group w-100">
                    <span class="input-group-text search-input text-white-50 border-end-0"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control search-input border-start-0" placeholder="Search user by name...">
                </div>
            </div>
        </div>

        <div class="row g-3 px-2">
            <div class="col-12 col-xl-6">
                <div class="account-card p-3 p-sm-4 d-flex align-items-start gap-3 flex-sm-nowrap flex-wrap">
                    <div class="avatar-placeholder flex-shrink-0"><span>AJ</span></div>
                    <div class="flex-grow-1 min-w-0">
                        <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                            <h5 class="m-0 fw-semibold text-truncate">Alex Johnson</h5>
                            <span class="badge status-active px-2 py-1 rounded-pill small">Active</span>
                        </div>
                        <p class="text-white-50 mb-1 small text-truncate"><i class="bi bi-envelope me-1"></i> alex@example.com</p>
                        <div class="d-flex gap-2 mt-3 flex-wrap">
                            <button class="btn btn-sm btn-outline-light px-3" data-bs-toggle="modal" data-bs-target="#editUserModal"><i class="bi bi-pencil-square me-1"></i> Edit</button>
                            <button class="btn btn-sm btn-outline-danger px-3"><i class="bi bi-slash-circle me-1"></i> Suspend</button>
                        </div>
                    </div>
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
                    <a href="user_manage.php" class="text-decoration-none text-white d-flex align-items-center p-2 rounded" style="background: rgba(255,255,255,0.05)">
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