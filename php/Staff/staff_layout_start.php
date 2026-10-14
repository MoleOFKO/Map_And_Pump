<?php
$staffPageTitle = trim((string)($staffPageTitle ?? 'Workspace'));
$staffActivePage = (string)($staffActivePage ?? '');
$staffShellName = trim((string)($displayName ?? $_SESSION['user_name'] ?? 'Staff')) ?: 'Staff';
$staffShellInitials = 'ST';
$staffShellNameParts = preg_split('/\s+/', $staffShellName, -1, PREG_SPLIT_NO_EMPTY);
if ($staffShellNameParts) {
    $staffShellFirst = $staffShellNameParts[0];
    $staffShellLast = count($staffShellNameParts) > 1 ? $staffShellNameParts[count($staffShellNameParts) - 1] : '';
    $staffShellInitials = strtoupper(
        (function_exists('mb_substr') ? mb_substr($staffShellFirst, 0, 1, 'UTF-8') : substr($staffShellFirst, 0, 1))
        . ($staffShellLast !== '' ? (function_exists('mb_substr') ? mb_substr($staffShellLast, 0, 1, 'UTF-8') : substr($staffShellLast, 0, 1)) : '')
    );
}
$staffNavItems = [
    'overview' => ['staff_dashboard.php', 'bi-grid', 'Overview'],
    'verify' => ['verify_qr.php', 'bi-upc-scan', 'Verify booking QR'],
    'feedback' => ['feedback_manage.php', 'bi-chat-square', 'Feedback'],
    'account' => ['staff_account.php', 'bi-person', 'My account'],
];
?>
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
            <?php foreach ($staffNavItems as $navKey => [$navHref, $navIcon, $navLabel]): ?>
                <a class="staff-nav-link <?= $staffActivePage === $navKey ? 'is-active' : '' ?>" href="<?= htmlspecialchars($navHref, ENT_QUOTES, 'UTF-8') ?>" <?= $staffActivePage === $navKey ? 'aria-current="page"' : '' ?>>
                    <i class="bi <?= htmlspecialchars($navIcon, ENT_QUOTES, 'UTF-8') ?>"></i><span><?= htmlspecialchars($navLabel, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if ($staffActivePage === $navKey): ?><span class="nav-indicator"></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="staff-sidebar-bottom">
            <a class="staff-help-card" href="staff_dashboard.php#quick-guide">
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
            <div class="staff-breadcrumb">
                <button class="staff-mobile-menu" type="button" data-bs-toggle="offcanvas" data-bs-target="#mainMenuOffcanvas" aria-label="Open navigation menu"><i class="bi bi-list"></i></button>
                <span>Workspace</span><i class="bi bi-chevron-right"></i><strong><?= htmlspecialchars($staffPageTitle, ENT_QUOTES, 'UTF-8') ?></strong>
            </div>
            <div class="staff-topbar-actions">
                <span class="staff-access"><i class="bi bi-shield-check"></i> Staff access</span>
                <label class="staff-theme-control" title="Toggle light mode"><input class="js-theme-toggle" type="checkbox" aria-label="Toggle light mode"><span><i class="bi bi-sun"></i></span></label>
                <span class="staff-avatar" aria-label="Signed in as <?= htmlspecialchars($staffShellName, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($staffShellInitials, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </header>
        <div class="staff-content">
