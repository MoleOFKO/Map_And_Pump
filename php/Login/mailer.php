<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
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
        mail_config_value('RESEND_FROM_EMAIL', mail_config_value('SMTP_FROM_EMAIL', mail_config_value('SMTP_USERNAME')))
    );
}

function mail_from_name(): string
{
    return mail_config_value('MAIL_FROM_NAME', mail_config_value('RESEND_FROM_NAME', mail_config_value('SMTP_FROM_NAME', 'Map & Pump')));
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

function mail_resend_from_address(): string
{
    return mail_config_value('RESEND_FROM_EMAIL', mail_config_value('MAIL_FROM_EMAIL', mail_config_value('SMTP_FROM_EMAIL', mail_config_value('SMTP_USERNAME'))));
}

function mail_resend_formatted_from(): string
{
    $fromEmail = mail_resend_from_address();
    $fromName = trim(mail_config_value('RESEND_FROM_NAME', mail_from_name()));

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

function resend_send_mail(string $to, string $subject, string $htmlBody, string $textBody = ''): void
{
    $apiKey = mail_config_value('RESEND_API_KEY');
    $from = mail_resend_formatted_from();
    $fromEmail = mail_resend_from_address();

    if ($apiKey === '' || $from === '') {
        throw new RuntimeException('Resend email is not configured. Set RESEND_API_KEY and RESEND_FROM_EMAIL. The sender must be verified in Resend.');
    }

    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Resend sender email is invalid. Set RESEND_FROM_EMAIL to a verified email address or domain sender.');
    }

    $payload = [
        'from' => $from,
        'to' => [$to],
        'subject' => $subject,
        'html' => $htmlBody,
        'text' => $textBody !== '' ? $textBody : mail_text_from_html($htmlBody),
    ];
    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($payloadJson === false) {
        throw new RuntimeException('Could not encode the Resend email payload.');
    }

    $headers = [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
        'Accept: application/json',
        'User-Agent: MapAndPump/1.0',
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $payloadJson,
            CURLOPT_TIMEOUT => 20,
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Resend API request failed: ' . ($curlError ?: 'Unknown cURL error.'));
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $payloadJson,
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);
        $response = file_get_contents('https://api.resend.com/emails', false, $context);
        $statusLine = $http_response_header[0] ?? '';
        $statusCode = preg_match('/\s(\d{3})\s/', $statusLine, $matches) ? (int) $matches[1] : 0;

        if ($response === false) {
            $lastError = error_get_last();
            throw new RuntimeException('Resend API request failed: ' . ($lastError['message'] ?? 'Unknown HTTP error.'));
        }
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        $decoded = json_decode((string) $response, true);
        $message = is_array($decoded) ? (string) ($decoded['message'] ?? $decoded['error'] ?? $response) : (string) $response;
        throw new RuntimeException('Resend API returned HTTP ' . $statusCode . ': ' . $message);
    }
}

function smtp_send_mail_via_phpmailer(string $to, string $subject, string $htmlBody, string $textBody = ''): void
{
    $host = mail_config_value('SMTP_HOST', 'smtp.gmail.com');
    $port = (int) mail_config_value('SMTP_PORT', '587');
    $username = mail_config_value('SMTP_USERNAME');
    $password = mail_config_value('SMTP_PASSWORD');
    $fromEmail = mail_config_value('SMTP_FROM_EMAIL', $username);
    $fromName = mail_config_value('SMTP_FROM_NAME', 'Map & Pump');
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
    $provider = strtolower(mail_config_value('MAIL_PROVIDER', 'auto'));

    if ($provider === 'resend' || ($provider === 'auto' && mail_config_value('RESEND_API_KEY') !== '')) {
        resend_send_mail($to, $subject, $htmlBody, $textBody);
        return;
    }

    if ($provider !== 'auto' && $provider !== 'smtp') {
        throw new RuntimeException('Unsupported mail provider "' . $provider . '". Use "resend", "smtp", or "auto".');
    }

    smtp_send_mail_via_phpmailer($to, $subject, $htmlBody, $textBody);
}

function send_registration_code(string $email, string $code): void
{
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    $html = '<div style="font-family:Arial,sans-serif;line-height:1.5;color:#111827">'
        . '<h2>Verify your Map & Pump account</h2>'
        . '<p>Your verification code is:</p>'
        . '<p style="font-size:28px;font-weight:700;letter-spacing:8px">' . $safeCode . '</p>'
        . '<p>This code expires in 10 minutes.</p>'
        . '</div>';

    smtp_send_mail($email, 'Your Map & Pump verification code', $html, "Your Map & Pump verification code is {$code}. It expires in 10 minutes.");
}

