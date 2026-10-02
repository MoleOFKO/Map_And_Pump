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
 * Debounced auto-submit on search input; immediate submit on companion select changes.
 * Targets GET forms that contain input[name="search"]. Skips hire-account AJAX search
 * (no name="search") and all POST modals.
 */
(function () {
    const DEBOUNCE_MS = 350;

    function isGetForm(form) {
        if (!(form instanceof HTMLFormElement)) return false;
        const method = (form.getAttribute('method') || 'get').trim().toLowerCase();
        return method === 'get';
    }

    function submitForm(form) {
        if (!form || form.dataset.liveFilterSubmitting === '1') return;
        form.dataset.liveFilterSubmitting = '1';
        try {
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.submit();
            }
        } catch (e) {
            form.dataset.liveFilterSubmitting = '0';
            console.warn('live-filter submit failed', e);
        }
    }

    function bindLiveFilter(form) {
        if (!isGetForm(form) || form.dataset.liveFilterBound === '1') return;

        const searchInput = form.querySelector('input[name="search"]');
        if (!searchInput) return;

        form.dataset.liveFilterBound = '1';
        let timer = null;
        let composing = false;

        const scheduleSubmit = () => {
            if (timer) clearTimeout(timer);
            timer = setTimeout(() => {
                timer = null;
                if (composing) return;
                submitForm(form);
            }, DEBOUNCE_MS);
        };

        searchInput.addEventListener('compositionstart', () => {
            composing = true;
        });
        searchInput.addEventListener('compositionend', () => {
            composing = false;
            scheduleSubmit();
        });
        searchInput.addEventListener('input', () => {
            if (composing) return;
            scheduleSubmit();
        });

        form.querySelectorAll('select').forEach((select) => {
            select.addEventListener('change', () => {
                if (timer) {
                    clearTimeout(timer);
                    timer = null;
                }
                submitForm(form);
            });
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
        document.addEventListener('DOMContentLoaded', initLiveFilters);
    } else {
        initLiveFilters();
    }
})();
