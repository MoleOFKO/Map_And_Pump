<?php
/** @var string $moderatorActiveNav home|hq|requests|report|profile|logs */
$moderatorActiveNav = $moderatorActiveNav ?? '';
$modMenuClass = static function (string $key) use ($moderatorActiveNav): string {
    return $moderatorActiveNav === $key ? 'menu-btn-nav active-nav' : 'menu-btn-nav';
};
?>
    <!-- SIDEBAR MAIN MENU -->
    <div class="offcanvas offcanvas-end" tabindex="-1" id="mainMenuOffcanvas" style="width: 320px; background: rgba(6, 12, 24, 0.98); backdrop-filter: blur(28px); border-left: 1px solid rgba(0, 180, 255, 0.18);">
        <div class="offcanvas-header pb-2 border-bottom border-white border-opacity-10">
            <div>
                <h5 class="offcanvas-title text-white fw-bold mb-0">Main Menu</h5>
                <p class="text-white-50 small mb-0" style="font-size:0.78rem;">Moderator Portal</p>
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
                <li><a href="moderator_dashboard.php" class="<?= $modMenuClass('home') ?>"><i class="bi bi-house me-3 txt-neon-cyan"></i> Home</a></li>
                <li><a href="view_hq_and_user_account(M).php" class="<?= $modMenuClass('hq') ?>"><i class="bi bi-building-gear me-3 txt-neon-cyan"></i> View HQ &amp; User Accounts</a></li>
                <li><a href="view_requests(M).php" class="<?= $modMenuClass('requests') ?>"><i class="bi bi-file-earmark-check me-3 txt-neon-cyan"></i> View Requests</a></li>
                <li><a href="view_report(M).php" class="<?= $modMenuClass('report') ?>"><i class="bi bi-graph-up-arrow me-3 txt-neon-cyan"></i> View Report</a></li>
                <li><a href="edit_profile(M).php" class="<?= $modMenuClass('profile') ?>"><i class="bi bi-person-bounding-box me-3 txt-neon-blue"></i> Edit Profile</a></li>
                <li><a href="view_logs(M).php" class="<?= $modMenuClass('logs') ?>"><i class="bi bi-terminal-split me-3 text-danger"></i> View Log Files</a></li>
                <hr style="border-color: rgba(255, 255, 255, 0.08); margin: 4px 0;">
                <li>
                    <form action="logout(M).php" method="post" class="m-0">
                        <button type="submit" class="logout-btn-nav btn border-0 w-100">
                            <i class="bi bi-box-arrow-right me-2"></i> Logout
                        </button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
