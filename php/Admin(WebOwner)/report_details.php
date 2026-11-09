<?php
require_once __DIR__ . '/admin_common.php';

admin_require_staff();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$reportId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$reportId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid report ID is required.']);
    exit;
}

if (!hq_db_available()) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'The report database is temporarily unavailable.']);
    exit;
}

global $conn;

try {
    $statement = $conn->prepare(
        'SELECT p.report_id, p.user_id, p.short_summery, p.message, p.importance, p.created_at, p.role_id,
                u.user_name, r.role_name
         FROM report p
         LEFT JOIN users u ON u.user_id = p.user_id
         LEFT JOIN roles r ON r.role_id = p.role_id
         WHERE p.report_id = ?
         LIMIT 1'
    );
    if (!$statement) {
        throw new RuntimeException('Could not prepare the report lookup.');
    }

    $statement->bind_param('i', $reportId);
    $statement->execute();
    $report = $statement->get_result()?->fetch_assoc();
    $statement->close();

    if (!$report) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'The selected report no longer exists.']);
        exit;
    }

    $metadata = admin_report_metadata((string) ($report['message'] ?? ''), [
        'short_summery' => (string) ($report['short_summery'] ?? ''),
        'role_id' => $report['role_id'] ?? null,
        'role_name' => (string) ($report['role_name'] ?? ''),
    ]);
    $metadata['scope'] = admin_normalize_report_scope((string) ($metadata['scope'] ?? 'system'));

    echo json_encode([
        'success' => true,
        'report' => [
            'report_id' => (int) $report['report_id'],
            'title' => (string) ($report['short_summery'] ?? ''),
            'message' => (string) ($report['message'] ?? ''),
            'importance' => (string) ($report['importance'] ?? ''),
            'created_at' => $report['created_at'],
            'author' => (string) ($report['user_name'] ?? 'System'),
            'role_name' => (string) ($report['role_name'] ?? 'Admin'),
        ],
        'metadata' => $metadata,
    ]);
} catch (Throwable $exception) {
    error_log('[Report details] ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'The report details could not be loaded.']);
}
