# F057 deployment-docker-production — Dukungan deploy Docker produksi

Issue: #23

Status rencana: AC-1–AC-10 APPROVED (review langsung). Sisa pekerjaan (AC-11–AC-16, revisi 22 Okt 2026): `plan-reviewer` CHANGES_REQUESTED (1 major: root password di `.env`), direvisi di AC-15/AC-16; minor dicatat di dokumen deploy (ping hanya membuktikan server hidup, gejala init tanpa root password, backup memakai user aplikasi, cron perlu `cd`).

## Ringkasan & tujuan
Sediakan deployment produksi Docker berbasis Apache yang dapat dibangun dan dijalankan bersama MySQL melalui Docker Compose. cPanel tetap mengikuti alur rilisnya. Acuan: `CLAUDE.md` §Batasan deployment, `docs/prd.md` §4–5, `docs/rilis.md`, dan `docs/instalasi.md`.

## Ruang lingkup
- Termasuk: Dockerfile produksi, `.dockerignore`, konfigurasi Apache, dan Compose berisi layanan Laravel dan MySQL; volume persisten; instruksi inisialisasi, upgrade, dan trigger scheduler eksternal.
- Build aset frontend pada stage Node; image runtime tidak menyertakan Node.
- Tidak termasuk (eksplisit): database eksternal atau database lain, TLS/reverse proxy, secret manager spesifik vendor, Redis, worker/scheduler daemon, backup otomatis, dan perubahan alur cPanel.

## Model data
Tidak ada perubahan tabel atau data aplikasi. Data MySQL aplikasi berada pada named volume Compose.

## Action class
Tidak ada Action baru; tidak ada operasi bisnis aplikasi.

## UI (Filament / Blade)
Tidak ada UI aplikasi.

## Permission & cakupan
Tidak ada permission, `unit_user`, atau saklar modul yang berubah.

## Kriteria penerimaan (bisa dites)
- **AC-1:** Build image pada PHP 8.4 + Apache menyediakan ekstensi PHP untuk MySQL/MariaDB dan PostgreSQL, memasang dependency Composer produksi, mengarahkan document root ke `public/`, mengaktifkan rewrite, dan melayani Laravel dari `public/index.php`.
- **AC-2:** Build menghasilkan `public/build/manifest.json` dan aset Filament. Node hanya ada di build stage; image runtime tidak berisi Node, server database, Redis, atau process manager.
- **AC-3:** Build context mengabaikan `.env` dan berkas rahasia lokal lain serta `node_modules`, tetapi memasukkan template environment dan tidak mengecualikan `public/build` atau aset Filament yang diperlukan. Tidak ada nilai rahasia di Dockerfile, Compose, build args, atau image.
- **AC-4:** `docker compose config` menghasilkan tepat dua layanan aplikasi: Laravel/Apache dan MySQL; tidak ada Redis, worker, scheduler, atau Node runtime. Aplikasi memakai MySQL service Compose, bukan koneksi database eksternal.
- **AC-5:** APP_KEY, password MySQL, dan kredensial admin awal hanya diberikan saat runtime melalui environment Compose/secret provider operator. File environment lokal boleh dipakai, harus diabaikan Git dan build context, tidak memuat nilai rahasia pada contoh yang dilacak, dan tidak dipasang ke image. App menerima hanya kredensial DB aplikasinya, bukan password root MySQL.
- **AC-6:** Compose menetapkan `APP_ENV=production`, `APP_DEBUG=false`, `DB_CONNECTION=mysql`, host DB internal `db`, serta konfigurasi queue/cache/session berbasis database. Image memiliki `.env` non-rahasia yang disalin dari template agar installer dapat dijalankan; runtime environment mengalahkan nilai template. APP_KEY wajib disuplai dari environment sebelum install dan startup.
- **AC-7:** HTTP dipublikasikan pada port host yang dapat dikonfigurasi (`APP_PORT`, default 8080) menuju port 80 container. Port MySQL tidak dipublikasikan ke host; database hanya tersedia di jaringan Compose.
- **AC-8:** MySQL menggunakan named volume. Setelah container DB dihentikan/dibuat ulang, data uji tetap tersedia; `docker compose down` tanpa `-v` tidak menghapus volume.
- **AC-9:** Laravel `storage/` menggunakan named volume yang writable dan tetap berisi berkas uji setelah container aplikasi dibuat ulang. `bootstrap/cache` writable; `public/storage` mengarah ke storage publik lokal.
- **AC-10:** MySQL memiliki healthcheck; layanan app menunggu MySQL berstatus healthy. App memiliki healthcheck HTTP terhadap endpoint Laravel `/up`; status sehat hanya setelah endpoint merespons sukses.
- **AC-11:** Instalasi pertama: isi environment runtime → `docker compose up -d`. Entrypoint (F064) menjalankan `pondok:install --skip-if-installed` sekali (berhenti tanpa perubahan bila super-admin sudah ada), lalu `pondok:upgrade --force`, lalu Apache foreground. Tidak perlu `docker compose run` terpisah.
- **AC-12:** Upgrade: backup DB dan `storage/` → ambil kode baru → `docker compose build app` → `docker compose up -d app`. Entrypoint menjalankan `pondok:upgrade --force`; named volumes dipertahankan dan tidak ada `migrate:fresh`.
- **AC-13:** Tidak ada container worker/scheduler persisten. Cron host/platform memicu tiap menit `docker compose exec -T -u www-data app php artisan schedule:run` (sebagai `www-data` agar berkas cache/log tidak dimiliki root). Worker antrean berjalan dari scheduler (`queue:work --stop-when-empty`).
- **AC-14:** Operator mendapat instruksi ringkas: secret hanya di runtime, `APP_PORT`, cek health, backup/restore DB dan storage terpisah, install dan upgrade tanpa menghapus volume.
- **AC-15:** `.env.docker.example` dilacak Git berisi kunci yang wajib diisi tanpa nilai rahasia; `.env` asli diabaikan Git dan build context. `DB_ROOT_PASSWORD` dilarang di `.env` (yang juga dipasang ke app lewat `env_file`); diberikan lewat `export` di shell host saat inisialisasi pertama, hanya diteruskan ke layanan `db`, dan hanya wajib saat inisialisasi volume MySQL pertama; perintah Compose rutin (cron, backup) tidak gagal bila variabel itu tidak diekspor. Healthcheck MySQL tidak memakai password root.
- **AC-16:** Smoke test terdokumentasi di `evidence`: build image, `up` sampai kedua layanan healthy, HTTP 200 di `/` dan `/admin/login`, `schedule:run` sekali sukses, `down` (tanpa `-v`) lalu `up` dan data (super-admin, berkas uji di storage) tetap ada, serta `docker history`/`inspect` image tanpa nilai rahasia dan `inspect` container app tanpa `MYSQL_ROOT_PASSWORD`/`DB_ROOT_PASSWORD`.

