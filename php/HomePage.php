<?php
/* ==========================================================================
   MAP & PUMP — HOMEPAGE
   Company-first: fuel companies and station operators are the paying
   customer, so they lead. Drivers get a compact section further down.
   ========================================================================== */

require_once __DIR__ . '/data/fuel_prices.php';

$page_title       = 'MAP & PUMP | Put Your Stations on the Map Drivers Actually Check';
$page_description = 'MAP & PUMP is the availability layer for fuel retail. Show which of your stations have stock, switch a station off the moment it runs dry, and stop losing drivers to guesswork. Three stations free.';
$active_nav       = 'home';

require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/nav.php';
?>

<!-- ==========================================================================
     HERO — company first
     ========================================================================== -->
<section class="hero-section hero-compact d-flex align-items-center" id="top">
    <div class="container">
        <div class="row align-items-center g-5">

            <div class="col-lg-6 text-center text-lg-start">
                <div class="badge-status mb-4 d-inline-flex align-items-center gap-2" data-reveal>
                    <span class="pulse-dot"></span>
                    <?php if (($PLATFORM_STATS['stations'] ?? 0) > 0): ?>
                        <?php echo (int) $PLATFORM_STATS['stations']; ?>+ stations live
                        <?php if (($PLATFORM_STATS['drivers'] ?? 0) > 0): ?>
                            &middot; <?php echo number_format((int) $PLATFORM_STATS['drivers']); ?>+ registered drivers
                        <?php endif; ?>
                    <?php else: ?>
                        Live station availability from participating fuel companies
                    <?php endif; ?>
                </div>

                <h1 class="hero-title" data-reveal style="--reveal-delay:80ms">
                    Your stations.<br>
                    <span class="text-gradient-accent">On every driver's map.</span>
                </h1>

                <p class="hero-sub mb-4" data-reveal style="--reveal-delay:160ms">
                    When a station runs dry, switch it off in one tap — drivers see it instantly
                    and stop queuing at the wrong forecourt. When you restock, switch it back on
                    and they come to you. Three stations free, forever.
                </p>

                <div class="hero-actions d-sm-flex gap-3 justify-content-center justify-content-lg-start" data-reveal style="--reveal-delay:240ms">
                    <a href="/php/Company/register.php" class="btn btn-primary btn-lg px-4 py-3 mb-3 mb-sm-0 w-100 w-sm-auto">
                        <i class="fa-solid fa-building-circle-check me-2"></i>Register your company
                    </a>
                    <a href="/php/link_button.php" class="btn btn-outline-light btn-lg px-4 py-3 mb-3 mb-sm-0 w-100 w-sm-auto">
                        Are you Driver?
                    </a>
                </div>

                <p class="small text-dim mt-3 mb-0" data-reveal style="--reveal-delay:280ms">
                    3 stations free &middot; No hardware to install &middot; Set up in an afternoon
                </p>

                <div class="hero-trust" data-reveal style="--reveal-delay:320ms">
                    <div>
                        <div class="trust-num" data-count="<?php echo max(1, (int) ($PLATFORM_STATS['stations'] ?? 0)); ?>" data-suffix="+">0</div>
                        <div class="trust-label">Stations listed</div>
                    </div>
                    <div>
                        <div class="trust-num" data-count="<?php echo max(1, (int) ($PLATFORM_STATS['drivers'] ?? 0)); ?>" data-suffix="+">0</div>
                        <div class="trust-label">Registered drivers</div>
                    </div>
                    <div>
                        <div class="trust-num" data-count="<?php echo count($FUEL_GRADES); ?>" data-suffix="">0</div>
                        <div class="trust-label">Fuel grades tracked</div>
                    </div>
                </div>
            </div>

            <!-- Driver-facing listing mockup -->
            <div class="col-lg-6">
                <div class="listing-stage" data-reveal style="--reveal-delay:200ms">

                    <div class="map-panel">
                        <span class="map-road road-1"></span>
                        <span class="map-road road-2"></span>
                        <span class="map-road road-3"></span>
                        <span class="map-radar"></span>

                        <div class="map-customer-card">
                            <div class="customer-card-top">
                                <span><i class="fa-solid fa-location-crosshairs"></i> Driver match</span>
                                <strong>2.4 km</strong>
                            </div>
                            <div class="customer-card-station">TharKayTa Station</div>
                            <div class="customer-card-meta">92 available &middot; token slot open</div>
                            <div class="customer-card-actions">
                                <span><i class="fa-solid fa-ticket"></i> Book token</span>
                                <span><i class="fa-solid fa-bell"></i> Restock alert</span>
                            </div>
                        </div>

                        <div class="map-pin pin-1">
                            <span class="pin-icon pin-ok"><i class="fa-solid fa-gas-pump"></i></span>
                            TharKayTa &middot; In stock
                        </div>
                        <div class="map-pin pin-2">
                            <span class="pin-icon pin-low"><i class="fa-solid fa-gas-pump"></i></span>
                            Hlaing &middot; Low
                        </div>
                        <div class="map-pin pin-3">
                            <span class="pin-icon pin-ok"><i class="fa-solid fa-gas-pump"></i></span>
                            Insein &middot; In stock
                        </div>
                        <div class="map-pin pin-4 is-disabled">
                            <span class="pin-icon pin-out"><i class="fa-solid fa-power-off"></i></span>
                            Dagon &middot; Switched off
                        </div>

                        <div class="map-legend">
                            <span><i class="fa-solid fa-circle" style="color:#10b981;font-size:.5rem"></i> In stock</span>
                            <span><i class="fa-solid fa-circle" style="color:#f59e0b;font-size:.5rem"></i> Running low</span>
                            <span><i class="fa-solid fa-circle" style="color:#64748b;font-size:.5rem"></i> Disabled by operator</span>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</section>

