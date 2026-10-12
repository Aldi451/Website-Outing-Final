<?php
/**
 * Global Helper Functions
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Mendapatkan Base URL aplikasi secara dinamis
 * @param string $path
 * @return string
 */
function base_url($path = '') {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    
    // Tentukan root path aplikasi dari posisi folder public_html
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
    // Normalisasi jika di dalam subfolder admin, member, atau api
    $appRoot = preg_replace('/(\/(admin|member|api|config))$/', '', $scriptDir);
    $appRoot = rtrim($appRoot, '/');
    
    $cleanPath = ltrim($path, '/');
    return $protocol . $host . $appRoot . ($cleanPath ? '/' . $cleanPath : '');
}

/**
 * Sanitasi string input
 * @param mixed $data
 * @return string
 */
function sanitize($data) {
    if (is_array($data)) {
        return array_map('sanitize', $data);
    }
    return htmlspecialchars(trim((string)$data), ENT_QUOTES, 'UTF-8');
}

/**
 * Format angka ke format mata uang Rupiah (Rp xx.xxx.xxx)
 * @param float|int $number
 * @return string
 */
function format_rupiah($number) {
    return 'Rp ' . number_format((float)$number, 0, ',', '.');
}

/**
 * Format tanggal Y-m-d ke Bahasa Indonesia
 * @param string $date
 * @param bool $withDay
 * @return string
 */
function format_date_id($date, $withDay = false) {
    if (empty($date) || $date === '0000-00-00') return '-';
    $timestamp = strtotime($date);
    if (!$timestamp) return '-';

    $hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $bulan = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];

    $d = date('j', $timestamp);
    $m = (int)date('n', $timestamp);
    $y = date('Y', $timestamp);
    $dayName = $hari[date('w', $timestamp)];

    $result = $d . ' ' . ($bulan[$m] ?? '') . ' ' . $y;
    return $withDay ? $dayName . ', ' . $result : $result;
}

/**
 * Format waktu H:i:s ke H:i WIB
 * @param string $time
 * @return string
 */
function format_time($time) {
    if (empty($time)) return '-';
    return date('H:i', strtotime($time)) . ' WIB';
}

/**
 * Badge status outing
 * @param string $status
 * @return string
 */
function status_badge($status) {
    switch (strtolower($status)) {
        case 'active':
            return '<span class="badge bg-success"><i class="bi bi-play-circle me-1"></i>Active</span>';
        case 'planning':
            return '<span class="badge bg-warning text-dark"><i class="bi bi-calendar2-range me-1"></i>Planning</span>';
        case 'selesai':
            return '<span class="badge bg-primary"><i class="bi bi-check-circle me-1"></i>Selesai</span>';
        case 'cancelled':
            return '<span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Cancelled</span>';
        default:
            return '<span class="badge bg-secondary">' . htmlspecialchars(ucfirst($status)) . '</span>';
    }
}

/**
 * Badge status kehadiran peserta
 * @param string $status
 * @return string
 */
function attendance_badge($status) {
    switch (strtolower($status)) {
        case 'ikut':
            return '<span class="badge bg-success"><i class="bi bi-check-lg me-1"></i>Ikut</span>';
        case 'batal':
            return '<span class="badge bg-danger"><i class="bi bi-x-lg me-1"></i>Batal</span>';
        case 'tidak_ikut':
            return '<span class="badge bg-secondary"><i class="bi bi-dash-circle me-1"></i>Tidak Ikut</span>';
        default:
            return '<span class="badge bg-light text-dark">' . htmlspecialchars(ucfirst($status)) . '</span>';
    }
}

/**
 * Badge status pembayaran peserta
 * @param string $status
 * @return string
 */
function payment_badge($status) {
    switch (strtolower($status)) {
        case 'sudah':
            return '<span class="badge bg-info text-dark"><i class="bi bi-cash-stack me-1"></i>Sudah Bayar</span>';
        case 'partial':
            return '<span class="badge bg-partial"><i class="bi bi-pie-chart-fill me-1"></i>Partial</span>';
        case 'belum':
            return '<span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split me-1"></i>Belum Bayar</span>';
        default:
            return '<span class="badge bg-secondary">' . htmlspecialchars(ucfirst($status)) . '</span>';
    }
}

