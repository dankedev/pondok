# F085 inti-frekuensi-backup — Pilihan frekuensi backup terjadwal dengan saran bawaan

Issue: #89
Status rencana: APPROVED
Jalur: penuh (setting yang mengubah jadwal dan rotasi backup, Action yang mengubah data). Bergantung: F042 (done).

## Ringkasan & tujuan
Backup terjadwal F042 berjalan harian dengan `CreateBackup::KEEP = 3`, jadi riwayat hanya ±3 hari. Super-admin kini memilih
Harian, Mingguan, atau Bulanan di Pengaturan → Backup & masa simpan. "Mingguan (disarankan)" ditandai, dan setiap pilihan
menjelaskan rentang riwayat (arsip × frekuensi) serta dampak kuota. Rujukan: `docs/plans/F042-plan.md` (K6, K7), F076.

## Keputusan
- **K1 Inti, bukan modul.** Tidak ada case `Module` baru. Event jadwal tetap `pondok:backup`, sehingga `$isCore` di
  `ModuleComplianceTest` tetap cocok tanpa diubah.
- **K2 Satu konstanta arsip `KEEP = 3` untuk semua frekuensi** (bukan konstanta per frekuensi, bukan input). Alasan: batas
  utama adalah kuota hosting. Dengan satu konstanta, kuota yang terpakai sama untuk ketiga pilihan (±3 × ukuran arsip),
  rentang riwayat = 3 × frekuensi (±3 hari / ±3 minggu / ±3 bulan), dan teks bantuan cukup sederhana. Konstanta per frekuensi
  (mis. harian 7) melipatgandakan kuota dan menambah aturan tanpa diminta user. `CreateBackup` tidak diubah.
- **K3 Satu event jadwal** `pondok:backup` `dailyAt('01:30')` WIB, `withoutOverlapping(120)`, dengan satu `->when()`:
  `backup.enabled` **dan** `BackupFrequency::current()->isDueOn(now('Asia/Jakarta'))`. Mingguan = setiap **Ahad**, bulanan =
  setiap **tanggal 1**. Tidak ada tiga event dengan cron berbeda: ekspresi cron tetap `30 1 * * *`, test F042 AC-5 tetap
  berlaku, dan perilaku cukup dites lewat `filtersPass()` dengan `Carbon::setTestNow`.
- **K4 Nilai kosong = Harian, untuk semua instance (lama dan baru).** Key `backup.frequency` tidak di-seed. Alasan:
  `SettingSeeder` ikut dijalankan `pondok:upgrade` (lewat `SystemSeeder`) dan `pondok:install` bisa dijalankan ulang
  (lewat `InstallSeeder`), sehingga seed nilai "mingguan" akan mengubah instance lama yang sudah memakai backup harian.
  Instalasi baru tetap aman karena `backup.enabled` default mati; saat admin menyalakannya, pilihan "Mingguan (disarankan)"
  terlihat di bawah saklar yang sama. Nilai tidak dikenal di DB juga dianggap Harian.
- **K5 Backup manual tidak terpengaruh.** `pondok:backup` dari terminal selalu membuat arsip, apa pun frekuensinya. Arsip
  manual ikut rotasi 3 arsip (perilaku F042); hal ini disebut di teks bantuan.

## Ruang lingkup
- Termasuk: enum frekuensi, filter jadwal, pilihan di halaman Backup & masa simpan, key opsional di
  `UpdateDataRetentionSettings` + activity log, teks `lang/id`, `docs/instalasi.md` §11, `docs/erd.md` (settings).
- Tidak termasuk (eksplisit): jam backup atau hari mingguan yang bisa diatur, jumlah arsip yang bisa diatur, arsip per
  frekuensi (mis. harian + bulanan sekaligus), tombol backup sekarang/unduh (F042 K7), notifikasi gagal backup, migrasi data.

