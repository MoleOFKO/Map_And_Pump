<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Feedback - Map & Pump</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../css/user_css.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .feedback-card { background-color: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.12); border-radius: 16px; transition: border-color 0.2s ease-in-out; }
        .feedback-card:hover { border-color: rgba(255, 255, 255, 0.3); }
        .badge-resolved { background-color: rgba(25, 135, 84, 0.2); color: #198754; border: 1px solid rgba(25, 135, 84, 0.3); }
        .badge-pending { background-color: rgba(255, 193, 7, 0.15); color: #ffc107; border: 1px solid rgba(255, 193, 7, 0.3); }
        .nav-logo { height: 32px; width: auto; object-fit: contain; }
    </style>
</head>
<body class="background_color" id="background_color">

    <!-- NAVBAR WITH CENTERED LOGO -->
    <div class="container">
        <nav class="navbar navbar-dark text-white navbar-expand-sm navigation_style px-3">
            <div class="container-fluid d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                    <button class="navbar-toggler me-2" type="button" data-bs-toggle="collapse" data-bs-target="#collapsibleNavbar"><span class="navbar-toggler-icon"></span></button>
                    <a class="navbar-brand expand-center d-sm-block d-none" href="staff_dashboard.php">Staff Portal</a>
                </div>
                <div class="position-sm-absolute start-50 translate-middle-sm text-center my-2 my-sm-0">
                    <a href="staff_dashboard.php" class="text-decoration-none d-flex align-items-center justify-content-center gap-2 text-white fw-bold">
                        <img src="../images/Logo.png" alt="Map & Pump Logo" class="nav-logo">
                        <span style="letter-spacing: 1px;">Map & Pump</span>
                    </a>
                </div>
                <div class="collapse navbar-collapse flex-grow-0" id="collapsibleNavbar">
                    <ul class="navbar-nav ms-auto gap-2 text-center text-sm-start mt-2 mt-sm-0">
                        <li class="nav-item"><a href="staff_dashboard.php" class="nav-link expand-center">Dashboard</a></li>
                        <li class="nav-item"><a class="nav-link expand-center" href="#" id="staffDropdown" role="button" data-bs-toggle="offcanvas" data-bs-target="#profileOffcanvas">Account</a></li>
                    </ul>
                </div>
            </div>
        </nav>
    </div>

    <div class="container my-4 my-sm-5 text_color">
        <div class="row mb-4">
            <div class="col-12 px-3">
                <div class="mb-2"><a href="staff_dashboard.php" class="text-white text-decoration-none small d-inline-flex align-items-center"><i class="bi bi-arrow-left me-2"></i> Back</a></div>
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <h2 class="fw-bold m-0">User Feedback Log</h2>
                        <p class="text-white-50 m-0 small">Review, address, and close support messages.</p>
                    </div>
                    <span class="badge bg-secondary py-2 px-3 rounded-pill">Total Unresolved: 1</span>
                </div>
            </div>
        </div>

        <div class="row g-3 px-2">
            <div class="col-12">
                <div class="feedback-card p-3 p-sm-4">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                        <div>
                            <h5 class="mb-1 text-white fw-semibold">Issue with QR Scanner App</h5>
                            <small class="text-white-50">By: <strong class="text-white">Alex Johnson</strong> • 2 hours ago</small>
                        </div>
                        <span class="badge badge-pending px-3 py-2 rounded-pill small fw-medium">Pending</span>
                    </div>
                    <p class="text-white-70 mb-4 small" style="opacity: 0.85;">"The ticket scanner didn't seem to recognize my generated QR code on my profile dashboard during check-in earlier today."</p>
                    <div class="d-flex justify-content-end gap-2 flex-wrap">
                        <button class="btn btn-outline-light btn-sm px-3" data-bs-toggle="modal" data-bs-target="#replyModal"><i class="bi bi-reply-fill me-1"></i> Reply</button>
                        <button class="btn btn-success btn-sm px-3"><i class="bi bi-check-lg me-1"></i> Resolve</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- REPLY MODAL -->
    <div class="modal fade" id="replyModal" tabindex="-1" aria-labelledby="replyModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered px-3">
            <div class="modal-content text_color" style="background-color: #1f2c39; border: 1px solid rgba(255,255,255,0.15)">
                <div class="modal-header border-bottom border-secondary">
                    <h5 class="modal-title" id="replyModalLabel">Draft Response to User</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                
                <!-- Form pointing directly to your processing script -->
                <form action="feedback_process.php" method="POST">
                    <div class="modal-body">
                    
                        <input type="hidden" id="modalFeedbackId" name="feedback_id" value="1"> 
                        <input type="hidden" id="modalStaffId" name="staff_id" value="CURRENT_STAFF_ID">

                        <div class="mb-3">
                            <label for="responseTextarea" class="form-label text-white-50 small">Message Body</label>
                            <textarea class="form-control" id="responseTextarea" name="response_msg" rows="5" 
                                    placeholder="Type your official reply or internal notes here..." 
                                    style="background: rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.2); color:#fff" 
                                    required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer border-top border-secondary">
                        <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancel</button>
                        <!-- Make sure this button is type="submit" so it triggers the form action -->
                        <button type="submit" name="submit_feedback_reply" class="btn btn-primary px-4">
                            <i class="bi bi-send-fill me-2"></i>Send Reply
                        </button>
                    </div>
                </form>

            </div>
        </div>
    </div>

    <!-- OFFCANVAS -->
    <div class="offcanvas offcanvas-end profile-offcanvas" tabindex="-1" id="profileOffcanvas">
        <div class="offcanvas-header">
            <h5 class="offcanvas-title">Staff Menu</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
        </div>
        <div class="offcanvas-body">
            <ul class="list-unstyled">
                <li class="mb-4">
                    <a href="user_manage.php" class="text-decoration-none text-white d-flex align-items-center p-2 rounded" style="background: rgba(255,255,255,0.05)">
                        <i class="bi bi-person-gear fs-3 me-3 text-info"></i>
                        <div><div class="fw-bold">Staff Session (Manage Users)</div><small class="text-white-50">Click to configure accounts</small></div>
                    </a>
                </li>
                <hr style="border-color: rgba(255,255,255,0.2)">
                <li class="mb-3"><a class="nav-link text-white d-flex align-items-center" href="logout.php"><i class="bi bi-box-arrow-right me-2 text-danger"></i> Logout</a></li>
            </ul>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>