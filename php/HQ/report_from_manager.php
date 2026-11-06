<?php
require_once __DIR__ . '/hq_guard.php';
require_once __DIR__ . '/../conn.php';

$reports = [];
$logs = [];
$managerRoleId = 4;
$headquarterId = map_pump_current_hq_id($pdo, (int) ($_SESSION['user_id'] ?? 0));
$todayDate = (new DateTimeImmutable('now'))->format('Y-m-d');
$hqCreatedDate = $todayDate;
$startDate = trim((string) ($_GET['start_date'] ?? ''));
$endDate = trim((string) ($_GET['end_date'] ?? ''));
$managerActivityHasOverflow = false;

try {
    if ($headquarterId <= 0) {
        throw new RuntimeException('No HQ scope is available for this account.');
    }

    $hqCreatedStmt = $pdo->prepare('SELECT DATE(created_at) FROM headquarter WHERE headquarter_id = ? LIMIT 1');
    $hqCreatedStmt->execute([$headquarterId]);
    $hqCreatedValue = $hqCreatedStmt->fetchColumn();
    if (is_string($hqCreatedValue) && $hqCreatedValue !== '') {
        $hqCreatedDate = $hqCreatedValue;
    }

    $validDatePattern = '/^\d{4}-\d{2}-\d{2}$/';
    $normalizeDate = function (string $rawDate, string $minDate, string $maxDate): string {
        $rawDate = trim($rawDate);
        if ($rawDate === '') {
            return '';
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate)) {
            return $minDate;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $rawDate);
        if ($date === false) {
            return $minDate;
        }

        $normalizedDate = $date->format('Y-m-d');
        if ($normalizedDate < $minDate) {
            return $minDate;
        }
        if ($normalizedDate > $maxDate) {
            return $maxDate;
        }

        return $normalizedDate;
    };

    $startDate = $normalizeDate($startDate, $hqCreatedDate, $todayDate);
    $endDate = $normalizeDate($endDate, $hqCreatedDate, $todayDate);

    if ($startDate !== '' && $endDate !== '' && $startDate > $endDate) {
        [$startDate, $endDate] = [$endDate, $startDate];
    }

    $reportSql =
        'SELECT r.*, u.user_name
        FROM report r
        LEFT JOIN users u ON u.user_id = r.user_id
        WHERE ' . map_pump_manager_report_scope_sql();
    $reportParams = [$managerRoleId, $managerRoleId, $managerRoleId, $headquarterId, $headquarterId];
    if ($startDate !== '') {
        $reportSql .= ' AND DATE(r.created_at) >= ?';
        $reportParams[] = $startDate;
    }
    if ($endDate !== '') {
        $reportSql .= ' AND DATE(r.created_at) <= ?';
        $reportParams[] = $endDate;
    }
    $reportSql .=
        ' ORDER BY CASE
                   WHEN LOWER(TRIM(COALESCE(r.importance, \'\'))) = \'high\'
                    AND r.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                   THEN 0 ELSE 1
                 END,
                 r.created_at DESC
        LIMIT 5';
    $stmt = $pdo->prepare($reportSql);
    $stmt->execute($reportParams);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $logSql =
        'SELECT l.log_id, l.user_id, l.action, l.created_at, u.user_name
         FROM log l
         LEFT JOIN users u ON u.user_id = l.user_id
         LEFT JOIN business_account_scope bas ON bas.user_id = u.user_id
         WHERE (l.role_id = ? OR (l.role_id IS NULL AND (u.role_id = ? OR u.business_role_id = ? OR u.current_role_id = ?)))
           AND (
             bas.headquarter_id = ?
                OR EXISTS (
                    SELECT 1
                    FROM station_business_scope sbs
                    WHERE sbs.branch_manager_user_id = u.user_id
                      AND sbs.headquarter_id = ?
                )
           )';
    $logParams = [$managerRoleId, $managerRoleId, $managerRoleId, $managerRoleId, $headquarterId, $headquarterId];

    if ($startDate !== '') {
        $logSql .= ' AND DATE(l.created_at) >= ?';
        $logParams[] = $startDate;
    }
    if ($endDate !== '') {
        $logSql .= ' AND DATE(l.created_at) <= ?';
        $logParams[] = $endDate;
    }

    $logSql .= ' ORDER BY l.created_at DESC';

    $logStmt = $pdo->prepare($logSql);
    $logStmt->execute($logParams);
    $rawLogs = $logStmt->fetchAll(PDO::FETCH_ASSOC);
    $logs = $rawLogs;
} catch (Throwable $e) {
    error_log('Failed to load manager activities: ' . $e->getMessage());
    $reports = [];
    $logs = [];
    $managerActivityHasOverflow = false;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <script src="../../js/hq-theme.js"></script>
<link rel="stylesheet" href="../../css/HQstyle.css">
    <title>Manager Reports & Activity</title>
    <style>
        /* Report search bar styling (matches pricing/station search bars) */
        .report-search {
            position: relative;
            flex: 0 0 auto;
            width: 260px;
            max-width: 100%;
        }

        .report-search .report-search-icon {
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--slate-300);
            font-size: 0.82rem;
            line-height: 1;
            opacity: 0.85;
            pointer-events: none;
        }

        .report-search #reportSearch {
            display: block;
            width: 100%;
            height: 36px;
            padding: 0 66px 0 34px;
            font-size: 0.85rem;
            color: var(--slate-50);
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: 999px;
            box-shadow: none;
            outline: none;
            appearance: none;
            -webkit-appearance: none;
            box-sizing: border-box;
        }

        .report-search #reportSearch::placeholder {
            color: var(--slate-300);
            opacity: 0.7;
        }

        .report-search #reportSearch:focus {
            background: rgba(255, 255, 255, 0.08);
            border-color: rgba(56, 189, 248, 0.45);
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.16);
        }

        .report-search #reportSearch::-webkit-search-cancel-button,
        .report-search #reportSearch::-webkit-search-decoration {
            -webkit-appearance: none;
            appearance: none;
        }

        .report-search .report-search-clear {
            position: absolute;
            right: 34px;
            top: 50%;
            transform: translateY(-50%);
            display: none;
            width: 22px;
            height: 22px;
            padding: 0;
            border: none;
            border-radius: 50%;
            background: transparent;
            color: var(--slate-300);
            font-size: 0.7rem;
            line-height: 1;
            cursor: pointer;
        }

        .report-search .report-search-clear:hover {
            color: var(--slate-50);
            background: rgba(255, 255, 255, 0.08);
        }

        .report-search.has-value .report-search-clear {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Manager activity log (monospace audit-trail lines) */
        .manager-activity-row {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
            font-size: 0.9rem;
            color: var(--slate-50);
            padding: 12px 12px;
            border-bottom: 1px solid var(--border);
            word-break: break-word;
        }

        .manager-activity-log {
            max-height: calc(10 * 42px + 2px);
            overflow-y: auto;
            overflow-x: hidden;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: rgba(15, 23, 42, 0.08);
        }

        .manager-activity-log::-webkit-scrollbar {
            width: 10px;
        }

        .manager-activity-log::-webkit-scrollbar-track {
            background: transparent;
        }

        .manager-activity-log::-webkit-scrollbar-thumb {
            background: rgba(148, 163, 184, 0.45);
            border-radius: 999px;
            border: 2px solid transparent;
            background-clip: content-box;
        }

        .manager-activity-log::-webkit-scrollbar-thumb:hover {
            background: rgba(148, 163, 184, 0.7);
            border-radius: 999px;
        }
    </style>
</head>
<body>
    <div class="container my-4">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; margin-bottom:8px;">
            <h2 class="title" style="margin:0;">Manager Reports & Activity</h2>
            <form method="GET" id="dateRangeFilter" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin:0;">
                <label style="display:flex; align-items:center; gap:6px; font-size:12px; color:var(--slate-300);">
                    <span>From</span>
                    <input type="date" name="start_date" value="<?= htmlspecialchars($startDate, ENT_QUOTES, 'UTF-8') ?>" min="<?= htmlspecialchars($hqCreatedDate, ENT_QUOTES, 'UTF-8') ?>" max="<?= htmlspecialchars($todayDate, ENT_QUOTES, 'UTF-8') ?>" style="background:rgba(255,255,255,0.04); border:1px solid var(--border); border-radius:8px; padding:7px 10px; color:var(--slate-50);">
                </label>
                <label style="display:flex; align-items:center; gap:6px; font-size:12px; color:var(--slate-300);">
                    <span>To</span>
                    <input type="date" name="end_date" value="<?= htmlspecialchars($endDate, ENT_QUOTES, 'UTF-8') ?>" min="<?= htmlspecialchars($hqCreatedDate, ENT_QUOTES, 'UTF-8') ?>" max="<?= htmlspecialchars($todayDate, ENT_QUOTES, 'UTF-8') ?>" style="background:rgba(255,255,255,0.04); border:1px solid var(--border); border-radius:8px; padding:7px 10px; color:var(--slate-50);">
                </label>
                <button type="submit" class="btn btn-sm btn-primary" style="padding:8px 12px;">Apply</button>
                <a href="report_from_manager.php" class="btn btn-sm btn-outline-light" style="padding:8px 12px;">All</a>
            </form>
        </div>
        <p class="subtitle">Review operational data, volume logs, and localized site edits from TiDB Cloud.</p>

        <?php
if (isset($dbError)): ?>
            <div class="alert alert-danger" role="alert">
                Database Connection Error: <?= htmlspecialchars($dbError) ?>
            </div>
        <?php
endif; ?>

        <div class="card mb-4 p-3">
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap;">
                <div>
                    <h3 style="margin: 0;">Manager Reports</h3>
                    <p class="subtitle" style="margin-bottom: 0;">Review reports submitted by your station managers.</p>
                </div>
                <?php if (!empty($reports)): ?>
                <div class="report-search" id="reportSearchWrap">
                    <i class="fa-solid fa-magnifying-glass report-search-icon" aria-hidden="true"></i>
                    <input type="search" id="reportSearch" class="form-control" placeholder="Search reports..." autocomplete="off" aria-label="Search reports by ID, user, importance, summary or status">
                    <button type="button" class="report-search-clear" id="reportSearchClear" title="Clear search" aria-label="Clear search">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </div>
                <?php endif; ?>
            </div>

            <?php
if (empty($reports)): ?>
                <div style="background: var(--bg); padding: 15px; border-radius: 6px; font-size: 0.9rem; color: var(--slate-50); text-align: center;">
                    No manager reports received yet.
                </div>
            <?php
else: ?>
                <div class="table-responsive">
                    <table id="reportsTable" class="table table-dark table-striped">
                        <thead>
                            <tr>
                                <th>Report ID</th>
                                <th>User</th>
                                <th>Importance</th>
                                <th>Summary</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
foreach ($reports as $rep): ?>
                                <tr id="report-<?= htmlspecialchars($rep['report_id']) ?>" class="report-row" data-search="<?= htmlspecialchars(strtolower(trim(
                                        $rep['report_id'] . ' ' .
                                        ($rep['user_name'] ?? '') . ' ' .
                                        ($rep['importance'] ?? '') . ' ' .
                                        ($rep['short_summery'] ?? '') . ' ' .
                                        'unread'
                                    )), ENT_QUOTES) ?>" onclick="showDetailFromRow(this)" style="cursor: pointer;">
                                    <td><?= htmlspecialchars($rep['report_id']) ?></td>
                                    <td><?= htmlspecialchars($rep['user_name']) ?></td>
                                    <td><?= htmlspecialchars($rep['importance']) ?></td>
                                    <td><?= htmlspecialchars($rep['short_summery']) ?></td>
                                    <td>
                                        <span class="badge bg-warning text-dark status-badge" data-status="unread">Unread</span>
                                    </td>
                                    <td onclick="event.stopPropagation();">
                                        <button onclick="toggleActions(this, <?= htmlspecialchars($rep['report_id']) ?>)" title="More Actions" style="border: none; background: none; color: #6c757d; cursor: pointer; font-size: 1.5rem; box-shadow: none; padding: 5px;">
                                            <i class="bi bi-three-dots-vertical"></i>
                                        </button>
                                        <div class="action-menu" id="menu-<?= htmlspecialchars($rep['report_id']) ?>" style="display: none; position: absolute; background: var(--card-bg); border: 1px solid var(--border); border-radius: 5px; padding: 8px; min-width: 180px; z-index: 1000;">
                                            <button onclick="event.stopPropagation(); toggleReadStatus(this, <?= htmlspecialchars($rep['report_id']) ?>)" style="border: none; background: none; color: #0d6efd; cursor: pointer; width: 100%; text-align: left; padding: 8px; display: flex; align-items: center; gap: 10px;">
                                                <i class="bi bi-envelope" style="font-size: 1rem;"></i> Mark as Read
                                            </button>
                                            <button onclick="event.stopPropagation(); showDetail(this)" data-report-id="<?= htmlspecialchars($rep['report_id']) ?>" data-user="<?= htmlspecialchars($rep['user_name']) ?>" data-summary="<?= htmlspecialchars($rep['short_summery']) ?>" data-description="<?= htmlspecialchars($rep['message'] ?? '') ?>" data-status="unread" style="border: none; background: none; color: #0d6efd; cursor: pointer; width: 100%; text-align: left; padding: 8px; display: flex; align-items: center; gap: 10px;">
                                                <i class="bi bi-eye" style="font-size: 1rem;"></i> See Detail
                                            </button>
                                            <button onclick="event.stopPropagation(); deleteReport(this, <?= htmlspecialchars($rep['report_id']) ?>)" style="border: none; background: none; color: #dc3545; cursor: pointer; width: 100%; text-align: left; padding: 8px; display: flex; align-items: center; gap: 10px;">
                                                <i class="bi bi-trash" style="font-size: 1rem;"></i> Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php
