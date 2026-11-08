<?php
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

include 'share_header.php'; 

$staffStatusPopupType = null;
$staffStatusPopupNotificationId = 0;
if (!empty($_SESSION['staff_status_popup_type'])) {
    $staffStatusPopupType = strtolower(trim((string) $_SESSION['staff_status_popup_type']));
}
$pendingStaffStatusEvents = [];
if (!empty($_SESSION['pending_staff_status_events']) && is_array($_SESSION['pending_staff_status_events'])) {
    $pendingStaffStatusEvents = array_values($_SESSION['pending_staff_status_events']);
}
if (empty($pendingStaffStatusEvents) && !empty($_SESSION['user_id'])) {
    $pendingStaffStatusEvents = staff_status_pending_for_current_user($pdo, (int) $_SESSION['user_id']);
    if (!empty($pendingStaffStatusEvents)) {
        $_SESSION['pending_staff_status_events'] = $pendingStaffStatusEvents;
    }
}
if ($staffStatusPopupType === null && !empty($pendingStaffStatusEvents)) {
    $latestStatusEvent = staff_status_latest_event($pendingStaffStatusEvents);
    if (is_array($latestStatusEvent)) {
        $eventType = strtolower((string) ($latestStatusEvent['notification_type'] ?? ''));
        if ($eventType === 'staff_account_disabled') {
            $staffStatusPopupType = 'disabled';
            $staffStatusPopupNotificationId = (int) ($latestStatusEvent['notification_id'] ?? 0);
        }
        if ($eventType === 'staff_account_reactivated') {
            $staffStatusPopupType = 'activated';
            $staffStatusPopupNotificationId = (int) ($latestStatusEvent['notification_id'] ?? 0);
        }
    }
}
if ($staffStatusPopupType !== null) {
    $_SESSION['staff_status_popup_type'] = $staffStatusPopupType;
}

$oilLimit = max(0.1, (float) ($_SESSION['oil_limit'] ?? 35.0));
$oilUsed = max(0.0, (float) ($_SESSION['oil_used'] ?? 0.0));
$oilRemaining = max(0.0, (float) ($_SESSION['oil_remaining'] ?? ($oilLimit - $oilUsed)));
$oilPercent = max(0.0, min(100.0, ($oilRemaining / $oilLimit) * 100));

$monthly_visits = 3;
require_once __DIR__ . '/../loyalty_points.php';
$customerId = (int) $_SESSION['user_id'];
$reward_points = loyalty_get_available_balance($pdo, $customerId);
$tierStatus = loyalty_customer_tier_status($pdo, $customerId, $reward_points);
$reward_tier_code = $tierStatus['tier_code'];
$reward_tier = loyalty_tier_label($reward_tier_code);
$redemptionOptions = loyalty_redemption_options($pdo);
$pointsCap = max(0, (int) $tierStatus['points_cap']);
$pointsProgress = $pointsCap > 0 ? min(100, ($reward_points / $pointsCap) * 100) : 0;
$nextTier = $tierStatus['next_tier'];
$nextExp = max(0, (int) $tierStatus['next_exp']);
$expRemaining = max(0, $nextExp - (int) $tierStatus['exp']);
$expProgress = $nextTier !== null && $nextExp > 0
    ? min(100, ((int) $tierStatus['exp'] / $nextExp) * 100)
    : 0;
?>

<div class="customer-dashboard">
<div class="row g-4 customer-dashboard-stats mb-4">
    <!-- Card 1: Remaining Fuel Quota -->
    <div class="col-lg-4 col-md-6 d-flex">
        <div class="dashboard-panel-card customer-stat-card w-100">
            <p class="text-secondary text-uppercase tracking-wider mb-2 dash-label">Remaining Fuel Quota</p>
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
            <p class="text-secondary text-uppercase tracking-wider mb-2 dash-label">Monthly Station Visits</p>
            <h3 class="text-white fs-1 mb-1"><?php echo $monthly_visits; ?> <span class="fs-5 text-secondary fw-normal">Times</span></h3>
            <p class="text-secondary mb-0 mt-3 dash-note">Total refueling sessions recorded this month</p>
        </div>
    </div>

    <!-- Card 3: Loyalty Reward Points (New Idea) -->
    <div class="col-lg-4 col-md-6 d-flex">
        <div class="dashboard-panel-card customer-stat-card w-100">
            <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                <p class="text-secondary text-uppercase tracking-wider mb-0 dash-label">Loyalty Reward Points</p>
                <button
                    type="button"
                    class="btn btn-sm btn-outline-info rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0 dash-info-btn"
                    data-bs-toggle="modal"
                    data-bs-target="#loyaltyRewardDetails"
                    aria-label="View loyalty reward details"
                    title="View loyalty reward details">
                    <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                </button>
            </div>
            <h3 class="text-white fs-1 mb-1">
                <?php echo number_format($reward_points); ?> 
                <span class="fs-5 text-warning ms-1"><i class="bi bi-star-fill dash-star"></i></span>
            </h3>
            <p class="customer-reward-tier customer-reward-tier-<?php echo htmlspecialchars(strtolower($reward_tier_code), ENT_QUOTES, 'UTF-8'); ?> mb-0 mt-3">
                <i class="bi bi-award-fill me-1"></i><?php echo htmlspecialchars($reward_tier, ENT_QUOTES, 'UTF-8'); ?> tier
            </p>
        </div>
    </div>
