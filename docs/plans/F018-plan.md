# F018 dashboard — Dashboard role-aware

Status rencana: APPROVED

## Ringkasan & tujuan

Satu dashboard panel admin (`/admin`) yang isinya mengikuti permission user (PRD 3.10, PRD AC 1 dan 6). Setiap widget:

1. hanya terlihat bila user punya permission metriknya (`view-*-metrics`). Tanpa permission, widget tidak dirender sama sekali (design-guide §7);
2. tetap render dengan pesan "Modul belum tersedia." bila saklar `modules.<nama>.enabled` mati, tabel modulnya belum ada, atau sumber metriknya belum dibangun. Tidak ada error dan tidak ada tombol aksi;
3. hanya menampilkan link bila route tujuannya terdaftar **dan** user boleh membukanya;
4. menghormati cakupan jenjang (`unit_user`) karena semua angka diambil lewat model yang memakai global scope (`Student`, `Invoice`, `Payment`) atau `Unit::accessible()`.

Saat ini modul yang sudah ada datanya adalah Akademik (santri) dan Keuangan. Mengikuti catatan F018 di `feature_list.json` ("widget per modul ditambah seiring modulnya selesai"), F018 membangun kerangka bersama, widget lengkap untuk santri, keuangan, dan status sistem, serta widget PSB, konten, kelengkapan dokumen, dan prestasi yang untuk sementara selalu menampilkan "Modul belum tersedia." Widget-widget itu baru menampilkan angka setelah fitur modulnya mengisi sumber metriknya (lihat pertanyaan terbuka 1).

## Ruang lingkup

- Termasuk:
  - Trait bersama `App\Filament\Concerns\InteractsWithDashboardModule`: cek permission, cek ketersediaan (saklar modul, tabel, sumber metrik), helper link, dan render state "Modul belum tersedia".
  - Tujuh widget di `app/Filament/Widgets` (ditemukan otomatis lewat `discoverWidgets`):
    - `FinanceOverviewWidget`: keuangan bulan berjalan (lengkap).
    - `ActiveStudentsWidget`: santri aktif per jenjang dan angkatan (lengkap).
    - `SystemOverviewWidget`: status sistem untuk `view-settings-metrics` (lengkap).
    - `PsbOverviewWidget`, `ContentOverviewWidget`, `DocumentCompletenessWidget`, `RecentAchievementsWidget`: hanya kerangka, permission, dan state tidak tersedia.
  - Action baru `App\Actions\Dashboard\SummarizeActiveStudents`, `SummarizeCurrentMonthFinance`, `SummarizeSystemStatus`.
  - View Blade bersama `resources/views/filament/widgets/module-unavailable.blade.php` dan view widget kustom.
  - Teks `pondok.dashboard.*` di `lang/id/pondok.php`.
- Tidak termasuk (eksplisit):
  - Query metrik PSB (`applicants`), konten (`posts`/`albums`/`videos`), kelengkapan dokumen (`documents`), dan prestasi (`achievements`). Tabel/model itu belum ada dan tidak bisa dites sekarang. Pengisiannya ikut fitur modul masing-masing (usulan di laporan).
  - Grafik/chart, dashboard musyrif/ustadz/petugas pos (F003 menunda dashboard musyrif ke F018, tetapi peran itu belum punya permission metrik; lihat pertanyaan terbuka 8).
  - Cache hasil metrik (lihat pertanyaan terbuka 4).
  - Perubahan permission/peran di `RolePermissionSeeder` (semua permission dashboard sudah di-seed).
  - Halaman Dashboard kustom. `Filament\Pages\Dashboard` bawaan tetap dipakai; akses halaman sudah dijaga `User::canAccessPanel()` (`view-dashboard`).
  - Perubahan pada Action F008. Action itu dipakai ulang apa adanya.

## Model data

Tidak ada migration, tabel, atau kolom baru. Data dibaca dari:

| Widget | Modul (`modules.<nama>.enabled`) | Tabel wajib ada (`Schema::hasTable`) | Sumber |
|---|---|---|---|
| `FinanceOverviewWidget` | `keuangan` | `invoices`, `invoice_items`, `payments`, `payment_allocations`, `fee_types` | `SummarizeInvoices`, `SummarizeCashReceipts` (F008) |
| `ActiveStudentsWidget` | `akademik` | `students`, `units`, `cohorts` | `Student::query()->active()` |
| `SystemOverviewWidget` | (inti, tanpa saklar) | `academic_years`, `settings`, `users` | `AcademicYear::active()`, `Setting`, `User` |
| `PsbOverviewWidget` | `psb` | `applicants` | belum ada sumber |
| `ContentOverviewWidget` | `konten` | `posts`, `albums`, `videos` | belum ada sumber |
| `DocumentCompletenessWidget` | `dokumen` | `documents`, `document_types` | belum ada sumber |
| `RecentAchievementsWidget` | `prestasi` | `achievements`, `achievement_student` | belum ada sumber |

