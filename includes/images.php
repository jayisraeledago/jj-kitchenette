<?php

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
    return '/' . appImagePath($path);
}
