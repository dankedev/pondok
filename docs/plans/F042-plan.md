# F042 inti-backup-pembersihan-data — Backup dan pembersihan data lama per periode

Issue: #17
Status rencana: APPROVED (orkestrator, setelah revisi Review #1)
Jalur: penuh (migration, Action yang mengubah data, dokumen pribadi, akses super-admin). Bergantung: F019 (done).

## Ringkasan & tujuan
1. Backup harian dari cron `schedule:run` yang jalan di shared hosting tanpa `exec`/`mysqldump`.
2. Pembersihan per periode = **redaksi data pribadi snapshot surat keluar** setelah masa simpan (UU PDP, pertanyaan no. 7).
   Tujuan redaksi: salinan beku data pribadi (NISN, tempat/tanggal lahir, dst.) tidak disimpan tanpa batas, sementara
   surat tetap bisa diverifikasi. Tidak ada tabel keuangan yang disentuh (Action hanya menulis `issued_letters`).

## Keputusan atas nama user (user tidur; dicatat agar bisa dikoreksi)
- **K1 Inti, bukan modul.** Tidak ada case `Module` baru. Redaksi tetap berjalan walau modul Dokumen mati (kewajiban PDP).
- **K2 Dump PHP murni, data saja, satu jalur** (tanpa `mysqldump`/`Process`). `database.sql` berisi `DELETE FROM` +
  `INSERT` per tabel; skema dibuat ulang lewat `php artisan migrate` pada versi yang sama (dicatat di manifest). Jalur yang
  sama berjalan di SQLite sehingga pemulihan dites di CI.
- **K3 Postgres ditolak** dengan pesan jelas (exit 1); docs menyarankan `pg_dump`/backup panel.
- **K4 Isi arsip:** `database.sql`, `manifest.json`, disk `private` (kecuali `backups/`, `finance-exports/`, `livewire-tmp/`),
  disk `public`. Hanya disk berdriver `local`; disk lain dilewati dan dicatat di manifest.
- **K5 Butuh ext-zip** (sudah wajib di `pondok:doctor`); tanpa `ZipArchive` gagal berpesan, tanpa fallback.
- **K6 Backup terjadwal default MATI** (`backup.enabled` = false) karena kuota hosting kecil. Arsip disimpan
  `CreateBackup::KEEP = 3` (konstanta, tanpa UI).
- **K7 Tanpa halaman unduh dan tanpa tombol "backup sekarang"**: unduhan seluruh data pribadi lewat web adalah permukaan
  serangan baru, dan request web bisa kena batas waktu. Arsip diambil lewat File Manager cPanel; backup manual lewat
  `php artisan pondok:backup`.
- **K8 Masa simpan surat default kosong** = tidak ada redaksi. Minimum 1 tahun.
- **K9 Redaksi minimum:** `snapshot.student` → `['name' => IssuedLetter::maskName(nama)]`. `letter_no`, `status`,
  `verification_code`, `snapshot.signers`, `snapshot.school`, `student_id` tetap. `purpose` dan `cancel_reason` **tidak**
  diredaksi (teks bebas; dicatat sebagai batasan di docs). `file_path` tidak punya penulis di kode, jadi tidak ada PDF dihapus.
- **K10 `APP_KEY`/`.env` tidak ikut arsip**; kolom `encrypted` hanya terbaca dengan APP_KEY yang sama.
- **K11 Pembersihan activity log dibuang dari F042** (keputusan orkestrator setelah review): belum ada bukti tabel membesar,
  dan log dokumen/PSB/pengaturan bernilai audit. Usulan issue lanjutan dengan allowlist `log_name`.

## Ruang lingkup
- Termasuk: `pondok:backup`, `pondok:redact-letter-snapshots`, dua jadwal inti, migration `snapshot_redacted_at`, halaman
  Pengaturan → Backup & masa simpan (super-admin), catatan redaksi di cetak surat dan kartu, docs.
- Tidak termasuk: pembersihan activity log/`notifications`/`failed_jobs`/pendaftar lama, unduh/pulihkan dari panel, command
  restore, enkripsi arsip, kirim ke lokasi luar, dump Postgres, hapus data atas permintaan santri/wali, cek di `pondok:doctor`.

## Model data
- Migration `add_snapshot_redacted_at_to_issued_letters_table`: `timestamp('snapshot_redacted_at')->nullable()`; `down()` drop
  kolom. Cast `datetime`; diisi Action lewat `forceFill`. Docblock `IssuedLetter` diperbarui ("snapshot diredaksi setelah
  masa simpan; surat tetap tidak dihapus").
- Settings (tanpa seeder, bawaan dari `Setting::get`): `backup.enabled` (bool, false), `retention.letter_snapshot_years` (int|null).
- ERD: kolom baru di `issued_letters`, dua key di bagian `settings`.

## Action class
- `Core\DumpDatabase::handle(string $absolutePath): array<string,int>` (baris per tabel). Tabel dari `Schema::getTables()`,
  kolom dari `Schema::getColumns()` tanpa kolom ber-`generation` (mis. `fee_rates`). **Dilewati:** `migrations` (dibuat
  ulang oleh `migrate`), `cache`, `cache_locks` (bisa dibangun ulang), `sessions` (pengguna cukup login ulang), `jobs`,
  `job_batches` (antrean sementara; pekerjaan tertunda hilang, dicatat di docs), `failed_jobs` (diagnostik saja).
  - Seluruh pembacaan dalam satu transaksi (`DB::transaction`) agar InnoDB memberi snapshot konsisten.
  - `DELETE FROM` ditulis untuk **semua** tabel yang di-dump, termasuk yang kosong (migration bisa mengisi baris).
  - Baca ter-chunk: `lazyById(500)` bila ada `id`, selain itu `cursor()` tanpa urutan. Tulis langsung ke stream.
  - Satu `INSERT` multi-baris ditutup saat mencapai 500 baris atau ±1 MB (`DumpDatabase::MAX_STATEMENT_BYTES
    = 1_000_000`); satu baris yang sendiri lebih besar menjadi statement tunggal.
  - Header/footer: MySQL/MariaDB `SET NAMES utf8mb4; SET FOREIGN_KEY_CHECKS=0;` … `=1`; SQLite `PRAGMA foreign_keys=OFF;`
    … `ON`. Nilai lewat `PDO::quote`, null → `NULL`. Driver lain → exception.
- `Core\CreateBackup::handle(): string` (path relatif di disk `private`). Tolak pgsql / tanpa ZipArchive
  (`RuntimeException`, pesan `lang/id`). Zip dibangun di `storage/app/backup-tmp`, dipindah ke
  `backups/pondok-YYYYmmdd-His.zip` hanya bila selesai; temp selalu dibersihkan (`finally`). Manifest: `created_at`,
  `driver`, migration terakhir, jumlah baris, disk dilewati. Setelah sukses: rotasi `backups/pondok-*.zip` ke `KEEP` terbaru,
  lalu `activity('sistem')->event('backup')` `{file, size}`. Gagal → tidak ada arsip baru, tidak ada rotasi.
- `Dokumen\RedactExpiredLetterSnapshots::handle(): int` — tanpa setting → 0. Surat semua status, `withoutGlobalScopes()`,
  `snapshot_redacted_at` null, `issued_on` ≤ hari ini − N tahun: redaksi K9 + isi `snapshot_redacted_at`, ter-chunk. Bila > 0,
  satu `activity('sistem')->event('redact')` `{redacted}` tanpa data pribadi.
- `Core\UpdateDataRetentionSettings::handle(array $data, User $by): void` — validasi `backup_enabled` bool (ditolak true bila
  driver pgsql), `letter_snapshot_years` kosong atau 1–100; simpan; `activity('sistem')` nilai lama/baru bila berubah.
- Command tipis: `pondok:backup` (gagal → error + exit 1 + `Log::error`), `pondok:redact-letter-snapshots`.
  Jadwal `routes/console.php`: backup `dailyAt('01:30')` WIB `->when(fn () => (bool) Setting::get('backup.enabled', false))`
  `->withoutOverlapping(120)`; redaksi `dailyAt('03:00')` WIB `->withoutOverlapping(120)`.

## UI (Filament / Blade)
- `App\Filament\Pages\Settings\DataRetention` (slug `settings/data-retention`, grup Pengaturan, ikon `OutlinedCircleStack`),
  `canAccess()` = `hasRole('super-admin')`, navigasi disembunyikan seperti `Modules`; boleh turunan `SettingsFormPage`.
  Section "Backup": toggle terjadwal, teks backup terakhir (nama, ukuran, waktu) atau "Belum ada backup", petunjuk lokasi
  dan `.env`. Bila driver pgsql: callout peringatan dan toggle nonaktif. Section "Masa simpan surat": angka + suffix tahun,
  helper "kosong = tidak diredaksi; data keuangan tidak pernah dihapus".
- `PrintIssuedLetter` meneruskan `redactedAt` ke view; `dokumen/letter.blade.php` dan `dokumen/student-card.blade.php`
  menampilkan catatan "Data pribadi surat ini telah dihapus sesuai masa simpan" dan field kosong tanpa galat.
- Teks `lang/id/pondok.php` (`data_retention.*`, `backup.*`). Design-guide §0, §6, §8, §9.

## Permission & cakupan
Tanpa permission Shield baru (role `super-admin`, pola `Modules`); tanpa saklar modul. Redaksi memakai `withoutGlobalScopes()`.
`ModuleComplianceTest`: `DataRetention::class` masuk `$core` (AC-9); `$isCore` ditambah `str_contains($event->command,
'pondok:backup') || str_contains($event->command, 'pondok:redact-letter-snapshots')` (AC-11).

## Kriteria penerimaan (bisa dites)
- **AC-1** Ada `private/documents/x.pdf`, `private/finance-exports/a.csv`, `private/livewire-tmp/t`, `public/posts/p.jpg` →
  `pondok:backup` exit 0 → satu `backups/pondok-YYYYmmdd-His.zip` berisi `database.sql`, `manifest.json`,
  `private/documents/x.pdf`, `public/posts/p.jpg`; tanpa `finance-exports`, `livewire-tmp`, `backups/`; `backup-tmp` kosong.
- **AC-2** (pemulihan, SQLite) Santri dengan kolom `encrypted`, `fee_rates` (generated column), `unit_user` (tanpa `id`),
  satu tabel kosong, 1.200 baris activity log → dump → `migrate:fresh` → impor → jumlah baris tiap tabel = manifest, nilai
  encrypted sama, `fee_rates` utuh, tabel kosong tetap kosong (ada `DELETE FROM`-nya). >1 `INSERT INTO` untuk tabel 1.200
  baris; tidak ada statement > `MAX_STATEMENT_BYTES` untuk baris berukuran ±10 KB; tabel yang dilewati tidak muncul.
- **AC-3** Rotasi: 4 arsip lama + `backups/catatan.txt` → backup → tersisa 3 arsip terbaru (termasuk baru), `catatan.txt` tetap.
- **AC-4** Gagal: driver `pgsql` (atau `DumpDatabase` dipaksa melempar) → exit 1 berpesan `lang/id`, tanpa arsip baru,
  arsip lama utuh, tanpa activity `backup`. Sukses → satu activity `sistem`/`backup` dengan `file` dan `size`.
- **AC-5** Jadwal: `pondok:backup` 01:30 WIB dilewati saat `backup.enabled` belum diatur/false dan jalan saat true;
  `pondok:redact-letter-snapshots` 03:00 WIB terdaftar; keduanya `withoutOverlapping`. `ModuleComplianceTest` lulus.
- **AC-6** Tanpa `retention.letter_snapshot_years` → tidak ada surat berubah. Diisi 2 → surat `terbit` dan `batal`
  `issued_on` 3 tahun lalu: `snapshot.student` = `['name' => 'Muhammad F. A.']`; `letter_no`, `status`,
  `verification_code`, `signers`, `school` tetap; `snapshot_redacted_at` terisi. Surat 1 tahun lalu tidak berubah. Berlaku
  lintas jenjang dan untuk santri diarsipkan.
- **AC-7** Idempoten dan audit: run kedua mengembalikan 0, `updated_at` tidak berubah, tanpa log `redact` baru. Run pertama
  mencatat satu activity `sistem`/`redact` dengan `redacted`, tanpa nama/NISN. Jumlah dan `updated_at` tagihan/pembayaran
  lama tidak berubah.
- **AC-8** Surat teredaksi: verifikasi publik 200 (status sah, nomor, nama termasker); cetak surat dan kartu santri 200,
  menampilkan catatan redaksi, tanpa NISN/tanggal lahir lama.
- **AC-9** Akses dan simpan: super-admin melihat menu dan membuka halaman; `admin-sekolah`, `bendahara`, `admin-konten`
  403 tanpa menu. Simpan toggle on + tahun 5 → settings tersimpan + activity `sistem` lama/baru; tahun kosong → null;
  tahun 0/101 ditolak di field. Halaman menampilkan "Belum ada backup" atau nama dan ukuran arsip terbaru.
- **AC-10** Driver pgsql: halaman menampilkan peringatan, toggle nonaktif, dan Action menolak `backup_enabled` true.

## Rencana test
- `tests/Feature/Actions/Core/CreateBackupTest.php` — AC-1, AC-3, AC-4 (`Storage::fake('private')`, `('public')`).
- `tests/Feature/Actions/Core/DumpDatabaseTest.php` — AC-2.
- `tests/Feature/Console/DataRetentionScheduleTest.php` — AC-5; ubah `tests/Feature/ModuleComplianceTest.php`.
- `tests/Feature/Actions/Dokumen/RedactExpiredLetterSnapshotsTest.php` — AC-6, AC-7, AC-8.
- `tests/Feature/Filament/Pages/DataRetentionSettingsTest.php` — AC-9, AC-10.

## Urutan implementasi
**Paket 1 — backup** (hijau: CreateBackupTest, DumpDatabaseTest, DataRetentionScheduleTest, ModuleComplianceTest)
1. `DumpDatabase`. 2. `CreateBackup` + rotasi + log. 3. `pondok:backup` + jadwal + `$isCore`.
**Paket 2 — redaksi** (hijau: RedactExpiredLetterSnapshotsTest, DataRetentionScheduleTest)
4. Migration + cast + docblock. 5. `RedactExpiredLetterSnapshots`. 6. `PrintIssuedLetter` + dua view. 7. Command + jadwal.
**Paket 3 — pengaturan & docs** (hijau: DataRetentionSettingsTest, ModuleComplianceTest)
8. `UpdateDataRetentionSettings` + halaman + `$core` + `lang/id`.
9. `docs/instalasi.md` bagian "Backup dan pemulihan": aktifkan; arsip di `storage/app/private/backups` (jangan dipindah/
   diekstrak ke `public_html`, termasuk Cara B); unduh dan simpan di luar hosting; `.env` terpisah. Pemulihan: DB kosong →
   `.env` dengan APP_KEY sama → kode versi yang sama (migration terakhir di manifest) → `php artisan migrate --force` tanpa
   seed → impor `database.sql` (phpMyAdmin/`mysql`, mode SQL bawaan, tanpa `NO_BACKSLASH_ESCAPES`) → ekstrak `private/` ke
   `storage/app/private`, `public/` ke disk public. Antrean tertunda dan sesi login tidak ikut. Batasan: `purpose` dan
   `cancel_reason` tidak diredaksi. `docs/deploy-docker.md` §Backup satu kalimat rujukan `pondok:backup`; `docs/erd.md`. Pint.

## Risiko & pertanyaan terbuka
- Jalur MySQL `DumpDatabase` (quote, header FK, transaksi) tidak dites otomatis (CI SQLite); uji manual sekali di MySQL
  lokal sebelum rilis dan catat di evidence.
- Impor besar lewat phpMyAdmin bisa kena batas unggah; docs menyarankan zip atau `mysql` di Terminal.
- Untuk user (tidak menghalangi): berapa tahun masa simpan surat untuk lembaga pengguna.
- Usulan issue lanjutan: pembersihan activity log dengan allowlist `log_name`; hapus data atas permintaan santri/wali.

## Riwayat revisi
- 22 Okt 2026: DRAFT pertama (13 AC).
- 22 Okt 2026: REVISED atas Review #1 (10 AC).

## Review #1 — 2026-10-22
Verdict CHANGES_REQUESTED (0 blocker, 3 major, 6 minor). Penanganan:
1. Major, prune activity log membuang log bernilai audit → dibuang dari lingkup (K11), AC-7/AC-8 lama dihapus, usulan issue.
2. Major, dump → `cursor()` untuk tabel tanpa `id`, batas ±1 MB per INSERT, satu transaksi, `DELETE FROM` semua tabel yang
   di-dump; daftar tabel yang dilewati beserta alasan; AC-2 diperluas; risiko jalur MySQL dicatat.
3. Major, ukuran → AC akses+simpan digabung (AC-9), `backup.keep` jadi konstanta `KEEP = 3`, ringkasan/risiko dipangkas.
4. `$core` dan `$isCore` ModuleComplianceTest disebut eksplisit (Permission & cakupan).
5. Docblock IssuedLetter, `redactedAt` lewat PrintIssuedLetter, `student-card.blade.php`; batasan `purpose`/`cancel_reason`; tujuan redaksi.
6. `withoutOverlapping(120)`; peringatan lokasi arsip di docs.
7. pgsql: peringatan dan toggle nonaktif (AC-10).
8–9. Docs pemulihan: antrean/sesi tidak ikut, versi migration sama, mode SQL bawaan tanpa `NO_BACKSLASH_ESCAPES`.
