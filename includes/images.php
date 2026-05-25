<?php

function ensureProductImageStorage($conn)
{
    static $checked = false;

    if ($checked) {
        return;
    }

    $columns = [];
    $result = $conn->query("SHOW COLUMNS FROM product_images");

    if ($result) {
        while ($column = $result->fetch_assoc()) {
            $columns[$column['Field']] = true;
        }
    }

    if (!isset($columns['image_mime'])) {
        $conn->query("ALTER TABLE product_images ADD COLUMN image_mime VARCHAR(100) NULL AFTER image_path");
    }

    if (!isset($columns['image_data'])) {
        $conn->query("ALTER TABLE product_images ADD COLUMN image_data MEDIUMBLOB NULL AFTER image_mime");
    }

    $checked = true;
}

function appImagePath($path)
{
    $path = trim((string) $path);
    $path = ltrim(str_replace('\\', '/', $path), '/');

    if ($path === '') {
        return 'uploads/default.png';
    }

    $fullPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);

    if (!is_file($fullPath)) {
        return 'uploads/default.png';
    }

    return $path;
}

function appImageUrl($path)
{
    $path = trim((string) $path);
    $path = ltrim(str_replace('\\', '/', $path), '/');

    if ($path === '' || $path === 'uploads/default.png') {
        return '/uploads/default.png';
    }

    return '/image.php?path=' . rawurlencode($path);
}