/**
 * Set flash message
 * @param string $type success|danger|warning|info
 * @param string $message
 */
function flash_set($type, $message) {
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

/**
 * Tampilkan flash message dan hapus dari session
 * @return string
 */
function flash_render() {
    if (!empty($_SESSION['flash'])) {
        $type = htmlspecialchars($_SESSION['flash']['type']);
        $msg = htmlspecialchars($_SESSION['flash']['message']);
        unset($_SESSION['flash']);
        return '<div class="alert alert-' . $type . ' alert-dismissible fade show" role="alert">
            ' . $msg . '
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>';
    }
    return '';
}

/**
 * Upload receipt / bukti pembayaran dengan validasi ketat
 * Ekstensi: JPG, PNG, PDF. Max Size: 5MB
 * Folder penyimpanan: uploads/outing/{outing_id}/
 * @param array $file
 * @param int $outing_id
 * @return string Path relatif file yang disimpan
 * @throws Exception
 */
function upload_receipt($file, $outing_id) {
    if (!isset($file['error']) || is_array($file['error'])) {
        throw new Exception('Parameter file upload tidak valid.');
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        switch ($file['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                throw new Exception('Ukuran file melebihi batas upload server.');
            case UPLOAD_ERR_PARTIAL:
                throw new Exception('File hanya terunggah sebagian.');
            case UPLOAD_ERR_NO_FILE:
                throw new Exception('Tidak ada file yang diunggah.');
            default:
                throw new Exception('Terjadi kegagalan upload file (Kode: ' . $file['error'] . ').');
        }
    }

    // Batas 5MB (kompatibel shared hosting InfinityFree)
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new Exception('Ukuran file maksimal 5 MB.');
    }

    // Validasi Ekstensi
    $filename = $file['name'];
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $allowedExts = ['jpg', 'jpeg', 'png', 'pdf'];
    if (!in_array($ext, $allowedExts)) {
        throw new Exception('Hanya diperbolehkan format file JPG, JPEG, PNG, dan PDF.');
    }

    // Validasi MIME type
    $allowedMimes = ['image/jpeg', 'image/pjpeg', 'image/png', 'application/pdf', 'application/x-pdf'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedMimes)) {
        throw new Exception('MIME Type file tidak valid (' . htmlspecialchars($mimeType) . ').');
    }

    // Buat target direktori spesifik per outing_id
    $outing_id = (int)$outing_id;
    $targetDir = __DIR__ . '/../uploads/outing/' . $outing_id . '/';
    if (!is_dir($targetDir)) {
        if (!mkdir($targetDir, 0755, true)) {
            throw new Exception('Gagal membuat direktori upload: uploads/outing/' . $outing_id);
        }
    }

    // Nama file unik dan aman
    $safeName = 'bukti_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $destPath = $targetDir . $safeName;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        throw new Exception('Gagal memindahkan file ke direktori tujuan.');
    }

    // Return format path yang tersimpan di DB
    return 'uploads/outing/' . $outing_id . '/' . $safeName;
}

/**
 * Hapus file bukti pembayaran dari disk jika ada
 * @param string $relativePath
 */
function delete_receipt($relativePath) {
    if (empty($relativePath)) return;
    $fullPath = __DIR__ . '/../' . ltrim($relativePath, '/');
    if (file_exists($fullPath) && is_file($fullPath)) {
        @unlink($fullPath);
    }
}

/* =====================================================================
 * FITUR PEMBAYARAN IURAN PESERTA (Budget per Member, Partial & Bukti Bayar)
 * ===================================================================== */

/** Daftar status pembayaran yang valid */
function payment_statuses() {
    return ['belum', 'partial', 'sudah'];
}

