<?php
session_start();
if (empty($_SESSION['registration_csrf'])) {
  $_SESSION['registration_csrf'] = bin2hex(random_bytes(32));
}
$registrationCsrf = $_SESSION['registration_csrf'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>MAP & PUMP - Register</title>
  
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
    
    <!-- Transparent Modern Back Button pointing to Homepage/Index -->
    <a href="/php/HomePage.php" class="back-nav-link" title="Return to Homepage">
      <svg class="back-arrow-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <line x1="19" y1="12" x2="5" y2="12"></line>
        <polyline points="12 19 5 12 12 5"></polyline>
      </svg>
      <span>Home</span>
    </a>

    <!-- Left Side: Registration Flow -->
    <div class="form-container">
      
      <!-- STEP 1: Registration Form -->
      <div class="auth-step active" id="step-1">
        <div class="header">
          <h1 class="title">REGISTER</h1>
        </div>

        <div id="mini-popup" class="mini-popup" aria-hidden="true">
          <div class="mini-msg"></div>
          <button type="button" class="close-btn" aria-label="Close">×</button>
        </div>

        <div id="register-status" role="alert" style="display:none; margin-bottom: 1rem; padding: 0.85rem 1rem; border-radius: 12px;"></div>

        <form id="form-step-1" onsubmit="return false;">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($registrationCsrf, ENT_QUOTES, 'UTF-8') ?>">
          
          <!-- Username Input -->
          <div class="input-group">
            <label for="username">Username</label>
            <div class="input-wrapper">
              <input type="text" id="username" name="username" placeholder="johndoe123" maxlength="225" required />
            </div>
          </div>

          <!-- Email Input -->
          <div class="input-group">
            <label for="register-email">Email</label>
            <div class="input-wrapper">
              <input type="email" id="register-email" name="email" placeholder="example123@gmail.com" required />
            </div>
            <small style="display:block;margin-top:8px;color:#a8b3c7;line-height:1.45;">Use an email address you can access only to receive the verification code. Do not enter any other real personal or sensitive information in this school project.</small>
          </div>

          <!-- Password Input -->
          <div class="input-group relative-group">
            <label for="new-password">Password</label>
            <div class="input-wrapper">
              <input type="password" id="new-password" name="password" placeholder="••••••••••••" required />
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

          <!-- Confirm Password Input -->
          <div class="input-group">
            <label for="confirm-password">Confirm password</label>
            <div class="input-wrapper">
              <input type="password" id="confirm-password" name="confirm_password" placeholder="••••••••••••" required />
              <button type="button" class="eye-btn" id="toggleConfirmPassword" aria-label="Toggle password visibility">
                <svg class="eye-icon" id="eyeIconConfirm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                  <circle cx="12" cy="12" r="3"></circle>
                </svg>
              </button>
            </div>
          </div>

          <label style="display:flex;gap:9px;align-items:flex-start;margin:4px 0 18px;color:#d5dceb;font-size:12px;line-height:1.45;cursor:pointer;">
            <input type="checkbox" id="test-data-acknowledgement" required style="margin-top:3px;accent-color:#0ea5e9;">
            <span>I understand that this is a school test project. I will use my accessible email only for OTP verification and fictional data for every other field.</span>
          </label>

          <!-- Submit Button -->
          <div class="login-btn-wrapper">
            <button type="button" class="submit-btn full-width-btn" id="to-step-2">REGISTER</button>
          </div>

          <div class="register-prompt" style="margin-top: 1.5rem;">
            <span>Already have an account?</span>
            <a href="login.php" class="register-link">Login Here</a>
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
          <h1 class="title">Verify your email</h1>
          <p class="step-subtitle">We sent a 6-digit code to <span id="display-email"></span></p>
        </div>
        <div id="verify-status" role="alert" style="display:none; margin-bottom: 1rem; padding: 0.85rem 1rem; border-radius: 12px;"></div>

        <form id="form-step-2" onsubmit="return false;">
          <input type="hidden" id="verify-csrf-token" value="<?= htmlspecialchars($registrationCsrf, ENT_QUOTES, 'UTF-8') ?>">
          <div class="input-group">
            <div class="otp-digit-container">
              <input type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="1" class="otp-digit" required />
              <input type="text" inputmode="numeric" maxlength="1" class="otp-digit" required />
              <input type="text" inputmode="numeric" maxlength="1" class="otp-digit" required />
              <input type="text" inputmode="numeric" maxlength="1" class="otp-digit" required />
              <input type="text" inputmode="numeric" maxlength="1" class="otp-digit" required />
              <input type="text" inputmode="numeric" maxlength="1" class="otp-digit" required />
            </div>
          </div>

          <div class="resend-prompt">
            <span>Didn't receive the email?</span> <a href="#" id="click-resend" class="resend-link">Click to resend</a>
          </div>

          <div class="login-btn-wrapper">
            <button type="button" class="submit-btn full-width-btn" id="to-step-3">Confirm</button>
          </div>

          <div class="register-prompt">
            <a href="#" id="back-to-step-1" class="register-link">Back to registration</a>
          </div>
        </form>
      </div>

      <!-- STEP 3: Success State -->
      <div class="auth-step" id="step-3">
        <div class="step-icon-badge success-badge">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M20 6L9 17l-5-5"></path>
          </svg>
        </div>
        <div class="header text-center">
          <h1 class="title">Registration complete!</h1>
          <p class="step-subtitle">Your account has been created successfully. You can now log in.</p>
        </div>

        <div class="login-btn-wrapper" style="margin-top: 30px;">
          <a href="login.php" class="submit-btn full-width-btn" style="text-decoration: none;">Go to login</a>
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
    // Helper utilities
    function setButtonLoading(button, loading, label = '') {
      if (!button) return;
      const originalText = (button.dataset.originalText || button.textContent || '').trim();
      if (!button.dataset.originalText) {
        button.dataset.originalText = originalText || (button.dataset.defaultText || button.getAttribute('data-label') || '');
      }
      if (!button.dataset.originalHtml) {
        button.dataset.originalHtml = button.innerHTML;
      }

      if (loading) {
        button.classList.add('is-loading');
        try { button.disabled = true; } catch (e) {}
        button.innerHTML = '<span class="btn-pulse" aria-label="Loading"><span class="btn-pulse-dot"></span><span class="btn-pulse-dot"></span><span class="btn-pulse-dot"></span></span>';
        return;
      }

      button.classList.remove('is-loading');
      try { button.disabled = false; } catch (e) {}
      const restoreText = button.dataset.originalText || button.dataset.defaultText || 'Submit';
      button.innerHTML = restoreText;
    }

    async function postForm(url, formData) {
      const response = await fetch(url, { method: 'POST', body: formData, credentials: 'same-origin' });
      const text = await response.text();
      const contentType = response.headers.get('content-type') || '';
      if (!contentType.includes('application/json')) {
        return { ok: false, message: text || 'Server returned an invalid response.' };
      }
      try { return JSON.parse(text); } catch (e) { return { ok: false, message: text || 'Server returned invalid JSON.' }; }
    }

    function registrationFormData() {
      const data = new FormData();
      data.append('username', (document.getElementById('username')?.value || '').trim());
      data.append('email', (document.getElementById('register-email')?.value || '').trim());
      data.append('password', document.getElementById('new-password')?.value || '');
      data.append('confirm_password', document.getElementById('confirm-password')?.value || '');
      data.append('csrf_token', document.querySelector('#form-step-1 [name="csrf_token"]')?.value || '');
      data.append('test_data_acknowledgement', document.getElementById('test-data-acknowledgement')?.checked ? 'yes' : '');
      return data;
    }

    function showStatus(element, message, ok = false) {
      if (!element) return;
      element.textContent = message;
      element.style.display = message ? 'block' : 'none';
      element.style.background = ok ? 'rgba(34, 197, 94, 0.12)' : 'rgba(248, 113, 113, 0.12)';
      element.style.border = ok ? '1px solid rgba(34, 197, 94, 0.35)' : '1px solid rgba(248, 113, 113, 0.35)';
      element.style.color = ok ? '#d1fae5' : '#fdd';
    }

    const step1 = document.getElementById('step-1');
    const step2 = document.getElementById('step-2');
    const step3 = document.getElementById('step-3');

    const toStep2 = document.getElementById('to-step-2');
    const toStep3 = document.getElementById('to-step-3');
    const backToStep1 = document.getElementById('back-to-step-1');
    const resendLink = document.getElementById('click-resend');
    const registerStatus = document.getElementById('register-status');
    const verifyStatus = document.getElementById('verify-status');
    
    const usernameInput = document.getElementById('username');
    const registerEmail = document.getElementById('register-email');
    const displayEmail = document.getElementById('display-email');
    
    const newPassword = document.getElementById('new-password');
    const confirmPassword = document.getElementById('confirm-password');
    const pwPopover = document.getElementById('pw-popover');
    const pbars = document.querySelectorAll('.pbar');
    const strengthText = document.getElementById('strength-text');

    const ruleLength = document.getElementById('rule-length');
    const ruleCases = document.getElementById('rule-cases');
    const ruleNumber = document.getElementById('rule-number');
    const ruleSymbol = document.getElementById('rule-symbol');

    const miniPopup = document.getElementById('mini-popup');
    const miniMsg = miniPopup?.querySelector('.mini-msg');
    const miniClose = miniPopup?.querySelector('.close-btn');

    function showMiniPopup(message) {
      try {
        if (!miniPopup || !miniMsg) return;
        miniMsg.textContent = message;
        miniPopup.classList.add('show');
        miniPopup.setAttribute('aria-hidden', 'false');
        clearTimeout(miniPopup._hideTimer);
        miniPopup._hideTimer = setTimeout(() => {
          miniPopup.classList.remove('show');
          miniPopup.setAttribute('aria-hidden', 'true');
        }, 5000);
      } catch (e) {
        console.error(e);
      }
    }

    function hideMiniPopup() {
      if (!miniPopup) return;
      clearTimeout(miniPopup._hideTimer);
      miniPopup.classList.remove('show');
      miniPopup.setAttribute('aria-hidden', 'true');
    }

    miniClose?.addEventListener('click', hideMiniPopup);

    function updateRule(elem, isValid) {
      const bullet = elem.querySelector('.bullet');
      if (isValid) {
        elem.classList.add('valid');
        bullet.textContent = '✓';
        elem.style.display = 'none';
      } else {
        elem.classList.remove('valid');
        bullet.textContent = '✕';
        elem.style.display = 'flex';
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
      } else {
        strengthText.textContent = labels[score] || 'Weak';
        strengthText.style.color = colors[score] || '#ef4444';
        strengthText.classList.add('visible');
      }

      const failedRules = [
        !isLength,
        !isCases,
        !isNumber,
        !isSymbol
      ].filter(Boolean);

      if (val1 && failedRules.length > 0) {
        pwPopover.classList.add('visible');
        pwPopover.setAttribute('aria-hidden', 'false');
      } else {
        pwPopover.classList.remove('visible');
        pwPopover.setAttribute('aria-hidden', 'true');
      }

      if (val1 && val2 && val1 !== val2) {
        // leave mismatch handling to submit validation so the popup is consistent with login page behavior
      }
    }

    newPassword.addEventListener('input', evaluatePassword);
    confirmPassword.addEventListener('input', evaluatePassword);

    toStep2.addEventListener('click', async function() {
      const form = document.getElementById('form-step-1');
      if (!form.checkValidity()) {
        form.reportValidity();
        setButtonLoading(toStep2, false);
        return;
      }

      const val1 = newPassword.value;
      const val2 = confirmPassword.value;
      const isLength = val1.length >= 8 && val1.length <= 15;
      const isCases = /[a-z]/.test(val1) && /[A-Z]/.test(val1);
      const isNumber = /\d/.test(val1);
      const isSymbol = /[^a-zA-Z0-9]/.test(val1);

      if (!isLength || !isCases || !isNumber || !isSymbol) {
        evaluatePassword();
        setButtonLoading(toStep2, false);
        return;
      }

      if (val1 !== val2) {
        showMiniPopup('Password Does Not Match, Please Try Again');
        setButtonLoading(toStep2, false);
        return;
      }

      showStatus(registerStatus, '');
      setButtonLoading(toStep2, true);

      try {
        const result = await postForm('register_process.php', registrationFormData());
        if (!result.ok) {
          showMiniPopup(result.message || 'Email Does Not Exist, Please Try Again');
          return;
        }

        displayEmail.textContent = registerEmail.value;
        showStatus(verifyStatus, result.message || 'Verification code sent.', true);
        pwPopover.classList.remove('visible');
        step1.classList.remove('active');
        step2.classList.add('active');
        document.querySelector('.otp-digit')?.focus();
      } catch (error) {
        showMiniPopup('Could not contact the server. Please try again.');
      } finally {
        setButtonLoading(toStep2, false);
      }
    });

    backToStep1.addEventListener('click', function(e) {
      e.preventDefault();
      step2.classList.remove('active');
      step1.classList.add('active');
    });

    toStep3.addEventListener('click', async function() {
      const code = Array.from(document.querySelectorAll('.otp-digit')).map((digit) => digit.value.trim()).join('');
      if (!/^\d{6}$/.test(code)) {
        showStatus(verifyStatus, 'Please enter the 6-digit code.');
        return;
      }

      showStatus(verifyStatus, '');
      setButtonLoading(toStep3, true);

      try {
        const data = new FormData();
        data.append('code', code);
        data.append('csrf_token', document.getElementById('verify-csrf-token').value);
        const result = await postForm('register_verify.php', data);

        if (!result.ok) {
          showStatus(verifyStatus, result.message || 'Verification failed.');
          return;
        }

        if (result.redirect) {
          window.location.href = result.redirect;
          return;
        }

        step2.classList.remove('active');
        step3.classList.add('active');
      } catch (error) {
        showStatus(verifyStatus, 'Could not contact the server. Please try again.');
      } finally {
        setButtonLoading(toStep3, false);
      }
    });

    resendLink.addEventListener('click', async function(e) {
      e.preventDefault();
      showStatus(verifyStatus, '');
      setButtonLoading(resendLink, true);

      try {
        const result = await postForm('register_process.php', registrationFormData());
        showStatus(verifyStatus, result.message || (result.ok ? 'Verification code sent.' : 'Could not resend code.'), !!result.ok);
      } catch (error) {
        showStatus(verifyStatus, 'Could not contact the server. Please try again.');
      } finally {
        setButtonLoading(resendLink, false);
      }
    });

    const otpDigits = document.querySelectorAll('.otp-digit');
    otpDigits.forEach((digit, index) => {
      digit.addEventListener('input', (e) => {
        if(e.target.value.length === 1 && index < otpDigits.length - 1) {
          otpDigits[index + 1].focus();
        }
      });
      digit.addEventListener('keydown', (e) => {
        if(e.key === 'Backspace' && e.target.value === '' && index > 0) {
          otpDigits[index - 1].focus();
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
