# F057 deployment-docker-production — Dukungan deploy Docker produksi

Status rencana: APPROVED (review langsung; plan-reviewer tidak tersedia karena model agent default tidak dikenali)

## Ringkasan & tujuan
Sediakan image web produksi Pondok yang bisa dijalankan pada host Docker umum tanpa mengubah runtime deployment cPanel. Rujukan: `CLAUDE.md` §Batasan deployment, `docs/prd.md` §4, `docs/rilis.md`, dan `docs/instalasi.md`.

## Ruang lingkup
- Termasuk: Dockerfile produksi, `.dockerignore`, dan konfigurasi document root Apache.
- Build frontend dilakukan pada build stage Node; image runtime tidak menyertakan Node.js.
- Tidak termasuk: Docker Compose/orchestrator, image/service database, konfigurasi secret manager/proxy/TLS, Redis, service worker/scheduler persisten, dokumentasi deploy terpisah, dan perubahan alur rilis cPanel.

## Model data
Tidak ada perubahan tabel atau data aplikasi.

## Action class
Tidak ada Action baru; tidak ada operasi bisnis aplikasi.

## UI (Filament / Blade)
Tidak ada UI aplikasi.

## Permission & cakupan
Tidak ada permission, `unit_user`, atau saklar modul yang berubah.

## Kriteria penerimaan (bisa dites)
- **AC-1:** Dengan PHP 8.4 dan Apache, image memuat ekstensi runtime untuk koneksi MySQL/MariaDB dan PostgreSQL; image runtime tidak memasang database server, Redis, Node.js, atau process manager tambahan.
- **AC-2:** Apache document root menunjuk ke `public/`, mengaktifkan rewrite Laravel, dan request aplikasi diteruskan ke `public/index.php`.
- **AC-3:** Build memasang dependency Composer produksi saja, membangun aset Vite dalam build stage, dan memastikan `public/build/manifest.json` tersedia; image runtime tidak menjalankan Node/Vite.
- **AC-4:** Build context mengecualikan `.env`, `.env.backup`, `.env.production`, file rahasia lokal lain, dan `node_modules`, tetapi mempertahankan template `.env.example` serta `.env.production.example` yang dipakai installer. `.dockerignore` tidak mengecualikan `public/build` maupun aset Filament yang diperlukan.
- **AC-5:** Image tidak menyertakan database; kredensial database dan `APP_KEY` diberikan sebagai environment saat runtime, bukan disimpan di Dockerfile/image.
- **AC-6:** `storage/` dan `bootstrap/cache/` writable oleh user web image; image menyediakan tautan `public/storage` ke storage lokal.
- **AC-7:** Web process berjalan di foreground, dan image menyajikan endpoint health Laravel. Setup database (`pondok:install` / migrasi), upgrade (`pondok:upgrade --force`), persistensi volume, dan pemicu eksternal scheduler dijelaskan kepada operator saat serah-terima.

## Rencana test
- Validasi `docker build` serta smoke test endpoint `/up` bila Docker daemon tersedia.
- Periksa secara langsung bahwa `.dockerignore` mengecualikan rahasia dan bahwa konfigurasi Apache/Dockerfile memakai document root `public/`, ekstensi yang diperlukan, serta stage build frontend non-runtime.
- Tidak menambah test Pest untuk konfigurasi infrastruktur statis.

## Urutan implementasi
1. **Image web:** `Dockerfile`, `.dockerignore`, dan `docker/000-default.conf`.
2. **Validasi:** build image dan smoke test endpoint health bila Docker daemon tersedia.

## Risiko & pertanyaan terbuka
- Bentuk scheduler eksternal bergantung pada platform hosting Docker; panduan hanya menetapkan command dan interval, bukan vendor orchestrator.
- Tidak ada keputusan user yang dibutuhkan untuk rencana ini.

## Riwayat revisi
- Revisi review: aset dibangun dalam stage Node agar image dapat dibuat dari checkout bersih; dokumentasi terpisah dan test Pest infrastruktur dikeluarkan dari lingkup. Worker/scheduler tidak dijalankan di web container; database tetap eksternal.
