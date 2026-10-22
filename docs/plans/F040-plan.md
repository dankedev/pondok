# F040 psb-syarat-daftar-ulang-nominal — Syarat bayar daftar ulang dalam nominal rupiah

Issue: #15
Status rencana: APPROVED (orkestrator, setelah revisi 1 atas Review #1)

## Ringkasan & tujuan
Ganti syarat persen `psb.min_paid_percent` dengan nominal minimal rupiah per jenjang yang diisi admin di panel. `CheckReRegistrationPayment` membandingkan jumlah terbayar tagihan penerimaan aktif dengan nominal itu.

**Keputusan user (20 Okt 2026, final):**
1. Nominal minimal **per jenjang** (`units`), rupiah `unsignedBigInteger`, diisi admin lewat panel.
2. Nominal kosong (`null`) = **wajib lunas** tagihan penerimaan. Nominal `0` = tanpa syarat bayar.
3. Setting `psb.min_paid_percent` **dihapus lewat migrasi** (tidak dikonversi). `SettingSeeder` tidak lagi men-seed key itu.
4. Bila total tagihan penerimaan < nominal (mis. beasiswa), cukup lunas: `required = min(nominal ?? total, total)`.

Pesan penolakan menampilkan nominal syarat dan jumlah terbayar dalam format `Rp 1.250.000` (design-guide §5).

## Ruang lingkup
- Termasuk: kolom `units.min_reregistration_amount`; migrasi hapus setting persen; ubah `CheckReRegistrationPayment`, pesan `ReRegisterApplicant`, infolist `ApplicantResource`; halaman pengaturan "Syarat daftar ulang" + permission; seeder (setting, permission, demo); dokumen ERD dan daftar pertanyaan untuk lembaga (berkas dihapus di F083).
- Tidak termasuk (eksplisit): nominal per gelombang/jalur; konversi persen lama; field di form `UnitResource`; kolom baru di tabel jenjang; tampilan syarat di portal pendaftar; akses bendahara ke halaman ini (bisa jadi issue baru bila diminta).

## Model data
- Migration `add_min_reregistration_amount_to_units_table`: `unsignedBigInteger('min_reregistration_amount')->nullable()` setelah `sort_order`. Tanpa index. `null` = wajib lunas, `0` = tanpa syarat.
- Migration yang sama menghapus baris `settings` dengan key `psb.min_paid_percent` lalu memanggil `Setting::forget('psb.min_paid_percent')` (hapus lewat query builder tidak memicu event model, jadi cache dibersihkan manual). `down()`: drop kolom saja; nilai persen tidak dipulihkan (catat di docblock).
- `Unit`: tambah ke `#[Fillable]`, cast `integer`. `UnitFactory` default `null`.
- Alasan memilih kolom di `units` (bukan setting JSON): KISS, bertipe, ikut cakupan jenjang lewat `Unit::accessible()`, dan dibaca langsung dari `$applicant->unit`.

## Action class
**Ubah `app/Actions/Psb/CheckReRegistrationPayment`**
- Hapus `SETTING_KEY`, `DEFAULT_MIN_PERCENT`, `requiredPercent()` beserta log peringatannya.
- Output: `array{applies: bool, minimum: int|null, required: int, total: int, paid: int, paid_percent: int, meets: bool, reason: string|null}`.
  - `minimum` = `units.min_reregistration_amount` jenjang pendaftar (dibaca tanpa scope).
  - `required` = `min(minimum ?? total, total)`; `0` bila tidak ada tagihan penerimaan.
  - `meets` = `paid >= required`. `paid_percent` tetap (dipakai baris "Pembayaran tagihan penerimaan").
- Perilaku lain tetap: modul keuangan mati → `applies=false, meets=true`; tagihan formulir dan `batal` tidak dihitung; tanpa tagihan penerimaan padahal ada tarif `saat_diterima` → `acceptance_invoices_missing`.

**Ubah `ReRegisterApplicant::ensurePaymentMet`**: parameter pesan `paid` = `Payment::formatRupiah($payment['paid'])`, `required` = `Payment::formatRupiah($payment['required'])`.

**Baru `app/Actions/Psb/UpdateReRegistrationMinimums`** — `handle(array<int, int|null> $amounts, User $by): void`
- Kunci = `unit_id`. Nilai `null` atau bilangan bulat 0–1.000.000.000.000; selain itu `ValidationException` (key `amounts.<unit_id>`).
- Cakupan dibaca eksplisit dari `$by->scopedUnitIds()` (kosong = semua jenjang), **tidak** lewat `Unit::accessible()` yang membaca `Auth::user()`. `unit_id` di luar cakupan atau tidak ada → `ValidationException` dengan satu pesan (`psb.re_registration_settings.unit_not_allowed`).
- Seluruh payload divalidasi dulu (nilai dan cakupan); bila satu entri gagal, tidak ada yang ditulis. Baru setelah itu menulis dalam satu transaksi; hanya jenjang yang nilainya berubah yang ditulis. Tiap perubahan dicatat `activity('psb')` event `re_registration_minimum_updated`, subject unit, properties `old`/`new`.

## UI (Filament / Blade)
- Halaman baru `app/Filament/Pages/Settings/ReRegistrationPayment.php` extends `NumberingSettingsPage` (pakai ulang form, tombol simpan, notifikasi, pemetaan error). Slug `settings/re-registration-payment`, grup navigasi `pondok.navigation.psb`, ikon `Banknotes`, `moduleKey()` = `psb`, permission `view:re-registration-payment`.
- Form `data.amounts.<unit_id>` satu `TextInput` per jenjang dalam `scopedUnitIds()` user login (kosong = semua), urut `sort_order`: label nama jenjang, `numeric()->integer()->minValue(0)->prefix('Rp')`, nullable, `tabular-nums`. Section dengan deskripsi: "Kosongkan agar wajib lunas. Isi 0 bila tanpa syarat pembayaran. Bila tagihan pendaftar lebih kecil, cukup lunas." `persist()` memanggil `UpdateReRegistrationMinimums`.
- Infolist `ApplicantResource` (seksi Daftar ulang): `TextEntry::make('min_paid_percent')` diganti `re_registration_required` berlabel tetap `Syarat minimal`, state `Payment::formatRupiah(required)`, `tabular-nums`, visibilitas sama. `belowContent`: `minimum === null` → "Nominal jenjang belum diatur, jadi wajib lunas."; `minimum > total` → "Tagihan lebih kecil dari nominal minimal, jadi cukup lunas."; selain itu kosong.
- `lang/id/pondok.php`: hapus `applicant.min_paid_percent`; tambah `applicant.re_registration_required`, `applicant.re_registration_minimum_unset`, `applicant.re_registration_minimum_capped`; blok `psb.re_registration_settings.*` (title, heading, description, helper, unit_not_allowed); ubah `psb.errors.min_payment_not_met` → "Pembayaran tagihan penerimaan baru :paid. Daftar ulang membutuhkan minimal :required."
- Rujukan design-guide: §5 (format uang, input `prefix('Rp')`), §0 checklist, label Indonesia.

## Permission & cakupan
- Permission page baru `view:re-registration-payment`, mengikuti pola F039 di `database/seeders/RolePermissionSeeder.php`: tambah ke `PAGE_PERMISSIONS` (baris ~37, dengan komentar F040) dan ke daftar peran `admin-psb` di `permissionsFor()` (seperti `view:finance-numbering` di bendahara). `super-admin` otomatis lewat `allPermissions()`. Peran lain tidak.
- Instance yang sudah berjalan: `php artisan pondok:upgrade` menjalankan `migrate` lalu `SystemSeeder` (→ `SettingSeeder`, `RolePermissionSeeder`), jadi permission sampai ke admin-psb tanpa langkah manual (sama seperti F039; `docs/instalasi.md` §10 sudah menyebut "menambah permission"). Tidak perlu dokumen upgrade baru.
- Cakupan: admin-psb dengan `unit_user` hanya melihat dan menyimpan jenjang dalam cakupannya.
- Saklar: halaman tersembunyi dan 403 bila `modules.psb.enabled` mati. Bila keuangan mati, halaman tetap ada; pemeriksaan syarat tidak berlaku (perilaku lama).

## Kriteria penerimaan (bisa dites)
- **AC-1** Migrasi: setelah `migrate`, `units.min_reregistration_amount` ada dan nullable; baris `settings` `psb.min_paid_percent` yang ada sebelumnya terhapus. `SettingSeeder` tidak membuat key itu (dijalankan dua kali tetap tidak ada).
- **AC-2** Nominal diatur: jenjang SLTP min Rp 3.000.000, tagihan penerimaan Rp 8.000.000, terbayar 2.999.999 → `meets=false`, `reason=min_payment_not_met`, `minimum=3000000`, `required=3000000`; bayar 1 lagi → `meets=true` dan daftar ulang berhasil membuat santri.
- **AC-3** Nominal `null`: terbayar 7.999.999 dari 8.000.000 → `meets=false`, `required=8000000`; lunas → `meets=true`.
- **AC-4** Nominal `0`: terbayar 0 dari 8.000.000 → `meets=true`, `required=0`; daftar ulang berhasil.
- **AC-5** Total < nominal: min Rp 10.000.000, tagihan penerimaan (setelah beasiswa) Rp 2.000.000 → `required=2000000`; terbayar 1.999.999 → gagal, lunas → `meets=true`.
- **AC-6** Perilaku lama tetap: tagihan `batal` dan tagihan formulir tidak dihitung; total 0 → `meets=true`; keuangan mati → `applies=false, meets=true`; tarif `saat_diterima` ada tanpa tagihan → `acceptance_invoices_missing`. Nominal dibaca dari jenjang pendaftar, bukan jenjang lain (SLTA min 0 tidak membebaskan pendaftar SLTP).
- **AC-7** Pesan penolakan `ReRegisterApplicant` (key `payment`) memuat `Rp 2.999.999` dan `Rp 3.000.000`; tanpa tanda `%`. Tidak ada data yang berubah (snapshot sama).
- **AC-8** `UpdateReRegistrationMinimums`: menyimpan `{SLTP: 3000000, SLTA: null, SD: 0}` persis (null tetap null, 0 tetap 0); nilai negatif, pecahan, teks, atau > 1 triliun → `ValidationException` dan tidak ada perubahan; satu entri activity log per jenjang yang berubah, tidak ada entri untuk jenjang yang nilainya sama.
- **AC-9** Cakupan: Action dipanggil **tanpa** `actingAs` dengan `$by` = admin-psb ber-`unit_user` SLTP: payload `{SLTP: 1000000, SLTA: 0}` → `ValidationException`, SLTP dan SLTA keduanya tidak berubah; `$by` tanpa `unit_user` boleh menyimpan semua jenjang. Halaman (login sebagai admin-psb SLTP) hanya menampilkan field SLTP.
- **AC-10** Akses halaman: super-admin dan admin-psb mendapat 200 dan item navigasi; bendahara, admin-sekolah, admin-konten, kesantrian → 403 dan navigasi tidak tampil; modul PSB mati → 403 bahkan untuk super-admin.
- **AC-11** Halaman Livewire: form terisi nilai tersimpan (kosong untuk null); isi SLTP `3000000`, kosongkan SLTA, simpan → database sesuai, notifikasi sukses; isi `-1` → error di `data.amounts.<id>`, database tidak berubah.
- **AC-12** Infolist pendaftar `diterima` belum dikonversi, satu dataset `(nominal, total) → (teks, catatan)`: `(3.000.000, 8.000.000) → Rp 3.000.000, tanpa catatan`; `(null, 8.000.000) → Rp 8.000.000, "belum diatur, jadi wajib lunas"`; `(10.000.000, 2.000.000) → Rp 2.000.000, "cukup lunas"`. Tidak ada teks `%` di baris itu.
- **AC-13** Seeder: `RolePermissionSeeder` membuat `view:re-registration-payment` untuk admin-psb dan super-admin saja, dan menjalankannya ulang di database yang sudah ter-seed (simulasi `pondok:upgrade`) memberikan permission itu ke admin-psb; `migrate:fresh --seed` demo tetap berhasil (alur `reregistered_partial` lulus karena demo mengisi nominal jenjang).

## Rencana test
- `tests/Feature/Actions/Psb/CheckReRegistrationPaymentTest.php` (tulis ulang dataset persen): AC-2 sampai AC-6.
- `tests/Feature/Actions/Psb/ReRegisterVerifyTest.php`: ganti `Setting::set('psb.min_paid_percent', …)` ke nominal unit; AC-7.
- `tests/Feature/Actions/Psb/UpdateReRegistrationMinimumsTest.php` (baru): AC-8, AC-9.
- `tests/Feature/Filament/Pages/Settings/ReRegistrationPaymentPageTest.php` (baru): AC-10, AC-11.
- `tests/Feature/Filament/Resources/Applicants/ReRegisterApplicantActionTest.php`: AC-12 (dan pesan `min_payment_not_met` di notifikasi).
- `tests/Feature/Database/Migrations/RemoveMinPaidPercentTest.php` (baru, kecil) + `SettingSeederTest`: AC-1.
- `RolePermissionSeederTest` (daftar admin-psb) + `DemoSeederTest`: AC-13.
- `PsbAccessTest` baris ~382/416: ganti key `min_paid_percent` → key lang baru, harapan pesan rupiah.
- **Fixture**: `daftarUlangExtend()` di `tests/Pest.php` mengisi `min_reregistration_amount = 4000000` untuk semua jenjang fixture, sehingga test F012 lain (bayar 4.000.000 dari 8.000.000) tetap lulus tanpa diubah.

## Urutan implementasi
**Paket 1 — data + Action** (hijau: CheckReRegistrationPaymentTest, ReRegisterVerifyTest, UpdateReRegistrationMinimumsTest, RemoveMinPaidPercentTest, SettingSeederTest, seluruh `tests/Feature/Actions/Psb`)
1. Migration kolom + hapus setting; `Unit` fillable/cast; factory.
2. `CheckReRegistrationPayment` nominal; hapus konstanta persen; `SettingSeeder` tanpa key persen.
3. Pesan rupiah di `ReRegisterApplicant` + lang `psb.errors.min_payment_not_met`.
4. Action `UpdateReRegistrationMinimums` + activity log.

**Paket 2 — Filament + seeder** (hijau: ReRegistrationPaymentPageTest, ReRegisterApplicantActionTest, PsbAccessTest, RolePermissionSeederTest, DemoSeederTest)
5. Permission `view:re-registration-payment` untuk admin-psb.
6. Halaman `ReRegistrationPayment` + lang.
7. Infolist `ApplicantResource` + lang.
8. `DemoAdmissions`/`DemoData`: isi nominal jenjang demo (mis. SLTP dan SLTA Rp 5.000.000) agar alur demo 60% tetap lolos dan 30% tetap belum.

**Paket 3 — dokumen**
9. `docs/erd.md`: kolom di diagram `units`, baris ~579 dan ~612 (nominal per jenjang, null = lunas, 0 = tanpa syarat, cukup lunas bila total lebih kecil); `docs/ERD Pondok.html` baris ~631; daftar pertanyaan untuk lembaga (berkas dihapus di F083) no. 5 "Sudah: nominal per jenjang (F040)". PRD tidak perlu diubah (tidak menyebut persen).

## Risiko & pertanyaan terbuka
- Catatan rilis: setelah `pondok:upgrade`, semua jenjang `null` → wajib lunas sampai admin-psb/super-admin mengisi nominal di halaman "Syarat daftar ulang" (sesuai keputusan 2–3). Permission halaman ikut terpasang oleh `pondok:upgrade` (SystemSeeder); bila admin memperbarui tanpa perintah itu, jalankan `php artisan db:seed --class=SystemSeeder --force`.
- Batas atas 1 triliun hanya penjaga input, bukan aturan bisnis.
- Tidak ada pertanyaan terbuka. Kriteria issue ("perilaku saat kosong dan migrasi ... dikonfirmasi user") sudah terjawab oleh keputusan 20 Okt 2026; body issue sebaiknya diperbarui oleh orkestrator.

## Riwayat revisi
- 20 Okt 2026: DRAFT pertama (product-manager).
- 20 Okt 2026, revisi 1 atas Review #1: (major 1) cakupan Action lewat `$by->scopedUnitIds()` eksplisit, AC-9 diuji tanpa `actingAs`; (major 2) permission mengikuti pola F039 di `RolePermissionSeeder`, penyebaran ke instance berjalan lewat `pondok:upgrade` → `SystemSeeder`, dicatat di Permission dan Risiko, AC-13 ditambah seed ulang; (minor 3) migrasi memanggil `Setting::forget`; (minor 4) AC-12 jadi satu dataset; (minor 5) validasi seluruh payload sebelum menulis, satu pesan untuk jenjang di luar cakupan/tidak ada.

## Review #1 — 2026-10-20

Verdict: CHANGES_REQUESTED

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | major | Action `UpdateReRegistrationMinimums` / AC-9 | Rencana memakai `Unit::accessible()` untuk cakupan `$by`, padahal scope itu membaca `Auth::user()` (`app/Models/Unit.php`), bukan parameter `$by`. Action jadi bergantung pada sesi login; pemanggilan dari test/command dengan `$by` berbeda memakai cakupan yang salah (AC-9 hanya lulus bila `actingAs`). | Pakai `$by->scopedUnitIds()` secara eksplisit (kosong = semua jenjang), atau tulis di rencana bahwa Action mensyaratkan `Auth::user() === $by`. Pilih yang pertama. |
| 2 | major | Permission / rilis (Risiko) | Instance produksi: `admin-psb` hanya menerima `view:re-registration-payment` bila `RolePermissionSeeder` dijalankan ulang. Rencana tidak menyebut cara permission sampai ke instance yang sudah berjalan, padahal tanpa itu syarat tidak bisa diisi dan semua jenjang wajib lunas (null). | Cek cara F039 (`view:finance-numbering`) menyebarkan permission, ikuti pola yang sama, dan tulis di Risiko/catatan rilis (jalankan seeder permission setelah update). Tambahkan ke AC-13 bila perlu. |
| 3 | minor | Model data / migrasi | Penghapusan setting lewat `DB::table('settings')->delete` melewati event model `Setting::deleted` sehingga cache `Setting::forget` tidak terpanggil; key tidak lagi dibaca, jadi dampak nihil. | Cukup catat; atau panggil `Setting::forget('psb.min_paid_percent')` di migrasi. |
| 4 | minor | AC-6 / AC-12 | Kasus `paid_percent` tetap ada di output; AC-12 tiga varian infolist hanya memecah varian sejenis. | Boleh dijadikan satu dataset test; bukan blocker. |
| 5 | minor | Cakupan Action | Validasi unit di luar cakupan dan unit tidak ada digabung; cukup satu pesan. Pastikan seluruh payload ditolak sebelum menulis (sudah tersirat oleh transaksi). | Validasi semua entri dulu, baru tulis. |

Ringkasan: 0 blocker, 2 major, 3 minor. Selebihnya (kolom `units`, uang `unsignedBigInteger`, portabilitas DB, `min(nominal ?? total, total)`, saklar modul, ukuran 13 AC) sesuai CLAUDE.md/ERD.
