<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.    <meta name="description" content="Map & Pump Admin — System reports and logs. Review consolidated performance metrics, station activities, and exportable data logs.">
    <title>View Reports — Map &amp; Pump</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="/images/Logo.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
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

    <!-- MAIN CONTENT -->
    <div class="container my-5">

        <!-- Header & Action Row -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <span class="stat-chip mb-2">
                    <i class="bi bi-bar-chart-line-fill txt-neon-cyan"></i>
                    Analytics Center
                </span>
                <h1 class="fw-bold m-0 h4 text-white mt-2">System Reports &amp; Logs</h1>
                <p class="text-white-50 m-0 small mt-1">Review consolidated performance metrics, system activities, and exportable data logs.</p>
            </div>
            <button class="btn btn-neon d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#generateReportModal">
                <i class="bi bi-file-earmark-plus-fill"></i> Generate Report
            </button>
        </div>

        <!-- Metric KPI Grid -->
        <div class="row g-4 mb-4">
            <div class="col-12 col-sm-6 col-md-3">
                <div class="dashboard-card metric-card">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div>
                            <p class="mb-0 metric-label">Total HQs Registered</p>
                            <h4 class="fw-bold text-white mb-0 metric-value">142</h4>
                        </div>
                        <span class="fs-3 txt-neon-cyan"><i class="bi bi-building"></i></span>
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-md-3">
                <div class="dashboard-card metric-card">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div>
                            <p class="mb-0 metric-label">Active Stations</p>
                            <h4 class="fw-bold text-white mb-0 metric-value">849</h4>
                        </div>
                        <span class="fs-3 txt-neon-blue"><i class="bi bi-geo-alt-fill"></i></span>
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-md-3">
                <div class="dashboard-card metric-card">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div>
                            <p class="mb-0 metric-label">Requests Handled</p>
                            <h4 class="fw-bold text-white mb-0 metric-value">3,412</h4>
                        </div>
                        <span class="fs-3 text-warning"><i class="bi bi-clipboard-data"></i></span>
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-md-3">
                <div class="dashboard-card metric-card">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div>
                            <p class="mb-0 metric-label">System Uptime</p>
                            <h4 class="fw-bold text-white mb-0 metric-value">99.98%</h4>
                        </div>
                        <span class="fs-3 text-success"><i class="bi bi-cpu-fill"></i></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <!-- Search & Filter Bar -->
            <div class="col-12">
                <div class="dashboard-card py-3 px-4">
                    <form class="row g-3 align-items-center" method="GET" action="">
                        <div class="col-12 col-md-5">
                            <div class="input-group">
                                <span class="input-group-text bg-transparent border-end-0" style="border-color: var(--glass-border); color: var(--neon-cyan); border-radius: 12px 0 0 12px;">
                                    <i class="bi bi-search"></i>
                                </span>
                                <input type="text" class="form-control glass-input border-start-0" placeholder="Search by report name or ID..." name="search">
                            </div>
                        </div>
                        <div class="col-12 col-md-3">
                            <select class="form-select glass-select" name="scope">
                                <option value="" selected>All Scopes</option>
                                <option value="hq_performance">HQ Performance</option>
                                <option value="station_status">Station Status Logs</option>
                                <option value="audit_logs">Audit &amp; Request Logs</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-2">
                            <select class="form-select glass-select" name="format">
                                <option value="" selected>All Formats</option>
                                <option value="pdf">PDF Document</option>
                                <option value="csv">CSV Spreadsheet</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-2 d-grid">
                            <button type="submit" class="btn btn-neon">Apply Filter</button>
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
                                    <th>Report Details</th>
                                    <th>Scope Level</th>
                                    <th>Time Frame Covered</th>
                                    <th>Generated By</th>
                                    <th>Format</th>
                                    <th class="text-end">Download</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Example Row 1 -->
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="rounded-3 d-flex align-items-center justify-content-center text-white" style="width: 44px; height: 44px; background: rgba(0, 244, 255, 0.1); border: 1px solid rgba(0,244,255,0.25); flex-shrink:0;">
                                                <i class="bi bi-file-earmark-bar-graph-fill fs-5 txt-neon-cyan"></i>
                                            </div>
                                            <div>
                                                <div class="fw-semibold text-white">Q2 HQ Performance Summary</div>
                                                <div class="text-white-50 small" style="font-size: 0.8rem;">ID: #REP-2026-042</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge rounded-pill bg-info bg-opacity-10 text-info border border-info border-opacity-25 px-2 py-1">HQ Performance</span>
                                    </td>
                                    <td class="text-white-50">Apr 01, 2026 – Jun 30, 2026</td>
                                    <td>
                                        <div class="text-white">System Admin</div>
                                        <div class="text-white-50 small" style="font-size: 0.8rem;">Auto-Generated</div>
                                    </td>
                                    <td>
                                        <span class="text-danger fw-semibold"><i class="bi bi-file-pdf me-1"></i>PDF</span>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-flex gap-2 justify-content-end">
                                            <a href="#" class="btn-action" title="Download Report"><i class="bi bi-cloud-arrow-down-fill"></i></a>
                                            <a href="#" class="btn-action btn-action-danger" title="Delete Archive"><i class="bi bi-trash3-fill"></i></a>
                                        </div>
                                    </td>
                                </tr>
                                <!-- Example Row 2 -->
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="rounded-3 d-flex align-items-center justify-content-center text-white" style="width: 44px; height: 44px; background: rgba(122, 92, 255, 0.1); border: 1px solid rgba(122,92,255,0.25); flex-shrink:0;">
                                                <i class="bi bi-file-earmark-spreadsheet-fill fs-5 txt-neon-purple"></i>
                                            </div>
                                            <div>
                                                <div class="fw-semibold text-white">Yangon Region Pump Verification Logs</div>
                                                <div class="text-white-50 small" style="font-size: 0.8rem;">ID: #REP-2026-039</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge rounded-pill bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2 py-1">Station Logs</span>
                                    </td>
                                    <td class="text-white-50">Jun 01, 2026 – Jun 15, 2026</td>
                                    <td>
                                        <div class="text-white">Admin Staff</div>
                                        <div class="text-white-50 small" style="font-size: 0.8rem;">ID: #ADM-0921</div>
                                    </td>
                                    <td>
                                        <span class="fw-semibold" style="color:#1be494;"><i class="bi bi-filetype-csv me-1"></i>CSV</span>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-flex gap-2 justify-content-end">
                                            <a href="#" class="btn-action" title="Download Report"><i class="bi bi-cloud-arrow-down-fill"></i></a>
                                            <a href="#" class="btn-action btn-action-danger" title="Delete Archive"><i class="bi bi-trash3-fill"></i></a>
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

    <!-- GENERATE REPORT MODAL -->
    <div class="modal fade glass-modal" id="generateReportModal" tabindex="-1" aria-labelledby="generateReportModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="generateReportModalLabel"><i class="bi bi-bar-chart-steps txt-neon-cyan me-2"></i> Configure Custom Report</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="generate_report_action.php" method="POST">
                    <div class="modal-body d-flex flex-column gap-3">
                        <div>
                            <label class="form-label text-white-50 small">Report Title</label>
                            <input type="text" name="report_title" class="form-control glass-input" placeholder="e.g., Q3 Fuel Distribution Log" required>
                        </div>
                        <div>
                            <label class="form-label text-white-50 small">Target Data Scope</label>
                            <select class="form-select glass-select" name="scope" required>
                                <option value="hq_performance" selected>HQ Performance Summary</option>
                                <option value="stations">Physical Pump Station Audit</option>
                                <option value="requests">System Approvals &amp; Rejections</option>
                                <option value="system">Overall System Security Audit</option>
                            </select>
                        </div>
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label text-white-50 small">Start Date</label>
                                <input type="date" name="start_date" class="form-control glass-input" required style="color-scheme: dark;">
                            </div>
                            <div class="col-6">
                                <label class="form-label text-white-50 small">End Date</label>
                                <input type="date" name="end_date" class="form-control glass-input" required style="color-scheme: dark;">
                            </div>
                        </div>
                        <div>
                            <label class="form-label text-white-50 small">Export Format</label>
                            <div class="d-flex gap-4 mt-1">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="format" id="formatPdf" value="pdf" checked>
                                    <label class="form-check-label text-white small" for="formatPdf">
                                        <i class="bi bi-file-pdf text-danger me-1"></i> PDF Document
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="format" id="formatCsv" value="csv">
                                    <label class="form-check-label text-white small" for="formatCsv">
                                        <i class="bi bi-filetype-csv me-1" style="color:#1be494;"></i> CSV Sheet
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary rounded-3 text-white-50 border-secondary border-opacity-20" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-neon">Compile Data</button>
                    </div>
                </form>
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
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
        </div>
        <div class="offcanvas-body pt-3">
            <ul class="list-unstyled d-flex flex-column gap-2">
                <li><a href="admin_dashboard.php" class="menu-btn-nav"><i class="bi bi-house me-3 txt-neon-cyan"></i> Home</a></li>
                <li><a href="view_hq_and_user_account.php" class="menu-btn-nav"><i class="bi bi-building-gear me-3 txt-neon-cyan"></i> View HQ &amp; User Accounts</a></li>
                <li><a href="view_requests.php" class="menu-btn-nav"><i class="bi bi-file-earmark-check me-3 txt-neon-cyan"></i> View Requests</a></li>
                <li><a href="view_report.php" class="menu-btn-nav active-nav"><i class="bi bi-graph-up-arrow me-3 txt-neon-cyan"></i> View Report</a></li>
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