endforeach; ?>
                            <tr id="reportsNoResults" class="d-none">
                                <td colspan="6" class="text-center text-muted">No reports match your search.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            <?php
endif; ?>
        </div>

        <div class="card p-3">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; margin-bottom:12px;">
                <div>
                    <h3 style="margin: 0;">Recent Manager Activity</h3>
                    <p class="subtitle" style="margin-bottom: 0;">Audit trail of branch-manager actions within your HQ scope.</p>
                </div>
            </div>

            <div class="manager-activity-log">
                <?php if (empty($logs)): ?>
                    <div style="text-align:center; color:var(--slate-400); padding:18px;">No manager activity is recorded for the selected date range.</div>
                <?php else: foreach ($logs as $log): ?>
                    <div class="manager-activity-row">[<?= htmlspecialchars($log['created_at']) ?>] USER <?= htmlspecialchars($log['user_name'] ?? 'Unknown Manager') ?>: <?= htmlspecialchars($log['action'] ?? 'No action detail') ?></div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <!-- Detail Modal -->
    <div class="modal fade" id="detailModal" tabindex="-1" aria-labelledby="detailModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content" style="background: var(--bg, #1e1e1e); border: 1px solid var(--border, #495057); color: var(--slate-50);">
                <div class="modal-header" style="border-bottom: 1px solid var(--border, #495057);">
                    <h5 class="modal-title" id="detailModalLabel" style="color: var(--slate-50);">Report Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p style="color: var(--slate-50);"><strong>User:</strong> <span id="modalUser" style="color: var(--slate-300);"></span></p>
                    <p style="color: var(--slate-50);"><strong>Summary:</strong> <span id="modalSummary" style="color: var(--slate-300);"></span></p>
                    <p style="color: var(--slate-50);"><strong>Status:</strong> <span id="modalStatus" class="badge" style="margin-left: 8px;"></span></p>
                    <p style="color: var(--slate-50);"><strong>Description:</strong></p>
                    <p id="modalDescription" style="background: var(--bg, #2d2d2d); padding: 10px; border-radius: 5px; border: 1px solid var(--border, #495057); color: var(--slate-300);"></p>
                </div>
                <div class="modal-footer" style="border-top: 1px solid var(--border, #495057);">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" id="modalToggleStatusBtn" onclick="handleModalToggleStatus()" style="display: none;"></button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function setupDateRangeFilter(formId, startName, endName) {
            const filterForm = document.getElementById(formId);
            const startInput = filterForm?.querySelector(`input[name="${startName}"]`);
            const endInput = filterForm?.querySelector(`input[name="${endName}"]`);
            if (!startInput || !endInput) return;

            const minDate = startInput.min || '';
            const maxDate = startInput.max || '';

            function clampValue(input) {
                const value = input.value;
                if (!value) return;
                if (minDate && value < minDate) input.value = minDate;
                if (maxDate && value > maxDate) input.value = maxDate;
            }

            startInput.addEventListener('change', function () {
                clampValue(startInput);
                if (endInput.value && startInput.value > endInput.value) {
                    endInput.value = startInput.value;
                }
                clampValue(endInput);
            });

            endInput.addEventListener('change', function () {
                clampValue(endInput);
                if (startInput.value && endInput.value < startInput.value) {
                    startInput.value = endInput.value;
                }
                clampValue(startInput);
            });
        }

        setupDateRangeFilter('dateRangeFilter', 'start_date', 'end_date');

        // Live search filter for the site reports table
        (function () {
            const input = document.getElementById('reportSearch');
            const clearBtn = document.getElementById('reportSearchClear');
            const searchWrap = document.getElementById('reportSearchWrap');
            if (!input) return;

            const rows = Array.prototype.slice.call(document.querySelectorAll('.report-row'));
            const noResults = document.getElementById('reportsNoResults');

            function updateClearButton() {
                if (input.value.trim() !== '') {
                    searchWrap.classList.add('has-value');
                } else {
                    searchWrap.classList.remove('has-value');
                }
            }

            function applyFilter() {
                const term = input.value.trim().toLowerCase();
                let visible = 0;

                rows.forEach(function (row) {
                    const haystack = row.dataset.search || row.textContent.toLowerCase();
                    const match = term === '' || haystack.indexOf(term) !== -1;
                    row.classList.toggle('d-none', !match);
                    if (match) visible++;
                });

                if (noResults) {
                    noResults.classList.toggle('d-none', !(rows.length > 0 && visible === 0));
                }
                updateClearButton();
            }

            input.addEventListener('input', applyFilter);
            input.addEventListener('search', applyFilter);
            input.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    input.value = '';
                    applyFilter();
                }
            });
            if (clearBtn) {
                clearBtn.addEventListener('click', function () {
                    input.value = '';
                    applyFilter();
                    input.focus();
                });
            }

            applyFilter();
        })();

        // Toggle Actions Menu
        function toggleActions(button, reportId) {
            const menu = document.getElementById('menu-' + reportId);
            const isVisible = menu.style.display === 'block';
            
            // Hide all other menus
            document.querySelectorAll('.action-menu').forEach(m => {
                if (m.id !== 'menu-' + reportId) {
                    m.style.display = 'none';
                }
            });
            
            // Toggle current menu
            menu.style.display = isVisible ? 'none' : 'block';
            
            // Close menu when clicking outside
            if (!isVisible) {
                document.addEventListener('click', function closeMenu(e) {
                    if (!menu.contains(e.target) && e.target !== button) {
                        menu.style.display = 'none';
                        document.removeEventListener('click', closeMenu);
                    }
                });
            }
        }

        // Toggle Read Status
        function toggleReadStatus(element, reportId) {
            const row = document.getElementById('report-' + reportId);
            const statusBadge = row.querySelector('.status-badge');
            const isRead = statusBadge.dataset.status === 'read';
            
            if (isRead) {
                statusBadge.classList.remove('bg-success', 'text-white');
                statusBadge.classList.add('bg-warning', 'text-dark');
                statusBadge.dataset.status = 'unread';
                statusBadge.textContent = 'Unread';
            } else {
                statusBadge.classList.remove('bg-warning', 'text-dark');
                statusBadge.classList.add('bg-success', 'text-white');
                statusBadge.dataset.status = 'read';
                statusBadge.textContent = 'Read';
            }
        }

        // Show Detail from Row Click
        function showDetailFromRow(row) {
            const detailButton = row.querySelector('button[data-report-id]');
            if (detailButton) {
                showDetail(detailButton);
            }
        }

        // Show Detail in Modal
        function showDetail(button) {
            const reportId = button.dataset.reportId;
            const userName = button.dataset.user;
            const summary = button.dataset.summary;
            const description = button.dataset.description;
            const status = button.dataset.status || 'unread';
            
            document.getElementById('modalUser').textContent = userName;
            document.getElementById('modalSummary').textContent = summary;
            document.getElementById('modalDescription').textContent = description || 'No description available.';
            
            // Set status badge
            const statusBadge = document.getElementById('modalStatus');
            if (status === 'read') {
                statusBadge.className = 'badge bg-success';
                statusBadge.textContent = 'Read';
            } else {
                statusBadge.className = 'badge bg-warning text-dark';
                statusBadge.textContent = 'Unread';
            }
            
            // Set up toggle button
            const toggleBtn = document.getElementById('modalToggleStatusBtn');
            toggleBtn.style.display = 'block';
            toggleBtn.dataset.reportId = reportId;
            toggleBtn.dataset.currentStatus = status;
            toggleBtn.textContent = status === 'read' ? 'Mark as Unread' : 'Mark as Read';
            
            const modal = new bootstrap.Modal(document.getElementById('detailModal'));
            modal.show();
        }

        // Handle toggle status from modal
        function handleModalToggleStatus() {
            const toggleBtn = document.getElementById('modalToggleStatusBtn');
            const reportId = toggleBtn.dataset.reportId;
            const currentStatus = toggleBtn.dataset.currentStatus;
            const row = document.getElementById('report-' + reportId);
            
            if (!row) return;
            
            const statusBadge = row.querySelector('.status-badge');
            const isRead = currentStatus === 'read';
            
            // Update status badge in table
            if (isRead) {
                statusBadge.classList.remove('bg-success', 'text-white');
                statusBadge.classList.add('bg-warning', 'text-dark');
                statusBadge.dataset.status = 'unread';
                statusBadge.textContent = 'Unread';
                toggleBtn.dataset.currentStatus = 'unread';
                toggleBtn.textContent = 'Mark as Read';
            } else {
                statusBadge.classList.remove('bg-warning', 'text-dark');
                statusBadge.classList.add('bg-success', 'text-white');
                statusBadge.dataset.status = 'read';
                statusBadge.textContent = 'Read';
                toggleBtn.dataset.currentStatus = 'read';
                toggleBtn.textContent = 'Mark as Unread';
            }
            
            // Update modal status badge
            const modalStatusBadge = document.getElementById('modalStatus');
            if (toggleBtn.dataset.currentStatus === 'read') {
                modalStatusBadge.className = 'badge bg-success';
                modalStatusBadge.textContent = 'Read';
            } else {
                modalStatusBadge.className = 'badge bg-warning text-dark';
                modalStatusBadge.textContent = 'Unread';
            }
        }

        // Delete Report
        function deleteReport(element, reportId) {
            if (confirm('Are you sure you want to delete this report?')) {
                const row = document.getElementById('report-' + reportId);
                
                // Simulate deletion with AJAX (you can replace with actual backend call)
                fetch('delete_report.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ report_id: reportId })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        row.style.opacity = '0.5';
                        row.style.textDecoration = 'line-through';
                        alert('Report deleted successfully!');
                        // Optional: Remove row after delay
                        setTimeout(() => row.remove(), 500);
                    } else {
                        alert('Error deleting report: ' + (data.message || 'Unknown error'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error deleting report. Please try again.');
                });
            }
        }

    </script>
</body>
<footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map & Pump | All Rights Reserved</footer>
</html>
