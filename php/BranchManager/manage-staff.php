<?php
/**
 * manage-staff.php  â€”  Staff CRUD + status toggle + activity log
 * Actions handled here (form-post):
 *   ?action=add         -> POST full_name, email, contact
 *   ?action=edit        -> POST staff_id, full_name, email, contact
 *   ?action=delete      -> POST staff_id
 *   ?action=activate    -> POST staff_id
 *   ?action=disable     -> POST staff_id
 * Each returns JSON so the existing JS can keep working (AJAX-friendly).
 * Role is always 'Pump Attendant' and shift defaults to 'Morning'
 * (kept in DB for schema compatibility but not user-editable here).
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../profile_avatar.php';
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../Login/mailer.php';
require_once __DIR__ . '/../Login/mail_templates.php';
require_branch_manager();

$managerDisplayName = trim((string) ($_SESSION['user_name'] ?? 'Manager'));
$managerInitial = map_pump_profile_initial($managerDisplayName, 'M');

$ctx      = current_context($pdo);
$user     = $ctx['user'];
$branch   = $ctx['branch'];
$initials = initials($user['full_name']);
$bid      = (int)$branch['branch_id'];
$alerts       = notif_recent($pdo, (int)$user['user_id'], 5);
$unread_count = notif_unread_count($pdo, (int)$user['user_id']);
$has_unread   = $unread_count > 0;

function staff_has_column(PDO $pdo, string $column): bool {
    static $cache = [];
    $key = strtolower($column);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = $pdo->query(
            "SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'staff'
               AND LOWER(COLUMN_NAME) = LOWER('$column')"
        );
        $cache[$key] = (bool) ((int) $stmt->fetchColumn() > 0);
    } catch (Throwable $e) {
        $cache[$key] = false;
    }

    return $cache[$key];
}

function staff_has_email_column(PDO $pdo): bool {
    return staff_has_column($pdo, 'email');
}

function staff_email_in_use(PDO $pdo, string $email, ?int $excludeUserId = null, ?int $excludeStaffId = null): bool {
    $email = strtolower(trim($email));
    if ($email === '') {
        return false;
    }

    if (!staff_has_email_column($pdo)) {
        return false;
    }

    $staffSql = "SELECT staff_id FROM staff WHERE LOWER(COALESCE(email, '')) = LOWER(:email)";
    $staffParams = [':email' => $email];
    if ($excludeStaffId !== null) {
        $staffSql .= ' AND staff_id != :exclude_staff_id';
        $staffParams[':exclude_staff_id'] = $excludeStaffId;
    }
    $staffSql .= ' LIMIT 1';
    $staffStmt = $pdo->prepare($staffSql);
    $staffStmt->execute($staffParams);
    return (bool) $staffStmt->fetchColumn();
}

function staff_name_in_use(PDO $pdo, string $fullName, ?int $excludeStaffId = null): bool {
    $fullName = trim($fullName);
    if ($fullName === '') {
        return false;
    }

    $sql = "SELECT staff_id
            FROM staff
            WHERE branch_id = :branch_id
              AND LOWER(TRIM(full_name)) = LOWER(TRIM(:full_name))";
    $params = [
        ':branch_id' => (int) $GLOBALS['bid'],
        ':full_name' => $fullName,
    ];

    if ($excludeStaffId !== null) {
        $sql .= ' AND staff_id != :exclude_staff_id';
        $params[':exclude_staff_id'] = (int) $excludeStaffId;
    }

    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (bool) $stmt->fetchColumn();
}

function staff_contact_in_use(PDO $pdo, string $contact, ?int $excludeStaffId = null): bool {
    $contact = trim($contact);
    if ($contact === '') {
        return false;
    }

    $sql = "SELECT staff_id
            FROM staff
            WHERE branch_id = :branch_id
              AND LOWER(TRIM(contact)) = LOWER(TRIM(:contact))";
    $params = [
        ':branch_id' => (int) $GLOBALS['bid'],
        ':contact' => $contact,
    ];

    if ($excludeStaffId !== null) {
        $sql .= ' AND staff_id != :exclude_staff_id';
        $params[':exclude_staff_id'] = (int) $excludeStaffId;
    }

    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (bool) $stmt->fetchColumn();
}

function staff_name_contact_in_use(PDO $pdo, string $fullName, string $contact, ?int $excludeStaffId = null): bool {
    return staff_name_in_use($pdo, $fullName, $excludeStaffId) || staff_contact_in_use($pdo, $contact, $excludeStaffId);
}

function staff_identity_in_use(PDO $pdo, string $fullName, string $contact, ?int $excludeStaffId = null): bool {
    return staff_name_contact_in_use($pdo, $fullName, $contact, $excludeStaffId);
}

function resolve_manager_headquarter_id(PDO $pdo, int $managerUserId, int $branchId): int {
    $managerUserId = (int) $managerUserId;
    $branchId = (int) $branchId;

    $candidateQueries = [
        [
            "SELECT sbs.headquarter_id
             FROM station_business_scope sbs
             WHERE sbs.station_id = :branch_id
               AND sbs.branch_manager_user_id = :manager_user_id
               AND sbs.headquarter_id IS NOT NULL
             LIMIT 1",
            [':branch_id' => $branchId, ':manager_user_id' => $managerUserId],
        ],
        [
            "SELECT sbs.headquarter_id
             FROM station_business_scope sbs
             WHERE sbs.branch_manager_user_id = :manager_user_id
               AND sbs.headquarter_id IS NOT NULL
             ORDER BY sbs.headquarter_id ASC
             LIMIT 1",
            [':manager_user_id' => $managerUserId],
        ],
        [
            "SELECT bas.headquarter_id
             FROM business_account_scope bas
             WHERE bas.user_id = :manager_user_id
               AND bas.headquarter_id IS NOT NULL
             LIMIT 1",
            [':manager_user_id' => $managerUserId],
        ],
        [
            "SELECT h.headquarter_id
             FROM headquarter h
             ORDER BY h.headquarter_id ASC
             LIMIT 1",
            [],
        ],
    ];

    foreach ($candidateQueries as [$sql, $params]) {
        $stmt = $pdo->prepare($sql);
        if ($params) {
            $stmt->execute($params);
        } else {
            $stmt->execute();
        }
        $hqId = (int) $stmt->fetchColumn();
        if ($hqId > 0) {
            return $hqId;
        }
    }

    return 0;
}

function next_available_staff_code(PDO $pdo, int $branchId): string {
    $base = 2090;
    $baseStmt = $pdo->prepare(
        "SELECT COALESCE(MAX(CAST(SUBSTRING(staff_code, 4) AS UNSIGNED)), :base)
         FROM staff
         WHERE branch_id = :b
           AND staff_code REGEXP '^ST-[0-9]+$'"
    );
    $baseStmt->execute([':base' => $base, ':b' => $branchId]);
    $next = max((int) $baseStmt->fetchColumn(), $base);

    while (true) {
        $next++;
        $code = 'ST-' . $next;
        $exists = $pdo->prepare("SELECT 1 FROM staff WHERE staff_code = :code LIMIT 1");
        $exists->execute([':code' => $code]);
        if (!$exists->fetchColumn()) {
            return $code;
        }
    }
}

function map_pump_customer_role_id(PDO $pdo): int {
    $stmt = $pdo->prepare(
        "SELECT role_id
         FROM roles
         WHERE LOWER(TRIM(role_name)) = 'customer'
         ORDER BY role_id ASC
         LIMIT 1"
    );
    $stmt->execute();
    $roleId = (int) $stmt->fetchColumn();
    return $roleId > 0 ? $roleId : 6;
}

function map_pump_staff_role_id(PDO $pdo): int {
    $stmt = $pdo->prepare(
        "SELECT role_id
         FROM roles
         WHERE LOWER(TRIM(role_name)) IN ('staff', 'pump attendant', 'pumpattendant')
         ORDER BY role_id ASC
         LIMIT 1"
    );
    $stmt->execute();
    $roleId = (int) $stmt->fetchColumn();
    return $roleId > 0 ? $roleId : 0;
}

function map_pump_branch_company_name(PDO $pdo, int $branchId): string {
    $stmt = $pdo->prepare(
        "SELECT COALESCE(hq.company_name, b.branch_name, 'Map & Pump')
         FROM branches b
         LEFT JOIN station_business_scope sbs ON sbs.station_id = b.branch_id
         LEFT JOIN headquarter hq ON hq.headquarter_id = sbs.headquarter_id
         WHERE b.branch_id = :bid
         ORDER BY sbs.headquarter_id IS NOT NULL DESC, sbs.headquarter_id ASC
         LIMIT 1"
    );
    $stmt->execute([':bid' => $branchId]);
    $name = trim((string) $stmt->fetchColumn());
    return $name !== '' ? $name : 'Map & Pump';
}

function map_pump_staff_invitation_link(int $invitationId): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = '/php/Customer/respond_invitation.php?invitation=' . rawurlencode((string) $invitationId) . '&decision=accept';
    return $host ? $scheme . '://' . $host . $path : $path;
}

function map_pump_send_staff_invitation_email(string $toEmail, string $subject, string $body, array $context = []): bool {
    $toEmail = trim($toEmail);
    if ($toEmail === '') {
        return false;
    }

    try {
        $noticeContext = [
            'kind' => 'hq_invitation',
            'company_name' => $context['company_name'] ?? 'Map & Pump',
            'role_name' => $context['role_name'] ?? 'Staff',
            'station_name' => $context['station_name'] ?? '',
            'account_active' => $context['account_active'] ?? false,
            'password_setup_sent' => $context['password_setup_sent'] ?? false,
        ];

        $result = mail_send_account_notice($toEmail, $noticeContext);
        return !empty($result['sent']);
    } catch (Throwable $e) {
        error_log('[Map & Pump invite email] ' . $e->getMessage());
        return false;
    }
}

function map_pump_branch_manager_hq(PDO $pdo, int $branchId, int $managerUserId): int {
    $branchId = (int) $branchId;
    $managerUserId = (int) $managerUserId;

    if ($branchId <= 0) {
        throw new RuntimeException('The selected branch is invalid.');
    }
    if ($managerUserId <= 0) {
        throw new RuntimeException('Branch manager context is missing. Please sign in again.');
    }

    $branchExists = $pdo->prepare('SELECT branch_id FROM branches WHERE branch_id = :branch_id LIMIT 1');
    $branchExists->execute([':branch_id' => $branchId]);
    if (!$branchExists->fetchColumn()) {
        throw new RuntimeException('The selected branch could not be found.');
    }

    $scopeStmt = $pdo->prepare(
        "SELECT sbs.headquarter_id
         FROM branches b
         INNER JOIN station_business_scope sbs ON sbs.station_id = b.branch_id
         INNER JOIN headquarter hq ON hq.headquarter_id = sbs.headquarter_id
         WHERE b.branch_id = :branch_id
           AND sbs.headquarter_id IS NOT NULL
           AND (b.manager_user_id = :manager_user_id OR sbs.branch_manager_user_id = :manager_user_id)
         LIMIT 1"
    );
    $scopeStmt->execute([':branch_id' => $branchId, ':manager_user_id' => $managerUserId]);
    $hqId = (int) $scopeStmt->fetchColumn();

    if ($hqId <= 0) {
        throw new RuntimeException('This branch is not assigned to a valid Headquarter for your branch manager account.');
    }

    $validScope = $pdo->prepare(
        "SELECT 1
         FROM station_business_scope sbs
         WHERE sbs.station_id = :branch_id
           AND sbs.headquarter_id = :hq_id
           AND (sbs.branch_manager_user_id = :manager_user_id OR EXISTS (
               SELECT 1
               FROM branches b
               WHERE b.branch_id = :branch_id
                 AND b.manager_user_id = :manager_user_id
           ))
         LIMIT 1"
    );
    $validScope->execute([':branch_id' => $branchId, ':hq_id' => $hqId, ':manager_user_id' => $managerUserId]);
    if (!$validScope->fetchColumn()) {
        throw new RuntimeException('The selected branch is not linked to a valid Headquarter for this branch manager.');
    }

    return $hqId;
}

function map_pump_existing_customer_invitation(PDO $pdo, int $branchId, string $email, string $hireMessage, int $managerUserId): array {
    $email = strtolower(trim($email));
    $hireMessage = trim($hireMessage);
    if ($email === '') {
        throw new RuntimeException('Email address is required.');
    }
    if ($hireMessage === '') {
        throw new RuntimeException('Please add a hire message before sending the invite.');
    }

    $roleId = map_pump_staff_role_id($pdo);
    if ($roleId <= 0) {
        throw new RuntimeException('This system does not have a Staff role configured yet.');
    }

    $customerStmt = $pdo->prepare(
        "SELECT user_id, user_name, email, business_role_id, current_role_id
         FROM users
         WHERE LOWER(TRIM(email)) = LOWER(TRIM(:email))
         LIMIT 1"
    );
    $customerStmt->execute([':email' => $email]);
    $customer = $customerStmt->fetch(PDO::FETCH_ASSOC);
    if (!$customer) {
        throw new RuntimeException('No existing customer account was found for this email.');
    }

    $customerUserId = (int) ($customer['user_id'] ?? 0);
    $pendingStmt = $pdo->prepare(
        "SELECT invitation_id
         FROM invitations
         WHERE invited_user_id = :uid
           AND station_id = :branch_id
           AND status = 'pending'
         LIMIT 1"
    );
    $pendingStmt->execute([':uid' => $customerUserId, ':branch_id' => $branchId]);
    if ($pendingStmt->fetchColumn()) {
        throw new RuntimeException('A pending staff invitation already exists for this customer in your branch.');
    }

    $staffStmt = $pdo->prepare('SELECT staff_id FROM staff WHERE user_id = :uid LIMIT 1');
    $staffStmt->execute([':uid' => $customerUserId]);
    if ($staffStmt->fetchColumn()) {
        throw new RuntimeException('This customer already has a staff record and cannot be invited again.');
    }

    if (((int) ($customer['business_role_id'] ?? 0) === $roleId) || ((int) ($customer['current_role_id'] ?? 0) === $roleId)) {
        throw new RuntimeException('This customer already has the Staff role assigned.');
    }

    $hqId = map_pump_branch_manager_hq($pdo, $branchId, $managerUserId);
    $companyName = map_pump_branch_company_name($pdo, $branchId);
    $roleName = 'Staff';

    $insert = $pdo->prepare(
        "INSERT INTO invitations (headquarter_id, invited_user_id, invited_email, business_role_id, station_id, status, created_at)
         VALUES (:hq, :uid, :email, :role, :branch, 'pending', NOW())"
    );
    $insert->execute([
        ':hq' => $hqId,
        ':uid' => $customerUserId,
        ':email' => $email,
        ':role' => $roleId,
        ':branch' => $branchId,
    ]);
    $invitationId = (int) $pdo->lastInsertId();

    $link = map_pump_staff_invitation_link($invitationId);
    $body = '<p>Hello,</p><p>You have been invited to join <strong>' . htmlspecialchars($companyName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong> as a <strong>' . htmlspecialchars($roleName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong>.</p><p><strong>Reason:</strong><br>' . nl2br(htmlspecialchars($hireMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p><p>Accept the invitation here:</p><p><a href="' . htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">Accept invitation</a></p><p>Thank you,<br>' . htmlspecialchars($companyName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    $subject = 'Invitation to join ' . $companyName . ' as a ' . $roleName;

    $notifId = notif_create($pdo, null, $email, 'staff_invitation', $subject, $body, $invitationId);
    $pdo->prepare('UPDATE invitations SET notification_id = ? WHERE invitation_id = ?')->execute([$notifId, $invitationId]);
    $emailSent = map_pump_send_staff_invitation_email($email, $subject, $body, [
        'company_name' => $companyName,
        'role_name' => 'Staff',
        'station_name' => $companyName,
        'account_active' => false,
    ]);
    $pdo->prepare('UPDATE notifications SET delivery_status = ? WHERE notification_id = ?')->execute([$emailSent ? 'sent' : 'failed', $notifId]);

    $pdo->prepare(
        'INSERT INTO notifications (recipient_user_id, notification_type, subject, message, delivery_status)
         VALUES (?, ?, ?, ?, "sent")'
    )->execute([
        $managerUserId,
        'staff_invitation_sent',
        'Staff invite sent',
        'Invite sent to ' . $email . ' for ' . $companyName . '.'
    ]);

    return ['invitation_id' => $invitationId, 'role_id' => $roleId, 'customer_user_id' => $customerUserId];
}

// -------- POST action router --------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) json_out(['ok' => false, 'error' => 'CSRF failed'], 403);
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add') {
            $email = trim((string)($_POST['email'] ?? ''));
            $hireMessage = trim((string)($_POST['hire_message'] ?? ''));
            $accountType = strtolower(trim((string)($_POST['account_type'] ?? 'customer')));
            $password = (string)($_POST['password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');

            if ($email === '') {
                json_out(['ok' => false, 'error' => 'Email address is required.']);
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                json_out(['ok' => false, 'error' => 'Invalid email address']);
            }
            if ($hireMessage === '') {
                json_out(['ok' => false, 'error' => 'Please add a hire message before sending the invite.']);
            }

            if ($accountType === 'standalone') {
                if ($password === '' || $confirmPassword === '') {
                    json_out(['ok' => false, 'error' => 'Please provide and confirm a staff password.']);
                }
                if ($password !== $confirmPassword) {
                    json_out(['ok' => false, 'error' => 'The password and confirmation do not match.']);
                }
                if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,15}$/', $password)) {
                    json_out(['ok' => false, 'error' => 'Use 8-15 characters with upper and lower case letters, a number, and a symbol.']);
                }
                if (staff_email_in_use($pdo, $email)) {
                    json_out(['ok' => false, 'error' => 'This email is already in use by another staff account.']);
                }
            }

            try {
                $pdo->beginTransaction();
                if ($accountType === 'standalone') {
                    $fullName = trim((string)($_POST['full_name'] ?? ''));
                    $contact = trim((string)($_POST['contact'] ?? ''));
                    $contact = $contact === '' ? null : $contact;
                    if ($fullName === '') {
                        $pdo->rollBack();
                        json_out(['ok' => false, 'error' => 'Full name is required for standalone staff accounts.']);
                    }

                    $staffCode = next_available_staff_code($pdo, $bid);
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $insert = $pdo->prepare(
                        "INSERT INTO staff (branch_id, full_name, email, staff_password, contact, staff_code, user_id, is_active, hired_at, created_at, updated_at)
                         VALUES (:branch_id, :full_name, :email, :staff_password, :contact, :staff_code, NULL, 1, CURDATE(), NOW(), NOW())"
                    );
                    $insert->execute([
                        ':branch_id' => $bid,
                        ':full_name' => $fullName,
                        ':email' => $email,
                        ':staff_password' => $hash,
                        ':contact' => $contact,
                        ':staff_code' => $staffCode,
                    ]);

                    $staffId = (int) $pdo->lastInsertId();
                    $companyName = map_pump_branch_company_name($pdo, $bid);
                    $roleName = 'Staff';
                    $loginLink = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/php/Login/login.php';
                    $body = '<p>Hello ' . htmlspecialchars($fullName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ',</p>'
                        . '<p>You have been invited to join <strong>' . htmlspecialchars($companyName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong> as a <strong>' . htmlspecialchars($roleName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong>.</p>'
                        . '<p>Your email: <strong>' . htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong></p>'
                        . '<p>Your password: <strong>' . htmlspecialchars($password, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong></p>'
                        . '<p style="margin: 24px 0;">'
                        . '<a href="' . htmlspecialchars($loginLink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" style="display:inline-block;background-color:#0d6efd;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:6px;font-weight:600;">Login to Map & Pump</a>'
                        . '</p>'
                        . '<p>After signing in, you can use your existing email and password to access your staff account.</p>';
                    $subject = 'Invitation to join ' . $companyName . ' as a ' . $roleName;
                    $emailSent = map_pump_send_staff_invitation_email($email, $subject, $body);
                    $notificationMessage = $emailSent ? 'Standalone staff account created and login instructions sent.' : 'Standalone staff account created. Email delivery could not be completed; share the password securely with the staff member.';
                    $pdo->commit();
                    log_activity($pdo, $bid, 'user', $user['user_id'], $user['full_name'], 'Created standalone staff account: ' . $fullName . ' (' . $staffCode . ')');
                    json_out(['ok' => true, 'message' => $notificationMessage, 'staff_id' => $staffId]);
                }

                $result = map_pump_existing_customer_invitation($pdo, $bid, $email, $hireMessage, (int) $user['user_id']);
                $pdo->commit();

                log_activity($pdo, $bid, 'user', $user['user_id'], $user['full_name'], 'Sent staff invitation to ' . $email);
                json_out([
                    'ok' => true,
                    'message' => 'Staff invite sent successfully.',
                    'invitation_id' => (int) ($result['invitation_id'] ?? 0),
                ]);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                json_out(['ok' => false, 'error' => $e->getMessage()], 400);
            }
        }

        if ($action === 'edit') {
            $sid       = (int)($_POST['staff_id'] ?? 0);
            $full_name = trim($_POST['full_name'] ?? '');
            $email     = trim($_POST['email'] ?? '');
            $contact   = trim($_POST['contact'] ?? '');

            if ($full_name === '') json_out(['ok' => false, 'error' => 'Name required']);
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                json_out(['ok' => false, 'error' => 'Invalid email address']);
            }

            $staffRow = $pdo->prepare("SELECT staff_code, user_id, email FROM staff WHERE staff_id = :sid AND branch_id = :b LIMIT 1");
            $staffRow->execute([':sid' => $sid, ':b' => $bid]);
            $staffData = $staffRow->fetch(PDO::FETCH_ASSOC);
            if (!$staffData) {
                json_out(['ok' => false, 'error' => 'Staff record not found.']);
            }

            if ($email !== '' && staff_email_in_use($pdo, $email, null, $sid)) {
                json_out(['ok' => false, 'error' => 'This email is already in use by another staff record.']);
            }
            if (!preg_match('/^\d{11}$/', $contact)) {
                json_out(['ok' => false, 'error' => 'Phone number must be exactly 11 digits and contain only numbers.']);
            }
            if (staff_name_in_use($pdo, $full_name, $sid)) {
                json_out(['ok' => false, 'error' => 'A staff member with the same name already exists in this branch.']);
            }
            if (staff_contact_in_use($pdo, $contact, $sid)) {
                json_out(['ok' => false, 'error' => 'A staff member with the same phone number already exists in this branch.']);
            }

            $setClauses = ['full_name = :n', 'contact = :p'];
            $params = [':n' => $full_name, ':p' => $contact, ':sid' => $sid, ':b' => $bid];
            if (staff_has_column($pdo, 'email')) {
                $setClauses[] = 'email = :e';
                $params[':e'] = $email;
            }
            if (staff_has_column($pdo, 'updated_at')) {
                $setClauses[] = 'updated_at = NOW()';
            }

            $sql = 'UPDATE staff SET ' . implode(', ', $setClauses) . ' WHERE staff_id = :sid AND branch_id = :b';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            log_activity($pdo, $bid, 'user', $user['user_id'], $user['full_name'], "Edited staff #{$sid}");
            json_out(['ok' => true, 'staff_code' => $staffData['staff_code'] ?? '']);
        }

        if ($action === 'delete') {
            $sid = (int)($_POST['staff_id'] ?? 0);

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT staff_id, user_id, full_name, staff_code FROM staff WHERE staff_id = :sid AND branch_id = :branch_id FOR UPDATE");
            $stmt->execute([':sid' => $sid, ':branch_id' => $bid]);
            $target = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$target) {
                $pdo->rollBack();
                json_out(['ok' => false, 'error' => 'That staff account is not available in your branch.'], 404);
            }

            if (!empty($target['user_id'])) {
                $customerRoleId = map_pump_customer_role_id($pdo);
                $updateUserRole = $pdo->prepare("UPDATE users SET business_role_id = :role_id, current_role_id = :role_id WHERE user_id = :user_id");
                $updateUserRole->execute([':role_id' => $customerRoleId, ':user_id' => (int)$target['user_id']]);
            }

            $delete = $pdo->prepare("DELETE FROM staff WHERE staff_id = :sid AND branch_id = :branch_id");
            $delete->execute([':sid' => $sid, ':branch_id' => $bid]);
            if ($delete->rowCount() !== 1) {
                $pdo->rollBack();
                json_out(['ok' => false, 'error' => 'The staff account could not be deleted. Please refresh and try again.'], 409);
            }
            $pdo->commit();

            $staffLabel = "{$target['full_name']} ({$target['staff_code']})";
            log_activity($pdo, $bid, 'user', $user['user_id'], $user['full_name'], "Deleted staff account: {$staffLabel}");
            json_out(['ok' => true]);
        }

        if ($action === 'activate' || $action === 'disable') {
            $sid = (int)($_POST['staff_id'] ?? 0);
            $targetAction = $action === 'activate' ? 'activate' : 'disable';
            $staffRoleId = map_pump_staff_role_id($pdo);
            $customerRoleId = map_pump_customer_role_id($pdo);
            if ($staffRoleId <= 0) {
                json_out(['ok' => false, 'error' => 'The staff role is not configured for this system.'], 409);
            }

            $pdo->beginTransaction();
            $targetStmt = $pdo->prepare(
                'SELECT s.staff_id, s.user_id, s.branch_id, s.full_name, s.staff_code, s.email, s.is_active
                 FROM staff s
                 WHERE s.staff_id = :sid AND s.branch_id = :branch_id
                 FOR UPDATE'
            );
            $targetStmt->execute([':sid' => $sid, ':branch_id' => $bid]);
            $target = $targetStmt->fetch(PDO::FETCH_ASSOC);
            if (!$target) {
                $pdo->rollBack();
                json_out(['ok' => false, 'error' => 'That staff member is not available in your branch.'], 404);
            }

            $userId = (int)($target['user_id'] ?? 0);
            $targetEmail = trim((string)($target['email'] ?? ''));
            if ($userId > 0) {
                $newRoleId = $targetAction === 'activate' ? $staffRoleId : $customerRoleId;
                $userRoleUpdate = $pdo->prepare(
                    'UPDATE users
                     SET business_role_id = :business_role_id,
                         current_role_id = :current_role_id,
                         updated_at = NOW()
                     WHERE user_id = :user_id'
                );
                $userRoleUpdate->execute([
                    ':business_role_id' => $newRoleId,
                    ':current_role_id' => $newRoleId,
                    ':user_id' => $userId,
                ]);
            }

            $statusColumn = staff_has_column($pdo, 'status') ? 'status' : 'is_active';
            $statusSql = $statusColumn === 'status'
                ? 'UPDATE staff SET status = :status_value, updated_at = NOW() WHERE staff_id = :sid AND branch_id = :branch_id'
                : 'UPDATE staff SET is_active = :is_active, updated_at = NOW() WHERE staff_id = :sid AND branch_id = :branch_id';
            $statusParams = [':sid' => $sid, ':branch_id' => $bid];
            if ($statusColumn === 'status') {
                $statusParams[':status_value'] = $targetAction === 'activate' ? 'active' : 'disabled';
            } else {
                $statusParams[':is_active'] = $targetAction === 'activate' ? 1 : 0;
            }
            $statusStmt = $pdo->prepare($statusSql);
            $statusStmt->execute($statusParams);

            $expectedStatusValue = $targetAction === 'activate'
                ? ($statusColumn === 'status' ? 'active' : 1)
                : ($statusColumn === 'status' ? 'disabled' : 0);
            $actualStatusSql = $statusColumn === 'status'
                ? 'SELECT status FROM staff WHERE staff_id = :sid AND branch_id = :branch_id LIMIT 1'
                : 'SELECT is_active FROM staff WHERE staff_id = :sid AND branch_id = :branch_id LIMIT 1';
            $actualStatusStmt = $pdo->prepare($actualStatusSql);
            $actualStatusStmt->execute([':sid' => $sid, ':branch_id' => $bid]);
            $actualStatusValue = $actualStatusStmt->fetchColumn();
            if ((string) $actualStatusValue !== (string) $expectedStatusValue) {
                error_log(sprintf(
                    '[manage-staff] status-toggle failed for staff_id=%d action=%s expected=%s actual=%s rowcount=%d',
                    $sid,
                    $targetAction,
                    var_export($expectedStatusValue, true),
                    var_export($actualStatusValue, true),
                    $statusStmt->rowCount()
                ));
                $pdo->rollBack();
                json_out(['ok' => false, 'error' => 'Unable to update the staff status for this branch.'], 409);
            }

            $targetUserEmail = $targetEmail !== '' ? $targetEmail : '';
            if ($targetUserEmail === '') {
                $targetUserEmailSql = $pdo->prepare('SELECT email FROM users WHERE user_id = :user_id LIMIT 1');
                $targetUserEmailSql->execute([':user_id' => $userId]);
                $targetUserEmail = trim((string) $targetUserEmailSql->fetchColumn());
            }
            error_log(sprintf(
                '[manage-staff:%s] about_to_create_notification recipient_user_id=%d staff_id=%d targetUserEmail=%s',
                $targetAction,
                $userId,
                $sid,
                $targetUserEmail !== '' ? $targetUserEmail : 'EMPTY'
            ));

            $notificationId = staff_status_notification_create(
                $pdo,
                $userId,
                $sid,
                $targetAction,
                $targetUserEmail
            );
            error_log(sprintf(
                '[manage-staff:%s] notification_created notification_id=%d recipient_user_id=%d staff_id=%d',
                $targetAction,
                $notificationId,
                $userId,
                $sid
            ));
            if ($notificationId <= 0) {
                $pdo->rollBack();
                json_out(['ok' => false, 'error' => 'Unable to record the staff status notification.'], 409);
            }

            $pdo->commit();
            log_activity($pdo, $bid, 'user', $user['user_id'], $user['full_name'], ($targetAction === 'activate' ? 'Activated' : 'Disabled') . " staff #{$sid}");
            json_out(['ok' => true, 'status' => $targetAction === 'activate' ? 'active' : 'disabled', 'notification_id' => $notificationId]);
        }

        json_out(['ok' => false, 'error' => 'Unknown action'], 400);
    } catch (PDOException $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
        json_out(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

// -------- Staff log viewer (modal) --------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'staff_logs') {
    header('Content-Type: application/json');
    $logStaffId = (int) ($_GET['staff_id'] ?? 0);
    $logStart = trim((string) ($_GET['start_date'] ?? ''));
    $logEnd = trim((string) ($_GET['end_date'] ?? ''));
    $isValidDate = static function (string $d): bool {
        $dt = DateTime::createFromFormat('Y-m-d', $d);
        return $dt && $dt->format('Y-m-d') === $d;
    };

    try {
        $logStaffStmt = $pdo->prepare(
            "SELECT s.staff_id, s.user_id, s.full_name, s.staff_code, s.created_at
             FROM staff s
             WHERE s.staff_id = :staff_id
               AND s.branch_id = :branch_id
             LIMIT 1"
        );
        $logStaffStmt->execute([':staff_id' => $logStaffId, ':branch_id' => $bid]);
        $logStaff = $logStaffStmt->fetch(PDO::FETCH_ASSOC);
        if (!$logStaff) {
            echo json_encode(['ok' => false, 'error' => 'Staff member not found.']);
            exit;
        }

        $staffCreatedDate = '';
        if (!empty($logStaff['created_at'])) {
            $staffCreatedDt = new DateTime((string) $logStaff['created_at']);
            if ($staffCreatedDt) {
                $staffCreatedDate = $staffCreatedDt->format('Y-m-d');
            }
        }

        $todayDate = (new DateTimeImmutable('now'))->format('Y-m-d');
        if ($logStart === '' || !$isValidDate($logStart)) {
            $logStart = $staffCreatedDate !== '' ? $staffCreatedDate : $todayDate;
        }
        if ($logEnd === '' || !$isValidDate($logEnd)) {
            $logEnd = $todayDate;
        }
        if ($staffCreatedDate !== '' && $logStart < $staffCreatedDate) {
            $logStart = $staffCreatedDate;
        }
        if ($logEnd > $todayDate) {
            $logEnd = $todayDate;
        }
        if ($logStart > $logEnd) {
            $logStart = $staffCreatedDate !== '' ? $staffCreatedDate : $logEnd;
        }

        $logRows = [];
        $logOverflow = false;
        $logStaffUserId = (int) ($logStaff['user_id'] ?? 0);
        if ($logStaffUserId > 0) {
            $logSql =
                "SELECT l.log_id, l.action, l.created_at
                 FROM log l
                 INNER JOIN staff s ON s.user_id = l.user_id
                 WHERE s.staff_id = :staff_id
                   AND s.branch_id = :branch_id
                   AND l.user_id = :staff_user_id";
            $logParams = [':staff_id' => $logStaffId, ':branch_id' => $bid, ':staff_user_id' => $logStaffUserId];
            if ($logStart !== '' && $isValidDate($logStart)) {
                $logSql .= ' AND DATE(l.created_at) >= :start_date';
                $logParams[':start_date'] = $logStart;
            }
            if ($logEnd !== '' && $isValidDate($logEnd)) {
                $logSql .= ' AND DATE(l.created_at) <= :end_date';
                $logParams[':end_date'] = $logEnd;
            }
            $logSql .= ' ORDER BY l.created_at DESC, l.log_id DESC';
            $logStmt = $pdo->prepare($logSql);
            $logStmt->execute($logParams);
            $rawLogRows = $logStmt->fetchAll(PDO::FETCH_ASSOC);
            $logOverflow = count($rawLogRows) > 10;
            $logRows = $rawLogRows;
        }

        echo json_encode([
            'ok' => true,
            'staff' => [
                'full_name' => (string) ($logStaff['full_name'] ?? ''),
                'staff_code' => (string) ($logStaff['staff_code'] ?? ''),
                'created_at' => $staffCreatedDate,
            ],
            'logs' => array_map(static function ($r) {
                return ['created_at' => (string) $r['created_at'], 'action' => (string) $r['action']];
            }, $logRows),
            'overflow' => $logOverflow,
        ]);
    } catch (Throwable $e) {
        error_log('Unable to load staff logs: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'Unable to load logs.']);
    }
    exit;
}

// -------- Load data for display --------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'staff_table') {
    $stmt = $pdo->prepare("SELECT s.*, 
            CASE 
                WHEN TRIM(COALESCE(ul.phone_number, '')) = '' THEN ' - ' 
                ELSE TRIM(ul.phone_number) 
            END AS phone_number
        FROM staff s
        LEFT JOIN users u ON u.user_id = s.user_id
        LEFT JOIN user_license ul ON ul.license_id = u.license_id
        WHERE s.branch_id = :b
        ORDER BY s.staff_code ASC");
    $stmt->execute([':b' => $bid]);
    $staff = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $payload = [];
    foreach ($staff as $s) {
        $contactValue = trim((string) ($s['phone_number'] ?? ''));
        $payload[] = [
            'staff_id' => (int) ($s['staff_id'] ?? 0),
            'staff_code' => (string) ($s['staff_code'] ?? ''),
            'full_name' => (string) ($s['full_name'] ?? ''),
            'email' => (string) ($s['email'] ?? ''),
            'contact' => $contactValue === '' || $contactValue === ' - ' ? ' - ' : $contactValue,
            'is_active' => (int) ($s['is_active'] ?? 0),
            'created_at' => !empty($s['created_at']) ? date('Y-m-d', strtotime((string)$s['created_at'])) : '',
        ];
    }

    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'staff' => $payload]);
    exit;
}

$stmt = $pdo->prepare("SELECT s.*, 
        CASE 
            WHEN TRIM(COALESCE(ul.phone_number, '')) = '' THEN ' - ' 
            ELSE TRIM(ul.phone_number) 
        END AS phone_number
        FROM staff s
        LEFT JOIN users u ON u.user_id = s.user_id
        LEFT JOIN user_license ul ON ul.license_id = u.license_id
        WHERE s.branch_id = :b
        ORDER BY s.staff_code ASC");
$stmt->execute([':b' => $bid]);
$staff = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_staff = count($staff);
$active_staff = 0;
$disabled_staff = 0;
foreach ($staff as $member) {
    if ((int)($member['is_active'] ?? 0) === 1) {
        $active_staff++;
    } else {
        $disabled_staff++;
    }
}

$branchCreatedDate = '';
$branchCreatedStmt = $pdo->prepare('SELECT DATE(created_at) AS created_date FROM branches WHERE branch_id = :branch_id LIMIT 1');
$branchCreatedStmt->execute([':branch_id' => $bid]);
$rawBranchCreated = $branchCreatedStmt->fetchColumn();
if (is_string($rawBranchCreated) && trim($rawBranchCreated) !== '') {
    $branchCreatedDate = date('Y-m-d', strtotime($rawBranchCreated));
}

$activities = [];
$activityStart = trim((string) ($_GET['activity_start_date'] ?? ''));
$activityEnd = trim((string) ($_GET['activity_end_date'] ?? ''));
$validActivityDate = static function (string $date): bool {
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return $dt && $dt->format('Y-m-d') === $date;
};
$todayDate = (new DateTimeImmutable('now'))->format('Y-m-d');
if (!$validActivityDate($activityStart)) {
    $activityStart = $branchCreatedDate !== '' ? $branchCreatedDate : '';
}
if (!$validActivityDate($activityEnd)) {
    $activityEnd = $todayDate;
}
if ($branchCreatedDate !== '' && $activityStart < $branchCreatedDate) {
    $activityStart = $branchCreatedDate;
}
if ($activityEnd > $todayDate) {
    $activityEnd = $todayDate;
}
if ($activityStart > $activityEnd) {
    $activityStart = $branchCreatedDate !== '' ? $branchCreatedDate : $activityEnd;
}

try {
    $activitySql =
        "SELECT l.log_id, l.user_id, l.action, l.created_at,
                s.full_name AS staff_name
         FROM log l
         INNER JOIN staff s ON s.user_id = l.user_id
         WHERE s.branch_id = :branch_id";
    $activityParams = [':branch_id' => $bid];
    if ($activityStart !== '') {
        $activitySql .= ' AND DATE(l.created_at) >= :start_date';
        $activityParams[':start_date'] = $activityStart;
    }
    if ($activityEnd !== '') {
        $activitySql .= ' AND DATE(l.created_at) <= :end_date';
        $activityParams[':end_date'] = $activityEnd;
    }
    $activitySql .= ' ORDER BY l.created_at DESC, l.log_id DESC';
    $activityStmt = $pdo->prepare($activitySql);
    $activityStmt->execute($activityParams);
    $activities = $activityStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $activities = [];
    error_log('Unable to load staff activity log: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MAP & PUMP &mdash; Manage Staff</title>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="icon" type="image/png" href="/images/Logo.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Noto+Sans+Myanmar:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
  :root{
    --navy-950:#070b14; --navy-900:#0b1220; --navy-800:#101a30; --navy-700:#16213f;
    --blue-700:#1d4ed8; --blue-500:#2f6feb; --sky-400:#38bdf8;
    --amber-400:#fbbf24; --amber-300:#fcd34d; --mint-400:#34d399;
    --red-400:#f87171;
    --slate-50:#f8fafc; --slate-300:#c9d2e3; --slate-400:#8b96ad;
  }
  *{box-sizing:border-box; margin:0; padding:0;}
  body{background:var(--navy-950); color:var(--slate-50); font-family:'Inter','Space Grotesk',sans-serif; overflow-x:hidden;}
  .disp{font-family:'Space Grotesk','Noto Sans Myanmar',sans-serif;}
  a{color:inherit; text-decoration:none;}
  button{font-family:inherit;}
  @media (prefers-reduced-motion: reduce){ *{animation-duration:0.001ms !important; animation-iteration-count:1 !important; transition: none !important;} }

  .app-shell{display:flex; min-height:100vh;}
  #delete-confirm-btn:disabled{opacity:.55; cursor:not-allowed !important;}
  
  /* ---------- sidebar ---------- */
  .sidebar{
    width:252px; flex-shrink:0; background:var(--navy-900); border-right:1px solid rgba(255,255,255,0.06); 
    display:flex; flex-direction:column; padding:22px 16px; position:sticky; top:0; height:100vh; z-index:60;
  }
  .nav-logo {
    width: 32px;        
    height: auto;
    object-fit: contain;
  }
  .side-brand{display:flex; align-items:center; gap:10px; font-weight:700; font-size:17px; padding:6px 8px 0;}
  .side-role{font-size:11px; color:var(--slate-400); font-weight:500; margin:2px 0 0 46px;}
  .side-branch{
    margin:20px 8px 0; padding:10px 12px; background:var(--navy-800); border-radius:12px; 
    border:1px solid rgba(255,255,255,0.06); font-size:12.5px;
    transition: background 0.3s, border-color 0.3s;
    display:flex; align-items:center; gap:10px;
  }
  .side-branch:hover{
    background: var(--navy-700);
    border-color: rgba(251,191,36,0.25);
  }
  .side-branch .hq-logo{
    width:36px; height:36px; border-radius:8px; object-fit:cover; flex-shrink:0;
    background:var(--navy-950); border:1px solid rgba(255,255,255,0.08);
  }
  .side-branch .txt{min-width:0; flex:1;}
  .side-branch b{display:block; font-size:14px; font-family:'Space Grotesk'; margin-bottom:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;}
  .side-branch span{color:var(--slate-400);}
  .side-nav{margin-top:22px; display:flex; flex-direction:column; gap:4px; flex:1;}
  .side-link{
    display:flex; align-items:center; gap:12px; padding:11px 12px; border-radius:10px; font-size:14px; 
    color:var(--slate-300); font-weight:500;
    transition: background 0.25s ease, color 0.25s ease, transform 0.2s ease;
  }
  .side-link:hover{
    background:rgba(255,255,255,0.045); color:var(--slate-50);
    transform: translateX(4px);
  }
  .side-link.active{
    background:linear-gradient(135deg, rgba(251,191,36,0.16), rgba(29,78,216,0.08)); 
    color:var(--slate-50); box-shadow:inset 0 0 0 1px rgba(251,191,36,0.32);
  }
  .side-link.active:hover{transform: none;}
  .side-link .ic{font-size:16px; width:18px; text-align:center; transition: transform 0.2s ease;}
  .side-link:hover .ic{transform: scale(1.15);}
  .side-logout{
    display:flex; align-items:center; gap:12px; padding:12px; border-radius:10px; font-size:14px; 
    color:var(--red-400); font-weight:600; margin-top:14px; border-top:1px solid rgba(255,255,255,0.06); padding-top:18px;
    transition: background 0.25s, color 0.25s, padding-left 0.2s;
  }
  .side-logout:hover{background:rgba(248,113,113,0.08); padding-left: 16px;}

  /* ---------- main ---------- */
  .main{flex:1; min-width:0;}
  .topbar{
    position:sticky; top:0; z-index:50; display:flex; align-items:center; justify-content:space-between; 
    padding:16px 32px; background:rgba(7,11,20,0.72); backdrop-filter:blur(14px); border-bottom:1px solid rgba(255,255,255,0.06);
  }
  .topbar h1{font-size:18px; font-weight:700;}
  .topbar .date{font-size:12.5px; color:var(--slate-400); margin-top:2px; font-variant-numeric: tabular-nums;}
  .topbar-right{display:flex; align-items:center; gap:16px; position:relative;}
  
  .bell{
    width:36px; height:36px; border-radius:50%; background:var(--navy-800); display:flex; align-items:center; 
    justify-content:center; border:1px solid rgba(255,255,255,0.08); position:relative; font-size:15px; cursor:pointer;
    transition: transform 0.2s, background-color 0.2s;
  }
  .bell:hover{
    background: var(--navy-700);
    transform: scale(1.05);
  }
  .bell .ping{position:absolute; top:6px; right:7px; width:7px; height:7px; border-radius:50%; background:var(--amber-400); box-shadow:0 0 0 2px var(--navy-800);}
  
  .avatar-chip{display:flex; align-items:center; gap:10px; font-size:13px; cursor: pointer; padding: 4px 8px; border-radius: 99px; transition: background 0.2s;}
  .avatar-chip:hover{background: rgba(255,255,255,0.05);}
  .avatar-circle{width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,var(--sky-400),var(--blue-700)); display:flex; align-items:center; justify-content:center; font-weight:700; font-size:13px; font-family:'Space Grotesk';}

  /* ---------- Notification Popup Dropdown (dashboard style) ---------- */
  .notif-popup {
    position: absolute;
    top: 48px;
    right: 60px;
    width: 320px;
    max-height: 420px;
    overflow-y: auto;
    background: var(--navy-800);
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 14px;
    box-shadow: 0 16px 32px rgba(0, 0, 0, 0.4);
    padding: 14px 12px;
    z-index: 100;
    display: none;
    animation: fadeInDown 0.25s cubic-bezier(0.16, 1, 0.3, 1);
  }
  .notif-popup.show,
  .notif-popup.active { display: block; opacity: 1; pointer-events: auto; transform: translateY(0); }
  .notif-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    padding-bottom: 10px;
    margin-bottom: 10px;
  }
  .notif-header h4 { font-size: 14px; font-weight: 700; margin: 0; }
  .notif-header button { background: transparent; border: none; color: var(--sky-400); font-size: 11px; cursor: pointer; }
  .notif-list { max-height: 260px; overflow-y: auto; }
  .notif-item {
    padding: 9px 8px;
    border-radius: 10px;
    display: flex;
    gap: 10px;
    align-items: flex-start;
    transition: background-color 0.2s;
    border: 1px solid transparent;
    cursor: pointer;
  }
  .notif-item:hover { background-color: rgba(255,255,255,0.04); }
  .notif-item .icon {
    width: 32px; height: 32px; border-radius: 10px; display:flex; align-items:center; justify-content:center; font-size:12px; flex-shrink:0;
    background: rgba(148,163,184,0.12); color: var(--slate-200);
  }
  .notif-item.unread { border-color: rgba(56,189,248,0.2); }
  .notif-item.warning .icon { background: rgba(251,191,36,0.16); color: var(--amber-300); }
  .notif-item.error .icon, .notif-item.err .icon { background: rgba(248,113,113,0.16); color: var(--red-400); }
  .notif-item.success .icon, .notif-item.ok .icon { background: rgba(52,211,153,0.14); color: var(--mint-400); }
  .notif-item.info .icon, .notif-item[data-kind="info"] .icon { background: rgba(56,189,248,0.14); color: var(--sky-400); }
  .notif-item .text b { display:block; font-size:12.5px; color:var(--slate-50); margin-bottom:2px; }
  .notif-item .text span { color:var(--slate-400); font-size:11.5px; display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .notif-item .time { font-size:10px; color:var(--slate-400); margin-top:4px; }
  @keyframes fadeInDown { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }

  .content{
    padding:30px 32px 60px;
    opacity: 0;
    transform: translateY(10px);
    animation: layoutFadeIn 0.5s ease forwards;
  }
  .page-foot{
    text-align:center; margin-top:34px; padding-top:18px;
    border-top:1px solid rgba(255,255,255,0.06);
    font-size:12px; color:var(--slate-400);
  }
  @keyframes layoutFadeIn {
    to { opacity: 1; transform: translateY(0); }
  }

  .page-head{display:flex; align-items:center; justify-content:space-between; margin-bottom:22px; flex-wrap:wrap; gap:14px;}
  .page-head h2{font-size:20px; font-weight:700;}
  .page-head p{font-size:13px; color:var(--slate-400); margin-top:4px;}
  
  .btn-primary{
    background:linear-gradient(135deg, var(--amber-400), #d97706); color:#2b1704; font-weight:700; font-size:13.5px; 
    padding:11px 18px; border-radius:11px; border:none; cursor:pointer; display:inline-flex; align-items:center; gap:8px; 
    box-shadow:0 10px 24px -10px rgba(251,191,36,0.5);
    transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.25s;
  }
  .btn-primary:hover{
    transform: translateY(-2px);
    box-shadow:0 12px 28px -8px rgba(251,191,36,0.65);
  }

  .stat-strip{display:grid; grid-template-columns:repeat(3,1fr); gap:14px; margin-bottom:26px;}
  @media (max-width:1000px){.stat-strip{grid-template-columns:repeat(2,1fr);}}
  
  .stat{
    background:linear-gradient(160deg, var(--navy-800), var(--navy-900)); border:1px solid rgba(255,255,255,0.07); 
    border-radius:14px; padding:16px 18px;
    transition: transform 0.3s cubic-bezier(0.2, 0.8, 0.2, 1), border-color 0.3s;
  }
  .stat:hover{
    transform: translateY(-3px);
    border-color: rgba(251,191,36,0.25);
  }
  .stat .n{font-family:'Space Grotesk'; font-size:22px; font-weight:700;}
  .stat .l{font-size:11.5px; color:var(--slate-400); margin-top:4px;}

  .toolbar{display:flex; gap:12px; margin-bottom:18px; flex-wrap:wrap; align-items:center;}
  .search{
    flex:1; min-width:220px; background:var(--navy-800); border:1px solid rgba(255,255,255,0.08); border-radius:11px;
    padding:10px 14px; display:flex; align-items:center; gap:9px; font-size:13.5px; color:var(--slate-400);
    transition: border-color 0.25s, box-shadow 0.25s;
  }
  .search:focus-within{
    border-color: rgba(251,191,36,0.4);
    box-shadow: 0 0 0 1px rgba(251,191,36,0.4);
  }
  .search input{background:none; border:none; outline:none; color:var(--slate-50); font-family:inherit; font-size:13.5px; width:100%;}
  
  .chip{
    padding:9px 15px; border-radius:999px; font-size:12.5px; font-weight:600; 
    border:1px solid rgba(255,255,255,0.1); color:var(--slate-300); cursor:pointer;
    transition: all 0.2s ease;
  }
  .chip:hover{background: rgba(255,255,255,0.05); color: var(--slate-50);}
  .chip.active{background:rgba(251,191,36,0.16); border-color:rgba(251,191,36,0.4); color:var(--amber-300);}

  .panel{background:linear-gradient(160deg, var(--navy-800), var(--navy-900)); border:1px solid rgba(255,255,255,0.07); border-radius: 18px; overflow: hidden;}
  #tab-manage .panel{overflow:visible;}
  .staff-table-scroll {
    overflow-x: hidden;
    overflow-y: hidden;
    max-height: none;
    scrollbar-width: thin;
    scrollbar-color: rgba(148, 163, 184, 0.45) transparent;
  }
  .staff-table-scroll.has-scroll {
    overflow-y: auto;
  }
  .staff-table-scroll::-webkit-scrollbar { width: 8px; }
  .staff-table-scroll::-webkit-scrollbar-track { background: transparent; }
  .staff-table-scroll::-webkit-scrollbar-thumb {
    background: rgba(148, 163, 184, 0.38);
    border-radius: 999px;
    border: 2px solid transparent;
    background-clip: content-box;
  }
  .staff-table-scroll::-webkit-scrollbar-thumb:hover { background: rgba(148, 163, 184, 0.62); }
  table{width:100%; border-collapse:collapse;}
  thead th{text-align:left; font-size:11.5px; color:var(--slate-400); text-transform:uppercase; letter-spacing: 0.4px; padding:14px 18px; border-bottom:1px solid rgba(255,255,255,0.07);}
  
  tbody tr { transition: opacity 0.2s ease, transform 0.2s ease, background-color 0.2s ease; }
  tbody tr:has(.table-actions-menu-wrap:hover),
  tbody tr:has(.row-menu-toggle:hover),
  tbody tr:has(.row-action-menu:hover),
  tbody tr:has(.row-action-menu.active) {
    background: transparent !important;
  }
  tbody tr:hover:not(:has(.table-actions-menu-wrap:hover, .row-menu-toggle:hover, .row-action-menu:hover, .row-action-menu.active)) {
    background:rgba(255,255,255,0.02);
  }
  tbody tr:focus-within{position:relative; z-index:2;}
  tbody td{padding:14px 18px; font-size:13.5px; border-bottom:1px solid rgba(255,255,255,0.05);}
  tbody tr:last-child td{border-bottom:none;}
  tbody tr:hover{background:rgba(255,255,255,0.02);}
  thead th:last-child,
  tbody td:last-child {
    width: 124px;
    min-width: 124px;
    max-width: 124px;
    padding-left: 8px;
    padding-right: 8px;
    text-align: center;
    white-space: nowrap;
  }
  #staff-directory-table{table-layout:fixed;}
  #staff-directory-table thead th,
  #staff-directory-table tbody td,
  #staff-directory-table thead th:last-child,
  #staff-directory-table tbody td:last-child{
    width:20%;
    min-width:0;
    max-width:none;
    overflow:hidden;
    text-overflow:ellipsis;
  }
  #staff-directory-table tbody td:nth-child(2){
    white-space:normal;
    overflow-wrap:anywhere;
    word-break:normal;
    overflow:visible;
    text-overflow:clip;
  }
  #staff-directory-table thead th:last-child,
  #staff-directory-table tbody td:last-child{
    padding-left:4px;
    padding-right:4px;
    text-align:center;
    white-space:nowrap;
    overflow:visible;
  }
  .staff-actions-wrap{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:5px;
    white-space:nowrap;
  }
  .staff-action-btn{
    border:1px solid var(--slate-400);
    background:transparent;
    color:var(--slate-300);
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:8px;
    cursor:pointer;
    padding:5px 8px;
    font-size:12px;
    font-weight:600;
    line-height:1.2;
    transition:background-color 0.15s ease;
  }
  .staff-action-btn:focus-visible{
    outline: 1px solid rgba(251,191,36,0.42);
    outline-offset: 2px;
  }
  .staff-action-btn.view-btn { color: var(--slate-50); border-color: #64748b; }
  .staff-action-btn.view-btn:hover { background: rgba(100,116,139,0.18); }
  .staff-action-btn.toggle-btn { color: #ea580c; border-color: #d97706; }
  .staff-action-btn.toggle-btn:hover { background: rgba(217,119,6,0.14); }
  .staff-action-btn.toggle-btn.activate { color: #22c55e; border-color: #16a34a; }
  .staff-action-btn.toggle-btn.activate:hover { background: rgba(22,163,74,0.14); }
  .staff-action-btn.delete-btn { color: var(--red-400); border-color: #dc2626; }
  .staff-action-btn.delete-btn:hover { background: rgba(220,38,38,0.14); }
  .staff-action-btn:active,
  .staff-action-btn:focus,
  .staff-action-btn:focus-visible {
    box-shadow: none;
    transform: none;
    opacity: 1;
  }
  
  td.actions a{margin-right:12px; font-weight:600; text-decoration:none; cursor: pointer; transition: opacity 0.2s;}
  td.actions a:hover{opacity: 0.8;}
  td.actions a.danger{color:var(--red-400);}
  
  .staff-name{display:flex; align-items:center; gap:11px;}
  .mini-avatar{
    width:34px; height:34px; border-radius:50%; background:linear-gradient(135deg,var(--sky-400),var(--blue-700)); 
    display:flex; align-items:center; justify-content:center; font-weight:700; font-size:12px; font-family:'Space Grotesk'; flex-shrink:0;
  }
  .staff-name b{display:block; font-size:13.5px;}
  .staff-name span{font-size:11.5px; color:var(--slate-400);}
  .role-pill{font-size:11px; padding:4px 10px; border-radius:999px; background:rgba(255,255,255,0.06); color:var(--slate-300);}
  
  .status-pill{font-size:11px; font-weight:700; padding:4px 10px; border-radius:999px; display:inline-flex; align-items:center; gap:6px; transition: all 0.3s;}
  .status-pill.on{background:rgba(52,211,153,0.14); color:var(--mint-400);}
  .status-pill.off{background:rgba(139,150,173,0.14); color:var(--slate-400);}
  .status-pill.leave{background:rgba(251,191,36,0.14); color:var(--amber-300);}
  .status-pill .d{width:6px; height:6px; border-radius:50%; background:currentColor;}
  
  .tabs{display:flex; gap:8px; margin-bottom:20px; border-bottom:1px solid rgba(255,255,255,0.08); flex-wrap:wrap;}
  .tab-btn{
    background:none; border:none; color:var(--slate-400); font-family:inherit; font-size:13.5px; font-weight:600;
    padding:11px 4px; cursor:pointer; position:relative; margin-right:22px; transition: color 0.25s ease;
  }
  .tab-btn:hover{color: var(--slate-50);}
  .tab-btn.active{color:var(--slate-50);}
  .tab-btn.active::after{
    content:""; position:absolute; left:0; right:0; bottom:-1px; height:2px; background:var(--amber-400); border-radius:2px;
    animation: slideLine 0.25s ease-out;
  }
  @keyframes slideLine {
    from { transform: scaleX(0); }
    to { transform: scaleX(1); }
  }

  .tab-panel{display:none;}
  .tab-panel.active{
    display:block;
    animation: panelFadeIn 0.3s ease forwards;
  }
  @keyframes panelFadeIn {
    from { opacity: 0; transform: translateY(4px); }
    to { opacity: 1; transform: translateY(0); }
  }

  .activity-row{
    display:flex; gap: 14px; padding:13px 18px; border-bottom:1px solid rgba(255,255,255,0.05); font-size:13px;
    transition: background 0.2s;
  }
  .activity-row:hover{background: rgba(255,255,255,0.01);}
  .activity-row:last-child{border-bottom:none;}
  #tab-activity > .panel{padding:24px;}
  #tab-activity .staff-activity-toolbar{
    display:flex; align-items:center; justify-content:space-between;
    gap:20px; flex-wrap:wrap; margin-bottom:22px;
  }
  #tab-activity .staff-activity-heading{min-width:0;}
  #tab-activity .staff-activity-heading h3{
    font-size:16px; font-weight:700; line-height:1.2; margin:0 0 12px;
  }
  #tab-activity .staff-activity-heading p{
    font-size:13.5px; line-height:1.5; color:var(--slate-300); margin:0;
  }
  .activity-scroll {
    overflow-x: hidden;
    overflow-y: hidden;
    max-height: none;
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 10px;
    padding: 0 12px;
    scrollbar-width: thin;
    scrollbar-color:rgba(148,163,184,0.45) transparent;
  }
  .activity-scroll.has-scroll { overflow-y: auto; }
  .activity-scroll::-webkit-scrollbar { width: 8px; }
  .activity-scroll::-webkit-scrollbar-track { background: transparent; }
  .activity-scroll::-webkit-scrollbar-thumb {
    background: rgba(148, 163, 184, 0.38);
    border-radius: 999px;
    border: 2px solid transparent;
    background-clip: content-box;
  }
  .activity-scroll::-webkit-scrollbar-thumb:hover { background: rgba(148, 163, 184, 0.62); }
  .activity-row .t{font-size:11.5px; color:var(--slate-400); min-width:70px;}
  .activity-row b{color:var(--slate-50);}
  #tab-activity .panel{padding:24px;}
  #tab-activity .staff-activity-toolbar{display:flex; align-items:center; justify-content:space-between; gap:20px; flex-wrap:wrap; margin-bottom:20px;}
  #tab-activity .staff-activity-heading{flex:1 1 280px; min-width:0;}
  #tab-activity .staff-activity-heading h3{margin:0 0 10px; font-size:16px; font-weight:700; line-height:1.4; color:var(--slate-50);}
  #tab-activity .staff-activity-heading p{margin:0; font-size:13px; line-height:1.6; color:var(--slate-300);}
  #tab-activity .logs-modal-filters{margin:0; margin-left:auto;}
  #tab-activity .staff-activity-log{font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; font-size:13px; line-height:1.5; color:var(--slate-50); padding:12px 0; border-bottom:1px solid rgba(255,255,255,0.08); overflow-wrap:anywhere;}
  #tab-activity .staff-activity-log:last-child{border-bottom:none;}

  /* ---------- Staff Logs Modal ---------- */
  .modal-card-custom.logs-modal-card{position:relative; max-width:900px; width:calc(100% - 32px); padding:26px;}
  .logs-modal-close{position:absolute; top:14px; right:18px;}
  .logs-modal-head{display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:18px; padding-right:28px;}
  .logs-modal-profile{display:flex; align-items:center; gap:11px;}
  .logs-modal-profile .mini-avatar{width:44px; height:44px; font-size:14px;}
  .logs-modal-profile b{display:block; font-size:15px;}
  .logs-modal-profile span{font-size:12px; color:var(--slate-400);}
  .logs-modal-filters{display:flex; align-items:center; gap:10px; flex-wrap:wrap;}
  .logs-modal-filters label{display:flex; align-items:center; gap:6px; font-size:12px; color:var(--slate-300); margin:0;}
  .logs-modal-filters input[type="date"]{background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.1); border-radius:8px; padding:7px 10px; color:var(--slate-50); font-size:12.5px; color-scheme:dark;}
  .logs-modal-btn{background:#0d5fdb; color:#fff; border:none; border-radius:8px; padding:8px 14px; font-size:13px; font-weight:700; cursor:pointer; transition:background 0.15s;}
  .logs-modal-btn:hover{background:#1d6ff0;}
  .logs-modal-box{max-height:none; overflow-y:hidden; border:1px solid rgba(255,255,255,0.1); border-radius:10px; padding:0 12px; scrollbar-width:thin; scrollbar-color:rgba(148,163,184,0.45) transparent;}
  .logs-modal-row{font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; font-size:13px; color:var(--slate-50); padding:14px 0; border-bottom:1px solid rgba(255,255,255,0.08); overflow-wrap:anywhere;}
  .logs-modal-row:last-child{border-bottom:none;}
  .logs-modal-empty{text-align:center; padding:26px; color:var(--slate-400); font-size:12.5px;}
  .logs-modal-note{padding-top:8px; color:var(--slate-400); font-size:11.5px;}

  /* ---------- Modern Modal CSS ---------- */
  .modal-overlay {
    position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(7, 11, 20, 0.85);
    backdrop-filter: blur(8px); z-index: 100; display: flex; align-items: center; justify-content: center;
    opacity: 0; pointer-events: none; transition: opacity 0.3s ease;
  }
  .modal-overlay.active { opacity: 1; pointer-events: auto; }
  
  .modal-card-custom {
    background: linear-gradient(160deg, var(--navy-800), var(--navy-900));
    border: 1px solid rgba(255,255,255,0.1); border-radius: 20px;
    width: 100%; max-width: 480px; padding: 28px; transform: scale(0.95);
    transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
  }
  .modal-overlay.active .modal-card-custom { transform: scale(1); }
  .delete-modal-card{width:calc(100% - 32px); max-width:560px; padding:36px; border-color:rgba(248,113,113,0.35);}
  .delete-modal-icon{width:60px; height:60px; border-radius:50%; background:rgba(248,113,113,0.16); color:var(--red-400); display:flex; align-items:center; justify-content:center; font-size:25px; margin-bottom:20px;}
  .delete-modal-title{font-size:22px; line-height:1.3; font-weight:750; margin:0 0 12px; color:var(--slate-50);}
  .delete-modal-message{font-size:15px; color:var(--slate-300); line-height:1.7; margin:0;}
  .delete-modal-message p{margin:0 0 12px;}
  .delete-target{display:block; margin:12px 0 16px; padding:16px 18px; border:1px solid rgba(251,191,36,0.4); border-radius:12px; background:rgba(251,191,36,0.1); color:#fff; font-size:22px; line-height:1.35; font-weight:750; overflow-wrap:anywhere;}
  .delete-account-details{color:#dbe5f5; font-size:14px;}
  #delete-modal .modal-actions{margin-top:28px; gap:14px;}
  #delete-modal .modal-actions button{min-height:46px; padding:12px 20px; font-size:14px;}
  @media (max-width:600px){.delete-modal-card{padding:26px 22px;}.delete-target{font-size:19px;}}
  
  .modal-header-custom { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
  .modal-header-custom h3 { font-size: 18px; font-weight: 700; margin: 0; }
  .modal-close { background: none; border: none; color: var(--slate-400); font-size: 20px; cursor: pointer; transition: color 0.2s; }
  .modal-close:hover { color: var(--slate-50); }

  .form-group { margin-bottom: 16px; }
  .form-group label { display: block; font-size: 12px; color: var(--slate-400); margin-bottom: 6px; font-weight: 500; }
  .form-control-custom {
    width: 100%; background: var(--navy-950); border: 1px solid rgba(255,255,255,0.08);
    border-radius: 10px; padding: 10px 14px; color: var(--slate-50); font-size: 13.5px; outline: none;
    transition: border-color 0.2s;
  }
  .form-control-custom:focus { border-color: var(--amber-400); }
  .textarea-custom {
    resize: vertical;
    min-height: 110px;
    line-height: 1.5;
  }
  .staff-detail-fields.hidden {
    display: none;
  }
  #staff-modal .modal-card-custom { max-width: 760px; }
  .modal-header-actions { display: flex; align-items: center; gap: 10px; }
  .staff-info-btn {
    width: 24px; height: 24px; border-radius: 50%; border: none; cursor: pointer;
    background: linear-gradient(135deg,var(--sky-400),var(--blue-700)); color: #fff;
    font-size: 13px; font-weight: 700; line-height: 1; display: inline-flex; align-items: center; justify-content: center;
  }
  .staff-info-btn.hidden { display: none; }
  .staff-info-panel {
    display: none; margin-bottom: 16px; padding: 14px 16px; border-radius: 12px;
    background: var(--navy-950); border: 1px solid rgba(255,255,255,0.1);
    font-size: 12px; line-height: 1.6; color: var(--slate-300);
  }
  .staff-info-panel.show { display: block; }
  .staff-info-panel p + p { margin-top: 8px; }
  .staff-info-panel strong { color: var(--slate-50); }
  .account-type-group { display: flex; flex-wrap: wrap; gap: 8px 32px; }
  .account-type-option {
    display: inline-flex !important; align-items: center; gap: 8px; margin: 0 !important;
    font-size: 13px !important; color: var(--slate-50) !important; font-weight: 600 !important; cursor: pointer;
  }
  .account-type-option input { accent-color: var(--sky-400); cursor: pointer; }
  .staff-password-fields.hidden { display: none; }
  .staff-password-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
  .staff-password-grid .form-group { position: relative; }
  .staff-password-grid .form-control-custom { padding-right: 42px; }
  .password-toggle-btn {
    position: absolute; right: 0; bottom: 0; width: 42px; height: 40px;
    background: none; border: none; color: var(--slate-400); cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center; font-size: 14px;
    transition: color 0.2s;
  }
  .password-toggle-btn:hover { color: var(--slate-50); }
  .staff-password-hint { font-size: 11.5px; color: var(--slate-400); margin-top: 8px; line-height: 1.5; }
  @media (max-width:520px){ .staff-password-grid { grid-template-columns: 1fr; } }

  .staff-form-feedback {
    display: none;
    margin-top: 12px;
    font-size: 13px;
    line-height: 1.5;
    min-height: 18px;
  }
  .staff-form-feedback.show {
    display: block;
  }
  .staff-form-feedback.show.error {
    color: var(--red-400);
    font-weight: 600;
  }
  .staff-form-feedback.show.success {
    color: var(--mint-400);
    font-weight: 600;
  }

  .modal-actions { display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px; }
  .btn-secondary-custom {
    background: transparent; border: 1px solid rgba(255,255,255,0.15); color: var(--slate-300);
    padding: 10px 18px; border-radius: 10px; cursor: pointer; font-size: 13px; font-weight: 600;
    transition: background 0.2s, color 0.2s;
  }
  .btn-secondary-custom:hover { background: rgba(255,255,255,0.05); color: var(--slate-50); }

  .table-actions-menu-wrap{position:relative; display:flex; justify-content:flex-end; isolation:isolate; z-index:3;}
  .table-actions-menu-wrap:hover,
  .table-actions-menu-wrap:focus-within,
  .row-menu-toggle:hover,
  .row-menu-toggle:focus,
  .row-action-menu:hover,
  .row-action-menu:focus-within {
    background: transparent;
    box-shadow: none;
    filter: none;
  }
  .row-menu-toggle{
    width:32px; height:32px; border-radius:8px; border:1px solid rgba(255,255,255,0.08); background:rgba(15,23,42,0.9);
    color:var(--slate-300); cursor:pointer; display:inline-flex; align-items:center; justify-content:center; transition:all 0.2s ease;
    position: relative; z-index: 4; box-shadow: inset 0 0 0 1px rgba(148,163,184,0.06);
  }
  .row-menu-toggle:hover,
  .row-menu-toggle:focus-visible {
    background: rgba(15,23,42,0.96);
    color: var(--slate-50) !important;
    border-color: rgba(148,163,184,0.18);
    transform: none;
    box-shadow: inset 0 0 0 1px rgba(148,163,184,0.12);
  }
  .row-action-menu{
    position: fixed;
    left: 0;
    top: 0;
    min-width: 172px;
    background: rgba(15, 23, 42, 0.97);
    border: 1px solid rgba(148, 163, 184, 0.16);
    border-radius: 10px;
    box-shadow: 0 12px 28px rgba(2, 6, 23, 0.42);
    padding: 8px;
    display: none;
    opacity: 0;
    pointer-events: none;
    transform: translateY(-2px);
    transition: opacity 0.16s ease, transform 0.16s ease;
    z-index: 5000;
  }
  .row-action-menu.active {
    display: block;
    opacity: 1;
    pointer-events: auto;
    transform: translateY(0);
  }
  .row-action{
    width:100%; border:none; background:transparent; color:var(--slate-200); text-align:left; padding:9px 10px; border-radius:8px; cursor:pointer;
    font-size:13px; font-weight:600; display:flex; align-items:center; gap:8px; transition:background 0.15s ease, color 0.15s ease;
  }
  .row-action:hover,
  .row-action:focus-visible {
    background: rgba(255,255,255,0.04) !important;
    color: var(--slate-50) !important;
    transform: none;
  }
  .row-action.status-toggle[data-action="activate"]{
    color: var(--mint-400) !important;
  }
  .row-action.status-toggle[data-action="activate"]:hover,
  .row-action.status-toggle[data-action="activate"]:focus-visible {
    color: #6ee7b7 !important;
  }
  .row-action.delete-action,
  .row-action.danger {
    color: var(--red-400) !important;
  }
  .row-action.delete-action:hover,
  .row-action.delete-action:focus-visible,
  .row-action.danger:hover,
  .row-action.danger:focus-visible {
    background: rgba(239,68,68,0.08) !important;
    color: #fca5a5 !important;
  }
  .row-action.status-toggle{color:var(--slate-200);}
  .row-action.status-toggle[style*="color:var(--mint-400)"]{color:var(--mint-400) !important;}
</style>
</head>
<body>

<div class="app-shell">
  <aside class="sidebar">
    <a href="dashboard.php" class="text-decoration-none d-flex align-items-center gap-2 text-white fw-bold" style="padding: 6px 8px 0;">
      <img src="/images/Logo.png" alt="Logo" class="nav-logo">
      <span class="disp" style="font-size: 17px; letter-spacing: 0.5px; color: #ffffff; font-weight: 700;">Map & Pump</span>
    </a>
    <div class="side-role">Branch Manager Console</div>

    <?php $hqLogoSrc = hq_logo_src($branch['hq_logo'] ?? null); ?>
    <div class="side-branch">
      <?php if ($hqLogoSrc): ?>
        <img src="<?= h($hqLogoSrc) ?>" alt="HQ Logo" class="hq-logo" onerror="this.style.display='none'">
      <?php else: ?>
        <div class="hq-logo" style="display:flex; align-items:center; justify-content:center; color:var(--slate-400);"><i class="fa-solid fa-building"></i></div>
      <?php endif; ?>
      <div class="txt">
        <b><?= h($branch['branch_name']) ?></b>
        <span>Branch ID: <?= h($branch['branch_code']) ?></span>
      </div>
    </div>

    <nav class="side-nav">
      <a href="dashboard.php" class="side-link"><span class="ic"><i class="fa-solid fa-house"></i></span> Dashboard</a>
      <a href="manage-staff.php" class="side-link active"><span class="ic"><i class="fa-solid fa-users"></i></span> Manage Staff</a>
      <a href="manage-oil.php" class="side-link"><span class="ic"><i class="fa-solid fa-gas-pump"></i></span> Manage Oil Stock</a>
      <a href="booking-slot.php" class="side-link"><span class="ic"><i class="fa-solid fa-ticket"></i></span> Monitor Booking Slot</a>
      <a href="view-customers.php" class="side-link"><span class="ic"><i class="fa-solid fa-address-book"></i></span> View Customers</a>
      <a href="report-hq.php" class="side-link"><span class="ic"><i class="fa-solid fa-paper-plane"></i></span> Report to HQ</a>
      <a href="branchprofile.php" class="side-link"><span class="ic"><i class="fa-solid fa-building"></i></span> Branch Profile</a>
      <a href="edit-profile.php" class="side-link"><span class="ic"><i class="fa-solid fa-gear"></i></span> Edit Profile</a>
    </nav>

    <a href="../Login/logout.php" class="side-logout"><span class="ic"><i class="fa-solid fa-right-from-bracket"></i></span> Log Out</a>
  </aside>

  <div class="main">
    <div class="topbar">
      <div>
        <h1>Manage Staff</h1>
        <div class="date" id="live-clock">Wednesday Â· July 9, 2026</div>
      </div>
      <div class="topbar-right">
        <!-- Bell Trigger Button -->
        <div class="bell" id="notif-bell"><i class="fa-regular fa-bell"></i><?php if ($has_unread): ?><span class="ping" id="notif-ping"></span><?php endif; ?></div>
        
        <!-- Notification Dropdown Popup (manage-oil style) -->
        <div class="notif-popup" id="notif-popup">
          <div class="notif-header">
            <h4>Notifications</h4>
            <button id="clear-notifs-btn" type="button">Mark all read</button>
          </div>
          <div class="notif-list" id="notif-list">
            <?php if (!$alerts): ?>
              <div class="notif-empty" style="text-align:center; padding:12px 0; color:var(--slate-400); font-size:12px;">No new notifications</div>
            <?php else:
              $icon_map = ['warn'=>'fa-triangle-exclamation','err'=>'fa-circle-exclamation','ok'=>'fa-truck-ramp-box','info'=>'fa-circle-info'];
              foreach ($alerts as $a):
                $ic = $icon_map[$a['kind']] ?? $icon_map['info'];
                $item_class = (empty($a['is_read']) || (int)$a['is_read'] === 0) ? ' unread' : '';
            ?>
              <div class="notif-item<?= h($item_class) ?>" data-notif-id="<?= (int)$a['notif_id'] ?>" data-link="<?= h($a['link'] ?? 'dashboard.php') ?>">
                <div class="icon"><i class="fa-solid <?= h($ic) ?>"></i></div>
                <div class="text">
                  <b><?= h($a['title']) ?></b>
                  <span><?= h($a['body']) ?></span>
                  <div class="time"><?= h($a['time_ago']) ?></div>
                </div>
              </div>
            <?php endforeach; endif; ?>
          </div>
        </div>

        <div class="avatar-chip">
          <div class="avatar-circle"><a href="edit-profile.php"><?= htmlspecialchars($managerInitial, ENT_QUOTES, 'UTF-8') ?></a></div>
          <div><b><a href="edit-profile.php"><?= htmlspecialchars($managerDisplayName, ENT_QUOTES, 'UTF-8') ?></a></b></div>
        </div>
      </div>
    </div>

    <div class="content">
      <div class="page-head">
        <div>
          <h2>Staff Directory</h2>
          <p>Create staff accounts, activate/disable access, and review activity logs</p>
        </div>
        <button class="btn-primary" id="open-add-btn"><i class="fa-solid fa-plus"></i> Add New Staff</button>
      </div>

      <div class="stat-strip">
        <div class="stat"><div class="n" id="stat-total"><?= $total_staff ?></div><div class="l">Total Staff</div></div>
        <div class="stat"><div class="n" id="stat-active" style="color:var(--mint-400)"><?= $active_staff ?></div><div class="l">Active Account</div></div>
        <div class="stat"><div class="n" id="stat-disabled" style="color:var(--slate-400)"><?= $disabled_staff ?></div><div class="l">Disabled Account</div></div>
      </div>

      <div class="tabs">
        <button class="tab-btn active" id="tabbtn-manage" onclick="showTab('manage')">Manage Staff Accounts</button>
        <button class="tab-btn" id="tabbtn-activity" onclick="showTab('activity')">View Staff Activity</button>
      </div>

      <!-- TAB 1: MANAGE STAFF ACCOUNTS -->
      <div class="tab-panel active" id="tab-manage">
        <div class="toolbar">



          <div class="search">
            <i class="fa-solid fa-magnifying-glass"></i> 
            <input type="text" id="directory-search" placeholder="Search by name, email, or Staff ID...">
          </div>
        </div>
        <div class="panel">
          <div class="staff-table-scroll">
            <table id="staff-directory-table">
              <thead><tr><th>Staff</th><th>Email</th><th>Contact</th><th>Account Status</th><th>Action</th></tr></thead>
              <tbody>
                <?php
                $duty_class  = ['On Duty' => 'on', 'Off Duty' => 'off', 'Leave' => 'leave'];
                foreach ($staff as $s):
                  $staff_email = $s['email'] ?? '';
                  $staff_contact = trim((string) ($s['phone_number'] ?? ''));
                  $on = (int)$s['is_active'] === 1;
                ?>
                <tr data-id="<?= (int)$s['staff_id'] ?>">
                  <td>
                    <div class="staff-name">
                      <div class="mini-avatar"><?= h(initials($s['full_name'])) ?></div>
                      <div><b><?= h($s['full_name']) ?></b><span>ID: <?= h($s['staff_code']) ?></span></div>
                    </div>
                  </td>
                  <td><?= $staff_email !== '' ? h($staff_email) : '<span style="color:var(--slate-400);">—</span>' ?></td>
                  <td><?= $staff_contact !== '' ? h($staff_contact) : ' - ' ?></td>
                  <td>
                    <span class="status-pill <?= $on ? 'on' : 'off' ?>">
                      <span class="d"></span>
                      <?= $on ? 'Active' : 'Disabled' ?>
                    </span>
                  </td>
                  <td class="staff-action-cell">
                    <div class="staff-actions-wrap">
                      <button type="button" class="staff-action-btn view-btn view-action" data-id="<?= (int)$s['staff_id'] ?>" data-name="<?= h($s['full_name']) ?>" data-code="<?= h($s['staff_code']) ?>" title="View staff logs" aria-label="View staff logs">View Logs</button>
                      <?php if ($on): ?>
                        <button type="button" class="staff-action-btn toggle-btn active" data-action="disable" data-id="<?= (int)$s['staff_id'] ?>" title="Disable staff account" aria-label="Disable staff account">Disable</button>
                      <?php else: ?>
                        <button type="button" class="staff-action-btn toggle-btn activate" data-action="activate" data-id="<?= (int)$s['staff_id'] ?>" title="Activate staff account" aria-label="Activate staff account">Activate</button>
                      <?php endif; ?>
                      <button type="button" class="staff-action-btn delete-btn delete-action" data-id="<?= (int)$s['staff_id'] ?>" data-name="<?= h($s['full_name']) ?>" data-code="<?= h($s['staff_code']) ?>" title="Delete staff member" aria-label="Delete staff member">Delete</button>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$staff): ?>
                  <tr><td colspan="5" style="text-align:center; color:var(--slate-400); padding:22px;">No staff registered yet.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- TAB 2: STAFF ACTIVITY LOG -->
      <div class="tab-panel" id="tab-activity">
        <div class="panel">
          <div class="staff-activity-toolbar">
            <div class="staff-activity-heading">
              <h3>Recent Staff Activity</h3>
              <p>Track completed tasks and actions performed by staff members.</p>
            </div>
            <form class="logs-modal-filters" id="activity-filters" method="get" action="manage-staff.php#tab-activity">
              <label><span>From</span><input type="date" name="activity_start_date" value="<?= h($activityStart) ?>" min="<?= h($branchCreatedDate !== '' ? $branchCreatedDate : '') ?>" max="<?= h($todayDate) ?>"></label>
              <label><span>To</span><input type="date" name="activity_end_date" value="<?= h($activityEnd) ?>" min="<?= h($branchCreatedDate !== '' ? $branchCreatedDate : '') ?>" max="<?= h($todayDate) ?>"></label>
              <button type="submit" class="logs-modal-btn">Apply</button>
              <button type="button" class="logs-modal-btn" id="activity-clear-btn">Clear</button>
            </form>
          </div>
          <div class="activity-scroll" id="activity-scroll">
            <?php if (!$activities): ?>
              <div class="logs-modal-empty">No staff activity is recorded for the selected date range.</div>
            <?php else: ?>
              <?php foreach ($activities as $a): ?>
                <div class="staff-activity-log">[<?= h($a['created_at']) ?>] USER <?= h($a['staff_name'] ?? 'Staff Member') ?>: <?= h($a['action']) ?></div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="page-foot">Â© 2026 MAP & PUMP | All Rights Reserved</div>
    </div>
  </div>
</div>

<!-- ---------- Add / Edit Staff Modal ---------- -->
<div class="modal-overlay" id="staff-modal">
  <div class="modal-card-custom">
    <div class="modal-header-custom">
      <h3 id="modal-title">Add New Staff Member</h3>
      <div class="modal-header-actions">
        <button type="button" class="staff-info-btn" id="staff-info-btn" aria-label="Account type information" aria-expanded="false" aria-controls="staff-info-panel">!</button>
        <button class="modal-close" id="modal-close-btn">&times;</button>
      </div>
    </div>
    <form id="staff-form" method="POST" action="manage-staff.php">
      <input type="hidden" name="csrf"     value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action"   id="staff-form-action" value="add">
      <input type="hidden" name="staff_id" id="staff-form-id"     value="">
      <input type="hidden" name="full_name" id="staff-name-input" value="">
      <input type="hidden" name="contact" id="staff-contact-input" value="">
      <div class="form-group">
        <label for="staff-email-input">Email Address</label>
        <input type="email" id="staff-email-input" name="email" class="form-control-custom" placeholder="e.g. aungkolatt@mappump.mm">
      </div>
      <div id="staff-info-panel" class="staff-info-panel" role="note">
        <p><strong>New Customer Account:</strong> Select this when the email entered above does not already have a customer account. The password you set will be used to create that account and sign in with that email.</p>
        <p><strong>Existing Customer Account:</strong> Select this when the email already belongs to a customer. Their current password is kept and is not changed.</p>
      </div>
      <div id="staff-account-fields">
        <div class="form-group">
          <div class="account-type-group">
            <label class="account-type-option"><input type="radio" name="account_type" value="standalone" id="account-type-standalone"> Create with Standalone Account</label>
            <label class="account-type-option"><input type="radio" name="account_type" value="customer" id="account-type-customer" checked> Invite with Customer Account</label>
          </div>
        </div>
        <div id="staff-password-fields" class="staff-password-fields hidden">
          <div class="staff-password-grid">
            <div class="form-group" style="margin-bottom:0;">
              <label for="staff-password-input">Set a Password</label>
              <input type="password" id="staff-password-input" name="password" class="form-control-custom" autocomplete="new-password" disabled>
              <button type="button" class="password-toggle-btn" data-password-target="staff-password-input" aria-label="Show password">
                <i class="fa-solid fa-eye"></i>
              </button>
            </div>
            <div class="form-group" style="margin-bottom:0;">
              <label for="staff-confirm-password-input">Confirm Password</label>
              <input type="password" id="staff-confirm-password-input" name="confirm_password" class="form-control-custom" autocomplete="new-password" disabled>
              <button type="button" class="password-toggle-btn" data-password-target="staff-confirm-password-input" aria-label="Show password">
                <i class="fa-solid fa-eye"></i>
              </button>
            </div>
          </div>
          <div class="staff-password-hint">Use 8-15 characters with uppercase and lowercase letters, a number, and a symbol.</div>
          <div style="height:16px;"></div>
        </div>
      </div>
      <div id="staff-detail-fields" class="staff-detail-fields hidden">
        <div class="form-group">
          <label for="staff-name-visible">Full Name</label>
          <input type="text" id="staff-name-visible" class="form-control-custom" placeholder="e.g. Aung Ko Latt">
        </div>
        <div class="form-group">
          <label for="staff-contact-visible">Contact Phone</label>
          <input type="text" id="staff-contact-visible" class="form-control-custom" placeholder="e.g. 09-751-XXXXXX">
        </div>
      </div>
      <div id="staff-form-feedback" class="staff-form-feedback" aria-live="polite"></div>
      <div class="modal-actions">
        <button type="button" class="btn-secondary-custom" id="modal-cancel-btn">Cancel</button>
        <button type="submit" class="btn-primary" id="staff-submit-btn" style="box-shadow: none; padding: 10px 18px;">Send Invite</button>
      </div>
    </form>
  </div>
</div>

<!-- ---------- Staff Logs Modal ---------- -->
<div class="modal-overlay" id="logs-modal">
  <div class="modal-card-custom logs-modal-card" role="dialog" aria-modal="true" aria-label="Staff logs">
    <button type="button" class="modal-close logs-modal-close" id="logs-modal-close" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    <div class="logs-modal-head">
      <div class="logs-modal-profile">
        <div class="mini-avatar" id="logs-modal-avatar"></div>
        <div><b id="logs-modal-name"></b><span id="logs-modal-code"></span></div>
      </div>
      <div class="logs-modal-filters">
        <label><span>From</span><input type="date" id="logs-start-date"></label>
        <label><span>To</span><input type="date" id="logs-end-date"></label>
        <button type="button" class="logs-modal-btn" id="logs-apply-btn">Apply</button>
        <button type="button" class="logs-modal-btn" id="logs-clear-btn">Clear</button>
      </div>
    </div>
    <div class="logs-modal-box" id="logs-modal-box"></div>
  </div>
</div>

<!-- ---------- Delete Confirmation Modal (custom red danger dialog) ---------- -->
<div class="modal-overlay" id="delete-modal">
  <div class="modal-card-custom delete-modal-card">
    <div class="delete-modal-icon">
      <i class="fa-solid fa-triangle-exclamation"></i>
    </div>
    <h3 class="delete-modal-title">Delete Staff Account?</h3>
    <div id="delete-modal-msg" class="delete-modal-message">
      <p>Confirm that this is the correct staff account before continuing.</p>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn-secondary-custom" id="delete-cancel-btn">Cancel</button>
      <button type="button" id="delete-confirm-btn"
        style="background:linear-gradient(135deg,#f87171,#b91c1c); color:#fff; font-weight:700; font-size:13px; padding:10px 18px; border-radius:10px; border:none; cursor:pointer;">
        Delete Permanently
      </button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Tab Switching Controller
  function showTab(name){
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    document.getElementById('tabbtn-' + name).classList.add('active');
  }

  document.addEventListener('DOMContentLoaded', () => {
    
    // 1. Digital Clock Component
    function updateClock() {
      const now = new Date();
      const options = { weekday: 'long', month: 'short', day: 'numeric', year: 'numeric' };
      const dateString = now.toLocaleDateString('en-US', options);
      const timeString = now.toLocaleTimeString('en-US', { hour12: true, hour: '2-digit', minute: '2-digit', second: '2-digit' });
      document.getElementById('live-clock').textContent = `${dateString} Â· ${timeString}`;
    }
    setInterval(updateClock, 1000);
    updateClock();

    // 2. Notification Popup Controller (shared dashboard API)
    const bellBtn = document.getElementById('notif-bell');
    const notifPopup = document.getElementById('notif-popup');
    const notifPing = document.getElementById('notif-ping');
    const clearNotifsBtn = document.getElementById('clear-notifs-btn');
    const notifList = document.getElementById('notif-list');

    function postDismiss(payload) {
      return fetch('api_dismiss_notification.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      }).then(r => r.json()).catch(() => ({ ok: false }));
    }

    function renderNotifEmpty() {
      if (!notifList) return;
      notifList.innerHTML = '<div class="notif-empty" style="text-align:center; padding:12px 0; color:var(--slate-400); font-size:12px;">No new notifications</div>';
      if (notifPing) notifPing.remove();
    }

    bellBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      notifPopup.classList.toggle('active');
      if (notifPing) notifPing.style.opacity = '0';
    });

    document.addEventListener('click', (e) => {
      if (!notifPopup.contains(e.target) && !bellBtn.contains(e.target)) {
        notifPopup.classList.remove('active');
      }
    });

    if (notifList) {
      notifList.addEventListener('click', (e) => {
        const item = e.target.closest('.notif-item');
        if (!item) return;
        const id = Number(item.dataset.notifId || 0);
        const link = item.dataset.link || '';
        if (id > 0) {
          postDismiss({ notif_id: id }).then((res) => {
            if (res.ok && notifPing && Number(res.unread || 0) <= 0) {
              notifPing.remove();
            }
          });
        }
        if (link) window.location.href = link;
      });
    }

    if (clearNotifsBtn) {
      clearNotifsBtn.addEventListener('click', () => {
        postDismiss({ all: true }).then((res) => {
          if (res.ok) renderNotifEmpty();
        });
      });
    }

    // 3. Client Side Dynamic Search Filtering (name, email, staff ID)
    const searchInput = document.getElementById('directory-search');
    const directoryRows = () => document.querySelectorAll('#staff-directory-table tbody tr');

    function updateTableScrollState() {
      const scrollWrap = document.querySelector('.staff-table-scroll');
      const table = document.getElementById('staff-directory-table');
      if (!scrollWrap || !table) return;

      const rows = table.querySelectorAll('tbody tr');
      const visibleRows = Array.from(rows).filter(row => row.style.display !== 'none').length;
      const shouldScroll = visibleRows > 5;

      scrollWrap.classList.toggle('has-scroll', shouldScroll);
      scrollWrap.style.maxHeight = shouldScroll ? 'calc(5 * 66px + 8px)' : 'none';
      scrollWrap.style.overflowY = shouldScroll ? 'auto' : 'hidden';
    }

    searchInput.addEventListener('input', () => {
      const query = searchInput.value.toLowerCase().trim();
      directoryRows().forEach(row => {
        const rowText = row.textContent.toLowerCase();
        const matchesSearch = query === '' || rowText.includes(query);
        row.style.display = matchesSearch ? '' : 'none';
      });
      updateTableScrollState();
    });

    updateTableScrollState();

    const tbodyObserver = new MutationObserver(() => {
      updateTableScrollState();
    });

    const staffTableBody = document.getElementById('staff-directory-table')?.querySelector('tbody');
    if (staffTableBody) {
      tbodyObserver.observe(staffTableBody, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['style']
      });
    }

    // "View Logs" opens the staff log modal (profile, date range, log lines).
    const logsModal = document.getElementById('logs-modal');
    const logsBox = document.getElementById('logs-modal-box');
    const logsStart = document.getElementById('logs-start-date');
    const logsEnd = document.getElementById('logs-end-date');
    let logsStaffId = null;
    let logsRequestSeq = 0;

    function logsShowMessage(text) {
      const div = document.createElement('div');
      div.className = 'logs-modal-empty';
      div.textContent = text;
      logsBox.replaceChildren(div);
      updateLogScrollState(logsBox, '.logs-modal-row');
    }

    function loadStaffLogs() {
      if (!logsStaffId) return;
      const seq = ++logsRequestSeq;
      const params = new URLSearchParams({ ajax: 'staff_logs', staff_id: logsStaffId });
      if (logsStart.value) params.set('start_date', logsStart.value);
      if (logsEnd.value) params.set('end_date', logsEnd.value);
      logsShowMessage('Loading logs...');
      fetch('manage-staff.php?' + params.toString())
        .then(res => res.json())
        .then(payload => {
          if (seq !== logsRequestSeq) return;
          if (!payload.ok) { logsShowMessage(payload.error || 'Unable to load logs.'); return; }
          const name = (payload.staff && payload.staff.full_name) || '';
          const startDate = (payload.staff && payload.staff.created_at) || '';
          if (startDate) {
            logsStart.min = startDate;
            logsStart.max = new Date().toISOString().slice(0, 10);
            if (!logsStart.value || logsStart.value < startDate) {
              logsStart.value = startDate;
            }
          } else {
            logsStart.min = '';
          }
          if (!logsEnd.value || logsEnd.value > logsStart.max) {
            logsEnd.value = logsStart.max;
          }
          logsEnd.min = logsStart.min || '';
          logsEnd.max = logsStart.max;
          if (!payload.logs.length) {
            logsShowMessage('No activity is recorded for the selected date range.');
            return;
          }
          logsBox.replaceChildren(...payload.logs.map(l => {
            const row = document.createElement('div');
            row.className = 'logs-modal-row';
            row.textContent = `[${l.created_at}] USER ${name}: ${l.action}`;
            return row;
          }));
          updateLogScrollState(logsBox, '.logs-modal-row');
        })
        .catch(() => { if (seq === logsRequestSeq) logsShowMessage('Unable to load logs.'); });
    }

    function openLogsModal(btn) {
      logsStaffId = btn.dataset.id;
      const name = btn.dataset.name || '';
      const initials = name.trim().split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0].toUpperCase()).join('');
      document.getElementById('logs-modal-avatar').textContent = initials;
      document.getElementById('logs-modal-name').textContent = name;
      document.getElementById('logs-modal-code').textContent = btn.dataset.code ? 'ID: ' + btn.dataset.code : '';

      const todayIso = new Date().toISOString().slice(0, 10);
      logsStart.min = '';
      logsEnd.min = '';
      logsStart.max = todayIso;
      logsEnd.max = todayIso;
      logsStart.value = '';
      logsEnd.value = todayIso;
      logsModal.classList.add('active');
      loadStaffLogs();
    }

    function closeLogsModal() {
      logsModal.classList.remove('active');
      logsStaffId = null;
      logsRequestSeq++;
    }

    document.addEventListener('click', (e) => {
      const viewBtn = e.target.closest('.view-action');
      if (!viewBtn) return;
      e.stopPropagation();
      openLogsModal(viewBtn);
    });
    document.getElementById('logs-apply-btn').addEventListener('click', loadStaffLogs);
    document.getElementById('logs-clear-btn').addEventListener('click', () => {
      logsStart.value = '';
      logsEnd.value = '';
      loadStaffLogs();
    });
    document.getElementById('logs-modal-close').addEventListener('click', closeLogsModal);
    logsModal.addEventListener('click', (e) => { if (e.target === logsModal) closeLogsModal(); });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && logsModal.classList.contains('active')) closeLogsModal();
    });

    const activityScroll = document.getElementById('activity-scroll');
    function updateLogScrollState(box, selector) {
      if (!box) return;
      const rows = box.querySelectorAll(selector);
      if (rows.length <= 10) {
        box.classList.remove('has-scroll');
        box.style.maxHeight = 'none';
        box.style.overflowY = 'hidden';
        return;
      }
      let height = 0;
      for (let i = 0; i < 10; i++) {
        height += rows[i].getBoundingClientRect().height;
      }
      if (!height) return;
      const style = getComputedStyle(box);
      height += parseFloat(style.paddingTop) + parseFloat(style.paddingBottom);
      if (style.boxSizing === 'border-box') {
        height += parseFloat(style.borderTopWidth) + parseFloat(style.borderBottomWidth);
      }
      box.classList.add('has-scroll');
      box.style.maxHeight = height + 'px';
      box.style.overflowY = 'auto';
    }
    function updateActivityScrollState() {
      updateLogScrollState(activityScroll, '.staff-activity-log');
      updateLogScrollState(logsBox, '.logs-modal-row');
    }
    if (window.location.hash === '#tab-activity') showTab('activity');
    updateActivityScrollState();
    window.addEventListener('resize', updateActivityScrollState);
    if (activityScroll && window.ResizeObserver) {
      new ResizeObserver(updateActivityScrollState).observe(activityScroll);
      new ResizeObserver(updateActivityScrollState).observe(logsBox);
    }
    document.getElementById('activity-clear-btn').addEventListener('click', () => {
      const form = document.getElementById('activity-filters');
      form.querySelectorAll('input[type="date"]').forEach(input => { input.value = ''; });
      form.submit();
    });

    const csrfToken = '<?= h(csrf_token()) ?>';
    const directoryTable = document.getElementById('staff-directory-table');
    const deleteModal = document.getElementById('delete-modal');
    const deleteMsg = document.getElementById('delete-modal-msg');
    const deleteCancel = document.getElementById('delete-cancel-btn');
    const deleteConfirm = document.getElementById('delete-confirm-btn');
    let pendingDeleteRow = null;
    let deleteCooldownTimer = null;
    let pendingStatusRequest = new Set();

    function updateTotalMetrics() {
      const totalStaff = document.querySelectorAll('#staff-directory-table tbody tr').length;
      const totalActive = document.querySelectorAll('#staff-directory-table tbody .status-pill.on').length;
      const totalDisabled = document.querySelectorAll('#staff-directory-table tbody .status-pill.off').length;
      document.getElementById('stat-total').textContent = totalStaff;

      document.getElementById('stat-active').textContent = totalActive;
      document.getElementById('stat-disabled').textContent = totalDisabled;
    }

    function escapeHtml(str) {
      return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
    }

    function getInitials(name) {
      if (!name) return '';
      const parts = name.trim().split(/\s+/).filter(Boolean);
      if (!parts.length) return '';
      if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
      return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
    }

    function clearStaffFormFeedback() {
      const el = document.getElementById('staff-form-feedback');
      if (!el) return;
      el.className = 'staff-form-feedback';
      el.textContent = '';
    }

    function showStaffFormFeedback(message, type = 'error') {
      const el = document.getElementById('staff-form-feedback');
      if (!el) return;
      el.className = 'staff-form-feedback show ' + (type === 'success' ? 'success' : 'error');
      el.textContent = message || '';
    }

    function refreshStaffDirectory() {
      fetch('manage-staff.php?ajax=staff_table')
        .then(res => res.json())
        .then(payload => {
          if (!payload.ok || !Array.isArray(payload.staff)) return;

          const tbody = document.querySelector('#staff-directory-table tbody');
          if (!tbody) return;

          if (!payload.staff.length) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; color:var(--slate-400); padding:22px;">No staff registered yet.</td></tr>';
            updateTotalMetrics();
            return;
          }

          tbody.innerHTML = payload.staff.map((s) => {
            const isActive = Number(s.is_active) === 1;
            const email = (s.email || '').trim();
            const safeName = escapeHtml(s.full_name || '');
            const safeCode = escapeHtml(s.staff_code || '');
            const safeEmail = escapeHtml(email || '');
            const safeContact = escapeHtml(s.contact || '');
            return `
              <tr data-id="${s.staff_id}">
                <td>
                  <div class="staff-name">
                    <div class="mini-avatar">${escapeHtml(getInitials(s.full_name || ''))}</div>
                    <div><b>${safeName}</b><span>ID: ${safeCode}</span></div>
                  </div>
                </td>
                <td>${email ? safeEmail : '<span style="color:var(--slate-400);">â€”</span>'}</td>
                <td>${safeContact}</td>
                <td>
                  <span class="status-pill ${isActive ? 'on' : 'off'}">
                    <span class="d"></span>
                    ${isActive ? 'Active' : 'Disabled'}
                  </span>
                </td>
                <td class="staff-action-cell">
                  <div class="staff-actions-wrap">
                    <button type="button" class="staff-action-btn view-btn view-action" data-id="${s.staff_id}" data-name="${safeName}" data-code="${safeCode}" title="View staff logs" aria-label="View staff logs">View Logs</button>
                    ${isActive ? `
                      <button type="button" class="staff-action-btn toggle-btn active" data-action="disable" data-id="${s.staff_id}" title="Disable staff account" aria-label="Disable staff account">Disable</button>
                    ` : `
                      <button type="button" class="staff-action-btn toggle-btn activate" data-action="activate" data-id="${s.staff_id}" title="Activate staff account" aria-label="Activate staff account">Activate</button>
                    `}
                    <button type="button" class="staff-action-btn delete-btn delete-action" data-id="${s.staff_id}" data-name="${safeName}" data-code="${safeCode}" title="Delete staff member" aria-label="Delete staff member">Delete</button>
                  </div>
                </td>
              </tr>
            `;
          }).join('');

          updateTotalMetrics();
        })
        .catch(() => {
          // Ignore refresh failures so the page stays quiet and stays consistent with live DB state.
        });
    }

    function openDeleteModal(row) {
      pendingDeleteRow = row;
      const deleteAction = row.querySelector('.delete-action');
      const name = deleteAction?.dataset.name || row.dataset.name || row.querySelector('.staff-name b')?.textContent.trim() || 'this staff member';
      const code = deleteAction?.dataset.code || row.querySelector('.delete-action')?.dataset.code || '';
      const intro = document.createElement('p');
      intro.textContent = 'You are about to permanently remove this staff member and disable their login account. Please verify the account details:';
      const highlightedName = document.createElement('strong');
      highlightedName.className = 'delete-target';
      highlightedName.textContent = name;
      const details = document.createElement('p');
      details.className = 'delete-account-details';
      details.textContent = `${code ? `Staff ID: ${code}. ` : ''}This action cannot be undone.`;
      deleteMsg.replaceChildren(intro, highlightedName, details);

      if (deleteCooldownTimer !== null) clearInterval(deleteCooldownTimer);
      let secondsRemaining = 5;
      deleteConfirm.disabled = true;
      deleteConfirm.textContent = `Please wait (${secondsRemaining}s)`;
      deleteCooldownTimer = setInterval(() => {
        secondsRemaining -= 1;
        if (secondsRemaining <= 0) {
          clearInterval(deleteCooldownTimer);
          deleteCooldownTimer = null;
          deleteConfirm.disabled = false;
          deleteConfirm.textContent = 'Delete Permanently';
          return;
        }
        deleteConfirm.textContent = `Please wait (${secondsRemaining}s)`;
      },  1000);
      deleteModal.classList.add('active');
    }

    function closeDeleteModal() {
      deleteModal.classList.remove('active');
      pendingDeleteRow = null;
      if (deleteCooldownTimer !== null) {
        clearInterval(deleteCooldownTimer);
        deleteCooldownTimer = null;
      }
    }

    document.addEventListener('click', (e) => {
      const editButton = e.target.closest('.edit-action');
      if (editButton) {
        e.stopPropagation();
        openEditForStaff(editButton.dataset.id);
        return;
      }

      const deleteButton = e.target.closest('.delete-action');
      if (deleteButton) {
        e.stopPropagation();
        openDeleteForStaff(deleteButton.dataset.id);
        return;
      }

      const toggleButton = e.target.closest('.toggle-btn');
      if (toggleButton) {
        e.stopPropagation();
        const staffId = toggleButton.dataset.id;
        const action = toggleButton.dataset.action;
        const isBusy = toggleButton.dataset.busy === '1' || pendingStatusRequest.has(staffId + ':' + action);
        if (isBusy) return;

        pendingStatusRequest.add(staffId + ':' + action);
        toggleButton.dataset.busy = '1';
        toggleButton.style.opacity = '0.6';

        triggerStaffStatus(staffId, action)
          .then(() => {
            refreshStaffDirectory();
          })
          .catch((err) => {
            console.error('Status failed:', err);
          })
          .finally(() => {
            pendingStatusRequest.delete(staffId + ':' + action);
            toggleButton.dataset.busy = '0';
            toggleButton.style.opacity = '1';
          });
        return;
      }
    });

    function openDeleteForStaff(staffId) {
      const row = getStaffRowById(staffId);
      if (!row) return;
      openDeleteModal(row);
    }

    function openEditForStaff(staffId) {
      const row = getStaffRowById(staffId);
      if (!row) return;

      const name = row.querySelector('.staff-name b')?.textContent.trim() || '';
      const email = row.cells[1]?.textContent.trim() || '';
      const contact = row.cells[2]?.textContent.trim() || '';

      editTargetRow = row;
      document.getElementById('staff-form-action').value = 'edit';
      document.getElementById('staff-form-id').value = staffId;
      document.getElementById('staff-name-input').value = name;
      document.getElementById('staff-contact-input').value = contact;
      openModal(true, name, email, contact);
    }

    function triggerStaffStatus(staffId, action) {
      const fd = new FormData();
      fd.append('csrf', csrfToken);
      fd.append('action', action);
      fd.append('staff_id', String(staffId));

      return fetch('manage-staff.php', { method: 'POST', body: fd }).then(async (res) => {
        const data = await res.json();
        if (!data.ok) {
          throw new Error(data.error || 'Unable to update status.');
        }
        return data;
      });
    }

    function getStaffRowById(staffId) {
      if (!staffId) return null;
      return document.querySelector(`#staff-directory-table tbody tr[data-id="${staffId}"]`);
    }

    deleteCancel.addEventListener('click', closeDeleteModal);

    deleteModal.addEventListener('click', (e) => {
      if (e.target === deleteModal) {
        closeDeleteModal();
      }
    });

    deleteConfirm.addEventListener('click', async () => {
      if (!pendingDeleteRow || deleteConfirm.disabled) return;
      const sid = pendingDeleteRow.dataset.id;
      deleteConfirm.disabled = true;
      deleteConfirm.textContent = 'Deleting...';

      const fd = new FormData();
      fd.append('csrf', csrfToken);
      fd.append('action', 'delete');
      fd.append('staff_id', sid);
      try {
        const res = await fetch('manage-staff.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.ok) {
          console.error('Delete failed:', data.error || 'unknown');
          return;
        }
        closeDeleteModal();
        refreshStaffDirectory();
      } catch (err) {
        console.error('Delete network error:', err);
      } finally {
        deleteConfirm.disabled = false;
        deleteConfirm.textContent = 'Delete Permanently';
      }
    });

    const modal = document.getElementById('staff-modal');
    const form = document.getElementById('staff-form');
    const openAddBtn = document.getElementById('open-add-btn');
    const cancelBtn = document.getElementById('modal-cancel-btn');
    const closeBtn = document.getElementById('modal-close-btn');
    let editTargetRow = null;

    const nameInput = document.getElementById('staff-name-input');
    const emailInput = document.getElementById('staff-email-input');
    const contactInput = document.getElementById('staff-contact-input');
    const nameVisibleInput = document.getElementById('staff-name-visible');
    const contactVisibleInput = document.getElementById('staff-contact-visible');
    const detailFields = document.getElementById('staff-detail-fields');

    function syncVisibleStaffFields() {
      if (!nameVisibleInput || !contactVisibleInput) return;
      nameInput.value = nameVisibleInput.value.trim();
      contactInput.value = contactVisibleInput.value.trim();
    }

    const accountFields = document.getElementById('staff-account-fields');
    const infoBtn = document.getElementById('staff-info-btn');
    const infoPanel = document.getElementById('staff-info-panel');
    const passwordFields = document.getElementById('staff-password-fields');
    const passwordInput = document.getElementById('staff-password-input');
    const confirmPasswordInput = document.getElementById('staff-confirm-password-input');
    const accountTypeRadios = document.querySelectorAll('input[name="account_type"]');

    function setInfoPanel(show) {
      infoPanel.classList.toggle('show', show);
      infoBtn.setAttribute('aria-expanded', show ? 'true' : 'false');
    }

    function resetPasswordToggleButtons() {
      document.querySelectorAll('.password-toggle-btn').forEach((button) => {
        const targetId = button.getAttribute('data-password-target');
        const targetInput = targetId ? document.getElementById(targetId) : null;
        if (!targetInput) return;
        targetInput.type = 'password';
        const icon = button.querySelector('i');
        if (icon) {
          icon.classList.remove('fa-eye-slash');
          icon.classList.add('fa-eye');
        }
        button.setAttribute('aria-label', 'Show password');
      });
    }

    function bindPasswordToggleButtons() {
      document.querySelectorAll('.password-toggle-btn').forEach((button) => {
        const targetId = button.getAttribute('data-password-target');
        const targetInput = targetId ? document.getElementById(targetId) : null;
        if (!targetInput) return;

        button.addEventListener('click', () => {
          const isHidden = targetInput.type === 'password';
          targetInput.type = isHidden ? 'text' : 'password';
          const icon = button.querySelector('i');
          if (icon) {
            icon.classList.toggle('fa-eye', !isHidden);
            icon.classList.toggle('fa-eye-slash', isHidden);
          }
          button.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
        });
      });
    }

    bindPasswordToggleButtons();

    function syncPasswordFields() {
      const standalone = document.getElementById('account-type-standalone').checked;
      passwordFields.classList.toggle('hidden', !standalone);
      passwordInput.disabled = !standalone;
      confirmPasswordInput.disabled = !standalone;
      if (!standalone) {
        passwordInput.value = '';
        confirmPasswordInput.value = '';
      }
      resetPasswordToggleButtons();
    }

    accountTypeRadios.forEach((r) => r.addEventListener('change', syncPasswordFields));
    infoBtn.addEventListener('click', (e) => { e.stopPropagation(); setInfoPanel(!infoPanel.classList.contains('show')); });
    infoPanel.addEventListener('click', (e) => e.stopPropagation());
    document.addEventListener('click', () => setInfoPanel(false));

    function openModal(isEdit = false, name = '', email = '', contact = '') {
      const submitBtn = document.getElementById('staff-submit-btn');
      accountFields.style.display = isEdit ? 'none' : '';
      infoBtn.classList.toggle('hidden', isEdit);
      setInfoPanel(false);
      if (detailFields) detailFields.classList.toggle('hidden', !isEdit);
      if (isEdit) {
        document.getElementById('modal-title').textContent = 'Edit Staff Member Details';
        nameVisibleInput.value = name;
        contactVisibleInput.value = contact;
        emailInput.value = email;
        nameInput.value = name;
        contactInput.value = contact;
        if (submitBtn) submitBtn.textContent = 'Save Staff';
      } else {
        document.getElementById('modal-title').textContent = 'Add New Staff Member';
        form.reset();
        nameVisibleInput.value = '';
        contactVisibleInput.value = '';
        emailInput.value = '';
        nameInput.value = '';
        contactInput.value = '';
        if (submitBtn) submitBtn.textContent = 'Send Invite';
      }
      syncPasswordFields();
      clearStaffFormFeedback();
      modal.classList.add('active');
    }

    function closeModal() {
      modal.classList.remove('active');
      editTargetRow = null;
      clearStaffFormFeedback();
      form.reset();
      setInfoPanel(false);
      syncPasswordFields();
    }

    openAddBtn.addEventListener('click', () => {
      document.getElementById('staff-form-action').value = 'add';
      document.getElementById('staff-form-id').value = '';
      openModal(false);
    });
    cancelBtn.addEventListener('click', closeModal);
    closeBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const action = document.getElementById('staff-form-action').value;
      const staffId = document.getElementById('staff-form-id').value;
      const email = document.getElementById('staff-email-input').value.trim();
      const inviteMessage = 'You have been invited to join as a staff member.';
      const fullName = (nameInput.value || nameVisibleInput?.value || '').trim();
      const contact = (contactInput.value || contactVisibleInput?.value || '').trim();

      clearStaffFormFeedback();
      if (action !== 'edit' && !email) { showStaffFormFeedback('Email address is required.', 'error'); return; }
      if (action === 'edit' && !fullName) { showStaffFormFeedback('Full name is required.', 'error'); return; }
      if (action === 'edit' && !contact) { showStaffFormFeedback('Contact number is required.', 'error'); return; }

      const fd = new FormData();
      fd.append('csrf', csrfToken);
      fd.append('action', action);
      fd.append('full_name', action === 'edit' ? fullName : (fullName || email.split('@')[0].replace(/[._-]+/g, ' ').trim() || 'New Staff Member'));
      fd.append('email', email);
      fd.append('contact', action === 'edit' ? contact : (contact || ''));
      fd.append('hire_message', inviteMessage);
      if (action === 'edit') fd.append('staff_id', staffId);
      if (action === 'add') {
        const accountType = document.querySelector('input[name="account_type"]:checked')?.value || 'customer';
        fd.append('account_type', accountType);
        if (accountType === 'standalone') {
          fd.append('password', passwordInput.value);
          fd.append('confirm_password', confirmPasswordInput.value);
        }
      }

      try {
        const res = await fetch('manage-staff.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.ok) {
          showStaffFormFeedback(data.error || 'Unable to save staff at this time.', 'error');
          return;
        }

        refreshStaffDirectory();
        showStaffFormFeedback(action === 'add' ? 'Staff invite sent successfully.' : 'Staff member updated successfully.', 'success');
        setTimeout(() => closeModal(), 250);
      } catch (err) {
        console.error('Staff save failed:', err);
        showStaffFormFeedback('The save was interrupted. Please try again.', 'error');
      }
    });
  });
</script>
</body>
</html>