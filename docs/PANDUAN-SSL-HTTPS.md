# 🔒 PANDUAN LENGKAP: MENGHILANGKAN PERINGATAN "TIDAK AMAN / NOT SECURE"

Peringatan **"Tidak aman"** (Chrome) atau **"Not secure"** di sebelah kiri alamat website
artinya halaman dibuka lewat **HTTP biasa (tanpa enkripsi SSL/TLS)**, atau SSL-nya bermasalah.
Dokumen ini menjelaskan penyebab, cara memasang SSL di hosting gratis **InfinityFree**,
cara mengaktifkan paksaan HTTPS di proyek ini, dan solusi bila masih error.

---

## 1️⃣ Kenali dulu jenis peringatannya

| Tampilan di browser | Artinya | Solusi |
| :--- | :--- | :--- |
| `Tidak aman` / `Not secure` + URL diawali `http://` | Website belum punya SSL (belum HTTPS) | Pasang sertifikat SSL → **Bagian 2** |
| `Koneksi Anda tidak pribadi` / `Your connection is not private` (`NET::ERR_CERT_...`) | SSL ada tapi **tidak valid**: salah domain, kedaluwarsa, atau belum ter-install | **Bagian 5** (Troubleshooting) |
| Gembok ada, tapi masih muncul `Tidak aman` / icon ⚠️ | **Mixed content**: ada gambar/script/CSS yang dimuat lewat `http://` | **Bagian 5** (Mixed content) |
| `Situs ini tidak dapat menyediakan koneksi aman` / `ERR_SSL_PROTOCOL_ERROR` | SSL dipaksa aktif padahal sertifikat belum terpasang | Matikan sementara `FORCE_HTTPS` → **Bagian 5** |

> 💡 **Cara cek cepat:** buka `https://domain-anda.com` secara manual.
> - Kalau terbuka dengan gembok → SSL sudah ada, tinggal paksa redirect (**Bagian 3**).
> - Kalau error / tidak bisa dibuka → SSL belum terpasang (**Bagian 2**).

---

## 2️⃣ Pasang sertifikat SSL gratis di InfinityFree

InfinityFree menyediakan SSL gratis (Let's Encrypt untuk domain sendiri, GoGetSSL untuk subdomain gratis
seperti `*.infinityfreeapp.com`, `*.epizy.com`, `*.rf.gd`). Sertifikat berlaku **±90 hari** dan perlu diperbarui.

