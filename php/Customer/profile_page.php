<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../conn.php';

$profileFlashMessage = $_SESSION['profile_flash_message'] ?? null;
$profileFlashType = $_SESSION['profile_flash_type'] ?? 'success';
unset($_SESSION['profile_flash_message'], $_SESSION['profile_flash_type']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    $newName = trim((string) ($_POST['user_name'] ?? ''));

    if (!isset($_SESSION['user_id']) || !ctype_digit((string) $_SESSION['user_id'])) {
        $_SESSION['profile_flash_message'] = 'Please sign in before updating your profile.';
        $_SESSION['profile_flash_type'] = 'danger';
    } elseif ($newName === '') {
        $_SESSION['profile_flash_message'] = 'Please enter a display name.';
        $_SESSION['profile_flash_type'] = 'danger';
    } elseif (mb_strlen($newName) > 225) {
        $_SESSION['profile_flash_message'] = 'Display name must be 225 characters or fewer.';
        $_SESSION['profile_flash_type'] = 'danger';
    } else {
        try {
            $update = $pdo->prepare('UPDATE users SET user_name = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?');
            $update->execute([$newName, (int) $_SESSION['user_id']]);
            $_SESSION['user_name'] = $newName;
            $_SESSION['profile_flash_message'] = 'Shared profile name updated.';
            $_SESSION['profile_flash_type'] = 'success';
        } catch (Throwable $e) {
            error_log('Failed to update customer profile name: ' . $e->getMessage());
            $_SESSION['profile_flash_message'] = 'Profile name could not be updated right now.';
            $_SESSION['profile_flash_type'] = 'danger';
        }
    }

    header('Location: profile_page.php');
    exit;
}

$linkedBusinessRoleName = '';
if (isset($_SESSION['user_id']) && ctype_digit((string) $_SESSION['user_id'])) {
    try {
        $businessStmt = $pdo->prepare(
            'SELECT u.role_id, u.business_role_id,
                    primary_role.role_name AS primary_role_name,
                    business_role.role_name AS business_role_name,
                    customer_role.role_id AS customer_role_id
             FROM users u
             LEFT JOIN roles primary_role ON primary_role.role_id = u.role_id
             LEFT JOIN roles business_role ON business_role.role_id = u.business_role_id
             LEFT JOIN roles customer_role ON LOWER(customer_role.role_name) = \'customer\'
             WHERE u.user_id = ?
             LIMIT 1'
        );
        $businessStmt->execute([(int) $_SESSION['user_id']]);
        $profileRoles = $businessStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $linkedBusinessRoleName = trim((string) ($profileRoles['business_role_name'] ?? ''));
    } catch (Throwable $e) {
        error_log('Failed to load customer profile modes: ' . $e->getMessage());
    }
}

include 'share_header.php';
?>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="dashboard-panel-card h-100">
            <h3 class="text-white mb-1">Profile Configurations</h3>
            <p class="text-secondary mb-4" style="font-size:13px;">Manage the shared account profile used by customer and business modes.</p>

            <?php if ($profileFlashMessage): ?>
                <div class="alert alert-<?php echo htmlspecialchars($profileFlashType === 'success' ? 'success' : ($profileFlashType === 'warning' ? 'warning' : 'danger')); ?> border-0 rounded-4" role="alert">
                    <?php echo htmlspecialchars($profileFlashMessage); ?>
                </div>
            <?php endif; ?>

            <form class="space-y-4" action="profile_page.php" method="POST">
                <input type="hidden" name="action" value="update_profile">
                <div class="mb-3">
                    <label class="form-label text-secondary" style="font-size:11px; font-weight:600;">DISPLAY FULL NAME</label>
                    <input type="text" name="user_name" value="<?php echo htmlspecialchars($_SESSION['user_name']); ?>" class="form-control custom-form-field" maxlength="225" required>
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
                            <i class="bi bi-arrow-left-right fs-4"></i>
                        </div>
                        <div>
                            <h3 class="text-white mb-1">Account Profile Mode</h3>
                            <p class="text-secondary mb-0" style="font-size:13px;">Customer is the basic gas-fill profile. Switch back to the linked business role anytime.</p>
                        </div>
                    </div>
                </div>
                <?php if ($linkedBusinessRoleName !== ''): ?>
                    <form action="../account_switch.php" method="POST" class="m-0">
                        <input type="hidden" name="mode" value="business">
                        <button type="submit" class="btn btn-outline-info btn-sm px-3 rounded-3 fw-bold" style="font-size:12px;">
                            <i class="bi bi-briefcase-fill me-1"></i>Switch to <?php echo htmlspecialchars($linkedBusinessRoleName); ?> Profile
                        </button>
                    </form>
                <?php else: ?>
                    <span class="text-secondary" style="font-size:12px;">No linked business profile is available for this account yet.</span>
                <?php endif; ?>
            </div>

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
