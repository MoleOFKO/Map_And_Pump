<?php
require_once __DIR__ . '/session_helper.php';
map_pump_configure_session();
session_start();

if (empty($_SESSION['password_reset_csrf'])) {
  $_SESSION['password_reset_csrf'] = bin2hex(random_bytes(32));
}
$passwordResetCsrf = (string) $_SESSION['password_reset_csrf'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>MAP & PUMP - Reset Password</title>
  
  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Prata&family=Poppins:wght@400;500&display=swap" rel="stylesheet">
  <link rel="icon" type="image/png" href="/images/Logo.png">
  <link rel="stylesheet" href="../../css/Login.css" />
</head>
<body>

  <!-- Background Decorative Blurs -->
  <div class="bg-blur bg-blur-1"></div>
  <div class="bg-blur bg-blur-2"></div>

  <!-- Main Container -->
  <div class="main-card">
    
    <!-- Left Side: Forgot Password Multi-Step Flow -->
    <div class="form-container">
      <div id="reset-status" role="status" aria-live="polite" style="display:none;margin-bottom:1rem;padding:0.85rem 1rem;border-radius:12px;"></div>
      
      <!-- STEP 1: Enter Email -->
      <div class="auth-step active" id="step-1">
        <div class="step-icon-badge">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
          </svg>
        </div>
        <div class="header text-center">
          <h1 class="title">Forgot password?</h1>
          <p class="step-subtitle">Enter your email to receive a 4-digit code.</p>
        </div>

        <form id="form-step-1" onsubmit="return false;">
          <div class="input-group">
            <label for="reset-email">Enter your email</label>
            <div class="input-wrapper">
              <input type="email" id="reset-email" placeholder="example123@gmail.com" required />
            </div>
          </div>

          <div class="login-btn-wrapper">
            <button type="button" class="submit-btn full-width-btn" id="to-step-2">Send 4-digit code</button>
          </div>

          <div class="register-prompt">
            <span>Remembered your password?</span>
            <a href="login.php" class="register-link">Back to Login</a>
          </div>
        </form>
      </div>

      <!-- STEP 2: Enter Code / OTP -->
      <div class="auth-step" id="step-2">
        <div class="step-icon-badge">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
            <polyline points="22,6 12,13 2,6"></polyline>
          </svg>
        </div>
        <div class="header text-center">
          <h1 class="title">Enter your code</h1>
          <p class="step-subtitle">We sent a code to <span id="display-email">example@gmail.com</span></p>
        </div>

        <form id="form-step-2" onsubmit="return false;">
          <div class="input-group">
            <div class="otp-digit-container">
              <input type="text" maxlength="1" class="otp-digit" required />
              <input type="text" maxlength="1" class="otp-digit" required />
              <input type="text" maxlength="1" class="otp-digit" required />
              <input type="text" maxlength="1" class="otp-digit" required />
            </div>
          </div>

          <div class="resend-prompt">
            <span>Didn't receive the email?</span> <a href="#" id="click-resend" class="resend-link">Click to resend</a>
          </div>

          <div class="login-btn-wrapper">
            <button type="button" class="submit-btn full-width-btn" id="to-step-3">Continue</button>
          </div>

          <div class="register-prompt">
            <a href="#" id="back-to-step-1" class="register-link">Back to email entry</a>
          </div>
        </form>
      </div>

      <!-- STEP 3: Set New Password -->
      <div class="auth-step" id="step-3">
        <div class="step-icon-badge">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
          </svg>
        </div>
        <div class="header text-center">
          <h1 class="title">Set new password</h1>
          <p class="step-subtitle">Create a new password. Ensure it differs from<br>previous ones for security</p>
        </div>

        <form id="form-step-3" onsubmit="return false;">
          <div class="input-group relative-group">
            <label for="new-password">New password</label>
            <div class="input-wrapper">
              <input type="password" id="new-password" placeholder="••••••••••••" required />
              <button type="button" class="eye-btn" id="toggleNewPassword" aria-label="Toggle password visibility">
                <svg class="eye-icon" id="eyeIconNew" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                  <circle cx="12" cy="12" r="3"></circle>
                </svg>
              </button>
            </div>

            <div class="strength-container">
              <div class="password-bars">
                <span class="pbar"></span>
                <span class="pbar"></span>
                <span class="pbar"></span>
                <span class="pbar"></span>
              </div>
              <span class="strength-label" id="strength-text"></span>
            </div>

            <!-- Popover Modal -->
            <div class="pw-popover" id="pw-popover">
              <div class="popover-title">Password Conditions</div>
              <ul class="req-list">
                <li id="rule-length"><span class="bullet">✕</span> 8~15 Characters</li>
                <li id="rule-cases"><span class="bullet">✕</span> Upper & Lowercase</li>
                <li id="rule-number"><span class="bullet">✕</span> At least 1 Number</li>
                <li id="rule-symbol"><span class="bullet">✕</span> At least 1 Symbol</li>
              </ul>
            </div>

            <!-- Error message -->
            <span class="error-msg" id="password-error"></span>
          </div>

          <div class="input-group">
            <label for="confirm-password">Confirm password</label>
            <div class="input-wrapper">
              <input type="password" id="confirm-password" placeholder="••••••••••••" required />
              <button type="button" class="eye-btn" id="toggleConfirmPassword" aria-label="Toggle password visibility">
                <svg class="eye-icon" id="eyeIconConfirm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                  <circle cx="12" cy="12" r="3"></circle>
                </svg>
              </button>
            </div>
          </div>

          <div class="login-btn-wrapper">
            <button type="button" class="submit-btn full-width-btn" id="to-step-4">Set new password</button>
          </div>
        </form>
      </div>

      <!-- STEP 4: Success State -->
      <div class="auth-step" id="step-4">
        <div class="step-icon-badge success-badge">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M20 6L9 17l-5-5"></path>
          </svg>
        </div>
        <div class="header text-center">
          <h1 class="title">All done!</h1>
          <p class="step-subtitle">Your password has been reset. You can now go back to the login page.</p>
        </div>

        <div class="login-btn-wrapper" style="margin-top: 30px;">
          <a href="login.php" class="submit-btn full-width-btn" style="text-decoration: none;">Back to login</a>
        </div>
      </div>

    </div>

    <!-- Right Side: Branding -->
    <div class="branding-container">
      <img src="../../images/Logo.png" alt="MAP & PUMP Logo" class="logo-img" />
      <h2 class="brand-title">MAP &amp; PUMP</h2>
      <p class="brand-subtitle">No need to queue.<br>Get a token and fill up on time</p>
    </div>

  </div>

  <script>
    const resetCsrfToken = <?= json_encode($passwordResetCsrf) ?>;
    const step1 = document.getElementById('step-1');
    const step2 = document.getElementById('step-2');
    const step3 = document.getElementById('step-3');
    const step4 = document.getElementById('step-4');

    const toStep2 = document.getElementById('to-step-2');
    const toStep3 = document.getElementById('to-step-3');
    const toStep4 = document.getElementById('to-step-4');
    const backToStep1 = document.getElementById('back-to-step-1');
    const resetEmail = document.getElementById('reset-email');
    const displayEmail = document.getElementById('display-email');
    const resetStatus = document.getElementById('reset-status');
    const otpDigits = document.querySelectorAll('.otp-digit');

    function showResetStatus(message, success = false) {
      resetStatus.textContent = message;
      resetStatus.style.display = message ? 'block' : 'none';
      resetStatus.style.background = success ? 'rgba(34, 197, 94, 0.12)' : 'rgba(248, 113, 113, 0.12)';
      resetStatus.style.border = success ? '1px solid rgba(34, 197, 94, 0.35)' : '1px solid rgba(248, 113, 113, 0.35)';
      resetStatus.style.color = success ? '#d1fae5' : '#fdd';
    }

    async function postResetAction(action, values = {}) {
      const formData = new FormData();
      formData.append('action', action);
      formData.append('csrf_token', resetCsrfToken);
      Object.entries(values).forEach(([key, value]) => formData.append(key, value));
      const response = await fetch('forgot_password_action.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
      });
      const data = await response.json();
      if (!response.ok || !data.ok) {
        throw new Error(data.message || 'Password recovery could not be completed.');
      }
      return data;
    }

    function currentOtp() {
      return Array.from(otpDigits, digit => digit.value).join('');
    }

    async function sendResetCode() {
      const email = resetEmail.value.trim();
      if (!resetEmail.reportValidity()) return;
      const button = document.getElementById('to-step-2');
      button.disabled = true;
      showResetStatus('Sending your code...');
      try {
        const result = await postResetAction('send_code', { email });
        displayEmail.textContent = email;
        step1.classList.remove('active');
        step2.classList.add('active');
        showResetStatus(result.message, true);
      } catch (error) {
        showResetStatus(error.message);
      } finally {
        button.disabled = false;
      }
    }

    toStep2.addEventListener('click', sendResetCode);

    backToStep1.addEventListener('click', function(e) {
      e.preventDefault();
      step2.classList.remove('active');
      step1.classList.add('active');
    });

    toStep3.addEventListener('click', async function() {
      const code = currentOtp();
      if (!/^\d{4}$/.test(code)) {
        showResetStatus('Enter the 4-digit code from your email.');
        return;
      }
      toStep3.disabled = true;
      showResetStatus('Checking your code...');
      try {
        await postResetAction('verify_code', { email: resetEmail.value.trim(), code });
        step2.classList.remove('active');
        step3.classList.add('active');
        showResetStatus('Code verified. Choose a new password.', true);
      } catch (error) {
        showResetStatus(error.message);
      } finally {
        toStep3.disabled = false;
      }
    });

    document.getElementById('click-resend').addEventListener('click', async function(event) {
      event.preventDefault();
      const resendLink = event.currentTarget;
      resendLink.style.pointerEvents = 'none';
      try {
        const result = await postResetAction('send_code', { email: resetEmail.value.trim() });
        otpDigits.forEach(digit => { digit.value = ''; });
        otpDigits[0].focus();
        showResetStatus(result.message, true);
      } catch (error) {
        showResetStatus(error.message);
      } finally {
        resendLink.style.pointerEvents = '';
      }
    });

    const newPassword = document.getElementById('new-password');
    const confirmPassword = document.getElementById('confirm-password');
    const pwPopover = document.getElementById('pw-popover');
    const pbars = document.querySelectorAll('.pbar');
    const strengthText = document.getElementById('strength-text');
    const passwordError = document.getElementById('password-error');

    const ruleLength = document.getElementById('rule-length');
    const ruleCases = document.getElementById('rule-cases');
    const ruleNumber = document.getElementById('rule-number');
    const ruleSymbol = document.getElementById('rule-symbol');

    newPassword.addEventListener('focus', () => {
      pwPopover.classList.add('visible');
    });

    newPassword.addEventListener('blur', () => {
      if (!newPassword.value) {
        pwPopover.classList.remove('visible');
      }
    });

    function updateRule(elem, isValid) {
      const bullet = elem.querySelector('.bullet');
      if (isValid) {
        elem.classList.add('valid');
        bullet.textContent = '✓';
      } else {
        elem.classList.remove('valid');
        bullet.textContent = '✕';
      }
    }

    function evaluatePassword() {
      const val1 = newPassword.value;
      const val2 = confirmPassword.value;

      const isLength = val1.length >= 8 && val1.length <= 15;
      const isCases = /[a-z]/.test(val1) && /[A-Z]/.test(val1);
      const isNumber = /\d/.test(val1);
      const isSymbol = /[^a-zA-Z0-9]/.test(val1);

      updateRule(ruleLength, isLength);
      updateRule(ruleCases, isCases);
      updateRule(ruleNumber, isNumber);
      updateRule(ruleSymbol, isSymbol);

      let score = 0;
      if (isLength) score++;
      if (isCases) score++;
      if (isNumber) score++;
      if (isSymbol) score++;

      const labels = ['', 'Weak', 'Fair', 'Good', 'Strong'];
      const classes = ['', 'lvl-1', 'lvl-2', 'lvl-3', 'lvl-4'];
      const colors = ['', '#ef4444', '#f97316', '#eab308', '#22c55e'];

      pbars.forEach((bar, idx) => {
        bar.className = 'pbar';
        if (val1 && idx < score) {
          bar.classList.add(classes[score]);
        }
      });

      if (!val1) {
        strengthText.textContent = '';
        strengthText.classList.remove('visible');
        passwordError.textContent = '';
      } else {
        strengthText.textContent = labels[score] || 'Weak';
        strengthText.style.color = colors[score] || '#ef4444';
        strengthText.classList.add('visible');
      }

      if (val1 && !isLength) {
        passwordError.textContent = 'Must be 8~15 characters.';
      } else if (val1 && val2 && val1 !== val2) {
        passwordError.textContent = 'Passwords do not match.';
      } else {
        passwordError.textContent = '';
      }
    }

    newPassword.addEventListener('input', evaluatePassword);
    confirmPassword.addEventListener('input', evaluatePassword);

    toStep4.addEventListener('click', async function() {
      const val1 = newPassword.value;
      const val2 = confirmPassword.value;

      if (!val1 || !val2) {
        document.getElementById('form-step-3').reportValidity();
        return;
      }

      const isLength = val1.length >= 8 && val1.length <= 15;
      if (!isLength) {
        passwordError.textContent = 'Must be 8~15 characters.';
        return;
      }

      if (val1 !== val2) {
        passwordError.textContent = 'Passwords do not match.';
        return;
      }

      passwordError.textContent = '';
      toStep4.disabled = true;
      showResetStatus('Saving your new password...');
      try {
        await postResetAction('reset_password', {
          email: resetEmail.value.trim(),
          code: currentOtp(),
          password: val1,
          confirm_password: val2
        });
        pwPopover.classList.remove('visible');
        step3.classList.remove('active');
        step4.classList.add('active');
        showResetStatus('Your password has been reset.', true);
      } catch (error) {
        showResetStatus(error.message);
      } finally {
        toStep4.disabled = false;
      }
    });

    otpDigits.forEach((digit, index) => {
      digit.addEventListener('input', (e) => {
        e.target.value = e.target.value.replace(/\D/g, '').slice(-1);
        if(e.target.value.length === 1 && index < otpDigits.length - 1) {
          otpDigits[index + 1].focus();
        }
      });
      digit.addEventListener('keydown', (e) => {
        if(e.key === 'Backspace' && e.target.value === '' && index > 0) {
          otpDigits[index - 1].focus();
        }
      });
      digit.addEventListener('paste', (event) => {
        const pastedCode = (event.clipboardData?.getData('text') || '').replace(/\D/g, '').slice(0, 4);
        if (pastedCode.length === 4) {
          event.preventDefault();
          otpDigits.forEach((input, digitIndex) => { input.value = pastedCode[digitIndex] || ''; });
          otpDigits[3].focus();
        }
      });
    });

    const toggleNewPassword = document.getElementById('toggleNewPassword');
    const eyeIconNew = document.getElementById('eyeIconNew');

    toggleNewPassword.addEventListener('click', function () {
      const type = newPassword.getAttribute('type') === 'password' ? 'text' : 'password';
      newPassword.setAttribute('type', type);
      eyeIconNew.innerHTML = type === 'text' 
        ? `<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>`
        : `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>`;
    });

    const toggleConfirmPassword = document.getElementById('toggleConfirmPassword');
    const eyeIconConfirm = document.getElementById('eyeIconConfirm');

    toggleConfirmPassword.addEventListener('click', function () {
      const type = confirmPassword.getAttribute('type') === 'password' ? 'text' : 'password';
      confirmPassword.setAttribute('type', type);
      eyeIconConfirm.innerHTML = type === 'text'
        ? `<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>`
        : `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>`;
    });
  </script>

</body>
</html>