# F068 keuangan-export-queue — Ekspor CSV keuangan lewat antrean

Issue: #46
Status rencana: APPROVED (orkestrator, setelah revisi Review #1)
Jalur: penuh (keuangan, data pribadi santri, cakupan jenjang)

## Ringkasan & tujuan
Ekspor CSV Laporan Keuangan (F008) saat ini sinkron (`ExportFinanceReportCsv::handle()` → `StreamedResponse`). F068 memindahkannya ke Job antrean (driver `database`, worker dari scheduler) agar klik ekspor tidak menunggu: permintaan web bisa timeout di proxy/hosting dan menahan proses PHP-FPM, sedangkan worker CLI tidak. Dikerjakan atas persetujuan user 22 Okt 2026 (komentar issue), bukan karena sudah ada bukti ekspor lambat. Hasil disimpan di disk `private`, tautan unduh dikirim lewat notifikasi database Filament, berkas lama dibersihkan berkala.

Hasil grep: satu-satunya ekspor CSV keuangan adalah `ExportFinanceReportCsv` (yang lain hanya template impor `HasCsvImportActions`, di luar lingkup).

## Ruang lingkup
- Termasuk: Job ekspor, penyimpanan ke `private`, route unduh, notifikasi database, command + jadwal pembersihan, penyesuaian aksi `export` di `FinanceReport`, teks `lang/id`.
- Tidak termasuk (eksplisit): tabel riwayat ekspor / halaman daftar ekspor; route bertanda tangan (`temporarySignedRoute`); ekspor CSV modul lain; setting masa simpan (konstanta cukup); log activity terpisah untuk setiap unduhan; polling notifikasi (panel tetap `databaseNotificationsPolling(null)`, pesan meminta muat ulang seperti F052); format berkas lain (XLSX/PDF).

## Keputusan atas nama user (user tidur, opsi paling aman/YAGNI)
1. **Tanpa tabel baru.** Metadata dari path: `finance-exports/{user_id}/{uuid}/{nama-berkas}.csv` di disk `private`. `{nama-berkas}` sama dengan nama sinkron sekarang (`laporan-keuangan-{section}-{from}-{to}.csv`).
2. **Tanpa signed URL.** Route ada di `authenticatedRoutes` panel; controller hanya mencari di folder `finance-exports/{auth()->id()}/` sehingga user lain tidak bisa mengakses (404, tidak membocorkan keberadaan berkas, konsisten dengan `DownloadPaymentProof`). UUID tidak bisa ditebak; masa berlaku dijaga pembersihan.
3. **Permission sama:** `view:finance-report` (dicek di click, di Job, dan di unduh) + middleware `EnsureModuleIsEnabled:keuangan`. Tidak punya permission saat unduh → 403.
4. **Cakupan jenjang:** Job memasang user pemicu ke guard (`Auth::setUser`) selama ekspor sehingga `UnitScope` dan validasi `FinanceReportFilter::make()` (jenjang harus dalam cakupan) berlaku persis seperti sinkron; guard dikembalikan ke keadaan semula di `finally` (worker tidak "tertinggal login"). Pola mirip `HasCsvImportActions`.
5. **Activity log:** `exported` kini berarti "berkas ekspor dibuat", dicatat saat Job selesai menulis berkas (causer = pemicu, properti `section`, `filter`, `rows` seperti sekarang; docblock Action diperbarui). Setiap unduhan berhasil dicatat ringan sebagai activity `keuangan`/`downloaded` (causer, properti `export` = uuid, `filename`), karena issue berlabel audit iso dan berkas memuat data pribadi; biayanya satu baris.
6. **Masa simpan 24 jam** (`PruneFinanceExports::RETENTION_HOURS = 24`, sama dengan `PruneOrphanPaymentProofs::GRACE_HOURS`). Command `keuangan:prune-finance-exports` dijadwalkan **setiap jam** agar berkas tidak hidup jauh melewati 24 jam.
7. **Jalur sinkron dihapus.** `handle()` tidak lagi mengembalikan `StreamedResponse`; page tidak punya pemanggil sinkron lain (DRY, satu jalur).

## Model data
Tidak ada migration. Tabel `notifications` sudah ada (`2026_10_23_000002_create_notifications_table.php`) dan panel sudah `->databaseNotifications()`. Berkas hanya di disk `private`.

## Action class
- **`app/Actions/Keuangan/ExportFinanceReportCsv`** (ubah): `handle(FinanceReportFilter $filter, string $section, User $user): string` → menyusun baris lewat `build()` yang ada, menulis byte yang identik dengan ekspor lama (BOM UTF-8 + `fputcsv($row, ';', '"', '')`) ke `finance-exports/{user_id}/{uuid}/{filename}` di disk `private`, mencatat activity `exported`, mengembalikan path. Tambah `filename(FinanceReportFilter, string): string` (dipakai bersama) dan konstanta `DISK = 'private'`, `DIRECTORY = 'finance-exports'`. `rows()` tetap. Section tidak valid → `ValidationException` sebelum menulis berkas/log. Docblock kelas diperbarui: berkas disimpan, `exported` = berkas dibuat.
- **`app/Actions/Keuangan/PruneFinanceExports`** (baru): `handle(?CarbonInterface $now = null): int` → hapus berkas di `finance-exports` dengan `lastModified` lebih tua dari 24 jam, lalu hapus folder `{uuid}` yang kosong; abaikan berkas titik; gagal hapus dicatat `Log::warning` dan dilewati (pola `PruneOrphanPaymentProofs`). Tidak menyentuh folder lain.
- **`app/Jobs/Keuangan/ExportFinanceReportJob`** (baru, pola `GenerateInvoicesJob`): properti `?User $user`, `string $section`, `array $filter` (hasil `FinanceReportFilter::toArray()`); `$tries = 1`, `$timeout = 300` (worker tanpa `--timeout` memakai 60 dtk; 300 tetap di bawah `retry_after` 1900), `$deleteWhenMissingModels = true`. `handle()`: bila user tidak lagi punya `view:finance-report` atau modul keuangan mati → selesai tanpa berkas/log/notifikasi. Selain itu simpan `$previous = Auth::user()`, pasang guard ke pemicu, `FinanceReportFilter::make(...)` ulang, panggil Action, kirim notifikasi `success` dengan aksi "Unduh" ke `route('filament.admin.keuangan.finance-export', $uuid, absolute: false)` (URL relatif; `APP_URL` di cPanel bisa salah). Di `finally`: `Auth::setUser($previous)` bila ada, selain itu `Auth::forgetUser()`. `ValidationException` → notifikasi `danger` berisi pesan error pertama, Job selesai normal. `failed()` → notifikasi `danger` generik tanpa detail exception.
- **`app/Http/Controllers/Keuangan/DownloadFinanceExport`** (baru): `__invoke(string $export)`; `abort_unless(user->can('view:finance-report'), 403)`; cari berkas `.csv` tunggal di `finance-exports/{auth id}/{export}/`; tidak ada → 404; catat activity `downloaded`; `download($path, basename, ['X-Content-Type-Options' => 'nosniff'])`.
- **`app/Console/Commands/PruneFinanceExportsCommand`** (baru): signature `keuangan:prune-finance-exports`, memanggil Action, menampilkan jumlah berkas terhapus.

## UI (Filament / Blade)
- `app/Filament/Pages/FinanceReport.php` aksi `export`: tetap `authorize(canAccess)`, `visible` sama, modal pilih bagian sama. `action()`: `appliedFilter()` (validasi cakupan saat klik), cek section di `SECTIONS` (tidak valid → error, tanpa dispatch), `dispatch(ExportFinanceReportJob::...)`, lalu toast `success` "Ekspor sedang diproses" + body arahkan ke ikon lonceng dan muat ulang (design-guide §state: berhasil menyebut objeknya; gagal = `danger` yang bisa ditindaklanjuti).
- `lang/id/pondok.php` `finance_report.export`: ubah `submit` → "Proses ekspor", `description` tambah kalimat bahwa tautan dikirim ke notifikasi; tambah `queued`, `queued_body`, `ready` (judul notifikasi, menyebut bagian & periode), `ready_body` (berlaku 24 jam), `download` (label aksi), `failed`; `finance_report.prune_done` untuk command.
- Checklist design-guide §0 untuk toast/notifikasi (teks Indonesia, tanpa jargon "job/queue").

## Permission & cakupan
Tidak ada permission baru: `view:finance-report` (bendahara, super-admin sesuai seeder sekarang). Cakupan `unit_user` pemicu diterapkan di dalam Job. Route di grup `EnsureModuleIsEnabled:keuangan` di `AdminPanelProvider::authenticatedRoutes`, `->whereUuid('export')`, nama `keuangan.finance-export`. Jadwal: `Schedule::command('keuangan:prune-finance-exports')->when(fn (): bool => Module::Keuangan->isEnabled())->hourly()->withoutOverlapping()`.

## Kriteria penerimaan (bisa dites)
- **AC-1** Bendahara dengan laporan valid → `callAction('export', ['section' => 'tunggakan'])` dengan `Queue::fake()` → `ExportFinanceReportJob` di-push satu kali dengan user, section, dan filter yang tampil; tidak ada file diunduh, tidak ada berkas di `private`, tidak ada activity `exported`; toast `queued` tampil.
- **AC-2** Aksi `export` saat permission dicabut atau modul keuangan mati, atau dengan `report.filter.unit_id` dirusak ke jenjang di luar cakupan → tidak ada Job di-push, tidak ada activity (pengganti test AC-25 lama).
- **AC-3** Menjalankan Job (`Storage::fake('private')`) → tepat satu berkas `finance-exports/{user_id}/{uuid}/laporan-keuangan-tunggakan-2026-10-2026-10.csv`; isinya byte-identik dengan BOM + `rows()` yang dirender `fputcsv(';', '"', '')` untuk filter dan section yang sama, untuk keempat section.
- **AC-4** Job untuk user dengan cakupan SLTP, dijalankan tanpa user login (konteks worker) → CSV hanya memuat santri SLTP (sama dengan `rows()` saat `actingAs` user itu), walau filter `unit_id` null; setelah Job, `Auth::user()` kembali `null`. Bila dijalankan saat ada user lain login (antrean `sync`), user itu dipulihkan.
- **AC-5** Job selesai → satu activity `keuangan`/`exported` dengan causer pemicu dan properti `section`, `filter`, `rows` (jumlah baris data, bukan header).
- **AC-6** Job selesai → pemicu punya satu notifikasi database Filament `success` yang memuat path unduh `/…/finance-exports/{uuid}` (assert path relatif, tidak mengunci host); user lain tidak menerima notifikasi. Job punya `timeout` 300 (`tries` 1).
- **AC-7** Job dengan filter yang kini tidak valid untuk pemicu (jenjang di luar cakupan setelah cakupan berubah) → tanpa berkas dan activity, notifikasi `danger` berisi pesan validasi. `failed()` → notifikasi `danger` generik; user sudah dihapus (`null`) tidak error.
- **AC-8** Pemicu kehilangan `view:finance-report` atau modul keuangan dimatikan sebelum Job berjalan → Job selesai tanpa berkas, activity, maupun notifikasi.
- **AC-9** GET route unduh oleh pemicu → 200, header `Content-Disposition` attachment dengan nama `laporan-keuangan-...csv`, `X-Content-Type-Options: nosniff`, isi sama dengan berkas; satu activity `keuangan`/`downloaded` dengan causer pemicu. Unduhan yang ditolak (AC-10/11) tidak mencatat activity.
- **AC-10** GET route unduh oleh user lain yang punya `view:finance-report` (UUID milik pemicu) → 404; UUID tak dikenal → 404; tamu → redirect login.
- **AC-11** Pemicu tanpa `view:finance-report` → 403; modul keuangan mati → 403 (middleware).
- **AC-12** `PruneFinanceExports` dengan berkas umur 25 jam dan 1 jam → yang 25 jam (dan folder `{uuid}`-nya) terhapus, yang 1 jam tetap; berkas di `payment-proofs` dan berkas titik tidak tersentuh; mengembalikan jumlah terhapus. Command `keuangan:prune-finance-exports` sukses dan memanggilnya.
- **AC-13** Jadwal `keuangan:prune-finance-exports` terdaftar setiap jam dan dilewati saat modul keuangan mati; `ModuleComplianceTest` (route + jadwal) tetap hijau.

## Rencana test
| AC | File | Nama test (usulan) |
|---|---|---|
| 1, 2 | `tests/Feature/Filament/Pages/FinanceReportTest.php` (ganti AC-25 lama) + `FinanceReportVerifyTest.php` (sesuaikan test `assertFileDownloaded`) | `queues the CSV export and shows a processing notice`; `does not queue the export without permission / module / with a tampered unit` |
| 3, 5 | `tests/Feature/Actions/Keuangan/ExportFinanceReportCsvTest.php` (sesuaikan test `handle()` lama) | `stores a CSV identical to rows() for every section`; `logs the export once` |
| 4, 6, 7, 8 | `tests/Feature/Jobs/Keuangan/ExportFinanceReportJobTest.php` (baru) | `applies the trigger unit scope in a worker context`; `restores the guard`; `notifies the trigger with a relative download link`; `sets a 300 second timeout`; `notifies danger on invalid filter / failure`; `skips when access was revoked` |
| 9, 10, 11 | `tests/Feature/Http/Keuangan/DownloadFinanceExportTest.php` (baru) | `lets the trigger download and logs it`; `hides other users' exports`; `forbids without permission or module` |
| 12, 13 | `tests/Feature/Actions/Keuangan/PruneFinanceExportsTest.php` (baru) | `deletes exports older than 24 hours only`; `schedules hourly behind the keuangan module` |

## Urutan implementasi
**Paket 1 — Action + Job + prune** (hijau: `ExportFinanceReportCsvTest`, `ExportFinanceReportJobTest`, `PruneFinanceExportsTest`)
1. Ubah `ExportFinanceReportCsv::handle()` menyimpan ke `private` + `filename()`.
2. `ExportFinanceReportJob` dengan pemasangan/pemulihan guard dan notifikasi.
3. `PruneFinanceExports` + command + jadwal di `routes/console.php`.

**Paket 2 — Route unduh + page** (hijau: `DownloadFinanceExportTest`, `FinanceReportTest`, `FinanceReportVerifyTest`, `ModuleComplianceTest`, `KeuanganAccessTest`)
4. `DownloadFinanceExport` + route di `AdminPanelProvider`.
5. Aksi `export` di `FinanceReport` → dispatch + toast; teks `lang/id`.
6. Pint, checklist design-guide §0.

## Risiko & pertanyaan terbuka
- Cakupan jenjang pemicu dipersempit setelah berkas jadi tetapi sebelum diunduh (≤24 jam): berkas lama tetap bisa diunduh pemicu. Diterima (sama dengan berkas yang sudah diunduh sinkron); dicatat, tidak ditangani.
- Notifikasi tidak muncul otomatis (polling mati); toast meminta muat ulang halaman, sama dengan F052.
- `UnitScope` membaca guard default (`Auth::user()`); panel tidak mengatur `authGuard`, jadi sama dengan guard default (`web`). Bila kelak panel memakai guard lain, Job harus ikut menyesuaikan.
- Antrean `sync` di dev/test: Job berjalan dalam request; pemulihan guard (AC-4) mencegah user terlogout.
- Pembersihan dilewati selama modul keuangan mati (aturan F066), sehingga berkas lama bisa tertinggal lebih dari 24 jam. Berkas tetap tak bisa diunduh (route juga tertutup) dan langsung terhapus pada jadwal pertama setelah modul dinyalakan; bisa juga dijalankan manual. Diterima.
- Antrean tunggal dan klik ganda: dua klik menghasilkan dua Job dan dua berkas, masing-masing dengan notifikasinya. Tidak merusak data (hanya baca) dan hilang setelah 24 jam; tanpa `ShouldBeUnique` (KISS, sama dengan F052). Ekspor juga menunggu di belakang Job lain (mis. generate tagihan) pada worker yang sama; diterima.
- Tidak ada pertanyaan yang memblokir; semua keputusan di atas bisa diubah user saat review.

## Riwayat revisi
- 22 Okt 2026: DRAFT pertama (product-manager).
- 22 Okt 2026: REVISED setelah Review #1 (lihat di bawah).

## Review #1 — 2026-10-22
Verdict plan-reviewer: CHANGES_REQUESTED (0 blocker, 1 major, 5 minor). Semua ditangani:
- **Major #1** Job tanpa `$timeout` → tambah `$timeout = 300` (< `retry_after` 1900) dan assert di AC-6.
- **Minor #2** URL notifikasi → `route(..., absolute: false)`; AC-6 tidak mengunci host.
- **Minor #3** Makna `exported` → kini "berkas dibuat" (docblock diperbarui); tambah activity `downloaded` di controller (AC-9).
- **Minor #4** Alasan → dikerjakan atas persetujuan user 22 Okt 2026; klaim `max_execution_time` diganti alasan timeout web/PHP-FPM.
- **Minor #5** Prune dilewati saat modul mati → dicatat di Risiko.
- **Minor #6** Antrean tunggal/klik ganda → dicatat di Risiko.
- Tambahan: pemulihan guard di `finally` dirinci (`setUser($previous)` atau `forgetUser()`).
