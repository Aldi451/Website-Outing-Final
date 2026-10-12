<?php
/**
 * API Endpoint: Purchases (AJAX Operations)
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';

if (!is_admin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Akses ditolak']);
    exit;
}

$pdo = getDB();
$purchId = (int)($_GET['id'] ?? 0);

if ($purchId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM purchases WHERE id = :id');
    $stmt->execute([':id' => $purchId]);
    $item = $stmt->fetch();
    if ($item) {
        echo json_encode(['success' => true, 'data' => $item]);
        exit;
    }
}

echo json_encode(['success' => false, 'message' => 'Data pengeluaran tidak ditemukan']);
exit;