Uang tetap integer rupiah dari snapshot `invoices.total`/`paid_total` dan `payments.amount`; tampil lewat `Payment::formatRupiah()` (`Rp 1.250.000`, design-guide §5).

## Action class

Semua Action read-only, tanpa efek samping, tidak menulis activity log, dan jumlah query-nya tetap (tidak bergantung jumlah data). Tanpa user login, global scope tidak menyaring (konsisten dengan `UnitScope`).

### `App\Actions\Dashboard\SummarizeActiveStudents::handle(): array`

Output:
```
array{
  total: int,
  units: list<array{unit_id: int, name: string, total: int,
    cohorts: list<array{cohort_id: int, name: string, total: int}>}>
}
```
Aturan:
- Hitungan satu query agregat: `Student::query()->active()->toBase()->selectRaw('unit_id, cohort_id, COUNT(*) AS total')->groupBy('unit_id', 'cohort_id')`. Global scope `Student` (cakupan jenjang) dan `SoftDeletes` (santri diarsipkan) tetap berlaku karena berangkat dari `Student::query()`.
- Daftar jenjang dari `Unit::query()->accessible()->orderBy('sort_order')`: semua jenjang dalam cakupan tampil, termasuk yang 0.
- Nama angkatan dari `Cohort::query()->whereKey(<id hasil agregat>)`, urut `entry_year` menurun (angkatan terbaru di atas). Angkatan dengan 0 santri aktif tidak ditampilkan.
- Total = jumlah semua baris agregat.
- Maksimal 3 query.

### `App\Actions\Dashboard\SummarizeCurrentMonthFinance::handle(?CarbonInterface $asOf = null): array`

Output:
```
array{period: string /* Y-m */, invoice_count: int, billed: int, paid: int,
  collection_rate: ?int, overdue: int, overdue_student_count: int,
  cash_received: int, payment_count: int}
```
Aturan:
- `$filter = FinanceReportFilter::currentMonth()` (bulan berjalan zona `config('app.timezone')`).
- `billed`, `paid`, `invoice_count`, `collection_rate`, `overdue`, `overdue_student_count` dari `totals` milik `SummarizeInvoices::handle($filter, $asOf)`. Artinya: tagihan non-`batal` periode bulan ini; menunggak = sisa tagihan `belum`/`sebagian` periode bulan ini yang jatuh temponya sebelum hari ini.
- `cash_received` dan `payment_count` dari `total` dan `payment_count` milik `SummarizeCashReceipts::handle($filter)`: pembayaran berstatus `diterima` dengan `paid_on` di bulan ini (catatan F007/F008).
- Tidak menghitung ulang apa pun; aturan bisnis tetap di Action F008.

### `App\Actions\Dashboard\SummarizeSystemStatus::handle(): array`

Output:
```
array{active_academic_year: ?string, enabled_module_count: int,
  module_count: int, active_user_count: int}
```
Aturan:
- `active_academic_year`: `AcademicYear::query()->active()->value('name')`, NULL bila tidak ada.
- `module_count`: jumlah baris `settings` dengan key berpola `modules.%.enabled` (query `LIKE`, portabel). `enabled_module_count`: dari baris itu yang nilainya true. Kode aplikasi tidak membaca konstanta `SettingSeeder`.
- `active_user_count`: `User::query()->where('is_active', true)->count()`.

## UI (Filament / Blade)

Rujukan: design-guide §3 (ikon), §5 (format), §6 "Dashboard", §7 (state), §9 (bahasa), §0 (checklist). Implementer wajib menjalankan alur §13 (anti-ui-slop) dan checklist §0.

### Trait `App\Filament\Concerns\InteractsWithDashboardModule`

Dipakai semua widget F018:
- `abstract public static function getDashboardPermission(): string`.
- `public static function getDashboardModule(): ?string` (NULL = inti, tanpa saklar).
- `public static function getRequiredTables(): array` (list nama tabel).
- `public static function hasMetricSource(): bool` (bawaan `true`; keempat widget kerangka mengembalikan `false`).
- `public static function canView(): bool` → `(bool) auth()->user()?->can(static::getDashboardPermission())`. Tidak memeriksa saklar modul; widget tetap tampil dengan state tidak tersedia.
- `public static function isModuleAvailable(): bool` → modul NULL atau `Setting::moduleEnabled()` true, **dan** semua `Schema::hasTable()` true, **dan** `hasMetricSource()`. Urutan cek: saklar, lalu sumber, lalu tabel (berhenti di cek pertama yang gagal sehingga tabel tidak diperiksa bila modul mati).
- `public static function urlIfRouteAvailable(string $routeName, bool $authorized = true, array $parameters = []): ?string` → `route($routeName, $parameters)` hanya bila `$authorized` dan `Route::has($routeName)`, selain itu NULL.
- `render()`: bila `! static::isModuleAvailable()`, render `filament.widgets.module-unavailable` dengan judul widget, ikon modul, dan teks `pondok.dashboard.module_unavailable`. Metrik tidak dihitung sama sekali. Bila tersedia, `parent::render()`.
- Semua widget F018 memakai `protected static bool $isLazy = false` (dirender di request halaman, sehingga GET `/admin` benar-benar menguji render) dan tanpa polling (`protected ?string $pollingInterval = null` pada `StatsOverviewWidget`; bawaan Filament 5 detik terlalu berat untuk shared hosting).
- Tidak menangkap exception secara umum. Ketersediaan dijamin oleh cek di atas, sehingga bug query tetap terlihat di test.

