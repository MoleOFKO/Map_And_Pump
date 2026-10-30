<?php
session_start();
require_once __DIR__ . '/../conn.php';

function redirect_to_login(string $message = 'Please sign in to continue.')
{
    $_SESSION['login_error'] = $message;
    header('Location: ../Login/login.php');
    exit;
}

if (empty($_SESSION['is_logged_in']) || empty($_SESSION['user_id'])) {
    redirect_to_login();
}
$roleName = strtolower((string)($_SESSION['role_name'] ?? ''));
if ($roleName !== 'staff') {
    redirect_to_login('This page is only available to staff accounts.');
}

$displayName = '';
$headquarterName = 'Not assigned yet';
$branchName = 'Not assigned yet';
$branchCode = 'N/A';
$headquarterLogo = '';
try {
    if (isset($pdo)) {
        $stmt = $pdo->prepare('SELECT u.user_name, h.company_name AS headquarter_name, h.company_logo AS headquarter_logo, b.branch_name, b.branch_code
            FROM users u
            LEFT JOIN headquarter h ON h.headquarter_id = u.headquarter_id
            LEFT JOIN staff st ON st.user_id = u.user_id
            LEFT JOIN branches b ON b.branch_id = st.branch_id
            WHERE u.user_id = ? LIMIT 1');
        $stmt->execute([(int)$_SESSION['user_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $displayName = trim((string)($row['user_name'] ?? ''));
        $headquarterName = trim((string)($row['headquarter_name'] ?? '')) ?: 'Not assigned yet';
        $headquarterLogo = trim((string)($row['headquarter_logo'] ?? ''));
        $branchName = trim((string)($row['branch_name'] ?? '')) ?: 'Not assigned yet';
        $branchCode = trim((string)($row['branch_code'] ?? '')) ?: 'N/A';
    }
} catch (Throwable $e) {
    // ignore
}

if ($headquarterLogo !== '') {
    $headquarterLogo = str_replace('\\', '/', $headquarterLogo);
    if (!preg_match('#^(https?:)?//#i', $headquarterLogo) && !str_starts_with($headquarterLogo, 'data:') && !str_starts_with($headquarterLogo, '/')) {
        $headquarterLogo = ltrim(preg_replace('#^/?php/HQ/#', '', $headquarterLogo), '/');
        $headquarterLogo = '/php/HQ/' . $headquarterLogo;
    }
}

$staffInitials = 'ST';
$nameParts = preg_split('/\s+/', trim($displayName), -1, PREG_SPLIT_NO_EMPTY);
if ($nameParts) {
    $firstName = $nameParts[0];
    $lastName = count($nameParts) > 1 ? $nameParts[count($nameParts) - 1] : '';
    $firstInitial = function_exists('mb_substr') ? mb_substr($firstName, 0, 1, 'UTF-8') : substr($firstName, 0, 1);
    $lastInitial = $lastName !== '' ? (function_exists('mb_substr') ? mb_substr($lastName, 0, 1, 'UTF-8') : substr($lastName, 0, 1)) : '';
    $staffInitials = strtoupper($firstInitial . $lastInitial);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Dashboard — Map & Pump</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="icon" type="image/png" href="/images/Logo.png">
    <link rel="stylesheet" href="../../css/Admin.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../../css/StaffDashboard.css">
</head>
<body class="background_color staff-dashboard" id="background_color">
    <div class="page-load-bar"></div>
    <div class="staff-shell">
        <aside class="staff-sidebar" aria-label="Staff workspace navigation">
            <a class="staff-brand" href="staff_dashboard.php">
                <img class="staff-brand-logo" src="/images/Logo.png" alt="">
                <span class="staff-brand-copy"><strong>Map &amp; Pump</strong><small>STAFF PORTAL</small></span>
            </a>

            <div class="staff-workspace-card">
                <span class="workspace-icon"><i class="bi bi-buildings"></i></span>
                <span><strong>Staff workspace</strong><small>Map &amp; Pump</small></span>
                <i class="bi bi-shield-check workspace-shield" aria-hidden="true"></i>
            </div>

            <p class="staff-nav-label">Workspace</p>
            <nav class="staff-nav" aria-label="Main navigation">
                <a class="staff-nav-link is-active" href="staff_dashboard.php" aria-current="page"><i class="bi bi-grid"></i><span>Overview</span><span class="nav-indicator"></span></a>
                <a class="staff-nav-link" href="verify_qr.php"><i class="bi bi-upc-scan"></i><span>Verify booking QR</span></a>
                <a class="staff-nav-link" href="feedback_manage.php"><i class="bi bi-chat-square"></i><span>Feedback</span></a>
                <a class="staff-nav-link" href="staff_account.php"><i class="bi bi-person"></i><span>My account</span></a>
            </nav>

            <div class="staff-sidebar-bottom">
                <a class="staff-help-card" href="#quick-guide">
                    <i class="bi bi-question-circle"></i>
                    <strong>A little help?</strong>
                    <span>We've got you covered.</span>
                    <span class="help-link">Staff guide <i class="bi bi-arrow-right"></i></span>
                </a>
                <form action="../Login/logout.php" method="post" class="staff-logout-form">
                    <button type="submit" class="staff-logout"><i class="bi bi-box-arrow-right"></i><span>Sign out</span></button>
                </form>
            </div>
        </aside>

        <main class="staff-main">
            <header class="staff-topbar">
                <div class="staff-breadcrumb"><button class="staff-mobile-menu" type="button" data-bs-toggle="offcanvas" data-bs-target="#mainMenuOffcanvas" aria-label="Open navigation menu"><i class="bi bi-list"></i></button><span>Workspace</span><i class="bi bi-chevron-right"></i><strong>Overview</strong></div>
                <div class="staff-topbar-actions">
                    <span class="staff-access"><i class="bi bi-shield-check"></i> Staff access</span>
                    <label class="staff-theme-control" title="Toggle light mode"><input class="js-theme-toggle" type="checkbox" aria-label="Toggle light mode"><span><i class="bi bi-sun"></i></span></label>
                    <span class="staff-avatar" aria-label="Signed in as <?= htmlspecialchars($displayName ?: 'Staff', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($staffInitials, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </header>

            <div class="staff-content">
                <section class="staff-welcome">
                    <div>
                        <p class="eyebrow"><span></span>Your daily workspace</p>
                        <h1>Welcome back, <?= htmlspecialchars($displayName ?: 'Staff', ENT_QUOTES, 'UTF-8') ?>.</h1>
                        <p class="welcome-subtitle">Everything you need to keep your day running smoothly.</p>
                    </div>
                    <div class="staff-date"><i class="bi bi-clock"></i><span><strong><?= date('l, F j') ?></strong><small>Let's make it a good day.</small></span></div>
                </section>

                <section class="staff-assignment" aria-label="Your work location">
                    <div class="assignment-hq-logo">
                        <?php if ($headquarterLogo !== ''): ?>
                            <img src="<?= htmlspecialchars($headquarterLogo, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($headquarterName, ENT_QUOTES, 'UTF-8') ?> logo">
                        <?php else: ?>
                            <i class="bi bi-buildings" aria-label="Headquarters logo not available"></i>
                        <?php endif; ?>
                    </div>
                    <div class="assignment-detail assignment-headquarters">
                        <span class="assignment-label">Headquarters</span>
                        <strong><i class="bi bi-buildings"></i><?= htmlspecialchars($headquarterName, ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                    <div class="assignment-detail assignment-branch">
                        <span class="assignment-label">Assigned branch</span>
                        <strong><?= htmlspecialchars($branchName, ENT_QUOTES, 'UTF-8') ?><span class="branch-code"><?= htmlspecialchars($branchCode, ENT_QUOTES, 'UTF-8') ?></span></strong>
                    </div>
                    <span class="assignment-status"><i></i> Assigned</span>
                </section>

                <section class="staff-tools" aria-labelledby="staff-tools-title">
                    <div class="section-heading">
                        <div><h2 id="staff-tools-title">Let's get to work</h2><p>Choose an action to manage your daily operations.</p></div>
                        <span class="section-kicker">Staff tools</span>
                    </div>
                    <div class="staff-tool-grid">
                        <article class="staff-tool-card verify-card">
                            <div class="tool-card-top"><span class="tool-icon"><i class="bi bi-upc-scan"></i></span><span class="tool-category">Booking management</span><span class="qr-decoration" aria-hidden="true"><i class="bi bi-qr-code"></i></span></div>
                            <div class="tool-card-body"><h3>Verify booking QR</h3><p>A quick scan. A seamless check-in.<br>Validate client bookings with a QR code or ticket ID.</p></div>
                            <div class="tool-card-actions"><a class="tool-button tool-button-primary" href="verify_qr.php">Start verification <i class="bi bi-arrow-right"></i></a><span class="tool-note"><i class="bi bi-shield-check"></i> Quick &amp; secure</span></div>
                        </article>
                        <article class="staff-tool-card feedback-card">
                            <div class="tool-card-top"><span class="tool-icon"><i class="bi bi-chat-square"></i></span><span class="tool-category">Customer experience</span><span class="feedback-decoration" aria-hidden="true"><i class="bi bi-chat"></i><span><i class="bi bi-check"></i></span></span></div>
                            <div class="tool-card-body"><h3>Every voice matters</h3><p>Great service starts with listening.<br>View, evaluate, and reply to customer feedback.</p></div>
                            <div class="tool-card-actions"><a class="tool-button" href="feedback_manage.php">Manage feedback <i class="bi bi-arrow-right"></i></a><span class="tool-note">Listen. Improve. Repeat.</span></div>
                        </article>
                    </div>
                </section>

                <section class="quick-guide" id="quick-guide" aria-labelledby="quick-guide-title">
                    <div class="guide-heading"><i class="bi bi-clipboard-check"></i><div><h2 id="quick-guide-title">A smooth check-in, in three steps</h2><p>A small reminder for a better customer experience.</p></div><span>Quick guide</span></div>
                    <ol class="guide-steps">
                        <li><span class="step-number">01</span><div><strong>Ask for the booking</strong><small>Request the client's QR code or ticket ID.</small></div><i class="bi bi-chevron-right step-arrow"></i></li>
                        <li><span class="step-number">02</span><div><strong>Scan &amp; verify</strong><small>Check the booking details and validity.</small></div><i class="bi bi-chevron-right step-arrow"></i></li>
                        <li><span class="step-number">03</span><div><strong>Welcome them in</strong><small>Confirm the booking and assist the client.</small></div></li>
                    </ol>
                </section>

                <p class="staff-workspace-note"><i class="bi bi-shield-check"></i> You're in the staff workspace. Your tools are tailored to your assigned branch.</p>
                <footer class="staff-footer">&copy; <?= date('Y') ?> Map &amp; Pump. All rights reserved.</footer>
            </div>
        </main>
    </div>

    <div class="offcanvas offcanvas-start staff-mobile-drawer" tabindex="-1" id="mainMenuOffcanvas" aria-label="Staff navigation">
        <div class="offcanvas-header"><a class="staff-brand" href="staff_dashboard.php"><img class="staff-brand-logo" src="/images/Logo.png" alt=""><span class="staff-brand-copy"><strong>Map &amp; Pump</strong><small>STAFF PORTAL</small></span></a><button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close menu"></button></div>
        <div class="offcanvas-body">
            <div class="staff-workspace-card"><span class="workspace-icon"><i class="bi bi-buildings"></i></span><span><strong>Staff workspace</strong><small>Map &amp; Pump</small></span><i class="bi bi-shield-check workspace-shield"></i></div>
            <p class="staff-nav-label">Workspace</p>
            <nav class="staff-nav" aria-label="Mobile main navigation">
                <a class="staff-nav-link is-active" href="staff_dashboard.php" aria-current="page"><i class="bi bi-grid"></i><span>Overview</span><span class="nav-indicator"></span></a>
                <a class="staff-nav-link" href="verify_qr.php"><i class="bi bi-upc-scan"></i><span>Verify booking QR</span></a>
                <a class="staff-nav-link" href="feedback_manage.php"><i class="bi bi-chat-square"></i><span>Feedback</span></a>
                <a class="staff-nav-link" href="staff_account.php"><i class="bi bi-person"></i><span>My account</span></a>
            </nav>
            <form action="../Login/logout.php" method="post" class="staff-logout-form"><button type="submit" class="staff-logout"><i class="bi bi-box-arrow-right"></i><span>Sign out</span></button></form>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/js/Admin.js"></script>
<?php require_once __DIR__ . '/../moderator_hire_popup.php'; ?>
</body>
</html>