# Panduan instalasi Pondok di hosting cPanel

Panduan ini untuk operator sekolah yang memasang Pondok di shared hosting cPanel. Tidak perlu staf IT khusus, tetapi Anda perlu akses ke panel cPanel (File Manager, MySQL Databases, Cron Jobs, dan bila ada, Terminal).

Contoh di panduan ini memakai akun cPanel `akun` dengan folder rumah `/home/akun` dan domain `pondok.sch.id`. Sesuaikan dengan milik Anda.

## 1. Kebutuhan server

- **PHP 8.3 atau lebih baru.** Di cPanel pilih versi `ea-php83` lewat **MultiPHP Manager** untuk domain Anda.
- **Ekstensi PHP** yang harus aktif: `ctype`, `dom`, `fileinfo`, `filter`, `hash`, `iconv`, `intl`, `json`, `libxml`, `mbstring`, `openssl`, `pcre`, `session`, `tokenizer`, `xmlreader`, `simplexml`, `zip`, `pdo`, dan `pdo_mysql`. Biasanya diatur di menu **Select PHP Version** atau **MultiPHP INI Editor**; bila tidak ada, minta penyedia hosting mengaktifkannya.
- **Database MySQL 8.0 atau lebih baru, atau MariaDB 10.6 atau lebih baru.**
- **Batas PHP:** `upload_max_filesize` minimal `5M` (unggahan dokumen santri), `post_max_size` tidak lebih kecil dari `upload_max_filesize`, dan `memory_limit` minimal `128M`. Atur di **MultiPHP INI Editor**.
- **Akses Terminal** di cPanel. Bila hosting tidak menyediakan Terminal, semua perintah bisa dijalankan lewat **Cron Jobs** sekali jalan (lihat langkah 6).

Pondok tidak membutuhkan Redis, Node.js, atau layanan yang berjalan terus-menerus. Antrean, cache, dan sesi memakai database, dan semua tugas terjadwal berjalan dari satu cron.

## 2. Membuat database dan user MySQL

1. Buka **MySQL Databases** di cPanel.
2. Buat database baru, misalnya `akun_pondok`.
3. Buat user database baru, misalnya `akun_pondok`, dengan password yang kuat. Simpan passwordnya.
4. Tambahkan user tersebut ke database dengan hak **ALL PRIVILEGES**.

## 3. Mengunggah dan mengekstrak paket

1. Unduh paket rilis `pondok-<versi>.zip` (dan berkas `.sha256` bila ingin mencocokkan checksum).
2. Buka **File Manager**, masuk ke folder rumah `/home/akun` (**bukan** `public_html`).
3. Unggah zip lalu pilih **Extract**. Hasilnya folder `/home/akun/pondok`.

Folder aplikasi harus berada **di luar** `public_html`. Di dalam paket ada berkas `.htaccess` di root aplikasi yang menolak semua akses. Bila setelah instalasi semua halaman menampilkan **403 Forbidden**, artinya aplikasi terekstrak di dalam folder web (misalnya `public_html/pondok`). Pindahkan folder itu ke `/home/akun/pondok` dan atur document root seperti langkah 4. Pengecualian: bila document root sudah benar (`/home/akun/pondok/public`) tetapi tetap 403 (sebagian server mewariskan `.htaccess` folder induk), hapus berkas `/home/akun/pondok/.htaccess`.

## 4. Mengatur document root

Situs harus dibuka dari folder `public` aplikasi, bukan dari folder aplikasi. Pilih salah satu cara.

**Cara A (disarankan): arahkan document root ke folder `public`.**
Di menu **Domains** (atau **Subdomains** / **Addon Domains**), ubah document root domain menjadi `/home/akun/pondok/public`.

