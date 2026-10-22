# F076 inti-pembersihan-activity-log — Pembersihan activity log lama per periode (allowlist log_name)

Issue: #67
Status rencana: APPROVED
Jalur: penuh (Action yang menghapus data audit, pengaturan super-admin). Bergantung: F042 (done).

## Ringkasan & tujuan
Pecahan F042 (lihat `docs/plans/F042-plan.md` K11). Super-admin bisa mengisi masa simpan activity log dalam bulan.
Setiap hari, log lama yang `log_name`-nya ada di allowlist kode (`akademik`, `konten`) dihapus. Log bernilai audit
(`keuangan`, `sistem`, `pengaturan`, `dokumen`, `psb`, `default`, null, atau nama lain apa pun) tidak pernah disentuh.
Lingkup sesuai komentar user di #67 (23 Okt 2026).

## Keputusan
- **K1 Inti, bukan modul.** Tidak ada case `Module` baru; halaman tetap `DataRetention` (sudah di `$core`).
- **K2 Allowlist = konstanta** `PruneActivityLog::PRUNABLE_LOG_NAMES = ['akademik', 'konten']`. Tidak bisa diubah dari UI
  (YAGNI). Query memakai `whereIn('log_name', ...)`, jadi null/nama tak dikenal otomatis tidak ikut.
- **K3 Setting** `retention.activity_log_months` (int|null). Kosong = tidak menghapus apa pun. Batas 24–1200 bulan
  (`UpdateDataRetentionSettings::MIN_ACTIVITY_LOG_MONTHS = 24`, `MAX_ACTIVITY_LOG_MONTHS = 1200`, setara 100 tahun seperti
  `MAX_YEARS`). Action juga menolak nilai < 24 yang ditulis langsung ke DB (return 0, tanpa hapus) sebagai pengaman.
- **K4 Batas waktu:** hapus bila `created_at < now()->subMonthsNoOverflow(N)` (zona aplikasi; tidak ada konversi WIB
  karena presisi bulan).
- **K5 Hapus ber-chunk portabel:** `Activity::query()->whereIn(log_name)->where(created_at)->select('id')->chunkById(500,
  fn ($rows) => Activity::whereIn('id', $rows->pluck('id'))->delete())`. Tanpa `DELETE ... LIMIT`/join khusus driver.
- **K6 Audit:** satu `activity('sistem')->event('prune')` dengan `{deleted, months, log_names}` **hanya bila deleted > 0**
  (sama dengan pola `RedactExpiredLetterSnapshots`; mencegah log harian kosong). Log ini dibuat setelah penghapusan dan
  ber-log `sistem`, jadi tidak pernah ikut terhapus.
- **K7 Jadwal** `pondok:prune-activity-log` setiap hari 03:30 WIB, `->when(fn () => filled(Setting::get(
  'retention.activity_log_months')))`, `->withoutOverlapping(120)`. Command tetap aman bila dijalankan manual saat kosong.
- **K8** Command bawaan Spatie `activitylog:clean` (memakai `delete_records_older_than_days` = 365, tanpa allowlist) **tidak**
  dijadwalkan; diuji (AC-8) dan docs memperingatkan agar tidak dijalankan.
- **K9** `pondok:doctor` tidak punya daftar tugas terjadwal (hanya cek heartbeat), jadi tidak diubah.

## Ruang lingkup
- Termasuk: Action, command, jadwal, field di halaman Backup & masa simpan, perluasan `UpdateDataRetentionSettings`,
  `lang/id`, `ModuleComplianceTest` (`$isCore`), docs `instalasi.md` §11 dan `erd.md` (settings).
