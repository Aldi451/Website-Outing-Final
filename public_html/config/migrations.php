<?php
/**
 * Auto Schema Migration (Fitur Pembayaran Iuran Peserta)
 *
 * Dijalankan otomatis dari getDB() maksimal satu kali per sesi login,
 * sehingga tidak perlu menjalankan SQL manual di phpMyAdmin (InfinityFree friendly).
 * File SQL manual yang setara tersedia di: database_migration_pembayaran.sql
 *
 * Perubahan skema:
 *  1. outings.budget_per_person             -> Estimasi iuran per member (0 = otomatis)
 *  2. outing_participants.amount_paid       -> Nominal iuran yang sudah dibayar member
 *  3. outing_participants.payment_status    -> ENUM ditambah status 'partial'
 *  4. Tabel payment_proofs                  -> Bukti transfer yang diupload member
 */

define('APP_SCHEMA_VERSION', 2);

/**
 * Cek apakah sebuah kolom ada pada tabel
 */
function db_column_info(PDO $pdo, $table, $column) {
    $stmt = $pdo->prepare('
        SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c
        LIMIT 1
    ');
    $stmt->execute([':t' => $table, ':c' => $column]);
    $type = $stmt->fetchColumn();
    return $type === false ? null : strtolower((string)$type);
}

/**
 * Cek apakah tabel ada
 */
function db_table_exists(PDO $pdo, $table) {
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t
    ');
    $stmt->execute([':t' => $table]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Jalankan migrasi skema jika diperlukan
 */
function run_schema_migrations(PDO $pdo) {
    // Hanya untuk MySQL / MariaDB
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        return;
    }

    // Cache per sesi agar tidak query information_schema di setiap request
    if (session_status() === PHP_SESSION_ACTIVE
        && isset($_SESSION['_schema_version'])
        && (int)$_SESSION['_schema_version'] >= APP_SCHEMA_VERSION) {
        return;
    }

    try {
        // 1. Estimasi iuran per member pada outing
        if (db_column_info($pdo, 'outings', 'budget_per_person') === null) {
            $pdo->exec("ALTER TABLE outings ADD COLUMN budget_per_person DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER budget");
        }

        // 2. Nominal yang sudah dibayar peserta
        if (db_column_info($pdo, 'outing_participants', 'amount_paid') === null) {
            $pdo->exec("ALTER TABLE outing_participants ADD COLUMN amount_paid DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER payment_status");
        }

        // 3. Tambahkan kembali status 'partial' pada ENUM payment_status
        $payType = db_column_info($pdo, 'outing_participants', 'payment_status');
        if ($payType !== null && strpos($payType, 'enum(') === 0 && strpos($payType, "'partial'") === false) {
            $pdo->exec("ALTER TABLE outing_participants MODIFY COLUMN payment_status ENUM('belum','partial','sudah') NOT NULL DEFAULT 'belum'");
        }

        // 4. Tabel bukti pembayaran member
        if (!db_table_exists($pdo, 'payment_proofs')) {
            $pdo->exec("
                CREATE TABLE payment_proofs (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['_schema_version'] = APP_SCHEMA_VERSION;
        }
    } catch (PDOException $e) {
        // Jangan hentikan aplikasi; catat ke log server agar admin bisa menjalankan SQL manual
        error_log('[Outing] Migrasi skema gagal: ' . $e->getMessage());
    }
}
