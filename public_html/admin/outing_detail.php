<?php
/**
 * Admin - Detail & Pusat Kendali Outing
 * Mengelola Peserta, Rundown, Pengeluaran & Upload Bukti, serta Rekapitulasi
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/csrf.php';

require_admin();

$pdo = getDB();
$outingId = (int)($_GET['id'] ?? 0);
$mode = $_GET['mode'] ?? 'normal'; // 'normal', 'view' (read-only), 'edit_history'

if ($outingId <= 0) {
    flash_set('danger', 'ID Outing tidak valid.');
    header('Location: ' . base_url('admin/index.php'));
    exit;
}

// 1. Ambil Data Outing
$stmt = $pdo->prepare('SELECT * FROM outings WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $outingId]);
$outing = $stmt->fetch();

if (!$outing) {
    flash_set('danger', 'Data outing tidak ditemukan.');
    header('Location: ' . base_url('admin/index.php'));
    exit;
}

// Apakah halaman dalam mode Read-Only?
// Outing 'selesai' / 'cancelled' adalah read-only KECUALI admin memilih mode 'edit_history'
$isReadOnly = in_array($outing['status'], ['selesai', 'cancelled']) && ($mode !== 'edit_history');

$redirectBase = base_url('admin/outing_detail.php?id=' . $outingId . ($mode === 'edit_history' ? '&mode=edit_history' : ''));

// Estimasi iuran per member terkini (manual atau Total Budget / peserta "Ikut")
$getEstimate = function () use ($pdo, $outingId, $outing) {
    $c = $pdo->prepare('SELECT COUNT(*) FROM outing_participants WHERE outing_id = :oid AND attendance_status = "ikut"');
    $c->execute([':oid' => $outingId]);
    return outing_estimate_per_person($outing, (int)$c->fetchColumn());
};

// Set status pembayaran peserta. $mode 'auto' = dihitung dari nominal dibayar vs estimasi
$setPaymentStatus = function ($participantId, $mode) use ($pdo, $outingId, $getEstimate) {
    if (in_array($mode, payment_statuses(), true)) {
        $status = $mode;
    } else {
        $q = $pdo->prepare('SELECT amount_paid FROM outing_participants WHERE id = :id AND outing_id = :oid');
        $q->execute([':id' => $participantId, ':oid' => $outingId]);
        $status = resolve_payment_status((float)$q->fetchColumn(), $getEstimate());
    }
    $pdo->prepare('UPDATE outing_participants SET payment_status = :st WHERE id = :id AND outing_id = :oid')
        ->execute([':st' => $status, ':id' => $participantId, ':oid' => $outingId]);
    return $status;
};

// Handle Actions POST (Hanya jika bukan Read-Only)
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isReadOnly) {
    if (!validate_csrf()) {
        $error = 'Validasi CSRF token gagal. Silakan muat ulang halaman.';
    } else {
        $action = $_POST['action'] ?? '';

        // --- A. TAMBAH PESERTA ---
        if ($action === 'add_participant') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $attendance = $_POST['attendance_status'] ?? 'ikut';
            if (!in_array($attendance, ['ikut', 'batal', 'tidak_ikut'], true)) $attendance = 'ikut';
            $payment = $_POST['payment_status'] ?? 'auto';
            $amountPaid = max(0, parse_money($_POST['amount_paid'] ?? '0'));
            $notes = trim($_POST['notes'] ?? '');

            if ($userId <= 0) {
                $error = 'Pilih user yang ingin ditambahkan.';
            } else {
                try {
                    $insP = $pdo->prepare('
                        INSERT INTO outing_participants (outing_id, user_id, attendance_status, payment_status, amount_paid, notes)
                        VALUES (:oid, :uid, :att, :pay, :amt, :notes)
                    ');
                    $insP->execute([
                        ':oid'   => $outingId,
                        ':uid'   => $userId,
                        ':att'   => $attendance,
                        ':pay'   => in_array($payment, payment_statuses(), true) ? $payment : 'belum',
                        ':amt'   => $amountPaid,
                        ':notes' => $notes
                    ]);
                    if (!in_array($payment, payment_statuses(), true)) {
                        $setPaymentStatus((int)$pdo->lastInsertId(), 'auto');
                    }
                    flash_set('success', 'Peserta berhasil ditambahkan ke outing.');
                    header('Location: ' . $redirectBase . '#participants');
                    exit;
                } catch (PDOException $e) {
                    $error = 'User sudah terdaftar sebagai peserta pada outing ini.';
                }
            }
        }

        // --- B. UPDATE STATUS PESERTA & NOMINAL YANG SUDAH DIBAYAR ---
        elseif ($action === 'update_participant_status') {
            $partId = (int)($_POST['participant_id'] ?? 0);
            $attendance = $_POST['attendance_status'] ?? 'ikut';
            if (!in_array($attendance, ['ikut', 'batal', 'tidak_ikut'], true)) $attendance = 'ikut';
            $payment = $_POST['payment_status'] ?? 'auto';
            $amountPaid = max(0, parse_money($_POST['amount_paid'] ?? '0'));
            $notes = trim($_POST['notes'] ?? '');

            $upP = $pdo->prepare('
                UPDATE outing_participants 
                SET attendance_status = :att, amount_paid = :amt, notes = :notes
                WHERE id = :id AND outing_id = :oid
            ');
            $upP->execute([
                ':att'   => $attendance,
                ':amt'   => $amountPaid,
                ':notes' => $notes,
                ':id'    => $partId,
                ':oid'   => $outingId
            ]);
            $finalStatus = $setPaymentStatus($partId, $payment);

            flash_set('success', 'Data peserta diperbarui. Dibayar: ' . format_rupiah($amountPaid) . ' (status: ' . strtoupper($finalStatus) . ').');
            header('Location: ' . $redirectBase . '#participants');
            exit;
        }

        // --- C. HAPUS PESERTA ---
        elseif ($action === 'delete_participant') {
            $partId = (int)($_POST['participant_id'] ?? 0);
            $chk = $pdo->prepare('SELECT id FROM outing_participants WHERE id = :id AND outing_id = :oid');
            $chk->execute([':id' => $partId, ':oid' => $outingId]);
            if ($chk->fetchColumn()) {
                delete_payment_proofs($pdo, 'participant_id', $partId);
            }
            $delP = $pdo->prepare('DELETE FROM outing_participants WHERE id = :id AND outing_id = :oid');
            $delP->execute([':id' => $partId, ':oid' => $outingId]);

            flash_set('success', 'Peserta berhasil dihapus dari outing.');
            header('Location: ' . $redirectBase . '#participants');
            exit;
        }

        // --- C2. HITUNG ULANG STATUS PEMBAYARAN SEMUA PESERTA ---
        elseif ($action === 'recalc_payment_status') {
            $ids = $pdo->prepare('SELECT id FROM outing_participants WHERE outing_id = :oid');
            $ids->execute([':oid' => $outingId]);
            $n = 0;
            foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                $setPaymentStatus((int)$pid, 'auto');
                $n++;
            }
            flash_set('success', 'Status pembayaran ' . $n . ' peserta dihitung ulang berdasarkan nominal dibayar vs estimasi per member (' . format_rupiah($getEstimate()) . ').');
            header('Location: ' . $redirectBase . '#participants');
            exit;
        }

        // --- C3. VERIFIKASI / TOLAK / HAPUS BUKTI PEMBAYARAN MEMBER ---
        elseif (in_array($action, ['verify_proof', 'reject_proof', 'delete_proof'], true)) {
            $proofId = (int)($_POST['proof_id'] ?? 0);
            $pfStmt = $pdo->prepare('SELECT * FROM payment_proofs WHERE id = :id AND outing_id = :oid LIMIT 1');
            $pfStmt->execute([':id' => $proofId, ':oid' => $outingId]);
            $proof = $pfStmt->fetch();

            if (!$proof) {
                $error = 'Bukti pembayaran tidak ditemukan.';
            } elseif ($action === 'delete_proof') {
                delete_payment_proofs($pdo, 'id', $proofId);
                flash_set('success', 'Bukti pembayaran berhasil dihapus.');
                header('Location: ' . $redirectBase . '#participants');
                exit;
            } elseif ($action === 'reject_proof') {
                $pdo->prepare('UPDATE payment_proofs SET status = "rejected", verified_by = :vb, verified_at = NOW() WHERE id = :id')
                    ->execute([':vb' => current_user()['id'], ':id' => $proofId]);
                flash_set('warning', 'Bukti pembayaran ditandai DITOLAK. Member dapat melihat status ini dan mengunggah ulang.');
                header('Location: ' . $redirectBase . '#participants');
                exit;
            } else {
                $addAmount = !empty($_POST['add_amount']) && $proof['status'] !== 'verified' && (float)$proof['amount'] > 0;
                $pdo->beginTransaction();
                try {
                    $pdo->prepare('UPDATE payment_proofs SET status = "verified", verified_by = :vb, verified_at = NOW() WHERE id = :id')
                        ->execute([':vb' => current_user()['id'], ':id' => $proofId]);
                    if ($addAmount) {
                        $pdo->prepare('UPDATE outing_participants SET amount_paid = amount_paid + :amt WHERE id = :pid AND outing_id = :oid')
                            ->execute([':amt' => (float)$proof['amount'], ':pid' => $proof['participant_id'], ':oid' => $outingId]);
                    }
                    $pdo->commit();
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }
                if ($addAmount) {
                    // Status naik otomatis (tidak pernah menurunkan status "sudah" yang diset manual)
                    $cur = $pdo->prepare('SELECT payment_status FROM outing_participants WHERE id = :pid');
                    $cur->execute([':pid' => $proof['participant_id']]);
                    if ($cur->fetchColumn() !== 'sudah') {
                        $setPaymentStatus((int)$proof['participant_id'], 'auto');
                    }
                }
                flash_set('success', 'Bukti pembayaran terverifikasi' . ($addAmount ? ' dan ' . format_rupiah($proof['amount']) . ' ditambahkan ke total dibayar peserta.' : '.'));
                header('Location: ' . $redirectBase . '#participants');
                exit;
            }
        }

        // --- C4. ADMIN UPLOAD BUKTI ATAS NAMA PESERTA ---
        elseif ($action === 'admin_upload_proof') {
            $partId = (int)($_POST['participant_id'] ?? 0);
            $ptStmt = $pdo->prepare('SELECT id, user_id FROM outing_participants WHERE id = :id AND outing_id = :oid');
            $ptStmt->execute([':id' => $partId, ':oid' => $outingId]);
            $pt = $ptStmt->fetch();
            if (!$pt) {
                $error = 'Peserta tidak ditemukan.';
            } else {
                try {
                    $up = upload_payment_proof($_FILES['proof_file'] ?? [], $outingId);
                    $pdo->prepare('
                        INSERT INTO payment_proofs (outing_id, participant_id, user_id, file_path, file_size, amount, note, status, uploaded_by)
                        VALUES (:oid, :pid, :uid, :fp, :fs, :amt, :note, "pending", :ub)
                    ')->execute([
                        ':oid'  => $outingId,
                        ':pid'  => $pt['id'],
                        ':uid'  => $pt['user_id'],
                        ':fp'   => $up['path'],
                        ':fs'   => $up['size'],
                        ':amt'  => max(0, parse_money($_POST['amount'] ?? '0')),
                        ':note' => str_limit(trim($_POST['note'] ?? ''), 255),
                        ':ub'   => current_user()['id'],
                    ]);
                    flash_set('success', 'Bukti pembayaran berhasil diunggah (' . format_filesize($up['size']) . '). Silakan verifikasi untuk menambahkan nominal.');
                    header('Location: ' . $redirectBase . '#participants');
                    exit;
                } catch (Exception $e) {
                    $error = 'Gagal upload bukti: ' . $e->getMessage();
                }
            }
        }

        // --- D. TAMBAH / EDIT RUNDOWN ---
        elseif ($action === 'save_rundown') {
            $rundownId = (int)($_POST['rundown_id'] ?? 0);
            $dayNumber = max(1, (int)($_POST['day_number'] ?? 1));
            $startTime = trim($_POST['start_time'] ?? '');
            $endTime = !empty($_POST['end_time']) ? trim($_POST['end_time']) : null;
            $activity = trim($_POST['activity'] ?? '');
            $location = trim($_POST['location'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            $sortOrder = (int)($_POST['sort_order'] ?? 1);

            if (empty($startTime) || empty($activity)) {
                $error = 'Waktu mulai dan Aktivitas wajib diisi.';
            } else {
                if ($rundownId > 0) {
                    $upR = $pdo->prepare('
                        UPDATE rundown
                        SET day_number = :day, start_time = :st, end_time = :et,
                            activity = :act, location = :loc, description = :desc, sort_order = :ord
                        WHERE id = :id AND outing_id = :oid
                    ');
                    $upR->execute([
                        ':day'  => $dayNumber,
                        ':st'   => $startTime,
                        ':et'   => $endTime,
                        ':act'  => $activity,
                        ':loc'  => $location,
                        ':desc' => $desc,
                        ':ord'  => $sortOrder,
                        ':id'   => $rundownId,
                        ':oid'  => $outingId
                    ]);
                    flash_set('success', 'Jadwal rundown berhasil diperbarui.');
                } else {
                    $insR = $pdo->prepare('
                        INSERT INTO rundown (outing_id, day_number, start_time, end_time, activity, location, description, sort_order)
                        VALUES (:oid, :day, :st, :et, :act, :loc, :desc, :ord)
                    ');
                    $insR->execute([
                        ':oid'  => $outingId,
                        ':day'  => $dayNumber,
                        ':st'   => $startTime,
                        ':et'   => $endTime,
                        ':act'  => $activity,
                        ':loc'  => $location,
                        ':desc' => $desc,
                        ':ord'  => $sortOrder
                    ]);
                    flash_set('success', 'Jadwal agenda baru berhasil ditambahkan ke rundown.');
                }
                header('Location: ' . base_url('admin/outing_detail.php?id=' . $outingId . ($mode === 'edit_history' ? '&mode=edit_history' : '') . '#rundown'));
                exit;
            }
        }

        // --- E. HAPUS RUNDOWN ---
        elseif ($action === 'delete_rundown') {
            $rundownId = (int)($_POST['rundown_id'] ?? 0);
            $delR = $pdo->prepare('DELETE FROM rundown WHERE id = :id AND outing_id = :oid');
            $delR->execute([':id' => $rundownId, ':oid' => $outingId]);

            flash_set('success', 'Agenda rundown berhasil dihapus.');
            header('Location: ' . base_url('admin/outing_detail.php?id=' . $outingId . ($mode === 'edit_history' ? '&mode=edit_history' : '') . '#rundown'));
            exit;
        }

        // --- F. TAMBAH / EDIT PEMBELIAN & UPLOAD BUKTI ---
        elseif ($action === 'save_purchase') {
            $purchId = (int)($_POST['purchase_id'] ?? 0);
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $newCatName = trim($_POST['new_category_name'] ?? '');
            $itemName = trim($_POST['item_name'] ?? '');
            $qty = max(1, (int)($_POST['qty'] ?? 1));
            $unitPrice = (float)str_replace(['.', ','], ['', '.'], $_POST['unit_price'] ?? '0');
            $totalPrice = $qty * $unitPrice; // Hitung ulang di sisi server (wajib mutlak)
            $purchaseDate = trim($_POST['purchase_date'] ?? date('Y-m-d'));
            $notes = trim($_POST['notes'] ?? '');

            // Jika admin menginput kategori baru secara langsung
            if ($categoryId === 0 && !empty($newCatName)) {
                $checkCat = $pdo->prepare('SELECT id FROM categories WHERE name = :name LIMIT 1');
                $checkCat->execute([':name' => $newCatName]);
                $cExist = $checkCat->fetch();
                if ($cExist) {
                    $categoryId = $cExist['id'];
                } else {
                    $insC = $pdo->prepare('INSERT INTO categories (name) VALUES (:name)');
                    $insC->execute([':name' => $newCatName]);
                    $categoryId = $pdo->lastInsertId();
                }
            }

            if (empty($itemName) || $categoryId <= 0) {
                $error = 'Nama Item dan Kategori Pengeluaran wajib diisi.';
            } else {
                $receiptPath = null;
                // Upload Bukti jika disertakan
                if (!empty($_FILES['receipt_file']['name'])) {
                    try {
                        $receiptPath = upload_receipt($_FILES['receipt_file'], $outingId);
                    } catch (Exception $e) {
                        $error = 'Gagal upload bukti: ' . $e->getMessage();
                    }
                }

                if (empty($error)) {
                    if ($purchId > 0) {
                        // Jika upload baru pada edit, ambil file lama untuk dihapus
                        if ($receiptPath) {
                            $oldFileStmt = $pdo->prepare('SELECT receipt_file FROM purchases WHERE id = :id');
                            $oldFileStmt->execute([':id' => $purchId]);
                            $oldFile = $oldFileStmt->fetchColumn();
                            delete_receipt($oldFile);

                            $upPurch = $pdo->prepare('
                                UPDATE purchases
                                SET category_id = :cat, item_name = :item, qty = :qty, unit_price = :up,
                                    total_price = :tp, purchase_date = :pdate, notes = :notes, receipt_file = :rf
                                WHERE id = :id AND outing_id = :oid
                            ');
                            $upPurch->execute([
                                ':cat'   => $categoryId,
                                ':item'  => $itemName,
                                ':qty'   => $qty,
                                ':up'    => $unitPrice,
                                ':tp'    => $totalPrice,
                                ':pdate' => $purchaseDate,
                                ':notes' => $notes,
                                ':rf'    => $receiptPath,
                                ':id'    => $purchId,
                                ':oid'   => $outingId
                            ]);
                        } else {
                            $upPurch = $pdo->prepare('
                                UPDATE purchases
                                SET category_id = :cat, item_name = :item, qty = :qty, unit_price = :up,
                                    total_price = :tp, purchase_date = :pdate, notes = :notes
                                WHERE id = :id AND outing_id = :oid
                            ');
                            $upPurch->execute([
                                ':cat'   => $categoryId,
                                ':item'  => $itemName,
                                ':qty'   => $qty,
                                ':up'    => $unitPrice,
                                ':tp'    => $totalPrice,
                                ':pdate' => $purchaseDate,
                                ':notes' => $notes,
                                ':id'    => $purchId,
                                ':oid'   => $outingId
                            ]);
                        }
                        flash_set('success', 'Data pengeluaran berhasil diperbarui.');
                    } else {
                        $currentUser = current_user();
                        $insPurch = $pdo->prepare('
                            INSERT INTO purchases (outing_id, category_id, item_name, qty, unit_price, total_price, receipt_file, purchase_date, notes, created_by)
                            VALUES (:oid, :cat, :item, :qty, :up, :tp, :rf, :pdate, :notes, :cby)
                        ');
                        $insPurch->execute([
                            ':oid'   => $outingId,
                            ':cat'   => $categoryId,
                            ':item'  => $itemName,
                            ':qty'   => $qty,
                            ':up'    => $unitPrice,
                            ':tp'    => $totalPrice,
                            ':rf'    => $receiptPath,
                            ':pdate' => $purchaseDate,
                            ':notes' => $notes,
                            ':cby'   => $currentUser['id']
                        ]);
                        flash_set('success', 'Data pengeluaran berhasil disimpan.');
                    }
                    header('Location: ' . base_url('admin/outing_detail.php?id=' . $outingId . ($mode === 'edit_history' ? '&mode=edit_history' : '') . '#purchases'));
                    exit;
                }
            }
        }

        // --- G. HAPUS PEMBELIAN ---
        elseif ($action === 'delete_purchase') {
            $purchId = (int)($_POST['purchase_id'] ?? 0);
            $fileStmt = $pdo->prepare('SELECT receipt_file FROM purchases WHERE id = :id AND outing_id = :oid');
            $fileStmt->execute([':id' => $purchId, ':oid' => $outingId]);
            $filePath = $fileStmt->fetchColumn();
            delete_receipt($filePath);

            $delPurch = $pdo->prepare('DELETE FROM purchases WHERE id = :id AND outing_id = :oid');
            $delPurch->execute([':id' => $purchId, ':oid' => $outingId]);

            flash_set('success', 'Transaksi pengeluaran berhasil dihapus.');
            header('Location: ' . base_url('admin/outing_detail.php?id=' . $outingId . ($mode === 'edit_history' ? '&mode=edit_history' : '') . '#purchases'));
            exit;
        }

        // --- H. UBAH CEPAT STATUS OUTING (MISAL: SET SELESAI) ---
        elseif ($action === 'quick_status_update') {
            $newStatus = $_POST['new_status'] ?? 'planning';
            $upStat = $pdo->prepare('UPDATE outings SET status = :status WHERE id = :id');
            $upStat->execute([':status' => $newStatus, ':id' => $outingId]);

            flash_set('success', 'Status outing berhasil diubah menjadi: ' . strtoupper($newStatus));
            header('Location: ' . base_url('admin/outing_detail.php?id=' . $outingId));
            exit;
        }
    }
}

// 2. Query Peserta
$partStmt = $pdo->prepare('
    SELECT p.*, u.user_id, u.nama, u.role
    FROM outing_participants p
    JOIN users u ON p.user_id = u.id
    WHERE p.outing_id = :oid
    ORDER BY u.nama ASC
');
$partStmt->execute([':oid' => $outingId]);
$participants = $partStmt->fetchAll();

// List user yang belum terdaftar di outing ini (untuk form tambah peserta)
$availableUsersStmt = $pdo->prepare('
    SELECT id, user_id, nama 
    FROM users 
    WHERE status = "active" AND id NOT IN (
        SELECT user_id FROM outing_participants WHERE outing_id = :oid
    )
    ORDER BY nama ASC
');
$availableUsersStmt->execute([':oid' => $outingId]);
$availableUsers = $availableUsersStmt->fetchAll();

// 3. Query Rundown
$rundownStmt = $pdo->prepare('
    SELECT * FROM rundown 
    WHERE outing_id = :oid 
    ORDER BY day_number ASC, sort_order ASC, start_time ASC
');
$rundownStmt->execute([':oid' => $outingId]);
$rundowns = $rundownStmt->fetchAll();

// Kelompokkan rundown per hari
$rundownsByDay = [];
foreach ($rundowns as $r) {
    $rundownsByDay[$r['day_number']][] = $r;
}
ksort($rundownsByDay);

// 4. Query Kategori
$categoriesStmt = $pdo->query('SELECT * FROM categories ORDER BY name ASC');
$categories = $categoriesStmt->fetchAll();

// 5. Query Purchases
$purchStmt = $pdo->prepare('
    SELECT pu.*, c.name AS category_name, u.nama AS created_by_name
    FROM purchases pu
    JOIN categories c ON pu.category_id = c.id
    LEFT JOIN users u ON pu.created_by = u.id
    WHERE pu.outing_id = :oid
    ORDER BY pu.purchase_date DESC, pu.id DESC
');
$purchStmt->execute([':oid' => $outingId]);
$purchases = $purchStmt->fetchAll();

// 6. Rekapitulasi per Kategori
$recapStmt = $pdo->prepare('
    SELECT c.name, COUNT(pu.id) AS total_items, COALESCE(SUM(pu.total_price), 0) AS total_amount
    FROM categories c
    JOIN purchases pu ON c.id = pu.category_id AND pu.outing_id = :oid
    GROUP BY c.id, c.name
    ORDER BY total_amount DESC
');
$recapStmt->execute([':oid' => $outingId]);
$categoryRecap = $recapStmt->fetchAll();

// 7. Hitung Statistik Outing Ini
$totalSpent = 0;
foreach ($purchases as $p) {
    $totalSpent += $p['total_price'];
}
$sisaBudget = $outing['budget'] - $totalSpent;

$countPesertaIkut = 0;
$countPesertaBatal = 0;
foreach ($participants as $pt) {
    if ($pt['attendance_status'] === 'ikut') $countPesertaIkut++;
    if ($pt['attendance_status'] === 'batal') $countPesertaBatal++;
}

// 8. Ringkasan Iuran / Pembayaran Peserta
$paySummary = payment_summary($participants, $outing);
$estimatePerPerson = $paySummary['estimate'];
$countSudahBayar = $paySummary['count_sudah'];
$countPartialBayar = $paySummary['count_partial'];
$countBelumBayar = $paySummary['count_belum'];

// 9. Bukti Pembayaran yang diupload member (dikelompokkan per peserta)
$proofsByParticipant = [];
$pendingProofCount = 0;
$proofStmt = $pdo->prepare('SELECT * FROM payment_proofs WHERE outing_id = :oid ORDER BY uploaded_at DESC, id DESC');
$proofStmt->execute([':oid' => $outingId]);
foreach ($proofStmt->fetchAll() as $pf) {
    $proofsByParticipant[$pf['participant_id']][] = $pf;
    if ($pf['status'] === 'pending') $pendingProofCount++;
}

$pageTitle = 'Manajemen: ' . $outing['outing_name'];
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Mode Notice -->
<?php if ($isReadOnly): ?>
    <div class="alert alert-secondary d-flex justify-content-between align-items-center mb-4">
        <div>
            <i class="bi bi-lock-fill fs-5 me-2"></i>
            <strong>Mode Arsip (Read-Only):</strong> Outing ini telah berstatus <strong><?= strtoupper($outing['status']); ?></strong>.
        </div>
        <a href="<?= base_url('admin/outing_detail.php?id=' . $outingId . '&mode=edit_history'); ?>" 
           class="btn btn-warning btn-sm fw-semibold"
           data-confirm="Buka mode Edit History untuk melakukan koreksi data arsip ini?">
            <i class="bi bi-pencil-square me-1"></i> Buka Edit History
        </a>
    </div>
<?php elseif ($mode === 'edit_history'): ?>
    <div class="alert alert-warning d-flex justify-content-between align-items-center mb-4">
        <div>
            <i class="bi bi-exclamation-triangle-fill fs-5 me-2"></i>
            <strong>Mode Koreksi Arsip (Edit History) Aktif:</strong> Perubahan yang Anda lakukan akan langsung disimpan ke database.
        </div>
        <a href="<?= base_url('admin/outing_detail.php?id=' . $outingId . '&mode=view'); ?>" class="btn btn-outline-dark btn-sm">
            <i class="bi bi-check-lg me-1"></i> Selesai Mengedit
        </a>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Header Outing Card -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body p-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center gap-3">
            <div>
                <div class="d-flex align-items-center gap-2 mb-1">
                    <h3 class="fw-bold mb-0"><?= htmlspecialchars($outing['outing_name']); ?></h3>
                    <?= status_badge($outing['status']); ?>
                </div>
                <div class="text-muted">
                    <i class="bi bi-geo-alt-fill text-danger me-1"></i><?= htmlspecialchars($outing['destination']); ?> &bull; 
                    <i class="bi bi-calendar3 ms-1 me-1"></i><?= format_date_id($outing['start_date'], true); ?> s/d <?= format_date_id($outing['end_date'], true); ?>
                </div>
                <?php if (!empty($outing['description'])): ?>
                    <div class="small text-secondary mt-2"><i class="bi bi-info-circle me-1"></i><?= nl2br(htmlspecialchars($outing['description'])); ?></div>
                <?php endif; ?>
            </div>

            <!-- Action Buttons -->
            <div class="d-flex flex-wrap gap-2">
                <a href="<?= base_url('admin/export_pdf.php?id=' . $outingId); ?>" class="btn btn-outline-danger btn-sm" target="_blank">
                    <i class="bi bi-file-earmark-pdf me-1"></i> Unduh PDF Laporan
                </a>
                <a href="<?= base_url('admin/import.php?outing_id=' . $outingId); ?>" class="btn btn-outline-success btn-sm">
                    <i class="bi bi-file-earmark-arrow-up me-1"></i> Import Excel
                </a>
                <?php if (!$isReadOnly): ?>
                    <a href="<?= base_url('admin/outings.php?action=edit&id=' . $outingId); ?>" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-pencil me-1"></i> Edit Info Outing
                    </a>
                    <?php if ($outing['status'] !== 'selesai'): ?>
                        <form method="POST" action="" class="d-inline">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="action" value="quick_status_update">
                            <input type="hidden" name="new_status" value="selesai">
                            <button type="submit" class="btn btn-success btn-sm" data-confirm="Tandai kegiatan ini sebagai Selesai? Outing akan otomatis dipindahkan ke Dashboard History.">
                                <i class="bi bi-check2-all me-1"></i> Set Selesai
                            </button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- KPI Mini Stats -->
        <div class="row g-3 mt-3 pt-3 border-top">
            <div class="col-6 col-md-3">
                <div class="text-muted small">Total Anggaran (Budget)</div>
                <div class="fs-5 fw-bold text-primary"><?= format_rupiah($outing['budget']); ?></div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-muted small">Total Pengeluaran</div>
                <div class="fs-5 fw-bold text-warning"><?= format_rupiah($totalSpent); ?></div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-muted small">Sisa Anggaran</div>
                <div class="fs-5 fw-bold <?= $sisaBudget < 0 ? 'text-danger' : 'text-success'; ?>"><?= format_rupiah($sisaBudget); ?></div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-muted small">Peserta (Ikut / Terdaftar)</div>
                <div class="fs-5 fw-bold text-dark"><?= $countPesertaIkut; ?> / <?= count($participants); ?> Orang</div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-muted small">Estimasi Iuran / Member</div>
                <div class="fs-5 fw-bold text-primary"><?= format_rupiah($estimatePerPerson); ?></div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-muted small">Iuran Terkumpul</div>
                <div class="fs-5 fw-bold text-success"><?= format_rupiah($paySummary['collected']); ?></div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-muted small">Kekurangan Iuran</div>
                <div class="fs-5 fw-bold <?= $paySummary['outstanding'] > 0 ? 'text-danger' : 'text-success'; ?>"><?= format_rupiah($paySummary['outstanding']); ?></div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-muted small">Status Bayar (Lunas / Partial / Belum)</div>
                <div class="fs-5 fw-bold text-dark"><?= $countSudahBayar; ?> / <span class="text-partial"><?= $countPartialBayar; ?></span> / <?= $countBelumBayar; ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Tabs Navigation -->
<ul class="nav nav-pills mb-4 gap-2" id="outingTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview" type="button" role="tab">
            <i class="bi bi-pie-chart me-1"></i> Ringkasan
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="participants-tab" data-bs-toggle="tab" data-bs-target="#participants" type="button" role="tab">
            <i class="bi bi-people me-1"></i> Peserta & Iuran (<?= count($participants); ?>)
            <?php if ($pendingProofCount > 0): ?><span class="badge bg-danger ms-1" title="Bukti bayar menunggu verifikasi"><?= $pendingProofCount; ?></span><?php endif; ?>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="rundown-tab" data-bs-toggle="tab" data-bs-target="#rundown" type="button" role="tab">
            <i class="bi bi-clock me-1"></i> Rundown Acara (<?= count($rundowns); ?>)
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="purchases-tab" data-bs-toggle="tab" data-bs-target="#purchases" type="button" role="tab">
            <i class="bi bi-receipt me-1"></i> Pembelian & Bukti (<?= count($purchases); ?>)
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="categories-tab" data-bs-toggle="tab" data-bs-target="#categories" type="button" role="tab">
            <i class="bi bi-tags me-1"></i> Rekap Kategori
        </button>
    </li>
</ul>

<!-- Tabs Content -->
<div class="tab-content" id="outingTabsContent">

    <!-- ==================== TAB 1: OVERVIEW ==================== -->
    <div class="tab-pane fade show active" id="overview" role="tabpanel">
        <div class="row g-4">
            <div class="col-12 col-md-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header fw-bold"><i class="bi bi-cash-coin me-2 text-primary"></i>Status Keuangan & Budget</div>
                    <div class="card-body">
                        <?php
                            $spentPercent = $outing['budget'] > 0 ? min(100, round(($totalSpent / $outing['budget']) * 100)) : 0;
                        ?>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Realisasi Pengeluaran:</span>
                            <strong><?= $spentPercent; ?>% dari budget</strong>
                        </div>
                        <div class="progress mb-4" style="height: 10px;">
                            <div class="progress-bar <?= $spentPercent > 90 ? 'bg-danger' : ($spentPercent > 70 ? 'bg-warning' : 'bg-primary'); ?>" style="width: <?= $spentPercent; ?>%"></div>
                        </div>

                        <ul class="list-group list-group-flush small">
                            <li class="list-group-item d-flex justify-content-between px-0">
                                <span class="text-muted">Total Alokasi Budget</span>
                                <span class="fw-bold"><?= format_rupiah($outing['budget']); ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between px-0">
                                <span class="text-muted">Total Pengeluaran Realisasi</span>
                                <span class="fw-bold text-warning"><?= format_rupiah($totalSpent); ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between px-0">
                                <span class="text-muted">Sisa Saldo Anggaran</span>
                                <span class="fw-bold <?= $sisaBudget < 0 ? 'text-danger' : 'text-success'; ?>"><?= format_rupiah($sisaBudget); ?></span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header fw-bold"><i class="bi bi-person-check me-2 text-success"></i>Status Kehadiran & Pembayaran</div>
                    <div class="card-body">
                        <div class="row text-center g-2 mb-3">
                            <div class="col-4">
                                <div class="p-2 border rounded bg-light">
                                    <div class="fs-4 fw-bold text-success"><?= $countPesertaIkut; ?></div>
                                    <div class="small text-muted">Ikut</div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="p-2 border rounded bg-light">
                                    <div class="fs-4 fw-bold text-danger"><?= $countPesertaBatal; ?></div>
                                    <div class="small text-muted">Batal</div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="p-2 border rounded bg-light">
                                    <div class="fs-4 fw-bold text-secondary"><?= count($participants) - $countPesertaIkut - $countPesertaBatal; ?></div>
                                    <div class="small text-muted">Tidak Ikut</div>
                                </div>
                            </div>
                        </div>

                        <div class="row text-center g-2">
                            <div class="col-4">
                                <div class="p-2 border rounded bg-light">
                                    <div class="fs-5 fw-bold text-info"><?= $countSudahBayar; ?> Org</div>
                                    <div class="small text-muted">Sudah Bayar</div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="p-2 border rounded bg-light">
                                    <div class="fs-5 fw-bold text-partial"><?= $countPartialBayar; ?> Org</div>
                                    <div class="small text-muted">Partial</div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="p-2 border rounded bg-light">
                                    <div class="fs-5 fw-bold text-warning"><?= $countBelumBayar; ?> Org</div>
                                    <div class="small text-muted">Belum Bayar</div>
                                </div>
                            </div>
                        </div>
                        <?php $collectPercent = $paySummary['target'] > 0 ? min(100, round(($paySummary['collected'] / $paySummary['target']) * 100)) : 0; ?>
                        <div class="d-flex justify-content-between small mt-3 mb-1">
                            <span>Iuran terkumpul <?= format_rupiah($paySummary['collected']); ?> dari <?= format_rupiah($paySummary['target']); ?></span>
                            <strong><?= $collectPercent; ?>%</strong>
                        </div>
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar bg-success" style="width: <?= $collectPercent; ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Daftar Tagihan Iuran (Partial & Belum Bayar) -->
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-2">
                        <span class="fw-bold"><i class="bi bi-receipt-cutoff me-2 text-danger"></i>Daftar Tagihan Iuran (Belum Lunas)</span>
                        <span class="small text-muted">
                            Estimasi / member: <strong class="text-primary"><?= format_rupiah($estimatePerPerson); ?></strong>
                            &bull; Total kekurangan: <strong class="text-danger"><?= format_rupiah($paySummary['outstanding']); ?></strong>
                        </span>
                    </div>
                    <div class="card-body p-0">
                        <?php if ($estimatePerPerson <= 0): ?>
                            <div class="text-center text-muted py-4 small">
                                <i class="bi bi-info-circle me-1"></i>Estimasi iuran per member belum dapat dihitung. Isi <strong>Total Budget</strong> atau <strong>Estimasi Iuran per Member</strong> di menu Edit Info Outing.
                            </div>
                        <?php elseif (empty($paySummary['to_bill'])): ?>
                            <div class="text-center text-success py-4">
                                <i class="bi bi-check-circle-fill me-1"></i>Semua peserta yang ikut sudah lunas.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width: 40px;">No</th>
                                            <th>Nama Peserta</th>
                                            <th>Status</th>
                                            <th class="text-end">Estimasi</th>
                                            <th class="text-end">Sudah Dibayar</th>
                                            <th class="text-end">Perlu Ditagih</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $no = 1; foreach ($paySummary['to_bill'] as $tb): ?>
                                            <tr>
                                                <td><?= $no++; ?></td>
                                                <td class="fw-semibold"><?= htmlspecialchars($tb['nama']); ?> <code class="small fw-normal"><?= htmlspecialchars($tb['user_id']); ?></code></td>
                                                <td><?= payment_badge($tb['payment_status']); ?></td>
                                                <td class="text-end"><?= format_rupiah($estimatePerPerson); ?></td>
                                                <td class="text-end"><?= format_rupiah($tb['amount_paid']); ?></td>
                                                <td class="text-end fw-bold text-danger"><?= format_rupiah($tb['_outstanding']); ?></td>
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
    </div>

    <!-- ==================== TAB 2: PESERTA ==================== -->
    <div class="tab-pane fade" id="participants" role="tabpanel">
        <!-- Ringkasan Iuran -->
        <div class="row g-3 mb-3">
            <div class="col-6 col-lg-3">
                <div class="card h-100 border-0 shadow-sm"><div class="card-body py-3">
                    <div class="text-muted small">Estimasi Iuran / Member</div>
                    <div class="fs-5 fw-bold text-primary"><?= format_rupiah($estimatePerPerson); ?></div>
                    <div class="text-muted" style="font-size:.72rem;">
                        <?= $paySummary['is_manual'] ? 'Diatur manual oleh admin' : 'Otomatis: Budget ÷ ' . $paySummary['count_ikut'] . ' peserta ikut (dibulatkan Rp 1.000)'; ?>
                    </div>
                </div></div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card h-100 border-0 shadow-sm"><div class="card-body py-3">
                    <div class="text-muted small">Target Iuran (<?= $paySummary['count_ikut']; ?> org ikut)</div>
                    <div class="fs-5 fw-bold text-dark"><?= format_rupiah($paySummary['target']); ?></div>
                </div></div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card h-100 border-0 shadow-sm"><div class="card-body py-3">
                    <div class="text-muted small">Iuran Terkumpul</div>
                    <div class="fs-5 fw-bold text-success"><?= format_rupiah($paySummary['collected']); ?></div>
                </div></div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card h-100 border-0 shadow-sm"><div class="card-body py-3">
                    <div class="text-muted small">Kekurangan (Perlu Ditagih)</div>
                    <div class="fs-5 fw-bold <?= $paySummary['outstanding'] > 0 ? 'text-danger' : 'text-success'; ?>"><?= format_rupiah($paySummary['outstanding']); ?></div>
                    <div class="text-muted" style="font-size:.72rem;"><?= count($paySummary['to_bill']); ?> peserta belum lunas</div>
                </div></div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-2">
                <span class="fw-bold">
                    <i class="bi bi-people-fill me-2 text-primary"></i>Daftar Peserta & Pembayaran Iuran
                    <?php if ($pendingProofCount > 0): ?>
                        <span class="badge bg-danger ms-1"><?= $pendingProofCount; ?> bukti baru</span>
                    <?php endif; ?>
                </span>
                <?php if (!$isReadOnly): ?>
                    <div class="d-flex flex-wrap gap-2">
                        <form method="POST" action="" class="d-inline">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="action" value="recalc_payment_status">
                            <button type="submit" class="btn btn-outline-secondary btn-sm"
                                    data-confirm="Hitung ulang status pembayaran SEMUA peserta berdasarkan nominal dibayar vs estimasi per member (<?= format_rupiah($estimatePerPerson); ?>)? Status yang diatur manual akan ditimpa."
                                    title="Sinkronkan status Belum/Partial/Sudah dengan nominal dibayar">
                                <i class="bi bi-arrow-repeat me-1"></i> Hitung Ulang Status
                            </button>
                        </form>
                        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addParticipantModal">
                            <i class="bi bi-person-plus me-1"></i> Tambah Peserta
                        </button>
                    </div>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <?php if (empty($participants)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-people fs-1"></i>
                        <p class="mt-2">Belum ada peserta yang ditambahkan ke kegiatan ini.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 40px;">No</th>
                                    <th>Nama Peserta</th>
                                    <th>Kehadiran</th>
                                    <th>Pembayaran</th>
                                    <th class="text-end">Sudah Dibayar</th>
                                    <th class="text-end">Kekurangan</th>
                                    <th class="text-center">Bukti Bayar</th>
                                    <th>Catatan</th>
                                    <?php if (!$isReadOnly): ?>
                                        <th class="text-center" style="width: 110px;">Aksi</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no = 1; foreach ($participants as $pt): ?>
                                    <?php
                                        $ptProofs = $proofsByParticipant[$pt['id']] ?? [];
                                        $ptPending = 0;
                                        foreach ($ptProofs as $pf) { if ($pf['status'] === 'pending') $ptPending++; }
                                        $kurang = participant_outstanding($pt, $estimatePerPerson);
                                        $lebih = (float)$pt['amount_paid'] - $estimatePerPerson;
                                    ?>
                                    <tr class="<?= $kurang > 0 && $pt['payment_status'] === 'partial' ? 'row-partial' : ''; ?>">
                                        <td><?= $no++; ?></td>
                                        <td>
                                            <div class="fw-semibold"><?= htmlspecialchars($pt['nama']); ?></div>
                                            <code class="small"><?= htmlspecialchars($pt['user_id']); ?></code>
                                        </td>
                                        <td><?= attendance_badge($pt['attendance_status']); ?></td>
                                        <td><?= payment_badge($pt['payment_status']); ?></td>
                                        <td class="text-end fw-semibold"><?= format_rupiah($pt['amount_paid']); ?></td>
                                        <td class="text-end">
                                            <?php if ($kurang > 0): ?>
                                                <span class="fw-bold text-danger"><?= format_rupiah($kurang); ?></span>
                                            <?php elseif ($pt['attendance_status'] === 'ikut' && $estimatePerPerson > 0 && $lebih > 0): ?>
                                                <span class="small text-success">Lebih <?= format_rupiah($lebih); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-sm <?= $ptPending > 0 ? 'btn-danger' : 'btn-outline-secondary'; ?>"
                                                    data-bs-toggle="modal" data-bs-target="#proofModal<?= $pt['id']; ?>" title="Lihat bukti pembayaran">
                                                <i class="bi bi-images me-1"></i><?= count($ptProofs); ?>
                                                <?php if ($ptPending > 0): ?><span class="badge bg-white text-danger ms-1"><?= $ptPending; ?> baru</span><?php endif; ?>
                                            </button>
                                        </td>
                                        <td class="small text-muted"><?= htmlspecialchars($pt['notes'] ?? '-'); ?></td>
                                        <?php if (!$isReadOnly): ?>
                                            <td class="text-center text-nowrap">
                                                <button type="button" class="btn btn-outline-primary btn-sm me-1" data-bs-toggle="modal" data-bs-target="#editPartModal<?= $pt['id']; ?>" title="Edit Status & Pembayaran">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <form method="POST" action="" class="d-inline">
                                                    <?= csrf_field(); ?>
                                                    <input type="hidden" name="action" value="delete_participant">
                                                    <input type="hidden" name="participant_id" value="<?= $pt['id']; ?>">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="Hapus peserta <?= htmlspecialchars($pt['nama']); ?> dari outing ini? Bukti pembayaran miliknya juga akan dihapus." title="Hapus">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <th colspan="4" class="text-end">TOTAL:</th>
                                    <th class="text-end text-success"><?= format_rupiah($paySummary['collected']); ?></th>
                                    <th class="text-end text-danger"><?= format_rupiah($paySummary['outstanding']); ?></th>
                                    <th colspan="<?= $isReadOnly ? 2 : 3; ?>"></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php foreach ($participants as $pt): ?>
            <?php
                $ptProofs = $proofsByParticipant[$pt['id']] ?? [];
                $autoStatus = resolve_payment_status($pt['amount_paid'], $estimatePerPerson);
                $statusIsAuto = ($pt['payment_status'] === $autoStatus);
            ?>
            <?php if (!$isReadOnly): ?>
                <!-- Modal Edit Peserta & Pembayaran -->
                <div class="modal fade" id="editPartModal<?= $pt['id']; ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Ubah Status: <?= htmlspecialchars($pt['nama']); ?></h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <form method="POST" action="" data-pay-form data-estimate="<?= (float)$estimatePerPerson; ?>">
                                <?= csrf_field(); ?>
                                <input type="hidden" name="action" value="update_participant_status">
                                <input type="hidden" name="participant_id" value="<?= $pt['id']; ?>">
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Status Kehadiran</label>
                                        <select name="attendance_status" class="form-select">
                                            <option value="ikut" <?= $pt['attendance_status'] === 'ikut' ? 'selected' : ''; ?>>Ikut</option>
                                            <option value="batal" <?= $pt['attendance_status'] === 'batal' ? 'selected' : ''; ?>>Batal</option>
                                            <option value="tidak_ikut" <?= $pt['attendance_status'] === 'tidak_ikut' ? 'selected' : ''; ?>>Tidak Ikut</option>
                                        </select>
                                    </div>
                                    <div class="alert alert-light border small py-2 mb-3">
                                        <i class="bi bi-calculator me-1"></i>Estimasi iuran per member: <strong><?= format_rupiah($estimatePerPerson); ?></strong>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Budget / Iuran Sudah Dibayar (Rp)</label>
                                        <div class="input-group">
                                            <span class="input-group-text">Rp</span>
                                            <input type="number" name="amount_paid" class="form-control" min="0" step="1" value="<?= (int)round($pt['amount_paid']); ?>">
                                        </div>
                                        <div class="d-flex flex-wrap gap-1 mt-2">
                                            <?php if ($estimatePerPerson > 0): ?>
                                                <button type="button" class="btn btn-outline-success btn-sm py-0" data-fill-amount="<?= (int)$estimatePerPerson; ?>">Lunas (<?= format_rupiah($estimatePerPerson); ?>)</button>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-outline-secondary btn-sm py-0" data-fill-amount="0">Reset 0</button>
                                        </div>
                                        <div class="form-text">Total akumulasi yang sudah diterima dari peserta ini.</div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Status Pembayaran</label>
                                        <select name="payment_status" class="form-select">
                                            <option value="auto" <?= $statusIsAuto ? 'selected' : ''; ?>>Otomatis dari nominal &rarr; <?= strtoupper($autoStatus); ?></option>
                                            <option value="belum" <?= !$statusIsAuto && $pt['payment_status'] === 'belum' ? 'selected' : ''; ?>>Belum Bayar (manual)</option>
                                            <option value="partial" <?= !$statusIsAuto && $pt['payment_status'] === 'partial' ? 'selected' : ''; ?>>Partial (manual)</option>
                                            <option value="sudah" <?= !$statusIsAuto && $pt['payment_status'] === 'sudah' ? 'selected' : ''; ?>>Sudah Bayar / Lunas (manual)</option>
                                        </select>
                                        <div class="form-text">Otomatis: 0 = Belum, kurang dari estimasi = Partial, &ge; estimasi = Sudah.</div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Catatan</label>
                                        <input type="text" name="notes" class="form-control" value="<?= htmlspecialchars($pt['notes'] ?? ''); ?>" placeholder="Contoh: Titip di bus 1, alergi seafood...">
                                        <div class="form-text text-warning"><i class="bi bi-eye me-1"></i>Catatan terlihat oleh peserta lain, jangan tulis nominal di sini.</div>
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
            <?php endif; ?>

            <!-- Modal Bukti Pembayaran Peserta -->
            <div class="modal fade" id="proofModal<?= $pt['id']; ?>" tabindex="-1">
                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-images me-2"></i>Bukti Bayar: <?= htmlspecialchars($pt['nama']); ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="d-flex flex-wrap gap-3 small mb-3 p-2 bg-light rounded border">
                                <div>Status: <?= payment_badge($pt['payment_status']); ?></div>
                                <div>Dibayar: <strong><?= format_rupiah($pt['amount_paid']); ?></strong></div>
                                <div>Estimasi: <strong><?= format_rupiah($estimatePerPerson); ?></strong></div>
                                <div>Kekurangan: <strong class="text-danger"><?= format_rupiah(participant_outstanding($pt, $estimatePerPerson)); ?></strong></div>
                            </div>

                            <?php if (empty($ptProofs)): ?>
                                <div class="text-center text-muted py-4">
                                    <i class="bi bi-image fs-1"></i>
                                    <p class="mb-0 mt-2">Peserta belum mengunggah bukti pembayaran.</p>
                                </div>
                            <?php else: ?>
                                <div class="row g-3">
                                    <?php foreach ($ptProofs as $pf): ?>
                                        <?php $pfUrl = base_url('member/view_payment_proof.php?id=' . $pf['id']); $isPdf = strtolower(pathinfo($pf['file_path'], PATHINFO_EXTENSION)) === 'pdf'; ?>
                                        <div class="col-12 col-md-6">
                                            <div class="border rounded p-2 h-100 d-flex flex-column">
                                                <a href="<?= $pfUrl; ?>" target="_blank" class="proof-thumb mb-2">
                                                    <?php if ($isPdf): ?>
                                                        <div class="text-center py-4 text-danger"><i class="bi bi-file-earmark-pdf fs-1"></i><div class="small">Buka PDF</div></div>
                                                    <?php else: ?>
                                                        <img src="<?= $pfUrl; ?>" loading="lazy" alt="Bukti pembayaran">
                                                    <?php endif; ?>
                                                </a>
                                                <div class="small d-flex justify-content-between align-items-center mb-1">
                                                    <?= proof_status_badge($pf['status']); ?>
                                                    <span class="text-muted"><?= date('d/m/Y H:i', strtotime($pf['uploaded_at'])); ?> &bull; <?= format_filesize($pf['file_size']); ?></span>
                                                </div>
                                                <div class="small">Nominal ditransfer: <strong><?= (float)$pf['amount'] > 0 ? format_rupiah($pf['amount']) : '-'; ?></strong></div>
                                                <?php if (!empty($pf['note'])): ?>
                                                    <div class="small text-muted">Catatan: <?= htmlspecialchars($pf['note']); ?></div>
                                                <?php endif; ?>
                                                <?php if (!$isReadOnly): ?>
                                                    <div class="d-flex flex-wrap gap-1 mt-auto pt-2">
                                                        <?php if ($pf['status'] !== 'verified'): ?>
                                                            <?php if ((float)$pf['amount'] > 0): ?>
                                                                <form method="POST" action="" class="d-inline">
                                                                    <?= csrf_field(); ?>
                                                                    <input type="hidden" name="action" value="verify_proof">
                                                                    <input type="hidden" name="proof_id" value="<?= $pf['id']; ?>">
                                                                    <input type="hidden" name="add_amount" value="1">
                                                                    <button type="submit" class="btn btn-success btn-sm" data-confirm="Verifikasi bukti ini dan tambahkan <?= format_rupiah($pf['amount']); ?> ke total dibayar <?= htmlspecialchars($pt['nama']); ?>?">
                                                                        <i class="bi bi-check2-circle me-1"></i>Verifikasi +<?= format_rupiah($pf['amount']); ?>
                                                                    </button>
                                                                </form>
                                                            <?php endif; ?>
                                                            <form method="POST" action="" class="d-inline">
                                                                <?= csrf_field(); ?>
                                                                <input type="hidden" name="action" value="verify_proof">
                                                                <input type="hidden" name="proof_id" value="<?= $pf['id']; ?>">
                                                                <button type="submit" class="btn btn-outline-success btn-sm" title="Tandai valid tanpa mengubah nominal dibayar">
                                                                    <i class="bi bi-check2"></i> Verifikasi saja
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>
                                                        <?php if ($pf['status'] === 'pending'): ?>
                                                            <form method="POST" action="" class="d-inline">
                                                                <?= csrf_field(); ?>
                                                                <input type="hidden" name="action" value="reject_proof">
                                                                <input type="hidden" name="proof_id" value="<?= $pf['id']; ?>">
                                                                <button type="submit" class="btn btn-outline-warning btn-sm" data-confirm="Tolak bukti pembayaran ini?">
                                                                    <i class="bi bi-x-circle"></i> Tolak
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>
                                                        <form method="POST" action="" class="d-inline ms-auto">
                                                            <?= csrf_field(); ?>
                                                            <input type="hidden" name="action" value="delete_proof">
                                                            <input type="hidden" name="proof_id" value="<?= $pf['id']; ?>">
                                                            <button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="Hapus file bukti pembayaran ini? Nominal dibayar peserta TIDAK berubah otomatis." title="Hapus">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!$isReadOnly): ?>
                                <hr>
                                <h6 class="fw-bold"><i class="bi bi-cloud-upload me-1"></i>Upload Bukti atas Nama Peserta</h6>
                                <form method="POST" action="" enctype="multipart/form-data" class="row g-2 align-items-end">
                                    <?= csrf_field(); ?>
                                    <input type="hidden" name="action" value="admin_upload_proof">
                                    <input type="hidden" name="participant_id" value="<?= $pt['id']; ?>">
                                    <div class="col-12 col-md-5">
                                        <label class="form-label small mb-1">File (foto otomatis dikompres)</label>
                                        <input type="file" name="proof_file" class="form-control form-control-sm" accept="image/jpeg,image/png,image/webp,application/pdf" data-compress-image required>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label class="form-label small mb-1">Nominal (Rp)</label>
                                        <input type="number" name="amount" class="form-control form-control-sm" min="0" step="1" placeholder="0">
                                    </div>
                                    <div class="col-6 col-md-4">
                                        <label class="form-label small mb-1">Catatan</label>
                                        <input type="text" name="note" class="form-control form-control-sm" maxlength="255" placeholder="Transfer BCA / Cash">
                                    </div>
                                    <div class="col-12"><div class="js-compress-info small text-muted"></div></div>
                                    <div class="col-12 text-end">
                                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-upload me-1"></i>Upload Bukti</button>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if (!$isReadOnly): ?>
            <!-- Modal Tambah Peserta -->
            <div class="modal fade" id="addParticipantModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Tambah Peserta ke Outing</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <form method="POST" action="" data-pay-form data-estimate="<?= (float)$estimatePerPerson; ?>">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="action" value="add_participant">
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Pilih Pengguna / Pegawai</label>
                                    <select name="user_id" class="form-select" required>
                                        <option value="">-- Pilih User --</option>
                                        <?php foreach ($availableUsers as $au): ?>
                                            <option value="<?= $au['id']; ?>"><?= htmlspecialchars($au['nama']); ?> (<?= htmlspecialchars($au['user_id']); ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">Hanya menampilkan pengguna aktif yang belum terdaftar di outing ini.</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Status Kehadiran Awal</label>
                                    <select name="attendance_status" class="form-select">
                                        <option value="ikut" selected>Ikut</option>
                                        <option value="batal">Batal</option>
                                        <option value="tidak_ikut">Tidak Ikut</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Budget / Iuran Sudah Dibayar (Rp)</label>
                                    <div class="input-group">
                                        <span class="input-group-text">Rp</span>
                                        <input type="number" name="amount_paid" class="form-control" min="0" step="1" value="0">
                                    </div>
                                    <div class="form-text">Estimasi iuran per member saat ini: <?= format_rupiah($estimatePerPerson); ?></div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Status Pembayaran</label>
                                    <select name="payment_status" class="form-select">
                                        <option value="auto" selected>Otomatis dari nominal (Rekomendasi)</option>
                                        <option value="belum">Belum Bayar (manual)</option>
                                        <option value="partial">Partial (manual)</option>
                                        <option value="sudah">Sudah Bayar / Lunas (manual)</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Catatan Khusus</label>
                                    <input type="text" name="notes" class="form-control" placeholder="Contoh: No Kursi, Permintaan khusus...">
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                                <button type="submit" class="btn btn-primary btn-sm">Tambahkan Peserta</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- ==================== TAB 3: RUNDOWN ==================== -->
    <div class="tab-pane fade" id="rundown" role="tabpanel">
        <div class="card shadow-sm">
            <div class="card-header d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-2">
                <span class="fw-bold"><i class="bi bi-calendar-range me-2 text-primary"></i>Susunan Rundown Acara</span>
                <?php if (!$isReadOnly): ?>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addRundownModal">
                        <i class="bi bi-plus-lg me-1"></i> Tambah Agenda
                    </button>
                <?php endif; ?>
            </div>
            <div class="card-body p-3">
                <?php if (empty($rundownsByDay)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-clock-history fs-1"></i>
                        <p class="mt-2">Belum ada susunan rundown untuk kegiatan ini.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($rundownsByDay as $dayNum => $items): ?>
                        <div class="mb-4">
                            <h5 class="fw-bold text-primary border-bottom pb-2 mb-3">
                                <i class="bi bi-flag-fill me-2"></i>Hari Ke-<?= $dayNum; ?>
                            </h5>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width: 50px;">Urut</th>
                                            <th style="width: 140px;">Waktu</th>
                                            <th>Aktivitas</th>
                                            <th>Lokasi</th>
                                            <th>Keterangan</th>
                                            <?php if (!$isReadOnly): ?>
                                                <th class="text-center" style="width: 110px;">Aksi</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($items as $item): ?>
                                            <tr>
                                                <td class="text-muted fw-bold"><?= $item['sort_order']; ?></td>
                                                <td class="fw-semibold text-primary">
                                                    <?= date('H:i', strtotime($item['start_time'])); ?>
                                                    <?= !empty($item['end_time']) ? ' - ' . date('H:i', strtotime($item['end_time'])) : ''; ?>
                                                </td>
                                                <td class="fw-bold"><?= htmlspecialchars($item['activity']); ?></td>
                                                <td><small class="text-muted"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($item['location'] ?? '-'); ?></small></td>
                                                <td class="small"><?= htmlspecialchars($item['description'] ?? '-'); ?></td>
                                                <?php if (!$isReadOnly): ?>
                                                    <td class="text-center">
                                                        <button type="button" class="btn btn-outline-primary btn-sm me-1" data-bs-toggle="modal" data-bs-target="#editRundownModal<?= $item['id']; ?>" title="Edit">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <form method="POST" action="" class="d-inline">
                                                            <?= csrf_field(); ?>
                                                            <input type="hidden" name="action" value="delete_rundown">
                                                            <input type="hidden" name="rundown_id" value="<?= $item['id']; ?>">
                                                            <button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="Hapus agenda ini dari rundown?" title="Hapus">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </form>

                                                        <!-- Modal Edit Rundown -->
                                                        <div class="modal fade text-start" id="editRundownModal<?= $item['id']; ?>" tabindex="-1">
                                                            <div class="modal-dialog">
                                                                <div class="modal-content">
                                                                    <div class="modal-header">
                                                                        <h5 class="modal-title">Edit Agenda Rundown</h5>
                                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                                    </div>
                                                                    <form method="POST" action="">
                                                                        <?= csrf_field(); ?>
                                                                        <input type="hidden" name="action" value="save_rundown">
                                                                        <input type="hidden" name="rundown_id" value="<?= $item['id']; ?>">
                                                                        <div class="modal-body">
                                                                            <div class="row g-2 mb-3">
                                                                                <div class="col-6">
                                                                                    <label class="form-label fw-semibold">Hari Ke-</label>
                                                                                    <input type="number" name="day_number" class="form-control" min="1" value="<?= $item['day_number']; ?>" required>
                                                                                </div>
                                                                                <div class="col-6">
                                                                                    <label class="form-label fw-semibold">Nomor Urut</label>
                                                                                    <input type="number" name="sort_order" class="form-control" min="1" value="<?= $item['sort_order']; ?>" required>
                                                                                </div>
                                                                            </div>
                                                                            <div class="row g-2 mb-3">
                                                                                <div class="col-6">
                                                                                    <label class="form-label fw-semibold">Jam Mulai</label>
                                                                                    <input type="time" name="start_time" class="form-control" value="<?= substr($item['start_time'], 0, 5); ?>" required>
                                                                                </div>
                                                                                <div class="col-6">
                                                                                    <label class="form-label fw-semibold">Jam Selesai</label>
                                                                                    <input type="time" name="end_time" class="form-control" value="<?= !empty($item['end_time']) ? substr($item['end_time'], 0, 5) : ''; ?>">
                                                                                </div>
                                                                            </div>
                                                                            <div class="mb-3">
                                                                                <label class="form-label fw-semibold">Aktivitas / Agenda</label>
                                                                                <input type="text" name="activity" class="form-control" value="<?= htmlspecialchars($item['activity']); ?>" required>
                                                                            </div>
                                                                            <div class="mb-3">
                                                                                <label class="form-label fw-semibold">Lokasi</label>
                                                                                <input type="text" name="location" class="form-control" value="<?= htmlspecialchars($item['location'] ?? ''); ?>">
                                                                            </div>
                                                                            <div class="mb-3">
                                                                                <label class="form-label fw-semibold">Keterangan Tambahan</label>
                                                                                <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars($item['description'] ?? ''); ?></textarea>
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
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$isReadOnly): ?>
            <!-- Modal Tambah Rundown -->
            <div class="modal fade" id="addRundownModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-clock-history me-2"></i>Tambah Agenda Rundown</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <form method="POST" action="">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="action" value="save_rundown">
                            <input type="hidden" name="rundown_id" value="0">
                            <div class="modal-body">
                                <div class="row g-2 mb-3">
                                    <div class="col-6">
                                        <label class="form-label fw-semibold">Hari Ke-</label>
                                        <input type="number" name="day_number" class="form-control" min="1" value="1" required>
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label fw-semibold">Nomor Urut</label>
                                        <input type="number" name="sort_order" class="form-control" min="1" value="<?= count($rundowns) + 1; ?>" required>
                                    </div>
                                </div>
                                <div class="row g-2 mb-3">
                                    <div class="col-6">
                                        <label class="form-label fw-semibold">Jam Mulai</label>
                                        <input type="time" name="start_time" class="form-control" required>
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label fw-semibold">Jam Selesai</label>
                                        <input type="time" name="end_time" class="form-control">
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Aktivitas / Kegiatan <span class="text-danger">*</span></label>
                                    <input type="text" name="activity" class="form-control" placeholder="Contoh: Kumpul di Lobby, Ice Breaking..." required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Lokasi Kegiatan</label>
                                    <input type="text" name="location" class="form-control" placeholder="Contoh: Lapangan Utama, Resto...">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Keterangan / Detail</label>
                                    <textarea name="description" class="form-control" rows="2" placeholder="Detail perlengkapan, dress code, penanggung jawab..."></textarea>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                                <button type="submit" class="btn btn-primary btn-sm">Tambahkan Agenda</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- ==================== TAB 4: PEMBELIAN & PENGELUARAN ==================== -->
    <div class="tab-pane fade" id="purchases" role="tabpanel">
        <div class="card shadow-sm">
            <div class="card-header d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-2">
                <span class="fw-bold"><i class="bi bi-cart-check-fill me-2 text-warning"></i>Catatan Pengeluaran & Bukti Pembelian</span>
                <?php if (!$isReadOnly): ?>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addPurchaseModal">
                        <i class="bi bi-plus-lg me-1"></i> Input Pembelian Baru
                    </button>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <?php if (empty($purchases)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-cash-stack fs-1"></i>
                        <p class="mt-2">Belum ada catatan pembelian/pengeluaran untuk kegiatan ini.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 40px;">No</th>
                                    <th>Tanggal</th>
                                    <th>Kategori</th>
                                    <th>Nama Barang / Jasa</th>
                                    <th class="text-center">Qty</th>
                                    <th class="text-end">Harga Satuan</th>
                                    <th class="text-end">Total Harga</th>
                                    <th class="text-center">Bukti Bayar</th>
                                    <?php if (!$isReadOnly): ?>
                                        <th class="text-center" style="width: 100px;">Aksi</th>
                                    <?php endif; ?>
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
                                            <div class="fw-bold"><?= htmlspecialchars($pu['item_name']); ?></div>
                                            <?php if (!empty($pu['notes'])): ?>
                                                <small class="text-muted"><?= htmlspecialchars($pu['notes']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center fw-semibold"><?= $pu['qty']; ?></td>
                                        <td class="text-end"><?= format_rupiah($pu['unit_price']); ?></td>
                                        <td class="text-end fw-bold text-primary"><?= format_rupiah($pu['total_price']); ?></td>
                                        <td class="text-center">
                                            <?php if (!empty($pu['receipt_file'])): ?>
                                                <button type="button" class="btn btn-outline-info btn-sm" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#receiptPreviewModal" 
                                                        data-file-url="<?= base_url($pu['receipt_file']); ?>" 
                                                        data-file-type="<?= $ext; ?>"
                                                        title="Lihat Bukti">
                                                    <i class="bi bi-file-earmark-<?= $ext === 'pdf' ? 'pdf' : 'image'; ?>"></i> Bukti
                                                </button>
                                            <?php else: ?>
                                                <span class="text-muted small">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <?php if (!$isReadOnly): ?>
                                            <td class="text-center">
                                                <form method="POST" action="" class="d-inline">
                                                    <?= csrf_field(); ?>
                                                    <input type="hidden" name="action" value="delete_purchase">
                                                    <input type="hidden" name="purchase_id" value="<?= $pu['id']; ?>">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="Hapus transaksi '<?= htmlspecialchars($pu['item_name']); ?>'?" title="Hapus">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <th colspan="6" class="text-end fw-bold">TOTAL PENGELUARAN:</th>
                                    <th class="text-end fw-bold fs-6 text-primary"><?= format_rupiah($totalSpent); ?></th>
                                    <th colspan="<?= $isReadOnly ? 1 : 2; ?>"></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$isReadOnly): ?>
            <!-- Modal Tambah Pembelian -->
            <div class="modal fade" id="addPurchaseModal" tabindex="-1">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-cart-plus me-2"></i>Catat Pengeluaran / Pembelian Baru</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <form method="POST" action="" enctype="multipart/form-data">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="action" value="save_purchase">
                            <input type="hidden" name="purchase_id" value="0">
                            <div class="modal-body">
                                <div class="row g-3 mb-3">
                                    <div class="col-12 col-md-6">
                                        <label class="form-label fw-semibold">Kategori Pengeluaran <span class="text-danger">*</span></label>
                                        <select name="category_id" class="form-select" id="purchaseCategorySelect" required>
                                            <option value="">-- Pilih Kategori --</option>
                                            <?php foreach ($categories as $cat): ?>
                                                <option value="<?= $cat['id']; ?>"><?= htmlspecialchars($cat['name']); ?></option>
                                            <?php endforeach; ?>
                                            <option value="0">+ Tambah Kategori Baru Manual...</option>
                                        </select>
                                    </div>
                                    <div class="col-12 col-md-6" id="newCategoryWrapper" style="display: none;">
                                        <label class="form-label fw-semibold">Nama Kategori Baru</label>
                                        <input type="text" name="new_category_name" class="form-control" placeholder="Contoh: Tiket Kapal, Dokumentasi...">
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <label class="form-label fw-semibold">Tanggal Pembelian <span class="text-danger">*</span></label>
                                        <input type="date" name="purchase_date" class="form-control" value="<?= date('Y-m-d'); ?>" required>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Nama Item / Barang / Jasa <span class="text-danger">*</span></label>
                                    <input type="text" name="item_name" class="form-control" placeholder="Contoh: Sewa Big Bus 50 Seat, Makan Siang Prasmanan..." required>
                                </div>

                                <!-- Auto-Calculation Section (Vanilla JS) -->
                                <div class="row g-3 mb-3">
                                    <div class="col-12 col-md-4">
                                        <label class="form-label fw-semibold">Kuantitas (Qty) <span class="text-danger">*</span></label>
                                        <input type="number" name="qty" id="calc_qty" class="form-control" min="1" value="1" required>
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <label class="form-label fw-semibold">Harga Satuan (Rp) <span class="text-danger">*</span></label>
                                        <input type="number" name="unit_price" id="calc_unit_price" class="form-control" min="0" step="100" placeholder="0" required>
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <label class="form-label fw-semibold">Total Harga (Otomatis)</label>
                                        <input type="hidden" name="total_price" id="calc_total_price" value="0">
                                        <div class="form-control bg-light fw-bold text-primary" id="calc_total_display">Rp 0</div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Upload Bukti Pembayaran / Struk (Opsional)</label>
                                    <input type="file" name="receipt_file" class="form-control" accept=".jpg,.jpeg,.png,.pdf">
                                    <div class="form-text">Mendukung format gambar JPG, PNG, atau dokumen PDF (Maks. 5 MB).</div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Catatan Tambahan</label>
                                    <textarea name="notes" class="form-control" rows="2" placeholder="Keterangan toko, nomor invoice, dll."></textarea>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                                <button type="submit" class="btn btn-primary btn-sm">Simpan Pengeluaran</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const catSelect = document.getElementById('purchaseCategorySelect');
                    const newCatWrap = document.getElementById('newCategoryWrapper');
                    if (catSelect && newCatWrap) {
                        catSelect.addEventListener('change', function() {
                            if (this.value === '0') {
                                newCatWrap.style.display = 'block';
                            } else {
                                newCatWrap.style.display = 'none';
                            }
                        });
                    }
                });
            </script>
        <?php endif; ?>
    </div>

    <!-- ==================== TAB 5: REKAP KATEGORI ==================== -->
    <div class="tab-pane fade" id="categories" role="tabpanel">
        <div class="card shadow-sm">
            <div class="card-header fw-bold">
                <i class="bi bi-pie-chart-fill me-2 text-primary"></i>Rekapitulasi Pengeluaran Berdasarkan Kategori
            </div>
            <div class="card-body p-0">
                <?php if (empty($categoryRecap)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-tag fs-1"></i>
                        <p class="mt-2">Belum ada transaksi pengeluaran pada kegiatan outing ini.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 50px;">No</th>
                                    <th>Nama Kategori</th>
                                    <th class="text-center">Jumlah Transaksi</th>
                                    <th class="text-end">Total Biaya</th>
                                    <th style="width: 250px;">Proporsi Terhadap Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no = 1; foreach ($categoryRecap as $cr): ?>
                                    <?php
                                        $prop = $totalSpent > 0 ? round(($cr['total_amount'] / $totalSpent) * 100, 1) : 0;
                                    ?>
                                    <tr>
                                        <td><?= $no++; ?></td>
                                        <td class="fw-bold"><?= htmlspecialchars($cr['name']); ?></td>
                                        <td class="text-center"><?= $cr['total_items']; ?> item</td>
                                        <td class="text-end fw-bold"><?= format_rupiah($cr['total_amount']); ?></td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="progress flex-grow-1" style="height: 8px;">
                                                    <div class="progress-bar bg-info" style="width: <?= $prop; ?>%"></div>
                                                </div>
                                                <span class="small fw-semibold" style="width: 45px; text-align: right;"><?= $prop; ?>%</span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <th colspan="3" class="text-end fw-bold">TOTAL KESELURUHAN:</th>
                                    <th class="text-end fw-bold text-primary"><?= format_rupiah($totalSpent); ?></th>
                                    <th>100%</th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
