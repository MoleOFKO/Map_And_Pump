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
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <title>MAP & PUMP - Reset Password</title>
  
  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Prata&family=Poppins:wght@400;500&display=swap" rel="stylesheet">
  <link rel="icon" type="image/png" href="/images/Logo.png">
  <link rel="stylesheet" href="../../css/Login.css" />
  <style>
  /* ===== Auth pages UI refinements (presentation only) =====
     Layered over ../../css/Login.css. Only visual properties are set here.
     display / visibility are never changed, because the page JS toggles
     .active, .show, .visible, .valid and inline display on these elements. */
  .main-card { border: 1px solid rgba(255, 255, 255, .08); border-radius: 24px; box-shadow: 0 30px 80px rgba(0, 0, 0, .45); }
  .title { letter-spacing: .14em; font-weight: 800; }
  .input-group label { font-size: 12px; font-weight: 600; letter-spacing: .04em; }
  .input-wrapper { transition: box-shadow .18s ease, border-color .18s ease; }
  .input-wrapper input { transition: color .15s ease, border-color .15s ease; }
  .input-wrapper:focus-within { box-shadow: 0 0 0 3px rgba(14, 165, 233, .22); }
  .input-wrapper input::placeholder { opacity: .55; }
  .eye-btn { border-radius: 8px; transition: background .15s ease, color .15s ease; }
  .eye-btn:hover { background: rgba(255, 255, 255, .06); }
  .eye-btn:focus-visible, .back-nav-link:focus-visible, .forgot-link:focus-visible,
  .register-link:focus-visible, .resend-link:focus-visible, .social-btn:focus-visible,
  .submit-btn:focus-visible, .checkbox-container input:focus-visible, .close-btn:focus-visible,
  .otp-digit:focus-visible {
    outline: 2px solid #38bdf8;
    outline-offset: 2px;
  }
  .submit-btn { min-height: 48px; transition: transform .15s ease, box-shadow .18s ease, filter .15s ease; box-shadow: 0 10px 26px rgba(14, 165, 233, .25); }
  .submit-btn:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 14px 32px rgba(14, 165, 233, .35); }
  .submit-btn:active:not(:disabled) { transform: translateY(0) scale(.99); }
  .submit-btn:disabled, .submit-btn.is-loading { cursor: wait; }
  .social-btn { transition: background .15s ease, border-color .15s ease, transform .15s ease; }
  .social-btn:hover { border-color: rgba(255, 255, 255, .28); transform: translateY(-1px); }
  .forgot-link, .register-link, .resend-link { text-underline-offset: 3px; }
  .forgot-link:hover, .register-link:hover, .resend-link:hover { text-decoration: underline; }
  .back-nav-link { transition: opacity .15s ease, color .15s ease; }
  .back-nav-link:hover { opacity: .85; }
  .checkbox-container { cursor: pointer; }
  .otp-digit { font-weight: 700; text-align: center; transition: border-color .15s ease, box-shadow .15s ease; }
  .otp-digit:focus { border-color: #38bdf8; box-shadow: 0 0 0 3px rgba(56, 189, 248, .22); }
  .pbar { height: 4px; border-radius: 99px; overflow: hidden; transition: background-color .25s ease; }
  .mini-popup { box-shadow: 0 16px 40px rgba(0, 0, 0, .45); border-radius: 14px; }
  .step-icon-badge { box-shadow: 0 0 0 6px rgba(14, 165, 233, .08); }
  .success-badge { box-shadow: 0 0 0 6px rgba(34, 197, 94, .1); }
  .pw-popover, .strength-label { letter-spacing: .02em; }
  @media (max-width: 480px) {
    .main-card { border-radius: 18px; }
    .title { letter-spacing: .1em; }
    .submit-btn { min-height: 46px; }
  }
  @media (prefers-reduced-motion: reduce) {
    .submit-btn, .social-btn, .input-wrapper, .input-wrapper input, .otp-digit, .eye-btn, .back-nav-link { transition: none !important; }
    .submit-btn:hover:not(:disabled), .social-btn:hover { transform: none; }
  }

  /* ===== Responsive layer (Tablet / Phone; Laptop stays as original) =====
     Everything below is inside media queries, so screens wider than 1024px
     render exactly like the original Login.css design. Only sizing, spacing,
     overflow and stacking are changed; display of .auth-step / .pw-popover /
     .mini-popup / .strength-label is never touched (page JS toggles
     .active, .visible and .show on them). */

  /* Phone-only logo shown inside the card (hidden everywhere else) */
  .mobile-logo { display: none; }

  /* --- Small laptop (861px - 1024px): just shrink so nothing is cut off --- */
  @media (min-width: 861px) and (max-width: 1024px) {
    body { padding: 0 16px; }
    .main-card { width: 100%; max-width: 900px; padding: 30px 32px; gap: 32px; }
    .form-container { width: 400px; flex-shrink: 0; }
    .brand-title { font-size: 30px; letter-spacing: 4px; }
  }

  /* --- Tablet and below (860px): stack form on top, branding below --- */
  @media (max-width: 860px) {
    html { -webkit-text-size-adjust: 100%; text-size-adjust: 100%; }
    body {
      align-items: flex-start;
      overflow-x: hidden;
      overflow-y: auto;
      min-height: 100vh;
      min-height: 100dvh;
      padding: max(16px, env(safe-area-inset-top)) max(16px, env(safe-area-inset-right)) max(16px, env(safe-area-inset-bottom)) max(16px, env(safe-area-inset-left));
    }
    .bg-blur-1 { width: 360px; height: 360px; }
    .bg-blur-2 { width: 320px; height: 320px; }
    .main-card {
      flex-direction: column;
      align-items: center;
      width: 100%;
      max-width: 560px;
      min-height: 0;
      margin: auto;
      padding: 24px 28px 28px;
      gap: 24px;
    }
    .form-container, .branding-container { min-width: 0; }
    .form-container {
      width: 100%;
      max-width: 100%;
      margin-top: 40px;
      padding: 28px 26px;
    }
    .branding-container { flex: 0 0 auto; width: 100%; }
    .title, .step-subtitle, .brand-title, .brand-subtitle, .mini-msg { overflow-wrap: anywhere; }
    .logo-img { width: 110px; max-width: 100%; object-fit: contain; margin-bottom: 14px; }
    .brand-title { font-size: 28px; }
    .back-nav-link { top: 16px; left: 22px; min-height: 40px; }
    .input-wrapper input { padding-right: 48px; }
    .eye-btn { min-width: 40px; min-height: 40px; right: 2px; }
    .close-btn { min-width: 40px; min-height: 40px; }
    .form-options { flex-wrap: wrap; gap: 8px 16px; }
    .register-prompt, .resend-prompt { line-height: 1.7; }
    .mini-popup { top: max(16px, env(safe-area-inset-top)); width: max-content; max-width: calc(100vw - 32px); }
    /* Password rules: show under the field instead of floating off to the right */
    .pw-popover {
      position: static;
      left: auto;
      top: auto;
      width: 100%;
      min-width: 0;
      max-width: none;
      margin-top: 10px;
    }
  }

  /* --- Phone (600px) --- */
  @media (max-width: 600px) {
    body { padding-left: max(10px, env(safe-area-inset-left)); padding-right: max(10px, env(safe-area-inset-right)); }
    .bg-blur-1 { width: 260px; height: 260px; filter: blur(100px); }
    .bg-blur-2 { width: 240px; height: 240px; filter: blur(100px); }
    .main-card { padding: 16px 14px 22px; gap: 18px; }
    .form-container { margin-top: 38px; padding: 22px 16px; border-radius: 16px; }
    .title { font-size: 22px; }
    .step-subtitle { font-size: 12.5px; }
    /* 16px stops iOS Safari from zooming when an input is focused */
    .input-wrapper input { font-size: 16px; height: 46px; }
    .input-group label { font-size: 12px; }
    .otp-digit-container { gap: 8px; }
    .otp-digit { width: clamp(38px, 12.5vw, 50px); height: 50px; font-size: 18px; padding: 0; }
    .form-options { flex-direction: column; align-items: flex-start; }
    .divider-text { font-size: 10px; }
    .logo-img { width: 84px; margin-bottom: 10px; }
    .brand-title { font-size: 22px; letter-spacing: 3px; }
    .brand-subtitle { font-size: 11px; letter-spacing: 1px; }
    .back-nav-link { left: 14px; top: 12px; }
    .mini-popup { min-width: 0; width: calc(100vw - 32px); }

    /* Login + Register: logo only (no text) inside the card on phones */
    body.has-mobile-logo .branding-container { display: none; }
    body.has-mobile-logo .main-card { gap: 0; }
    .form-container { position: relative; }
    .mobile-logo {
      display: block;
      position: absolute;
      top: 12px;
      right: 12px;
      width: 44px;
      height: auto;
      margin: 0;
      object-fit: contain;
      z-index: 2;
    }
  }

  /* --- Small phones (360px and below) --- */
  @media (max-width: 360px) {
    .main-card { padding: 14px 10px 18px; }
    .form-container { padding: 18px 12px; }
    .otp-digit-container { gap: 5px; }
    .otp-digit { width: clamp(34px, 12vw, 42px); height: 46px; }
    .brand-title { letter-spacing: 2px; }
    .mobile-logo { width: 38px; top: 10px; right: 10px; }
  }

  /* --- Landscape phones / very short screens --- */
  @media (max-width: 860px) and (max-height: 520px) and (orientation: landscape) {
    body { align-items: flex-start; }
    .main-card { margin: 0 auto; min-height: 0; }
    .branding-container { display: none; }
    .form-container { margin-top: 34px; }
    .mobile-logo { display: none; }
  }

  /* --- Touch screens: no sticky hover lift --- */
  @media (hover: none) {
    .social-btn:hover { transform: none; }
    .submit-btn:hover:not(:disabled) { transform: none; }
  }
  </style>
</head>
<body class="auth-refined has-mobile-logo">

  <!-- Background Decorative Blurs -->
  <div class="bg-blur bg-blur-1"></div>
  <div class="bg-blur bg-blur-2"></div>

  <!-- Main Container -->
  <div class="main-card">
    
    <!-- Left Side: Forgot Password Multi-Step Flow -->
    <div class="form-container">
      <img src="../../images/Logo.png" alt="MAP &amp; PUMP Logo" class="mobile-logo" />
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