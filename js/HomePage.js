/* ==========================================================================
   MAP & PUMP — shared front-end behaviour
   Loaded by partials/footer.php on every page.
   ========================================================================== */
(function () {
    'use strict';

    /* ---------- School-project consent gate (home page only) ---------- */
    var projectConsent = document.getElementById('project-consent');
    if (projectConsent) {
        var consentKey = 'map_pump_school_project_terms_v1';
        var consentCheckbox = document.getElementById('project-consent-checkbox');
        var acceptConsent = document.getElementById('project-consent-accept');
        var leaveConsent = document.getElementById('project-consent-leave');

        try {
            if (window.localStorage.getItem(consentKey) === 'accepted') {
                projectConsent.remove();
            } else {
                projectConsent.hidden = false;
                document.body.classList.add('project-consent-open');
                consentCheckbox.focus();
            }
        } catch (error) {
            projectConsent.hidden = false;
            document.body.classList.add('project-consent-open');
        }

        if (consentCheckbox && acceptConsent) {
            consentCheckbox.addEventListener('change', function () {
                acceptConsent.disabled = !consentCheckbox.checked;
            });

            acceptConsent.addEventListener('click', function () {
                if (!consentCheckbox.checked) return;
                try { window.localStorage.setItem(consentKey, 'accepted'); } catch (error) {}
                projectConsent.remove();
                document.body.classList.remove('project-consent-open');
            });
        }

        if (leaveConsent) {
            leaveConsent.addEventListener('click', function () {
                window.location.replace('about:blank');
            });
        }
    }

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
       Homepage estimate: the first three stations are included; paid
       stations use the configured rate and 12/60-month upfront terms.
       ====================================================================== */
    var calc = document.getElementById('calc');
    if (calc) {
        var RATE = Number(calc.dataset.rate) || 37000;
        var INCLUDED = Number(calc.dataset.included) || 3;
        var rangeEl = document.getElementById('calc-range');
        var numberEl = document.getElementById('calc-number');
        var countEl = document.getElementById('calc-count');
        var totalStationsEl = document.getElementById('calc-total-stations');
        var freeEl = document.getElementById('calc-free');
        var paidEl = document.getElementById('calc-paid');
        var monthsWrap = document.getElementById('calc-months');
        var baseEl = document.getElementById('calc-base');
        var savingsEl = document.getElementById('calc-savings');
        var termLabelEl = document.getElementById('calc-term-label');
        var totalEl = document.getElementById('calc-total');
        var perEl = document.getElementById('calc-per');
        var freeNoteEl = document.getElementById('calc-free-note');
        var months = 12;
        var discount = 2;

        function fmt(value) { return Math.round(value).toLocaleString('en-US'); }
        function clampStations(value) {
            var parsed = Number.parseInt(value, 10);
            if (!Number.isFinite(parsed)) parsed = Number(rangeEl.value) || 1;
            return Math.min(Number(rangeEl.max), Math.max(Number(rangeEl.min), parsed));
        }

        function update(stationValue) {
            var stations = clampStations(stationValue);
            var free = Math.min(stations, INCLUDED);
            var paid = Math.max(stations - INCLUDED, 0);
            var base = paid * RATE * months;
            var savings = base * discount / 100;
            var upfront = Math.round(base - savings);

            rangeEl.value = String(stations);
            numberEl.value = String(stations);
            countEl.textContent = String(stations);
            totalStationsEl.textContent = String(stations);
            freeEl.textContent = String(free);
            paidEl.textContent = String(paid);
            termLabelEl.textContent = months === 12 ? '1 year' : '5 years';
            baseEl.textContent = fmt(base) + ' MMK';
            savingsEl.textContent = fmt(savings) + ' MMK (' + discount + '%)';
            totalEl.textContent = fmt(upfront) + ' MMK';
            perEl.textContent = paid
                ? 'Equivalent to ' + fmt(upfront / paid / months) + ' MMK / paid station / month'
                : 'No paid stations in this estimate';
            freeNoteEl.hidden = paid > 0;

            var pct = ((stations - Number(rangeEl.min)) / (Number(rangeEl.max) - Number(rangeEl.min))) * 100;
            rangeEl.style.setProperty('--fill', pct + '%');
        }

        rangeEl.addEventListener('input', function () { update(rangeEl.value); });
        numberEl.addEventListener('input', function () {
            if (numberEl.value !== '') update(numberEl.value);
        });
        numberEl.addEventListener('change', function () { update(numberEl.value); });

        monthsWrap.querySelectorAll('button').forEach(function (button) {
            button.addEventListener('click', function () {
                monthsWrap.querySelectorAll('button').forEach(function (item) {
                    var selected = item === button;
                    item.classList.toggle('is-active', selected);
                    item.setAttribute('aria-pressed', String(selected));
                });
                months = Number(button.dataset.months);
                discount = Number(button.dataset.discount);
                update(numberEl.value);
            });
        });

        update(numberEl.value);
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
