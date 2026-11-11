<?php
require_once __DIR__ . '/../payment_transfers.php';

function account_password_setup_create_link(mysqli $conn, int $userId, int $expiresHours = 48): string
{
    if ($userId <= 0 || $expiresHours < 1 || $expiresHours > 168) {
        throw new InvalidArgumentException('Password setup link parameters are invalid.');
    }

    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $tokenHash = hash('sha256', $token);
    $invalidate = $conn->prepare(
        'UPDATE account_password_setup_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL'
    );
    if (!$invalidate) {
        throw new RuntimeException('Could not prepare replacement password setup links.');
    }
    $invalidate->bind_param('i', $userId);
    if (!$invalidate->execute()) {
        throw new RuntimeException('Could not invalidate previous password setup links.');
    }
    $invalidate->close();

    $insert = $conn->prepare(
        'INSERT INTO account_password_setup_tokens (user_id, token_hash, expires_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ' . $expiresHours . ' HOUR))'
    );
    if (!$insert) {
        throw new RuntimeException('Could not prepare the password setup link.');
    }
    $insert->bind_param('is', $userId, $tokenHash);
    if (!$insert->execute()) {
        throw new RuntimeException('Could not create the password setup link.');
    }
    $insert->close();

    return payment_transfer_absolute_url('/php/Login/set_account_password.php?token=' . rawurlencode($token));
}

function account_password_setup_ensure_schema_pdo(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS account_password_setup_tokens (
            token_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            token_hash CHAR(64) COLLATE utf8mb4_bin NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (token_id),
            UNIQUE KEY uq_account_password_setup_token_hash (token_hash),
            KEY idx_account_password_setup_user (user_id, expires_at),
            CONSTRAINT fk_account_password_setup_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin"
    );
}

function account_password_setup_create_link_pdo(PDO $pdo, int $userId, int $expiresHours = 48): string
{
    if ($userId <= 0 || $expiresHours < 1 || $expiresHours > 168) {
        throw new InvalidArgumentException('Password setup link parameters are invalid.');
    }

    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $invalidate = $pdo->prepare(
        'UPDATE account_password_setup_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL'
    );
    $invalidate->execute([$userId]);

    $insert = $pdo->prepare(
        'INSERT INTO account_password_setup_tokens (user_id, token_hash, expires_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ' . $expiresHours . ' HOUR))'
    );
    $insert->execute([$userId, hash('sha256', $token)]);

    return payment_transfer_absolute_url('/php/Login/set_account_password.php?token=' . rawurlencode($token));
}

function hq_password_setup_ensure_schema(PDO $pdo): void
{
    $columns = $pdo->prepare(
        "SELECT COLUMN_NAME
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hq_password_setup_tokens'"
    );
    $columns->execute();
    $columnNames = array_fill_keys(array_map('strval', $columns->fetchAll(PDO::FETCH_COLUMN)), true);

    if (isset($columnNames['headquarter_id'])) {
        return;
    }

    // Earlier installations used this table for user password setup tokens.
    // The table name alone is not enough to identify the HQ schema, and using
    // it as-is causes approval to fail on WHERE headquarter_id = ?.
    if (isset($columnNames['user_id'])) {
        account_password_setup_ensure_schema_pdo($pdo);
        $pdo->exec(
            "INSERT IGNORE INTO account_password_setup_tokens
                (user_id, token_hash, expires_at, used_at, created_at)
             SELECT user_id, token_hash, expires_at, used_at, created_at
             FROM hq_password_setup_tokens"
        );
        $pdo->exec('DROP TABLE hq_password_setup_tokens');
    } elseif ($columnNames !== []) {
        throw new RuntimeException('The HQ password setup token table has an unsupported schema.');
    }

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS hq_password_setup_tokens (
            token_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            headquarter_id INT NOT NULL,
            token_hash CHAR(64) COLLATE utf8mb4_bin NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (token_id),
            UNIQUE KEY uq_hq_password_setup_token_hash (token_hash),
            KEY idx_hq_password_setup_hq (headquarter_id, expires_at),
            CONSTRAINT fk_hq_password_setup_hq FOREIGN KEY (headquarter_id) REFERENCES headquarter (headquarter_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin"
        );
    } catch (PDOException $e) {
        $columns->execute();
        $columnNames = array_fill_keys(array_map('strval', $columns->fetchAll(PDO::FETCH_COLUMN)), true);
        if (!isset($columnNames['headquarter_id'])) {
            throw $e;
        }
    }
}

function hq_password_setup_create_link(PDO $pdo, int $headquarterId, int $expiresHours = 48): string
{
    if ($headquarterId <= 0 || $expiresHours < 1 || $expiresHours > 168) {
        throw new InvalidArgumentException('HQ password setup link parameters are invalid.');
    }

    hq_password_setup_ensure_schema($pdo);
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $pdo->prepare(
        'UPDATE hq_password_setup_tokens SET used_at = NOW() WHERE headquarter_id = ? AND used_at IS NULL'
    )->execute([$headquarterId]);

    $insert = $pdo->prepare(
        'INSERT INTO hq_password_setup_tokens (headquarter_id, token_hash, expires_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ' . $expiresHours . ' HOUR))'
    );
    $insert->execute([$headquarterId, hash('sha256', $token)]);

    return payment_transfer_absolute_url('/php/Login/set_account_password.php?token=' . rawurlencode($token));
}

function hq_password_setup_create_link_mysqli(mysqli $conn, int $headquarterId, int $expiresHours = 48): string
{
    if ($headquarterId <= 0 || $expiresHours < 1 || $expiresHours > 168) {
        throw new InvalidArgumentException('HQ password setup link parameters are invalid.');
    }

    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $tokenHash = hash('sha256', $token);
    $invalidate = $conn->prepare(
        'UPDATE hq_password_setup_tokens SET used_at = NOW() WHERE headquarter_id = ? AND used_at IS NULL'
    );
    if (!$invalidate) {
        throw new RuntimeException('Could not prepare replacement HQ password setup links.');
    }
    $invalidate->bind_param('i', $headquarterId);
    if (!$invalidate->execute()) {
        $invalidate->close();
        throw new RuntimeException('Could not invalidate previous HQ password setup links.');
    }
    $invalidate->close();

    $insert = $conn->prepare(
        'INSERT INTO hq_password_setup_tokens (headquarter_id, token_hash, expires_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ' . $expiresHours . ' HOUR))'
    );
    if (!$insert) {
        throw new RuntimeException('Could not prepare the HQ password setup link.');
    }
    $insert->bind_param('is', $headquarterId, $tokenHash);
    if (!$insert->execute()) {
        $insert->close();
        throw new RuntimeException('Could not create the HQ password setup link.');
    }
    $insert->close();

    return payment_transfer_absolute_url('/php/Login/set_account_password.php?token=' . rawurlencode($token));
}