- Tidak termasuk: allowlist yang bisa diubah dari UI, masa simpan berbeda per `log_name`, backup/ekspor sebelum hapus
  (F077, #70), pembersihan `notifications`/`failed_jobs`/`jobs`, index baru di `activity_log.created_at` (lihat risiko),
  perubahan `config/activitylog.php`.

## Model data
Tanpa migration. Setting baru `retention.activity_log_months` (tanpa seeder; bawaan null dari `Setting::get`). Tabel
`activity_log` (Spatie, model `Spatie\Activitylog\Models\Activity`) hanya dihapus barisnya; bukan tabel keuangan.
`docs/erd.md` baris settings F042 ditambah key ini dan aturan allowlist.

## Action class
- `App\Actions\Core\PruneActivityLog::handle(): int` — jumlah baris dihapus. Aturan K2–K6. Tanpa global scope (activity
  tidak punya scope jenjang).
- `App\Actions\Core\UpdateDataRetentionSettings` (diubah): input tambahan `activity_log_months` (opsional; tidak ada/kosong
  = null), validasi `nullable|integer|min:24|max:1200` dengan atribut `lang/id`; ikut di `$old`/`$new`, disimpan dalam
  transaksi yang sama, dan tercatat di activity `sistem`/`updated` yang sudah ada.
- `App\Console\Commands\PruneActivityLogCommand` (`pondok:prune-activity-log`), pola `RedactLetterSnapshotsCommand`:
  memanggil Action, menampilkan `pondok.data_retention.activity_log_pruned` (`:count`), exit 0.

## UI (Filament / Blade)
- `App\Filament\Pages\Settings\DataRetention`: Section baru "Masa simpan log aktivitas" setelah Section surat.
  `TextInput::make('activity_log_months')` pola `letter_snapshot_years`: `numeric()->integer()->minValue(24)
  ->maxValue(1200)->nullable()->suffix('bulan')`, `tabular-nums`. `fillData()` dan `persist()` ikut field ini.
- Teks `lang/id/pondok.php` `data_retention.*`: `activity_log_heading`, `activity_log_description` (log akademik dan konten
  yang lebih lama dari masa simpan dihapus setiap hari), `activity_log_months`, `activity_log_months_helper` ("Isi minimal
  24 bulan. Kosongkan bila log tidak perlu dihapus. Log keuangan, PSB, dokumen, pengaturan, dan sistem tidak pernah
  dihapus."), `months_suffix`, `activity_log_pruned`, `activity_pruned`. Design-guide §0, §5, §9.

## Permission & cakupan
Tanpa permission baru; halaman tetap `hasRole('super-admin')`. Tanpa saklar modul. `ModuleComplianceTest` AC-11: `$isCore`
ditambah `|| str_contains((string) $event->command, 'pondok:prune-activity-log')`.

## Kriteria penerimaan (bisa dites)
- **AC-1** Setting tidak ada atau null → `handle()` mengembalikan 0, semua log (termasuk `akademik`/`konten` 5 tahun lalu)
  tetap, tanpa activity `prune`.
- **AC-2** Setting 24; log `akademik` dan `konten` berumur 25 bulan → terhapus; log `akademik`/`konten` berumur 23 bulan →
  tetap. `handle()` mengembalikan jumlah yang terhapus.
- **AC-3** Setting 24; log berumur 5 tahun dengan `log_name` `keuangan`, `sistem`, `pengaturan`, `dokumen`, `psb`,
  `default`, `lainnya`, dan null → semuanya tetap setelah `handle()` (dataset satu test).
- **AC-4** Setting 24; 1.200 log `konten` berumur 3 tahun → semua terhapus, `handle()` = 1200, dan query `delete` yang
  tercatat (`DB::enableQueryLog`) lebih dari satu (ber-chunk).
- **AC-5** Run yang menghapus mencatat tepat satu activity `sistem`/`prune` dengan `deleted`, `months` = 24,
  `log_names` = `['akademik','konten']`; run kedua mengembalikan 0 dan tidak menambah activity `prune`.
- **AC-6** Pengaman: setting 12 ditulis langsung lewat `Setting::set` → `handle()` = 0, log `akademik` berumur 13 bulan tetap.
- **AC-7** `pondok:prune-activity-log` exit 0 dan menampilkan pesan jumlah (`activity_log_pruned`), juga saat setting kosong.
- **AC-8** Jadwal: event `pondok:prune-activity-log` ekspresi `30 3 * * *`, timezone `Asia/Jakarta`, `withoutOverlapping`
  dengan `expiresAt` 120; `filtersPass` false saat setting belum ada/null dan true saat 24. Tidak ada event jadwal yang
  memuat `activitylog:clean`. `ModuleComplianceTest` lulus.
- **AC-9** Halaman (super-admin): simpan `activity_log_months` 36 → setting 36 dan activity `sistem`/`updated` memuat
  `activity_log_months` di `old` dan `attributes`; kosong → null; 23, 0, dan 1201 ditolak (error form di field). Peran
  lain tetap 403 (test F042 yang ada).
- **AC-10** `UpdateDataRetentionSettings::handle` langsung: `activity_log_months` 23 → `ValidationException`; input tanpa
  key `activity_log_months` (pemanggil lama) tetap valid dan menyimpan null. Kunci `lang/id` baru terisi
  (`expectTranslatedKeys`).

## Rencana test
- `tests/Feature/Actions/Core/PruneActivityLogTest.php` — AC-1 s.d. AC-6 (`$this->travelTo`/`created_at` diisi langsung).
- `tests/Feature/Console/DataRetentionScheduleTest.php` (tambah) — AC-7, AC-8; ubah `tests/Feature/ModuleComplianceTest.php`.
- `tests/Feature/Filament/Pages/Settings/DataRetentionSettingsTest.php` (tambah) — AC-9, AC-10.

## Urutan implementasi
**Paket 1 — Action, command, jadwal** (hijau: PruneActivityLogTest, DataRetentionScheduleTest, ModuleComplianceTest)
1. `PruneActivityLog` + konstanta allowlist + log `prune`.
2. `PruneActivityLogCommand` + jadwal 03:30 di `routes/console.php` (komentar gaya blok lain) + `$isCore`.
**Paket 2 — pengaturan & docs** (hijau: DataRetentionSettingsTest, DataRetentionVerifyTest)
3. Konstanta + validasi di `UpdateDataRetentionSettings`; field di `DataRetention`; `lang/id`.
4. `docs/instalasi.md` §11 paragraf "Masa simpan log aktivitas" (isi bulan, minimal 24, hanya log akademik & konten,
   jadwal 03.30 WIB, manual `php artisan pondok:prune-activity-log`, jangan jalankan `activitylog:clean`); `docs/erd.md`;
   `feature_list.json` evidence. Pint.

## Risiko & pertanyaan terbuka
- `activity_log.created_at` tanpa index: query hapus memindai tabel tetapi difilter `log_name` (ber-index) dan hanya
  sekali sehari; index ditambah bila nanti terbukti lambat (tidak dalam lingkup).
- Log `akademik` saat ini juga mencatat pembuatan/kirim ulang akun portal wali dan perubahan penomoran NIS; ikut terhapus
  setelah masa simpan sesuai keputusan user. Bila nama log modul berubah ke allowlist tanpa sengaja, log audit bisa ikut
  terhapus; AC-3 menjaga daftar log yang dilindungi.
- `activitylog:clean` masih bisa dijalankan manual dan menghapus semua log > 365 hari; hanya diperingatkan di docs.
- Tidak ada pertanyaan terbuka untuk user.

## Riwayat revisi
- 23 Okt 2026: DRAFT pertama (10 AC).

## Review #1 — 2026-10-23

Verdict: APPROVED

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | minor | AC-9 / `UpdateDataRetentionSettings` | Test F042 yang ada kemungkinan meng-assert `old`/`attributes` activity `updated` secara persis; key baru `activity_log_months` akan mematahkannya. | Saat implementasi, perbarui test lama itu (bukan perilaku) dan pastikan `$old === $new` early-return tetap benar untuk pemanggil lama. |
| 2 | minor | K3/AC-6 | Pengaman "nilai <24 di DB → 0" duplikasi validasi, tapi murah dan melindungi dari data log audit; boleh dipertahankan. Nilai dari `Setting::get` bisa berupa string. | Cast `(int)` setelah `is_numeric`, pola `RedactExpiredLetterSnapshots`. |
| 3 | minor | K6/K2 | Log `akademik` juga memuat akun portal wali dan penomoran NIS (sudah diakui di Risiko). Allowlist konstanta + `whereIn` aman: null/nama lain tidak ikut. Log memang dipakai: `akademik`, `konten`, `keuangan`, `dokumen`, `psb`, `sistem`, `pengaturan`. | Tidak ada tindakan; AC-3 sudah menjaga. |
| 4 | minor | K7/AC-8 | Pola jadwal dan `$isCore` konsisten dengan `redact-letter-snapshots` (routes/console.php, ModuleComplianceTest baris 158-161). | Tidak ada. |

Cek: portabel (chunkById 500 + whereIn, tanpa LIMIT/join), cPanel aman (cron schedule:run), tanpa migration, YAGNI terjaga (allowlist tidak dapat diubah UI), 10 AC bisa di-assert.
