<?php
require_once __DIR__ . '/hq_guard.php';
/**
 * Shared database connection.
 */
include_once __DIR__ . '/../conn.php';
$message = '';
$error = '';

// Handle Form Submissions & AJAX requests via PDO
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Register Station
    if ($action === 'register') {
        $station_name    = trim($_POST['station_name'] ?? '');
        $location        = trim($_POST['location'] ?? '');
        $station_address = trim($_POST['station_address'] ?? '');
        $manager_email   = trim($_POST['manager_email'] ?? '');

        if (!empty($station_name) && !empty($location) && !empty($manager_email)) {
            try {
                $managerStmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ? AND COALESCE(business_role_id, role_id) = 4 LIMIT 1");
                $managerStmt->execute([$manager_email]);
                $manager_id = $managerStmt->fetchColumn();

                if (!$manager_id) {
                    throw new RuntimeException('Please select a valid branch manager.');
                }

                $stmt = $pdo->prepare("INSERT INTO stations (station_name, location, station_address, business_user_id, status) VALUES (?, ?, ?, ?, 'Active')");
                $stmt->execute([$station_name, $location, $station_address, $manager_id]);
                $message = "Station node created successfully.";
            } catch (PDOException $e) {
                $error = "Error registering station: " . $e->getMessage();
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
        } else {
            $error = "Station Name, branch manager email, and location are required.";
        }
    }

    // Update Configuration
    if ($action === 'configure') {
        $station_id      = trim($_POST['station_id'] ?? '');
        $station_name    = trim($_POST['station_name'] ?? '');
        $station_address = trim($_POST['station_address'] ?? '');
        $location        = trim($_POST['location'] ?? '');
        $manager_email   = trim($_POST['manager_email'] ?? '');

        try {
            // Find user_id based on manager email
            $managerStmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ? AND COALESCE(business_role_id, role_id) = 4 LIMIT 1");
            $managerStmt->execute([$manager_email]);
            $manager_id = $managerStmt->fetchColumn();

            if (!$manager_id) {
                throw new RuntimeException('Please select a valid branch manager.');
            }

            $stmt = $pdo->prepare("UPDATE stations SET station_name = ?, station_address = ?, location = ?, business_user_id = ? WHERE station_id = ?");
            $stmt->execute([$station_name, $station_address, $location, $manager_id, $station_id]);
            $message = "Configuration saved successfully!";
        } catch (PDOException $e) {
            $error = "Error updating configuration: " . $e->getMessage();
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
    }

    // Toggle Status (AJAX Handler)
    if ($action === 'toggle_status') {
        $station_id = $_POST['station_id'] ?? '';
        $new_status = $_POST['status'] ?? '';

        if (!empty($station_id) && in_array($new_status, ['Active', 'Maintenance'])) {
            try {
                $stmt = $pdo->prepare("UPDATE stations SET status = ? WHERE station_id = ?");
                $stmt->execute([$new_status, $station_id]);
                echo json_encode(['success' => true]);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
        }
        exit;
    }

    // Decommission Station
    if ($action === 'decommission') {
        $station_id = $_POST['station_id'] ?? '';

        try {
            $stmt = $pdo->prepare("UPDATE stations SET status = 'Decommissioned' WHERE station_id = ?");
            $stmt->execute([$station_id]);
            $message = "Station successfully decommissioned and hidden.";
        } catch (PDOException $e) {
            $error = "Error decommissioning station: " . $e->getMessage();
        }
    }
}

// Fetch Stations from database with Manager Info
$stations = [];
$branchManagers = [];
try {
    $stmt = $pdo->query("SELECT s.*, u.user_name AS manager_name, u.email AS manager_email FROM stations s LEFT JOIN users u ON u.user_id = s.business_user_id WHERE s.status != 'Decommissioned' OR s.status IS NULL ORDER BY s.station_id DESC");
    $stations = $stmt->fetchAll();

    $managerStmt = $pdo->query("SELECT user_id, email FROM users WHERE COALESCE(business_role_id, role_id) = 4 AND status NOT IN ('suspended', 'removed') ORDER BY email");
    $branchManagers = $managerStmt->fetchAll();
} catch (PDOException $e) {
    $error = "Could not retrieve stations: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../../css/HQstyle.css">
    <style>
        .table-custom { background-color: transparent !important; color: #dee2e6; }
        .table-custom th { text-transform: uppercase; font-size: 0.74rem; letter-spacing: 0.05em; color: var(--slate-300); padding: 12px 16px; background: rgba(255, 255, 255, 0.05); border-bottom: 1px solid rgba(255, 255, 255, 0.08); }
        .table-custom td { background-color: transparent !important; color: #ced4da !important; border-color: #2c3034; vertical-align: middle; }
        .table-actions { white-space: nowrap; }
        .modal-content-custom { background-color: #121A26 !important; border: 1px solid #373b3e !important; box-shadow: 0 1rem 3rem rgba(0, 0, 0, 0.5); }
        .modal-content-decommission { background-color: #121A26 !important; border: 1px solid #842029 !important; box-shadow: 0 1rem 3rem rgba(220, 53, 69, 0.15); }
        #stationMap, #configMap { height: 220px; border-radius: 8px; border: 1px solid #373b3e; }
    </style>
</head>
<body>
    <div class="container py-4">
        <h2 class="title">Station Network</h2>
        <p class="subtitle">Onboard physical station nodes and set up terminal operating profiles.</p>

        <?php
if (!empty($message)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php
endif; ?>

        <?php
if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php
endif; ?>

        <div class="card p-4 bg-dark text-light border-secondary">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h3>Manage Deployed Stations</h3>
                    <p class="subtitle mb-0">Monitor active nodes, update operational status, or remove configurations.</p>
                </div>
                <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#registerModal">+Add Station</button>
            </div>
            <div class="table-responsive">
                <table class="table table-custom align-middle">
                    <thead>
                        <tr>
                            <th>Station ID</th>
                            <th>Station Name</th>
                            <th>Address</th>
                            <th>Branch Manager</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
if (empty($stations)): ?>
                            <tr><td colspan="6" class="text-center text-muted">No station nodes found.</td></tr>
                        <?php
else: ?>
                            <?php
foreach ($stations as $row): ?>
                                <?php
$isMaintenance = ($row['status'] === 'Maintenance');
                                    $badgeClass = $isMaintenance ? 'badge bg-warning text-dark' : 'badge bg-success';
                                    $statusText = $isMaintenance ? 'Maintenance' : 'Active';
                                    $btnClass = $isMaintenance ? 'btn btn-sm me-1' : 'btn btn-sm me-1';
                                    $btnText = $isMaintenance ? 'Set Active' : 'Set Maintenance';
                                    $targetStatus = $isMaintenance ? 'Active' : 'Maintenance';
                                ?>
                                <tr>
                                    <td>#<?= htmlspecialchars($row['station_id']) ?></td>
                                    <td><?= htmlspecialchars($row['station_name']) ?></td>
                                    <td><?= htmlspecialchars($row['station_address']) ?></td>
                                    <td><?= htmlspecialchars($row['manager_name'] ?? 'Not assigned') ?></td>
                                    <td>
                                        <span id="status-badge-<?= $row['station_id'] ?>" class="<?= $badgeClass ?>">
                                            <?= $statusText ?>
                                        </span>
                                    </td>
                                    <td class="text-end table-actions">
                                        <button type="button" id="toggle-btn-<?= $row['station_id'] ?>" class="<?= $btnClass ?>" onclick="toggleStatus(<?= $row['station_id'] ?>, '<?= $targetStatus ?>')">
                                            <?= $btnText ?>
                                        </button>
                                        <button type="button" class="btn btn-sm me-1" data-bs-toggle="modal" data-bs-target="#configureModal" 
                                            onclick="setConfigureData('<?= htmlspecialchars($row['station_id'], ENT_QUOTES) ?>', '<?= htmlspecialchars($row['station_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($row['station_address'], ENT_QUOTES) ?>', '<?= htmlspecialchars($row['manager_email'], ENT_QUOTES) ?>', '<?= htmlspecialchars($row['location'], ENT_QUOTES) ?>')">
                                            Configure
                                        </button>
                                        <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#decommissionModal" onclick="setDecommissionData('<?= htmlspecialchars($row['station_id']) ?>', 'Station #<?= htmlspecialchars($row['station_id']) ?> - <?= htmlspecialchars($row['station_name']) ?>')">Decommission</button>
                                    </td>
                                </tr>
                            <?php
endforeach; ?>
                        <?php
endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Register Modal -->
    <div class="modal fade" id="registerModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content modal-content-custom text-light">
                <form method="POST">
                    <input type="hidden" name="action" value="register">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title">Register Station Node</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-light">Station Name</label>
                                <input type="text" name="station_name" class="form-control bg-dark text-light border-secondary" placeholder="e.g. Downtown Metro" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-light">Branch Manager Email</label>
                                <select name="manager_email" class="form-select bg-dark text-light border-secondary" required>
                                    <option value="">Choose a branch manager</option>
                                    <?php
foreach ($branchManagers as $manager): ?>
                                        <option value="<?= htmlspecialchars($manager['email'], ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars($manager['email']) ?>
                                        </option>
                                    <?php
endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 mb-3">
                                <label class="form-label text-light">Station Address</label>
                                <input type="text" name="station_address" class="form-control bg-dark text-light border-secondary" placeholder="e.g. No. 45, Main Road, Township">
                            </div>
                            <div class="col-12 mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label class="form-label text-light mb-0">Location (Coordinates)</label>
                                    <button type="button" class="btn btn-sm btn-outline-info" id="trackLocBtn">
                                        <i class="bi bi-geo-alt-fill"></i> Track My Location
                                    </button>
                                </div>
                                <input type="text" id="stationLocation" name="location" class="form-control bg-dark text-light border-secondary" placeholder="Click the map or track location" required readonly>
                            </div>
                            <div class="col-12">
                                <div id="stationMap" aria-label="Choose station location on map"></div>
                                <small class="text-secondary">Click the map or use the button above to select coordinates.</small>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-secondary">
                        <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-success">Register Station</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Configure Modal -->
    <div class="modal fade" id="configureModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content modal-content-custom text-light">
                <form method="POST">
                    <input type="hidden" name="action" value="configure">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title">Configure Station Node</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-light">Station ID</label>
                                <input type="text" id="configStationId" name="station_id" class="form-control bg-dark text-light border-secondary" readonly>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-light">Station Name</label>
                                <input type="text" id="configStationName" name="station_name" class="form-control bg-dark text-light border-secondary" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-light">Branch Manager Email</label>
                                <select id="configManagerEmail" name="manager_email" class="form-select bg-dark text-light border-secondary" required>
                                    <option value="">Choose a branch manager</option>
                                    <?php
foreach ($branchManagers as $manager): ?>
                                        <option value="<?= htmlspecialchars($manager['email'], ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars($manager['email']) ?>
                                        </option>
                                    <?php
endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-light">Station Address</label>
                                <input type="text" id="configStationAddress" name="station_address" class="form-control bg-dark text-light border-secondary" placeholder="e.g. No. 45, Main Road">
                            </div>
                            <div class="col-12 mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label class="form-label text-light mb-0">Location (Coordinates)</label>
                                    <button type="button" class="btn btn-sm btn-outline-info" id="configTrackLocBtn">
                                        <i class="bi bi-geo-alt-fill"></i> Track My Location
                                    </button>
                                </div>
                                <input type="text" id="configLocation" name="location" class="form-control bg-dark text-light border-secondary" required readonly>
                            </div>
                            <div class="col-12">
                                <div id="configMap" aria-label="Choose station location on map"></div>
                                <small class="text-secondary">Click the map or use the button above to update station coordinates.</small>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-secondary">
                        <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Decommission Modal -->
    <div class="modal fade" id="decommissionModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content modal-content-decommission text-light">
                <form method="POST">
                    <input type="hidden" name="action" value="decommission">
                    <input type="hidden" id="decommissionStationId" name="station_id">
                    <div class="modal-header border-danger">
                        <h5 class="modal-title text-danger">Decommission Station Node</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to decommission <strong id="decommissionTarget" class="text-warning"></strong>?</p>
                        <p class="small text-muted">This action will hide the station from the active table without deleting its record from the database.</p>
                        <div class="text-end mt-4">
                            <button type="button" class="btn btn-outline-light me-2" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger">Confirm Decommission</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        let stationMap, stationMarker;
        let configMap, configMarker;

        // Helper function for Register Modal location
        function updateStationLocation(lat, lng) {
            const latLng = [lat, lng];
            if (stationMarker) {
                stationMarker.setLatLng(latLng);
            } else {
                stationMarker = L.marker(latLng).addTo(stationMap);
            }
            stationMap.setView(latLng, 15);
            document.getElementById('stationLocation').value = lat.toFixed(6) + ', ' + lng.toFixed(6);
        }

        // Helper function for Configure Modal location
        function updateConfigLocation(lat, lng) {
            const latLng = [lat, lng];
            if (configMarker) {
                configMarker.setLatLng(latLng);
            } else {
                configMarker = L.marker(latLng).addTo(configMap);
            }
            configMap.setView(latLng, 15);
            document.getElementById('configLocation').value = lat.toFixed(6) + ', ' + lng.toFixed(6);
        }

        // Register Modal Map
        document.getElementById('registerModal').addEventListener('shown.bs.modal', function () {
            if (!stationMap) {
                stationMap = L.map('stationMap').setView([21.9588, 96.0891], 6);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19
                }).addTo(stationMap);

                stationMap.on('click', function (event) {
                    updateStationLocation(event.latlng.lat, event.latlng.lng);
                });

                // Track My Location Button Functionality for Register
                document.getElementById('trackLocBtn').addEventListener('click', function () {
                    const btn = this;

                    if ("geolocation" in navigator) {
                        btn.disabled = true;
                        btn.textContent = "Locating...";

                        navigator.geolocation.getCurrentPosition(
                            function (position) {
                                updateStationLocation(position.coords.latitude, position.coords.longitude);
                                btn.disabled = false;
                                btn.innerHTML = '<i class="bi bi-geo-alt-fill"></i> Track My Location';
                            },
                            function (error) {
                                btn.disabled = false;
                                btn.innerHTML = '<i class="bi bi-geo-alt-fill"></i> Track My Location';
                                alert("Unable to retrieve your location.");
                            },
                            { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
                        );
                    } else {
                        alert("Geolocation is not supported by your web browser.");
                    }
                });
            }
            setTimeout(() => stationMap.invalidateSize(), 100);
        });

        // Configure Modal Map
        document.getElementById('configureModal').addEventListener('shown.bs.modal', function () {
            const coordsVal = document.getElementById('configLocation').value;
            let initialLatLng = [21.9588, 96.0891];
            let zoomLevel = 6;

            if (coordsVal && coordsVal.includes(',')) {
                const parts = coordsVal.split(',');
                const parsedLat = parseFloat(parts[0]);
                const parsedLng = parseFloat(parts[1]);
                if (!isNaN(parsedLat) && !isNaN(parsedLng)) {
                    initialLatLng = [parsedLat, parsedLng];
                    zoomLevel = 15;
                }
            }

            if (!configMap) {
                configMap = L.map('configMap').setView(initialLatLng, zoomLevel);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19
                }).addTo(configMap);

                configMap.on('click', function (event) {
                    updateConfigLocation(event.latlng.lat, event.latlng.lng);
                });

                // Track My Location Button Functionality for Configure
                document.getElementById('configTrackLocBtn').addEventListener('click', function () {
                    const btn = this;

                    if ("geolocation" in navigator) {
                        btn.disabled = true;
                        btn.textContent = "Locating...";

                        navigator.geolocation.getCurrentPosition(
                            function (position) {
                                updateConfigLocation(position.coords.latitude, position.coords.longitude);
                                btn.disabled = false;
                                btn.innerHTML = '<i class="bi bi-geo-alt-fill"></i> Track My Location';
                            },
                            function (error) {
                                btn.disabled = false;
                                btn.innerHTML = '<i class="bi bi-geo-alt-fill"></i> Track My Location';
                                alert("Unable to retrieve your location.");
                            },
                            { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
                        );
                    } else {
                        alert("Geolocation is not supported by your web browser.");
                    }
                });
            } else {
                configMap.setView(initialLatLng, zoomLevel);
            }

            // Set marker if coords exist
            if (coordsVal && coordsVal.includes(',')) {
                const parts = coordsVal.split(',');
                const latLngObj = L.latLng(parseFloat(parts[0]), parseFloat(parts[1]));
                if (configMarker) {
                    configMarker.setLatLng(latLngObj);
                } else {
                    configMarker = L.marker(latLngObj).addTo(configMap);
                }
            }

            setTimeout(() => configMap.invalidateSize(), 100);
        });

        function setConfigureData(stationId, stationName, stationAddress, managerEmail, location) {
            document.getElementById('configStationId').value = stationId;
            document.getElementById('configStationName').value = stationName;
            document.getElementById('configStationAddress').value = stationAddress;
            document.getElementById('configManagerEmail').value = managerEmail;
            document.getElementById('configLocation').value = location;
        }

        function setDecommissionData(stationId, stationText) {
            document.getElementById('decommissionStationId').value = stationId;
            document.getElementById('decommissionTarget').textContent = stationText;
        }

        function toggleStatus(id, newStatus) {
            const formData = new URLSearchParams();
            formData.append('action', 'toggle_status');
            formData.append('station_id', id);
            formData.append('status', newStatus);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const badge = document.getElementById('status-badge-' + id);
                    const btn = document.getElementById('toggle-btn-' + id);

                    if (newStatus === 'Maintenance') {
                        badge.className = 'badge bg-warning text-dark';
                        badge.textContent = 'Maintenance';
                        btn.className = 'btn btn-sm btn-outline-success me-1';
                        btn.textContent = 'Set Active';
                        btn.setAttribute('onclick', `toggleStatus(${id}, 'Active')`);
                    } else {
                        badge.className = 'badge bg-success';
                        badge.textContent = 'Active';
                        btn.className = 'btn btn-sm btn-outline-warning me-1';
                        btn.textContent = 'Set Maintenance';
                        btn.setAttribute('onclick', `toggleStatus(${id}, 'Maintenance')`);
                    }
                } else {
                    alert('Failed to update status: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while updating the status.');
            });
        }
    </script>
</body>
<footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map & Pump | All Rights Reserved</footer>
</html>
