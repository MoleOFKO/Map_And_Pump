<?php
session_start();
require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../feedback_schema.php';
require_once __DIR__ . '/../usage_history_schema.php';
require_once __DIR__ . '/staff_access_guard.php';

map_pump_require_active_staff_session($pdo, false);

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

if (empty($_SESSION['staff_csrf_token'])) {
    $_SESSION['staff_csrf_token'] = bin2hex(random_bytes(32));
}
$staffCsrfToken = (string)$_SESSION['staff_csrf_token'];

function staff_feedback_comment_only(?string $feedback): string
{
    $feedback = trim((string)$feedback);
    if (preg_match('/^\[Usage #[^\r\n]*\]\r?\n(.*)$/s', $feedback, $matches)) {
        return trim($matches[1]);
    }
    return $feedback;
}

// Load the signed-in staff member and their assigned station.
$displayName = '';
$staffStationId = 0;
$loggedStaffId = 0;
try {
    if (isset($pdo)) {
        $staffStmt = $pdo->prepare(
            'SELECT staff_id, branch_id, full_name
             FROM staff
             WHERE user_id = :user_id AND is_active = 1
             LIMIT 1'
        );
        $staffStmt->execute([':user_id' => (int)$_SESSION['user_id']]);
        $staffRow = $staffStmt->fetch(PDO::FETCH_ASSOC);

        if ($staffRow) {
            $loggedStaffId = (int)($staffRow['staff_id'] ?? 0);
            $staffStationId = (int)($staffRow['branch_id'] ?? 0);
            $displayName = trim((string)($staffRow['full_name'] ?? ''));
        }

        if ($staffStationId <= 0) {
            $scopeStmt = $pdo->prepare(
                'SELECT station_id
                 FROM station_business_scope
                 WHERE branch_manager_user_id = :manager_user_id
                 ORDER BY station_id ASC
                 LIMIT 1'
            );
            $scopeStmt->execute([':manager_user_id' => (int)$_SESSION['user_id']]);
            $staffStationId = (int)($scopeStmt->fetchColumn() ?: 0);
        }

        if ($displayName === '') {
            $userStmt = $pdo->prepare('SELECT user_name FROM users WHERE user_id = :user_id LIMIT 1');
            $userStmt->execute([':user_id' => (int)$_SESSION['user_id']]);
            $displayName = trim((string)($userStmt->fetchColumn() ?: ''));
        }
    }
} catch (Throwable $e) {
    error_log('Failed to resolve staff branch context: ' . $e->getMessage());
}

map_pump_feedback_schema_ready($pdo);
map_pump_usage_history_schema_ready($pdo);

