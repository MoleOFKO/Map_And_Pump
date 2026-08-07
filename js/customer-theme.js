/* Customer portal day/night mode; stored independently from the admin preference. */
(function () {
    const themeKey = 'mapPumpCustomerTheme';
    const toggle = document.querySelector('.js-customer-theme-toggle');
    const applyTheme = (theme) => {
        const isLight = theme === 'light';
        document.documentElement.dataset.theme = theme;
        document.body.classList.toggle('customer-light-mode', isLight);
        document.body.dataset.theme = theme;
        if (toggle) {
            toggle.checked = isLight;
            toggle.setAttribute('aria-checked', String(isLight));
        }
        localStorage.setItem(themeKey, theme);
    };

    applyTheme(localStorage.getItem(themeKey) || 'dark');
    if (toggle) toggle.addEventListener('change', () => applyTheme(toggle.checked ? 'light' : 'dark'));
})();
