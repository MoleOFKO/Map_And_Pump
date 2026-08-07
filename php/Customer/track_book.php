<?php include 'share_header.php'; ?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">

<section id="station-launch-card" class="dashboard-panel-card customer-map-launch-card">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div>
            <h3 class="text-white mb-1">Find a station and book your fuel</h3>
            <p class="text-secondary mb-0" style="font-size:13px;">Choose a nearby station to create your booking pass.</p>
        </div>
        <button id="locate-btn" class="btn btn-info px-4 py-3 fw-bold rounded-4" type="button" onclick="openMapExperience()" style="font-size:13px; font-family:'Space Grotesk';">
            Choose a station on the map <i class="bi bi-arrow-right ms-2"></i>
        </button>
    </div>
</section>

<section id="map-experience" class="customer-map-experience d-none" aria-live="polite">
    <div id="station-map" class="customer-station-map"></div>

    <button id="show-station-card" type="button" class="btn btn-info customer-map-reopen d-none" onclick="showStationCard()">
        <i class="bi bi-card-text me-2"></i> View selected station
    </button>

    <section id="station-booking-card" class="dashboard-panel-card customer-map-station-card d-none">
        <button type="button" class="btn btn-outline-info btn-sm customer-card-hide-btn" onclick="hideStationCard()" aria-label="Hide station card">
            <i class="bi bi-chevron-down"></i><span>Hide card</span>
        </button>
        <div id="station-booking-form">
            <div class="d-flex justify-content-between align-items-start gap-2 border-bottom border-secondary border-opacity-10 pb-3 mb-3">
                <div>
                    <span class="text-sky-400 text-uppercase" style="font-size:10px; font-weight:700; letter-spacing:.08em;">Selected station</span>
                    <h4 id="selected-station-name" class="text-white mt-1 mb-1">Station</h4>
                    <p id="selected-station-address" class="text-secondary mb-0" style="font-size:12px;"></p>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label text-secondary" for="station-fuel-select" style="font-size:10px; font-weight:600;">FUEL TYPE</label>
                <select id="station-fuel-select" class="form-select custom-form-field"></select>
            </div>
            <div class="mb-3">
                <label class="form-label text-secondary" for="station-volume-input" style="font-size:10px; font-weight:600;">AMOUNT (LITERS)</label>
                <input id="station-volume-input" type="number" min="0.1" step="0.1" value="20" class="form-control custom-form-field">
                <small class="text-secondary d-block mt-2">Remaining allowance: <strong id="station-quota" class="text-sky-400"></strong></small>
            </div>
            <button type="button" class="btn btn-info w-100 py-3 fw-bold rounded-4" onclick="confirmBooking()">Book this station <i class="bi bi-qr-code ms-1"></i></button>
        </div>

        <div id="booking-pass" class="d-none text-center py-1">
            <div class="customer-qr-icon mx-auto mb-2"><i class="bi bi-qr-code-scan"></i></div>
            <span class="status-pill active mb-2">Booking confirmed</span>
            <h4 class="text-white mb-1">Booking pass</h4>
            <p id="booking-summary" class="text-secondary mb-2" style="font-size:12px;"></p>
            <div class="customer-qr-wrap customer-map-qr mx-auto mb-2"><img id="qr-image" src="" alt="Booking QR code"></div>
            <div id="qr-token-code" class="token-no text-sky-400 mb-3" style="font-size:16px; letter-spacing:.08em;"></div>
            <button type="button" class="btn btn-outline-danger btn-sm px-3 py-2 fw-bold rounded-3" onclick="cancelBooking()"><i class="bi bi-x-circle me-1"></i>Cancel booking</button>
        </div>
    </section>
