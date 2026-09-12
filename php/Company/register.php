<?php
$page_title = 'Register your company | MAP & PUMP';
$page_description = 'Submit your company details to join MAP & PUMP.';
$page_styles = ['/css/CompanyRegistration.css'];

/* ==========================================================================
   PAYMENT RECEIVING ACCOUNTS  — edit these values only
   'number' is what the user is told to transfer to.
   ========================================================================== */
$payment_receiver_name = 'MAP & PUMP Co., Ltd';
$payment_default_phone = '09 795 123 456';

$payment_accounts = [
    'KPay'     => ['label' => 'KPay phone number',     'number' => $payment_default_phone],
    'Wave Pay' => ['label' => 'Wave Pay phone number', 'number' => $payment_default_phone],
    'AYA Pay'  => ['label' => 'AYA Pay phone number',  'number' => $payment_default_phone],
    'CB Pay'   => ['label' => 'CB Pay phone number',   'number' => $payment_default_phone],
    'AYA Bank'  => ['label' => 'AYA Bank account number',  'number' => '0012 3456 7890 1234', 'phone' => $payment_default_phone],
    'CB Bank'   => ['label' => 'CB Bank account number',   'number' => '0022 3456 7890 1234', 'phone' => $payment_default_phone],
    'KBZ Bank'  => ['label' => 'KBZ Bank account number',  'number' => '0032 3456 7890 1234', 'phone' => $payment_default_phone],
    'YOMA Bank' => ['label' => 'YOMA Bank account number', 'number' => '0042 3456 7890 1234', 'phone' => $payment_default_phone],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $respond = static function (bool $success, string $message, int $status = 200, array $extra = []): never {
        http_response_code($status);
        echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
        exit;
    };

    $iniBytes = static function (string $value): int {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $unit = strtolower($value[strlen($value) - 1]);
        $bytes = (float) $value;
        return (int) match ($unit) {
            'g' => $bytes * 1024 * 1024 * 1024,
            'm' => $bytes * 1024 * 1024,
            'k' => $bytes * 1024,
            default => $bytes,
        };
    };
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postMaxBytes = $iniBytes((string) ini_get('post_max_size'));
    if ($contentLength > 0 && $postMaxBytes > 0 && $contentLength > $postMaxBytes) {
        $respond(false, 'The uploaded files are too large. Please keep the total upload under 24 MB.', 413);
    }

    $companyName = trim((string) ($_POST['company_name'] ?? ''));
    $companyAddress = trim((string) ($_POST['company_address'] ?? ''));
    $licenseId = trim((string) ($_POST['license_id'] ?? ''));
    $licenseExpiry = trim((string) ($_POST['license_expiry'] ?? ''));
    $ownerEmail = strtolower(trim((string) ($_POST['owner_email'] ?? '')));
    $ownerPhone = trim((string) ($_POST['owner_phone'] ?? ''));
    $companyEmail = strtolower(trim((string) ($_POST['company_email'] ?? '')));
    $companyPhone = trim((string) ($_POST['company_phone'] ?? ''));
    $paymentMethod = trim((string) ($_POST['payment_method'] ?? ''));
    $bankName = trim((string) ($_POST['bank_name'] ?? ''));

    $allowedMethods = ['Bank transfer', 'KPay', 'Wave Pay', 'AYA Pay', 'CB Pay'];
    $allowedBanks = ['AYA Bank', 'CB Bank', 'KBZ Bank', 'YOMA Bank'];
    $expiry = DateTime::createFromFormat('!Y-m-d', $licenseExpiry);
    $expiryErrors = DateTime::getLastErrors();
    $expiryValid = $expiry && ($expiryErrors === false || ($expiryErrors['warning_count'] === 0 && $expiryErrors['error_count'] === 0)) && $expiry->format('Y-m-d') === $licenseExpiry;

    $validationErrors = [];
    if ($companyName === '') $validationErrors[] = 'company name';
    if ($companyAddress === '') $validationErrors[] = 'company address';
    if ($licenseId === '') $validationErrors[] = 'license ID';
    if (!$expiryValid) $validationErrors[] = 'license expiry date';
    if (!filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) $validationErrors[] = 'owner email';
    if (!filter_var($companyEmail, FILTER_VALIDATE_EMAIL)) $validationErrors[] = 'company email';
    if ($ownerPhone === '') $validationErrors[] = 'owner phone';
    if ($companyPhone === '') $validationErrors[] = 'company phone';
    if (!in_array($paymentMethod, $allowedMethods, true)) $validationErrors[] = 'payment method';
    if ($paymentMethod === 'Bank transfer' && !in_array($bankName, $allowedBanks, true)) $validationErrors[] = 'bank';
    if ($validationErrors) {
        $respond(false, 'Please check: ' . implode(', ', $validationErrors) . '.', 422);
    }

    if (strlen($companyName) > 255 || strlen($companyAddress) > 255 || strlen($licenseId) > 255
        || strlen($ownerEmail) > 255 || strlen($companyEmail) > 255 || strlen($ownerPhone) > 50 || strlen($companyPhone) > 50) {
        $respond(false, 'One or more fields are longer than allowed.', 422);
    }

    $uploadErrorMessages = [
        UPLOAD_ERR_INI_SIZE => 'One of the uploaded files is larger than the server allows.',
        UPLOAD_ERR_FORM_SIZE => 'One of the uploaded files is larger than the form allows.',
        UPLOAD_ERR_PARTIAL => 'One of the uploaded files was only partially uploaded.',
        UPLOAD_ERR_NO_FILE => 'Please upload all required documents.',
        UPLOAD_ERR_NO_TMP_DIR => 'The server upload folder is not available.',
        UPLOAD_ERR_CANT_WRITE => 'The server could not write one of the uploaded files.',
        UPLOAD_ERR_EXTENSION => 'A server extension stopped one of the uploaded files.',
    ];

    $detectMime = static function (string $path): string {
        if (class_exists('finfo')) {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }

        if (function_exists('mime_content_type')) {
            $mime = mime_content_type($path);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }

        throw new RuntimeException('The server cannot verify uploaded file formats right now.');
    };

    $uploadDir = __DIR__ . '/uploads/';
    $upload = static function (string $field, array $allowedMimes, int $maxBytes) use ($uploadDir, $uploadErrorMessages, $detectMime): string {
        if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
            throw new RuntimeException('Please upload all required documents.');
        }
        $file = $_FILES[$field];
        $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            throw new RuntimeException($uploadErrorMessages[$uploadError] ?? 'One of the uploaded files could not be received.');
        }
        if ((int) $file['size'] > $maxBytes || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('One of the uploaded files is too large or invalid.');
        }
        $mime = $detectMime((string) $file['tmp_name']);
        if (!in_array($mime, $allowedMimes, true)) {
            throw new RuntimeException('One of the uploaded files has an unsupported format.');
        }
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
        $fileName = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('The upload folder could not be created.');
        }
        if (!move_uploaded_file($file['tmp_name'], $uploadDir . $fileName)) {
            throw new RuntimeException('An uploaded file could not be saved.');
        }
        return '/php/Company/uploads/' . $fileName;
    };

    $savedFiles = [];
    try {
        require_once __DIR__ . '/../conn.php';
        $duplicate = $pdo->prepare("SELECT request_id FROM system_requests WHERE request_type = 'hq_registration' AND status = 'pending' AND (headquarter_email = ? OR headquarter_owner_email = ?) LIMIT 1");
        $duplicate->execute([$companyEmail, $ownerEmail]);
        if ($duplicate->fetchColumn()) {
            $respond(false, 'A pending registration already exists for this email address.', 409);
        }

        $savedFiles[] = $logoPath = $upload('company_logo', ['image/jpeg', 'image/png', 'image/webp'], 5 * 1024 * 1024);
        $savedFiles[] = $licensePath = $upload('license_photo', ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], 10 * 1024 * 1024);
        $savedFiles[] = $receiptPath = $upload('payment_receipt', ['image/jpeg', 'image/png', 'image/webp'], 5 * 1024 * 1024);

        $details = json_encode([
            'company_address' => $companyAddress,
            'owner_phone' => $ownerPhone,
            'company_phone' => $companyPhone,
            'license_id' => $licenseId,
            'payment_method' => $paymentMethod,
            'bank_name' => $bankName,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $reference = 'HQ-REG-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            "INSERT INTO system_requests
                (reference_code, request_type, status, submitter_user_id, role_id, sender_entity, sender_name, sender_email,
                 location, request_details, document_name, document_path, headquarter_owner_email, headquarter_email,
                 headquarter_company_logo, headquarter_contact_number, headquarter_expire_at)
             VALUES (?, 'hq_registration', 'pending', ?, 3, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $submitterId = (int) ($_SESSION['user_id'] ?? 0) ?: null;
        $stmt->execute([
            $reference, $submitterId, $companyName, $companyName, $ownerEmail, $companyAddress, $details,
            $licenseId, $licensePath, $ownerEmail, $companyEmail, $logoPath, $companyPhone, $licenseExpiry . ' 23:59:59',
        ]);
        $requestId = (int) $pdo->lastInsertId();
        $receiptStmt = $pdo->prepare(
            "INSERT INTO hq_receipt (hq_id, request_id, receipt_type, receipt_image)
             VALUES (NULL, ?, 'registration', ?)"
        );
        $receiptStmt->execute([$requestId, $receiptPath]);
        $pdo->commit();
        $respond(true, 'Registration submitted successfully. We will verify your documents and email you within 1 business day.', 201, ['reference' => $reference]);
    } catch (Throwable $error) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        foreach ($savedFiles as $path) {
            @unlink(__DIR__ . '/uploads/' . basename($path));
        }
        error_log('Company registration failed: ' . $error->getMessage());
        $message = $error instanceof RuntimeException
            ? $error->getMessage()
            : 'The registration could not be saved. Please try again or contact support.';
        $respond(false, $message, 500);
    }
}

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
                        <input id="company-name" name="company_name" class="form-control" type="text" autocomplete="organization" placeholder="e.g. Golden Road Logistics" required>
                    </div>
                    <div class="col-12">
                        <label for="company-address" class="form-label">Company address</label>
                        <textarea id="company-address" name="company_address" class="form-control" rows="3" autocomplete="street-address" placeholder="Building, street, township, city" required></textarea>
                    </div>
                </div>
            </section>

            <section class="registration-section">
                <div class="registration-section-title">
                    <span class="registration-step">02</span>
                    <div><h2>Business license</h2><p>We use this to verify your company.</p></div>
                </div>
                <div class="row g-4">
                    <div class="col-md-6"><label for="license-id" class="form-label">Company license ID</label><input id="license-id" name="license_id" class="form-control" type="text" placeholder="License number" required></div>
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
                    <div class="col-md-6"><label for="owner-email" class="form-label">Company owner&rsquo;s email</label><input id="owner-email" name="owner_email" class="form-control" type="email" autocomplete="email" placeholder="owner@company.com" required></div>
                    <div class="col-md-6"><label for="owner-phone" class="form-label">Company owner&rsquo;s phone number</label><input id="owner-phone" name="owner_phone" class="form-control" type="tel" autocomplete="tel" placeholder="09xxxxxxxxx" required></div>
                    <div class="col-md-6"><label for="company-email" class="form-label">Company email</label><input id="company-email" name="company_email" class="form-control" type="email" autocomplete="email" placeholder="info@company.com" required></div>
                    <div class="col-md-6"><label for="company-phone" class="form-label">Company phone number</label><input id="company-phone" name="company_phone" class="form-control" type="tel" autocomplete="tel" placeholder="01xxxxxxx" required></div>
                </div>
            </section>

            <div class="registration-actions"><button type="submit" class="btn btn-primary btn-lg px-5 py-3">Submit registration <i class="fa-solid fa-arrow-right ms-2"></i></button></div>
            <p id="registration-form-status" class="registration-form-status" aria-live="polite"></p>
        </form>
    </div>
