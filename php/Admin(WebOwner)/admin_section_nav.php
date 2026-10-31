<?php
/**
 * Shared Admin portal shell: page-load bar, sticky top nav, and main-menu offcanvas.
 * Set $adminSection before include so the active menu item is highlighted.
 * Valid keys: home | view_hq | view_users | requests | report | loyalty | profile | manage_moderators | logs
 */
$adminSection = (string) ($adminSection ?? '');
$adminMenuClass = static fn (string $key): string => $adminSection === $key ? 'menu-btn-nav active-nav' : 'menu-btn-nav';
?>
<div class="page-load-bar"></div>
<div class="container sticky-nav-wrapper">
    <nav class="navbar navbar-dark text-white navigation_style">
        <div class="container-fluid d-flex justify-content-between align-items-center p-0 gap-3">
            <a class="navbar-brand d-flex align-items-center gap-2 fw-semibold txt-neon-cyan m-0" href="admin_dashboard.php" style="font-size:.95rem;">
                <img src="/images/Logo.png" alt="Logo" class="nav-logo">
                <span class="d-none d-sm-inline">Admin Portal</span>
            </a>

            <div class="admin-nav-actions">
                <?php if (function_exists('expiry_render_nav_bell')) { expiry_render_nav_bell('admin'); } ?>
                <label class="cosmic-toggle nav-theme-toggle" title="Toggle light mode">
                    <input class="toggle js-theme-toggle" type="checkbox" aria-label="Toggle light mode" />
                    <div class="slider">
                        <span class="theme-icon theme-icon-dark"><i class="bi bi-moon-stars-fill"></i></span>
                        <span class="theme-icon theme-icon-light"><i class="bi bi-sun-fill"></i></span>
                        <div class="cosmos"></div>
                        <div class="energy-line"></div>
                        <div class="energy-line"></div>
                        <div class="energy-line"></div>
                        <div class="toggle-orb"><div class="inner-orb"></div><div class="ring"></div></div>
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

<!-- ADMIN SIDEBAR MAIN MENU (offcanvas) -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="mainMenuOffcanvas" style="width: 320px; background: rgba(6, 12, 24, 0.98); backdrop-filter: blur(28px); border-left: 1px solid rgba(0, 180, 255, 0.18);">
    <div class="offcanvas-header pb-2 border-bottom border-white border-opacity-10">
        <div>
            <h5 class="offcanvas-title text-white fw-bold mb-0">Main Menu</h5>

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
                <div class="toggle-orb"><div class="inner-orb"></div><div class="ring"></div></div>
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
            <li><a href="admin_dashboard.php" class="<?= $adminMenuClass('home') ?>"><i class="bi bi-house me-3 txt-neon-cyan"></i> Home</a></li>
            <li><a href="view_hq_and_user_account.php" class="<?= $adminMenuClass('view_hq') ?>"><i class="bi bi-building-gear me-3 txt-neon-cyan"></i> View/Manage HQ</a></li>
            <li><a href="view_users.php" class="<?= $adminMenuClass('view_users') ?>"><i class="bi bi-people me-3 txt-neon-cyan"></i> View/Manage Users</a></li>
            <li><a href="view_requests.php" class="<?= $adminMenuClass('requests') ?>"><i class="bi bi-file-earmark-check me-3 txt-neon-cyan"></i> View Requests</a></li>
            <li><a href="view_report.php" class="<?= $adminMenuClass('report') ?>"><i class="bi bi-graph-up-arrow me-3 txt-neon-cyan"></i> View Report</a></li>
            <li><a href="loyalty_management.php" class="<?= $adminMenuClass('loyalty') ?>"><i class="bi bi-stars me-3 txt-neon-cyan"></i> Loyalty &amp; Billing</a></li>
            <li><a href="edit_profile.php" class="<?= $adminMenuClass('profile') ?>"><i class="bi bi-person-bounding-box me-3 txt-neon-blue"></i> Edit Profile</a></li>
            <li><a href="manage_moderators.php" class="<?= $adminMenuClass('manage_moderators') ?>"><i class="bi bi-people-fill me-3 txt-neon-blue"></i> Manage Moderators</a></li>
            <li><a href="view_logs.php" class="<?= $adminMenuClass('logs') ?>"><i class="bi bi-terminal-split me-3 text-danger"></i> View Log Files</a></li>
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
