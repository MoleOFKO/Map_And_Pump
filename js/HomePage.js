/* ==========================================================================
   MAP & PUMP — shared front-end behaviour
   Loaded by partials/footer.php on every page.
   ========================================================================== */
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
    var toTop = document.getElementById('back-to-top');

    function onScroll() {
        var y = window.scrollY || document.documentElement.scrollTop;
        if (nav) nav.classList.toggle('is-scrolled', y > 24);
        if (toTop) toTop.classList.toggle('is-visible', y > 620);
        if (bar) {
            var h = document.documentElement.scrollHeight - window.innerHeight;
            bar.style.width = (h > 0 ? (y / h) * 100 : 0) + '%';
        }
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();

    if (toTop) {
        toTop.addEventListener('click', function () {
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
        }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });
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
        var dur = 1500, start = null;

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
    document.querySelectorAll('#navbarNav a').forEach(function (link) {
        link.addEventListener('click', function () {
            if (collapseEl && collapseEl.classList.contains('show')) {
                var inst = bootstrap.Collapse.getInstance(collapseEl) || new bootstrap.Collapse(collapseEl, { toggle: false });
                inst.hide();
            }
        });
    });

    /* ---------- Scrollspy for in-page nav links ---------- */
    var spyIds = ['how', 'pricing', 'drivers'];
    var sections = spyIds.map(function (id) { return document.getElementById(id); }).filter(Boolean);

    if ('IntersectionObserver' in window && sections.length) {
        var so = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                document.querySelectorAll('#navbarNav .nav-link[data-spy]').forEach(function (l) {
                    l.classList.toggle('active', l.getAttribute('data-spy') === entry.target.id);
                });
            });
        }, { rootMargin: '-45% 0px -50% 0px' });
        sections.forEach(function (s) { so.observe(s); });
    }

    /* ======================================================================
       PRICING CALCULATOR
       Free tier: first 3 stations (registration fee included).
       Extra stations billed per station per month, with volume + duration
       discounts. All rates live in the data-* attributes on #calc so you can
       change your pricing in the HTML without touching this file.
       ====================================================================== */
    var calc = document.getElementById('calc');
    if (calc) {
        var FREE      = parseInt(calc.dataset.free, 10) || 3;
        var RATE      = parseInt(calc.dataset.rate, 10) || 2000;

        // Volume discount tiers, applied on the number of PAID stations.
        var VOLUME = [
            { min: 23, off: 0.20 },
            { min: 8,  off: 0.10 },
            { min: 1,  off: 0.00 }
        ];
        // Duration discounts, keyed by months committed.
        var DURATION = { 1: 0, 3: 0.05, 6: 0.10, 12: 0.20 };

        var rangeEl   = document.getElementById('calc-range');
        var countEl   = document.getElementById('calc-count');
        var paidEl    = document.getElementById('calc-paid');
        var monthsWrap= document.getElementById('calc-months');
        var baseEl    = document.getElementById('calc-base');
        var volEl     = document.getElementById('calc-vol');
        var durEl     = document.getElementById('calc-dur');
        var totalEl   = document.getElementById('calc-total');
        var perEl     = document.getElementById('calc-per');
        var noteEl    = document.getElementById('calc-note');

        var months = 1;

        function fmt(n) { return Math.round(n).toLocaleString('en-US'); }

        function volumeOff(paid) {
            for (var i = 0; i < VOLUME.length; i++) {
                if (paid >= VOLUME[i].min) return VOLUME[i].off;
            }
            return 0;
        }

        function update() {
            var stations = parseInt(rangeEl.value, 10);
            var paid     = Math.max(0, stations - FREE);

            var vOff = volumeOff(paid);
            var dOff = DURATION[months] || 0;

            var base  = paid * RATE * months;
            var afterV = base * (1 - vOff);
            var total  = afterV * (1 - dOff);

            countEl.textContent = stations;
            paidEl.textContent  = paid;
            baseEl.textContent  = fmt(base) + ' MMK';
            volEl.textContent   = vOff ? '−' + fmt(base - afterV) + ' MMK (' + Math.round(vOff * 100) + '%)' : '—';
            durEl.textContent   = dOff ? '−' + fmt(afterV - total) + ' MMK (' + Math.round(dOff * 100) + '%)' : '—';
            totalEl.textContent = fmt(total) + ' MMK';

            perEl.textContent = paid > 0
                ? fmt(total / paid / months) + ' MMK / station / month'
                : 'Free';

            noteEl.textContent = paid === 0
                ? 'Within your ' + FREE + ' free stations — registration fee only.'
                : months > 1
                    ? 'Total for ' + months + ' months, billed upfront.'
                    : 'Billed monthly.';

            // fill the slider track up to the thumb
            var pct = ((stations - rangeEl.min) / (rangeEl.max - rangeEl.min)) * 100;
            rangeEl.style.setProperty('--fill', pct + '%');
        }

        rangeEl.addEventListener('input', update);

        monthsWrap.querySelectorAll('button').forEach(function (btn) {
            btn.addEventListener('click', function () {
                monthsWrap.querySelectorAll('button').forEach(function (b) { b.classList.remove('is-active'); });
                btn.classList.add('is-active');
                months = parseInt(btn.dataset.months, 10);
                update();
            });
        });

        update();
    }

    /* ======================================================================
       NEWS PAGE — grade filter for the company price table
       ====================================================================== */
    var gradeTabs = document.getElementById('grade-tabs');
    if (gradeTabs) {
        gradeTabs.querySelectorAll('button').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var code = btn.dataset.grade;

                gradeTabs.querySelectorAll('button').forEach(function (b) { b.classList.remove('is-active'); });
                btn.classList.add('is-active');

                document.querySelectorAll('[data-grade-col]').forEach(function (cell) {
                    var show = (code === 'all') || (cell.dataset.gradeCol === code);
                    cell.classList.toggle('d-none', !show);
                });
            });
        });
    }

    /* ======================================================================
       NEWS PAGE — tag filter for announcement posts
       ====================================================================== */
    var tagFilter = document.getElementById('tag-filter');
    if (tagFilter) {
        tagFilter.querySelectorAll('button').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var tag = btn.dataset.tag;

                tagFilter.querySelectorAll('button').forEach(function (b) { b.classList.remove('is-active'); });
                btn.classList.add('is-active');

                document.querySelectorAll('[data-post-tag]').forEach(function (post) {
                    var show = (tag === 'all') || (post.dataset.postTag === tag);
                    post.classList.toggle('d-none', !show);
                });
            });
        });
    }
})();