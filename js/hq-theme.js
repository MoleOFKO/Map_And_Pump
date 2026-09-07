/* Applied in the head so every HQ page paints in the saved theme. */
(() => {
    const key = 'mapPumpHQTheme';
    const normalize = value => value === 'light' ? 'light' : 'dark';
    let initial = 'dark';
    try {
        initial = localStorage.getItem(key);
    } catch (_) { /* Storage may be disabled; switching still works in this session. */ }
    try {
        if (window.parent !== window && window.parent.HQTheme) initial = window.parent.HQTheme.current();
    } catch (_) { /* A standalone page uses its own preference. */ }
    function apply(value) {
        const theme = normalize(value);
        document.documentElement.dataset.hqTheme = theme;
        document.documentElement.dataset.bsTheme = theme;
        document.querySelectorAll('[data-hq-theme-toggle]').forEach(button => {
            button.setAttribute('aria-pressed', String(theme === 'light'));
            button.setAttribute('aria-label', theme === 'light' ? 'Switch to dark mode' : 'Switch to light mode');
            button.title = button.getAttribute('aria-label');
        });
        document.querySelectorAll('iframe').forEach(frame => {
            try { frame.contentWindow.HQTheme?.apply(theme); } catch (_) { /* Ignore unrelated frames. */ }
        });
    }
    window.HQTheme = { apply, current: () => document.documentElement.dataset.hqTheme };
    apply(initial);
    document.addEventListener('DOMContentLoaded', () => {
        apply(window.HQTheme.current());
        document.querySelectorAll('[data-hq-theme-toggle]').forEach(button => {
            button.addEventListener('click', () => {
                const theme = window.HQTheme.current() === 'dark' ? 'light' : 'dark';
                apply(theme);
                try { localStorage.setItem(key, theme); } catch (_) { /* Keep the in-memory preference. */ }
            });
        });
        document.querySelectorAll('iframe').forEach(frame => frame.addEventListener('load', () => apply(window.HQTheme.current())));
    });
    window.addEventListener('storage', event => {
        if (event.key === key || event.key === null) apply(event.newValue);
    });
})();