### Widget

Urutan (`$sort`) mengikuti design-guide §6: stat dulu, lalu daftar, lalu status sistem. `AccountWidget` bawaan tetap di posisi pertama.

| Widget | Jenis | `$sort` | Permission | Ikon (§3) | Isi saat tersedia |
|---|---|---|---|---|---|
| `FinanceOverviewWidget` | `StatsOverviewWidget` | 10 | `view-finance-metrics` | `Banknotes` | 4 stat, lihat di bawah |
| `PsbOverviewWidget` | `StatsOverviewWidget` | 20 | `view-psb-metrics` | `ClipboardDocumentList` | kerangka |
| `ContentOverviewWidget` | `StatsOverviewWidget` | 30 | `view-content-metrics` | `Newspaper` | kerangka |
| `ActiveStudentsWidget` | `Widget` + view | 40 | `view-student-metrics` | `AcademicCap` | tabel jenjang/angkatan |
| `DocumentCompletenessWidget` | `Widget` + view | 50 | `view-student-metrics` | `DocumentText` | kerangka |
| `RecentAchievementsWidget` | `Widget` + view | 60 | `view-student-metrics` | `Trophy` | kerangka |
| `SystemOverviewWidget` | `StatsOverviewWidget` | 70 | `view-settings-metrics` | `Cog6Tooth` | 3 stat |

**FinanceOverviewWidget**: heading "Keuangan {Oktober 2026}" (`translatedFormat('F Y')`), dari `SummarizeCurrentMonthFinance`:
1. "Ditagihkan": `Rp billed`, deskripsi ":count tagihan".
2. "Terbayar": `Rp paid`, deskripsi ":rate% terkumpul" atau "-" bila `collection_rate` NULL. Warna `success`.
3. "Lewat jatuh tempo": `Rp overdue`, deskripsi ":count santri". Warna `danger` bila > 0, selain itu `gray`.
4. "Kas diterima": `Rp cash_received`, deskripsi ":count pembayaran".
Bila `invoice_count = 0` dan `payment_count = 0`: tetap 4 stat bernilai `Rp 0` dengan deskripsi stat 1 "Belum ada tagihan untuk bulan ini." Link (bila ada) di header widget: "Lihat laporan keuangan" ke `FinanceReport` (`urlIfRouteAvailable(<route FinanceReport>, FinanceReport::canAccess())`). Implementer mengambil nama route dari `FinanceReport::getRouteName()`.

**ActiveStudentsWidget**: section "Santri aktif", angka total besar (`tabular-nums`), lalu tabel sederhana: Jenjang | Angkatan | Jumlah (rata kanan), baris subtotal per jenjang. Jenjang 0 tampil dengan baris "Belum ada santri aktif." Bila total 0: empty state ikon + "Belum ada santri aktif." Link "Lihat data santri" ke index `StudentResource` bila `StudentResource::canViewAny()` (route dari `StudentResource::getRouteBaseName().'.index'`).

**SystemOverviewWidget**: heading "Status sistem":
1. "Tahun ajaran aktif": nama atau "-" dengan deskripsi "Belum ada tahun ajaran aktif." (warna `warning`).
2. "Modul aktif": ":enabled dari :total".
3. "Pengguna aktif": angka.
Link "Atur tahun ajaran" ke index `AcademicYearResource` bila `AcademicYearResource::canViewAny()`.

**Empat widget kerangka**: `hasMetricSource()` = `false`, `getStats()`/view tidak berisi angka. Selalu render state tidak tersedia, tanpa link.

**View bersama** `resources/views/filament/widgets/module-unavailable.blade.php`: `<x-filament-widgets::widget>` + `<x-filament::section>` berjudul nama widget, ikon Heroicon Outlined modul, satu kalimat `pondok.dashboard.module_unavailable`. Tanpa warna mentah, tanpa tombol. Widget kustom memakai `resources/views/filament/widgets/active-students.blade.php` (dan view kerangka untuk dokumen/prestasi bila dibutuhkan Filament; kontennya tetap lewat state tidak tersedia).

### Teks `lang/id/pondok.php` (blok baru `dashboard`)

