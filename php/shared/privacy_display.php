<?php
/**
 * Presentation-only masking for personal and business contact details.
 * Stored values and server-side review/action code continue to use the originals.
 */

function privacy_mask_email(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '' || !str_contains($value, '@')) {
        return $value;
    }

    [$local, $domain] = explode('@', $value, 2);
    if ($local === '' || $domain === '') {
        return privacy_mask_identifier($value);
    }

    $visibleLength = min(2, strlen($local));
    $maskedLength = max(1, strlen($local) - $visibleLength);
    return substr($local, 0, $visibleLength) . str_repeat('*', $maskedLength) . '@' . $domain;
}

function privacy_mask_phone(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '' || !preg_match_all('/\d/', $value, $matches)) {
        return $value;
    }

    $digitsToKeep = 2;
    $digitCount = count($matches[0]);
    $seenDigits = 0;
    $masked = '';
    foreach (preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
        if (preg_match('/\d/u', $character)) {
            $seenDigits++;
            $masked .= $seenDigits > $digitCount - $digitsToKeep ? $character : '*';
        } else {
            $masked .= '*';
        }
    }

    return $masked;
}

function privacy_mask_identifier(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '' || in_array(strtolower($value), ['not provided', 'not available', 'unknown', '—', '-'], true)) {
        return $value;
    }

    $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: str_split($value);
    $visibleIndexes = [];
    for ($index = count($characters) - 1; $index >= 0 && count($visibleIndexes) < 2; $index--) {
        if (preg_match('/[\p{L}\p{N}]/u', $characters[$index])) {
            $visibleIndexes[$index] = true;
        }
    }

    foreach ($characters as $index => $character) {
        if (!preg_match('/\s/u', $character) && !isset($visibleIndexes[$index])) {
            $characters[$index] = '*';
        }
    }

    return implode('', $characters);
}

function privacy_mask_name(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '' || in_array(strtolower($value), ['not provided', 'not available', 'name unavailable', 'company name unavailable', 'unknown', 'unknown owner', 'unknown sender', 'unknown account', 'company payer', 'owner account unavailable', 'not assigned', 'not linked', 'anonymous', 'n/a', 'station staff'], true)) {
        return $value;
    }

    return preg_replace_callback('/[\p{L}\p{N}][\p{L}\p{N}\'’.-]*/u', static function (array $match): string {
        $word = $match[0];
        $first = function_exists('mb_substr') ? mb_substr($word, 0, 1, 'UTF-8') : substr($word, 0, 1);
        $length = function_exists('mb_strlen') ? mb_strlen($word, 'UTF-8') : strlen($word);
        return $first . str_repeat('*', max(1, $length - 1));
    }, $value) ?? $value;
}

function privacy_mask_location(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '' || in_array(strtolower($value), ['not provided', 'not available', 'location not provided', 'address not set', 'no address on file', 'unknown', '—', '-'], true)) {
        return $value;
    }
    return '********';
}

function privacy_mask_free_text(?string $value): string
{
    $value = (string) $value;
    if ($value === '') {
        return $value;
    }

    $value = preg_replace_callback(
        '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i',
        static fn (array $match): string => privacy_mask_email($match[0]),
        $value
    ) ?? $value;

    $value = preg_replace_callback(
        '/(?<![A-Z0-9])(?:\+?959|09)[\s().-]?\d(?:[\d\s().-]{5,}\d)?(?!\d)/i',
        static fn (array $match): string => privacy_mask_phone($match[0]),
        $value
    ) ?? $value;

    return preg_replace_callback(
        '/\b(email|e-mail|phone|telephone|mobile|contact number|full name|owner name|customer name|address|location|NRC(?: number)?|business license(?: number)?|license number|reference(?: code)?|bank account(?: number)?|payment method)\s*[:=]\s*([^\r\n,;|]+)/iu',
        static function (array $match): string {
            $label = strtolower($match[1]);
            $sensitiveValue = trim($match[2]);
            if (str_contains($label, 'email')) {
                $maskedValue = privacy_mask_email($sensitiveValue);
            } elseif (preg_match('/phone|telephone|mobile|contact number/', $label)) {
                $maskedValue = privacy_mask_phone($sensitiveValue);
            } elseif (str_contains($label, 'name')) {
                $maskedValue = privacy_mask_name($sensitiveValue);
            } elseif (preg_match('/address|location/', $label)) {
                $maskedValue = privacy_mask_location($sensitiveValue);
            } else {
                $maskedValue = privacy_mask_identifier($sensitiveValue);
            }
            return $match[1] . ': ' . $maskedValue;
        },
        $value
    ) ?? $value;
}

/** Recursively mask known sensitive fields before they reach staff UI JSON. */
function privacy_mask_sensitive_data(mixed $value, string $key = ''): mixed
{
    if (is_array($value)) {
        $masked = [];
        foreach ($value as $childKey => $childValue) {
            $masked[$childKey] = privacy_mask_sensitive_data($childValue, (string) $childKey);
        }
        return $masked;
    }

    if (!is_string($value)) {
        return $value;
    }

    $normalizedKey = strtolower(trim($key));
    if ($normalizedKey === 'request_details') {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            $maskedDetails = privacy_mask_sensitive_data($decoded);
            $encodedDetails = json_encode($maskedDetails, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return is_string($encodedDetails) ? $encodedDetails : '********';
        }
        return privacy_mask_free_text($value);
    }

    if (str_contains($normalizedKey, 'email')) {
        return privacy_mask_email($value);
    }
    if (preg_match('/phone|telephone|mobile|contact_number|owner_phone|company_phone/', $normalizedKey)) {
        return privacy_mask_phone($value);
    }
    if (preg_match('/address|location/', $normalizedKey)) {
        return privacy_mask_location($value);
    }
    if (preg_match('/(^|_)(user_name|owner_name|sender_name|sender_entity|submitter_username|payer_name|payer_hq_name|actor_name|manager_name|staff_name|customer_name|full_name|recipient_staff_name|reporter_name|license_name|company_name|hq_company_name|headquarter_name|business_name)(_|$)/', $normalizedKey)) {
        return privacy_mask_name($value);
    }
    if (preg_match('/business_license|license_number|license_id|nrc_number|car_plate_number|chassis_number|payment_reference|reference_code|user_public_id|bank_account|document_name|receipt_name|file_name|payment_method|bank_name/', $normalizedKey)) {
        return privacy_mask_identifier($value);
    }
    if (preg_match('/date_of_birth|birth_date|personal_address/', $normalizedKey)) {
        return privacy_mask_identifier($value);
    }
    if (preg_match('/(^|_)(description|note|review_reason|message)(_|$)/', $normalizedKey)) {
        return privacy_mask_free_text($value);
    }
    if (preg_match('/(^|_)(bio|headquarter_bio)(_|$)/', $normalizedKey)) {
        return privacy_mask_free_text($value);
    }

    return $value;
}
