<?php
/**
 * Admin Dashboard - Outing Aktif & Planning
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/csrf.php';

require_admin();

$pdo = getDB();

// 1. Ambil Outing Aktif & Planning
$stmt = $pdo->query("
    SELECT o.*,
           (SELECT COUNT(*) FROM outing_participants p WHERE p.outing_id = o.id AND p.attendance_status = 'ikut') AS total_peserta_ikut,
           (SELECT COUNT(*) FROM outing_participants p WHERE p.outing_id = o.id) AS total_peserta,
           (SELECT COALESCE(SUM(total_price), 0) FROM purchases pu WHERE pu.outing_id = o.id) AS total_pengeluaran
    FROM outings o
    WHERE o.status IN ('planning', 'active')
    ORDER BY FIELD(o.status, 'active', 'planning'), o.start_date ASC
");
$activeOutings = $stmt->fetchAll();

// 1b. Ringkasan iuran per outing (estimasi per member, terkumpul, kekurangan)
$paySummaries = load_payment_summaries($pdo, $activeOutings);

// 2. Ringkasan Global Outing Aktif
$totalBudget = 0;
$totalSpent = 0;
$totalParticipants = 0;
foreach ($activeOutings as $o) {
    $totalBudget += $o['budget'];
    $totalSpent += $o['total_pengeluaran'];
    $totalParticipants += $o['total_peserta_ikut'];
}

// 3. Rekap Total Pengeluaran per Kategori di seluruh Outing Aktif
$catStmt = $pdo->query("
    SELECT c.name, COALESCE(SUM(pu.total_price), 0) AS total_kategori
    FROM categories c
    LEFT JOIN purchases pu ON c.id = pu.category_id AND pu.outing_id IN (
        SELECT id FROM outings WHERE status IN ('planning', 'active')
    )
    GROUP BY c.id, c.name
    HAVING total_kategori > 0
    ORDER BY total_kategori DESC
");
$categoryExpenses = $catStmt->fetchAll();

$pageTitle = 'Dashboard Outing Aktif';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 gap-3">
    <div>
        <h3 class="fw-bold mb-1"><i class="bi bi-grid-fill text-primary me-2"></i>Dashboard Outing Aktif</h3>
        <p class="text-muted mb-0">Kelola kegiatan outing yang berstatus <strong>Planning</strong> dan <strong>Active</strong></p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= base_url('admin/outings.php?action=create'); ?>" class="btn btn-primary">
            <i class="bi bi-plus-circle me-1"></i> Buat Outing Baru
        </a>
        <a href="<?= base_url('admin/history.php'); ?>" class="btn btn-outline-secondary">
            <i class="bi bi-clock-history me-1"></i> Buka History
        </a>
    </div>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card stat-card stat-info h-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small fw-semibold text-uppercase">Outing Aktif/Plan</div>
                    <div class="fs-3 fw-bold mt-1 text-info"><?= count($activeOutings); ?> Kegiatan</div>
                </div>
                <div class="stat-icon bg-info bg-opacity-10 text-info">
                    <i class="bi bi-compass"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small fw-semibold text-uppercase">Total Budget</div>
                    <div class="fs-4 fw-bold mt-1 text-primary"><?= format_rupiah($totalBudget); ?></div>
                </div>
                <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                    <i class="bi bi-wallet2"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card stat-card stat-warning h-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small fw-semibold text-uppercase">Total Pengeluaran</div>
                    <div class="fs-4 fw-bold mt-1 text-warning"><?= format_rupiah($totalSpent); ?></div>
                </div>
                <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                    <i class="bi bi-cart-check"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card stat-card stat-success h-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small fw-semibold text-uppercase">Peserta Ikut</div>
                    <div class="fs-3 fw-bold mt-1 text-success"><?= $totalParticipants; ?> Orang</div>
                </div>
                <div class="stat-icon bg-success bg-opacity-10 text-success">
                    <i class="bi bi-people"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Daftar Outing Aktif -->
<div class="row g-4 mb-4">
    <div class="col-12 col-lg-8">
        <div class="card shadow-sm h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-bold"><i class="bi bi-calendar-event me-2 text-primary"></i>Daftar Outing Berjalan</span>
                <span class="badge bg-light text-dark"><?= count($activeOutings); ?> Data</span>
            </div>
            <div class="card-body p-3">
                <?php if (empty($activeOutings)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-calendar-x text-muted" style="font-size: 3rem;"></i>
                        <p class="mt-3 text-muted">Belum ada agenda outing aktif atau planning.</p>
                        <a href="<?= base_url('admin/outings.php?action=create'); ?>" class="btn btn-sm btn-primary">
                            <i class="bi bi-plus-lg me-1"></i> Buat Outing Pertama
                        </a>
                    </div>
                <?php else: ?>
                    <div class="row g-3">
                        <?php foreach ($activeOutings as $item): ?>
                            <?php
                                $percentBudget = $item['budget'] > 0 ? min(100, round(($item['total_pengeluaran'] / $item['budget']) * 100)) : 0;
                                $progressColor = $percentBudget > 90 ? 'bg-danger' : ($percentBudget > 70 ? 'bg-warning' : 'bg-primary');
                            ?>
                            <div class="col-12">
                                <div class="card card-hover border p-3">
                                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-2">
                                        <div>
                                            <h5 class="fw-bold mb-1">
                                                <a href="<?= base_url('admin/outing_detail.php?id=' . $item['id']); ?>" class="text-decoration-none text-dark">
                                                    <?= htmlspecialchars($item['outing_name']); ?>
                                                </a>
                                            </h5>
                                            <div class="text-muted small">
                                                <i class="bi bi-geo-alt-fill text-danger me-1"></i><?= htmlspecialchars($item['destination']); ?> &bull; 
                                                <i class="bi bi-calendar-week ms-1 me-1"></i><?= format_date_id($item['start_date']); ?> s/d <?= format_date_id($item['end_date']); ?>
                                            </div>
                                        </div>
                                        <div>
                                            <?= status_badge($item['status']); ?>
                                        </div>
                                    </div>

                                    <!-- Progress & Info -->
                                    <div class="row g-2 mt-2 pt-2 border-top small">
                                        <div class="col-6 col-md-3">
                                            <span class="text-muted d-block">Budget:</span>
                                            <strong class="text-primary"><?= format_rupiah($item['budget']); ?></strong>
                                        </div>
                                        <div class="col-6 col-md-3">
                                            <span class="text-muted d-block">Terpakai:</span>
                                            <strong class="text-warning"><?= format_rupiah($item['total_pengeluaran']); ?></strong>
                                        </div>
                                        <div class="col-6 col-md-3">
                                            <span class="text-muted d-block">Sisa:</span>
                                            <strong class="<?= ($item['budget'] - $item['total_pengeluaran']) < 0 ? 'text-danger' : 'text-success'; ?>">
                                                <?= format_rupiah($item['budget'] - $item['total_pengeluaran']); ?>
                                            </strong>
                                        </div>
                                        <div class="col-6 col-md-3">
                                            <span class="text-muted d-block">Peserta Ikut:</span>
                                            <strong><i class="bi bi-people-fill text-secondary me-1"></i><?= $item['total_peserta_ikut']; ?> / <?= $item['total_peserta']; ?> org</strong>
                                        </div>
                                    </div>

                                    <!-- Estimasi Iuran per Member -->
                                    <?php $ps = $paySummaries[$item['id']]; ?>
                                    <div class="row g-2 mt-1 pt-2 border-top small bg-light rounded mx-0 pb-2">
                                        <div class="col-6 col-md-3">
                                            <span class="text-muted d-block">Estimasi / Member:</span>
                                            <strong class="text-primary"><?= format_rupiah($ps['estimate']); ?></strong>
                                            <span class="text-muted" style="font-size:.7rem;"><?= $ps['is_manual'] ? '(manual)' : '(otomatis)'; ?></span>
                                        </div>
                                        <div class="col-6 col-md-3">
                                            <span class="text-muted d-block">Iuran Terkumpul:</span>
                                            <strong class="text-success"><?= format_rupiah($ps['collected']); ?></strong>
                                        </div>
                                        <div class="col-6 col-md-3">
                                            <span class="text-muted d-block">Kekurangan Iuran:</span>
                                            <strong class="<?= $ps['outstanding'] > 0 ? 'text-danger' : 'text-success'; ?>"><?= format_rupiah($ps['outstanding']); ?></strong>
                                        </div>
                                        <div class="col-6 col-md-3">
                                            <span class="text-muted d-block">Lunas / Partial / Belum:</span>
                                            <strong><?= $ps['count_sudah']; ?> / <span class="text-partial"><?= $ps['count_partial']; ?></span> / <?= $ps['count_belum']; ?></strong>
                                            <?php if ($ps['pending_proofs'] > 0): ?>
                                                <a href="<?= base_url('admin/outing_detail.php?id=' . $item['id'] . '#participants'); ?>" class="badge bg-danger text-decoration-none ms-1"><?= $ps['pending_proofs']; ?> bukti baru</a>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Progress Bar -->
                                    <div class="mt-3">
                                        <div class="d-flex justify-content-between text-muted" style="font-size: 0.75rem;">
                                            <span>Penggunaan Anggaran</span>
                                            <span><?= $percentBudget; ?>%</span>
                                        </div>
                                        <div class="progress" style="height: 6px;">
                                            <div class="progress-bar <?= $progressColor; ?>" role="progressbar" style="width: <?= $percentBudget; ?>%" aria-valuenow="<?= $percentBudget; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                        </div>
                                    </div>

                                    <!-- Action Buttons -->
                                    <div class="d-flex justify-content-end gap-2 mt-3 pt-2 border-top">
                                        <a href="<?= base_url('admin/outing_detail.php?id=' . $item['id']); ?>" class="btn btn-sm btn-primary">
                                            <i class="bi bi-box-arrow-in-right me-1"></i> Buka Manajemen Outing
                                        </a>
                                        <a href="<?= base_url('admin/export_pdf.php?id=' . $item['id']); ?>" class="btn btn-sm btn-outline-danger" target="_blank">
                                            <i class="bi bi-file-earmark-pdf me-1"></i> Unduh PDF
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Ringkasan Kategori Pengeluaran Global -->
    <div class="col-12 col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-header fw-bold">
                <i class="bi bi-pie-chart-fill me-2 text-primary"></i>Pengeluaran per Kategori (Aktif)
            </div>
            <div class="card-body p-3">
                <?php if (empty($categoryExpenses)): ?>
                    <p class="text-muted text-center py-4 small">Belum ada transaksi pengeluaran pada outing aktif.</p>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($categoryExpenses as $cat): ?>
                            <?php
                                $catPercent = $totalSpent > 0 ? round(($cat['total_kategori'] / $totalSpent) * 100) : 0;
                            ?>
                            <li class="list-group-item px-0 py-2">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="fw-semibold small"><?= htmlspecialchars($cat['name']); ?></span>
                                    <span class="small fw-bold"><?= format_rupiah($cat['total_kategori']); ?></span>
                                </div>
                                <div class="progress" style="height: 5px;">
                                    <div class="progress-bar bg-info" style="width: <?= $catPercent; ?>%"></div>
                                </div>
                                <div class="text-muted text-end" style="font-size: 0.7rem;"><?= $catPercent; ?>% dari total</div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
