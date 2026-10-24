# Pondok

**Pondok** adalah sistem informasi terpadu untuk pesantren dan sekolah. Aplikasi ini mengelola data santri dan kegiatan lembaga melalui panel admin, menyediakan portal publik dan penerimaan santri baru (PSB), serta membantu sekolah mengelola asrama, dokumen, dan keuangan.

Pondok bersifat **single-tenant**: satu instalasi digunakan oleh satu lembaga. Aplikasi dirancang agar dapat dijalankan di shared hosting cPanel atau VPS kecil, tanpa Redis, worker yang terus berjalan, maupun Node.js di server produksi.

## Situs resmi

- [pondok.org](https://pondok.org) — informasi tentang Pondok.
- [Dokumentasi](https://pondok.org/docs)
- [Fitur](https://pondok.org/fitur)
- [Blog](https://pondok.org/blog)

## Fitur

- **Akademik dan santri** — jenjang, tahun ajaran, angkatan, rombongan belajar, santri, wali, staf, riwayat kelas, dan absensi.
- **Asrama** — asrama, kamar, penempatan santri, musyrif, perizinan, kunjungan wali, dan pencatatan kesehatan.
- **Penerimaan santri baru (PSB)** — informasi pendaftaran publik, gelombang, jalur, formulir, berkas, status pendaftaran, dan proses penerimaan.
- **Keuangan** — jenis biaya, tarif, beasiswa/keringanan, tagihan, pembayaran, alokasi cicilan, dan laporan.
- **Tahfidz dan prestasi** — halaqah, setoran dan ujian tahfidz, capaian hafalan, serta prestasi santri.
- **Dokumen** — berkas pribadi santri dan pendaftar, surat keluar, serta verifikasi surat.
- **Konten dan portal publik** — identitas lembaga, halaman, berita, album foto, video, navigasi, dan tampilan situs.
- **Portal wali** — informasi santri, tagihan, dan unduhan dokumen sesuai akses yang diberikan.
- **Akses dan administrasi** — peran dan izin, cakupan akses per jenjang, pengaturan modul, dashboard, pencatatan aktivitas, dan backup.

Modul yang dapat dinyalakan atau dimatikan di pengaturan dapat disesuaikan dengan kebutuhan lembaga. Hak akses tetap ditentukan oleh peran pengguna dan cakupan jenjangnya. Ketersediaan fitur dapat berubah seiring pengembangan; lihat [PRD](docs/prd.md) dan [daftar fitur](feature_list.json) untuk konteksnya.

## Teknologi

- Laravel 13 dan PHP 8.3+
- Filament 5 untuk panel admin
- Blade, Livewire, dan Tailwind CSS 4 untuk portal
- Spatie Laravel Permission untuk peran dan izin
- Pest untuk pengujian
- MySQL/MariaDB untuk produksi; SQLite untuk pengembangan. Kode juga ditujukan agar kompatibel dengan PostgreSQL.

## Menjalankan secara lokal

### Prasyarat

- PHP 8.3 atau lebih baru dengan ekstensi Laravel yang diperlukan
- Composer 2
- Node.js dan pnpm (hanya untuk membangun aset)
- SQLite, MySQL, atau MariaDB

### Instalasi

```sh
git clone https://github.com/dankedev/pondok.git
cd pondok
composer install
pnpm install
cp .env.example .env
```

Untuk menggunakan SQLite, ubah konfigurasi berikut di `.env`:

```dotenv
DB_CONNECTION=sqlite
DB_DATABASE=database/database.sqlite
```

Pastikan file database tersedia (`touch database/database.sqlite`), kemudian buat kunci aplikasi dan pasang aplikasi dengan akun super-admin lokal:

```sh
php artisan key:generate
php artisan pondok:install \
  --admin-name="Super Admin" \
  --admin-email=admin@pondok.test \
  --admin-password='ganti-password-lokal'
pnpm run build
```

Ganti password contoh sebelum menjalankan perintah. Untuk database MySQL/MariaDB, isi `DB_*` di `.env` sebelum menjalankan instalasi. Perintah `pondok:install` menjalankan migrasi, menyiapkan data sistem dan akun super-admin, serta mengatur penyimpanan publik.

Jalankan aplikasi dan buka `http://127.0.0.1:8000`:

```sh
php artisan serve
```

Untuk mengembangkan aset secara langsung, jalankan `pnpm run dev` di terminal terpisah. Akses panel admin melalui `/admin`.

### Data demo

Untuk mencoba data contoh, jalankan seeder demo setelah instalasi:

```sh
php artisan db:seed --class=DemoSeeder
```

Data dan akun yang dibuat sepenuhnya fiktif. Detail isi demo, akun, password awal, dan cara mengulang database tersedia di [panduan data demo](docs/demo.md). **Jangan gunakan data demo atau password bawaannya pada instalasi yang dapat diakses publik.** `DemoSeeder` menolak berjalan ketika `APP_ENV=production`.

## Instalasi produksi

Untuk cPanel, gunakan paket rilis dan ikuti [panduan instalasi cPanel](docs/instalasi.md). Untuk membuat paket rilis, lihat [panduan rilis](docs/rilis.md). Tersedia juga [Docker Compose](docs/deploy-docker.md).

Hal penting untuk pengelola:

- Gunakan HTTPS, `APP_ENV=production`, dan `APP_DEBUG=false`.
- Dokumen pribadi disimpan di disk private; jangan letakkan folder aplikasi atau arsip backup di document root publik.
- Jadwalkan `php artisan schedule:run` setiap menit. Scheduler juga menjalankan antrean singkat; tidak perlu worker permanen.
- Simpan backup database, storage, file `.env`, dan `APP_KEY` secara aman. Tanpa `APP_KEY` yang sama, data terenkripsi tidak dapat dibaca setelah pemulihan.
- Jangan menjalankan `migrate:fresh` pada data lembaga yang digunakan.

Untuk pemeriksaan instalasi, jalankan `php artisan pondok:doctor`. Rincian persyaratan server, pembaruan, backup, dan pemulihan ada di panduan cPanel.

## Pengujian dan kualitas kode

Jalankan pengujian Pest:

```sh
php artisan test --compact
```

Format kode PHP dengan Laravel Pint:

```sh
vendor/bin/pint
```

## Dokumentasi

- [Panduan instalasi cPanel](docs/instalasi.md)
- [Panduan deploy Docker Compose](docs/deploy-docker.md)
- [Panduan rilis](docs/rilis.md)
- [Panduan data demo](docs/demo.md)
- [PRD — kebutuhan produk](docs/prd.md)
- [ERD — skema dan relasi data](docs/erd.md)
- [Panduan desain UI](docs/design-guide.md)

## Kontribusi

Pondok dikembangkan secara terbuka di [GitHub](https://github.com/dankedev/pondok). Sebelum mengusulkan perubahan, baca dokumentasi dan pola kontribusi yang berlaku di repository. Perubahan fitur sebaiknya disepakati melalui issue agar ruang lingkup dan kriteria penerimaannya jelas.

## Lisensi

Pondok menggunakan lisensi MIT.
