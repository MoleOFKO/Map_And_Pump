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

<section id="quick-mapping-card" class="dashboard-panel-card customer-map-launch-card mt-3">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div>
            <h3 class="text-white mb-1">Quick Mapping</h3>
            <p class="text-secondary mb-0" style="font-size:13px;">Automatically choose nearest station on the map</p>
        </div>
        <button id="auto-connect-btn" class="btn btn-info px-4 py-3 fw-bold rounded-4" type="button" onclick="autoConnectNearestStation()" style="font-size:13px; font-family:'Space Grotesk';">
            Auto connect <i class="bi bi-geo-alt-fill ms-2"></i>
        </button>
    </div>
</section>

<section id="top5-stations-card" class="dashboard-panel-card customer-map-launch-card mt-3 top5-stations-panel">
    <h3 class="text-white mb-2">Top 5 nearest stations</h3>
    <div id="top5-stations-list" class="top5-stations-list">
        <div class="text-secondary" style="font-size:13px;">Locating nearest stations…</div>
    </div>
</section>

<style>
.top5-stations-panel {
    overflow: hidden;
}
.top5-stations-list {
    display: flex;
    flex-direction: column;
    gap: 0;
}
.top5-station-row {
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
    padding: 10px 4px;
    border: 0;
    border-bottom: 1px solid rgba(255,255,255,.08);
    background: transparent;
    color: inherit;
    text-align: left;
    cursor: pointer;
    transition: background .15s;
}
.top5-station-row:last-child {
    border-bottom: 0;
    padding-bottom: 2px;
}
.top5-station-row:first-of-type {
    padding-top: 4px;
}
.top5-station-row:hover {
    background: rgba(0, 244, 255, .07);
}
.top5-station-row .row-rank {
    flex: 0 0 22px;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 700;
    color: #00a8ff;
    background: rgba(0, 168, 255, .12);
}
.top5-station-row img {
    flex: 0 0 28px;
    width: 28px;
    height: 28px;
    object-fit: contain;
    border-radius: 8px;
    background: rgba(255,255,255,.06);
    padding: 3px;
}
.top5-station-row .row-body {
    flex: 1 1 auto;
    min-width: 0;
}
.top5-station-row .row-name {
    display: block;
    color: #fff;
    font-size: 13px;
    font-weight: 600;
    line-height: 1.3;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.top5-station-row .row-address {
    display: block;
    color: rgba(255,255,255,.45);
    font-size: 11px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.top5-station-row .row-dist {
    flex: 0 0 auto;
    color: #00a8ff;
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
}

/* Light mode: station name / address must stay readable on pale glass cards */
body.customer-light-mode .top5-station-row {
    border-bottom-color: rgba(11, 94, 215, 0.12);
    color: #102033;
}
body.customer-light-mode .top5-station-row:hover {
    background: rgba(11, 94, 215, 0.07);
}
body.customer-light-mode .top5-station-row .row-rank {
    color: #0b5ed7;
    background: rgba(11, 94, 215, 0.1);
}
body.customer-light-mode .top5-station-row img {
    background: rgba(11, 94, 215, 0.06);
}
body.customer-light-mode .top5-station-row .row-name {
    color: #102033;
}
body.customer-light-mode .top5-station-row .row-address {
    color: rgba(51, 65, 85, 0.72);
}
body.customer-light-mode .top5-station-row .row-dist {
    color: #0b5ed7;
}

.customer-map-back-btn {
    position: absolute;
    z-index: 900;
    top: 16px;
    right: 16px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    border-radius: 999px;
    font-weight: 700;
    font-size: 13px;
    padding: 8px 14px;
    color: #e8f3ff;
    background: rgba(4, 10, 20, 0.82);
    border: 1px solid rgba(13, 110, 253, 0.28);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.28);
    transition: background 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
}
.customer-map-back-btn:hover {
    background: rgba(13, 110, 253, 0.18);
    border-color: rgba(13, 110, 253, 0.45);
    color: #fff;
    box-shadow: 0 10px 24px rgba(13, 110, 253, 0.15);
}
body.customer-light-mode .customer-map-back-btn {
    color: #102033;
    background: rgba(255, 255, 255, 0.92);
    border-color: rgba(11, 94, 215, 0.2);
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.12);
}
body.customer-light-mode .customer-map-back-btn:hover {
    background: rgba(11, 94, 215, 0.1);
    border-color: rgba(11, 94, 215, 0.35);
    color: #0b5ed7;
}
.customer-map-recenter-btn {
    position: absolute;
    z-index: 900;
    right: 16px;
    bottom: 16px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    border-radius: 999px;
    font-weight: 700;
    font-size: 13px;
    padding: 8px 14px;
    color: #e8f3ff;
    background: rgba(4, 10, 20, 0.82);
    border: 1px solid rgba(13, 110, 253, 0.28);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.28);
    transition: background 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
}
.customer-map-recenter-btn:hover {
    background: rgba(13, 110, 253, 0.18);
    border-color: rgba(13, 110, 253, 0.45);
    color: #fff;
    box-shadow: 0 10px 24px rgba(13, 110, 253, 0.15);
}
body.customer-light-mode .customer-map-recenter-btn {
    color: #102033;
    background: rgba(255, 255, 255, 0.92);
    border-color: rgba(11, 94, 215, 0.2);
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.12);
}
body.customer-light-mode .customer-map-recenter-btn:hover {
    background: rgba(11, 94, 215, 0.1);
    border-color: rgba(11, 94, 215, 0.35);
    color: #0b5ed7;
}
@media (max-width: 575.98px) {
    .customer-map-back-btn,
    .customer-map-recenter-btn {
        padding: 7px 12px;
        font-size: 12px;
    }
    .customer-map-back-btn { top: 12px; right: 12px; }
    .customer-map-recenter-btn { right: 12px; bottom: 12px; }
}
</style>


