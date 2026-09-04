/* ==========================================================================
   COMPANY REGISTRATION
   - file upload labels
   - submit -> payment modal
   - STEP 1: custom payment-method dropdown + bank logo picker
   - STEP 2: transfer number + payment receipt photo upload
   ========================================================================== */

/* ---------- File upload labels (company logo, license, receipt) ---------- */
document.querySelectorAll('.upload-field input[type="file"]').forEach((input) => {
    input.addEventListener('change', () => {
        const label = document.getElementById(`${input.id}-label`);
        if (label && input.files.length) label.textContent = input.files[0].name;
    });
});

const registrationForm = document.getElementById('company-registration-form');
const formStatus = document.getElementById('registration-form-status');
const paymentModal = document.getElementById('payment-modal');
const paymentConfirm = document.getElementById('payment-confirm');

/* Receiving accounts rendered by PHP: { "KPay": { label, number, phone? }, ... } */
let accounts = {};
try { accounts = JSON.parse(paymentModal?.dataset.accounts || '{}'); } catch (e) { accounts = {}; }
const receiverName = paymentModal?.dataset.receiver || '';

/* ---------- Step 1: payment method dropdown ---------- */
const select = document.getElementById('payment-select');
const toggle = document.getElementById('payment-select-toggle');
const menu = document.getElementById('payment-select-menu');
const selectedSlot = toggle?.querySelector('[data-payment-selected]');
const methodInput = document.getElementById('payment-method-input');
const options = menu ? Array.from(menu.querySelectorAll('.payment-select-option')) : [];

const banksBox = document.getElementById('payment-banks');
const bankButtons = banksBox ? Array.from(banksBox.querySelectorAll('.payment-bank')) : [];
const bankInput = document.getElementById('payment-bank-input');
const paymentError = document.getElementById('payment-error');

/* ---------- Step 2: receipt ---------- */
const steps = paymentModal ? Array.from(paymentModal.querySelectorAll('.payment-step')) : [];
const stepDots = paymentModal ? Array.from(paymentModal.querySelectorAll('[data-step-dot]')) : [];
const backButton = document.getElementById('payment-back');
const summaryLogo = document.getElementById('payment-summary-logo');
const summaryMethod = document.getElementById('payment-summary-method');
const summarySub = document.getElementById('payment-summary-sub');
const transferLabel = document.getElementById('payment-transfer-label');
const transferNumber = document.getElementById('payment-transfer-number');
const transferName = document.getElementById('payment-transfer-name');
const transferPhoneRow = document.getElementById('payment-transfer-phone-row');
const transferPhone = document.getElementById('payment-transfer-phone');
const copyButton = document.getElementById('payment-copy');
const receiptInput = document.getElementById('payment-receipt');
const receiptField = document.querySelector('.payment-receipt-field');
const receiptLabel = document.getElementById('payment-receipt-label');
const receiptPreview = document.getElementById('payment-receipt-preview');
const receiptImage = document.getElementById('payment-receipt-image');
const receiptName = document.getElementById('payment-receipt-name');
const receiptSize = document.getElementById('payment-receipt-size');
const receiptRemove = document.getElementById('payment-receipt-remove');
const receiptError = document.getElementById('payment-receipt-error');
const paymentSubmit = document.getElementById('payment-submit');

const MAX_RECEIPT_BYTES = 5 * 1024 * 1024;
let activeIndex = -1;
let previouslyFocusedElement;
let previewUrl = null;

/* ---------- helpers ---------- */
const showError = (target, message) => {
    if (!target) return;
    target.innerHTML = `<i class="fa-solid fa-circle-exclamation"></i>${message}`;
    target.hidden = false;
};

const clearError = () => {
    if (paymentError) paymentError.hidden = true;
    if (receiptError) receiptError.hidden = true;
    select?.classList.remove('has-error');
    banksBox?.classList.remove('has-error');
    receiptField?.classList.remove('has-error');
};

const formatSize = (bytes) => (bytes < 1024 * 1024
    ? `${Math.max(1, Math.round(bytes / 1024))} KB`
    : `${(bytes / (1024 * 1024)).toFixed(1)} MB`);

const setActive = (index) => {
    options.forEach((option, i) => option.classList.toggle('is-active', i === index));
    activeIndex = index;
    if (index > -1) options[index].scrollIntoView({ block: 'nearest' });
};

const openMenu = () => {
    if (!menu || !select) return;
    menu.hidden = false;
    select.classList.add('is-open');
    toggle.setAttribute('aria-expanded', 'true');
    const selectedIndex = options.findIndex((o) => o.getAttribute('aria-selected') === 'true');
    setActive(selectedIndex > -1 ? selectedIndex : 0);
};

