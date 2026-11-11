<?php
require_once __DIR__ . '/session_helper.php';
map_pump_configure_session();
session_start();

// Remember Me: if this browser is still logged in from a remembered login, skip the login page.
// The 10 second guard stops a redirect loop if the dashboard sends the user straight back here.
if (!empty($_SESSION['is_logged_in']) && !empty($_SESSION['remember_me']) && !empty($_SESSION['login_destination'])) {
  $lastAutoRedirect = (int) ($_SESSION['login_autoredirect_at'] ?? 0);
  if (time() - $lastAutoRedirect > 10) {
    $_SESSION['login_autoredirect_at'] = time();
    header('Location: ' . $_SESSION['login_destination']);
    exit;
  }
}

$loginError = $_SESSION['login_error'] ?? '';
$socialDebug = $_SESSION['social_login_debug'] ?? '';
$loginSuccess = $_SESSION['login_success'] ?? '';
$rememberedEmail = (string) ($_COOKIE[MAP_PUMP_REMEMBER_EMAIL_COOKIE] ?? '');
$prefillEmail = (string) ($_SESSION['login_prefill_email'] ?? $_COOKIE[MAP_PUMP_LOGIN_PREFILL_EMAIL_COOKIE] ?? '');
$loginEmailValue = $prefillEmail !== '' ? $prefillEmail : $rememberedEmail;
unset($_SESSION['login_error']);
unset($_SESSION['login_success']);
unset($_SESSION['social_login_debug']);
unset($_SESSION['login_prefill_email']);
if (!empty($_COOKIE[MAP_PUMP_LOGIN_PREFILL_EMAIL_COOKIE])) {
  setcookie(MAP_PUMP_LOGIN_PREFILL_EMAIL_COOKIE, '', [
    'expires' => time() - 3600,
    'path' => '/',
    'domain' => '',
    'secure' => map_pump_secure_cookie(),
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <title>MAP & PUMP - Login</title>
  
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
    
    <!-- Transparent Modern Back Button pointing to Homepage/Index -->
    <a href="/php/HomePage.php" class="back-nav-link" title="Return to Homepage">
      <svg class="back-arrow-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <line x1="19" y1="12" x2="5" y2="12"></line>
        <polyline points="12 19 5 12 12 5"></polyline>
      </svg>
      <span>Home</span>
    </a>

    <!-- Left Side: Login Form -->
    <div class="form-container">
      <img src="../../images/Logo.png" alt="MAP &amp; PUMP Logo" class="mobile-logo" />
      <div class="header">
        <h1 class="title">LOGIN</h1>
      </div>

      <!-- Compact popup for server-side messages (OAuth / login) - populated via data attrs -->
      <div id="mini-popup" class="mini-popup" aria-hidden="true"
           data-login="<?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?>"
           data-social="<?= htmlspecialchars($socialDebug, ENT_QUOTES, 'UTF-8') ?>"
           data-success="<?= htmlspecialchars($loginSuccess, ENT_QUOTES, 'UTF-8') ?>">
        <div class="mini-msg"></div>
        <button type="button" class="close-btn" aria-label="Close">×</button>
      </div>

      <form id="login-form" action="login_process.php" method="POST">
        
        <!-- Email Input -->
        <div class="input-group">
          <label for="email">Email</label>
          <div class="input-wrapper">
            <input type="email" id="email" name="email" placeholder="example123@gmail.com" spellcheck="false" autocorrect="off" autocapitalize="off" value="<?= htmlspecialchars($loginEmailValue, ENT_QUOTES, 'UTF-8') ?>" required />
          </div>
        </div>

        <!-- Password Input -->
        <div class="input-group">
          <label for="password">Password</label>
          <div class="input-wrapper">
            <input type="password" id="password" name="password" spellcheck="false" placeholder="••••••••••••" required />
            <button type="button" class="eye-btn" id="togglePassword" aria-label="Toggle password visibility">
              <svg class="eye-icon" id="eyeIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                <circle cx="12" cy="12" r="3"></circle>
              </svg>
            </button>
          </div>
        </div>

        <!-- Options: Remember Me & Forgot Password -->
        <div class="form-options">
          <label class="checkbox-container">
            <input type="checkbox" name="remember" value="1" <?= $rememberedEmail !== '' ? 'checked' : '' ?> />
            <span>Remember Me</span>
          </label>
          <a href="forgot_password.php" class="forgot-link">Forgot Password?</a>
        </div>

        <!-- Submit Button -->
        <div class="login-btn-wrapper">
          <button type="submit" class="submit-btn" id="loginSubmitBtn" data-loading-text="LOGGING IN">LOG IN</button>
        </div>

        <!-- Divider -->
        <div class="divider">
          <div class="line"></div>
          <span class="divider-text">OR CONTINUE WITH</span>
          <div class="line"></div>
        </div>

        <!-- Social Login Button -->
        <div class="social-options">
          <a href="social_login.php?provider=google" class="social-btn" aria-label="Continue with Google" data-loading-text="Connecting...">
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
              <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
              <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
              <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
            </svg>
            <span>Continue with Google</span>
          </a>
        </div>

        <!-- Register Link -->
        <div class="register-prompt">
          <span>Don't have an account?</span>
          <a href="register.php" class="register-link">Register Here</a>
        </div>
      </form>
    </div>

    <!-- Right Side: Branding -->
    <div class="branding-container">
      <img src="../../images/Logo.png" alt="MAP & PUMP Logo" class="logo-img" />
      <h2 class="brand-title">MAP &amp; PUMP</h2>
      <p class="brand-subtitle">No need to queue.<br>Get a token and fill up on time</p>
    </div>

  </div>

  <!-- JS Interactivity -->
  <script>
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
        button.setAttribute('aria-busy', 'true');
        if ('disabled' in button) button.disabled = true;
        button.innerHTML = '<span class="btn-pulse" aria-label="Loading"><span class="btn-pulse-dot"></span><span class="btn-pulse-dot"></span><span class="btn-pulse-dot"></span></span>';
        return;
      }

      button.classList.remove('is-loading');
      button.removeAttribute('aria-busy');
      if ('disabled' in button) button.disabled = false;
      const restoreText = button.dataset.originalText || button.dataset.defaultText || 'Submit';
      button.innerHTML = restoreText;
    }

    // Show compact server-side popup when a message is present to avoid expanding the card
    (function () {
      try {
        const popup = document.getElementById('mini-popup');
        if (!popup) return;

        const msgEl = popup.querySelector('.mini-msg');
        const closeBtn = popup.querySelector('.close-btn');
        const messages = [
          popup.dataset.social,
          popup.dataset.login,
          popup.dataset.success
        ].filter((value) => typeof value === 'string' && value.trim() !== '');

        const text = messages[0] || '';
        if (text) {
          msgEl.textContent = popup.dataset.social ? `OAuth: ${popup.dataset.social}` : text;
          popup.classList.add('show');
          popup.setAttribute('aria-hidden', 'false');
          setTimeout(() => {
            popup.classList.remove('show');
            popup.setAttribute('aria-hidden', 'true');
          }, 5000);
        }

        closeBtn?.addEventListener('click', function () {
          popup.classList.remove('show');
          popup.setAttribute('aria-hidden', 'true');
        });
      } catch (e) {
        console.error(e);
      }
    })();
    const passwordInput = document.getElementById('password');
    const togglePasswordBtn = document.getElementById('togglePassword');
    const eyeIcon = document.getElementById('eyeIcon');

    document.getElementById('login-form')?.addEventListener('submit', function (event) {
      const submitButton = document.getElementById('loginSubmitBtn');
      if (!this.checkValidity()) {
        event.preventDefault();
        setButtonLoading(submitButton, false);
        this.reportValidity();
        return;
      }

      setButtonLoading(submitButton, true);
    });

    document.querySelectorAll('.social-btn').forEach((button) => {
      button.addEventListener('click', function () {
        setButtonLoading(button, true, button.dataset.loadingText || 'Connecting...');
      });
    });
    togglePasswordBtn.addEventListener('click', function () {
      const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
      passwordInput.setAttribute('type', type);
      
      eyeIcon.innerHTML = type === 'text' 
        ? `<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>`
        : `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>`;
    });
  </script>

</body>
</html>