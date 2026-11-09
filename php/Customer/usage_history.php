<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../usage_history_schema.php';
require_once __DIR__ . '/../feedback_schema.php';
require_once __DIR__ . '/../loyalty_points.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
$usageRows = [];
$usageHistoryReady = map_pump_usage_history_schema_ready($pdo);
$feedbackReady = map_pump_feedback_schema_ready($pdo);
// Best-effort: add points_earned / points_redeemed / discount_mmk if missing.
loyalty_schema_ready($pdo);

if ($userId > 0 && $usageHistoryReady) {
    try {
        $stmt = $pdo->prepare(
                'SELECT uh.usage_id, uh.booking_id, uh.qr_refrence_code, uh.filled_liters, uh.price_per_litre,
                    uh.staff_user_id,
                    uh.total_cost, uh.status, uh.is_internal, uh.filled_at, s.branch_name AS station_name,
                    s.address AS station_location,
                    o.oil_name, c.car_plate_number,
                    COALESCE(fb.feedback_count, 0) AS feedback_count, fb.latest_rating,
                    COALESCE(uh.points_earned, 0) AS points_earned,
                    COALESCE(uh.points_redeemed, 0) AS points_redeemed,
                    COALESCE(uh.discount_mmk, 0) AS discount_mmk,
                    COALESCE(scr.applied_mmk, 0) AS station_coupon_discount
             FROM usage_history uh
             LEFT JOIN branches s ON s.branch_id = uh.station_id
             LEFT JOIN oil_types o ON o.oil_type_id = uh.oil_type_id
             LEFT JOIN car_license c ON c.car_license_id = uh.car_license_id
             LEFT JOIN (
                 SELECT usage_id, COUNT(*) AS feedback_count, MAX(rating) AS latest_rating
                 FROM feedback
                 WHERE usage_id IS NOT NULL
                 GROUP BY usage_id
             ) fb ON fb.usage_id = uh.usage_id
             LEFT JOIN station_coupon_redemptions scr ON scr.usage_id = uh.usage_id
                         WHERE uh.user_id = ?
                             AND LOWER(uh.status) = \'filled\'
             ORDER BY uh.filled_at DESC, uh.usage_id DESC'
        );
        $stmt->execute([$userId]);
        $usageRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Fallback if loyalty columns are not present yet.
        error_log('Failed to load customer usage history (with loyalty): ' . $e->getMessage());
        try {
            $stmt = $pdo->prepare(
                'SELECT uh.usage_id, uh.booking_id, uh.qr_refrence_code, uh.filled_liters, uh.price_per_litre,
                    uh.staff_user_id,
                    uh.total_cost, uh.status, uh.is_internal, uh.filled_at, s.branch_name AS station_name,
                    s.address AS station_location,
                    o.oil_name, c.car_plate_number,
                    COALESCE(fb.feedback_count, 0) AS feedback_count, fb.latest_rating,
                    COALESCE(scr.applied_mmk, 0) AS station_coupon_discount
                 FROM usage_history uh
                 LEFT JOIN branches s ON s.branch_id = uh.station_id
                 LEFT JOIN oil_types o ON o.oil_type_id = uh.oil_type_id
                 LEFT JOIN car_license c ON c.car_license_id = uh.car_license_id
                 LEFT JOIN (
                     SELECT usage_id, COUNT(*) AS feedback_count, MAX(rating) AS latest_rating
                     FROM feedback
                     WHERE usage_id IS NOT NULL
                     GROUP BY usage_id
                 ) fb ON fb.usage_id = uh.usage_id
                 LEFT JOIN station_coupon_redemptions scr ON scr.usage_id = uh.usage_id
                 WHERE uh.user_id = ?
                   AND LOWER(uh.status) = \'filled\'
                 ORDER BY uh.filled_at DESC, uh.usage_id DESC'
            );
            $stmt->execute([$userId]);
            $usageRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e2) {
            error_log('Failed to load customer usage history: ' . $e2->getMessage());
            $usageRows = [];
        }
    }

}