`module_unavailable` = "Modul belum tersedia." (teks PRD 3.10; `common.module_disabled` tetap untuk 403), `finance.heading` = "Keuangan :period", `finance.billed`, `finance.billed_description` (":count tagihan"), `finance.no_invoices`, `finance.paid`, `finance.paid_description` (":rate% terkumpul"), `finance.overdue`, `finance.overdue_description` (":count santri"), `finance.cash`, `finance.cash_description` (":count pembayaran"), `finance.report_link` ("Lihat laporan keuangan"), `students.heading` ("Santri aktif"), `students.unit`, `students.cohort`, `students.count`, `students.subtotal`, `students.empty` ("Belum ada santri aktif."), `students.list_link` ("Lihat data santri"), `psb.heading` ("Pendaftaran santri baru"), `content.heading` ("Konten"), `documents.heading` ("Kelengkapan dokumen"), `achievements.heading` ("Prestasi terbaru"), `system.heading` ("Status sistem"), `system.academic_year`, `system.no_academic_year`, `system.modules`, `system.modules_value` (":enabled dari :total"), `system.users`, `system.academic_year_link` ("Atur tahun ajaran"). Kapital hanya di awal kalimat (§3).

## Permission & cakupan

- Tidak ada permission baru. Pemetaan widget → permission ada di tabel UI. Dokumen dan prestasi memakai `view-student-metrics` (pertanyaan terbuka 2).
- Akses halaman: `view-dashboard` lewat `User::canAccessPanel()` (sudah ada, tidak diubah).
- Matriks hasil dari `RolePermissionSeeder` saat ini (selain `AccountWidget` yang tampil untuk semua):

| Peran | Widget F018 yang terlihat |
|---|---|
| super-admin | ketujuh widget |
| bendahara | Finance |
| admin-konten | Content |
| admin-sekolah | ActiveStudents, DocumentCompleteness, RecentAchievements, SystemOverview |
| admin-psb | PsbOverview |
| kesantrian | ActiveStudents, DocumentCompleteness, RecentAchievements |
| musyrif, ustadz-tahfidz, petugas-pos | tidak ada |
| wali | 403 di `/admin` (sudah ada) |

- Cakupan jenjang: `SummarizeActiveStudents` (global scope `Student` + `Unit::accessible()`), `SummarizeCurrentMonthFinance` (global scope `Invoice`/`Payment` lewat `FinanceReportFilter`). `SystemOverviewWidget` tidak bergantung jenjang.
- Saklar: `keuangan`, `akademik`, `psb`, `konten`, `dokumen`, `prestasi` sesuai tabel model data. Saklar mati tidak menyembunyikan widget, hanya mengganti isinya dengan state tidak tersedia.

## Kriteria penerimaan (bisa dites)

Kondisi umum: `RolePermissionSeeder` dan `SettingSeeder` di-seed, `UnitSeeder` (SD, SLTP, SLTA), waktu dibekukan di `2026-10-15 10:00` Asia/Jakarta, panel `admin` aktif.

