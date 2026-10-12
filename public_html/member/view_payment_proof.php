<?php
/**
 * Secure Payment Proof Viewer (Bukti Pembayaran Iuran Member)
 * - Hanya Admin ATAU pemilik bukti (member yang bersangkutan) yang dapat melihat file.
 * - Member lain tidak dapat mengakses bukti / nominal pembayaran orang lain.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';

if (!is_logged_in()) {
    http_response_code(403);
    die('Silakan login terlebih dahulu.');
}

$proofId = (int)($_GET['id'] ?? 0);
$currentUser = current_user();
$pdo = getDB();

$stmt = $pdo->prepare('SELECT id, user_id, file_path FROM payment_proofs WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $proofId]);
$proof = $stmt->fetch();

if (!$proof || (!is_admin() && (int)$proof['user_id'] !== (int)$currentUser['id'])) {
    // Pesan sama untuk "tidak ada" & "bukan milik Anda" agar ID tidak bisa ditebak
    http_response_code(404);
    die('Bukti pembayaran tidak ditemukan.');
}

$relPath = ltrim(str_replace('\\', '/', (string)$proof['file_path']), '/');
$fullPath = realpath(__DIR__ . '/../' . $relPath);
$baseDir = realpath(__DIR__ . '/../uploads/payments');

if (!$fullPath || !$baseDir || strpos($fullPath, $baseDir . DIRECTORY_SEPARATOR) !== 0 || !is_file($fullPath)) {
    http_response_code(404);
    die('File bukti pembayaran tidak ditemukan di server.');
}

$ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
$mimeMap = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
    'pdf'  => 'application/pdf',
];

header('Content-Type: ' . ($mimeMap[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($fullPath));
header('Content-Disposition: inline; filename="bukti_pembayaran_' . (int)$proof['id'] . '.' . $ext . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
readfile($fullPath);
exit;
