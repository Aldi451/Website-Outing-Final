<?php
/**
 * API Endpoint: Rundown (AJAX Operations)
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';

if (!is_logged_in()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Akses ditolak']);
    exit;
}

$pdo = getDB();
$outingId = (int)($_GET['outing_id'] ?? 0);

if ($outingId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM rundown WHERE outing_id = :oid ORDER BY day_number ASC, sort_order ASC');
    $stmt->execute([':oid' => $outingId]);
    $rundowns = $stmt->fetchAll();
    echo json_encode(['success' => true, 'data' => $rundowns]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Outing ID diperlukan']);
exit;
