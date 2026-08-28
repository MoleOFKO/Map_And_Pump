<?php
$page_title = 'Contact Us | MAP & PUMP';
$page_description = 'Get in touch with MAP & PUMP by email, phone, or Facebook.';
$active_nav = 'contact';

require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/nav.php';
?>

<section class="page-header">
    <div class="container">
        <div class="row align-items-end g-4">
            <div class="col-lg-8" data-reveal>
                <span class="eyebrow">MAP &amp; PUMP support</span>
                <h1 class="page-title">Let&apos;s keep<br><span class="text-gradient-accent">fuel moving.</span></h1>
                <p class="section-lead mb-0">
                    Have a question about stations, bookings, or your company account? Reach our team
                    through the contact channel that works best for you.
                </p>
            </div>
            <div class="col-lg-4" data-reveal style="--reveal-delay:120ms">
                <div class="updated-card">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="pulse-dot"></span>
                        <span class="updated-label">Support channel</span>
                    </div>
                    <div class="updated-time">We&apos;re here to help</div>
                    <p class="small text-dim mb-0 mt-2">Send us a message or connect with MAP &amp; PUMP on Facebook.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="section-pad pt-0">
    <div class="container">
        <div class="row g-4">
            <div class="col-md-4" data-reveal>
                <a class="feature-card d-block h-100" href="mailto:mapa.and.pump@gmail.com">
                    <div class="icon-shape mb-4"><i class="fa-solid fa-envelope"></i></div>
                    <h3>Email us</h3>
                    <p class="mb-0">mapa.and.pump@gmail.com</p>
                </a>
            </div>
            <div class="col-md-4" data-reveal style="--reveal-delay:80ms">
                <a class="feature-card d-block h-100" href="tel:09888788889">
                    <div class="icon-shape mb-4"><i class="fa-solid fa-phone"></i></div>
                    <h3>Call us</h3>
                    <p class="mb-0">09888788889</p>
                </a>
            </div>
            <div class="col-md-4" data-reveal style="--reveal-delay:160ms">
                <a class="feature-card d-block h-100" href="https://www.facebook.com/profile.php?id=61593561616596" target="_blank" rel="noopener">
                    <div class="icon-shape mb-4"><i class="fa-brands fa-facebook-f"></i></div>
                    <h3>Facebook</h3>
                    <p class="mb-0">Visit our Facebook page</p>
                </a>
            </div>
        </div>
    </div>
</section>

<section class="section-pad pt-0">
    <div class="container">
        <div class="bg-gradient-business text-center px-3 py-5 px-md-5" data-reveal>
            <div class="max-width-md mx-auto position-relative py-3">
                <span class="eyebrow justify-content-center d-inline-flex">Need a faster answer?</span>
                <h2 class="section-title">We&apos;d like to hear from you.</h2>
                <p class="text-light opacity-75 mb-0 px-md-4">
                    Tell us what you need and the MAP &amp; PUMP team will help you find the right next step.
                </p>
            </div>
        </div>
    </div>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
