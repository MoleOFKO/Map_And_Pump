<?php
include 'share_header.php';
require_once __DIR__ . '/../feedback_schema.php';

$feedbackReady = map_pump_feedback_schema_ready($pdo);
$customerFeedback = [];
$customerReplies = [];
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($feedbackReady && $userId > 0) {
    try {
        $readReplies = $pdo->prepare(
            'UPDATE feedback_replies fr
             INNER JOIN feedback f ON f.feedback_id = fr.feedback_id
             SET fr.customer_read_at = COALESCE(fr.customer_read_at, NOW())
             WHERE f.user_id = ?'
        );
        $readReplies->execute([$userId]);

        $feedbackStmt = $pdo->prepare(
            'SELECT f.feedback_id, f.feedback, f.created_at, f.usage_id, f.rating,
                    s.station_name, o.oil_name,
                    (SELECT COUNT(*) FROM feedback_replies fr WHERE fr.feedback_id = f.feedback_id) AS reply_count
             FROM feedback f
             LEFT JOIN stations s ON s.station_id = f.station_id
             LEFT JOIN usage_history uh ON uh.usage_id = f.usage_id
             LEFT JOIN oil_types o ON o.oil_type_id = uh.oil_type_id
             WHERE f.user_id = ?
             ORDER BY f.created_at DESC, f.feedback_id DESC'
        );
        $feedbackStmt->execute([$userId]);
        $customerFeedback = $feedbackStmt->fetchAll(PDO::FETCH_ASSOC);

        $replyStmt = $pdo->prepare(
            'SELECT fr.reply_id, fr.reply_text, fr.created_at, f.feedback_id, f.feedback, f.usage_id,
                    COALESCE(NULLIF(staff.user_name, \'\'), \'Station staff\') AS staff_name,
                    s.station_name, o.oil_name
             FROM feedback_replies fr
             INNER JOIN feedback f ON f.feedback_id = fr.feedback_id
             LEFT JOIN users staff ON staff.user_id = fr.staff_id
             LEFT JOIN stations s ON s.station_id = f.station_id
             LEFT JOIN usage_history uh ON uh.usage_id = f.usage_id
             LEFT JOIN oil_types o ON o.oil_type_id = uh.oil_type_id
             WHERE f.user_id = ?
             ORDER BY fr.created_at DESC, fr.reply_id DESC'
        );
        $replyStmt->execute([$userId]);
        $customerReplies = $replyStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Failed to load customer feedback conversation: ' . $e->getMessage());
    }
}

function customer_feedback_time(?string $value): string
{
    try {
        return (new DateTime((string)$value))->format('M j, Y \\a\\t H:i');
    } catch (Throwable $e) {
        return 'Time unavailable';
    }
}
?>

<section class="dashboard-panel-card feedback-page">
    <div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-4">
        <div>
            <h3 class="text-white mb-1">Feedback &amp; Replies</h3>
            <p class="text-secondary mb-0" style="font-size:13px;">Keep track of feedback you have sent and responses from station staff.</p>
        </div>
        <a class="btn btn-outline-info btn-sm px-3" href="usage_history.php"><i class="bi bi-chat-left-text me-1"></i> Send feedback</a>
    </div>

    <div class="feedback-tabs" role="tablist" aria-label="Feedback views">
        <button class="feedback-tab active" type="button" data-feedback-tab="sent" role="tab" aria-selected="true">My feedback <span><?= count($customerFeedback) ?></span></button>
        <button class="feedback-tab" type="button" data-feedback-tab="replies" role="tab" aria-selected="false">Staff replies <span><?= count($customerReplies) ?></span></button>
    </div>

    <div id="feedback-panel-sent" class="feedback-tab-panel" role="tabpanel">
        <?php if (!$customerFeedback): ?>
            <div class="feedback-empty"><i class="bi bi-chat-square-text"></i><p>You have not sent feedback yet.</p><a href="usage_history.php">Open usage history</a></div>
        <?php else: foreach ($customerFeedback as $feedback): ?>
            <?php $isUsageFeedback = !empty($feedback['usage_id']); ?>
            <article class="feedback-conversation-card feedback-from-customer">
                <div class="feedback-card-top">
                    <div>
                        <span class="feedback-label feedback-label-customer"><i class="bi bi-person-fill"></i> Your feedback</span>
                        <h4>You</h4>
                        <div class="feedback-meta"><span>Type: <?= $isUsageFeedback ? 'Fuel fill feedback' : 'General feedback' ?></span><span><?= htmlspecialchars(customer_feedback_time($feedback['created_at'])) ?></span></div>
                    </div>
                    <?php if (!empty($feedback['rating'])): ?><span class="feedback-rating"><?= (int)$feedback['rating'] ?>/5</span><?php endif; ?>
                </div>
                <?php if (!empty($feedback['station_name'])): ?><p class="feedback-context"><i class="bi bi-geo-alt"></i><?= htmlspecialchars($feedback['station_name']) ?><?= !empty($feedback['oil_name']) ? ' / ' . htmlspecialchars($feedback['oil_name']) : '' ?></p><?php endif; ?>
                <p class="feedback-message"><?= nl2br(htmlspecialchars($feedback['feedback'])) ?></p>
                <p class="feedback-reply-count"><i class="bi bi-reply-fill"></i><?= (int)$feedback['reply_count'] ?> staff repl<?= (int)$feedback['reply_count'] === 1 ? 'y' : 'ies' ?></p>
            </article>
        <?php endforeach; endif; ?>
    </div>

    <div id="feedback-panel-replies" class="feedback-tab-panel d-none" role="tabpanel">
        <?php if (!$customerReplies): ?>
            <div class="feedback-empty"><i class="bi bi-chat-left-dots"></i><p>No staff replies yet.</p></div>
        <?php else: foreach ($customerReplies as $reply): ?>
            <?php $isUsageFeedback = !empty($reply['usage_id']); ?>
            <article class="feedback-conversation-card feedback-from-staff">
                <div class="feedback-card-top">
                    <div>
                        <span class="feedback-label feedback-label-staff"><i class="bi bi-building"></i> Staff reply</span>
                        <h4><?= htmlspecialchars($reply['staff_name']) ?></h4>
                        <div class="feedback-meta"><span>Type: <?= $isUsageFeedback ? 'Fuel fill response' : 'General feedback response' ?></span><span><?= htmlspecialchars(customer_feedback_time($reply['created_at'])) ?></span></div>
                    </div>
                </div>
                <?php if (!empty($reply['station_name'])): ?><p class="feedback-context"><i class="bi bi-geo-alt"></i><?= htmlspecialchars($reply['station_name']) ?><?= !empty($reply['oil_name']) ? ' / ' . htmlspecialchars($reply['oil_name']) : '' ?></p><?php endif; ?>
                <p class="feedback-message"><?= nl2br(htmlspecialchars($reply['reply_text'])) ?></p>
                <div class="feedback-original"><strong>Your feedback:</strong> <?= htmlspecialchars($reply['feedback']) ?></div>
            </article>
        <?php endforeach; endif; ?>
    </div>
</section>

<style>
.feedback-page { max-width: 980px; }
.feedback-tabs { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:20px; border-bottom:1px solid rgba(255,255,255,.1); padding-bottom:12px; }
.feedback-tab { border:1px solid rgba(13,110,253,.3); border-radius:7px; background:transparent; color:#a3b8cc; padding:8px 12px; font-size:13px; font-weight:700; }
.feedback-tab:hover { border-color:rgba(13,110,253,.7); color:#e8f3ff; }
.feedback-tab.active { background:#0d6efd; border-color:#0d6efd; color:#fff; }
.feedback-tab span { display:inline-flex; justify-content:center; min-width:19px; margin-left:5px; padding:0 5px; border-radius:99px; background:rgba(0,0,0,.18); font-size:11px; }
.feedback-conversation-card { border:1px solid rgba(255,255,255,.1); border-radius:8px; padding:18px; margin-bottom:12px; }
.feedback-from-customer { border-left:4px solid #0d6efd; background:rgba(13,110,253,.06); }
.feedback-from-staff { border-left:4px solid #1be494; background:rgba(27,228,148,.06); }
.feedback-card-top { display:flex; justify-content:space-between; gap:12px; align-items:flex-start; }
.feedback-card-top h4 { color:#fff; margin:5px 0 4px; font-size:16px; }
.feedback-label { display:inline-flex; align-items:center; gap:6px; font-size:11px; font-weight:800; letter-spacing:.04em; text-transform:uppercase; }
.feedback-label-customer { color:#72b7ff; }.feedback-label-staff { color:#72efbc; }
.feedback-meta { display:flex; flex-wrap:wrap; gap:6px 14px; color:#a3b8cc; font-size:12px; }
.feedback-rating { border-radius:7px; padding:5px 8px; color:#1e293b; background:#ffc107; font-size:12px; font-weight:800; }
.feedback-context { margin:14px 0 8px; color:#a3b8cc; font-size:12px; }.feedback-context i { margin-right:5px; color:#38bdf8; }
.feedback-message { color:#e8f3ff; margin:0; line-height:1.65; white-space:normal; }.feedback-reply-count { color:#a3b8cc; font-size:12px; margin:14px 0 0; }.feedback-reply-count i { color:#1be494; margin-right:5px; }
.feedback-original { margin-top:14px; padding:10px 12px; border-radius:7px; color:#c9d2e3; background:rgba(255,255,255,.05); font-size:12px; }.feedback-original strong { color:#fff; }
.feedback-empty { padding:50px 20px; text-align:center; color:#a3b8cc; }.feedback-empty i { display:block; color:#38bdf8; font-size:30px; margin-bottom:12px; }.feedback-empty p { margin-bottom:10px; }.feedback-empty a { color:#38bdf8; }
body.customer-light-mode .feedback-conversation-card { border-color:rgba(15,23,42,.12); } body.customer-light-mode .feedback-tab { color:#475569; } body.customer-light-mode .feedback-tab.active { color:#fff; } body.customer-light-mode .feedback-card-top h4, body.customer-light-mode .feedback-message, body.customer-light-mode .feedback-original strong { color:#102033; } body.customer-light-mode .feedback-original { background:rgba(15,23,42,.05); color:#475569; }
</style>

<script>
document.querySelectorAll('[data-feedback-tab]').forEach(function (tab) {
    tab.addEventListener('click', function () {
        const selected = tab.dataset.feedbackTab;
        document.querySelectorAll('[data-feedback-tab]').forEach(function (item) {
            item.classList.toggle('active', item === tab);
            item.setAttribute('aria-selected', item === tab ? 'true' : 'false');
        });
        document.getElementById('feedback-panel-sent').classList.toggle('d-none', selected !== 'sent');
        document.getElementById('feedback-panel-replies').classList.toggle('d-none', selected !== 'replies');
    });
});
</script>

<?php include 'share_footer.php'; ?>
