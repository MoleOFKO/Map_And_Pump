        <footer class="staff-footer">&copy; <?= date('Y') ?> Map &amp; Pump. All rights reserved.</footer>
        </div>
    </main>
</div>
<div class="offcanvas offcanvas-start staff-mobile-drawer" tabindex="-1" id="mainMenuOffcanvas" aria-label="Staff navigation">
    <div class="offcanvas-header">
        <a class="staff-brand" href="staff_dashboard.php"><img class="staff-brand-logo" src="/images/Logo.png" alt=""><span class="staff-brand-copy"><strong>Map &amp; Pump</strong><small>STAFF PORTAL</small></span></a>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close menu"></button>
    </div>
    <div class="offcanvas-body">
        <div class="staff-workspace-card">
            <span class="workspace-icon"><i class="bi bi-buildings"></i></span>
            <span><strong>Staff workspace</strong><small>Map &amp; Pump</small></span>
            <i class="bi bi-shield-check workspace-shield" aria-hidden="true"></i>
        </div>
        <p class="staff-nav-label">Workspace</p>
        <nav class="staff-nav" aria-label="Mobile main navigation">
            <?php foreach ($staffNavItems as $navKey => [$navHref, $navIcon, $navLabel]): ?>
                <a class="staff-nav-link <?= $staffActivePage === $navKey ? 'is-active' : '' ?>" href="<?= htmlspecialchars($navHref, ENT_QUOTES, 'UTF-8') ?>" <?= $staffActivePage === $navKey ? 'aria-current="page"' : '' ?>>
                    <i class="bi <?= htmlspecialchars($navIcon, ENT_QUOTES, 'UTF-8') ?>"></i><span><?= htmlspecialchars($navLabel, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if ($staffActivePage === $navKey): ?><span class="nav-indicator"></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <form action="../Login/logout.php" method="post" class="staff-logout-form">
            <button type="submit" class="staff-logout"><i class="bi bi-box-arrow-right"></i><span>Sign out</span></button>
        </form>
    </div>
</div>
