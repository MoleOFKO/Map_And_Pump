<?php
require_once __DIR__ . '/hq_guard.php';
require_once __DIR__ . '/../conn.php';
// 2. Handle Form Submission
$alertMessage = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $topic = $_POST['topic'] ?? '';
    $urgency = $_POST['urgency'] ?? '';
    $description = $_POST['description'] ?? '';

    $user_id = (int) ($_SESSION['user_id'] ?? 0);
    $role_id = (int) ($_SESSION['role_id'] ?? $_SESSION['current_role_id'] ?? 3);

    if (!empty($topic) && !empty($urgency) && !empty($description)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO report (user_id, role_id, importance, short_summery, message, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            
            $importance = ($urgency === 'high') ? 'high' : 'normal';
            $summary = ucfirst($topic) . " Issue";
            
            $stmt->execute([$user_id, $role_id, $importance, $summary, $description]);
            $alertMessage = "Your escalation has been successfully dispatched to the admin!";
        } catch (PDOException $e) {
            $alertMessage = "Error dispatching report: " . $e->getMessage();
        }
    } else {
        $alertMessage = "Please fill in all required fields.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../../css/HQstyle.css">
</head>
<body>
    <h2 class="title">Report to Admin</h2>
    <p class="subtitle">Submit incident files, request technical help, or escalate critical terminal operational issues directly to global system administrators.</p>

    <?php
if (!empty($alertMessage)): ?>
        <div class="alert alert-info" role="alert" style="margin: 15px 0;">
            <?php
echo htmlspecialchars($alertMessage); ?>
        </div>
    <?php
endif; ?>

    <div class="card">
        <h3>Operational Escalation</h3>
        <p class="subtitle" style="margin-bottom: 15px;">Provide detailed information regarding system bugs, regional hardware malfunctions, or merchant disputes.</p>
        
        <!-- Form submits to itself -->
        <form method="POST" action="">
            <div class="form-group">
                <label>Issue Classification / Topic</label>
                <select name="topic" id="issue-topic" required>
                    <option value="">-- Select Category --</option>
                    <option value="pricing">Pricing Sync Error</option>
                    <option value="access">Access Control & Credentials</option>
                    <option value="dispute">Transaction / Settlement Dispute</option>
                    <option value="other">Other Technical Request</option>
                </select>
            </div>

            <div class="form-group">
                <label>Impact Urgency Level</label>
                <select name="urgency" id="issue-urgency" required>
                    <option value="low">Low - General Question / Routine Update</option>
                    <option value="normal">Medium - System anomaly but business operational</option>
                    <option value="high">High - Business Critical / Station Outage</option>
                </select>
            </div>

            <div class="form-group">
                <label>Detailed Description</label>
                <textarea name="description" id="issue-description" rows="6" placeholder="Please describe the exact issue, steps to reproduce, or key terminal details..." style="width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 6px; font-family: inherit; font-size: 0.95rem;" required></textarea>
            </div>

            <div class="form-group">
                <label>Attach Evidence / Screen Capture (Optional)</label>
                <input type="file" accept="image/*,application/pdf">
            </div>

            <button type="submit" class="btn btn-danger">Send Report to Admin</button>
        </form>
    </div>
</body>
<footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map & Pump | All Rights Reserved</footer>
</html>
