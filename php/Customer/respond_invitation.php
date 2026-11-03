<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../invitations_schema.php';
require_once __DIR__ . '/../BranchManager/_helpers.php';
require_once __DIR__ . '/license_expiry.php';
require_once __DIR__ . '/../Login/mail_templates.php';
require_once __DIR__ . '/../Login/account_password_setup.php';

try {
    map_pump_ensure_invitation_schema($pdo);
} catch (Throwable $error) {
    $_SESSION['customer_invitation_message'] = $error->getMessage();
    header('Location: dashboard.php');
    exit;
}

$invitationId = filter_var($_POST['invitation_id'] ?? ($_GET['invitation'] ?? null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$decision = strtolower(trim((string)($_POST['decision'] ?? $_GET['decision'] ?? '')));
$actionToken = trim((string) ($_POST['token'] ?? $_GET['token'] ?? ''));
$isEmailAction = $actionToken !== '';
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$redirectLocation = 'dashboard.php';
$responseMessage = '';
$setupEmailNotice = null;
$setupUrl = '';

function customer_invitation_render_email_response(string $message): void
{
    ?>
    <!doctype html>
    <html lang="en">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>Invitation response | Map &amp; Pump</title>
      <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    </head>
    <body class="bg-dark text-light d-flex min-vh-100 align-items-center justify-content-center p-3">
      <main class="card bg-black text-light border-secondary p-4" style="max-width: 34rem">
        <h1 class="h4">Invitation response</h1>
        <p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
        <a class="btn btn-primary align-self-start" href="../Login/login.php">Go to login</a>
      </main>
    </body>
    </html>
    <?php
}

function customer_invitation_render_email_confirmation(string $decision, string $token): void
{
    $actionLabel = $decision === 'accept' ? 'Accept invitation' : 'Decline invitation';
    $buttonClass = $decision === 'accept' ? 'btn-primary' : 'btn-outline-danger';
    ?>
    <!doctype html>
    <html lang="en">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>Confirm invitation response | Map &amp; Pump</title>
      <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    </head>
    <body class="bg-dark text-light d-flex min-vh-100 align-items-center justify-content-center p-3">
      <main class="card bg-black text-light border-secondary p-4" style="max-width: 34rem">
        <h1 class="h4">Confirm your response</h1>
        <p>Confirm that you want to <?= $decision === 'accept' ? 'accept' : 'decline' ?> this Map &amp; Pump invitation.</p>
        <form method="POST" action="respond_invitation.php">
          <input type="hidden" name="decision" value="<?= htmlspecialchars($decision, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
          <button type="submit" class="btn <?= $buttonClass ?>"><?= htmlspecialchars($actionLabel, ENT_QUOTES, 'UTF-8') ?></button>
        </form>
      </main>
    </body>
    </html>
    <?php
}

if ($isEmailAction && preg_match('/^[A-Za-z0-9_-]{40,80}$/', $actionToken) === 1) {
    try {
        $tokenColumn = $decision === 'accept' ? 'accept_token_hash' : ($decision === 'decline' ? 'decline_token_hash' : '');
        if ($tokenColumn !== '') {
            $tokenLookup = $pdo->prepare(
                "SELECT invitation_id, invited_user_id
                 FROM invitations
                 WHERE {$tokenColumn} = ? AND status = 'pending' AND token_expires_at > NOW()
                 LIMIT 1"
            );
            $tokenLookup->execute([hash('sha256', $actionToken)]);
            $tokenInvitation = $tokenLookup->fetch(PDO::FETCH_ASSOC);
            if ($tokenInvitation) {
                $invitationId = (int) $tokenInvitation['invitation_id'];
                $userId = (int) $tokenInvitation['invited_user_id'];
                if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                    customer_invitation_render_email_confirmation($decision, $actionToken);
                    exit;
                }
            }
        }
    } catch (Throwable $error) {
        error_log('[Customer invitation] Email token lookup failed: ' . $error->getMessage());
    }
}

$licenseExpiryState = (!$isEmailAction && $userId)
    ? customer_sync_license_expiry($pdo, $userId)
    : ['driver' => ['status' => 'active']];

if (empty($_SESSION['user_id']) && !$isEmailAction) {
    header('Location: ../Login/login.php');
    exit;
}

if (!$isEmailAction && ($licenseExpiryState['driver']['status'] ?? '') === 'expired') {
    header('Location: profile_page.php');
    exit;
}

if (!$invitationId || !in_array($decision, ['accept', 'decline'], true) || ($isEmailAction && $userId <= 0)) {
    $responseMessage = 'This invitation link is invalid, expired, or already used.';
    if (!$isEmailAction) {
        $_SESSION['customer_invitation_message'] = $responseMessage;
        header('Location: ' . ($userId ? 'dashboard.php' : '../Login/login.php'));
        exit;
    }
    http_response_code(400);
    customer_invitation_render_email_response($responseMessage);
    exit;
}

try {
    if ($decision === 'accept') {
        account_password_setup_ensure_schema_pdo($pdo);
    }
    $pdo->beginTransaction();
    $tokenExpiryCondition = $isEmailAction ? ' AND token_expires_at > NOW()' : '';
    $stmt = $pdo->prepare(
        "SELECT invitation_id, headquarter_id, invited_email, business_role_id, station_id, invited_user_id,
                created_account,
                accept_token_hash, decline_token_hash, token_expires_at
         FROM invitations
         WHERE invitation_id = ? AND invited_user_id = ? AND status = 'pending'
         {$tokenExpiryCondition}
         FOR UPDATE"
    );
    $stmt->execute([$invitationId, $userId]);
    $invitation = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invitation) {
        throw new RuntimeException('This invitation is no longer pending.');
    }
    if ($isEmailAction) {
        $tokenColumn = $decision === 'accept' ? 'accept_token_hash' : 'decline_token_hash';
        if (empty($invitation[$tokenColumn])
            || !hash_equals((string) $invitation[$tokenColumn], hash('sha256', $actionToken))) {
            throw new RuntimeException('This invitation link is invalid, expired, or already used.');
        }
    }
    $userDetails = $pdo->prepare('SELECT user_name, email, hashed_password FROM users WHERE user_id = ? LIMIT 1');
    $userDetails->execute([$userId]);
    $acceptedUser = $userDetails->fetch(PDO::FETCH_ASSOC);
    if (!$acceptedUser) {
        throw new RuntimeException('The invited user account no longer exists.');
    }
    $hqOwnerStmt = $pdo->prepare('SELECT headquarters_owner_id FROM headquarter WHERE headquarter_id = ? LIMIT 1');
    $hqOwnerStmt->execute([(int) $invitation['headquarter_id']]);
    $hqOwnerUserId = (int) ($hqOwnerStmt->fetchColumn() ?: 0);

    $branchId = null;
    if ($decision === 'accept') {
        $roleId = (int) $invitation['business_role_id'];
        $hqId = (int) $invitation['headquarter_id'];
        $branchId = $invitation['station_id'] === null ? null : (int) $invitation['station_id'];

        if ($branchId !== null) {
            $branchStmt = $pdo->prepare(
                "SELECT b.branch_id
                 FROM branches b
                 INNER JOIN station_business_scope sbs ON sbs.station_id = b.branch_id
                 WHERE b.branch_id = ? AND sbs.headquarter_id = ?
                 LIMIT 1"
            );
            $branchStmt->execute([$branchId, $hqId]);
            if (!$branchStmt->fetchColumn()) {
                throw new RuntimeException('This invitation is not linked to a valid branch under its HQ.');
            }
        } elseif ($roleId === 5) {
            throw new RuntimeException('A staff invitation needs a station assignment before it can be accepted.');
        }

        $userUpdateSql = "UPDATE users SET business_role_id = ?, headquarter_id = ?, status = 'active', updated_at = NOW()";
        $userParams = [$roleId, $hqId, $userId];
        if (column_exists($pdo, 'users', 'current_role_id')) {
            $userUpdateSql .= ', current_role_id = ?';
            $userParams = [$roleId, $hqId, $roleId, $userId];
        }
        $userUpdateSql .= " WHERE user_id = ?";
        $userUpdate = $pdo->prepare($userUpdateSql);
        $userUpdate->execute($userParams);

        $scope = $pdo->prepare(
            "INSERT INTO business_account_scope
                (user_id, business_role_id, headquarter_id, branch_manager_user_id, station_id, status)
             VALUES (?, ?, ?, NULL, ?, 'active')
             ON DUPLICATE KEY UPDATE business_role_id = VALUES(business_role_id), headquarter_id = VALUES(headquarter_id), station_id = VALUES(station_id), status = 'active'"
        );
        $scope->execute([$userId, $roleId, $hqId, $branchId]);

        if ($roleId === 4 && $branchId !== null) {
            $managerAssignment = $pdo->prepare(
                'UPDATE station_business_scope
                 SET branch_manager_user_id = ?
                 WHERE station_id = ? AND headquarter_id = ?'
            );
            $managerAssignment->execute([$userId, $branchId, $hqId]);

            $branchManager = $pdo->prepare(
                'UPDATE branches SET manager_user_id = ? WHERE branch_id = ?'
            );
            $branchManager->execute([$userId, $branchId]);
        }

        if ($roleId === 5) {
            $staffLookup = $pdo->prepare('SELECT staff_id FROM staff WHERE user_id = ? LIMIT 1');
            $staffLookup->execute([$userId]);
            $staffId = $staffLookup->fetchColumn();

            if ($staffId) {
                $staffUpdateSql = "UPDATE staff SET branch_id = ?, full_name = ?, is_active = 1";
                $staffUpdateParams = [$branchId, $acceptedUser['user_name']];

                if (column_exists($pdo, 'staff', 'email')) {
                    $staffUpdateSql .= ', email = ?';
                    $staffUpdateParams[] = $acceptedUser['email'] ?? '';
                }
                if (column_exists($pdo, 'staff', 'contact')) {
                    $staffUpdateSql .= ', contact = ?';
                    $staffUpdateParams[] = '';
                }
                $staffUpdateSql .= ' WHERE staff_id = ?';
                $staffUpdateParams[] = $staffId;

                $staffUpdate = $pdo->prepare($staffUpdateSql);
                $staffUpdate->execute($staffUpdateParams);
            } else {
                $hasEmail = column_exists($pdo, 'staff', 'email');
                $legacySql = 'SELECT staff_id FROM staff WHERE user_id IS NULL AND branch_id = ? AND full_name = ? LIMIT 1';
                if ($hasEmail) {
                    $legacySql = 'SELECT staff_id FROM staff WHERE user_id IS NULL AND branch_id = ? AND (full_name = ? OR email = ?) LIMIT 1';
                }
                $legacyLookup = $pdo->prepare($legacySql);
                $legacyParams = $hasEmail
                    ? [$branchId, $acceptedUser['user_name'], $acceptedUser['email']]
                    : [$branchId, $acceptedUser['user_name']];
                $legacyLookup->execute($legacyParams);
                $legacyStaffId = $legacyLookup->fetchColumn();

                if ($legacyStaffId) {
                    $linkLegacy = $pdo->prepare('UPDATE staff SET user_id = ?, is_active = 1 WHERE staff_id = ?');
                    $linkLegacy->execute([$userId, $legacyStaffId]);
                } else {
                    $lockName = 'staff_code_gen_' . (int)$branchId;
                    $gotLock = (int) $pdo->query("SELECT GET_LOCK('" . addslashes($lockName) . "', 10)")->fetchColumn();
                    if (!$gotLock) {
                        throw new RuntimeException('Could not acquire lock for staff code generation.');
                    }
                    try {
                        $codeStmt = $pdo->prepare(
                            "SELECT COALESCE(MAX(CAST(SUBSTRING(staff_code, 4) AS UNSIGNED)), 2090)
                             FROM staff
                             WHERE branch_id = ?
                               AND staff_code REGEXP '^ST-[0-9]+$'"
                        );
                        $codeStmt->execute([$branchId]);
                        $next = max((int) $codeStmt->fetchColumn(), 2090);
                        do {
                            $next++;
                            $staffCode = 'ST-' . $next;
                            $exists = $pdo->prepare('SELECT 1 FROM staff WHERE staff_code = ? LIMIT 1');
                            $exists->execute([$staffCode]);
                        } while ($exists->fetchColumn());

                        $columns = ['user_id', 'branch_id', 'staff_code', 'full_name', 'is_active'];
                        $values = ['?', '?', '?', '?', '1'];
                        $params = [$userId, $branchId, $staffCode, $acceptedUser['user_name']];

                        if (column_exists($pdo, 'staff', 'email')) {
                            $columns[] = 'email';
                            $values[] = '?';
                            $params[] = $acceptedUser['email'] ?? '';
                        }
                        if (column_exists($pdo, 'staff', 'contact')) {
                            $columns[] = 'contact';
                            $values[] = '?';
                            $params[] = '';
                        }
                        if (column_exists($pdo, 'staff', 'hired_at')) {
                            $columns[] = 'hired_at';
                            $values[] = 'CURDATE()';
                        }
                        if (column_exists($pdo, 'staff', 'created_at')) {
                            $columns[] = 'created_at';
                            $values[] = 'NOW()';
                        }

                        $sql = 'INSERT INTO staff (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')';
                        $stmtInsert = $pdo->prepare($sql);
                        $stmtInsert->execute($params);
                    } finally {
                        try { $pdo->query("SELECT RELEASE_LOCK('" . addslashes($lockName) . "')"); } catch (Throwable $_) {}
                    }
                }
            }
        }

        $needsPasswordSetup = trim((string) ($acceptedUser['hashed_password'] ?? '')) === '';
        if ($needsPasswordSetup) {
            $setupUrl = account_password_setup_create_link_pdo($pdo, $userId);
        }
    }

    $update = $pdo->prepare('UPDATE invitations SET status = ?, responded_at = NOW() WHERE invitation_id = ?');
    $update->execute([$decision === 'accept' ? 'accepted' : 'declined', $invitationId]);
    $pdo->prepare(
        'UPDATE invitations
         SET accept_token_hash = NULL, decline_token_hash = NULL, token_expires_at = NULL
         WHERE invitation_id = ?'
    )->execute([$invitationId]);
    if ($decision === 'decline' && (int) $invitation['created_account'] === 1) {
        $pdo->prepare("UPDATE users SET status = 'removed', updated_at = NOW() WHERE user_id = ?")->execute([$userId]);
    }

    if ($decision === 'accept' && (int) $invitation['business_role_id'] === 5 && $branchId !== null) {
        $managerUserId = null;
        $managerStmt = $pdo->prepare(
            "SELECT branch_manager_user_id
             FROM station_business_scope
             WHERE station_id = ? AND branch_manager_user_id IS NOT NULL
             LIMIT 1"
        );
        $managerStmt->execute([$branchId]);
        $managerUserId = (int) $managerStmt->fetchColumn();
        if ($managerUserId > 0) {
            $notificationMessage = sprintf(
                '%s accepted the staff invitation and is now active for %s.',
                $acceptedUser['user_name'] ?? 'A staff member',
                $branchId
            );
            $pdo->prepare(
                'INSERT INTO notifications (recipient_user_id, notification_type, subject, message, delivery_status)
                 VALUES (?, ?, ?, ?, "sent")'
            )->execute([
                $managerUserId,
                'staff_invitation_accepted',
                'Staff invitation accepted',
                $notificationMessage,
            ]);
        }
    } elseif ($decision === 'decline' && (int) $invitation['business_role_id'] === 5 && $branchId !== null) {
        $managerUserId = null;
        $managerStmt = $pdo->prepare(
            "SELECT branch_manager_user_id
             FROM station_business_scope
             WHERE station_id = ? AND branch_manager_user_id IS NOT NULL
             LIMIT 1"
        );
        $managerStmt->execute([$branchId]);
        $managerUserId = (int) $managerStmt->fetchColumn();
        if ($managerUserId > 0) {
            $notificationMessage = sprintf(
                '%s declined the staff invitation for branch %s.',
                $acceptedUser['user_name'] ?? 'A staff member',
                $branchId
            );
            $pdo->prepare(
                'INSERT INTO notifications (recipient_user_id, notification_type, subject, message, delivery_status)
                 VALUES (?, ?, ?, ?, "sent")'
            )->execute([
                $managerUserId,
                'staff_invitation_declined',
                'Staff invitation declined',
                $notificationMessage,
            ]);
        }
    }

    if ($hqOwnerUserId > 0) {
        $accepted = $decision === 'accept';
        $roleLabel = (int) $invitation['business_role_id'] === 4 ? 'Branch manager' : 'Staff';
        $managerDecisionMessage = sprintf(
            '%s (%s) %s the %s invitation%s.',
            (string) ($acceptedUser['user_name'] ?: 'The invitee'),
            (string) $acceptedUser['email'],
            $accepted ? 'accepted' : 'declined',
            strtolower($roleLabel),
            $branchId !== null ? ' for station #' . $branchId : ''
        );
        $pdo->prepare(
            'INSERT INTO notifications
             (recipient_user_id, notification_type, subject, message, delivery_status)
             VALUES (?, ?, ?, ?, \'sent\')'
        )->execute([
            $hqOwnerUserId,
            $accepted ? 'hq_invitation_accepted' : 'hq_invitation_declined',
            $roleLabel . ' invitation ' . ($accepted ? 'accepted' : 'declined'),
            $managerDecisionMessage,
        ]);
    }

    $pdo->commit();
    if ($setupUrl !== '') {
        $setupEmailNotice = mail_send_account_notice((string) $acceptedUser['email'], [
            'kind' => 'account_password_setup',
            'account_label' => (int) $invitation['business_role_id'] === 4 ? 'Branch Manager' : 'Staff',
            'account_name' => (string) $acceptedUser['user_name'],
            'setup_url' => $setupUrl,
        ]);
        if (($setupEmailNotice['status'] ?? '') === 'sent') {
            $responseMessage = 'Invitation accepted. A password setup email has been sent to your email address.';
        } else {
            error_log(sprintf(
                '[Customer invitation] Password setup email for user %d was not sent: %s (%s)',
                $userId,
                (string) ($setupEmailNotice['status'] ?? 'failed'),
                (string) ($setupEmailNotice['note'] ?? 'No delivery details available.')
            ));
            $responseMessage = 'Invitation accepted, but the password setup email was not sent ('
                . (string) ($setupEmailNotice['status'] ?? 'failed') . '). Please contact the HQ administrator.';
        }
    } else {
        $responseMessage = $decision === 'accept'
            ? 'Invitation accepted. Your business account is now active.'
            : 'Invitation declined.';
    }

    if (!$isEmailAction) {
        $_SESSION['customer_invitation_message'] = $responseMessage;
    }
    if (!$isEmailAction && $decision === 'accept' && (int)$invitation['business_role_id'] === 4) {
        $_SESSION['role_id'] = 4;
        $_SESSION['role_name'] = 'BranchManager';
        $_SESSION['business_role_id'] = 4;
        $_SESSION['business_role_name'] = 'BranchManager';
        $_SESSION['current_role_id'] = 4;
        $_SESSION['current_role_name'] = 'BranchManager';
        $_SESSION['headquarter_id'] = (int)$invitation['headquarter_id'];
        $_SESSION['branch_id'] = $invitation['station_id'] === null ? 0 : (int)$invitation['station_id'];
        $_SESSION['active_profile_mode'] = 'business';
        $redirectLocation = '/php/BranchManager/dashboard.php';
    }
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[Customer invitation] Response failed: ' . $error->getMessage());
    $responseMessage = $error->getMessage();
    if (!$isEmailAction) {
        $_SESSION['customer_invitation_message'] = $responseMessage;
    }
}

if ($isEmailAction) {
    customer_invitation_render_email_response($responseMessage);
    exit;
}

header('Location: ' . $redirectLocation);
exit;

function column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $column]);
    return (bool) $stmt->fetchColumn();
}
