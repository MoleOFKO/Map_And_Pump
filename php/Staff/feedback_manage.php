<?php
session_start();
require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../feedback_schema.php';
require_once __DIR__ . '/../usage_history_schema.php';

function redirect_to_login(string $message = 'Please sign in to continue.')
{
    $_SESSION['login_error'] = $message;
    header('Location: ../Login/login.php');
    exit;
}

// Require staff session
if (empty($_SESSION['is_logged_in']) || empty($_SESSION['user_id'])) {
    redirect_to_login();
}
$roleName = strtolower((string)($_SESSION['role_name'] ?? ''));
if ($roleName !== 'staff') {
    redirect_to_login('This page is only available to staff accounts.');
}

// Load current staff user name
$displayName = '';
try {
    if (isset($pdo)) {
        $stmtUser = $pdo->prepare('SELECT user_name FROM users WHERE user_id = ? LIMIT 1');
        $stmtUser->execute([(int)$_SESSION['user_id']]);
        $uRow = $stmtUser->fetch(PDO::FETCH_ASSOC);
        $displayName = trim((string)($uRow['user_name'] ?? ''));
    }
} catch (Throwable $e) {
    // ignore — displayName remains empty
}

map_pump_feedback_schema_ready($pdo);
map_pump_usage_history_schema_ready($pdo);

