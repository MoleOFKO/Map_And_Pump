<?php
require_once __DIR__ . '/hq_guard.php';
require_once __DIR__ . '/../conn.php';
// 2. Handle Form Submission
$alertMessage = "";
$user_id = (int) ($_SESSION['user_id'] ?? 0);
$reports = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $topic = trim((string) ($_POST['topic'] ?? ''));
    $urgency = trim((string) ($_POST['urgency'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $role_id = (int) ($_SESSION['role_id'] ?? $_SESSION['current_role_id'] ?? 3);

    if (isset($_POST['edit_report_id'])) {
        $reportId = (int) $_POST['edit_report_id'];
        if ($reportId > 0 && $topic !== '' && in_array($urgency, ['low', 'normal', 'high'], true) && $description !== '') {
            try {
                $stmt = $pdo->prepare(
                    'UPDATE report
                     SET importance = ?, short_summery = ?, message = ?
                     WHERE report_id = ? AND user_id = ?'
                );
                $summary = ucfirst($topic) . ' Issue';
                $stmt->execute([$urgency, $summary, $description, $reportId, $user_id]);
                $alertMessage = $stmt->rowCount() > 0 ? 'Your report was updated successfully.' : 'The report was not found or is unchanged.';
            } catch (PDOException $e) {
                $alertMessage = 'Error updating report: ' . $e->getMessage();
            }
        } else {
            $alertMessage = 'Please provide valid report details.';
        }
    } elseif ($topic !== '' && $urgency !== '' && $description !== '') {
        try {
            $stmt = $pdo->prepare("INSERT INTO report (user_id, role_id, importance, short_summery, message, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            
            $importance = ($urgency === 'high') ? 'high' : 'normal';
            $summary = ucfirst($topic) . " Issue";
            
            $stmt->execute([$user_id, $role_id, $importance, $summary, $description]);
            $alertMessage = "Your report has been successfully sent to the admin and moderator team!";
        } catch (PDOException $e) {
            $alertMessage = "Error dispatching report: " . $e->getMessage();
        }
    } else {
        $alertMessage = "Please fill in all required fields.";
    }
}

try {
    $stmt = $pdo->prepare(
        'SELECT report_id, importance, short_summery, message, created_at
         FROM report
         WHERE user_id = ?
         ORDER BY created_at DESC, report_id DESC'
    );
    $stmt->execute([$user_id]);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $alertMessage = $alertMessage !== '' ? $alertMessage : 'Your reports could not be loaded.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <script src="../../js/hq-theme.js"></script>
<link rel="stylesheet" href="../../css/HQstyle.css">
</head>
<body>
    <h2 class="title">Report to Admin and Moderator</h2>
    <p class="subtitle">Submit an issue for review by the admin and moderator team. You can view and edit your submitted reports below.</p>

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

            <button type="submit" class="btn btn-danger">Send Report to Admin and Moderator</button>
        </form>
    </div>

    <div class="card" style="margin-top: 24px;">
        <h3>My Submitted Reports</h3>
        <?php if (!$reports): ?>
            <p class="subtitle">You have not submitted any reports yet.</p>
        <?php else: ?>
            <?php foreach ($reports as $report): ?>
                <details style="margin-top: 14px; padding: 12px; border: 1px solid var(--border); border-radius: 6px;">
                    <summary style="cursor: pointer;">
                        <strong><?= htmlspecialchars($report['short_summery']) ?></strong>
                        <span style="margin-left: 10px; color: var(--slate-400);"> <?= htmlspecialchars($report['importance']) ?> · <?= htmlspecialchars($report['created_at']) ?></span>
                    </summary>
                    <form method="POST" action="" style="margin-top: 14px;">
                        <input type="hidden" name="edit_report_id" value="<?= (int) $report['report_id'] ?>">
                        <div class="form-group">
                            <label>Issue Classification / Topic</label>
                            <select name="topic" required>
                                <?php foreach (['pricing' => 'Pricing Sync Error', 'access' => 'Access Control & Credentials', 'dispute' => 'Transaction / Settlement Dispute', 'other' => 'Other Technical Request'] as $topicKey => $topicLabel): ?>
                                    <option value="<?= htmlspecialchars($topicKey) ?>" <?= stripos((string) $report['short_summery'], $topicKey) !== false ? 'selected' : '' ?>><?= htmlspecialchars($topicLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Impact Urgency Level</label>
                            <select name="urgency" required>
                                <?php foreach (['low', 'normal', 'high'] as $urgency): ?>
                                    <option value="<?= $urgency ?>" <?= strtolower((string) $report['importance']) === $urgency ? 'selected' : '' ?>><?= ucfirst($urgency) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Detailed Description</label>
                            <textarea name="description" rows="4" required><?= htmlspecialchars((string) $report['message']) ?></textarea>
                        </div>
                        <button type="submit" class="btn btn-danger">Update My Report</button>
                    </form>
                </details>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>
<footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map & Pump | All Rights Reserved</footer>
</html>
