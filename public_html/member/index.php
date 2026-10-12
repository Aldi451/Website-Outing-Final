<?php
/**
 * Member Dashboard (100% View Only)
 * Menampilkan daftar agenda outing yang diikuti oleh member
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';

require_member();

$pdo = getDB();
$currentUser = current_user();

// Ambil daftar outing yang diikuti oleh user ini
$stmt = $pdo->prepare('
    SELECT o.*, 
           p.attendance_status, 
           p.payment_status, 
           p.notes as participant_notes,
           (SELECT COUNT(*) FROM outing_participants op WHERE op.outing_id = o.id AND op.attendance_status = "ikut") as total_peserta_ikut,
           (SELECT COUNT(*) FROM rundown r WHERE r.outing_id = o.id) as total_agenda
    FROM outing_participants p
    JOIN outings o ON p.outing_id = o.id
    WHERE p.user_id = :uid
    ORDER BY FIELD(o.status, "active", "planning", "selesai", "cancelled"), o.start_date ASC
');
$stmt->execute([':uid' => $currentUser['id']]);
$myOutings = $stmt->fetchAll();

$pageTitle = 'Dashboard Member - Outing Saya';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="card bg-primary text-white border-0 shadow-sm overflow-hidden">
            <div class="card-body p-4 p-md-5">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
                    <div>
                        <span class="badge bg-white text-primary mb-2">Member Portal &bull; View Only</span>
                        <h2 class="fw-bold mb-1">Halo, <?= htmlspecialchars($currentUser['nama']); ?>! 👋</h2>
                        <p class="mb-0 text-white-50">Berikut adalah agenda outing perusahaan yang Anda ikuti.</p>
                    </div>
                    <div class="bg-white bg-opacity-10 p-3 rounded text-center">
                        <div class="small text-white-50">Total Outing Anda</div>
                        <div class="fs-2 fw-bold text-white"><?= count($myOutings); ?> Kegiatan</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<h4 class="fw-bold mb-3"><i class="bi bi-compass-fill text-primary me-2"></i>Daftar Outing Yang Diikuti</h4>

<?php if (empty($myOutings)): ?>
    <div class="card shadow-sm border-0 text-center py-5">
        <div class="card-body">
            <i class="bi bi-calendar-x text-muted" style="font-size: 3.5rem;"></i>
            <h5 class="fw-bold mt-3">Belum Terdaftar di Kegiatan Outing</h5>
            <p class="text-muted">Administrator belum menambahkan akun Anda ke dalam daftar kegiatan outing aktif. Silakan hubungi koordinator acara jika ada pertanyaan.</p>
        </div>
    </div>
<?php else: ?>
    <div class="row g-4">
        <?php foreach ($myOutings as $o): ?>
            <div class="col-12 col-md-6 col-xl-4">
                <div class="card card-hover h-100 shadow-sm border">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <?= status_badge($o['status']); ?>
                        <small class="text-muted"><i class="bi bi-clock me-1"></i><?= $o['total_agenda']; ?> Agenda</small>
                    </div>
                    <div class="card-body d-flex flex-column">
                        <h5 class="fw-bold mb-2">
                            <a href="<?= base_url('member/outing_detail.php?id=' . $o['id']); ?>" class="text-decoration-none text-dark">
                                <?= htmlspecialchars($o['outing_name']); ?>
                            </a>
                        </h5>
                        <p class="text-muted small mb-3">
                            <i class="bi bi-geo-alt-fill text-danger me-1"></i><?= htmlspecialchars($o['destination']); ?><br>
                            <i class="bi bi-calendar-check text-primary me-1"></i><?= format_date_id($o['start_date']); ?> s/d <?= format_date_id($o['end_date']); ?>
                        </p>

                        <!-- Box Status Diri Sendiri -->
                        <div class="bg-light p-3 rounded mb-3 mt-auto">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="small text-muted">Status Kehadiran:</span>
                                <div><?= attendance_badge($o['attendance_status']); ?></div>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="small text-muted">Status Iuran/Bayar:</span>
                                <div><?= payment_badge($o['payment_status']); ?></div>
                            </div>
                            <?php if (!empty($o['participant_notes'])): ?>
                                <div class="mt-2 pt-2 border-top small text-secondary">
                                    <i class="bi bi-info-circle me-1"></i>Catatan: <?= htmlspecialchars($o['participant_notes']); ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <a href="<?= base_url('member/outing_detail.php?id=' . $o['id']); ?>" class="btn btn-outline-primary btn-sm w-100 mt-2">
                            <i class="bi bi-card-checklist me-1"></i> Lihat Rundown & Info Lengkap
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
