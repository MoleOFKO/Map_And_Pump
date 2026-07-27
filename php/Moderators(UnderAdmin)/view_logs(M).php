<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Log Files - Map & Pump</title>
    <!-- Browser Tab Logo (Favicon) -->
    <link rel="icon" type="image/png" href="/images/Logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="/css/Admin.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
   
</head>
<body class="background_color" id="background_color">

<?php include __DIR__ . '/includes/moderator_navbar.php'; ?>

    <!-- MAIN MANAGE CONTENT -->
    <div class="container my-5">
        
        <!-- Header Row -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <span class="stat-chip mb-2">
                    <i class="bi bi-terminal-split txt-neon-cyan"></i>
                    System Monitoring
                </span>
                <h1 class="fw-bold m-0 h4 text-white">View Log Files</h1>
                <p class="text-white-50 m-0 small">Audit overall system connections, database transactions, and critical administrator changes.</p>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-danger btn-sm border-danger border-opacity-20 text-danger rounded-3" style="background: rgba(220, 53, 69, 0.05); padding: 10px 16px;" onclick="confirm('Clear all logs?')">
                    <i class="bi bi-trash3-fill me-1"></i> Clear Log
                </button>
                <button class="btn btn-neon d-flex align-items-center gap-2">
                    <i class="bi bi-cloud-arrow-down-fill"></i> Export Active File
                </button>
            </div>
        </div>

        <!-- Metric KPI Grid -->
        <div class="row g-4 mb-4">
            <div class="col-12 col-sm-6 col-md-3">
                <div class="dashboard-card p-3 d-flex align-items-center justify-content-between">
                    <div>
                        <p class="text-white-50 mb-1 small uppercase">Active Log File</p>
                        <h4 class="fw-bold text-white mb-0" style="font-size: 1.1rem;">system_audit.log</h4>
                    </div>
                    <span class="fs-3 txt-neon-cyan"><i class="bi bi-file-earmark-code"></i></span>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-md-3">
                <div class="dashboard-card p-3 d-flex align-items-center justify-content-between">
                    <div>
                        <p class="text-white-50 mb-1 small uppercase">File Allocation Size</p>
                        <h4 class="fw-bold text-white mb-0">1.24 MB</h4>
                    </div>
                    <span class="fs-3 txt-neon-blue"><i class="bi bi-hdd-network-fill"></i></span>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-md-3">
                <div class="dashboard-card p-3 d-flex align-items-center justify-content-between">
                    <div>
                        <p class="text-white-50 mb-1 small uppercase">Log Lines Printed</p>
                        <h4 class="fw-bold text-white mb-0">4,910</h4>
                    </div>
                    <span class="fs-3 text-warning"><i class="bi bi-list-columns"></i></span>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-md-3">
                <div class="dashboard-card p-3 d-flex align-items-center justify-content-between">
                    <div>
                        <p class="text-white-50 mb-1 small uppercase">Logged Exceptions</p>
                        <h4 class="fw-bold text-white mb-0">0 Critical</h4>
                    </div>
                    <span class="fs-3 text-success"><i class="bi bi-shield-fill-check"></i></span>
                </div>
            </div>
        </div>

        <!-- Toolbar & Filter Selection Bar -->
        <div class="dashboard-card py-3 px-4 mb-4">
            <form class="row g-3 align-items-center" method="GET" action="">
                <div class="col-12 col-md-5">
                    <div class="input-group">
                        <span class="input-group-text bg-transparent border-end-0" style="border-color: var(--glass-border); color: var(--neon-cyan);">
                            <i class="bi bi-terminal"></i>
                        </span>
                        <input type="text" class="form-control glass-input border-start-0" placeholder="Filter console outputs (e.g., SQL)..." name="search">
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <select class="form-select glass-select" name="log_level">
                        <option value="all" selected>All Debug & Status Levels</option>
                        <option value="info">INFO Outputs</option>
                        <option value="warn">WARN Outputs</option>
                        <option value="error">ERROR Outputs</option>
                        <option value="security">SECURITY Protocols</option>
                    </select>
                </div>
                <div class="col-12 col-md-3 d-grid">
                    <button type="submit" class="btn btn-outline-info rounded-3 text-white border-info border-opacity-20" style="background: rgba(0, 244, 255, 0.05); padding: 10px;">
                        Re-Scan Console Log
                    </button>
                </div>
            </form>
        </div>

        <!-- Terminal Console Space -->
        <div class="analytics-card p-1">
            <div class="terminal-panel">
                <div><span class="log-time">[2026-07-17 15:10:02]</span> <span class="log-info">[INFO]</span> Successfully initiated secure SSL handshake parameter.</div>
                <div><span class="log-time">[2026-07-17 15:10:05]</span> <span class="log-info">[INFO]</span> Database Pool size initialized (8 active instances configured).</div>
                <div><span class="log-time">[2026-07-17 15:11:34]</span> <span class="log-sec">[SECURITY]</span> Admin user <span class="txt-neon-cyan">@alex_sys</span> authenticated successfully from IP: 172.16.254.1.</div>
                <div><span class="log-time">[2026-07-17 15:12:12]</span> <span class="log-warn">[WARN]</span> Map verification response latency exceeded threshold (150ms).</div>
                <div><span class="log-time">[2026-07-17 15:13:01]</span> <span class="log-info">[INFO]</span> CRON: Cleaned up expired station queue logs successfully.</div>
                <div><span class="log-time">[2026-07-17 15:13:40]</span> <span class="log-error">[ERROR]</span> FXML Controller could not establish bind stream on system port 8081. (Target Refused).</div>
                <div><span class="log-time">[2026-07-17 15:14:22]</span> <span class="log-info">[INFO]</span> Map marker coordinate dataset verified with DB checksum <span class="text-success">OK</span>.</div>
                <div><span class="log-time">[2026-07-17 15:14:33]</span> <span class="log-sec">[SECURITY]</span> View logs file page access requested by current session.</div>
                <div class="mt-2"><span class="txt-neon-cyan">&gt; Console input listening for diagnostics sync...</span><span class="placeholder col-1 bg-info"></span></div>
            </div>
        </div>

    </div>

<?php $moderatorActiveNav = 'logs'; include __DIR__ . '/includes/moderator_offcanvas.php'; ?>

    <footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map & Pump | All Rights Reserved</footer>
<?php include __DIR__ . '/includes/moderator_footer_scripts.php'; ?>
</body>
</html>