### Langkah A — Minta sertifikat di Client Area
1. Login ke **Client Area**: [https://dash.infinityfree.com](https://dash.infinityfree.com)
2. Buka menu **"SSL Certificates"** (kadang tertulis **"Free SSL Certificates"**).
3. Klik **"New SSL Certificate"**.
4. Masukkan nama domain website Anda **tanpa** `http://` dan **tanpa** `www`
   (contoh: `outingku.infinityfreeapp.com` atau `outingku.com`).
   - Sertifikat otomatis mencakup versi `www` untuk domain sendiri.
   - Untuk subdomain gratis, tulis subdomain lengkapnya.
5. Klik **"Create Order"**.

### Langkah B — Verifikasi domain (CNAME)
6. Buka sertifikat yang baru dibuat → halaman akan menampilkan **CNAME record** untuk verifikasi.
7. Jika nameserver Anda memakai InfinityFree: klik tombol **"Setup CNAME Records Automatically"**.
   Jika memakai nameserver pihak ketiga (mis. Cloudflare/ registrar lain): tambahkan CNAME tersebut
   manual di pengelola DNS Anda (untuk subdomain, tambahkan nama subdomain di akhir *record name*,
   contoh `_acme-challenge.outingku`).
8. Tunggu hingga status **"Current Destination"** menjadi **Ready** (bisa beberapa menit – beberapa jam),
   lalu klik **"Verify Domain"**.

### Langkah C — Install sertifikat
9. Setelah sertifikat terbit, klik **"Install Certificate"** (otomatis) di halaman SSL tersebut.
10. Atau install manual lewat **Control Panel (vPanel)** akun hosting Anda → menu **"SSL/TLS"** →
    **Configure** di sebelah domain → tempel **Private Key** lalu klik *Upload Key*,
    kemudian tempel **Certificate** (termasuk baris `-----BEGIN CERTIFICATE-----` dan `-----END CERTIFICATE-----`)
    lalu klik *Upload Certificate*.
    - Panel InfinityFree hanya menerima **private key RSA 2048 bit** (jangan 4096 bit).
    - **CA chain / intermediate certificate tidak perlu** (tidak didukung panel).
11. Tunggu beberapa menit, lalu buka `https://domain-anda.com`. Gembok harusnya sudah muncul.

### Alternatif: Cloudflare (SSL instan + proteksi tambahan)
Jika ingin cepat, arahkan nameserver domain ke **Cloudflare** (paket gratis):
- Set mode SSL/TLS ke **"Full"** (bukan *Flexible*) agar koneksi origin juga terenkripsi.
- Aktifkan **"Always Use HTTPS"** di menu SSL/TLS → Edge Certificates.
- Proyek ini sudah mengenali header Cloudflare (`CF-Visitor`, `X-Forwarded-Proto`),
  jadi tidak akan terjadi redirect loop.

---

## 3️⃣ Paksa semua pengunjung memakai HTTPS (bagian proyek ini)

Setelah gembok HTTPS muncul, aktifkan redirect otomatis supaya pengunjung yang mengetik
`http://` (atau mengklik link lama) langsung dibawa ke `https://`.

**Cara 1 — lewat PHP (disarankan, paling aman & anti-loop):**

Buka `public_html/config/site.php`, ubah:

```php
define('FORCE_HTTPS', true);   // sebelumnya false
```

Lalu upload ulang file tersebut ke `htdocs/config/site.php` di hosting.

> File ini juga berisi `FORCE_HTTPS_EXCLUDE_HOSTS` (default: `localhost, 127.0.0.1, ::1, *.local, *.test`),
> jadi pengembangan lokal di XAMPP/Laragon tetap jalan lewat HTTP dan tidak ikut dipaksa HTTPS.

**Cara 2 — lewat Apache `.htaccess` (redirect lebih cepat, hemat resource):**

Buka `public_html/.htaccess` (di root `htdocs`), hapus tanda `#` pada blok paling atas:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{HTTP:X-Forwarded-Proto} !https
    RewriteCond %{HTTPS} off
    RewriteCond %{HTTP:CF-Visitor} !{"scheme":"https"}
    RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
</IfModule>
```

Kombinasi ketiga kondisi di atas mencegah **redirect loop** saat website berada di belakang
reverse proxy (InfinityFree) atau Cloudflare mode *Flexible*.

> ⚠️ **Jangan** mengaktifkan salah satu dari dua cara ini sebelum SSL benar-benar terpasang,
> karena website jadi tidak bisa dibuka sama sekali.

---

## 4️⃣ Verifikasi hasil

1. Buka `http://domain-anda.com` → harus otomatis berpindah ke `https://domain-anda.com`.
2. Cek alamat di browser: tidak ada lagi tulisan **"Tidak aman"**, muncul **gembok 🔒**.
3. Buka **DevTools (F12) → Console**: tidak boleh ada peringatan `Mixed Content`.
4. Buka **DevTools → Network → centang "Disable cache" → reload**: pastikan kolom *Protocol*
   seluruh request bernilai `h2`/`http/1.1` dengan skema `https`.
5. Uji dengan SSL checker online (mis. `ssllabs.com/ssltest` atau `whynopadlock.com`) untuk melihat
   detail sertifikat, masa berlaku, dan masalah rantai sertifikat.
6. Klik semua halaman penting (Login, Dashboard Admin, Detail Outing, Upload Bukti Bayar, Export PDF)
   dan pastikan tidak ada yang kembali ke `http://`.

---

## 5️⃣ Troubleshooting

**a. Masih muncul "Tidak aman" padahal URL sudah `https://`** → *Mixed content.*
Ada aset (gambar/script/CSS/font) yang dimuat lewat `http://`. Cara menemukan:
- DevTools (F12) → Console → cari pesan `Mixed Content: The page at ... was loaded over HTTPS,
  but requested an insecure ...`.
- Perbaiki URL menjadi `https://` atau gunakan **protocol-relative** (`//domain/file.js`).
- Catatan proyek ini: seluruh aset internal sudah dibangun dengan `base_url()` yang otomatis
  mengikuti skema request, dan Bootstrap/Bootstrap Icons dimuat dari CDN `https://` — jadi
  mixed content biasanya berasal dari konten yang Anda tambahkan sendiri (gambar/link manual
  di database atau HTML).

**b. `ERR_SSL_PROTOCOL_ERROR` / website tidak bisa dibuka setelah mengaktifkan HTTPS**
→ Sertifikat belum terpasang (atau belum aktif). Solusi: set kembali `FORCE_HTTPS` menjadi `false`
di `config/site.php` (dan/atau beri `#` pada blok rewrite di `.htaccess`), lalu ulangi **Bagian 2**.

**c. Redirect loop / `ERR_TOO_MANY_REDIRECTS`**
→ Biasanya karena Cloudflare mode *Flexible* + rewrite yang hanya mengecek `%{HTTPS}`.
Gunakan blok rewrite dari **Bagian 3 Cara 2** (memakai `X-Forwarded-Proto` & `CF-Visitor`),
atau ubah mode SSL Cloudflare ke **Full**.

**d. `NET::ERR_CERT_COMMON_NAME_INVALID`**
→ Sertifikat dibuat untuk domain yang berbeda. Pastikan nama domain saat *Create Order* sama persis
dengan domain yang dipakai membuka website (termasuk `www` vs tanpa `www` dan subdomain gratis lain:
tiap subdomain seperti `nama.epizy.com` dan `nama.rf.gd` butuh sertifikat masing-masing).

**e. `NET::ERR_CERT_DATE_INVALID` / sertifikat kedaluwarsa**
→ SSL gratis berlaku ±90 hari. Perbarui lewat Client Area (**SSL Certificates** → domain Anda →
**Renew / Request SSL Certificate**) lalu install ulang. Pasang pengingat kalender tiap 2 bulan.

**f. Gembok sudah ada tapi browser masih menampilkan peringatan lama**
→ Chrome menyimpan status sertifikat & redirect 301 (cache). Coba:
- Hard reload `Ctrl + Shift + R`, atau buka **Incognito/Private window**.
- Bersihkan cache: `chrome://settings/clearBrowserData` → *Cached images and files*.
- Tutup-buka browser (HSTS/301 bisa tersimpan beberapa jam).

**g. Halaman login masih dianggap tidak aman di Chrome**
→ Chrome memberi label lebih tegas pada halaman HTTP yang memiliki form input. Setelah HTTPS aktif
label tersebut hilang dengan sendirinya.

---

## 6️⃣ Bonus keamanan yang sudah disertakan proyek ini

| Berkas | Fungsi |
| :--- | :--- |
| `public_html/.htaccess` | Redirect HTTPS (opsional), header keamanan (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, `HSTS` saat HTTPS), mematikan *directory listing*, memblokir akses ke file `.sql/.md/.log` dan folder internal |
| `public_html/config/site.php` | Saklar `FORCE_HTTPS`, daftar host yang dikecualikan (localhost), saklar cookie sesi aman |
| `public_html/config/helpers.php` | `is_https()` (mengenali proxy InfinityFree/Cloudflare), `enforce_https()`, `start_secure_session()`, dan `base_url()` yang otomatis memakai skema benar |
| `public_html/config/.htaccess`, `includes/.htaccess`, `libs/.htaccess` | Menolak akses langsung lewat browser ke folder internal aplikasi |

Dengan cookie sesi `Secure` + `HttpOnly` + `SameSite=Lax`, cookie login tidak bisa dibaca JavaScript
dan tidak dikirim lewat koneksi HTTP — ini penting karena aplikasi menyimpan data pembayaran iuran.

> ⚠️ **Penting:** kredensial database (`config/database.php`) **jangan ikut di-commit ke repositori publik**.
> Simpan file itu di server saja, atau gunakan `.gitignore`, lalu **ganti password MySQL/vPanel** bila
> pernah terlanjur dipublikasikan.