// Inbox tabs are server-side so unread/read state also works without JavaScript.
$activeTab = (string)($_GET['tab'] ?? 'all');
if (!in_array($activeTab, ['all', 'read', 'unread', 'replies'], true)) {
    $activeTab = 'all';
}
$recentFeedback = [];
$staffReplies = [];
$feedbackCounts = ['all' => 0, 'read' => 0, 'unread' => 0, 'replies' => 0];
try {
    $countStmt = $pdo->prepare(
        'SELECT COUNT(*) AS all_count,
                SUM(CASE WHEN f.staff_read_at IS NOT NULL THEN 1 ELSE 0 END) AS read_count,
                SUM(CASE WHEN f.staff_read_at IS NULL THEN 1 ELSE 0 END) AS unread_count
         FROM feedback f
         WHERE f.station_id = :station_id'
    );
    $countStmt->execute([':station_id' => $staffStationId]);
    $counts = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $feedbackCounts['all'] = (int)($counts['all_count'] ?? 0);
    $feedbackCounts['read'] = (int)($counts['read_count'] ?? 0);
    $feedbackCounts['unread'] = (int)($counts['unread_count'] ?? 0);

    $replyCountStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM feedback_replies fr
         INNER JOIN feedback f ON f.feedback_id = fr.feedback_id
         WHERE fr.staff_id = :staff_id
           AND f.station_id = :station_id'
    );
    $replyCountStmt->execute([':staff_id' => $loggedStaffId, ':station_id' => $staffStationId]);
    $feedbackCounts['replies'] = (int)$replyCountStmt->fetchColumn();

    if ($activeTab === 'replies') {
        $replyStmt = $pdo->prepare(
            'SELECT fr.reply_id, fr.reply_text, fr.created_at, fr.feedback_id,
                    f.feedback, f.user_id, u.user_name AS customer_name,
                    st.full_name AS staff_name
             FROM feedback_replies fr
             INNER JOIN feedback f ON f.feedback_id = fr.feedback_id
             LEFT JOIN users u ON u.user_id = f.user_id
             LEFT JOIN staff st ON st.staff_id = fr.staff_id
             WHERE fr.staff_id = :staff_id
               AND f.station_id = :station_id
             ORDER BY fr.created_at DESC, fr.reply_id DESC'
        );
        $replyStmt->execute([':staff_id' => $loggedStaffId, ':station_id' => $staffStationId]);
        $staffReplies = $replyStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stateFilter = $activeTab === 'read' ? ' AND f.staff_read_at IS NOT NULL' : ($activeTab === 'unread' ? ' AND f.staff_read_at IS NULL' : '');
        $stmt = $pdo->prepare(
            'SELECT f.feedback_id, f.user_id, u.user_name AS customer_name, f.feedback, f.created_at, f.station_id, f.rating, f.staff_read_at,
                    COALESCE(ltp.tier_code, "bronze") AS tier_code,
                    (SELECT COUNT(*) FROM feedback_replies fr WHERE fr.feedback_id = f.feedback_id) AS reply_count
             FROM feedback f
             LEFT JOIN users u ON u.user_id = f.user_id
             LEFT JOIN loyalty_user_tier_progress ltp ON ltp.user_id = f.user_id
             WHERE f.station_id = :station_id' . $stateFilter . '
             ORDER BY f.created_at DESC, f.feedback_id DESC'
        );
        $stmt->execute([':station_id' => $staffStationId]);
        $recentFeedback = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    error_log('Failed to load staff feedback: ' . $e->getMessage());
    $recentFeedback = [];
    $staffReplies = [];
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
    <link rel="stylesheet" href="../../css/StaffDashboard.css">
    <style>
        .badge-resolved { background-color: rgba(27, 228, 148, 0.15); color: #1be494; border: 1px solid rgba(27, 228, 148, 0.25); }
        .badge-pending { background-color: rgba(255, 193, 7, 0.15); color: #ffc107; border: 1px solid rgba(255, 193, 7, 0.25); }
        .feedback-tabs { display:flex; gap:8px; flex-wrap:wrap; border-bottom:1px solid rgba(255,255,255,.12); margin:28px 0 20px; padding-bottom:12px; }
        .feedback-tab { color:rgba(255,255,255,.66); text-decoration:none; border:1px solid rgba(255,255,255,.14); border-radius:7px; padding:8px 12px; font-size:.85rem; font-weight:700; }
        .feedback-tab:hover { color:#fff; border-color:rgba(0,200,255,.55); }
        .feedback-tab:focus-visible { outline:2px solid #55d8ff; outline-offset:2px; }
        .feedback-tab.active { color:#06111d; background:#55d8ff; border-color:#55d8ff; }
        .feedback-tab .count { display:inline-flex; min-width:20px; justify-content:center; margin-left:5px; padding:0 5px; border-radius:999px; background:rgba(0,0,0,.16); font-size:.72rem; }
        body.background_color.light-mode .feedback-tabs { border-bottom-color:rgba(15,23,42,.18); }
        body.background_color.light-mode .feedback-tab { color:#334155; border-color:rgba(15,23,42,.2); }
        body.background_color.light-mode .feedback-tab:hover { color:#084298; border-color:rgba(11,94,215,.55); background:rgba(11,94,215,.06); }
        body.background_color.light-mode .feedback-tab.active,
        body.background_color.light-mode .feedback-tab.active:hover { color:#06111d; background:#55d8ff; border-color:#55d8ff; }
        body.background_color.light-mode .feedback-tab:focus-visible { outline-color:#084298; }
        body.background_color.light-mode #createReportModal .modal-footer .btn-outline-light { color:#334155; border-color:rgba(15,23,42,.34); }
        body.background_color.light-mode #createReportModal .modal-footer .btn-outline-light:hover,
        body.background_color.light-mode #createReportModal .modal-footer .btn-outline-light:focus-visible { color:#084298; background:rgba(11,94,215,.08); border-color:#0b5ed7; }
        #createReportModal .report-modal-content {
            overflow: hidden;
            border: 1px solid #1e293b;
            border-radius: 18px;
            background: #131b2e;
            color: #f8fafc;
            box-shadow: 0 24px 64px rgba(0,0,0,.4);
        }
        #createReportModal .report-modal-dialog { max-width: min(660px, calc(100% - 1.5rem)); }
        #createReportModal .report-modal-header,
        #createReportModal .report-modal-footer {
            padding: 1.25rem 1.5rem;
            border-color: #263247;
        }
        #createReportModal .report-modal-title { margin: 0; color: #f8fafc; font-size: 1.1rem; font-weight: 700; }
        #createReportModal .report-modal-body { padding: 1.5rem; }
        #createReportModal .report-form-fields { display: grid; gap: 1.15rem; }
        #createReportModal .report-form-field { min-width: 0; }
        #createReportModal .report-modal-label {
            display: block;
            margin-bottom: .5rem;
            color: #94a3b8;
            font-size: .875rem;
            font-weight: 600;
        }
        #createReportModal .report-modal-control {
            display: block;
            width: 100%;
            min-height: 46px;
            padding: .7rem .9rem;
            border: 1px solid #334155;
            border-radius: .7rem;
            background-color: #0f172a;
            color: #f8fafc;
            box-shadow: none;
        }
        #createReportModal textarea.report-modal-control { min-height: 132px; resize: vertical; }
        #createReportModal select.report-modal-control {
            padding-right: 2.5rem;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%2394a3b8' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e");
        }
        #createReportModal .report-modal-control::placeholder { color: #64748b; opacity: 1; }
        #createReportModal .report-modal-control:focus {
            border-color: #4386f5;
            box-shadow: 0 0 0 .2rem rgba(67,134,245,.18);
        }
        #createReportModal select.report-modal-control option { background: #0f172a; color: #f8fafc; }
        #createReportModal .report-modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: .75rem;
            border-top: 1px solid #263247;
        }
        body.background_color.light-mode #createReportModal .report-modal-content,
        body.background_color[data-theme="light"] #createReportModal .report-modal-content {
            border-color: #f1f5f9;
            background: #fff;
            color: #0f172a;
            box-shadow: 0 24px 64px rgba(15,23,42,.18);
        }
        body.background_color.light-mode #createReportModal .report-modal-header,
        body.background_color[data-theme="light"] #createReportModal .report-modal-header,
        body.background_color.light-mode #createReportModal .report-modal-footer,
        body.background_color[data-theme="light"] #createReportModal .report-modal-footer {
            border-color: #e2e8f0;
        }
        body.background_color.light-mode #createReportModal .report-modal-title,
        body.background_color[data-theme="light"] #createReportModal .report-modal-title { color: #0f172a; }
        body.background_color.light-mode #createReportModal .report-modal-label,
        body.background_color[data-theme="light"] #createReportModal .report-modal-label { color: #475569; }
        body.background_color.light-mode #createReportModal .report-modal-control,
        body.background_color[data-theme="light"] #createReportModal .report-modal-control {
            border-color: #e2e8f0;
            background-color: #f8fafc;
            color: #0f172a;
        }
        body.background_color.light-mode #createReportModal .report-modal-control::placeholder,
        body.background_color[data-theme="light"] #createReportModal .report-modal-control::placeholder { color: #64748b; }
        body.background_color.light-mode #createReportModal select.report-modal-control,
        body.background_color[data-theme="light"] #createReportModal select.report-modal-control {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23475569' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e");
        }
        body.background_color.light-mode #createReportModal select.report-modal-control option,
        body.background_color[data-theme="light"] #createReportModal select.report-modal-control option {
            background: #f8fafc;
            color: #0f172a;
        }
        body.background_color.light-mode #createReportModal .btn-close,
        body.background_color[data-theme="light"] #createReportModal .btn-close { filter: none; }
        @media (max-width: 575.98px) {
            #createReportModal .report-modal-header,
            #createReportModal .report-modal-body,
            #createReportModal .report-modal-footer { padding: 1rem; }
            #createReportModal .report-modal-footer { flex-direction: column-reverse; }
            #createReportModal .report-modal-footer .btn { width: 100%; }
        }
        .feedback-card { border-left:4px solid rgba(255,255,255,.2); }
        .feedback-card.is-unread { border-left-color:#ffc107; box-shadow:0 0 0 1px rgba(255,193,7,.16); }
        .feedback-status { display:inline-flex; align-items:center; gap:6px; font-size:.76rem; font-weight:800; text-transform:uppercase; letter-spacing:.04em; }
        .feedback-status.unread { color:#ffc107; }
        .feedback-status.read { color:#61e6b0; }
        .feedback-status .dot { width:8px; height:8px; border-radius:50%; background:currentColor; box-shadow:0 0 10px currentColor; }
        .feedback-meta { display:flex; flex-wrap:wrap; gap:7px 14px; color:rgba(255,255,255,.6); font-size:.8rem; }
        .feedback-meta strong { color:#fff; }
        .reply-preview { margin-top:12px; padding:10px 12px; border-radius:7px; background:rgba(27,228,148,.08); border:1px solid rgba(27,228,148,.15); color:#c9d2e3; font-size:.84rem; }
    </style>
</head>
<body class="background_color staff-dashboard" id="background_color">

    <!-- TOP LOADING SHIMMER BAR -->
    <div class="page-load-bar"></div>
    <?php $staffPageTitle = 'Feedback'; $staffActivePage = 'feedback'; require __DIR__ . '/staff_layout_start.php'; ?>

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
    <div class="container staff-page-content text_color">
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
                        <span class="stat-chip" style="color:#ffc107; border-color: rgba(255,193,7,0.3); background: rgba(255,193,7,0.05);"><i class="bi bi-envelope-exclamation me-1"></i> Unread: <?= $feedbackCounts['unread'] ?></span>
                    </div>
                </div>
            </div>
        </div>
        <?php if ($showSuccess): ?>
            <div class="alert alert-success border-0">Report was created and sent to Branch Manager.</div>
        <?php endif; ?>
        <?php if ($showReplySuccess): ?>
            <div class="alert alert-success border-0">Reply sent. The customer can now see it in Feedback &amp; Replies.</div>
        <?php endif; ?>
        <?php if (isset($_GET['reply_error'])): ?>
            <div class="alert alert-warning border-0">The feedback could not be updated. It may belong to another station.</div>
        <?php endif; ?>

        <nav class="feedback-tabs" aria-label="Feedback inbox filters">
            <?php foreach (['all' => 'All', 'read' => 'Read', 'unread' => 'Unread', 'replies' => 'My replies'] as $tabKey => $tabLabel): ?>
                <a class="feedback-tab <?= $activeTab === $tabKey ? 'active' : '' ?>" href="feedback_manage.php?tab=<?= $tabKey ?>" <?= $activeTab === $tabKey ? 'aria-current="page"' : '' ?>>
                    <?= $tabLabel ?><span class="count"><?= $feedbackCounts[$tabKey] ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="row g-4 px-2 feedback-results" aria-live="polite" aria-busy="false">
            <?php if ($activeTab === 'replies'): ?>
                <?php if (empty($staffReplies)): ?>
                    <div class="col-12"><div class="dashboard-card text-center text-white-50 py-5">You have not sent any replies yet.</div></div>
                <?php else: foreach ($staffReplies as $reply): ?>
                    <?php $originalFeedback = staff_feedback_comment_only($reply['feedback'] ?? null); ?>
                    <div class="col-12"><article class="dashboard-card feedback-card">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                            <div>
                                <h5 class="mb-1 text-white fw-semibold">Your reply</h5>
                                <div class="feedback-meta"><span>Customer UID <strong>#<?= (int)$reply['user_id'] ?></strong></span><span>Type: Staff reply</span><span><?= htmlspecialchars((new DateTime($reply['created_at']))->format('M j, Y \\a\\t H:i')) ?></span></div>
                            </div>
                            <span class="feedback-status read"><span class="dot"></span> Sent</span>
                        </div>
                        <p class="text-white mb-2 small"><?= nl2br(htmlspecialchars($reply['reply_text'])) ?></p>
                        <div class="reply-preview"><strong>Original customer feedback:</strong> <?= nl2br(htmlspecialchars($originalFeedback, ENT_QUOTES, 'UTF-8')) ?></div>
                    </article></div>
                <?php endforeach; endif; ?>
            <?php elseif (empty($recentFeedback)): ?>
                <div class="col-12"><div class="dashboard-card text-center text-white-50 py-5">No <?= htmlspecialchars($activeTab) ?> feedback is available.</div></div>
            <?php else: ?>
                <?php foreach ($recentFeedback as $fb): ?>
                    <?php
                        $isUsageFeedback = !empty($fb['usage_id']);
                        $feedbackTitle = $isUsageFeedback ? 'Fuel fill feedback' : 'General feedback';
                        $isUnread = empty($fb['staff_read_at']);
                        $feedbackComment = staff_feedback_comment_only($fb['feedback'] ?? null);
                    ?>
                    <div class="col-12">
                        <article class="dashboard-card feedback-card <?= $isUnread ? 'is-unread' : '' ?>">
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                                <div>
                                    <h5 class="mb-1 text-white fw-semibold"><?= htmlspecialchars($feedbackTitle) ?></h5>
                                    <div class="feedback-meta"><span>Customer UID <strong>#<?= (int)$fb['user_id'] ?></strong></span><span><?= htmlspecialchars((new DateTime($fb['created_at']))->format('M j, Y \\a\\t H:i')) ?></span></div>
                                </div>
                                <div class="d-flex align-items-center gap-2"><span class="feedback-status <?= $isUnread ? 'unread' : 'read' ?>"><span class="dot"></span><?= $isUnread ? 'Unread' : 'Read' ?></span><span class="badge <?= $isUnread ? 'badge-pending' : 'badge-resolved' ?> px-3 py-2 rounded-pill small fw-medium"><?= !empty($fb['rating']) ? ((int)$fb['rating'] . '/5') : 'Feedback' ?></span></div>
                            </div>
                            <p class="text-white-70 mb-4 small" style="opacity: 0.85;"><?= nl2br(htmlspecialchars(substr($feedbackComment, 0, 240), ENT_QUOTES, 'UTF-8')) ?><?= strlen($feedbackComment) > 240 ? '…' : '' ?></p>
                            <?php if ((int)$fb['reply_count'] > 0): ?><div class="reply-preview"><i class="bi bi-reply-fill me-1"></i><?= (int)$fb['reply_count'] ?> staff repl<?= (int)$fb['reply_count'] === 1 ? 'y' : 'ies' ?> recorded for this feedback.</div><?php endif; ?>
                            <div class="d-flex justify-content-end gap-2 flex-wrap">
                                <?php if ($isUnread): ?><form action="feedback_process.php" method="POST"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($staffCsrfToken, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="mark_read"><input type="hidden" name="feedback_id" value="<?= (int)$fb['feedback_id'] ?>"><input type="hidden" name="return_tab" value="<?= htmlspecialchars($activeTab) ?>"><button class="btn btn-outline-light px-4" type="submit"><i class="bi bi-check2 me-1"></i> Mark read</button></form><?php endif; ?>
                                <button class="btn menu-pill-btn px-4" data-bs-toggle="modal" data-bs-target="#replyModal" data-feedback-id="<?= (int)$fb['feedback_id'] ?>"><i class="bi bi-reply-fill me-1"></i> Reply</button>
                                <button class="btn btn-outline-light px-4" data-feedback-id="<?= (int)$fb['feedback_id'] ?>" onclick="attachToReport(<?= (int)$fb['feedback_id'] ?>)"><i class="bi bi-pin-angle me-1"></i> Attach to Report</button>
                            </div>
                        </article>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- SIDEBAR MAIN MENU -->
    <div class="offcanvas offcanvas-end legacy-staff-menu" tabindex="-1" id="legacyMainMenuOffcanvas" style="width: 320px; background: rgba(6, 12, 24, 0.98); backdrop-filter: blur(28px); border-left: 1px solid rgba(0, 180, 255, 0.18);">
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

    <?php require __DIR__ . '/staff_layout_end.php'; ?>
    <div class="modal fade" id="replyModal" tabindex="-1" aria-labelledby="replyModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered px-3">
            <div class="modal-content text_color" style="background: var(--glass-bg); backdrop-filter: blur(28px); border: 1px solid var(--glass-border); border-radius: var(--card-radius);">
                <div class="modal-header border-bottom border-secondary border-opacity-25">
                    <h5 class="modal-title text-white fw-bold" id="replyModalLabel">Draft Response to User</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="feedback_process.php" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($staffCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="reply">
                        <input type="hidden" id="modalFeedbackId" name="feedback_id" value="">
                        <input type="hidden" name="return_tab" value="<?= htmlspecialchars($activeTab) ?>">
                        <div class="mb-3">
                            <label for="responseTextarea" class="form-label text-white-50 small">Message Body</label>
                            <textarea class="form-control glass-input" id="responseTextarea" name="response_msg" rows="5" placeholder="Type your official reply or internal notes here..." required></textarea>
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
    <div class="modal fade" id="createReportModal" tabindex="-1" aria-labelledby="createReportModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered report-modal-dialog">
            <div class="modal-content report-modal-content">
                <div class="modal-header report-modal-header">
                    <h5 class="modal-title report-modal-title" id="createReportModalLabel">Create Report for Branch Manager</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="report_action.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($staffCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="modal-body report-modal-body">
                        <input type="hidden" name="staff_id" value="<?= (int)($_SESSION['user_id'] ?? 0) ?>">
                        <div class="report-form-fields">
                            <div class="report-form-field">
                                <label for="reportImportance" class="report-modal-label">Importance</label>
                                <select id="reportImportance" name="importance" class="form-select report-modal-control" required>
                                    <option value="low">Low</option>
                                    <option value="normal" selected>Normal</option>
                                    <option value="high">High</option>
                                </select>
                            </div>
                            <div class="report-form-field">
                                <label for="attachFeedbackSelect" class="report-modal-label">Attach Recent Feedback (optional)</label>
                                <select id="attachFeedbackSelect" name="attach_feedback_id" class="form-select report-modal-control">
                                    <option value="">-- None --</option>
                                    <?php foreach ($recentFeedback as $fbOpt): ?>
                                        <?php $feedbackOptionText = staff_feedback_comment_only($fbOpt['feedback'] ?? null); ?>
                                        <option value="<?= (int)$fbOpt['feedback_id'] ?>"><?= htmlspecialchars(substr($feedbackOptionText, 0, 80), ENT_QUOTES, 'UTF-8') ?><?= strlen($feedbackOptionText) > 80 ? '…' : '' ?> — Customer UID #<?= (int)$fbOpt['user_id'] ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="report-form-field">
                                <label for="reportShortSummary" class="report-modal-label">Reason / Short Summary</label>
                                <input id="reportShortSummary" type="text" name="short_summary" class="form-control report-modal-control" maxlength="255" placeholder="Short title for the report" required>
                            </div>
                            <div class="report-form-field">
                                <label for="reportWhy" class="report-modal-label">Why it happened (optional)</label>
                                <textarea id="reportWhy" name="why_happened" class="form-control report-modal-control" rows="3" placeholder="Optional: analysis or suspected cause"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer report-modal-footer">
                        <button type="button" class="btn btn-outline-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="submit_staff_report" class="btn btn-primary rounded-pill px-4" style="background: var(--neon-cyan); border:none; color:#000; font-weight:600;"><i class="bi bi-send-fill me-2"></i>Send to Branch Manager</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
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
                var inputFb = document.getElementById('modalFeedbackId');
                if (inputFb && feedbackId) inputFb.value = feedbackId;
            });
        }
    </script>
</body>
</html>
