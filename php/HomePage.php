<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#05070e">
    <title>MAP &amp; PUMP | Skip the Fuel Queue. Book Your Pump.</title>
    <meta name="description" content="Find the nearest station with fuel in stock, see live availability, and lock a digital token so you arrive exactly when your pump is ready. No queues, no guessing, no black market.">

    <!-- Bootstrap 5.3.3 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome 6.5.1 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- Typography -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <!-- Custom Theme -->
    <link rel="stylesheet" href="/css/HomePage.css">

    <!-- Browser Tab Logo (Favicon) -->
    <link rel="icon" type="image/png" href="/images/Logo.png">
</head>
<body>

<!-- Ambient background layer + UI chrome -->
<div class="mp-backdrop"></div>
<div id="scroll-progress"></div>
<div id="mouse-follower"></div>

<!-- ==========================================================================
     NAVIGATION
     ========================================================================== -->
<nav class="navbar navbar-expand-lg navbar-dark fixed-top main-navbar py-3">
    <div class="container">
        <a class="navbar-brand logo-text d-flex align-items-center gap-2 fs-4 fw-bold" href="#top">
            <img src="/images/Logo.png" alt="MAP &amp; PUMP Logo" class="navbar-logo-img">
            MAP &amp; PUMP
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav mx-auto gap-lg-2 my-2 my-lg-0 text-center">
                <li class="nav-item"><a class="nav-link px-3" href="#how">How it works</a></li>
                <li class="nav-item"><a class="nav-link px-3" href="#features">Features</a></li>
                <li class="nav-item"><a class="nav-link px-3" href="#map">Live map</a></li>
                <li class="nav-item"><a class="nav-link px-3" href="#about">For stations</a></li>
                <li class="nav-item"><a class="nav-link px-3" href="#faq">FAQ</a></li>
            </ul>

            <div class="d-flex justify-content-center mt-3 mt-lg-0">
                <a class="btn btn-primary px-4 py-2 fw-semibold" href="/php/link_button.php">
                    Sign In <i class="fa-solid fa-arrow-right ms-1 small"></i>
                </a>
            </div>
        </div>
    </div>
</nav>

<!-- ==========================================================================
     HERO
     ========================================================================== -->