- **AC-1 (akses per peran)**: Untuk setiap peran panel (`super-admin`, `bendahara`, `admin-konten`, `admin-sekolah`, `admin-psb`, `kesantrian`, `musyrif`, `ustadz-tahfidz`, `petugas-pos`), GET `/admin` → 200, dan daftar widget F018 yang dirender di `Filament\Pages\Dashboard` **persis** sesuai matriks di bagian Permission. Widget di luar matriks tidak dirender (tidak ada heading widget itu di HTML).
- **AC-2 (permission, bukan peran)**: User tanpa peran yang hanya punya `view-dashboard` dan `view-finance-metrics` → hanya `FinanceOverviewWidget` (plus `AccountWidget`). Setiap widget F018: `canView()` false untuk user tanpa permission metriknya dan false tanpa user login.
- **AC-3 (wali)**: `wali` GET `/admin` → 403 (test lama tetap lulus).
- **AC-4 (saklar mati)**: `modules.keuangan.enabled = false` → `bendahara` GET `/admin` → 200, HTML memuat "Modul belum tersedia." di widget keuangan, tidak memuat "Ditagihkan", nominal "Rp", maupun link laporan keuangan. `modules.akademik.enabled = false` → `admin-sekolah` mendapat state yang sama di `ActiveStudentsWidget` (tanpa "Lihat data santri"). `SummarizeCurrentMonthFinance`/`SummarizeActiveStudents` tidak dipanggil (dibuktikan dengan mock Action yang `shouldNotReceive('handle')`).
- **AC-5 (tabel belum ada)**: Dengan FK dimatikan sementara, tabel `payment_allocations` di-drop → `bendahara` GET `/admin` → 200 dan widget keuangan menampilkan "Modul belum tersedia." Tabel `cohorts` di-drop → `kesantrian` GET `/admin` → 200 dan widget santri menampilkan "Modul belum tersedia."
- **AC-6 (modul belum dibangun)**: Saklar `psb`, `konten`, `dokumen`, `prestasi` diaktifkan → super-admin GET `/admin` → 200; `PsbOverviewWidget`, `ContentOverviewWidget`, `DocumentCompletenessWidget`, `RecentAchievementsWidget` masing-masing menampilkan heading-nya dan "Modul belum tersedia.", tanpa link. Hasil sama saat saklar mati. `isModuleAvailable()` keempatnya false.
- **AC-7 (angka keuangan)**: Santri SLTP dan SLTA aktif; tagihan periode Oktober 2026: A total 500.000 `lunas` (paid 500.000), B total 300.000 `sebagian` (paid 100.000) jatuh tempo 2026-10-10, C total 200.000 `belum` jatuh tempo 2026-10-20, D total 400.000 `batal`; tagihan September 2026 total 999.000 `sebagian` (paid 70.000). Pembayaran `diterima` paid_on Oktober: 500.000 untuk A dan 100.000 untuk B (2 pembayaran), satu pembayaran Oktober 50.000 untuk C yang kemudian `batal` (paid_total C kembali 0), satu pembayaran `diterima` paid_on 30 September 70.000 untuk tagihan September. Data dibuat lewat `RecordPayment`/`CancelPayment` atau factory dengan `paid_total` konsisten. → `SummarizeCurrentMonthFinance::handle()` = `period` `2026-10`, `invoice_count` 3, `billed` 1.000.000, `paid` 600.000, `collection_rate` 60, `overdue` 200.000, `overdue_student_count` 1, `cash_received` 600.000, `payment_count` 2. Widget `bendahara` menampilkan "Keuangan Oktober 2026", "Rp 1.000.000", "Rp 600.000", "60% terkumpul", "Rp 200.000", "Rp 600.000".
- **AC-8 (keuangan tanpa data)**: Tanpa tagihan dan pembayaran bulan ini → `collection_rate` NULL; widget menampilkan empat "Rp 0", deskripsi "Belum ada tagihan untuk bulan ini.", dan deskripsi terbayar "-".
- **AC-9 (cakupan jenjang keuangan)**: Data AC-7, `bendahara` dengan baris `unit_user` SLTP saja dan tagihan A, B milik santri SLTP sedangkan C milik santri SLTA → hanya A dan B serta pembayarannya terhitung (`billed` 800.000). `bendahara` tanpa baris `unit_user` → `billed` 1.000.000.
- **AC-10 (link keuangan)**: `bendahara` melihat link dengan href = URL `FinanceReport`. User dengan `view-dashboard` + `view-finance-metrics` tanpa `view:finance-report` → widget tampil tanpa link itu.
- **AC-11 (santri aktif)**: SLTP: angkatan 2025 dua santri aktif, angkatan 2026 tiga santri aktif, satu santri `lulus`, satu santri aktif yang di-soft-delete; SLTA: angkatan 2026 satu santri aktif; SD tanpa santri. → `SummarizeActiveStudents::handle()`: `total` 6; `units` urut `sort_order` SD (0, cohorts kosong), SLTP (5, cohorts [2026: 3, 2025: 2] urut `entry_year` menurun), SLTA (1). Widget `admin-sekolah` memuat "Santri aktif", "6", nama tiap angkatan dengan jumlahnya, dan baris SD "Belum ada santri aktif."
- **AC-12 (cakupan jenjang santri)**: Data AC-11, `kesantrian` dengan `unit_user` SLTP → `total` 5, `units` hanya SLTP. Tanpa user login → `total` 6.
- **AC-13 (santri kosong & link)**: Tanpa santri aktif → widget memuat "Belum ada santri aktif." `admin-sekolah` (punya `view-any:student`) melihat link "Lihat data santri" ke index `StudentResource`; user dengan `view-dashboard` + `view-student-metrics` tanpa `view-any:student` → widget tampil tanpa link.
- **AC-14 (status sistem)**: Tahun ajaran aktif "2026/2027", `SettingSeeder` (9 saklar, 3 aktif), 4 user aktif dan 1 nonaktif → `SummarizeSystemStatus::handle()` = `active_academic_year` "2026/2027", `enabled_module_count` 3, `module_count` 9, `active_user_count` 4. Widget `admin-sekolah` memuat "2026/2027" dan "3 dari 9". Tanpa tahun ajaran aktif → `active_academic_year` NULL dan widget memuat "Belum ada tahun ajaran aktif." `admin-sekolah` tidak melihat link "Atur tahun ajaran" bila tidak punya `view-any:academic-year`; super-admin melihatnya.
- **AC-15 (helper link)**: `urlIfRouteAvailable('filament.admin.resources.applicants.index')` → NULL (route tidak terdaftar, tanpa exception); route terdaftar dengan `$authorized = false` → NULL; route terdaftar dan `$authorized = true` → string URL yang sama dengan `route()`.
- **AC-16 (render langsung, tanpa polling)**: Ketujuh widget punya `isLazy()` false; widget turunan `StatsOverviewWidget` mengembalikan `getPollingInterval()` NULL. HTML GET `/admin` super-admin memuat heading ketujuh widget (bukan placeholder lazy) dan tidak memuat atribut `wire:poll`.
- **AC-17 (query tetap)**: Super-admin GET `/admin` dengan 2 santri/2 tagihan lalu dengan 30 santri/30 tagihan tersebar di 3 jenjang dan 3 angkatan → jumlah query (query log) sama pada kedua kondisi.
- **AC-18 (teks)**: Tidak ada kunci mentah `pondok.dashboard.` di HTML `/admin` super-admin; `__('pondok.dashboard.module_unavailable')` = "Modul belum tersedia.".

