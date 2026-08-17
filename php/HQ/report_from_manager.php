<?php
require_once __DIR__ . '/../conn.php';

$reports = [];
$logs = [];
$managerRoleId = 4;

try {
    $stmt = $pdo->prepare(
        'SELECT r.*, u.user_name
         FROM report r
         LEFT JOIN users u ON u.user_id = r.user_id
         WHERE COALESCE(u.business_role_id, u.role_id) = :role_id
         ORDER BY r.created_at DESC
         LIMIT 5'
    );
    $stmt->execute([':role_id' => $managerRoleId]);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $logStmt = $pdo->prepare(
        'SELECT l.log_id, l.user_id, l.action, l.created_at, u.user_name
         FROM log l
         LEFT JOIN users u ON u.user_id = l.user_id
         WHERE COALESCE(u.business_role_id, u.role_id) = :role_id
         ORDER BY l.created_at DESC
         LIMIT 10'
    );
    $logStmt->execute([':role_id' => $managerRoleId]);
    $logs = $logStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Failed to load manager activities: ' . $e->getMessage());
    $reports = [];
    $logs = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../../css/HQstyle.css">
    <title>Manager Reports & Activity</title>
</head>
<body>
    <div class="container my-4">
        <h2 class="title">Manager Reports & Activity</h2>
        <p class="subtitle">Review operational data, volume logs, and localized site edits from TiDB Cloud.</p>

        <?php if (isset($dbError)): ?>
            <div class="alert alert-danger" role="alert">
                Database Connection Error: <?= htmlspecialchars($dbError) ?>
            </div>
        <?php endif; ?>

        <div class="card mb-4 p-3">
            <h3>Site Reports</h3>
            <p class="subtitle" style="margin-bottom: 12px;">View shift-ending tallies submitted by field operators.</p>
            
            <?php if (empty($reports)): ?>
                <div style="background: var(--bg); padding: 15px; border-radius: 6px; font-size: 0.9rem; color: #ffffff; text-align: center;">
                    No pending daily station reports require evaluation.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-dark table-striped">
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
                            <?php foreach ($reports as $rep): ?>
                                <tr id="report-<?= htmlspecialchars($rep['report_id']) ?>" onclick="showDetailFromRow(this)" style="cursor: pointer;">
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
                                        <div class="action-menu" id="menu-<?= htmlspecialchars($rep['report_id']) ?>" style="display: none; position: absolute; background: #343a40; border: 1px solid #495057; border-radius: 5px; padding: 8px; min-width: 180px; z-index: 1000;">
                                            <button onclick="event.stopPropagation(); toggleReadStatus(this, <?= htmlspecialchars($rep['report_id']) ?>)" style="border: none; background: none; color: #0d6efd; cursor: pointer; width: 100%; text-align: left; padding: 8px; display: flex; align-items: center; gap: 10px;">
                                                <i class="bi bi-envelope" style="font-size: 1rem;"></i> Mark as Read
                                            </button>
                                            <button onclick="event.stopPropagation(); showDetail(this)" data-report-id="<?= htmlspecialchars($rep['report_id']) ?>" data-user="<?= htmlspecialchars($rep['user_name']) ?>" data-summary="<?= htmlspecialchars($rep['short_summery']) ?>" data-description="<?= htmlspecialchars($rep['message'] ?? '') ?>" style="border: none; background: none; color: #0d6efd; cursor: pointer; width: 100%; text-align: left; padding: 8px; display: flex; align-items: center; gap: 10px;">
                                                <i class="bi bi-eye" style="font-size: 1rem;"></i> See Detail
                                            </button>
                                            <button onclick="event.stopPropagation(); deleteReport(this, <?= htmlspecialchars($rep['report_id']) ?>)" style="border: none; background: none; color: #dc3545; cursor: pointer; width: 100%; text-align: left; padding: 8px; display: flex; align-items: center; gap: 10px;">
                                                <i class="bi bi-trash" style="font-size: 1rem;"></i> Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="card p-3">
            <h3>Recent Manager Activity</h3>
            <p class="subtitle" style="margin-bottom: 12px;">Audit trail of critical events occurring at active pumps.</p>
            
            <ul style="list-style: none; padding-left: 0; font-family: monospace; font-size: 0.9rem; color: #ffffff;">
                <?php if (empty($logs)): ?>
                    <li style="border-bottom: 1px solid var(--border); padding: 8px 0; color: #ffffff;">
                        No manager activity has been recorded yet.
                    </li>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <li style="border-bottom: 1px solid var(--border); padding: 8px 0; color: #ffffff;">
                            [<?= htmlspecialchars($log['created_at']) ?>] USER <?= htmlspecialchars($log['user_name'] ?? 'Unknown Manager') ?>: <?= htmlspecialchars($log['action'] ?? 'No action detail') ?>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>
    </div>

    <!-- Detail Modal -->
    <div class="modal fade" id="detailModal" tabindex="-1" aria-labelledby="detailModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content" style="background: var(--bg, #1e1e1e); border: 1px solid var(--border, #495057); color: #ffffff;">
                <div class="modal-header" style="border-bottom: 1px solid var(--border, #495057);">
                    <h5 class="modal-title" id="detailModalLabel" style="color: #ffffff;">Report Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p style="color: #ffffff;"><strong>User:</strong> <span id="modalUser" style="color: #e9ecef;"></span></p>
                    <p style="color: #ffffff;"><strong>Summary:</strong> <span id="modalSummary" style="color: #e9ecef;"></span></p>
                    <p style="color: #ffffff;"><strong>Description:</strong></p>
                    <p id="modalDescription" style="background: var(--bg, #2d2d2d); padding: 10px; border-radius: 5px; border: 1px solid var(--border, #495057); color: #e9ecef;"></p>
                </div>
                <div class="modal-footer" style="border-top: 1px solid var(--border, #495057);">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
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
            
            document.getElementById('modalUser').textContent = userName;
            document.getElementById('modalSummary').textContent = summary;
            document.getElementById('modalDescription').textContent = description || 'No description available.';
            
            const modal = new bootstrap.Modal(document.getElementById('detailModal'));
            modal.show();
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