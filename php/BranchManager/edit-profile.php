<?php
/**
 * edit-profile.php — User's personal profile (branch manager account).
 * Handles POST submit to update the branch manager account name/email with CSRF protection.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../profile_avatar.php';
require_once __DIR__ . '/../imgbb_image_host.php';
require_once __DIR__ . '/../Login/mailer.php';
require_once __DIR__ . '/../role_state.php';


require_branch_manager();

function branch_manager_link_json(bool $ok, string $message, int $status = 200, array $extra = []): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => $ok, 'message' => $message] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
}

function branch_manager_link_current_branch_id(PDO $pdo, int $userId): int {
    if ($userId <= 0) {
        return 0;
    }

    $stmt = $pdo->prepare('SELECT branch_id FROM branches WHERE manager_user_id = :user_id ORDER BY branch_id ASC LIMIT 1');
    $stmt->execute([':user_id' => $userId]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

function branch_manager_link_customer_role_id(PDO $pdo): int {
    $stmt = $pdo->prepare("SELECT role_id FROM roles WHERE LOWER(TRIM(role_name)) = 'customer' ORDER BY role_id ASC LIMIT 1");
    $stmt->execute();
    return (int) ($stmt->fetchColumn() ?: 0);
}

function branch_manager_link_update_target(PDO $pdo, int $branchId, int $currentUserId, int $targetUserId): void {
    $stmt = $pdo->prepare(
        'UPDATE branches SET manager_user_id = :target_user_id WHERE branch_id = :branch_id AND manager_user_id = :current_user_id'
    );
    $stmt->execute([
        ':target_user_id' => $targetUserId,
        ':branch_id' => $branchId,
        ':current_user_id' => $currentUserId,
    ]);

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('The branch record could not be updated.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array((string) $_POST['action'], ['send_existing_code', 'start_new_registration', 'verify_code'], true)) {
    $linkAction = (string) $_POST['action'];
    $csrfToken = (string) ($_POST['csrf'] ?? '');
    if (!csrf_check($csrfToken)) {
        branch_manager_link_json(false, 'Your session expired. Refresh the page and try again.', 403);
    }

    $managerUserId = (int) ($_SESSION['user_id'] ?? 0);
    if ($managerUserId <= 0) {
        branch_manager_link_json(false, 'Please sign in again before linking an account.', 401);
    }

    $branchId = branch_manager_link_current_branch_id($pdo, $managerUserId);
    if ($branchId <= 0) {
        branch_manager_link_json(false, 'No branch is currently assigned to this manager account.', 409);
    }

    $customerRoleId = branch_manager_link_customer_role_id($pdo);
    if ($customerRoleId <= 0) {
        branch_manager_link_json(false, 'The Customer role is not available.', 503);
    }

    $now = time();
    $pending = $_SESSION['branch_manager_link_otp'] ?? null;

    if ($linkAction === 'send_existing_code') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            branch_manager_link_json(false, 'Enter a valid customer email address.', 422);
        }
        if (is_array($pending) && (int) ($pending['last_sent_at'] ?? 0) > $now - 60) {
            branch_manager_link_json(false, 'Please wait one minute before requesting another code.', 429);
        }

        $accountStmt = $pdo->prepare(
            "SELECT u.user_id
             FROM users u
             WHERE LOWER(TRIM(u.email)) = ?
               AND u.role_id = ?
               AND LOWER(TRIM(COALESCE(u.status, ''))) IN ('active', 'approved', 'license_expired')
               AND u.user_id <> ?
               AND NOT EXISTS (
                   SELECT 1 FROM branches b WHERE b.manager_user_id = u.user_id
               )
             LIMIT 1"
        );
        $accountStmt->execute([$email, $customerRoleId, $managerUserId]);
        $targetUserId = (int) ($accountStmt->fetchColumn() ?: 0);
        if ($targetUserId <= 0) {
            branch_manager_link_json(false, 'No eligible customer account was found for that email.', 404);
        }

        $code = (string) random_int(100000, 999999);
        send_registration_code($email, $code);
        $_SESSION['branch_manager_link_otp'] = [
            'kind' => 'existing',
            'branch_id' => $branchId,
            'manager_user_id' => $managerUserId,
            'user_id' => $targetUserId,
            'email' => $email,
            'code_hash' => password_hash($code, PASSWORD_DEFAULT),
            'expires_at' => $now + 600,
            'attempts' => 0,
            'last_sent_at' => $now,
        ];
        branch_manager_link_json(true, 'A 6-digit verification code was sent to that customer account email.');
    }

    if ($linkAction === 'start_new_registration') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
        $acknowledge = (string) ($_POST['test_data_acknowledgement'] ?? '');

        if ($username === '' || strlen($username) > 225) {
            branch_manager_link_json(false, 'Enter a username no longer than 225 characters.', 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            branch_manager_link_json(false, 'Enter a valid email address.', 422);
        }
        if ($password !== $confirmPassword) {
            branch_manager_link_json(false, 'Passwords do not match.', 422);
        }
        if (strlen($password) < 8 || strlen($password) > 15 || !preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password) || !preg_match('/\d/', $password) || !preg_match('/[^a-zA-Z0-9]/', $password)) {
            branch_manager_link_json(false, 'Password must be 8 to 15 characters and include upper/lowercase letters, a number, and a symbol.', 422);
        }
        if ($acknowledge !== 'yes') {
            branch_manager_link_json(false, 'Confirm the demo privacy notice to continue.', 422);
        }
        if (is_array($pending) && (int) ($pending['last_sent_at'] ?? 0) > $now - 60) {
            branch_manager_link_json(false, 'Please wait one minute before requesting another code.', 429);
        }

        $dupeStmt = $pdo->prepare('SELECT 1 FROM users WHERE LOWER(TRIM(email)) = ? LIMIT 1');
        $dupeStmt->execute([$email]);
        if ($dupeStmt->fetchColumn()) {
            branch_manager_link_json(false, 'That email is already registered. Link the existing account instead.', 409);
        }

        $code = (string) random_int(100000, 999999);
        send_registration_code($email, $code);
        $_SESSION['branch_manager_link_otp'] = [
            'kind' => 'new',
            'branch_id' => $branchId,
            'manager_user_id' => $managerUserId,
            'username' => $username,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'code_hash' => password_hash($code, PASSWORD_DEFAULT),
            'expires_at' => $now + 600,
            'attempts' => 0,
            'last_sent_at' => $now,
        ];
        branch_manager_link_json(true, 'A 6-digit verification code was sent. Enter it to create and link the customer account.');
    }

    if ($linkAction === 'verify_code') {
        if (!is_array($pending) || (int) ($pending['branch_id'] ?? 0) !== $branchId || (int) ($pending['manager_user_id'] ?? 0) !== $managerUserId || (int) ($pending['expires_at'] ?? 0) < $now) {
            unset($_SESSION['branch_manager_link_otp']);
            branch_manager_link_json(false, 'The verification code expired. Request a new code.', 422);
        }

        $pending['attempts'] = (int) ($pending['attempts'] ?? 0) + 1;
        if ($pending['attempts'] > 5) {
            unset($_SESSION['branch_manager_link_otp']);
            branch_manager_link_json(false, 'Too many incorrect attempts. Request a new code.', 429);
        }
        $_SESSION['branch_manager_link_otp'] = $pending;

        $code = preg_replace('/\D+/', '', (string) ($_POST['code'] ?? ''));
        if (strlen($code) !== 6 || !password_verify($code, (string) ($pending['code_hash'] ?? ''))) {
            branch_manager_link_json(false, 'The verification code is incorrect.', 422);
        }

        $pdo->beginTransaction();
        try {
            $ownerCheck = $pdo->prepare('SELECT 1 FROM branches WHERE branch_id = ? AND manager_user_id = ? FOR UPDATE');
            $ownerCheck->execute([$branchId, $managerUserId]);
            if (!$ownerCheck->fetchColumn()) {
                throw new RuntimeException('The branch owner changed before the account could be linked.');
            }

            $targetUserId = 0;
            if (($pending['kind'] ?? '') === 'existing') {
                $targetStmt = $pdo->prepare(
                    "SELECT u.user_id
                     FROM users u
                     WHERE u.user_id = ?
                       AND u.role_id = ?
                       AND LOWER(TRIM(COALESCE(u.status, ''))) IN ('active', 'approved', 'license_expired')
                       AND u.user_id <> ?
                       AND NOT EXISTS (SELECT 1 FROM branches b WHERE b.manager_user_id = u.user_id)
                     FOR UPDATE"
                );
                $targetStmt->execute([(int) ($pending['user_id'] ?? 0), $customerRoleId, $managerUserId]);
                $targetUserId = (int) ($targetStmt->fetchColumn() ?: 0);
                if ($targetUserId <= 0) {
                    throw new RuntimeException('The customer account is no longer eligible to link.');
                }
            } elseif (($pending['kind'] ?? '') === 'new') {
                $duplicate = $pdo->prepare('SELECT 1 FROM users WHERE LOWER(TRIM(email)) = ? LIMIT 1 FOR UPDATE');
                $duplicate->execute([(string) ($pending['email'] ?? '')]);
                if ($duplicate->fetchColumn()) {
                    throw new RuntimeException('That email was registered while verification was in progress. Link the existing account instead.');
                }

                $hasCurrentRole = map_pump_current_role_column_ready($pdo);
                $insertSql = $hasCurrentRole
                    ? 'INSERT INTO users (user_name, email, hashed_password, role_id, current_role_id, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, "active", NOW(), NOW())'
                    : 'INSERT INTO users (user_name, email, hashed_password, role_id, status, created_at, updated_at) VALUES (?, ?, ?, ?, "active", NOW(), NOW())';
                $insert = $pdo->prepare($insertSql);
                $insertParams = [(string) $pending['username'], (string) $pending['email'], (string) $pending['password_hash'], $customerRoleId];
                if ($hasCurrentRole) {
                    $insertParams[] = $customerRoleId;
                }
                $insert->execute($insertParams);
                $targetUserId = (int) $pdo->lastInsertId();
            } else {
                throw new RuntimeException('The link request is invalid.');
            }

            branch_manager_link_update_target($pdo, $branchId, $managerUserId, $targetUserId);
            $pdo->commit();
            unset($_SESSION['branch_manager_link_otp']);

            if (($pending['kind'] ?? '') === 'new') {
                try {
                    if (function_exists('mail_send_account_notice')) {
                        mail_send_account_notice((string) $pending['email'], [
                            'kind' => 'account',
                            'action' => 'created',
                            'account_label' => (string) $pending['username'],
                            'effect' => 'Your Map & Pump customer account was created and linked to your branch manager account.',
                        ]);
                    }
                } catch (Throwable $mailError) {
                    error_log('Branch manager linked customer welcome email failed: ' . $mailError->getMessage());
                }
            }

            branch_manager_link_json(true, 'Customer account linked successfully.');
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    branch_manager_link_json(false, 'Unsupported account link request.', 400);
}

$message = '';
$message_type = '';
$accountDeletionFlash = (string) ($_SESSION['account_deletion_flash'] ?? '');
unset($_SESSION['account_deletion_flash']);

// ------- Avatar upload endpoint (multipart) --------
// Handled separately so we can respond as JSON to the client-side uploader.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_avatar') {
    header('Content-Type: application/json; charset=utf-8');
    if (!csrf_check($_POST['csrf'] ?? null)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
    if (empty($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => 'No file uploaded']);
        exit;
    }

    $file = $_FILES['avatar'];
    // Size cap: 3 MB
    if ($file['size'] > 3 * 1024 * 1024) {
        echo json_encode(['ok' => false, 'error' => 'File too large (max 3MB)']);
        exit;
    }
    // Verify actual mime type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowedMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($allowedMime[$mime])) {
        echo json_encode(['ok' => false, 'error' => 'Only JPG, PNG, WebP, or GIF images are allowed']);
        exit;
    }
    $ext = $allowedMime[$mime];

    $uid      = (int)$_SESSION['user_id'];
    $filename = 'branchmgr-' . $uid . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
    $publicUrl = 'BranchManager/uploads/' . $filename;
    $destPath = null;
    if (map_pump_imgbb_enabled()) {
      $imageBytes = @file_get_contents((string) $file['tmp_name']);
      try {
        if (!is_string($imageBytes)) {
          throw new RuntimeException('Could not read the uploaded profile image.');
        }
        $publicUrl = map_pump_imgbb_upload($imageBytes, $filename, $mime);
      } catch (Throwable $e) {
        error_log('Branch manager avatar ImgBB upload failed: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'Could not upload the profile image']);
        exit;
      }
    } else {
      $uploadDir = __DIR__ . '/uploads';
      if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0775, true); }
      if (!is_writable($uploadDir)) {
        echo json_encode(['ok' => false, 'error' => 'Upload folder is not writable']);
        exit;
      }
      $destPath = $uploadDir . '/' . $filename;
      if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        echo json_encode(['ok' => false, 'error' => 'Could not save the uploaded file']);
        exit;
      }
    }

    try {
        // Reuse oauth_avatar_url column since the app already uses it for local avatars.
        $stmt = $pdo->prepare("UPDATE users SET oauth_avatar_url = :u, updated_at = NOW() WHERE user_id = :uid");
        $stmt->execute([':u' => $publicUrl, ':uid' => $uid]);
        $_SESSION['user_avatar_url'] = $publicUrl;

        // Best-effort log
        try { log_activity($pdo, 0, 'user', $uid, $_SESSION['user_name'] ?? '', 'Updated branch manager profile avatar'); } catch (Throwable $e) {}

        echo json_encode(['ok' => true, 'url' => '/' . ltrim($publicUrl, '/')]);
    } catch (PDOException $e) {
      if ($destPath !== null) {
        @unlink($destPath);
      }
        echo json_encode(['ok' => false, 'error' => 'Database error saving avatar']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $message = 'Invalid CSRF token. Please refresh and try again.';
        $message_type = 'danger';
    } else {
        $full_name = trim($_POST['full_name'] ?? '');
        $email     = trim($_POST['email'] ?? '');

        if ($full_name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Name and a valid email are required.';
            $message_type = 'danger';
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET user_name = :n, email = :e, updated_at = NOW()
                    WHERE user_id = :uid
                ");
                $stmt->execute([
                    ':n'   => $full_name,
                    ':e'   => $email,
                    ':uid' => $_SESSION['user_id'],
                ]);

                $_SESSION['user_name']  = $full_name;
                $_SESSION['user_email'] = $email;

                $message = 'Profile updated successfully.';
                $message_type = 'success';
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $message = 'That email is already in use by another account.';
                } else {
                    error_log('Branch manager profile update failed: ' . $e->getMessage());
                    $message = 'Could not update profile right now.';
                }
                $message_type = 'danger';
            }
        }
    }
}

$ctx      = current_context($pdo);
$user     = $ctx['user'];
$branch   = $ctx['branch'];

$managerDisplayName = !empty($user['full_name']) ? $user['full_name'] : 'Manager';
$initials           = function_exists('map_pump_profile_initial') 
                      ? map_pump_profile_initial($managerDisplayName, 'M') 
                      : initials($managerDisplayName);

// Resolve avatar URL (may live under BranchManager/uploads/, /php/…, or an OAuth CDN)
$avatarRaw = $user['avatar_url'] ?? ($_SESSION['user_avatar_url'] ?? '');
$avatarUrl = '';
if ($avatarRaw) {
    if (preg_match('#^https?://#i', $avatarRaw)) {
        $avatarUrl = $avatarRaw;                       // absolute URL (Google, etc.)
    } elseif (str_starts_with($avatarRaw, '/')) {
        $avatarUrl = $avatarRaw;                       // already root-relative
    } else {
        $avatarUrl = '/' . ltrim($avatarRaw, '/');     // make it root-relative
    }
}

// Load shared notifications
$alerts       = notif_recent($pdo, (int)$user['user_id'], 5);
$unread_count = notif_unread_count($pdo, (int)$user['user_id']);
$has_unread   = $unread_count > 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MAP & PUMP — Edit Profile</title>

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

    .sidebar{
      width:252px; flex-shrink:0; background:var(--navy-900); border-right:1px solid rgba(255,255,255,0.06);
      display:flex; flex-direction:column; padding:22px 16px; position:sticky; top:0; height:100vh; z-index:60;
    }
    .nav-logo { width: 32px; height: auto; object-fit: contain; }
    .side-role{font-size:11px; color:var(--slate-400); font-weight:500; margin:2px 0 0 46px;}
    .side-branch{
      margin:20px 8px 0; padding:10px 12px; background:var(--navy-800); border-radius:12px;
      border:1px solid rgba(255,255,255,0.06); font-size:12.5px;
      transition: background 0.3s, border-color 0.3s;
      display:flex; align-items:center; gap:10px;
    }
    .side-branch:hover{ background: var(--navy-700); border-color: rgba(52,211,153,0.2); }
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
    .side-link:hover{ background:rgba(255,255,255,0.045); color:var(--slate-50); transform: translateX(4px); }
    .side-link.active{
      background:linear-gradient(135deg, rgba(56,189,248,0.16), rgba(29,78,216,0.12));
      color:var(--slate-50); box-shadow:inset 0 0 0 1px rgba(56,189,248,0.32);
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

    .main{flex:1; min-width:0;}
    .topbar{
      position:sticky; top:0; z-index:50; display:flex; align-items:center; justify-content:space-between;
      padding:16px 32px; background:rgba(7,11,20,0.72); backdrop-filter:blur(14px); border-bottom:1px solid rgba(255,255,255,0.06);
    }
    .topbar h1{font-size:18px; font-weight:700;}
    .topbar .date{font-size:12.5px; color:var(--slate-400); margin-top:2px; font-variant-numeric: tabular-nums;}
    .topbar-right{display:flex; align-items:center; gap:16px;}
    .bell{
      width:36px; height:36px; border-radius:50%; background:var(--navy-800); display:flex; align-items:center;
      justify-content:center; border:1px solid rgba(255,255,255,0.08); position:relative; font-size:15px; cursor:pointer;
      transition: transform 0.2s, background-color 0.2s;
    }
    .bell:hover{ background: var(--navy-700); transform: scale(1.05); }
    .bell .ping{position:absolute; top:6px; right:7px; width:7px; height:7px; border-radius:50%; background:var(--amber-400); box-shadow:0 0 0 2px var(--navy-800);}
    .avatar-chip{display:flex; align-items:center; gap:10px; font-size:13px; cursor: pointer; padding: 4px 8px; border-radius: 99px; transition: background 0.2s;}
    .avatar-chip:hover{background: rgba(255,255,255,0.05);}
    .avatar-circle{width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,var(--sky-400),var(--blue-700)); display:flex; align-items:center; justify-content:center; font-weight:700; font-size:13px; font-family:'Space Grotesk';}

    .content{ padding:30px 32px 60px; opacity: 0; transform: translateY(10px); animation: layoutFadeIn 0.5s ease forwards; }
    @keyframes layoutFadeIn { to { opacity: 1; transform: translateY(0); } }
    .page-foot{ text-align:center; margin-top:34px; padding-top:18px; border-top:1px solid rgba(255,255,255,0.06); font-size:12px; color:var(--slate-400); }

    .page-head{margin-bottom:22px;}
    .page-head h2{font-size:20px; font-weight:700;}
    .page-head p{font-size:13px; color:var(--slate-400); margin-top:4px;}

    .layout-2col{display:grid; grid-template-columns:1fr 1.3fr; gap:20px; align-items:start;}
    @media (max-width:980px){.layout-2col{grid-template-columns:1fr;}}

    .panel{background:linear-gradient(160deg, var(--navy-800), var(--navy-900)); border:1px solid rgba(255,255,255,0.07); border-radius:18px; padding:26px;}
    .panel h3{font-size:15px; font-weight:700; margin-bottom:18px;}

    .avatar-big{
      width:88px; height:88px; border-radius:50%; margin:0 auto 14px; background:linear-gradient(135deg,var(--sky-400),var(--blue-700));
      display:flex; align-items:center; justify-content:center; font-weight:700; font-size:30px; font-family:'Space Grotesk'; position:relative;
    }
    .avatar-edit-btn{
      position:absolute; bottom:-2px; right:-2px; width:30px; height:30px; border-radius:50%; background:var(--navy-950);
      border:2px solid var(--navy-800); display:flex; align-items:center; justify-content:center; font-size:12px; color:var(--slate-300);
      cursor:pointer; transition: background 0.2s;
    }
    .avatar-edit-btn:hover{background:var(--navy-700);}
    .profile-card-center{text-align:center;}
    .profile-card-center h4{font-size:16px; font-weight:700; margin-bottom:2px;}
    .profile-card-center span{font-size:12.5px; color:var(--slate-400);}
    .profile-tag{
      display:inline-flex; align-items:center; gap:6px; margin-top:12px; font-size:11.5px; color:var(--mint-400);
      background:rgba(52,211,153,0.12); border:1px solid rgba(52,211,153,0.3); padding:5px 12px; border-radius:999px;
    }

    .form-group{margin-bottom:16px;}
    .form-row{display:grid; grid-template-columns:1fr 1fr; gap:14px;}
    @media (max-width:560px){.form-row{grid-template-columns:1fr;}}
    .form-group label{display:block; font-size:12px; color:var(--slate-400); margin-bottom:6px; font-weight:500;}
    .form-control-custom{
      width:100%; background:var(--navy-950); border:1px solid rgba(255,255,255,0.08);
      border-radius:10px; padding:11px 14px; color:var(--slate-50); font-size:13.5px; outline:none;
      font-family:inherit; transition: border-color 0.2s;
    }
    .form-control-custom:focus{border-color:var(--sky-400);}
    .form-control-custom:disabled{color:var(--slate-400); cursor:not-allowed; opacity:0.7;}

    .btn-save{
      background:linear-gradient(135deg, var(--sky-400), var(--blue-700)); color:#fff; font-weight:700; font-size:13.5px;
      padding:12px 20px; border-radius:11px; border:none; cursor:pointer; display:inline-flex; align-items:center; gap:8px;
      box-shadow:0 10px 24px -10px rgba(56,189,248,0.55); transition: transform 0.2s, box-shadow 0.2s;
    }
    .btn-save:hover{ transform: translateY(-2px); box-shadow:0 12px 28px -8px rgba(56,189,248,0.7); }

    .danger-zone{
      margin-top:22px; padding:18px 20px; border-radius:14px; border:1px solid rgba(248,113,113,0.28);
      background:rgba(248,113,113,0.06);
    }
    .danger-zone h4{font-size:13.5px; font-weight:700; color:var(--red-400); margin-bottom:6px;}
    .danger-zone p{font-size:12.5px; color:var(--slate-300); line-height:1.6; margin-bottom:14px;}
    .btn-danger-outline{
      background:transparent; border:1px solid rgba(248,113,113,0.4); color:var(--red-400); font-weight:700; font-size:13px;
      padding:10px 16px; border-radius:10px; cursor:pointer; display:inline-flex; align-items:center; gap:8px; transition: all 0.2s;
    }
    .btn-danger-outline:hover{background:rgba(248,113,113,0.12);}

    /* Modal */
    .modal-overlay {
      position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(7, 11, 20, 0.85);
      backdrop-filter: blur(8px); z-index: 100; display: flex; align-items: center; justify-content: center;
      opacity: 0; pointer-events: none; transition: opacity 0.3s ease;
    }
    .modal-overlay.active { opacity: 1; pointer-events: auto; }
    .modal-card-custom {
      background: linear-gradient(160deg, var(--navy-800), var(--navy-900));
      border: 1px solid rgba(248,113,113,0.25); border-radius: 20px;
      width: 100%; max-width: 440px; padding: 28px; transform: scale(0.95);
      transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
    }
    .modal-overlay.active .modal-card-custom { transform: scale(1); }
    .modal-icon{
      width:52px; height:52px; border-radius:50%; background:rgba(248,113,113,0.14); color:var(--red-400);
      display:flex; align-items:center; justify-content:center; font-size:22px; margin-bottom:16px;
    }
    .modal-card-custom h3{font-size:17px; font-weight:700; margin-bottom:8px;}
    .modal-card-custom p{font-size:13px; color:var(--slate-300); line-height:1.6; margin-bottom:22px;}
    .modal-actions{display:flex; gap:12px; justify-content:flex-end;}
    .btn-secondary-custom{
      background: transparent; border: 1px solid rgba(255,255,255,0.15); color: var(--slate-300);
      padding: 10px 18px; border-radius: 10px; cursor: pointer; font-size: 13px; font-weight: 600;
      transition: background 0.2s, color 0.2s;
    }
    .btn-secondary-custom:hover { background: rgba(255,255,255,0.05); color: var(--slate-50); }
    .btn-confirm-danger{
      background:linear-gradient(135deg, #f87171, #b91c1c); color:#fff; font-weight:700; font-size:13px;
      padding:10px 18px; border-radius:10px; border:none; cursor:pointer; transition: filter 0.2s;
    }
    .btn-confirm-danger:hover{filter:brightness(1.08);}

    .notif-popup {
      position: absolute; top: 48px; right: 60px; width: 320px; max-height: 420px; overflow-y: auto;
      background: var(--navy-800); border: 1px solid rgba(255,255,255,0.12); border-radius: 14px;
      box-shadow: 0 16px 32px rgba(0,0,0,0.4); padding: 14px 12px; z-index: 100; display: none;
      animation: fadeInDown 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .notif-popup.show { display: block; }
    .notif-header { display:flex; align-items:center; justify-content:space-between; border-bottom:1px solid rgba(255,255,255,0.08); padding-bottom:10px; margin-bottom:10px; }
    .notif-header h4 { font-size:14px; font-weight:700; margin:0; }
    .notif-header button { background: transparent; border: none; color: var(--sky-400); font-size: 11px; cursor: pointer; }
    .notif-item { padding:9px 8px; border-radius:10px; display:flex; gap:10px; align-items:flex-start; transition:background-color 0.2s; border:1px solid transparent; }
    .notif-item:hover { background-color: rgba(255,255,255,0.04); }
    .notif-item .icon { width:32px; height:32px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:12px; flex-shrink:0; background:rgba(148,163,184,0.12); color:var(--slate-200); }
    .notif-item.unread { border-color: rgba(56,189,248,0.2); }
    .notif-item.warning .icon { background: rgba(251,191,36,0.16); color: var(--amber-300); }
    .notif-item.error .icon { background: rgba(248,113,113,0.16); color: var(--red-400); }
    .notif-item.success .icon { background: rgba(52,211,153,0.14); color: var(--mint-400); }
    .notif-item.info .icon { background: rgba(56,189,248,0.14); color: var(--sky-400); }
    .notif-item .text b { display:block; font-size:12.5px; color:var(--slate-50); margin-bottom:2px; }
    .notif-item .text span { color:var(--slate-400); font-size:11.5px; display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .notif-item .time { font-size:10px; color:var(--slate-400); margin-top:4px; }
    @keyframes fadeInDown { from { opacity:0; transform: translateY(-8px); } to { opacity:1; transform: translateY(0); } }

    /* Account Management redesign: page content only. */
    .account-management .page-head{margin-bottom:26px;}
    .account-management .page-head h2{font-size:24px; margin-bottom:8px;}
    .account-management .page-head p{color:var(--slate-300); line-height:1.6;}
    .account-management .layout-2col{grid-template-columns:minmax(0,2fr) minmax(280px,1fr); gap:24px;}
    .account-management .panel{background:linear-gradient(160deg,#152234,var(--navy-800)); border-color:rgba(148,163,184,.2); border-radius:16px; padding:24px;}
    .account-management .panel h3{font-size:16px; margin:0 0 22px; line-height:1.5;}
    .account-profile-avatar{text-align:center; padding:0 0 26px;}
    .account-management .avatar-big{width:124px; height:124px; border:4px solid var(--slate-50); margin:0 auto 18px; background:linear-gradient(135deg,#0787ff,#00d2df); font-size:48px;}
    .account-management .avatar-edit-btn{position:static; width:auto; height:auto; margin:0 auto; border:1px solid rgba(148,163,184,.35); background:transparent; border-radius:8px; padding:7px 11px; font-size:12px; color:var(--slate-50);}
    .account-management .avatar-edit-btn:hover{background:rgba(255,255,255,.05);}
    .account-management .form-group label{color:var(--slate-300); font-size:13px; font-weight:600; margin-bottom:7px;}
    .account-management .form-control-custom,.account-modal .form-control-custom{background:#142133; border-color:rgba(148,163,184,.35); border-radius:9px; min-height:46px; color:var(--slate-50);}
    .account-management .form-control-custom:focus,.account-modal .form-control-custom:focus{border-color:#1688ff; box-shadow:0 0 0 3px rgba(22,136,255,.12);}
    .account-management .form-control-custom::placeholder,.account-modal .form-control-custom::placeholder{color:var(--slate-400); opacity:1;}
    .account-btn{display:inline-flex; justify-content:center; align-items:center; gap:10px; min-height:42px; padding:10px 18px; border-radius:9px; border:1px solid transparent; font-size:13px; font-weight:700; color:#fff; background:#0d5fdb; cursor:pointer; transition:background .15s; line-height:1.5;}
    .account-btn:hover{background:#1474ed;}
    .account-btn:focus-visible,.account-modal-close:focus-visible{outline:2px solid var(--sky-400); outline-offset:3px;}
    .account-btn.full-width{width:100%;}
    .account-btn.outline{background:transparent; color:#8fcaff; border-color:#1874ed;}
    .account-btn.outline:hover{background:rgba(22,116,237,.1);}
    .account-btn.secondary{background:transparent; color:var(--slate-300); border-color:rgba(148,163,184,.35);}
    .account-btn.secondary:hover{background:rgba(255,255,255,.05);}
    .account-sidecards{display:flex; flex-direction:column; gap:28px;}
    .account-sidecard{text-align:center;}
    .account-sidecard p{font-size:13px; line-height:1.8; color:var(--slate-300); margin:0 0 22px;}
    .account-management .account-sidecard h3{margin-bottom:16px;}
    .account-management .account-delete-panel{margin-top:26px; border-color:rgba(248,113,113,.35); background:linear-gradient(125deg,rgba(127,29,29,.16),var(--navy-900));}
    .account-management .account-delete-panel h3{margin-bottom:10px;}
    .account-delete-panel p{font-size:13px; line-height:1.7; color:var(--slate-300); margin-bottom:20px;}
    .account-delete-panel label{display:block; font-size:13px; font-weight:600; color:var(--slate-300); margin-bottom:9px;}
    .account-delete-phrase{display:inline-block; max-width:100%; overflow-wrap:anywhere; padding:8px 11px; border:1px solid rgba(248,113,113,.35); border-radius:8px; color:#fff; background:rgba(127,29,29,.25); font-size:12px; margin-bottom:10px;}
    .account-delete-panel .form-control-custom{display:block; max-width:540px;}
    .account-delete-actions{display:flex; justify-content:flex-end; margin-top:16px;}
    .account-feedback{font-size:12px; line-height:1.6; color:var(--slate-300); margin-top:12px;}
    .account-modal{visibility:hidden; padding:20px; overflow-y:auto;}
    .account-modal.active{visibility:visible;}
    .account-modal .modal-card-custom{max-width:510px; padding:28px; border-color:rgba(148,163,184,.25); background:linear-gradient(160deg,#1a2a3e,#142133); max-height:calc(100dvh - 40px); overflow-y:auto; border-radius:18px;}
    .account-modal-header{display:flex; align-items:center; justify-content:space-between; gap:14px; margin-bottom:16px;}
    .account-modal .account-modal-header h3{font-size:22px; line-height:1.4; margin:0;}
    .account-modal-close{flex-shrink:0; border:0; background:transparent; padding:4px; color:var(--slate-300); font-size:26px; line-height:1; cursor:pointer;}
    .account-modal p{font-size:13px; line-height:1.8; margin-bottom:24px;}
    .account-link-options{display:flex; flex-direction:column; gap:16px;}
    .account-link-options .account-btn{min-height:48px; font-size:14px;}
    .account-modal .form-group label{display:block; color:var(--slate-300); font-size:14px; font-weight:600; margin-bottom:8px;}
    .account-modal .form-group{margin-bottom:15px;}
    .account-demo-consent{display:flex; align-items:flex-start; gap:9px; margin:18px 0 22px; font-size:12px; color:var(--slate-300); line-height:1.7; font-weight:600;}
    .account-demo-consent input{margin-top:4px; width:15px; height:15px; flex-shrink:0; accent-color:#0d5fdb;}
    .account-modal-actions{display:flex; gap:12px; margin-top:22px;}
    .account-modal-actions .account-btn:last-child{flex:1;}
    .account-modal [hidden],.account-management [hidden]{display:none!important;}
    @media(max-width:1050px){.account-management .layout-2col{grid-template-columns:minmax(0,1fr);} .account-sidecards{display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:20px;}}
    @media(max-width:600px){.account-sidecards{grid-template-columns:1fr;} .account-management .panel{padding:20px;} .account-modal{padding:12px;} .account-modal .modal-card-custom{padding:22px; max-height:calc(100dvh - 24px);} .account-modal .account-modal-header h3{font-size:20px;} .account-delete-actions .account-btn{width:100%;}}

    /* Requested password, OTP, scrollbar and deletion-button refinements. */
    .account-modal,.account-modal .modal-card-custom{color-scheme:dark; scrollbar-width:thin; scrollbar-color:rgba(148,163,184,.42) transparent;}
    .account-modal::-webkit-scrollbar,.account-modal .modal-card-custom::-webkit-scrollbar{width:6px; height:6px;}
    .account-modal::-webkit-scrollbar-track,.account-modal .modal-card-custom::-webkit-scrollbar-track{background:transparent; margin:18px 0;}
    .account-modal::-webkit-scrollbar-thumb,.account-modal .modal-card-custom::-webkit-scrollbar-thumb{background:rgba(148,163,184,.42); border-radius:10px;}
    .account-modal::-webkit-scrollbar-thumb:hover,.account-modal .modal-card-custom::-webkit-scrollbar-thumb:hover{background:rgba(148,163,184,.65);}
    .account-modal::-webkit-scrollbar-button,.account-modal .modal-card-custom::-webkit-scrollbar-button{display:none; height:0;}
    .account-password-wrap{position:relative;}
    .account-modal .account-password-wrap input{padding-right:46px;}
    .account-password-toggle{position:absolute; right:7px; top:50%; transform:translateY(-50%); display:flex; align-items:center; justify-content:center; width:32px; height:32px; border:0; border-radius:6px; background:transparent; color:var(--slate-300); cursor:pointer;}
    .account-password-toggle:hover{background:rgba(255,255,255,.06); color:#fff;}
    .account-password-toggle:focus-visible{outline:2px solid var(--sky-400); outline-offset:2px;}
    .account-password-toggle svg{width:19px; height:19px; fill:none; stroke:currentColor; stroke-width:1.7; stroke-linecap:round; stroke-linejoin:round;}
    .account-password-toggle .eye-slash{display:none;}
    .account-password-toggle[aria-pressed="true"] .eye-slash{display:block;}
    .account-modal .account-password-hint{font-size:11px; line-height:1.6; color:var(--slate-400); margin:7px 0 0;}
    .account-delete-actions .account-btn{background:#dc2626; border-color:#dc2626;}
    .account-delete-actions .account-btn:hover{background:#b91c1c; border-color:#b91c1c;}
    .account-otp-success{background:#d1e7dd; border:1px solid #a3cfbb; color:#0f5132; border-radius:8px; padding:12px 14px; font-size:13px; line-height:1.6; margin-bottom:10px;}
    .account-modal .account-otp-preview-note{font-size:11px; line-height:1.6; margin:0 0 18px; color:var(--slate-400);}
    .account-modal #link-otp-code{text-align:center; font-size:21px; letter-spacing:.45em; padding-left:calc(14px + .45em); font-variant-numeric:tabular-nums;}
    .account-modal .account-otp-help{font-size:12px; margin:7px 0 0; line-height:1.6; color:var(--slate-300);}
  </style>
</head>
<body>

<div class="app-shell">
  <aside class="sidebar">
    <a href="dashboard.php" class="text-decoration-none d-flex align-items-center gap-2 text-white fw-bold padding" style="padding: 6px 8px 0;">
      <img src="/images/Logo.png" alt="Logo" class="nav-logo">
      <span class="disp" style="font-size: 17px; letter-spacing: 0.5px; color: #ffffff; font-weight: 700;">Map & Pump</span>
    </a>
    <div class="side-role">Branch Manager Console</div>

    <?php $hqLogoSrc = function_exists('hq_logo_src') ? hq_logo_src($branch['hq_logo'] ?? null) : null; ?>
    <div class="side-branch">
      <?php if ($hqLogoSrc): ?>
        <img src="<?= htmlspecialchars($hqLogoSrc) ?>" alt="HQ Logo" class="hq-logo" onerror="this.style.display='none'">
      <?php else: ?>
        <div class="hq-logo" style="display:flex; align-items:center; justify-content:center; color:var(--slate-400);"><i class="fa-solid fa-building"></i></div>
      <?php endif; ?>
      <div class="txt">
        <b><?= htmlspecialchars($branch['branch_name'] ?? $user['branch_name'] ?? 'Unassigned Branch') ?></b>
        <span>Branch ID: <?= htmlspecialchars($branch['branch_code'] ?? $user['branch_code'] ?? 'N/A') ?></span>
      </div>
    </div>

    <nav class="side-nav">
      <a href="dashboard.php" class="side-link"><span class="ic"><i class="fa-solid fa-house"></i></span> Dashboard</a>
      <a href="manage-staff.php" class="side-link"><span class="ic"><i class="fa-solid fa-users"></i></span> Manage Staff</a>
      <a href="manage-oil.php" class="side-link"><span class="ic"><i class="fa-solid fa-gas-pump"></i></span> Manage Oil Stock</a>
      <a href="booking-slot.php" class="side-link"><span class="ic"><i class="fa-solid fa-ticket"></i></span> Monitor Booking Slot</a>
      <a href="view-customers.php" class="side-link"><span class="ic"><i class="fa-solid fa-address-book"></i></span> View Customers</a>
      <a href="report-hq.php" class="side-link"><span class="ic"><i class="fa-solid fa-paper-plane"></i></span> Report to HQ</a>
      <a href="branchprofile.php" class="side-link"><span class="ic"><i class="fa-solid fa-building"></i></span> Branch Profile</a>
      <a href="edit-profile.php" class="side-link active"><span class="ic"><i class="fa-solid fa-gear"></i></span> Edit Profile</a>
    </nav>

    <a href="../Login/logout.php" class="side-logout"><span class="ic"><i class="fa-solid fa-right-from-bracket"></i></span> Log Out</a>
  </aside>

  <div class="main">
    <div class="topbar">
      <div>
        <h1>Edit Profile</h1>
        <div class="date" id="live-clock">--</div>
      </div>
      <div class="topbar-right">
        <div class="bell" id="notif-bell"><i class="fa-regular fa-bell"></i><?php if ($has_unread): ?><span class="ping" id="notif-ping"></span><?php endif; ?></div>
        <div class="notif-popup" id="notif-popup">
          <div class="notif-header">
            <h4>Notifications</h4>
            <button id="clear-notifs-btn" type="button">Mark all read</button>
          </div>
          <div class="notif-list" id="notif-list">
            <?php if (!$alerts): ?>
              <div class="notif-empty" style="text-align:center; padding:12px 0; color:var(--slate-400); font-size:12px;">No new notifications</div>
            <?php else:
              foreach ($alerts as $a):
                $meta = notif_ui_meta($a['type'], $a['title']);
                $icon = $meta['icon'];
                $tone = $meta['tone'];
            ?>
              <div class="notif-item <?= h($tone) ?>" data-notif-id="<?= (int)$a['notif_id'] ?>" data-link="<?= h($a['link'] ?? 'dashboard.php') ?>">
                <div class="icon"><i class="fa-solid <?= h($icon) ?>"></i></div>
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
          <div class="avatar-circle"><a href="edit-profile.php"><?= htmlspecialchars(mb_substr($initials,0,1), ENT_QUOTES, 'UTF-8') ?></a></div>
          <div><b><a href="edit-profile.php"><?= htmlspecialchars(explode(' ', $user['full_name'] ?? 'Manager')[0]) ?></a></b></div>
        </div>
      </div>
    </div>

    <div class="content account-management">
      <div class="page-head">
        <h2>Account Management</h2>
        <p>Update your account credentials, settings, or switch between interfaces.</p>
      </div>

      <?php if (!empty($message)): ?>
        <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert" style="border-radius:12px; background:var(--navy-800); color:#fff; border-color:rgba(255,255,255,0.1);">
          <?= htmlspecialchars($message) ?>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>
      <?php if ($accountDeletionFlash !== ''): ?><div class="alert alert-danger" role="alert" style="border-radius:12px; background:var(--navy-800); color:#fff; border-color:rgba(248,113,113,.35);"><?= htmlspecialchars($accountDeletionFlash) ?></div><?php endif; ?>

      <div class="layout-2col">
        <section class="panel account-profile-card" aria-labelledby="account-profile-title">
          <h3 id="account-profile-title">Edit Account Profile</h3>
          <div class="account-profile-avatar">
            <div class="avatar-big" id="avatar-big" style="overflow:hidden; cursor:pointer;">
              <?php if ($avatarUrl): ?>
                <img id="avatar-img" src="<?= htmlspecialchars($avatarUrl, ENT_QUOTES) ?>" alt="Profile" style="width:100%; height:100%; object-fit:cover; border-radius:50%;">
                <span id="avatar-initials" style="display:none;"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></span>
              <?php else: ?>
                <img id="avatar-img" alt="Profile" style="display:none; width:100%; height:100%; object-fit:cover; border-radius:50%;">
                <span id="avatar-initials"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></span>
              <?php endif; ?>
            </div>
            <button type="button" class="avatar-edit-btn" id="avatar-edit-btn">Change Profile Picture</button>
            <input type="file" id="avatar-file-input" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
            <div id="avatar-status" role="status" style="min-height:16px; font-size:11.5px; color:var(--slate-400); margin-top:8px;"></div>
          </div>
          <form id="profile-form" action="edit-profile.php" method="POST">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <div class="form-group">
              <label for="pf-name">Full Name</label>
              <input type="text" id="pf-name" name="full_name" class="form-control-custom" value="<?= htmlspecialchars($user['full_name'] ?? '') ?>" autocomplete="name" required>
            </div>
            <div class="form-group">
              <label for="pf-email">Email Address</label>
              <input type="email" id="pf-email" name="email" class="form-control-custom" value="<?= htmlspecialchars($user['email'] ?? '') ?>" autocomplete="email" required>
            </div>
            <button type="submit" class="account-btn full-width">Save Changes</button>
          </form>
        </section>
        <div class="account-sidecards">
          <section class="panel account-sidecard">
            <h3>Change Password</h3>
            <p>Update your account password securely.</p>
            <button type="button" class="account-btn full-width" id="change-password-btn">Change Password</button>
          </section>
          <section class="panel account-sidecard">
            <h3>Account Interface Switcher</h3>
            <p>Link a customer account to this Branch Manager account to switch between the two profiles.</p>
            <button type="button" class="account-btn outline full-width" id="switch-account-btn"><i class="fa-solid fa-link" aria-hidden="true"></i> Link to Customer Account</button>
          </section>
        </div>
      </div>
      <section class="panel account-delete-panel" aria-labelledby="delete-account-title">
        <h3 id="delete-account-title">Delete Branch Manager account</h3>
        <p>This Deletes your Branch Manager account and signs you out.</p>
        <form id="account-delete-form" data-frontend-only="true">
          <label for="account-delete-confirmation">Type this exact text to continue</label>
          <code class="account-delete-phrase" id="account-delete-phrase"><?= htmlspecialchars(($user['full_name'] ?? $managerDisplayName) . '_delete_account', ENT_QUOTES, 'UTF-8') ?></code>
          <input type="text" class="form-control-custom" id="account-delete-confirmation" autocomplete="off" spellcheck="false" aria-describedby="account-delete-phrase" required>
          <div class="account-delete-actions"><button type="submit" class="account-btn">Delete Account</button></div>
          <div class="account-feedback" id="account-delete-feedback" role="status" hidden></div>
        </form>
      </section>

      <div class="page-foot">© 2026 MAP & PUMP | All Right Reserved</div>
    </div>
  </div>
</div>

<!-- Frontend-only modal flow. Profile save and avatar upload remain unchanged. -->
<div class="modal-overlay account-modal" id="switch-modal" aria-hidden="true" inert>
  <div class="modal-card-custom" role="dialog" aria-modal="true" aria-labelledby="link-account-title" aria-describedby="link-account-description" tabindex="-1">
    <div class="account-modal-header">
      <h3 id="link-account-title">Link a Customer Account</h3>
      <button type="button" class="account-modal-close" data-close-account-modal aria-label="Close">&times;</button>
    </div>
    <p id="link-account-description">Choose how to link a customer profile. Verification codes expire after 10 minutes.</p>
    <div id="link-choice-step" class="account-link-options">
      <button type="button" class="account-btn full-width" id="link-create-btn"><i class="fa-solid fa-user-plus" aria-hidden="true"></i> Create a new customer account</button>
      <button type="button" class="account-btn outline full-width" id="link-existing-btn"><i class="fa-solid fa-link" aria-hidden="true"></i> Link with existing account</button>
    </div>
    <form id="link-create-step" data-frontend-only="true" hidden>
      <div class="form-group"><label for="link-username">Username</label><input class="form-control-custom" id="link-username" type="text" autocomplete="username" required></div>
      <div class="form-group"><label for="link-new-email">Email</label><input class="form-control-custom" id="link-new-email" type="email" autocomplete="email" required></div>
      <div class="form-group"><label for="link-new-password">Password</label><div class="account-password-wrap"><input class="form-control-custom" id="link-new-password" type="password" minlength="8" maxlength="15" autocomplete="new-password" required aria-describedby="link-new-password-hint"><button type="button" class="account-password-toggle" data-password-toggle="link-new-password" aria-label="Show password" aria-controls="link-new-password" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle><path class="eye-slash" d="M3 3l18 18"></path></svg></button></div><p class="account-password-hint" id="link-new-password-hint">Use 8-15 characters with uppercase and lowercase letters, a number, and a symbol.</p></div>
      <div class="form-group"><label for="link-confirm-password">Confirm password</label><div class="account-password-wrap"><input class="form-control-custom" id="link-confirm-password" type="password" minlength="8" maxlength="15" autocomplete="new-password" required><button type="button" class="account-password-toggle" data-password-toggle="link-confirm-password" aria-label="Show password" aria-controls="link-confirm-password" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle><path class="eye-slash" d="M3 3l18 18"></path></svg></button></div></div>
      <label class="account-demo-consent"><input type="checkbox" id="link-demo-consent" required><span>I understand this is a school demo. I will use an accessible email for verification and fictional information for other fields.</span></label>
      <div class="account-modal-actions"><button type="button" class="account-btn secondary" data-link-back>Back</button><button type="submit" class="account-btn">Send verification code</button></div>
    </form>
    <form id="link-existing-step" data-frontend-only="true" hidden>
      <div class="form-group"><label for="link-existing-email">Customer account email</label><input class="form-control-custom" id="link-existing-email" type="email" autocomplete="email" required></div>
      <div class="account-modal-actions"><button type="button" class="account-btn secondary" data-link-back>Back</button><button type="submit" class="account-btn">Send verification code</button></div>
    </form>
    <form id="link-otp-step" data-frontend-only="true" hidden>
      <div class="account-otp-success" role="status">Verification code sent successfully! Check your inbox.</div>
      <p class="account-otp-preview-note">Frontend preview only. No OTP email has actually been sent.</p>
      <div class="form-group">
        <label for="link-otp-code">6-digit verification code</label>
        <input class="form-control-custom" id="link-otp-code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" aria-describedby="link-otp-help" required>
        <p class="account-otp-help" id="link-otp-help">Enter the code sent to your email.</p>
      </div>
      <div class="account-modal-actions"><button type="button" class="account-btn secondary" data-close-account-modal>Cancel</button><button type="submit" class="account-btn">Verify and link</button></div>
    </form>
    <div class="account-feedback" id="link-feedback" role="status" hidden></div>
  </div>
</div>
<div class="modal-overlay account-modal" id="password-modal" aria-hidden="true" inert>
  <div class="modal-card-custom" role="dialog" aria-modal="true" aria-labelledby="password-modal-title" tabindex="-1">
    <div class="account-modal-header"><h3 id="password-modal-title">Change Password</h3><button type="button" class="account-modal-close" data-close-account-modal aria-label="Close">&times;</button></div>
    <p>Update your account password securely.</p>
    <form id="password-preview-form" data-frontend-only="true">
      <div class="form-group"><label for="password-current">Current password</label><div class="account-password-wrap"><input class="form-control-custom" id="password-current" type="password" autocomplete="current-password" required><button type="button" class="account-password-toggle" data-password-toggle="password-current" aria-label="Show password" aria-controls="password-current" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle><path class="eye-slash" d="M3 3l18 18"></path></svg></button></div></div>
      <div class="form-group"><label for="password-new">New password</label><div class="account-password-wrap"><input class="form-control-custom" id="password-new" type="password" autocomplete="new-password" required aria-describedby="password-new-hint"><button type="button" class="account-password-toggle" data-password-toggle="password-new" aria-label="Show password" aria-controls="password-new" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle><path class="eye-slash" d="M3 3l18 18"></path></svg></button></div><p class="account-password-hint" id="password-new-hint">Use 8-15 characters with uppercase and lowercase letters, a number, and a symbol.</p></div>
      <div class="form-group"><label for="password-confirm">Confirm new password</label><div class="account-password-wrap"><input class="form-control-custom" id="password-confirm" type="password" autocomplete="new-password" required><button type="button" class="account-password-toggle" data-password-toggle="password-confirm" aria-label="Show password" aria-controls="password-confirm" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle><path class="eye-slash" d="M3 3l18 18"></path></svg></button></div></div>
      <div class="account-modal-actions"><button type="button" class="account-btn secondary" data-close-account-modal>Cancel</button><button type="submit" class="account-btn">Change Password</button></div>
      <div class="account-feedback" id="password-feedback" role="status" hidden></div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  document.addEventListener('DOMContentLoaded', () => {
    function updateClock() {
      const now = new Date();
      const options = { weekday: 'long', month: 'short', day: 'numeric', year: 'numeric' };
      const dateString = now.toLocaleDateString('en-US', options);
      const timeString = now.toLocaleTimeString('en-US', { hour12: true, hour: '2-digit', minute: '2-digit', second: '2-digit' });
      document.getElementById('live-clock').textContent = `${dateString} · ${timeString}`;
    }
    setInterval(updateClock, 1000);
    updateClock();

    // Notification bell + persistent read state
    const bellBtn = document.getElementById('notif-bell');
    const notifPopup = document.getElementById('notif-popup');
    const notifPing = document.getElementById('notif-ping');
    const clearNotifsBtn = document.getElementById('clear-notifs-btn');
    const notifList = document.getElementById('notif-list');

    bellBtn.addEventListener('click', () => {
      const isOpen = notifPopup.classList.toggle('show');
      if (isOpen) {
        notifPing.style.display = 'none';
        fetch('notif_read_all.php', { method: 'POST', credentials: 'same-origin' });
      }
    });
    clearNotifsBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      fetch('notif_read_all.php', { method: 'POST', credentials: 'same-origin' })
        .then(() => {
          notifList.querySelectorAll('.notif-item.unread').forEach((item) => {
            item.classList.remove('unread');
            const ping = item.querySelector('.ping');
            if (ping) {
              ping.parentNode.removeChild(ping);
            }
          });
          const emptyNotice = document.createElement('div');
          emptyNotice.className = 'notif-empty';
          emptyNotice.textContent = 'No new notifications';
          emptyNotice.style.cssText = 'text-align:center; padding:12px 0; color:var(--slate-400); font-size:12px;';
          notifList.innerHTML = '';
          notifList.appendChild(emptyNotice);
        });
    });

    // Profile avatar upload
    const avatarFileInput = document.getElementById('avatar-file-input');
    const avatarEditBtn = document.getElementById('avatar-edit-btn');
    const avatarImg = document.getElementById('avatar-img');
    const avatarInitials = document.getElementById('avatar-initials');
    const avatarStatus = document.getElementById('avatar-status');

    avatarEditBtn.addEventListener('click', () => {
      avatarFileInput.click();
    });
    avatarFileInput.addEventListener('change', async (e) => {
      const file = e.target.files[0];
      if (!file) return;
      const formData = new FormData();
      formData.append('action', 'upload_avatar');
      formData.append('csrf', '<?= htmlspecialchars(csrf_token()) ?>');
      formData.append('avatar', file);

      avatarStatus.textContent = 'Uploading...';
      avatarStatus.style.color = 'var(--slate-50)';

      try {
        const response = await fetch('edit-profile.php', {
          method: 'POST',
          body: formData,
          credentials: 'same-origin'
        });
        const result = await response.json();
        if (!response.ok || !result.ok) {
          throw new Error(result.error || 'Failed to upload avatar.');
        }
        avatarImg.src = result.url;
        avatarInitials.style.display = 'none';
        avatarStatus.textContent = 'Upload successful!';
        avatarStatus.style.color = 'var(--mint-400)';
      } catch (error) {
        avatarStatus.textContent = error.message;
        avatarStatus.style.color = 'var(--red-400)';
      }
    });

    // Frontend-only account linking flow
    const switchModal = document.getElementById('switch-modal');
    const passwordModal = document.getElementById('password-modal');
    const csrfToken = '<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>';
    const linkFeedback = document.getElementById('link-feedback');
    let activeAccountModal = null;
    let accountModalOpener = null;
    let previousBodyOverflow = '';
    let linkRequestMode = null;

    function showAccountFeedback(element, text, ok = true) {
      element.textContent = text;
      element.classList.remove('text-success', 'text-danger');
      element.classList.add(ok ? 'text-success' : 'text-danger');
      element.hidden = false;
    }

    async function sendBranchLinkRequest(form, action, extra = {}) {
      const data = new FormData(form);
      data.set('action', action);
      data.set('csrf', csrfToken);
      Object.entries(extra).forEach(([key, value]) => data.set(key, value));
      const response = await fetch('edit-profile.php', {
        method: 'POST',
        body: data,
        credentials: 'same-origin'
      });
      const result = await response.json();
      if (!response.ok || !result.ok) {
        throw new Error(result.message || 'The customer account could not be linked.');
      }
      return result;
    }

    function resetLinkFeedback() {
      linkFeedback.hidden = true;
      linkFeedback.classList.remove('text-success', 'text-danger');
    }

    function setLinkStep(step) {
      const linkSteps = {
        choice: document.getElementById('link-choice-step'),
        create: document.getElementById('link-create-step'),
        existing: document.getElementById('link-existing-step'),
        otp: document.getElementById('link-otp-step')
      };
      Object.keys(linkSteps).forEach((s) => {
        if (linkSteps[s]) {
          linkSteps[s].hidden = s !== step;
        }
      });
      if (linkFeedback) {
        linkFeedback.hidden = true;
      }
      if (step === 'choice') {
        const existingEmail = document.getElementById('link-existing-email');
        if (existingEmail) existingEmail.value = '';
        const username = document.getElementById('link-username');
        if (username) username.value = '';
      }
      if (step === 'create') {
        const newEmail = document.getElementById('link-new-email');
        if (newEmail) newEmail.focus();
      } else if (step === 'existing') {
        const existingEmail = document.getElementById('link-existing-email');
        if (existingEmail) existingEmail.focus();
      } else if (step === 'otp') {
        const otpInput = document.getElementById('link-otp-code');
        if (otpInput) otpInput.focus();
      }
    }

    function openAccountModal(modal, opener) {
      accountModalOpener = opener;
      activeAccountModal = modal;
      previousBodyOverflow = document.body.style.overflow;
      document.body.style.overflow = 'hidden';
      modal.inert = false;
      modal.setAttribute('aria-hidden', 'false');
      modal.classList.add('active');
      modal.querySelector('.modal-card-custom').focus();
    }

    function closeAccountModal() {
      if (!activeAccountModal) return;
      const modal = activeAccountModal;
      modal.classList.remove('active');
      modal.setAttribute('aria-hidden', 'true');
      modal.inert = true;
      document.body.style.overflow = previousBodyOverflow;
      modal.querySelectorAll('form').forEach((form) => form.reset());
      modal.querySelectorAll('input').forEach((input) => input.setCustomValidity(''));
      modal.querySelectorAll('.account-feedback').forEach((feedback) => {
        feedback.hidden = true;
      });
      modal.querySelectorAll('[data-password-toggle]').forEach((button) => {
        const input = document.getElementById(button.dataset.passwordToggle);
        if (input) {
          input.type = 'password';
          button.setAttribute('aria-pressed', 'false');
          button.setAttribute('aria-label', 'Show password');
        }
      });
      activeAccountModal = null;
      accountModalOpener?.focus();
    }

    document.getElementById('switch-account-btn')?.addEventListener('click', (e) => {
      openAccountModal(switchModal, e.currentTarget);
      setLinkStep('choice');
    });
    document.getElementById('change-password-btn')?.addEventListener('click', (e) => openAccountModal(passwordModal, e.currentTarget));
    document.getElementById('link-create-btn')?.addEventListener('click', () => setLinkStep('create'));
    document.getElementById('link-existing-btn')?.addEventListener('click', () => setLinkStep('existing'));
    document.querySelectorAll('[data-link-back]').forEach((btn) => {
      btn.addEventListener('click', () => setLinkStep('choice'));
    });
    document.querySelectorAll('[data-close-account-modal]').forEach((btn) => {
      btn.addEventListener('click', closeAccountModal);
    });
    [switchModal, passwordModal].forEach((modal) => {
      modal?.addEventListener('click', (e) => {
        if (e.target === modal) closeAccountModal();
      });
    });
    document.addEventListener('keydown', (e) => {
      if (!activeAccountModal) return;
      if (e.key === 'Escape') {
        e.preventDefault();
        closeAccountModal();
        return;
      }
      if (e.key === 'Tab') {
        const focusable = [...activeAccountModal.querySelectorAll('button, input, [tabindex="0"]')].filter((el) => !el.disabled && el.getClientRects().length);
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (e.shiftKey && (document.activeElement === first || document.activeElement === activeAccountModal.querySelector('.modal-card-custom'))) {
          e.preventDefault();
          last?.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
          e.preventDefault();
          first?.focus();
        }
      }
    });

    function connectPasswordConfirmation(passwordId, confirmationId) {
      const password = document.getElementById(passwordId);
      const confirmation = document.getElementById(confirmationId);
      if (!password || !confirmation) return;
      const validate = () => {
        confirmation.setCustomValidity(confirmation.value && confirmation.value !== password.value ? 'Passwords do not match.' : '');
      };
      password.addEventListener('input', validate);
      confirmation.addEventListener('input', validate);
    }
    connectPasswordConfirmation('link-new-password', 'link-confirm-password');
    connectPasswordConfirmation('password-new', 'password-confirm');

    const newAccountForm = document.getElementById('link-create-step');
    const existingAccountForm = document.getElementById('link-existing-step');
    const otpForm = document.getElementById('link-otp-step');

    newAccountForm?.addEventListener('submit', async (e) => {
      e.preventDefault();
      resetLinkFeedback();
      const username = document.getElementById('link-username').value.trim();
      const email = document.getElementById('link-new-email').value.trim();
      const password = document.getElementById('link-new-password').value;
      const confirmPassword = document.getElementById('link-confirm-password').value;
      const consent = document.getElementById('link-demo-consent').checked ? 'yes' : '';
      try {
        const result = await sendBranchLinkRequest(newAccountForm, 'start_new_registration', {
          username,
          email,
          password,
          confirm_password: confirmPassword,
          test_data_acknowledgement: consent,
        });
        linkRequestMode = 'new';
        showAccountFeedback(linkFeedback, result.message, true);
        setLinkStep('otp');
      } catch (error) {
        showAccountFeedback(linkFeedback, error.message, false);
      }
    });

    existingAccountForm?.addEventListener('submit', async (e) => {
      e.preventDefault();
      resetLinkFeedback();
      const email = document.getElementById('link-existing-email').value.trim();
      try {
        const result = await sendBranchLinkRequest(existingAccountForm, 'send_existing_code', { email });
        linkRequestMode = 'existing';
        showAccountFeedback(linkFeedback, result.message, true);
        setLinkStep('otp');
      } catch (error) {
        showAccountFeedback(linkFeedback, error.message, false);
      }
    });

    otpForm?.addEventListener('submit', async (e) => {
      e.preventDefault();
      resetLinkFeedback();
      const code = document.getElementById('link-otp-code').value.trim();
      if (!code) {
        showAccountFeedback(linkFeedback, 'Enter the 6-digit verification code.', false);
        return;
      }
      try {
        const result = await sendBranchLinkRequest(otpForm, 'verify_code', { code, link_mode: linkRequestMode || 'existing' });
        showAccountFeedback(linkFeedback, result.message, true);
        setTimeout(() => {
          window.location.reload();
        }, 800);
      } catch (error) {
        showAccountFeedback(linkFeedback, error.message, false);
      }
    });

    document.querySelectorAll('[data-password-toggle]').forEach((button) => {
      button.addEventListener('click', () => {
        const input = document.getElementById(button.dataset.passwordToggle);
        if (!input) return;
        const visible = input.type === 'password';
        input.type = visible ? 'text' : 'password';
        button.setAttribute('aria-pressed', String(visible));
        button.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
      });
    });

    const deleteConfirmation = document.getElementById('account-delete-confirmation');
    const deletePhrase = document.getElementById('account-delete-phrase')?.textContent || '';
    deleteConfirmation?.addEventListener('input', () => {
      deleteConfirmation.setCustomValidity(deleteConfirmation.value === deletePhrase ? '' : 'Type the exact confirmation text shown above.');
    });
    document.getElementById('account-delete-form')?.addEventListener('submit', (e) => {
      e.preventDefault();
      if (!deleteConfirmation || deleteConfirmation.value !== deletePhrase) {
        deleteConfirmation?.setCustomValidity('Type the exact confirmation text shown above.');
        deleteConfirmation?.reportValidity();
        return;
      }
      showAccountFeedback(document.getElementById('account-delete-feedback'), 'Frontend preview only. Your account has not been deleted and you have not been signed out.');
    });
  });
</script>
</body>
</html>
