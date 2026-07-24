<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.    <meta name="description" content="Map & Pump Admin — View system log files. Audit overall system connections, database transactions, and critical administrator changes.">
    <title>View Log Files — Map &amp; Pump</title>
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

                <button class="btn btn-sm menu-pill-btn" type="button" data-bs-toggle="offcanvas" data-bs-target="#mainMenuOffcanvas">
                    <i class="bi bi-grid-fill me-1 txt-neon-cyan"></i> Menu
                </button>
            </div>
        </nav>
    </div>

    <!-- MAIN CONTENT -->
    <div class="container my-5">

        <!-- Header Row -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <span class="stat-chip mb-2">
                    <i class="bi bi-terminal-split txt-neon-cyan"></i>
                    System Monitoring
                </span>
                <h1 class="fw-bold m-0 h4 text-white mt-2">View Log Files</h1>
                <p class="text-white-50 m-0 small mt-1">Audit overall system connections, database transactions, and critical administrator changes.</p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <button class="btn btn-outline-danger btn-sm border-danger border-opacity-20 text-danger rounded-3 d-flex align-items-center gap-2" style="background: rgba(255, 77, 106, 0.05); padding: 10px 16px;" onclick="confirm('Clear all logs?')">
                    <i class="bi bi-trash3-fill"></i> Clear Log
                </button>
                <button class="btn btn-neon d-flex align-items-center gap-2">
                    <i class="bi bi-cloud-arrow-down-fill"></i> Export Active File
                </button>
            </div>
        </div>

        <!-- Metric KPI Grid -->
        <div class="row g-4 mb-4">
            <div class="col-12 col-sm-6 col-md-3">
                <div class="dashboard-card metric-card">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div>
                            <p class="mb-0 metric-label">Active Log File</p>
                            <h4 class="fw-bold text-white mb-0" style="font-size: 0.95rem; letter-spacing:-0.01em; word-break:break-all;">system_audit.log</h4>
                        </div>
                        <span class="fs-3 txt-neon-cyan"><i class="bi bi-file-earmark-code"></i></span>
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-md-3">
                <div class="dashboard-card metric-card">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div>
                            <p class="mb-0 metric-label">File Allocation Size</p>
                            <h4 class="fw-bold text-white mb-0 metric-value" style="font-size:1.8rem;">1.24 <span style="font-size:1rem;">MB</span></h4>
                        </div>
                        <span class="fs-3 txt-neon-blue"><i class="bi bi-hdd-network-fill"></i></span>
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-md-3">
                <div class="dashboard-card metric-card">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div>
                            <p class="mb-0 metric-label">Log Lines Printed</p>
                            <h4 class="fw-bold text-white mb-0 metric-value">4,910</h4>
                        </div>
                        <span class="fs-3 text-warning"><i class="bi bi-list-columns"></i></span>
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-md-3">
                <div class="dashboard-card metric-card">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div>
                            <p class="mb-0 metric-label">Logged Exceptions</p>
                            <h4 class="fw-bold text-white mb-0 metric-value" style="font-size:1.6rem;">0 <span style="font-size:1rem; font-weight:500;">Critical</span></h4>
                        </div>
                        <span class="fs-3 text-success"><i class="bi bi-shield-fill-check"></i></span>
                    </div>
                    <p class="text-success small mb-0 mt-2"><i class="bi bi-check-circle-fill me-1"></i>No exceptions flagged</p>
                </div>
            </div>
        </div>

        <!-- Toolbar & Filter Selection Bar -->
        <div class="dashboard-card py-3 px-4 mb-4">
            <form class="row g-3 align-items-center" method="GET" action="">
                <div class="col-12 col-md-5">
                    <div class="input-group">
                        <span class="input-group-text bg-transparent border-end-0" style="border-color: var(--glass-border); color: var(--neon-cyan); border-radius: 12px 0 0 12px;">
                            <i class="bi bi-terminal"></i>
                        </span>
                        <input type="text" class="form-control glass-input border-start-0" placeholder="Filter console outputs (e.g., SQL, AUTH)..." name="search">
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <select class="form-select glass-select" name="log_level">
                        <option value="all" selected>All Debug &amp; Status Levels</option>
                        <option value="info">INFO Outputs</option>
                        <option value="warn">WARN Outputs</option>
                        <option value="error">ERROR Outputs</option>
                        <option value="security">SECURITY Protocols</option>
                    </select>
                </div>
                <div class="col-12 col-md-3 d-grid">
                    <button type="submit" class="btn btn-neon">Re-Scan Console Log</button>
                </div>
            </form>
        </div>

        <!-- Terminal Console Space -->
        <div class="analytics-card p-1">
            <div class="terminal-panel">
                <div><span class="log-time">[2026-07-17 15:10:02]</span> <span class="log-info">[INFO]</span>&nbsp;&nbsp;&nbsp;&nbsp; Successfully initiated secure SSL handshake parameter.</div>
                <div><span class="log-time">[2026-07-17 15:10:05]</span> <span class="log-info">[INFO]</span>&nbsp;&nbsp;&nbsp;&nbsp; Database Pool size initialized (8 active instances configured).</div>
                <div><span class="log-time">[2026-07-17 15:11:34]</span> <span class="log-sec">[SECURITY]</span> Admin user <span class="txt-neon-cyan">@alex_sys</span> authenticated successfully from IP: 172.16.254.1.</div>
                <div><span class="log-time">[2026-07-17 15:12:12]</span> <span class="log-warn">[WARN]</span>&nbsp;&nbsp;&nbsp;&nbsp; Map verification response latency exceeded threshold (150ms).</div>
                <div><span class="log-time">[2026-07-17 15:13:01]</span> <span class="log-info">[INFO]</span>&nbsp;&nbsp;&nbsp;&nbsp; CRON: Cleaned up expired station queue logs successfully.</div>
                <div><span class="log-time">[2026-07-17 15:13:40]</span> <span class="log-error">[ERROR]</span>&nbsp;&nbsp;&nbsp; FXML Controller could not establish bind stream on system port 8081. (Target Refused).</div>
                <div><span class="log-time">[2026-07-17 15:14:22]</span> <span class="log-info">[INFO]</span>&nbsp;&nbsp;&nbsp;&nbsp; Map marker coordinate dataset verified with DB checksum <span class="text-success">OK</span>.</div>
                <div><span class="log-time">[2026-07-17 15:14:33]</span> <span class="log-sec">[SECURITY]</span> View logs file page access requested by current session.</div>
                <div class="mt-2"><span class="txt-neon-cyan">&gt; Console input listening for diagnostics sync...</span><span class="term-cursor"></span></div>
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
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
        </div>
        <div class="offcanvas-body pt-3">
            <ul class="list-unstyled d-flex flex-column gap-2">
                <li><a href="admin_dashboard.php" class="menu-btn-nav"><i class="bi bi-house me-3 txt-neon-cyan"></i> Home</a></li>
                <li><a href="view_hq_and_user_account.php" class="menu-btn-nav"><i class="bi bi-building-gear me-3 txt-neon-cyan"></i> View HQ &amp; User Accounts</a></li>
                <li><a href="view_requests.php" class="menu-btn-nav"><i class="bi bi-file-earmark-check me-3 txt-neon-cyan"></i> View Requests</a></li>
                <li><a href="view_report.php" class="menu-btn-nav"><i class="bi bi-graph-up-arrow me-3 txt-neon-cyan"></i> View Report</a></li>
                <li><a href="edit_profile.php" class="menu-btn-nav"><i class="bi bi-person-bounding-box me-3 txt-neon-blue"></i> Edit Profile</a></li>
                <li><a href="manage_moderators.php" class="menu-btn-nav"><i class="bi bi-people-fill me-3 txt-neon-blue"></i> Manage Moderators</a></li>
                <li><a href="view_logs.php" class="menu-btn-nav active-nav"><i class="bi bi-terminal-split me-3 text-danger"></i> View Log Files</a></li>
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