<!-- ==========================================================================
     LIVE TICKER
     ========================================================================== -->
<div class="ticker">
    <div class="ticker-track">
        <?php if (!empty($LIVE_TICKER_ITEMS)): ?>
            <?php foreach ($LIVE_TICKER_ITEMS as $item): ?>
                <span class="ticker-item"><span class="dot <?php echo htmlspecialchars($item['class']); ?>"></span> <?php echo htmlspecialchars($item['text']); ?></span>
            <?php endforeach; ?>
            <?php foreach ($LIVE_TICKER_ITEMS as $item): ?>
                <span class="ticker-item" aria-hidden="true"><span class="dot <?php echo htmlspecialchars($item['class']); ?>"></span> <?php echo htmlspecialchars($item['text']); ?></span>
            <?php endforeach; ?>
        <?php else: ?>
            <span class="ticker-item"><span class="dot dot-out"></span> Live availability updates appear here once branch managers publish stock</span>
            <span class="ticker-item" aria-hidden="true"><span class="dot dot-out"></span> Live availability updates appear here once branch managers publish stock</span>
        <?php endif; ?>
    </div>
</div>

<!-- ==========================================================================
     WHY COMPANIES USE IT
     ========================================================================== -->
<section class="section-pad">
    <div class="container">
        <div class="text-center mb-4" data-reveal>
            <span class="eyebrow">For fuel companies</span>
            <h2 class="section-title">Empty forecourts and angry queues<br class="d-none d-lg-block"> are both information problems</h2>
        </div>

        <div class="row g-4">
            <div class="col-md-6 col-lg-4" data-reveal>
                <div class="feature-card js-spotlight">
                    <div class="icon-shape mb-4"><i class="fa-solid fa-user-slash"></i></div>
                    <h3>Stop losing drivers</h3>
                    <p>A driver who finds your station empty doesn't come back — they go to whoever they can confirm has fuel. Being visible and honest on the map keeps them yours.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-4" data-reveal style="--reveal-delay:90ms">
                <div class="feature-card js-spotlight">
                    <div class="icon-shape tone-amber mb-4"><i class="fa-solid fa-toggle-on"></i></div>
                    <h3>One toggle per station</h3>
                    <p>Out of stock? Disable the station and it drops off the map immediately. No crowd forming outside, no staff arguing with drivers who drove across town.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-4" data-reveal style="--reveal-delay:180ms">
                <div class="feature-card js-spotlight">
                    <div class="icon-shape tone-green mb-4"><i class="fa-solid fa-chart-line"></i></div>
                    <h3>See your real demand</h3>
                    <p>Every search, view and token tells you where demand is building. Plan deliveries against actual driver intent instead of last month's guesswork.</p>
                </div>
            </div>
        </div>

        <div id="how" class="pt-5 mt-4">
            <div class="text-center mb-4" data-reveal>
                <span class="eyebrow justify-content-center d-inline-flex">How it works</span>
                <h2 class="section-title-sm">Live on the map in four steps</h2>
            </div>

        <div class="row g-4 steps-row">
            <div class="col-md-6 col-lg-3" data-reveal>
                <div class="step-card">
                    <div class="step-num">01</div>
                    <h3>Register the company</h3>
                    <p>One account for your whole business. Registration fee covers your first three stations.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-3" data-reveal style="--reveal-delay:90ms">
                <div class="step-card">
                    <div class="step-num">02</div>
                    <h3>Add your stations</h3>
                    <p>Drop each branch on the map, set the grades it carries, and invite your staff.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-3" data-reveal style="--reveal-delay:180ms">
                <div class="step-card">
                    <div class="step-num">03</div>
                    <h3>Toggle availability</h3>
                    <p>Staff flip stock on or off per grade as tankers arrive and tanks empty. Drivers see it in seconds.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-3" data-reveal style="--reveal-delay:270ms">
                <div class="step-card">
                    <div class="step-num">04</div>
                    <h3>Take booked arrivals</h3>
                    <p>Drivers book token slots, scan at your gate, and your forecourt moves in an orderly line.</p>
                </div>
            </div>
        </div>
        </div>
    </div>
