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
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <title>MAP & PUMP - Register</title>
  
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

  /* Register page: always allow scrolling (card is taller than most laptop screens) */
  body.page-register { overflow-x: hidden; overflow-y: auto; padding-top: 24px; padding-bottom: 24px; }


  /* ===== Logo speech-cloud: the logo "talks" (tail points at the logo) ===== */
  .logo-bubble {
    --bx: 50px;
    position: relative;
    align-self: center;
    width: 280px;
    max-width: 100%;
    margin: 0 0 24px;
    padding: 12px 16px;
    font-size: 12px;
    line-height: 1.5;
    text-align: left;
    color: #d5e3f2;
    background: #11293b;
    border: 1px solid rgba(56, 189, 248, .55);
    border-radius: 30px 36px 32px 34px / 32px 30px 36px 30px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, .3), 0 0 16px rgba(56, 189, 248, .15);
    overflow-wrap: anywhere;
    transform: translateX(var(--bx));
    animation: logoTalk 3.2s ease-in-out infinite;
  }
  /* speech tail pointing down at the logo */
  .logo-bubble::before {
    content: "";
    position: absolute;
    width: 14px;
    height: 14px;
    left: 83px;
    bottom: -8px;
    background: #11293b;
    border-right: 1px solid rgba(56, 189, 248, .55);
    border-bottom: 1px solid rgba(56, 189, 248, .55);
    border-radius: 0 0 4px 0;
    transform: rotate(45deg);
  }
  .logo-bubble-mobile { display: none; }
  @keyframes logoTalk {
    0%, 100% { transform: translate(var(--bx), 0); }
    50%      { transform: translate(var(--bx), -4px); }
  }
  @media (prefers-reduced-motion: reduce) {
    .logo-bubble { animation: none; }
  }

  /* Register card: same size as the Login card on laptop */
  @media (min-width: 861px) {
    body.page-register .form-container { padding: 22px 26px; }
    body.page-register .input-group { margin-bottom: 12px; }
    body.page-register .input-group label { margin-bottom: 5px; }
    body.page-register .input-wrapper input { height: 40px; }
    body.page-register #form-step-1 > label { margin: 2px 0 10px !important; font-size: 11px !important; }
    body.page-register .login-btn-wrapper { margin-top: 8px; margin-bottom: 8px; }
    body.page-register .register-prompt { margin-top: 12px !important; }
  }

  /* Tablet: cloud centered right above the logo */
  @media (max-width: 860px) {
    .logo-bubble { --bx: 0px; width: min(300px, 100%); }
    .logo-bubble::before { left: calc(50% - 7px); }
  }

  /* Phone: logo sits in the card's top-right corner; cloud is beside it, tail points right */
  @media (max-width: 600px) {
    .branding-container .logo-bubble { display: none; }
    .logo-bubble-mobile {
      display: block;
      align-self: auto;
      width: auto;
      max-width: none;
      margin: 0 52px 18px 0;
      padding: 10px 13px;
      font-size: 11px;
      border-radius: 22px 26px 24px 26px / 24px 22px 26px 22px;
    }
    .logo-bubble-mobile::before {
      left: auto;
      right: -8px;
      bottom: auto;
      top: 6px;
      border-bottom: 0;
      border-top: 1px solid rgba(56, 189, 248, .55);
      border-radius: 0 4px 0 0;
    }
  }
  @media (max-width: 360px) {
    .logo-bubble-mobile { margin-right: 45px; font-size: 10.5px; }
  }
  @media (max-width: 860px) and (max-height: 520px) and (orientation: landscape) {
    .logo-bubble-mobile { display: none; }
  }
  </style>
</head>
<body class="auth-refined has-mobile-logo page-register">

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
      <img src="../../images/Logo.png" alt="MAP &amp; PUMP Logo" class="mobile-logo" />
      
      <!-- STEP 1: Registration Form -->
      <div class="auth-step active" id="step-1">
        <div class="logo-bubble logo-bubble-mobile" role="note">Use an email address you can access for verification and essential account, security, invitation, or request-status notices. Use fictional information for every other field.</div>
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
                <li id="rule-length"><span class="bullet">✕</span> Minimum 8 Characters</li>
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
            <span>I understand that this is a school demo. I may use an accessible email for verification and essential account notices, and will use fictional information for every other field.</span>
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
            <button type="button" class="submit-btn full-width-btn" id="verify-registration">Confirm</button>
          </div>

          <div class="register-prompt">
            <a href="#" id="back-to-step-1" class="register-link">Back to registration</a>
          </div>
        </form>
      </div>

    </div>

    <!-- Right Side: Branding -->
    <div class="branding-container">
      <div class="logo-bubble" role="note">Use an email address you can access for verification and essential account, security, invitation, or request-status notices. Use fictional information for every other field.</div>
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

    const toStep2 = document.getElementById('to-step-2');
    const verifyRegistration = document.getElementById('verify-registration');
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

      const isLength = val1.length >= 8;
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
      const isLength = val1.length >= 8;
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

    verifyRegistration.addEventListener('click', async function() {
      const code = Array.from(document.querySelectorAll('.otp-digit')).map((digit) => digit.value.trim()).join('');
      if (!/^\d{6}$/.test(code)) {
        showStatus(verifyStatus, 'Please enter the 6-digit code.');
        return;
      }

      showStatus(verifyStatus, '');
      setButtonLoading(verifyRegistration, true);

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

        window.location.href = 'login.php';
      } catch (error) {
        showStatus(verifyStatus, 'Could not contact the server. Please try again.');
      } finally {
        setButtonLoading(verifyRegistration, false);
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