## Rencana test

| AC | File | Nama test yang diusulkan |
|---|---|---|
| AC-1 | `tests/Feature/Filament/DashboardAccessTest.php` | `it renders only the widgets allowed for :role` (dataset 9 peran → daftar widget) |
| AC-2 | `tests/Feature/Filament/DashboardAccessTest.php` | `it follows permissions rather than roles`, `it hides every dashboard widget from guests and users without the metric permission` |
| AC-3 | `tests/Feature/Filament/PanelAccessTest.php` (sudah ada) | `returns 403 to a role without view-dashboard` |
| AC-4 | `tests/Feature/Filament/DashboardModuleAvailabilityTest.php` | `it shows module unavailable when keuangan is disabled`, `it shows module unavailable when akademik is disabled` |
| AC-5 | `tests/Feature/Filament/DashboardModuleAvailabilityTest.php` | `it still renders when a finance table is missing`, `it still renders when a student table is missing` |
| AC-6 | `tests/Feature/Filament/DashboardModuleAvailabilityTest.php` | `it shows module unavailable for modules that are not built yet` |
| AC-7, AC-8 | `tests/Feature/Actions/Dashboard/SummarizeCurrentMonthFinanceTest.php`, `tests/Feature/Filament/Widgets/FinanceOverviewWidgetTest.php` | `it summarizes the current month`, `it returns zeros without data`, `it renders current month finance`, `it renders the empty month` |
| AC-9 | `tests/Feature/Actions/Dashboard/SummarizeCurrentMonthFinanceTest.php` | `it respects the unit scope` |
| AC-10 | `tests/Feature/Filament/Widgets/FinanceOverviewWidgetTest.php` | `it links to the finance report only when allowed` |
| AC-11, AC-12 | `tests/Feature/Actions/Dashboard/SummarizeActiveStudentsTest.php`, `tests/Feature/Filament/Widgets/ActiveStudentsWidgetTest.php` | `it counts active students per unit and cohort`, `it respects the unit scope`, `it renders active students per unit and cohort` |
| AC-13 | `tests/Feature/Filament/Widgets/ActiveStudentsWidgetTest.php` | `it shows the empty state`, `it links to the student list only when allowed` |
| AC-14 | `tests/Feature/Actions/Dashboard/SummarizeSystemStatusTest.php`, `tests/Feature/Filament/Widgets/SystemOverviewWidgetTest.php` | `it summarizes system status`, `it handles a missing active academic year`, `it links to academic years only when allowed` |
| AC-15 | `tests/Feature/Filament/Widgets/DashboardWidgetLinkTest.php` | `it returns null for unregistered routes`, `it returns null when not authorized`, `it returns the route url when available` |
| AC-16 | `tests/Feature/Filament/DashboardAccessTest.php` | `it renders widgets eagerly without polling` |
| AC-17 | `tests/Feature/Filament/DashboardPerformanceTest.php` | `it keeps the dashboard query count independent of data volume` |
| AC-18 | `tests/Feature/Filament/DashboardAccessTest.php` | `it has no raw translation keys` |

Catatan test: render widget bisa diperiksa lewat GET `/admin` (widget tidak lazy) dan/atau `Livewire::test(<Widget>::class)`. Drop tabel di AC-5 memakai `Schema::disableForeignKeyConstraints()` di SQLite dalam transaksi `RefreshDatabase`.

## Urutan implementasi

1. `lang/id/pondok.php`: blok `dashboard`.
2. Trait `InteractsWithDashboardModule` + view `module-unavailable` (AC-15, AC-16 sebagian).
3. Empat widget kerangka (AC-6, AC-1 sebagian).
4. `SummarizeActiveStudents` + `ActiveStudentsWidget` + view (AC-11–13, AC-5 santri, AC-4 santri).
5. `SummarizeCurrentMonthFinance` + `FinanceOverviewWidget` (AC-7–10, AC-4/5 keuangan). Bergantung pada Action F008 yang sudah ada di repo.
6. `SummarizeSystemStatus` + `SystemOverviewWidget` (AC-14).
7. Matriks peran, query tetap, dan teks (AC-1–3, AC-16–18), lalu Pint dan checklist design-guide §0.

## Risiko & pertanyaan terbuka

