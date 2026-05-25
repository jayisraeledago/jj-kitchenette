<?php
require_once __DIR__ . '/includes/images.php';
require_once __DIR__ . '/db.php';

$path = ltrim(str_replace('\\', '/', trim((string) ($_GET['path'] ?? ''))), '/');

if ($path === '' || str_contains($path, '..')) {
    $path = 'uploads/default.png';
}

$defaultPath = __DIR__ . '/uploads/default.png';

if ($path !== 'uploads/default.png') {
    ensureProductImageStorage($conn);

    $stmt = $conn->prepare("
        SELECT image_mime, image_data
        FROM product_images
        WHERE image_path = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $path);
    $stmt->execute();
    $image = $stmt->get_result()->fetch_assoc();

    if (!empty($image['image_data'])) {
        header('Content-Type: ' . ($image['image_mime'] ?: 'image/jpeg'));
        header('Cache-Control: public, max-age=86400');
        echo $image['image_data'];
        exit;
    }
}

$filePath = __DIR__ . '/' . $path;

if (!is_file($filePath)) {
    $filePath = $defaultPath;
}

$mime = mime_content_type($filePath) ?: 'image/png';
header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=86400');
readfile($filePath);