function customer_usage_fuel_class(?string $oilName): string
{
    $oilName = strtolower((string) $oilName);
    if (str_contains($oilName, '95')) {
        return 'fuel-95';
    }
    if (str_contains($oilName, '92')) {
        return 'fuel-92';
    }
    if (str_contains($oilName, 'diesel')) {
        return 'fuel-diesel';
    }
    return 'fuel-92';
}

function customer_usage_fmt_liters(float $liters): string
{
    return rtrim(rtrim(number_format($liters, 1, '.', ''), '0'), '.');
}

// Totals for the summary bar (match the visible rows on first paint).
$summaryCount = count($usageRows);
$summaryLiters = 0.0;
foreach ($usageRows as $summaryRow) {
    $summaryLiters += (float) ($summaryRow['filled_liters'] ?? 0);
}

include 'share_header.php';
?>
<div class="dashboard-panel-card">
    <?php if (isset($_GET['feedback_sent'])): ?>
        <div class="alert alert-success border-0 mb-4">Feedback sent to staff. Thank you.</div>
    <?php elseif (isset($_GET['feedback_error'])): ?>
        <div class="alert alert-warning border-0 mb-4">Feedback could not be saved. Please choose a filled usage record and try again.</div>
    <?php endif; ?>
    <div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-4">
        <div>
            <h3 class="text-white mb-1">Usage Log Reports</h3>
            <p class="text-secondary mb-0" style="font-size:13px;">Review historical system usage, transaction rates, and verified delivery logs.</p>
        </div>
        <div class="usage-date-picker">
            <label class="text-secondary d-block mb-1" style="font-size:10px; font-weight:700; letter-spacing:.06em; text-transform:uppercase;">Filter by date range</label>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <button type="button" id="usage-range-toggle" class="btn custom-form-field usage-range-toggle" aria-expanded="false" aria-controls="usage-calendar-panel">
                    <i class="bi bi-calendar3 me-2"></i>
                    <span id="usage-range-label">All dates</span>
                    <i class="bi bi-chevron-down ms-2 usage-range-caret"></i>
                </button>
                <button type="button" id="usage-date-clear" class="btn btn-outline-secondary btn-sm rounded-3 px-3" title="Show all dates">All</button>
            </div>

            <div id="usage-calendar-panel" class="usage-calendar-panel d-none" role="dialog" aria-label="Date range calendar">
                <div class="usage-cal-header">
                    <button type="button" id="usage-cal-prev" class="usage-cal-nav" aria-label="Previous month"><i class="bi bi-chevron-left"></i></button>
                    <span id="usage-cal-title" class="usage-cal-title"></span>
                    <button type="button" id="usage-cal-next" class="usage-cal-nav" aria-label="Next month"><i class="bi bi-chevron-right"></i></button>
                </div>
                <div class="usage-cal-weekdays">
                    <span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span>
                </div>
                <div id="usage-cal-grid" class="usage-cal-grid"></div>
                <p class="usage-cal-hint text-secondary mb-0">
                    <span class="usage-cal-dot"></span> Dates with records &nbsp;·&nbsp; Start date then end date &nbsp;·&nbsp; Click twice for only one day
                </p>
            </div>
        </div>
    </div>

    <div class="usage-summary d-flex flex-wrap gap-2 align-items-center mb-3" aria-live="polite">
        <span class="usage-summary-chip"><strong id="usage-sum-count"><?php echo (int) $summaryCount; ?></strong> <span id="usage-sum-count-label"><?php echo $summaryCount === 1 ? 'fill' : 'fills'; ?></span></span>
        <span class="usage-summary-chip"><strong id="usage-sum-liters"><?php echo htmlspecialchars(customer_usage_fmt_liters($summaryLiters), ENT_QUOTES, 'UTF-8'); ?></strong> L total</span>
    </div>

    <div class="table-responsive">
        <table class="table table-dark table-hover align-middle mb-0 usage-table">
            <caption class="visually-hidden">Filled fuel usage records</caption>
            <thead>
                <tr>
                    <th scope="col">Transaction</th>
                    <th scope="col">Date</th>
                    <th scope="col">Station</th>
                    <th scope="col" class="text-end">Volume</th>
                    <th scope="col">Fuel</th>
                    <th scope="col">Loyalty</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-end">Feedback</th>
                </tr>
            </thead>
            <tbody id="usage-log-body" class="text-white">
                <?php if (!$usageHistoryReady): ?>
                    <tr><td colspan="8" class="py-4 text-center text-secondary">Usage history table is not available yet.</td></tr>
                <?php elseif (!$usageRows): ?>
                    <tr><td colspan="8" class="py-4 text-center text-secondary">No filled usage history yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($usageRows as $usage): ?>
                        <?php
                            $filledAt = (string) ($usage['filled_at'] ?? '');
                            $filledTs = $filledAt !== '' ? strtotime($filledAt) : false;
                            $date = $filledTs !== false ? date('Y-m-d', $filledTs) : '';
                            $dateLabel = $filledTs !== false ? date('d M Y', $filledTs) : 'Not recorded';
                            $timeLabel = $filledTs !== false ? date('H:i', $filledTs) : '';
                            $liters = customer_usage_fmt_liters((float) ($usage['filled_liters'] ?? 0));
                            $stationName = trim((string) ($usage['station_name'] ?? '')) ?: 'Unknown station';
                            $stationLocation = trim((string) ($usage['station_location'] ?? ''));
                            $oilName = (string) ($usage['oil_name'] ?? 'Fuel');
                            $status = ucfirst((string) ($usage['status'] ?? 'filled'));
                            $statusLabel = $status === 'Filled' ? 'Delivered' : $status;
                            $feedbackCount = (int) ($usage['feedback_count'] ?? 0);
                            $latestRating = (int) ($usage['latest_rating'] ?? 0);
                            $feedbackModalId = 'feedbackModal' . (int) $usage['usage_id'];
                            $ptsEarned = (int) ($usage['points_earned'] ?? 0);
                            $ptsRedeemed = (int) ($usage['points_redeemed'] ?? 0);
                            $discountMmk = (float) ($usage['discount_mmk'] ?? 0);
                            $loyaltyBits = [];
                            if ($ptsEarned > 0) {
                                $loyaltyBits[] = '+' . $ptsEarned . ' earned';
                            }
                            if ($ptsRedeemed > 0) {
                                $loyaltyBits[] = '−' . $ptsRedeemed . ' used';
                            }
                            if ($discountMmk > 0) {
                                $loyaltyBits[] = number_format($discountMmk, 0) . ' MMK off';
                            }
                            $couponDiscount = (float) ($usage['station_coupon_discount'] ?? 0);
                            if ($couponDiscount > 0) {
                                $loyaltyBits[] = number_format($couponDiscount, 0) . ' MMK station coupon';
                            }
                        ?>
                        <tr class="usage-row" data-date="<?php echo htmlspecialchars($date, ENT_QUOTES, 'UTF-8'); ?>" data-liters="<?php echo htmlspecialchars(number_format((float) ($usage['filled_liters'] ?? 0), 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>">
                            <td>
                                <span class="usage-txn">TXN-<?php echo (int) $usage['usage_id']; ?>-MP</span>
                            </td>
                            <td>
                                <div class="text-white"><?php echo htmlspecialchars($dateLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php if ($timeLabel !== ''): ?>
                                    <div class="usage-sub"><?php echo htmlspecialchars($timeLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="text-white fw-semibold"><?php echo htmlspecialchars($stationName, ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php if ($stationLocation !== ''): ?>
                                    <div class="usage-sub"><?php echo htmlspecialchars($stationLocation, ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <span class="usage-volume"><?php echo htmlspecialchars($liters, ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="usage-unit">L</span>
                            </td>
                            <td>
                                <span class="fuel-tag <?php echo htmlspecialchars(customer_usage_fuel_class($oilName), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($oilName, ENT_QUOTES, 'UTF-8'); ?></span>
                            </td>
                            <td>
                                <?php if ($loyaltyBits !== []): ?>
                                    <div class="d-flex flex-wrap gap-1">
                                        <?php foreach ($loyaltyBits as $bit): ?>
                                            <span class="loyalty-chip"><?php echo htmlspecialchars($bit, ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="usage-sub">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-pill active"><?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                            </td>
                            <td class="text-end">
                                <?php if (!empty($usage['staff_user_id'])): ?>
                                    <button type="button" class="btn btn-outline-info btn-sm rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#<?php echo htmlspecialchars($feedbackModalId, ENT_QUOTES, 'UTF-8'); ?>">
                                        <i class="bi bi-chat-left-heart me-1"></i><?php echo $feedbackCount > 0 ? 'Feedback again' : 'Give feedback'; ?>
                                    </button>
                                <?php else: ?>
                                    <span class="usage-sub">Staff details unavailable</span>
                                <?php endif; ?>
                                <?php if ($feedbackCount > 0): ?>
                                    <div class="usage-sub mt-1"><?php echo $feedbackCount; ?> sent<?php echo $latestRating > 0 ? ' · last ' . $latestRating . '/5' : ''; ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <p id="usage-empty-msg" class="text-secondary text-center py-4 mb-0 d-none" style="font-size:13px;">No usage records for the selected date range.</p>
</div>


<?php if ($usageHistoryReady && $usageRows): ?>
    <?php foreach ($usageRows as $usage): ?>
        <?php
            $feedbackModalId = 'feedbackModal' . (int)$usage['usage_id'];
            $modalStation = trim((string)($usage['station_name'] ?? 'Unknown station'));
            $modalFuel = (string)($usage['oil_name'] ?? 'Fuel');
            $modalLiters = customer_usage_fmt_liters((float) ($usage['filled_liters'] ?? 0));
            $modalPtsEarned = (int) ($usage['points_earned'] ?? 0);
            $modalPtsRedeemed = (int) ($usage['points_redeemed'] ?? 0);
            $modalDiscount = (float) ($usage['discount_mmk'] ?? 0);
            $modalLoyalty = [];
            if ($modalPtsEarned > 0) {
                $modalLoyalty[] = '+' . $modalPtsEarned . ' pts earned';
            }
            if ($modalPtsRedeemed > 0) {
                $modalLoyalty[] = '−' . $modalPtsRedeemed . ' pts used';
            }
            if ($modalDiscount > 0) {
                $modalLoyalty[] = number_format($modalDiscount, 0) . ' MMK off';
            }
            $modalLoyaltyText = $modalLoyalty !== [] ? implode(' · ', $modalLoyalty) : '';
        ?>
        <div class="modal fade" id="<?php echo htmlspecialchars($feedbackModalId, ENT_QUOTES, 'UTF-8'); ?>" tabindex="-1" aria-hidden="true" data-bs-backdrop="true">
            <div class="modal-dialog modal-dialog-centered px-3">
                <div class="modal-content custom-glass-modal text_color">
                    <form action="submit_feedback.php" method="POST">
                        <div class="modal-header border-bottom border-secondary border-opacity-25">
                            <div>
                                <h5 class="modal-title text-white fw-bold">Fuel Fill Feedback</h5>
                                <p class="text-secondary mb-0 small"><?php echo htmlspecialchars($modalStation . ' / ' . $modalFuel . ' / ' . $modalLiters . ' L', ENT_QUOTES, 'UTF-8'); ?></p>
                                <?php if ($modalLoyaltyText !== ''): ?>
                                    <p class="text-warning mb-0 small mt-1"><?php echo htmlspecialchars($modalLoyaltyText, ENT_QUOTES, 'UTF-8'); ?></p>
                                <?php endif; ?>
                            </div>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Skip feedback"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="usage_id" value="<?php echo (int)$usage['usage_id']; ?>">
                            <label class="form-label text-secondary small d-block">Rating</label>
                            <div class="usage-feedback-rating mb-3" role="radiogroup" aria-label="Fuel fill rating">
                                <?php for ($rating = 5; $rating >= 1; $rating--): ?>
                                    <input type="radio" id="rating-<?php echo (int)$usage['usage_id']; ?>-<?php echo $rating; ?>" name="rating" value="<?php echo $rating; ?>">
                                    <label for="rating-<?php echo (int)$usage['usage_id']; ?>-<?php echo $rating; ?>" title="<?php echo $rating; ?> out of 5"><i class="bi bi-star-fill"></i></label>
                                <?php endfor; ?>
                            </div>
                            <label for="feedbackText<?php echo (int)$usage['usage_id']; ?>" class="form-label text-secondary small">Message to staff</label>
                            <textarea id="feedbackText<?php echo (int)$usage['usage_id']; ?>" name="feedback" class="form-control glass-input" rows="4" maxlength="1000" placeholder="Tell staff about this fill, station, service, or queue experience..."></textarea>
                            <p class="text-secondary small mt-2 mb-0">You can skip this now and send feedback from usage history later.</p>
                        </div>
                        <div class="modal-footer border-top border-secondary border-opacity-25">
                            <button type="button" class="btn btn-outline-light rounded-pill px-4" data-bs-dismiss="modal">Skip</button>
                            <button type="submit" class="btn btn-info rounded-pill px-4 fw-semibold"><i class="bi bi-send-fill me-2"></i>Send to staff</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<style>
/* Fix modal non-interactive and backdrop layering bug */
.modal {
    z-index: 1055 !important;
}

.modal-backdrop {
    z-index: 1050 !important;
}

.custom-glass-modal {
    background: var(--glass-bg, rgba(15, 23, 42, 0.85)) !important;
    backdrop-filter: blur(24px) !important;
    -webkit-backdrop-filter: blur(24px) !important;
    border: 1px solid var(--glass-border, rgba(255, 255, 255, 0.1)) !important;
    border-radius: var(--card-radius, 16px) !important;
    pointer-events: auto !important;
}

.glass-input, 
.usage-feedback-rating label {
    pointer-events: auto !important;
    position: relative;
    z-index: 2;
}

/* Rating styles */
.usage-feedback-rating {
    display: inline-flex;
    flex-direction: row-reverse;
    gap: 6px;
}
.usage-feedback-rating input {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}
.usage-feedback-rating label {
    color: rgba(255,255,255,0.28);
    cursor: pointer;
    font-size: 22px;
    transition: color 0.15s ease, transform 0.15s ease;
}
.usage-feedback-rating label:hover,
.usage-feedback-rating label:hover ~ label,
.usage-feedback-rating input:checked ~ label {
    color: #ffc107;
}
.usage-feedback-rating label:hover {
    transform: translateY(-1px);
}

.usage-date-picker {
    position: relative;
    min-width: 220px;
}
.usage-range-toggle {
    display: inline-flex;
    align-items: center;
    min-width: 220px;
    max-width: 280px;
    text-align: left;
    cursor: pointer;
    color: var(--slate-50, #e8f3ff) !important;
    font-size: 13px;
    font-weight: 500;
}
.usage-range-toggle:hover,
.usage-range-toggle[aria-expanded="true"] {
    border-color: rgba(13, 110, 253, 0.45) !important;
}
.usage-range-caret {
    margin-left: auto;
    font-size: 11px;
    opacity: 0.7;
    transition: transform 0.2s ease;
}
.usage-range-toggle[aria-expanded="true"] .usage-range-caret {
    transform: rotate(180deg);
}

.usage-calendar-panel {
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    z-index: 40;
    width: 300px;
    padding: 14px;
    border-radius: 16px;
    background: rgba(10, 22, 44, 0.96);
    border: 1px solid rgba(13, 110, 253, 0.2);
    box-shadow: 0 18px 40px rgba(0, 0, 0, 0.45);
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
}

.usage-cal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
}
.usage-cal-title {
    font-weight: 700;
    font-size: 14px;
    color: #e8f3ff;
}
.usage-cal-nav {
    width: 32px;
    height: 32px;
    border-radius: 10px;
    border: 1px solid rgba(13, 110, 253, 0.18);
    background: rgba(13, 110, 253, 0.06);
    color: #e8f3ff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: background 0.15s ease, border-color 0.15s ease;
}
.usage-cal-nav:hover:not(:disabled) {
    background: rgba(13, 110, 253, 0.16);
    border-color: rgba(13, 110, 253, 0.4);
}
.usage-cal-nav:disabled {
    opacity: 0.35;
    cursor: not-allowed;
}

.usage-cal-weekdays {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 2px;
    margin-bottom: 4px;
    text-align: center;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: #8fa0dd;
}
.usage-cal-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 2px;
}
.usage-cal-day {
    position: relative;
    aspect-ratio: 1;
    border: 0;
    border-radius: 10px;
    background: transparent;
    color: #c9d2e3;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s ease, color 0.15s ease;
}
.usage-cal-day:hover:not(:disabled):not(.is-empty) {
    background: rgba(13, 110, 253, 0.12);
    color: #fff;
}
.usage-cal-day.is-empty {
    cursor: default;
    visibility: hidden;
}
.usage-cal-day.is-disabled {
    color: #4a5568;
    cursor: not-allowed;
    opacity: 0.45;
}
.usage-cal-day.has-records::after {
    content: '';
    position: absolute;
    left: 50%;
    bottom: 4px;
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: #0d6efd;
    transform: translateX(-50%);
    box-shadow: 0 0 6px rgba(13, 110, 253, 0.55);
}
.usage-cal-day.has-records {
    color: #fff;
    font-weight: 700;
}
.usage-cal-day.is-in-range {
    background: rgba(13, 110, 253, 0.14);
    color: #fff;
    border-radius: 0;
}
.usage-cal-day.is-range-start,
.usage-cal-day.is-range-end,
.usage-cal-day.is-selected {
    background: #0d6efd !important;
    color: #fff !important;
    border-radius: 10px;
}
.usage-cal-day.is-range-start.is-in-range {
    border-radius: 10px 0 0 10px;
}
.usage-cal-day.is-range-end.is-in-range {
    border-radius: 0 10px 10px 0;
}
.usage-cal-day.is-range-start.is-range-end {
    border-radius: 10px;
}
.usage-cal-day.is-today:not(.is-selected):not(.is-range-start):not(.is-range-end) {
    box-shadow: inset 0 0 0 1px rgba(13, 110, 253, 0.45);
}
.usage-cal-hint {
    margin-top: 12px;
    font-size: 11px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.usage-cal-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #0d6efd;
    box-shadow: 0 0 6px rgba(13, 110, 253, 0.5);
    flex: 0 0 auto;
}

/* Usage table */
.usage-table {
    width: 100%;
    min-width: 1240px;
}
.usage-summary-chip {
    font-size: 12px;
    padding: 6px 12px;
    border-radius: 999px;
    border: 1px solid rgba(13, 110, 253, 0.25);
    background: rgba(13, 110, 253, 0.08);
    color: #9fc3ff;
}
.usage-summary-chip strong {
    color: #fff;
    font-variant-numeric: tabular-nums;
}
.usage-table thead th {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #8fa0dd;
    background: transparent;
    border-bottom: 1px solid rgba(255, 255, 255, 0.12);
    padding: 0 12px 12px;
    white-space: nowrap;
}
.usage-table thead th:first-child,
.usage-table tbody td:first-child {
    padding-left: 0;
}
.usage-table thead th:last-child,
.usage-table tbody td:last-child {
    padding-right: 0;
}
.usage-table tbody td {
    padding: 14px 12px;
    font-size: 13px;
    font-weight: 500;
    vertical-align: middle;
    border-color: rgba(255, 255, 255, 0.06);
}
.usage-table tbody tr:hover td {
    background: rgba(13, 110, 253, 0.04);
}
.usage-txn {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.02em;
    white-space: nowrap;
}
.usage-sub {
    font-size: 11px;
    color: #8b97ad;
}
.usage-volume {
    font-size: 14px;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
}
.usage-unit {
    font-size: 11px;
    color: #8b97ad;
    margin-left: 2px;
}
.loyalty-chip {
    display: inline-block;
    font-size: 11px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 999px;
    background: rgba(255, 193, 7, 0.12);
    color: #ffc107;
    white-space: nowrap;
}

body.customer-light-mode .usage-summary-chip {
    color: #0b5ed7;
    background: rgba(11, 94, 215, 0.07);
    border-color: rgba(11, 94, 215, 0.2);
}
body.customer-light-mode .usage-summary-chip strong {
    color: #102033;
}
body.customer-light-mode .usage-sub,
body.customer-light-mode .usage-unit {
    color: #64748b;
}
body.customer-light-mode .loyalty-chip {
    color: #b45309;
    background: rgba(245, 158, 11, 0.15);
}
body.customer-light-mode .usage-table thead th {
    color: #475569;
    border-bottom-color: rgba(15, 23, 42, 0.12);
}
body.customer-light-mode .usage-table tbody td {
    border-color: rgba(15, 23, 42, 0.06);
}

body.customer-light-mode .usage-range-toggle {
    color: #102033 !important;
}
body.customer-light-mode .usage-calendar-panel {
    background: rgba(255, 255, 255, 0.98);
    border-color: rgba(11, 94, 215, 0.18);
    box-shadow: 0 18px 40px rgba(15, 23, 42, 0.14);
}
body.customer-light-mode .usage-cal-title,
body.customer-light-mode .usage-cal-nav {
    color: #102033;
}
body.customer-light-mode .usage-cal-nav {
    background: rgba(11, 94, 215, 0.06);
    border-color: rgba(11, 94, 215, 0.16);
}
body.customer-light-mode .usage-cal-day {
    color: #334155;
}
body.customer-light-mode .usage-cal-day.has-records {
    color: #0b5ed7;
}
body.customer-light-mode .usage-cal-day.is-disabled {
    color: #94a3b8;
}
body.customer-light-mode .usage-cal-day.is-in-range {
    background: rgba(11, 94, 215, 0.1);
    color: #0b5ed7;
}
body.customer-light-mode .usage-cal-day.is-range-start,
body.customer-light-mode .usage-cal-day.is-range-end,
body.customer-light-mode .usage-cal-day.is-selected {
    background: #0b5ed7 !important;
    color: #fff !important;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Append modals directly to <body> to avoid stacking context collisions
    const modals = document.querySelectorAll('.modal');
    modals.forEach(function (modal) {
        document.body.appendChild(modal);
    });

    const rows = Array.from(document.querySelectorAll('#usage-log-body tr.usage-row'));
    const emptyMsg = document.getElementById('usage-empty-msg');
    const toggleBtn = document.getElementById('usage-range-toggle');
    const clearBtn = document.getElementById('usage-date-clear');
    const panel = document.getElementById('usage-calendar-panel');
    const grid = document.getElementById('usage-cal-grid');
    const titleEl = document.getElementById('usage-cal-title');
    const prevBtn = document.getElementById('usage-cal-prev');
    const nextBtn = document.getElementById('usage-cal-next');
    const labelEl = document.getElementById('usage-range-label');
    const sumCountEl = document.getElementById('usage-sum-count');
    const sumCountLabelEl = document.getElementById('usage-sum-count-label');
    const sumLitersEl = document.getElementById('usage-sum-liters');

    const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];

    function toISO(d) {
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${day}`;
    }

    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const todayStr = toISO(today);

    const recordDates = new Set(
        rows.map(row => row.getAttribute('data-date')).filter(Boolean)
    );

    let viewYear = today.getFullYear();
    let viewMonth = today.getMonth();
    let rangeStart = null;
    let rangeEnd = null;

    function formatLabel(start, end) {
        if (!start && !end) return 'All dates';
        if (start && !end) return start;
        if (start === end) return start;
        return `${start} → ${end}`;
    }

    function filterRows() {
        let visibleCount = 0;
        let visibleLiters = 0;
        rows.forEach(row => {
            const rowDate = row.getAttribute('data-date');
            let show = true;
            if (rangeStart && rangeEnd) {
                show = rowDate >= rangeStart && rowDate <= rangeEnd;
            } else if (rangeStart && !rangeEnd) {
                show = rowDate === rangeStart;
            }
            row.classList.toggle('d-none', !show);
            if (show) {
                visibleCount++;
                visibleLiters += parseFloat(row.getAttribute('data-liters') || '0') || 0;
            }
        });
        emptyMsg.classList.toggle('d-none', rows.length === 0 || visibleCount > 0);
        labelEl.textContent = formatLabel(rangeStart, rangeEnd);

        if (sumCountEl) {
            sumCountEl.textContent = visibleCount;
            sumCountLabelEl.textContent = visibleCount === 1 ? 'fill' : 'fills';
            sumLitersEl.textContent = visibleLiters.toLocaleString(undefined, { maximumFractionDigits: 1 });
        }
    }

    function renderCalendar() {
        titleEl.textContent = `${monthNames[viewMonth]} ${viewYear}`;
        grid.innerHTML = '';

        const first = new Date(viewYear, viewMonth, 1);
        const startWeekday = first.getDay();
        const daysInMonth = new Date(viewYear, viewMonth + 1, 0).getDate();

        const isCurrentOrFutureMonth =
            viewYear > today.getFullYear() ||
            (viewYear === today.getFullYear() && viewMonth >= today.getMonth());
        nextBtn.disabled = isCurrentOrFutureMonth;

        for (let i = 0; i < startWeekday; i++) {
            const cell = document.createElement('button');
            cell.type = 'button';
            cell.className = 'usage-cal-day is-empty';
            cell.tabIndex = -1;
            cell.disabled = true;
            grid.appendChild(cell);
        }

        for (let day = 1; day <= daysInMonth; day++) {
            const dateObj = new Date(viewYear, viewMonth, day);
            const iso = toISO(dateObj);
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'usage-cal-day';
            btn.textContent = String(day);
            btn.dataset.date = iso;

            const isFuture = iso > todayStr;
            const hasRecords = recordDates.has(iso);
            const isToday = iso === todayStr;

            if (isFuture) {
                btn.classList.add('is-disabled');
                btn.disabled = true;
            }
            if (hasRecords) btn.classList.add('has-records');
            if (isToday) btn.classList.add('is-today');

            if (rangeStart && rangeEnd) {
                if (iso === rangeStart) btn.classList.add('is-range-start', 'is-selected');
                if (iso === rangeEnd) btn.classList.add('is-range-end', 'is-selected');
                if (iso > rangeStart && iso < rangeEnd) btn.classList.add('is-in-range');
                if (iso === rangeStart && iso === rangeEnd) {
                    btn.classList.add('is-range-start', 'is-range-end', 'is-selected');
                }
            } else if (rangeStart && iso === rangeStart) {
                btn.classList.add('is-selected', 'is-range-start', 'is-range-end');
            }

            if (!isFuture) {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    onDayClick(iso);
                });
            }
            grid.appendChild(btn);
        }
    }

    function onDayClick(iso) {
        if (!rangeStart || (rangeStart && rangeEnd)) {
            rangeStart = iso;
            rangeEnd = null;
        } else {
            if (iso < rangeStart) {
                rangeEnd = rangeStart;
                rangeStart = iso;
            } else {
                rangeEnd = iso;
            }
        }
        filterRows();
        renderCalendar();
        if (rangeStart && rangeEnd) {
            closePanel();
        }
    }

    function openPanel() {
        panel.classList.remove('d-none');
        toggleBtn.setAttribute('aria-expanded', 'true');
        renderCalendar();
    }

    function closePanel() {
        panel.classList.add('d-none');
        toggleBtn.setAttribute('aria-expanded', 'false');
    }

    toggleBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        if (panel.classList.contains('d-none')) openPanel();
        else closePanel();
    });

    prevBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        viewMonth -= 1;
        if (viewMonth < 0) {
            viewMonth = 11;
            viewYear -= 1;
        }
        renderCalendar();
    });

    nextBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        if (nextBtn.disabled) return;
        viewMonth += 1;
        if (viewMonth > 11) {
            viewMonth = 0;
            viewYear += 1;
        }
        renderCalendar();
    });

    clearBtn.addEventListener('click', () => {
        rangeStart = null;
        rangeEnd = null;
        filterRows();
        renderCalendar();
        closePanel();
    });

    panel.addEventListener('click', (e) => e.stopPropagation());

    document.addEventListener('click', (e) => {
        if (!panel.classList.contains('d-none') && !panel.contains(e.target) && !toggleBtn.contains(e.target)) {
            closePanel();
        }
    });

    filterRows();
});
</script>

<?php include 'share_footer.php'; ?>