</main>

<div class="payment-modal" id="payment-modal" role="dialog" aria-modal="true" aria-labelledby="payment-modal-title" hidden
     data-accounts="<?php echo htmlspecialchars(json_encode($payment_accounts), ENT_QUOTES, 'UTF-8'); ?>"
     data-receiver="<?php echo htmlspecialchars($payment_receiver_name, ENT_QUOTES, 'UTF-8'); ?>">
    <div class="payment-modal-backdrop" data-payment-close></div>
    <div class="payment-card" role="document">
        <button type="button" class="payment-close" data-payment-close aria-label="Close payment window"><i class="fa-solid fa-xmark"></i></button>
        <span class="payment-kicker"><i class="fa-solid fa-shield-halved"></i> Secure registration</span>

        <!-- step indicator -->
        <ol class="payment-steps" id="payment-steps">
            <li class="payment-steps-item is-current" data-step-dot="method"><span>1</span> Payment method</li>
            <li class="payment-steps-item" data-step-dot="receipt"><span>2</span> Upload receipt</li>
        </ol>

        <!-- ============================ STEP 1 ============================ -->
        <div class="payment-step is-active" data-step="method">
            <h2 id="payment-modal-title">Complete your registration</h2>
            <p class="payment-description">Choose how you would like to pay the company registration fee.</p>

            <div class="payment-total">
                <span>Registration fee</span>
                <strong>2,999,999 <small>MMK</small></strong>
            </div>

            <div class="payment-field">
                <label class="payment-field-label" id="payment-method-label" for="payment-select-toggle">Payment method</label>

                <div class="payment-select" id="payment-select">
                    <button type="button" class="payment-select-toggle" id="payment-select-toggle"
                            aria-haspopup="listbox" aria-expanded="false" aria-labelledby="payment-method-label"
                            aria-controls="payment-select-menu">
                        <span class="payment-option-body" data-payment-selected>
                            <span class="payment-logo payment-logo-empty"><i class="fa-solid fa-wallet"></i></span>
                            <span class="payment-option-copy">
                                <strong>Select a payment method</strong>
                                <small>Bank transfer or a mobile wallet</small>
                            </span>
                        </span>
                        <i class="fa-solid fa-chevron-down payment-select-caret" aria-hidden="true"></i>
                    </button>

                    <ul class="payment-select-menu" id="payment-select-menu" role="listbox"
                        aria-labelledby="payment-method-label" tabindex="-1" hidden>
                        <li class="payment-select-option" role="option" tabindex="-1" aria-selected="false"
                            data-value="Bank transfer" data-bank-required="true">
                            <span class="payment-option-body">
                                <span class="payment-logo payment-logo-bank"><i class="fa-solid fa-building-columns"></i></span>
                                <span class="payment-option-copy"><strong>Bank transfer</strong><small>Pay from your bank account</small></span>
                            </span>
                            <i class="fa-solid fa-circle-check payment-check" aria-hidden="true"></i>
                        </li>
                        <li class="payment-select-option" role="option" tabindex="-1" aria-selected="false" data-value="KPay">
                            <span class="payment-option-body">
                                <span class="payment-logo payment-logo-kpay">K</span>
                                <span class="payment-option-copy"><strong>KPay</strong><small>Pay with KBZPay wallet</small></span>
                            </span>
                            <i class="fa-solid fa-circle-check payment-check" aria-hidden="true"></i>
                        </li>
                        <li class="payment-select-option" role="option" tabindex="-1" aria-selected="false" data-value="Wave Pay">
                            <span class="payment-option-body">
                                <span class="payment-logo payment-logo-wave">W</span>
                                <span class="payment-option-copy"><strong>Wave Pay</strong><small>Pay with Wave Money wallet</small></span>
                            </span>
                            <i class="fa-solid fa-circle-check payment-check" aria-hidden="true"></i>
                        </li>
                        <li class="payment-select-option" role="option" tabindex="-1" aria-selected="false" data-value="AYA Pay">
                            <span class="payment-option-body">
                                <span class="payment-logo payment-logo-aya">AYA</span>
                                <span class="payment-option-copy"><strong>AYA Pay</strong><small>Pay with AYA Pay wallet</small></span>
                            </span>
                            <i class="fa-solid fa-circle-check payment-check" aria-hidden="true"></i>
                        </li>
                        <li class="payment-select-option" role="option" tabindex="-1" aria-selected="false" data-value="CB Pay">
                            <span class="payment-option-body">
                                <span class="payment-logo payment-logo-cb">CB</span>
                                <span class="payment-option-copy"><strong>CB Pay</strong><small>Pay with CBPay wallet</small></span>
                            </span>
                            <i class="fa-solid fa-circle-check payment-check" aria-hidden="true"></i>
                        </li>
                    </ul>
                </div>

                <input type="hidden" name="payment_method" id="payment-method-input" value="">
            </div>

            <div class="payment-banks" id="payment-banks" hidden>
                <span class="payment-banks-title">Choose your bank</span>
                <div class="payment-bank-grid" role="radiogroup" aria-label="Choose your bank">
                    <button type="button" class="payment-bank" role="radio" aria-checked="false" data-bank="AYA Bank">
                        <span class="payment-logo payment-logo-aya">AYA</span>
                        <span class="payment-bank-name">AYA Bank</span>
                        <i class="fa-solid fa-circle-check payment-check" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="payment-bank" role="radio" aria-checked="false" data-bank="CB Bank">
                        <span class="payment-logo payment-logo-cb">CB</span>
                        <span class="payment-bank-name">CB Bank</span>
                        <i class="fa-solid fa-circle-check payment-check" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="payment-bank" role="radio" aria-checked="false" data-bank="KBZ Bank">
                        <span class="payment-logo payment-logo-kbz">KBZ</span>
                        <span class="payment-bank-name">KBZ Bank</span>
                        <i class="fa-solid fa-circle-check payment-check" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="payment-bank" role="radio" aria-checked="false" data-bank="YOMA Bank">
                        <span class="payment-logo payment-logo-yoma">YOMA</span>
                        <span class="payment-bank-name">YOMA Bank</span>
                        <i class="fa-solid fa-circle-check payment-check" aria-hidden="true"></i>
                    </button>
                </div>
                <input type="hidden" name="bank_name" id="payment-bank-input" value="">
            </div>

            <p class="payment-error" id="payment-error" aria-live="polite" hidden></p>

            <button type="button" class="btn btn-primary payment-confirm" id="payment-confirm">Continue to payment <i class="fa-solid fa-arrow-right ms-2"></i></button>
            <p class="payment-note"><i class="fa-solid fa-lock"></i> Payment details are handled securely by your chosen platform.</p>
        </div>

        <!-- ============================ STEP 2 ============================ -->
        <div class="payment-step" data-step="receipt" hidden>
            <button type="button" class="payment-back" id="payment-back"><i class="fa-solid fa-arrow-left"></i> Change payment method</button>

            <h2 class="payment-step-title">Transfer &amp; upload your receipt</h2>
            <p class="payment-description">Transfer the fee to the account below, then upload a photo of the payment receipt.</p>

            <div class="payment-summary">
                <span class="payment-logo payment-logo-empty" id="payment-summary-logo"><i class="fa-solid fa-wallet"></i></span>
                <span class="payment-summary-copy">
                    <strong id="payment-summary-method">&mdash;</strong>
                    <small id="payment-summary-sub">Selected payment method</small>
                </span>
                <span class="payment-summary-amount">2,999,999 <small>MMK</small></span>
            </div>

            <div class="payment-transfer">
                <span class="payment-transfer-label" id="payment-transfer-label">Transfer to this phone number</span>
                <div class="payment-transfer-row">
                    <strong class="payment-transfer-number" id="payment-transfer-number"><?php echo htmlspecialchars($payment_default_phone, ENT_QUOTES, 'UTF-8'); ?></strong>
                    <button type="button" class="payment-copy" id="payment-copy" aria-label="Copy number"><i class="fa-regular fa-copy"></i> <span>Copy</span></button>
                </div>
                <ul class="payment-transfer-meta">
                    <li><i class="fa-solid fa-user-tie"></i> Account name: <strong id="payment-transfer-name"><?php echo htmlspecialchars($payment_receiver_name, ENT_QUOTES, 'UTF-8'); ?></strong></li>
                    <li id="payment-transfer-phone-row" hidden><i class="fa-solid fa-phone"></i> Contact phone: <strong id="payment-transfer-phone"></strong></li>
                </ul>
            </div>

            <div class="payment-field">
                <label class="payment-field-label" for="payment-receipt">Payment receipt photo</label>
                <label class="upload-field payment-receipt-field" for="payment-receipt">
                    <span class="upload-icon"><i class="fa-solid fa-receipt"></i></span>
                    <span class="upload-copy"><strong id="payment-receipt-label">Upload receipt photo</strong><small>PNG, JPG, or WEBP &middot; max 5 MB</small></span>
                    <input id="payment-receipt" name="payment_receipt" type="file" accept="image/png,image/jpeg,image/webp">
                </label>

                <div class="payment-receipt-preview" id="payment-receipt-preview" hidden>
                    <img id="payment-receipt-image" src="" alt="Receipt preview">
                    <div class="payment-receipt-info">
                        <strong id="payment-receipt-name">receipt.jpg</strong>
                        <small id="payment-receipt-size"></small>
                    </div>
                    <button type="button" class="payment-receipt-remove" id="payment-receipt-remove" aria-label="Remove receipt photo"><i class="fa-solid fa-xmark"></i></button>
                </div>
            </div>

            <p class="payment-error" id="payment-receipt-error" aria-live="polite" hidden></p>

            <button type="button" class="btn btn-primary payment-confirm" id="payment-submit">Submit payment receipt <i class="fa-solid fa-check ms-2"></i></button>
            <p class="payment-note"><i class="fa-solid fa-clock"></i> We verify receipts within 1 business day and email you the result.</p>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../partials/footer.php'; ?>
<script src="/js/company-registration.js"></script>
