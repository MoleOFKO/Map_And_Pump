<?php
header('Content-Type: application/json');

require_once __DIR__ . '/hq_guard.php';
require_once __DIR__ . '/report_schema.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('Invalid request method.');
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400);
        throw new RuntimeException('Invalid request body.');
    }

    $csrfToken = (string) ($input['csrf_token'] ?? '');
    if (empty($_SESSION['manager_report_status_csrf'])
        || !hash_equals((string) $_SESSION['manager_report_status_csrf'], $csrfToken)) {
        http_response_code(403);
        throw new RuntimeException('Your session expired. Refresh the page and try again.');
    }

    $reportId = (int) ($input['report_id'] ?? 0);
    $status = (string) ($input['status'] ?? '');
    if ($reportId <= 0 || !in_array($status, ['read', 'unread'], true)) {
        http_response_code(400);
        throw new RuntimeException('Invalid report status request.');
    }

    hq_report_ensure_scope_schema($pdo);
    $headquarterId = map_pump_current_hq_id($pdo, (int) ($_SESSION['user_id'] ?? 0));
    if ($headquarterId <= 0) {
        http_response_code(403);
        throw new RuntimeException('No HQ scope is available for this account.');
    }

    $stmt = $pdo->prepare(
        'UPDATE report r
         INNER JOIN users u ON u.user_id = r.user_id
         SET r.status = ?
         WHERE r.report_id = ?
           AND r.role_id = 4
           AND ' . map_pump_manager_report_scope_sql()
    );
    $stmt->execute([$status, $reportId, 4, 4, 4, $headquarterId, $headquarterId]);
    if ($stmt->rowCount() === 0) {
        $check = $pdo->prepare(
            'SELECT r.status
             FROM report r
             INNER JOIN users u ON u.user_id = r.user_id
             WHERE r.report_id = ?
               AND r.role_id = 4
               AND ' . map_pump_manager_report_scope_sql()
        );
        $check->execute([$reportId, 4, 4, 4, $headquarterId, $headquarterId]);
        $existingStatus = $check->fetchColumn();
        if ($existingStatus === false) {
            http_response_code(404);
            throw new RuntimeException('Report not found in your HQ scope.');
        }
        if ((string) $existingStatus !== $status) {
            throw new RuntimeException('Report status could not be updated.');
        }
    }

    echo json_encode(['success' => true, 'status' => $status]);
} catch (Throwable $e) {
    if (http_response_code() < 400) {
        http_response_code(400);
    }
    error_log('Failed to update manager report status: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