## Rencana test
- Tidak menambah test Pest untuk konfigurasi infrastruktur statis.
- Validasi `docker build` dan `docker compose config`; inspeksi image/build context untuk runtime Node, file rahasia, port DB, dan service terlarang.
- Smoke test dengan Docker daemon (AC-16) memakai project Compose khusus (`-p pondok-f057-smoke`), port tidak bentrok, dan kredensial sementara; setelah selesai `down -v`, hapus image uji, hentikan Docker.
- Verifikasi urutan operasional dan perintah scheduler pada catatan serah-terima; jangan menjalankan scheduler sebagai daemon.

## Urutan implementasi
1. **Image web:** `Dockerfile`, `.dockerignore`, dan `docker/000-default.conf`. Hijau: build image dan pemeriksaan AC-1–AC-3.
2. **Compose dan data persisten:** `docker-compose.yml`, layanan `app` + `db`, healthcheck, port, serta volume. Hijau: validasi config dan smoke test AC-4–AC-10.
3. **Operasional:** catatan setup/upgrade/scheduler untuk operator, tanpa daemon atau perubahan dokumen cPanel. Hijau: walkthrough AC-11–AC-14.

## Risiko & pertanyaan terbuka
- Compose mengelola MySQL satu host; backup volume dan pemulihan harus dilakukan operator.
- Scheduler harus dipicu oleh cron/scheduler di luar container; pemilihan mekanisme spesifik hosting tetap tanggung jawab operator.
- Tidak ada pertanyaan terbuka yang memblokir: user memilih database MySQL di Compose dan meminta Compose masuk lingkup.

## Riwayat revisi
- Revisi scope atas pilihan eksplisit user: tambahkan Docker Compose dengan MySQL lokal Compose, named volumes DB/storage, runtime secrets, health/dependency, konfigurasi port, dan alur install/upgrade. Database eksternal bukan lagi target.
- Pertahankan batas proyek: tanpa Redis, Node runtime, atau daemon worker/scheduler; trigger scheduler tetap eksternal.
- Rencana sebelumnya direview langsung karena plan-reviewer tidak tersedia (model agent default tidak dikenali).
- Review revisi dilakukan langsung karena plan-reviewer kembali tidak dapat dijalankan. Verifikasi installer memastikan `.env` template harus ada sebelum `pondok:install`, maka image menyediakan template non-rahasia dan APP_KEY diwajibkan saat runtime.
- 22 Okt 2026 (pengambilalihan): AC-11/AC-12 disesuaikan dengan entrypoint F064 (install sekali otomatis); AC-13 menjalankan scheduler sebagai `www-data`; tambah AC-15 (contoh env tanpa rahasia, root password MySQL opsional setelah inisialisasi) dan AC-16 (smoke test).
