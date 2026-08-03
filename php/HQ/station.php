<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="/css/HQstyle.css">
    <style>
        /* Custom table color overrides matching dark dashboard aesthetic */
        .table-custom {
            background-color: transparent !important;
            color: #dee2e6;
        }
        .table-custom th {
            text-transform: uppercase;
            font-size: 0.74rem;
            letter-spacing: 0.05em;
            color: var(--slate-300);
            padding: 12px 16px;
            background: rgba(255, 255, 255, 0.05);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }
        .table-custom td {
            background-color: transparent !important;
            color: #ced4da !important;
            border-color: #2c3034;
            vertical-align: middle;
        }
        /* Ensure buttons wrap gracefully and stay aligned inside table cells */
        .table-actions {
            white-space: nowrap;
        }

        /* Custom Modal Theme Colors Matching the Dark Dashboard Card Style */
        .modal-content-custom {
            background-color: #121A26 !important;
            border: 1px solid #373b3e !important;
            box-shadow: 0 1rem 3rem rgba(0, 0, 0, 0.5);
        }

        .modal-content-decommission {
            background-color: #121A26 !important;
            border: 1px solid #842029 !important;
            box-shadow: 0 1rem 3rem rgba(220, 53, 69, 0.15);
        }
    </style>
</head>
<body>
    <h2 class="title">Station Network</h2>
    <p class="subtitle">Onboard physical station nodes and set up terminal operating profiles.</p>
    <!-- Managed Stations Section with Location instead of Location Code -->
    <div class="card">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h3>Manage Deployed Stations</h3>
                <p class="subtitle mb-0">Monitor active nodes, update operational status, or remove configurations.</p>
            </div>
            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#registerModal">ADD</button>
        </div>
        <div class="table-responsive">
            <table class="table table-custom align-middle">
                <thead>
                    <tr>
                        <th>Branch ID</th>
                        <th>Station Name</th>
                        <th>Location</th>
                        <th>Branch Manager</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>BR-HQ-01</td>
                        <td>Downtown Metro</td>
                        <td>New York</td>
                        <td>Sarah Jenkins <br><small class="text-muted">sarah.j@apex.com</small></td>
                        <td><span id="status-badge-1" class="badge bg-success">Active</span></td>
                        <td class="text-end table-actions">
                            <button type="button" id="toggle-btn-1" class="btn btn-sm btn-outline-warning me-1" onclick="toggleStatus(1)">Set Maintenance</button>
                            <button type="button" class="btn btn-sm btn-outline-light me-1" data-bs-toggle="modal" data-bs-target="#configureModal" onclick="setConfigureData('Downtown Metro', 'New York', 'Sarah Jenkins')">Configure</button>
                            <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#decommissionModal" onclick="setDecommissionData('Site #014 - Downtown Metro')">Decommission</button>
                        </td>
                    </tr>
                    <tr>
                        <td>BR-HQ-02</td>
                        <td>Highway Bypass</td>
                        <td>Chicago</td>
                        <td>Marcus Vance <br><small class="text-muted">marcus.v@apex.com</small></td>
                        <td><span id="status-badge-2" class="badge bg-warning text-dark">Maintenance</span></td>
                        <td class="text-end table-actions">
                            <button type="button" id="toggle-btn-2" class="btn btn-sm btn-outline-success me-1" onclick="toggleStatus(2)">Set Active</button>
                            <button type="button" class="btn btn-sm btn-outline-light me-1" data-bs-toggle="modal" data-bs-target="#configureModal" onclick="setConfigureData('Highway Bypass', 'Chicago', 'Marcus Vance')">Configure</button>
                            <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#decommissionModal" onclick="setDecommissionData('Site #009 - Highway Bypass')">Decommission</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Register Station & Onboard Manager Modal -->
    <div class="modal fade" id="registerModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content modal-content-custom text-light">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title">Register Station Node & Manager</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Register Station Node Section -->
                    <div class="card bg-transparent border-0 mb-4 p-0">
                        <h3>Register Station Node</h3>
                        <p class="subtitle" style="margin-bottom: 12px;">Establish unique location tags for network reporting[cite: 3].</p>
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <div class="form-group">
                                    <label class="form-label text-light">Branch ID</label>
                                    <input type="text" class="form-control bg-dark text-light border-secondary" placeholder="e.g. #014">
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="form-group">
                                    <label class="form-label text-light">Station Name</label>
                                    <input type="text" class="form-control bg-dark text-light border-secondary" placeholder="e.g. Downtown Metro">
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="form-group">
                                    <label class="form-label text-light">Region</label>
                                    <select class="form-control bg-dark text-light border-secondary">
                                        <option value="" disabled selected>Select Region</option>
                                        <option value="Ygn">Yangon</option>
                                        <option value="Mdy">Mandalay</option>
                                        <option value="NPT">Nay Pyi Taw</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="form-group">
                                    <label class="form-label text-light">Location</label>
                                    <input type="text" class="form-control bg-dark text-light border-secondary" placeholder="e.g. New York">
                                </div>
                            </div>
                        </div>
                    </div>

                    <hr class="border-secondary my-4">

                    <!-- Onboard Site Manager Account Section -->
                    <div class="card bg-transparent border-0 p-0">
                        <h3>Onboard Site Manager Account</h3>
                        <p class="subtitle" style="margin-bottom: 12px;">Provision manager profiles responsible for log and inventory oversight[cite: 3].</p>
                        <div class="form-group mb-3">
                            <label class="form-label text-light">Assigned Node</label>
                            <select class="form-control bg-dark text-light border-secondary">
                                <option>BR-HQ-01 - Downtown Metro</option>
                                <option>BR-HQ-02 - Highway Bypass</option>
                            </select>
                        </div>
                        <div class="form-group mb-3">
                            <label class="form-label text-light">Operator Professional Email</label>
                            <input type="email" class="form-control bg-dark text-light border-secondary" placeholder="manager.name@apex.com">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-success" data-bs-dismiss="modal" onclick="alert('Terminal Node and Manager profile created successfully.')">Register Station</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Configure Modal with Location Field -->
    <div class="modal fade" id="configureModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content modal-content-custom text-light">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title">Configure Station Node</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form onsubmit="event.preventDefault(); alert('Configuration saved successfully!');">
                        <div class="mb-3">
                            <label class="form-label text-light">Branch ID</label>
                            <input type="text" id="configBranchId" class="form-control bg-dark text-light border-secondary" value="BR-HQ-01">
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-light">Station Name</label>
                            <input type="text" id="configStationName" class="form-control bg-dark text-light border-secondary">
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-light">Location</label>
                            <input type="text" id="configLocation" class="form-control bg-dark text-light border-secondary">
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-light">Assigned Branch Manager</label>
                            <input type="text" id="configManagerName" class="form-control bg-dark text-light border-secondary">
                        </div>
                        <div class="text-end mt-4">
                            <button type="button" class="btn btn-outline-light me-2" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-success" data-bs-dismiss="modal">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Decommission Modal -->
    <div class="modal fade" id="decommissionModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content modal-content-decommission text-light">
                <div class="modal-header border-danger">
                    <h5 class="modal-title text-danger">Decommission Station Node</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to decommission <strong id="decommissionTarget" class="text-warning"></strong>?</p>
                    <p class="small text-muted">This action will remove terminal node configurations and revoke associated manager keys.</p>
                    <div class="text-end mt-4">
                        <button type="button" class="btn btn-outline-light me-2" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-danger" data-bs-dismiss="modal" onclick="alert('Station successfully decommissioned.')">Confirm Decommission</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle (Required for Modals) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        function toggleStatus(rowId) {
            const badge = document.getElementById('status-badge-' + rowId);
            const btn = document.getElementById('toggle-btn-' + rowId);

            if (badge.classList.contains('bg-success')) {
                badge.className = 'badge bg-warning text-dark';
                badge.textContent = 'Maintenance';
                btn.className = 'btn btn-sm btn-outline-success me-1';
                btn.textContent = 'Set Active';
            } else {
                badge.className = 'badge bg-success';
                badge.textContent = 'Active';
                btn.className = 'btn btn-sm btn-outline-warning me-1';
                btn.textContent = 'Set Maintenance';
            }
        }

        function setConfigureData(stationName, location, manager) {
            document.getElementById('configStationName').value = stationName;
            document.getElementById('configLocation').value = location;
            document.getElementById('configManagerName').value = manager;
        }

        function setDecommissionData(station) {
            document.getElementById('decommissionTarget').textContent = station;
        }
    </script>
</body>
<footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map & Pump | All Rights Reserved</footer>
</html>