</section>

<!-- ==========================================================================
     PRICING
     ========================================================================== -->
<section id="pricing" class="section-pad pt-0">
    <div class="container">
        <div class="text-center mb-4" data-reveal>
            <span class="eyebrow">Pricing</span>
            <h2 class="section-title">Three stations free. Scale when you're ready.</h2>
            <p class="section-lead mx-auto">
                Your registration fee covers your first three stations on the map. Need more visible?
                Add them per station, per month — and the more you add, the cheaper each one gets.
            </p>
        </div>

        <div class="row g-4 align-items-stretch mb-4">
            <div class="col-lg-5" data-reveal>
                <div class="plan-card h-100">
                    <div class="plan-tag">Included with registration</div>
                    <h3 class="plan-name">Starter</h3>
                    <div class="plan-price">3 stations <span>free</span></div>
                    <p class="plan-note">One-time registration fee, no monthly charge for these three.</p>
                    <ul class="plan-list">
                        <li><i class="fa-solid fa-check"></i> 3 stations live on the map</li>
                        <li><i class="fa-solid fa-check"></i> Enable / disable per station</li>
                        <li><i class="fa-solid fa-check"></i> Per-grade stock status</li>
                        <li><i class="fa-solid fa-check"></i> Driver token bookings</li>
                    </ul>
                    <a href="/php/Company/register.php" class="btn btn-ghost w-100 py-3 mt-auto">Register your company</a>
                </div>
            </div>

            <div class="col-lg-7" data-reveal style="--reveal-delay:110ms">
                <div class="plan-card plan-card-featured h-100">
                    <div class="plan-tag plan-tag-accent">Most companies land here</div>
                    <h3 class="plan-name">Growth</h3>
                    <div class="plan-price">2,000 <span>MMK / station / month</span></div>
                    <p class="plan-note">For every station beyond your free three. Volume and multi-month discounts applied automatically.</p>

                    <div class="discount-grid">
                        <div class="discount-cell">
                            <div class="discount-head">Volume</div>
                            <div class="discount-row"><span>1–7 paid</span><strong>Standard</strong></div>
                            <div class="discount-row"><span>8–22 paid</span><strong>10% off</strong></div>
                            <div class="discount-row"><span>23+ paid</span><strong>20% off</strong></div>
                        </div>
                        <div class="discount-cell">
                            <div class="discount-head">Commitment</div>
                            <div class="discount-row"><span>Monthly</span><strong>Standard</strong></div>
                            <div class="discount-row"><span>3 months</span><strong>5% off</strong></div>
                            <div class="discount-row"><span>6 months</span><strong>10% off</strong></div>
                            <div class="discount-row"><span>12 months</span><strong>20% off</strong></div>
                        </div>
                    </div>

                    <p class="small text-dim mb-0">Discounts stack — 30 stations on a 12-month term works out well under 1,500 MMK each.</p>
                </div>
            </div>
        </div>

        <!-- Calculator -->
        <div class="calc-wrap" id="calc" data-free="3" data-rate="2000" data-reveal>
            <div class="row g-4 align-items-center">
                <div class="col-lg-7">
                    <span class="eyebrow">Estimate</span>
                    <h3 class="calc-title">What would your company pay?</h3>

                    <label class="calc-label" for="calc-range">
                        Stations you want visible: <strong id="calc-count">6</strong>
                    </label>
                    <input type="range" id="calc-range" class="calc-range" min="1" max="60" value="6" step="1">
                    <div class="calc-scale"><span>1</span><span>30</span><span>60</span></div>

                    <div class="calc-label mt-4 mb-2">Commitment</div>
                    <div class="calc-months" id="calc-months">
                        <button type="button" data-months="1" class="is-active">Monthly</button>
                        <button type="button" data-months="3">3 months</button>
                        <button type="button" data-months="6">6 months</button>
                        <button type="button" data-months="12">12 months</button>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="calc-result">
                        <div class="calc-line"><span>Free stations</span><strong>3</strong></div>
                        <div class="calc-line"><span>Paid stations</span><strong id="calc-paid">3</strong></div>
                        <div class="calc-line"><span>Base cost</span><strong id="calc-base">6,000 MMK</strong></div>
                        <div class="calc-line"><span>Volume discount</span><strong id="calc-vol">—</strong></div>
                        <div class="calc-line"><span>Term discount</span><strong id="calc-dur">—</strong></div>
                        <div class="calc-total">
                            <span>You pay</span>
                            <strong id="calc-total">6,000 MMK</strong>
                        </div>
                        <div class="calc-per" id="calc-per">2,000 MMK / station / month</div>
                        <p class="calc-foot" id="calc-note">Billed monthly.</p>
                        <a href="/php/Company/register.php" class="btn btn-primary w-100 py-3">Get started free</a>
                    </div>
                </div>
            </div>
            <p class="small text-dim mt-4 mb-0">
                <i class="fa-solid fa-circle-info me-2"></i>Indicative pricing. Final rates confirmed at registration.
            </p>
        </div>
    </div>
