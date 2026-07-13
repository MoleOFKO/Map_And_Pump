<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Owner Dashboard - Map & Pump</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../css/user_css.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        /* Modern Tech-Blue Deep Theme Background Override */
        body.background_color {
            background-color: #0a111e !important;
            background-image: radial-gradient(circle at 50% 0%, #0c2340 0%, #0a111e 75%) !important;
        }

        /* iPhone Dynamic Island Style Floating Navbar Structure */
        .sticky-nav-wrapper {
            position: sticky;
            top: 20px;
            z-index: 1020;
            padding: 0;
            display: flex;
            justify-content: center;
        }
        .navigation_style {
            background: rgba(12, 35, 64, 0.4) !important;
            backdrop-filter: blur(25px);
            -webkit-backdrop-filter: blur(25px);
            border: 1px solid rgba(0, 180, 255, 0.25);
            border-radius: 50px !important;
            padding: 8px 24px !important;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.6), 0 0 15px rgba(0, 114, 255, 0.15);
            width: auto;
            min-width: 80%;
        }

        /* Fixed iPhone Capsule Button Design (Maintains Perfect 50% Capsule Radius) */
        .menu-btn-nav {
            background: rgba(12, 35, 64, 0.4) !important;
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(0, 114, 255, 0.2);
            border-radius: 50px !important; /* Locks shape at 50% pill radius */
            padding: 12px 24px !important;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.3);
            text-decoration: none; 
            color: #d1dbed; 
            display: flex; 
            align-items: center;
            transition: all 0.3s ease-in-out;
        }
        
        /* Smooth Liquid Glow & Shimmer Without Altering the Border Radius */
        .menu-btn-nav:hover { 
            color: #ffffff;
            background: rgba(0, 114, 255, 0.25) !important; 
            border-color: rgba(0, 244, 255, 0.5);
            
            /* Keeps the clean, solid pill shape exactly intact */
            border-radius: 50px !important; 
            
            box-shadow: 0 12px 28px rgba(0, 114, 255, 0.4), 
                        inset 0 4px 12px rgba(255, 255, 255, 0.3);
        }

        /* Matching Electric Color Adjustments for Menu Icons */
        .txt-neon-cyan { color: #00f4ff !important; }
        .txt-neon-blue { color: #0072ff !important; }

        .nav-logo { height: 28px; width: auto; }
        
        /* Analytics Glass Elements tailored to Logo Shield Palette */
        .analytics-card {
            background: rgba(12, 35, 64, 0.35);
            backdrop-filter: blur(15px);
            -webkit-backdrop-filter: blur(15px);
            border: 1px solid rgba(0, 114, 255, 0.2);
            border-radius: 24px;
            padding: 24px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.4);
        }
        .chart-placeholder {
            height: 250px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(5, 10, 18, 0.4);
            border-radius: 16px;
            border: 1px dashed rgba(0, 180, 255, 0.2);
        }
        .table-dark-custom {
            --bs-table-bg: transparent;
            --bs-table-border-color: rgba(0, 114, 255, 0.15);
            color: #d1dbed;
        }

        .bg-gradient-blue-cyan {
            background: linear-gradient(90deg, #0072ff 0%, #00f4ff 100%) !important;
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
                    <a class="navbar-brand d-sm-block d-none fw-semibold txt-neon-cyan" href="owner_dashboard.php" style="font-size: 0.95rem;">Owner Portal</a>
                </div>

                <div class="text-center">
                    <a href="owner_dashboard.php" class="text-decoration-none d-flex align-items-center justify-content-center gap-2 text-white fw-bold">
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

    <!-- MAIN DASHBOARD CONTENT -->
    <div class="container my-5 text_color">
        
        <!-- Filter Controls -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <h1 class="fw-bold m-0 h4 text-white">Control Center Overview</h1>
                <p class="text-white-50 m-0 small">System performance data distribution</p>
            </div>
            <div class="btn-group" role="group" aria-label="Timeframe filter">
                <input type="radio" class="btn-check" name="timeframe" id="btn-week" checked>
                <label class="btn btn-outline-info btn-sm px-3 rounded-start-pill text-white" for="btn-week">Week</label>

                <input type="radio" class="btn-check" name="timeframe" id="btn-month">
                <label class="btn btn-outline-info btn-sm px-3 text-white" for="btn-month">Month</label>

                <input type="radio" class="btn-check" name="timeframe" id="btn-year">
                <label class="btn btn-outline-info btn-sm px-3 rounded-end-pill text-white" for="btn-year">Year</label>
            </div>
        </div>

        <div class="row g-4">
            <!-- Left: Pie Chart Section -->
            <div class="col-12 col-md-5">
                <div class="analytics-card h-100">
                    <h5 class="fw-semibold mb-3 text-white-50 small text-uppercase tracking-wider">Share Distribution</h5>
                    <div class="chart-placeholder">
                        <div class="text-center">
                            <i class="bi bi-pie-chart fs-1 txt-neon-cyan mb-2 d-block"></i>
                            <span class="text-white-50 small">[Pie Chart]</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right: Bar Table Summary -->
            <div class="col-12 col-md-7">
                <div class="analytics-card h-100">
                    <h5 class="fw-semibold mb-3 text-white-50 small text-uppercase tracking-wider">Data Metrics & Bars</h5>
                    <div class="table-responsive">
                        <table class="table table-dark-custom align-middle m-0 small">
                            <thead>
                                <tr>
                                    <th>Category</th>
                                    <th>Volume Metric Bar</th>
                                    <th class="text-end">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="fw-medium text-white">Active Companies</td>
                                    <td style="width: 55%;">
                                        <div class="progress rounded-pill" style="height: 6px; background: rgba(255,255,255,0.08);">
                                            <div class="progress-bar bg-gradient-blue-cyan rounded-pill" style="width: 75%"></div>
                                        </div>
                                    </td>
                                    <td class="text-end fw-semibold txt-neon-cyan">75%</td>
                                </tr>
                                <tr>
                                    <td class="fw-medium text-white">Pending Requests</td>
                                    <td>
                                        <div class="progress rounded-pill" style="height: 6px; background: rgba(255,255,255,0.08);">
                                            <div class="progress-bar bg-gradient-blue-cyan rounded-pill" style="width: 40%"></div>
                                        </div>
                                    </td>
                                    <td class="text-end fw-semibold txt-neon-cyan">40%</td>
                                </tr>
                                <tr>
                                    <td class="fw-medium text-white">System Errors / Logs</td>
                                    <td>
                                        <div class="progress rounded-pill" style="height: 6px; background: rgba(255,255,255,0.08);">
                                            <div class="progress-bar bg-danger rounded-pill" style="width: 15%"></div>
                                        </div>
                                    </td>
                                    <td class="text-end fw-semibold text-danger">15%</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- SIDEBAR MAIN MENU -->
    <div class="offcanvas offcanvas-end profile-offcanvas" tabindex="-1" id="mainMenuOffcanvas" style="width: 320px; background: rgba(8, 15, 28, 0.96); backdrop-filter: blur(25px); border-left: 1px solid rgba(0, 114, 255, 0.25);">
        <div class="offcanvas-header pb-2">
            <h5 class="offcanvas-title text-white fw-bold">Main Menu</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
        </div>
        <div class="offcanvas-body">
            <ul class="list-unstyled d-flex flex-column gap-3">
                <li>
                    <a href="view_company.php" class="menu-btn-nav">
                        <i class="bi bi-building-gear fs-5 me-3 txt-neon-cyan"></i> View Company[cite: 1]
                    </a>
                </li>
                <li>
                    <a href="view_requests.php" class="menu-btn-nav">
                        <i class="bi bi-file-earmark-check fs-5 me-3 txt-neon-cyan"></i> View Requests[cite: 1]
                    </a>
                </li>
                <li>
                    <a href="view_report.php" class="menu-btn-nav">
                        <i class="bi bi-graph-up-arrow fs-5 me-3 txt-neon-cyan"></i> View Report[cite: 1]
                    </a>
                </li>
                <li>
                    <a href="edit_profile.php" class="menu-btn-nav">
                        <i class="bi bi-person-bounding-box fs-5 me-3 txt-neon-blue"></i> Edit Profile[cite: 1]
                    </a>
                </li>
                <li>
                    <a href="manage_admin.php" class="menu-btn-nav">
                        <i class="bi bi-people-fill fs-5 me-3 txt-neon-blue"></i> Manage Admins[cite: 1]
                    </a>
                </li>
                <li>
                    <a href="view_logs.php" class="menu-btn-nav">
                        <i class="bi bi-terminal-split fs-5 me-3 text-danger"></i> View Log Files[cite: 1]
                    </a>
                </li>
                <hr style="border-color: rgba(0, 114, 255, 0.25);">
                <li>
                    <a class="nav-link text-danger d-flex align-items-center px-4 py-2 rounded-pill" href="logout.php" style="transition: background 0.2s;" onmouseover="this.style.background='rgba(220,53,69,0.1)'" onmouseout="this.style.background='transparent'">
                        <i class="bi bi-box-arrow-right me-3 fs-5"></i> Logout[cite: 1]
                    </a>
                </li>
            </ul>
        </div>
    </div>

    <footer class="text-white-50 text-center py-3 mt-5 small" style="border-top: 1px solid rgba(0, 114, 255, 0.1);">&copy; 2026 Map & Pump | All Rights Reserved</footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>