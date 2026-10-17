<?php
// Serves a stylesheet from this folder with the configured system color theme applied.
// Only whitelisted files; see config/theme.php.
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/theme.php';

$allowed = ['admin' => __DIR__ . '/admin.css'];
$file = $allowed[$_GET['f'] ?? ''] ?? null;
if (!$file || !is_file($file)) {
    http_response_code(404);
    exit();
}

header('Content-Type: text/css; charset=UTF-8');
header('Cache-Control: public, max-age=3600');
echo themeApply(file_get_contents($file));
