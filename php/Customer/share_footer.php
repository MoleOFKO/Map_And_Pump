</main>

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
                <?php if ($customerDriverLicenseExpired ?? false): ?>
                    <a href="profile_page.php" class="nav-item-link">
                        <i class="bi bi-person-fill-gear"></i> Edit Profile
                    </a>
                    <a href="license.php?update=1" class="nav-item-link">
                        <i class="bi bi-person-vcard-fill"></i> Renew Driver License
                    </a>
                <?php elseif (($customerLicenseAccess['is_approved'] ?? false)): ?>
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
                <?php if (!($customerDriverLicenseExpired ?? false) && !($customerLicenseAccess['is_approved'] ?? false)): ?>
                    <a href="license.php" class="nav-item-link">
                        <i class="bi bi-person-vcard-fill"></i> Driver License
                    </a>
                <?php endif; ?>
            </div>
            <div class="p-4 bg-black bg-opacity-20">
                <?php if (!($customerDriverLicenseExpired ?? false) && ($linkedBusinessRoleNameHeader ?? '') !== ''): ?>
                    <form action="../account_switch.php" method="POST" class="mb-3">
                        <input type="hidden" name="mode" value="business">
                        <button type="submit" class="btn btn-outline-info w-100 py-2">
                            <i class="bi bi-arrow-left-right me-1"></i> Switch Account
                        </button>
                    </form>
                <?php elseif (!($customerDriverLicenseExpired ?? false)): ?>
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
            const button = document.getElementById('notif-bell');
            if (!panel || !button) return;
            const shouldOpen = panel.classList.contains('d-none');
            panel.classList.toggle('d-none', !shouldOpen);
            button.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
            panel.setAttribute('aria-hidden', shouldOpen ? 'false' : 'true');
        }

        window.toggleAlertBox = toggleAlertBox;

        document.addEventListener('DOMContentLoaded', function () {
            const button = document.getElementById('notif-bell');
            const panel = document.getElementById('notif-alert-panel');
            if (!button) return;

            if (panel) {
                document.addEventListener('click', function (event) {
                    if (!panel.classList.contains('d-none') && !button.contains(event.target) && !panel.contains(event.target)) {
                        panel.classList.add('d-none');
                        button.setAttribute('aria-expanded', 'false');
                        panel.setAttribute('aria-hidden', 'true');
                    }
                });
            }
        });
    </script>
    <?php
    $moderatorHireFlash = '';
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['moderator_hire_flash'])) {
        $moderatorHireFlash = (string) $_SESSION['moderator_hire_flash'];
        unset($_SESSION['moderator_hire_flash']);
    }
    if ($moderatorHireFlash !== ''):
    ?>
    <div class="container my-3">
        <div class="alert alert-info mb-0" role="alert" style="border-radius:14px;">
            <?= htmlspecialchars($moderatorHireFlash, ENT_QUOTES, 'UTF-8') ?>
        </div>
    </div>
    <?php endif; ?>
    <?php require_once __DIR__ . '/../moderator_hire_popup.php'; ?>
</body>
</html>
