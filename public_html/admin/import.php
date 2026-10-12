<?php
/**
 * Admin - Alur Import Excel (.xlsx) Lengkap
 * Alur: Upload -> Validasi Sistem -> Preview (Valid vs Error) -> Konfirmasi Simpan
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../libs/SimpleXLSX.php';

require_admin();

$pdo = getDB();

$step = 'upload'; // 'upload', 'preview'
$type = $_GET['type'] ?? $_POST['import_type'] ?? 'users';
$outingId = (int)($_GET['outing_id'] ?? $_POST['outing_id'] ?? 0);

// Ambil daftar outing untuk dropdown
$outingsStmt = $pdo->query("SELECT id, outing_name, status FROM outings ORDER BY start_date DESC");
$allOutings = $outingsStmt->fetchAll();

$error = '';
$previewData = [];
$validCount = 0;
$errorCount = 0;
$autoCreateCategory = false;

// -------------------------------------------------------------
// STEP 1: PROSES UPLOAD & VALIDASI EXCEL
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'validate_upload') {
    if (!validate_csrf()) {
        $error = 'Validasi CSRF token gagal. Silakan muat ulang halaman.';
    } elseif (empty($_FILES['excel_file']['tmp_name'])) {
        $error = 'Pilih file Excel (.xlsx atau .csv) terlebih dahulu.';
    } else {
        $autoCreateCategory = !empty($_POST['auto_create_category']);
        $filePath = $_FILES['excel_file']['tmp_name'];
        $xlsx = SimpleXLSX::parse($filePath);

        if (!$xlsx) {
            $error = 'Gagal membaca file Excel. Pastikan format file adalah .xlsx atau .csv yang valid.';
        } else {
            $rows = $xlsx->rows();
            if (count($rows) <= 1) {
                $error = 'File Excel kosong atau hanya berisi baris judul header.';
            } else {
                $step = 'preview';
                $header = array_shift($rows); // Hapus baris header pertama

                // A. VALIDASI IMPORT USERS
                if ($type === 'users') {
                    $existingUsers = $pdo->query("SELECT user_id FROM users")->fetchAll(PDO::FETCH_COLUMN);
                    $existingUsersMap = array_flip(array_map('strtolower', $existingUsers));
                    $fileUserIds = [];

                    foreach ($rows as $idx => $r) {
                        $rowNum = $idx + 2;
                        $uid = trim($r[0] ?? '');
                        $nama = trim($r[1] ?? '');
                        $role = strtolower(trim($r[2] ?? 'member'));
                        $status = strtolower(trim($r[3] ?? 'active'));
                        $pwd = trim($r[4] ?? 'user123');

                        $isValid = true;
                        $errorMsg = [];

                        if (empty($uid)) {
                            $isValid = false;
                            $errorMsg[] = 'User ID kosong';
                        } elseif (isset($existingUsersMap[strtolower($uid)])) {
                            $isValid = false;
                            $errorMsg[] = 'User ID sudah ada di database';
                        } elseif (in_array(strtolower($uid), $fileUserIds)) {
                            $isValid = false;
                            $errorMsg[] = 'User ID duplikat di dalam file';
                        } else {
                            $fileUserIds[] = strtolower($uid);
                        }

                        if (empty($nama)) {
                            $isValid = false;
                            $errorMsg[] = 'Nama lengkap kosong';
                        }
                        if (!in_array($role, ['admin', 'member'])) {
                            $role = 'member';
                        }
                        if (!in_array($status, ['active', 'inactive'])) {
                            $status = 'active';
                        }
                        if (empty($pwd)) {
                            $pwd = 'user123';
                        }

                        if ($isValid) $validCount++; else $errorCount++;

                        $previewData[] = [
                            'row' => $rowNum,
                            'is_valid' => $isValid,
                            'errors' => implode(', ', $errorMsg),
                            'data' => [
                                'user_id' => $uid,
                                'nama' => $nama,
                                'role' => $role,
                                'status' => $status,
                                'password' => $pwd
                            ]
                        ];
                    }
                }

                // B. VALIDASI IMPORT PESERTA
                elseif ($type === 'participants') {
                    if ($outingId <= 0) {
                        $error = 'Pilih Outing Target terlebih dahulu untuk import peserta.';
                        $step = 'upload';
                    } else {
                        // Ambil user map
                        $userMapStmt = $pdo->query("SELECT LOWER(user_id) as uid, id FROM users");
                        $userDbMap = $userMapStmt->fetchAll(PDO::FETCH_KEY_PAIR);

                        // Ambil peserta yang sudah terdaftar di outing ini
                        $existingPartsStmt = $pdo->prepare("SELECT user_id FROM outing_participants WHERE outing_id = :oid");
                        $existingPartsStmt->execute([':oid' => $outingId]);
                        $existingParts = array_flip($existingPartsStmt->fetchAll(PDO::FETCH_COLUMN));

                        $fileUserIds = [];

                        foreach ($rows as $idx => $r) {
                            $rowNum = $idx + 2;
                            $uid = trim($r[0] ?? '');
                            $att = strtolower(trim($r[1] ?? 'ikut'));
                            $pay = strtolower(trim($r[2] ?? 'belum'));
                            $notes = trim($r[3] ?? '');

                            $isValid = true;
                            $errorMsg = [];

                            if (empty($uid)) {
                                $isValid = false;
                                $errorMsg[] = 'User ID kosong';
                            } elseif (!isset($userDbMap[strtolower($uid)])) {
                                $isValid = false;
                                $errorMsg[] = 'User ID tidak terdaftar di sistem';
                            } else {
                                $dbUserId = $userDbMap[strtolower($uid)];
                                if (isset($existingParts[$dbUserId])) {
                                    $isValid = false;
                                    $errorMsg[] = 'User sudah terdaftar di outing ini';
                                } elseif (in_array(strtolower($uid), $fileUserIds)) {
                                    $isValid = false;
                                    $errorMsg[] = 'User ID duplikat di file';
                                } else {
                                    $fileUserIds[] = strtolower($uid);
                                }
                            }

                            if (!in_array($att, ['ikut', 'batal', 'tidak_ikut'])) $att = 'ikut';
                            if (!in_array($pay, payment_statuses(), true)) $pay = 'belum';

                            if ($isValid) $validCount++; else $errorCount++;

                            $previewData[] = [
                                'row' => $rowNum,
                                'is_valid' => $isValid,
                                'errors' => implode(', ', $errorMsg),
                                'data' => [
                                    'user_id' => $uid,
                                    'db_user_id' => $userDbMap[strtolower($uid)] ?? null,
                                    'attendance_status' => $att,
                                    'payment_status' => $pay,
                                    'notes' => $notes
                                ]
                            ];
                        }
                    }
                }

                // C. VALIDASI IMPORT PEMBELIAN
                elseif ($type === 'purchases') {
                    if ($outingId <= 0) {
                        $error = 'Pilih Outing Target terlebih dahulu untuk import pembelian.';
                        $step = 'upload';
                    } else {
                        // Ambil kategori map dari database
                        $catStmt = $pdo->query("SELECT LOWER(name), id FROM categories");
                        $catDbMap = $catStmt->fetchAll(PDO::FETCH_KEY_PAIR);

                        foreach ($rows as $idx => $r) {
                            $rowNum = $idx + 2;
                            $tgl = trim($r[0] ?? date('Y-m-d'));
                            $catName = trim($r[1] ?? 'Lain-lain');
                            $itemName = trim($r[2] ?? '');
                            $qty = max(1, (int)($r[3] ?? 1));
                            $unitPrice = (float)str_replace(['.', ','], ['', '.'], $r[4] ?? '0');
                            $calcTotal = $qty * $unitPrice; // Hitung ulang di sisi sistem (abaikan total di Excel)
                            $notes = trim($r[5] ?? '');

                            $isValid = true;
                            $errorMsg = [];

                            if (empty($itemName)) {
                                $isValid = false;
                                $errorMsg[] = 'Nama barang kosong';
                            }

                            if ($unitPrice <= 0) {
                                $isValid = false;
                                $errorMsg[] = 'Harga satuan harus > 0';
                            }

                            $catId = $catDbMap[strtolower($catName)] ?? null;
                            if (!$catId) {
                                if (!$autoCreateCategory) {
                                    $isValid = false;
                                    $errorMsg[] = 'Kategori "' . $catName . '" belum ada di database';
                                }
                            }

                            if ($isValid) $validCount++; else $errorCount++;

                            $previewData[] = [
                                'row' => $rowNum,
                                'is_valid' => $isValid,
                                'errors' => implode(', ', $errorMsg),
                                'data' => [
                                    'purchase_date' => $tgl,
                                    'category_name' => $catName,
                                    'category_id' => $catId,
                                    'item_name' => $itemName,
                                    'qty' => $qty,
                                    'unit_price' => $unitPrice,
                                    'total_price' => $calcTotal,
                                    'notes' => $notes
                                ]
                            ];
                        }
                    }
                }
            }
        }
    }
}

// -------------------------------------------------------------
// STEP 2: KONFIRMASI IMPORT (SIMPAN HANYA DATA VALID)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm_import') {
    if (!validate_csrf()) {
        $error = 'Validasi CSRF token gagal. Silakan coba kembali.';
    } else {
        $payload = json_decode($_POST['valid_payload'] ?? '[]', true);
        $importedCount = 0;

        if (empty($payload)) {
            $error = 'Tidak ada baris data valid yang dapat diimpor.';
        } else {
            $pdo->beginTransaction();
            try {
                // A. SIMPAN USERS
                if ($type === 'users') {
                    $insU = $pdo->prepare('
                        INSERT INTO users (user_id, nama, password, role, status)
                        VALUES (:uid, :nama, :pwd, :role, :status)
                    ');
                    foreach ($payload as $item) {
                        $hash = password_hash($item['password'], PASSWORD_BCRYPT);
                        $insU->execute([
                            ':uid'    => $item['user_id'],
                            ':nama'   => $item['nama'],
                            ':pwd'    => $hash,
                            ':role'   => $item['role'],
                            ':status' => $item['status']
                        ]);
                        $importedCount++;
                    }
                    $pdo->commit();
                    flash_set('success', 'Berhasil mengimpor ' . $importedCount . ' user baru dari Excel!');
                    header('Location: ' . base_url('admin/users.php'));
                    exit;
                }

                // B. SIMPAN PESERTA
                elseif ($type === 'participants') {
                    $insP = $pdo->prepare('
                        INSERT INTO outing_participants (outing_id, user_id, attendance_status, payment_status, notes)
                        VALUES (:oid, :uid, :att, :pay, :notes)
                    ');
                    foreach ($payload as $item) {
                        $insP->execute([
                            ':oid'   => $outingId,
                            ':uid'   => $item['db_user_id'],
                            ':att'   => $item['attendance_status'],
                            ':pay'   => $item['payment_status'],
                            ':notes' => $item['notes']
                        ]);
                        $importedCount++;
                    }
                    $pdo->commit();
                    flash_set('success', 'Berhasil mengimpor ' . $importedCount . ' peserta ke kegiatan outing!');
                    header('Location: ' . base_url('admin/outing_detail.php?id=' . $outingId . '#participants'));
                    exit;
                }

                // C. SIMPAN PEMBELIAN
                elseif ($type === 'purchases') {
                    $cUser = current_user();
                    $insPurch = $pdo->prepare('
                        INSERT INTO purchases (outing_id, category_id, item_name, qty, unit_price, total_price, purchase_date, notes, created_by)
                        VALUES (:oid, :cat, :item, :qty, :up, :tp, :pdate, :notes, :cby)
                    ');

                    foreach ($payload as $item) {
                        $catId = $item['category_id'];
                        // Buat kategori otomatis jika belum ada dan nama disertakan
                        if (!$catId && !empty($item['category_name'])) {
                            $findC = $pdo->prepare('SELECT id FROM categories WHERE LOWER(name) = LOWER(:name) LIMIT 1');
                            $findC->execute([':name' => $item['category_name']]);
                            $cRow = $findC->fetch();
                            if ($cRow) {
                                $catId = $cRow['id'];
                            } else {
                                $newC = $pdo->prepare('INSERT INTO categories (name) VALUES (:name)');
                                $newC->execute([':name' => $item['category_name']]);
                                $catId = $pdo->lastInsertId();
                            }
                        }

                        $insPurch->execute([
                            ':oid'   => $outingId,
                            ':cat'   => $catId,
                            ':item'  => $item['item_name'],
                            ':qty'   => $item['qty'],
                            ':up'    => $item['unit_price'],
                            ':tp'    => $item['total_price'],
                            ':pdate' => $item['purchase_date'],
                            ':notes' => $item['notes'],
                            ':cby'   => $cUser['id']
                        ]);
                        $importedCount++;
                    }
                    $pdo->commit();
                    flash_set('success', 'Berhasil mengimpor ' . $importedCount . ' transaksi pengeluaran dari Excel!');
                    header('Location: ' . base_url('admin/outing_detail.php?id=' . $outingId . '#purchases'));
                    exit;
                }

            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Gagal menyimpan data import ke database: ' . $e->getMessage();
            }
        }
    }
}

$pageTitle = 'Import Data Excel (.xlsx)';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center mb-4 gap-3">
    <div>
        <h3 class="fw-bold mb-1"><i class="bi bi-file-earmark-spreadsheet-fill text-success me-2"></i>Import Data Excel (.xlsx)</h3>
        <p class="text-muted mb-0">Alur: <strong>Upload File</strong> &rarr; <strong>Validasi Sistem</strong> &rarr; <strong>Preview Data</strong> &rarr; <strong>Konfirmasi Simpan</strong></p>
    </div>
    <?php if ($outingId > 0): ?>
        <a href="<?= base_url('admin/outing_detail.php?id=' . $outingId); ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Kembali ke Outing
        </a>
    <?php endif; ?>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($step === 'upload'): ?>
    <!-- ==================== TAHAP 1: FORM UPLOAD ==================== -->
    <div class="row g-4">
        <div class="col-12 col-lg-8">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3 fw-bold">
                    <i class="bi bi-cloud-arrow-up-fill me-2 text-primary"></i>Upload Berkas Excel
                </div>
                <div class="card-body p-4">
                    <form method="POST" action="" enctype="multipart/form-data">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="action" value="validate_upload">

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Pilih Tipe Data yang Ingin Diimpor</label>
                            <select name="import_type" id="import_type" class="form-select" onchange="updateTemplateLink()">
                                <option value="users" <?= $type === 'users' ? 'selected' : ''; ?>>1. Data Master Pengguna (Users)</option>
                                <option value="participants" <?= $type === 'participants' ? 'selected' : ''; ?>>2. Data Peserta Outing (Participants)</option>
                                <option value="purchases" <?= $type === 'purchases' ? 'selected' : ''; ?>>3. Data Pembelian & Pengeluaran (Purchases)</option>
                            </select>
                        </div>

                        <div class="mb-3" id="targetOutingWrapper" style="<?= $type === 'users' ? 'display:none;' : ''; ?>">
                            <label class="form-label fw-semibold">Pilih Target Kegiatan Outing <span class="text-danger">*</span></label>
                            <select name="outing_id" id="outing_id" class="form-select" onchange="updateTemplateLink()">
                                <option value="">-- Pilih Outing --</option>
                                <?php foreach ($allOutings as $ao): ?>
                                    <option value="<?= $ao['id']; ?>" <?= $ao['id'] == $outingId ? 'selected' : ''; ?>>
                                        <?= htmlspecialchars($ao['outing_name']); ?> (<?= strtoupper($ao['status']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Pilih File Excel (.xlsx / .csv) <span class="text-danger">*</span></label>
                            <input type="file" name="excel_file" class="form-control" accept=".xlsx,.csv" required>
                            <div class="form-text">Pastikan struktur kolom mengikuti format template resmi yang disediakan.</div>
                        </div>

                        <div class="form-check form-switch mb-4" id="autoCategoryWrapper" style="<?= $type !== 'purchases' ? 'display:none;' : ''; ?>">
                            <input class="form-check-input" type="checkbox" role="switch" id="auto_create_category" name="auto_create_category" value="1" checked>
                            <label class="form-check-label fw-semibold" for="auto_create_category">
                                Buat Kategori Otomatis jika kategori di Excel belum terdaftar di database
                            </label>
                        </div>

                        <div class="d-flex justify-content-between align-items-center pt-3 border-top">
                            <a id="downloadTemplateBtn" href="<?= base_url('admin/template_excel.php?type=' . $type . ($outingId > 0 ? '&outing_id=' . $outingId : '')); ?>" class="btn btn-outline-success">
                                <i class="bi bi-file-earmark-excel me-1"></i> Download Template Excel
                            </a>
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="bi bi-arrow-right-circle me-1"></i> Upload & Validasi Sistem
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-header fw-bold"><i class="bi bi-info-circle me-2 text-info"></i>Petunjuk Import</div>
                <div class="card-body small text-muted">
                    <p>Sistem dilengkapi validasi otomatis dua tingkat:</p>
                    <ol class="ps-3 mb-3">
                        <li><strong>Download Template:</strong> File template berisi <em>Sheet 1: DATA</em> dan <em>Sheet 2: PETUNJUK</em>.</li>
                        <li><strong>Validasi Baris:</strong> Sistem memeriksa setiap baris. Baris yang memiliki error akan diberi tanda merah beserta alasannya.</li>
                        <li><strong>Import Parsial Aman:</strong> Anda dapat langsung mengonfirmasi baris yang valid. Sistem akan otomatis <strong>mengabaikan baris error</strong> tanpa membatalkan baris yang sudah benar.</li>
                        <li><strong>Perhitungan Total:</strong> Total harga pada pembelian dihitung ulang secara otomatis oleh sistem (Qty × Harga Satuan).</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <script>
        function updateTemplateLink() {
            const type = document.getElementById('import_type').value;
            const outingId = document.getElementById('outing_id').value;
            const targetOutingWrapper = document.getElementById('targetOutingWrapper');
            const autoCategoryWrapper = document.getElementById('autoCategoryWrapper');
            const btn = document.getElementById('downloadTemplateBtn');

            if (type === 'users') {
                targetOutingWrapper.style.display = 'none';
                autoCategoryWrapper.style.display = 'none';
            } else if (type === 'participants') {
                targetOutingWrapper.style.display = 'block';
                autoCategoryWrapper.style.display = 'none';
            } else if (type === 'purchases') {
                targetOutingWrapper.style.display = 'block';
                autoCategoryWrapper.style.display = 'block';
            }

            btn.href = '<?= base_url("admin/template_excel.php"); ?>?type=' + type + (outingId ? '&outing_id=' + outingId : '');
        }
    </script>

<?php else: ?>
    <!-- ==================== TAHAP 2: PREVIEW DATA (VALID vs ERROR) ==================== -->
    <?php
        $validPayload = [];
        foreach ($previewData as $p) {
            if ($p['is_valid']) {
                $validPayload[] = $p['data'];
            }
        }
    ?>

    <div class="card shadow-sm mb-4">
        <div class="card-body p-4">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
                <div>
                    <h5 class="fw-bold mb-1">Hasil Validasi File: <?= count($previewData); ?> Total Baris</h5>
                    <div class="d-flex gap-2 mt-2">
                        <span class="badge bg-success fs-6 py-2 px-3"><i class="bi bi-check-circle me-1"></i><?= $validCount; ?> Baris Valid</span>
                        <span class="badge bg-danger fs-6 py-2 px-3"><i class="bi bi-exclamation-octagon me-1"></i><?= $errorCount; ?> Baris Error</span>
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <a href="<?= base_url('admin/import.php?type=' . $type . ($outingId > 0 ? '&outing_id=' . $outingId : '')); ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-x-circle me-1"></i> Batalkan & Upload Ulang
                    </a>
                    <?php if ($validCount > 0): ?>
                        <form method="POST" action="">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="action" value="confirm_import">
                            <input type="hidden" name="import_type" value="<?= htmlspecialchars($type); ?>">
                            <input type="hidden" name="outing_id" value="<?= $outingId; ?>">
                            <input type="hidden" name="valid_payload" value="<?= htmlspecialchars(json_encode($validPayload)); ?>">
                            <button type="submit" class="btn btn-primary" data-confirm="Lanjutkan import <?= $validCount; ?> data yang valid? (Baris error akan diabaikan)">
                                <i class="bi bi-check2-circle me-1"></i> Konfirmasi Import (<?= $validCount; ?> Data)
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabel Preview Baris -->
    <div class="card shadow-sm">
        <div class="card-header fw-bold">
            <i class="bi bi-table me-2 text-primary"></i>Rincian Preview Per Baris
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 70px;">Baris</th>
                            <th style="width: 120px;">Status Validasi</th>
                            <th>Data Kolom Excel</th>
                            <th>Keterangan / Pesan Error</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($previewData as $item): ?>
                            <tr class="<?= $item['is_valid'] ? 'table-success bg-opacity-25' : 'table-danger bg-opacity-25'; ?>">
                                <td class="fw-bold text-muted">#<?= $item['row']; ?></td>
                                <td>
                                    <?php if ($item['is_valid']): ?>
                                        <span class="badge bg-success"><i class="bi bi-check-lg me-1"></i>Valid</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger"><i class="bi bi-x-lg me-1"></i>Error</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($type === 'users'): ?>
                                        <strong><?= htmlspecialchars($item['data']['user_id']); ?></strong> - <?= htmlspecialchars($item['data']['nama']); ?> 
                                        (Role: <?= $item['data']['role']; ?>, Status: <?= $item['data']['status']; ?>)
                                    <?php elseif ($type === 'participants'): ?>
                                        <strong><?= htmlspecialchars($item['data']['user_id']); ?></strong> &bull; 
                                        Kehadiran: <?= attendance_badge($item['data']['attendance_status']); ?> &bull; 
                                        Pembayaran: <?= payment_badge($item['data']['payment_status']); ?>
                                        <?= !empty($item['data']['notes']) ? ' (' . htmlspecialchars($item['data']['notes']) . ')' : ''; ?>
                                    <?php elseif ($type === 'purchases'): ?>
                                        <strong><?= htmlspecialchars($item['data']['item_name']); ?></strong> &bull; 
                                        Kategori: <span class="badge bg-light text-dark border"><?= htmlspecialchars($item['data']['category_name']); ?></span> &bull; 
                                        Qty: <?= $item['data']['qty']; ?> &times; <?= format_rupiah($item['data']['unit_price']); ?> = 
                                        <strong class="text-primary"><?= format_rupiah($item['data']['total_price']); ?></strong>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($item['is_valid']): ?>
                                        <span class="text-success small fw-semibold"><i class="bi bi-check-circle me-1"></i>Siap diimpor</span>
                                    <?php else: ?>
                                        <span class="text-danger small fw-semibold"><i class="bi bi-exclamation-triangle-fill me-1"></i><?= htmlspecialchars($item['errors']); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
