<?php
/**
 * API Endpoint: Outings (AJAX Operations)
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/csrf.php';

if (!is_admin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Akses ditolak']);
    exit;
}

$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $pdo->prepare('SELECT id, outing_name, destination, start_date, end_date, budget, status FROM outings WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $outing = $stmt->fetch();
        if ($outing) {
            echo json_encode(['success' => true, 'data' => $outing]);
            exit;
        }
    }
}

echo json_encode(['success' => false, 'message' => 'Data tidak ditemukan']);
exit;
