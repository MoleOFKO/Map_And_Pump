/* ==========================================================================
   COMPANY REGISTRATION
   - file upload labels
   - submit -> payment modal
   - STEP 1: custom payment-method dropdown + bank logo picker
   - STEP 2: external transfer declaration and registration submission
   ========================================================================== */

/* ---------- File upload labels (company logo and license only) ---------- */
document.querySelectorAll('.upload-field input[type="file"]').forEach((input) => {
    input.addEventListener('change', () => {
        const label = document.getElementById(`${input.id}-label`);
        if (label && input.files.length) label.textContent = input.files[0].name;
    });
});

const registrationForm = document.getElementById('company-registration-form');
const formStatus = document.getElementById('registration-form-status');
const paymentModal = document.getElementById('payment-modal');
const registrationMessageModal = document.getElementById('registration-message-modal');
const registrationMessageTitle = document.getElementById('registration-message-title');
const registrationMessageBody = document.getElementById('registration-message-body');
const registrationMessageIcon = document.getElementById('registration-message-icon');
const paymentConfirm = document.getElementById('payment-confirm');
const ownerEmailInput = document.getElementById('owner-email');
const companyEmailInput = document.getElementById('company-email');

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

/* ---------- Step 2: external payment request ---------- */
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
const paymentStatusError = document.getElementById('payment-status-error');
const paymentSubmit = document.getElementById('payment-submit');
const stationCountInput = document.getElementById('station-count');
const registrationTermField = document.getElementById('registration-term-field');
const registrationTermInput = document.getElementById('registration-term-months');
const registrationTotal = document.getElementById('registration-total');
const paymentSummaryAmount = document.getElementById('payment-summary-amount');
const paymentReceiptInput = document.getElementById('payment-receipt');
const paymentMethodField = document.getElementById('payment-method-field');
const paymentConfirmButton = document.getElementById('payment-confirm');
const paymentNote = document.getElementById('payment-note');
const paymentSteps = document.getElementById('payment-steps');

let activeIndex = -1;
let previouslyFocusedElement;
let messageModalPreviousFocus;

/* ---------- helpers ---------- */
const closeRegistrationMessage = () => {
    if (!registrationMessageModal || registrationMessageModal.hidden) return;
    registrationMessageModal.hidden = true;
    registrationMessageModal.classList.remove('is-error', 'is-success');
    if (!paymentModal || paymentModal.hidden) {
        document.body.classList.remove('payment-modal-open');
    }
    messageModalPreviousFocus?.focus();
};

const showRegistrationMessage = (message, isError = false, showAccountLink = false) => {
    if (!registrationMessageModal || !registrationMessageBody) return;

    registrationMessageModal.classList.toggle('is-error', isError);
    registrationMessageModal.classList.toggle('is-success', !isError);
    registrationMessageTitle.textContent = isError ? 'Please check your registration' : 'Registration update';
    registrationMessageIcon.innerHTML = isError
        ? '<i class="fa-solid fa-circle-exclamation"></i>'
        : '<i class="fa-solid fa-circle-info"></i>';
    registrationMessageBody.replaceChildren(document.createTextNode(message));

    if (showAccountLink) {
        const link = document.createElement('a');
        link.href = '/php/Login/register.php';
        link.textContent = 'Create an account';
        registrationMessageBody.append(document.createElement('br'), link);
    }

    messageModalPreviousFocus = document.activeElement;
    registrationMessageModal.hidden = false;
    document.body.classList.add('payment-modal-open');
    registrationMessageModal.querySelector('.registration-message-close')?.focus();
};

registrationMessageModal?.querySelectorAll('[data-registration-message-close]').forEach((element) => {
    element.addEventListener('click', closeRegistrationMessage);
});

registrationMessageModal?.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        event.preventDefault();
        closeRegistrationMessage();
        return;
    }
    if (event.key !== 'Tab') return;

    const focusable = Array.from(registrationMessageModal.querySelectorAll('button:not([disabled]), a[href]'));
    if (!focusable.length) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
});