const closeMenu = ({ focusToggle = false } = {}) => {
    if (!menu || !select) return;
    menu.hidden = true;
    select.classList.remove('is-open');
    toggle.setAttribute('aria-expanded', 'false');
    setActive(-1);
    if (focusToggle) toggle.focus();
};

const isMenuOpen = () => menu && !menu.hidden;

const clearBankSelection = () => {
    bankButtons.forEach((button) => button.setAttribute('aria-checked', 'false'));
    if (bankInput) bankInput.value = '';
};

const selectOption = (option) => {
    if (!option) return;
    options.forEach((item) => item.setAttribute('aria-selected', String(item === option)));

    const body = option.querySelector('.payment-option-body');
    if (selectedSlot && body) selectedSlot.innerHTML = body.innerHTML;
    if (methodInput) methodInput.value = option.dataset.value || '';

    const needsBank = option.dataset.bankRequired === 'true';
    if (banksBox) banksBox.hidden = !needsBank;
    if (!needsBank) clearBankSelection();

    clearError();
};

/* ---------- step switching ---------- */
const goToStep = (name) => {
    steps.forEach((step) => {
        const isTarget = step.dataset.step === name;
        step.hidden = !isTarget;
        step.classList.toggle('is-active', isTarget);
    });
    stepDots.forEach((dot) => {
        const isTarget = dot.dataset.stepDot === name;
        dot.classList.toggle('is-current', isTarget);
        dot.classList.toggle('is-done', name === 'receipt' && dot.dataset.stepDot === 'method');
    });
    paymentModal?.querySelector('.payment-card')?.scrollTo({ top: 0, behavior: 'smooth' });
};

/* Fill step 2 with the chosen method / bank details */
const fillReceiptStep = () => {
    const method = methodInput?.value || '';
    const bank = bankInput?.value || '';
    const key = method === 'Bank transfer' ? bank : method;
    const account = accounts[key] || {};
    const isBank = method === 'Bank transfer';

    const chosenOption = options.find((o) => o.dataset.value === method);
    const chosenLogo = isBank
        ? bankButtons.find((b) => b.dataset.bank === bank)?.querySelector('.payment-logo')
        : chosenOption?.querySelector('.payment-logo');
    if (summaryLogo && chosenLogo) summaryLogo.className = chosenLogo.className;
    if (summaryLogo && chosenLogo) summaryLogo.innerHTML = chosenLogo.innerHTML;

    if (summaryMethod) summaryMethod.textContent = isBank ? `Bank transfer — ${bank}` : method;
    if (summarySub) summarySub.textContent = isBank ? 'Transfer from your bank account' : 'Mobile wallet transfer';

    if (transferLabel) transferLabel.textContent = account.label || (isBank ? 'Transfer to this account number' : 'Transfer to this phone number');
    if (transferNumber) transferNumber.textContent = account.number || '';
    if (transferName) transferName.textContent = receiverName;

    if (transferPhoneRow && transferPhone) {
        if (account.phone) {
            transferPhone.textContent = account.phone;
            transferPhoneRow.hidden = false;
        } else {
            transferPhoneRow.hidden = true;
        }
    }

    if (copyButton) {
        copyButton.classList.remove('is-copied');
        const copyText = copyButton.querySelector('span');
        if (copyText) copyText.textContent = 'Copy';
    }
};

/* ---------- dropdown events ---------- */
toggle?.addEventListener('click', () => (isMenuOpen() ? closeMenu() : openMenu()));

options.forEach((option, index) => {
    option.addEventListener('click', () => {
        selectOption(option);
        closeMenu({ focusToggle: true });
    });
    option.addEventListener('mousemove', () => setActive(index));
});

const dropdownKeys = ['ArrowDown', 'ArrowUp', 'Home', 'End', 'Enter', ' ', 'Escape', 'Tab'];
select?.addEventListener('keydown', (event) => {
    if (!dropdownKeys.includes(event.key)) return;

    if (!isMenuOpen()) {
        if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(event.key)) {
            event.preventDefault();
            openMenu();
        }
        return;
    }

    switch (event.key) {
        case 'ArrowDown':
            event.preventDefault();
            setActive((activeIndex + 1) % options.length);
            break;
        case 'ArrowUp':
            event.preventDefault();
            setActive((activeIndex - 1 + options.length) % options.length);
            break;
        case 'Home':
            event.preventDefault();
            setActive(0);
            break;
        case 'End':
            event.preventDefault();
            setActive(options.length - 1);
            break;
        case 'Enter':
        case ' ':
            event.preventDefault();
            selectOption(options[activeIndex]);
            closeMenu({ focusToggle: true });
            break;
        case 'Escape':
            event.preventDefault();
            closeMenu({ focusToggle: true });
            break;
        case 'Tab':
            closeMenu();
            break;
    }
});

document.addEventListener('click', (event) => {
    if (isMenuOpen() && select && !select.contains(event.target)) closeMenu();
});

