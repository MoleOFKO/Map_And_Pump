<?php include 'share_header.php'; ?>

<!-- Leaflet map library (no API key required, uses OpenStreetMap tiles) -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

<div class="dashboard-panel-card mb-4">
    <div class="d-flex align-items-center justify-content-between pb-4 mb-4 border-bottom border-secondary border-opacity-10 flex-wrap gap-3">
        <div>
            <h3 class="text-white mb-1">Find & Book a Station</h3>
            <p class="text-secondary mb-0" style="font-size:13px;">Locate live station nodes on the map, tap a marker to view available fuel stock, then book your token.</p>
        </div>
        <button id="locate-btn" onclick="initStationMap()" class="btn btn-info px-4 py-3 fw-bold rounded-4" style="font-size:13px; font-family:'Space Grotesk';">
            <i class="bi bi-geo-alt-fill me-2"></i> Locate Stations on Map
        </button>
    </div>

    <div id="map-placeholder" class="text-center py-5">
        <i class="bi bi-map fs-1 text-secondary mb-3 d-block"></i>
        <p class="text-secondary mb-0" style="font-size:13px;">Map preview will appear here once you locate stations.</p>
    </div>

    <div id="map-wrapper" class="d-none">
        <div id="station-map" style="height:440px; border-radius:16px; overflow:hidden; border:1px solid rgba(255,255,255,0.08);"></div>
        <p class="text-secondary mt-3 mb-0" style="font-size:11px;"><i class="bi bi-info-circle me-1"></i> Tap a marker to view station details and book a token.</p>
    </div>
</div>

<!-- QR Booking Card: hidden until a booking is confirmed -->
<div id="qr-booking-card" class="dashboard-panel-card d-none mb-4">
    <div class="text-center p-4 mx-auto" style="max-width: 380px;">
        <div class="bg-success bg-opacity-10 text-success rounded-circle d-flex align-items-center justify-content-center mx-auto mb-3" style="width: 60px; height: 60px;">
            <i class="bi bi-qr-code-scan fs-3"></i>
        </div>
        <h4 class="text-white mb-1">Distribution Token Validated</h4>
        <p class="text-secondary mb-4" style="font-size:13px;">Scan this verified booking pass at your selected location's scanning terminals.</p>

        <div class="p-4 bg-black bg-opacity-30 border border-secondary border-opacity-10 rounded-4 mx-auto mb-4" style="max-width: 260px;">
            <img id="qr-image" src="" alt="Booking QR Code" class="rounded-3 mb-3 d-block mx-auto" style="width:180px; height:180px; background:#fff; padding:8px;">
            <div id="qr-token-code" class="token-no text-info bg-dark bg-opacity-50 p-3 rounded-3 border border-secondary border-opacity-10 mb-2 select-all" style="font-size: 20px; letter-spacing: 0.1em; font-weight: 700;">
                MP-000-0000
            </div>
            <span class="status-pill active" style="font-size:9px;">Active Queue Token</span>
        </div>

        <div class="text-start mx-auto mb-4" style="max-width: 300px; font-size:13px;">
            <div class="d-flex justify-content-between border-bottom border-secondary border-opacity-10 py-2">
                <span class="text-secondary">Station</span>
                <span class="text-white fw-semibold" id="qr-station-name">-</span>
            </div>
            <div class="d-flex justify-content-between border-bottom border-secondary border-opacity-10 py-2">
                <span class="text-secondary">Fuel Type</span>
                <span class="text-white fw-semibold" id="qr-fuel-type">-</span>
            </div>
            <div class="d-flex justify-content-between py-2">
                <span class="text-secondary">Volume</span>
                <span class="text-white fw-semibold" id="qr-volume">-</span>
            </div>
        </div>

        <button onclick="cancelQrBooking()" class="btn btn-outline-danger px-4 py-3 fw-bold rounded-4" style="font-size:13px; font-family:'Space Grotesk';">Cancel Reservation</button>
    </div>
</div>

