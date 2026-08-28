<?php
$page_title = 'Privacy Policy | MAP & PUMP';
$page_description = 'How MAP & PUMP collects, uses, and protects information.';
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
            <h1>Your information,<br><span class="text-gradient-accent">handled with care.</span></h1>
            <p>This policy explains how MAP &amp; PUMP handles information when you use our website and services.</p>
            <p class="legal-updated"><i class="fa-regular fa-calendar me-2"></i>Last updated: August 20, 2026</p>
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
                <h2>1. Information we collect</h2>
                <p>We collect information you give us when you create or use an account, make a fuel booking, register a company, contact us, or update your profile. This can include your name, email address, phone number, account details, vehicle or license information, booking details, and company information.</p>
                <p>We may also collect technical information needed to operate and secure the service, such as device and browser details, IP address, sign-in activity, and pages or features used. If you choose to use location-based features, we may use your location to show nearby stations and relevant availability information.</p>
            </section>

            <section>
                <h2>2. How we use information</h2>
                <p>We use information to provide and improve MAP &amp; PUMP, including to manage accounts, show stations and fuel availability, process bookings, verify users and companies, provide support, prevent misuse, and keep the service secure.</p>
                <p>When you use QR features, such as scanning a station QR code, booking QR code, or customer verification QR code, we use the QR information only to identify the relevant booking, station, account, or service action. QR scans may help confirm visits, reduce manual entry errors, and make station service faster, but they are not used to collect unrelated personal information.</p>
                <p>We may use contact information to send essential service messages, such as account, booking, security, or policy updates. We will ask for permission where the law requires it for other communications.</p>
            </section>

            <section>
                <h2>3. When information is shared</h2>
                <p>We share only the information needed to deliver the service. For example, booking and vehicle details may be made available to the station handling your booking; company registration details may be provided to our review team; and information may be processed by service providers that help us run MAP &amp; PUMP.</p>
                <p>We may also disclose information when required by law, to protect the rights and safety of MAP &amp; PUMP, users, or the public, or as part of a business transfer. We do not share information for purposes that are incompatible with this policy without an appropriate notice or permission where required.</p>
            </section>

            <section>
                <h2>4. Data retention</h2>
                <p>We keep information for as long as it is reasonably needed to provide the service, meet legal or operational obligations, resolve disputes, and enforce our agreements. The retention period depends on the type of information and why it was collected.</p>
                <p>Account profile information is normally kept while your account is active. Booking, QR scan, station visit, license, verification, and support records may be kept for up to 3 years after the related service activity so we can audit bookings, handle disputes, and support station operations. Security logs, sign-in records, and abuse-prevention records may be kept for up to 12 months unless a longer period is required for investigation, legal compliance, or system protection.</p>
                <p>When information is no longer needed, we delete it, anonymise it, or keep only the minimum records required by law or legitimate operational needs.</p>
            </section>

            <section>
                <h2>5. Security</h2>
                <p>We use reasonable technical and organisational measures to protect information. No online service can guarantee complete security, so please keep your password confidential and let us know promptly if you believe your account has been accessed without permission.</p>
                <p>MAP &amp; PUMP stores service data in controlled database tables on the application server and uses server-side PHP tools, MySQL database storage, session controls, password hashing, input validation, access roles, and activity logs to help protect personal, booking, station, QR, and license information. Uploaded license or profile files are stored in restricted application upload folders and linked to the relevant account records.</p>
            </section>

            <section>
                <h2>6. Your choices and rights</h2>
                <p>Depending on the laws that apply to you, you may have rights to access, correct, delete, restrict, or object to certain uses of your personal information, and to withdraw consent where processing relies on it. You can update some information through your account. To make another request, contact the MAP &amp; PUMP support team through the support channel available in your account.</p>
            </section>

            <section>
                <h2>7. Cookies and similar technologies</h2>
                <p>We may use cookies and similar technologies that are necessary for the website and sign-in features to work, to remember preferences, and to understand how the service is used. Where required, we will provide choices for non-essential cookies.</p>
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
