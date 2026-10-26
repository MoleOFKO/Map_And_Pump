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
        . 'You received this message because a Map &amp; Pump account, invitation, request, or security event is associated with this email address.'
        . '</p>'
        . '<p style="color:#6b7280;font-size:12px;line-height:1.5;margin:0 0 8px">'
        . 'MAP &amp; PUMP is a school demonstration project, not a bank, payment processor, live commercial fuel service, or emergency service. Payments occur outside the website. Payment records reflect user declarations and recipient confirmations only; they are not independent bank verification.'
        . '</p>'
        . '<p style="color:#6b7280;font-size:12px;line-height:1.5;margin:0">'
        . 'This is an account, invitation, request, or security service notice, not promotional email. For questions, contact map.and.pump@gmail.com. Review the Terms of Service and Privacy Policy on the Map &amp; Pump site for information about how the project handles data.'
        . '</p>';

    $text = "----\n"
        . "You received this message because a Map & Pump account, invitation, request, or security event is associated with this email address.\n\n"
        . "MAP & PUMP is a school demonstration project, not a bank, payment processor, live commercial fuel service, or emergency service. Payments occur outside the website. Payment records reflect user declarations and recipient confirmations only; they are not independent bank verification.\n\n"
        . "This is an account, invitation, request, or security service notice, not promotional email. For questions, contact map.and.pump@gmail.com. Review the Terms of Service and Privacy Policy on the Map & Pump site for information about how the project handles data.\n";

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
        'created' => [
            'title' => 'Created',
            'status' => 'created',
            'effect' => 'An administrator created a moderator account for this email address. Sign-in details will be provided separately. For security, do not send passwords by email.',
        ],
        'invited' => [
            'title' => 'Invitation',
            'status' => 'invited to review',
            'effect' => 'An administrator invited this account to review a moderator role. Sign in to review the invitation and accept or decline it. Moderator access is not granted unless the invitation is accepted.',
        ],
        'hq_invited' => [
            'title' => 'Invitation',
            'status' => 'invited to review',
            'effect' => 'A Map & Pump headquarters account invited this account to review a role and branch assignment. Sign in to review the invitation and accept or decline it. The role is not granted unless the invitation is accepted.',
        ],
        'email_changed' => [
            'title' => 'Email address changed',
            'status' => 'email address changed',
            'effect' => 'An administrator changed the email address associated with this account. This notice was sent to the address previously on file. If you did not expect this change, contact project support.',
        ],
        'profile_changed' => [
            'title' => 'Profile updated',
            'status' => 'profile updated',
            'effect' => 'An administrator changed profile details associated with this account. If you did not expect this change, contact project support.',
        ],
        'password_changed' => [
            'title' => 'Password changed',
            'status' => 'password changed',
            'effect' => 'An administrator changed this account password. This email does not contain a password. If you did not expect this change, contact project support.',
        ],
        'remove', 'removed' => [
            'title' => 'Removed',
            'status' => 'removed',
            'effect' => 'Sign-in to this account is disabled while its status is removed.',
        ],
        'activate', 'activated' => [
            'title' => 'HQ activated',
            'status' => 'active',
            'effect' => 'Your HQ profile is active. Sign in and switch to your HQ profile to use its management tools.',
        ],
        'reject', 'rejected' => [
            'title' => 'Rejected',
            'status' => 'rejected',
            'effect' => 'The request was not approved. Sign in to review its details.',
        ],
        'restore', 'approved', 'approve' => [
            'title' => $action === 'approve' || $action === 'approved' ? 'Approved' : 'Restored',
            'status' => 'approved',
            'effect' => 'The account status is approved. Sign in to continue using the account.',
        ],
        'suspend', 'suspended' => [
            'title' => 'Suspended',
            'status' => 'suspended',
            'effect' => 'Sign-in to this account is disabled while its status is suspended.',
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
 * - kind: account|hq|verification|request|moderator|moderator_invitation|hq_invitation|security (default account)
 * - action: suspend|remove|restore|approve|reject|created|invited|hq_invited|email_changed|profile_changed|password_changed
 * - account_label: accepted for compatibility but omitted to minimize personal data in email
 * - reason: retained in action history but intentionally omitted from email
 * - reference_code: optional request reference code
 *
 * @param array<string,mixed> $context
 * @return array{subject:string,html:string,text:string}
 */
function mail_build_account_notice(array $context): array
{
    $kind = strtolower(trim((string) ($context['kind'] ?? 'account')));
    $action = strtolower(trim((string) ($context['action'] ?? 'updated')));
    $labels = mail_action_labels($action);
    $footer = mail_legal_footer();

    if ($kind === 'payment') {
        $paymentStatus = match ($action) {
            'pending', 'pending_payment' => 'Payment request created; awaiting payment',
            'payer_reported_paid' => 'Payer reported payment; awaiting recipient confirmation',
            'confirmed', 'received' => 'Recipient recorded that the external payment was received',
            'rejected' => 'Recipient recorded that the external payment was not received',
            'cancelled' => 'Payer cancelled the pending payment notice',
            default => 'External payment status updated',
        };
        $referenceCode = trim((string) ($context['reference_code'] ?? ''));
        $amount = number_format((float) ($context['amount_mmk'] ?? 0), 2) . ' MMK';
        $description = trim((string) ($context['description'] ?? 'External payment'));
        $recipient = trim((string) ($context['recipient_label'] ?? 'the designated recipient'));
        $subject = 'Map & Pump external payment status: ' . match ($action) {
            'pending', 'pending_payment' => 'Awaiting payment',
            'payer_reported_paid' => 'Awaiting recipient confirmation',
            'confirmed', 'received' => 'Recipient recorded receipt',
            'rejected' => 'Not confirmed',
            'cancelled' => 'Cancelled',
            default => 'Updated',
        };
        $text = "Map & Pump — external payment status\n\n{$paymentStatus}.\n\n"
            . "Description: {$description}\nAmount: {$amount}\nIntended recipient: {$recipient}\n"
            . ($referenceCode !== '' ? "Reference: {$referenceCode}\n" : '')
            . (!empty($context['payment_url']) ? "Secure payment status link: " . (string) $context['payment_url'] . "\n" : '')
            . "\nThis record does not move funds or verify a transfer with a bank or wallet provider. Review the status in the secure link provided, or in Payment status after signing in.\n\n"
            . $footer['text'];
        $html = '<div style="font-family:Arial,Helvetica,sans-serif;line-height:1.55;color:#111827;max-width:560px">'
            . '<p style="margin:0 0 12px;font-size:14px;color:#374151">Map &amp; Pump — external payment status</p>'
            . '<h2 style="margin:0 0 16px;font-size:20px;color:#111827">' . mail_h($paymentStatus) . '</h2>'
            . '<p><strong>Description:</strong> ' . mail_h($description) . '<br><strong>Amount:</strong> ' . mail_h($amount) . '<br><strong>Intended recipient:</strong> ' . mail_h($recipient) . '</p>'
            . ($referenceCode !== '' ? '<p><strong>Reference:</strong> ' . mail_h($referenceCode) . '</p>' : '')
            . (!empty($context['payment_url']) ? '<p><a href="' . mail_h((string) $context['payment_url']) . '" style="display:inline-block;padding:10px 16px;background:#0b6bcb;color:#fff;text-decoration:none;border-radius:6px">Open secure payment status</a></p>' : '')
            . '<p>This record does not move funds or verify a transfer with a bank or wallet provider. Review the status using the secure link provided, or in Payment status after signing in.</p>'
            . $footer['html'] . '</div>';
        return ['subject' => $subject, 'html' => $html, 'text' => $text];
    }

    if ($kind === 'license_conflict') {
        $licenseKind = strtolower(trim((string) ($context['license_kind'] ?? 'license')));
        $licenseLabel = $licenseKind === 'car' ? 'car license plate' : 'driver license';
        $licenseNumber = trim((string) ($context['license_number'] ?? ''));
        $decision = strtolower(trim((string) ($context['recipient_decision'] ?? 'allowed_all')));
        $reason = trim((string) ($context['reason'] ?? ''));
        $subject = 'Map & Pump duplicate ' . $licenseLabel . ' review';
        $message = match ($decision) {
            'blocked' => 'Your ' . $licenseLabel . ' was blocked by an Admin or Moderator. The matching record was allowed. Reviewer reason: ' . $reason,
            'blocked_all' => 'All matching ' . $licenseKind . ' license records were blocked by an Admin or Moderator. Reviewer reason: ' . $reason,
            'other_blocked' => 'A duplicate ' . $licenseLabel . ' was found. Your record was allowed, while the other matching record was blocked after review. Contact Map & Pump support if you need more information.',
            default => 'A duplicate ' . $licenseLabel . ' was found. After review, both matching records were allowed. Contact Map & Pump support if you need more information.',
        };
        $text = "Map & Pump — duplicate license review\n\n{$message}\n"
            . ($licenseNumber !== '' ? "License number: {$licenseNumber}\n" : '')
            . "\n{$footer['text']}";
        $html = '<div style="font-family:Arial,Helvetica,sans-serif;line-height:1.55;color:#111827;max-width:560px">'
            . '<p style="margin:0 0 12px;font-size:14px;color:#374151">Map &amp; Pump — duplicate license review</p>'
            . '<h2 style="margin:0 0 16px;font-size:20px;color:#111827">' . mail_h($subject) . '</h2>'
            . '<p>' . mail_h($message) . '</p>'
            . ($licenseNumber !== '' ? '<p><strong>License number:</strong> ' . mail_h($licenseNumber) . '</p>' : '')
            . $footer['html'] . '</div>';
        return ['subject' => $subject, 'html' => $html, 'text' => $text];
    }

    $scope = match ($kind) {
        'hq' => 'HQ account',
        'verification' => 'account verification',
        'request' => 'request',
        'moderator' => 'moderator account',
        'moderator_invitation' => 'moderator invitation',
        'hq_invitation' => 'HQ invitation',
        'security' => 'account security',
        default => 'account',
    };

    $subject = match ($kind) {
        'verification' => 'Map & Pump account verification: ' . $labels['title'],
        'request' => 'Map & Pump request update: ' . $labels['title'],
        'hq' => 'Map & Pump HQ account status update: ' . $labels['title'],
        'moderator_invitation' => 'Map & Pump moderator role invitation',
        'moderator' => 'Map & Pump moderator account update: ' . $labels['title'],
        'hq_invitation' => 'Map & Pump HQ invitation',
        'security' => 'Map & Pump account security notice: ' . $labels['title'],
        default => 'Map & Pump account status update: ' . $labels['title'],
    };

    $intro = match ($kind) {
        'hq' => 'A Map & Pump headquarters account associated with this email address was updated by an administrator or moderator.',
        'verification' => 'A Map & Pump account verification decision was recorded for the account associated with this email address.',
        'request' => 'A Map & Pump request associated with this email address was updated by an administrator or moderator.',
        'moderator' => 'A Map & Pump moderator account associated with this email address was updated by an administrator.',
        'moderator_invitation' => 'A Map & Pump moderator role invitation is waiting for this account.',
        'hq_invitation' => 'A Map & Pump headquarters invitation is waiting for this account.',
        'security' => 'A security-related change was made to the Map & Pump account associated with this email address.',
        default => 'A Map & Pump account associated with this email address was updated by an administrator or moderator.',
    };

    $statusPrefix = match ($kind) {
        'security' => 'Change recorded: ',
        'moderator_invitation', 'hq_invitation' => 'Invitation status: ',
        default => 'New status: ',
    };
    $statusLine = $statusPrefix . $labels['status'];
    $effectLine = $labels['effect'];
    if (!empty($context['effect']) && is_string($context['effect'])) {
        $effectLine = trim($context['effect']);
    }

    $referenceCode = trim((string) ($context['reference_code'] ?? ''));

    // Plain text body
    $textParts = [
        'Map & Pump — ' . $scope . ' notice',
        '',
        $intro,
        '',
        $statusLine,
        $effectLine,
    ];
    if ($referenceCode !== '') {
        $textParts[] = 'Reference code: ' . $referenceCode;
    }
    $textParts[] = '';
    $textParts[] = $footer['text'];
    $text = implode("\n", $textParts);

    // HTML body
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;line-height:1.55;color:#111827;max-width:560px">'
        . '<p style="margin:0 0 12px;font-size:14px;color:#374151">Map &amp; Pump — ' . mail_h($scope) . ' notice</p>'
        . '<h2 style="margin:0 0 16px;font-size:20px;color:#111827">' . mail_h($labels['title']) . '</h2>'
        . '<p style="margin:0 0 12px">' . mail_h($intro) . '</p>'
        . '<p style="margin:0 0 8px"><strong>' . mail_h($statusLine) . '</strong></p>'
        . '<p style="margin:0 0 12px">' . mail_h($effectLine) . '</p>';

    if ($referenceCode !== '') {
        $html .= '<p style="margin:0 0 8px"><strong>Reference code:</strong> ' . mail_h($referenceCode) . '</p>';
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
            'note' => 'No valid email address was available; the action was saved in the application.',
        ];
    }

    if (!mail_is_configured()) {
        return [
            'sent' => false,
            'status' => 'not_configured',
            'note' => 'The action was saved in the application, but email delivery is not configured on this server.',
        ];
    }

    $subject = '';
    try {
        $built = mail_build_account_notice($context);
        $subject = $built['subject'];
        smtp_send_mail($recipient, $built['subject'], $built['html'], $built['text']);

        return [
            'sent' => true,
            'status' => 'sent',
            'note' => 'Email sent to account address.',
            'subject' => $built['subject'],
        ];
    } catch (Throwable $e) {
        error_log('[mail_templates] Email delivery failed: ' . $e->getMessage());

        $result = [
            'sent' => false,
            'status' => 'failed',
            'note' => 'Email delivery failed; the account action was still saved.',
        ];
        if ($subject !== '') {
            $result['subject'] = $subject;
        }

        return $result;
    }
}