</div>

<div class="modal fade" id="loyaltyRewardDetails" tabindex="-1" aria-labelledby="loyaltyRewardDetailsTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content text-white dash-modal">
            <div class="modal-header border-secondary border-opacity-25 position-relative pe-5">
                <div>
                    <h2 class="modal-title h5 mb-1" id="loyaltyRewardDetailsTitle"><i class="bi bi-stars text-info me-2"></i>Your loyalty rewards</h2>
                    <p class="text-secondary small mb-0">A quick guide to earning points and moving up tiers.</p>
                </div>
                <button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-3" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="rounded-3 p-3 dash-modal-panel">
                    <div class="mb-3">
                        <h3 class="h6 text-white mb-1"><i class="bi bi-info-circle text-info me-2"></i>Earn &amp; redeem</h3>
                        <p class="text-secondary small mb-2">Earn points on successful external fills, then choose one fixed reward when booking.</p>
                        <div class="row row-cols-2 g-2">
                            <?php foreach ($redemptionOptions as $points => $discount): ?>
                                <div class="col">
                                    <div class="rounded-3 p-2 h-100 dash-redeem-tile">
                                        <span class="d-block text-white fw-semibold"><?= number_format($discount) ?> MMK</span>
                                        <span class="text-secondary dash-tile-points"><?= number_format($points) ?> points</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="border-top border-secondary border-opacity-25 pt-3">
                        <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                            <span class="text-white small fw-semibold"><?= htmlspecialchars($reward_tier, ENT_QUOTES, 'UTF-8') ?> point cap</span>
                            <span class="text-secondary small"><?= number_format($reward_points) ?> / <?= number_format($pointsCap) ?></span>
                        </div>
                        <div class="progress bg-dark bg-opacity-50 rounded-pill mb-2" style="height:6px;">
                            <div class="progress-bar bg-info" role="progressbar" style="width:<?= $pointsProgress ?>%;" aria-valuenow="<?= (int) round($pointsProgress) ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Points toward tier cap"></div>
                        </div>
                        <?php if ($nextTier !== null): ?>
                            <p class="text-secondary small mb-0">
                                <?= number_format((int) $tierStatus['points_remaining_to_cap']) ?> points to cap. After that, each successful external fill earns 1 EXP.
                            </p>
                            <div class="d-flex justify-content-between align-items-center gap-2 mt-3 mb-2">
                                <span class="text-white small fw-semibold">Progress to <?= htmlspecialchars(loyalty_tier_label($nextTier), ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="text-secondary small"><?= (int) $tierStatus['exp'] ?> / <?= $nextExp ?> EXP</span>
                            </div>
                            <div class="progress bg-dark bg-opacity-50 rounded-pill mb-2" style="height:6px;">
                                <div class="progress-bar" style="width:<?= $expProgress ?>%;background:#a78bfa;" role="progressbar" aria-valuenow="<?= (int) round($expProgress) ?>" aria-valuemin="0" aria-valuemax="100" aria-label="EXP toward next tier"></div>
                            </div>
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-2">
                                <p class="text-secondary small mb-0">
                                    <?= $expRemaining ?> more EXP to reach <?= htmlspecialchars(loyalty_tier_label($nextTier), ENT_QUOTES, 'UTF-8') ?>.
                                </p>
                                <button type="button" class="btn btn-outline-info btn-sm rounded-3 flex-shrink-0" data-open-tier-details>
                                    <i class="bi bi-list-ul me-1" aria-hidden="true"></i>Tier details
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-2">
                                <p class="text-secondary small mb-0">Diamond tier reached. After your point cap, each successful external fill earns 1 EXP.</p>
                                <button type="button" class="btn btn-outline-info btn-sm rounded-3 flex-shrink-0" data-open-tier-details>
                                    <i class="bi bi-list-ul me-1" aria-hidden="true"></i>Tier details
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-secondary border-opacity-25">
                <button type="button" class="btn btn-outline-info rounded-3 px-4" data-bs-dismiss="modal">Got it</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="tierDetailsModal" tabindex="-1" aria-labelledby="tierDetailsModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content text-white dash-modal">
            <div class="modal-header border-secondary border-opacity-25 position-relative pe-5">
                <div>
                    <h2 class="modal-title h5 mb-1" id="tierDetailsModalTitle"><i class="bi bi-award text-info me-2"></i>Tier details</h2>
                    <p class="text-secondary small mb-0">Point caps and shared monthly reward pool by tier.</p>
                </div>
                <button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-3" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <p class="text-secondary small mb-3">Each tier has its own point-balance cap. Once you reach your current cap, successful external fills award EXP instead of points. EXP advances your tier and raises your point cap.</p>
                <div class="table-responsive">
                    <table class="table table-dark table-sm align-middle mb-0">
                        <thead>
                            <tr><th scope="col">Tier</th><th scope="col">Point cap</th><th scope="col">Points until cap</th><th scope="col">Pool remaining this month</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($tierStatus['tiers'] as $tier): ?>
                            <tr>
                                <th scope="row"><?= htmlspecialchars($tier['label'], ENT_QUOTES, 'UTF-8') ?></th>
                                <td><?= number_format($tier['points_cap']) ?></td>
                                <td><?= number_format($tier['points_remaining']) ?></td>
                                <td><?= number_format($tier['pool_remaining_mmk'], 2) ?> MMK</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer border-secondary border-opacity-25">
                <button type="button" class="btn btn-outline-info rounded-3 px-4" data-return-to-rewards>Back to rewards</button>
            </div>
        </div>
    </div>
</div>
<script>
    (() => {
        const loyaltyModalElement = document.getElementById('loyaltyRewardDetails');
        const tierModalElement = document.getElementById('tierDetailsModal');
        document.querySelectorAll('#loyaltyRewardDetails, #tierDetailsModal').forEach((modal) => {
            document.body.appendChild(modal);
        });

        const initializeModalNavigation = () => {
            if (!loyaltyModalElement || !tierModalElement || typeof bootstrap === 'undefined') return;

            const loyaltyModal = bootstrap.Modal.getOrCreateInstance(loyaltyModalElement);
            const tierModal = bootstrap.Modal.getOrCreateInstance(tierModalElement);

            document.querySelectorAll('[data-open-tier-details]').forEach((button) => {
                button.addEventListener('click', () => {
                    loyaltyModalElement.addEventListener('hidden.bs.modal', () => tierModal.show(), { once: true });
                    loyaltyModal.hide();
                });
            });

            document.querySelector('[data-return-to-rewards]')?.addEventListener('click', () => {
                tierModalElement.addEventListener('hidden.bs.modal', () => loyaltyModal.show(), { once: true });
                tierModal.hide();
            });
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initializeModalNavigation, { once: true });
        } else {
            initializeModalNavigation();
        }
    })();
