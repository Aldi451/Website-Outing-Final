<?php
/**
 * Download Template Excel (.xlsx) dengan Sheet 1: DATA dan Sheet 2: PETUNJUK
 * 100% Native PHP menggunakan SimpleXLSXGen (Zero Dependency / No Composer)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../libs/SimpleXLSXGen.php';

require_admin();

$pdo = getDB();
$type = $_GET['type'] ?? 'users'; // 'users', 'participants', 'purchases'
$outingId = (int)($_GET['outing_id'] ?? 0);

$sheets = [];
$filename = 'template_' . $type . '.xlsx';

if ($type === 'users') {
    $filename = 'template_import_users.xlsx';
    
    // Sheet 1: DATA
    $sheets['DATA'] = [
        ['USER_ID', 'NAMA_LENGKAP', 'ROLE', 'STATUS', 'PASSWORD_AWAL'],
        ['user06', 'Dian Permata', 'member', 'active', 'user123'],
        ['user07', 'Fajar Nugraha', 'member', 'active', 'user123'],
        ['user08', 'Citra Kirana', 'member', 'active', 'user123'],
    ];

    // Sheet 2: PETUNJUK
    $sheets['PETUNJUK'] = [
        ['KOLOM', 'TIPE / PILIHAN VALID', 'KETERANGAN & ATURAN'],
        ['USER_ID', 'Teks Unik (Wajib)', 'ID unik login untuk user (contoh: NIK atau username tanpa spasi). Tidak boleh duplikat.'],
        ['NAMA_LENGKAP', 'Teks (Wajib)', 'Nama lengkap personil / karyawan.'],
        ['ROLE', 'member / admin', 'Hak akses. Default adalah "member". Admin memiliki akses penuh ke sistem.'],
        ['STATUS', 'active / inactive', 'Status akun. "active" bisa login, "inactive" tidak bisa login.'],
        ['PASSWORD_AWAL', 'Teks (Wajib)', 'Password login awal. Sistem akan otomatis meng-enkripsi menggunakan BCRYPT hash.']
    ];

} elseif ($type === 'participants') {
    $filename = 'template_import_peserta.xlsx';

    // Sheet 1: DATA
    $sheets['DATA'] = [
        ['USER_ID', 'STATUS_KEHADIRAN', 'STATUS_PEMBAYARAN', 'CATATAN'],
        ['user01', 'ikut', 'sudah', 'Bus 1'],
        ['user02', 'ikut', 'belum', 'Bus 2'],
        ['user03', 'batal', 'belum', 'Izin mendadak'],
    ];

    // Sheet 2: PETUNJUK
    $sheets['PETUNJUK'] = [
        ['KOLOM', 'TIPE / PILIHAN VALID', 'KETERANGAN & ATURAN'],
        ['USER_ID', 'Teks (Wajib)', 'User ID yang sudah terdaftar di sistem. Jika belum ada di master user, baris akan ditandai error.'],
        ['STATUS_KEHADIRAN', 'ikut / batal / tidak_ikut', 'Pilihan status kehadiran peserta outing.'],
        ['STATUS_PEMBAYARAN', 'sudah / partial / belum', 'Status pembayaran iuran / kontribusi peserta. Gunakan partial jika baru membayar sebagian. Nominal dibayar diisi admin di menu Peserta.'],
        ['CATATAN', 'Teks (Opsional)', 'Catatan tambahan seperti bus, kamar, atau alergi.']
    ];

} elseif ($type === 'purchases') {
    $filename = 'template_import_pembelian.xlsx';

    // Sheet 1: DATA
    $sheets['DATA'] = [
        ['TANGGAL', 'KATEGORI', 'NAMA_BARANG', 'QTY', 'HARGA_SATUAN', 'CATATAN'],
        [date('Y-m-d'), 'Konsumsi', 'Snack Box Pagi', 50, 25000, 'Toko Roti Sedap'],
        [date('Y-m-d'), 'Transportasi', 'Bensin Bus Tambahan', 2, 350000, 'SPBU Tol KM 45'],
        [date('Y-m-d'), 'Lain-lain', 'Baterai Mic Wireless', 4, 15000, 'Alfamart'],
    ];

    // Sheet 2: PETUNJUK
    $sheets['PETUNJUK'] = [
        ['KOLOM', 'TIPE / PILIHAN VALID', 'KETERANGAN & ATURAN'],
        ['TANGGAL', 'YYYY-MM-DD (Wajib)', 'Format tanggal transaksi: Tahun-Bulan-Hari (contoh: ' . date('Y-m-d') . ').'],
        ['KATEGORI', 'Teks Kategori (Wajib)', 'Kategori pengeluaran. Jika belum ada di database, sistem menyediakan opsi "Buat Kategori Otomatis".'],
        ['NAMA_BARANG', 'Teks (Wajib)', 'Nama item, barang, atau jasa yang dibeli.'],
        ['QTY', 'Angka Bulat >= 1', 'Jumlah unit yang dibeli.'],
        ['HARGA_SATUAN', 'Angka Murni', 'Harga satuan tanpa titik atau koma (contoh: 25000). Total harga akan dihitung otomatis oleh sistem (Qty * Harga).'],
        ['CATATAN', 'Teks (Opsional)', 'Nomor kwitansi, nama toko, atau keterangan pengeluaran.']
    ];
}

// Generate file XLSX dengan 2 Sheet
try {
    $xlsx = SimpleXLSXGen::fromSheets($sheets);
    $xlsx->downloadAs($filename);
    exit;
} catch (Exception $e) {
    die('Gagal membuat template Excel: ' . htmlspecialchars($e->getMessage()));
}
