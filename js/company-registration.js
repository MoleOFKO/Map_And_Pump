const paymentDefaults = {
    'bank-transfer': { label: 'Bank transfer', phone: '+966 500 123 456' },
    tabby: { label: 'Tabby', phone: '+966 555 778 905' },
    'stc-pay': { label: 'STC Pay', phone: '+966 555 432 187' },
    mada: { label: 'Mada', phone: '+966 500 009 872' },
};

const paymentMethodSelect = document.getElementById('payment-method');
const paymentMethodSelectorCard = document.getElementById('payment-method-selector-card');
const paymentDetailsCard = document.getElementById('payment-details-card');
const continuePaymentButton = document.getElementById('continue-payment');
const changePaymentMethodButton = document.getElementById('change-payment-method');
const selectedPaymentName = document.getElementById('selected-payment-name');
const paymentPhoneInput = document.getElementById('payment-phone');

const syncSelectedPayment = () => {
    const selectedMethod = paymentMethodSelect?.value;
    if (!selectedMethod || !paymentDefaults[selectedMethod]) {
        if (paymentPhoneInput) paymentPhoneInput.value = '';
        if (selectedPaymentName) selectedPaymentName.textContent = 'No payment method selected';
        return;
    }

    const selected = paymentDefaults[selectedMethod];
    if (selectedPaymentName) selectedPaymentName.textContent = `${selected.label} • ${selected.phone}`;
    if (paymentPhoneInput) paymentPhoneInput.value = selected.phone;
};

paymentMethodSelect?.addEventListener('change', syncSelectedPayment);

continuePaymentButton?.addEventListener('click', () => {
    if (!paymentMethodSelect?.value) {
        paymentMethodSelect?.focus();
        paymentMethodSelect?.reportValidity();
        return;
    }

    syncSelectedPayment();
    paymentMethodSelectorCard?.classList.add('is-hidden');
    paymentDetailsCard?.classList.remove('is-hidden');
});

changePaymentMethodButton?.addEventListener('click', () => {
    paymentDetailsCard?.classList.add('is-hidden');
    paymentMethodSelectorCard?.classList.remove('is-hidden');
    paymentMethodSelect?.focus();
});

const uploadFields = document.querySelectorAll('.upload-field input[type="file"]');

const setUploadFieldState = (input) => {
    const uploadField = input.closest('.upload-field');
    if (!uploadField) return;

    uploadField.classList.toggle('is-invalid', !input.checkValidity());
};

uploadFields.forEach((input) => {
    input.addEventListener('change', () => {
        const label = document.getElementById(`${input.id}-label`);
        if (label && input.files.length) label.textContent = input.files[0].name;
        setUploadFieldState(input);
    });

    input.addEventListener('blur', () => setUploadFieldState(input));
});

const paymentReceiptInput = document.getElementById('payment-receipt');
const paymentReceiptLabel = document.getElementById('payment-receipt-label');
paymentReceiptInput?.addEventListener('change', () => {
    if (paymentReceiptInput.files.length) {
        paymentReceiptLabel.textContent = paymentReceiptInput.files[0].name;
    }
    setUploadFieldState(paymentReceiptInput);
});

const registrationForm = document.getElementById('company-registration-form');
const registrationFormContent = document.getElementById('registration-form-content');
const registrationSuccessState = document.getElementById('registration-success-state');

registrationForm?.addEventListener('submit', (event) => {
    event.preventDefault();
    uploadFields.forEach((input) => setUploadFieldState(input));

    if (!event.currentTarget.checkValidity()) {
        event.currentTarget.classList.add('was-validated');
        const firstInvalid = event.currentTarget.querySelector(':invalid');
        if (firstInvalid) firstInvalid.focus();
        return;
    }

    registrationFormContent?.classList.add('is-hidden');
    registrationSuccessState?.classList.remove('is-hidden');
    document.getElementById('registration-form-status').textContent = '';
});
