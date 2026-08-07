<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Reports - Map & Pump</title>
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
        
        <!-- Header & Action Row -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <span class="stat-chip mb-2">
                    <i class="bi bi-bar-chart-line-fill txt-neon-cyan"></i>
                    Analytics Center
                </span>
                <h1 class="fw-bold m-0 h4 text-white">System Reports & Logs</h1>
                <p class="text-white-50 m-0 small">Review consolidated performance metrics, system activities, and exportable data logs.</p>
            </div>
            <button class="btn btn-neon d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#generateReportModal">
                <i class="bi bi-file-earmark-plus-fill"></i> Generate Report
            </button>
        </div>

        <!-- Metric KPI Grid -->
        <div class="row g-4 mb-4">
            <!-- Metric 1 -->
            <div class="col-12 col-sm-6 col-md-3">
                <div class="dashboard-card p-3 d-flex align-items-center justify-content-between">
                    <div>
                        <p class="text-white-50 mb-1 small uppercase">Total HQs Registered</p>
                        <h4 class="fw-bold text-white mb-0">142</h4>
                    </div>
                    <span class="fs-3 txt-neon-cyan"><i class="bi bi-building"></i></span>
                </div>
            </div>
            <!-- Metric 2 -->
            <div class="col-12 col-sm-6 col-md-3">
                <div class="dashboard-card p-3 d-flex align-items-center justify-content-between">
                    <div>
                        <p class="text-white-50 mb-1 small uppercase">Active Stations</p>
                        <h4 class="fw-bold text-white mb-0">849</h4>
                    </div>
                    <span class="fs-3 txt-neon-blue"><i class="bi bi-geo-alt-fill"></i></span>
                </div>
            </div>
            <!-- Metric 3 -->
            <div class="col-12 col-sm-6 col-md-3">
                <div class="dashboard-card p-3 d-flex align-items-center justify-content-between">
                    <div>
                        <p class="text-white-50 mb-1 small uppercase">Requests Handled</p>
                        <h4 class="fw-bold text-white mb-0">3,412</h4>
                    </div>
                    <span class="fs-3 text-warning"><i class="bi bi-clipboard-data"></i></span>
                </div>
            </div>
            <!-- Metric 4 -->
            <div class="col-12 col-sm-6 col-md-3">
                <div class="dashboard-card p-3 d-flex align-items-center justify-content-between">
                    <div>
                        <p class="text-white-50 mb-1 small uppercase">System Uptime</p>
                        <h4 class="fw-bold text-white mb-0">99.98%</h4>
                    </div>
                    <span class="fs-3 text-success"><i class="bi bi-cpu-fill"></i></span>
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
                                <span class="input-group-text bg-transparent border-end-0" style="border-color: var(--glass-border); color: var(--neon-cyan);">
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
                                <option value="audit_logs">Audit & Request Logs</option>
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
                                            <div class="rounded-3 d-flex align-items-center justify-content-center text-white" style="width: 44px; height: 44px; background: rgba(0, 244, 255, 0.1); border: 1px solid var(--neon-cyan);">
                                                <i class="bi bi-file-earmark-bar-graph-fill fs-5 txt-neon-cyan"></i>
                                            </div>
                                            <div>
                                                <div class="fw-semibold text-white">Q2 HQ Performance Summary</div>
                                                <div class="text-white-50 small" style="font-size: 0.8rem;">ID: #REP-2026-042</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge rounded-pill bg-info bg-opacity-10 text-info border border-info border-opacity-25 px-2.5 py-1.5">HQ Performance</span>
                                    </td>
                                    <td class="text-white-50">Apr 01, 2026 - Jun 30, 2026</td>
                                    <td>
                                        <div class="text-white">System Admin</div>
                                        <div class="text-white-50 small" style="font-size: 0.8rem;">Auto-Generated</div>
                                    </td>
                                    <td>
                                        <span class="text-danger fw-semibold"><i class="bi bi-file-pdf me-1"></i> PDF</span>
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
                                            <div class="rounded-3 d-flex align-items-center justify-content-center text-white" style="width: 44px; height: 44px; background: rgba(122, 92, 255, 0.1); border: 1px solid var(--neon-purple);">
                                                <i class="bi bi-file-earmark-spreadsheet-fill fs-5 txt-neon-purple"></i>
                                            </div>
                                            <div>
                                                <div class="fw-semibold text-white">Yangon Region Pump Verification Logs</div>
                                                <div class="text-white-50 small" style="font-size: 0.8rem;">ID: #REP-2026-039</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge rounded-pill bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2.5 py-1.5">Station Logs</span>
                                    </td>
                                    <td class="text-white-50">Jun 01, 2026 - Jun 15, 2026</td>
                                    <td>
                                        <div class="text-white">Admin Staff</div>
                                        <div class="text-white-50 small" style="font-size: 0.8rem;">ID: #ADM-0921</div>
                                    </td>
                                    <td>
                                        <span class="text-success fw-semibold"><i class="bi bi-filetype-csv me-1"></i> CSV</span>
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
                                <option value="requests">System Approvals & Rejections</option>
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
                            <label class="form-label text-white-50 small">Export Document Format</label>
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
                                        <i class="bi bi-filetype-csv text-success me-1"></i> CSV Sheet
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

<?php $moderatorActiveNav = 'report'; include __DIR__ . '/includes/moderator_offcanvas.php'; ?>

    <footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map & Pump | All Rights Reserved</footer>
<?php include __DIR__ . '/includes/moderator_footer_scripts.php'; ?>
</body>
</html>