/**
 * Parsing input nominal Rupiah menjadi angka bulat.
 * Mendukung "150000", "150.000", "150,000", "Rp 150.000", "150000.00"
 * @param mixed $value
 * @return float
 */
function parse_money($value) {
    $value = trim((string)$value);
    if ($value === '') return 0;
    // Angka dengan desimal 1-2 digit (format input type=number)
    if (preg_match('/^\d+([.,]\d{1,2})?$/', $value)) {
        return (float)floor((float)str_replace(',', '.', $value));
    }
    $digits = preg_replace('/[^\d]/', '', $value);
    return $digits === '' ? 0 : (float)$digits;
}

/**
 * Estimasi iuran per member.
 * - Jika admin mengisi budget_per_person (> 0), gunakan nilai tersebut.
 * - Jika tidak, hitung otomatis: Total Budget / jumlah peserta berstatus "Ikut",
 *   dibulatkan ke atas per Rp 1.000 agar mudah ditagihkan.
 * @param array $outing
 * @param int $countIkut
 * @return float
 */
function outing_estimate_per_person(array $outing, $countIkut) {
    $manual = (float)($outing['budget_per_person'] ?? 0);
    if ($manual > 0) {
        return $manual;
    }
    $budget = (float)($outing['budget'] ?? 0);
    $countIkut = (int)$countIkut;
    if ($budget <= 0 || $countIkut <= 0) {
        return 0;
    }
    return (float)(ceil(($budget / $countIkut) / 1000) * 1000);
}

/** Apakah estimasi per member diisi manual oleh admin */
function outing_estimate_is_manual(array $outing) {
    return (float)($outing['budget_per_person'] ?? 0) > 0;
}

/**
 * Menentukan status pembayaran otomatis berdasarkan nominal dibayar vs estimasi
 * @param float $paid
 * @param float $estimate
 * @return string belum|partial|sudah
 */
function resolve_payment_status($paid, $estimate) {
    $paid = (float)$paid;
    $estimate = (float)$estimate;
    if ($paid <= 0) return 'belum';
    if ($estimate <= 0 || $paid >= $estimate) return 'sudah';
    return 'partial';
}

/**
 * Kekurangan bayar seorang peserta (0 jika tidak ikut / sudah lunas)
 * @param array $participant (attendance_status, payment_status, amount_paid)
 * @param float $estimate
 * @return float
 */
function participant_outstanding(array $participant, $estimate) {
    if (($participant['attendance_status'] ?? '') !== 'ikut') return 0;
    if (($participant['payment_status'] ?? '') === 'sudah') return 0;
    return max(0, (float)$estimate - (float)($participant['amount_paid'] ?? 0));
}

/**
 * Ringkasan pembayaran iuran seluruh peserta dalam satu outing
 * @param array $participants
 * @param array $outing
 * @return array
 */
function payment_summary(array $participants, array $outing) {
    $countIkut = 0;
    foreach ($participants as $p) {
        if (($p['attendance_status'] ?? '') === 'ikut') $countIkut++;
    }
    $estimate = outing_estimate_per_person($outing, $countIkut);

    $sum = [
        'estimate'      => $estimate,
        'is_manual'     => outing_estimate_is_manual($outing),
        'count_ikut'    => $countIkut,
        'target'        => $estimate * $countIkut,
        'collected'     => 0,
        'outstanding'   => 0,
        'count_sudah'   => 0,
        'count_partial' => 0,
        'count_belum'   => 0,
        'to_bill'       => [], // daftar peserta "ikut" yang masih punya kekurangan
    ];

    foreach ($participants as $p) {
        $sum['collected'] += (float)($p['amount_paid'] ?? 0);
        $status = $p['payment_status'] ?? 'belum';
        if ($status === 'sudah') $sum['count_sudah']++;
        elseif ($status === 'partial') $sum['count_partial']++;
        else $sum['count_belum']++;

        $kurang = participant_outstanding($p, $estimate);
        if ($kurang > 0) {
            $sum['outstanding'] += $kurang;
            $p['_outstanding'] = $kurang;
            $sum['to_bill'][] = $p;
        }
    }

    // Urutkan daftar tagihan dari kekurangan terbesar
    usort($sum['to_bill'], function ($a, $b) {
        return $b['_outstanding'] <=> $a['_outstanding'];
    });

    return $sum;
}

