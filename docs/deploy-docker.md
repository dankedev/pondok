# Deploy Pondok dengan Docker Compose

Compose menjalankan dua layanan: `app` (Laravel + Apache, PHP 8.4) dan `db` (MySQL 8.4). Tidak ada Redis atau worker terpisah; container `app` menjalankan scheduler sendiri di samping Apache (lihat [Scheduler](#scheduler)). Pasang reverse proxy dengan HTTPS di depan `app`. Port MySQL tidak dipublikasikan ke host.

Data tersimpan di dua named volume: `mysql_data` (database) dan `pondok_storage` (unggahan, dokumen santri, log). Nama aslinya diawali nama project Compose, mis. `pondok_mysql_data`; lihat dengan `docker volume ls`.

## Rahasia dan konfigurasi

Semua rahasia diberikan saat runtime. Image tidak berisi `.env` asli, APP_KEY, atau password; `.env` diabaikan Git dan build context.

1. Siapkan Docker Engine dan Docker Compose v2.
2. Salin contoh lalu isi nilainya:

   ```sh
   cp .env.docker.example .env
   chmod 600 .env
   ```

   Wajib: `APP_URL`, `APP_KEY`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `SUPER_ADMIN_EMAIL`, `SUPER_ADMIN_PASSWORD`. Opsional: `APP_PORT` (bawaan `8080`) dan nilai lain dari `.env.production.example`, mis. `MAIL_*`.

   Buat APP_KEY tanpa PHP di host:

   ```sh
   echo "base64:$(openssl rand -base64 32)"
   ```

   Simpan APP_KEY di tempat aman. Tanpa kunci yang sama, data terenkripsi (NIK, nomor KK) tidak bisa dibaca.

3. **Jangan isi `DB_ROOT_PASSWORD` di `.env`.** `.env` ikut dipasang ke container `app`, sedangkan password root hanya untuk MySQL. Berikan lewat shell saat instalasi pertama saja (lihat di bawah). Setelah volume database terbentuk, MySQL tidak membacanya lagi.

Aplikasi diakses lewat HTTPS, jadi cookie sesi bawaan hanya dikirim lewat HTTPS. Untuk uji coba lewat `http://` tanpa TLS, isi sementara `SESSION_SECURE_COOKIE=false`.

## Instalasi pertama

```sh
export DB_ROOT_PASSWORD='isi-password-root-yang-kuat'
docker compose up -d --build
unset DB_ROOT_PASSWORD
docker compose ps
```

Saat container `app` start, entrypoint menyiapkan folder storage, lalu menjalankan `pondok:install --skip-if-installed` (migrasi, data awal, super-admin, storage link; hanya sekali) dan `pondok:upgrade --force`, lalu scheduler dan Apache. Tunggu sampai kedua layanan `healthy` (`app` memakai endpoint `/up`). Log instalasi: `docker compose logs app`.

Bila `db` gagal start pada instalasi pertama dengan pesan bahwa salah satu dari `MYSQL_ROOT_PASSWORD`, `MYSQL_ALLOW_EMPTY_PASSWORD`, atau `MYSQL_RANDOM_ROOT_PASSWORD` harus diisi, berarti `DB_ROOT_PASSWORD` belum diekspor. Ekspor lalu ulangi `docker compose up -d`.

Setelah berhasil login di `/admin`, hapus nilai `SUPER_ADMIN_PASSWORD` dari `.env` lalu `docker compose up -d app`. Akun yang sudah dibuat tidak berubah.

## Scheduler

Pondok memerlukan `schedule:run` setiap menit. Scheduler juga menjalankan worker antrean singkat (`queue:work --stop-when-empty`), jadi email yang antre (lupa password, undangan portal wali), backup terjadwal, dan tugas lain ikut berjalan. Tanpa scheduler, email tertahan di tabel `jobs` walau halaman menampilkan "email terkirim".

Image sudah menjalankan scheduler sendiri: setelah install/upgrade, entrypoint menjalankan `php artisan schedule:work` sebagai `www-data` di background, di samping Apache. Bila prosesnya berhenti, entrypoint menjalankannya ulang setelah 5 detik dan mencatat `Scheduler (schedule:work) berhenti` di log container. Tidak perlu cron host.

Saat start, entrypoint juga menjalankan `schedule:clear-cache` untuk menghapus kunci `withoutOverlapping` yang tertinggal bila container sebelumnya mati di tengah tugas. Job yang terputus karena container berhenti dikembalikan ke antrean dan dicoba lagi.

**Memeriksa:**

```sh
docker compose exec -u www-data app php artisan pondok:doctor
docker compose exec -u www-data app php artisan tinker --execute 'echo DB::table("jobs")->count();'
```

Baris scheduler di `pondok:doctor` menampilkan "Cron berjalan" bila scheduler berjalan dalam 5 menit terakhir (pesannya sama untuk cron cPanel). Jumlah baris `jobs` seharusnya kembali ke 0 dalam ±1 menit setelah ada email yang antre.

**Mematikan** (mis. platform yang menjalankan `schedule:run` sendiri, atau replika container tambahan): isi environment `PONDOK_SCHEDULER=false`, lalu jalankan `schedule:run` tiap menit sebagai `www-data` dari luar, mis. cron host:

```cron
* * * * * cd /srv/pondok && docker compose exec -T -u www-data app php artisan schedule:run >> /dev/null 2>&1
```

Jangan menjalankan keduanya sekaligus. Bila sebelumnya sudah ada cron host atau *scheduled task* panel untuk `schedule:run`, hapus setelah memakai image ini, atau isi `PONDOK_SCHEDULER=false`.

Perintah artisan lain juga dijalankan dengan `docker compose exec -u www-data app php artisan ...`.

## Backup

Named volume bukan backup. Jadwalkan backup harian, simpan di luar direktori proyek, enkripsi, dan salin ke lokasi lain sesuai kebijakan lembaga. Backup memakai user aplikasi, bukan root:

```sh
docker compose exec -T db sh -c \
  'mysqldump --single-transaction --no-tablespaces -u "$MYSQL_USER" --password="$MYSQL_PASSWORD" "$MYSQL_DATABASE"' \
  > /path/backup-aman/pondok-db-$(date +%F).sql
docker compose exec -T app tar -czf - -C /var/www/html/storage . \
  > /path/backup-aman/pondok-storage-$(date +%F).tar.gz
```

Simpan juga `.env` (terutama APP_KEY) di tempat aman yang terpisah.

Alternatif tanpa `mysqldump`: `docker compose exec -u www-data app php artisan pondok:backup` membuat satu arsip zip berisi data dan berkas di `storage/app/private/backups`; cara memulihkannya ada di [instalasi.md bagian Backup dan pemulihan](instalasi.md#11-backup-dan-pemulihan).

## Restore

Hentikan app, pulihkan database dan storage, lalu nyalakan lagi:

```sh
docker compose stop app
cat /path/backup-aman/pondok-db.sql | docker compose exec -T db sh -c \
  'mysql -u "$MYSQL_USER" --password="$MYSQL_PASSWORD" "$MYSQL_DATABASE"'
cat /path/backup-aman/pondok-storage.tar.gz | docker compose run --rm -T --no-deps --entrypoint tar app -xzf - -C /var/www/html/storage
docker compose up -d app
```

Restore database ke database yang kosong (instalasi baru dengan `DB_*` yang sama). Entrypoint memperbaiki kepemilikan folder storage saat `app` start.

## Upgrade

1. Backup database dan storage (lihat di atas).
2. Ambil versi kode baru (`git pull` atau ekstrak paket rilis).
3. Bangun image dan ganti container:

   ```sh
   docker compose build app
   docker compose up -d app
   ```

Entrypoint menjalankan `pondok:upgrade --force` (migrasi baru dan data sistem) sebelum Apache. Periksa `docker compose logs app` dan `docker compose ps`. Volume tidak disentuh dan `migrate:fresh` tidak pernah dijalankan.

**Jangan pernah** memakai `docker compose down -v` atau `docker volume rm` pada instalasi yang datanya dipakai: keduanya menghapus database dan unggahan. `docker compose down` tanpa `-v` aman.

## Menjaga data saat build ulang

Setiap container start, entrypoint menjalankan `pondok:install --skip-if-installed` (berhenti tanpa perubahan bila super-admin sudah ada), lalu `pondok:upgrade --force`. Data lembaga tidak pernah dihapus, dan data bawaan yang sudah dihapus admin tidak dibuat ulang.

Pada platform seperti Coolify atau Dokploy:

- Isi `APP_ENV=production`. Hanya di production, `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe`, dan seeder demo ditolak. Entrypoint menampilkan peringatan bila `APP_ENV` bukan `production`.
- Jangan mengisi perintah *pre/post deployment* dengan `migrate:fresh`, `db:seed`, atau `DemoSeeder`. Entrypoint sudah menangani migrasi.
- Pastikan database memakai volume persisten (mis. `mysql_data`) atau layanan database terpisah, bukan penyimpanan di dalam container aplikasi.
- Data yang sudah terhapus hanya bisa dipulihkan dari backup.

## Panel berbasis Dockerfile (Easypanel dan sejenisnya)

Panel seperti Easypanel, Coolify, atau Dokploy bisa membangun `Dockerfile` langsung tanpa Compose. Perilaku image sama: entrypoint memasang sekali lalu upgrade setiap start.

- **Port:** container mendengarkan HTTP di port `80`; arahkan domain/proxy panel ke port itu.
- **Environment runtime wajib:** `APP_ENV=production`, `APP_KEY`, `APP_URL`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, serta `SUPER_ADMIN_NAME`, `SUPER_ADMIN_EMAIL`, `SUPER_ADMIN_PASSWORD` untuk instalasi pertama. Isi di menu environment panel, bukan sebagai build argument. Nilai lain dari `.env.production.example` (mis. `MAIL_*`) boleh ditambahkan. `DB_CONNECTION=mysql` sudah bawaan image.
- **Database:** pakai layanan MySQL/MariaDB dari panel dengan volume persisten, dan isi `DB_HOST` dengan nama host internal layanan itu.
- **Volume persisten:** pasang volume ke `/var/www/html/storage`. Tanpa volume ini, unggahan dan dokumen santri hilang setiap deploy. Bila volume belum terpasang, log container menampilkan `PERINGATAN: /var/www/html/storage bukan volume persisten...` saat start, dan `pondok:doctor` menampilkan baris **Volume storage** berstatus peringatan. Container tetap jalan; pasang volume lalu deploy ulang sampai peringatan hilang. Peringatan dilewati hanya bila disk `private` dan `public` sama-sama dialihkan ke S3/R2 (`PRIVATE_DISK_DRIVER=s3` dan `PUBLIC_DISK_DRIVER=s3`; lihat [Penyimpanan di Cloudflare R2](#penyimpanan-di-cloudflare-r2)). `FILESYSTEM_DISK=s3` saja tidak cukup karena aplikasi memakai disk bernama `private` dan `public`.
  - Path `/app/storage` atau `/app/storage/app` dari template Laravel lain **tidak berlaku** untuk image Pondok: aplikasi ada di `/var/www/html`, sehingga volume di path itu tidak menyimpan apa pun dan unggahan tetap hilang. Pasang volume tepat ke `/var/www/html/storage` (seluruh folder storage, bukan subfoldernya).
- **Scheduler:** sudah berjalan di dalam container; tidak perlu cron panel. Bila sebelumnya ada cron/scheduled task panel untuk `schedule:run`, hapus, atau isi `PONDOK_SCHEDULER=false` bila ingin tetap memakai cron panel. Cek dengan `php artisan pondok:doctor` di console panel sebagai `www-data` (lihat [Scheduler](#scheduler)).
- **Backup:** pakai fitur backup database panel, dan backup volume storage terpisah.

## Penyimpanan di Cloudflare R2

Opsional. Bawaan Pondok adalah disk lokal; lewati bagian ini bila volume storage cukup. R2 berguna bila disk server kecil (free tier 10 GB, tanpa biaya egress). Semua S3-compatible (AWS S3, MinIO) bisa dipakai dengan variabel yang sama.

1. Di dashboard Cloudflare, buat **dua bucket**: privat untuk dokumen (mis. `pondok-private`) dan publik untuk galeri/sampul/logo (mis. `pondok-public`). **Jangan** aktifkan akses publik pada bucket privat.
2. Pada bucket publik, aktifkan akses publik lewat custom domain (disarankan, mis. `media.contoh.sch.id`) atau domain `r2.dev`.
3. Buat API token R2 (Object Read & Write) yang dibatasi ke kedua bucket. Catat Access Key ID, Secret Access Key, dan endpoint `https://<account-id>.r2.cloudflarestorage.com`.
4. Isi environment:

```env
PRIVATE_DISK_DRIVER=s3
PUBLIC_DISK_DRIVER=s3
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=auto
AWS_ENDPOINT=https://<account-id>.r2.cloudflarestorage.com
AWS_USE_PATH_STYLE_ENDPOINT=true
AWS_BUCKET=pondok-private
AWS_PUBLIC_BUCKET=pondok-public
AWS_PUBLIC_URL=https://media.contoh.sch.id
```

Keduanya bisa dialihkan terpisah (mis. hanya `PRIVATE_DISK_DRIVER`), tetapi peringatan volume baru hilang bila keduanya S3. Dokumen pribadi tetap diunduh lewat route aplikasi yang dicek permission, bukan lewat URL bucket.

- **CORS:** tidak perlu, karena unggahan lewat server aplikasi. Bila gambar publik dimuat dari domain lain lewat skrip, tambahkan aturan `GET` untuk domain itu di bucket publik.
- **Instalasi yang sudah berjalan:** berkas lama tidak pindah otomatis. Salin isi `storage/app/private` ke bucket privat dan `storage/app/public` ke bucket publik (path sama), mis. dengan `rclone copy`, sebelum mengganti environment.
- **Backup:** backup bawaan (`pondok:backup`) hanya mendukung disk `private` lokal dan akan menolak berjalan bila `PRIVATE_DISK_DRIVER=s3`. Backup database dengan `mysqldump`, dan aktifkan versioning atau replikasi di sisi bucket.

## Catatan healthcheck

Healthcheck `db` memakai `mysqladmin ping` lewat TCP dengan user aplikasi. Ping hanya membuktikan server MySQL hidup; kredensial yang salah terlihat dari `app` yang gagal install/upgrade di `docker compose logs app`.
