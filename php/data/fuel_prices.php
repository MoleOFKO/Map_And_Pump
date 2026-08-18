<?php
/* ==========================================================================
   MAP & PUMP — FUEL PRICE / NEWS DATA
   --------------------------------------------------------------------------
   RIGHT NOW this file is the single source of truth for everything shown on
   the NEWS page and the price snapshot on the homepage. Edit the arrays below
   and both pages update.

   ⚠️  ALL NUMBERS AND COMPANY NAMES BELOW ARE SAMPLE DATA.
       Replace them with your real figures before you publish.

   --------------------------------------------------------------------------
   WHEN YOU BUILD THE MySQL TABLES LATER
   --------------------------------------------------------------------------
   You said you're not sure how to structure this yet. Here is a schema that
   fits exactly what these pages render — three small tables, nothing clever:

     CREATE TABLE companies (
         id            INT AUTO_INCREMENT PRIMARY KEY,
         name          VARCHAR(120) NOT NULL,
         slug          VARCHAR(120) NOT NULL UNIQUE,
         logo_path     VARCHAR(255) DEFAULT NULL,
         is_active     TINYINT(1) NOT NULL DEFAULT 1,
         created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
     );

     CREATE TABLE fuel_grades (
         id            INT AUTO_INCREMENT PRIMARY KEY,
         code          VARCHAR(20) NOT NULL UNIQUE,   -- '92', '95', 'diesel', 'premium_diesel'
         label         VARCHAR(60) NOT NULL           -- 'Octane 92'
     );

     -- one row per company per grade per day. This is the important one:
     -- keep history, never overwrite. Trends come free from the history.
     CREATE TABLE fuel_prices (
         id            INT AUTO_INCREMENT PRIMARY KEY,
         company_id    INT NOT NULL,
         grade_id      INT NOT NULL,
         region        VARCHAR(80) DEFAULT NULL,      -- NULL = national price
         price_mmk     DECIMAL(10,2) NOT NULL,
         effective_on  DATE NOT NULL,
         created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
         UNIQUE KEY uniq_price (company_id, grade_id, region, effective_on),
         FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
         FOREIGN KEY (grade_id)   REFERENCES fuel_grades(id) ON DELETE CASCADE
     );

   Latest price per company + grade, with last week's price for the trend:

     SELECT c.name AS company, g.label AS grade,
            p.price_mmk AS today,
            (SELECT p2.price_mmk FROM fuel_prices p2
              WHERE p2.company_id = p.company_id
                AND p2.grade_id   = p.grade_id
                AND p2.effective_on <= (CURDATE() - INTERVAL 7 DAY)
              ORDER BY p2.effective_on DESC LIMIT 1) AS last_week
       FROM fuel_prices p
       JOIN companies   c ON c.id = p.company_id
       JOIN fuel_grades g ON g.id = p.grade_id
      WHERE p.effective_on = (SELECT MAX(p3.effective_on) FROM fuel_prices p3
                               WHERE p3.company_id = p.company_id
                                 AND p3.grade_id   = p.grade_id)
        AND c.is_active = 1
      ORDER BY c.name, g.id;

   Then build $FUEL_GRADES / $COMPANY_PRICES from that result set in the same
   shape as below, and every page keeps working untouched.
   ========================================================================== */

/* When the prices were last updated — shown on both pages. */
$PRICE_UPDATED_AT = '17 August 2026, 9:00 AM';

/* --------------------------------------------------------------------------
   1. NATIONAL AVERAGE PER GRADE
   'avg' = today's national average in MMK per litre
   'prev' = the same average one week ago (used to draw the trend arrow)
   -------------------------------------------------------------------------- */
$FUEL_GRADES = [
    [ 'code' => '92',     'label' => 'Octane 92',      'icon' => 'fa-gas-pump',    'avg' => 3145, 'prev' => 3090 ],
    [ 'code' => '95',     'label' => 'Octane 95',      'icon' => 'fa-gas-pump',    'avg' => 3320, 'prev' => 3350 ],
    [ 'code' => 'diesel', 'label' => 'Diesel',         'icon' => 'fa-truck-fast',  'avg' => 3010, 'prev' => 2960 ],
    [ 'code' => 'pdiesel','label' => 'Premium Diesel', 'icon' => 'fa-truck-front', 'avg' => 3180, 'prev' => 3180 ],
];

/* --------------------------------------------------------------------------
   2. PRICE BY COMPANY
   Because prices differ per company, this is the table drivers actually care
   about. Keys must match the 'code' values in $FUEL_GRADES above.
   Use null when a company doesn't sell that grade.
   -------------------------------------------------------------------------- */
