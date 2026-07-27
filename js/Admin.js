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
