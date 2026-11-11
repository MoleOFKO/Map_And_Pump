<?php
require_once __DIR__ . '/hq_guard.php';
require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../business_scope.php';
require_once __DIR__ . '/../invitations_schema.php';

try {
    map_pump_ensure_invitation_schema($pdo);
} catch (Throwable $e) {
    error_log('Failed to prepare HQ invitation schema: ' . $e->getMessage());
}

$managers = [];
$staffAccounts = [];
$stations = [];
$branchManagers = [];
$dbError = '';
$statusMessage = '';
$hqId = map_pump_current_hq_id($pdo);
$invitationDecisionMessages = [];
$sessionMessage = $_SESSION['hq_account_message'] ?? null;
unset($_SESSION['hq_account_message']);
if (is_array($sessionMessage)) {
    if (($sessionMessage['type'] ?? 'success') === 'danger') {
        $dbError = (string)($sessionMessage['text'] ?? '');
    } else {
        $statusMessage = (string)($sessionMessage['text'] ?? '');
    }
}

$accountUserId = (int) ($_SESSION['user_id'] ?? 0);
if ($accountUserId > 0) {
    try {
        $decisionStmt = $pdo->prepare(
            "SELECT notification_id, subject, message, notification_type
             FROM notifications
             WHERE recipient_user_id = ?
               AND notification_type IN ('hq_invitation_accepted', 'hq_invitation_declined')
               AND read_at IS NULL
             ORDER BY created_at ASC
             LIMIT 10"
        );
        $decisionStmt->execute([$accountUserId]);
        $invitationDecisionMessages = $decisionStmt->fetchAll(PDO::FETCH_ASSOC);
        if ($invitationDecisionMessages) {
            $notificationIds = array_map(
                static fn(array $notification): int => (int) $notification['notification_id'],
                $invitationDecisionMessages
            );
            $placeholders = implode(',', array_fill(0, count($notificationIds), '?'));
            $markRead = $pdo->prepare(
                "UPDATE notifications SET read_at = NOW()
                 WHERE recipient_user_id = ? AND notification_id IN ({$placeholders}) AND read_at IS NULL"
            );
            $markRead->execute(array_merge([$accountUserId], $notificationIds));
        }
    } catch (Throwable $error) {
        error_log('[HQ account] Could not load staff invitation responses: ' . $error->getMessage());
        $dbError = 'Could not load invitation response notifications.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_manager_status'])) {
    $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $newStatus = filter_input(INPUT_POST, 'new_status', FILTER_SANITIZE_STRING);
    $accountRole = filter_input(INPUT_POST, 'account_role', FILTER_VALIDATE_INT);

    if ($userId && $accountRole === 4 && in_array($newStatus, ['active', 'disabled'], true)) {
        try {
            if (!map_pump_manager_in_hq($pdo, (int)$userId, $hqId)) {
                $statusMessage = 'That manager is not under your HQ.';
            } else {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('UPDATE users SET status = ? WHERE user_id = ? AND COALESCE(business_role_id, role_id) = ?');
                $stmt->execute([$newStatus, $userId, $accountRole]);

                $stationStmt = $pdo->prepare('SELECT station_id FROM station_business_scope WHERE headquarter_id = ? AND branch_manager_user_id = ? ORDER BY station_id LIMIT 1');
                $stationStmt->execute([$hqId, $userId]);
                $stationId = $stationStmt->fetchColumn() ?: null;

                $scopeStmt = $pdo->prepare(
                    "INSERT INTO business_account_scope (user_id, business_role_id, headquarter_id, branch_manager_user_id, station_id, status)
                     VALUES (?, ?, ?, NULL, ?, ?)
                     ON DUPLICATE KEY UPDATE headquarter_id = VALUES(headquarter_id), business_role_id = VALUES(business_role_id), station_id = VALUES(station_id), status = VALUES(status)"
                );
                 $scopeStmt->execute([$userId, $accountRole, $hqId, $stationId, $newStatus]);
                $pdo->commit();
                $statusMessage = 'Manager status updated successfully.';
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $dbError = $e->getMessage();
        }
    } else {
        $statusMessage = 'Invalid manager status update request.';
    }
}

try {
    $sql = "SELECT u.user_id, u.user_name, u.email, u.status, 4 AS account_role,
                   s.branch_id AS station_id, s.branch_name AS station_name,
                   CASE WHEN s.latitude IS NOT NULL AND s.longitude IS NOT NULL
                        THEN CONCAT(s.latitude, ', ', s.longitude) ELSE s.address END AS location
            FROM users u
            LEFT JOIN business_account_scope bas
                   ON bas.user_id = u.user_id
                  AND bas.headquarter_id = :hq_id_scope
            LEFT JOIN station_business_scope sbs
                   ON sbs.branch_manager_user_id = u.user_id
                  AND sbs.headquarter_id = :hq_id_scope_filter
            LEFT JOIN branches s ON s.branch_id = COALESCE(bas.station_id, sbs.station_id)
            WHERE :hq_id_valid > 0
              AND COALESCE(u.business_role_id, u.role_id) = 4
              AND (bas.user_id IS NOT NULL OR sbs.branch_manager_user_id IS NOT NULL)
            ORDER BY u.user_id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':hq_id_scope' => $hqId,
        ':hq_id_scope_filter' => $hqId,
        ':hq_id_valid' => $hqId,
    ]);
    $managers = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