</section>

<!-- ==========================================================================
     NEWS SNAPSHOT — links out to News.php
     ========================================================================== -->
<section class="section-pad pt-0">
    <div class="container">
        <div class="d-lg-flex justify-content-between align-items-end mb-4 gap-4" data-reveal>
            <div>
                <span class="eyebrow">Market news</span>
                <h2 class="section-title mb-2">Today's average fuel prices</h2>
                <p class="section-lead mb-0">National average per litre &middot; updated <?php echo htmlspecialchars($PRICE_UPDATED_AT); ?></p>
            </div>
            <a href="/php/News.php" class="btn btn-ghost px-4 py-2 mt-3 mt-lg-0 flex-shrink-0">
                All prices &amp; news <i class="fa-solid fa-arrow-right ms-2 small"></i>
            </a>
        </div>

        <?php if (empty($FUEL_GRADES)): ?>
            <div class="alert alert-secondary border-0" data-reveal>
                No public fuel prices are published yet. Branch managers must set station prices before averages appear here.
            </div>
        <?php else: ?>
        <div class="row g-3">
            <?php foreach ($FUEL_GRADES as $i => $grade): $t = mp_trend($grade['avg'], $grade['prev']); ?>
                <div class="col-6 col-lg-3" data-reveal style="--reveal-delay:<?php echo $i * 80; ?>ms">
                    <div class="price-card">
                        <div class="price-head">
                            <i class="fa-solid <?php echo $grade['icon']; ?>"></i>
                            <span><?php echo htmlspecialchars($grade['label']); ?></span>
                        </div>
                        <div class="price-value"><?php echo mp_money($grade['avg']); ?><small>MMK</small></div>
                        <div class="trend-chip <?php echo $t['class']; ?>">
                            <i class="fa-solid <?php echo $t['icon']; ?>"></i> <?php echo $t['delta']; ?>
                            <span class="trend-period">vs last week</span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>

<hr class="divider-glow">