const showError = (target, message) => {
    if (!target) return;
    target.replaceChildren();
    const icon = document.createElement('i');
    icon.className = 'fa-solid fa-circle-exclamation';
    target.append(icon, document.createTextNode(message));
    target.hidden = false;
    showRegistrationMessage(message, true);
};

const clearError = () => {
    if (paymentError) paymentError.hidden = true;
    if (paymentStatusError) paymentStatusError.hidden = true;
    select?.classList.remove('has-error');
    banksBox?.classList.remove('has-error');
};

const setActive = (index) => {
    options.forEach((option, i) => option.classList.toggle('is-active', i === index));
    activeIndex = index;
    if (index > -1) options[index].scrollIntoView({ block: 'nearest' });
};

/* The menu is inline, so it can only run past the viewport on short screens —
   nudge the modal scroller just enough to bring it into view. */
const revealMenu = () => {
    if (!menu || menu.hidden) return;
    const box = menu.getBoundingClientRect();
    const overflow = box.bottom - (window.innerHeight - 12);
    if (overflow > 0) paymentModal?.scrollBy({ top: overflow, behavior: 'smooth' });
};

const paymentCard = paymentModal?.querySelector('.payment-card');

const openMenu = () => {
    if (!menu || !select) return;
    menu.hidden = false;
    select.classList.add('is-open');
    paymentCard?.classList.add('is-menu-open');
    toggle.setAttribute('aria-expanded', 'true');
    const selectedIndex = options.findIndex((o) => o.getAttribute('aria-selected') === 'true');
    setActive(selectedIndex > -1 ? selectedIndex : 0);
    requestAnimationFrame(revealMenu);
};

const closeMenu = ({ focusToggle = false } = {}) => {
    if (!menu || !select) return;
    menu.hidden = true;
    select.classList.remove('is-open');
    paymentCard?.classList.remove('is-menu-open');
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
        dot.classList.toggle('is-done', name === 'payment' && dot.dataset.stepDot === 'method');
    });
    paymentModal?.querySelector('.payment-card')?.scrollTo({ top: 0, behavior: 'smooth' });
};

