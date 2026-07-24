<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set New Password</title>
    <link rel="stylesheet" href="/css/HQstyle.css">
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
            <h2 class="title">First-Time Password Change</h2>
            <p class="subtitle">To finalize activation, create a secure personalized password.</p>
            
            <form onsubmit="updatePassword(event)">
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" placeholder="Min. 8 characters" required id="p1">
                </div>
                <div class="form-group">
                    <label>Confirm Password</label>
                    <input type="password" placeholder="Confirm" required id="p2">
                </div>
                <button type="submit" class="btn" style="width: 100%;">Save Password & Continue</button>
            </form>
        </div>
    </div>

    <script>
        function updatePassword(e) {
            e.preventDefault();
            const p1 = document.getElementById('p1').value;
            const p2 = document.getElementById('p2').value;

            if (p1 !== p2) {
                alert("Passwords do not match!");
                return;
            }

            localStorage.setItem('first_time_login', 'false');
            alert("Password updated! Initializing HQ Workspace.");
            window.location.href = 'index.html';
        }
    </script>
</body>
<footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map & Pump | All Rights Reserved</footer>
</html>