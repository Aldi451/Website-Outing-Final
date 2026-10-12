# 🌴 OUTING MANAGEMENT SYSTEM (100% INFINITYFREE READY)

Sistem Informasi Manajemen Outing & Gathering Perusahaan berbasis **PHP Native, PDO, MariaDB/MySQL, Bootstrap 5, dan Vanilla JS**. 

Sistem ini didesain khusus **100% kompatibel dengan Shared Hosting Gratis InfinityFree** (tanpa Composer, tanpa Node.js, tanpa dependensi server eksternal, ramah resource & kuota inode).

---

## 🚀 FITUR UTAMA SISTEM

1. **Multi-Outing & Manajemen Arsip (History):**
   - Mendukung banyak agenda outing secara simultan dengan ID unik.
   - Status Outing: **Planning** & **Active** (tampil di Dashboard Outing Aktif), serta **Selesai** & **Cancelled** (otomatis masuk ke Dashboard History).
   - Mode Arsip (History) bersifat **Read-Only** secara default untuk mencegah salah klik, dilengkapi tombol **"Edit History"** dengan dialog konfirmasi jika butuh koreksi data.
   - Pembuatan outing baru tidak akan pernah menimpa atau menghapus data outing sebelumnya.

2. **Panel Administrator (Full Access CRUD):**
   - **Manajemen User:** Tambah, edit, aktif/nonaktifkan status akun, dan reset password (enkripsi BCRYPT `password_hash()`).
   - **Manajemen Peserta:** Pendaftaran user ke outing, penentuan status kehadiran (*Ikut / Batal / Tidak Ikut*) dan pembayaran iuran (*Sudah / Belum*).
   - **Susunan Rundown:** Penataan jadwal agenda harian, waktu mulai/selesai, aktivitas, lokasi, dan nomor urut.
   - **Catatan Pembelian & Bukti Bayar:** Pencatatan belanja, pemilihan kategori dinamis, kalkulasi otomatis (*Qty × Harga Satuan*), serta upload bukti fisik format JPG/PNG/PDF (tersimpan aman di folder `uploads/outing/{outing_id}/`).
   - **Kategori Dinamis:** Penambahan kategori belanja secara langsung dari modal atau form master data.

3. **Portal Member (100% View Only & Mobile Friendly):**
   - Akses aman hanya ke agenda outing yang diikutinya.
   - Proteksi ketat: Member yang mencoba mengakses URL `/admin` akan otomatis ditolak dan dialihkan kembali ke Dashboard Member.
   - Tampilan Rundown format **Timeline Card Responsif** yang sangat nyaman dibaca di smartphone saat kegiatan berlangsung.
   - Transparansi keuangan & tampilan popup bukti bayar.

4. **Fitur Import Excel (.xlsx) & Export PDF:**
   - **Template Excel Resmi:** Menyediakan tombol unduh template `.xlsx` yang terdiri dari **Sheet 1: DATA** dan **Sheet 2: PETUNJUK** untuk User, Peserta, dan Pembelian.
   - **Alur Import Aman:** Upload &rarr; Validasi Sistem per baris &rarr; Halaman Preview (tampilan baris hijau valid vs baris merah error) &rarr; Tombol "Konfirmasi Import" (hanya memproses baris valid, mengabaikan error).
   - **Opsi Kategori Otomatis:** Otomatis membuat kategori baru di database jika nama kategori pada Excel belum terdaftar.
   - **Laporan PDF Resmi:** Ekspor laporan formal berformat vector PDF menggunakan engine FPDF pure PHP (mencakup ringkasan eksekutif, rekapitulasi kategori, rincian pembelian, rundown, dan daftar peserta).

---

## 📁 STRUKTUR DIREKTORI PROYEK