try {
    $staffStmt = $pdo->prepare(
        "SELECT st.staff_id, st.user_id, st.full_name AS user_name, st.email,
                CASE WHEN st.is_active = 1 THEN 'active' ELSE 'disabled' END AS status,
                5 AS account_role, b.branch_id AS station_id, b.branch_name AS station_name,
                CASE WHEN b.latitude IS NOT NULL AND b.longitude IS NOT NULL
                     THEN CONCAT(b.latitude, ', ', b.longitude) ELSE b.address END AS location
         FROM staff st
         INNER JOIN branches b ON b.branch_id = st.branch_id
         WHERE ? > 0
           AND st.branch_id IS NOT NULL
           AND EXISTS (
             SELECT 1
             FROM station_business_scope sbs
             WHERE sbs.station_id = b.branch_id
               AND sbs.headquarter_id = ?
         )
         ORDER BY st.full_name ASC, st.staff_id ASC"
    );
    $staffStmt->execute([$hqId, $hqId]);
    $staffAccounts = $staffStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

try {
    $stationStmt = $pdo->prepare("SELECT s.branch_id AS station_id, s.branch_name AS station_name,
                                         CASE WHEN s.latitude IS NOT NULL AND s.longitude IS NOT NULL
                                              THEN CONCAT(s.latitude, ', ', s.longitude) ELSE s.address END AS location,
                                         s.address AS station_address
                                  FROM branches s
                                  INNER JOIN station_business_scope sbs ON sbs.station_id = s.branch_id
                                  WHERE ? > 0 AND sbs.headquarter_id = ?
                                  ORDER BY s.branch_name");
    $stationStmt->execute([$hqId, $hqId]);
    $stations = $stationStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

try {
    $branchManagerStmt = $pdo->prepare(
        "SELECT u.user_id, u.email
         FROM users u
         LEFT JOIN business_account_scope bas ON bas.user_id = u.user_id
         WHERE ? > 0
           AND COALESCE(u.business_role_id, u.role_id) = 4
           AND (
               bas.headquarter_id = ?
               OR EXISTS (
                   SELECT 1 FROM station_business_scope sbs
                   WHERE sbs.branch_manager_user_id = u.user_id
                     AND sbs.headquarter_id = ?
               )
           )
           AND LOWER(COALESCE(u.status, 'active')) NOT IN ('suspended', 'removed')
         ORDER BY u.email"
    );
    $branchManagerStmt->execute([$hqId, $hqId, $hqId]);
    $branchManagers = $branchManagerStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $dbError = $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <script src="../../js/hq-theme.js"></script>
<link rel="stylesheet" href="../../css/HQstyle.css">
    <style>
        /* Account roster search bar styling (matches pricing/station search bars) */
        .account-search {
            position: relative;
            flex: 0 0 auto;
            width: 260px;
            max-width: 100%;
        }

        .account-search .account-search-icon {
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

        .account-search #accountSearch {
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

        .account-search #accountSearch::placeholder {
            color: var(--slate-300);
            opacity: 0.7;
        }

        .account-search #accountSearch:focus {
            background: rgba(255, 255, 255, 0.08);
            border-color: rgba(56, 189, 248, 0.45);
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.16);
        }

        .account-search #accountSearch::-webkit-search-cancel-button,
        .account-search #accountSearch::-webkit-search-decoration {
            -webkit-appearance: none;
            appearance: none;
        }

        .account-search .account-search-clear {
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

        .account-search .account-search-clear:hover {
            color: var(--slate-50);
            background: rgba(255, 255, 255, 0.08);
        }

        .account-search.has-value .account-search-clear {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .invite-account-type {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem 1.5rem;
            margin: 0.15rem 0 1rem;
        }

        .invite-account-type .form-check {
            margin: 0;
        }

        .invite-account-type .form-check-label {
            color: var(--slate-50);
            font-weight: 500;
        }

        .invite-modal-header {
            position: relative;
            align-items: flex-start;
            min-height: 3.5rem;
            padding-right: 4rem;
        }

        .invite-modal-actions {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.4rem;
            flex-shrink: 0;
            position: absolute;
            top: 0.75rem;
            right: 1rem;
        }

        .invite-help-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2rem;
            height: 2rem;
            padding: 0;
            border-radius: 50%;
            font-weight: 700;
            line-height: 1;
        }

        .invite-help-panel {
            border: 1px solid var(--border);
            border-radius: 0.75rem;
            background: rgba(255, 255, 255, 0.05);
        }

        .invite-password-row[hidden],
        #inviteAccountTypeOptions[hidden] {
            display: none !important;
        }

        .invite-password-input {
            position: relative;
        }

        .invite-password-input .form-control {
            padding-right: 2.75rem;
        }

        .invite-password-toggle {
            position: absolute;
            top: 50%;
            right: 0.75rem;
            transform: translateY(-50%);
            padding: 0.25rem;
            border: 0;
            background: transparent;
            color: var(--slate-300);
            cursor: pointer;
        }

        .invite-password-toggle:hover,
        .invite-password-toggle:focus-visible {
            color: var(--slate-50);
        }
    </style>
</head>
<body>
    <div class="container">
        <h2 class="title">Employee Accounts</h2>
        <p class="subtitle">View branch managers and staff assigned to stations in this HQ.</p>

        <?php
if (!empty($dbError)): ?>
            <div class="alert alert-danger" role="alert">
                Database error: <?= htmlspecialchars($dbError) ?>
            </div>
        <?php
endif; ?>

        <?php
if (!empty($statusMessage)): ?>
            <div class="alert alert-success" role="alert">
                <?= htmlspecialchars($statusMessage) ?>
            </div>
        <?php
endif; ?>

        <?php foreach ($invitationDecisionMessages as $index => $notification): ?>
            <div class="alert alert-<?= str_contains((string) $notification['notification_type'], 'accepted') ? 'success' : 'warning' ?> invitation-response-alert" role="status" data-alert-index="<?= (int) $index ?>">
                <strong><?= htmlspecialchars((string) $notification['subject'], ENT_QUOTES, 'UTF-8') ?>:</strong>
                <?= htmlspecialchars((string) $notification['message'], ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endforeach; ?>

        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap;">
                <h3 style="margin: 0;">Employee Roster</h3>
                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                    <div class="account-search" id="accountSearchWrap">
                        <i class="fa-solid fa-magnifying-glass account-search-icon" aria-hidden="true"></i>
                        <input type="search" id="accountSearch" class="form-control" placeholder="Search accounts..." autocomplete="off" aria-label="Search accounts by name, email, station or status">
                    </div>
                    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#inviteAccountModal">+ Add Manager or Staff</button>
                </div>
            </div>
            <ul class="nav nav-tabs mt-3" id="accountRoleTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" type="button" role="tab" data-account-role-tab="4" aria-selected="true">Branch Managers <span class="badge bg-secondary ms-1"><?= count($managers) ?></span></button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" type="button" role="tab" data-account-role-tab="5" aria-selected="false">Staff <span class="badge bg-secondary ms-1"><?= count($staffAccounts) ?></span></button>
                </li>
            </ul>
            <table id="accountRosterTable" style="width: 100%; border-collapse: collapse; margin-top: 10px;">
                <thead>
                    <tr style="border-bottom: 2px solid var(--border); text-align: left;">
                        <th style="padding: 12px 8px;">Account Name</th>
                        <th style="padding: 12px 8px;">Account Email</th>
                        <th style="padding: 12px 8px;">Station Assignment</th>
                        <th style="padding: 12px 8px;">Status</th>
                        <th style="padding: 12px 8px; text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
if (!empty($managers)): ?>
                        <?php
foreach ($managers as $manager): ?>
                            <?php
$status = strtolower((string)($manager['status'] ?? ''));
                                $isActive = in_array($status, ['active', 'approved'], true);
                                $statusLabel = $isActive ? 'Active' : ucfirst($status ?: 'Inactive');
                                $statusColor = $isActive ? 'var(--success)' : 'var(--danger)';
                                $stationName = trim((string)($manager['station_name'] ?? ''));
                                $stationLocation = trim((string)($manager['location'] ?? ''));
                                $stationText = $stationName !== '' ? ($stationLocation !== '' ? $stationName . ' — ' . $stationLocation : $stationName) : 'Unassigned';
                            ?>
                            <tr style="border-bottom: 1px solid var(--border);" data-account-role="<?= (int)($manager['account_role'] ?? 4) ?>" data-search="<?= htmlspecialchars(strtolower(trim(
                                    ($manager['user_name'] ?? '') . ' ' .
                                    ($manager['email'] ?? '') . ' ' .
                                    $stationText . ' ' .
                                    $statusLabel
                                )), ENT_QUOTES) ?>">
                                <td style="padding: 12px 8px;"><?= htmlspecialchars($manager['user_name'] ?? 'N/A') ?></td>
                                <td style="padding: 12px 8px;"><?= htmlspecialchars($manager['email'] ?? 'N/A') ?></td>
                                <td style="padding: 12px 8px;"><?= htmlspecialchars($stationText) ?></td>
                                <td style="padding: 12px 8px;"><span style="color: <?= htmlspecialchars($statusColor) ?>; font-weight: bold;"><?= htmlspecialchars($statusLabel) ?></span></td>
                                <td style="padding: 12px 8px; text-align: right;">
                                    <form method="POST" action="" style="display: inline;" class="manager-status-form">
                                        <input type="hidden" name="toggle_manager_status" value="1">
                                        <input type="hidden" name="user_id" value="<?= (int)($manager['user_id'] ?? 0) ?>">
                                        <input type="hidden" name="account_role" value="<?= (int)($manager['account_role'] ?? 4) ?>">
                                        <input type="hidden" name="new_status" value="<?= $isActive ? 'disabled' : 'active' ?>">
                                        <button type="button" class="btn <?= $isActive ? 'btn-danger' : 'btn-success' ?> status-toggle-btn" style="padding: 6px 12px; font-size: 0.8rem;" data-action-text="<?= $isActive ? 'disable' : 'activate' ?>" data-user-name="<?= htmlspecialchars($manager['user_name'] ?? 'this manager', ENT_QUOTES) ?>">
                                            <?= $isActive ? 'Disable' : 'Activate' ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php
