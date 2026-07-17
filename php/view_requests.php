<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Requests - Map & Pump</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <!-- Browser Tab Logo (Favicon) -->
    <link rel="icon" type="image/png" href="../images/Logo.png">
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

        /* METRIC CHIPS */
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

        .request-indicator-card {
            background: rgba(5, 12, 26, 0.5);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 16px;
            padding: 16px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
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

        .btn-action-success:hover {
            background: rgba(25, 135, 84, 0.15);
            border-color: #198754;
            color: #39da8a;
        }

        .btn-action-danger:hover {
            background: rgba(220, 53, 69, 0.15);
            border-color: #dc3545;
            color: #ff8591;
        }

        /* CUSTOM TABLES */
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

        .glass-modal .modal-header {
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        .glass-modal .modal-footer {
            border-top: 1px solid rgba(255, 255, 255, 0.08);
        }

        /* MENU OFF-CANVAS */
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
                    <i class="bi bi-clipboard-check-fill txt-neon-cyan"></i>
                    Review Pipeline
                </span>
                <h1 class="fw-bold m-0 h4 text-white">Inbound System Requests</h1>
                <p class="text-white-50 m-0 small">Approve or deny partner registration, station adjustments, and licensing requests.</p>
            </div>
        </div>

        <!-- Quick Summary Cards -->
        <div class="row g-3 mb-4">
            <div class="col-12 col-sm-4">
                <div class="request-indicator-card">
                    <div>
                        <p class="text-white-50 mb-0 small uppercase">Pending Verification</p>
                        <h4 class="fw-bold text-warning mb-0">35</h4>
                    </div>
                    <span class="fs-4 text-warning"><i class="bi bi-hourglass-split"></i></span>
                </div>
            </div>
            <div class="col-12 col-sm-4">
                <div class="request-indicator-card">
                    <div>
                        <p class="text-white-50 mb-0 small uppercase">Approved Today</p>
                        <h4 class="fw-bold text-success mb-0">12</h4>
                    </div>
                    <span class="fs-4 text-success"><i class="bi bi-patch-check-fill"></i></span>
                </div>
            </div>
            <div class="col-12 col-sm-4">
                <div class="request-indicator-card">
                    <div>
                        <p class="text-white-50 mb-0 small uppercase">Declined / Flagged</p>
                        <h4 class="fw-bold text-danger mb-0">3</h4>
                    </div>
                    <span class="fs-4 text-danger"><i class="bi bi-shield-x"></i></span>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <!-- Search and Filter Panel -->
            <div class="col-12">
                <div class="dashboard-card py-3 px-4">
                    <form class="row g-3 align-items-center" method="GET" action="">
                        <div class="col-12 col-md-5">
                            <div class="input-group">
                                <span class="input-group-text bg-transparent border-end-0" style="border-color: var(--glass-border); color: var(--neon-cyan);">
                                    <i class="bi bi-funnel-fill"></i>
                                </span>
                                <input type="text" class="form-control glass-input border-start-0" placeholder="Filter by company name or reference ID..." name="search">
                            </div>
                        </div>
                        <div class="col-12 col-md-3">
                            <select class="form-select glass-select" name="type">
                                <option value="" selected>All Request Types</option>
                                <option value="station">New Station Registration</option>
                                <option value="license">License Modification</option>
                                <option value="deactivation">Account Deactivation</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-2">
                            <select class="form-select glass-select" name="status">
                                <option value="" selected>All Statuses</option>
                                <option value="pending">Pending</option>
                                <option value="approved">Approved</option>
                                <option value="rejected">Rejected</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-2 d-grid">
                            <button type="submit" class="btn btn-outline-info rounded-3 text-white border-info border-opacity-20" style="background: rgba(0, 244, 255, 0.05); padding: 10px;">
                                Apply Filter
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Main Table Panel -->
            <div class="col-12">
                <div class="analytics-card">
                    <div class="table-responsive">
                        <table class="table table-dark-custom align-middle m-0">
                            <thead>
                                <tr>
                                    <th>Ref ID</th>
                                    <th>Sender Entity</th>
                                    <th>Type of Request</th>
                                    <th>Submission Date</th>
                                    <th>Status</th>
                                    <th class="text-end">Command</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Example Row 1 -->
                                <tr>
                                    <td class="fw-bold txt-neon-cyan">#REQ-9981</td>
                                    <td>
                                        <div class="fw-semibold text-white">Apex Fueling Group</div>
                                        <div class="text-white-50 small" style="font-size: 0.8rem;">Yangon Sector 4</div>
                                    </td>
                                    <td>
                                        <span class="badge rounded-pill bg-info bg-opacity-10 text-info border border-info border-opacity-25 px-2.5 py-1.5">New Station Reg</span>
                                    </td>
                                    <td class="text-white-50">July 16, 2026</td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="d-inline-block rounded-circle bg-warning" style="width: 8px; height: 8px; box-shadow: 0 0 8px #ffc107;"></span>
                                            <span class="text-white-50 small">Pending</span>
                                        </div>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-flex gap-2 justify-content-end">
                                            <button class="btn-action" title="View Request Details" data-bs-toggle="modal" data-bs-target="#viewReqModal"><i class="bi bi-eye-fill"></i></button>
                                            <a href="#" class="btn-action btn-action-success" title="Approve"><i class="bi bi-check-lg"></i></a>
                                            <a href="#" class="btn-action btn-action-danger" title="Reject"><i class="bi bi-x-lg"></i></a>
                                        </div>
                                    </td>
                                </tr>
                                <!-- Example Row 2 -->
                                <tr>
                                    <td class="fw-bold txt-neon-cyan">#REQ-9975</td>
                                    <td>
                                        <div class="fw-semibold text-white">Nexus Logistics Co.</div>
                                        <div class="text-white-50 small" style="font-size: 0.8rem;">Mandalay Hub</div>
                                    </td>
                                    <td>
                                        <span class="badge rounded-pill bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2.5 py-1.5">License Mod</span>
                                    </td>
                                    <td class="text-white-50">July 15, 2026</td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="d-inline-block rounded-circle bg-success" style="width: 8px; height: 8px; box-shadow: 0 0 8px #198754;"></span>
                                            <span class="text-white-50 small">Approved</span>
                                        </div>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-flex gap-2 justify-content-end">
                                            <button class="btn-action" title="View Request Details" data-bs-toggle="modal" data-bs-target="#viewReqModal"><i class="bi bi-eye-fill"></i></button>
                                            <button class="btn-action btn-action-danger" title="Archive / Delete"><i class="bi bi-trash3-fill"></i></button>
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

    <!-- REQUEST PREVIEW MODAL -->
    <div class="modal fade glass-modal" id="viewReqModal" tabindex="-1" aria-labelledby="viewReqModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="viewReqModalLabel"><i class="bi bi-file-earmark-text txt-neon-cyan me-2"></i> Request Specification - #REQ-9981</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-4">
                        <div class="col-12 col-md-6">
                            <h6 class="fw-semibold text-white mb-3" style="letter-spacing: 0.5px;">SUBMITTER INFO</h6>
                            <div class="mb-3">
                                <label class="text-white-50 small d-block">Corporate Partner</label>
                                <span class="text-white">Apex Fueling Group</span>
                            </div>
                            <div class="mb-3">
                                <label class="text-white-50 small d-block">Authorized Official</label>
                                <span class="text-white">John Doe (john.doe@apex.com)</span>
                            </div>
                            <div class="mb-3">
                                <label class="text-white-50 small d-block">Operation Hub Location</label>
                                <span class="text-white">No. 124, Pyay Road, Kamayut, Yangon</span>
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <h6 class="fw-semibold text-white mb-3" style="letter-spacing: 0.5px;">REQUEST SPECIFICS</h6>
                            <div class="mb-3">
                                <label class="text-white-50 small d-block">Desired Action Type</label>
                                <span class="badge rounded-pill bg-info bg-opacity-10 text-info border border-info border-opacity-25 px-2 py-1">New Station Authorization</span>
                            </div>
                            <div class="mb-3">
                                <label class="text-white-50 small d-block">Required Capacity Setup</label>
                                <span class="text-white">3 Pump Units (Petrol/Diesel 92 & 95)</span>
                            </div>
                            <div class="mb-3">
                                <label class="text-white-50 small d-block">Submitted Verification Document</label>
                                <a href="#" class="text-decoration-none txt-neon-blue d-flex align-items-center gap-2 mt-1">
                                    <i class="bi bi-file-pdf-fill fs-5 text-danger"></i>
                                    <span>fuel_station_permit_2026.pdf</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <form action="decline_req.php" method="POST" class="me-auto">
                        <input type="hidden" name="req_id" value="9981">
                        <button type="submit" class="btn btn-outline-danger border-danger border-opacity-20 px-3">Decline Request</button>
                    </form>
                    <button type="button" class="btn btn-outline-secondary rounded-3 text-white-50 border-secondary border-opacity-20" data-bs-dismiss="modal">Cancel</button>
                    <form action="approve_req.php" method="POST">
                        <input type="hidden" name="req_id" value="9981">
                        <button type="submit" class="btn btn-neon px-4">Authorize Registration</button>
                    </form>
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
                    <a href="admin_dashboard.php" class="menu-btn-nav">
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
                    <a href="manage_admin.php" class="menu-btn-nav">
                        <i class="bi bi-people-fill me-3 txt-neon-blue"></i> Manage Admins
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