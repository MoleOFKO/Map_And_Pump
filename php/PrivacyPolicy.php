<?php
$page_title = 'Privacy Policy | MAP & PUMP';
$page_description = 'How MAP & PUMP handles test information in its school-project environment.';
$page_styles = ['/css/LegalPage.css'];
$support_phone = '09-421234567';
$support_email = 'support@mapandpump.com';
$support_location = 'Yangon Region, Myanmar';
$support_map_url = 'https://www.google.com/maps/search/?api=1&query=Yangon%20Region%2C%20Myanmar';

require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/nav.php';
?>

<main class="legal-page">
    <div class="container">
        <header class="legal-hero">
            <span class="eyebrow">Privacy policy</span>
            <h1>Test data only,<br><span class="text-gradient-accent">never real personal data.</span></h1>
            <p>This policy explains how MAP &amp; PUMP handles information in its non-production school-project environment.</p>
            <p class="legal-updated"><i class="fa-regular fa-calendar me-2"></i>Last updated: September 17, 2026</p>
        </header>

        <section class="legal-quick-contact" aria-label="MAP & PUMP privacy contact details">
            <div>
                <span class="legal-card-label">Need privacy help?</span>
                <h2>Contact MAP &amp; PUMP support</h2>
                <p>For privacy questions, account data requests, or urgent security concerns, use the contact details below.</p>
            </div>
            <div class="legal-contact-grid">
                <a class="legal-contact-item" href="tel:<?= htmlspecialchars(str_replace('-', '', $support_phone), ENT_QUOTES, 'UTF-8') ?>">
                    <i class="fa-solid fa-phone"></i>
                    <span>
                        <strong>Phone</strong>
                        <?= htmlspecialchars($support_phone, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </a>
                <a class="legal-contact-item" href="mailto:<?= htmlspecialchars($support_email, ENT_QUOTES, 'UTF-8') ?>">
                    <i class="fa-solid fa-envelope"></i>
                    <span>
                        <strong>Email</strong>
                        <?= htmlspecialchars($support_email, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </a>
                <a class="legal-contact-item" href="<?= htmlspecialchars($support_map_url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
                    <i class="fa-solid fa-location-dot"></i>
                    <span>
                        <strong>Map location</strong>
                        <?= htmlspecialchars($support_location, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </a>
            </div>
        </section>

        <article class="legal-content">
            <section>
                <h2>1. Test information only</h2>
                <p>This is a school project, not a production service. Users must enter invented test data only. Do not provide real names, email addresses, phone numbers, addresses, vehicle or licence numbers, company details, passwords, identity documents, payment details, receipts, photographs, or location data.</p>
                <p>The application may store the test information entered during a demonstration, account activity, and basic technical data required for the site to operate, such as browser sessions and server logs.</p>
            </section>

            <section>
                <h2>2. How test information is used</h2>
                <p>Test information is used only to demonstrate project features, troubleshoot the application, assess coursework, and protect the test environment from misuse. It must not be used for real bookings, payments, verification, marketing, or business decisions.</p>
            </section>

            <section>
                <h2>3. Sharing</h2>
                <p>Test information may be visible to project members, teachers, and authorised demonstration participants who need it to evaluate or operate the school project. Do not assume any submitted data is private or confidential.</p>
            </section>

            <section>
                <h2>4. Retention and deletion</h2>
                <p>Test records may be reset, changed, or deleted without notice when the project is demonstrated, assessed, repaired, or redeployed. If real data is entered accidentally, contact the project team immediately to request removal; we will make a reasonable effort to remove it from the active test environment.</p>
            </section>

            <section>
                <h2>5. Security</h2>
                <p>We use reasonable project-level safeguards, but this is a test environment and we cannot guarantee its security, availability, or confidentiality. This is another reason never to submit real personal or sensitive data.</p>
            </section>

            <section>
                <h2>6. Your choices</h2>
                <p>Do not use the site if you do not accept this test-data policy. You can ask the project team to remove test records linked to an account, but records may already have been reset or included in assessment materials.</p>
            </section>

            <section>
                <h2>7. Local browser storage</h2>
                <p>We use browser local storage to remember that you acknowledged the school-project warning and may use session cookies needed for sign-in features. You can clear these using your browser settings.</p>
            </section>

            <section>
                <h2>8. Changes to this policy</h2>
                <p>We may update this policy as our service or legal requirements change. We will post the updated version here and revise the "Last updated" date. If a change is material, we will provide additional notice where appropriate.</p>
            </section>

            <section>
                <h2>9. Contact us</h2>
                <p>If you have a question or concern about this policy or your information, contact the MAP &amp; PUMP support team through the support channel available in your account, by phone at <a href="tel:<?= htmlspecialchars(str_replace('-', '', $support_phone), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($support_phone, ENT_QUOTES, 'UTF-8') ?></a>, by email at <a href="mailto:<?= htmlspecialchars($support_email, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($support_email, ENT_QUOTES, 'UTF-8') ?></a>, or at our map location: <a href="<?= htmlspecialchars($support_map_url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?= htmlspecialchars($support_location, ENT_QUOTES, 'UTF-8') ?></a>.</p>
            </section>
        </article>
    </div>
</main>

<?php require __DIR__ . '/partials/footer.php'; ?>