<section class="hero-section d-flex align-items-center" id="top">
    <div class="container">
        <div class="row align-items-center g-5">

            <!-- Copy -->
            <div class="col-lg-7 text-center text-lg-start">
                <div class="badge-status mb-4 d-inline-flex align-items-center gap-2" data-reveal>
                    <span class="pulse-dot"></span> Live tracking across 340+ stations
                </div>

                <h1 class="hero-title" data-reveal style="--reveal-delay:80ms">
                    Stop queuing for fuel.<br>
                    <span class="text-gradient-accent">Book the pump instead.</span>
                </h1>

                <p class="hero-sub mb-4" data-reveal style="--reveal-delay:160ms">
                    See which stations actually have fuel right now, reserve a digital token in seconds,
                    and roll up exactly when your pump is free. No idling in the heat. No wasted trips.
                    No dealing with the black market.
                </p>

                <div class="d-sm-flex gap-3 justify-content-center justify-content-lg-start" data-reveal style="--reveal-delay:240ms">
                    <a href="/php/Customer/track_book.php" class="btn btn-primary btn-lg px-4 py-3 mb-3 mb-sm-0 w-100 w-sm-auto">
                        <i class="fa-solid fa-location-crosshairs me-2"></i>Find fuel near me
                    </a>
                    <a href="#how" class="btn btn-outline-light btn-lg px-4 py-3 w-100 w-sm-auto">
                        <i class="fa-solid fa-play me-2 small"></i>See how it works
                    </a>
                </div>

                <div class="hero-trust" data-reveal style="--reveal-delay:320ms">
                    <div>
                        <div class="trust-num" data-count="52000" data-suffix="+">0</div>
                        <div class="trust-label">Drivers served</div>
                    </div>
                    <div>
                        <div class="trust-num" data-count="41" data-suffix=" min">0</div>
                        <div class="trust-label">Avg. wait saved</div>
                    </div>
                    <div>
                        <div class="trust-num" data-count="340" data-suffix="+">0</div>
                        <div class="trust-label">Stations mapped</div>
                    </div>
                </div>
            </div>

            <!-- Device mockup -->
            <div class="col-lg-5">
                <div class="phone-stage" data-reveal style="--reveal-delay:200ms">

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

                            <!-- Station card -->
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
                                <div class="fuel-bar is-low"><span></span></div>
                            </div>

                            <!-- Token card -->
                            <div class="card bg-gradient-token p-4 text-center token-qr-card">
                                <span class="small text-info fw-bold tracking-wider d-block mb-3">YOUR DIGITAL TOKEN</span>

                                <div class="qr-wrapper mb-3">
                                    <i class="fa-solid fa-qrcode"></i>
                                </div>

                                <div class="small text-secondary mb-1">Token <strong class="text-white">#MP-4192</strong></div>
                                <hr class="border-secondary my-3">
                                <div class="text-center small text-secondary">
                                    Arrive by <strong class="text-white">11:52 AM</strong>
                                </div>
                            </div>
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
        <!-- duplicated set for seamless loop -->
        <span class="ticker-item"><span class="dot dot-ok"></span> TharKayTa &mdash; 92 Octane in stock</span>
        <span class="ticker-item"><span class="dot dot-low"></span> Hlaing &mdash; Diesel running low</span>
        <span class="ticker-item"><span class="dot dot-ok"></span> Insein &mdash; 95 Octane in stock</span>
        <span class="ticker-item"><span class="dot dot-out"></span> Dagon &mdash; Out of stock</span>
        <span class="ticker-item"><span class="dot dot-ok"></span> Mayangone &mdash; Diesel in stock</span>
        <span class="ticker-item"><span class="dot dot-low"></span> Tamwe &mdash; 92 Octane low</span>
        <span class="ticker-item"><span class="dot dot-ok"></span> South Okkalapa &mdash; Restocked 9:14 AM</span>
        <span class="ticker-item"><span class="dot dot-ok"></span> Bahan &mdash; 95 Octane in stock</span>

        <span class="ticker-item" aria-hidden="true"><span class="dot dot-ok"></span> TharKayTa &mdash; 92 Octane in stock</span>
        <span class="ticker-item" aria-hidden="true"><span class="dot dot-low"></span> Hlaing &mdash; Diesel running low</span>
        <span class="ticker-item" aria-hidden="true"><span class="dot dot-ok"></span> Insein &mdash; 95 Octane in stock</span>
        <span class="ticker-item" aria-hidden="true"><span class="dot dot-out"></span> Dagon &mdash; Out of stock</span>
        <span class="ticker-item" aria-hidden="true"><span class="dot dot-ok"></span> Mayangone &mdash; Diesel in stock</span>
        <span class="ticker-item" aria-hidden="true"><span class="dot dot-low"></span> Tamwe &mdash; 92 Octane low</span>
        <span class="ticker-item" aria-hidden="true"><span class="dot dot-ok"></span> South Okkalapa &mdash; Restocked 9:14 AM</span>
        <span class="ticker-item" aria-hidden="true"><span class="dot dot-ok"></span> Bahan &mdash; 95 Octane in stock</span>
    </div>
</div>

<!-- ==========================================================================
     STATS
     ========================================================================== -->
<section class="section-pad">
    <div class="container">
        <div class="row g-4">
            <div class="col-6 col-lg-3" data-reveal>
                <div class="stat-card">
                    <div class="stat-num" data-count="41" data-suffix=" min">0</div>
                    <div class="stat-label">Average queue time removed from every fill-up</div>
                </div>
            </div>
            <div class="col-6 col-lg-3" data-reveal style="--reveal-delay:90ms">
                <div class="stat-card">
                    <div class="stat-num" data-count="340" data-suffix="+">0</div>
                    <div class="stat-label">Stations broadcasting live stock levels</div>
                </div>
            </div>
            <div class="col-6 col-lg-3" data-reveal style="--reveal-delay:180ms">
                <div class="stat-card">
                    <div class="stat-num" data-count="98" data-suffix="%">0</div>
                    <div class="stat-label">Tokens honoured on arrival, no re-queuing</div>
                </div>
            </div>
            <div class="col-6 col-lg-3" data-reveal style="--reveal-delay:270ms">
                <div class="stat-card">
                    <div class="stat-num" data-count="1200" data-suffix="+">0</div>
                    <div class="stat-label">Price-gouging reports reviewed and acted on</div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ==========================================================================
     HOW IT WORKS
     ========================================================================== -->
