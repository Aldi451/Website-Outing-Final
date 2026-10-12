<?php
/**
 * Konfigurasi Database PDO - 100% InfinityFree Compatible
 * Ganti nilai kredensial di bawah ini sesuai data MySQL dari Control Panel InfinityFree.
 */

define('DB_HOST', 'sql210.infinityfree.com'); // Contoh: sql108.infinityfree.com atau localhost jika lokal
define('DB_PORT', '3306');
define('DB_NAME', 'if0_42971783_outing');     // Ganti dengan Nama Database InfinityFree Anda
define('DB_USER', 'if0_42971783');            // Ganti dengan MySQL Username InfinityFree Anda
define('DB_PASS', 'F1020jong');     // Ganti dengan Password Akun Hosting/vPanel Anda
define('DB_CHARSET', 'utf8mb4');

/**
 * Mendapatkan koneksi PDO ke database
 * @return PDO
 */
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Tampilan pesan error aman dan informatif jika koneksi gagal
            die('<div style="font-family:sans-serif;max-width:600px;margin:50px auto;padding:24px;border:1px solid #f5c6cb;background:#f8d7da;color:#721c24;border-radius:8px;">
                <h3 style="margin-top:0;">Gagal Terhubung ke Database MySQL</h3>
                <p>Silakan periksa pengaturan di file <code>config/database.php</code>:</p>
                <ul>
                    <li>Host: <strong>' . htmlspecialchars(DB_HOST) . '</strong></li>
                    <li>Database: <strong>' . htmlspecialchars(DB_NAME) . '</strong></li>
                    <li>User: <strong>' . htmlspecialchars(DB_USER) . '</strong></li>
                </ul>
                <p><em>Pesan Teknis: ' . htmlspecialchars($e->getMessage()) . '</em></p>
                <p><small>Tips InfinityFree: Buka Control Panel -> MySQL Databases untuk melihat detail Host & User MySQL Anda.</small></p>
            </div>');
        }
    }
    return $pdo;
}
