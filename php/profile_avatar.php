<?php

function map_pump_profile_initial(?string $name, string $fallback = 'U'): string
{
    $name = trim((string) $name);
    $fallback = trim($fallback) !== '' ? strtoupper(substr(trim($fallback), 0, 1)) : 'U';

    if ($name === '') {
        return $fallback;
    }

    if (function_exists('mb_substr')) {
        $initial = mb_substr($name, 0, 1, 'UTF-8');
        return mb_strtoupper($initial, 'UTF-8') ?: $fallback;
    }

    return strtoupper(substr($name, 0, 1)) ?: $fallback;
}

function map_pump_profile_initial_from_session(string $fallback = 'U'): string
{
    return map_pump_profile_initial($_SESSION['user_name'] ?? '', $fallback);
}

function map_pump_profile_avatar_url(?string $imagePath, string $fallback = ''): string
{
    $imagePath = trim((string) $imagePath);
    if ($imagePath === '') {
        return $fallback;
    }

    $imagePath = str_replace('\\', '/', $imagePath);

    if (preg_match('#^(https?:)?//#i', $imagePath) || str_starts_with($imagePath, 'data:')) {
        return $imagePath;
    }

    if (str_starts_with($imagePath, '/')) {
        return $imagePath;
    }

    if (str_starts_with($imagePath, '../') || str_starts_with($imagePath, './')) {
        return $imagePath;
    }

    return '/' . ltrim($imagePath, '/');
}

function map_pump_profile_initial_svg(?string $name, string $fallback = 'U'): string
{
    $initial = htmlspecialchars(map_pump_profile_initial($name, $fallback), ENT_QUOTES, 'UTF-8');
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="160" height="160" viewBox="0 0 160 160">'
        . '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop stop-color="#0d6efd"/><stop offset="1" stop-color="#00f4ff"/></linearGradient></defs>'
        . '<rect width="160" height="160" rx="80" fill="url(#g)"/>'
        . '<text x="50%" y="54%" text-anchor="middle" dominant-baseline="middle" font-family="Arial, sans-serif" font-size="76" font-weight="800" fill="#ffffff">' . $initial . '</text>'
        . '</svg>';

    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}