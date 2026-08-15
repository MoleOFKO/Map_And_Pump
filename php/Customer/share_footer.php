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
                <a href="/php/Login/logout.php" class="btn btn-danger w-100 py-2">Sign Out</a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/js/customer-theme.js"></script>

    <script>
        function toggleAlertBox() {
            const panel = document.getElementById('notif-alert-panel');
            panel.classList.toggle('d-none');
            document.getElementById('notif-pulse-dot').classList.add('d-none');
        }
    </script>
</body>
</html>
