<?php
/**
 * Transactional email templates for MAP & PUMP (Admin / Moderator notices).
 * Content is service/account related only — not marketing.
 */
require_once __DIR__ . '/mailer.php';

/**
 * True when the active mail provider has enough configuration to attempt a send.
 */
function mail_is_configured(): bool
{
    $provider = strtolower(mail_config_value('MAIL_PROVIDER', 'smtp'));

    if ($provider === 'resend') {
        return mail_config_value('RESEND_API_KEY') !== ''
            && mail_config_value('RESEND_FROM_EMAIL', mail_from_address()) !== '';
    }

    $username = mail_config_value('SMTP_USERNAME');
    $password = mail_config_value('SMTP_PASSWORD');
    $from = mail_config_value('SMTP_FROM_EMAIL', $username);

    return $username !== '' && $password !== '' && $from !== '';
}

/**
 * Escape plain text for safe HTML embedding.
 */
function mail_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Shared legal / identity footer (HTML + plain text).
 *
 * @return array{html:string,text:string}
 */
function mail_legal_footer(): array
{
    $html = '<hr style="border:none;border-top:1px solid #e5e7eb;margin:24px 0">'
        . '<p style="color:#6b7280;font-size:12px;line-height:1.5;margin:0 0 8px">'
        . 'You received this message because a Map &amp; Pump account or request associated with this email address was updated by an administrator or moderator.'
        . '</p>'
        . '<p style="color:#6b7280;font-size:12px;line-height:1.5;margin:0 0 8px">'
        . 'MAP &amp; PUMP is a school demonstration project for learning and testing. It is not a live commercial fuel service, payment service, or emergency service. Do not rely on it for real-world operations.'
        . '</p>'
        . '<p style="color:#6b7280;font-size:12px;line-height:1.5;margin:0">'
        . 'This is a transactional account notice, not marketing. For questions about this message, use the Contact options on the Map &amp; Pump site or reply only if your mail client allows a reply to the sender address configured for this project. Review the Terms of Service and Privacy Policy on the site for how the project handles data.'
        . '</p>';

    $text = "----\n"
        . "You received this message because a Map & Pump account or request associated with this email address was updated by an administrator or moderator.\n\n"
        . "MAP & PUMP is a school demonstration project for learning and testing. It is not a live commercial fuel service, payment service, or emergency service. Do not rely on it for real-world operations.\n\n"
        . "This is a transactional account notice, not marketing. For questions about this message, use the Contact options on the Map & Pump site. Review the Terms of Service and Privacy Policy on the site for how the project handles data.\n";

    return ['html' => $html, 'text' => $text];
}

/**
 * Human labels for account actions.
 *
 * @return array{title:string,status:string,effect:string}
 */
function mail_action_labels(string $action): array
{
    $action = strtolower(trim($action));

    return match ($action) {
        'remove', 'removed', 'reject', 'rejected' => [
            'title' => $action === 'reject' || $action === 'rejected' ? 'Rejected' : 'Removed',
            'status' => $action === 'reject' || $action === 'rejected' ? 'rejected' : 'removed',
            'effect' => 'Access associated with this account may be blocked or limited under the project rules.',
        ],
        'restore', 'approved', 'approve' => [
            'title' => $action === 'approve' || $action === 'approved' ? 'Approved' : 'Restored',
            'status' => 'approved',
            'effect' => 'The account may be used again under the project Terms of Service, subject to any other checks that still apply.',
        ],
        'suspend', 'suspended' => [
            'title' => 'Suspended',
            'status' => 'suspended',
            'effect' => 'Sign-in and use of this account may be limited while the status remains suspended.',
        ],
        default => [
            'title' => 'Updated',
            'status' => $action !== '' ? $action : 'updated',
            'effect' => 'The account status recorded in Map & Pump was updated.',
        ],
    };
}

/**
 * Build a transactional account / HQ / verification / request notice.
 *
 * Expected $context keys:
 * - kind: account|hq|verification|request (default account)
 * - action: suspend|remove|restore|approve|reject|...
 * - account_label: display name (user or HQ)
 * - reason: optional staff reason
 * - extra_lines: optional list of plain strings
 *
 * @param array<string,mixed> $context
 * @return array{subject:string,html:string,text:string}
 */
