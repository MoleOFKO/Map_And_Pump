<?php
$page_title = 'Register your company | MAP & PUMP';
$page_description = 'Submit your company details to join MAP & PUMP.';
$page_styles = ['/css/CompanyRegistration.css'];

require __DIR__ . '/../partials/head.php';
require __DIR__ . '/../partials/nav.php';
?>

<main class="company-registration">
    <div class="container">
        <div class="registration-intro text-center mx-auto">
            <span class="eyebrow justify-content-center d-inline-flex">Company registration</span>
            <h1>Put your company on the map.</h1>
            <p>Tell us about your company and provide your business license for review.</p>
        </div>

        <form class="company-registration-form" id="company-registration-form" novalidate>
            <div id="registration-form-content">
                <section class="registration-section">
                    <div class="registration-section-title">
                        <span class="registration-step">01</span>
                        <div><h2>Company details</h2><p>How your company will appear in MAP &amp; PUMP.</p></div>
                    </div>
                    <div class="row g-4">
                        <div class="col-md-5">
                            <label class="upload-field" for="company-logo">
                                <span class="upload-icon"><i class="fa-solid fa-image"></i></span>
                                <span class="upload-copy"><strong id="company-logo-label">Upload company logo</strong><small>PNG, JPG, or WEBP</small></span>
                                <input id="company-logo" name="company_logo" type="file" accept="image/png,image/jpeg,image/webp" required>
                            </label>
                        </div>
                        <div class="col-md-7">
                            <label for="company-name" class="form-label">Company name</label>
                            <input id="company-name" name="company_name" class="form-control" type="text" autocomplete="organization" required>
                        </div>
                        <div class="col-12">
                            <label for="company-address" class="form-label">Company address</label>
                            <textarea id="company-address" name="company_address" class="form-control" rows="3" autocomplete="street-address" required></textarea>
                        </div>
                    </div>
                </section>

                <section class="registration-section">
                    <div class="registration-section-title">
                        <span class="registration-step">02</span>
                        <div><h2>Business license</h2><p>We use this to verify your company.</p></div>
                    </div>
                    <div class="row g-4">
                        <div class="col-md-6"><label for="license-id" class="form-label">Company license ID</label><input id="license-id" name="license_id" class="form-control" type="text" required></div>
                        <div class="col-md-6"><label for="license-expiry" class="form-label">License expiry date</label><input id="license-expiry" name="license_expiry" class="form-control" type="date" required></div>
                        <div class="col-12">
                            <label class="upload-field" for="license-photo">
                                <span class="upload-icon"><i class="fa-solid fa-file-arrow-up"></i></span>
                                <span class="upload-copy"><strong id="license-photo-label">Upload license photo</strong><small>PNG, JPG, WEBP, or PDF</small></span>
                                <input id="license-photo" name="license_photo" type="file" accept="image/png,image/jpeg,image/webp,application/pdf" required>
                            </label>
                        </div>
                    </div>
                </section>

                <section class="registration-section">
                    <div class="registration-section-title">
                        <span class="registration-step">03</span>
                        <div><h2>Contact details</h2><p>Separate contacts for the owner and company.</p></div>
                    </div>
                    <div class="row g-4">
                        <div class="col-md-6"><label for="owner-email" class="form-label">Company owner’s email</label><input id="owner-email" name="owner_email" class="form-control" type="email" autocomplete="email" required></div>
                        <div class="col-md-6"><label for="owner-phone" class="form-label">Company owner’s phone number</label><input id="owner-phone" name="owner_phone" class="form-control" type="tel" autocomplete="tel" required></div>
                        <div class="col-md-6"><label for="company-email" class="form-label">Company email</label><input id="company-email" name="company_email" class="form-control" type="email" autocomplete="email" required></div>
                        <div class="col-md-6"><label for="company-phone" class="form-label">Company phone number</label><input id="company-phone" name="company_phone" class="form-control" type="tel" autocomplete="tel" required></div>
                    </div>
                </section>

                <section class="registration-section payment-section">
                    <div class="registration-section-title">
                        <span class="registration-step">04</span>
                        <div><h2>Payment method</h2><p>Choose the payment option your company will use.</p></div>
                    </div>

                    <div id="payment-method-selector-card" class="payment-card">
                        <div class="row g-4">
                            <div class="col-12">
                                <label for="payment-method" class="form-label">Select payment method</label>
                                <select id="payment-method" name="payment_method" class="form-select form-control" required>
                                    <option value="">Choose a payment method</option>
                                    <option value="bank-transfer">Bank transfer</option>
                                    <option value="tabby">Tabby</option>
                                    <option value="stc-pay">STC Pay</option>
                                    <option value="mada">Mada</option>
                                </select>
                            </div>
                        </div>
                        <div class="registration-actions payment-actions">
                            <button type="button" id="continue-payment" class="btn btn-primary btn-lg px-5 py-3">Continue to payment <i class="fa-solid fa-arrow-right ms-2"></i></button>
                        </div>
                    </div>

                    <div id="payment-details-card" class="payment-card is-hidden">
                        <div class="payment-method-summary">
                            <span class="payment-method-label">Selected payment method</span>
                            <strong id="selected-payment-name">Bank transfer</strong>
                        </div>
                        <div class="row g-4 mt-1">
                            <div class="col-12">
                                <label for="payment-phone" class="form-label">Phone number for payment</label>
                                <input id="payment-phone" name="payment_phone" class="form-control" type="tel" readonly required>
                            </div>
                            <div class="col-12">
                                <label class="upload-field" for="payment-receipt">
                                    <span class="upload-icon"><i class="fa-solid fa-file-invoice-dollar"></i></span>
                                    <span class="upload-copy"><strong id="payment-receipt-label">Upload payment receipt</strong><small>PNG, JPG, WEBP, or PDF</small></span>
                                    <input id="payment-receipt" name="payment_receipt" type="file" accept="image/png,image/jpeg,image/webp,application/pdf" required>
                                </label>
                            </div>
                        </div>
                        <div class="payment-footer">
                            <button type="button" id="change-payment-method" class="btn btn-outline-light btn-sm">Change payment method</button>
                        </div>
                    </div>
                </section>

                <div class="registration-actions"><button type="submit" class="btn btn-primary btn-lg px-5 py-3">Submit registration <i class="fa-solid fa-arrow-right ms-2"></i></button></div>
                <p id="registration-form-status" class="registration-form-status" aria-live="polite"></p>
            </div>

            <div id="registration-success-state" class="registration-success-state is-hidden" aria-live="polite">
                <div class="success-badge"><i class="fa-solid fa-circle-check"></i></div>
                <h2>Registration Successful</h2>
                <p>Wait for the admin approval.</p>
                <p>We will send you an email when your account is ready.</p>
            </div>
        </form>
    </div>
</main>

<?php require __DIR__ . '/../partials/footer.php'; ?>
<script src="/js/company-registration.js"></script>
