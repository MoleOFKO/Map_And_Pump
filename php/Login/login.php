<?php
require_once __DIR__ . '/session_helper.php';
map_pump_configure_session();
session_start();
$loginError = $_SESSION['login_error'] ?? '';
$loginSuccess = $_SESSION['login_success'] ?? '';
$rememberedEmail = (string) ($_COOKIE[MAP_PUMP_REMEMBER_EMAIL_COOKIE] ?? '');
unset($_SESSION['login_error']);
unset($_SESSION['login_success']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>MAP & PUMP - Login</title>
  
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

    <!-- Left Side: Login Form -->
    <div class="form-container">
      <div class="header">
        <span class="welcome-text">Welcome!</span>
        <h1 class="title">LOGIN</h1>
      </div>

      <?php if ($loginError): ?>
        <div class="alert alert-danger" role="alert" style="margin-bottom: 1rem; padding: 0.85rem 1rem; border-radius: 12px; background: rgba(248, 113, 113, 0.12); border: 1px solid rgba(248, 113, 113, 0.35); color: #fdd;">
          <?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?>
        </div>
      <?php endif; ?>
      <?php if ($loginSuccess): ?>
        <div class="alert alert-success" role="alert" style="margin-bottom: 1rem; padding: 0.85rem 1rem; border-radius: 12px; background: rgba(34, 197, 94, 0.12); border: 1px solid rgba(34, 197, 94, 0.35); color: #d1fae5;">
          <?= htmlspecialchars($loginSuccess, ENT_QUOTES, 'UTF-8') ?>
        </div>
      <?php endif; ?>

      <form id="login-form" action="login_process.php" method="POST">
        
        <!-- Email Input -->
        <div class="input-group">
          <label for="email">Email</label>
          <div class="input-wrapper">
            <input type="email" id="email" name="email" placeholder="example123@gmail.com" value="<?= htmlspecialchars($rememberedEmail, ENT_QUOTES, 'UTF-8') ?>" required />
          </div>
        </div>

        <!-- Password Input -->
        <div class="input-group">
          <label for="password">Password</label>
          <div class="input-wrapper">
            <input type="password" id="password" name="password" placeholder="••••••••••••" required />
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
          <button type="submit" class="submit-btn">LOG IN</button>
        </div>

        <!-- Divider -->
        <div class="divider">
          <div class="line"></div>
          <span class="divider-text">OR CONTINUE WITH</span>
          <div class="line"></div>
        </div>

        <!-- Social Login Icons -->
        <div class="social-options">
          <a href="social_login.php?provider=google" class="social-btn" aria-label="Login with Google">
            <svg viewBox="0 0 24 24">
              <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
              <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
              <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
              <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
            </svg>
          </a>
          
          <a href="social_login.php?provider=facebook" class="social-btn" aria-label="Login with Facebook">
            <svg viewBox="0 0 24 24" fill="#1877F2">
              <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
            </svg>
          </a>

          <a href="social_login.php?provider=apple" class="social-btn" aria-label="Login with Apple">
            <svg viewBox="0 0 24 24" fill="#FFFFFF">
              <path d="M18.71 19.5c-.83 1.24-1.71 2.45-3.05 2.47-1.34.03-1.77-.79-3.29-.79-1.53 0-2 .77-3.27.82-1.31.05-2.3-1.32-3.14-2.53C4.25 17 2.94 12.45 4.7 9.39c.87-1.52 2.43-2.48 4.12-2.51 1.28-.02 2.5.87 3.29.87.78 0 2.26-1.07 3.81-.91.65.03 2.47.26 3.64 1.98-.09.06-2.17 1.28-2.15 3.81.03 3.02 2.65 4.03 2.68 4.04-.03.07-.42 1.44-1.38 2.83M15.97 6.37c.68-.82 1.13-1.97.98-3.12-1 .04-2.19.67-2.88 1.48-.62.72-1.16 1.89-.99 3.01 1.12.09 2.22-.55 2.89-1.37z"/>
            </svg>
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
    const passwordInput = document.getElementById('password');
    const togglePasswordBtn = document.getElementById('togglePassword');
    const eyeIcon = document.getElementById('eyeIcon');

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
