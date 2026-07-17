<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Map & Pump</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <!-- Browser Tab Logo (Favicon) -->
    <link rel="icon" type="image/png" href="../../images/Logo.png">
    <link rel="stylesheet" href="../../css/user_css.css">
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

        .navigation_style .navbar-toggler:focus {
            box-shadow: 0 0 0 0.25rem rgba(0, 244, 255, 0.2);
        }

        .navigation_style .nav-link {
            color: #a3b8cc !important;
            transition: color 0.2s ease, text-shadow 0.2s ease;
        }

        .navigation_style .nav-link:hover, 
        .navigation_style .nav-link:focus {
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

        .analytics-card {
            padding: 24px;
        }

        /* METRICS & CHIPS */
        .metric-card {
            min-height: 125px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            background: rgba(4, 9, 21, 0.4) !important;
            border: 1px solid rgba(255, 255, 255, 0.04);
            border-radius: 16px;
            transition: transform 0.2s ease;
        }

        .metric-card:hover {
            transform: translateY(-2px);
        }

        .metric-card .metric-value {
            font-size: 2rem;
            font-weight: 700;
            letter-spacing: -0.03em;
        }

        .metric-card .metric-label {
            color: #8fa0dd;
            font-size: 0.85rem;
            font-weight: 500;
        }

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

        /* STATUS BADGES & SELECTION */
        .btn-filter {
            min-width: 90px;
            border-color: var(--glass-border) !important;
            font-weight: 500;
            font-size: 0.85rem;
            transition: all 0.2s ease;
        }

        .btn-check:checked + .btn-filter {
            background-color: rgba(0, 244, 255, 0.15) !important;
            border-color: var(--neon-cyan) !important;
            color: #ffffff !important;
            box-shadow: 0 0 10px rgba(0, 244, 255, 0.2);
        }

        /* CHROME GRADIENTS & HEALTH GRAPH */
        .bg-gradient-blue-cyan {
            background: linear-gradient(90deg, var(--neon-blue) 0%, var(--neon-cyan) 100%);
        }

        .chart-placeholder {
            min-height: 260px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(3, 7, 15, 0.6);
            border-radius: 16px;
            border: 1px dashed rgba(0, 244, 255, 0.15);
        }

        /* TABLES */
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

        /* OFF-CANVAS MAIN MENU BUTTONS */
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
            box-shadow: 0 4px 15px rgba(220, 53, 69, 0.15);
        }

        /* UTILITY COLORS */
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
            letter-spacing: 0.05em;
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
                    <a href="view_hq_and_user_account.php" class="menu-btn-nav">
                        <i class="bi bi-building-gear me-3 txt-neon-cyan"></i>  View HQ & User Accounts
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