```text
├── database.sql                  <- Skrip pembuatan database, relasi tabel & seed data
├── README.md                     <- Panduan instalasi dan dokumentasi sistem
├── index.php                     <- Root router otomatis mengarah ke public_html
└── public_html/                  <- Root web (diupload ke folder htdocs InfinityFree)
    ├── index.php                 <- Pintu masuk aplikasi (router role)
    ├── login.php                 <- Halaman login modern (CSRF & Bcrypt)
    ├── logout.php                <- Handler keluar sesi aman
    ├── config/
    │   ├── database.php          <- Kredensial koneksi PDO MySQL InfinityFree
    │   ├── auth.php              <- Proteksi role, session guard & redirection
    │   ├── csrf.php              <- Proteksi Cross-Site Request Forgery (CSRF)
    │   └── helpers.php           <- Format Rupiah, tanggal ID, upload & sanitasi
    ├── admin/
    │   ├── index.php             <- Dashboard Outing Aktif & KPI Keuangan
    │   ├── history.php           <- Dashboard Arsip Outing (Selesai & Cancelled)
    │   ├── outings.php           <- Form Buat & Edit Outing Baru
    │   ├── outing_detail.php     <- Pusat kendali outing (Peserta, Rundown, Belanja)
    │   ├── users.php             <- CRUD Pengguna & Reset Password
    │   ├── categories.php        <- CRUD Kategori Pengeluaran Dinamis
    │   ├── template_excel.php    <- Generator Template Excel 2 Sheet (.xlsx)
    │   ├── import.php            <- Engine Import Excel (Upload -> Preview -> Commit)
    │   └── export_pdf.php        <- Generator Laporan Resmi PDF (FPDF Native)
    ├── member/
    │   ├── index.php             <- Dashboard Member (Outing Saya)
    │   ├── outing_detail.php     <- View-Only: Info, Timeline HP, Peserta, Transparansi
    │   └── view_receipt.php      <- Viewer bukti pembayaran aman (Anti-Traversal)
    ├── api/
    │   ├── outings.php           <- Endpoint AJAX info outing
    │   ├── participants.php      <- Endpoint AJAX status peserta
    │   ├── rundown.php           <- Endpoint AJAX jadwal acara
    │   └── purchases.php         <- Endpoint AJAX data pengeluaran
    ├── libs/
    │   ├── fpdf/                 <- Engine PDF Native PHP (No Composer)
    │   │   └── fpdf.php
    │   ├── SimpleXLSX.php        <- Parser Excel XLSX Pure PHP
    │   └── SimpleXLSXGen.php     <- Generator Excel XLSX Pure PHP Multi-Sheet
    ├── assets/
    │   ├── css/style.css         <- Styling kustom Bootstrap 5 & Timeline Mobile
    │   └── js/main.js            <- Vanilla JS kalkulasi otomatis & konfirmasi dialog
    └── uploads/
        └── outing/               <- Folder upload dipisah per outing ID
            └── .htaccess         <- Proteksi server (Mencegah eksekusi file script)
```

---

## 🛠️ TUTORIAL 8 STEP INSTALASI DARI NOL DI INFINITYFREE

Ikuti panduan 8 langkah berikut untuk memasang sistem ini di hosting gratis **InfinityFree**:

