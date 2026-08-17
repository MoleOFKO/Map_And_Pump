<?php
$adminSection = (string) ($adminSection ?? '');
$adminNavClass = static fn (string $key): string => $adminSection === $key ? 'menu-btn-nav active-nav' : 'menu-btn-nav';
?>
<div class="container sticky-nav-wrapper">
    <nav class="navbar navbar-dark text-white navigation_style">
        <div class="container-fluid d-flex flex-wrap justify-content-between align-items-center p-0 gap-3">
            <a class="navbar-brand d-flex align-items-center gap-2 fw-semibold txt-neon-cyan m-0" href="admin_dashboard.php" style="font-size:.95rem;"><img src="/images/Logo.png" alt="Logo" class="nav-logo"><span>Admin Portal</span></a>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <a href="manage_hq.php" class="<?= $adminNavClass('manage_hq') ?>"><i class="bi bi-buildings me-1 txt-neon-cyan"></i> Manage HQ</a>
                <a href="view_hq_and_user_account.php" class="<?= $adminNavClass('view_hq') ?>"><i class="bi bi-building-gear me-1 txt-neon-cyan"></i> View HQ</a>
                <a href="manage_users.php" class="<?= $adminNavClass('manage_users') ?>"><i class="bi bi-person-gear me-1 txt-neon-cyan"></i> Manage Users</a>
                <a href="view_users.php" class="<?= $adminNavClass('view_users') ?>"><i class="bi bi-people me-1 txt-neon-cyan"></i> View Users</a>
                <a href="admin_dashboard.php" class="menu-btn-nav"><i class="bi bi-house me-1 txt-neon-cyan"></i> Home</a>
            </div>
        </div>
    </nav>
</div>