</script>

<!-- Updated "Track Stations & Book Tokens" Card -->
<div class="customer-live-queue-card p-4 p-md-4 rounded-4 position-relative overflow-hidden mb-4">
    <div class="row align-items-center position-relative" style="z-index:10;">
        <div class="col-lg-10 col-xl-8">
            <span class="status-pill active mb-2 inline-block"><span class="spinner-grow spinner-grow-sm me-1" role="status"></span> Live Queue Verification active</span>
            <h2 class="text-white mb-2 fs-2">Track Stations & Book Tokens</h2>
            <p class="text-secondary mb-3 max-width-md dash-queue-copy">Generate token barcodes, and queue dynamically at localized stations.</p>
            <a href="track_book.php" class="btn btn-info px-4 py-2 fw-bold rounded-3 shadow dash-queue-cta">
                Begin Reservation Hub <i class="fa-solid fa-arrow-right ms-2"></i>
            </a>
        </div>
    </div>
    <div class="position-absolute" style="width: 250px; height: 250px; background: radial-gradient(circle, rgba(56,189,248,0.1) 0%, rgba(0,0,0,0) 70%); right: -50px; bottom: -50px; pointer-events: none;"></div>
</div>
</div>

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

    window.toggleAlertBox = window.toggleAlertBox || toggleAlertBox;