**Cara B: document root tidak bisa diubah (`public_html`).**
1. Isi di `.env`: `PONDOK_PUBLIC_PATH=/home/akun/public_html`
2. Setelah instalasi (langkah 6), jalankan:
   ```
   php artisan pondok:publish-public
   ```
   Perintah ini menyalin isi `public/` (aset `public/build`, Filament, ikon) ke `public_html` dan menulis `index.php` yang menunjuk ke folder aplikasi. Folder lain di `public_html` (misalnya `cgi-bin`, `.well-known`) tidak disentuh, dan blok `cPanel-generated handler` di `public_html/.htaccess` dipertahankan.

Jangan mengekstrak seluruh aplikasi ke `public_html`: berkas `.env` (berisi password database) bisa terunduh oleh siapa saja.

## 5. Mengisi `.env`

Berkas `.env` berisi pengaturan server. Templatnya `.env.production.example`; `pondok:install` menyalinnya menjadi `.env` secara otomatis bila `.env` belum ada. Buka `.env` lewat File Manager (aktifkan **Show Hidden Files**) lalu isi:

- `APP_URL`: alamat situs **persis sama** dengan yang dibuka pengunjung, termasuk `https://`, misalnya `APP_URL=https://pondok.sch.id`. Alamat ini dipakai QR pada surat dan gambar di portal; bila salah, QR dan gambar tidak berfungsi.
- `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`: dari langkah 2. `DB_HOST` biasanya `localhost`.
- `SUPER_ADMIN_NAME`, `SUPER_ADMIN_EMAIL`, `SUPER_ADMIN_PASSWORD` (minimal 8 karakter): akun pertama yang dibuat saat instalasi.
- `MAIL_*`: pengaturan SMTP akun email cPanel agar email benar-benar terkirim.
- `TRUSTED_PROXIES`: hanya bila situs berada di belakang Cloudflare atau proxy lain. Isi daftar IP proxy dipisah koma, atau `*`.
- `PONDOK_PUBLIC_PATH`: hanya untuk cara B.
- `PUBLIC_STORAGE_ROOT`: hanya bila hosting mematikan `symlink` (lihat langkah 7).

Biarkan `APP_ENV=production` dan `APP_DEBUG=false`.

## 6. Menjalankan instalasi

**Dengan Terminal cPanel:**

```
cd ~/pondok
/opt/cpanel/ea-php83/root/usr/bin/php artisan pondok:install
```

Bila PHP default Terminal sudah 8.3, cukup `php artisan pondok:install`. Perintah ini memeriksa server, membuat `APP_KEY`, membuat tabel, mengisi data awal (jenjang, tahun ajaran, peran, pengaturan), membuat akun super-admin, dan menghubungkan folder unggahan publik. Aman dijalankan ulang: data yang sudah ada tidak ditimpa dan password super-admin tidak diubah.

Bila `SUPER_ADMIN_*` di `.env` kosong, perintah menanyakan nama, email, dan password. Bisa juga memakai opsi `--admin-name`, `--admin-email`, dan `--admin-password`.

**Tanpa Terminal (cron sekali jalan):** di **Cron Jobs**, tambahkan cron setiap menit:

```
/opt/cpanel/ea-php83/root/usr/bin/php /home/akun/pondok/artisan pondok:install --no-interaction >> /home/akun/pondok/storage/logs/install.log 2>&1
```

Tunggu satu sampai dua menit, baca `storage/logs/install.log` di File Manager, lalu **hapus cron tersebut**.

Setelah berhasil login di `https://pondok.sch.id/admin`, hapus nilai `SUPER_ADMIN_PASSWORD` dari `.env`.

## 7. Folder unggahan publik (`storage:link`)

Foto portal dan gambar lain disimpan di `storage/app/public` dan ditampilkan lewat tautan `public/storage`. `pondok:install` membuat tautan ini otomatis (setara `php artisan storage:link`).

Bila hosting mematikan fungsi `symlink`, instalasi tetap selesai tetapi menampilkan peringatan dan baris yang perlu ditambahkan ke `.env`, misalnya:

```
PUBLIC_STORAGE_ROOT=/home/akun/pondok/public/storage
```

