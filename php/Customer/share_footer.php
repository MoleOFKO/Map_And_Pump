</main>

<?php
$licenseExpiryWarnings = $licenseExpiryWarnings ?? [];
$showLicenseExpiryWarning = $showLicenseExpiryWarning ?? false;
?>

<?php if (!empty($showLicenseExpiryWarning)): ?>
    <div class="license-expiry-warning" id="licenseExpiryWarning" role="alert">
        <div class="license-expiry-warning-icon"><i class="bi bi-hourglass-split"></i></div>
        <div class="license-expiry-warning-content">
            <strong>License expiry reminder</strong>
            <span>
                <?php foreach ($licenseExpiryWarnings as $warningIndex => $warning): ?>
                    <?php if ($warningIndex > 0): ?>, <?php endif; ?>
                    <a href="<?php echo htmlspecialchars($warning['url'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($warning['label'], ENT_QUOTES, 'UTF-8'); ?></a>
                    expires in <b><?php echo (int) $warning['days_left']; ?> day<?php echo (int) $warning['days_left'] === 1 ? '' : 's'; ?></b>
                <?php endforeach; ?>.
            </span>
        </div>
        <button type="button" class="license-expiry-later" id="licenseExpiryLater">Later</button>
    </div>
    <style>
        .license-expiry-warning { position: fixed; right: 24px; bottom: 24px; z-index: 1100; display: flex; align-items: flex-start; gap: 12px; width: min(440px, calc(100vw - 32px)); padding: 15px 16px; color: #fff4d6; background: rgba(53, 38, 12, .96); border: 1px solid rgba(255, 193, 7, .48); border-radius: 12px; box-shadow: 0 16px 40px rgba(0,0,0,.4); }
        .license-expiry-warning-icon { color: #ffd15c; font-size: 20px; }
        .license-expiry-warning-content { display: grid; gap: 4px; min-width: 0; font-size: 12px; line-height: 1.45; }
        .license-expiry-warning-content strong { color: #ffd15c; font-size: 13px; }
        .license-expiry-warning-content a { color: #fff; font-weight: 700; text-decoration: underline; }
        .license-expiry-warning-content b { color: #fff; }
        .license-expiry-later { margin-left: auto; padding: 4px 0; color: #d9c78b; background: transparent; border: 0; font-size: 11px; cursor: pointer; }
        .license-expiry-later:hover { color: #fff; }
    </style>
    <script>
        document.getElementById('licenseExpiryLater')?.addEventListener('click', function () {
            document.getElementById('licenseExpiryWarning')?.remove();
        });
    </script>
<?php endif; ?>

    <!-- Mobile Sidebar -->
    <div class="offcanvas offcanvas-start bg-dark text-white" tabindex="-1" id="mobileSidebar" style="width:280px; background-color: var(--navy-900) !important;">
        <div class="offcanvas-header border-bottom border-secondary border-opacity-10">
            <h5 class="offcanvas-title disp-brand text-sky-400 d-flex align-items-center gap-2">
                <img src="/images/Logo.png" alt="Map & Pump" style="width:24px; height:24px;"> MAP & PUMP
            </h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
        </div>
        <div class="offcanvas-body d-flex flex-column justify-content-between p-0">
            <div class="mt-4">
                <?php if (($customerLicenseAccess['is_approved'] ?? false)): ?>
                    <a href="dashboard.php" class="nav-item-link">
                        <i class="bi bi-grid-1x2-fill"></i> Dashboard
                    </a>
                    <a href="track_book.php" class="nav-item-link">
                        <i class="bi bi-geo-alt-fill"></i> Track & Book
                    </a>
                    <a href="usage_history.php" class="nav-item-link">
                        <i class="bi bi-clipboard2-pulse-fill"></i> Usage History
                    </a>
                    <a href="profile_page.php" class="nav-item-link">
                        <i class="bi bi-person-fill-gear"></i> Edit Profile
                    </a>
                <?php endif; ?>
                <?php if (!($customerLicenseAccess['is_approved'] ?? false)): ?>
                    <a href="license.php" class="nav-item-link">
                        <i class="bi bi-person-vcard-fill"></i> Driver License
                    </a>
                <?php endif; ?>
            </div>
            <div class="p-4 bg-black bg-opacity-20">
                <?php if (($linkedBusinessRoleNameHeader ?? '') !== ''): ?>
                    <form action="../account_switch.php" method="POST" class="mb-3">
                        <input type="hidden" name="mode" value="business">
                        <button type="submit" class="btn btn-outline-info w-100 py-2">
                            <i class="bi bi-arrow-left-right me-1"></i> Switch Account
                        </button>
                    </form>
                <?php else: ?>
                    <a href="profile_page.php" class="btn btn-outline-info w-100 py-2 mb-3">
                        <i class="bi bi-arrow-left-right me-1"></i> Switch Account
                    </a>
                <?php endif; ?>
                <a href="../Login/logout.php" class="btn btn-danger w-100 py-2">Sign Out</a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php if (!empty($_SESSION['customer_invitation_message'])): ?>
        <div class="alert alert-info position-fixed top-0 start-50 translate-middle-x mt-3 shadow" style="z-index: 2000;" role="alert">
            <?= htmlspecialchars((string) $_SESSION['customer_invitation_message'], ENT_QUOTES, 'UTF-8') ?>
        </div>
        <?php unset($_SESSION['customer_invitation_message']); ?>
    <?php endif; ?>
    <?php if (!empty($pendingInvitation)): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                new bootstrap.Modal(document.getElementById('hqInvitationModal'), {backdrop: 'static', keyboard: false}).show();
            });
        </script>
    <?php endif; ?>
    <script src="/js/customer-theme.js"></script>

    <script>
        function toggleAlertBox() {
            const panel = document.getElementById('notif-alert-panel');
            panel.classList.toggle('d-none');
            document.getElementById('notif-pulse-dot').classList.add('d-none');
        }
    </script>
    <?php require_once __DIR__ . '/../moderator_hire_popup.php'; ?>
</body>
</html>
