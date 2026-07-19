<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HQ & User Accounts - Map & Pump</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <!-- Browser Tab Logo (Favicon) -->
    <link rel="icon" type="image/png" href="../../images/Logo.png">
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
                    <a class="navbar-brand d-sm-block d-none fw-semibold txt-neon-cyan" href="admin_dashboard.php" style="font-size: 0.95rem;">Admin Portal</a>

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
    <div class="container my-5">
        
        <!-- Header & Action Row -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <span class="stat-chip mb-2">
                    <i class="bi bi-building-fill txt-neon-cyan"></i>
                    Registry Control
                </span>
                <h1 class="fw-bold m-0 h4 text-white">HQ Stations & User Accounts</h1>
                <p class="text-white-50 m-0 small">Manage corporate headquarter structures, linked stations, and associated system user accounts.</p>
            </div>
            <button class="btn btn-neon d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#addHQModal">
                <i class="bi bi-plus-circle-fill"></i> Register New HQ
            </button>
        </div>

        <div class="row g-4">
            <!-- Search and Filter Panel -->
            <div class="col-12">
                <div class="dashboard-card py-3 px-4">
                    <form class="row g-3 align-items-center" method="GET" action="">
                        <div class="col-12 col-md-5">
                            <div class="input-group">
                                <span class="input-group-text bg-transparent border-end-0" style="border-color: var(--glass-border); color: var(--neon-cyan);">
                                    <i class="bi bi-search"></i>
                                </span>
                                <input type="text" class="form-control glass-input border-start-0" placeholder="Search by HQ, owner or city name..." name="search">
                            </div>
                        </div>
                        <div class="col-12 col-md-3">
                            <select class="form-select glass-select" name="region">
                                <option value="" selected>All Regions</option>
                                <option value="yangon">Yangon Region</option>
                                <option value="mandalay">Mandalay Region</option>
                                <option value="naypyidaw">Naypyidaw</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-2">
                            <select class="form-select glass-select" name="status">
                                <option value="" selected>All Status</option>
                                <option value="active">Active</option>
                                <option value="pending">Pending Verification</option>
                                <option value="suspended">Suspended</option>
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
                                    <th>HQ Entity & Owner</th>
                                    <th>Headquarters Location</th>
                                    <th>Linked Pumps/Users</th>
                                    <th>Status</th>
                                    <th class="text-end">Command</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Example Row 1 -->
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="rounded-3 d-flex align-items-center justify-content-center text-white fw-bold" style="width: 44px; height: 44px; background: rgba(0, 244, 255, 0.1); border: 1px solid var(--neon-cyan);">
                                                AF
                                            </div>
                                            <div>
                                                <div class="fw-semibold text-white">Apex Fueling Group</div>
                                                <div class="text-white-50 small" style="font-size: 0.8rem;">Owner: John Doe (john.doe@apex.com)</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="text-white">Yangon Sector 4</div>
                                        <div class="text-white-50 small" style="font-size: 0.8rem;">No. 124, Pyay Road, Kamayut Tsp</div>
                                    </td>
                                    <td>
                                        <span class="badge rounded-pill bg-info bg-opacity-10 text-info border border-info border-opacity-25 px-2.5 py-1.5">14 Stations Active</span>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="d-inline-block rounded-circle bg-success" style="width: 8px; height: 8px; box-shadow: 0 0 8px #198754;"></span>
                                            <span class="text-white-50 small">Active</span>
                                        </div>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-flex gap-2 justify-content-end">
                                            <a href="#" class="btn-action" title="View Stations & Users"><i class="bi bi-eye-fill"></i></a>
                                            <a href="#" class="btn-action" title="Edit HQ Account"><i class="bi bi-pencil-square"></i></a>
                                            <a href="#" class="btn-action btn-action-danger" title="Suspend/Remove"><i class="bi bi-trash3-fill"></i></a>
                                        </div>
                                    </td>
                                </tr>
                                <!-- Example Row 2 -->
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="rounded-3 d-flex align-items-center justify-content-center text-white fw-bold" style="width: 44px; height: 44px; background: rgba(122, 92, 255, 0.1); border: 1px solid var(--neon-purple);">
                                                NL
                                            </div>
                                            <div>
                                                <div class="fw-semibold text-white">Nexus Logistics Co.</div>
                                                <div class="text-white-50 small" style="font-size: 0.8rem;">Owner: Sarah Connor (s.connor@nexus.com)</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="text-white">Mandalay Hub</div>
                                        <div class="text-white-50 small" style="font-size: 0.8rem;">78th Street, Between 26th & 27th</div>
                                    </td>
                                    <td>
                                        <span class="badge rounded-pill bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2.5 py-1.5">8 Stations Active</span>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="d-inline-block rounded-circle bg-warning" style="width: 8px; height: 8px; box-shadow: 0 0 8px #ffc107;"></span>
                                            <span class="text-white-50 small">Pending</span>
                                        </div>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-flex gap-2 justify-content-end">
                                            <a href="#" class="btn-action" title="View Stations & Users"><i class="bi bi-eye-fill"></i></a>
                                            <a href="#" class="btn-action" title="Edit HQ Account"><i class="bi bi-pencil-square"></i></a>
                                            <a href="#" class="btn-action btn-action-danger" title="Suspend/Remove"><i class="bi bi-trash3-fill"></i></a>
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

    <!-- REGISTER HQ MODAL -->
    <div class="modal fade glass-modal" id="addHQModal" tabindex="-1" aria-labelledby="addHQModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="addHQModalLabel"><i class="bi bi-building-add txt-neon-cyan me-2"></i> Register New Headquarter</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="add_hq_action.php" method="POST">
                    <div class="modal-body d-flex flex-column gap-3">
                        <div>
                            <label class="form-label text-white-50 small">Company / HQ Name</label>
                            <input type="text" name="hq_name" class="form-control glass-input" placeholder="e.g. Apex Fueling Group" required>
                        </div>
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label text-white-50 small">Owner Name</label>
                                <input type="text" name="owner_name" class="form-control glass-input" placeholder="Owner Full Name" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label text-white-50 small">Owner Email</label>
                                <input type="email" name="owner_email" class="form-control glass-input" placeholder="owner@domain.com" required>
                            </div>
                        </div>
                        <div>
                            <label class="form-label text-white-50 small">Regional Headquarters Office</label>
                            <select class="form-select glass-select" name="region" required>
                                <option value="yangon" selected>Yangon Division</option>
                                <option value="mandalay">Mandalay Division</option>
                                <option value="naypyidaw">Naypyidaw Capital</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label text-white-50 small">Physical Address Details</label>
                            <textarea name="address" rows="2" class="form-control glass-input" placeholder="Street name, Township, Postal..." required style="resize: none;"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary rounded-3 text-white-50 border-secondary border-opacity-20" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-neon">Register Account</button>
                    </div>
                </form>
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
                        <i class="bi bi-house me-3 txt-neon-cyan"></i> Home
                    </a>
                </li>
                <li>
                    <a href="view_hq_and_user_account.php" class="menu-btn-nav">
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