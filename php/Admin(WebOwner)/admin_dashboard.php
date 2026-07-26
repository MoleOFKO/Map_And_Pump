<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.    <meta name="description" content="Map & Pump Admin Dashboard — Control center overview for tracking requests, reports, HQ accounts, and operational health.">
    <title>Admin Dashboard — Map &amp; Pump</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="icon" type="image/png" href="/images/Logo.png">
    <link rel="stylesheet" href="/css/Admin.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body class="background_color" id="background_color">

    <!-- TOP LOADING SHIMMER BAR -->
    <div class="page-load-bar"></div>

    <!-- IPHONE CAPSULE NAVBAR -->
    <div class="container sticky-nav-wrapper">
        <nav class="navbar navbar-dark text-white navigation_style">
            <div class="container-fluid d-flex justify-content-between align-items-center p-0 gap-3">
                <a class="navbar-brand d-flex align-items-center gap-2 fw-semibold txt-neon-cyan m-0" href="admin_dashboard.php" style="font-size: 0.95rem;">
                    <img src="/images/Logo.png" alt="Logo" class="nav-logo">
                    <span class="d-none d-sm-inline">Admin Portal</span>
                </a>

                <div class="admin-nav-actions">
                    <label class="cosmic-toggle nav-theme-toggle" title="Toggle light mode">
                        <input class="toggle js-theme-toggle" type="checkbox" aria-label="Toggle light mode" />
                        <div class="slider">
                            <span class="theme-icon theme-icon-dark"><i class="bi bi-moon-stars-fill"></i></span>
                            <span class="theme-icon theme-icon-light"><i class="bi bi-sun-fill"></i></span>
                            <div class="cosmos"></div>
                            <div class="energy-line"></div>
                            <div class="energy-line"></div>
                            <div class="energy-line"></div>
                            <div class="toggle-orb">
                                <div class="inner-orb"></div>
                                <div class="ring"></div>
                            </div>
                            <div class="particles">
                                <div style="--angle: 30deg" class="particle"></div>
                                <div style="--angle: 60deg" class="particle"></div>
                                <div style="--angle: 90deg" class="particle"></div>
                                <div style="--angle: 120deg" class="particle"></div>
                                <div style="--angle: 150deg" class="particle"></div>
                                <div style="--angle: 180deg" class="particle"></div>
                            </div>
                        </div>
                    </label>
                    <button class="btn btn-sm menu-pill-btn" type="button" data-bs-toggle="offcanvas" data-bs-target="#mainMenuOffcanvas">
                        <i class="bi bi-grid-fill me-1 txt-neon-cyan"></i> Menu
                    </button>
                </div>
            </div>
        </nav>
    </div>

    <!-- MAIN DASHBOARD CONTENT -->
    <div class="container my-5">

        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <span class="stat-chip mb-2">
                    <i class="bi bi-speedometer2 txt-neon-cyan"></i>
                    Admin Dashboard
                </span>
                <h1 class="fw-bold m-0 h4 text-white mt-2">Control Center Overview</h1>
                <p class="text-white-50 m-0 small mt-1">Track requests, reports, accounts, and operational health from one page.</p>
            </div>
            <span class="stat-chip"><i class="bi bi-clock-history me-1"></i> Updated 10m ago</span>
        </div>

        <!-- METRIC CARDS -->
        <div class="row g-3 mb-4">
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="analytics-card metric-card">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div>
                            <p class="mb-0 metric-label">Total HQs</p>
                            <h3 class="metric-value text-white mb-0">142</h3>
                        </div>
                        <span class="txt-neon-cyan fs-2"><i class="bi bi-building"></i></span>
                    </div>
                    <p class="text-success small mb-0 mt-2"><i class="bi bi-arrow-up-short"></i> 8 new this month</p>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="analytics-card metric-card">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div>
                            <p class="mb-0 metric-label">Open Requests</p>
                            <h3 class="metric-value text-white mb-0">56</h3>
                        </div>
                        <span class="txt-neon-blue fs-2"><i class="bi bi-file-earmark-check"></i></span>
                    </div>
                    <p class="text-warning small mb-0 mt-2">35 pending verification</p>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="analytics-card metric-card">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div>
                            <p class="mb-0 metric-label">Reports</p>
                            <h3 class="metric-value text-white mb-0">27</h3>
                        </div>
                        <span class="txt-neon-cyan fs-2"><i class="bi bi-file-earmark-bar-graph"></i></span>
                    </div>
                    <p class="text-white-50 small mb-0 mt-2">5 reports generated today</p>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="analytics-card metric-card">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div>
                            <p class="mb-0 metric-label">System Uptime</p>
                            <h3 class="metric-value text-white mb-0">99.98%</h3>
                        </div>
                        <span class="text-success fs-2"><i class="bi bi-cpu-fill"></i></span>
                    </div>
                    <p class="text-success small mb-0 mt-2"><i class="bi bi-shield-check me-1"></i>Stable service state</p>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <!-- OPERATIONAL SNAPSHOT -->
            <div class="col-12 col-xl-7">
                <div class="dashboard-card h-100">
                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start gap-3 mb-4">
                        <div>
                            <h2 class="fw-bold h5 text-white mb-1">Operational Snapshot</h2>
                            <p class="text-white-50 mb-0 small">The most active admin queues and their current status distribution.</p>
                        </div>
                        
                    </div>

                    <div class="row g-4">
                        <div class="col-12 col-lg-6 ">
                            <div class="pie-panel">
                                <div class="pie-chart request-pie" role="img" aria-label="Request status chart: 35 pending, 18 approved, 3 declined">
                                    <span>56</span>
                                </div>
                                <div class="flex-grow-1">
                                    <h3 class="h6 fw-semibold text-white mb-3">Request Status</h3>
                                    <ul class="chart-legend list-unstyled m-0">
                                        <li><span class="legend-dot bg-warning"></span><span>Pending</span><strong>35</strong></li>
                                        <li><span class="legend-dot" style="background:#1be494;"></span><span>Approved</span><strong>18</strong></li>
                                        <li><span class="legend-dot" style="background:#ff4d6a;"></span><span>Declined</span><strong>3</strong></li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-lg-6">
                            <div class="pie-panel">
                                <div class="pie-chart report-pie" role="img" aria-label="Report type chart: 12 HQ performance, 9 station logs, 6 audit reports">
                                    <span>27</span>
                                </div>
                                <div class="flex-grow-1">
                                    <h3 class="h6 fw-semibold text-white mb-3">Report Types</h3>
                                    <ul class="chart-legend list-unstyled m-0">
                                        <li><span class="legend-dot bg-info"></span><span>HQ Performance</span><strong>12</strong></li>
                                        <li><span class="legend-dot bg-primary"></span><span>Station Logs</span><strong>9</strong></li>
                                        <li><span class="legend-dot" style="background:#1be494;"></span><span>Audit Logs</span><strong>6</strong></li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SUMMARY CARDS -->
            <div class="col-12 col-xl-5">
                <div class="row g-4 h-100">
                    <div class="col-12">
                        <a href="view_requests.php" class="dashboard-summary-link">
                            <div class="analytics-card summary-card">
                                <div class="d-flex align-items-start justify-content-between gap-3">
                                    <div>
                                        <span class="stat-chip mb-3"><i class="bi bi-file-earmark-check"></i> View Requests</span>
                                        <h3 class="h5 fw-bold text-white mb-2 mt-2">Request Review Summary</h3>
                                        <p class="text-white-50 small mb-3">Pending partner approvals, station changes, and flagged request actions.</p>
                                    </div>
                                    <span class="summary-arrow"><i class="bi bi-arrow-right"></i></span>
                                </div>
                                <div class="summary-stat-grid">
                                    <div><span class="text-warning">35</span><small>Pending</small></div>
                                    <div><span style="color:#1be494;">18</span><small>Approved</small></div>
                                    <div><span style="color:#ff4d6a;">3</span><small>Declined</small></div>
                                </div>
                            </div>
                        </a>
                    </div>
                    <div class="col-12">
                        <a href="view_report.php" class="dashboard-summary-link">
                            <div class="analytics-card summary-card">
                                <div class="d-flex align-items-start justify-content-between gap-3">
                                    <div>
                                        <span class="stat-chip mb-3"><i class="bi bi-graph-up-arrow"></i> View Report</span>
                                        <h3 class="h5 fw-bold text-white mb-2 mt-2">Report Center Summary</h3>
                                        <p class="text-white-50 small mb-3">Generated reports, HQ performance summaries, station logs, and audit exports.</p>
                                    </div>
                                    <span class="summary-arrow"><i class="bi bi-arrow-right"></i></span>
                                </div>
                                <div class="summary-stat-grid">
                                    <div><span class="txt-neon-cyan">12</span><small>HQ Reports</small></div>
                                    <div><span class="txt-neon-blue">9</span><small>Station Logs</small></div>
                                    <div><span style="color:#1be494;">6</span><small>Audit Files</small></div>
                                </div>
                            </div>
                        </a>
                    </div>
                </div>
            </div>

            <!-- PRIORITY WORK QUEUE -->
            <div class="col-12">
                <div class="analytics-card">
                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-4">
                        <div>
                            <h3 class="h5 fw-semibold mb-1 text-white">Priority Work Queue</h3>
                            <p class="text-white-50 mb-0 small">Fast view of what needs admin attention first.</p>
                        </div>
                        <span class="stat-chip"><i class="bi bi-lightning-charge-fill txt-neon-cyan me-1"></i> Today</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-dark-custom align-middle m-0">
                            <thead>
                                <tr>
                                    <th>Area</th>
                                    <th>Status</th>
                                    <th>Next Action</th>
                                    <th class="text-end">Open</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="fw-medium text-white">Inbound Requests</td>
                                    <td><span class="badge rounded-pill bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25">35 Pending</span></td>
                                    <td class="text-white-50">Review new station registrations and license changes.</td>
                                    <td class="text-end"><a href="view_requests.php" class="btn-action" title="Open View Requests"><i class="bi bi-arrow-right"></i></a></td>
                                </tr>
                                <tr>
                                    <td class="fw-medium text-white">System Reports</td>
                                    <td><span class="badge rounded-pill bg-info bg-opacity-10 text-info border border-info border-opacity-25">27 Files</span></td>
                                    <td class="text-white-50">Check generated summaries and download required logs.</td>
                                    <td class="text-end"><a href="view_report.php" class="btn-action" title="Open View Report"><i class="bi bi-arrow-right"></i></a></td>
                                </tr>
                                <tr>
                                    <td class="fw-medium text-white">HQ &amp; User Accounts</td>
                                    <td><span class="badge rounded-pill bg-success bg-opacity-10 text-success border border-success border-opacity-25">142 Active</span></td>
                                    <td class="text-white-50">Monitor registered HQs and linked customer accounts.</td>
                                    <td class="text-end"><a href="view_hq_and_user_account.php" class="btn-action" title="Open HQ and User Accounts"><i class="bi bi-arrow-right"></i></a></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- SIDEBAR MAIN MENU -->
    <div class="offcanvas offcanvas-end" tabindex="-1" id="mainMenuOffcanvas" style="width: 320px; background: rgba(6, 12, 24, 0.98); backdrop-filter: blur(28px); border-left: 1px solid rgba(0, 180, 255, 0.18);">
        <div class="offcanvas-header pb-2 border-bottom border-white border-opacity-10">
            <div>
                <h5 class="offcanvas-title text-white fw-bold mb-0">Main Menu</h5>
                <p class="text-white-50 small mb-0" style="font-size:0.78rem;">Admin Portal</p>
            </div>
            <label class="cosmic-toggle float-end" title="Toggle light mode">
                <input class="toggle js-theme-toggle" id="themeToggle" type="checkbox" aria-label="Toggle light mode" />
                <div class="slider">
                    <span class="theme-icon theme-icon-dark"><i class="bi bi-moon-stars-fill"></i></span>
                    <span class="theme-icon theme-icon-light"><i class="bi bi-sun-fill"></i></span>
                    <div class="cosmos"></div>
                    <div class="energy-line"></div>
                    <div class="energy-line"></div>
                    <div class="energy-line"></div>
                    <div class="toggle-orb">
                    <div class="inner-orb"></div>
                    <div class="ring"></div>
                    </div>
                    <div class="particles">
                    <div style="--angle: 30deg" class="particle"></div>
                    <div style="--angle: 60deg" class="particle"></div>
                    <div style="--angle: 90deg" class="particle"></div>
                    <div style="--angle: 120deg" class="particle"></div>
                    <div style="--angle: 150deg" class="particle"></div>
                    <div style="--angle: 180deg" class="particle"></div>
                    </div>
                </div>
            </label>

            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
        </div>
        <div class="offcanvas-body pt-3">
            <ul class="list-unstyled d-flex flex-column gap-2">
                <li><a href="admin_dashboard.php" class="menu-btn-nav active-nav"><i class="bi bi-house me-3 txt-neon-cyan"></i> Home</a></li>
                <li><a href="view_hq_and_user_account.php" class="menu-btn-nav"><i class="bi bi-building-gear me-3 txt-neon-cyan"></i> View HQ &amp; User Accounts</a></li>
                <li><a href="view_requests.php" class="menu-btn-nav"><i class="bi bi-file-earmark-check me-3 txt-neon-cyan"></i> View Requests</a></li>
                <li><a href="view_report.php" class="menu-btn-nav"><i class="bi bi-graph-up-arrow me-3 txt-neon-cyan"></i> View Report</a></li>
                <li><a href="edit_profile.php" class="menu-btn-nav"><i class="bi bi-person-bounding-box me-3 txt-neon-blue"></i> Edit Profile</a></li>
                <li><a href="manage_moderators.php" class="menu-btn-nav"><i class="bi bi-people-fill me-3 txt-neon-blue"></i> Manage Moderators</a></li>
                <li><a href="view_logs.php" class="menu-btn-nav"><i class="bi bi-terminal-split me-3 text-danger"></i> View Log Files</a></li>
                <hr style="border-color: rgba(255, 255, 255, 0.08); margin: 4px 0;">
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
    <script src="/js/Admin.js"></script>
    <script>
        document.addEventListener('pointermove', (event) => {
            document.body.style.setProperty('--bg-x', `${(event.clientX / window.innerWidth) * 100}%`);
            document.body.style.setProperty('--bg-y', `${(event.clientY / window.innerHeight) * 100}%`);
        });
    </script>
</body>
</html>