<section id="how" class="section-pad">
    <div class="container">
        <div class="text-center mb-5" data-reveal>
            <span class="eyebrow">How it works</span>
            <h2 class="section-title">Three taps between you and a full tank</h2>
            <p class="section-lead mx-auto">No phone calls, no rumours, no driving across town to find an empty station.</p>
        </div>

        <div class="row g-4 steps-row">
            <div class="col-lg-4" data-reveal>
                <div class="step-card">
                    <div class="step-num">01</div>
                    <h3>Check the live map</h3>
                    <p>Open MAP &amp; PUMP and see every nearby station colour-coded by real stock — verified by the station itself, updated by the minute.</p>
                </div>
            </div>
            <div class="col-lg-4" data-reveal style="--reveal-delay:110ms">
                <div class="step-card">
                    <div class="step-num">02</div>
                    <h3>Reserve your slot</h3>
                    <p>Pick a time window that suits you and lock a digital token. Your place in line is held — you can stay home, at work, or with your family.</p>
                </div>
            </div>
            <div class="col-lg-4" data-reveal style="--reveal-delay:220ms">
                <div class="step-card">
                    <div class="step-num">03</div>
                    <h3>Scan and fill</h3>
                    <p>Arrive at your window, show the QR at the gate, and pull straight up to the pump. Average time from gate to road: under six minutes.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<hr class="divider-glow">

<!-- ==========================================================================
     FEATURES
     ========================================================================== -->
<section id="features" class="section-pad">
    <div class="container">
        <div class="text-center mb-5" data-reveal>
            <span class="eyebrow">Why MAP &amp; PUMP</span>
            <h2 class="section-title">Built for the way fuel actually moves</h2>
            <p class="section-lead mx-auto">Every feature exists because someone lost half a day in a queue that didn't need to happen.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-6 col-lg-4" data-reveal>
                <div class="feature-card js-spotlight">
                    <div class="icon-shape mb-4"><i class="fa-solid fa-map-location-dot"></i></div>
                    <h3>Real-time stock map</h3>
                    <p>Never drive blindly again. Instant stock levels and verified availability for every station on an interactive map before you leave the house.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-4" data-reveal style="--reveal-delay:80ms">
                <div class="feature-card js-spotlight">
                    <div class="icon-shape tone-blue mb-4"><i class="fa-solid fa-hourglass-half"></i></div>
                    <h3>Smart token slots</h3>
                    <p>Book a guaranteed time window and wait wherever you like. Your slot is held at the station — no sitting in a hot car on the roadside.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-4" data-reveal style="--reveal-delay:160ms">
                <div class="feature-card js-spotlight">
                    <div class="icon-shape tone-green mb-4"><i class="fa-solid fa-shield-halved"></i></div>
                    <h3>Anti-scam reporting</h3>
                    <p>Flag fake stock listings or black-market pricing in two taps. Reports are reviewed and repeat offenders lose their listing.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-4" data-reveal style="--reveal-delay:40ms">
                <div class="feature-card js-spotlight">
                    <div class="icon-shape tone-amber mb-4"><i class="fa-solid fa-bell"></i></div>
                    <h3>Restock alerts</h3>
                    <p>Follow the stations you rely on and get pinged the moment a delivery lands — usually before the queue outside starts forming.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-4" data-reveal style="--reveal-delay:120ms">
                <div class="feature-card js-spotlight">
                    <div class="icon-shape mb-4"><i class="fa-solid fa-route"></i></div>
                    <h3>Fuel-smart routing</h3>
                    <p>We factor in distance, current queue length and stock confidence, then rank stations by which one actually gets you fuelled fastest.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-4" data-reveal style="--reveal-delay:200ms">
                <div class="feature-card js-spotlight">
                    <div class="icon-shape tone-blue mb-4"><i class="fa-solid fa-mobile-screen-button"></i></div>
                    <h3>Works on any phone</h3>
                    <p>Light enough for a slow connection and a low-end handset. Your token stays valid offline once it's issued.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ==========================================================================
     LIVE MAP SHOWCASE
     ========================================================================== -->
