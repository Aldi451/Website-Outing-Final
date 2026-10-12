<?php
/**
 * API Endpoint: Peserta Outing (AJAX Operations)
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

if ($method === 'POST') {
    if (!validate_csrf()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'CSRF Token tidak valid']);
        exit;
    }

    $action = $_POST['action'] ?? '';

    // Quick toggle status kehadiran
    if ($action === 'toggle_attendance') {
        $id = (int)($_POST['participant_id'] ?? 0);
        $status = $_POST['status'] ?? 'ikut';
        
        if (!in_array($status, ['ikut', 'batal', 'tidak_ikut'])) {
            echo json_encode(['success' => false, 'message' => 'Status tidak valid']);
            exit;
        }

        $stmt = $pdo->prepare('UPDATE outing_participants SET attendance_status = :st WHERE id = :id');
        $stmt->execute([':st' => $status, ':id' => $id]);
        echo json_encode(['success' => true, 'message' => 'Status kehadiran diperbarui']);
        exit;
    }

    // Quick toggle status pembayaran
    if ($action === 'toggle_payment') {
        $id = (int)($_POST['participant_id'] ?? 0);
        $status = $_POST['status'] ?? 'belum';

        if (!in_array($status, payment_statuses(), true)) {
            echo json_encode(['success' => false, 'message' => 'Status pembayaran tidak valid']);
            exit;
        }

        $stmt = $pdo->prepare('UPDATE outing_participants SET payment_status = :st WHERE id = :id');
        $stmt->execute([':st' => $status, ':id' => $id]);
        echo json_encode(['success' => true, 'message' => 'Status pembayaran diperbarui']);
        exit;
    }
}

echo json_encode(['success' => false, 'message' => 'Aksi tidak didukung']);
exit;
