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
