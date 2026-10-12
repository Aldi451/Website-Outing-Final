<?php
/**
 * Admin - CRUD Outings (Buat Baru & Edit Outing)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/csrf.php';

require_admin();

$pdo = getDB();
$action = $_GET['action'] ?? 'create';
$id = (int)($_GET['id'] ?? 0);

$outing = [
    'id' => '',
    'outing_name' => '',
    'destination' => '',
    'start_date' => date('Y-m-d'),
    'end_date' => date('Y-m-d', strtotime('+1 day')),
    'budget' => 0,
    'budget_per_person' => 0,
    'status' => 'planning',
    'description' => ''
];

$error = '';

// Load data jika mode Edit
if ($id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM outings WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $fetched = $stmt->fetch();
    if ($fetched) {
        $outing = $fetched;
    } else {
        flash_set('danger', 'Data outing tidak ditemukan.');
        header('Location: ' . base_url('admin/index.php'));
        exit;
    }
}

// Handler POST (Simpan / Update / Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        $error = 'Validasi keamanan CSRF gagal. Silakan coba kembali.';
    } else {
        $postAction = $_POST['form_action'] ?? '';

        if ($postAction === 'delete') {
            $deleteId = (int)($_POST['id'] ?? 0);
            if ($deleteId > 0) {
                // Hapus file receipt yang terkait di disk
                $receiptsStmt = $pdo->prepare('SELECT receipt_file FROM purchases WHERE outing_id = :id AND receipt_file IS NOT NULL');
                $receiptsStmt->execute([':id' => $deleteId]);
                $files = $receiptsStmt->fetchAll(PDO::FETCH_COLUMN);
                foreach ($files as $f) {
                    delete_receipt($f);
                }
                // Hapus bukti pembayaran iuran member (file privat + data)
                delete_payment_proofs($pdo, 'outing_id', $deleteId);

                // Hapus data outing (CASCADE menghapus participants, rundown, purchases)
                $delStmt = $pdo->prepare('DELETE FROM outings WHERE id = :id');
                $delStmt->execute([':id' => $deleteId]);

                flash_set('success', 'Kegiatan outing berhasil dihapus.');
                header('Location: ' . base_url('admin/index.php'));
                exit;
            }
        } elseif ($postAction === 'save') {
            $name = trim($_POST['outing_name'] ?? '');
            $destination = trim($_POST['destination'] ?? '');
            $start_date = trim($_POST['start_date'] ?? '');
            $end_date = trim($_POST['end_date'] ?? '');
            $budget = (float)str_replace(['.', ','], ['', '.'], $_POST['budget'] ?? '0');
            $budgetPerPerson = max(0, parse_money($_POST['budget_per_person'] ?? '0'));
            $status = trim($_POST['status'] ?? 'planning');
            $description = trim($_POST['description'] ?? '');

            if (empty($name) || empty($destination) || empty($start_date) || empty($end_date)) {
                $error = 'Nama Outing, Destinasi, dan Tanggal Pelaksanaan wajib diisi.';
            } elseif ($end_date < $start_date) {
                $error = 'Tanggal selesai tidak boleh lebih awal dari tanggal mulai.';
            } else {
                if ($id > 0) {
                    // Update
                    $upStmt = $pdo->prepare('
                        UPDATE outings 
                        SET outing_name = :name, destination = :dest, start_date = :s_date, end_date = :e_date, 
                            budget = :budget, budget_per_person = :bpp, status = :status, description = :desc
                        WHERE id = :id
                    ');
                    $upStmt->execute([
                        ':name'    => $name,
                        ':dest'    => $destination,
                        ':s_date'  => $start_date,
                        ':e_date'  => $end_date,
                        ':budget'  => $budget,
                        ':bpp'     => $budgetPerPerson,
                        ':status'  => $status,
                        ':desc'    => $description,
                        ':id'      => $id
                    ]);

                    flash_set('success', 'Data outing "' . htmlspecialchars($name) . '" berhasil diperbarui!');
                    header('Location: ' . base_url('admin/outing_detail.php?id=' . $id));
                    exit;
                } else {
                    // Insert Outing Baru (Tidak menimpa/menghapus data lama)
                    $insStmt = $pdo->prepare('
                        INSERT INTO outings (outing_name, destination, start_date, end_date, budget, budget_per_person, status, description)
                        VALUES (:name, :dest, :s_date, :e_date, :budget, :bpp, :status, :desc)
                    ');
                    $insStmt->execute([
                        ':name'    => $name,
                        ':dest'    => $destination,
                        ':s_date'  => $start_date,
                        ':e_date'  => $end_date,
                        ':budget'  => $budget,
                        ':bpp'     => $budgetPerPerson,
                        ':status'  => $status,
                        ':desc'    => $description
                    ]);
                    $newId = $pdo->lastInsertId();

                    flash_set('success', 'Outing baru berhasil dibuat! Anda sekarang dapat menambahkan peserta, rundown, dan pengeluaran.');
                    header('Location: ' . base_url('admin/outing_detail.php?id=' . $newId));
                    exit;
                }
            }
        }
    }
}

$pageTitle = ($id > 0) ? 'Edit Outing: ' . htmlspecialchars($outing['outing_name']) : 'Buat Outing Baru';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-12 col-lg-8">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="fw-bold mb-0">
                    <i class="bi bi-<?= ($id > 0) ? 'pencil-square text-warning' : 'plus-circle text-primary'; ?> me-2"></i>
                    <?= ($id > 0) ? 'Edit Informasi Outing' : 'Tambah Agenda Outing Baru'; ?>
                </h5>
                <a href="<?= ($id > 0) ? base_url('admin/outing_detail.php?id=' . $id) : base_url('admin/index.php'); ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Batal / Kembali
                </a>
            </div>
            <div class="card-body p-4">
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="form_action" value="save">
                    <input type="hidden" name="id" value="<?= $id; ?>">

                    <div class="mb-3">
                        <label for="outing_name" class="form-label fw-semibold">Nama Outing <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="outing_name" name="outing_name" placeholder="Contoh: Company Gathering & Outbound 2026" required value="<?= htmlspecialchars($_POST['outing_name'] ?? $outing['outing_name']); ?>">
                    </div>

                    <div class="mb-3">
                        <label for="destination" class="form-label fw-semibold">Destinasi / Lokasi <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-geo-alt"></i></span>
                            <input type="text" class="form-control" id="destination" name="destination" placeholder="Contoh: Puncak, Bogor / Pantai Anyer / Bandung" required value="<?= htmlspecialchars($_POST['destination'] ?? $outing['destination']); ?>">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-6">
                            <label for="start_date" class="form-label fw-semibold">Tanggal Mulai <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="start_date" name="start_date" required value="<?= htmlspecialchars($_POST['start_date'] ?? $outing['start_date']); ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label for="end_date" class="form-label fw-semibold">Tanggal Selesai <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="end_date" name="end_date" required value="<?= htmlspecialchars($_POST['end_date'] ?? $outing['end_date']); ?>">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-6">
                            <label for="budget" class="form-label fw-semibold">Estimasi Total Budget (Rp)</label>
                            <div class="input-group">
                                <span class="input-group-text">Rp</span>
                                <input type="number" class="form-control" id="budget" name="budget" min="0" step="1000" placeholder="0" value="<?= htmlspecialchars($_POST['budget'] ?? $outing['budget']); ?>">
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <label for="status" class="form-label fw-semibold">Status Outing</label>
                            <select class="form-select" id="status" name="status">
                                <option value="planning" <?= ($outing['status'] === 'planning') ? 'selected' : ''; ?>>Planning (Dalam Perencanaan)</option>
                                <option value="active" <?= ($outing['status'] === 'active') ? 'selected' : ''; ?>>Active (Sedang Berjalan)</option>
                                <option value="selesai" <?= ($outing['status'] === 'selesai') ? 'selected' : ''; ?>>Selesai (Arsip History)</option>
                                <option value="cancelled" <?= ($outing['status'] === 'cancelled') ? 'selected' : ''; ?>>Cancelled (Dibatalkan)</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="budget_per_person" class="form-label fw-semibold">Estimasi Iuran per Member (Rp) <span class="text-muted fw-normal small">(opsional)</span></label>
                        <div class="input-group">
                            <span class="input-group-text">Rp</span>
                            <input type="number" class="form-control" id="budget_per_person" name="budget_per_person" min="0" step="1000" placeholder="0 = otomatis" value="<?= htmlspecialchars($_POST['budget_per_person'] ?? (int)($outing['budget_per_person'] ?? 0)); ?>">
                        </div>
                        <div class="form-text">Kosongkan / isi 0 agar dihitung otomatis: <strong>Total Budget ÷ jumlah peserta berstatus Ikut</strong> (dibulatkan ke atas per Rp 1.000). Nilai ini menjadi acuan status <em>Partial</em> / <em>Sudah Bayar</em>.</div>
                    </div>

                    <div class="mb-4">
                        <label for="description" class="form-label fw-semibold">Keterangan / Deskripsi Kegiatan</label>
                        <textarea class="form-control" id="description" name="description" rows="3" placeholder="Tuliskan catatan tema acara, dress code, atau informasi umum lainnya..."><?= htmlspecialchars($_POST['description'] ?? $outing['description']); ?></textarea>
                    </div>

                    <div class="d-flex justify-content-between align-items-center pt-3 border-top">
                        <?php if ($id > 0): ?>
                            <button type="submit" form="deleteForm" class="btn btn-outline-danger" data-confirm="PERINGATAN: Menghapus outing ini akan menghapus seluruh data peserta, rundown, dan pengeluaran terkait! Apakah Anda yakin?">
                                <i class="bi bi-trash me-1"></i> Hapus Outing
                            </button>
                        <?php else: ?>
                            <div></div>
                        <?php endif; ?>

                        <div class="d-flex gap-2">
                            <a href="<?= ($id > 0) ? base_url('admin/outing_detail.php?id=' . $id) : base_url('admin/index.php'); ?>" class="btn btn-secondary">
                                Batal
                            </a>
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="bi bi-check-circle me-1"></i> <?= ($id > 0) ? 'Simpan Perubahan' : 'Buat Outing'; ?>
                            </button>
                        </div>
                    </div>
                </form>

                <?php if ($id > 0): ?>
                    <!-- Form Terpisah Khusus Penghapusan Outing -->
                    <form id="deleteForm" method="POST" action="" style="display: none;">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="form_action" value="delete">
                        <input type="hidden" name="id" value="<?= $id; ?>">
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
