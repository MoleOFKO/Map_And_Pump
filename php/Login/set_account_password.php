<?php
require_once __DIR__ . '/session_helper.php';
map_pump_configure_session();
session_start();
require_once __DIR__ . '/../conn.php';

header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');

if (empty($_SESSION['account_password_setup_csrf'])) {
    $_SESSION['account_password_setup_csrf'] = bin2hex(random_bytes(32));
}

function account_password_setup_find_token(PDO $pdo, string $tokenHash, bool $lock = false): array
{
  try {
    $lockClause = $lock ? ' FOR UPDATE' : '';
    $stmt = $pdo->prepare(
      "SELECT t.user_id, u.email, u.user_name,
          COALESCE(business_role.role_name, primary_role.role_name, 'Account') AS account_role,
          'account_password_setup_tokens' AS token_table
       FROM account_password_setup_tokens t
       INNER JOIN users u ON u.user_id = t.user_id
       LEFT JOIN roles business_role ON business_role.role_id = u.business_role_id
       LEFT JOIN roles primary_role ON primary_role.role_id = u.role_id
       WHERE t.token_hash = ? AND t.used_at IS NULL AND t.expires_at > NOW()
       LIMIT 1{$lockClause}"
    );
    $stmt->execute([$tokenHash]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $exception) {
    error_log('[Account password setup] Token table lookup failed: ' . $exception->getMessage());
    return [];
    }
}

$token = trim((string) ($_POST['token'] ?? $_GET['token'] ?? ''));
$error = '';
$account = [];
$tokenValid = preg_match('/^[A-Za-z0-9_-]{40,80}$/', $token) === 1;

if ($tokenValid) {
    $account = account_password_setup_find_token($pdo, hash('sha256', $token));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$tokenValid || !$account) {
        $error = 'This password setup link is invalid, expired, or already used.';
    } elseif (!hash_equals((string) $_SESSION['account_password_setup_csrf'], (string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session expired. Reload this page and try again.';
    } else {
        $newPassword = (string) ($_POST['password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
        $passwordLength = mb_strlen($newPassword);
        if ($passwordLength < 8 || $passwordLength > 15) {
            $error = 'Password must be 8 to 15 characters long.';
        } elseif (!hash_equals($newPassword, $confirmPassword)) {
            $error = 'The passwords do not match.';
        } else {
            try {
                $pdo->beginTransaction();
                $lockedAccount = account_password_setup_find_token($pdo, hash('sha256', $token), true);
                if (!$lockedAccount) {
                    throw new RuntimeException('This password setup link is invalid, expired, or already used.');
                }

                $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $passwordUpdate = $pdo->prepare('UPDATE users SET hashed_password = ?, updated_at = NOW() WHERE user_id = ?');
                $passwordUpdate->execute([$passwordHash, (int) $lockedAccount['user_id']]);

                $tokenTable = $lockedAccount['token_table'] === 'account_password_setup_tokens'
                    ? $lockedAccount['token_table']
                    : '';
                if ($tokenTable === '') {
                    throw new RuntimeException('The password setup token could not be validated.');
                }
                $consumeToken = $pdo->prepare(
                    "UPDATE {$tokenTable} SET used_at = NOW() WHERE token_hash = ? AND used_at IS NULL"
                );
                $consumeToken->execute([hash('sha256', $token)]);
                if ($consumeToken->rowCount() !== 1) {
                    throw new RuntimeException('This password setup link was already used.');
                }

                $pdo->commit();
                session_regenerate_id(true);
                $_SESSION['login_prefill_email'] = (string) $lockedAccount['email'];
                header('Location: login.php');
                exit;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[Account password setup] ' . $exception->getMessage());
                $error = $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'The password could not be saved. Please try again.';
            }
        }
    }
}

$tokenValid = $tokenValid && $account !== [];
$accountEmail = (string) ($account['email'] ?? '');
$accountRole = trim((string) ($account['account_role'] ?? 'Account'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Set Account Password | MAP &amp; PUMP</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Prata&display=swap" rel="stylesheet">
  <link rel="icon" type="image/png" href="/images/Logo.png">
  <link rel="stylesheet" href="../../css/Login.css" />
</head>
<body>
  <div class="bg-blur bg-blur-1"></div>
  <div class="bg-blur bg-blur-2"></div>
  <main class="main-card">
    <section class="form-container">
      <div class="header">
        <h1 class="title">SET ACCOUNT PASSWORD</h1>
        <?php if ($tokenValid): ?>
          <p class="step-subtitle"><?= htmlspecialchars($accountRole, ENT_QUOTES, 'UTF-8') ?> login: <?= htmlspecialchars($accountEmail, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
      </div>
      <?php if ($error !== ''): ?>
        <p role="alert" style="color:#b42318;margin:0 0 18px"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
      <?php endif; ?>
      <?php if ($tokenValid): ?>
        <form method="POST" action="set_account_password.php" autocomplete="on">
          <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['account_password_setup_csrf'], ENT_QUOTES, 'UTF-8') ?>">
          <div class="input-group">
            <label for="password">Password</label>
            <div class="input-wrapper">
              <input type="password" id="password" name="password" minlength="8" maxlength="15" autocomplete="new-password" required>
            </div>
          </div>
          <div class="input-group">
            <label for="confirm-password">Confirm password</label>
            <div class="input-wrapper">
              <input type="password" id="confirm-password" name="confirm_password" minlength="8" maxlength="15" autocomplete="new-password" required>
            </div>
          </div>
          <div class="login-btn-wrapper">
            <button type="submit" class="submit-btn full-width-btn">Set password</button>
          </div>
        </form>
      <?php else: ?>
        <p>This setup link is invalid, expired, or already used. Request a new link from Map &amp; Pump support.</p>
        <div class="register-prompt"><a href="login.php" class="register-link">Back to Login</a></div>
      <?php endif; ?>
    </section>
    <aside class="branding-container">
      <img src="../../images/Logo.png" alt="MAP &amp; PUMP Logo" class="logo-img" />
      <h2 class="brand-title">MAP &amp; PUMP</h2>
      <p class="brand-subtitle">Account security</p>
    </aside>
  </main>
</body>
</html>