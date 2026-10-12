<?php
/**
 * Halaman Login Sistem Outing
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/config/auth.php';

// Jika sudah login, langsung alihkan
if (is_logged_in()) {
    header('Location: ' . (is_admin() ? base_url('admin/index.php') : base_url('member/index.php')));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        $error = 'Validasi keamanan (CSRF Token) gagal atau sesi Anda kedaluwarsa. Silakan muat ulang halaman.';
    } else {
        $userId = trim($_POST['user_id'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if (empty($userId) || empty($password)) {
            $error = 'User ID dan Password wajib diisi.';
        } else {
            $pdo = getDB();
            $stmt = $pdo->prepare('SELECT * FROM users WHERE user_id = :user_id LIMIT 1');
            $stmt->execute([':user_id' => $userId]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                if ($user['status'] !== 'active') {
                    $error = 'Akun Anda dinonaktifkan oleh Administrator.';
                } else {
                    // Login Sukses
                    session_regenerate_id(true);
                    $_SESSION['user_db_id'] = $user['id'];
                    $_SESSION['user_id']    = $user['user_id'];
                    $_SESSION['nama']       = $user['nama'];
                    $_SESSION['role']       = $user['role'];
                    $_SESSION['status']     = $user['status'];

                    flash_set('success', 'Selamat datang kembali, ' . htmlspecialchars($user['nama']) . '!');
                    if ($user['role'] === 'admin') {
                        header('Location: ' . base_url('admin/index.php'));
                    } else {
                        header('Location: ' . base_url('member/index.php'));
                    }
                    exit;
                }
            } else {
                $error = 'User ID atau Password yang Anda masukkan salah.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Outing Management System</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="<?= base_url('assets/css/style.css'); ?>" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center min-vh-100 py-4">
<div class="container">
    <div class="login-container">
        <div class="card login-card shadow border-0 overflow-hidden">
            <div class="card-header bg-primary text-white text-center py-4">
                <div class="fs-1 mb-2"><i class="bi bi-compass"></i></div>
                <h4 class="fw-bold mb-1">Outing Management</h4>
                <p class="small mb-0 text-white-50">Silakan login menggunakan User ID & Password Anda</p>
            </div>
            <div class="card-body p-4 p-md-5 bg-white">
                <?= flash_render(); ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger d-flex align-items-center alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill fs-5 me-2"></i>
                        <div><?= htmlspecialchars($error); ?></div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <?= csrf_field(); ?>
                    <div class="mb-3">
                        <label for="user_id" class="form-label fw-semibold">User ID</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="bi bi-person-badge"></i></span>
                            <input type="text" class="form-control" id="user_id" name="user_id" placeholder="Masukkan User ID (e.g. admin)" required autofocus value="<?= htmlspecialchars($_POST['user_id'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="mb-4">
                        <label for="password" class="form-label fw-semibold">Password</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="bi bi-lock"></i></span>
                            <input type="password" class="form-control" id="password" name="password" placeholder="Masukkan Password" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">
                        <i class="bi bi-box-arrow-in-right me-1"></i> Masuk Sekarang
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
