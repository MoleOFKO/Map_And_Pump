<?php
require_once __DIR__ . '/session_helper.php';
map_pump_configure_session();
session_start();

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/mailer.php';

function password_reset_json(bool $ok, string $message, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(['ok' => $ok, 'message' => $message]);
    exit;
}

function password_reset_ensure_schema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS password_reset_codes (
            reset_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            code_hash VARCHAR(255) COLLATE utf8mb4_bin NOT NULL,
            expires_at DATETIME NOT NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            used_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (reset_id),
            KEY idx_password_reset_user_created (user_id, created_at),
            KEY idx_password_reset_active (user_id, used_at, expires_at),
            CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id)
                REFERENCES users (user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin"
    );
}

function password_reset_active_code(PDO $pdo, string $email, bool $lock = false): array
{
    $lockClause = $lock ? ' FOR UPDATE' : '';
    $stmt = $pdo->prepare(
        "SELECT pr.reset_id, pr.user_id, pr.code_hash, pr.attempts
         FROM password_reset_codes pr
         INNER JOIN users u ON u.user_id = pr.user_id
         WHERE LOWER(u.email) = LOWER(?)
           AND pr.used_at IS NULL
           AND pr.expires_at > NOW()
           AND pr.attempts < 5
         ORDER BY pr.created_at DESC, pr.reset_id DESC
         LIMIT 1{$lockClause}"
    );
    $stmt->execute([$email]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function password_reset_record_failed_attempt(PDO $pdo, int $resetId): void
{
    $stmt = $pdo->prepare(
        'UPDATE password_reset_codes
         SET used_at = CASE WHEN attempts >= 4 THEN NOW() ELSE used_at END,
             attempts = attempts + 1
         WHERE reset_id = ? AND used_at IS NULL'
    );
    $stmt->execute([$resetId]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    password_reset_json(false, 'Invalid request.', 405);
}

$csrfToken = (string) ($_POST['csrf_token'] ?? '');
if (empty($_SESSION['password_reset_csrf']) || !hash_equals((string) $_SESSION['password_reset_csrf'], $csrfToken)) {
    password_reset_json(false, 'Your session expired. Reload the page and try again.', 403);
}

$action = (string) ($_POST['action'] ?? '');
$email = trim((string) ($_POST['email'] ?? ''));

try {
    password_reset_ensure_schema($pdo);

    if ($action === 'send_code') {
        $genericMessage = 'If an account exists for that email, a reset code will be sent. Check your inbox and spam folder.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            password_reset_json(true, $genericMessage);
        }

        $userStmt = $pdo->prepare('SELECT user_id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1');
        $userStmt->execute([$email]);
        $userId = (int) ($userStmt->fetchColumn() ?: 0);
        if ($userId > 0) {
            $pdo->beginTransaction();
            $lockUser = $pdo->prepare('SELECT user_id FROM users WHERE user_id = ? FOR UPDATE');
            $lockUser->execute([$userId]);
            $recentStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM password_reset_codes
                 WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)'
            );
            $recentStmt->execute([$userId]);
            $hourlyStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM password_reset_codes
                 WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
            );
            $hourlyStmt->execute([$userId]);
            $resetId = 0;
            if ((int) $recentStmt->fetchColumn() === 0 && (int) $hourlyStmt->fetchColumn() < 5) {
                $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
                $invalidate = $pdo->prepare(
                    'UPDATE password_reset_codes SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL'
                );
                $invalidate->execute([$userId]);
                $insert = $pdo->prepare(
                    'INSERT INTO password_reset_codes (user_id, code_hash, expires_at)
                     VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))'
                );
                $insert->execute([$userId, password_hash($code, PASSWORD_DEFAULT)]);
                $resetId = (int) $pdo->lastInsertId();
            }
            $pdo->commit();

            if ($resetId > 0) {
                try {
                    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
                    $html = '<div style="font-family:Arial,sans-serif;line-height:1.5;color:#111827">'
                        . '<h2>Reset your MAP &amp; PUMP password</h2>'
                        . '<p>Use this code to reset the password for the account associated with this email address:</p>'
                        . '<p style="font-size:28px;font-weight:700;letter-spacing:8px">' . $safeCode . '</p>'
                        . '<p>This code expires in 10 minutes and can be used once. Do not share it with anyone.</p>'
                        . '<p>If you did not request a password reset, ignore this email. Your current password has not been changed.</p>'
                        . '</div>';
                    $text = "Your MAP & PUMP password reset code is {$code}. It expires in 10 minutes and can be used once. Do not share it. If you did not request this, ignore this email; your password has not been changed.";
                    send_mail($email, 'Your MAP & PUMP password reset code', $html, $text);
                } catch (Throwable $mailError) {
                    $invalidateFailedCode = $pdo->prepare(
                        'UPDATE password_reset_codes SET used_at = NOW() WHERE reset_id = ? AND used_at IS NULL'
                    );
                    $invalidateFailedCode->execute([$resetId]);
                    error_log('[Password reset] Email delivery failed: ' . $mailError->getMessage());
                }
            }
        }

        password_reset_json(true, $genericMessage);
    }

    if ($action === 'verify_code' || $action === 'reset_password') {
        $code = trim((string) ($_POST['code'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/^\d{4}$/', $code) !== 1) {
            password_reset_json(false, 'The code is invalid or expired.', 422);
        }

        $newPassword = (string) ($_POST['password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
        if ($action === 'reset_password') {
            $passwordLength = mb_strlen($newPassword);
            if ($passwordLength < 8 || $passwordLength > 15) {
                password_reset_json(false, 'Password must be 8 to 15 characters long.', 422);
            }
            if (!hash_equals($newPassword, $confirmPassword)) {
                password_reset_json(false, 'The passwords do not match.', 422);
            }
        }

        $pdo->beginTransaction();
        $activeCode = password_reset_active_code($pdo, $email, true);
        if (!$activeCode) {
            $pdo->rollBack();
            password_reset_json(false, 'The code is invalid, expired, or already used.', 422);
        }

        if (!password_verify($code, (string) $activeCode['code_hash'])) {
            password_reset_record_failed_attempt($pdo, (int) $activeCode['reset_id']);
            $pdo->commit();
            password_reset_json(false, 'The code is invalid, expired, or already used.', 422);
        }

        if ($action === 'verify_code') {
            $pdo->commit();
            password_reset_json(true, 'Code verified. Choose a new password.');
        }

        $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $accountRoleStmt = $pdo->prepare('SELECT business_role_id FROM users WHERE user_id = ? LIMIT 1');
        $accountRoleStmt->execute([(int) $activeCode['user_id']]);
        if ((int) $accountRoleStmt->fetchColumn() === 4) {
            $passwordUpdate = $pdo->prepare(
                'UPDATE branches SET branch_password = ? WHERE manager_user_id = ?'
            );
            $passwordUpdate->execute([$newPasswordHash, (int) $activeCode['user_id']]);
            if ($passwordUpdate->rowCount() !== 1) {
                throw new RuntimeException('The assigned branch could not be found for this manager.');
            }
        } else {
            $passwordUpdate = $pdo->prepare('UPDATE users SET hashed_password = ?, updated_at = NOW() WHERE user_id = ?');
            $passwordUpdate->execute([$newPasswordHash, (int) $activeCode['user_id']]);
        }
        $consumeCodes = $pdo->prepare(
            'UPDATE password_reset_codes SET used_at = NOW()
             WHERE user_id = ? AND used_at IS NULL'
        );
        $consumeCodes->execute([(int) $activeCode['user_id']]);
        $pdo->commit();
        password_reset_json(true, 'Your password has been reset.');
    }

    password_reset_json(false, 'Invalid password recovery action.', 400);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[Password reset] ' . $exception->getMessage());
    password_reset_json(false, 'Password recovery is temporarily unavailable. Please try again later.', 500);
}