</script>

<!-- Staff account status pop-ups (customer) -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
.mp-sa-overlay{position:fixed;inset:0;z-index:2000;display:none;align-items:center;justify-content:center;padding:16px;background:rgba(0,0,0,.72);-webkit-backdrop-filter:blur(4px);backdrop-filter:blur(4px)}
.mp-sa-overlay.is-open{display:flex;animation:mpSaFade .18s ease-out}
body.mp-sa-locked{pointer-events:none}
body.mp-sa-locked .mp-sa-overlay,
body.mp-sa-locked .mp-sa-overlay *{pointer-events:auto}
.mp-sa-card{width:100%;max-width:380px;box-sizing:border-box;padding:26px 24px 22px;background:#05070a;color:#f1f5f9;border:1px solid rgba(255,255,255,.12);border-radius:22px;box-shadow:0 24px 60px rgba(0,0,0,.6);font-family:inherit;animation:mpSaPop .22s cubic-bezier(.2,.9,.3,1.2)}
.mp-sa-card *{box-sizing:border-box}
.mp-sa-center{text-align:center}
.mp-sa-icon{display:block;font-size:34px;line-height:1;margin:0 0 14px}
.mp-sa-icon.is-red{color:#ef4444}
.mp-sa-icon.is-green{color:#22c55e}
.mp-sa-title{margin:0 0 10px;font-size:19px;font-weight:700;letter-spacing:.02em;text-transform:uppercase;color:#f8fafc}
.mp-sa-text{margin:0 0 20px;font-size:14px;font-weight:600;line-height:1.5;color:#e2e8f0}
.mp-sa-actions{display:flex;gap:10px}
.mp-sa-btn{flex:1 1 0;min-width:0;padding:11px 16px;border-radius:999px;border:1px solid rgba(255,255,255,.1);background:#111418;color:#b6bcc6;font:inherit;font-size:13px;font-weight:600;cursor:pointer;text-align:center;text-decoration:none;transition:background .15s,color .15s,border-color .15s}
.mp-sa-btn:hover{background:#1a1f26;color:#fff;border-color:rgba(255,255,255,.22)}
.mp-sa-btn:focus-visible{outline:2px solid #38bdf8;outline-offset:2px}
.mp-sa-btn.is-ghost{background:transparent}
.mp-sa-btn.is-ghost:hover{background:rgba(255,255,255,.06)}
/* Unavailable (customer only) */
.mp-sa-card.is-unavailable{max-width:420px;padding:20px 20px 18px;border-radius:18px}
.mp-sa-head{display:flex;align-items:center;gap:10px;margin:0 0 14px}
.mp-sa-head i{font-size:18px;color:#f59e0b}
.mp-sa-head h2{margin:0;font-size:16px;font-weight:700;color:#f59e0b}
.mp-sa-card.is-unavailable .mp-sa-text{font-weight:500;margin-bottom:16px}
@keyframes mpSaFade{from{opacity:0}to{opacity:1}}
@keyframes mpSaPop{from{opacity:0;transform:translateY(8px) scale(.96)}to{opacity:1;transform:none}}
@media (max-width:420px){.mp-sa-actions{flex-direction:column-reverse}.mp-sa-card{padding:22px 18px 18px}}
@media (prefers-reduced-motion:reduce){.mp-sa-overlay.is-open,.mp-sa-card{animation:none}}
</style>

<style>
/* ===== Customer dashboard UI refinements (presentation only) ===== */
.customer-stat-card { position: relative; overflow: hidden; border-radius: 18px !important; padding: 20px 22px !important; transition: transform .15s ease, border-color .15s ease; }
.customer-stat-card::after { content: ''; position: absolute; top: 0; right: 0; width: 120px; height: 120px; background: radial-gradient(circle at top right, rgba(56,189,248,.12), transparent 70%); pointer-events: none; }
.customer-stat-card:hover { transform: translateY(-2px); border-color: rgba(125,211,252,.3) !important; }
.dash-label { font-size: 11px; font-weight: 700; letter-spacing: .08em; line-height: 1.4; }
.dash-note { font-size: 12px; line-height: 1.5; }
.customer-stat-card h3 { line-height: 1.1; letter-spacing: -.02em; font-weight: 700; }
.dash-star { font-size: 20px; filter: drop-shadow(0 0 8px rgba(251,191,36,.35)); }
.dash-info-btn { width: 32px; height: 32px; padding: 0; }
.dash-modal { background: #0a162c; border: 1px solid rgba(56,189,248,.3); border-radius: 20px; box-shadow: 0 24px 70px rgba(0,0,0,.55); }
.dash-modal .modal-header { padding: 20px 22px 16px; }
.dash-modal .modal-body { padding: 20px 22px !important; }
.dash-modal .modal-footer { padding: 14px 22px 18px; }
.dash-modal .modal-title { font-weight: 700; }
.dash-modal-panel { background: rgba(10,22,44,.7); border: 1px solid rgba(56,189,248,.18); }
.dash-redeem-tile { background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.06); transition: border-color .15s ease; }
.dash-redeem-tile:hover { border-color: rgba(56,189,248,.35); }
.dash-tile-points { font-size: 11px; }
.dash-modal .table { --bs-table-bg: transparent; }
.dash-modal .table th, .dash-modal .table td { padding: 10px 8px; font-size: 13px; }
.dash-modal .table thead th { font-size: 11px; letter-spacing: .06em; text-transform: uppercase; color: #8b9ab0; font-weight: 700; }
.customer-live-queue-card { background: linear-gradient(135deg, rgba(13,110,253,.12), rgba(56,189,248,.04)); border: 1px solid rgba(56,189,248,.18); }
.dash-queue-copy { font-size: 13px; line-height: 1.6; max-width: 520px; }
.dash-queue-cta { font-size: 13px; font-family: 'Space Grotesk', sans-serif; }
@media (max-width: 575.98px) {
    .customer-stat-card { padding: 18px !important; }
    .customer-stat-card h3 { font-size: 2rem !important; }
    .dash-queue-cta { width: 100%; text-align: center; }
    .dash-modal .modal-header, .dash-modal .modal-body, .dash-modal .modal-footer { padding-left: 16px !important; padding-right: 16px !important; }
}
body.customer-light-mode .customer-stat-card:hover { border-color: rgba(11,94,215,.3) !important; }
body.customer-light-mode .dash-label,
body.customer-light-mode .dash-note,
body.customer-light-mode .dash-queue-copy { color: #526174 !important; }
body.customer-light-mode .dash-modal { background: #ffffff; border-color: rgba(11,94,215,.22); color: #102033; }
body.customer-light-mode .dash-modal-panel { background: rgba(240,246,255,.9); border-color: rgba(11,94,215,.14); }
body.customer-light-mode .dash-redeem-tile { background: rgba(15,23,42,.03); border-color: rgba(15,23,42,.08); }
body.customer-light-mode .customer-live-queue-card { background: linear-gradient(135deg, rgba(11,94,215,.08), rgba(56,189,248,.03)); border-color: rgba(11,94,215,.16); }
</style>

<div class="mp-sa-overlay" id="mpSaDisabled" data-mp-sa="disabled" data-notification-id="<?php echo (int) ($staffStatusPopupNotificationId ?: 0); ?>" role="dialog" aria-modal="true" aria-labelledby="mpSaDisabledTitle" aria-hidden="true">
    <div class="mp-sa-card mp-sa-center">
        <i class="fa-solid fa-user-lock mp-sa-icon is-red" aria-hidden="true"></i>
        <h2 class="mp-sa-title" id="mpSaDisabledTitle">Staff Account Disabled</h2>
        <p class="mp-sa-text">Your Branch Manager has disabled your staff account.</p>
        <div class="mp-sa-actions">
            <button type="button" class="mp-sa-btn" data-mp-sa-close>I Understand</button>
        </div>
    </div>
</div>

<div class="mp-sa-overlay" id="mpSaActivated" data-mp-sa="activated" data-notification-id="<?php echo (int) ($staffStatusPopupNotificationId ?: 0); ?>" role="dialog" aria-modal="true" aria-labelledby="mpSaActivatedTitle" aria-hidden="true">
    <div class="mp-sa-card mp-sa-center">
        <i class="fa-solid fa-user-check mp-sa-icon is-green" aria-hidden="true"></i>
        <h2 class="mp-sa-title" id="mpSaActivatedTitle">Staff Account Activated</h2>
        <p class="mp-sa-text">Your Branch Manager has reactivated your staff account. You can now access your previous staff account again.</p>
        <div class="mp-sa-actions">
            <button type="button" class="mp-sa-btn" data-mp-sa-switch data-href="<?= htmlspecialchars('staff_dashboard.php', ENT_QUOTES, 'UTF-8') ?>" data-notification-id="<?php echo (int) ($staffStatusPopupNotificationId ?: 0); ?>">Switch Account</button>
            <button type="button" class="mp-sa-btn is-ghost" data-mp-sa-close>I Understand</button>
        </div>
    </div>
</div>

<div class="mp-sa-overlay" id="mpSaUnavailable" data-mp-sa="unavailable" data-notification-id="<?php echo (int) ($staffStatusPopupNotificationId ?: 0); ?>" role="dialog" aria-modal="true" aria-labelledby="mpSaUnavailableTitle" aria-hidden="true">
    <div class="mp-sa-card is-unavailable">
        <div class="mp-sa-head">
            <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
            <h2 id="mpSaUnavailableTitle">Staff Account Unavailable</h2>
        </div>
        <p class="mp-sa-text">Your staff account has been disabled. You cannot switch accounts.</p>
        <div class="mp-sa-actions">
            <button type="button" class="mp-sa-btn" data-mp-sa-close>I Understand</button>
        </div>
    </div>
</div>

<script>
(function () {
    if (window.MapPumpStaffStatus) return;
    var openEl = null, lastFocus = null;

    function find(type) { return document.querySelector('[data-mp-sa="' + type + '"]'); }

    function hide() {
        if (!openEl) return;
        var type = openEl.getAttribute('data-mp-sa');
        openEl.classList.remove('is-open');
        openEl.setAttribute('aria-hidden', 'true');
        openEl = null;
        document.body.style.overflow = '';
        document.body.classList.remove('mp-sa-locked');
        if (lastFocus && lastFocus.focus) lastFocus.focus();
        document.dispatchEvent(new CustomEvent('staffstatus:close', { detail: { type: type } }));
    }

    function show(type) {
        var el = find(type);
        if (!el) return false;
        if (openEl) hide();
        lastFocus = document.activeElement;
        openEl = el;
        el.classList.add('is-open');
        el.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        document.body.classList.add('mp-sa-locked');
        var btn = el.querySelector('.mp-sa-btn');
        if (btn) btn.focus();
        return true;
    }

    function acknowledge(type, notificationId) {
        if (!type) return;
        var payload = new FormData();
        payload.append('type', type);
        if (notificationId) payload.append('notification_id', String(notificationId));
        fetch('/php/staff_status_ack.php', {
            method: 'POST',
            credentials: 'same-origin',
            body: payload,
        }).catch(function () {});
    }

    function doServerSwitch() {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = '/php/account_switch.php';

        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'mode';
        input.value = 'business';
        form.appendChild(input);

        document.body.appendChild(form);
        form.submit();
        return true;
    }

    document.addEventListener('click', function (e) {
        var t = e.target;
        if (!t.closest) return;

        var sw = t.closest('[data-mp-sa-switch]');
        if (sw) {
            var type = openEl ? openEl.getAttribute('data-mp-sa') : 'activated';
            var ev = new CustomEvent('staffstatus:switch', { detail: { type: type, href: sw.getAttribute('data-href') }, cancelable: true });
            if (document.dispatchEvent(ev)) {
                var notificationId = sw.getAttribute('data-notification-id');
                if (notificationId) {
                    acknowledge(type, notificationId);
                }
                if (type === 'activated') {
                    doServerSwitch();
                    return;
                }
                if (sw.getAttribute('data-href')) {
                    window.location.href = sw.getAttribute('data-href');
                }
            }
            return;
        }

        if (t.closest('[data-mp-sa-close]')) {
            var ct = openEl ? openEl.getAttribute('data-mp-sa') : '';
            var notificationId = openEl ? (openEl.getAttribute('data-notification-id') || '') : '';
            document.dispatchEvent(new CustomEvent('staffstatus:acknowledge', { detail: { type: ct, notification_id: notificationId } }));
            acknowledge(ct, notificationId || 0);
            hide();
            return;
        }

        // backdrop click is intentionally disabled: only the modal buttons can close it
        return;
    });

    document.addEventListener('keydown', function (e) {
        if (!openEl) return;
        if (e.key === 'Escape') hide();
        if (e.key === 'Tab') {
            var f = openEl.querySelectorAll('button');
            if (!f.length) return;
            var first = f[0], last = f[f.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
    });

    var triggerType = <?php echo json_encode($staffStatusPopupType ?: ''); ?>;
    if (triggerType) {
        setTimeout(function () {
            show(triggerType);
        }, 120);
    }

    window.MapPumpStaffStatus = { show: show, hide: hide };
})();
</script>
<?php include 'share_footer.php'; ?>