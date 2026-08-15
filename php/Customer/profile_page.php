<?php include 'share_header.php'; ?>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="dashboard-panel-card h-100">
            <h3 class="text-white mb-1">Profile Configurations</h3>
            <p class="text-secondary mb-4" style="font-size:13px;">Manage driver profile metadata registered with state license records.</p>

            <form class="space-y-4" onsubmit="event.preventDefault(); alert('Profile updates saved successfully.');">
                <div class="mb-3">
                    <label class="form-label text-secondary" style="font-size:11px; font-weight:600;">DISPLAY FULL NAME</label>
                    <input type="text" value="<?php echo htmlspecialchars($_SESSION['user_name']); ?>" class="form-control custom-form-field">
                </div>
                <div class="mb-4">
                    <label class="form-label text-secondary" style="font-size:11px; font-weight:600;">REGISTERED SYSTEM EMAIL</label>
                    <input type="email" value="<?php echo htmlspecialchars($_SESSION['user_email']); ?>" class="form-control custom-form-field" readonly>
                </div>
                <button type="submit" class="btn btn-info px-4 py-2.5 fw-bold rounded-4" style="font-size:12px; font-family:'Space Grotesk';">Save Changes</button>
            </form>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="d-flex flex-column gap-4 h-100">
            <div class="dashboard-panel-card">
                <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
                    <div class="d-flex align-items-start gap-3">
                        <div class="bg-primary bg-opacity-10 p-2.5 rounded-3 text-sky-400">
                            <i class="bi bi-person-vcard fs-4"></i>
                        </div>
                        <div>
                            <h3 class="text-white mb-1">Driver License</h3>
                            <p class="text-secondary mb-0" style="font-size:13px;">Keep the driver's license details for this user up to date.</p>
                        </div>
                    </div>
                    <a href="license.php" class="btn btn-outline-info btn-sm px-3 rounded-3 fw-bold" style="font-size:12px;">Edit</a>
                </div>
                <button type="button" class="btn btn-link text-info p-0 fw-bold text-decoration-none" style="font-size:12px;" onclick="alert('Add-user setup will open here.');">
                    <i class="bi bi-person-plus-fill me-1"></i>Add User
                </button>
            </div>

            <div class="dashboard-panel-card flex-grow-1">
                <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
                    <div class="d-flex align-items-start gap-3">
                        <div class="bg-primary bg-opacity-10 p-2.5 rounded-3 text-sky-400">
                            <i class="bi bi-car-front-fill fs-4"></i>
                        </div>
                        <div>
                            <h3 class="text-white mb-1">Car License</h3>
                            <p class="text-secondary mb-0" style="font-size:13px;">Manage the registration and license details for the selected car.</p>
                        </div>
                    </div>
                    <button type="button" class="btn btn-outline-info btn-sm px-3 rounded-3 fw-bold" style="font-size:12px;" onclick="alert('Car license editing will open here.');">Edit</button>
                </div>
                <button type="button" class="btn btn-link text-info p-0 fw-bold text-decoration-none" style="font-size:12px;" onclick="alert('Car switcher will open here.');">
                    <i class="bi bi-arrow-left-right me-1"></i>Switch Car
                </button>
            </div>
        </div>
    </div>
</div>

<?php include 'share_footer.php'; ?>
