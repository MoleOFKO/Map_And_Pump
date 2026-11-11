<?php
session_start();

if (empty($_SESSION['is_logged_in']) || (empty($_SESSION['user_id']) && empty($_SESSION['hq_auth']))) {
    $_SESSION['login_error'] = 'Please sign in to continue.';
    header('Location: ../Login/login.php');
    exit;
}
if (!empty($_SESSION['hq_auth']) && empty($_SESSION['hq_switch_csrf'])) {
    $_SESSION['hq_switch_csrf'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <script src="../../js/hq-theme.js"></script>
<link rel="stylesheet" href="../../css/HQstyle.css">
    <title>Switch Account - Map & Pump</title>
</head>
<body>
    <div class="card" style="max-width: 500px; text-align: center; margin: 40px auto 0 auto;">
        <h3>Switch to Customer Profile</h3>
        <p class="subtitle" style="margin-top: 8px;">This keeps your signed-in session active and opens the Customer profile linked to this HQ.</p>
        <form action="../account_switch.php" method="POST" target="_top">
            <input type="hidden" name="mode" value="customer">
            <?php if (!empty($_SESSION['hq_auth'])): ?>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['hq_switch_csrf'], ENT_QUOTES, 'UTF-8') ?>">
            <?php endif; ?>
            <button type="submit" class="btn">Switch Profile</button>
        </form>
    </div>
</body>
<footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map & Pump | All Rights Reserved</footer>
</html>