endforeach; ?>
                                            <?php endif; ?>
                                            <?php foreach ($staffAccounts as $staffAccount): ?>
                                                <?php
                                                    $staffStatus = ($staffAccount['status'] ?? '') === 'active' ? 'active' : 'disabled';
                                                    $staffStatusLabel = $staffStatus === 'active' ? 'Active' : 'Disabled';
                                                    $staffStatusColor = $staffStatus === 'active' ? 'var(--success)' : 'var(--danger)';
                                                    $staffStationName = trim((string) ($staffAccount['station_name'] ?? ''));
                                                    $staffLocation = trim((string) ($staffAccount['location'] ?? ''));
                                                    $staffStationText = $staffStationName !== ''
                                                        ? ($staffLocation !== '' ? $staffStationName . ' — ' . $staffLocation : $staffStationName)
                                                        : 'Unassigned';
                                                ?>
                                                <tr style="border-bottom: 1px solid var(--border);" data-account-role="5" data-search="<?= htmlspecialchars(strtolower(trim(
                                                    ($staffAccount['user_name'] ?? '') . ' ' .
                                                    ($staffAccount['email'] ?? '') . ' ' .
                                                    $staffStationText . ' ' .
                                                    $staffStatusLabel
                                                )), ENT_QUOTES) ?>">
                                                    <td style="padding: 12px 8px;"><?= htmlspecialchars($staffAccount['user_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td style="padding: 12px 8px;"><?= htmlspecialchars($staffAccount['email'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td style="padding: 12px 8px;"><?= htmlspecialchars($staffStationText, ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td style="padding: 12px 8px;"><span style="color: <?= htmlspecialchars($staffStatusColor, ENT_QUOTES, 'UTF-8') ?>; font-weight: bold;"><?= htmlspecialchars($staffStatusLabel, ENT_QUOTES, 'UTF-8') ?></span></td>
                                                    <td style="padding: 12px 8px; text-align: right;"><span class="text-muted">Managed by branch manager</span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <tr data-account-empty="true">
                                                <td colspan="5" style="padding: 16px 8px; text-align: center; color: var(--muted);"></td>
                                            </tr>
                                        </tbody>
            </table>
        </div>
    </div>

    <div class="modal fade" id="inviteAccountModal" tabindex="-1" aria-labelledby="inviteAccountModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content" style="background: var(--bg, #111827); color: var(--slate-50, #f8fafc); border: 1px solid rgba(255,255,255,0.08); border-radius: 18px;">
                <form method="POST" action="./invite_account.php" id="inviteAccountForm">
                    <div class="modal-header border-0 invite-modal-header">
                        <h5 class="modal-title" id="inviteAccountModalLabel">Add Branch Manager or Staff Account</h5>
                        <div class="invite-modal-actions">
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            <button type="button" class="btn btn-sm btn-outline-info invite-help-button" id="inviteAccountHelpButton" aria-label="Show account type and password information" aria-expanded="false" aria-controls="inviteAccountHelp">!</button>
                        </div>
                    </div>
                    <div class="modal-body">
                        <div id="inviteAccountHelp" class="invite-help-panel small p-3 mb-3" hidden>
                            <p class="mb-2"><strong>New Customer Account:</strong> Select this when the email entered above does not already have a customer account. The password you set will be used to create that account and sign in with that email.</p>
                            <p class="mb-2"><strong>Existing Customer Account:</strong> Select this when the email already belongs to a customer. Their current password is kept and is not changed.</p>
                            <p class="mb-0"><strong>Station assignment:</strong> Every branch manager and staff account must be assigned to a station.</p>
                        </div>

                        <div class="row g-3 align-items-end mb-3">
                            <div class="col-md-8">
                                <label class="form-label" for="inviteEmail">Email for Invitee Account</label>
                                <input class="form-control" type="email" id="inviteEmail" name="email" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="inviteRole">Role</label>
                                <select class="form-select" id="inviteRole" name="role" required><option value="manager">Branch Manager</option><option value="staff">Staff</option></select>
                            </div>
                        </div>

                        <div class="row g-3 mb-3" id="inviteStaffNameWrap" hidden>
                            <div class="col-md-6">
                                <label class="form-label" for="inviteStaffFullName">Staff Full Name</label>
                                <input class="form-control" type="text" id="inviteStaffFullName" name="full_name" maxlength="120" autocomplete="name">
                            </div>
                        </div>

                        <fieldset id="inviteAccountTypeOptions" class="border-0 p-0 m-0">
                            <legend class="visually-hidden">Account type</legend>
                            <div class="invite-account-type">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="invitee_account_type" id="inviteNewCustomerAccount" value="new">
                                    <label class="form-check-label" for="inviteNewCustomerAccount">New Account</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="invitee_account_type" id="inviteExistingCustomerAccount" value="existing" checked>
                                    <label class="form-check-label" for="inviteExistingCustomerAccount">Existing Customer Account</label>
                                </div>
                            </div>
                        </fieldset>

                        <div class="row g-3 invite-password-row mb-3" id="inviteManagerPasswordWrap" hidden>
                            <div class="col-md-6">
                                <label class="form-label" for="inviteManagerPassword">Set a Password</label>
                                <div class="invite-password-input">
                                    <input class="form-control" type="password" id="inviteManagerPassword" name="password" minlength="8" maxlength="15" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^a-zA-Z0-9]).{8,15}" title="Use 8–15 characters with uppercase and lowercase letters, a number, and a symbol." autocomplete="new-password">
                                    <button type="button" class="invite-password-toggle" data-password-target="inviteManagerPassword" aria-label="Show password" aria-pressed="false"><i class="fa-solid fa-eye" aria-hidden="true"></i></button>
                                </div>
                                <div class="form-text text-white-50">Use 8–15 characters with uppercase and lowercase letters, a number, and a symbol.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="inviteManagerPasswordConfirm">Confirm Password</label>
                                <div class="invite-password-input">
                                    <input class="form-control" type="password" id="inviteManagerPasswordConfirm" name="confirm_password" minlength="8" maxlength="15" autocomplete="new-password">
                                    <button type="button" class="invite-password-toggle" data-password-target="inviteManagerPasswordConfirm" aria-label="Show password confirmation" aria-pressed="false"><i class="fa-solid fa-eye" aria-hidden="true"></i></button>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                            <label class="form-label mb-0" for="inviteStation">Assign To Station <span id="inviteStationRequirement" class="text-white-50">(required)</span></label>
                            <button type="button" class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#quickStationModal" data-reopen-source="inviteAccountModal">+ Add Station</button>
                        </div>
                        <select class="form-select" id="inviteStation" name="station_id" required><option value="">Select a station</option><?php foreach ($stations as $station): ?><option value="<?= (int)$station['station_id'] ?>"><?= htmlspecialchars((string)$station['station_name'] . ' — ' . (string)$station['location']) ?></option><?php endforeach; ?></select>
                        <?php if (empty($stations)): ?>
                            <div class="alert alert-info mt-2 mb-0" role="alert">Create a station before adding a branch manager or staff account.</div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer border-0"><button type="button" class="btn btn-outline-secondary text-white" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Send Invitation</button></div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="quickStationModal" tabindex="-1" aria-labelledby="quickStationModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content" style="background: var(--bg, #111827); color: var(--slate-50, #f8fafc); border: 1px solid rgba(255,255,255,0.08); border-radius: 18px;">
                <form method="POST" action="./station.php" id="quickStationForm">
                    <input type="hidden" name="action" value="register">
                    <input type="hidden" name="return_to" value="account.php">
                    <input type="hidden" name="allow_unassigned_manager" value="1">
                    <div class="modal-header border-0">
                        <h5 class="modal-title" id="quickStationModalLabel">Add Station</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div id="quickStationStatus" class="alert d-none" role="status" aria-live="polite"></div>
                        <div class="row">
                            <div class="col-12 mb-3">
                                <label class="form-label text-light">Station Name</label>
                                <input type="text" name="station_name" class="form-control bg-dark text-light border-secondary" placeholder="e.g. Downtown Metro" required>
                            </div>
                            <div class="col-12 mb-3">
                                <label class="form-label text-light">Station Address</label>
                                <input type="text" name="station_address" class="form-control bg-dark text-light border-secondary" placeholder="e.g. No. 45, Main Road, Township">
                            </div>
                            <div class="col-12 mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label class="form-label text-light mb-0">Location (Coordinates)</label>
                                    <button type="button" class="btn btn-sm btn-outline-info" id="quickAddTrackLocBtn">
                                        <i class="bi bi-geo-alt-fill"></i> Track My Location
                                    </button>
                                </div>
                                <input type="text" id="quickStationLocation" name="location" class="form-control bg-dark text-light border-secondary" placeholder="Click the map or track location" required readonly>
                            </div>
                            <div class="col-12">
                                <div id="quickStationMap" aria-label="Choose station location on map" style="height: 220px; border-radius: 8px; border: 1px solid #373b3e;"></div>
                                <small class="text-secondary">Click the map or use the button above to select coordinates.</small>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0">
                        <button type="button" class="btn btn-outline-secondary text-white" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success" id="quickStationSubmit">Save Station</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="statusConfirmModal" tabindex="-1" aria-labelledby="statusConfirmModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="background: var(--bg, #111827); color: var(--slate-50, #f8fafc); border: 1px solid rgba(255,255,255,0.08); border-radius: 18px;">
                <div class="modal-header border-0">
                    <h5 class="modal-title" id="statusConfirmModalLabel">Confirm Action</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p id="statusConfirmText" class="mb-0">Are you sure you want to proceed?</p>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-outline-secondary text-white" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmStatusAction">Confirm</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        const statusModal = new bootstrap.Modal(document.getElementById('statusConfirmModal'));
        let quickStationMap;
        let quickStationMarker;
        let pendingForm = null;

        function parseQuickStationCoordinates(value) {
            const parts = (value || '').split(',');
            if (parts.length !== 2) return null;
            const latitude = Number.parseFloat(parts[0]);
            const longitude = Number.parseFloat(parts[1]);
            if (!Number.isFinite(latitude) || !Number.isFinite(longitude) || latitude < -90 || latitude > 90 || longitude < -180 || longitude > 180) {
                return null;
            }
            return L.latLng(latitude, longitude);
        }

        function setQuickStationLocation(input, latLng) {
            if (quickStationMarker) {
                quickStationMarker.setLatLng(latLng);
            } else {
                quickStationMarker = L.marker(latLng).addTo(quickStationMap);
            }
            input.value = latLng.lat.toFixed(6) + ', ' + latLng.lng.toFixed(6);
        }

        function initializeQuickStationMap() {
            const locationInput = document.getElementById('quickStationLocation');
            const initialLocation = parseQuickStationCoordinates(locationInput.value);
            if (!quickStationMap) {
                quickStationMap = L.map('quickStationMap').setView(initialLocation || [21.9588, 96.0891], initialLocation ? 14 : 6);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                }).addTo(quickStationMap);
                quickStationMap.on('click', event => setQuickStationLocation(locationInput, event.latlng));
            } else if (initialLocation) {
                quickStationMap.setView(initialLocation, 14);
            }

            if (initialLocation) {
                setQuickStationLocation(locationInput, initialLocation);
            }
            window.setTimeout(() => quickStationMap.invalidateSize(), 100);
        }

        document.querySelectorAll('[data-reopen-source]').forEach(button => {
            button.addEventListener('click', function () {
                const targetId = this.getAttribute('data-bs-target');
                const targetModal = targetId ? document.querySelector(targetId) : null;
                const sourceId = this.getAttribute('data-reopen-source');
                if (targetModal && sourceId) {
                    targetModal.setAttribute('data-reopen-source', sourceId);
                }
            });
        });

        document.querySelectorAll('.modal').forEach(modalEl => {
            modalEl.addEventListener('hidden.bs.modal', function () {
                const reopenSource = this.getAttribute('data-reopen-source');
                if (!reopenSource) {
                    return;
                }

                const sourceModal = document.getElementById(reopenSource);
                if (sourceModal) {
                    window.setTimeout(() => {
                        const modalInstance = bootstrap.Modal.getOrCreateInstance(sourceModal);
                        modalInstance.show();
                    }, 120);
                }

                this.removeAttribute('data-reopen-source');
            });
        });

        document.getElementById('quickStationModal').addEventListener('shown.bs.modal', initializeQuickStationMap);
        document.getElementById('quickAddTrackLocBtn').addEventListener('click', () => {
            if (!quickStationMap) {
                initializeQuickStationMap();
            }
            if (!navigator.geolocation) {
                alert('Location services are not available in this browser.');
                return;
            }
            navigator.geolocation.getCurrentPosition(position => {
                const location = L.latLng(position.coords.latitude, position.coords.longitude);
                setQuickStationLocation(document.getElementById('quickStationLocation'), location);
                quickStationMap.setView(location, 14);
            }, () => alert('Could not get your location. Check browser location permissions and try again.'), { enableHighAccuracy: true, timeout: 10000 });
        });

        const quickStationForm = document.getElementById('quickStationForm');
        quickStationForm.addEventListener('submit', async event => {
            event.preventDefault();
            const submitButton = document.getElementById('quickStationSubmit');
            const status = document.getElementById('quickStationStatus');
            submitButton.disabled = true;
            status.className = 'alert alert-info';
            status.textContent = 'Saving station...';

            try {
                const response = await fetch(quickStationForm.action, {
                    method: 'POST',
                    body: new FormData(quickStationForm),
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    credentials: 'same-origin'
                });
                const result = await response.json();
                if (!response.ok || !result.success) {
                    throw new Error(result.message || 'The station could not be created.');
                }

                const stationSelect = document.getElementById('inviteStation');
                const option = new Option(result.station.name + ' — ' + result.station.location, result.station.id, true, true);
                stationSelect.add(option);
                stationSelect.dispatchEvent(new Event('change', { bubbles: true }));

                status.className = 'alert alert-success';
                status.textContent = result.message;
                quickStationForm.reset();
                document.getElementById('quickStationLocation').value = '';
                if (quickStationMarker) {
                    quickStationMap.removeLayer(quickStationMarker);
                }
                quickStationMarker = null;
                bootstrap.Modal.getOrCreateInstance(document.getElementById('quickStationModal')).hide();
            } catch (error) {
                status.className = 'alert alert-danger';
                status.textContent = error.message || 'The station could not be created.';
            } finally {
                submitButton.disabled = false;
            }
        });

        document.querySelectorAll('.status-toggle-btn').forEach(function (button) {
            button.addEventListener('click', function () {
                const actionText = button.dataset.actionText || 'proceed';
                const userName = button.dataset.userName || 'this manager';
                const form = button.closest('.manager-status-form');

                pendingForm = form;
                const confirmText = document.getElementById('statusConfirmText');
                const confirmButton = document.getElementById('confirmStatusAction');

                confirmText.textContent = 'Are you sure you want to ' + actionText + ' manager "' + userName + '"?';
                confirmButton.classList.toggle('btn-danger', actionText === 'disable');
                confirmButton.classList.toggle('btn-success', actionText === 'activate');
                confirmButton.textContent = 'Confirm ' + actionText.charAt(0).toUpperCase() + actionText.slice(1);

                statusModal.show();
            });
        });

        document.getElementById('confirmStatusAction').addEventListener('click', function () {
            if (pendingForm) {
                pendingForm.submit();
            }
        });

        const accountRoleTabs = document.querySelectorAll('[data-account-role-tab]');
        const accountRows = document.querySelectorAll('[data-account-role]');
        let accountEmptyRow = document.querySelector('[data-account-empty]');
        const accountTableBody = accountEmptyRow ? accountEmptyRow.parentElement : document.querySelector('table tbody');
        let currentAccountRole = '4';

        function showAccountRole(role) {
            currentAccountRole = role;
            let visibleRows = 0;
            const term = (document.getElementById('accountSearch') || {}).value ? document.getElementById('accountSearch').value.trim().toLowerCase() : '';
            accountRows.forEach(function (row) {
                const matchesRole = row.dataset.accountRole === role;
                const haystack = row.dataset.search || '';
                const matchesSearch = term === '' || haystack.indexOf(term) !== -1;
                const isVisible = matchesRole && matchesSearch;
                row.style.display = isVisible ? '' : 'none';
                if (isVisible) {
                    visibleRows += 1;
                }
            });

            const emptyText = term !== ''
                ? 'No accounts match your search.'
                : (role === '5' ? 'No staff accounts found.' : 'No branch manager accounts found.');

            if (accountEmptyRow) {
                accountEmptyRow.style.display = visibleRows === 0 ? '' : 'none';
                accountEmptyRow.querySelector('td').textContent = emptyText;
            } else if (visibleRows === 0) {
                const emptyRow = document.createElement('tr');
                emptyRow.dataset.accountEmpty = 'true';
                emptyRow.innerHTML = '<td colspan="5" style="padding: 16px 8px; text-align: center; color: var(--muted);"></td>';
                accountTableBody.appendChild(emptyRow);
                accountEmptyRow = emptyRow;
                accountEmptyRow.querySelector('td').textContent = emptyText;
            }
        }

        accountRoleTabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                accountRoleTabs.forEach(function (item) {
                    const active = item === tab;
                    item.classList.toggle('active', active);
                    item.setAttribute('aria-selected', active ? 'true' : 'false');
                });
                showAccountRole(tab.dataset.accountRoleTab);
            });
        });

        showAccountRole('4');

        document.querySelectorAll('.invitation-response-alert').forEach(alert => {
            window.setTimeout(() => {
                alert.classList.add('fade');
                alert.addEventListener('transitionend', () => alert.remove(), { once: true });
                window.setTimeout(() => alert.remove(), 500);
            }, 10000);
        });

        // Live search filter for the account roster table
        (function () {
            const input = document.getElementById('accountSearch');
            const clearBtn = document.getElementById('accountSearchClear');
            const searchWrap = document.getElementById('accountSearchWrap');
            if (!input) return;

            function updateClearButton() {
                if (input.value.trim() !== '') {
                    searchWrap.classList.add('has-value');
                } else {
                    searchWrap.classList.remove('has-value');
                }
            }

            function applyFilter() {
                updateClearButton();
                showAccountRole(currentAccountRole);
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
        })();

        const inviteAccountForm = document.getElementById('inviteAccountForm');
        if (inviteAccountForm) {
            const inviteRole = document.getElementById('inviteRole');
            const accountTypeOptions = document.getElementById('inviteAccountTypeOptions');
            const newCustomerAccount = document.getElementById('inviteNewCustomerAccount');
            const existingCustomerAccount = document.getElementById('inviteExistingCustomerAccount');
            const managerPasswordWrap = document.getElementById('inviteManagerPasswordWrap');
            const managerPassword = document.getElementById('inviteManagerPassword');
            const managerPasswordConfirm = document.getElementById('inviteManagerPasswordConfirm');
            const staffNameWrap = document.getElementById('inviteStaffNameWrap');
            const staffFullName = document.getElementById('inviteStaffFullName');
            const inviteSubmitButton = inviteAccountForm.querySelector('button[type="submit"]');
            const inviteModal = document.getElementById('inviteAccountModal');
            const inviteStation = document.getElementById('inviteStation');
            const stationRequirement = document.getElementById('inviteStationRequirement');
            const helpButton = document.getElementById('inviteAccountHelpButton');
            const helpPanel = document.getElementById('inviteAccountHelp');

            document.querySelectorAll('#inviteAccountModal .invite-password-toggle').forEach(function (toggle) {
                toggle.addEventListener('click', function () {
                    const passwordInput = document.getElementById(toggle.dataset.passwordTarget);
                    const icon = toggle.querySelector('i');
                    const isVisible = passwordInput.type === 'password';

                    passwordInput.type = isVisible ? 'text' : 'password';
                    toggle.setAttribute('aria-pressed', String(isVisible));
                    toggle.setAttribute('aria-label', isVisible
                        ? (passwordInput.id === 'inviteManagerPassword' ? 'Hide password' : 'Hide password confirmation')
                        : (passwordInput.id === 'inviteManagerPassword' ? 'Show password' : 'Show password confirmation'));
                    icon.classList.toggle('fa-eye', !isVisible);
                    icon.classList.toggle('fa-eye-slash', isVisible);
                });
            });

            helpButton.addEventListener('click', function () {
                const isExpanded = helpButton.getAttribute('aria-expanded') === 'true';
                helpButton.setAttribute('aria-expanded', isExpanded ? 'false' : 'true');
                helpPanel.hidden = isExpanded;
            });

            const syncManagerPasswordField = function () {
                const isManager = inviteRole.value === 'manager';
                const isNewAccount = newCustomerAccount.checked;
                accountTypeOptions.hidden = !isManager;
                staffNameWrap.hidden = isManager;
                staffFullName.required = !isManager;
                managerPasswordWrap.hidden = isManager && !isNewAccount;
                managerPassword.required = !isManager || isNewAccount;
                managerPasswordConfirm.required = !isManager || isNewAccount;
                managerPassword.disabled = isManager && !isNewAccount;
                managerPasswordConfirm.disabled = isManager && !isNewAccount;
                inviteStation.required = true;
                stationRequirement.textContent = '(required)';
                if (!isNewAccount) {
                    managerPassword.value = '';
                    managerPasswordConfirm.value = '';
                    managerPasswordConfirm.setCustomValidity('');
                    document.querySelectorAll('#inviteAccountModal .invite-password-toggle').forEach(function (toggle) {
                        const passwordInput = document.getElementById(toggle.dataset.passwordTarget);
                        const icon = toggle.querySelector('i');
                        passwordInput.type = 'password';
                        toggle.setAttribute('aria-pressed', 'false');
                        toggle.setAttribute('aria-label', passwordInput.id === 'inviteManagerPassword'
                            ? 'Show password'
                            : 'Show password confirmation');
                        icon.classList.add('fa-eye');
                        icon.classList.remove('fa-eye-slash');
                    });
                }
            };

            inviteRole.addEventListener('change', syncManagerPasswordField);
            newCustomerAccount.addEventListener('change', syncManagerPasswordField);
            existingCustomerAccount.addEventListener('change', syncManagerPasswordField);
            syncManagerPasswordField();

            const validatePasswordConfirmation = function () {
                managerPasswordConfirm.setCustomValidity(
                    managerPasswordConfirm.value === managerPassword.value ? '' : 'Passwords do not match.'
                );
            };
            managerPassword.addEventListener('input', validatePasswordConfirmation);
            managerPasswordConfirm.addEventListener('input', validatePasswordConfirmation);

            inviteAccountForm.addEventListener('submit', function (event) {
                event.preventDefault();
                validatePasswordConfirmation();
                if (!inviteAccountForm.checkValidity()) {
                    inviteAccountForm.reportValidity();
                    return;
                }

                const statusContainer = document.querySelector('.container');
                let resultAlert = document.getElementById('inviteSubmissionNotice');
                if (!resultAlert) {
                    resultAlert = document.createElement('div');
                    resultAlert.id = 'inviteSubmissionNotice';
                    resultAlert.setAttribute('role', 'status');
                    statusContainer.insertBefore(resultAlert, statusContainer.firstChild);
                }
                resultAlert.className = 'alert alert-info';
                resultAlert.textContent = 'Processing invitation and sending email...';
                bootstrap.Modal.getOrCreateInstance(inviteModal).hide();
                inviteSubmitButton.disabled = true;

                fetch(inviteAccountForm.action, {
                    method: 'POST',
                    body: new FormData(inviteAccountForm),
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                })
                    .then(async function (response) {
                        const responseText = await response.text();
                        let result;
                        try {
                            result = JSON.parse(responseText);
                        } catch (error) {
                            throw new Error('The server returned an unexpected response. Please refresh the page and try again.');
                        }
                        if (!response.ok || !result.success) {
                            throw new Error(result.message || 'The invitation could not be processed.');
                        }
                        resultAlert.className = 'alert alert-' + (result.email_status === 'sent' ? 'success' : 'warning');
                        resultAlert.textContent = result.message;
                        inviteAccountForm.reset();
                        existingCustomerAccount.checked = true;
                        syncManagerPasswordField();
                    })
                    .catch(function (error) {
                        resultAlert.className = 'alert alert-danger';
                        resultAlert.textContent = error.message || 'The invitation could not be processed.';
                    })
                    .finally(function () {
                        inviteSubmitButton.disabled = false;
                    });
            });
        }
    </script>

</body>
<footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map & Pump | All Rights Reserved</footer>
</html>
