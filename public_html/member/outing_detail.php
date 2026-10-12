<?php
/**
 * Member Outing Detail (100% View Only)
 * Fitur: Info Acara, Status Diri, Timeline Rundown (Responsif HP), Peserta, Transparansi Biaya & Bukti Bayar
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';

require_member();

$pdo = getDB();
$currentUser = current_user();
$outingId = (int)($_GET['id'] ?? 0);

if ($outingId <= 0) {
    flash_set('danger', 'ID Outing tidak valid.');
    header('Location: ' . base_url('member/index.php'));
    exit;
}

// 1. Ambil Info Outing
$stmt = $pdo->prepare('SELECT * FROM outings WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $outingId]);
$outing = $stmt->fetch();

if (!$outing) {
    flash_set('danger', 'Kegiatan outing tidak ditemukan.');
    header('Location: ' . base_url('member/index.php'));
    exit;
}

// 2. Ambil Status Diri Sendiri di Outing ini
$myPartStmt = $pdo->prepare('SELECT * FROM outing_participants WHERE outing_id = :oid AND user_id = :uid LIMIT 1');
$myPartStmt->execute([':oid' => $outingId, ':uid' => $currentUser['id']]);
$myParticipant = $myPartStmt->fetch();

// 3. Ambil Rundown Acara
$rundownStmt = $pdo->prepare('
    SELECT * FROM rundown 
    WHERE outing_id = :oid 
    ORDER BY day_number ASC, sort_order ASC, start_time ASC
');
$rundownStmt->execute([':oid' => $outingId]);
$rundowns = $rundownStmt->fetchAll();

$rundownsByDay = [];
foreach ($rundowns as $r) {
    $rundownsByDay[$r['day_number']][] = $r;
}
ksort($rundownsByDay);

// 4. Ambil Daftar Peserta
$partStmt = $pdo->prepare('
    SELECT p.*, u.nama, u.user_id 
    FROM outing_participants p
    JOIN users u ON p.user_id = u.id
    WHERE p.outing_id = :oid
    ORDER BY u.nama ASC
');
$partStmt->execute([':oid' => $outingId]);
$allParticipants = $partStmt->fetchAll();

// 5. Ambil Pengeluaran & Bukti
$purchStmt = $pdo->prepare('
    SELECT pu.*, c.name AS category_name
    FROM purchases pu
    JOIN categories c ON pu.category_id = c.id
    WHERE pu.outing_id = :oid
    ORDER BY pu.purchase_date DESC, pu.id DESC
');
$purchStmt->execute([':oid' => $outingId]);
$purchases = $purchStmt->fetchAll();

$totalSpent = 0;
foreach ($purchases as $pu) {
    $totalSpent += $pu['total_price'];
}

$pageTitle = htmlspecialchars($outing['outing_name']);
require_once __DIR__ . '/../includes/header.php';
?>

<div class="mb-3">
    <a href="<?= base_url('member/index.php'); ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Kembali ke Outing Saya
    </a>
</div>

<!-- Header Card Outing -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body p-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
            <div>
                <div class="d-flex align-items-center gap-2 mb-2">
                    <h3 class="fw-bold mb-0"><?= htmlspecialchars($outing['outing_name']); ?></h3>
                    <?= status_badge($outing['status']); ?>
                </div>
                <div class="text-muted">
                    <i class="bi bi-geo-alt-fill text-danger me-1"></i><?= htmlspecialchars($outing['destination']); ?> &bull; 
                    <i class="bi bi-calendar3 ms-1 me-1"></i><?= format_date_id($outing['start_date'], true); ?> s/d <?= format_date_id($outing['end_date'], true); ?>
                </div>
                <?php if (!empty($outing['description'])): ?>
                    <p class="text-secondary small mt-2 mb-0"><i class="bi bi-info-circle me-1"></i><?= nl2br(htmlspecialchars($outing['description'])); ?></p>
                <?php endif; ?>
            </div>

            <?php if ($myParticipant): ?>
                <div class="p-3 bg-light rounded border text-md-end w-100 w-md-auto">
                    <div class="small text-muted mb-1">Status Keikutsertaan Anda:</div>
                    <div class="d-flex gap-2 justify-content-md-end">
                        <?= attendance_badge($myParticipant['attendance_status']); ?>
                        <?= payment_badge($myParticipant['payment_status']); ?>
                    </div>
                    <?php if (!empty($myParticipant['notes'])): ?>
                        <div class="small text-muted mt-1"><i class="bi bi-chat-left-text me-1"></i><?= htmlspecialchars($myParticipant['notes']); ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Tabs Navigation -->
<ul class="nav nav-pills mb-4 gap-2" id="memberOutingTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="m-rundown-tab" data-bs-toggle="tab" data-bs-target="#m-rundown" type="button" role="tab">
            <i class="bi bi-clock-history me-1"></i> Jadwal Rundown (<?= count($rundowns); ?>)
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="m-participants-tab" data-bs-toggle="tab" data-bs-target="#m-participants" type="button" role="tab">
            <i class="bi bi-people me-1"></i> Teman Peserta (<?= count($allParticipants); ?>)
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="m-purchases-tab" data-bs-toggle="tab" data-bs-target="#m-purchases" type="button" role="tab">
            <i class="bi bi-receipt me-1"></i> Transparansi Biaya & Bukti (<?= count($purchases); ?>)
        </button>
    </li>
</ul>

<!-- Tabs Content -->
<div class="tab-content" id="memberOutingTabsContent">

    <!-- ==================== TAB 1: RUNDOWN TIMELINE (RESPONSIF HP) ==================== -->
    <div class="tab-pane fade show active" id="m-rundown" role="tabpanel">
        <?php if (empty($rundownsByDay)): ?>
            <div class="card shadow-sm text-center py-5">
                <i class="bi bi-calendar2-range text-muted" style="font-size: 3rem;"></i>
                <p class="mt-3 text-muted">Jadwal rundown acara belum diumumkan oleh panitia.</p>
            </div>
        <?php else: ?>
            <div class="row">
                <div class="col-12 col-lg-10 col-xl-8">
                    <?php foreach ($rundownsByDay as $dayNum => $dayItems): ?>
                        <div class="card shadow-sm border-0 mb-4">
                            <div class="card-header bg-primary text-white d-flex align-items-center justify-content-between py-3">
                                <span class="fw-bold fs-6"><i class="bi bi-flag-fill me-2"></i>HARI KE-<?= $dayNum; ?></span>
                                <span class="badge bg-white text-primary"><?= count($dayItems); ?> Sesi</span>
                            </div>
                            <div class="card-body p-3 p-md-4">
                                <div class="timeline">
                                    <?php foreach ($dayItems as $item): ?>
                                        <div class="timeline-item">
                                            <div class="timeline-badge"></div>
                                            <div class="timeline-card">
                                                <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-1 mb-1">
                                                    <span class="timeline-time">
                                                        <i class="bi bi-alarm me-1"></i>
                                                        <?= date('H:i', strtotime($item['start_time'])); ?>
                                                        <?= !empty($item['end_time']) ? ' - ' . date('H:i', strtotime($item['end_time'])) : ''; ?> WIB
                                                    </span>
                                                    <?php if (!empty($item['location'])): ?>
                                                        <span class="timeline-location">
                                                            <i class="bi bi-geo-alt-fill text-danger me-1"></i><?= htmlspecialchars($item['location']); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <h6 class="fw-bold mb-1 text-dark"><?= htmlspecialchars($item['activity']); ?></h6>
                                                <?php if (!empty($item['description'])): ?>
                                                    <p class="small text-muted mb-0 mt-2 bg-light p-2 rounded">
                                                        <?= nl2br(htmlspecialchars($item['description'])); ?>
                                                    </p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- ==================== TAB 2: PESERTA ==================== -->
    <div class="tab-pane fade" id="m-participants" role="tabpanel">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3 fw-bold">
                <i class="bi bi-people-fill me-2 text-primary"></i>Daftar Peserta Terdaftar
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 50px;">No</th>
                                <th>Nama Peserta</th>
                                <th>Status Kehadiran</th>
                                <th>Catatan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $no = 1; foreach ($allParticipants as $pt): ?>
                                <tr class="<?= $pt['user_id'] === $currentUser['user_id'] ? 'table-primary bg-opacity-25' : ''; ?>">
                                    <td><?= $no++; ?></td>
                                    <td class="fw-semibold">
                                        <?= htmlspecialchars($pt['nama']); ?>
                                        <?php if ($pt['user_id'] === $currentUser['user_id']): ?>
                                            <span class="badge bg-primary ms-1">Anda</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= attendance_badge($pt['attendance_status']); ?></td>
                                    <td class="small text-muted"><?= htmlspecialchars($pt['notes'] ?? '-'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ==================== TAB 3: TRANSPARANSI BIAYA ==================== -->
    <div class="tab-pane fade" id="m-purchases" role="tabpanel">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <span class="fw-bold"><i class="bi bi-receipt-cutoff me-2 text-warning"></i>Transparansi Pengeluaran & Kwitansi</span>
                <span class="badge bg-primary fs-6">Total: <?= format_rupiah($totalSpent); ?></span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($purchases)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-cash-stack fs-1"></i>
                        <p class="mt-2">Belum ada catatan pengeluaran yang diunggah panitia.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 50px;">No</th>
                                    <th>Tanggal</th>
                                    <th>Kategori</th>
                                    <th>Item Belanja</th>
                                    <th class="text-center">Qty</th>
                                    <th class="text-end">Harga Satuan</th>
                                    <th class="text-end">Total Biaya</th>
                                    <th class="text-center" style="width: 120px;">Bukti Bayar</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no = 1; foreach ($purchases as $pu): ?>
                                    <?php
                                        $ext = !empty($pu['receipt_file']) ? strtolower(pathinfo($pu['receipt_file'], PATHINFO_EXTENSION)) : '';
                                    ?>
                                    <tr>
                                        <td><?= $no++; ?></td>
                                        <td class="small"><?= format_date_id($pu['purchase_date']); ?></td>
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($pu['category_name']); ?></span></td>
                                        <td>
                                            <div class="fw-semibold"><?= htmlspecialchars($pu['item_name']); ?></div>
                                            <?php if (!empty($pu['notes'])): ?>
                                                <small class="text-muted"><?= htmlspecialchars($pu['notes']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center"><?= $pu['qty']; ?></td>
                                        <td class="text-end"><?= format_rupiah($pu['unit_price']); ?></td>
                                        <td class="text-end fw-bold text-primary"><?= format_rupiah($pu['total_price']); ?></td>
                                        <td class="text-center">
                                            <?php if (!empty($pu['receipt_file'])): ?>
                                                <button type="button" class="btn btn-outline-info btn-sm" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#receiptPreviewModal" 
                                                        data-file-url="<?= base_url($pu['receipt_file']); ?>" 
                                                        data-file-type="<?= $ext; ?>">
                                                    <i class="bi bi-eye me-1"></i> Lihat Bukti
                                                </button>
                                            <?php else: ?>
                                                <span class="text-muted small">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
