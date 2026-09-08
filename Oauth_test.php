<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Pure JS OAuth Test</title>
  
  <!-- Official Google OAuth Library -->
  <script src="https://accounts.google.com/gsi/client" async defer></script>

  <style>
    /* Dark Theme Setup */
    body {
      background-color: #0b0f17;
      color: #ffffff;
      font-family: system-ui, -apple-system, sans-serif;
      display: flex;
      justify-content: center;
      align-items: center;
      min-height: 100vh;
      margin: 0;
    }

    .card {
      background: rgba(15, 23, 42, 0.85);
      border: 1px solid rgba(255, 255, 255, 0.12);
      border-radius: 16px;
      padding: 32px;
      width: 100%;
      max-width: 360px;
      text-align: center;
      box-shadow: 0 20px 40px rgba(0, 0, 0, 0.5);
    }

    h2 { margin-top: 0; color: #38bdf8; }

    /* Custom Google Button */
    .google-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      width: 100%;
      padding: 12px;
      background: #0f172a;
      border: 1px solid rgba(56, 189, 248, 0.3);
      border-radius: 10px;
      color: #ffffff;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.15s ease;
    }

    .google-btn:hover {
      border-color: #38bdf8;
      box-shadow: 0 0 12px rgba(56, 189, 248, 0.25);
      transform: translateY(-2px);
    }

    .google-btn:active {
      transform: translateY(2px);
    }

    .google-btn svg { width: 20px; height: 20px; }

    /* User Profile Display (Hidden initially) */
    .profile-box {
      display: none; /* Controlled by JS */
    }

    .avatar {
      width: 80px;
      height: 80px;
      border-radius: 50%;
      border: 2px solid #38bdf8;
      margin-bottom: 12px;
    }

    .logout-btn {
      margin-top: 20px;
      width: 100%;
      padding: 10px;
      background: #ef4444;
      border: none;
      border-radius: 8px;
      color: white;
      font-weight: bold;
      cursor: pointer;
      transition: background 0.15s ease;
    }

    .logout-btn:hover { background: #dc2626; }
    .logout-btn:active { transform: translateY(1px); }
  </style>
</head>
<body>

  <div class="card">
    
    <!-- STATE 1: LOGIN VIEW -->
    <div id="login-view">
      <h2>System Login</h2>
      <p style="color: #94a3b8; font-size: 0.9rem; margin-bottom: 24px;">Test Google OAuth in browser memory</p>
      
      <button type="button" class="google-btn" id="google-login-btn">
        <svg viewBox="0 0 24 24">
          <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
          <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
          <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
          <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
        </svg>
        Sign in with Google
      </button>
    </div>

    <!-- STATE 2: LOGGED-IN VIEW -->
    <div id="profile-view" class="profile-box">
      <img id="user-avatar" class="avatar" src="" alt="User Avatar" />
      <h3 id="user-name" style="margin: 4px 0;"></h3>
      <p id="user-email" style="color: #94a3b8; font-size: 0.85rem; margin: 0;"></p>
      
      <button type="button" class="logout-btn" id="logout-btn">Log Out</button>
    </div>

  </div>

  <script>
    // 1. Replace this with your Google Client ID
    const CLIENT_ID = "513039511125-mlrd4m0lqn87radhimv0ms0vhtpvm9mh.apps.googleusercontent.com";

    let tokenClient;

    // Elements
    const loginView = document.getElementById('login-view');
    const profileView = document.getElementById('profile-view');
    const userName = document.getElementById('user-name');
    const userEmail = document.getElementById('user-email');
    const userAvatar = document.getElementById('user-avatar');

    // 2. Function to Update UI based on Browser Storage
    function checkAuthState() {
      const storedUser = localStorage.getItem('oauth_demo_user');

      if (storedUser) {
        // User is logged in -> Show Profile View
        const user = JSON.parse(storedUser);
        userName.textContent = user.name;
        userEmail.textContent = user.email;
        userAvatar.src = user.picture;

        loginView.style.display = 'none';
        profileView.style.display = 'block';
      } else {
        // User is logged out -> Show Login View
        loginView.style.display = 'block';
        profileView.style.display = 'none';
      }
    }

    // 3. Initialize Google OAuth Client on Page Load
    window.onload = function() {
      checkAuthState(); // Check if already logged in from previous session

      tokenClient = google.accounts.oauth2.initTokenClient({
        client_id: CLIENT_ID,
        scope: 'https://www.googleapis.com/auth/userinfo.profile https://www.googleapis.com/auth/userinfo.email',
        callback: (response) => {
          if (response.access_token) {
            // Fetch User Details directly from Google API using JavaScript
            fetch('https://www.googleapis.com/oauth2/v3/userinfo', {
              headers: { Authorization: `Bearer ${response.access_token}` }
            })
            .then(res => res.json())
            .then(user => {
              // Save profile to browser memory (localStorage)
              localStorage.setItem('oauth_demo_user', JSON.stringify(user));
              
              // Refresh UI
              checkAuthState();
            });
          }
        },
      });
    };

    // 4. Trigger Login Popup on Button Click
    document.getElementById('google-login-btn').addEventListener('click', () => {
      if (tokenClient) {
        // Forces Google to show account selection every time for easy testing
        tokenClient.requestAccessToken({ prompt: 'select_account' });
      }
    });

    // 5. Trigger Logout on Button Click
    document.getElementById('logout-btn').addEventListener('click', () => {
      // Clear user data from browser memory
      localStorage.removeItem('oauth_demo_user');
      
      // Reset UI back to login screen
      checkAuthState();
    });
  </script>

</body>
</html>