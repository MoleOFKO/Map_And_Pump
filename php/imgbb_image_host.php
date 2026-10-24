<?php

function map_pump_imgbb_enabled(): bool
{
    return trim((string) (getenv('IMGBB_API_KEY') ?: '')) !== '';
}

function map_pump_imgbb_upload(string $imageBytes, string $filename, string $mime): string
{
    $apiKey = trim((string) (getenv('IMGBB_API_KEY') ?: ''));
    if ($apiKey === '') {
        throw new RuntimeException('ImgBB image hosting is not configured.');
    }
    if ($imageBytes === '' || strlen($imageBytes) > 5 * 1024 * 1024) {
        throw new RuntimeException('Image must be between 1 byte and 5 MB.');
    }

    $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
    $imageInfo = @getimagesizefromstring($imageBytes);
    if (!in_array($mime, $allowedTypes, true) || ($imageInfo['mime'] ?? '') !== $mime) {
        throw new RuntimeException('Only valid JPG, PNG, and WEBP images can be hosted.');
    }

    $boundary = '----MapPumpUpload' . bin2hex(random_bytes(16));
    $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($filename)) ?: 'profile-image';
    $body = '--' . $boundary . "\r\n"
        . "Content-Disposition: form-data; name=\"image\"; filename=\"{$filename}\"\r\n"
        . 'Content-Type: ' . $mime . "\r\n\r\n"
        . $imageBytes . "\r\n"
        . '--' . $boundary . "--\r\n";

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Accept: application/json\r\n"
                . 'Content-Type: multipart/form-data; boundary=' . $boundary . "\r\n"
                . 'Content-Length: ' . strlen($body) . "\r\n",
            'content' => $body,
            'timeout' => 25,
            'ignore_errors' => true,
        ],
    ]);

    $response = @file_get_contents(
        'https://api.imgbb.com/1/upload?key=' . rawurlencode($apiKey),
        false,
        $context
    );
    $statusLine = (string) ($http_response_header[0] ?? '');
    if ($response === false || !preg_match('/\s2\d\d\s/', $statusLine)) {
        throw new RuntimeException('Image host did not accept the profile image. Please try again.');
    }

    $payload = json_decode($response, true);
    $imageUrl = trim((string) ($payload['data']['url'] ?? ''));
    if (empty($payload['success']) || !filter_var($imageUrl, FILTER_VALIDATE_URL)
        || !str_starts_with(strtolower($imageUrl), 'https://')) {
        throw new RuntimeException('Image host returned an invalid image URL. Please try again.');
    }

    return $imageUrl;
}