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