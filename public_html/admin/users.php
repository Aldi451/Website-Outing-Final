<?php
/**
 * Admin - Manajemen User (CRUD, Aktif/Nonaktif, Reset Password)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/csrf.php';

require_admin();

$pdo = getDB();
$currentUser = current_user();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        $error = 'Validasi keamanan CSRF gagal. Silakan muat ulang halaman.';
    } else {
        $action = $_POST['action'] ?? '';

        // 1. TAMBAH USER BARU
        if ($action === 'create_user') {
            $userId = trim($_POST['user_id'] ?? '');
            $nama = trim($_POST['nama'] ?? '');
            $password = trim($_POST['password'] ?? '');
            $role = $_POST['role'] ?? 'member';
            $status = $_POST['status'] ?? 'active';

            if (empty($userId) || empty($nama) || empty($password)) {
                $error = 'User ID, Nama Lengkap, dan Password wajib diisi.';
            } else {
                // Cek apakah user_id sudah ada
                $check = $pdo->prepare('SELECT id FROM users WHERE user_id = :uid LIMIT 1');
                $check->execute([':uid' => $userId]);
                if ($check->fetch()) {
                    $error = 'User ID "' . htmlspecialchars($userId) . '" sudah digunakan. Silakan gunakan User ID lain.';
                } else {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $ins = $pdo->prepare('INSERT INTO users (user_id, nama, password, role, status) VALUES (:uid, :nama, :pwd, :role, :status)');
                    $ins->execute([
                        ':uid'    => $userId,
                        ':nama'   => $nama,
                        ':pwd'    => $hash,
                        ':role'   => $role,
                        ':status' => $status
                    ]);
                    flash_set('success', 'User baru "' . htmlspecialchars($nama) . '" berhasil didaftarkan.');
                    header('Location: ' . base_url('admin/users.php'));
                    exit;
                }
            }
        }

        // 2. EDIT USER
        elseif ($action === 'update_user') {
            $id = (int)($_POST['id'] ?? 0);
            $nama = trim($_POST['nama'] ?? '');
            $role = $_POST['role'] ?? 'member';
            $status = $_POST['status'] ?? 'active';

            if (empty($nama)) {
                $error = 'Nama Lengkap wajib diisi.';
            } else {
                // Jangan izinkan admin menonaktifkan akun sendiri
                if ($id == $currentUser['id'] && ($status !== 'active' || $role !== 'admin')) {
                    $error = 'Anda tidak dapat mengubah role atau menonaktifkan akun Anda sendiri.';
                } else {
                    $up = $pdo->prepare('UPDATE users SET nama = :nama, role = :role, status = :status WHERE id = :id');
                    $up->execute([
                        ':nama'   => $nama,
                        ':role'   => $role,
                        ':status' => $status,
                        ':id'     => $id
                    ]);
                    flash_set('success', 'Data user berhasil diperbarui.');
                    header('Location: ' . base_url('admin/users.php'));
                    exit;
                }
            }
        }

        // 3. RESET PASSWORD
        elseif ($action === 'reset_password') {
            $id = (int)($_POST['id'] ?? 0);
            $newPassword = trim($_POST['new_password'] ?? '');

            if (strlen($newPassword) < 4) {
                $error = 'Password baru minimal terdiri dari 4 karakter.';
            } else {
                $hash = password_hash($newPassword, PASSWORD_BCRYPT);
                $up = $pdo->prepare('UPDATE users SET password = :pwd WHERE id = :id');
                $up->execute([':pwd' => $hash, ':id' => $id]);

                flash_set('success', 'Password user berhasil direset.');
                header('Location: ' . base_url('admin/users.php'));
                exit;
            }
        }

        // 4. HAPUS USER
        elseif ($action === 'delete_user') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id == $currentUser['id']) {
                $error = 'Anda tidak dapat menghapus akun Anda sendiri yang sedang aktif.';
            } else {
                $del = $pdo->prepare('DELETE FROM users WHERE id = :id');
                $del->execute([':id' => $id]);
                flash_set('success', 'User berhasil dihapus.');
                header('Location: ' . base_url('admin/users.php'));
                exit;
            }
        }
    }
}

// Ambil list semua users
$usersStmt = $pdo->query('
    SELECT u.*,
           (SELECT COUNT(*) FROM outing_participants p WHERE p.user_id = u.id) AS total_outing_diikuti
    FROM users u
    ORDER BY u.role ASC, u.nama ASC
');
$users = $usersStmt->fetchAll();

$pageTitle = 'Kelola Pengguna & User ID';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center mb-4 gap-3">
    <div>
        <h3 class="fw-bold mb-1"><i class="bi bi-people-fill text-primary me-2"></i>Kelola User & Kredensial</h3>
        <p class="text-muted mb-0">Manajemen akun login anggota & admin sistem outing</p>
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
            <i class="bi bi-person-plus me-1"></i> Tambah User Baru
        </button>
        <a href="<?= base_url('admin/import.php?type=users'); ?>" class="btn btn-outline-success">
            <i class="bi bi-file-earmark-excel me-1"></i> Import User Excel
        </a>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-bold"><i class="bi bi-person-lines-fill me-2 text-primary"></i>Daftar Pengguna Sistem</span>
        <span class="badge bg-secondary"><?= count($users); ?> Total Pengguna</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 50px;">No</th>
                        <th>User ID (Login)</th>
                        <th>Nama Lengkap</th>
                        <th>Hak Akses (Role)</th>
                        <th>Status</th>
                        <th>Outing Diikuti</th>
                        <th class="text-center" style="width: 170px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $no = 1; foreach ($users as $u): ?>
                        <tr>
                            <td><?= $no++; ?></td>
                            <td><code><?= htmlspecialchars($u['user_id']); ?></code></td>
                            <td class="fw-semibold">
                                <?= htmlspecialchars($u['nama']); ?>
                                <?php if ($u['id'] == $currentUser['id']): ?>
                                    <span class="badge bg-primary ms-1">Akun Anda</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= $u['role'] === 'admin' ? '<span class="badge bg-danger">ADMIN</span>' : '<span class="badge bg-secondary">MEMBER</span>'; ?>
                            </td>
                            <td>
                                <?= $u['status'] === 'active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-danger">Inactive</span>'; ?>
                            </td>
                            <td><span class="badge bg-light text-dark border"><?= $u['total_outing_diikuti']; ?> Kegiatan</span></td>
                            <td class="text-center">
                                <div class="btn-group btn-group-sm">
                                    <!-- Edit User Modal Button -->
                                    <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editUserModal<?= $u['id']; ?>" title="Edit User">
                                        <i class="bi bi-pencil"></i>
                                    </button>

                                    <!-- Reset Password Modal Button -->
                                    <button type="button" class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#resetPassModal<?= $u['id']; ?>" title="Reset Password">
                                        <i class="bi bi-key"></i>
                                    </button>

                                    <!-- Hapus User -->
                                    <?php if ($u['id'] != $currentUser['id']): ?>
                                        <form method="POST" action="" class="d-inline">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="id" value="<?= $u['id']; ?>">
                                            <button type="submit" class="btn btn-outline-danger" data-confirm="Hapus pengguna '<?= htmlspecialchars($u['nama']); ?>'? Seluruh data kepesertaan user ini juga akan terhapus." title="Hapus User">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>

                                <!-- Modal Edit User -->
                                <div class="modal fade text-start" id="editUserModal<?= $u['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Edit User: <?= htmlspecialchars($u['user_id']); ?></h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form method="POST" action="">
                                                <?= csrf_field(); ?>
                                                <input type="hidden" name="action" value="update_user">
                                                <input type="hidden" name="id" value="<?= $u['id']; ?>">
                                                <div class="modal-body">
                                                    <div class="mb-3">
                                                        <label class="form-label fw-semibold">User ID (Tetap)</label>
                                                        <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($u['user_id']); ?>" disabled>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label fw-semibold">Nama Lengkap <span class="text-danger">*</span></label>
                                                        <input type="text" name="nama" class="form-control" value="<?= htmlspecialchars($u['nama']); ?>" required>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label fw-semibold">Role / Hak Akses</label>
                                                        <select name="role" class="form-select" <?= $u['id'] == $currentUser['id'] ? 'disabled' : ''; ?>>
                                                            <option value="member" <?= $u['role'] === 'member' ? 'selected' : ''; ?>>Member (View Only)</option>
                                                            <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : ''; ?>>Admin (Full Access CRUD)</option>
                                                        </select>
                                                        <?php if ($u['id'] == $currentUser['id']): ?>
                                                            <input type="hidden" name="role" value="admin">
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label fw-semibold">Status Akun</label>
                                                        <select name="status" class="form-select" <?= $u['id'] == $currentUser['id'] ? 'disabled' : ''; ?>>
                                                            <option value="active" <?= $u['status'] === 'active' ? 'selected' : ''; ?>>Active (Dapat Login)</option>
                                                            <option value="inactive" <?= $u['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive (Dinonaktifkan)</option>
                                                        </select>
                                                        <?php if ($u['id'] == $currentUser['id']): ?>
                                                            <input type="hidden" name="status" value="active">
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                                                    <button type="submit" class="btn btn-primary btn-sm">Simpan Perubahan</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>

                                <!-- Modal Reset Password -->
                                <div class="modal fade text-start" id="resetPassModal<?= $u['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title"><i class="bi bi-key me-2"></i>Reset Password: <?= htmlspecialchars($u['nama']); ?></h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form method="POST" action="">
                                                <?= csrf_field(); ?>
                                                <input type="hidden" name="action" value="reset_password">
                                                <input type="hidden" name="id" value="<?= $u['id']; ?>">
                                                <div class="modal-body">
                                                    <p class="small text-muted">Masukkan password baru untuk user ID <strong><?= htmlspecialchars($u['user_id']); ?></strong>.</p>
                                                    <div class="mb-3">
                                                        <label class="form-label fw-semibold">Password Baru <span class="text-danger">*</span></label>
                                                        <input type="password" name="new_password" class="form-control" placeholder="Minimal 4 karakter" required minlength="4">
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                                                    <button type="submit" class="btn btn-warning btn-sm">Update Password</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>

                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Tambah User Baru -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Tambah User Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <?= csrf_field(); ?>
                <input type="hidden" name="action" value="create_user">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">User ID (Unik untuk Login) <span class="text-danger">*</span></label>
                        <input type="text" name="user_id" class="form-control" placeholder="Contoh: user05 atau NIK123" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nama Lengkap <span class="text-danger">*</span></label>
                        <input type="text" name="nama" class="form-control" placeholder="Contoh: Andi Wijaya" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Password Awal <span class="text-danger">*</span></label>
                        <input type="password" name="password" class="form-control" placeholder="Masukkan password awal" required minlength="4">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Hak Akses (Role)</label>
                        <select name="role" class="form-select">
                            <option value="member" selected>Member (View Only)</option>
                            <option value="admin">Admin (Full Access CRUD)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Status Akun</label>
                        <select name="status" class="form-select">
                            <option value="active" selected>Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary btn-sm">Simpan User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
