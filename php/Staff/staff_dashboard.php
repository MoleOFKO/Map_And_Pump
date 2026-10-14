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
try {
    if (isset($pdo)) {
        $stmt = $pdo->prepare('SELECT u.user_name, h.company_name AS headquarter_name, b.branch_name, b.branch_code
            FROM users u
            LEFT JOIN headquarter h ON h.headquarter_id = u.headquarter_id
            LEFT JOIN branches b ON b.branch_id = u.branch_id
            WHERE u.user_id = ? LIMIT 1');
        $stmt->execute([(int)$_SESSION['user_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $displayName = trim((string)($row['user_name'] ?? ''));
        $headquarterName = trim((string)($row['headquarter_name'] ?? '')) ?: 'Not assigned yet';
        $branchName = trim((string)($row['branch_name'] ?? '')) ?: 'Not assigned yet';
        $branchCode = trim((string)($row['branch_code'] ?? '')) ?: 'N/A';
    }
} catch (Throwable $e) {
    // ignore
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
    <style>
        .icon-box { font-size: calc(2.5rem + 1vw); color: var(--icon-color); margin-bottom: 15px; }
        .dashboard-card { cursor: pointer; }
        .assignment-banner {
            background: linear-gradient(135deg, rgba(15, 23, 42, 0.9), rgba(30, 41, 59, 0.82));
            border: 1px solid rgba(88, 166, 255, 0.18);
            border-radius: 14px;
            padding: 0.7rem 0.8rem;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.12);
        }
        .assignment-badge {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(0, 244, 255, 0.08);
            border: 1px solid rgba(0, 244, 255, 0.2);
            color: var(--neon-cyan);
            font-size: 0.9rem;
            flex-shrink: 0;
        }
        .assignment-pill {
            background: rgba(15, 118, 110, 0.08);
            border: 1px solid rgba(34, 197, 94, 0.14);
            border-radius: 10px;
            padding: 0.5rem 0.7rem;
            min-width: 140px;
        }
        .assignment-label {
            display: block;
            color: rgba(255, 255, 255, 0.62);
            font-size: 0.62rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-bottom: 0.18rem;
        }
        .assignment-value {
            color: #fff;
            font-weight: 700;
            font-size: 0.8rem;
            line-height: 1.3;
        }
        .assignment-meta {
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.66rem;
            margin-top: 0.06rem;
        }
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
    <div class="container my-5">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <span class="stat-chip mb-2">
                    <i class="bi bi-person-workspace txt-neon-cyan"></i>
                    Staff Dashboard
                </span>
                <h1 class="fw-bold m-0 h4 text-white mt-2">Welcome Back, <?= htmlspecialchars($displayName ?: 'Staff') ?></h1>
                <p class="text-white-50 m-0 small mt-1">Select an action below to manage active operations.</p>
            </div>
        </div>

        <div class="assignment-banner mb-4">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <div class="assignment-badge">
                    <i class="bi bi-geo-alt-fill"></i>
                </div>
                <div class="flex-grow-1">
                    <div class="small text-white-50 text-uppercase mb-1" style="letter-spacing:0.08em; font-size:0.62rem;">Work location</div>
                    <div class="d-flex flex-wrap gap-2">
                        <div class="assignment-pill">
                            <span class="assignment-label">HQ</span>
                            <div class="assignment-value"><?= htmlspecialchars($headquarterName) ?></div>
                        </div>
                        <div class="assignment-pill">
                            <span class="assignment-label">Branch</span>
                            <div class="assignment-value"><?= htmlspecialchars($branchName) ?></div>
                            <div class="assignment-meta">Code: <?= htmlspecialchars($branchCode) ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 justify-content-center">
            <!-- Verify Booking QR -->
            <div class="col-12 col-md-6 col-lg-5">
                <a href="verify_qr.php" class="text-decoration-none h-100 d-block">
                    <div class="dashboard-card text-center h-100 d-flex flex-column align-items-center justify-content-center">
                        <div class="icon-box"><i class="bi bi-qr-code-scan"></i></div>
                        <h3 class="text-white fw-semibold">Verify Booking QR</h3>
                        <p class="text-white-50 mt-2 mb-0">Scan or manually enter client ticket QR codes to check validity.</p>
                    </div>
                </a>
            </div>

            <!-- Feedback -->
            <div class="col-12 col-md-6 col-lg-5">
                <a href="feedback_manage.php" class="text-decoration-none h-100 d-block">
                    <div class="dashboard-card text-center h-100 d-flex flex-column align-items-center justify-content-center">
                        <div class="icon-box"><i class="bi bi-chat-left-heart"></i></div>
                        <h3 class="text-white fw-semibold">Feedback</h3>
                        <p class="text-white-50 mt-2 mb-0">View, evaluate, and reply to submissions received from users.</p>
                    </div>
                </a>
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
                <li><a href="staff_dashboard.php" class="menu-btn-nav active-nav"><i class="bi bi-house me-3 txt-neon-cyan"></i> Dashboard</a></li>
                <li><a href="verify_qr.php" class="menu-btn-nav"><i class="bi bi-qr-code-scan me-3 txt-neon-cyan"></i> Verify QR</a></li>
                <li><a href="feedback_manage.php" class="menu-btn-nav"><i class="bi bi-chat-left-heart me-3 txt-neon-cyan"></i> Feedback</a></li>
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
    </script>
<?php require_once __DIR__ . '/../moderator_hire_popup.php'; ?>
</body>
</html>