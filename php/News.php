<?php
/* ==========================================================================
   MAP & PUMP — NEWS & FUEL PRICES
   All figures come from /data/fuel_prices.php
   ========================================================================== */

require_once __DIR__ . '/data/fuel_prices.php';

$page_title       = 'News & Fuel Prices | MAP & PUMP';
$page_description = 'Average fuel prices in MMK per litre by grade, by company and by region, plus supply and policy updates from across the fuel retail market.';
$active_nav       = 'news';

require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/nav.php';
?>

<!-- ==========================================================================
     PAGE HEADER
     ========================================================================== -->
<section class="page-header">
    <div class="container">
        <div class="row align-items-end g-4">
            <div class="col-lg-8" data-reveal>
                <span class="eyebrow">News &amp; market prices</span>
                <h1 class="page-title">What fuel costs today</h1>
                <p class="section-lead mb-0">
                    Average retail prices in MMK per litre, broken down by grade, by company and by region —
                    plus the supply and policy updates that moved them.
                </p>
            </div>
            <div class="col-lg-4" data-reveal style="--reveal-delay:120ms">
                <div class="updated-card">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="pulse-dot"></span>
                        <span class="updated-label">Last updated</span>
                    </div>
                    <div class="updated-time"><?php echo htmlspecialchars($PRICE_UPDATED_AT); ?></div>
                    <p class="small text-dim mb-0 mt-2">Prices are collected from participating companies and reviewed before publishing.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ==========================================================================
     NATIONAL AVERAGE BY GRADE
     ========================================================================== -->
<section class="section-pad pt-0">
    <div class="container">
        <div class="mb-4" data-reveal>
            <h2 class="section-title-sm">National average by grade</h2>
        </div>

        <div class="row g-3">
            <?php if (empty($FUEL_GRADES)): ?>
                <div class="col-12" data-reveal>
                    <div class="alert alert-secondary border-0 mb-0">
                        No manager-approved fuel prices are live yet. Once branch managers publish station prices, national averages will appear here.
                    </div>
                </div>
            <?php else: ?>
            <?php foreach ($FUEL_GRADES as $i => $grade): $t = mp_trend($grade['avg'], $grade['prev']); ?>
                <div class="col-6 col-lg-3" data-reveal style="--reveal-delay:<?php echo $i * 80; ?>ms">
                    <div class="price-card price-card-lg">
                        <div class="price-head">
                            <i class="fa-solid <?php echo $grade['icon']; ?>"></i>
                            <span><?php echo htmlspecialchars($grade['label']); ?></span>
                        </div>
                        <div class="price-value"><?php echo mp_money($grade['avg']); ?><small>MMK</small></div>
                        <div class="trend-chip <?php echo $t['class']; ?>">
                            <i class="fa-solid <?php echo $t['icon']; ?>"></i> <?php echo $t['delta']; ?>
                            <span class="trend-period">vs last week</span>
                        </div>
                        <div class="price-foot">Last week: <?php echo mp_money($grade['prev']); ?> MMK</div>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- ==========================================================================
     PRICE BY COMPANY
     ========================================================================== -->
<section id="by-company" class="section-pad pt-0">
    <div class="container">
        <div class="d-lg-flex justify-content-between align-items-end gap-4 mb-4" data-reveal>
            <div>
                <h2 class="section-title-sm">Price by company</h2>
                <p class="section-lead mb-0">Prices differ between companies. Filter by grade to compare like for like.</p>
            </div>

            <div class="grade-tabs mt-3 mt-lg-0" id="grade-tabs">
                <button type="button" data-grade="all" class="is-active">All grades</button>
                <?php foreach ($FUEL_GRADES as $grade): ?>
                    <button type="button" data-grade="<?php echo $grade['code']; ?>"><?php echo htmlspecialchars($grade['label']); ?></button>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="table-shell" data-reveal>
            <div class="table-responsive">
                <table class="table price-table mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Company</th>
                            <?php foreach ($FUEL_GRADES as $grade): ?>
                                <th scope="col" class="text-end" data-grade-col="<?php echo $grade['code']; ?>">
                                    <?php echo htmlspecialchars($grade['label']); ?>
                                </th>
                            <?php endforeach; ?>
                            <th scope="col" class="text-end">Stations</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($COMPANY_PRICES)): ?>
                            <tr>
                                <td colspan="<?php echo max(2, count($FUEL_GRADES) + 2); ?>" class="text-center text-dim py-4">
                                    No participating companies have published prices yet.
                                </td>
                            </tr>
                        <?php else: ?>
                        <?php foreach ($COMPANY_PRICES as $row): ?>
                            <tr>
                                <th scope="row">
                                    <span class="company-cell">
                                        <span class="company-mark"><?php echo htmlspecialchars(function_exists('mb_substr') ? mb_substr($row['company'], 0, 1) : substr($row['company'], 0, 1)); ?></span>
                                        <?php echo htmlspecialchars($row['company']); ?>
                                    </span>
                                </th>

                                <?php foreach ($FUEL_GRADES as $grade):
                                    $code = $grade['code'];
                                    $now  = $row['prices'][$code] ?? null;
                                    $prev = $row['prev'][$code] ?? null;
                                    $t    = mp_trend($now, $prev);
                                ?>
                                    <td class="text-end" data-grade-col="<?php echo $code; ?>">
                                        <?php if ($now === null): ?>
                                            <span class="text-dim">—</span>
                                        <?php else: ?>
                                            <span class="cell-price"><?php echo mp_money($now); ?></span>
                                            <span class="cell-trend <?php echo $t['class']; ?>">
                                                <i class="fa-solid <?php echo $t['icon']; ?>"></i>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>

                                <td class="text-end text-muted-custom"><?php echo (int) $row['stations']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <p class="small text-dim mt-3 mb-0">
            <i class="fa-solid fa-circle-info me-2"></i>All prices in MMK per litre. Arrows compare against the same grade one week ago.
        </p>
    </div>
