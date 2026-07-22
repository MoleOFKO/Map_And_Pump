<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Requests - Map & Pump</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <!-- Browser Tab Logo (Favicon) -->
    <link rel="icon" type="image/png" href="/images/Logo.png">
    <link rel="stylesheet" href="/css/Admin.css">
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
                        <img src="/images/Logo.png" alt="Logo" class="nav-logo">
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
                    <a href="manage_moderators.php" class="menu-btn-nav">
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