function mail_build_account_notice(array $context): array
{
    $kind = strtolower(trim((string) ($context['kind'] ?? 'account')));
    $action = strtolower(trim((string) ($context['action'] ?? 'updated')));
    $label = trim((string) ($context['account_label'] ?? ''));
    $reason = trim((string) ($context['reason'] ?? ''));
    if (mb_strlen($reason) > 2000) {
        $reason = mb_substr($reason, 0, 2000);
    }

    $labels = mail_action_labels($action);
    $footer = mail_legal_footer();

    $scope = match ($kind) {
        'hq' => 'HQ account',
        'verification' => 'account verification',
        'request' => 'request',
        default => 'account',
    };

    $subject = match ($kind) {
        'verification' => 'Map & Pump account verification: ' . $labels['title'],
        'request' => 'Map & Pump request update: ' . $labels['title'],
        'hq' => 'Map & Pump HQ account status update: ' . $labels['title'],
        default => 'Map & Pump account status update: ' . $labels['title'],
    };

    $intro = match ($kind) {
        'hq' => 'A Map & Pump headquarters account associated with this email address was updated by an administrator or moderator.',
        'verification' => 'A Map & Pump account verification decision was recorded for the account associated with this email address.',
        'request' => 'A Map & Pump request associated with this email address was updated by an administrator or moderator.',
        default => 'A Map & Pump account associated with this email address was updated by an administrator or moderator.',
    };

    $targetLine = $label !== ''
        ? 'Target: ' . $label
        : 'Target: the account linked to this email address';

    $statusLine = 'New status: ' . $labels['status'];
    $effectLine = $labels['effect'];
    if (!empty($context['effect']) && is_string($context['effect'])) {
        $effectLine = trim($context['effect']);
    }

    $extraLines = [];
    if (!empty($context['extra_lines']) && is_array($context['extra_lines'])) {
        foreach ($context['extra_lines'] as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $extraLines[] = $line;
            }
        }
    }

    // Plain text body
    $textParts = [
        'Map & Pump — ' . $scope . ' notice',
        '',
        $intro,
        '',
        $targetLine,
        $statusLine,
        $effectLine,
    ];
    if ($reason !== '') {
        $textParts[] = '';
        $textParts[] = 'Reason provided:';
        $textParts[] = $reason;
    }
    foreach ($extraLines as $line) {
        $textParts[] = $line;
    }
    $textParts[] = '';
    $textParts[] = $footer['text'];
    $text = implode("\n", $textParts);

    // HTML body
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;line-height:1.55;color:#111827;max-width:560px">'
        . '<p style="margin:0 0 12px;font-size:14px;color:#374151">Map &amp; Pump — ' . mail_h($scope) . ' notice</p>'
        . '<h2 style="margin:0 0 16px;font-size:20px;color:#111827">Status update: ' . mail_h($labels['title']) . '</h2>'
        . '<p style="margin:0 0 12px">' . mail_h($intro) . '</p>'
        . '<p style="margin:0 0 8px"><strong>' . mail_h($targetLine) . '</strong></p>'
        . '<p style="margin:0 0 8px"><strong>' . mail_h($statusLine) . '</strong></p>'
        . '<p style="margin:0 0 12px">' . mail_h($effectLine) . '</p>';

    if ($reason !== '') {
        $html .= '<p style="margin:16px 0 6px"><strong>Reason provided</strong></p>'
            . '<p style="margin:0 0 12px;padding:12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;white-space:pre-wrap">'
            . mail_h($reason)
            . '</p>';
    }

    foreach ($extraLines as $line) {
        $html .= '<p style="margin:0 0 8px">' . mail_h($line) . '</p>';
    }

    $html .= $footer['html'] . '</div>';

    return [
        'subject' => $subject,
        'html' => $html,
        'text' => $text,
    ];
}

/**
 * Send a transactional notice and return a delivery result array.
 *
 * @param array<string,mixed> $context  Passed to mail_build_account_notice()
 * @return array{sent:bool,status:string,note:string,subject?:string}
 */
function mail_send_account_notice(string $recipient, array $context): array
{
    $recipient = trim($recipient);

    if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        return [
            'sent' => false,
            'status' => 'skipped',
            'note' => 'No valid email address was available; the reason was recorded in the action history.',
        ];
    }

    if (!mail_is_configured()) {
        return [
            'sent' => false,
            'status' => 'not_configured',
            'note' => 'Email delivery is not configured on this server; the reason was recorded in the action history.',
        ];
    }

    $built = mail_build_account_notice($context);

    try {
        smtp_send_mail($recipient, $built['subject'], $built['html'], $built['text']);

        return [
            'sent' => true,
            'status' => 'sent',
            'note' => 'Email sent to account address.',
            'subject' => $built['subject'],
        ];
    } catch (Throwable $e) {
        $note = 'Email delivery failed: ' . $e->getMessage();
        error_log('[mail_templates] ' . $note);

        return [
            'sent' => false,
            'status' => 'failed',
            'note' => substr($note, 0, 255),
            'subject' => $built['subject'],
        ];
    }
}