$COMPANY_PRICES = [
    [
        'company'  => 'Max Energy',
        'stations' => 42,
        'prices'   => [ '92' => 3140, '95' => 3315, 'diesel' => 3005, 'pdiesel' => 3175 ],
        'prev'     => [ '92' => 3085, '95' => 3345, 'diesel' => 2955, 'pdiesel' => 3175 ],
    ],
    [
        'company'  => 'Denko',
        'stations' => 38,
        'prices'   => [ '92' => 3150, '95' => 3325, 'diesel' => 3015, 'pdiesel' => 3185 ],
        'prev'     => [ '92' => 3095, '95' => 3355, 'diesel' => 2965, 'pdiesel' => 3180 ],
    ],
    [
        'company'  => 'Shwe Byine Phyu',
        'stations' => 31,
        'prices'   => [ '92' => 3135, '95' => 3310, 'diesel' => 2995, 'pdiesel' => null ],
        'prev'     => [ '92' => 3080, '95' => 3340, 'diesel' => 2950, 'pdiesel' => null ],
    ],
    [
        'company'  => 'ST Oil',
        'stations' => 24,
        'prices'   => [ '92' => 3155, '95' => 3330, 'diesel' => 3020, 'pdiesel' => 3190 ],
        'prev'     => [ '92' => 3100, '95' => 3330, 'diesel' => 2970, 'pdiesel' => 3190 ],
    ],
    [
        'company'  => 'Parami Energy',
        'stations' => 19,
        'prices'   => [ '92' => 3145, '95' => null, 'diesel' => 3010, 'pdiesel' => 3180 ],
        'prev'     => [ '92' => 3090, '95' => null, 'diesel' => 2960, 'pdiesel' => 3175 ],
    ],
];

/* --------------------------------------------------------------------------
   3. PRICE BY REGION / TOWNSHIP
   'avg92' is the headline number shown on the card.
   -------------------------------------------------------------------------- */
$REGION_PRICES = [
    [ 'region' => 'Yangon',     'avg92' => 3145, 'prev92' => 3090, 'stations' => 128 ],
    [ 'region' => 'Mandalay',   'avg92' => 3190, 'prev92' => 3140, 'stations' => 74  ],
    [ 'region' => 'Naypyidaw',  'avg92' => 3165, 'prev92' => 3120, 'stations' => 41  ],
    [ 'region' => 'Bago',       'avg92' => 3175, 'prev92' => 3175, 'stations' => 33  ],
    [ 'region' => 'Mawlamyine', 'avg92' => 3210, 'prev92' => 3250, 'stations' => 28  ],
    [ 'region' => 'Taunggyi',   'avg92' => 3240, 'prev92' => 3195, 'stations' => 22  ],
];

/* --------------------------------------------------------------------------
   4. NEWS / ANNOUNCEMENT POSTS
   'tag' controls the pill colour: price | supply | policy | station
   -------------------------------------------------------------------------- */
$NEWS_POSTS = [
    [
        'tag'   => 'price',
        'date'  => '17 Aug 2026',
        'title' => 'Octane 92 climbs 55 MMK as import costs rise',
        'body'  => 'The national average for Octane 92 moved from 3,090 to 3,145 MMK per litre this week, driven mainly by higher landed import costs. Octane 95 moved the other way, easing 30 MMK.',
    ],
    [
        'tag'   => 'supply',
        'date'  => '15 Aug 2026',
        'title' => 'Diesel deliveries normalise across Yangon',
        'body'  => 'After two weeks of intermittent supply, diesel deliveries to Yangon stations have returned to a regular schedule. Stations reporting through MAP & PUMP showed a 34% drop in out-of-stock hours.',
    ],
    [
        'tag'   => 'policy',
        'date'  => '12 Aug 2026',
        'title' => 'New retail price display requirements for stations',
        'body'  => 'Stations are now expected to display current per-litre prices for every grade they carry at the forecourt entrance. Companies on MAP & PUMP can push the same figures to the map in one update.',
    ],
    [
        'tag'   => 'station',
        'date'  => '09 Aug 2026',
        'title' => '26 new stations joined the live map this month',
        'body'  => 'Six companies added stations to MAP & PUMP in August, bringing live coverage to 340+ forecourts nationwide. Mandalay saw the largest increase at nine new listings.',
    ],
];

/* --------------------------------------------------------------------------
   5. HELPERS — shared by both pages
   -------------------------------------------------------------------------- */
if (!function_exists('mp_money')) {
    /** 3145 -> "3,145" */
    function mp_money($n) {
        return $n === null ? '—' : number_format((float) $n);
    }
}

if (!function_exists('mp_trend')) {
    /**
     * Compares today vs last week and returns everything the template needs
     * to render a trend chip: direction, css class, arrow icon, delta label.
     */
    function mp_trend($now, $prev) {
        if ($now === null || $prev === null) {
            return [ 'dir' => 'flat', 'class' => 'trend-flat', 'icon' => 'fa-minus', 'delta' => '—' ];
        }
        $diff = (float) $now - (float) $prev;
        if (abs($diff) < 0.01) {
            return [ 'dir' => 'flat', 'class' => 'trend-flat', 'icon' => 'fa-minus', 'delta' => 'No change' ];
        }
        if ($diff > 0) {
            return [ 'dir' => 'up', 'class' => 'trend-up', 'icon' => 'fa-arrow-trend-up',
                     'delta' => '+' . mp_money(round($diff)) . ' MMK' ];
        }
        return [ 'dir' => 'down', 'class' => 'trend-down', 'icon' => 'fa-arrow-trend-down',
                 'delta' => '−' . mp_money(round(abs($diff))) . ' MMK' ];
    }
}

if (!function_exists('mp_tag_label')) {
    function mp_tag_label($tag) {
        $map = [
            'price'   => 'Price update',
            'supply'  => 'Supply',
            'policy'  => 'Policy',
            'station' => 'Stations',
        ];
        return $map[$tag] ?? ucfirst($tag);
    }
}