1. **Widget modul yang belum dibangun.** Orkestrator ingin widget "otomatis aktif saat modulnya ada", sedangkan catatan F018 di `feature_list.json` berbunyi "widget per modul ditambah seiring modulnya selesai". Menulis query `applicants`/`posts`/`documents`/`achievements` sekarang berarti kode yang tidak bisa dites dan menebak kolom. *Default:* F018 hanya memasang kerangka (permission, saklar, cek tabel, state tidak tersedia) dengan `hasMetricSource() = false`. Fitur modul mengisi Action metrik dan mengubah `hasMetricSource()` menjadi true, beserta test-nya. Usulan catatan di `feature_list.json`: F010 (atau F011) untuk statistik PSB (total, per status, 7 hari terakhir), F013 untuk kelengkapan dokumen (memakai `Student::withIncompleteDocuments()`), F015 untuk prestasi terbaru, F016 untuk jumlah konten.
2. **Permission widget dokumen dan prestasi** tidak disebut PRD. *Default:* `view-student-metrics` (admin-sekolah, kesantrian, super-admin). Alternatif: prestasi juga untuk `view-content-metrics`.
3. **Isi widget `view-settings-metrics`** tidak dirinci PRD. *Default:* status sistem minimal (tahun ajaran aktif, modul aktif, pengguna aktif). Semuanya bersumber data nyata.
4. **Cache.** *Default:* tanpa cache. Jumlah query tetap (AC-17), widget tidak lazy dan tanpa polling, dan data keuangan langsung mutakhir setelah pembayaran dicatat. Bila nanti lambat, tambahkan `Cache::remember` 5 menit per user (driver `database`/`file`) dengan kunci yang memuat cakupan jenjang. Itu fitur terpisah.
5. **Teks state.** PRD memakai "Modul belum tersedia", `common.module_disabled` berbunyi "Modul belum diaktifkan." (dipakai untuk 403). *Default:* kunci baru `pondok.dashboard.module_unavailable` = "Modul belum tersedia." Design-guide §7 menyebut `common.module_disabled`; penyimpangan ini dilaporkan (PRD menang atas dokumen lain).
6. **"Lewat jatuh tempo" di widget keuangan** hanya untuk tagihan periode bulan ini (sesuai "keuangan bulan berjalan" dan `SummarizeInvoices`), bukan tunggakan sepanjang masa. *Default:* bulan ini. Label stat memakai heading widget yang menyebut bulannya.
7. **Ketergantungan F008.** Widget keuangan memanggil `FinanceReportFilter`, `SummarizeInvoices`, `SummarizeCashReceipts` yang belum ditandai `done`. *Default:* langkah 5 dikerjakan setelah F008 VERIFY PASS (kodenya sudah ada di working tree). Usulan: tambahkan `F008` ke `depends_on` F018.
8. **Dashboard musyrif/ustadz-tahfidz/petugas-pos** (F003 menundanya ke F018). Peran itu hanya punya `view-dashboard`, jadi hanya `AccountWidget` yang tampil. *Default:* di luar lingkup F018; usulan entri baru bila diinginkan (mis. "dashboard-musyrif": penghuni asrama yang diawasi).
9. **Drop tabel di test** hanya aman di SQLite (DDL di MySQL memicu implicit commit). Suite memakai SQLite `:memory:`, jadi diterima.
10. **Exception** tidak ditangkap umum. Bila tabel ada tetapi skemanya berbeda, halaman bisa error. Ini disengaja agar bug tidak tersembunyi; ketersediaan dijaga oleh saklar + `Schema::hasTable`.

## Riwayat revisi

- r1 (DRAFT): rencana awal.

## Review #1 — 2026-10-15

Verdict: APPROVED

