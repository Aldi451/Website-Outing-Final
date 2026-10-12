<?php
/**
 * Admin Dashboard - History Outing (Selesai & Cancelled)
 * Bersifat Read-Only secara default, dengan tombol "Edit History" khusus admin berkonfirmasi.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/csrf.php';

require_admin();

$pdo = getDB();

// Filter parameters
$filterYear = trim($_GET['year'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$filterSearch = trim($_GET['search'] ?? '');

// Ambil list tahun yang ada di database untuk opsi filter dropdown
$yearsStmt = $pdo->query("SELECT DISTINCT YEAR(start_date) AS yr FROM outings WHERE status IN ('selesai', 'cancelled') ORDER BY yr DESC");
$availableYears = $yearsStmt->fetchAll(PDO::FETCH_COLUMN);

// Build SQL Query
$sql = "
    SELECT o.*,
           (SELECT COUNT(*) FROM outing_participants p WHERE p.outing_id = o.id AND p.attendance_status = 'ikut') AS total_peserta_ikut,
           (SELECT COUNT(*) FROM outing_participants p WHERE p.outing_id = o.id) AS total_peserta,
           (SELECT COALESCE(SUM(total_price), 0) FROM purchases pu WHERE pu.outing_id = o.id) AS total_pengeluaran
    FROM outings o
    WHERE o.status IN ('selesai', 'cancelled')
";
$params = [];

if (!empty($filterYear)) {
    $sql .= " AND YEAR(o.start_date) = :year";
    $params[':year'] = $filterYear;
}

if (!empty($filterStatus)) {
    $sql .= " AND o.status = :status";
    $params[':status'] = $filterStatus;
}

if (!empty($filterSearch)) {
    $sql .= " AND (o.outing_name LIKE :search OR o.destination LIKE :search)";
    $params[':search'] = '%' . $filterSearch . '%';
}

$sql .= " ORDER BY o.start_date DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$historyOutings = $stmt->fetchAll();

// Ringkasan iuran per outing (estimasi per member, terkumpul, kekurangan)
$paySummaries = load_payment_summaries($pdo, $historyOutings);

$pageTitle = 'History Outing (Arsip)';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 gap-3">
    <div>
        <h3 class="fw-bold mb-1"><i class="bi bi-clock-history text-secondary me-2"></i>History & Arsip Outing</h3>
        <p class="text-muted mb-0">Daftar kegiatan outing yang berstatus <strong>Selesai</strong> dan <strong>Cancelled</strong> (Arsip Read-Only)</p>
    </div>
    <div>
        <a href="<?= base_url('admin/index.php'); ?>" class="btn btn-outline-primary">
            <i class="bi bi-arrow-left me-1"></i> Kembali ke Outing Aktif
        </a>
    </div>
</div>

<!-- Filter Card -->
<div class="card shadow-sm mb-4">
    <div class="card-body p-3">
        <form method="GET" action="" class="row g-2 align-items-center">
            <div class="col-12 col-md-4">
                <label class="form-label small text-muted mb-1">Cari Nama / Lokasi</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" name="search" class="form-control" placeholder="Ketik kata kunci..." value="<?= htmlspecialchars($filterSearch); ?>">
                </div>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small text-muted mb-1">Tahun Kegiatan</label>
                <select name="year" class="form-select form-select-sm">
                    <option value="">Semua Tahun</option>
                    <?php foreach ($availableYears as $yr): ?>
                        <option value="<?= $yr; ?>" <?= $filterYear == $yr ? 'selected' : ''; ?>><?= $yr; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small text-muted mb-1">Status Arsip</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">Semua Status</option>
                    <option value="selesai" <?= $filterStatus === 'selesai' ? 'selected' : ''; ?>>Selesai</option>
                    <option value="cancelled" <?= $filterStatus === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
            </div>
            <div class="col-12 col-md-2 d-flex align-items-end gap-1 mt-md-4">
                <button type="submit" class="btn btn-primary btn-sm w-100">
                    <i class="bi bi-funnel me-1"></i> Filter
                </button>
                <a href="<?= base_url('admin/history.php'); ?>" class="btn btn-outline-secondary btn-sm" title="Reset Filter">
                    <i class="bi bi-arrow-counterclockwise"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- History Table / Cards -->
<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-bold"><i class="bi bi-archive me-2 text-secondary"></i>Arsip Kegiatan Outing</span>
        <span class="badge bg-secondary"><?= count($historyOutings); ?> Kegiatan Ditemukan</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($historyOutings)): ?>
            <div class="text-center py-5">
                <i class="bi bi-archive text-muted" style="font-size: 3rem;"></i>
                <p class="mt-3 text-muted">Tidak ada data history outing sesuai filter.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 50px;">No</th>
                            <th>Nama Outing & Tujuan</th>
                            <th>Tanggal Pelaksanaan</th>
                            <th>Status</th>
                            <th>Anggaran vs Realisasi</th>
                            <th>Estimasi / Member & Iuran</th>
                            <th>Peserta Hadir</th>
                            <th class="text-center" style="width: 220px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $no = 1; foreach ($historyOutings as $row): ?>
                            <?php
                                $sisa = $row['budget'] - $row['total_pengeluaran'];
                            ?>
                            <tr>
                                <td><?= $no++; ?></td>
                                <td>
                                    <div class="fw-bold"><?= htmlspecialchars($row['outing_name']); ?></div>
                                    <small class="text-muted"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($row['destination']); ?></small>
                                </td>
                                <td>
                                    <div class="small fw-semibold"><?= format_date_id($row['start_date']); ?></div>
                                    <small class="text-muted">s/d <?= format_date_id($row['end_date']); ?></small>
                                </td>
                                <td><?= status_badge($row['status']); ?></td>
                                <td>
                                    <div class="small">Budget: <strong><?= format_rupiah($row['budget']); ?></strong></div>
                                    <div class="small text-muted">Realisasi: <strong class="text-warning"><?= format_rupiah($row['total_pengeluaran']); ?></strong></div>
                                    <div class="small <?= $sisa < 0 ? 'text-danger' : 'text-success'; ?>">
                                        Sisa: <?= format_rupiah($sisa); ?>
                                    </div>
                                </td>
                                <td>
                                    <?php $ps = $paySummaries[$row['id']]; ?>
                                    <div class="small">Estimasi: <strong class="text-primary"><?= format_rupiah($ps['estimate']); ?></strong><span class="text-muted">/org</span></div>
                                    <div class="small text-muted">Terkumpul: <strong class="text-success"><?= format_rupiah($ps['collected']); ?></strong></div>
                                    <?php if ($ps['outstanding'] > 0): ?>
                                        <div class="small text-danger">Kurang: <?= format_rupiah($ps['outstanding']); ?></div>
                                    <?php endif; ?>
                                    <div class="small text-muted"><?= $ps['count_sudah']; ?> lunas &bull; <span class="text-partial"><?= $ps['count_partial']; ?> partial</span> &bull; <?= $ps['count_belum']; ?> belum</div>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border">
                                        <i class="bi bi-people-fill me-1"></i> <?= $row['total_peserta_ikut']; ?> / <?= $row['total_peserta']; ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm" role="group">
                                        <!-- View Detail (Read-Only Mode) -->
                                        <a href="<?= base_url('admin/outing_detail.php?id=' . $row['id'] . '&mode=view'); ?>" class="btn btn-outline-primary" title="Lihat Detail (Read-only)">
                                            <i class="bi bi-eye me-1"></i> Lihat
                                        </a>

                                        <!-- Edit History dengan Konfirmasi Dialog -->
                                        <a href="<?= base_url('admin/outing_detail.php?id=' . $row['id'] . '&mode=edit_history'); ?>" 
                                           class="btn btn-outline-warning" 
                                           data-confirm="PERHATIAN: Outing ini sudah masuk arsip (<?= strtoupper($row['status']); ?>). Apakah Anda yakin ingin membuka mode koreksi data (Edit History)?"
                                           title="Koreksi / Edit History">
                                            <i class="bi bi-pencil-square"></i> Edit
                                        </a>

                                        <!-- Download PDF Report -->
                                        <a href="<?= base_url('admin/export_pdf.php?id=' . $row['id']); ?>" class="btn btn-outline-danger" target="_blank" title="Unduh Laporan PDF">
                                            <i class="bi bi-file-earmark-pdf"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