## Model data
Tanpa migration. Key settings baru `backup.frequency` (string `daily` | `weekly` | `monthly`; tidak ada/tidak dikenal =
`daily`). ERD bagian settings (baris Backup & masa simpan F042) ditambah key ini beserta hari jalannya.

## Enum & Action
- `App\Enums\BackupFrequency: string` — `Daily = 'daily'`, `Weekly = 'weekly'`, `Monthly = 'monthly'`.
  - `label(): string` dari `pondok.backup_frequency.<value>.label`; Weekly berlabel "Mingguan (disarankan)".
  - `description(): string` dari `pondok.backup_frequency.<value>.description` dengan `:keep` = `CreateBackup::KEEP`
    (mis. "Setiap Ahad pukul 01.30 WIB. Riwayat ±3 minggu."). Teks harian menyebut riwayat pendek, bulanan menyebut data
    hingga sebulan terakhir bisa hilang.
  - `isDueOn(CarbonInterface $at): bool` — Daily selalu true, Weekly `$at->isSunday()`, Monthly `$at->day === 1`.
  - `static current(): self` — `self::tryFrom((string) Setting::get('backup.frequency')) ?? self::Daily`.
  - `static options(): array` dan `static descriptions(): array` untuk Radio (value → teks) bila perlu; boleh inline di page.
- `Core\UpdateDataRetentionSettings` — key opsional `backup_frequency`: aturan `['sometimes', Rule::enum(BackupFrequency::class)]`.
  Bila key tidak dikirim, nilai sekarang dipertahankan (pemanggil lama tetap valid dan tidak menulis key). `$old` dan `$new`
  memuat `backup_frequency` sebagai nilai efektif (`current()->value`); bila berubah, `Setting::set('backup.frequency', ...)`
  di transaksi yang sama dan activity `sistem` `updated` memuat old/new. Tidak ada perubahan → tidak ada log (pola sekarang).
- `routes/console.php` — event backup sesuai K3; komentar diperbarui ("sesuai frekuensi di Pengaturan").

## UI (Filament)
`App\Filament\Pages\Settings\DataRetention`, section "Backup" (super-admin, tanpa permission baru):
- Toggle berlabel "Backup terjadwal" (tanpa kata "harian"); helper: "Berjalan pukul 01.30 WIB sesuai frekuensi."
- `Radio::make('backup_frequency')` setelah toggle: `options` = label enum, `descriptions` = deskripsi enum, `helperText`
  kuota: "Selalu disimpan :keep arsip terbaru, jadi kuota hosting yang terpakai sama untuk semua pilihan. Makin jarang
  backup, makin panjang riwayat, tetapi perubahan sejak backup terakhir bisa hilang. Backup manual ikut dihitung."
  `->required()`, `->disabled($pgsql)` seperti toggle. Tidak disembunyikan saat toggle mati (KISS).
- `fillData()` menambah `'backup_frequency' => BackupFrequency::current()->value`; `persist()` meneruskan
  `backup_frequency` hanya bila terisi (field nonaktif di pgsql → key tidak dikirim → nilai lama tetap).
- Teks `lang/id/pondok.php`: `backup_frequency.*`, `data_retention.backup_frequency`, `backup_frequency_helper`, ubah
  `backup_enabled` dan `backup_enabled_helper`. Design-guide §0 (checklist), §9 (bahasa), cek 360px/1024px/desktop.

## Permission & cakupan
Tanpa permission baru (role `super-admin`, pola F042). Tanpa saklar modul, tanpa cakupan jenjang.
`ModuleComplianceTest` tidak diubah; harus tetap lulus.

## Kriteria penerimaan (bisa dites)
- **AC-1** Enum: tiga case `daily`/`weekly`/`monthly`; label `Weekly` memuat "(disarankan)", dua lainnya tidak. `current()`:
  tanpa key → `Daily`; nilai `'yearly'`, `''`, `null` → `Daily`; `'weekly'` → `Weekly`.
- **AC-2** `isDueOn`: Daily true di Senin dan tanggal 15; Weekly true hanya di Ahad (false Senin–Sabtu); Monthly true hanya
  tanggal 1 (false tanggal 2 dan 31).