/* ---------- bank logo picker ---------- */
bankButtons.forEach((button) => {
    button.addEventListener('click', () => {
        bankButtons.forEach((item) => item.setAttribute('aria-checked', String(item === button)));
        if (bankInput) bankInput.value = button.dataset.bank || '';
        clearError();
    });
});

/* ---------- receipt upload ---------- */
const resetReceipt = () => {
    if (receiptInput) receiptInput.value = '';
    if (previewUrl) { URL.revokeObjectURL(previewUrl); previewUrl = null; }
    if (receiptPreview) receiptPreview.hidden = true;
    if (receiptLabel) receiptLabel.textContent = 'Upload receipt photo';
};

receiptInput?.addEventListener('change', () => {
    const file = receiptInput.files?.[0];
    if (!file) { resetReceipt(); return; }

    if (!file.type.startsWith('image/')) {
        resetReceipt();
        receiptField?.classList.add('has-error');
        showError(receiptError, 'Please upload a photo (PNG, JPG, or WEBP).');
        return;
    }
    if (file.size > MAX_RECEIPT_BYTES) {
        resetReceipt();
        receiptField?.classList.add('has-error');
        showError(receiptError, 'That photo is larger than 5 MB. Please upload a smaller one.');
        return;
    }

    clearError();
    if (previewUrl) URL.revokeObjectURL(previewUrl);
    previewUrl = URL.createObjectURL(file);
    if (receiptImage) receiptImage.src = previewUrl;
    if (receiptName) receiptName.textContent = file.name;
    if (receiptSize) receiptSize.textContent = formatSize(file.size);
    if (receiptPreview) receiptPreview.hidden = false;
});

receiptRemove?.addEventListener('click', () => {
    resetReceipt();
    clearError();
    receiptInput?.focus();
});

copyButton?.addEventListener('click', async () => {
    const value = transferNumber?.textContent?.trim();
    if (!value) return;
    try {
        await navigator.clipboard.writeText(value);
    } catch (e) {
        const helper = document.createElement('textarea');
        helper.value = value;
        document.body.appendChild(helper);
        helper.select();
        document.execCommand('copy');
        helper.remove();
    }
    copyButton.classList.add('is-copied');
    const copyText = copyButton.querySelector('span');
    if (copyText) copyText.textContent = 'Copied';
    setTimeout(() => {
        copyButton.classList.remove('is-copied');
        if (copyText) copyText.textContent = 'Copy';
    }, 1800);
});

/* ---------- modal open / close ---------- */
const closePaymentModal = () => {
    if (!paymentModal) return;
    closeMenu();
    paymentModal.hidden = true;
    document.body.classList.remove('payment-modal-open');
    previouslyFocusedElement?.focus();
};

registrationForm?.addEventListener('submit', (event) => {
    event.preventDefault();
    if (!registrationForm.checkValidity()) {
        registrationForm.classList.add('was-validated');
        return;
    }

    previouslyFocusedElement = document.activeElement;
    clearError();
    goToStep('method');
    paymentModal.hidden = false;
    document.body.classList.add('payment-modal-open');
    toggle?.focus();
});

paymentModal?.querySelectorAll('[data-payment-close]').forEach((element) => {
    element.addEventListener('click', closePaymentModal);
});

/* Step 1 -> Step 2 */
paymentConfirm?.addEventListener('click', () => {
    const method = methodInput?.value;
    if (!method) {
        select?.classList.add('has-error');
        showError(paymentError, 'Please choose a payment method to continue.');
        toggle?.focus();
        return;
    }
    if (method === 'Bank transfer' && !bankInput?.value) {
        banksBox?.classList.add('has-error');
        showError(paymentError, 'Please choose which bank you will transfer from.');
        bankButtons[0]?.focus();
        return;
    }

    clearError();
    fillReceiptStep();
    goToStep('receipt');
    backButton?.focus();
});

/* Step 2 -> Step 1 */
backButton?.addEventListener('click', () => {
    clearError();
    goToStep('method');
    toggle?.focus();
});

/* Final submit */
paymentSubmit?.addEventListener('click', () => {
    if (!receiptInput?.files?.length) {
        receiptField?.classList.add('has-error');
        showError(receiptError, 'Please upload a photo of your payment receipt.');
        receiptInput?.focus();
        return;
    }

    const method = methodInput?.value;
    const bank = bankInput?.value;
    clearError();
    closePaymentModal();

    if (formStatus) {
        formStatus.classList.add('is-success');
        formStatus.textContent = bank
            ? `Receipt received for ${method} via ${bank}. We will verify your payment and email you within 1 business day.`
            : `Receipt received for ${method}. We will verify your payment and email you within 1 business day.`;
    }
});

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    if (isMenuOpen()) return; // dropdown handles its own Escape
    if (paymentModal && !paymentModal.hidden) closePaymentModal();
});