Semua `acceptance` F018 dan PRD 3.10 serta PRD 5 butir 1 dan 6 tercakup (AC-1 s.d. AC-6, AC-15). Dependensi F001 dan F008 berstatus `done` (F008 sudah ada di `depends_on` F018 di `feature_list.json`). Tidak ada migration, jadi tidak ada risiko ERD. Pemakaian ulang `FinanceReportFilter`, `SummarizeInvoices`, `SummarizeCashReceipts` sudah dicek terhadap kodenya: global scope `Invoice`/`Payment` tetap berlaku, jumlah query tetap, dan angka AC-7 cocok dengan perilaku Action itu (billed 1.000.000, paid 600.000, rate 60, overdue 200.000, 1 santri, kas 600.000, 2 pembayaran). API Filament 5 yang diandalkan terverifikasi di vendor: `CanBeLazy::$isLazy` bersifat static, `CanPoll::$pollingInterval` bertipe `?string`, dan `Widget::render()` ada sehingga `parent::render()` di trait aman. Matriks peran cocok dengan `RolePermissionSeeder`. Temuan di bawah semuanya minor dan harus ditangani saat RED/GREEN.

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | minor | AC-17, Rencana test | Spatie Permission menyimpan cache permission per proses. Request pertama memuat permission (query tambahan), request kedua memakainya dari cache, sehingga perbandingan jumlah query antara 2 dan 30 santri bisa gagal bukan karena N+1. | Tambahkan satu request pemanasan (user dan peran sama) sebelum kedua pengukuran, atau ukur dua kali dengan data berbeda setelah pemanasan. Tulis di catatan test. |
| 2 | minor | AC-1, AC-4, AC-6 (cara assert) | Penegasan "tidak ada heading widget di HTML" rawan salah positif: teks seperti "Keuangan", "Konten", "Santri", "Prestasi" juga muncul di grup/menu navigasi. Nama unit di `UnitSeeder` adalah "Sekolah Dasar", bukan "SD". | Pakai `assertSeeLivewire(Widget::class)` / `assertDontSeeLivewire(...)` (widget non-lazy dirender sebagai komponen Livewire) untuk ada/tidaknya widget. Pakai teks hanya untuk isi unik (mis. "Keuangan Oktober 2026"). Di AC-11 sebutkan apakah baris jenjang memakai `units.name` atau `code`, lalu samakan assert-nya. |
| 3 | minor | Bagian UI, FinanceOverviewWidget dan SystemOverviewWidget | Link "di header widget" tidak didukung `StatsOverviewWidget` (hanya heading/description). Cara implementasinya tidak ditentukan. | Tetapkan: pakai `Stat::url()` pada stat yang relevan, atau view kustom untuk header. Pastikan AC-10/AC-14 mengassert href yang sama dengan cara yang dipilih. |
| 4 | minor | Pertanyaan terbuka 5, design-guide §7 | Plan sengaja menyimpang dari design-guide §7 (pakai `pondok.dashboard.module_unavailable`, bukan `pondok.common.module_disabled`). Design-guide (penutup) mewajibkan perubahan panduan ikut commit yang sama, tetapi urutan implementasi tidak memuat langkah itu. | Tambahkan ke langkah 1 atau 7: ubah baris "Modul mati / tabel belum ada" di design-guide §7 agar merujuk `pondok.dashboard.module_unavailable` untuk widget dashboard. Lakukan di commit yang sama. |
| 5 | minor | AC-7, AC-8, AC-9 (kasus tepi) | Belum ada kasus batas: `due_on` sama dengan hari ini (bukan menunggak), `paid_on` pada tanggal 1 dan hari terakhir bulan, dan pergantian bulan menurut zona `Asia/Jakarta` (mis. 2026-10-31 20:00 UTC masih Oktober UTC tetapi sudah 1 November di Jakarta). AC-9 hanya menegaskan `billed`. | Tambah satu AC atau perluas AC-7: due_on = hari ini tidak menunggak; tagihan Oktober/November dan pembayaran di batas bulan; waktu dibekukan di 2026-10-31 20:00 UTC menghasilkan `period` 2026-11. Di AC-9 tambahkan `paid`, `cash_received`, dan `payment_count` yang ikut tersaring. |
| 6 | minor | AC-4 (mock Action) | `shouldNotReceive('handle')` hanya bisa dipakai bila widget memperoleh Action lewat container (`app(...)`). Tidak disebut di bagian UI. | Tulis bahwa widget memakai `app(SummarizeCurrentMonthFinance::class)` / `app(SummarizeActiveStudents::class)`, bukan `new`. Alternatif assert tanpa mock: query log tidak memuat tabel `invoices`/`students`. |
| 7 | minor | Risiko 7, Ruang lingkup | Teks pertanyaan terbuka 7 sudah usang: F008 kini `done` dan sudah ada di `depends_on` F018. Selain itu langkah 5 tidak lagi perlu menunggu VERIFY F008. | Perbarui teks risiko 7 saat revisi berikutnya (tidak menghalangi implementasi). |
| 8 | minor | Model data, AC-5 | Daftar tabel wajib `FinanceOverviewWidget` memuat `invoice_items` dan `fee_types`, padahal hanya `SummarizeCashReceipts` yang memakainya (lewat `by_fee_type`). Widget tidak menampilkan rincian itu, jadi tabel hilang sebanyak ini bisa menyembunyikan widget tanpa perlu. Selain itu AC-5 menjatuhkan `cohorts` saat halaman `/admin` lain (resource, badge) mungkin ikut menyentuh tabel itu. | Biarkan daftar tabel sesuai kebutuhan Action yang dipanggil (itu aman dan konservatif); catat alasannya. Saat RED, pastikan halaman `/admin` tetap 200 ketika tabel di-drop (jika ada navigasi yang menyentuh tabel itu, perbaiki di sumbernya, bukan dengan try/catch). |

Catatan non-temuan: link ke resource sudah dijaga ganda (`Route::has` dan `canViewAny()`/`canAccess()`), widget tanpa permission tidak dirender (sesuai design-guide §7), dan keputusan tanpa cache dan tanpa polling sudah konsisten dengan batasan shared hosting.
