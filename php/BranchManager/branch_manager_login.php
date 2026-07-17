<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MAP & PUMP — Branch Manager Login</title>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<!-- Browser Tab Logo (Favicon) -->
<link rel="icon" type="image/png" href="../images/Logo.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2 family=Space+Grotesk:wght@400;500;600;700&family=Noto+Sans+Myanmar:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
  :root{
    --navy-950:#070b14; --navy-900:#0b1220; --navy-800:#101a30; --navy-700:#16213f;
    --blue-700:#1d4ed8; --blue-500:#2f6feb; --sky-400:#38bdf8;
    --amber-400:#fbbf24; --amber-300:#fcd34d; --mint-400:#34d399;
    --red-400:#f87171;
    --slate-50:#f8fafc; --slate-300:#c9d2e3; --slate-400:#8b96ad;
  }
  *{box-sizing:border-box; margin:0; padding:0;}
  body{
    background:var(--navy-950); color:var(--slate-50);
    font-family:'Inter','Space Grotesk',sans-serif;
    min-height:100vh; display:flex; align-items:center; justify-content:center;
    overflow-x:hidden; position:relative; padding:24px;
  }
  .disp{font-family:'Space Grotesk','Noto Sans Myanmar',sans-serif;}
  a{color:inherit; text-decoration: none; transition: color 0.2s;}
  @media (prefers-reduced-motion: reduce){ *{animation-duration:0.001ms !important; animation-iteration-count:1 !important; transition: none !important;} }

  .bg-glow{
    position:fixed; inset:0; z-index:0; pointer-events:none;
    background:
      radial-gradient(700px 500px at 85% 0%, rgba(56,189,248,0.16), transparent 60%),
      radial-gradient(900px 600px at -10% 100%, rgba(29,78,216,0.20), transparent 60%);
  }

  /* ambient fuel-gauge ring, signature element */
  .gauge-ring{
    position:fixed; width:640px; height:640px; border-radius:50%;
    border:1px solid rgba(56,189,248,0.08); top:50%; left:50%;
    transform:translate(-50%,-50%); pointer-events:none;
  }
  .gauge-ring.r2{width:840px; height:840px; border-color:rgba(251,191,36,0.06);}
  .gauge-needle{
    position:fixed; top:50%; left:50%; width:2px; height:320px;
    background:linear-gradient(to top, transparent, rgba(56,189,248,0.35), transparent);
    transform-origin:top center; transform:translate(-50%,0) rotate(38deg);
    animation:sweep 9s ease-in-out infinite; pointer-events:none;
  }
  @keyframes sweep{0%,100%{transform:translate(-50%,0) rotate(20deg);} 50%{transform:translate(-50%,0) rotate(56deg);}}

  /* Entrance Transitions */
  .wrap{
    position:relative; z-index:1; width:100%; max-width:404px;
    opacity: 0;
    transform: translateY(15px);
    animation: fadeUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
  }
  @keyframes fadeUp {
    to { opacity: 1; transform: translateY(0); }
  }

  .brand{display:flex; align-items:center; justify-content:center; gap:10px; font-weight:700; font-size:20px; margin-bottom:34px;}
  .brand-mark{width:34px; height:34px; transition: transform 0.3s ease;}
  .brand:hover .brand-mark { transform: rotate(-5deg) scale(1.05); }

  .card{
    background:linear-gradient(160deg, rgba(16,26,48,0.92), rgba(11,18,32,0.92));
    border:1px solid rgba(255,255,255,0.08); border-radius:22px;
    padding:36px 32px 30px;
    box-shadow:0 40px 80px -30px rgba(0,0,0,0.7);
    backdrop-filter:blur(18px);
    transition: border-color 0.3s;
  }
  .card:hover {
    border-color: rgba(56,189,248,0.15);
  }
  .eyebrow{
    display:inline-flex; align-items:center; gap:8px; font-size:12px; letter-spacing:0.6px;
    color:var(--amber-300); background:rgba(251,191,36,0.1); border:1px solid rgba(251,191,36,0.28);
    padding:5px 11px; border-radius:999px; margin-bottom:16px;
  }
  .eyebrow .dot{
    width:6px; height:6px; border-radius:50%; background:var(--mint-400); 
    box-shadow:0 0 0 3px rgba(52,211,153,0.25);
    animation: pulse 2s infinite;
  }
  @keyframes pulse {
    0% { box-shadow: 0 0 0 0px rgba(52,211,153,0.4); }
    70% { box-shadow: 0 0 0 6px rgba(52,211,153,0); }
    100% { box-shadow: 0 0 0 0px rgba(52,211,153,0); }
  }

  /* Updated title styled with a modern glowing gradient */
  .card h1{
    font-size:23px; 
    font-weight:700; 
    margin-bottom:6px;
    background: linear-gradient(135deg, var(--slate-50) 30%, var(--sky-400) 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
  }
  .card .sub{font-size:13.5px; color:var(--slate-400); margin-bottom:28px; line-height:1.6;}

  .field{margin-bottom:18px;}
  .field label{display:block; font-size:12.5px; color:var(--slate-300); margin-bottom:7px; font-weight:600;}
  .field .input-wrap{position:relative;}
  .field .input-wrap span.ic{
    position:absolute; left:14px; top:50%; transform:translateY(-50%); 
    font-size:15px; color:var(--slate-400);
    transition: color 0.2s, transform 0.2s;
  }
  .field input{
    width:100%; background:var(--navy-950); border:1px solid rgba(255,255,255,0.1);
    border-radius:11px; padding:13px 40px 13px 40px; color:var(--slate-50); font-size:14.5px;
    font-family:inherit; outline:none; transition:border-color .25s, box-shadow .25s, background-color .25s;
  }
  .field input::placeholder{color:var(--slate-400); transition: opacity 0.2s;}
  .field input:focus::placeholder{opacity: 0.5;}
  .field input:focus{
    border-color:var(--sky-400); 
    box-shadow:0 0 0 3px rgba(56,189,248,0.15);
    background: rgba(11, 18, 32, 0.6);
  }
  
  /* Icon active states */
  .field input:focus ~ span.ic {
    color: var(--sky-400);
  }

  /* Password Toggle Interactive Button */
  .pass-toggle {
    position: absolute; right: 14px; top: 50%; transform: translateY(-50%);
    font-size: 14px; color: var(--slate-400); cursor: pointer; border: none;
    background: none; padding: 0; transition: color 0.2s; z-index: 2;
  }
  .pass-toggle:hover { color: var(--sky-400); }

  .row-between{display:flex; align-items:center; justify-content:space-between; margin:2px 0 24px; font-size:12.5px;}
  
  /* Styled Checkbox Interactive States */
  .remember{
    display:inline-flex; align-items:center; gap:8px; color:var(--slate-300); 
    cursor:pointer; user-select:none; transition: color 0.2s;
  }
  .remember:hover { color: var(--slate-50); }
  .remember input{
    accent-color:var(--sky-400); cursor: pointer;
    width: 15px; height: 15px; margin: 0;
  }
  
  .forgot{color:var(--sky-400); font-weight:600;}
  .forgot:hover{color: var(--sky-300); text-decoration:underline;}

  .btn-login{
    width:100%; background:linear-gradient(135deg, var(--sky-400), var(--blue-700));
    color:#fff; padding:14px; border-radius:12px; font-weight:700; font-size:15px;
    border:none; cursor:pointer; box-shadow:0 14px 30px -12px rgba(29,78,216,0.8);
    display:flex; align-items:center; justify-content:center; gap:8px;
    font-family:inherit;
    transition: transform 0.2s, filter 0.2s, box-shadow 0.2s;
  }
  .btn-login:hover{
    filter:brightness(1.08); 
    transform: translateY(-1px);
    box-shadow:0 16px 35px -10px rgba(29,78,216,0.9);
  }
  .btn-login:active{
    transform: translateY(1px);
    filter: brightness(0.95);
  }
  .btn-login i {
    transition: transform 0.2s;
  }
  .btn-login:hover i {
    transform: translateX(3px);
  }

  .divider-line{display:flex; align-items:center; gap:12px; margin:24px 0 18px; font-size:11.5px; color:var(--slate-400);}
  .divider-line::before, .divider-line::after{content:""; flex:1; height:1px; background:rgba(255,255,255,0.08);}

  .helpline{
    display:flex; align-items:center; gap:10px; background:var(--navy-800);
    border:1px solid rgba(255,255,255,0.06); border-radius:12px; padding:12px 14px; font-size:12.5px; color:var(--slate-300);
    transition: border-color 0.2s;
  }
  .helpline:hover { border-color: rgba(255, 255, 255, 0.12); }
  .helpline i{font-size:16px; color:var(--sky-400);}
  .helpline b{color:var(--slate-50);}

  .foot-note{text-align:center; margin-top:22px; font-size:12px; color:var(--slate-400);}
  .foot-note a{color:var(--sky-400); font-weight:600;}
  .foot-note a:hover{color:var(--sky-300);}
  .foot-note a i { transition: transform 0.2s; display: inline-block; }
  .foot-note a:hover i { transform: translateX(-3px); }
</style>
</head>
<body>

<div class="bg-glow"></div>
<div class="gauge-ring"></div>
<div class="gauge-ring r2"></div>
<div class="gauge-needle"></div>

<div class="wrap">
  <div class="brand">
    <svg class="brand-mark" viewBox="0 0 100 120" fill="none">
      <defs>
        <linearGradient id="g1" x1="0" y1="0" x2="100" y2="120">
          <stop offset="0" stop-color="#38bdf8"/>
          <stop offset="1" stop-color="#1d4ed8"/>
        </linearGradient>
      </defs>
      <path d="M50 4 L92 18 L92 54 C92 82 74 100 50 116 C26 100 8 82 8 54 L8 18 Z" fill="url(#g1)"/>
      <circle cx="48" cy="52" r="24" stroke="white" stroke-width="5" fill="none"/>
      <path d="M36 52 L45 62 L64 40" stroke="white" stroke-width="6" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
    </svg>
    MAP & PUMP
  </div>

  <div class="card">
    <div class="eyebrow"><span class="dot"></span> Branch Manager Portal</div>
    <h1>Branch Manager Login</h1>
    <p class="sub">Sign in with your Branch ID and password to access the dashboard.</p>

    <form id="loginForm">
      <div class="field">
        <label for="branchId">Branch ID</label>
        <div class="input-wrap">
          <span class="ic"><i class="fa-solid fa-tag"></i></span>
          <input type="text" id="branchId" placeholder="e.g. YGN-014" required autocomplete="username">
        </div>
      </div>
      <div class="field">
        <label for="password">Password</label>
        <div class="input-wrap">
          <span class="ic"><i class="fa-solid fa-lock"></i></span>
          <input type="password" id="password" placeholder="••••••••" required autocomplete="current-password">
          <button type="button" class="pass-toggle" id="togglePassword" aria-label="Toggle password visibility">
            <i class="fa-solid fa-eye"></i>
          </button>
        </div>
      </div>

      <div class="row-between">
        <label class="remember"><input type="checkbox" id="rememberMe"> Remember this device</label>
        <a href="#" class="forgot" onclick="alert('Please contact HQ administrator to reset your credentials.'); return false;">Forgot password?</a>
      </div>

      <label class="remember" style="margin-bottom:20px; display:flex;">
        <input type="checkbox" id="firstTime"> 
        <span>This is my first time logging in with this Branch ID</span>
      </label>

      <button type="submit" class="btn-login" id="submitBtn">
        <span id="btnText">Sign in to Dashboard</span> 
        <i class="fa-solid fa-arrow-right" id="btnIcon"></i>
      </button>

    </form>

    <div class="divider-line">or</div>

    <div class="helpline">
      <i class="fa-solid fa-life-ring"></i> <span>Trouble logging in? Contact HQ Support at <b>0912345678</b>.</span>
    </div>
  </div>

  <p class="foot-note">© 2026 MAP & PUMP · <a href="index.php"><i class="fa-solid fa-arrow-left"></i> Back to Landing Page</a></p>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  document.addEventListener('DOMContentLoaded', () => {
    const passwordInput = document.getElementById('password');
    const togglePasswordBtn = document.getElementById('togglePassword');
    const toggleIcon = togglePasswordBtn.querySelector('i');
    const firstTimeCheckbox = document.getElementById('firstTime');
    const submitBtn = document.getElementById('submitBtn');
    const btnText = document.getElementById('btnText');
    const btnIcon = document.getElementById('btnIcon');
    const loginForm = document.getElementById('loginForm');

    // 1. Password Visibility Toggle
    togglePasswordBtn.addEventListener('click', () => {
      const isPassword = passwordInput.type === 'password';
      passwordInput.type = isPassword ? 'text' : 'password';
      
      // Update eye icon state
      toggleIcon.className = isPassword ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
    });

    // 2. Dynamic Submit Button Text & Route updates
    firstTimeCheckbox.addEventListener('change', () => {
      if (firstTimeCheckbox.checked) {
        btnText.textContent = "Setup Secure Password";
        btnIcon.className = "fa-solid fa-key";
        submitBtn.style.background = "linear-gradient(135deg, var(--amber-400), var(--blue-700))";
      } else {
        btnText.textContent = "Sign in to Dashboard";
        btnIcon.className = "fa-solid fa-arrow-right";
        submitBtn.style.background = "linear-gradient(135deg, var(--sky-400), var(--blue-700))";
      }
    });

    // 3. Elegant Form Submit Handling
    loginForm.addEventListener('submit', (event) => {
      event.preventDefault();
      
      // Add visual loading state feedback
      submitBtn.disabled = true;
      btnText.textContent = "Authenticating...";
      btnIcon.className = "fa-solid fa-spinner fa-spin";
      
      setTimeout(() => {
        if (firstTimeCheckbox.checked) {
          window.location.href = 'change-password.php';
        } else {
          window.location.href = 'branch_manager_dashboard.php';
        }
      }, 750); // Small duration for visual immersion
    });
  });
</script>
</body>
</html>