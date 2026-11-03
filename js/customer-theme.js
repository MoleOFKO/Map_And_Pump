/* Customer portal day/night mode; stored independently from the admin preference. */
(function () {
    const themeKey = 'mapPumpCustomerTheme';
    const applyTheme = (theme) => {
        const isLight = theme === 'light';
        document.documentElement.dataset.theme = theme;
        document.body.classList.toggle('customer-light-mode', isLight);
        document.body.dataset.theme = theme;

        const toggle = document.querySelector('.js-customer-theme-toggle');
        if (toggle) {
            toggle.checked = isLight;
            toggle.setAttribute('aria-checked', String(isLight));
        }

        try {
            localStorage.setItem(themeKey, theme);
        } catch (e) {
            // Ignore storage failures; preference is non-critical.
        }
    };

    const initTheme = () => {
        const savedTheme = (() => {
            try {
                return localStorage.getItem(themeKey) || 'dark';
            } catch (e) {
                return 'dark';
            }
        })();

        applyTheme(savedTheme);

        const toggle = document.querySelector('.js-customer-theme-toggle');
        if (toggle) {
            toggle.addEventListener('change', () => applyTheme(toggle.checked ? 'light' : 'dark'));
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initTheme, { once: true });
    } else {
        initTheme();
    }
})();
