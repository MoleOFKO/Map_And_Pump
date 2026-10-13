<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function mail_load_local_env(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $path = dirname(__DIR__, 2) . '/.env.local';
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key === '') {
            continue;
        }

        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }

        if (getenv($key) === false && !isset($_ENV[$key]) && !isset($_SERVER[$key])) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

function mail_config_value(string $key, string $default = ''): string
{
    mail_load_local_env();

    $value = getenv($key);

    if ($value === false) {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? '';
    }

    return is_string($value) && trim($value) !== '' ? trim($value) : $default;
}

function mail_from_address(): string
{
    return mail_config_value(
        'MAIL_FROM_EMAIL',
        mail_config_value('SMTP_FROM_EMAIL', mail_config_value('SMTP_USERNAME'))
    );
}

function mail_from_name(): string
{
    return mail_config_value('MAIL_FROM_NAME', mail_config_value('SMTP_FROM_NAME', 'Map&Pump'));
}

function mail_formatted_from(): string
{
    $fromEmail = mail_from_address();
    $fromName = trim(mail_from_name());

    if ($fromEmail === '') {
        return '';
    }

    return $fromName !== '' ? sprintf('%s <%s>', $fromName, $fromEmail) : $fromEmail;
}

function mail_text_from_html(string $htmlBody): string
{
    $text = str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody);
    return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

function smtp_send_mail_via_phpmailer(string $to, string $subject, string $htmlBody, string $textBody = ''): void
{
    $host = mail_config_value('SMTP_HOST', 'smtp.gmail.com');
    $port = (int) mail_config_value('SMTP_PORT', '587');
    $username = mail_config_value('SMTP_USERNAME');
    $password = mail_config_value('SMTP_PASSWORD');
    $fromEmail = mail_config_value('SMTP_FROM_EMAIL', $username);
    $fromName = mail_config_value('SMTP_FROM_NAME', 'Map&Pump');
    $debugLevel = min(max((int) mail_config_value('SMTP_DEBUG', '0'), 0), 3);

    if ($username === '' || $password === '' || $fromEmail === '') {
        throw new RuntimeException('SMTP email is not configured. Set SMTP_USERNAME and SMTP_PASSWORD. SMTP_FROM_EMAIL is optional and defaults to SMTP_USERNAME.');
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->SMTPDebug = $debugLevel;
        $mail->Debugoutput = function (string $str, int $level) {
            error_log(sprintf('[PHPMailer][%d] %s', $level, $str));
        };
        $mail->Host = $host;
        $mail->SMTPAuth = true;
        $mail->Username = $username;
        $mail->Password = str_replace([' ', "\t", "\r", "\n"], '', $password);
        $mail->Timeout = 30;
        $mail->SMTPKeepAlive = false;
        $mail->SMTPAutoTLS = true;

        $verifyPeer = filter_var(mail_config_value('SMTP_VERIFY_PEER', 'true'), FILTER_VALIDATE_BOOLEAN);
        $smtpSslOptions = [
            'verify_peer' => $verifyPeer,
            'verify_peer_name' => $verifyPeer,
            'allow_self_signed' => false,
        ];
        $caFile = mail_config_value('SMTP_CA_FILE', dirname(__DIR__) . '/isrgrootx1.pem');
        if ($verifyPeer && is_file($caFile)) {
            $smtpSslOptions['cafile'] = $caFile;
        }
        $mail->SMTPOptions = [
            'ssl' => $smtpSslOptions,
        ];

        if ($port === 465) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->Port = $port;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($to);

        // Optional Reply-To (project contact). Falls back to From when unset.
        $replyTo = mail_config_value('MAIL_REPLY_TO', mail_config_value('SMTP_REPLY_TO', $fromEmail));
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $mail->addReplyTo($replyTo, $fromName);
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody !== '' ? $textBody : mail_text_from_html($htmlBody);

        $mail->send();
    } catch (Exception $e) {
        error_log('PHPMailer exception: ' . $e->getMessage());
        throw new RuntimeException('Could not send email via PHPMailer: ' . $mail->ErrorInfo, 0, $e);
    }
}

function smtp_send_mail(string $to, string $subject, string $htmlBody, string $textBody = ''): void
{
    if (strtolower(mail_config_value('MAIL_PROVIDER', 'smtp')) === 'resend') {
        resend_send_mail($to, $subject, $htmlBody, $textBody);
        return;
    }

    smtp_send_mail_via_phpmailer($to, $subject, $htmlBody, $textBody);
}

function resend_send_mail(string $to, string $subject, string $htmlBody, string $textBody = ''): void
{
    $apiKey = mail_config_value('RESEND_API_KEY');
    $fromEmail = mail_config_value('RESEND_FROM_EMAIL', mail_from_address());
    $fromName = mail_config_value('RESEND_FROM_NAME', mail_from_name());

    if ($apiKey === '' || $fromEmail === '') {
        throw new RuntimeException('Resend email is not configured. Set RESEND_API_KEY and RESEND_FROM_EMAIL.');
    }

    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is required to send email through Resend.');
    }

    $payload = [
        'from' => $fromName !== '' ? sprintf('%s <%s>', $fromName, $fromEmail) : $fromEmail,
        'to' => [$to],
        'subject' => $subject,
        'html' => $htmlBody,
        'text' => $textBody !== '' ? $textBody : mail_text_from_html($htmlBody),
    ];

    $ch = curl_init('https://api.resend.com/emails');
    if ($ch === false) {
        throw new RuntimeException('Could not initialize the Resend request.');
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Could not connect to Resend: ' . ($curlError ?: 'unknown cURL error'));
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $details = json_decode((string) $response, true);
        $message = is_array($details) ? (string) ($details['message'] ?? '') : '';
        throw new RuntimeException('Resend rejected the email request' . ($message !== '' ? ': ' . $message : ' (HTTP ' . $httpCode . ').'));
    }
}

function send_mail(string $to, string $subject, string $htmlBody, string $textBody = ''): void
{
    smtp_send_mail($to, $subject, $htmlBody, $textBody);
}

function send_registration_code(string $email, string $code): void
{
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    $html = '<div style="font-family:Arial,sans-serif;line-height:1.5;color:#111827">'
        . '<h2>Verify your MAP &amp; PUMP account</h2>'
        . '<p>You requested a verification code for your account in the MAP &amp; PUMP school demonstration project.</p>'
        . '<p>Your verification code is:</p>'
        . '<p style="font-size:28px;font-weight:700;letter-spacing:8px">' . $safeCode . '</p>'
        . '<p>This code expires in 10 minutes and can be used once. Do not share it with anyone.</p>'
        . '<p>If you did not start this registration, ignore this email. Do not reply with passwords, payment details, identity documents, or other personal information.</p>'
        . '<p><strong>Privacy reminder:</strong> your email is used for verification and may receive essential account or security notices. Use fictional information for every other field in this school demo.</p>'
        . '</div>';

    send_mail($email, 'Your MAP & PUMP account verification code', $html, "Your MAP & PUMP school demo account verification code is {$code}. It expires in 10 minutes and can be used once. Do not share it. If you did not request it, ignore this email. Your email is used for verification and may receive essential account or security notices; use fictional information for all other fields.");
}