/* Fill step 2 with the chosen method / bank details */
const fillPaymentStep = () => {
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

const getRegistrationAmount = () => {
    if (!stationCountInput || !registrationTotal) return 0;
    const stationCount = Number(stationCountInput.value);
    const discounts = JSON.parse(registrationTotal.dataset.discounts || '[]');
    const discount = discounts.reduce((current, tier) => stationCount >= Number(tier.min) ? Number(tier.percent) : current, 0);
    const months = Number(registrationTermInput?.value || 1);
    const termDiscounts = JSON.parse(registrationTotal.dataset.termDiscounts || '[]');
    const termDiscount = Number(termDiscounts.find((term) => Number(term.months) === months)?.percent || 0);
    const packageAmount = Number(registrationTotal.dataset.monthly) * stationCount * months * (1 - discount / 100) * (1 - termDiscount / 100);
    return Math.round((Number(registrationTotal.dataset.base) + packageAmount) * 100) / 100;
};

const updateRegistrationAmount = () => {
    if (!registrationTotal) return;
    const amount = getRegistrationAmount();
    const formatted = `${new Intl.NumberFormat().format(amount)} <small>MMK</small>`;
    registrationTotal.innerHTML = formatted;
    if (paymentSummaryAmount) paymentSummaryAmount.innerHTML = formatted;
    const isFree = amount <= 0;
    if (registrationTermField) registrationTermField.hidden = false;
    if (paymentMethodField) paymentMethodField.hidden = isFree;
    if (paymentSteps) paymentSteps.hidden = isFree;
    if (banksBox) banksBox.hidden = isFree || methodInput?.value !== 'Bank transfer';
    if (paymentConfirmButton) paymentConfirmButton.innerHTML = isFree
        ? 'Submit registration <i class="fa-solid fa-check ms-2"></i>'
        : 'Continue to payment <i class="fa-solid fa-arrow-right ms-2"></i>';
    if (paymentNote) paymentNote.textContent = 'The MMK package includes station capacity, operation, and service extension. Driver loyalty points are not used for HQ billing.';
};

stationCountInput?.addEventListener('change', updateRegistrationAmount);
stationCountInput?.addEventListener('input', updateRegistrationAmount);
registrationTermInput?.addEventListener('change', updateRegistrationAmount);
registrationTermInput?.addEventListener('input', updateRegistrationAmount);
updateRegistrationAmount();

const submitRegistration = async (requiresPayment) => {
    if (requiresPayment && !paymentReceiptInput?.files?.length) {
        showError(paymentStatusError, 'Upload the payment screenshot to submit the registration.');
        paymentReceiptInput?.focus();
        return;
    }
    const submitButton = requiresPayment ? paymentSubmit : paymentConfirmButton;
    if (submitButton) {
        submitButton.disabled = true;
        submitButton.classList.add('is-loading');
    }
    try {
        const formData = new FormData(registrationForm);
        formData.set('payment_method', requiresPayment ? methodInput?.value || '' : '');
        formData.set('bank_name', requiresPayment ? bankInput?.value || '' : '');
        formData.set('station_count', stationCountInput?.value || '');
        formData.set('service_months', registrationTermInput?.value || '1');
        if (requiresPayment && paymentReceiptInput?.files?.[0]) {
            formData.set('payment_receipt', paymentReceiptInput.files[0]);
        }
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData,
            headers: { Accept: 'application/json' },
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Registration could not be submitted.');

        closePaymentModal();
        setRegistrationStatus(result.message);
        registrationForm.reset();
        updateRegistrationAmount();
    } catch (error) {
        showError(requiresPayment ? paymentStatusError : paymentError, error.message);
        if (requiresPayment) goToStep('payment');
    } finally {
        if (submitButton) {
            submitButton.disabled = false;
            submitButton.classList.remove('is-loading');
        }
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

window.addEventListener('resize', revealMenu);

/* ---------- bank logo picker ---------- */
bankButtons.forEach((button) => {
    button.addEventListener('click', () => {
        bankButtons.forEach((item) => item.setAttribute('aria-checked', String(item === button)));
        if (bankInput) bankInput.value = button.dataset.bank || '';
        clearError();
    });
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

const setRegistrationStatus = (message, isError = false, showAccountLink = false) => {
    if (formStatus) {
        formStatus.classList.toggle('is-error', isError);
        formStatus.classList.toggle('is-success', !isError);
        formStatus.textContent = message;
    }
    if (message) showRegistrationMessage(message, isError, showAccountLink);
};

registrationForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!registrationForm.checkValidity()) {
        registrationForm.classList.add('was-validated');
        return;
    }

    setRegistrationStatus('');
    const emailCheck = new FormData();
    emailCheck.set('action', 'verify_registration_emails');
    emailCheck.set('csrf_token', registrationForm.querySelector('[name="csrf_token"]')?.value || '');
    emailCheck.set('owner_email', ownerEmailInput?.value.trim() || '');
    emailCheck.set('company_email', companyEmailInput?.value.trim() || '');
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: emailCheck,
            headers: { Accept: 'application/json' },
        });
        const result = await response.json();
        if (!response.ok || !result.success) {
            setRegistrationStatus(result.message || 'Please check the owner and company emails.', true);
            return;
        }
    } catch (error) {
        setRegistrationStatus('Email verification is temporarily unavailable. Please try again.', true);
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
    if (getRegistrationAmount() === 0) {
        submitRegistration(false);
        return;
    }
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
    fillPaymentStep();
    goToStep('payment');
    backButton?.focus();
});

/* Step 2 -> Step 1 */
backButton?.addEventListener('click', () => {
    clearError();
    goToStep('method');
    toggle?.focus();
});

/* Submit the registration and create a fixed-amount payment request. */
paymentSubmit?.addEventListener('click', async () => {
    clearError();
    await submitRegistration(true);
});

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    if (isMenuOpen()) return; // dropdown handles its own Escape
    if (paymentModal && !paymentModal.hidden) closePaymentModal();
});
