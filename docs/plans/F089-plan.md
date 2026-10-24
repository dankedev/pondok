# F089 Image Docker menjalankan scheduler sendiri

Issue: #103 · Jalur: cepat (entrypoint Docker dan dokumen, tanpa perubahan PHP)

## Tujuan

Image Docker hanya menjalankan Apache, sehingga job antre (email reset password, undangan wali) tertahan di tabel `jobs` dan tugas terjadwal (`queue:work --stop-when-empty`, `pondok:backup`, redaksi) tidak pernah jalan. Image harus menjalankan scheduler sendiri tanpa cron host.

## Rancangan

Di `docker/entrypoint.sh`, setelah `pondok:upgrade` dan sebelum `exec "$@"`:

1. Bila `PONDOK_SCHEDULER` bukan `false` (bawaan aktif):
   - `schedule:clear-cache` sebagai `www-data`. Cache memakai database, jadi kunci `withoutOverlapping` dari container lama yang mati di tengah tugas (default 24 jam untuk `queue:work`) bisa tertinggal dan menahan antrean. Saat container baru start, belum ada tugas jadwal yang berjalan.
   - Loop di background: `runuser -u www-data -- php artisan schedule:work`; bila berhenti, catat ke stderr lalu jalankan ulang setelah 5 detik. Container tidak diam-diam berjalan tanpa scheduler.
   - stdout scheduler dibuang (satu baris per menit dari `queue:work`), stderr tetap ke log container.
2. `exec "$@"` tetap, jadi Apache tetap PID 1 dan menerima SIGTERM seperti sebelumnya.

Tanpa supervisor, cron daemon, atau paket tambahan (KISS).

**SIGTERM:** saat container berhenti, Apache (PID 1) keluar dan kernel menghentikan proses lain di container, termasuk scheduler. Image tidak memasang ekstensi `pcntl`, jadi `queue:work` memang tidak bisa berhenti anggun. Job yang terputus dikembalikan ke antrean oleh driver database setelah `retry_after`, dan kunci yang tertinggal dibersihkan `schedule:clear-cache` saat start berikutnya.

**Heartbeat `pondok:doctor`:** `RecordSchedulerHeartbeat` menulis `storage/framework/pondok-scheduler-heartbeat` dari proses `schedule:run` yang dijalankan `schedule:work`, sebagai `www-data` di `/var/www/html/storage` (volume). Entrypoint sudah `chown` folder storage ke `www-data` sebelumnya. Tidak ada perubahan PHP.

## Berkas yang diubah

- `docker/entrypoint.sh` — saklar `PONDOK_SCHEDULER`, clear-cache, loop scheduler.
- `docs/deploy-docker.md` — scheduler berjalan di image, cara mematikan, cara cek (`pondok:doctor`, tabel `jobs`); bagian cron host dan Easypanel disesuaikan.
- `.env.docker.example` — contoh `PONDOK_SCHEDULER` (dikomentari).
- `feature_list.json`.

Tidak berubah: alur install/upgrade di entrypoint, `routes/console.php`, target cPanel (satu cron `schedule:run`).

## Kriteria penerimaan

1. Setelah container start, scheduler berjalan sebagai `www-data` tanpa perintah manual, sehingga job antre diproses dalam ±1 menit.
2. Bila `schedule:work` berhenti, loop menjalankannya ulang dan mencatatnya di log container.
3. `PONDOK_SCHEDULER=false` mematikan scheduler; nilai lain atau kosong = aktif.
4. `pondok:doctor` melaporkan scheduler berjalan (heartbeat ditulis `www-data` di storage).
5. Urutan install → upgrade → Apache tidak berubah; entrypoint lolos `sh -n` (dan shellcheck bila tersedia).
6. `docs/deploy-docker.md` menjelaskan scheduler bawaan, cara mematikan, dan cara cek; tidak menyarankan cron host ganda.

## Verifikasi

Docker dilarang di mesin pengembang: `sh -n`, shellcheck bila ada, dan simulasi loop dengan perintah tiruan di shell lokal. Smoke test container dilakukan pemilik di Easypanel setelah deploy.

## Risiko

- Deploy yang sudah memakai cron panel + scheduler bawaan: `schedule:run` ganda. `withoutOverlapping` mencegah tugas yang sama jalan bersamaan, tetapi tugas tanpa kunci (heartbeat) jalan dua kali. Dokumen meminta cron panel dihapus atau `PONDOK_SCHEDULER=false`.
- Beberapa replika container: masing-masing menjalankan scheduler. Pondok single-instance; dokumen menyarankan `PONDOK_SCHEDULER=false` di replika tambahan.
