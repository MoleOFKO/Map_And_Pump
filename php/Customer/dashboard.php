<?php 
include 'share_header.php'; 

$oilLimit = max(0.1, (float) ($_SESSION['oil_limit'] ?? 35.0));
$oilUsed = max(0.0, (float) ($_SESSION['oil_used'] ?? 0.0));
$oilRemaining = max(0.0, (float) ($_SESSION['oil_remaining'] ?? ($oilLimit - $oilUsed)));
$oilPercent = max(0.0, min(100.0, ($oilRemaining / $oilLimit) * 100));

$monthly_visits = 3; 
$rewardPointsStmt = $pdo->prepare('SELECT COALESCE(had_point, 0) FROM users WHERE user_id = ? LIMIT 1');
$rewardPointsStmt->execute([(int) $_SESSION['user_id']]);
$reward_points = max(0, (int) $rewardPointsStmt->fetchColumn());

if ($reward_points >= 100) {
    $reward_tier = 'Diamond';
} elseif ($reward_points >= 80) {
    $reward_tier = 'Platinum';
} elseif ($reward_points >= 60) {
    $reward_tier = 'Gold';
} elseif ($reward_points >= 40) {
    $reward_tier = 'Silver';
} else {
    $reward_tier = 'Bronze';
}
?>

<div class="customer-dashboard">
<div class="row g-4 customer-dashboard-stats mb-4">
    <!-- Card 1: Remaining Fuel Quota -->
    <div class="col-lg-4 col-md-6 d-flex">
        <div class="dashboard-panel-card customer-stat-card w-100">
            <p class="text-secondary text-uppercase tracking-wider mb-2" style="font-size:11px; font-weight:600;">Remaining Fuel Quota</p>
            <h3 class="text-sky-400 fs-1 mb-1">
                <?php echo number_format($oilRemaining, 1); ?>
                <span class="fs-4 text-secondary fw-normal">/ <?php echo number_format($oilLimit, 1); ?> L</span>
            </h3>
            <div class="progress bg-dark bg-opacity-50 rounded-pill mt-3" style="height: 6px;">
                <div class="progress-bar bg-info" style="width: <?php echo $oilPercent; ?>%"></div>
            </div>
        </div>
    </div>

    <!-- Card 2: Monthly Station Visits -->
    <div class="col-lg-4 col-md-6 d-flex">
        <div class="dashboard-panel-card customer-stat-card w-100">
            <p class="text-secondary text-uppercase tracking-wider mb-2" style="font-size:11px; font-weight:600;">Monthly Station Visits</p>
            <h3 class="text-white fs-1 mb-1"><?php echo $monthly_visits; ?> <span class="fs-5 text-secondary fw-normal">Times</span></h3>
            <p class="text-secondary mb-0 mt-3" style="font-size:12px;">Total refueling sessions recorded this month</p>
        </div>
    </div>

    <!-- Card 3: Loyalty Reward Points (New Idea) -->
    <div class="col-lg-4 col-md-6 d-flex">
        <div class="dashboard-panel-card customer-stat-card w-100">
            <p class="text-secondary text-uppercase tracking-wider mb-2" style="font-size:11px; font-weight:600;">Loyalty Reward Points</p>
            <h3 class="text-white fs-1 mb-1">
                <?php echo number_format($reward_points); ?> 
                <span class="fs-5 text-warning ms-1"><i class="bi bi-star-fill" style="font-size: 20px;"></i></span>
            </h3>
            <p class="customer-reward-tier customer-reward-tier-<?php echo strtolower($reward_tier); ?> mb-0 mt-3">
                <i class="bi bi-award-fill me-1"></i><?php echo htmlspecialchars($reward_tier, ENT_QUOTES, 'UTF-8'); ?> tier
            </p>
            <p class="text-secondary mb-0 mt-2" style="font-size:12px;">3 points per successful fill · redeem up to 20 points per fill (max 20% off)</p>
        </div>
    </div>
</div>

<!-- Updated "Track Stations & Book Tokens" Card -->
<div class="customer-live-queue-card p-4 p-md-4 rounded-4 position-relative overflow-hidden mb-4">
    <div class="row align-items-center position-relative" style="z-index:10;">
        <div class="col-lg-10 col-xl-8">
            <span class="status-pill active mb-2 inline-block"><span class="spinner-grow spinner-grow-sm me-1" role="status"></span> Live Queue Verification active</span>
            <h2 class="text-white mb-2 fs-2">Track Stations & Book Tokens</h2>
            <p class="text-secondary mb-3 max-width-md" style="font-size:13px; line-height: 1.5;">Generate token barcodes, and queue dynamically at localized stations.</p>
            <a href="track_book.php" class="btn btn-info px-4 py-2 fw-bold rounded-3 shadow" style="font-size:13px; font-family:'Space Grotesk';">
                Begin Reservation Hub <i class="fa-solid fa-arrow-right ms-2"></i>
            </a>
        </div>
    </div>
    <div class="position-absolute" style="width: 250px; height: 250px; background: radial-gradient(circle, rgba(56,189,248,0.1) 0%, rgba(0,0,0,0) 70%); right: -50px; bottom: -50px; pointer-events: none;"></div>
</div>
</div>

<?php include 'share_footer.php'; ?>