- **AC-3** Jadwal: tepat **satu** event `pondok:backup`, ekspresi `30 1 * * *`, timezone `Asia/Jakarta`,
  `withoutOverlapping` 120. Dengan `Carbon::setTestNow`: `backup.enabled` true tanpa frekuensi → lulus filter di Senin
  (instance lama tetap harian); `weekly` → gagal Senin, lulus Ahad; `monthly` → gagal tanggal 2, lulus tanggal 1;
  `backup.enabled` false/tanpa key + frekuensi apa pun → gagal. Test F042 AC-5 lama tetap hijau.
- **AC-4** Batas zona waktu: `weekly`, waktu uji Ahad 25 Okt 2026 01:30 WIB (= Sabtu 18:30 UTC) → lulus filter; Sabtu
  24 Okt 2026 01:30 WIB (= Jumat UTC) → gagal. `monthly`, 1 Nov 2026 01:30 WIB (= 31 Okt UTC) → lulus.
- **AC-5** Backup manual dan rotasi: `backup.frequency` = `monthly`, `backup.enabled` false, hari Senin tanggal 26, 4 arsip
  lama → `pondok:backup` exit 0, arsip baru dibuat, tersisa tepat `CreateBackup::KEEP` (3) arsip terbaru.
- **AC-6** Halaman (super-admin): field `backup_frequency` berisi tiga pilihan; terlihat teks "Mingguan (disarankan)",
  deskripsi tiap pilihan memuat rentang "3 hari", "3 minggu", "3 bulan" (dari `KEEP`), dan helper kuota. Label toggle tidak
  lagi memuat "harian". Tanpa key → form terisi `daily`; key `weekly` → terisi `weekly`.
- **AC-7** Simpan: pilih `weekly` → `Setting::get('backup.frequency')` = `'weekly'`; activity `sistem`/`updated` dengan
  `old.backup_frequency` = `'daily'` dan `attributes.backup_frequency` = `'weekly'`. Simpan ulang tanpa perubahan (termasuk
  instance tanpa key yang disimpan dengan `daily`) → tidak ada activity baru (test VERIFY F076 tetap hijau).
- **AC-8** Validasi dan kompatibilitas Action: `backup_frequency` `'yearly'`, `''`, `1` → `ValidationException`, setting tidak
  berubah. Panggilan tanpa key `backup_frequency` (bentuk lama) dengan `backup.frequency` = `monthly` tersimpan → field lain
  tersimpan, frekuensi tetap `monthly`; tanpa key tersimpan → key tetap tidak ada.
- **AC-9** Akses: `UpdateDataRetentionSettings` dengan `backup_frequency` oleh `admin-sekolah` → `AuthorizationException`,
  frekuensi tidak berubah. Driver pgsql: field frekuensi nonaktif (perluasan test F042 AC-10).
- **AC-10** Nilai lama tidak diubah sistem: setelah `SystemSeeder` dan `InstallSeeder` dijalankan pada instance dengan
  `backup.enabled` true tanpa frekuensi, key `backup.frequency` tetap tidak ada dan event backup lulus filter di hari Senin.
- **AC-11** Teks: semua key `pondok.backup_frequency.*` dan key baru `data_retention.*` berisi teks Indonesia (bukan nama key).

## Rencana test
- `tests/Feature/Enums/BackupFrequencyTest.php` — AC-1, AC-2 (`it('AC-1: marks weekly as recommended ...')`,
  `it('AC-2: weekly is due only on Sunday ...')`).
- `tests/Feature/Console/DataRetentionScheduleTest.php` (tambah) — AC-3, AC-4, AC-10
  (`it('F085 AC-3: runs the backup only on days due for the chosen frequency')`, `it('F085 AC-4: decides the day in Jakarta time')`).