(untuk cara B: `/home/akun/public_html/storage`). Dengan pengaturan ini unggahan publik disimpan langsung di folder web tanpa symlink. Jalankan ulang `pondok:install` setelah mengubah `.env`. Dokumen pribadi santri tetap disimpan di luar folder web.

## 8. Cron

Pondok hanya butuh **satu cron** yang berjalan setiap menit. Di **Cron Jobs**, pilih "Once Per Minute" lalu isi:

```
/opt/cpanel/ea-php83/root/usr/bin/php /home/akun/pondok/artisan schedule:run >> /dev/null 2>&1
```

Di sebagian server path PHP 8.3 adalah `/usr/local/bin/ea-php83`. Pastikan path ini PHP 8.3, karena PHP default cron bisa versi lain.

Cron ini menjalankan antrean tugas (email, dsb.), membuat tagihan bulanan setiap tanggal 1, dan mencatat bahwa cron hidup (dibaca `pondok:doctor`).

**Bila hosting mematikan `proc_open`**, `schedule:run` tidak bisa menjalankan perintah lain. Ganti dengan dua cron terpisah:

```
* * * * *  /opt/cpanel/ea-php83/root/usr/bin/php /home/akun/pondok/artisan queue:work --stop-when-empty --max-time=50 >> /dev/null 2>&1
0 1 1 * *  /opt/cpanel/ea-php83/root/usr/bin/php /home/akun/pondok/artisan keuangan:generate-invoices >> /dev/null 2>&1
```

## 9. HTTPS dan pemeriksaan

1. Aktifkan sertifikat gratis lewat **SSL/TLS Status** (AutoSSL) di cPanel, lalu pastikan `APP_URL` memakai `https://`.
2. Jalankan pemeriksaan:
   ```
   php artisan pondok:doctor
   ```
   Hasilnya tabel berisi status OK, Peringatan, Gagal, atau Dilewati untuk versi PHP, ekstensi, koneksi database, folder yang bisa ditulis, aset, folder unggahan, cron, antrean, batas unggah, dan email. Perbaiki semua baris **Gagal**. Catatan: nilai batas PHP yang dibaca dari Terminal bisa berbeda dari situs; batas situs diatur di MultiPHP INI Editor.

## 10. Memperbarui ke versi baru

1. **Buat backup** lebih dulu: database (menu **Backup** cPanel atau ekspor lewat phpMyAdmin), folder `storage`, berkas `.env`, dan `public/.htaccess`.
2. Hapus folder kode lama di `/home/akun/pondok`: `app`, `bootstrap/cache/*.php`, `config`, `database`, `lang`, `public/build`, `resources`, `routes`, dan `vendor`. Jangan hapus `.env` dan `storage`.
3. Unggah dan ekstrak zip versi baru di `/home/akun` (menimpa folder `pondok`).
4. **Segera** jalankan:
   ```
   php artisan pondok:upgrade --force
   ```
   Perintah ini menutup situs sementara (mode pemeliharaan), memperbarui tabel, menambah permission dan pengaturan baru, mengembalikan blok `cPanel-generated handler` ke `public/.htaccess` yang tertimpa zip, menyalin ulang `public_html` (cara B), membersihkan cache, lalu membuka situs kembali. Tanpa `--force`, perintah menanyakan apakah backup sudah dibuat.
5. Bila pembaruan gagal, situs tetap dalam mode pemeliharaan. Perbaiki penyebabnya lalu jalankan ulang perintah yang sama (aman diulang). Bila perlu memulihkan backup, buka situs dengan `php artisan up` setelah selesai.

Catatan: pembaruan mengembalikan permission **peran bawaan** (super-admin, admin sekolah, dst.) ke definisi aplikasi. Peran buatan sendiri tidak berubah. Pengaturan yang sudah diubah dari panel tidak ditimpa.

Bila situs error 500 setelah pembaruan, pilih ulang PHP 8.3 di **MultiPHP Manager** untuk menulis ulang blok handler di `.htaccess`.

## 11. Backup dan pemulihan