</section>

<!-- ==========================================================================
     PRICE BY REGION
     ========================================================================== -->
<section id="by-region" class="section-pad pt-0">
    <div class="container">
        <div class="mb-4" data-reveal>
            <h2 class="section-title-sm">Price by region</h2>
            <p class="section-lead mb-0">Octane 92 average per litre, with the weekly move and how many stations reported.</p>
        </div>

        <div class="row g-3">
            <?php if (empty($REGION_PRICES)): ?>
                <div class="col-12" data-reveal>
                    <div class="alert alert-secondary border-0 mb-0">
                        Regional averages will appear after stations publish Octane 92 prices with location data.
                    </div>
                </div>
            <?php else: ?>
            <?php foreach ($REGION_PRICES as $i => $region): $t = mp_trend($region['avg92'], $region['prev92']); ?>
                <div class="col-6 col-lg-4" data-reveal style="--reveal-delay:<?php echo $i * 60; ?>ms">
                    <div class="region-card">
                        <div class="region-top">
                            <span class="region-name"><i class="fa-solid fa-location-dot me-2"></i><?php echo htmlspecialchars($region['region']); ?></span>
                            <span class="trend-chip trend-chip-sm <?php echo $t['class']; ?>">
                                <i class="fa-solid <?php echo $t['icon']; ?>"></i> <?php echo $t['delta']; ?>
                            </span>
                        </div>
                        <div class="region-price"><?php echo mp_money($region['avg92']); ?><small>MMK</small></div>
                        <div class="region-meta"><?php echo (int) $region['stations']; ?> stations reporting</div>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</section>

<hr class="divider-glow">

<!-- ==========================================================================
     ANNOUNCEMENTS / ARTICLES
     ========================================================================== -->
<section id="updates" class="section-pad">
    <div class="container">
        <div class="d-lg-flex justify-content-between align-items-end gap-4 mb-4" data-reveal>
            <div>
                <span class="eyebrow">Updates</span>
                <h2 class="section-title-sm mt-2">Supply, policy and station news</h2>
            </div>

            <div class="grade-tabs mt-3 mt-lg-0" id="tag-filter">
                <button type="button" data-tag="all" class="is-active">Everything</button>
                <button type="button" data-tag="price">Prices</button>
                <button type="button" data-tag="supply">Supply</button>
                <button type="button" data-tag="policy">Policy</button>
                <button type="button" data-tag="station">Stations</button>
            </div>
        </div>

        <div class="row g-4">
            <?php if (empty($NEWS_POSTS)): ?>
                <div class="col-12" data-reveal>
                    <div class="alert alert-secondary border-0 mb-0">
                        Market updates will appear here from live price changes, fuel deliveries, and station activity.
                    </div>
                </div>
            <?php else: ?>
            <?php foreach ($NEWS_POSTS as $i => $post): ?>
                <div class="col-md-6" data-post-tag="<?php echo $post['tag']; ?>" data-reveal style="--reveal-delay:<?php echo $i * 80; ?>ms">
                    <article class="news-card js-spotlight">
                        <div class="news-top">
                            <span class="tag-pill tag-<?php echo $post['tag']; ?>"><?php echo mp_tag_label($post['tag']); ?></span>
                            <span class="news-date"><?php echo htmlspecialchars($post['date']); ?></span>
                        </div>
                        <h3 class="news-title"><?php echo htmlspecialchars($post['title']); ?></h3>
                        <p class="news-body"><?php echo htmlspecialchars($post['body']); ?></p>
                    </article>
                </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- ==========================================================================
     CTA
     ========================================================================== -->
<section class="pb-5">
    <div class="container">
        <div class="bg-gradient-business text-center px-3 py-5 px-md-5" data-reveal>
            <div class="max-width-md mx-auto position-relative py-3">
                <span class="eyebrow justify-content-center d-inline-flex">For fuel companies</span>
                <h2 class="section-title">Want your prices listed here?</h2>
                <p class="text-light opacity-75 mb-4 px-md-4">
                    Companies on MAP &amp; PUMP publish their per-grade prices straight to this page
                    and control their station availability on the live map. First three stations free.
                </p>
                <div class="d-sm-flex gap-3 justify-content-center">
                    <a href="/php/Login/login.php" class="btn btn-success btn-lg px-5 py-3 fw-bold mb-3 mb-sm-0 w-100 w-sm-auto">Register your company</a>
                    <a href="/HomePage.php#pricing" class="btn btn-outline-light btn-lg px-4 py-3 w-100 w-sm-auto">See pricing</a>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>