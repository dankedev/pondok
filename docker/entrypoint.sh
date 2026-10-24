#!/bin/sh
# Dijalankan setiap container start: siapkan folder storage (volume bisa kosong),
# pasang Pondok sekali (`pondok:install --skip-if-installed`), lalu `pondok:upgrade`
# untuk migrasi dan data sistem baru, lalu scheduler di background dan Apache.
# Tidak pernah menghapus data.
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

if [ "$APP_ENV" != "production" ]; then
    echo "PERINGATAN: APP_ENV=$APP_ENV, bukan production. Pengaman data (larangan migrate:fresh/db:wipe dan seeder demo) tidak aktif." >&2
fi

# Database bisa belum siap saat container baru start, jadi coba beberapa kali.
attempt=1
until runuser -u www-data -- php artisan pondok:install --skip-if-installed --no-interaction; do
    if [ "$attempt" -ge 10 ]; then
        echo "pondok:install gagal setelah $attempt percobaan. Periksa koneksi database dan SUPER_ADMIN_*." >&2
        exit 1
    fi
    attempt=$((attempt + 1))
    echo "Mencoba lagi pondok:install dalam 5 detik (percobaan $attempt)..." >&2
    sleep 5
done

runuser -u www-data -- php artisan pondok:upgrade --force --no-interaction

# Scheduler di samping Apache (F089): menjalankan antrean (email) dan tugas
# terjadwal tanpa cron host. Matikan dengan PONDOK_SCHEDULER=false bila
# platform menjalankan `schedule:run` sendiri. Kunci withoutOverlapping dari
# container lama yang mati di tengah tugas dihapus dulu agar tidak menahan
# antrean; saat start belum ada tugas jadwal yang berjalan.
if [ "${PONDOK_SCHEDULER:-true}" != "false" ]; then
    runuser -u www-data -- php artisan schedule:clear-cache --no-interaction > /dev/null \
        || echo "schedule:clear-cache gagal; scheduler tetap dijalankan." >&2
    (
        while true; do
            runuser -u www-data -- php artisan schedule:work --no-interaction > /dev/null || true
            echo "Scheduler (schedule:work) berhenti; dijalankan ulang dalam 5 detik." >&2
            sleep 5
        done
    ) &
fi

exec "$@"
