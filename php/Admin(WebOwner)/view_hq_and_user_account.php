<?php
header('Location: manage_hq.php');
exit;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Map &amp; Pump Admin — View role 3 HQ accounts and their HQ details.">
    <title>View HQ — Map &amp; Pump</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&amp;display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="icon" type="image/png" href="/images/Logo.png">
    <link rel="stylesheet" href="/css/Admin.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .hq-status {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            color: rgba(255, 255, 255, .68);
            font-size: .8rem;
            white-space: nowrap;
        }
        .hq-status::before {
            content: '';
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
        }
        .hq-status-active::before { background: #1be494; box-shadow: 0 0 8px #1be494; }
        .hq-status-pending::before { background: #ffc107; box-shadow: 0 0 8px #ffc107; }
        .hq-status-suspended::before { background: #ff9f43; box-shadow: 0 0 8px #ff9f43; }
        .hq-status-removed::before { background: #ff4d6a; box-shadow: 0 0 8px #ff4d6a; }
        .hq-flash { border-radius: 14px; background: rgba(10, 22, 44, .82); border: 1px solid var(--glass-border); color: var(--text-primary); }
        .hq-flash.alert-success { border-color: rgba(27, 228, 148, .4); }
        .hq-flash.alert-danger { border-color: rgba(255, 77, 106, .45); }
        .hq-credential-box { border: 1px solid rgba(0, 244, 255, .2); border-radius: 12px; background: rgba(0, 244, 255, .06); }
        .hq-detail-label { color: var(--text-muted); font-size: .72rem; text-transform: uppercase; letter-spacing: .07em; }
        .hq-detail-value { color: var(--text-primary); word-break: break-word; }
        .hq-modal-table { --bs-table-bg: transparent; --bs-table-color: var(--text-primary); --bs-table-border-color: var(--glass-border); }
        .hq-modal-table th { color: var(--text-muted); font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; }
        body.background_color.light-mode .hq-flash { background: rgba(255,255,255,.9); }
        body.background_color.light-mode .hq-status { color: var(--text-muted); }
    </style>
</head>
<body class="background_color" id="background_color">
    <div class="page-load-bar"></div>

    <div class="container sticky-nav-wrapper">
        <nav class="navbar navbar-dark text-white navigation_style">
            <div class="container-fluid d-flex justify-content-between align-items-center p-0 gap-3">
                <a class="navbar-brand d-flex align-items-center gap-2 fw-semibold txt-neon-cyan m-0" href="admin_dashboard.php" style="font-size: 0.95rem;">
                    <img src="/images/Logo.png" alt="Logo" class="nav-logo">
                    <span class="d-none d-sm-inline">Admin Portal</span>
                </a>
                <div class="admin-nav-actions">
                    <label class="cosmic-toggle nav-theme-toggle" title="Toggle light mode">
                        <input class="toggle js-theme-toggle" type="checkbox" aria-label="Toggle light mode">
                        <div class="slider">
                            <span class="theme-icon theme-icon-dark"><i class="bi bi-moon-stars-fill"></i></span>
                            <span class="theme-icon theme-icon-light"><i class="bi bi-sun-fill"></i></span>
                            <div class="cosmos"></div>
                            <div class="toggle-orb"><div class="inner-orb"></div><div class="ring"></div></div>
                        </div>
                    </label>
                    <button class="btn btn-sm menu-pill-btn" type="button" data-bs-toggle="offcanvas" data-bs-target="#mainMenuOffcanvas">
                        <i class="bi bi-grid-fill me-1 txt-neon-cyan"></i> Menu
                    </button>
                </div>
            </div>
        </nav>
    </div>

    <main class="container my-5">
        <?php if ($flash && $flashType): ?>
            <div class="alert hq-flash alert-<?= hq_escape($flashType) ?> alert-dismissible fade show mb-4" role="alert">
                <div class="d-flex gap-2 align-items-start">
                    <i class="bi <?= $flashType === 'success' ? 'bi-check-circle-fill text-success' : 'bi-exclamation-triangle-fill text-danger' ?> mt-1"></i>
                    <div class="flex-grow-1">
                        <div><?= hq_escape($flash['message'] ?? '') ?></div>
                        <?php if ($flashType === 'success' && !empty($flashContext['temporary_password'])): ?>
                            <div class="hq-credential-box p-3 mt-3 small">
                                <div class="fw-semibold txt-neon-cyan mb-1">Temporary login credentials — save these now</div>
                                <div>Username: <code><?= hq_escape($flashContext['username'] ?? '') ?></code></div>
                                <div>Temporary password: <code><?= hq_escape($flashContext['temporary_password']) ?></code></div>
                                <div class="text-white-50 mt-2">Email: <?= hq_escape($flashContext['email'] ?? '') ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <span class="stat-chip mb-2"><i class="bi bi-building-fill txt-neon-cyan"></i> View Registry</span>
                <h1 class="fw-bold m-0 h4 text-white mt-2">View HQ</h1>
                <p class="text-white-50 m-0 small mt-1">View only role 3 HQ accounts and their HQ details from the live database.</p>
            </div>
        </div>

        <?php if ($dbError): ?>
            <div class="alert hq-flash alert-danger mb-4" role="alert">
                <i class="bi bi-database-exclamation me-2"></i><?= hq_escape($dbError) ?>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-12">
                <div class="dashboard-card py-3 px-4">
                    <form class="row g-3 align-items-center" method="GET" action="view_hq_and_user_account.php">
                        <div class="col-12 col-md-5">
                            <div class="input-group">
                                <span class="input-group-text bg-transparent border-end-0" style="border-color: var(--glass-border); color: var(--neon-cyan); border-radius: 12px 0 0 12px;"><i class="bi bi-search"></i></span>
                                <input type="text" class="form-control glass-input border-start-0" placeholder="Search by HQ or owner name..." name="search" value="<?= hq_escape($search) ?>">
                            </div>
                        </div>
                        <div class="col-12 col-md-3">
                            <select class="form-select glass-select" name="region">
                                <option value="" <?= $region === '' ? 'selected' : '' ?>>All Regions</option>
                                <?php foreach ($allowedRegions as $option): ?>
                                    <option value="<?= $option ?>" <?= $region === $option ? 'selected' : '' ?>><?= hq_escape($regionLabel($option)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-2">
                            <select class="form-select glass-select" name="status">
                                <option value="" <?= $status === '' ? 'selected' : '' ?>>All Status</option>
                                <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending Verification</option>
                                <option value="suspended" <?= $status === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                                <option value="removed" <?= $status === 'removed' ? 'selected' : '' ?>>Removed</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-2 d-grid"><button type="submit" class="btn btn-neon">Apply Filter</button></div>
                    </form>
                </div>
            </div>

            <div class="col-12">
                <div class="analytics-card">
                    <div class="table-responsive">
                        <table class="table table-dark-custom align-middle m-0">
                            <thead>
                                <tr><th>HQ Entity &amp; Owner</th><th>Role</th><th>Headquarters Location</th><th>Status</th><th class="text-end">Command</th></tr>
                            </thead>
                            <tbody>
                                <?php if (!$hqRows): ?>
                                    <tr><td colspan="5" class="text-center text-white-50 py-5"><i class="bi bi-building-x fs-3 d-block mb-2"></i>No HQ accounts match the current filter.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($hqRows as $row): ?>
                                    <?php
                                    $companyName = (string) ($row['company_name'] ?? 'Unnamed HQ');
                                    $ownerName = (string) ($row['owner_name'] ?? 'No owner account');
                                    $ownerEmail = (string) ($row['owner_email'] ?? 'No email available');
                                    $rowStatus = (string) ($row['account_status'] ?? 'pending');
                                    $locationParts = explode(' — ', (string) ($row['location'] ?? ''), 2);
                                    $locationRegion = $locationParts[0] ?? 'Location not available';
                                    $locationAddress = $locationParts[1] ?? ($locationParts[0] ?? 'Location not available');
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center gap-3">
                                                <div class="rounded-3 d-flex align-items-center justify-content-center text-white fw-bold" style="width:44px;height:44px;background:rgba(0,244,255,.1);border:1px solid rgba(0,244,255,.28);flex-shrink:0;font-size:.85rem;color:var(--neon-cyan)!important;">
                                                    <?= hq_escape(hq_initials($companyName)) ?>
                                                </div>
                                                <div>
                                                    <div class="fw-semibold text-white"><?= hq_escape($companyName) ?></div>
                                                    <div class="text-white-50 small" style="font-size:.8rem;">Owner: <?= hq_escape($ownerName) ?> (<?= hq_escape($ownerEmail) ?>)</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-white-50">#3 &middot; HQ</td>
                                        <td><div class="text-white"><?= hq_escape($locationRegion) ?></div><div class="text-white-50 small" style="font-size:.8rem;"><?= hq_escape($locationAddress) ?></div></td>
                                        <td><span class="hq-status <?= hq_status_class($rowStatus) ?>"><?= hq_escape(hq_status_label($rowStatus)) ?></span></td>
                                        <td class="text-end">
                                            <div class="d-flex gap-2 justify-content-end">
                                                <a href="#" class="btn-action js-view-hq" title="View Stations &amp; Users" aria-label="View <?= hq_escape($companyName) ?> details" data-hq-id="<?= (int) $row['headquarter_id'] ?>"><i class="bi bi-eye-fill"></i></a>
                                                <a href="#" class="btn-action btn-action-danger js-account-action" title="Suspend/Remove" aria-label="Suspend or remove <?= hq_escape($companyName) ?>" data-hq-id="<?= (int) $row['headquarter_id'] ?>" data-hq-name="<?= hq_escape($companyName) ?>"><i class="bi bi-trash3-fill"></i></a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <div class="modal fade glass-modal" id="accountActionModal" tabindex="-1" aria-labelledby="accountActionModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title fw-bold" id="accountActionModalLabel"><i class="bi bi-shield-exclamation text-danger me-2"></i> Suspend or Remove HQ</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <form action="hq_account_action.php" method="POST">
                    <div class="modal-body d-flex flex-column gap-3">
                        <input type="hidden" name="headquarter_id" id="actionHQId">
                        <div class="small text-white-50">Target HQ: <span class="txt-neon-cyan fw-semibold" id="actionHQName">—</span></div>
                        <div><label class="form-label text-white-50 small" for="actionType">Account action</label><select class="form-select glass-select" name="action_type" id="actionType" required><option value="suspend">Suspend account</option><option value="remove">Remove account</option></select></div>
                        <div><label class="form-label text-white-50 small" for="actionReason">Reason to record and notify</label><textarea class="form-control glass-input" name="reason" id="actionReason" rows="5" maxlength="2000" placeholder="Explain why this HQ account is being suspended or removed..." required style="resize:none;"></textarea></div>
                        <div class="small text-white-50"><i class="bi bi-envelope me-1 txt-neon-cyan"></i>The reason is saved to the HQ action history. Email delivery is currently a clearly marked placeholder until SMTP/provider settings are added.</div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-3 text-white-50 border-secondary border-opacity-20" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger" id="actionSubmit">Confirm Suspend</button></div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade glass-modal" id="hqDetailsModal" tabindex="-1" aria-labelledby="hqDetailsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title fw-bold" id="hqDetailsModalLabel"><i class="bi bi-building txt-neon-cyan me-2"></i> HQ Details</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <div class="modal-body" id="hqDetailsBody"><div class="text-center text-white-50 py-5"><div class="spinner-border spinner-border-sm txt-neon-cyan mb-2" role="status"></div><div>Loading HQ details…</div></div></div>
            </div>
        </div>
    </div>

    <div class="offcanvas offcanvas-end" tabindex="-1" id="mainMenuOffcanvas" style="width:320px;background:rgba(6,12,24,.98);backdrop-filter:blur(28px);border-left:1px solid rgba(0,180,255,.18);">
        <div class="offcanvas-header pb-2 border-bottom border-white border-opacity-10"><div><h5 class="offcanvas-title text-white fw-bold mb-0">Main Menu</h5><p class="text-white-50 small mb-0" style="font-size:.78rem;">Admin Portal</p></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button></div>
        <div class="offcanvas-body pt-3"><ul class="list-unstyled d-flex flex-column gap-2">
            <li><a href="admin_dashboard.php" class="menu-btn-nav"><i class="bi bi-house me-3 txt-neon-cyan"></i> Home</a></li>
            <li><a href="view_hq_and_user_account.php" class="menu-btn-nav active-nav"><i class="bi bi-building-gear me-3 txt-neon-cyan"></i> View HQ</a></li>
            <li><a href="manage_hq.php" class="menu-btn-nav"><i class="bi bi-buildings me-3 txt-neon-cyan"></i> Manage HQ</a></li>
            <li><a href="view_users.php" class="menu-btn-nav"><i class="bi bi-people me-3 txt-neon-cyan"></i> View Users</a></li>
            <li><a href="manage_users.php" class="menu-btn-nav"><i class="bi bi-person-gear me-3 txt-neon-cyan"></i> Manage Users</a></li>
            <li><a href="view_requests.php" class="menu-btn-nav"><i class="bi bi-file-earmark-check me-3 txt-neon-cyan"></i> View Requests</a></li>
            <li><a href="view_report.php" class="menu-btn-nav"><i class="bi bi-graph-up-arrow me-3 txt-neon-cyan"></i> View Report</a></li>
            <li><a href="edit_profile.php" class="menu-btn-nav"><i class="bi bi-person-bounding-box me-3 txt-neon-blue"></i> Edit Profile</a></li>
            <li><a href="manage_moderators.php" class="menu-btn-nav"><i class="bi bi-people-fill me-3 txt-neon-blue"></i> Manage Moderators</a></li>
            <li><a href="view_logs.php" class="menu-btn-nav"><i class="bi bi-terminal-split me-3 text-danger"></i> View Log Files</a></li>
            <hr style="border-color:rgba(255,255,255,.08);margin:4px 0;">
            <li><form action="../Login/logout.php" method="post" class="m-0"><button type="submit" class="logout-btn-nav btn border-0 w-100"><i class="bi bi-box-arrow-right me-2"></i> Logout</button></form></li>
        </ul></div>
    </div>

    <footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map &amp; Pump | All Rights Reserved</footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/js/Admin.js"></script>
    <script>
        (() => {
            const actionModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('accountActionModal'));
            const detailsModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('hqDetailsModal'));
            const actionId = document.getElementById('actionHQId');
            const actionName = document.getElementById('actionHQName');
            const actionType = document.getElementById('actionType');
            const actionSubmit = document.getElementById('actionSubmit');
            const detailsTitle = document.getElementById('hqDetailsModalLabel');
            const detailsBody = document.getElementById('hqDetailsBody');

            const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (character) => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[character]));
            const formatDate = (value) => value ? new Date(String(value).replace(' ', 'T')).toLocaleString() : 'Not available';
            const statusClass = (status) => ({active:'hq-status-active', suspended:'hq-status-suspended', removed:'hq-status-removed'}[String(status || '').toLowerCase()] || 'hq-status-pending');
            const statusLabel = (status) => ({active:'Active', suspended:'Suspended', removed:'Removed', pending:'Pending Verification', pending_verification:'Pending Verification'}[String(status || '').toLowerCase()] || 'Pending Verification');
            const removeUserSections = () => { detailsBody.querySelectorAll('h6, .hq-detail-label').forEach((heading) => { if (/linked\s+user|linked\s+account/i.test(heading.textContent || '')) { heading.nextElementSibling?.remove(); heading.remove(); } }); };
            new MutationObserver(removeUserSections).observe(detailsBody, {childList:true, subtree:true});

            actionType.addEventListener('change', () => {
                const remove = actionType.value === 'remove';
                actionSubmit.textContent = remove ? 'Confirm Remove' : 'Confirm Suspend';
                actionSubmit.classList.toggle('btn-danger', true);
            });

            document.querySelectorAll('.js-account-action').forEach((button) => {
                button.addEventListener('click', (event) => {
                    event.preventDefault();
                    actionId.value = button.dataset.hqId || '';
                    actionName.textContent = button.dataset.hqName || 'Selected HQ';
                    actionType.value = 'suspend';
                    actionSubmit.textContent = 'Confirm Suspend';
                    document.getElementById('actionReason').value = '';
                    actionModal.show();
                });
            });

            const renderDetails = (payload) => {
                const hq = payload.hq || {};
                const accounts = payload.accounts || [];
                const stations = payload.stations || [];
                const actions = payload.actions || [];
                detailsTitle.innerHTML = '<i class="bi bi-building txt-neon-cyan me-2"></i>' + escapeHtml(hq.company_name || 'HQ Details');
                const accountRows = accounts.length ? accounts.map((account) => '<tr><td>' + escapeHtml(account.username || '—') + '</td><td>' + escapeHtml(account.email || '—') + '</td><td><span class="hq-status ' + statusClass(account.status) + '">' + escapeHtml(statusLabel(account.status)) + '</span></td><td>' + escapeHtml(formatDate(account.created_at)) + '</td></tr>').join('') : '<tr><td colspan="4" class="text-center text-white-50">No user accounts are linked.</td></tr>';
                const stationRows = stations.length ? stations.map((station) => '<tr><td>' + escapeHtml(station.station_name || '—') + '</td><td>' + escapeHtml(station.location || '—') + '</td><td>' + escapeHtml(formatDate(station.created_at)) + '</td></tr>').join('') : '<tr><td colspan="3" class="text-center text-white-50">No station is linked in the current database schema.</td></tr>';
                const actionRows = actions.length ? actions.map((action) => '<tr><td>' + escapeHtml(String(action.action_type || '').toUpperCase()) + '</td><td>' + escapeHtml(action.reason || '—') + '</td><td>' + escapeHtml(formatDate(action.created_at)) + '</td></tr>').join('') : '<tr><td colspan="3" class="text-center text-white-50">No suspend/remove history.</td></tr>';
                detailsBody.innerHTML = '<div class="row g-3 mb-4"><div class="col-12 col-md-6"><div class="hq-detail-label">HQ location</div><div class="hq-detail-value">' + escapeHtml(hq.location || 'Not available') + '</div></div><div class="col-6 col-md-3"><div class="hq-detail-label">Contact</div><div class="hq-detail-value">' + escapeHtml(hq.contact_number || 'Not available') + '</div></div><div class="col-6 col-md-3"><div class="hq-detail-label">Registered</div><div class="hq-detail-value">' + escapeHtml(formatDate(hq.created_at)) + '</div></div></div><h6 class="text-white fw-semibold mb-2"><i class="bi bi-people-fill txt-neon-cyan me-2"></i>Linked user accounts</h6><div class="table-responsive mb-4"><table class="table hq-modal-table align-middle"><thead><tr><th>Username</th><th>Email</th><th>Status</th><th>Created</th></tr></thead><tbody>' + accountRows + '</tbody></table></div><h6 class="text-white fw-semibold mb-2"><i class="bi bi-fuel-pump-fill txt-neon-cyan me-2"></i>Linked stations</h6><div class="table-responsive mb-4"><table class="table hq-modal-table align-middle"><thead><tr><th>Station</th><th>Location</th><th>Created</th></tr></thead><tbody>' + stationRows + '</tbody></table></div><h6 class="text-white fw-semibold mb-2"><i class="bi bi-clock-history txt-neon-cyan me-2"></i>Account action history</h6><div class="table-responsive"><table class="table hq-modal-table align-middle"><thead><tr><th>Action</th><th>Reason</th><th>Recorded</th></tr></thead><tbody>' + actionRows + '</tbody></table></div>';
            };

            const renderDetailsHQOnly = (payload) => {
                const hq = payload.hq || {};
                const actions = payload.actions || [];
                const actionRows = actions.length ? actions.map((action) => '<tr><td>' + escapeHtml(String(action.action_type || '').toUpperCase()) + '</td><td>' + escapeHtml(action.reason || '—') + '</td><td>' + escapeHtml(formatDate(action.created_at)) + '</td></tr>').join('') : '<tr><td colspan="3" class="text-center text-white-50">No HQ suspend/remove history.</td></tr>';
                detailsTitle.innerHTML = '<i class="bi bi-building txt-neon-cyan me-2"></i>' + escapeHtml(hq.company_name || 'HQ Details');
                detailsBody.innerHTML = '<div class="row g-3 mb-4"><div class="col-12"><div class="hq-detail-label">HQ location</div><div class="hq-detail-value">' + escapeHtml(hq.location || 'Not available') + '</div></div><div class="col-6 col-md-3"><div class="hq-detail-label">Contact</div><div class="hq-detail-value">' + escapeHtml(hq.contact_number || 'Not available') + '</div></div><div class="col-6 col-md-3"><div class="hq-detail-label">Email</div><div class="hq-detail-value">' + escapeHtml(hq.headquarter_email || 'Not available') + '</div></div><div class="col-6 col-md-3"><div class="hq-detail-label">Registered</div><div class="hq-detail-value">' + escapeHtml(formatDate(hq.created_at)) + '</div></div><div class="col-6 col-md-3"><div class="hq-detail-label">License expiry</div><div class="hq-detail-value">' + escapeHtml(formatDate(hq.expire_at)) + '</div></div></div><h6 class="text-white fw-semibold mb-2"><i class="bi bi-clock-history txt-neon-cyan me-2"></i>HQ action history</h6><div class="table-responsive"><table class="table hq-modal-table align-middle"><thead><tr><th>Action</th><th>Reason</th><th>Recorded</th></tr></thead><tbody>' + actionRows + '</tbody></table></div>';
            };

            document.querySelectorAll('.js-view-hq').forEach((button) => {
                button.addEventListener('click', async (event) => {
                    event.preventDefault();
                    detailsTitle.innerHTML = '<i class="bi bi-building txt-neon-cyan me-2"></i> HQ Details';
                    detailsBody.innerHTML = '<div class="text-center text-white-50 py-5"><div class="spinner-border spinner-border-sm txt-neon-cyan mb-2" role="status"></div><div>Loading HQ details…</div></div>';
                    detailsModal.show();
                    try {
                        const response = await fetch('hq_details.php?id=' + encodeURIComponent(button.dataset.hqId), {headers: {Accept: 'application/json'}});
                        const payload = await response.json();
                        if (!response.ok || !payload.success) throw new Error(payload.message || 'Could not load HQ details.');
                        renderDetailsHQOnly(payload);
                    } catch (error) {
                        detailsBody.innerHTML = '<div class="alert hq-flash alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>' + escapeHtml(error.message) + '</div>';
                    }
                });
            });
        })();
    </script>
    <script>document.addEventListener('pointermove', (event) => { document.body.style.setProperty('--bg-x', `${(event.clientX / window.innerWidth) * 100}%`); document.body.style.setProperty('--bg-y', `${(event.clientY / window.innerHeight) * 100}%`); });</script>
</body>
</html>
