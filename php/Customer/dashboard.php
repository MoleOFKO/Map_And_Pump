<?php 
include 'share_header.php'; 

// Simulated variables for the new dashboard cards
// You can later replace these with real database queries
$monthly_visits = 3; 
$reward_points = 1250;
?>

<div class="row g-4 mb-5">
    <!-- Card 1: Remaining Fuel Quota -->
    <div class="col-xl-4 col-md-6">
        <div class="dashboard-panel-card">
            <p class="text-secondary text-uppercase tracking-wider mb-2" style="font-size:11px; font-weight:600;">Remaining Fuel Quota</p>
            <h3 class="text-sky-400 fs-1 mb-1">
                <?php echo ($_SESSION['oil_limit'] - $_SESSION['oil_used']); ?> 
                <span class="fs-4 text-secondary fw-normal">/ <?php echo $_SESSION['oil_limit']; ?> L</span>
            </h3>
            <div class="progress bg-dark bg-opacity-50 rounded-pill mt-3" style="height: 6px;">
                <div class="progress-bar bg-info" style="width: <?php echo (($_SESSION['oil_limit'] - $_SESSION['oil_used'])/$_SESSION['oil_limit'])*100; ?>%"></div>
            </div>
        </div>
    </div>

    <!-- Card 2: Monthly Station Visits -->
    <div class="col-xl-4 col-md-6">
        <div class="dashboard-panel-card">
            <p class="text-secondary text-uppercase tracking-wider mb-2" style="font-size:11px; font-weight:600;">Monthly Station Visits</p>
            <h3 class="text-white fs-1 mb-1"><?php echo $monthly_visits; ?> <span class="fs-5 text-secondary fw-normal">Times</span></h3>
            <p class="text-secondary mb-0 mt-3" style="font-size:12px;">Total refueling sessions recorded this month</p>
        </div>
    </div>

    <!-- Card 3: Loyalty Reward Points (New Idea) -->
    <div class="col-xl-4 col-md-12">
        <div class="dashboard-panel-card">
            <p class="text-secondary text-uppercase tracking-wider mb-2" style="font-size:11px; font-weight:600;">Loyalty Reward Points</p>
            <h3 class="text-white fs-1 mb-1">
                <?php echo number_format($reward_points); ?> 
                <span class="fs-5 text-warning ms-1"><i class="bi bi-star-fill" style="font-size: 20px;"></i></span>
            </h3>
            <p class="text-secondary mb-0 mt-3" style="font-size:12px;">Earn points on every liter to redeem for future discounts</p>
        </div>
    </div>
</div>

<div class="p-5 rounded-5 shadow-lg border border-secondary border-opacity-10 position-relative overflow-hidden mb-5" style="background: linear-gradient(135deg, #101a30 0%, #070b14 100%);">
    <div class="row align-items-center position-relative" style="z-index:10;">
        <div class="col-lg-8">
            <span class="status-pill active mb-3"><span class="spinner-grow spinner-grow-sm me-1" role="status"></span> Live Queue Verification active</span>
            <h2 class="text-white mb-2 fs-1">Track Stations & Book Tokens</h2>
            <p class="text-secondary mb-4 max-width-md" style="font-size:14px;">Verify vehicle license registries, compute remaining allocations, generate token barcodes, and queue dynamically at localized stations.</p>
            <a href="track_book.php" class="btn btn-info px-4 py-3 fw-bold rounded-4 shadow" style="font-size:13px; font-family:'Space Grotesk';">
                Begin Reservation Hub <i class="fa-solid fa-arrow-right ms-2"></i>
            </a>
        </div>
    </div>
    <div class="position-absolute" style="width: 300px; height: 300px; background: radial-gradient(circle, rgba(56,189,248,0.1) 0%, rgba(0,0,0,0) 70%); right: -50px; bottom: -50px; pointer-events: none;"></div>
</div>

<?php include 'share_footer.php'; ?>