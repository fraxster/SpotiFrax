<?php
/**
 * Admin: upload a logo image (multipart form field "logo").
 * Stores it under assets/uploads/ and saves the relative path in settings.
 */

declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

require_admin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(['error' => 'method_not_allowed'], 405);
}

if (empty($_FILES['logo']) || ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_response(['error' => 'no_file', 'message' => 'No image was uploaded.'], 400);
}

$file = $_FILES['logo'];

// Size guard: 3 MB.
if ($file['size'] > 3 * 1024 * 1024) {
    json_response(['error' => 'too_large', 'message' => 'Image must be 3 MB or smaller.'], 400);
}

// Validate it's really an image and map to a safe extension.
$allowed = [
    'image/png'     => 'png',
    'image/jpeg'    => 'jpg',
    'image/gif'     => 'gif',
    'image/webp'    => 'webp',
    'image/svg+xml' => 'svg',
];

$mime = null;
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
}
// getimagesize covers raster types even if finfo is unavailable.
if ($mime === null || !isset($allowed[$mime])) {
    $info = @getimagesize($file['tmp_name']);
    if ($info && isset($info['mime']) && isset($allowed[$info['mime']])) {
        $mime = $info['mime'];
    }
}

if ($mime === null || !isset($allowed[$mime])) {
    json_response(['error' => 'bad_type', 'message' => 'Use a PNG, JPG, GIF, WEBP or SVG image.'], 400);
}

$ext = $allowed[$mime];

// Uploads live next to the other web assets so they can be served directly.
$uploadDir = __DIR__ . '/../assets/uploads';
if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
    json_response(['error' => 'no_dir', 'message' => 'Uploads directory is not writable.'], 500);
}
if (!is_writable($uploadDir)) {
    json_response(['error' => 'not_writable', 'message' => 'assets/uploads is not writable (chmod it).'], 500);
}

$name = 'logo-' . bin2hex(random_bytes(6)) . '.' . $ext;
$dest = $uploadDir . '/' . $name;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    json_response(['error' => 'move_failed', 'message' => 'Could not save the uploaded file.'], 500);
}
@chmod($dest, 0644);

$relative = 'assets/uploads/' . $name;
$saved = $settings->save(['logoUrl' => $relative]);

json_response(['ok' => true, 'logoUrl' => $relative, 'settings' => $saved]);
