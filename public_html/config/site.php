<?php
/**
 * Konfigurasi Situs (Skema URL & Keamanan Transport)
 * --------------------------------------------------
 * File ini dibaca oleh config/helpers.php dan TIDAK perlu di-require manual.
 *
 * CARA MENGHILANGKAN PERINGATAN "TIDAK AMAN / NOT SECURE" DI BROWSER:
 * 1. Pasang dulu sertifikat SSL di hosting (InfinityFree: menu "Free SSL Certificates"
 *    di Client Area -> install lewat Control Panel menu "SSL/TLS").
 * 2. Pastikan https://domain-anda.com sudah bisa dibuka dengan icon gembok.
 * 3. Baru ubah FORCE_HTTPS di bawah ini menjadi true.
 *
 * PENTING: Jangan set FORCE_HTTPS = true SEBELUM SSL aktif, karena seluruh
 * pengunjung akan dialihkan ke https:// yang sertifikatnya belum ada
 * (muncul ERR_SSL_PROTOCOL_ERROR / website tidak bisa dibuka).
 */

/**
 * Paksa semua pengunjung memakai HTTPS (redirect 301 otomatis).
 * Nilai: true = paksa HTTPS | false = biarkan apa adanya (default, aman untuk lokal)
 */
if (!defined('FORCE_HTTPS')) {
    define('FORCE_HTTPS', false);
}

/**
 * Daftar host yang DIKECUALIKAN dari paksaan HTTPS (pisahkan dengan koma).
 * Berguna saat pengembangan lokal (XAMPP / Laragon / localhost) yang belum punya SSL.
 * Wildcard "*" diperbolehkan, contoh: *.local
 */
if (!defined('FORCE_HTTPS_EXCLUDE_HOSTS')) {
    define('FORCE_HTTPS_EXCLUDE_HOSTS', 'localhost, 127.0.0.1, ::1, *.local, *.test, *.localhost');
}

/**
 * Paksa cookie sesi hanya dikirim lewat HTTPS + HttpOnly + SameSite.
 * Sangat disarankan true saat website sudah berjalan dengan HTTPS.
 */
if (!defined('SECURE_SESSION_COOKIE')) {
    define('SECURE_SESSION_COOKIE', true);
}