<!-- Booking Modal (opened from a station's map popup) -->
<div class="modal fade" id="bookingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border border-secondary border-opacity-25 rounded-4" style="background: var(--navy-900);">
            <div class="modal-header border-bottom border-secondary border-opacity-10">
                <h5 class="modal-title text-white" id="modal-station-name">Station Name</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-secondary mb-4" style="font-size:12px;"><i class="bi bi-geo-alt-fill text-danger me-1"></i><span id="modal-station-address"></span></p>

                <label class="form-label text-secondary" style="font-size:11px; font-weight:600;">FUEL PRODUCT PROFILE</label>
                <select id="modal-fuel-select" class="form-select custom-form-field mb-3"></select>

                <label class="form-label text-secondary" style="font-size:11px; font-weight:600;">ALLOCATION QUANTITY (LITERS)</label>
                <input type="number" id="modal-volume-input" value="20" class="form-control custom-form-field mb-2">
                <p class="text-secondary mb-0" style="font-size:11px;">Current allowance limit balance: <strong class="text-sky-400" id="modal-quota-display">0 L</strong></p>
            </div>
            <div class="modal-footer border-top border-secondary border-opacity-10">
                <button type="button" class="btn btn-outline-secondary rounded-4" data-bs-dismiss="modal">Cancel</button>
                <button type="button" onclick="confirmBooking()" class="btn btn-info fw-bold rounded-4" style="font-family:'Space Grotesk';">Confirm Booking</button>
            </div>
        </div>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    let mapInstance = null;
    let selectedStation = null;
    const availableOilQuota = <?php echo ($_SESSION['oil_limit'] - $_SESSION['oil_used']); ?>;

    // Station directory: swap this for a real database query later
    const stationsData = [
        {
            id: 1,
            name: "Yangon Terminal Center",
            address: "Pyay Road, Mayangone Township",
            lat: 16.8661,
            lng: 96.1349,
            fuels: [
                { label: "Octane 95", cssClass: "fuel-95" },
                { label: "Diesel", cssClass: "fuel-diesel" }
            ]
        },
        {
            id: 2,
            name: "Insein Branch Station 04",
            address: "Baho Road, Insein Township",
            lat: 16.8961,
            lng: 96.0891,
            fuels: [
                { label: "Octane 92", cssClass: "fuel-92" }
            ]
        }
    ];

    function initStationMap() {
        document.getElementById('map-placeholder').classList.add('d-none');
        document.getElementById('map-wrapper').classList.remove('d-none');

        if (mapInstance) {
            mapInstance.invalidateSize();
            return;
        }

        mapInstance = L.map('station-map', { scrollWheelZoom: false }).setView([16.878, 96.112], 12);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors',
            maxZoom: 18
        }).addTo(mapInstance);

        stationsData.forEach(station => {
            const fuelTagsHtml = station.fuels
                .map(f => `<span class="fuel-tag ${f.cssClass}" style="margin-right:4px;">${f.label}</span>`)
                .join('');

            const popupHtml = `
                <div style="min-width:210px; font-family:'Inter',sans-serif;">
                    <h6 style="color:#0b1220; margin-bottom:4px; font-weight:700;">${station.name}</h6>
                    <p style="font-size:12px; color:#555; margin-bottom:8px;">
                        <i class="bi bi-geo-alt-fill" style="color:#dc3545;"></i> ${station.address}
                    </p>
                    <div style="margin-bottom:10px;">${fuelTagsHtml}</div>
                    <button class="btn btn-info btn-sm w-100 fw-bold" style="font-size:12px;" onclick="openBookingModal(${station.id})">
                        Book This Station
                    </button>
                </div>
            `;

            L.marker([station.lat, station.lng]).addTo(mapInstance).bindPopup(popupHtml);
        });
    }

    function openBookingModal(stationId) {
        selectedStation = stationsData.find(s => s.id === stationId);
        if (!selectedStation) return;

        document.getElementById('modal-station-name').innerText = selectedStation.name;
        document.getElementById('modal-station-address').innerText = selectedStation.address;

        const fuelSelect = document.getElementById('modal-fuel-select');
        fuelSelect.innerHTML = selectedStation.fuels
            .map(f => `<option value="${f.label}">${f.label}</option>`)
            .join('');

        document.getElementById('modal-volume-input').value = 20;
        document.getElementById('modal-quota-display').innerText = availableOilQuota + ' L';

        // Close the map popup so it doesn't sit behind the modal
        if (mapInstance) mapInstance.closePopup();

        const modalEl = new bootstrap.Modal(document.getElementById('bookingModal'));
        modalEl.show();
    }

    function confirmBooking() {
        const requestedVal = parseFloat(document.getElementById('modal-volume-input').value);
        const chosenFuel = document.getElementById('modal-fuel-select').value;

        if (isNaN(requestedVal) || requestedVal <= 0) {
            alert("Provide a valid fuel allocation volume.");
            return;
        }
        if (requestedVal > availableOilQuota) {
            alert(`Authorization rejected. Your requested reservation of ${requestedVal}L exceeds your remaining allowance limit: ${availableOilQuota}L.`);
            return;
        }

        bootstrap.Modal.getInstance(document.getElementById('bookingModal')).hide();

        const tokenCode = 'MP-' + Math.floor(100 + Math.random() * 900) + '-' +
            Math.random().toString(36).substring(2, 6).toUpperCase();
        const qrPayload = `${tokenCode}|${selectedStation.name}|${chosenFuel}|${requestedVal}L`;

        document.getElementById('qr-token-code').innerText = tokenCode;
        document.getElementById('qr-station-name').innerText = selectedStation.name;
        document.getElementById('qr-fuel-type').innerText = chosenFuel;
        document.getElementById('qr-volume').innerText = requestedVal + ' L';
        document.getElementById('qr-image').src =
            'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(qrPayload);

        const qrCard = document.getElementById('qr-booking-card');
        qrCard.classList.remove('d-none');
        qrCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function cancelQrBooking() {
        const stationName = document.getElementById('qr-station-name').innerText;
        alert("Processing cancellation logs. Dispatched network cancel event to stations.");

        document.getElementById('notif-pulse-dot').classList.remove('d-none');
        document.getElementById('alert-logs-list').innerHTML = `
            <div class="px-3 py-2 border-bottom border-secondary border-opacity-5" style="font-size:12px;">
                <p class="font-bold text-danger mb-0">Token Cancelled</p>
                <p class="text-secondary mb-0 mt-0.5">Reservation for ${stationName} was cancelled.</p>
            </div>
        `;

        document.getElementById('qr-booking-card').classList.add('d-none');
    }
</script>

<?php include 'share_footer.php'; ?>
