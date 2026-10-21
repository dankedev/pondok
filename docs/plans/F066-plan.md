# F066 inti-saklar-modul — Modul fitur bisa diaktifkan/dimatikan dari admin

Issue: #35
Status rencana: APPROVED (orkestrator, setelah Revisi #1)
Jalur: penuh (hak akses, route, jadwal)

## Ringkasan & tujuan
Super-admin menyalakan/mematikan modul dari **Pengaturan → Modul** dengan ketergantungan yang dijaga, tercatat di activity log. Satu definisi modul di kode (enum) dipakai seeder, halaman, Action, dan test kepatuhan yang menyisir panel, route, dan jadwal sehingga fitur baru yang lupa didaftarkan gagal test. Mekanisme penegakan yang sudah ada dipakai ulang (`BelongsToModule`, `EnsureModuleIsEnabled`, `InteractsWithDashboardModule`, cek di Action portal); tidak ada sistem paralel.

## Ruang lingkup
- Termasuk: enum `Module`, Action `SetModuleEnabled`, halaman Pengaturan → Modul, konvensi `getModule()` untuk Page, `->when()` jadwal modul, test kepatuhan + smoke test, aturan "fitur baru = modul" di CLAUDE.md.
- Tidak termasuk: saklar tahfidz/perizinan (masuk enum saat modulnya dibangun); "status efektif" (modul aktif tetapi dependensinya mati tetap dibaca apa adanya); menghapus fallback PSB-tanpa-keuangan yang sudah ada; sapuan relation manager lintas modul (yang ada sudah cek saklar: Achievements/Documents RM); tabel baru; migrasi data untuk instalasi yang sudah melanggar graf; form Situs > Beranda (`Site/Home.php`) yang masih bisa menambah blok video/feed saat Konten mati: diusulkan issue terpisah (render portal sudah aman, AC-12).

## Model data
Tidak ada migration. Tetap `settings.modules.<key>.enabled` (json bool). Baris lama `modules.tahfidz/perizinan.enabled` dibiarkan (diabaikan karena tidak ada di enum).

**`App\Enums\Module`** (backed string = key setting), 7 case: `Akademik`, `Asrama`, `Keuangan`, `Psb`, `Dokumen`, `Konten`, `Prestasi`.
- `label()`, `description()` → `__('pondok.modules.<key>.label|description')`.
- `requires(): list<Module>`; `dependents(): list<Module>` diturunkan dari `requires()` semua case (bukan ditulis dua kali).
- `isEnabled(): bool` → `Setting::moduleEnabled($this->value)`.

Graf ketergantungan (usulan, alasan dari kode):
| Modul | Membutuhkan | Alasan |
|---|---|---|
| akademik | — | dasar data santri (`StudentResource`, `Guardian`, rombel) |
| asrama | akademik | `room_assignments.student_id`, penempatan memilih santri |
| keuangan | akademik | tagihan per santri, generator menyisir santri aktif |
| psb | akademik, keuangan | daftar ulang membuat `students`; tagihan `saat_daftar`/`saat_diterima` (contoh di issue) |
| dokumen | — | pemilik dokumen juga staff (inti) dan prestasi; relation manager dokumen sudah cek saklarnya sendiri |
| prestasi | akademik | `achievements.student_id` |
| konten | — | portal berdiri sendiri |

Data lama yang melanggar graf (mis. psb aktif, keuangan mati): tidak dimigrasi. Halaman menampilkan peringatan "Membutuhkan <X> yang belum aktif" di kartu modul itu; perilaku aplikasi tetap seperti sekarang (fallback PSB tanpa tagihan tetap jalan). Admin bisa mematikan psb atau menyalakan keuangan.

**Inti** (tanpa saklar, tidak ada di enum). Daftar eksplisit yang dipakai test kepatuhan:
- Resource: `UserResource`, `UnitResource`, `AcademicYearResource`, `StaffResource` (`ImportStaffRow` di `Actions/Core`, grup Data Induk), Shield `RoleResource`.
- Page: `Filament\Pages\Dashboard`, `Settings\Identity`, `Settings\Contacts`, `Settings\Modules` (baru), `Site\Home`, `Site\Navigation`, `Site\Footer`, `Site\Theme`.
- Widget: `Filament\Widgets\AccountWidget`, `SystemOverviewWidget`.
- Route: `portal.home`, `portal.sitemap`, `portal.robots`.
- Jadwal: event dengan description `pondok:scheduler-heartbeat`, dan event yang command-nya memuat `queue:work`.

## Action class
`app/Actions/Core/SetModuleEnabled::handle(User $actor, Module $module, bool $enabled): void`
- Aktor bukan `super-admin` → `AuthorizationException`.
- Nilai sama dengan sekarang → no-op, tanpa log.
- Aktifkan: semua `requires()` harus aktif, bila tidak → `ValidationException` key `module` yang menyebut **semua** dependensi yang mati, dipisah koma: "Aktifkan dulu modul Akademik, Keuangan karena dibutuhkan PSB."
- Matikan: tidak boleh ada `dependents()` yang aktif → pesan menyebut **semua** dependen aktif, dipisah koma: "Matikan dulu modul Asrama, Keuangan, PSB karena membutuhkan Akademik."
- Cek dependensi dan nilai lama dibaca **di dalam** `DB::transaction` (setelah `Setting::forget` key terkait agar cache request tidak basi), lalu `Setting::set(...)` lalu `activity('pengaturan')->causedBy($actor)->event('updated')->withProperties(['module' => key, 'old' => ['enabled' => bool], 'attributes' => ['enabled' => bool]])->log(...)`. Tanpa `performedOn` (PK `settings` string). Setting belum ada = `false`.
- Data modul tidak disentuh.

Perubahan kecil lain: `SettingSeeder` menyisir `Module::cases()` (semua aktif saat instalasi baru, `firstOrCreate`); konstanta `MODULES`/`ENABLED_BY_DEFAULT` dihapus. `SummarizeSystemStatus` menghitung `module_count`/`enabled_module_count` dari `Module::cases()` agar baris lama tahfidz/perizinan tidak ikut.

## UI (Filament / Blade)
- `app/Filament/Pages/Settings/Modules.php`, grup `pondok.navigation.pengaturan`, judul "Modul". `canAccess()` = `hasRole('super-admin')` (tidak menambah permission Shield; super-admin sudah lewat gate); `mount()` memanggil `abort_unless(static::canAccess(), 403)`. Pola visibilitas navigasi seperti `SettingsFormPage`.
- Isi: satu Section per `Module` (urut enum): judul label, deskripsi, badge status Aktif/Nonaktif (warna sesuai §4), baris "Membutuhkan: …" (atau "Tidak membutuhkan modul lain"), peringatan bila dependensinya mati. Header action per Section bernama `toggle-<key>` (mis. `toggle-konten`, dipakai test): label "Aktifkan modul"/"Matikan modul" sesuai status, dengan `requiresConfirmation()`; deskripsi modal menyebut akibat ("Menu, halaman, dan jadwal <X> disembunyikan. Data tetap tersimpan dan kembali saat modul diaktifkan."). Galat Action → notifikasi `danger` berisi pesan Action; berhasil → notifikasi `success` menyebut modul.
- Section terakhir "Modul inti (selalu aktif)": daftar teks inti, tanpa aksi.
- Teks di `lang/id/pondok.php` (`modules.*`, `module_settings.*`). Ikuti design-guide §0, §4, §6, §7, §8, §13 (anti-ui-slop).
- Rencana ini tidak menyentuh `Site/Home.php`.

## Permission & cakupan
- Tidak ada permission baru; halaman Modul hanya `super-admin`. Tidak menyentuh `unit_user`.
- **Konvensi Page**: setiap Page non-inti punya `public static function getModule(): string` (nilai `Module`) dan `canAccess()` false bila modul mati. Ubah: `FinanceReport` (keuangan), `AchievementRecap` (prestasi), `NumberingSettingsPage::moduleKey()` → `getModule(): ?string` (FinanceNumbering keuangan, StudentNumbering akademik, ReRegistrationPayment psb). Resource tetap `BelongsToModule::getModule()`, widget tetap `getDashboardModule()`.
- **Route kustom panel** (`AdminPanelProvider::authenticatedRoutes()`): `filament.admin.keuangan.receipt`, `filament.admin.keuangan.payment-proof`, `filament.admin.psb.applicant-document`, `filament.admin.dokumen.download`, `filament.admin.dokumen.letters.print` sudah memakai `EnsureModuleIsEnabled`; nama lengkap diverifikasi red-tester dengan `php artisan route:list --except-vendor` (vendor belum terpasang di worktree saat rencana ditulis). Route kustom modul baru wajib memakai middleware yang sama.
- **Jadwal** (`routes/console.php`): tiga event keuangan diberi `->when(fn (): bool => Module::Keuangan->isEnabled())` (termasuk `keuangan:prune-payment-proofs`). Cek di dalam command generator tetap (untuk jalankan manual).

## Kriteria penerimaan (bisa dites)
- **AC-1** `Module::cases()` berisi tepat 7 key di atas; setiap case punya label & deskripsi non-kosong dari `lang/id` (bukan key mentah); `requires()` sesuai tabel; `Module::Keuangan->dependents()` = [psb], `Module::Akademik->dependents()` = asrama, keuangan, psb, prestasi; `Module::Dokumen->requires()` dan `Module::Konten->requires()` kosong.
- **AC-2** `db:seed` pada DB kosong membuat `modules.<key>.enabled = true` untuk 7 modul dan tidak membuat baris tahfidz/perizinan; seed ulang tidak menimpa nilai yang sudah `false`. `SummarizeSystemStatus` dengan baris lama tahfidz/perizinan tetap melaporkan `module_count = 7`.
- **AC-3** Mematikan modul tanpa dependen aktif (mis. konten) → setting `false`; satu activity `log_name = pengaturan`, `causer` = aktor, `properties.module = konten`, `old.enabled = true`, `attributes.enabled = false`. Mengaktifkan kembali → `true` dan baris data modul (mis. `posts`) jumlahnya sama sebelum dan sesudah.
- **AC-4** Mematikan keuangan saat psb aktif → `ValidationException` (key `module`) yang pesannya memuat "PSB"; setting dan activity log tidak berubah. Mematikan akademik saat asrama, keuangan, psb aktif → pesan memuat ketiganya.
- **AC-5** Mengaktifkan psb saat keuangan mati → `ValidationException` memuat "Keuangan"; tidak berubah. Setelah keuangan aktif, psb bisa diaktifkan.
- **AC-6** Aktor bukan super-admin (mis. `admin-sekolah`) → `AuthorizationException`, tidak ada perubahan. Nilai sama dengan sekarang → tidak ada activity baru.
- **AC-7** Halaman Modul: super-admin 200 dan melihat label, deskripsi, dan "Membutuhkan" tiap modul serta bagian modul inti; peran lain (`admin-sekolah`, `bendahara`) 403 dan tidak melihat menunya.
- **AC-8** Dari halaman (Livewire): aksi matikan konten → setting `false` + notifikasi sukses; aksi matikan keuangan saat psb aktif → notifikasi galat, setting tetap. Kondisi lama psb aktif + keuangan mati → kartu PSB menampilkan peringatan dependensi dan halaman tetap 200.
- **AC-9** Kepatuhan panel: setiap resource, page, dan widget terdaftar di panel `admin` (`Filament::getPanel('admin')->getResources()/getPages()/getWidgets()`) ada di daftar inti eksplisit (bagian Model data) **atau** mendeklarasikan modul (`getModule()`/`getDashboardModule()`) yang valid (`Module::tryFrom` tidak null). Untuk yang bermodul, saat hanya modul itu dimatikan: resource `canViewAny()` false, page `canAccess()` false (super-admin), widget menampilkan `pondok.dashboard.module_unavailable`.
- **AC-10** Kepatuhan route, predikat: ambil `Route::getRoutes()`; lewati route yang action-nya bukan class `App\...` (closure, vendor, Livewire, internal Filament); lewati action yang subclass `Filament\Pages\Page` atau `Filament\Resources\Pages\Page` (tercakup AC-9). Sisanya wajib ada di daftar route inti **atau** punya entri `gatherMiddleware()` berbentuk `EnsureModuleIsEnabled::class.':<modul>[,404]'` dengan `<modul>` valid (`Module::tryFrom`). Sampel perilaku: keuangan mati → `route('filament.admin.keuangan.receipt', $payment)` 403 untuk bendahara; konten mati → `/berita` 404.
- **AC-11** Kepatuhan jadwal: saat semua modul mati, setiap event di `Schedule::events()` yang bukan inti (description `pondok:scheduler-heartbeat`, atau `command` memuat `queue:work`) punya `filtersPass(app()) === false`; saat semua aktif → `true`.
- **AC-12** Smoke: semua 7 modul mati → `/admin/login` 200, dashboard super-admin 200 dengan widget modul berisi "Modul belum tersedia", halaman inti (Pengguna, Jenjang, Tahun ajaran, Staff, Identitas, Modul) 200, portal `/` 200 tanpa `href` ke `/berita`, `/galeri`, `/video`, `/psb`, `/info-psb`, `/verifikasi-surat` walau navigasi, footer, dan blok beranda sudah diisi tautan/blok ke sana.

## Rencana test
| AC | File | Test |
|---|---|---|
| 1 | `tests/Feature/Enums/ModuleTest.php` | `module cases labels and dependency graph` |
| 2 | `tests/Feature/Database/Seeders/InstallSeederTest.php` (+ ganti 4 rujukan konstanta: `tests/Pest.php:1306`, `InstallSeederTest:35` dan baris 63–69 yang memakai `modules.tahfidz` (ganti ke modul enum), `InstallCommandTest:95`, `KontenSeedersTest:55`), `tests/Feature/Actions/Dashboard/SummarizeSystemStatusTest.php` | `seeds enum modules enabled`, `module count ignores legacy keys` |
| 3–6 | `tests/Feature/Actions/Core/SetModuleEnabledTest.php` | `disables and logs`, `reenable keeps data`, `blocks disabling required module`, `blocks enabling before requirements`, `rejects non super admin`, `no-op without log` |
| 7–8 | `tests/Feature/Filament/Pages/Settings/ModuleSettingsPageTest.php` | `super admin sees modules`, `other roles forbidden`, `toggle via page`, `shows warning for legacy violation` |
| 9–11 | `tests/Feature/ModuleComplianceTest.php` | `panel components belong to a module`, `app routes belong to a module`, `scheduled events skip when modules off` |
| 12 | `tests/Feature/ModulesDisabledSmokeTest.php` | `app works with all modules disabled` |

## Urutan implementasi
**Paket 1 — data + Action** (hijau: ModuleTest, InstallSeederTest, SetModuleEnabledTest, tes SummarizeSystemStatus)
1. `App\Enums\Module` + `lang/id` `modules.*`.
2. `SettingSeeder` pakai enum; `SummarizeSystemStatus` hitung dari enum.
3. `SetModuleEnabled` + pesan galat.

**Paket 2 — panel, route, jadwal** (hijau: ModuleSettingsPageTest, ModuleComplianceTest, test akses modul yang ada)
4. Halaman `Settings/Modules` + teks.
5. `getModule()` pada FinanceReport, AchievementRecap, NumberingSettingsPage (+ turunannya).
6. `->when()` jadwal keuangan di `routes/console.php`.

**Paket 3 — portal smoke** (hijau: ModulesDisabledSmokeTest)
7. Tutup celah render yang ditemukan smoke test (bila ada).

**Paket 4 — dokumen** (commit `docs(inti): ...` terpisah)
8. CLAUDE.md bagian Arsitektur: "Fitur baru wajib dibangun sebagai modul: case di `App\Enums\Module` (+`requires()`), `getModule()` di resource/page, `getDashboardModule()` di widget, `EnsureModuleIsEnabled` di route, `->when()` di jadwal; test kepatuhan akan gagal bila lupa."

## Risiko & pertanyaan terbuka
1. **Dilaporkan ke user — graf:** psb → keuangan wajib (contoh di issue) walau kode PSB punya fallback tanpa keuangan (fallback dibiarkan). dokumen tanpa dependensi (keputusan orkestrator). Mengubahnya cukup lewat `requires()`.
2. Keuangan tidak dibuat bergantung asrama: biaya `mukim` tetap dibaca dari `room_assignments` walau asrama mati (perilaku sekarang, tidak diubah).
3. Issue terpisah (usulan): form Situs > Beranda mengunci blok video/feed dan album hero saat Konten mati tanpa membuang blok tersimpan (bersinggungan dengan PR #56).

## Riwayat revisi
- DRAFT 1 (22 Okt 2026): rencana awal.
- REVISED 1 (22 Okt 2026): menjawab Review #1 (lihat di bawah).

## Review #1 — 2026-10-22
Verdict: CHANGES_REQUESTED (ringkasan temuan sebagaimana diteruskan orkestrator)

| # | Tingkat | Temuan |
|---|---|---|
| 1 | major | AC-10: predikat route tidak konkret; contoh nama route kustom panel salah (`keuangan.receipt` tanpa prefiks panel); route kustom `authenticatedRoutes()` harus ikut terdaftar ke modul. |
| 2 | major | AC-13/langkah 8 (form Beranda, `Site/Home.php`) memperluas ruang lingkup dan bentrok dengan PR #56. |
| 3 | minor | Daftar inti eksplisit belum lengkap (Dashboard, AccountWidget, Shield RoleResource, halaman Site/Settings, halaman Modul). |
| 4 | minor | Rujukan `SettingSeeder::` di tests belum didaftar lengkap. |
| 5 | minor | `dokumen → akademik` kurang beralasan. |
| 6 | minor | Pesan galat harus menyebut semua dependen aktif; cek dependensi di dalam transaksi. |
| 7 | minor | Cara mengenali event jadwal inti belum ditetapkan. |
| 8 | minor | Nama aksi halaman dan penjagaan `mount()` belum ditetapkan. |

## Revisi #1
1. AC-10 ditulis ulang dengan predikat konkret (`Route::getRoutes()`, filter `App\`, lewati subclass Page Filament, parse `gatherMiddleware()`); nama route kustom memakai prefiks `filament.admin.`; kelima route kustom panel sudah bermiddleware dan dicatat di bagian Permission. Nama lengkap diverifikasi red-tester via `route:list --except-vendor`.
2. AC-13 dan langkah 8 dihapus; `Site/Home.php` tidak disentuh; dicatat di "Tidak termasuk" dan risiko 3 sebagai usulan issue terpisah.
3. Daftar inti eksplisit lengkap di bagian Model data (resource, page, widget, route, jadwal).
4. Hanya 4 rujukan konstanta (`MODULES`, `ENABLED_BY_DEFAULT`) di tests; rujukan lain hanya `SettingSeeder::class`. Konstanta dihapus dan 4 rujukan + baris tahfidz `InstallSeederTest:63–69` disesuaikan (lebih sedikit churn daripada alias yang menduplikasi daftar enum).
5. dokumen tanpa dependensi; AC-1 diperbarui. psb → keuangan tetap, dilaporkan ke user.
6. Pesan menyebut semua dependensi/dependen dipisah koma; cek di dalam transaksi; AC-4 ditambah kasus akademik dengan tiga dependen.
7. Event inti: description `pondok:scheduler-heartbeat` atau command memuat `queue:work` (AC-11).
8. Aksi bernama `toggle-<key>`; `mount()` memakai `abort_unless(static::canAccess(), 403)`.
