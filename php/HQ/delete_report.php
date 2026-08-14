<?php
/**
 * Delete Report Handler
 * Handles AJAX request to delete a report
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../conn.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    // Get JSON data
    $input = json_decode(file_get_contents('php://input'), true);
    $reportId = intval($input['report_id'] ?? 0);

    if (!$reportId) {
        throw new Exception('Invalid report ID');
    }

    // Check if connection exists
    if (!isset($pdo)) {
        throw new Exception('Database connection error');
    }

    // Delete the report
    $stmt = $pdo->prepare("DELETE FROM report WHERE report_id = ?");
    $result = $stmt->execute([$reportId]);

    if ($result) {
        echo json_encode(['success' => true, 'message' => 'Report deleted successfully']);
    } else {
        throw new Exception('Failed to delete report');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
