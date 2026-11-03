<?php
session_start();
require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/staff_access_guard.php';

map_pump_require_active_staff_session($pdo, false);

if (empty($_SESSION['staff_verify_csrf_token'])) $_SESSION['staff_verify_csrf_token'] = bin2hex(random_bytes(32));
$staffVerifyCsrfToken = (string)$_SESSION['staff_verify_csrf_token'];
$verifyFlash = $_SESSION['verify_flash'] ?? null; unset($_SESSION['verify_flash']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Booking QR - Map & Pump</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="icon" type="image/png" href="/images/Logo.png">
    <link rel="stylesheet" href="../../css/Admin.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../../css/StaffDashboard.css">
    <style>
        .scanner-window {
            position: relative; width: 100%; max-width: 280px; height: 280px;
            background: #050b14; border-radius: 16px; overflow: hidden; border: 2px solid rgba(0, 244, 255, 0.15);
            box-shadow: inset 0 0 20px rgba(0,0,0,0.5), 0 0 15px rgba(0, 244, 255, 0.05);
        }
        #qr-reader { position: relative; z-index: 1; min-height: 100%; }
        #qr-reader video { object-fit: cover !important; border-radius: 14px; }
        .scanner-laser, .target-box, .scanner-status { pointer-events: none; z-index: 2; }
        .scanner-laser { position: absolute; width: 100%; height: 3px; background-color: var(--neon-cyan); box-shadow: 0 0 15px var(--neon-cyan); top: 0; animation: scanAnimation 2.5s infinite linear; }
        .target-box { position: absolute; top: 15%; left: 15%; right: 15%; bottom: 15%; border: 2px dashed rgba(0, 244, 255, 0.3); border-radius: 12px; display: flex; align-items: center; justify-content: center; transition: opacity .25s; }
        .scanner-window.is-live .target-box i { opacity: 0; }
        .scanner-status { position: absolute; bottom: 0; left: 0; right: 0; margin-bottom: 12px; z-index: 3; }
        .verify-result { display: none; }
        .verify-result.is-visible { display: block; }
        .verify-result .result-card { border-radius: 14px; padding: 1rem 1.1rem; text-align: left; }
        .verify-result.success .result-card { background: rgba(25, 135, 84, 0.14); border: 1px solid rgba(25, 135, 84, 0.35); }
        .verify-result.error .result-card { background: rgba(220, 53, 69, 0.12); border: 1px solid rgba(220, 53, 69, 0.35); }
        .verify-result .detail-row { display: flex; justify-content: space-between; gap: 1rem; font-size: 0.82rem; padding: 0.2rem 0; color: rgba(255,255,255,0.72); }
        .verify-result .detail-row strong { color: #fff; font-weight: 600; }
        @media (min-width: 400px) { .scanner-window { max-width: 320px; height: 320px; } }
        @keyframes scanAnimation { 0% { top: 0%; } 50% { top: 100%; } 100% { top: 0%; } }
    </style>
</head>
<body class="background_color staff-dashboard" id="background_color">
    <div class="page-load-bar"></div>
    <?php $staffPageTitle = 'Verify booking QR'; $staffActivePage = 'verify'; require __DIR__ . '/staff_layout_start.php'; ?>

    <!-- MAIN INTERFACE -->
    <div class="container my-5 text_color">
        <div class="row justify-content-center">
            <div class="col-12 col-sm-10 col-md-8 col-lg-6 px-3">
                <div class="mb-3 ms-1">
                    <a href="staff_dashboard.php" class="stat-chip text-decoration-none">
                        <i class="bi bi-arrow-left"></i> Back
                    </a>
                </div>
                
                <div class="dashboard-card text-center p-4 p-sm-5 mt-2">
                    <h2 class="fw-bold mb-2 text-white">Verify Booking QR</h2>
                    <p class="text-white-50 mb-4 small">Position the user's booking QR code within the frame to automatically scan it.</p>
                    
                    <div id="verifyResult" class="verify-result mb-4" aria-live="polite">
                        <div class="result-card">
                            <div class="d-flex align-items-start gap-2 mb-2">
                                <i id="verifyResultIcon" class="bi fs-5"></i>
                                <div>
                                    <div id="verifyResultTitle" class="fw-semibold text-white"></div>
                                    <div id="verifyResultMessage" class="small text-white-50"></div>
                                </div>
                            </div>
                            <div id="verifyResultDetails" class="mt-2"></div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-center mb-4">
                        <div id="scannerWindow" class="scanner-window d-flex align-items-center justify-content-center">
                            <div id="qr-reader" class="w-100 h-100"></div>
                            <div class="scanner-laser"></div>
                            <div class="target-box"><i class="bi bi-qr-code text-white-50" style="font-size: calc(3.5rem + 1vw);"></i></div>
                            <small class="scanner-status text-white-50 w-100 text-center px-2"><i class="bi bi-camera-video me-1"></i> <span id="scanStatus">Seeking stream...</span></small>
                        </div>
                    </div>
                    
                    <div class="d-flex align-items-center my-4">
                        <hr class="flex-grow-1 border-secondary border-opacity-50">
                        <span class="mx-3 text-white-50 small fw-bold" style="font-size: 0.75rem;">OR MANUALLY ENTER</span>
                        <hr class="flex-grow-1 border-secondary border-opacity-50">
                    </div>
                    
                    <form id="manualVerifyForm" action="verify_process.php" method="POST" class="text-start">
                        <input type="hidden" name="action" value="verify">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($staffVerifyCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="mb-4">
                            <label for="bookingCode" class="form-label text-white-50 small">Booking Reference Code</label>
                            <div class="input-group">
                                <span class="input-group-text glass-input text-white-50 border-end-0"><i class="bi bi-hash"></i></span>
                                <input type="text" class="form-control glass-input border-start-0 ps-0" id="bookingCode" name="booking_code" placeholder="e.g. MP-123-ABC" required autocomplete="off">
                            </div>
                        </div>
                        <button id="manualVerifyBtn" type="submit" class="btn btn-light w-100 py-2 fw-semibold rounded-pill shadow" style="color: #000;">
                            <i class="bi bi-check-circle-fill me-2"></i> Verify Code
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php require __DIR__ . '/staff_layout_end.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/js/html5-qrcode.min.js"></script>
    <script src="/js/Admin.js"></script>
    <script>
        document.addEventListener('pointermove', (event) => {
            document.body.style.setProperty('--bg-x', `${(event.clientX / window.innerWidth) * 100}%`);
            document.body.style.setProperty('--bg-y', `${(event.clientY / window.innerHeight) * 100}%`);
        });

        const verifyFlash = <?php echo json_encode($verifyFlash, JSON_UNESCAPED_UNICODE); ?>;
        let lastScannedCode = '';
        let scanCooldown = false;

        function normalizeBookingReference(value) {
            let text = String(value || '').trim();
            if (!text) return '';

            try {
                const parsed = JSON.parse(text);
                const nested = parsed.booking_code || parsed.qr_refrence_code || parsed.qr_reference_code || parsed.code;
                if (nested) return normalizeBookingReference(nested);
            } catch (error) {
                // The QR is usually plain text, not JSON.
            }

            try {
                const url = new URL(text);
                const nested = url.searchParams.get('booking_code') || url.searchParams.get('qr_refrence_code') || url.searchParams.get('qr_reference_code') || url.searchParams.get('code') || url.searchParams.get('qr');
                if (nested) return normalizeBookingReference(nested);
            } catch (error) {
                // Not a URL payload.
            }

            if (text.includes('|')) text = text.split('|')[0];
            return text.replace(/\s+/g, '').toUpperCase();
        }

        function renderVerifyResult(result) {
            const panel = document.getElementById('verifyResult');
            const icon = document.getElementById('verifyResultIcon');
            const title = document.getElementById('verifyResultTitle');
            const message = document.getElementById('verifyResultMessage');
            const details = document.getElementById('verifyResultDetails');
            if (!panel) return;

            panel.classList.remove('success', 'error', 'is-visible');
            details.innerHTML = '';

            if (!result) return;

            const booking = result.booking;
            const isFilled = result.success && booking
                && ['filled', 'completed'].includes(String(booking.status || '').toLowerCase());
            panel.classList.add('is-visible', result.success ? 'success' : 'error');
            icon.className = `bi fs-5 ${result.success ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger'}`;
            title.textContent = isFilled ? 'Successfully filled' : result.success ? 'Booking verified' : 'Verification failed';
            message.textContent = isFilled ? 'Fuel fill completed successfully.' : (result.message || '');

            if (booking && !isFilled) {
                const rows = [
                    ['Code', booking.qr_refrence_code?.split('|')[0] || booking.qr_refrence_code || '—'],
                    ['Station', booking.station_name || `Station #${booking.station_id}`],
                    ['Fuel', booking.oil_name || '—'],
                    ['Status', booking.status || '—'],
                ];
                details.innerHTML = rows.map(([label, value]) => (
                    `<div class="detail-row"><span>${label}</span><strong>${value}</strong></div>`
                )).join('');
            }
        }

        async function submitVerification(payload) {
            const response = await fetch('verify_process.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: payload
            });
            const result = await response.json().catch(() => ({ success: false, message: 'Unexpected server response.' }));
            const isFilled = result.booking
                && ['filled', 'completed'].includes(String(result.booking.status || '').toLowerCase());
            if (result.success && result.booking && result.booking.booking_id && !isFilled) {
                window.location.href = `verify_detail.php?booking_id=${encodeURIComponent(result.booking.booking_id)}`;
                return result;
            }
            renderVerifyResult(result);
            document.getElementById('scanStatus').textContent = isFilled ? 'Fill complete' : result.success ? 'Checked in' : (result.message || 'Invalid booking');
            return result;
        }

        document.getElementById('manualVerifyForm')?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const button = document.getElementById('manualVerifyBtn');
            const codeField = document.getElementById('bookingCode');
            const payload = new URLSearchParams();
            payload.append('action', 'verify');
            payload.append('booking_code', codeField.value.trim());
            payload.append('csrf_token', <?= json_encode($staffVerifyCsrfToken) ?>);
            button.disabled = true;
            try {
                await submitVerification(payload);
            } catch (error) {
                renderVerifyResult({ success: false, message: 'Unable to reach the verification service.' });
            } finally {
                button.disabled = false;
            }
        });

        document.addEventListener('DOMContentLoaded', async () => {
            if (verifyFlash) {
                renderVerifyResult(verifyFlash);
            }

            const scannerArea = document.getElementById('qr-reader');
            const scannerWindow = document.getElementById('scannerWindow');
            if (!scannerArea || !window.Html5Qrcode) {
                document.getElementById('scanStatus').textContent = 'Scanner unavailable';
                return;
            }

            try {
                const cameras = await Html5Qrcode.getCameras();
                if (!cameras || !cameras.length) {
                    document.getElementById('scanStatus').textContent = 'No camera found';
                    return;
                }

                const rearCamera = cameras.find((camera) => /back|rear|environment/i.test(camera.label));
                const cameraList = rearCamera ? [rearCamera, ...cameras.filter((camera) => camera.id !== rearCamera.id)] : cameras;
                const scanner = new Html5Qrcode('qr-reader');
                window.qrScanner = scanner;

                const startScanner = async (cameraId) => {
                    await scanner.start(
                        cameraId,
                        { fps: 10, qrbox: { width: 230, height: 230 }, aspectRatio: 1.0 },
                        async (decodedText) => {
                            if (scanCooldown) return;

                            const raw = decodedText.trim();
                            const bookingCode = normalizeBookingReference(raw);
                            if (!bookingCode || bookingCode === lastScannedCode) return;

                            scanCooldown = true;
                            lastScannedCode = bookingCode;

                            const codeField = document.getElementById('bookingCode');
                            if (codeField) codeField.value = bookingCode;

                            const payload = new URLSearchParams();
                            payload.append('action', 'verify');
                            payload.append('booking_code', bookingCode);
                            payload.append('qr_payload', raw);
                            payload.append('csrf_token', <?= json_encode($staffVerifyCsrfToken) ?>);

                            try {
                                try {
                                    await scanner.pause(true);
                                } catch (pauseError) {
                                    console.warn('QR scanner pause failed; continuing verification.', pauseError);
                                }
                                await submitVerification(payload);
                            } catch (error) {
                                console.error('QR verification request failed.', error);
                                renderVerifyResult({ success: false, message: error?.message || 'Scan succeeded but verification failed.' });
                            }

                            setTimeout(async () => {
                                scanCooldown = false;
                                try {
                                    await scanner.resume();
                                } catch (error) {
                                    // Scanner may already be running again.
                                }
                            }, 2500);
                        },
                        () => {}
                    );
                };

                let started = false;
                let lastError = null;
                for (const camera of cameraList) {
                    try {
                        await startScanner(camera.id);
                        started = true;
                        break;
                    } catch (error) {
                        lastError = error;
                        console.warn('QR scanner failed to start for camera:', camera.label, error);
                    }
                }

                if (!started) {
                    document.getElementById('scanStatus').textContent = `Camera unavailable${lastError?.message ? ': ' + lastError.message : ''}`;
                    return;
                }

                scannerWindow?.classList.add('is-live');
                document.getElementById('scanStatus').textContent = 'Camera online — scan QR';
            } catch (error) {
                document.getElementById('scanStatus').textContent = `Camera unavailable${error?.message ? ': ' + error.message : ''}`;
            }
        });
    </script>
</body>
</html>