<section id="map-experience" class="customer-map-experience d-none" aria-live="polite">

    <button id="map-recenter-btn" type="button" class="btn btn-sm customer-map-recenter-btn" onclick="recenterCustomerLocation()" aria-label="Recenter map on your location" title="Recenter on your location">
        <i class="bi bi-crosshair me-1"></i> My location
    </button>

    <button id="map-back-btn" type="button" class="btn btn-sm customer-map-back-btn" onclick="closeMapExperience()" aria-label="Back to station cards">
        <i class="bi bi-arrow-left me-1"></i> Back
    </button>

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
    const stationLogoUrl = '../../images/Logo.png';
    const stationsData = [
        { id: 1, name: 'Yangon Terminal Center', address: 'Pyay Road, Mayangone Township', lat: 16.8661, lng: 96.1349, fuels: ['Octane 95', 'Diesel'], logo: stationLogoUrl },
        { id: 2, name: 'Insein Branch Station 04', address: 'Baho Road, Insein Township', lat: 16.8961, lng: 96.0891, fuels: ['Octane 92'], logo: stationLogoUrl },
        { id: 3, name: 'Sanchaung Fuel Hub', address: 'Bargayar Road, Sanchaung', lat: 16.8055, lng: 96.1330, fuels: ['Octane 95', 'Octane 92'], logo: stationLogoUrl },
        { id: 4, name: 'Tamwe Express Station', address: 'Kyaikkasan Road, Tamwe', lat: 16.8120, lng: 96.1755, fuels: ['Diesel', 'Octane 92'], logo: stationLogoUrl },
        { id: 5, name: 'Hlaing Tharyar Depot', address: 'No.3 Main Road, Hlaing Tharyar', lat: 16.8700, lng: 96.0650, fuels: ['Octane 95', 'Diesel'], logo: stationLogoUrl },
        { id: 6, name: 'Thingangyun Station 02', address: 'Waizayantar Road, Thingangyun', lat: 16.8305, lng: 96.1950, fuels: ['Octane 92'], logo: stationLogoUrl },
        { id: 7, name: 'Kamayut Central Pump', address: 'University Avenue, Kamayut', lat: 16.8250, lng: 96.1355, fuels: ['Octane 95', 'Octane 92', 'Diesel'], logo: stationLogoUrl }
    ];

    function openMapExperience(autoSelectNearest, preselectStationId) {
        document.getElementById('station-launch-card').classList.add('d-none');
        document.getElementById('quick-mapping-card').classList.add('d-none');
        document.getElementById('top5-stations-card').classList.add('d-none');
        document.getElementById('map-experience').classList.remove('d-none');
        if (!mapInstance) {
            mapInstance = L.map('station-map', { scrollWheelZoom: false }).setView(customerLocation, 12);
            L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', { attribution: '&copy; OpenStreetMap contributors &copy; CARTO', maxZoom: 20 }).addTo(mapInstance);
            stationsData.forEach(station => {
                const marker = L.marker([station.lat, station.lng]).addTo(mapInstance);
                marker.bindTooltip(station.name, { direction: 'top' });
                marker.on('click', () => selectStation(station.id));
            });
            locateCustomer(autoSelectNearest, preselectStationId);
        } else {
            if (preselectStationId) {
                selectStation(preselectStationId);
            } else if (autoSelectNearest) {
                locateCustomer(true);
            }
        }
        setTimeout(() => mapInstance.invalidateSize(), 50);
    }

    function closeMapExperience() {
        document.getElementById('map-experience').classList.add('d-none');
        document.getElementById('station-booking-card').classList.add('d-none');
        document.getElementById('show-station-card').classList.add('d-none');
        document.getElementById('station-launch-card').classList.remove('d-none');
        document.getElementById('quick-mapping-card').classList.remove('d-none');
        document.getElementById('top5-stations-card').classList.remove('d-none');
        document.getElementById('booking-pass').classList.add('d-none');
        document.getElementById('station-booking-form').classList.remove('d-none');
        selectedStation = null;
    }

    function distanceKm(lat1, lng1, lat2, lng2) {
        const toRad = deg => deg * Math.PI / 180;
        const R = 6371;
        const dLat = toRad(lat2 - lat1);
        const dLng = toRad(lng2 - lng1);
        const a = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLng / 2) ** 2;
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    function findNearestStation() {
        let nearest = null;
        let minDist = Infinity;
        stationsData.forEach(station => {
            const d = distanceKm(customerLocation[0], customerLocation[1], station.lat, station.lng);
            if (d < minDist) {
                minDist = d;
                nearest = station;
            }
        });
        return nearest;
    }

    function getTopNearestStations(limit) {
        return stationsData
            .map(station => ({
                ...station,
                distance: distanceKm(customerLocation[0], customerLocation[1], station.lat, station.lng)
            }))
            .sort((a, b) => a.distance - b.distance)
            .slice(0, limit);
    }

    function renderTop5Stations() {
        const list = document.getElementById('top5-stations-list');
        if (!list) return;
        const top5 = getTopNearestStations(5);
        list.innerHTML = top5.map((station, index) => `
            <button type="button" class="top5-station-row" onclick="bookFromTop5(${station.id})" title="${station.name}">
                <span class="row-rank">${index + 1}</span>
                <img src="${station.logo}" alt="${station.name} logo" onerror="this.src='../../images/Logo.png'">
                <span class="row-body">
                    <span class="row-name">${station.name}</span>
                    <span class="row-address">${station.address}</span>
                </span>
                <span class="row-dist">${station.distance.toFixed(1)} km</span>
            </button>
        `).join('');
    }


    function bookFromTop5(stationId) {
        openMapExperience(false, stationId);
    }

    function autoConnectNearestStation() {
        openMapExperience(true);
    }

    function locateCustomer(autoSelectNearest, preselectStationId) {
        const afterLocate = () => {
            drawCustomerLocation(autoSelectNearest, preselectStationId);
            renderTop5Stations();
        };
        if (!navigator.geolocation) {
            afterLocate();
            return;
        }
        navigator.geolocation.getCurrentPosition(position => {
            customerLocation = [position.coords.latitude, position.coords.longitude];
            afterLocate();
        }, afterLocate, { enableHighAccuracy: true, timeout: 8000 });
    }

    function recenterCustomerLocation() {
        if (!mapInstance) return;
        const recenter = () => {
            drawCustomerLocation(false);
            mapInstance.setView(customerLocation, 15, { animate: true });
            renderTop5Stations();
        };
        if (!navigator.geolocation) {
            recenter();
            return;
        }
        navigator.geolocation.getCurrentPosition(position => {
            customerLocation = [position.coords.latitude, position.coords.longitude];
            recenter();
        }, recenter, { enableHighAccuracy: true, timeout: 8000 });
    }

    function drawCustomerLocation(autoSelectNearest, preselectStationId) {
        if (!mapInstance) return;
        if (customerMarker) mapInstance.removeLayer(customerMarker);
        customerMarker = L.circleMarker(customerLocation, { radius: 9, color: '#fff', weight: 2, fillColor: '#00a8ff', fillOpacity: 1 }).addTo(mapInstance).bindTooltip('Your location');
        if (preselectStationId) {
            selectStation(preselectStationId);
        } else if (autoSelectNearest) {
            const nearest = findNearestStation();
            if (nearest) selectStation(nearest.id);
        } else {
            if (!selectedStation) mapInstance.setView(customerLocation, 12);
            if (selectedStation) showRoute();
        }
    }

    // Populate Top 5 list as soon as the page loads (uses geolocation when allowed).
    (function initTop5List() {
        if (!navigator.geolocation) {
            renderTop5Stations();
            return;
        }
        navigator.geolocation.getCurrentPosition(position => {
            customerLocation = [position.coords.latitude, position.coords.longitude];
            renderTop5Stations();
        }, () => renderTop5Stations(), { enableHighAccuracy: true, timeout: 8000 });
    })();



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

    async function confirmBooking() {
        const quantity = Number(document.getElementById('station-volume-input').value);
        if (!selectedStation || !quantity || quantity <= 0 || quantity > availableOilQuota) { alert(`Enter an amount between 0.1 and ${availableOilQuota} liters.`); return; }
        const fuel = document.getElementById('station-fuel-select').value;
        const code = `MP-${Math.floor(100 + Math.random() * 900)}-${Math.random().toString(36).substring(2, 6).toUpperCase()}`;
        const displayPayload = `${code}|${selectedStation.name}|${fuel}|${quantity}L`;

        const formData = new FormData();
        formData.append('booking_code', code);
        formData.append('station_id', String(selectedStation.id));
        formData.append('fuel_type', fuel);
        formData.append('quantity_liters', String(quantity));
        formData.append('display_payload', displayPayload);
        formData.append('customer_id', '<?php echo isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0; ?>');

        let result = { payload: displayPayload };
        try {
            const response = await fetch('save_booking.php', {
                method: 'POST',
                body: formData
            });
            result = await response.json();
            if (!response.ok || !result.success) {
                console.warn('Booking was not saved to the server:', result.message || 'Unknown API error.');
            }
        } catch (error) {
            console.warn('Booking API unavailable; displaying the locally generated booking pass.', error);
        }

        document.getElementById('qr-token-code').textContent = code;
        document.getElementById('booking-summary').textContent = `${selectedStation.name} / ${fuel} / ${quantity} L`;
        const qrPayload = result.payload || displayPayload;
        document.getElementById('qr-image').src = `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(qrPayload)}`;
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
