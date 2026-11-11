<?php

function map_pump_parse_license_expiry_date(string $value): ?string
{
    $value = trim($value);
    $formats = [['!Y-m-d', 'Y-m-d']];

    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $value, $parts)) {
        $monthOrDay = (int) $parts[1];
        // Prefer MM/DD/YYYY when both slash-separated interpretations are valid.
        $slashFormats = $monthOrDay > 12
            ? [['!d/m/Y', 'j/n/Y'], ['!d/m/Y', 'd/m/Y'], ['!m/d/Y', 'n/j/Y'], ['!m/d/Y', 'm/d/Y']]
            : [['!m/d/Y', 'n/j/Y'], ['!m/d/Y', 'm/d/Y'], ['!d/m/Y', 'j/n/Y'], ['!d/m/Y', 'd/m/Y']];
        $formats = array_merge($formats, $slashFormats);
    }

    foreach ($formats as [$parseFormat, $outputFormat]) {
        $date = DateTimeImmutable::createFromFormat($parseFormat, $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format($outputFormat) === $value) {
            return $date->format('Y-m-d');
        }
    }

    return null;
}