// Load recent feedback (most recent 10)
$recentFeedback = [];
try {
    $stmt = $pdo->query(
        'SELECT f.feedback_id, f.feedback, f.created_at, f.usage_id, f.rating,
                u.user_name, uh.qr_refrence_code, s.station_name, o.oil_name
         FROM feedback f
         LEFT JOIN users u ON u.user_id = f.user_id
         LEFT JOIN usage_history uh ON uh.usage_id = f.usage_id
         LEFT JOIN stations s ON s.station_id = f.station_id
         LEFT JOIN oil_types o ON o.oil_type_id = uh.oil_type_id
         ORDER BY f.created_at DESC
         LIMIT 10'
    );
    $recentFeedback = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Failed to load staff feedback: ' . $e->getMessage());
    $recentFeedback = [];
}
$showSuccess = isset($_GET['report_created']) && $_GET['report_created'] === '1';
$showReplySuccess = isset($_GET['reply_sent']) && $_GET['reply_sent'] === '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Feedback - Map & Pump</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="icon" type="image/png" href="/images/Logo.png">
    <link rel="stylesheet" href="/css/Admin.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .badge-resolved { background-color: rgba(27, 228, 148, 0.15); color: #1be494; border: 1px solid rgba(27, 228, 148, 0.25); }
        .badge-pending { background-color: rgba(255, 193, 7, 0.15); color: #ffc107; border: 1px solid rgba(255, 193, 7, 0.25); }
    </style>
</head>
<body class="background_color" id="background_color">

    <!-- TOP LOADING SHIMMER BAR -->
    <div class="page-load-bar"></div>

    <!-- IPHONE CAPSULE NAVBAR -->
    <div class="container sticky-nav-wrapper">
        <nav class="navbar navbar-dark text-white navigation_style">
            <div class="container-fluid d-flex justify-content-between align-items-center p-0 gap-3">
                <a class="navbar-brand d-flex align-items-center gap-2 fw-semibold txt-neon-cyan m-0" href="staff_dashboard.php" style="font-size: 0.95rem;">
                    <img src="/images/Logo.png" alt="Logo" class="nav-logo">
                    <span class="d-none d-sm-inline">Staff Portal</span>
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
    <div class="container my-5 text_color">
        <div class="row mb-4">
            <div class="col-12 px-3">
                <div class="mb-2">
                    <a href="staff_dashboard.php" class="stat-chip text-decoration-none">
                        <i class="bi bi-arrow-left"></i> Back
                    </a>
                </div>
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mt-3">
                    <div>
                        <h2 class="fw-bold m-0 text-white">User Feedback Log</h2>
                        <p class="text-white-50 m-0 small mt-1">Review, address, and close support messages.</p>
                        <?php if ($displayName !== ''): ?>
                            <p class="text-white-50 m-0 small mt-1">Signed in as: <strong class="text-white"><?= htmlspecialchars($displayName) ?></strong></p>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex gap-2 align-items-center">
                        <button class="btn menu-pill-btn" type="button" data-bs-toggle="modal" data-bs-target="#createReportModal"><i class="bi bi-file-earmark-plus-fill me-1"></i> Create Report</button>
                        <span class="stat-chip" style="color:#ffc107; border-color: rgba(255,193,7,0.3); background: rgba(255,193,7,0.05);"><i class="bi bi-exclamation-circle me-1"></i> Total Unresolved: 1</span>
                    </div>
                </div>
            </div>
        </div>
        <?php if ($showSuccess): ?>
            <div class="alert alert-success border-0">Report was created and sent to Branch Manager.</div>
        <?php endif; ?>
        <?php if ($showReplySuccess): ?>
            <div class="alert alert-success border-0">Reply was saved and delivered to the user (internal record).</div>
        <?php endif; ?>

        <div class="row g-4 px-2">
            <?php if (empty($recentFeedback)): ?>
                <div class="col-12"><div class="dashboard-card text-center text-white-50 py-5">No recent feedback available.</div></div>
            <?php else: ?>
                <?php foreach ($recentFeedback as $fb): ?>
                    <?php
                        $isUsageFeedback = !empty($fb['usage_id']);
                        $feedbackTitle = $isUsageFeedback ? 'Fuel fill feedback' : (substr((string)$fb['feedback'], 0, 80) . (strlen((string)$fb['feedback']) > 80 ? '…' : ''));
                        $feedbackMeta = $isUsageFeedback ? ('Usage #' . (int)$fb['usage_id'] . (!empty($fb['qr_refrence_code']) ? ' / ' . $fb['qr_refrence_code'] : '')) : 'General feedback';
                    ?>
                    <div class="col-12">
                        <div class="dashboard-card">
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                                <div>
                                    <h5 class="mb-1 text-white fw-semibold"><?= htmlspecialchars($feedbackTitle) ?></h5>
                                    <small class="text-white-50">By: <strong class="text-white"><?= htmlspecialchars($fb['user_name'] ?: 'Anonymous') ?></strong> • <?= htmlspecialchars($feedbackMeta) ?> • <?= htmlspecialchars((new DateTime($fb['created_at']))->format('M j, Y \\a\\t H:i')) ?></small>
                                </div>
                                <span class="badge <?= 'badge-pending' ?> px-3 py-2 rounded-pill small fw-medium"><?= !empty($fb['rating']) ? ((int)$fb['rating'] . '/5') : 'Recent' ?></span>
                            </div>
                            <p class="text-white-70 mb-4 small" style="opacity: 0.85;"><?= nl2br(htmlspecialchars(substr($fb['feedback'], 0, 240))) ?><?= strlen($fb['feedback']) > 240 ? '…' : '' ?></p>
                            <div class="d-flex justify-content-end gap-2 flex-wrap">
                                <button class="btn menu-pill-btn px-4" data-bs-toggle="modal" data-bs-target="#replyModal" data-feedback-id="<?= (int)$fb['feedback_id'] ?>" data-staff-id="<?= (int)($_SESSION['user_id'] ?? 0) ?>"><i class="bi bi-reply-fill me-1"></i> Reply</button>
                                <button class="btn btn-outline-light px-4" data-feedback-id="<?= (int)$fb['feedback_id'] ?>" onclick="attachToReport(<?= (int)$fb['feedback_id'] ?>)"><i class="bi bi-pin-angle me-1"></i> Attach to Report</button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- REPLY MODAL -->
    <div class="modal fade" id="replyModal" tabindex="-1" aria-labelledby="replyModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered px-3">
            <div class="modal-content text_color" style="background: var(--glass-bg); backdrop-filter: blur(28px); border: 1px solid var(--glass-border); border-radius: var(--card-radius);">
                <div class="modal-header border-bottom border-secondary border-opacity-25">
                    <h5 class="modal-title text-white fw-bold" id="replyModalLabel">Draft Response to User</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                
                <!-- Form pointing directly to your processing script -->
                <form action="feedback_process.php" method="POST">
                    <div class="modal-body">
                    
                        <input type="hidden" id="modalFeedbackId" name="feedback_id" value="1"> 
                        <input type="hidden" id="modalStaffId" name="staff_id" value="CURRENT_STAFF_ID">

                        <div class="mb-3">
                            <label for="responseTextarea" class="form-label text-white-50 small">Message Body</label>
                            <textarea class="form-control glass-input" id="responseTextarea" name="response_msg" rows="5" 
                                    placeholder="Type your official reply or internal notes here..." 
                                    required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer border-top border-secondary border-opacity-25">
                        <button type="button" class="btn btn-outline-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="submit_feedback_reply" class="btn btn-primary rounded-pill px-4" style="background: var(--neon-cyan); border:none; color: #000; font-weight: 600;">
                            <i class="bi bi-send-fill me-2"></i>Send Reply
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

            <!-- CREATE REPORT MODAL -->
            <div class="modal fade" id="createReportModal" tabindex="-1" aria-labelledby="createReportModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-lg">
                    <div class="modal-content text_color" style="background: var(--glass-bg); backdrop-filter: blur(18px); border: 1px solid var(--glass-border); border-radius: var(--card-radius);">
                        <div class="modal-header">
                            <h5 class="modal-title text-white fw-bold" id="createReportModalLabel">Create Report for Branch Manager</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <form action="report_action.php" method="POST">
                            <div class="modal-body">
                                <input type="hidden" name="staff_id" value="<?= (int)($_SESSION['user_id'] ?? 0) ?>">
                                <div class="row g-3">
                                    <div class="col-12 col-md-6">
                                        <label class="form-label text-white-50 small">Importance</label>
                                        <select name="importance" class="form-select glass-select" required>
                                            <option value="low">Low</option>
                                            <option value="normal" selected>Normal</option>
                                            <option value="high">High</option>
                                        </select>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <label class="form-label text-white-50 small">Attach Recent Feedback (optional)</label>
                                        <select id="attachFeedbackSelect" name="attach_feedback_id" class="form-select glass-select">
                                            <option value="">-- None --</option>
                                            <?php foreach ($recentFeedback as $fbOpt): ?>
                                                <option value="<?= (int)$fbOpt['feedback_id'] ?>"><?= htmlspecialchars(substr($fbOpt['feedback'], 0, 80)) ?><?= strlen($fbOpt['feedback']) > 80 ? '…' : '' ?> — <?= htmlspecialchars($fbOpt['user_name'] ?: 'Anonymous') ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label text-white-50 small">Reason / Short Summary</label>
                                        <input type="text" name="short_summary" class="form-control glass-input" maxlength="255" placeholder="Short title for the report" required>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label text-white-50 small">Why it happened (optional)</label>
                                        <textarea name="why_happened" class="form-control glass-input" rows="3" placeholder="Optional: analysis or suspected cause"></textarea>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" name="submit_staff_report" class="btn btn-primary rounded-pill px-4" style="background: var(--neon-cyan); border:none; color:#000; font-weight:600;"><i class="bi bi-send-fill me-2"></i>Send to Branch Manager</button>
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
                <p class="text-white-50 small mb-0" style="font-size:0.78rem;">Staff Portal</p>
            </div>
            <label class="cosmic-toggle float-end" title="Toggle light mode">
                <input class="toggle js-theme-toggle" id="themeToggleMenu" type="checkbox" aria-label="Toggle light mode" />
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
                <li><a href="staff_dashboard.php" class="menu-btn-nav"><i class="bi bi-house me-3 txt-neon-cyan"></i> Dashboard</a></li>
                <li><a href="verify_qr.php" class="menu-btn-nav"><i class="bi bi-qr-code-scan me-3 txt-neon-cyan"></i> Verify QR</a></li>
                <li><a href="feedback_manage.php" class="menu-btn-nav active-nav"><i class="bi bi-chat-left-heart me-3 txt-neon-cyan"></i> Feedback</a></li>
                <li><a href="staff_account.php" class="menu-btn-nav"><i class="bi bi-person-gear me-3 txt-neon-blue"></i> Account</a></li>
                <hr style="border-color: rgba(255, 255, 255, 0.08); margin: 4px 0;">
                <li>
                    <form action="../Login/logout.php" method="post" class="m-0">
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
        // Attach a recent feedback to the Create Report modal and open it
        function attachToReport(feedbackId) {
            const sel = document.getElementById('attachFeedbackSelect');
            if (!sel) return;
            sel.value = feedbackId;
            const modalEl = document.getElementById('createReportModal');
            const modal = new bootstrap.Modal(modalEl);
            modal.show();
        }

        // Populate reply modal hidden fields when opened from a feedback card
        var replyModalEl = document.getElementById('replyModal');
        if (replyModalEl) {
            replyModalEl.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget;
                var feedbackId = button.getAttribute('data-feedback-id');
                var staffId = button.getAttribute('data-staff-id');
                var inputFb = document.getElementById('modalFeedbackId');
                var inputStaff = document.getElementById('modalStaffId');
                if (inputFb && feedbackId) inputFb.value = feedbackId;
                if (inputStaff && staffId) inputStaff.value = staffId;
            });
        }
    </script>
</body>
</html>
