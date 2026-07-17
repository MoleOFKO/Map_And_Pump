<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HQ Registration Portal</title>
    <link rel="stylesheet" href="../../css/HQstyle.css">
    <style>
        body {
            display: flex;
            justify-content: center;
            align-items: center;
        }
    </style>
</head>
<body>
    <div style="width: 100%; max-width: 500px;">
        <!-- Registration Form Card -->
        <div id="register-card" class="card">
            <h2 class="title">Register HQ Account</h2>
            <p class="subtitle">Enter your details to submit a registration request to our administration team.</p>
            
            <form id="registration-form" onsubmit="submitRegistration(event)">
                <div class="form-group">
                    <label>Account Owner's Name</label>
                    <input type="text" placeholder="John Doe" required style="margin-bottom: 8px;">
                    <label>Account Owner's Email</label>
                    <input type="email" placeholder="john@example.com" required>
                </div>
                
                <div class="form-group">
                    <label>Company Name</label>
                    <input type="text" placeholder="Global Energy Corp" required style="margin-bottom: 8px;">
                    <label>Company Logo</label>
                    <input type="file" accept="image/*">
                </div>
                
                <div class="form-group">
                    <label>Business License</label>
                    <input type="text" placeholder="LIC-12345678" required>
                </div>
                
                <div class="form-group">
                    <label>Company Address</label>
                    <input type="text" placeholder="456 Power Blvd, Houston, TX" required style="margin-bottom: 8px;">
                    <label>Contact Number</label>
                    <input type="text" placeholder="+1 (555) 019-2834" required style="margin-bottom: 8px;">
                </div>
                
                <button type="submit" class="btn" style="width: 100%;">Submit Request for Approval</button>
            </form>
            <p style="text-align: center; margin-top: 15px; font-size: 0.9rem; color: var(--text-muted);">
                Already registered? <a href="login.php" style="color: var(--primary);">Go to Login</a>
            </p>
        </div>

        <!-- Pending Approval / Simulation Card -->
        <div id="approval-card" class="card" style="display: none; text-align: center;">
            <h2 class="title" style="color: var(--primary);">Request Submitted!</h2>
            <p class="subtitle">Your registration is pending administrator evaluation.</p>
            
            <div style="background: var(--bg); padding: 20px; border-radius: 8px; border: 1px dashed var(--border); margin: 20px 0;">
                <p style="font-weight: 600; margin-bottom: 15px;">Simulate Admin Response (Sandbox Only):</p>
                <div style="display: flex; gap: 10px; justify-content: center;">
                    <button class="btn btn-success" onclick="simulateApproval(true)">Approve Request</button>
                    <button class="btn btn-danger" onclick="simulateApproval(false)">Reject Request</button>
                </div>
            </div>
            
            <div id="rejection-box" style="display: none; border: 1px solid var(--danger); background: #fef2f2; padding: 12px; border-radius: 6px; color: var(--danger); margin-top: 15px;">
                <strong>Rejected:</strong> Please check your email credentials, amend the details, and try registering again.
            </div>
        </div>
    </div>

    <script>
        function submitRegistration(e) {
            e.preventDefault();
            document.getElementById('register-card').style.display = 'none';
            document.getElementById('approval-card').style.display = 'block';
        }

        function simulateApproval(approved) {
            if (approved) {
                localStorage.setItem('hq_registered', 'true');
                localStorage.setItem('first_time_login', 'true'); // Triggers Change Password page upon first login
                alert("Account Approved! Redirecting to login to continue first-time configuration.");
                window.location.href = 'login.html';
            } else {
                document.getElementById('rejection-box').style.display = 'block';
                setTimeout(() => {
                    document.getElementById('rejection-box').style.display = 'none';
                    document.getElementById('approval-card').style.display = 'none';
                    document.getElementById('register-card').style.display = 'block';
                }, 4000);
            }
        }
    </script>
</body>
</html>