<section id="map" class="section-pad">
    <div class="container">
        <div class="row g-5 align-items-center">

            <div class="col-lg-5" data-reveal>
                <span class="eyebrow">The live map</span>
                <h2 class="section-title">Every station, honestly reported</h2>
                <p class="section-lead">
                    Stations push their own stock updates and drivers confirm them on arrival.
                    When the two disagree, the listing gets flagged — so what you see is what you get.
                </p>

                <ul class="check-list">
                    <li><i class="fa-solid fa-circle-check"></i><span><strong>Colour-coded stock</strong> — green in stock, amber running low, red empty.</span></li>
                    <li><i class="fa-solid fa-circle-check"></i><span><strong>Live queue length</strong> reported by drivers already on site.</span></li>
                    <li><i class="fa-solid fa-circle-check"></i><span><strong>Confidence score</strong> based on how recently the data was verified.</span></li>
                    <li><i class="fa-solid fa-circle-check"></i><span><strong>Per-fuel detail</strong> for 92, 95, diesel and premium diesel separately.</span></li>
                </ul>

                <a href="/register" class="btn btn-primary btn-lg px-4 py-3 mt-4">Open the live map</a>
            </div>

            <div class="col-lg-7" data-reveal style="--reveal-delay:140ms">
                <div class="map-panel">
                    <span class="map-road road-1"></span>
                    <span class="map-road road-2"></span>
                    <span class="map-road road-3"></span>
                    <span class="map-radar"></span>

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
                    <div class="map-pin pin-4">
                        <span class="pin-icon pin-out"><i class="fa-solid fa-gas-pump"></i></span>
                        Dagon &middot; Empty
                    </div>

                    <div class="map-legend">
                        <span><i class="fa-solid fa-circle" style="color:#10b981;font-size:.5rem"></i> In stock</span>
                        <span><i class="fa-solid fa-circle" style="color:#f59e0b;font-size:.5rem"></i> Running low</span>
                        <span><i class="fa-solid fa-circle" style="color:#f43f5e;font-size:.5rem"></i> Out of stock</span>
                    </div>
                </div>
            </div>

        </div>
    </div>
</section>

<hr class="divider-glow">

<!-- ==========================================================================
     TESTIMONIALS
     ========================================================================== -->
<section class="section-pad">
    <div class="container">
        <div class="text-center mb-5" data-reveal>
            <span class="eyebrow">From the road</span>
            <h2 class="section-title">People got their mornings back</h2>
        </div>

        <div class="row g-4">
            <div class="col-md-6 col-lg-4" data-reveal>
                <div class="quote-card">
                    <div class="stars"><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i></div>
                    <blockquote>"I used to leave the house at 5am and still lose two hours. Now I book a slot the night before and I'm in and out before work."</blockquote>
                    <div class="quote-person">
                        <span class="avatar">KZ</span>
                        <span>
                            <span class="name d-block">Ko Zaw</span>
                            <span class="role">Ride-hailing driver, Yangon</span>
                        </span>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg-4" data-reveal style="--reveal-delay:110ms">
                <div class="quote-card">
                    <div class="stars"><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i></div>
                    <blockquote>"The restock alert is the whole thing for me. I get the notification, book, and arrive before the street fills up. It's changed how I plan my week."</blockquote>
                    <div class="quote-person">
                        <span class="avatar av-2">MM</span>
                        <span>
                            <span class="name d-block">Ma Moe</span>
                            <span class="role">Small business owner</span>
                        </span>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg-4" data-reveal style="--reveal-delay:220ms">
                <div class="quote-card">
                    <div class="stars"><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star-half-stroke"></i></div>
                    <blockquote>"On our side the traffic chaos dropped almost overnight. Staff scan tokens at the gate and the forecourt finally moves in an orderly way."</blockquote>
                    <div class="quote-person">
                        <span class="avatar av-3">TH</span>
                        <span>
                            <span class="name d-block">Thiha Aung</span>
                            <span class="role">Station manager, 3 branches</span>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ==========================================================================
     B2B BAND
     ========================================================================== -->
