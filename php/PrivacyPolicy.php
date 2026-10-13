<?php
$page_title = 'Privacy Policy | MAP & PUMP';
$page_description = 'How MAP & PUMP handles account email and test information in its school-project environment.';
$page_styles = ['/css/LegalPage.css'];
$support_email = 'map.and.pump@gmail.com';

require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/nav.php';
?>

<main class="legal-page">
    <div class="container">
        <header class="legal-hero">
            <span class="eyebrow">Privacy policy</span>
            <h1>School demo data,<br><span class="text-gradient-accent">with one email exception.</span></h1>
            <p>This policy explains how MAP &amp; PUMP handles information in its non-production school-project environment.</p>
            <p class="legal-updated"><i class="fa-regular fa-calendar me-2"></i>Last updated: October 13, 2026</p>
        </header>

        <section class="legal-quick-contact" aria-label="MAP & PUMP privacy contact details">
            <div>
                <span class="legal-card-label">Need privacy help?</span>
                <h2>Contact MAP &amp; PUMP support</h2>
                <p>For privacy questions, account data requests, or security concerns, email the project team.</p>
            </div>
            <div class="legal-contact-grid">
                <a class="legal-contact-item" href="mailto:<?= htmlspecialchars($support_email, ENT_QUOTES, 'UTF-8') ?>">
                    <i class="fa-solid fa-envelope"></i>
                    <span>
                        <strong>Email</strong>
                        <?= htmlspecialchars($support_email, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </a>
            </div>
        </section>

        <article class="legal-content">
            <section>
                <h2>1. Information you may enter</h2>
                <p>MAP &amp; PUMP is a school demonstration project, not a production service. You may provide a real email address that you control or are authorised to use in an email field required for an account, invitation, or project contact. It may be used for sign-in, verification, and essential account, security, invitation, or request-status notices. Use fictional information for all other profile and project fields. Do not submit real names, phone numbers, street addresses, vehicle or licence numbers, company details, identity documents, payment details, receipts, photographs, precise location data, or personal information about another person.</p>
                <p>The application stores account information, project records, account activity, and technical data needed to operate and secure the site, such as session data and server logs. Do not reuse a password from another service.</p>
            </section>

            <section>
                <h2>2. How information is used</h2>
                <p>Account and project information is used to operate and demonstrate the school project, authenticate accounts, send verification codes and essential account, security, invitation, and request-status notices, troubleshoot the application, assess coursework, and protect the test environment from misuse. We do not use email addresses for marketing.</p>
                <p>The website does not process payments. Any real-world money exchange, if it occurs, takes place outside the website and is not managed or guaranteed by this project. Do not enter payment details or rely on the site for real bookings, payments, fuel availability, or business decisions.</p>
            </section>

            <section>
                <h2>3. Access and email delivery</h2>
                <p>Project members, instructors, and authorised demonstration participants may access information when needed to operate, troubleshoot, or assess the project. We do not sell account email addresses.</p>
                <p>Emails are sent through the email delivery service configured for the project. That service may process the recipient address, sender address, subject, message content, and delivery information to transmit and troubleshoot the email, under its own terms and practices. Email can also be visible to people with access to the recipient's mailbox. Do not assume project data or email is confidential.</p>
            </section>

            <section>
                <h2>4. Retention and deletion</h2>
                <p>Records in the active application may be reset, changed, or deleted when the project is demonstrated, assessed, repaired, or redeployed. There is no fixed retention period for this school demo. You may email the project team to request correction or removal of information linked to your account. Copies may remain in backups, server logs, or email-provider records until those systems' retention or deletion processes remove them.</p>
            </section>

            <section>
                <h2>5. Security</h2>
                <p>We use project-level safeguards, but this is a school demonstration environment and we cannot guarantee its security, availability, or confidentiality. Keep account access secure and do not submit real personal or sensitive information beyond the email address described above.</p>
            </section>

            <section>
                <h2>6. Your choices</h2>
                <p>Do not register if you do not agree to this policy. You may ask the project team to correct or remove information linked to your account. Some records may already have been reset or included in assessment materials, and copies in backups or delivery-provider logs may take longer to expire.</p>
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
                <p>If you have a question or concern about this policy or your information, email the MAP &amp; PUMP project team at <a href="mailto:<?= htmlspecialchars($support_email, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($support_email, ENT_QUOTES, 'UTF-8') ?></a>.</p>
            </section>
        </article>
    </div>
</main>

<?php require __DIR__ . '/partials/footer.php'; ?>