- `tests/Feature/Actions/Core/CreateBackupTest.php` (tambah) — AC-5.
- `tests/Feature/Filament/Pages/Settings/DataRetentionSettingsTest.php` (tambah) — AC-6, AC-7, AC-8, AC-9, AC-11.
- `tests/Feature/ModuleComplianceTest.php` — dijalankan, tidak diubah.

## Urutan implementasi
**Paket 1 — enum & jadwal** (hijau: BackupFrequencyTest, DataRetentionScheduleTest, CreateBackupTest, ModuleComplianceTest)
1. `App\Enums\BackupFrequency` + teks `pondok.backup_frequency.*`.
2. Filter `when` pada event `pondok:backup` di `routes/console.php` + komentar.

**Paket 2 — pengaturan & docs** (hijau: DataRetentionSettingsTest, DataRetentionVerifyTest)
3. `UpdateDataRetentionSettings`: key opsional, validasi enum, old/new, `Setting::set`.
4. `DataRetention`: Radio, `fillData`, `persist`, ubah label toggle; teks `lang/id`.
5. `docs/instalasi.md` §11: nyalakan **Backup terjadwal**, pilih frekuensi (Mingguan disarankan), tabel singkat
   frekuensi → hari jalan → rentang riwayat, catatan kuota sama dan arsip manual ikut dihitung; `docs/erd.md` settings.
6. Pint, cek tampilan 360px/1024px/desktop (design-guide §0).

## Risiko & pertanyaan terbuka
- Bulanan: perubahan hingga ±1 bulan bisa hilang; disebut di deskripsi pilihan. Harian tetap tersedia.
- Backup bulanan tanggal 1 pukul 01.30 berjalan setelah generator tagihan 01.00; arsip ikut memuat tagihan baru (bukan masalah).
- Untuk user (tidak menghalangi): instalasi baru tetap default Harian (K4). Alternatif bila diinginkan: form memilih
  Mingguan saat key kosong dan backup belum menyala — ditunda karena menyimpan form lain akan menulis frekuensi diam-diam.

## Riwayat revisi
- 23 Okt 2026: DRAFT pertama (11 AC).

## Review #1 — 2026-10-23

Verdict: APPROVED

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | minor | K2 / AC-6 | Harian tetap hanya ±3 hari (keluhan awal user), dan satu backup manual menggeser satu arsip terjadwal sehingga rentang "±3 minggu" bisa berkurang. Sudah diungkap di K5 dan teks bantuan. | Pastikan deskripsi Harian jelas menyebut riwayat pendek; cukup di teks, tanpa menambah aturan rotasi. |
| 2 | minor | K3 / AC-4 | Zona waktu sudah benar: `config/app.php` timezone = Asia/Jakarta dan event berzona sama, jadi `now('Asia/Jakarta')` konsisten. Filter hanya dievaluasi saat event jatuh tempo (01:30), sehingga cron yang mati pada hari Ahad/tanggal 1 melewatkan satu siklus (mingguan = celah 2 minggu). | Catat di Risiko; tidak perlu logika catch-up (YAGNI). |
| 3 | minor | AC-10 | Test seeder (SystemSeeder + InstallSeeder) mahal untuk jaminan yang sudah dipastikan K4 (key tidak di-seed). | Boleh diganti assert sederhana bahwa `backup.frequency` tidak ada di daftar seed, atau tetap bila murah. |
| 4 | minor | Action | `$old === $new` memakai nilai efektif `current()->value`, jadi no-change = no-log (F076 VERIFY) terjaga; test lama yang memakai `toHaveKeys` tidak pecah karena key baru. Pemanggil lama tanpa key aman. | Tidak ada tindakan; pastikan AC-7/AC-8 benar-benar dites. |

Cakupan kriteria issue: pilihan + penanda disarankan (AC-1, AC-6), scheduler hanya bila enabled (AC-3/4), rotasi sesuai teks (K2, AC-5, AC-6), activity log (AC-7). Nilai lama tetap harian (K4, AC-3, AC-10). Satu cron, tanpa dependensi baru.