<section id="about" class="section-pad pt-0">
    <div class="container">
        <div class="bg-gradient-business text-center px-3 py-5 px-md-5" data-reveal>
            <div class="max-width-md mx-auto position-relative py-4">
                <span class="eyebrow justify-content-center d-inline-flex">For operators</span>
                <h2 class="section-title">Run a station or fuel company?</h2>
                <p class="text-light opacity-75 mb-4 px-md-4">
                    Digitise your queue, manage every branch from one dashboard, protect your staff from crowd control,
                    and get inventory metrics you can actually plan deliveries around.
                </p>

                <div class="b2b-perks">
                    <span class="perk-pill"><i class="fa-solid fa-gauge-high"></i> Branch dashboard</span>
                    <span class="perk-pill"><i class="fa-solid fa-qrcode"></i> Gate-side token scanning</span>
                    <span class="perk-pill"><i class="fa-solid fa-chart-line"></i> Demand forecasting</span>
                    <span class="perk-pill"><i class="fa-solid fa-users-gear"></i> Staff &amp; role controls</span>
                </div>

                <a href="/php/Login/login.php" class="btn btn-success btn-lg px-5 py-3 fw-bold">Register your company</a>
                <p class="small text-dim mt-3 mb-0">Free during onboarding &middot; No hardware required</p>
            </div>
        </div>
    </div>
</section>

<!-- ==========================================================================
     FAQ
     ========================================================================== -->
<section id="faq" class="section-pad pt-0">
    <div class="container">
        <div class="row g-5">
            <div class="col-lg-4" data-reveal>
                <span class="eyebrow">FAQ</span>
                <h2 class="section-title">Straight answers</h2>
                <p class="section-lead">Still stuck? Reach us any day between 8am and 8pm.</p>
                <a href="#top" class="btn btn-ghost px-4 py-2 mt-2">Contact support</a>
            </div>

            <div class="col-lg-8" data-reveal style="--reveal-delay:120ms">
                <div class="accordion faq-accordion" id="faqAccordion">

                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
                                Does MAP &amp; PUMP cost anything to use?
                            </button>
                        </h2>
                        <div id="faq1" class="accordion-collapse collapse show" data-bs-parent="#faqAccordion">
                            <div class="accordion-body">
                                It's free for drivers — checking the map, booking tokens and setting restock alerts all cost nothing. We charge stations and fuel companies for the operator dashboard.
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
                                How accurate is the stock data?
                            </button>
                        </h2>
                        <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                            <div class="accordion-body">
                                Stations update their own levels, and drivers confirm or dispute them on arrival. Each listing carries a confidence score based on how recently it was verified, so you always know how fresh the number is.
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
                                What happens if I miss my token window?
                            </button>
                        </h2>
                        <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                            <div class="accordion-body">
                                Your token stays valid for a 15-minute grace period. After that it's released to the next driver, and you can rebook the next open slot straight from the app.
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq4">
                                Do I need a smartphone or data connection?
                            </button>
                        </h2>
                        <div id="faq4" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                            <div class="accordion-body">
                                A basic smartphone is enough — the app is built to run on slow connections. Once your token is issued it's stored on the device, so it scans fine at the gate even with no signal.
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq5">
                                How do you handle fake listings and price gouging?
                            </button>
                        </h2>
                        <div id="faq5" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                            <div class="accordion-body">
                                Any driver can report a station in two taps. Reports are reviewed against arrival data from other users, and stations with a pattern of false listings are suspended from the map.
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>
</section>

<!-- ==========================================================================
     FINAL CTA
     ========================================================================== -->