### **Langkah 1: Buat Akun & Hosting di InfinityFree**
1. Buka [https://www.infinityfree.com](https://www.infinityfree.com) dan daftar akun gratis jika belum memiliki akun.
2. Di Dashboard InfinityFree, klik **"Create Account"**.
3. Pilih domain gratis (contoh: `domainanda.epizy.com` atau `domainanda.infinityfreeapp.com`).
4. Tunggu beberapa menit hingga status akun menjadi **Active**.

### **Langkah 2: Buat Database MySQL di Control Panel**
1. Klik tombol **"Control Panel"** (vPanel) pada akun hosting Anda.
2. Di halaman Control Panel, cari dan pilih menu **"MySQL Databases"**.
3. Pada kolom *Create New Database*, masukkan nama database (misal: `outing`), lalu klik **"Create Database"**.
4. Catat informasi koneksi database yang tampil di halaman tersebut:
   - **MySQL Hostname:** (contoh: `sql108.infinityfree.com`)
   - **MySQL Database Name:** (contoh: `if0_38000000_outing`)
   - **MySQL Username:** (contoh: `if0_38000000`)
   - **MySQL Password:** (Password akun hosting/vPanel Anda)

### **Langkah 3: Import Database SQL via phpMyAdmin**
1. Di Control Panel InfinityFree, klik menu **"phpMyAdmin"**.
2. Klik tombol **"Connect"** di sebelah nama database Anda.
3. Di halaman phpMyAdmin, klik tab **"Import"** pada bilah navigasi atas.
4. Klik **"Choose File"** dan pilih berkas `database.sql` yang ada di paket proyek ini.
5. Gulir ke bawah dan klik tombol **"Import"** / **"Go"**.
6. Pastikan seluruh tabel (`users`, `outings`, `categories`, `outing_participants`, `rundown`, `purchases`) berhasil dibuat.

### **Langkah 4: Upload File Proyek ke Folder `htdocs`**
1. Kembali ke Control Panel InfinityFree, buka **"Online File Manager"** (atau gunakan aplikasi FTP seperti FileZilla).
2. Masuk ke dalam folder **`htdocs/`** (ini adalah web root default di InfinityFree).
3. Hapus file default `DO NOT UPLOAD FILES HERE` jika ada.
4. Upload seluruh isi yang ada di dalam folder **`public_html/`** proyek ini langsung ke dalam folder **`htdocs/`**.
   > *Catatan: Pastikan file `index.php`, folder `config/`, folder `admin/`, folder `member/`, folder `libs/`, dll berada tepat di dalam folder `htdocs`.*

### **Langkah 5: Edit Konfigurasi Database di `config/database.php`**
1. Di File Manager, buka folder **`config/`** lalu klik kanan pada file **`database.php`** dan pilih **"Edit"**.
2. Sesuaikan kredensial sesuai data pada **Langkah 2**:
   ```php
   define('DB_HOST', 'sql108.infinityfree.com'); // Ganti dengan MySQL Hostname Anda
   define('DB_PORT', '3306');
   define('DB_NAME', 'if0_38000000_outing');     // Ganti dengan Nama Database Anda
   define('DB_USER', 'if0_38000000');            // Ganti dengan Username MySQL Anda
   define('DB_PASS', 'password_vpanel_anda');     // Ganti dengan Password Akun Anda
   define('DB_CHARSET', 'utf8mb4');
   ```
3. Klik **"Save"** / Simpan file.

### **Langkah 6: Konfigurasi Permission Folder Uploads**
1. Pastikan folder **`uploads/`** dan subfolder **`uploads/outing/`** sudah memiliki izin tulis (*write permission*).
2. Di File Manager, klik kanan pada folder `uploads`, pilih **"Permissions"**, dan pastikan bernilai `755` (atau `777`).

### **Langkah 7: Buka Website Melalui Browser**
1. Buka browser web (Google Chrome, Mozilla Firefox, Microsoft Edge, atau Safari).
2. Kunjungi alamat domain Anda (contoh: `http://domainanda.infinityfreeapp.com`).
3. Sistem akan otomatis menampilkan halaman Login Outing Management System.

### **Langkah 8: Login Menggunakan Akun Bawaan & Verifikasi Sistem**
1. Masuk menggunakan akun Administrator default:
   - **User ID:** `admin`
   - **Password:** `admin123`
2. Uji coba fitur-fitur utama:
   - ✅ **Dashboard Aktif:** Lihat statistik ringkasan outing yang sedang berjalan.
   - ✅ **Buat Outing Baru:** Buka menu "Buat Outing Baru" dan isi nama serta tanggal kegiatan.
   - ✅ **Kelola Peserta & Rundown:** Masuk ke detail outing, tambahkan peserta dan susun rundown.
   - ✅ **Pencatatan Pembelian:** Input pembelian barang/jasa, perhatikan kalkulasi otomatis Qty × Harga, dan coba upload bukti bayar (gambar/PDF).
   - ✅ **Export PDF:** Klik "Unduh PDF Laporan" untuk memastikan laporan ter-generate sempurna.
   - ✅ **Import Excel:** Buka menu "Import Excel", unduh template `.xlsx`, lalu coba upload data.
   - ✅ **Login Member:** Logout, lalu login sebagai member (`user01` / `user123`) untuk memverifikasi tampilan *View-Only* dan timeline mobile.

---

## 🔑 INFORMASI AKUN BAWAAN (DEFAULT CREDENTIALS)

| Role | User ID | Password | Hak Akses |
| :--- | :--- | :--- | :--- |
| **Admin** | `admin` | `admin123` | Akses Penuh (CRUD Outing, Peserta, Rundown, Pembelian, User, Kategori, Import/Export) |
| **Member 1** | `user01` | `user123` | View Only (Melihat Outing Diikuti, Timeline HP, Peserta, Bukti Bayar) |
| **Member 2** | `user02` | `user123` | View Only |
| **Member 3** | `user03` | `user123` | View Only |

> ⚠️ *Disarankan untuk segera mengganti password akun admin atau membuat user admin baru di menu "Kelola User" setelah instalasi selesai demi keamanan.*

---

## 🔒 KEAMANAN & PRAKTIK TERBAIK (BEST PRACTICES)
- **Password Hashing:** Menggunakan standar PHP `password_hash()` BCRYPT yang aman dan kebal terhadap rainbow table attacks.
- **SQL Injection Prevention:** Seluruh kueri interaktif menggunakan PDO Prepared Statements dengan parameter binding.
- **CSRF Protection:** Setiap form interaktif diproteksi token CSRF unik per sesi.
- **Upload Validation:** Memvalidasi MIME type asli serta ekstensi file, membatasi ukuran maksimal 5 MB, mengganti nama file secara acak, dan memproteksi folder upload menggunakan `.htaccess` untuk mencegah pengeksekusian file skrip berbahaya.
- **Role Verification Guard:** Hak akses diverifikasi di level backend pada setiap pemanggilan halaman (bukan hanya menyembunyikan tombol di UI).
