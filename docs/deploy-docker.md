# Deploy Pondok dengan Docker Compose

Compose menjalankan dua layanan: `app` (Laravel + Apache, PHP 8.4) dan `db` (MySQL 8.4). Tidak ada Redis, worker, atau scheduler yang berjalan terus; scheduler dipicu cron host setiap menit. Pasang reverse proxy dengan HTTPS di depan `app`. Port MySQL tidak dipublikasikan ke host.

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

Saat container `app` start, entrypoint menyiapkan folder storage, lalu menjalankan `pondok:install --skip-if-installed` (migrasi, data awal, super-admin, storage link; hanya sekali) dan `pondok:upgrade --force`, lalu Apache. Tunggu sampai kedua layanan `healthy` (`app` memakai endpoint `/up`). Log instalasi: `docker compose logs app`.

Bila `db` gagal start pada instalasi pertama dengan pesan bahwa salah satu dari `MYSQL_ROOT_PASSWORD`, `MYSQL_ALLOW_EMPTY_PASSWORD`, atau `MYSQL_RANDOM_ROOT_PASSWORD` harus diisi, berarti `DB_ROOT_PASSWORD` belum diekspor. Ekspor lalu ulangi `docker compose up -d`.

Setelah berhasil login di `/admin`, hapus nilai `SUPER_ADMIN_PASSWORD` dari `.env` lalu `docker compose up -d app`. Akun yang sudah dibuat tidak berubah.

## Scheduler (cron host)

Pondok memerlukan `schedule:run` setiap menit. Scheduler juga menjalankan worker antrean singkat (`queue:work --stop-when-empty`), jadi tidak perlu worker terpisah. Tambahkan ke crontab user yang boleh menjalankan Docker (`crontab -e`):

```cron
* * * * * cd /srv/pondok && docker compose exec -T -u www-data app php artisan schedule:run >> /dev/null 2>&1
```

Ganti `/srv/pondok` dengan direktori yang berisi `docker-compose.yml` dan `.env`. Jalankan sebagai `www-data` agar berkas cache dan log tidak dimiliki root. Pada platform seperti Coolify atau Dokploy, buat *scheduled task* tiap menit dengan perintah `php artisan schedule:run` di container `app` sebagai `www-data`. Cek hasilnya dengan `docker compose exec -u www-data app php artisan pondok:doctor`.

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

## Catatan healthcheck

Healthcheck `db` memakai `mysqladmin ping` lewat TCP dengan user aplikasi. Ping hanya membuktikan server MySQL hidup; kredensial yang salah terlihat dari `app` yang gagal install/upgrade di `docker compose logs app`.