<section class="pb-5">
    <div class="container">
        <div class="final-cta" data-reveal>
            <span class="eyebrow justify-content-center d-inline-flex mb-2">Get started</span>
            <h2 class="text-gradient">Your next fill-up<br class="d-none d-md-block"> shouldn't cost you a morning.</h2>
            <p class="section-lead mx-auto mb-4">Join 52,000+ drivers who stopped queuing and started booking.</p>
            <div class="d-sm-flex gap-3 justify-content-center">
                <a href="/php/Customer/track_book.php" class="btn btn-primary btn-lg px-4 py-3 mb-3 mb-sm-0 w-100 w-sm-auto">Find fuel near me</a>
                <a href="/php/Login/login.php" class="btn btn-outline-light btn-lg px-4 py-3 w-100 w-sm-auto">I run a station</a>
            </div>
        </div>
    </div>
</section>

<!-- ==========================================================================
     FOOTER
     ========================================================================== -->
<footer class="site-footer">
    <div class="container">
        <div class="row g-4">
            <div class="col-lg-4">
                <a class="navbar-brand logo-text d-flex align-items-center gap-2 fs-4 fw-bold mb-3" href="#top">
                    <img src="/images/Logo.png" alt="MAP &amp; PUMP Logo" class="navbar-logo-img">
                    MAP &amp; PUMP
                </a>
                <p class="text-muted-custom small mb-0" style="max-width:320px">
                    Smart fuel booking and live station tracking — built for public safety and smarter distribution.
                </p>
                <div class="social-row">
                    <a href="#" aria-label="Facebook"><i class="fa-brands fa-facebook-f"></i></a>
                    <a href="#" aria-label="Telegram"><i class="fa-brands fa-telegram"></i></a>
                    <a href="#" aria-label="Viber"><i class="fa-brands fa-viber"></i></a>
                    <a href="#" aria-label="Email"><i class="fa-solid fa-envelope"></i></a>
                </div>
            </div>

            <div class="col-6 col-lg-2 offset-lg-1">
                <div class="footer-title">Product</div>
                <ul class="footer-links">
                    <li><a href="#how">How it works</a></li>
                    <li><a href="#features">Features</a></li>
                    <li><a href="#map">Live map</a></li>
                    <li><a href="#faq">FAQ</a></li>
                </ul>
            </div>

            <div class="col-6 col-lg-2">
                <div class="footer-title">For business</div>
                <ul class="footer-links">
                    <li><a href="#about">Station owners</a></li>
                    <li><a href="#about">Fuel companies</a></li>
                    <li><a href="/php/Login/login.php">Operator login</a></li>
                </ul>
            </div>

            <div class="col-6 col-lg-3">
                <div class="footer-title">Company</div>
                <ul class="footer-links">
                    <li><a href="#">About us</a></li>
                    <li><a href="#">Privacy policy</a></li>
                    <li><a href="#">Terms of service</a></li>
                    <li><a href="#">Report a station</a></li>
                </ul>
            </div>
        </div>

        <div class="footer-bottom d-md-flex justify-content-between align-items-center text-center text-md-start">
            <p class="mb-2 mb-md-0">&copy; <?php echo date('Y'); ?> MAP &amp; PUMP. Built for public safety and smarter distribution.</p>
            <p class="mb-0 d-inline-flex align-items-center gap-2 justify-content-center">
                <span class="pulse-dot"></span> All systems operational
            </p>
        </div>
    </div>
</footer>

<button id="back-to-top" aria-label="Back to top"><i class="fa-solid fa-arrow-up"></i></button>