<!-- ==========================================================================
     FOR DRIVERS — compact, secondary
     ========================================================================== -->
<section id="drivers" class="section-pad">
    <div class="container">
        <div class="row g-4 align-items-center">

            <div class="col-lg-5 order-lg-2" data-reveal>
                <span class="eyebrow">For drivers</span>
                <h2 class="section-title-sm">Never queue for fuel again</h2>
                <p class="section-lead">
                    Check which stations actually have your grade, book a token slot,
                    and arrive when your pump is free. Free, and always will be.
                </p>

                <ul class="check-list">
                    <li><i class="fa-solid fa-circle-check"></i><span><strong>Live stock map</strong> — see what's available before you leave home.</span></li>
                    <li><i class="fa-solid fa-circle-check"></i><span><strong>Digital tokens</strong> — hold your place in line without sitting in it.</span></li>
                    <li><i class="fa-solid fa-circle-check"></i><span><strong>Restock alerts</strong> — get pinged the moment your station refills.</span></li>
                </ul>

                <div class="d-sm-flex gap-3 mt-4">
                    <a href="/php/Login/login.php" class="btn btn-primary btn-lg px-4 py-3 mb-3 mb-sm-0 w-100 w-sm-auto">
                        <i class="fa-solid fa-location-crosshairs me-2"></i>Find fuel near me
                    </a>
                </div>
            </div>

            <div class="col-lg-7 order-lg-1">
                <div class="phone-stage" data-reveal style="--reveal-delay:120ms">
                    <div class="float-chip chip-a">
                        <i class="fa-solid fa-bolt" style="color:#fbbf24"></i> Token confirmed in 4s
                    </div>
                    <div class="float-chip chip-b">
                        <i class="fa-solid fa-gas-pump" style="color:#34d399"></i> 92 Octane restocked
                    </div>

                    <div class="phone-container p-4 expanded-phone">
                        <div>
                            <div class="phone-notch"></div>

                            <div class="d-flex justify-content-between align-items-center small mb-4">
                                <span class="fw-semibold text-light">MAP &amp; PUMP</span>
                                <span class="d-inline-flex align-items-center gap-2 text-danger fw-bold">
                                    <span class="pulse-dot" style="background:#f43f5e"></span> LIVE
                                </span>
                            </div>

                            <div class="card p-3 mb-3 text-start">
                                <div class="d-flex justify-content-between align-items-start mb-1">
                                    <h5 class="h6 mb-0 text-light">TharKayTa Station</h5>
                                    <span class="badge rounded-pill" style="background:rgba(16,185,129,.15);color:#34d399;font-size:.65rem">OPEN</span>
                                </div>
                                <p class="small text-secondary mb-3">
                                    <i class="fa-solid fa-location-dot me-1"></i> TharKayTa Township &middot; 2.4 km
                                </p>
                                <div class="d-flex justify-content-between small text-light mb-2">
                                    <span>Octane 92</span>
                                    <span class="text-success fw-bold">Available</span>
                                </div>
                                <div class="fuel-bar mb-3"><span></span></div>
                                <div class="d-flex justify-content-between small text-light mb-2">
                                    <span>Diesel</span>
                                    <span class="fw-bold" style="color:#fbbf24">Running low</span>
                                </div>
                                <div class="fuel-bar2 is-low"><span></span></div>
                            </div>

                            <div class="card bg-gradient-token p-3 d-flex flex-row align-items-center justify-content-between token-qr-card">
                                <div class="text-start">
                                    <span class="small text-info fw-bold tracking-wider d-block">DIGITAL TOKEN</span>
                                    <div class="small text-secondary">#MP-4192 &middot; arrive by <strong class="text-white">11:52 AM</strong></div>
                                </div>
                                <div class="qr-wrapper qr-sm"><i class="fa-solid fa-qrcode"></i></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</section>

<!-- ==========================================================================
     TWO QUOTES — one operator, one driver
     ========================================================================== -->