/** Badge status verifikasi bukti bayar */
function proof_status_badge($status) {
    switch ($status) {
        case 'verified':
            return '<span class="badge bg-success"><i class="bi bi-patch-check-fill me-1"></i>Terverifikasi</span>';
        case 'rejected':
            return '<span class="badge bg-danger"><i class="bi bi-x-octagon me-1"></i>Ditolak</span>';
        default:
            return '<span class="badge bg-secondary"><i class="bi bi-hourglass me-1"></i>Menunggu Verifikasi</span>';
    }
}

/** Format ukuran file (KB / MB) */
function format_filesize($bytes) {
    $bytes = (int)$bytes;
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2, ',', '.') . ' MB';
    return number_format(max(1, $bytes / 1024), 0, ',', '.') . ' KB';
}

/**
 * Pastikan folder upload privat ada & terlindungi (.htaccess deny all)
 * File di folder ini HANYA bisa diakses lewat script ber-otorisasi.
 * @param string $dir Path absolut
 */
function ensure_private_upload_dir($dir) {
    $root = dirname(__DIR__) . '/uploads/payments/';
    $dir = rtrim($dir, '/');
    foreach ([rtrim($root, '/'), $dir] as $d) {
        if (!is_dir($d)) {
            // Non-rekursif bila parent sudah ada; cek ulang is_dir agar aman saat dua upload bersamaan
            is_dir(dirname($d)) ? @mkdir($d, 0755) : @mkdir($d, 0755, true);
        }
        if (!is_dir($d)) {
            throw new Exception('Gagal membuat direktori upload bukti pembayaran.');
        }
    }
    $ht = $root . '.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht, "# Bukti pembayaran bersifat privat - akses hanya via member/view_payment_proof.php\n"
            . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n"
            . "Options -Indexes\n");
    }
    foreach ([$root, $dir] as $d) {
        if (!file_exists(rtrim($d, '/') . '/index.html')) {
            @file_put_contents(rtrim($d, '/') . '/index.html', '');
        }
    }
}

/** Bebaskan resource GD (imagedestroy tidak diperlukan & deprecated sejak PHP 8) */
function gd_free($img) {
    if (PHP_VERSION_ID < 80000 && is_resource($img)) {
        imagedestroy($img);
    }
}

/**
 * Kompres & resize gambar menjadi JPEG yang ringan namun tetap jelas dibaca.
 * - Sisi pendek maks. 1440px dan sisi panjang maks. 2560px (screenshot struk tetap tajam)
 * - Kualitas JPEG 82, orientasi EXIF dikoreksi, transparansi PNG diisi putih
 * @param string $srcPath
 * @param string $destPath
 * @param string $mime
 * @return bool true jika berhasil dikompres, false jika GD tidak tersedia
 * @throws Exception jika gambar rusak / tidak valid
 */
