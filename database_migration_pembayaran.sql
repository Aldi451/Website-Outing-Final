-- =====================================================================
-- MIGRASI: Fitur Pembayaran Iuran Peserta
--   (Budget sudah dibayar, Estimasi per Member, Status Partial, Bukti Bayar)
--
-- CATATAN: Migrasi ini SUDAH DIJALANKAN OTOMATIS oleh aplikasi
-- (config/migrations.php) saat halaman pertama kali dibuka setelah login.
-- Jalankan file ini secara manual di phpMyAdmin HANYA jika migrasi otomatis gagal
-- (mis. user database tidak memiliki hak ALTER). Jalankan per-blok; abaikan error
-- "Duplicate column" / "already exists" jika sebagian sudah pernah dijalankan.
-- =====================================================================

-- 1. Estimasi iuran per member (0 = otomatis: Total Budget / peserta "Ikut")
ALTER TABLE outings
    ADD COLUMN budget_per_person DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER budget;

-- 2. Nominal budget / iuran yang sudah dibayar peserta
ALTER TABLE outing_participants
    ADD COLUMN amount_paid DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER payment_status;

-- 3. Tambahkan kembali status 'partial'
ALTER TABLE outing_participants
    MODIFY COLUMN payment_status ENUM('belum','partial','sudah') NOT NULL DEFAULT 'belum';

-- 4. Bukti pembayaran yang diupload member (file disimpan privat di uploads/payments/{outing_id}/)
CREATE TABLE IF NOT EXISTS payment_proofs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    outing_id INT NOT NULL,
    participant_id INT NOT NULL,
    user_id INT NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_size INT NOT NULL DEFAULT 0,
    amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    note VARCHAR(255) NULL,
    status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
    uploaded_by INT NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    verified_by INT NULL,
    verified_at DATETIME NULL,
    INDEX idx_pp_outing (outing_id),
    INDEX idx_pp_participant (participant_id),
    INDEX idx_pp_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
