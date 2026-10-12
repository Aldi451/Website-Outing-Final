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
