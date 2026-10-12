<?php
/**
 * Secure Receipt Viewer
 * Memvalidasi hak akses dan mencegah Directory Traversal Attack
 */
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';

require_login();

$file = $_GET['file'] ?? '';

// Sanitasi dan cegah Directory Traversal
$file = str_replace(['..', '\\'], ['', '/'], $file);
$file = ltrim($file, '/');

// Pastikan file berada di dalam folder uploads/outing/
if (strpos($file, 'uploads/outing/') !== 0) {
    http_response_code(403);
    die('Akses file ditolak.');
}

$fullPath = realpath(__DIR__ . '/../' . $file);
$baseUploadDir = realpath(__DIR__ . '/../uploads/outing/');

if (!$fullPath || strpos($fullPath, $baseUploadDir) !== 0 || !file_exists($fullPath)) {
    http_response_code(404);
    die('File bukti pembayaran tidak ditemukan.');
}

$ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
$mimeMap = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'pdf'  => 'application/pdf'
];

$mimeType = $mimeMap[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: private, max-age=86400');
readfile($fullPath);
exit;
