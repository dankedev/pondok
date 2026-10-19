# Deploy Pondok dengan Docker Compose

Compose menjalankan aplikasi Laravel dan MySQL. Gunakan reverse proxy/TLS di host produksi; port MySQL tidak dipublikasikan ke host.

## Konfigurasi awal

1. Siapkan Docker Engine dan Docker Compose v2.
2. Salin template produksi lalu atur minimal `APP_URL`, `APP_KEY`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `SUPER_ADMIN_NAME`, `SUPER_ADMIN_EMAIL`, dan `SUPER_ADMIN_PASSWORD` di `.env`. Buat kunci dengan `php artisan key:generate --show` di mesin yang memiliki PHP.
3. Atur `APP_PORT` bila port host selain `8080` diinginkan. `.env` tidak boleh dimasukkan ke Git.
4. Sediakan `DB_ROOT_PASSWORD` sebagai environment pada host/container manager. Jangan menambahkannya ke `.env`, karena `.env` juga dipasang ke layanan aplikasi. Password root hanya diteruskan ke layanan MySQL.

Contoh persiapan:

```sh
cp .env.production.example .env
```

Ekspor `DB_ROOT_PASSWORD` di shell deployment atau atur melalui secret/environment manager host, lalu jalankan perintah Compose dari direktori proyek yang berisi `.env`.

## Instalasi pertama

```sh
docker compose up -d db
docker compose run --rm app php artisan pondok:install --no-interaction
docker compose up -d app
docker compose ps
```

Installer membaca konfigurasi database dari environment container, menjalankan migrasi dan seeder instalasi, lalu menyiapkan storage. Container aplikasi menyimpan unggahan pada named volume `pondok_storage`; MySQL menggunakan `mysql_data`. Jangan memakai `docker compose down -v` pada instalasi yang datanya ingin dipertahankan.

Setelah berhasil login, hapus `SUPER_ADMIN_PASSWORD` dari `.env`. Aplikasi tetap berjalan, tetapi simpan kata sandi akun dengan aman.

## Operasi rutin

- Aplikasi tersedia pada `http://HOST:APP_PORT` (default `8080`). Gunakan HTTPS melalui reverse proxy.
- Periksa status layanan dengan `docker compose ps`. App menunggu MySQL sehat; healthcheck app memakai endpoint `/up`.
- Jadwalkan `docker compose exec -T app php artisan schedule:run` dari cron host/platform setiap menit. Pondok menjalankan worker database singkat melalui Laravel Scheduler; Compose tidak menjalankan worker atau scheduler sebagai daemon.
- Named volume bukan backup. Tulis backup ke lokasi di luar direktori proyek, lalu enkripsi dan salin ke lokasi terpisah sesuai kebijakan lembaga:

```sh
docker compose exec -T db sh -c \
  'MYSQL_PWD="$MYSQL_PASSWORD" mysqldump --single-transaction --no-tablespaces -u "$MYSQL_USER" "$MYSQL_DATABASE"' \
  > /path/backup-aman/pondok-db.sql
docker compose exec -T app tar -czf - -C /var/www/html/storage . \
  > /path/backup-aman/pondok-storage.tar.gz
```

Untuk restore, hentikan app dan pulihkan database ke database kosong yang telah dibuat MySQL. Restore database dapat dialirkan melalui user aplikasi:

```sh
docker compose stop app
cat /path/backup-aman/pondok-db.sql | docker compose exec -T db sh -c \
  'MYSQL_PWD="$MYSQL_PASSWORD" mysql -u "$MYSQL_USER" "$MYSQL_DATABASE"'
cat /path/backup-aman/pondok-storage.tar.gz | docker compose run --rm -T --no-deps app tar -xzf - -C /var/www/html/storage
docker compose run --rm --no-deps app chown -R www-data:www-data /var/www/html/storage
docker compose up -d app
```

## Upgrade

1. Backup database dan `pondok_storage`.
2. Ambil versi kode baru, lalu bangun image: `docker compose build app`.
3. Jalankan `docker compose run --rm app php artisan pondok:upgrade --force`.
4. Terapkan image baru: `docker compose up -d app`.

Jangan menghapus named volumes saat upgrade. Perintah upgrade tidak menjalankan `migrate:fresh`.

## Menjaga data saat build ulang

Setiap container start, entrypoint menjalankan `pondok:install --skip-if-installed` (hanya memasang bila belum ada super-admin), lalu `pondok:upgrade --force` (migrasi baru dan data sistem). Data lembaga tidak pernah dihapus, dan data bawaan yang sudah dihapus admin tidak dibuat ulang.

Pada platform seperti Coolify atau Dokploy:

- Isi `APP_ENV=production`. Hanya di production, `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe`, dan seeder demo ditolak. Entrypoint menampilkan peringatan bila `APP_ENV` bukan `production`.
- Jangan mengisi perintah *pre/post deployment* dengan `migrate:fresh`, `db:seed`, atau `DemoSeeder`. Entrypoint sudah menangani migrasi.
- Pastikan database memakai volume persisten (mis. `mysql_data`) atau layanan database terpisah, bukan penyimpanan di dalam container aplikasi.
- Data yang sudah terhapus hanya bisa dipulihkan dari backup.