function compress_image_to_jpeg($srcPath, $destPath, $mime) {
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
        return false;
    }

    $info = @getimagesize($srcPath);
    if (!$info || empty($info[0]) || empty($info[1])) {
        throw new Exception('File gambar rusak atau tidak dapat dibaca.');
    }
    list($w, $h) = $info;
    if ($w * $h > 50000000) { // > 50 megapiksel
        throw new Exception('Resolusi gambar terlalu besar.');
    }

    @ini_set('memory_limit', '256M');
    switch ($mime) {
        case 'image/jpeg':
        case 'image/pjpeg':
            $src = @imagecreatefromjpeg($srcPath);
            break;
        case 'image/png':
            $src = @imagecreatefrompng($srcPath);
            break;
        case 'image/webp':
            $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($srcPath) : false;
            break;
        default:
            $src = false;
    }
    if (!$src) {
        throw new Exception('Format gambar tidak didukung atau file rusak.');
    }

    // Koreksi orientasi foto dari kamera HP (EXIF)
    if (in_array($mime, ['image/jpeg', 'image/pjpeg']) && function_exists('exif_read_data')) {
        $exif = @exif_read_data($srcPath);
        $orientation = (int)($exif['Orientation'] ?? 1);
        $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;
        if ($angle !== 0) {
            $rotated = imagerotate($src, $angle, 0);
            if ($rotated) {
                gd_free($src);
                $src = $rotated;
            }
        }
    }

    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1, 1440 / min($w, $h), 2560 / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));

    $dst = imagecreatetruecolor($nw, $nh);
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefilledrectangle($dst, 0, 0, $nw, $nh, $white);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imageinterlace($dst, true); // progressive JPEG

    $ok = imagejpeg($dst, $destPath, 82);
    gd_free($src);
    gd_free($dst);

    if (!$ok) {
        throw new Exception('Gagal menyimpan gambar hasil kompresi.');
    }
    return true;
}

/**
 * Upload bukti pembayaran iuran member (privat)
 * - Gambar (JPG/PNG/WEBP) otomatis dikompres & dikonversi ke JPEG
 * - PDF disimpan apa adanya (maks. 5 MB)
 * Folder: uploads/payments/{outing_id}/
 * @param array $file
 * @param int $outing_id
 * @return array ['path' => string, 'size' => int, 'original_size' => int]
 * @throws Exception
 */
function upload_payment_proof($file, $outing_id) {
    if (!isset($file['error']) || is_array($file['error'])) {
        throw new Exception('Parameter file upload tidak valid.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        switch ($file['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                throw new Exception('Ukuran file melebihi batas upload server.');
            case UPLOAD_ERR_NO_FILE:
                throw new Exception('Silakan pilih file bukti pembayaran.');
            default:
                throw new Exception('Upload gagal (Kode: ' . $file['error'] . ').');
        }
    }

    $originalSize = (int)$file['size'];
    if ($originalSize > 10 * 1024 * 1024) {
        throw new Exception('Ukuran file maksimal 10 MB.');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    if (PHP_VERSION_ID < 80100) { finfo_close($finfo); }

    $imageMimes = ['image/jpeg', 'image/pjpeg', 'image/png', 'image/webp'];
    $pdfMimes = ['application/pdf', 'application/x-pdf'];
    if (!in_array($mime, $imageMimes) && !in_array($mime, $pdfMimes)) {
        throw new Exception('Format file harus JPG, PNG, WEBP, atau PDF.');
    }

    $outing_id = (int)$outing_id;
    $targetDir = dirname(__DIR__) . '/uploads/payments/' . $outing_id . '/';
    ensure_private_upload_dir($targetDir);

    $baseName = 'bayar_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8));

    if (in_array($mime, $pdfMimes)) {
        if ($originalSize > 5 * 1024 * 1024) {
            throw new Exception('Ukuran file PDF maksimal 5 MB. Gunakan foto/screenshot agar bisa dikompres otomatis.');
        }
        $safeName = $baseName . '.pdf';
        if (!move_uploaded_file($file['tmp_name'], $targetDir . $safeName)) {
            throw new Exception('Gagal menyimpan file bukti pembayaran.');
        }
    } else {
        $safeName = $baseName . '.jpg';
        $destPath = $targetDir . $safeName;
        $info = @getimagesize($file['tmp_name']);
        if (!$info) {
            throw new Exception('File gambar rusak atau tidak valid.');
        }

        // Lewati kompres ulang jika sudah ringan (mis. sudah dikompres di browser)
        $alreadyLight = in_array($mime, ['image/jpeg', 'image/pjpeg'])
            && $originalSize <= 700 * 1024
            && min($info[0], $info[1]) <= 1440
            && max($info[0], $info[1]) <= 2560;

        $compressed = false;
        if (!$alreadyLight) {
            $compressed = compress_image_to_jpeg($file['tmp_name'], $destPath, $mime);
            // Screenshot PNG kecil kadang lebih besar jika dijadikan JPEG -> simpan file asli
            if ($compressed && filesize($destPath) >= $originalSize
                && min($info[0], $info[1]) <= 1440 && max($info[0], $info[1]) <= 2560) {
                @unlink($destPath);
                $compressed = false;
                $alreadyLight = true;
            }
        }

        if (!$compressed) {
            if (!$alreadyLight && $originalSize > 5 * 1024 * 1024) {
                throw new Exception('Server tidak dapat mengompres gambar. Ukuran maksimal 5 MB.');
            }
            if (!in_array($mime, ['image/jpeg', 'image/pjpeg'])) {
                // Simpan sesuai ekstensi asli (GD tidak tersedia / file asli lebih kecil)
                $safeName = $baseName . ($mime === 'image/png' ? '.png' : '.webp');
                $destPath = $targetDir . $safeName;
            }
            if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                throw new Exception('Gagal menyimpan file bukti pembayaran.');
            }
        }
    }

    $relPath = 'uploads/payments/' . $outing_id . '/' . $safeName;
    return [
        'path'          => $relPath,
        'size'          => (int)@filesize(dirname(__DIR__) . '/' . $relPath),
        'original_size' => $originalSize,
    ];
}