<section class="section-pad pt-0">
    <div class="container">
        <div class="row g-4">
            <div class="col-lg-6" data-reveal>
                <div class="quote-card">
                    <div class="stars"><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i></div>
                    <blockquote>"Before this, every dry day meant a hundred drivers arriving to find nothing and taking it out on my staff. Now I switch the branch off and the crowd never forms. The traffic chaos dropped almost overnight."</blockquote>
                    <div class="quote-person">
                        <span class="avatar av-3">TH</span>
                        <span>
                            <span class="name d-block">Thiha Aung</span>
                            <span class="role">Operations lead &middot; 3 branches</span>
                        </span>
                    </div>
                </div>
            </div>

            <div class="col-lg-6" data-reveal style="--reveal-delay:110ms">
                <div class="quote-card">
                    <div class="stars"><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i></div>
                    <blockquote>"I used to leave the house at 5am and still lose two hours. Now I check the map, book a slot the night before, and I'm filled up before work starts."</blockquote>
                    <div class="quote-person">
                        <span class="avatar">KZ</span>
                        <span>
                            <span class="name d-block">Ko Zaw</span>
                            <span class="role">Ride-hailing driver &middot; Yangon</span>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ==========================================================================
     FAQ — operator leaning
     ========================================================================== -->
<section id="faq" class="section-pad pt-0">
    <div class="container">
        <div class="row g-5">
            <div class="col-lg-4" data-reveal>
                <span class="eyebrow">FAQ</span>
                <h2 class="section-title">Straight answers</h2>
                <p class="section-lead">Still stuck? We answer every day between 8am and 8pm.</p>
            </div>

            <div class="col-lg-8" data-reveal style="--reveal-delay:120ms">
                <div class="accordion faq-accordion" id="faqAccordion">

                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
                                What exactly is free, and what costs money?
                            </button>
                        </h2>
                        <div id="faq1" class="accordion-collapse collapse show" data-bs-parent="#faqAccordion">
                            <div class="accordion-body">
                                Your company registration fee covers your first three stations on the map with the full feature set. Beyond that, each additional station is 2,000 MMK per month, with volume and multi-month discounts. It's always free for drivers.
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
                                Do we need special equipment at the station?
                            </button>
                        </h2>
                        <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                            <div class="accordion-body">
                                No. Any phone your forecourt staff already own is enough — they toggle stock status and scan driver tokens from the browser. No terminals, no installation, no integration with your pumps required.
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
                                Who controls whether our station shows as available?
                            </button>
                        </h2>
                        <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                            <div class="accordion-body">
                                You do. Only your company account and the staff you authorise can enable or disable a station or change its stock status. Driver reports are shown to you as feedback, but they never overwrite what you set.
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq4">
                                Can we add or remove stations mid-term?
                            </button>
                        </h2>
                        <div id="faq4" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                            <div class="accordion-body">
                                Yes. Add a station any time and it's prorated for the rest of the period. Remove one and the slot frees up at the end of the current cycle — useful for seasonal or temporarily closed branches.
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>
</section>

<!-- ==========================================================================
     CLOSING CTA
     ========================================================================== -->
<section class="pb-5">
    <div class="container">
        <div class="bg-gradient-business text-center px-3 py-4 px-md-5" data-reveal>
            <div class="max-width-md mx-auto position-relative py-2">
                <span class="eyebrow justify-content-center d-inline-flex">Get started</span>
                <h2 class="section-title">Put your first three stations<br class="d-none d-md-block"> on the map for free</h2>
                <p class="text-light opacity-75 mb-4 px-md-4">
                    Set up takes an afternoon. No hardware, no integration, no long contract to sign.
                </p>

                <div class="b2b-perks">
                    <span class="perk-pill"><i class="fa-solid fa-gauge-high"></i> Branch dashboard</span>
                    <span class="perk-pill"><i class="fa-solid fa-qrcode"></i> Gate-side token scanning</span>
                    <span class="perk-pill"><i class="fa-solid fa-chart-line"></i> Demand data</span>
                    <span class="perk-pill"><i class="fa-solid fa-users-gear"></i> Staff controls</span>
                </div>

                <div class="d-sm-flex gap-3 justify-content-center">
                    <a href="/php/Company/register.php" class="btn btn-success btn-lg px-5 py-3 fw-bold mb-3 mb-sm-0 w-100 w-sm-auto">Register your company</a>
                    <a href="/php/News.php" class="btn btn-outline-light btn-lg px-4 py-3 w-100 w-sm-auto">Check today's prices</a>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
