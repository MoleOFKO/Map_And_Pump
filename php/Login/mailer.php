<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

function mail_config_value(string $key, string $default = ''): string
{
    $value = getenv($key);
    return is_string($value) && trim($value) !== '' ? trim($value) : $default;
}

function smtp_send_mail(string $to, string $subject, string $htmlBody, string $textBody = ''): void
{
    $host = mail_config_value('SMTP_HOST', 'smtp.gmail.com');
    $port = (int) mail_config_value('SMTP_PORT', '587');
    $username = mail_config_value('SMTP_USERNAME', 'mapa.and.pump@gmail.com');
    $password = mail_config_value('SMTP_PASSWORD', 'ndvtisdcscvfynum');
    $fromEmail = mail_config_value('SMTP_FROM_EMAIL', 'mapa.and.pump@gmail.com');
    $fromName = mail_config_value('SMTP_FROM_NAME', 'Map & Pump');

    if ($username === '' || $password === '' || $fromEmail === '') {
        throw new RuntimeException('Email is not configured. Set SMTP_USERNAME, SMTP_PASSWORD, and SMTP_FROM_EMAIL.');
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->SMTPAuth = true;
        $mail->Username = $username;
        $mail->Password = str_replace([' ', '\t', '\r', '\n'], '', $password);
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $port;
        $mail->SMTPAutoTLS = true;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody !== '' ? $textBody : strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));

        $mail->send();
    } catch (Exception $e) {
        throw new RuntimeException('Could not send email via PHPMailer: ' . $mail->ErrorInfo, 0, $e);
    }
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