Pondok bisa membuat arsip backup sendiri tanpa `mysqldump`, cukup dari cron `schedule:run` (langkah 8). Fitur ini mendukung MySQL, MariaDB, dan SQLite. Untuk PostgreSQL, pakai `pg_dump` atau fitur backup panel hosting.

**Mengaktifkan backup terjadwal.** Masuk sebagai super-admin, buka **Pengaturan → Backup & masa simpan**, nyalakan **Backup terjadwal**, pilih frekuensi (**Mingguan** disarankan), lalu simpan. Backup berjalan pukul 01.30 WIB pada hari yang sesuai frekuensi. Hanya 3 arsip terbaru yang disimpan, karena kuota hosting biasanya kecil, jadi kuota yang terpakai sama untuk semua pilihan; yang berbeda adalah rentang riwayatnya:

| Frekuensi | Hari jalan | Rentang riwayat |
|---|---|---|
| Harian | setiap hari | ±3 hari |
| Mingguan (disarankan) | setiap Ahad | ±3 minggu |
| Bulanan | setiap tanggal 1 | ±3 bulan, tetapi perubahan hingga sebulan terakhir bisa hilang |

Bila frekuensi belum pernah dipilih (termasuk instalasi lama), backup berjalan harian. Arsip dari backup manual ikut dihitung dalam 3 arsip itu, jadi satu backup manual menggeser satu arsip terjadwal tertua. Bila cron tidak berjalan pada hari jatuh tempo (mis. hosting mati pada hari Ahad), backup siklus itu terlewat dan baru dibuat pada jadwal berikutnya; jalankan backup manual bila perlu. Backup manual kapan saja, apa pun frekuensinya:

```
php artisan pondok:backup
```

**Isi dan lokasi arsip.** Arsip `pondok-YYYYmmdd-His.zip` tersimpan di `storage/app/private/backups` dan berisi:

- `database.sql`: data semua tabel (perintah `DELETE FROM` lalu `INSERT`), tanpa struktur tabel;
- `manifest.json`: waktu backup, jenis database, migration terakhir, dan jumlah baris per tabel;
- `private/`: berkas disk private (dokumen santri, berkas PSB), tanpa folder `backups`, `finance-exports`, dan `livewire-tmp`;
- `public/`: berkas disk public (foto, gambar portal, logo).

Arsip memuat seluruh data pribadi santri dan wali. Jangan memindahkan atau mengekstrak arsip ke `public_html` (termasuk pada cara B di langkah 4), karena berkas di sana bisa diunduh siapa saja. Unduh arsip lewat **File Manager** dan simpan di luar hosting (komputer kantor, penyimpanan awan lembaga). Panel admin sengaja tidak menyediakan tombol unduh.

Berkas `.env` **tidak** ikut arsip. Simpan salinannya terpisah di tempat aman: tanpa `APP_KEY` yang sama, kolom terenkripsi (NIK, nomor KK) tidak bisa dibaca setelah pemulihan.

**Memulihkan dari arsip.**

1. Siapkan database kosong (langkah 2) dan pasang `.env` lama dengan `APP_KEY` yang sama.
2. Pasang kode Pondok **versi yang sama** dengan saat backup dibuat. Migration terakhir tercatat di `manifest.json` (`last_migration`).
3. Buat tabel tanpa data awal:
   ```
   php artisan migrate --force
   ```
   Jangan menjalankan seeder atau `pondok:install`, karena datanya akan dihapus dan diganti isi backup.
4. Impor `database.sql` lewat phpMyAdmin (menu **Import**) atau Terminal (`mysql -u user -p nama_database < database.sql`). Pakai mode SQL bawaan server; jangan aktifkan `NO_BACKSLASH_ESCAPES`. Bila berkas terlalu besar untuk batas unggah phpMyAdmin, unggah dalam bentuk zip atau pakai Terminal.
5. Ekstrak isi folder `private/` dari arsip ke `storage/app/private`, dan isi `public/` ke folder disk public (`storage/app/public`, atau `PUBLIC_STORAGE_ROOT` bila diisi).
6. Jalankan `php artisan optimize:clear`, lalu `php artisan pondok:doctor`.

