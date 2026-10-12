<?php
/**
 * Member Outing Detail (100% View Only)
 * Fitur: Info Acara, Status Diri, Timeline Rundown (Responsif HP), Peserta, Transparansi Biaya & Bukti Bayar
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/csrf.php';

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

// Privasi: member hanya boleh membuka outing yang diikutinya (admin tetap boleh meninjau)
if (!$myParticipant && !is_admin()) {
    flash_set('danger', 'Anda tidak terdaftar sebagai peserta pada kegiatan outing tersebut.');
    header('Location: ' . base_url('member/index.php'));
    exit;
}

// Upload bukti bayar diizinkan selama outing tidak dibatalkan
$canUploadProof = $myParticipant && $outing['status'] !== 'cancelled';
$maxProofs = 20;
$uploadError = '';

// 2b. Handle Upload / Hapus Bukti Pembayaran milik sendiri
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $myParticipant) {
    $redirect = base_url('member/outing_detail.php?id=' . $outingId) . '#m-payment';
    if (!validate_csrf()) {
        $uploadError = 'Validasi keamanan gagal. Silakan muat ulang halaman dan coba lagi.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'upload_proof') {
            if (!$canUploadProof) {
                $uploadError = 'Upload bukti pembayaran tidak tersedia untuk outing yang dibatalkan.';
            } else {
                $cnt = $pdo->prepare('SELECT COUNT(*) FROM payment_proofs WHERE participant_id = :pid');
                $cnt->execute([':pid' => $myParticipant['id']]);
                if ((int)$cnt->fetchColumn() >= $maxProofs) {
                    $uploadError = 'Batas maksimal ' . $maxProofs . ' bukti pembayaran tercapai. Hubungi admin.';
                } else {
                    try {
                        $up = upload_payment_proof($_FILES['proof_file'] ?? [], $outingId);
                        $ins = $pdo->prepare('
                            INSERT INTO payment_proofs (outing_id, participant_id, user_id, file_path, file_size, amount, note, status, uploaded_by)
                            VALUES (:oid, :pid, :uid, :fp, :fs, :amt, :note, "pending", :ub)
                        ');
                        $ins->execute([
                            ':oid'  => $outingId,
                            ':pid'  => $myParticipant['id'],
                            ':uid'  => $currentUser['id'],
                            ':fp'   => $up['path'],
                            ':fs'   => $up['size'],
                            ':amt'  => max(0, parse_money($_POST['amount'] ?? '0')),
                            ':note' => str_limit(trim($_POST['note'] ?? ''), 255),
                            ':ub'   => $currentUser['id'],
                        ]);
                        $info = $up['original_size'] > $up['size']
                            ? ' (dikompres ' . format_filesize($up['original_size']) . ' → ' . format_filesize($up['size']) . ')'
                            : ' (' . format_filesize($up['size']) . ')';
                        flash_set('success', 'Bukti pembayaran berhasil diunggah' . $info . '. Admin akan memverifikasi pembayaran Anda.');
                        header('Location: ' . $redirect);
                        exit;
                    } catch (Exception $e) {
                        $uploadError = 'Gagal upload: ' . $e->getMessage();
                    }
                }
            }
        } elseif ($action === 'delete_proof') {
            // Member hanya boleh menghapus bukti MILIKNYA yang belum diverifikasi
            $proofId = (int)($_POST['proof_id'] ?? 0);
            $chk = $pdo->prepare('SELECT id FROM payment_proofs WHERE id = :id AND user_id = :uid AND participant_id = :pid AND status <> "verified"');
            $chk->execute([':id' => $proofId, ':uid' => $currentUser['id'], ':pid' => $myParticipant['id']]);
            if ($chk->fetchColumn()) {
                delete_payment_proofs($pdo, 'id', $proofId);
                flash_set('success', 'Bukti pembayaran dihapus.');
            } else {
                flash_set('danger', 'Bukti pembayaran tidak dapat dihapus (sudah diverifikasi atau bukan milik Anda).');
            }
            header('Location: ' . $redirect);
            exit;
        }
    }
}

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
// PRIVASI: sengaja TIDAK mengambil kolom amount_paid peserta lain, hanya status.
$partStmt = $pdo->prepare('
    SELECT p.id, p.attendance_status, p.payment_status, p.notes, u.nama, u.user_id
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

// 6. Estimasi iuran per member & data pembayaran milik sendiri
$countIkut = 0;
foreach ($allParticipants as $pt) {
    if ($pt['attendance_status'] === 'ikut') $countIkut++;
}
$estimatePerPerson = outing_estimate_per_person($outing, $countIkut);

$myProofs = [];
$myPaid = 0;
$myOutstanding = 0;
if ($myParticipant) {
    $myPaid = (float)$myParticipant['amount_paid'];
    $myOutstanding = participant_outstanding($myParticipant, $estimatePerPerson);
    $pfStmt = $pdo->prepare('SELECT * FROM payment_proofs WHERE participant_id = :pid AND user_id = :uid ORDER BY uploaded_at DESC, id DESC');
    $pfStmt->execute([':pid' => $myParticipant['id'], ':uid' => $currentUser['id']]);
    $myProofs = $pfStmt->fetchAll();
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
                    <div class="small mt-2">
                        <span class="text-muted">Estimasi/member:</span> <strong><?= format_rupiah($estimatePerPerson); ?></strong>
                        &bull; <span class="text-muted">Dibayar:</span> <strong class="text-success"><?= format_rupiah($myPaid); ?></strong>
                        <?php if ($myOutstanding > 0): ?>
                            &bull; <span class="text-muted">Sisa:</span> <strong class="text-danger"><?= format_rupiah($myOutstanding); ?></strong>
                        <?php endif; ?>
                    </div>
                    <a href="#m-payment" class="btn btn-success btn-sm mt-2 js-open-tab" data-tab-target="#m-payment">
                        <i class="bi bi-cloud-arrow-up me-1"></i> Upload Bukti Bayar
                    </a>
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
    <?php if ($myParticipant): ?>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="m-payment-tab" data-bs-toggle="tab" data-bs-target="#m-payment" type="button" role="tab">
            <i class="bi bi-wallet2 me-1"></i> Pembayaran Saya
            <?php if ($myOutstanding > 0): ?><span class="badge bg-danger ms-1">Sisa</span><?php endif; ?>
        </button>
    </li>
    <?php endif; ?>
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

    <!-- ==================== TAB: PEMBAYARAN SAYA (PRIVAT) ==================== -->
    <?php if ($myParticipant): ?>
    <div class="tab-pane fade" id="m-payment" role="tabpanel">
        <?php if ($uploadError): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($uploadError); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- Ringkasan Pembayaran Saya -->
            <div class="col-12 col-lg-5">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-header bg-white py-3 fw-bold">
                        <i class="bi bi-wallet2 me-2 text-primary"></i>Ringkasan Iuran Saya
                        <span class="badge bg-light text-muted border ms-1 fw-normal"><i class="bi bi-lock-fill me-1"></i>Hanya terlihat oleh Anda & admin</span>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <div class="small text-muted">Status Pembayaran</div>
                            <div class="fs-5"><?= payment_badge($myParticipant['payment_status']); ?></div>
                        </div>
                        <?php $myPercent = $estimatePerPerson > 0 ? min(100, round(($myPaid / $estimatePerPerson) * 100)) : ($myPaid > 0 ? 100 : 0); ?>
                        <div class="progress mb-1" style="height: 10px;">
                            <div class="progress-bar <?= $myPercent >= 100 ? 'bg-success' : 'bg-partial'; ?>" style="width: <?= $myPercent; ?>%"></div>
                        </div>
                        <div class="text-end small text-muted mb-3"><?= $myPercent; ?>% dari estimasi</div>

                        <ul class="list-group list-group-flush small">
                            <li class="list-group-item d-flex justify-content-between px-0">
                                <span class="text-muted">Estimasi Iuran per Member</span>
                                <strong class="text-primary"><?= format_rupiah($estimatePerPerson); ?></strong>
                            </li>
                            <li class="list-group-item d-flex justify-content-between px-0">
                                <span class="text-muted">Budget Sudah Dibayar</span>
                                <strong class="text-success"><?= format_rupiah($myPaid); ?></strong>
                            </li>
                            <li class="list-group-item d-flex justify-content-between px-0">
                                <span class="text-muted">Sisa yang Harus Dibayar</span>
                                <strong class="<?= $myOutstanding > 0 ? 'text-danger' : 'text-success'; ?>">
                                    <?= $myOutstanding > 0 ? format_rupiah($myOutstanding) : 'Lunas'; ?>
                                </strong>
                            </li>
                        </ul>
                        <?php if ($myParticipant['attendance_status'] !== 'ikut'): ?>
                            <div class="alert alert-secondary small mt-3 mb-0 py-2">
                                <i class="bi bi-info-circle me-1"></i>Status kehadiran Anda bukan "Ikut", sehingga tidak ada tagihan iuran.
                            </div>
                        <?php endif; ?>
                        <div class="small text-muted mt-3">
                            <i class="bi bi-info-circle me-1"></i>Nominal "Sudah Dibayar" diperbarui oleh admin setelah bukti pembayaran Anda diverifikasi.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Upload Bukti Pembayaran -->
            <div class="col-12 col-lg-7">
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-white py-3 fw-bold">
                        <i class="bi bi-cloud-arrow-up me-2 text-success"></i>Upload Bukti Pembayaran
                    </div>
                    <div class="card-body">
                        <?php if (!$canUploadProof): ?>
                            <div class="text-muted small"><i class="bi bi-slash-circle me-1"></i>Upload bukti pembayaran tidak tersedia karena kegiatan ini dibatalkan.</div>
                        <?php else: ?>
                            <form method="POST" action="#m-payment" enctype="multipart/form-data" class="js-proof-form">
                                <?= csrf_field(); ?>
                                <input type="hidden" name="action" value="upload_proof">
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Foto / Screenshot Bukti Transfer <span class="text-danger">*</span></label>
                                    <input type="file" name="proof_file" class="form-control" accept="image/jpeg,image/png,image/webp,application/pdf" data-compress-image required>
                                    <div class="form-text">JPG, PNG, WEBP (otomatis dikompres agar ringan namun tetap jelas) atau PDF maks. 5 MB.</div>
                                    <div class="js-compress-info small mt-2"></div>
                                </div>
                                <div class="row g-3 mb-3">
                                    <div class="col-12 col-md-6">
                                        <label class="form-label fw-semibold">Nominal Ditransfer (Rp)</label>
                                        <div class="input-group">
                                            <span class="input-group-text">Rp</span>
                                            <input type="number" name="amount" class="form-control" min="0" step="1" placeholder="<?= (int)($myOutstanding > 0 ? $myOutstanding : $estimatePerPerson); ?>">
                                        </div>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <label class="form-label fw-semibold">Catatan</label>
                                        <input type="text" name="note" class="form-control" maxlength="255" placeholder="Contoh: Transfer BCA cicilan ke-1">
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-success w-100">
                                    <i class="bi bi-upload me-1"></i> Kirim Bukti Pembayaran
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Riwayat Bukti Pembayaran Saya -->
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white py-3 fw-bold">
                        <i class="bi bi-clock-history me-2 text-secondary"></i>Riwayat Bukti Pembayaran Saya (<?= count($myProofs); ?>)
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($myProofs)): ?>
                            <div class="text-center text-muted py-4 small">Belum ada bukti pembayaran yang diunggah.</div>
                        <?php else: ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($myProofs as $pf): ?>
                                    <?php $pfUrl = base_url('member/view_payment_proof.php?id=' . $pf['id']); $isPdf = strtolower(pathinfo($pf['file_path'], PATHINFO_EXTENSION)) === 'pdf'; ?>
                                    <li class="list-group-item d-flex gap-3 align-items-center">
                                        <a href="<?= $pfUrl; ?>" target="_blank" class="proof-mini flex-shrink-0" title="Lihat bukti">
                                            <?php if ($isPdf): ?>
                                                <i class="bi bi-file-earmark-pdf text-danger fs-2"></i>
                                            <?php else: ?>
                                                <img src="<?= $pfUrl; ?>" loading="lazy" alt="Bukti">
                                            <?php endif; ?>
                                        </a>
                                        <div class="flex-grow-1 small">
                                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                                <?= proof_status_badge($pf['status']); ?>
                                                <strong><?= (float)$pf['amount'] > 0 ? format_rupiah($pf['amount']) : 'Nominal tidak diisi'; ?></strong>
                                            </div>
                                            <div class="text-muted"><?= date('d/m/Y H:i', strtotime($pf['uploaded_at'])); ?> &bull; <?= format_filesize($pf['file_size']); ?><?= !empty($pf['note']) ? ' &bull; ' . htmlspecialchars($pf['note']) : ''; ?></div>
                                        </div>
                                        <?php if ($pf['status'] !== 'verified'): ?>
                                            <form method="POST" action="#m-payment">
                                                <?= csrf_field(); ?>
                                                <input type="hidden" name="action" value="delete_proof">
                                                <input type="hidden" name="proof_id" value="<?= $pf['id']; ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="Hapus bukti pembayaran ini?" title="Hapus">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ==================== TAB 2: PESERTA ==================== -->
    <div class="tab-pane fade" id="m-participants" role="tabpanel">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3 fw-bold">
                <i class="bi bi-people-fill me-2 text-primary"></i>Daftar Peserta Terdaftar
                <div class="small text-muted fw-normal mt-1"><i class="bi bi-lock me-1"></i>Nominal pembayaran tiap peserta bersifat privat, yang ditampilkan hanya statusnya.</div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 50px;">No</th>
                                <th>Nama Peserta</th>
                                <th>Status Kehadiran</th>
                                <th>Status Bayar</th>
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
                                    <td><?= payment_badge($pt['payment_status']); /* hanya status, tanpa nominal */ ?></td>
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
