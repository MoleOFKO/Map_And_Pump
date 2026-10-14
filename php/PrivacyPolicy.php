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
            <p class="legal-updated"><i class="fa-regular fa-calendar me-2"></i>Last updated: October 14, 2026</p>
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
                <p>MAP &amp; PUMP is a school demonstration project, not a production service. You may provide an email address you control for sign-in, verification, invitations, secure payment-request access, and essential account or request-status notices. Company registration forms may collect company and contact details, a business licence number and document, and a company logo for the registration review. Only submit information and documents you are authorised to share. Do not submit passwords, wallet PINs, full payment credentials, or personal information about another person.</p>
                <p>For external payment requests, the application stores the server-set amount and recipient, payment category, selected method, generated reference, linked request or fuel fill, payer and recipient identifiers, status history, timestamps, and recipient notes. A payer may optionally attach one JPG, PNG, WEBP, or PDF receipt of up to 5 MB. The file is stored with the payment record and is available only to the payer and designated recipient through an authenticated or time-limited payment link. A receipt is evidence only and is not treated as payment confirmation. The application does not ask for or store payment account passwords or wallet PINs.</p>
                <p>The application stores account information, project records, account activity, and technical data needed to operate and secure the site, such as session data and server logs. Do not reuse a password from another service.</p>
            </section>

            <section>
                <h2>2. How information is used</h2>
                <p>Account and project information is used to operate and demonstrate the school project, authenticate accounts, send verification codes and essential account, security, invitation, and request-status notices, troubleshoot the application, assess coursework, and protect the test environment from misuse. Email addresses are not used for marketing.</p>
                <p>Any money transfer takes place separately through the payer’s bank or wallet. MAP &amp; PUMP does not initiate, collect, hold, or independently verify funds. A payment request, payer report, and recipient response are recorded separately; none is bank or wallet confirmation. Only a response recorded by the designated recipient may unlock a related project feature. Do not rely on the site for real fuel availability or business decisions.</p>
            </section>

            <section>
                <h2>3. Access and email delivery</h2>
                <p>The payer can view their payment record. For a public HQ application without an account, a random, time-limited link is sent to the owner email; anyone who can access that mailbox or link can view and report payment for that request, so keep the link private. The intended recipient can view and respond to the record. Admin accounts can review platform fees; the associated HQ can review fuel-payment records and their receipt evidence. Authorised project members and instructors may access underlying records or attachments when needed to operate, troubleshoot, or assess the demonstration. We do not sell account email addresses.</p>
                <p>Emails are sent through the email delivery service configured for the project. That service may process the recipient address, sender address, subject, message content, and delivery information to transmit and troubleshoot the email, under its own terms and practices. Email can also be visible to people with access to the recipient's mailbox. Do not assume project data or email is confidential.</p>
            </section>

            <section>
                <h2>4. Retention and deletion</h2>
                <p>Records in the active application may be reset, changed, or deleted when the project is demonstrated, assessed, repaired, or redeployed. There is no fixed retention period for this school demo. You may email the project team to request correction or removal of information linked to your account. Copies may remain in backups, server logs, or email-provider records until those systems' retention or deletion processes remove them.</p>
            </section>

            <section>
                <h2>5. Security</h2>
                <p>We use project-level safeguards, but this is a school demonstration environment and we cannot guarantee its security, availability, or confidentiality. Use a unique password, never share account credentials or wallet PINs, and submit only information you are authorised to provide.</p>
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
