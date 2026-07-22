<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="/css/HQstyle.css">
</head>
<body>
    <h2 class="title">Account Management</h2>
    <p class="subtitle">Update your account credentials, settings, or switch between interfaces.</p>

    <!-- Main Container Layout: Two Separate Cards Side-by-Side -->
    <!-- <div class="container" style="max-width: 1200px; margin-top: 40px;"> -->
        <div class="row g-4 align-items-center justify-content-center">
            
            <!-- Left Side: Separate Edit Profile Card -->
            <div class="col-lg-8">
                <div class="card h-100 p-4 text-left">
                    <h3 class="mb-4">Edit Account Profile</h3>
                    <div class="row align-items-center">
                        <!-- Profile Picture Upload & Preview -->
                        <div class="col-md-4 text-center border-end-md mb-4 mb-md-0">
                            <img id="profilePreview" src="https://via.placeholder.com/150" alt="Profile Picture" class="rounded-circle img-thumbnail mb-3" style="width: 150px; height: 150px; object-fit: cover; background-color: #fff;">
                            <div>
                                <label for="profilePictureInput" class="btn btn-sm btn-outline-secondary w-75">Change Picture</label>
                                <input type="file" id="profilePictureInput" accept="image/*" style="display: none;" onchange="previewImage(event)">
                            </div>
                        </div>

                        <!-- Form Fields -->
                        <div class="col-md-8 ps-md-4">
                            <form onsubmit="saveProfile(event)">
                                <div class="mb-3">
                                    <label for="profileName" class="form-label">Full Name</label>
                                    <input type="text" class="form-control" id="profileName" value="John Doe" required>
                                </div>
                                <div class="mb-3">
                                    <label for="profileEmail" class="form-label">Email Address</label>
                                    <input type="email" class="form-control" id="profileEmail" value="johndoe@example.com" required>
                                </div>

                                <hr class="my-4">
                                <h5 class="mb-3">Change Password</h5>

                                <div class="mb-3">
                                    <label for="currentPassword" class="form-label">Current Password</label>
                                    <input type="password" class="form-control" id="currentPassword">
                                </div>
                                <div class="mb-3">
                                    <label for="newPassword" class="form-label">New Password</label>
                                    <input type="password" class="form-control" id="newPassword">
                                </div>
                                <div class="mb-3">
                                    <label for="confirmPassword" class="form-label">Confirm New Password</label>
                                    <input type="password" class="form-control" id="confirmPassword">
                                </div>

                                <button type="submit" class="btn btn-primary w-100">Save Changes</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Side: Separate Switch Account Card -->
            <div class="col-lg-4">
                <div class="card h-100 p-4 text-center">
                    <h3 class="mb-3">Account Interface Switcher</h3>
                    <p class="subtitle mb-4">Interlink your account proxy with downstream customer interface metrics.</p>

                    <div id="check-step">
                        <h5 class="text-secondary">Checking Handshake Status</h5>
                        <p class="subtitle small mt-2 mb-3">Analyzing profile links...</p>
                        <button class="btn btn-outline-primary w-100" onclick="checkHandshake()">Scan Account Integration</button>
                    </div>

                    <div id="unconnected-step" style="display: none;">
                        <h5 style="color: var(--danger);">No Linked Profiles</h5>
                        <p class="subtitle small mt-2 mb-3">You must verify a secure client integration handshake first.</p>
                        <button class="btn btn-warning w-100" onclick="connectProfile()">Connect Customer Profile</button>
                    </div>

                    <div id="connected-step" style="display: none;">
                        <h5 style="color: var(--success);">Integration Active!</h5>
                        <p class="subtitle small mt-2 mb-3">Proxy Key: <strong>CUST-PROX-9004</strong></p>
                        <button class="btn btn-success w-100" onclick="alert('Redirecting execution frame to client-facing system...');">Launch Client Workspace</button>
                    </div>
                </div>
            </div>

        </div>
    <!-- </div> -->

    <script>
        // Load saved profile data and picture on startup
        window.addEventListener('DOMContentLoaded', () => {
            const savedName = localStorage.getItem('profile_name');
            const savedEmail = localStorage.getItem('profile_email');
            const savedImage = localStorage.getItem('profile_image');

            if (savedName) document.getElementById('profileName').value = savedName;
            if (savedEmail) document.getElementById('profileEmail').value = savedEmail;
            if (savedImage) document.getElementById('profilePreview').src = savedImage;
        });

        function checkHandshake() {
            const connected = localStorage.getItem('cust_linked') === 'true';
            document.getElementById('check-step').style.display = 'none';
            if (connected) {
                document.getElementById('connected-step').style.display = 'block';
            } else {
                document.getElementById('unconnected-step').style.display = 'block';
            }
        }

        function connectProfile() {
            localStorage.setItem('cust_linked', 'true');
            alert("Linking accounts... Handshake parameters saved.");
            document.getElementById('unconnected-step').style.display = 'none';
            document.getElementById('connected-step').style.display = 'block';
        }

        function previewImage(event) {
            const file = event.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('profilePreview').src = e.target.result;
                    localStorage.setItem('profile_image', e.target.result);
                }
                reader.readAsDataURL(file);
            }
        }

        function saveProfile(event) {
            event.preventDefault();
            const name = document.getElementById('profileName').value;
            const email = document.getElementById('profileEmail').value;
            const currentPass = document.getElementById('currentPassword').value;
            const newPass = document.getElementById('newPassword').value;
            const confirmPass = document.getElementById('confirmPassword').value;

            if (currentPass || newPass || confirmPass) {
                if (!currentPass || !newPass || !confirmPass) {
                    alert("Please fill in all password fields to change your password.");
                    return;
                }
                if (newPass !== confirmPass) {
                    alert("New passwords do not match!");
                    return;
                }
                localStorage.setItem('profile_password', newPass);
            }
            
            localStorage.setItem('profile_name', name);
            localStorage.setItem('profile_email', email);
            
            alert("Profile and security settings updated successfully!");
        }
    </script>
</body>
<footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map & Pump | All Rights Reserved</footer>
</html>