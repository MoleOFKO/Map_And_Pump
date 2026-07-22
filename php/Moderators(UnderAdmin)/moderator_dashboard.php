<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Map & Pump</title>
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
                    <a class="navbar-brand d-sm-block d-none fw-semibold txt-neon-cyan" href="admin_dashboard.php" style="font-size: 0.95rem;">Admin Portal</a>
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

    <!-- MAIN DASHBOARD CONTENT -->
    <div class="container my-5">
        
        <!-- Filter Controls -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <h1 class="fw-bold m-0 h4 text-white">Control Center Overview</h1>
                <p class="text-white-50 m-0 small">System performance and account operations</p>
            </div>
            <div class="btn-group" role="group" aria-label="Timeframe filter">
                <input type="radio" class="btn-check" name="timeframe" id="btn-week" checked>
                <label class="btn btn-outline-info btn-sm btn-filter rounded-start-pill text-white" for="btn-week">Week</label>

                <input type="radio" class="btn-check" name="timeframe" id="btn-month">
                <label class="btn btn-outline-info btn-sm btn-filter text-white" for="btn-month">Month</label>

                <input type="radio" class="btn-check" name="timeframe" id="btn-year">
                <label class="btn btn-outline-info btn-sm btn-filter rounded-end-pill text-white" for="btn-year">Year</label>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-12 col-xl-8">
                <div class="dashboard-card h-100">
                    <div class="d-flex flex-column flex-sm-row justify-content-between gap-3 mb-4">
                        <div>
                            <span class="stat-chip">
                                <i class="bi bi-speedometer2 txt-neon-cyan"></i>
                                Live metrics
                            </span>
                            <h2 class="fw-bold mt-3 text-white">Performance Summary</h2>
                            <p class="text-white-50 mb-0 small">Keep an eye on traffic, requests, and operational health in one view.</p>
                        </div>
                        <div class="text-start">
                            <span class="d-inline-block badge rounded-pill bg-info bg-opacity-10 text-info fw-semibold py-2 px-3 border border-info border-opacity-20">Active</span>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-12 col-sm-6 col-lg-4">
                            <div class="analytics-card metric-card">
                                <div class="d-flex align-items-center gap-3">
                                    <span class="txt-neon-cyan fs-3"><i class="bi bi-people-fill"></i></span>
                                    <div>
                                        <p class="mb-0 metric-label">Companies</p>
                                        <h3 class="metric-value text-white mb-0">128</h3>
                                    </div>
                                </div>
                                <p class="text-success small mb-0 mt-2"><i class="bi bi-arrow-up-short"></i> +8% from last week</p>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-lg-4">
                            <div class="analytics-card metric-card">
                                <div class="d-flex align-items-center gap-3">
                                    <span class="txt-neon-blue fs-3"><i class="bi bi-card-checklist"></i></span>
                                    <div>
                                        <p class="mb-0 metric-label">Requests</p>
                                        <h3 class="metric-value text-white mb-0">56</h3>
                                    </div>
                                </div>
                                <p class="text-warning small mb-0 mt-2">35 pending review</p>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-lg-4">
                            <div class="analytics-card metric-card">
                                <div class="d-flex align-items-center gap-3">
                                    <span class="text-danger fs-3"><i class="bi bi-exclamation-triangle-fill"></i></span>
                                    <div>
                                        <p class="mb-0 metric-label">Errors</p>
                                        <h3 class="metric-value text-white mb-0">7</h3>
                                    </div>
                                </div>
                                <p class="text-danger small mb-0 mt-2">Critical logs flagged</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-xl-4">
                <div class="analytics-card h-100 d-flex flex-column justify-content-between">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div>
                            <p class="text-white-50 small text-uppercase mb-1" style="font-size: 0.75rem; letter-spacing: 0.5px;">System health</p>
                            <h5 class="fw-semibold mb-0 text-white">Quick Status</h5>
                        </div>
                        <span class="badge rounded-pill bg-success bg-opacity-15 text-success border border-success border-opacity-25">Stable</span>
                    </div>
                    <div class="chart-placeholder flex-grow-1">
                        <div class="text-center">
                            <i class="bi bi-graph-up-arrow fs-1 txt-neon-cyan mb-2 d-block"></i>
                            <span class="text-white-50 small">Dashboard graph coming soon</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="analytics-card">
                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-4">
                        <div>
                            <h5 class="fw-semibold mb-1 text-white">Activity Metrics</h5>
                            <p class="text-white-50 mb-0 small">Performance bar distribution across categories</p>
                        </div>
                        <span class="stat-chip"><i class="bi bi-clock-history"></i> Updated 10m ago</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-dark-custom align-middle m-0">
                            <thead>
                                <tr>
                                    <th>Category</th>
                                    <th>Progress</th>
                                    <th class="text-end">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="fw-medium text-white">Active Companies</td>
                                    <td style="width: 55%;">
                                        <div class="progress rounded-pill" style="height: 8px; background: rgba(255,255,255,0.08);">
                                            <div class="progress-bar bg-gradient-blue-cyan rounded-pill" style="width: 75%;"></div>
                                        </div>
                                    </td>
                                    <td class="text-end fw-semibold txt-neon-cyan">75%</td>
                                </tr>
                                <tr>
                                    <td class="fw-medium text-white">Pending Requests</td>
                                    <td>
                                        <div class="progress rounded-pill" style="height: 8px; background: rgba(255,255,255,0.08);">
                                            <div class="progress-bar bg-gradient-blue-cyan rounded-pill" style="width: 40%;"></div>
                                        </div>
                                    </td>
                                    <td class="text-end fw-semibold txt-neon-cyan">40%</td>
                                </tr>
                                <tr>
                                    <td class="fw-medium text-white">System Errors / Logs</td>
                                    <td>
                                        <div class="progress rounded-pill" style="height: 8px; background: rgba(255,255,255,0.08);">
                                            <div class="progress-bar bg-danger rounded-pill" style="width: 15%;"></div>
                                        </div>
                                    </td>
                                    <td class="text-end fw-semibold text-danger">15%</td>
                                </tr>
                                <tr>
                                    <td class="fw-medium text-white">New Admins</td>
                                    <td>
                                        <div class="progress rounded-pill" style="height: 8px; background: rgba(255,255,255,0.08);">
                                            <div class="progress-bar bg-success rounded-pill" style="width: 50%;"></div>
                                        </div>
                                    </td>
                                    <td class="text-end fw-semibold text-success">50%</td>
                                </tr>
                            </tbody>
                        </table>
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
                        <i class="bi bi-building-gear me-3 txt-neon-cyan"></i>  View HQ & User Accounts
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
                    <form action="logout(M).php" method="post" class="m-0">
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