Yang tidak ikut dipulihkan: antrean tugas yang belum selesai dan riwayat tugas gagal (`jobs`, `failed_jobs`), sesi login (pengguna cukup login ulang), dan cache.

**Masa simpan surat.** Di halaman yang sama, isi **Masa simpan data pribadi surat** (1 sampai 100 tahun). Setiap hari pukul 03.00 WIB, surat keluar yang tanggal terbitnya sudah melewati masa simpan dihapus data pribadinya (nama lengkap, NIS, NISN, jenis kelamin, tempat dan tanggal lahir, jenjang, dan kelas). Surat tetap ada, nomor dan kode verifikasinya tetap berlaku, dan nama santri tampil termasker. Kosongkan isian bila data surat tidak perlu dihapus. Manual: `php artisan pondok:redact-letter-snapshots`. Batasan: isian bebas **keperluan** dan **alasan pembatalan** surat tidak ikut dihapus, jadi jangan menulis data pribadi di sana. Data keuangan tidak pernah dihapus.

**Masa simpan log aktivitas.** Di halaman yang sama, isi **Masa simpan log akademik dan konten** dalam bulan (minimal 24, maksimal 1200). Setiap hari pukul 03.30 WIB, log aktivitas akademik dan konten yang lebih lama dari masa simpan dihapus. Log keuangan, PSB, dokumen, pengaturan, dan sistem tidak pernah dihapus. Kosongkan isian bila log tidak perlu dihapus (bawaan). Manual: `php artisan pondok:prune-activity-log`. Jangan menjalankan `php artisan activitylog:clean` bawaan paket: perintah itu menghapus semua log yang lebih dari setahun, termasuk log keuangan.

## 12. Masalah umum

| Gejala | Penyebab dan solusi |
|---|---|
| Error 500 `Vite manifest not found` | Folder `public/build` tidak ada. Paket tidak lengkap; unggah ulang zip rilis resmi (bukan unduhan kode sumber). Cara B: jalankan ulang `pondok:publish-public`. |
| Gambar tidak tampil | Folder unggahan belum terhubung (jalankan ulang `pondok:install`, atau isi `PUBLIC_STORAGE_ROOT`), atau `APP_URL` tidak sama dengan alamat situs. |
| `Your Composer dependencies require a PHP version >= 8.3` | PHP yang menjalankan perintah atau cron bukan 8.3. Pakai path PHP 8.3 seperti di langkah 8, dan pilih `ea-php83` di MultiPHP Manager. |
| Unggahan dokumen gagal | `upload_max_filesize` atau `post_max_size` terlalu kecil. Naikkan di MultiPHP INI Editor. |
| Perubahan `.env` tidak berpengaruh | Konfigurasi masih ter-cache. Jalankan `php artisan optimize:clear`. |
| Error 500 setelah pembaruan | `public/.htaccess` kehilangan blok `cPanel-generated handler` sehingga situs jatuh ke PHP lama. Jalankan `pondok:upgrade --force`, atau pilih ulang PHP 8.3 di MultiPHP Manager. |
| Semua halaman 403 | Aplikasi terekstrak di dalam folder web (`public_html`). Pindahkan ke luar dan atur document root (langkah 3 dan 4). |
| `pondok:doctor` memberi peringatan cron | Cron `schedule:run` belum ditambahkan atau path PHP salah (langkah 8). |

## 13. Opsional: mempercepat aplikasi

Setelah semua pengaturan final, Anda bisa menjalankan `php artisan optimize` untuk menyimpan cache konfigurasi dan route. Konsekuensinya: setiap kali `.env` diubah, jalankan `php artisan optimize:clear` (lalu `optimize` lagi), karena perubahan `.env` tidak terbaca selama konfigurasi ter-cache. Bila ragu, lewati langkah ini.
