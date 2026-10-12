<?php
/**
 * Admin - Manajemen Kategori Pengeluaran Dinamis
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/csrf.php';

require_admin();

$pdo = getDB();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        $error = 'Validasi keamanan CSRF token gagal.';
    } else {
        $action = $_POST['action'] ?? '';

        // 1. TAMBAH KATEGORI
        if ($action === 'create_category') {
            $name = trim($_POST['name'] ?? '');
            $desc = trim($_POST['description'] ?? '');

            if (empty($name)) {
                $error = 'Nama kategori wajib diisi.';
            } else {
                try {
                    $ins = $pdo->prepare('INSERT INTO categories (name, description) VALUES (:name, :desc)');
                    $ins->execute([':name' => $name, ':desc' => $desc]);
                    flash_set('success', 'Kategori baru "' . htmlspecialchars($name) . '" berhasil ditambahkan.');
                    header('Location: ' . base_url('admin/categories.php'));
                    exit;
                } catch (PDOException $e) {
                    $error = 'Kategori dengan nama tersebut sudah ada.';
                }
            }
        }

        // 2. UPDATE KATEGORI
        elseif ($action === 'update_category') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $desc = trim($_POST['description'] ?? '');

            if (empty($name)) {
                $error = 'Nama kategori wajib diisi.';
            } else {
                try {
                    $up = $pdo->prepare('UPDATE categories SET name = :name, description = :desc WHERE id = :id');
                    $up->execute([':name' => $name, ':desc' => $desc, ':id' => $id]);
                    flash_set('success', 'Kategori berhasil diperbarui.');
                    header('Location: ' . base_url('admin/categories.php'));
                    exit;
                } catch (PDOException $e) {
                    $error = 'Nama kategori sudah digunakan oleh data lain.';
                }
            }
        }

        // 3. HAPUS KATEGORI
        elseif ($action === 'delete_category') {
            $id = (int)($_POST['id'] ?? 0);
            
            // Cek apakah ada pengeluaran yang menggunakan kategori ini
            $chk = $pdo->prepare('SELECT COUNT(*) FROM purchases WHERE category_id = :id');
            $chk->execute([':id' => $id]);
            $countUsage = $chk->fetchColumn();

            if ($countUsage > 0) {
                $error = 'Kategori tidak dapat dihapus karena sedang digunakan pada ' . $countUsage . ' transaksi pengeluaran.';
            } else {
                $del = $pdo->prepare('DELETE FROM categories WHERE id = :id');
                $del->execute([':id' => $id]);
                flash_set('success', 'Kategori berhasil dihapus.');
                header('Location: ' . base_url('admin/categories.php'));
                exit;
            }
        }
    }
}

// Ambil semua kategori beserta jumlah pemakaian
$categoriesStmt = $pdo->query('
    SELECT c.*,
           (SELECT COUNT(*) FROM purchases pu WHERE pu.category_id = c.id) AS total_transaksi,
           (SELECT COALESCE(SUM(pu.total_price), 0) FROM purchases pu WHERE pu.category_id = c.id) AS total_nominal
    FROM categories c
    ORDER BY c.name ASC
');
$categories = $categoriesStmt->fetchAll();

$pageTitle = 'Kategori Pengeluaran';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center mb-4 gap-3">
    <div>
        <h3 class="fw-bold mb-1"><i class="bi bi-tags-fill text-primary me-2"></i>Kategori Pengeluaran</h3>
        <p class="text-muted mb-0">Kelola kategori anggaran belanja outing secara dinamis</p>
    </div>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
        <i class="bi bi-plus-circle me-1"></i> Tambah Kategori Baru
    </button>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-bold"><i class="bi bi-list-check me-2 text-primary"></i>Daftar Kategori Dinamis</span>
        <span class="badge bg-secondary"><?= count($categories); ?> Kategori</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 50px;">No</th>
                        <th>Nama Kategori</th>
                        <th>Keterangan / Deskripsi</th>
                        <th class="text-center">Jumlah Transaksi</th>
                        <th class="text-end">Total Biaya Realisasi</th>
                        <th class="text-center" style="width: 120px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $no = 1; foreach ($categories as $c): ?>
                        <tr>
                            <td><?= $no++; ?></td>
                            <td class="fw-bold"><?= htmlspecialchars($c['name']); ?></td>
                            <td class="text-muted small"><?= htmlspecialchars($c['description'] ?? '-'); ?></td>
                            <td class="text-center">
                                <span class="badge bg-light text-dark border"><?= $c['total_transaksi']; ?> transaksi</span>
                            </td>
                            <td class="text-end fw-semibold text-primary"><?= format_rupiah($c['total_nominal']); ?></td>
                            <td class="text-center">
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editCatModal<?= $c['id']; ?>" title="Edit Kategori">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <form method="POST" action="" class="d-inline">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete_category">
                                        <input type="hidden" name="id" value="<?= $c['id']; ?>">
                                        <button type="submit" class="btn btn-outline-danger" data-confirm="Hapus kategori '<?= htmlspecialchars($c['name']); ?>'?" title="Hapus Kategori" <?= $c['total_transaksi'] > 0 ? 'disabled' : ''; ?>>
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </div>

                                <!-- Modal Edit Kategori -->
                                <div class="modal fade text-start" id="editCatModal<?= $c['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Edit Kategori: <?= htmlspecialchars($c['name']); ?></h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form method="POST" action="">
                                                <?= csrf_field(); ?>
                                                <input type="hidden" name="action" value="update_category">
                                                <input type="hidden" name="id" value="<?= $c['id']; ?>">
                                                <div class="modal-body">
                                                    <div class="mb-3">
                                                        <label class="form-label fw-semibold">Nama Kategori <span class="text-danger">*</span></label>
                                                        <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($c['name']); ?>" required>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label fw-semibold">Deskripsi</label>
                                                        <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars($c['description'] ?? ''); ?></textarea>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                                                    <button type="submit" class="btn btn-primary btn-sm">Simpan</button>
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

<!-- Modal Tambah Kategori -->
<div class="modal fade" id="addCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Tambah Kategori Pengeluaran</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <?= csrf_field(); ?>
                <input type="hidden" name="action" value="create_category">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nama Kategori <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" placeholder="Contoh: Dokumentasi & Media" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Deskripsi Singkat</label>
                        <textarea name="description" class="form-control" rows="2" placeholder="Contoh: Sewa drone, fotografer, videografer..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary btn-sm">Simpan Kategori</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
