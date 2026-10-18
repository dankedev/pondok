#!/bin/sh
# Dijalankan setiap container start: siapkan folder storage (volume bisa kosong),
# lalu `pondok:install` (migrasi, data awal, super-admin; aman diulang) sebelum Apache.
set -e

cd /var/www/html

mkdir -p \
    storage/app/private \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

if [ -z "$APP_KEY" ]; then
    echo "APP_KEY belum diisi. Buat dengan: php artisan key:generate --show, lalu isi di environment." >&2
    exit 1
fi

# Database bisa belum siap saat container baru start, jadi coba beberapa kali.
attempt=1
until runuser -u www-data -- php artisan pondok:install --no-interaction; do
    if [ "$attempt" -ge 10 ]; then
        echo "pondok:install gagal setelah $attempt percobaan. Periksa koneksi database dan SUPER_ADMIN_*." >&2
        exit 1
    fi
    attempt=$((attempt + 1))
    echo "Mencoba lagi pondok:install dalam 5 detik (percobaan $attempt)..." >&2
    sleep 5
done

exec "$@"
