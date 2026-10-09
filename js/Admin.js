/**
 * Admin navbar — Facebook-style auto hide/show on scroll.
 * Hides when scrolling down; reappears on any upward scroll.
 */
(function () {
    const nav = document.querySelector('.sticky-nav-wrapper');
    if (!nav) return;

    let lastScrollY = window.scrollY;
    let ticking = false;

    const SCROLL_DELTA = 6;
    const TOP_OFFSET = 16;

    function updateNav() {
        const currentScrollY = window.scrollY;
        const delta = currentScrollY - lastScrollY;

        if (currentScrollY <= TOP_OFFSET) {
            nav.classList.remove('is-hidden');
        } else if (delta > SCROLL_DELTA) {
            nav.classList.add('is-hidden');
        } else if (delta < -SCROLL_DELTA) {
            nav.classList.remove('is-hidden');
        }

        lastScrollY = currentScrollY;
        ticking = false;
    }

    window.addEventListener('scroll', () => {
        if (!ticking) {
            window.requestAnimationFrame(updateNav);
            ticking = true;
        }
    }, { passive: true });
})();

/**
 * Admin theme toggle.
 * Adds light-mode hooks to the document and remembers the user's choice.
 */
(function () {
    const THEME_KEY = 'mapPumpAdminTheme';
    const toggles = document.querySelectorAll('.js-theme-toggle');
    const savedTheme = localStorage.getItem(THEME_KEY);
    const initialTheme = savedTheme || 'dark';

    function applyTheme(theme) {
        const isLight = theme === 'light';

        document.documentElement.classList.toggle('light-mode', isLight);
        document.documentElement.dataset.theme = theme;

        if (document.body) {
            document.body.classList.toggle('light-mode', isLight);
            document.body.dataset.theme = theme;
        }

        toggles.forEach((toggle) => {
            toggle.checked = isLight;
            toggle.setAttribute('aria-checked', String(isLight));
        });

        localStorage.setItem(THEME_KEY, theme);
    }

    applyTheme(initialTheme);

    toggles.forEach((toggle) => {
        toggle.addEventListener('change', () => {
            applyTheme(toggle.checked ? 'light' : 'dark');
        });
    });
})();

/**
 * Main Menu offcanvas — always open from the top so Home stays visible after page scroll.
 */
(function () {
    const menu = document.getElementById('mainMenuOffcanvas');
    if (!menu) return;

    menu.addEventListener('show.bs.offcanvas', () => {
        const body = menu.querySelector('.offcanvas-body');
        if (body) {
            body.scrollTop = 0;
        }
    });
})();

/**
 * Ensure sticky navs are direct children of <body> so `position: sticky` behaves consistently.
 * Some admin pages render the sticky wrapper inside nested containers which prevents
 * sticky from working correctly in some browsers. Move non-body-wrapped navs to the top of body.
 */
(function () {
    try {
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.sticky-nav-wrapper').forEach((nav) => {
                if (!nav || !(nav instanceof HTMLElement)) return;
                if (nav.parentElement === document.body) return; // already in the right place

                // Only move navs that look like the capsule/navbar (guard against moving offcanvas bodies)
                const hasNav = nav.querySelector('.navigation_style') !== null;
                if (!hasNav) return;

                // Move the nav wrapper to be the first child of body so sticky positioning works as intended.
                document.body.insertBefore(nav, document.body.firstChild);
            });
        });
    } catch (e) {
        // swallow — non-critical enhancement
        console.warn('sticky-nav-wrapper move failed', e);
    }
})();

/**
 * Live filter forms (Admin + Moderator list pages).
 * Debounced AJAX refresh of the results panel (no full-page reload).
 * Targets GET forms that contain input[name="search"]. Skips hire-account AJAX search
 * (no name="search") and all POST modals. Falls back to full submit if needed.
 *
 * After a successful panel swap, dispatches `livefilter:updated` on document so
 * page scripts can re-bind row action buttons (querySelectorAll handlers).
 */