/**
 * Hapus seluruh bukti pembayaran (file + data) berdasarkan kondisi
 * @param PDO $pdo
 * @param string $column 'participant_id' | 'outing_id' | 'id'
 * @param int $value
 */
function delete_payment_proofs(PDO $pdo, $column, $value) {
    if (!in_array($column, ['participant_id', 'outing_id', 'id'], true)) return;
    try {
        $stmt = $pdo->prepare("SELECT file_path FROM payment_proofs WHERE {$column} = :v");
        $stmt->execute([':v' => (int)$value]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $path) {
            if (strpos((string)$path, 'uploads/payments/') === 0) {
                delete_receipt($path);
            }
        }
        $del = $pdo->prepare("DELETE FROM payment_proofs WHERE {$column} = :v");
        $del->execute([':v' => (int)$value]);
    } catch (PDOException $e) {
        error_log('[Outing] Gagal menghapus bukti pembayaran: ' . $e->getMessage());
    }
}

/** Potong string dengan aman (UTF-8) */
function str_limit($text, $limit) {
    $text = (string)$text;
    return function_exists('mb_substr') ? mb_substr($text, 0, $limit, 'UTF-8') : substr($text, 0, $limit);
}

/**
 * Muat ringkasan pembayaran iuran untuk banyak outing sekaligus (Dashboard & History)
 * @param PDO $pdo
 * @param array $outings
 * @return array [outing_id => summary + pending_proofs]
 */
function load_payment_summaries(PDO $pdo, array $outings) {
    $result = [];
    $byOuting = [];
    $pending = [];
    $ids = array_map(function ($o) { return (int)$o['id']; }, $outings);

    if (!empty($ids)) {
        $in = implode(',', $ids); // aman: sudah di-cast ke integer
        $rows = $pdo->query("
            SELECT outing_id, attendance_status, payment_status, amount_paid
            FROM outing_participants WHERE outing_id IN ($in)
        ")->fetchAll();
        foreach ($rows as $r) {
            $byOuting[$r['outing_id']][] = $r;
        }
        try {
            $pr = $pdo->query("
                SELECT outing_id, COUNT(*) AS c FROM payment_proofs
                WHERE status = 'pending' AND outing_id IN ($in) GROUP BY outing_id
            ")->fetchAll();
            foreach ($pr as $r) $pending[$r['outing_id']] = (int)$r['c'];
        } catch (PDOException $e) {
            // tabel belum tersedia (migrasi belum berjalan)
        }
    }

    foreach ($outings as $o) {
        $sum = payment_summary($byOuting[$o['id']] ?? [], $o);
        unset($sum['to_bill']);
        $sum['pending_proofs'] = $pending[$o['id']] ?? 0;
        $result[$o['id']] = $sum;
    }
    return $result;
}