<!-- Bootstrap Bundle JS with Popper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
(function () {
    'use strict';

    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ---------- Cursor spotlight ---------- */
    var follower = document.getElementById('mouse-follower');
    if (follower && !reduceMotion) {
        var tX = window.innerWidth / 2, tY = window.innerHeight / 2;
        var cX = tX, cY = tY, ease = 0.11, visible = false;

        window.addEventListener('mousemove', function (e) {
            tX = e.clientX; tY = e.clientY;
            if (!visible) { visible = true; follower.classList.add('is-active'); }
        });
        window.addEventListener('mouseleave', function () {
            visible = false; follower.classList.remove('is-active');
        });
        (function loop() {
            cX += (tX - cX) * ease;
            cY += (tY - cY) * ease;
            follower.style.transform = 'translate3d(' + cX + 'px,' + cY + 'px,0) translate(-50%,-50%)';
            requestAnimationFrame(loop);
        })();
    }

    /* ---------- Navbar state + scroll progress + back to top ---------- */
    var nav = document.querySelector('.main-navbar');
    var bar = document.getElementById('scroll-progress');
    var top = document.getElementById('back-to-top');

    function onScroll() {
        var y = window.scrollY || document.documentElement.scrollTop;
        if (nav) nav.classList.toggle('is-scrolled', y > 24);
        if (top) top.classList.toggle('is-visible', y > 620);
        if (bar) {
            var h = document.documentElement.scrollHeight - window.innerHeight;
            bar.style.width = (h > 0 ? (y / h) * 100 : 0) + '%';
        }
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();

    if (top) {
        top.addEventListener('click', function () {
            window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
        });
    }

    /* ---------- Scroll reveal ---------- */
    var revealEls = document.querySelectorAll('[data-reveal]');
    if ('IntersectionObserver' in window && !reduceMotion) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    io.unobserve(entry.target);
                }
            });
        }, { threshold: 0.12, rootMargin: '0px 0px -60px 0px' });
        revealEls.forEach(function (el) { io.observe(el); });
    } else {
        revealEls.forEach(function (el) { el.classList.add('is-visible'); });
    }

    /* ---------- Animated counters ---------- */
    function formatNum(n) {
        return n >= 1000 ? n.toLocaleString('en-US') : String(n);
    }

    function runCounter(el) {
        var target = parseFloat(el.getAttribute('data-count')) || 0;
        var suffix = el.getAttribute('data-suffix') || '';
        var dur = 1600, start = null;

        function tick(ts) {
            if (!start) start = ts;
            var p = Math.min((ts - start) / dur, 1);
            var eased = 1 - Math.pow(1 - p, 3);
            el.textContent = formatNum(Math.round(target * eased)) + suffix;
            if (p < 1) requestAnimationFrame(tick);
        }
        requestAnimationFrame(tick);
    }

    var counters = document.querySelectorAll('[data-count]');
    if ('IntersectionObserver' in window && !reduceMotion) {
        var co = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    runCounter(entry.target);
                    co.unobserve(entry.target);
                }
            });
        }, { threshold: 0.5 });
        counters.forEach(function (el) { co.observe(el); });
    } else {
        counters.forEach(function (el) {
            el.textContent = formatNum(parseFloat(el.getAttribute('data-count')) || 0) + (el.getAttribute('data-suffix') || '');
        });
    }

    /* ---------- Card spotlight follows cursor ---------- */
    document.querySelectorAll('.js-spotlight').forEach(function (card) {
        card.addEventListener('mousemove', function (e) {
            var r = card.getBoundingClientRect();
            card.style.setProperty('--mx', (e.clientX - r.left) + 'px');
            card.style.setProperty('--my', (e.clientY - r.top) + 'px');
        });
    });

    /* ---------- Close mobile menu on link tap ---------- */
    var collapseEl = document.getElementById('navbarNav');
    document.querySelectorAll('#navbarNav .nav-link').forEach(function (link) {
        link.addEventListener('click', function () {
            if (collapseEl && collapseEl.classList.contains('show')) {
                var inst = bootstrap.Collapse.getInstance(collapseEl) || new bootstrap.Collapse(collapseEl, { toggle: false });
                inst.hide();
            }
        });
    });

    /* ---------- Scrollspy on nav links ---------- */
    var sections = ['how', 'features', 'map', 'about', 'faq']
        .map(function (id) { return document.getElementById(id); })
        .filter(Boolean);

    if ('IntersectionObserver' in window && sections.length) {
        var so = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                document.querySelectorAll('#navbarNav .nav-link').forEach(function (l) {
                    l.classList.toggle('active', l.getAttribute('href') === '#' + entry.target.id);
                });
            });
        }, { rootMargin: '-45% 0px -50% 0px' });
        sections.forEach(function (s) { so.observe(s); });
    }
})();
</script>

</body>
</html>