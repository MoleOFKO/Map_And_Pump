<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HQ Authentication Portal</title>
    <link rel="stylesheet" href="../css/Style.css">
    <style>
        body {
            display: flex;
            justify-content: center;
            align-items: center;
        }
    </style>
</head>
<body>
    <div style="width: 100%; max-width: 400px;">
        <div class="card">
            <h2 class="title">HQ Portal Login</h2>
            <p class="subtitle">Please enter your workspace credentials.</p>
            
            <form onsubmit="handleLogin(event)">
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" id="email" placeholder="hq@company.com" required>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" placeholder="••••••••" required>
                </div>
                <button type="submit" class="btn" style="width: 100%;">Sign In</button>
            </form>
            
            <p style="text-align: center; margin-top: 20px; font-size: 0.9rem; color: var(--text-muted);">
                New Account? <a href="../register/register.php" style="color: var(--primary);">Register Now</a>
            </p>
        </div>
    </div>

    <script>
        function handleLogin(e) {
            e.preventDefault();
            const registered = localStorage.getItem('hq_registered') === 'true';
            
            if (!registered) {
                alert("No active registration found. Directing to Registration Flow.");
                window.location.href = '../register/register.php';
                return;
            }

            const firstTime = localStorage.getItem('first_time_login') === 'true';
            if (firstTime) {
                window.location.href = '../change_password/change_password.php';
            } else {
                window.location.href = '../dashboard/index.php';
            }
        }
    </script>
</body>
</html>