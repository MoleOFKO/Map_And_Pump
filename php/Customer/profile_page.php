<?php
// Process profile post upgrades before rendering HTML header blocks
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upgrade_business') {
    session_start();
    $_SESSION['has_business'] = true;
    $message = "Your profile has been successfully linked and transitioned to a Business Account!";
}

include 'share_header.php';
?>

<?php if (isset($message)): ?>
    <div class="alert alert-success border-0 bg-success bg-opacity-10 text-success rounded-4 p-4 mb-4 d-flex align-items-center gap-3" role="alert">
        <i class="bi bi-check-circle-fill fs-4"></i>
        <span class="fw-semibold" style="font-size:14px;"><?php echo $message; ?></span>
    </div>
<?php endif; ?>

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
        <div class="dashboard-panel-card h-100">
            <div class="d-flex align-items-start gap-3 mb-4">
                <div class="bg-primary bg-opacity-10 p-2.5 rounded-3 text-sky-400">
                    <i class="bi bi-buildings fs-4"></i>
                </div>
                <div>
                    <h3 class="text-white mb-1">Enterprise Conversion</h3>
                    <p class="text-secondary mb-0" style="font-size:13px;">Corporate entities and station branches can request profile transitions below.</p>
                </div>
            </div>

            <?php if ($_SESSION['has_business']): ?>
                <div class="p-3 bg-success bg-opacity-10 text-success rounded-4 border border-success border-opacity-10 font-semibold" style="font-size: 13px;">
                    <i class="bi bi-shield-fill-check me-1"></i> This account is successfully connected to a registered Enterprise Group profile.
                </div>
            <?php else: ?>
                <form method="POST" action="profile_page.php">
                    <input type="hidden" name="action" value="upgrade_business">
                    <button type="submit" class="btn btn-primary w-100 py-3 fw-bold rounded-4" style="font-size:12px; font-family:'Space Grotesk';">
                        Transition profile to Station Owner / Partner
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'share_footer.php'; ?>