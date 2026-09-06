<?php
/**
 * In-app popup for pending moderator hire invites.
 * Include near the end of authenticated pages (before </body>).
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/moderator_hire_helpers.php';

$moderatorHireInvites = moderator_hire_session_invites();
// Refresh from DB when session is empty but the user is logged in (e.g. invite created after login).
if (!$moderatorHireInvites && !empty($_SESSION['user_id'])) {
    try {
        if (!isset($pdo) || !($pdo instanceof PDO)) {
            require_once __DIR__ . '/conn.php';
        }
        if (isset($pdo) && $pdo instanceof PDO) {
            $moderatorHireInvites = moderator_hire_pending_for_user_pdo($pdo, (int) $_SESSION['user_id']);
            moderator_hire_store_pending_in_session($moderatorHireInvites);
        }
    } catch (Throwable $e) {
        error_log('[Moderator hire] Popup refresh failed: ' . $e->getMessage());
    }
}
if (!$moderatorHireInvites) {
    return;
}

$primaryInvite = $moderatorHireInvites[0];
$inviteId = (int) ($primaryInvite['invite_id'] ?? 0);
if ($inviteId <= 0) {
    return;
}

$hireMessage = (string) ($primaryInvite['message'] ?? '');
$hireRole = (string) ($primaryInvite['offered_role_name'] ?? 'Moderator');
$hireFrom = (string) ($primaryInvite['invited_by_name'] ?? 'Administrator');
$hireCreated = (string) ($primaryInvite['created_at'] ?? '');
$responseUrl = '/php/moderator_hire_response.php';
?>
<div class="modal fade" id="moderatorHireInviteModal" tabindex="-1" aria-labelledby="moderatorHireInviteModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background:rgba(10,16,30,.98); border:1px solid rgba(0,244,255,.25); color:#fff;">
            <div class="modal-header border-0 pb-0">
                <div>
                    <h5 class="modal-title fw-bold" id="moderatorHireInviteModalLabel">Moderator access invitation</h5>
                    <p class="mb-0 mt-1" style="color:rgba(255,255,255,.6); font-size:.82rem;">
                        From <?= htmlspecialchars($hireFrom, ENT_QUOTES, 'UTF-8') ?>
                        · Role offered: <?= htmlspecialchars($hireRole, ENT_QUOTES, 'UTF-8') ?>
                        <?php if ($hireCreated !== ''): ?>
                            · <?= htmlspecialchars($hireCreated, ENT_QUOTES, 'UTF-8') ?>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <div class="modal-body pt-3">
                <div style="white-space:pre-wrap; line-height:1.55; color:rgba(255,255,255,.88);"><?= htmlspecialchars($hireMessage, ENT_QUOTES, 'UTF-8') ?></div>
                <p class="mb-0 mt-3" style="color:rgba(255,255,255,.55); font-size:.8rem;">
                    Accepting will switch this account to the offered moderator role. Declining dismisses the invite.
                </p>
            </div>
            <div class="modal-footer border-0 pt-0">
                <form method="post" action="<?= htmlspecialchars($responseUrl, ENT_QUOTES, 'UTF-8') ?>" class="d-inline">
                    <input type="hidden" name="invite_id" value="<?= $inviteId ?>">
                    <input type="hidden" name="decision" value="declined">
                    <button type="submit" class="btn btn-outline-secondary text-white-50 border-secondary border-opacity-25">Decline</button>
                </form>
                <form method="post" action="<?= htmlspecialchars($responseUrl, ENT_QUOTES, 'UTF-8') ?>" class="d-inline">
                    <input type="hidden" name="invite_id" value="<?= $inviteId ?>">
                    <input type="hidden" name="decision" value="accepted">
                    <button type="submit" class="btn btn-info text-dark fw-semibold">Accept access</button>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    const modalEl = document.getElementById('moderatorHireInviteModal');
    if (!modalEl) return;
    const show = function () {
        if (window.bootstrap && bootstrap.Modal) {
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
            return;
        }
        modalEl.classList.add('show');
        modalEl.style.display = 'block';
        modalEl.removeAttribute('aria-hidden');
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', show);
    } else {
        show();
    }
})();
</script>