</section>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    let mapInstance, customerMarker, routeLine, selectedStation;
    let customerLocation = [16.8409, 96.1735]; // Yangon fallback when location permission is unavailable.
    const availableOilQuota = <?php echo ($_SESSION['oil_limit'] - $_SESSION['oil_used']); ?>;
    const stationsData = [
        { id: 1, name: 'Yangon Terminal Center', address: 'Pyay Road, Mayangone Township', lat: 16.8661, lng: 96.1349, fuels: ['Octane 95', 'Diesel'] },
        { id: 2, name: 'Insein Branch Station 04', address: 'Baho Road, Insein Township', lat: 16.8961, lng: 96.0891, fuels: ['Octane 92'] }
    ];

    function openMapExperience() {
        document.getElementById('station-launch-card').classList.add('d-none');
        document.getElementById('map-experience').classList.remove('d-none');
        if (!mapInstance) {
            mapInstance = L.map('station-map', { scrollWheelZoom: false }).setView(customerLocation, 12);
            L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', { attribution: '&copy; OpenStreetMap contributors &copy; CARTO', maxZoom: 20 }).addTo(mapInstance);
            stationsData.forEach(station => {
                const marker = L.marker([station.lat, station.lng]).addTo(mapInstance);
                marker.bindTooltip(station.name, { direction: 'top' });
                marker.on('click', () => selectStation(station.id));
            });
            locateCustomer();
        }
        setTimeout(() => mapInstance.invalidateSize(), 50);
    }

    function locateCustomer() {
        if (!navigator.geolocation) { drawCustomerLocation(); return; }
        navigator.geolocation.getCurrentPosition(position => {
            customerLocation = [position.coords.latitude, position.coords.longitude];
            drawCustomerLocation();
        }, drawCustomerLocation, { enableHighAccuracy: true, timeout: 8000 });
    }

    function drawCustomerLocation() {
        if (customerMarker) mapInstance.removeLayer(customerMarker);
        customerMarker = L.circleMarker(customerLocation, { radius: 9, color: '#fff', weight: 2, fillColor: '#00a8ff', fillOpacity: 1 }).addTo(mapInstance).bindTooltip('Your location');
        if (!selectedStation) mapInstance.setView(customerLocation, 12);
        if (selectedStation) showRoute();
    }

    function selectStation(stationId) {
        selectedStation = stationsData.find(station => station.id === stationId);
        document.getElementById('selected-station-name').textContent = selectedStation.name;
        document.getElementById('selected-station-address').textContent = selectedStation.address;
        document.getElementById('station-fuel-select').innerHTML = selectedStation.fuels.map(fuel => `<option value="${fuel}">${fuel}</option>`).join('');
        document.getElementById('station-quota').textContent = availableOilQuota + ' L';
        document.getElementById('station-volume-input').value = Math.min(20, availableOilQuota);
        document.getElementById('booking-pass').classList.add('d-none');
        document.getElementById('station-booking-form').classList.remove('d-none');
        showStationCard();
        showRoute();
    }

    function showStationCard() {
        if (!selectedStation) return;
        document.getElementById('station-booking-card').classList.remove('d-none');
        document.getElementById('show-station-card').classList.add('d-none');
    }

    function hideStationCard() {
        document.getElementById('station-booking-card').classList.add('d-none');
        document.getElementById('show-station-card').innerHTML = '<i class="bi bi-card-text me-2"></i> Show station card';
        document.getElementById('show-station-card').classList.remove('d-none');
    }

    async function showRoute() {
        if (!mapInstance || !selectedStation) return;
        if (routeLine) mapInstance.removeLayer(routeLine);
        const destination = [selectedStation.lat, selectedStation.lng];
        routeLine = L.polyline([customerLocation, destination], { color: '#00f4ff', weight: 4, opacity: .8, dashArray: '8 8' }).addTo(mapInstance);
        mapInstance.fitBounds(routeLine.getBounds(), { padding: [55, 55] });
        try {
            const points = `${customerLocation[1]},${customerLocation[0]};${selectedStation.lng},${selectedStation.lat}`;
            const response = await fetch(`https://router.project-osrm.org/route/v1/driving/${points}?overview=full&geometries=geojson`);
            const route = (await response.json()).routes?.[0];
            if (!route) return;
            mapInstance.removeLayer(routeLine);
            routeLine = L.geoJSON(route.geometry, { style: { color: '#00f4ff', weight: 5, opacity: .9 } }).addTo(mapInstance);
        } catch (error) { /* The cyan fallback line remains visible. */ }
    }

    function confirmBooking() {
        const quantity = Number(document.getElementById('station-volume-input').value);
        if (!selectedStation || !quantity || quantity <= 0 || quantity > availableOilQuota) { alert(`Enter an amount between 0.1 and ${availableOilQuota} liters.`); return; }
        const fuel = document.getElementById('station-fuel-select').value;
        const code = `MP-${Math.floor(100 + Math.random() * 900)}-${Math.random().toString(36).substring(2, 6).toUpperCase()}`;
        document.getElementById('qr-token-code').textContent = code;
        document.getElementById('booking-summary').textContent = `${selectedStation.name} / ${fuel} / ${quantity} L`;
        document.getElementById('qr-image').src = `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(`${code}|${selectedStation.name}|${fuel}|${quantity}L`)}`;
        document.getElementById('station-booking-form').classList.add('d-none');
        document.getElementById('booking-pass').classList.remove('d-none');
        showStationCard();
    }

    function cancelBooking() {
        document.getElementById('booking-pass').classList.add('d-none');
        document.getElementById('station-booking-form').classList.remove('d-none');
    }
</script>

<?php include 'share_footer.php'; ?>