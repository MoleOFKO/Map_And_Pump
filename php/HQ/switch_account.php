<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="/css/HQstyle.css">
</head>
<body>
    <h2 class="title">Account Interface Switcher</h2>
    <p class="subtitle">Interlink your account proxy with downstream customer interface metrics.</p>

    <div class="card" style="max-width: 500px; text-align: center; margin: 40px auto 0 auto;">
        <div id="check-step">
            <h3>Checking Customer Handshake Status</h3>
            <p class="subtitle" style="margin-top: 8px;">Analyzing profile links...</p>
            <button class="btn" onclick="checkHandshake()">Scan Account Integration</button>
        </div>

        <div id="unconnected-step" style="display: none;">
            <h3 style="color: var(--danger);">No Linked Customer Profiles Detected</h3>
            <p class="subtitle" style="margin-top: 8px;">You must verify a secure client integration handshake first.</p>
            <button class="btn" onclick="connectProfile()">Connect Customer Profile</button>
        </div>

        <div id="connected-step" style="display: none;">
            <h3 style="color: var(--success);">Customer Account Integration Active!</h3>
            <p class="subtitle" style="margin-top: 8px;">Active Customer Proxy Key: <strong>CUST-PROX-9004</strong></p>
            <button class="btn btn-success" onclick="alert('Redirecting execution frame to client-facing system...');">Launch Client Workspace</button>
        </div>
    </div>

    <script>
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
    </script>
</body>
<footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map & Pump | All Rights Reserved</footer>
</html>