(function () {
    const DEBOUNCE_MS = 320;
    const LIVE_REQUEST_COUNTS_INTERVAL_MS = 5000;

    function initLiveRequestCounts() {
        const requestPie = document.querySelector('[data-live-request-pie]');
        const liveCounters = document.querySelectorAll('[data-live-request-count]');
        if (!requestPie && liveCounters.length === 0) return;

        const refresh = async () => {
            if (document.hidden) return;
            try {
                const response = await fetch('/php/Admin(WebOwner)/request_status_counts.php', {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                    cache: 'no-store',
                });
                if (!response.ok) {
                    throw new Error(`Request totals refresh failed (${response.status}).`);
                }
                const counts = await response.json();
                liveCounters.forEach((counter) => {
                    const key = counter.dataset.liveRequestCount;
                    if (Object.prototype.hasOwnProperty.call(counts, key)) {
                        counter.textContent = String(counts[key]);
                    }
                });

                if (requestPie) {
                    const pending = Number(counts.pending) || 0;
                    const approved = Number(counts.approved) || 0;
                    const declined = Number(counts.declined) || 0;
                    const total = pending + approved + declined;
                    const pendingPct = total ? pending / total * 100 : 0;
                    const approvedPct = total ? (pending + approved) / total * 100 : 0;
                    const background = total
                        ? `conic-gradient(#ffc107 0 ${pendingPct}%, #1be494 ${pendingPct}% ${approvedPct}%, #ff4d6a ${approvedPct}% 100%)`
                        : 'conic-gradient(rgba(255,255,255,.12) 0 100%)';
                    requestPie.style.background = background;
                    requestPie.setAttribute('aria-label', `Request status chart: ${pending} pending, ${approved} approved, ${declined} declined or flagged`);
                    const totalLabel = requestPie.querySelector('[data-live-request-total]');
                    if (totalLabel) totalLabel.textContent = String(total);
                }
            } catch (error) {
                console.error('Live request totals could not be refreshed.', error);
            }
        };

        refresh();
        window.setInterval(refresh, LIVE_REQUEST_COUNTS_INTERVAL_MS);
        document.addEventListener('visibilitychange', refresh);
    }

    function isGetForm(form) {
        if (!(form instanceof HTMLFormElement)) return false;
        const method = (form.getAttribute('method') || 'get').trim().toLowerCase();
        return method === 'get';
    }

    function buildFilterUrl(form) {
        const action = form.getAttribute('action') || window.location.pathname;
        const url = new URL(action, window.location.href);
        const data = new FormData(form);
        url.search = '';
        data.forEach((value, key) => {
            if (value === null || value === undefined) return;
            const str = String(value).trim();
            if (str === '') return;
            url.searchParams.append(key, str);
        });
        return url;
    }

    /**
     * Locate the results panel that belongs to this filter form.
     * Markup patterns: form inside .dashboard-card, results in a following .analytics-card
     * (sibling under main, or under a neighboring .col-* in a .row).
     */
    function findResultsPanel(form) {
        const card = form.closest('.dashboard-card');
        if (card) {
            let sib = card.nextElementSibling;
            while (sib) {
                if (sib.classList && sib.classList.contains('analytics-card')) return sib;
                sib = sib.nextElementSibling;
            }

            const col = card.closest('[class*="col-"]');
            if (col && col.parentElement) {
                let colSib = col.nextElementSibling;
                while (colSib) {
                    if (colSib.classList && colSib.classList.contains('analytics-card')) return colSib;
                    const inner = colSib.querySelector('.analytics-card');
                    if (inner) return inner;
                    colSib = colSib.nextElementSibling;
                }
            }
        }

        const root = form.closest('main') || document.body;
        const cards = root.querySelectorAll('.analytics-card');
        for (let i = 0; i < cards.length; i += 1) {
            if (form.compareDocumentPosition(cards[i]) & Node.DOCUMENT_POSITION_FOLLOWING) {
                return cards[i];
            }
        }
        return null;
    }

    function findResultsPanelInDoc(doc, formActionPath) {
        const forms = doc.querySelectorAll('form');
        for (let i = 0; i < forms.length; i += 1) {
            const f = forms[i];
            if (!isGetForm(f) || !f.querySelector('input[name="search"]')) continue;
            const action = f.getAttribute('action') || '';
            if (formActionPath && action) {
                const actionFile = action.split('?')[0].split('/').pop();
                if (actionFile && formActionPath && actionFile !== formActionPath) {
                    continue;
                }
            }
            const panel = findResultsPanel(f);
            if (panel) return { form: f, panel };
        }
        const main = doc.querySelector('main');
        const panel = (main || doc).querySelector('.analytics-card');
        return panel ? { form: null, panel } : null;
    }

    function syncClearButton(liveForm, fetchedForm) {
        if (!liveForm || !fetchedForm) return;
        const liveBtnRow = liveForm.querySelector('.d-flex.gap-2');
        const fetchedBtnRow = fetchedForm.querySelector('.d-flex.gap-2');
        if (!liveBtnRow || !fetchedBtnRow) return;

        liveBtnRow.querySelectorAll('a.btn').forEach((a) => a.remove());

        const fetchedClear = fetchedBtnRow.querySelector('a[title="Clear filters"]');
        if (fetchedClear) {
            liveBtnRow.appendChild(fetchedClear.cloneNode(true));
        }
    }

    function hardSubmit(form) {
        // Use native submit() so the submit event (which we preventDefault) is not re-fired.
        try {
            HTMLFormElement.prototype.submit.call(form);
        } catch (e) {
            console.warn('live-filter hard submit failed', e);
        }
    }

    function bindLiveFilter(form) {
        if (!isGetForm(form) || form.dataset.liveFilterBound === '1') return;

        const searchInput = form.querySelector('input[name="search"]');
        if (!searchInput) return;

        form.dataset.liveFilterBound = '1';
        let timer = null;
        let composing = false;
        let abortCtrl = null;
        let requestSeq = 0;

        const runFilter = async () => {
            const panel = findResultsPanel(form);
            if (!panel) {
                hardSubmit(form);
                return;
            }

            const url = buildFilterUrl(form);
            const seq = ++requestSeq;

            if (abortCtrl) {
                try { abortCtrl.abort(); } catch (_) { /* ignore */ }
            }
            abortCtrl = new AbortController();

            panel.classList.add('is-live-filtering');
            panel.setAttribute('aria-busy', 'true');

            try {
                const response = await fetch(url.toString(), {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
                    signal: abortCtrl.signal,
                    cache: 'no-store',
                });

                if (seq !== requestSeq) return;

                if (!response.ok) {
                    hardSubmit(form);
                    return;
                }

                const html = await response.text();
                if (seq !== requestSeq) return;

                const doc = new DOMParser().parseFromString(html, 'text/html');
                const actionAttr = form.getAttribute('action') || '';
                const actionPath = actionAttr.split('?')[0].split('/').pop() || '';
                const matched = findResultsPanelInDoc(doc, actionPath);

                if (!matched || !matched.panel) {
                    hardSubmit(form);
                    return;
                }

                panel.innerHTML = matched.panel.innerHTML;
                syncClearButton(form, matched.form);

                if (window.history && window.history.replaceState) {
                    window.history.replaceState(null, '', url.pathname + url.search);
                }

                document.dispatchEvent(new CustomEvent('livefilter:updated', {
                    detail: { form, panel, url: url.toString() },
                }));
            } catch (err) {
                if (err && err.name === 'AbortError') return;
                console.warn('live-filter fetch failed', err);
                hardSubmit(form);
            } finally {
                if (seq === requestSeq) {
                    panel.classList.remove('is-live-filtering');
                    panel.removeAttribute('aria-busy');
                }
            }
        };

        const scheduleFilter = () => {
            if (timer) clearTimeout(timer);
            timer = setTimeout(() => {
                timer = null;
                if (composing) return;
                runFilter();
            }, DEBOUNCE_MS);
        };

        const filterNow = () => {
            if (timer) {
                clearTimeout(timer);
                timer = null;
            }
            runFilter();
        };

        const clearButton = form.querySelector('.js-clear-filters');
        if (clearButton) {
            clearButton.addEventListener('click', () => {
                form.querySelectorAll('input[type="search"], input[type="text"], input[type="date"]').forEach((input) => {
                    input.value = '';
                });
                form.querySelectorAll('select').forEach((select) => {
                    select.selectedIndex = 0;
                });
                filterNow();
            });
        }

        searchInput.addEventListener('compositionstart', () => {
            composing = true;
        });
        searchInput.addEventListener('compositionend', () => {
            composing = false;
            scheduleFilter();
        });
        searchInput.addEventListener('input', () => {
            if (composing) return;
            scheduleFilter();
        });

        form.querySelectorAll('select').forEach((select) => {
            select.addEventListener('change', filterNow);
        });

        form.querySelectorAll('input[type="date"]').forEach((input) => {
            input.addEventListener('change', filterNow);
        });

        form.addEventListener('submit', (event) => {
            // Apply button / Enter — AJAX path instead of full navigation.
            event.preventDefault();
            filterNow();
        });
    }

    function initLiveFilters() {
        document.querySelectorAll('form').forEach((form) => {
            if (!isGetForm(form)) return;
            if (!form.querySelector('input[name="search"]')) return;
            bindLiveFilter(form);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            initLiveRequestCounts();
            initLiveFilters();
        });
    } else {
        initLiveRequestCounts();
        initLiveFilters();
    }
})();
