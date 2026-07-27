<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Requests - Map & Pump</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <!-- Browser Tab Logo (Favicon) -->
    <link rel="icon" type="image/png" href="/images/Logo.png">
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

<?php $moderatorActiveNav = 'requests'; include __DIR__ . '/includes/moderator_offcanvas.php'; ?>

    <footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map & Pump | All Rights Reserved</footer>
<?php include __DIR__ . '/includes/moderator_footer_scripts.php'; ?>
</body>
</html>